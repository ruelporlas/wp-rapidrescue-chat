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
		 * -------------------------------------------------------------
		 * 1. Identify the customer.
		 * -------------------------------------------------------------
		 *
		 * Email/name information may be supplied in the current
		 * message. If no identity is present, the existing conversation
		 * customer is recovered below.
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

		$customer_id = absint(
			$customer_id
		);

		/*
		 * -------------------------------------------------------------
		 * 2. Get or create the conversation.
		 * -------------------------------------------------------------
		 */
		$conversation =
			WP_RapidRescue_Chat_Conversation::get_or_create(
				$session_id,
				$customer_id
			);

		if ( is_wp_error( $conversation ) ) {
			return $conversation;
		}

		$conversation_id =
			absint(
				$conversation->id
			);

		/*
		 * If the current message did not contain an identity but this
		 * conversation already belongs to a customer, recover it.
		 */
		if (
			$customer_id < 1 &&
			! empty(
				$conversation->customer_id
			)
		) {

			$customer_id =
				absint(
					$conversation->customer_id
				);
		}

		/*
		 * -------------------------------------------------------------
		 * 3. Save the customer message.
		 * -------------------------------------------------------------
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
		 * -------------------------------------------------------------
		 * 4. Load conversation state.
		 * -------------------------------------------------------------
		 */
		$history =
			WP_RapidRescue_Chat_Conversation::get_recent_messages(
				$conversation_id
			);

		$pending_escalation =
			WP_RapidRescue_Chat_Conversation::get_pending_sensitive_escalation(
				$conversation_id
			);

		/*
		 * -------------------------------------------------------------
		 * 5. Determine whether this message explicitly confirms
		 *    a previously offered ticket.
		 * -------------------------------------------------------------
		 *
		 * This is an application-level permission.
		 *
		 * The AI cannot create this permission itself.
		 */
		$explicit_ticket_confirmation = false;

		if (
			$pending_escalation &&
			self::is_ticket_confirmation_message(
				$message
			)
		) {

			$explicit_ticket_confirmation = true;
		}

		/*
		 * If the customer explicitly rejects the pending ticket,
		 * cancel it before asking the AI to continue.
		 */
		if (
			$pending_escalation &&
			self::is_ticket_cancellation_message(
				$message
			)
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

			return self::response(
				$conversation_id,
				$customer_id,
				$assistant_text,
				false,
				null,
				null
			);
		}

		/*
		 * -------------------------------------------------------------
		 * 6. Build PHP-controlled AI tool context.
		 * -------------------------------------------------------------
		 *
		 * This is extremely important:
		 *
		 * The AI does NOT decide these values.
		 *
		 * PHP determines:
		 *
		 * - which customer this conversation belongs to
		 * - which conversation is being handled
		 * - whether ticket creation was explicitly confirmed
		 * - which tickets have already been verified
		 */
		$tool_context = array(
			'conversation_id' =>
				$conversation_id,

			'customer_id' =>
				$customer_id,

			'explicit_ticket_confirmation' =>
				$explicit_ticket_confirmation,

			'verified_ticket_keys' =>
				array(),
		);

		/*
		 * -------------------------------------------------------------
		 * 7. Load previously verified active ticket.
		 * -------------------------------------------------------------
		 */
		$active_ticket_key =
			WP_RapidRescue_Chat_Conversation::get_active_ticket_key(
				$conversation_id
			);

		$active_ticket_customer_id =
			WP_RapidRescue_Chat_Conversation::get_active_ticket_customer_id(
				$conversation_id
			);

		$ticket_context = array();

		if (
			'' !== $active_ticket_key &&
			(
				$active_ticket_customer_id < 1 ||
				(
					$customer_id > 0 &&
					$active_ticket_customer_id ===
					$customer_id
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

				/*
				 * IMPORTANT:
				 *
				 * An active ticket stored on the conversation is not
				 * automatically considered verified forever.
				 *
				 * The current tool security context must still know
				 * that this ticket was previously verified.
				 */
				$tool_context =
					WP_RapidRescue_Chat_Tool_Security::verify_ticket_context(
						$active_ticket_key,
						$tool_context
					);

				$ticket_context[] =
					self::ticket_to_context(
						$active_ticket,
						false
					);
			}
		}

		/*
		 * -------------------------------------------------------------
		 * 8. Pass the current request to the AI.
		 * -------------------------------------------------------------
		 *
		 * The AI can now decide:
		 *
		 * - search knowledge
		 * - get customer
		 * - lookup ticket
		 * - verify ticket
		 * - create ticket
		 *
		 * PHP controls whether each operation is actually allowed.
		 */
		$response =
			WP_RapidRescue_Chat_AI::respond(
				$message,
				$history,
				$ticket_context,
				$tool_context
			);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$assistant_text =
			isset(
				$response['text']
			)
				? trim(
					$response['text']
				)
				: '';

		if ( '' === $assistant_text ) {

			$assistant_text =
				'Sorry, I wasn\'t able to generate a response right now.';
		}

		/*
		 * -------------------------------------------------------------
		 * 9. Handle a pending escalation offer.
		 * -------------------------------------------------------------
		 *
		 * If the AI decided that a human ticket should be offered,
		 * store the request for the next customer confirmation.
		 *
		 * No ticket is created here.
		 */
		$action =
			isset(
				$response['action']
			) &&
			is_array(
				$response['action']
			)
				? $response['action']
				: array();

		$action_type =
			isset(
				$action['type']
			)
				? sanitize_key(
					$action['type']
				)
				: 'none';

		if (
			'offer_sensitive_ticket' ===
			$action_type &&
			! $explicit_ticket_confirmation
		) {

			if ( $customer_id < 1 ) {

				$assistant_text =
					'Before I prepare a support ticket, I need your email address so our support team knows how to follow up with you.';

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

				} elseif (
					'' === trim(
						$assistant_text
					)
				) {

					$assistant_text =
						'I can prepare a support ticket for a human specialist to review your request. Would you like me to create it?';
				}
			}
		}

		/*
		 * -------------------------------------------------------------
		 * 10. If the customer confirmed a pending ticket, the AI
		 *     should have called create_ticket.
		 * -------------------------------------------------------------
		 *
		 * The provider/tool layer will return the actual PHP-generated
		 * ticket information through tool_results.
		 */
		$tool_results =
			isset(
				$response['tool_results']
			) &&
			is_array(
				$response['tool_results']
			)
				? $response['tool_results']
				: array();

		$created_ticket =
			self::find_created_ticket(
				$tool_results
			);

		$ticket_id  = null;
		$ticket_key = null;
		$escalated  = false;

		if ( $created_ticket ) {

			$ticket_id =
				absint(
					$created_ticket['ticket_id']
				);

			$ticket_key =
				sanitize_text_field(
					$created_ticket['ticket_key']
				);

			if (
				$ticket_id > 0 &&
				'' !== $ticket_key
			) {

				$escalated = true;

				/*
				 * The ticket now actually exists.
				 */
				WP_RapidRescue_Chat_Conversation::clear_pending_sensitive_escalation(
					$conversation_id
				);

				WP_RapidRescue_Chat_Conversation::set_active_ticket(
					$conversation_id,
					$ticket_key,
					$customer_id
				);

				/*
				 * Do not let the AI invent the ticket number in its
				 * customer-facing response.
				 *
				 * PHP appends the authoritative ticket number.
				 */
				$assistant_text =
					self::build_ticket_confirmation(
						$ticket_key,
						$assistant_text
					);
			}
		}

		/*
		 * -------------------------------------------------------------
		 * 11. Save the assistant response.
		 * -------------------------------------------------------------
		 */
		WP_RapidRescue_Chat_Conversation::add_message(
			$conversation_id,
			'assistant',
			$assistant_text
		);

		return self::response(
			$conversation_id,
			$customer_id,
			$assistant_text,
			$escalated,
			$ticket_id,
			$ticket_key
		);
	}

	/**
	 * Build a REST response.
	 *
	 * @param int         $conversation_id Conversation ID.
	 * @param int         $customer_id Customer ID.
	 * @param string      $text Assistant response.
	 * @param bool        $escalated Whether a ticket was created.
	 * @param int|null    $ticket_id Ticket ID.
	 * @param string|null $ticket_key Ticket key.
	 * @return WP_REST_Response
	 */
	private static function response(
		$conversation_id,
		$customer_id,
		$text,
		$escalated,
		$ticket_id,
		$ticket_key
	) {

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'conversation_id' =>
						absint(
							$conversation_id
						),

					'customer_id' =>
						$customer_id
							? absint(
								$customer_id
							)
							: null,

					'text' =>
						$text,

					'escalated' =>
						(bool) $escalated,

					'ticket_id' =>
						$ticket_id
							? absint(
								$ticket_id
							)
							: null,

					'ticket_key' =>
						$ticket_key
							? sanitize_text_field(
								$ticket_key
							)
							: null,
				),
			)
		);
	}

	/**
	 * Find a successful create_ticket tool result.
	 *
	 * @param array $tool_results Tool results.
	 * @return array|null
	 */
	private static function find_created_ticket(
		$tool_results
	) {

		foreach (
			$tool_results as $tool_result
		) {

			if ( ! is_array( $tool_result ) ) {
				continue;
			}

			if (
				'create_ticket' !==
				(
					isset(
						$tool_result['tool']
					)
						? $tool_result['tool']
						: ''
				)
			) {
				continue;
			}

			$result =
				isset(
					$tool_result['result']
				) &&
				is_array(
					$tool_result['result']
				)
					? $tool_result['result']
					: array();

			if (
				empty(
					$result['success']
				)
			) {
				continue;
			}

			$ticket_id =
				isset(
					$result['ticket_id']
				)
					? absint(
						$result['ticket_id']
					)
					: 0;

			$ticket_key =
				isset(
					$result['ticket_key']
				)
					? sanitize_text_field(
						$result['ticket_key']
					)
					: '';

			if (
				$ticket_id < 1 ||
				'' === $ticket_key
			) {
				continue;
			}

			return array(
				'ticket_id' =>
					$ticket_id,
				'ticket_key' =>
					$ticket_key,
			);
		}

		return null;
	}

	/**
	 * Build a customer-facing ticket confirmation.
	 *
	 * @param string $ticket_key Authoritative PHP-generated key.
	 * @param string $assistant_text AI response.
	 * @return string
	 */
	private static function build_ticket_confirmation(
		$ticket_key,
		$assistant_text
	) {

		$ticket_key =
			sanitize_text_field(
				$ticket_key
			);

		$assistant_text =
			trim(
				(string) $assistant_text
			);

		/*
		 * If the AI already produced a sensible confirmation, append
		 * the authoritative ticket number.
		 */
		if ( '' !== $assistant_text ) {

			/*
			 * Remove any ticket number the AI may have hallucinated.
			 *
			 * PHP's generated ticket number is the only one we trust.
			 */
			$assistant_text =
				preg_replace(
					'/\bRR-\d{1,10}\b/i',
					'',
					$assistant_text
				);

			$assistant_text =
				trim(
					preg_replace(
						'/\s+/',
						' ',
						$assistant_text
					)
				);

			return $assistant_text .
				' Your support ticket number is ' .
				$ticket_key .
				'.';
		}

		return sprintf(
			'Your support request has been escalated to our support team. Your ticket number is %s.',
			$ticket_key
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
	 * Convert a verified ticket into limited AI context.
	 *
	 * @param object $ticket Ticket object.
	 * @param bool   $explicit_reference Explicit reference.
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
	 * Determine whether a message confirms ticket creation.
	 *
	 * @param string $message Customer message.
	 * @return bool
	 */
	private static function is_ticket_confirmation_message(
		$message
	) {

		$message =
			strtolower(
				trim(
					sanitize_textarea_field(
						$message
					)
				)
			);

		$message =
			preg_replace(
				'/[^\p{L}\p{N}\s]/u',
				' ',
				$message
			);

		$message =
			trim(
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
	 * Determine whether a message cancels ticket creation.
	 *
	 * @param string $message Customer message.
	 * @return bool
	 */
	private static function is_ticket_cancellation_message(
		$message
	) {

		$message =
			strtolower(
				trim(
					sanitize_textarea_field(
						$message
					)
				)
			);

		$message =
			preg_replace(
				'/[^\p{L}\p{N}\s]/u',
				' ',
				$message
			);

		$message =
			trim(
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
}