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
				'callback'            => array(
					__CLASS__,
					'chat',
				),
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

		/*
		 * Identify customer information from the current message.
		 *
		 * Email is an identifier only. It does not by itself authorize
		 * access to private ticket or account information.
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
		 * The session is used only for conversational continuity.
		 * Ticket access is verified against database records.
		 */
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

		/*
		 * Resolve explicit ticket references.
		 *
		 * This is intentionally database-based. A ticket number by
		 * itself is never sufficient for private ticket information.
		 */
		$ticket_lookup =
			self::resolve_explicit_ticket_reference(
				$message,
				$customer_id
			);

		/*
		 * If a ticket was verified, persist it as the active verified
		 * ticket for this conversation.
		 */
		if (
			! empty( $ticket_lookup['ticket'] ) &&
			! empty( $ticket_lookup['customer_verified'] )
		) {

			WP_RapidRescue_Chat_Conversation::set_active_ticket(
				$conversation_id,
				$ticket_lookup['ticket']->ticket_key,
				$customer_id
			);
		}

		/*
		 * If there was no explicit ticket number in this message,
		 * retrieve the previously verified active ticket.
		 *
		 * This is conversational continuity, not the original
		 * authentication mechanism.
		 */
		if (
			empty( $ticket_lookup['ticket'] ) &&
			absint( $customer_id ) > 0
		) {

			$active_state =
				WP_RapidRescue_Chat_Conversation::get_active_ticket_state(
					$conversation_id
				);

			if (
				! empty( $active_state['verified'] ) &&
				absint(
					$active_state['customer_id']
				) === absint( $customer_id ) &&
				! empty( $active_state['ticket_key'] )
			) {

				$active_ticket =
					WP_RapidRescue_Chat_Ticket::get_by_key(
						$active_state['ticket_key']
					);

				if (
					$active_ticket &&
					absint(
						$active_ticket->customer_id
					) === absint( $customer_id )
				) {

					$ticket_lookup['ticket'] =
						$active_ticket;

					$ticket_lookup['ticket_key'] =
						$active_ticket->ticket_key;

					$ticket_lookup['status'] =
						'verified';

					$ticket_lookup['customer_verified'] =
						true;
				}
			}
		}

		/*
		 * Build ticket context.
		 *
		 * IMPORTANT:
		 * We do NOT automatically expose every ticket belonging to a
		 * customer just because their email was supplied.
		 *
		 * Only the explicitly verified ticket or previously verified
		 * active ticket is sent to the AI.
		 */
		$ticket_context = array();

		if (
			! empty( $ticket_lookup['ticket'] ) &&
			! empty( $ticket_lookup['customer_verified'] )
		) {

			$ticket_context[] =
				self::ticket_to_context(
					$ticket_lookup['ticket'],
					! empty(
						$ticket_lookup['explicit']
					)
				);

		} elseif (
			! empty( $ticket_lookup['explicit'] )
		) {

			/*
			 * Do not tell the AI whether the ticket exists.
			 * It only needs to know that verification is required.
			 */
			$ticket_context[] = array(
				'ticket_key'         => '',
				'subject'            => '',
				'status'             => '',
				'priority'           => '',
				'summary'            => '',
				'explicit_reference' => true,
				'lookup_status'      => 'verification_required',
			);
		}

		/*
		 * Include recent conversation context.
		 */
		$history =
			WP_RapidRescue_Chat_Conversation::get_recent_messages(
				$conversation_id
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

		$action =
			isset( $response['action'] ) &&
			is_array( $response['action'] )
				? $response['action']
				: array();

		$action_type =
			isset( $action['type'] )
				? sanitize_key(
					$action['type']
				)
				: 'none';

		/*
		 * Sensitive ticket offer.
		 *
		 * No ticket is created here.
		 */
		if (
			'offer_sensitive_ticket' ===
			$action_type
		) {

			if ( absint( $customer_id ) < 1 ) {

				$assistant_text =
					'Before I can offer a support ticket for this request, I need your email address so our support team knows who to follow up with.';

			} else {

				$subject =
					isset( $action['subject'] )
						? sanitize_text_field(
							$action['subject']
						)
						: '';

				$summary =
					isset( $action['summary'] )
						? sanitize_textarea_field(
							$action['summary']
						)
						: '';

				$priority =
					isset( $action['priority'] )
						? sanitize_key(
							$action['priority']
						)
						: 'normal';

				$pending =
					WP_RapidRescue_Chat_Conversation::set_pending_escalation(
						$conversation_id,
						$subject,
						$summary,
						$priority
					);

				if ( is_wp_error( $pending ) ) {

					$assistant_text =
						'I\'m sorry, but I couldn\'t prepare the support request right now. No ticket has been created.';

				} else {

					/*
					 * Make absolutely sure the response does not claim
					 * that the ticket already exists.
					 */
					if ( '' === trim( $assistant_text ) ) {
						$assistant_text =
							'I can have a human support specialist review this request. Would you like me to create a support ticket?';
					}
				}
			}
		}

		/*
		 * Customer declined a pending sensitive ticket.
		 */
		elseif (
			'cancel_sensitive_ticket' ===
			$action_type
		) {

			WP_RapidRescue_Chat_Conversation::clear_pending_escalation(
				$conversation_id
			);

			$assistant_text =
				'No problem. I won\'t create a support ticket. I can still help with general information or troubleshooting.';
		}

		/*
		 * Existing verified ticket.
		 */
		elseif (
			'existing_ticket' ===
			$action_type
		) {

			$requested_ticket_key =
				isset( $action['ticket_key'] )
					? strtoupper(
						sanitize_text_field(
							$action['ticket_key']
						)
					)
					: '';

			$verified_ticket = null;

			/*
			 * Only allow an exact match to the PHP-verified ticket.
			 */
			if (
				'' !== $requested_ticket_key &&
				! empty( $ticket_lookup['ticket'] ) &&
				! empty(
					$ticket_lookup['customer_verified']
				) &&
				$requested_ticket_key ===
					$ticket_lookup['ticket']->ticket_key
			) {

				$candidate_ticket =
					$ticket_lookup['ticket'];

				if (
					absint(
						$candidate_ticket->customer_id
					) === absint( $customer_id )
				) {
					$verified_ticket =
						$candidate_ticket;
				}
			}

			if (
				$verified_ticket &&
				self::ticket_is_active(
					$verified_ticket
				)
			) {

				$ticket_id =
					absint(
						$verified_ticket->id
					);

				$ticket_key =
					$verified_ticket->ticket_key;

				$escalated = true;

				WP_RapidRescue_Chat_Conversation::set_active_ticket(
					$conversation_id,
					$ticket_key,
					$customer_id
				);

				$assistant_text = sprintf(
					'Your request matches your existing support ticket %s, so I\'ll keep it with that support request.',
					$ticket_key
				);

			} else {

				$assistant_text =
					'I couldn\'t safely match this request to a verified active support ticket.';
			}
		}

		/*
		 * Create ticket.
		 *
		 * PHP only permits this when there is a pending escalation
		 * that the customer has explicitly confirmed through the AI
		 * conversation.
		 */
		elseif (
			'create_ticket' ===
			$action_type
		) {

			$pending =
				WP_RapidRescue_Chat_Conversation::get_pending_escalation(
					$conversation_id
				);

			if ( empty( $pending ) ) {

				/*
				 * This prevents the AI from bypassing the confirmation
				 * workflow by simply returning create_ticket.
				 */
				$assistant_text =
					'I can prepare a support request for you, but I need your confirmation before creating the ticket. Would you like me to create one?';

				$ticket_id  = null;
				$ticket_key = null;
				$escalated  = false;

			} elseif (
				absint( $customer_id ) < 1
			) {

				$assistant_text =
					'Before I create the support ticket, I need your email address so our support team knows how to follow up with you.';

				$ticket_id  = null;
				$ticket_key = null;
				$escalated  = false;

			} else {

				/*
				 * IMPORTANT:
				 *
				 * Do not trust subject/summary/priority from the
				 * create_ticket AI action here.
				 *
				 * PHP uses the previously stored pending escalation.
				 */
				$ticket_result =
					WP_RapidRescue_Chat_Escalation::create_ticket(
						$conversation_id,
						$customer_id,
						$pending['subject'],
						$pending['summary'],
						$pending['priority']
					);

				if ( is_wp_error( $ticket_result ) ) {

					$assistant_text =
						'I\'m sorry, but I couldn\'t create the support request just now. No ticket has been created. Please try again in a moment.';

					$ticket_id  = null;
					$ticket_key = null;
					$escalated  = false;

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

					WP_RapidRescue_Chat_Conversation::clear_pending_escalation(
						$conversation_id
					);

					WP_RapidRescue_Chat_Conversation::set_active_ticket(
						$conversation_id,
						$ticket_key,
						$customer_id
					);

					$confirmation =
						WP_RapidRescue_Chat_Escalation::get_confirmation_message(
							$ticket_result
						);

					if (
						'' !== trim(
							$confirmation
						)
					) {
						$assistant_text =
							$confirmation;
					}

				} else {

					$assistant_text =
						'I\'m sorry, but the support request could not be confirmed. No ticket has been created.';

					$ticket_id  = null;
					$ticket_key = null;
					$escalated  = false;
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
					'customer_id'     =>
						$customer_id
							? absint(
								$customer_id
							)
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
	 * Resolve an explicit ticket reference.
	 *
	 * IMPORTANT:
	 * The ticket number is checked against the database, but private
	 * ticket information is only exposed after the email/customer
	 * relationship has been verified.
	 *
	 * @param string   $message Customer message.
	 * @param int|null $customer_id Customer ID.
	 * @return array
	 */
	private static function resolve_explicit_ticket_reference(
		$message,
		$customer_id
	) {

		$ticket_key =
			WP_RapidRescue_Chat_Ticket::extract_ticket_key(
				$message
			);

		$result = array(
			'explicit'          => '' !== $ticket_key,
			'ticket_key'        => $ticket_key,
			'status'            => '',
			'ticket'            => null,
			'customer_verified' => false,
		);

		if ( '' === $ticket_key ) {
			return $result;
		}

		/*
		 * No customer identity means the ticket cannot be verified.
		 */
		if ( absint( $customer_id ) < 1 ) {

			$result['status'] =
				'email_required';

			return $result;
		}

		$ticket =
			WP_RapidRescue_Chat_Ticket::get_by_key(
				$ticket_key
			);

		/*
		 * Do not expose whether the ticket exists.
		 */
		if ( ! $ticket ) {

			$result['status'] =
				'verification_failed';

			return $result;
		}

		/*
		 * Normal customer-owned ticket.
		 */
		if (
			absint(
				$ticket->customer_id
			) > 0
		) {

			if (
				absint(
					$ticket->customer_id
				) === absint( $customer_id )
			) {

				$result['customer_verified'] = true;
				$result['status']             = 'verified';
				$result['ticket']             = $ticket;

				return $result;
			}

			/*
			 * Wrong email/customer.
			 *
			 * Do not reveal anything about the ticket.
			 */
			$result['status'] =
				'verification_failed';

			return $result;
		}

		/*
		 * Legacy anonymous ticket.
		 *
		 * Because the customer has supplied BOTH:
		 * - an email-derived customer identity
		 * - the exact ticket number
		 *
		 * PHP may claim the legacy anonymous ticket for that customer.
		 *
		 * Future tickets will always have a customer_id.
		 */
		$assigned =
			WP_RapidRescue_Chat_Ticket::assign_customer(
				$ticket->id,
				$customer_id
			);

		if ( is_wp_error( $assigned ) ) {

			$result['status'] =
				'verification_failed';

			return $result;
		}

		$ticket =
			WP_RapidRescue_Chat_Ticket::get_by_id(
				$ticket->id
			);

		if ( ! $ticket ) {

			$result['status'] =
				'verification_failed';

			return $result;
		}

		$result['customer_verified'] = true;
		$result['status']             = 'verified';
		$result['ticket']             = $ticket;

		return $result;
	}

	/**
	 * Convert a verified ticket to limited AI context.
	 *
	 * @param object $ticket Ticket object.
	 * @param bool   $explicit_reference Explicitly referenced.
	 * @return array
	 */
	private static function ticket_to_context(
		$ticket,
		$explicit_reference = false
	) {

		return array(
			'ticket_key' =>
				sanitize_text_field(
					$ticket->ticket_key
				),
			'subject' =>
				sanitize_text_field(
					$ticket->subject
				),
			'status' =>
				sanitize_key(
					$ticket->status
				),
			'priority' =>
				sanitize_key(
					$ticket->priority
				),
			'summary' =>
				sanitize_textarea_field(
					$ticket->summary
				),
			'explicit_reference' =>
				$explicit_reference,
			'lookup_status' =>
				'verified',
		);
	}

	/**
	 * Determine whether a ticket is active.
	 *
	 * @param object $ticket Ticket object.
	 * @return bool
	 */
	private static function ticket_is_active(
		$ticket
	) {

		if ( ! $ticket ) {
			return false;
		}

		return in_array(
			$ticket->status,
			array(
				'open',
				'in_progress',
				'waiting_customer',
			),
			true
		);
	}
}