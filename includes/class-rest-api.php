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

		/*
		 * Extract any identity information from the current message.
		 *
		 * Email identifies a customer record. It is not required to be
		 * repeated on every message once the conversation already belongs
		 * to that customer.
		 */
		$identity = WP_RapidRescue_Chat_Customer::extract_identity(
			$message
		);

		$customer_id = WP_RapidRescue_Chat_Customer::find_or_create_from_identity(
			$identity
		);

		if ( is_wp_error( $customer_id ) ) {
			return $customer_id;
		}

		/*
		 * Get or create the current conversation.
		 *
		 * The browser session only maintains conversation continuity.
		 * The database remains the source of truth for the conversation
		 * and its associated customer.
		 */
		$conversation = WP_RapidRescue_Chat_Conversation::get_or_create(
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
		 * IMPORTANT:
		 *
		 * If the current message did not contain an email, recover the
		 * customer from the existing conversation.
		 *
		 * This is what allows:
		 *
		 *   test@test.com
		 *   ...
		 *   yes
		 *
		 * to continue using the same customer without asking for the
		 * email again.
		 */
		if ( absint( $customer_id ) < 1 && ! empty( $conversation->customer_id ) ) {
			$customer_id = absint( $conversation->customer_id );
		}

		/*
		 * Save the customer's message.
		 */
		$saved_message = WP_RapidRescue_Chat_Conversation::add_message(
			$conversation_id,
			'user',
			$message
		);

		if ( is_wp_error( $saved_message ) ) {
			return $saved_message;
		}

		/*
		 * Resolve an explicit ticket reference.
		 *
		 * Once the conversation is associated with a verified customer,
		 * the customer ID can be used for ticket ownership. The customer
		 * does not have to repeat their email on every message.
		 */
		$ticket_lookup = self::resolve_explicit_ticket_reference(
			$message,
			$customer_id
		);

		/*
		 * A successfully verified explicit ticket becomes the active
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
		 * If no explicit ticket was supplied, retrieve the previously
		 * verified active ticket.
		 */
		if ( empty( $ticket_lookup['ticket'] ) ) {

			$active_ticket_key =
				WP_RapidRescue_Chat_Conversation::get_active_ticket_key(
					$conversation_id
				);

			$active_ticket_customer_id =
				WP_RapidRescue_Chat_Conversation::get_active_ticket_customer_id(
					$conversation_id
				);

			if (
				'' !== $active_ticket_key &&
				(
					absint( $active_ticket_customer_id ) < 1 ||
					(
						absint( $customer_id ) > 0 &&
						absint( $active_ticket_customer_id ) === absint( $customer_id )
					)
				)
			) {

				$active_ticket =
					WP_RapidRescue_Chat_Ticket::get_by_key(
						$active_ticket_key
					);

				if (
					$active_ticket &&
					self::ticket_is_active(
						$active_ticket
					)
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
		 * Only verified ticket context is sent to the AI.
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
			 * Privacy-safe verification result.
			 */
			$ticket_context[] = array(
				'ticket_key'         => $ticket_lookup['ticket_key'],
				'lookup_status'      => 'not_verified',
				'explicit_reference' => true,
				'subject'            => '',
				'status'             => '',
				'priority'           => '',
				'summary'            => '',
			);
		}

		$history =
			WP_RapidRescue_Chat_Conversation::get_recent_messages(
				$conversation_id
			);

		/*
		 * Check whether there is already a pending sensitive escalation
		 * request.
		 */
		$pending_escalation =
			WP_RapidRescue_Chat_Conversation::get_pending_sensitive_escalation(
				$conversation_id
			);

		/*
		 * Deterministic confirmation.
		 *
		 * The AI does not need to interpret "yes" here. PHP already knows
		 * there is a pending ticket request.
		 */
		if (
			$pending_escalation &&
			self::is_ticket_confirmation_message( $message )
		) {

			return self::create_pending_ticket_response(
				$conversation_id,
				$customer_id,
				$pending_escalation
			);
		}

		/*
		 * Deterministic cancellation.
		 */
		if (
			$pending_escalation &&
			self::is_ticket_cancellation_message( $message )
		) {

			WP_RapidRescue_Chat_Conversation::clear_pending_sensitive_escalation(
				$conversation_id
			);

			$assistant_text =
				'No problem. I will not create the support ticket.';

			WP_RapidRescue_Chat_Conversation::add_message(
				$conversation_id,
				'assistant',
				$assistant_text
			);

			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => array(
						'conversation_id' => $conversation_id,
						'customer_id'     => $customer_id
							? absint( $customer_id )
							: null,
						'text'            => $assistant_text,
						'escalated'       => false,
						'ticket_id'       => null,
						'ticket_key'      => null,
					),
				)
			);
		}

		/*
		 * Ask the AI for the response.
		 */
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
		) &&
		is_array(
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
		 * Existing verified ticket.
		 */
		if ( 'existing_ticket' === $action_type ) {

			$requested_ticket_key =
				isset(
					$action['ticket_key']
				)
					? strtoupper(
						sanitize_text_field(
							$action['ticket_key']
						)
					)
					: '';

			$verified_ticket = null;

			if (
				'' !== $requested_ticket_key &&
				! empty( $ticket_lookup['ticket'] ) &&
				! empty( $ticket_lookup['customer_verified'] ) &&
				$requested_ticket_key ===
					$ticket_lookup['ticket']->ticket_key
			) {

				$verified_ticket =
					$ticket_lookup['ticket'];
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
					'I couldn\'t safely match this request to an active support ticket. Please provide the ticket number and the email address associated with that ticket.';
			}
		}

		/*
		 * Offer to create a new support ticket.
		 *
		 * No ticket is created here.
		 */
		elseif (
			'offer_sensitive_ticket' ===
			$action_type
		) {

			if ( absint( $customer_id ) < 1 ) {

				$assistant_text =
					'Before I prepare the support ticket, I need your email address so our support team knows how to follow up with you.';

			} else {

				$subject =
					isset(
						$action['subject']
					)
						? sanitize_text_field(
							$action['subject']
						)
						: 'Customer support request';

				$summary =
					isset(
						$action['summary']
					)
						? sanitize_textarea_field(
							$action['summary']
						)
						: '';

				$priority =
					isset(
						$action['priority']
					)
						? sanitize_key(
							$action['priority']
						)
						: 'normal';

				$reason =
					isset(
						$action['reason']
					)
						? sanitize_textarea_field(
							$action['reason']
						)
						: '';

				$pending_result =
					WP_RapidRescue_Chat_Conversation::set_pending_sensitive_escalation(
						$conversation_id,
						$subject,
						$summary,
						$priority,
						$reason
					);

				if ( is_wp_error( $pending_result ) ) {

					$assistant_text =
						'I\'m sorry, but I couldn\'t prepare the support request right now. No ticket has been created.';

				} else {

					$assistant_text =
						'I can prepare a support ticket for a human specialist to review your request. Would you like me to create it?';
				}
			}
		}

		/*
		 * AI-created ticket action is never allowed to bypass the
		 * application confirmation flow.
		 */
		elseif (
			'create_ticket' ===
			$action_type
		) {

			if ( ! $pending_escalation ) {

				$assistant_text =
					'I can prepare a support request for you, but I need your confirmation before creating the ticket. Would you like me to create one?';

			} elseif ( absint( $customer_id ) < 1 ) {

				$assistant_text =
					'Before I create the support ticket, I need your email address so our support team knows how to follow up with you.';

			} else {

				return self::create_pending_ticket_response(
					$conversation_id,
					$customer_id,
					$pending_escalation
				);
			}
		}

		/*
		 * Explicit cancellation requested by AI.
		 */
		elseif (
			'cancel_sensitive_ticket' ===
			$action_type
		) {

			WP_RapidRescue_Chat_Conversation::clear_pending_sensitive_escalation(
				$conversation_id
			);

			$assistant_text =
				'No problem. I will not create the support ticket.';
		}

		/*
		 * Save final assistant response.
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
	 * Resolve an explicit ticket reference.
	 *
	 * The existing conversation customer can be used to verify ownership.
	 * The customer does not need to repeat their email on every message.
	 *
	 * @param string   $message Customer message.
	 * @param int|null $customer_id Identified customer ID.
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
		 * We need an identified customer before revealing ticket details.
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

		if ( ! $ticket ) {

			$result['status'] =
				'not_verified';

			return $result;
		}

		/*
		 * Customer-owned ticket.
		 *
		 * The customer ID came either from the current email or from the
		 * already-established customer on this conversation.
		 */
		if ( absint( $ticket->customer_id ) > 0 ) {

			if (
				absint(
					$ticket->customer_id
				) === absint(
					$customer_id
				)
			) {

				$result['customer_verified'] =
					true;

				$result['status'] =
					'verified';

				$result['ticket'] =
					$ticket;

				return $result;
			}

			$result['status'] =
				'not_verified';

			return $result;
		}

		/*
		 * Legacy anonymous tickets are not automatically disclosed.
		 */
		$result['status'] =
			'not_verified';

		return $result;
	}

	/**
	 * Convert a verified ticket into limited AI context.
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

	/**
	 * Determine whether a customer message explicitly confirms
	 * creation of a pending ticket.
	 *
	 * @param string $message Customer message.
	 * @return bool
	 */
	private static function is_ticket_confirmation_message(
		$message
	) {

		$message = strtolower(
			trim(
				sanitize_textarea_field(
					$message
				)
			)
		);

		$message = preg_replace(
			'/[^\p{L}\p{N}\s]/u',
			' ',
			$message
		);

		$message = trim(
			preg_replace(
				'/\s+/',
				' ',
				$message
			)
		);

		$confirmations = array(
			'yes',
			'yes please',
			'yes create',
			'yes create it',
			'yes create a ticket',
			'create it',
			'create the ticket',
			'create ticket',
			'please create it',
			'please create the ticket',
			'please create a ticket',
			'confirm',
			'i confirm',
			'i agree',
			'go ahead',
			'go ahead and create it',
			'go ahead and create the ticket',
			'go ahead create it',
			'do it',
			'please do',
			'sure',
			'sure create it',
			'ok',
			'okay',
			'go',
		);

		return in_array(
			$message,
			$confirmations,
			true
		);
	}

	/**
	 * Determine whether a customer cancelled a pending ticket.
	 *
	 * @param string $message Customer message.
	 * @return bool
	 */
	private static function is_ticket_cancellation_message(
		$message
	) {

		$message = strtolower(
			trim(
				sanitize_textarea_field(
					$message
				)
			)
		);

		$message = preg_replace(
			'/[^\p{L}\p{N}\s]/u',
			' ',
			$message
		);

		$message = trim(
			preg_replace(
				'/\s+/',
				' ',
				$message
			)
		);

		$cancellations = array(
			'no',
			'no thanks',
			'cancel',
			'cancel it',
			'dont create it',
			'do not create it',
			'dont create the ticket',
			'do not create the ticket',
			'i changed my mind',
			'never mind',
			'nevermind',
		);

		return in_array(
			$message,
			$cancellations,
			true
		);
	}

	/**
	 * Create the ticket stored in the pending escalation state.
	 *
	 * @param int   $conversation_id Conversation ID.
	 * @param int   $customer_id Customer ID.
	 * @param array $pending Pending escalation data.
	 * @return WP_REST_Response
	 */
	private static function create_pending_ticket_response(
		$conversation_id,
		$customer_id,
		$pending
	) {

		$conversation_id = absint(
			$conversation_id
		);

		$customer_id = absint(
			$customer_id
		);

		if (
			$conversation_id < 1 ||
			$customer_id < 1 ||
			! is_array( $pending )
		) {

			$assistant_text =
				'Before I create the support ticket, I need your email address so our support team knows how to follow up with you.';

			if ( $conversation_id > 0 ) {
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
							? $customer_id
							: null,
						'text'            => $assistant_text,
						'escalated'       => false,
						'ticket_id'       => null,
						'ticket_key'      => null,
					),
				)
			);
		}

		$subject = isset(
			$pending['subject']
		)
			? sanitize_text_field(
				$pending['subject']
			)
			: 'Customer support request';

		$summary = isset(
			$pending['summary']
		)
			? sanitize_textarea_field(
				$pending['summary']
			)
			: '';

		$priority = isset(
			$pending['priority']
		)
			? sanitize_key(
				$pending['priority']
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
				'I\'m sorry, but I couldn\'t create the support request just now. No ticket has been created. Please try again in a moment.';

			WP_RapidRescue_Chat_Conversation::add_message(
				$conversation_id,
				'assistant',
				$assistant_text
			);

			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => array(
						'conversation_id' => $conversation_id,
						'customer_id'     => $customer_id,
						'text'            => $assistant_text,
						'escalated'       => false,
						'ticket_id'       => null,
						'ticket_key'      => null,
					),
				)
			);
		}

		if (
			empty(
				$ticket_result['ticket_key']
			)
		) {

			$assistant_text =
				'I\'m sorry, but the support request could not be confirmed. No ticket has been created.';

			WP_RapidRescue_Chat_Conversation::add_message(
				$conversation_id,
				'assistant',
				$assistant_text
			);

			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => array(
						'conversation_id' => $conversation_id,
						'customer_id'     => $customer_id,
						'text'            => $assistant_text,
						'escalated'       => false,
						'ticket_id'       => null,
						'ticket_key'      => null,
					),
				)
			);
		}

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

		/*
		 * The ticket now exists and has been confirmed by PHP.
		 */
		WP_RapidRescue_Chat_Conversation::clear_pending_sensitive_escalation(
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

		if ( '' === trim( $confirmation ) ) {

			$confirmation = sprintf(
				'Your support request has been escalated to our support team. Your ticket number is %s.',
				$ticket_key
			);
		}

		WP_RapidRescue_Chat_Conversation::add_message(
			$conversation_id,
			'assistant',
			$confirmation
		);

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'conversation_id' => $conversation_id,
					'customer_id'     => $customer_id,
					'text'            => $confirmation,
					'escalated'       => true,
					'ticket_id'       => $ticket_id,
					'ticket_key'      => $ticket_key,
				),
			)
		);
	}
}