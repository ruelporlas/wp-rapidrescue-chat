<?php
/**
 * REST API endpoints.
 *
 * @package WP_RapidRescue_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles public chat REST API requests.
 */
class WP_RapidRescue_Chat_REST_API {

	const NAMESPACE = 'wp-rapidrescue/v1';

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public static function register_routes() {

		register_rest_route(
			self::NAMESPACE,
			'/chat',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'chat' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'message' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
						'validate_callback' => function ( $value ) {
							return is_string( $value ) &&
								'' !== trim( $value );
						},
					),
					'session_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => function ( $value ) {
							return is_string( $value ) &&
								'' !== trim( $value ) &&
								strlen( $value ) <= 64;
						},
					),
				),
			)
		);
	}

	/**
	 * Handle a chat request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function chat( WP_REST_Request $request ) {

		$message = $request->get_param( 'message' );
		$session_id = $request->get_param( 'session_id' );

		$message = sanitize_textarea_field( $message );
		$session_id = sanitize_text_field( $session_id );

		if ( '' === trim( $message ) ) {
			return new WP_Error(
				'empty_message',
				'Please enter a message.',
				array(
					'status' => 400,
				)
			);
		}

		if ( '' === trim( $session_id ) ) {
			return new WP_Error(
				'invalid_session',
				'The chat session is invalid.',
				array(
					'status' => 400,
				)
			);
		}

		/*
		 * Determine whether this message contains reliable identity
		 * information before creating or retrieving the conversation.
		 */
		$identity =
			WP_RapidRescue_Chat_Customer::extract_identity(
				$message
			);

		$customer_id =
			WP_RapidRescue_Chat_Customer::find_or_create_from_identity(
				$identity
			);

		if ( is_wp_error( $customer_id ) ) {
			return $customer_id;
		}

		/*
		 * If the message did not contain enough information to identify
		 * a customer, customer_id remains null and the conversation
		 * continues anonymously.
		 */
		$conversation =
			WP_RapidRescue_Chat_Conversation::get_or_create(
				$session_id,
				$customer_id
			);

		if ( is_wp_error( $conversation ) ) {
			return $conversation;
		}

		$conversation_id = absint( $conversation->id );

		/*
		 * Save the customer's message.
		 */
		$saved_message =
			WP_RapidRescue_Chat_Conversation::add_message(
				$conversation_id,
				'user',
				$message
			);

		if ( is_wp_error( $saved_message ) ) {
			return $saved_message;
		}

		/*
		 * Get recent conversation history.
		 */
		$history =
			WP_RapidRescue_Chat_Conversation::get_recent_messages(
				$conversation_id
			);

		/*
		 * Ask the AI using the conversation history.
		 */
		$response =
			WP_RapidRescue_Chat_AI::respond(
				$message,
				$history
			);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$assistant_text =
			isset( $response['text'] )
				? $response['text']
				: '';

		$ticket_id = null;
		$ticket_key = null;
		$escalated = false;

		/*
		 * The AI can request a ticket action, but PHP performs the
		 * actual ticket creation.
		 */
		$action = isset( $response['action'] ) &&
			is_array( $response['action'] )
			? $response['action']
			: array();

		$action_type = isset( $action['type'] )
			? sanitize_key( $action['type'] )
			: 'none';

		if ( 'create_ticket' === $action_type ) {

			$subject = isset( $action['subject'] )
				? sanitize_text_field(
					$action['subject']
				)
				: '';

			$summary = isset( $action['summary'] )
				? sanitize_textarea_field(
					$action['summary']
				)
				: '';

			$priority = isset( $action['priority'] )
				? sanitize_key(
					$action['priority']
				)
				: 'normal';

			$ticket_result =
				WP_RapidRescue_Chat_Escalation::create_ticket(
					$conversation_id,
					$customer_id,
					$subject,
					$summary,
					$priority
				);

			if ( is_wp_error( $ticket_result ) ) {

				/*
				 * Never allow the customer to believe that a ticket
				 * exists when the application failed to create one.
				 */
				$assistant_text .=
					"\n\nI'm sorry, but I couldn't create the "
					. "support request just now. No ticket has "
					. "been created. Please try again in a moment.";

			} elseif (
				! empty( $ticket_result['ticket_key'] )
			) {

				$ticket_id = ! empty(
					$ticket_result['ticket_id']
				)
					? absint(
						$ticket_result['ticket_id']
					)
					: null;

				$ticket_key =
					sanitize_text_field(
						$ticket_result['ticket_key']
					);

				$escalated = true;

				/*
				 * Replace the AI's preliminary escalation wording
				 * with a confirmed application-generated message.
				 */
				$confirmation =
					WP_RapidRescue_Chat_Escalation::get_confirmation_message(
						$ticket_result
					);

				if ( '' !== trim( $confirmation ) ) {

					$assistant_text =
						$confirmation;
				}
			}
		}

		/*
		 * Save the final assistant response, including any confirmed
		 * ticket information generated by the application.
		 */
		if ( '' !== trim( $assistant_text ) ) {

			WP_RapidRescue_Chat_Conversation::add_message(
				$conversation_id,
				'assistant',
				$assistant_text
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'conversation_id' => $conversation_id,
					'customer_id'     => $customer_id
						? absint( $customer_id )
						: null,
					'text'            => $assistant_text,
					'escalated'       => $escalated,
					'ticket_id'       => $ticket_id,
					'ticket_key'      => $ticket_key,
				),
			)
		);
	}
}