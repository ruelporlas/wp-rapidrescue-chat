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
	public static function chat(
		WP_REST_Request $request
	) {

		$message = sanitize_textarea_field(
			$request->get_param( 'message' )
		);

		$session_id = sanitize_text_field(
			$request->get_param( 'session_id' )
		);

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

		$conversation =
			WP_RapidRescue_Chat_Conversation::get_or_create(
				$session_id,
				$customer_id
			);

		if ( is_wp_error( $conversation ) ) {
			return $conversation;
		}

		$conversation_id = absint(
			$conversation->id
		);

		/*
		 * Save the customer's message first.
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

		$history =
			WP_RapidRescue_Chat_Conversation::get_recent_messages(
				$conversation_id
			);

		/*
		 * Build a limited list of active tickets relevant to this
		 * customer/conversation.
		 *
		 * The AI sees the issue information, but PHP remains the
		 * authority over whether a ticket can actually be used.
		 */
		$ticket_context =
			self::get_active_ticket_context(
				$conversation_id,
				$customer_id
			);

		$response =
			WP_RapidRescue_Chat_AI::respond(
				$message,
				$history,
				$ticket_context
			);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$assistant_text =
			isset( $response['text'] )
				? $response['text']
				: '';

		$ticket_id  = null;
		$ticket_key = null;
		$escalated  = false;

		$action = isset(
			$response['action']
		) && is_array(
			$response['action']
		)
			? $response['action']
			: array();

		$action_type = isset(
			$action['type']
		)
			? sanitize_key(
				$action['type']
			)
			: 'none';

		/*
		 * Existing ticket.
		 */
		if ( 'existing_ticket' === $action_type ) {

			$requested_ticket_key = isset(
				$action['ticket_key']
			)
				? strtoupper(
					sanitize_text_field(
						$action['ticket_key']
					)
				)
				: '';

			$ticket =
				WP_RapidRescue_Chat_Ticket::get_by_key(
					$requested_ticket_key
				);

			/*
			 * PHP verifies the ticket before allowing the AI's
			 * selection to affect the workflow.
			 */
			if (
				$ticket &&
				self::ticket_is_valid_for_context(
					$ticket,
					$conversation_id,
					$customer_id
				)
			) {

				$ticket_id  = absint( $ticket->id );
				$ticket_key = $ticket->ticket_key;
				$escalated  = true;

				$assistant_text = sprintf(
					'Your request matches your existing support ticket %s, so I\'ll keep it with that support request.',
					$ticket_key
				);

			} else {

				/*
				 * Never silently attach the customer to a ticket
				 * that PHP could not verify.
				 */
				$assistant_text =
					'I couldn\'t safely match this request to an existing support ticket. Please tell me which ticket or issue you\'re referring to.';
			}
		}

		/*
		 * New ticket.
		 */
		elseif ( 'create_ticket' === $action_type ) {

			$subject = isset(
				$action['subject']
			)
				? sanitize_text_field(
					$action['subject']
				)
				: '';

			$summary = isset(
				$action['summary']
			)
				? sanitize_textarea_field(
					$action['summary']
				)
				: '';

			$priority = isset(
				$action['priority']
			)
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

				$assistant_text =
					"I'm sorry, but I couldn't create the support request just now. No ticket has been created. Please try again in a moment.";

			} elseif (
				! empty(
					$ticket_result['ticket_key']
				)
			) {

				$ticket_id =
					! empty(
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
		 * Save the final assistant response.
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

	/**
	 * Get active ticket context for the AI.
	 *
	 * @param int      $conversation_id Conversation ID.
	 * @param int|null $customer_id Customer ID.
	 * @return array
	 */
	private static function get_active_ticket_context(
		$conversation_id,
		$customer_id
	) {

		$tickets = array();

		/*
		 * Customer history is useful because the customer may return
		 * in a completely new conversation to continue an old issue.
		 */
		if ( absint( $customer_id ) > 0 ) {

			$tickets =
				WP_RapidRescue_Chat_Ticket::get_by_customer(
					absint( $customer_id ),
					20
				);

		} else {

			$tickets =
				WP_RapidRescue_Chat_Ticket::get_by_conversation(
					absint( $conversation_id ),
					20
				);
		}

		if ( empty( $tickets ) ) {
			return array();
		}

		$context = array();

		foreach ( $tickets as $ticket ) {

			if (
				! in_array(
					$ticket->status,
					array(
						'open',
						'in_progress',
						'waiting_customer',
					),
					true
				)
			) {
				continue;
			}

			$context[] = array(
				'ticket_key' => sanitize_text_field(
					$ticket->ticket_key
				),
				'subject'    => sanitize_text_field(
					$ticket->subject
				),
				'status'     => sanitize_key(
					$ticket->status
				),
				'priority'   => sanitize_key(
					$ticket->priority
				),
				'summary'    => sanitize_textarea_field(
					$ticket->summary
				),
			);

			/*
			 * Keep the AI context deliberately small.
			 */
			if ( count( $context ) >= 10 ) {
				break;
			}
		}

		return $context;
	}

	/**
	 * Verify that a ticket can be used in the current context.
	 *
	 * @param object   $ticket Ticket object.
	 * @param int      $conversation_id Conversation ID.
	 * @param int|null $customer_id Customer ID.
	 * @return bool
	 */
	private static function ticket_is_valid_for_context(
		$ticket,
		$conversation_id,
		$customer_id
	) {

		if ( ! $ticket ) {
			return false;
		}

		if (
			! in_array(
				$ticket->status,
				array(
					'open',
					'in_progress',
					'waiting_customer',
				),
				true
			)
		) {
			return false;
		}

		$customer_id = absint(
			$customer_id
		);

		/*
		 * A known customer must own the ticket.
		 */
		if ( $customer_id > 0 ) {

			if (
				absint(
					$ticket->customer_id
				) !== $customer_id
			) {
				return false;
			}

			return true;
		}

		/*
		 * For anonymous customers, only the current conversation
		 * may establish the relationship.
		 */
		return absint(
			$ticket->conversation_id
		) === absint(
			$conversation_id
		);
	}
}