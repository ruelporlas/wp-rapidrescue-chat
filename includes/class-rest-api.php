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
		 * 1. Identify the customer from the current message.
		 * -------------------------------------------------------------
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
		 * If no customer was identified in this message, recover the
		 * customer already attached to the conversation.
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
		 * 3. Save customer message.
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
		 * 5. Handle pending escalation confirmation/cancellation.
		 * -------------------------------------------------------------
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
		 * 6. Create the initial PHP-controlled tool context.
		 * -------------------------------------------------------------
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
		 * 7. Resolve an explicitly supplied ticket number.
		 * -------------------------------------------------------------
		 *
		 * THIS IS NOW PHP-CONTROLLED.
		 *
		 * The AI does not get to decide whether the ticket is verified.
		 *
		 * If the customer says:
		 *
		 *     RR-00005
		 *
		 * PHP immediately performs the ticket lookup.
		 *
		 * If the customer has an identified email, PHP also performs
		 * the actual ticket/email verification.
		 */
		$explicit_ticket_key =
			WP_RapidRescue_Chat_Ticket::extract_ticket_key(
				$message
			);

		$ticket_context = array();

		if ( '' !== $explicit_ticket_key ) {

			$ticket_resolution =
				self::resolve_explicit_ticket(
					$explicit_ticket_key,
					$customer_id,
					$tool_context
				);

			$tool_context =
				$ticket_resolution['context'];

			$ticket_context =
				$ticket_resolution['ticket_context'];

			/*
			 * If PHP successfully verified this ticket, make it the
			 * active ticket for this conversation.
			 */
			if (
				! empty(
					$ticket_resolution['verified']
				)
			) {

				WP_RapidRescue_Chat_Conversation::set_active_ticket(
					$conversation_id,
					$explicit_ticket_key,
					$customer_id
				);
			}
		}

		/*
		 * -------------------------------------------------------------
		 * 8. If there was no explicit ticket number, use the previously
		 *    verified active ticket.
		 * -------------------------------------------------------------
		 *
		 * IMPORTANT:
		 *
		 * get_active_ticket_key() only returns a ticket when the
		 * conversation explicitly stored active_ticket_verified=true.
		 *
		 * We DO NOT call verify_ticket_context() here.
		 *
		 * The stored conversation state is the record that the ticket
		 * was already successfully verified in this conversation.
		 */
		if ( '' === $explicit_ticket_key ) {

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
				$customer_id > 0 &&
				$active_ticket_customer_id === $customer_id
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
					 * The conversation itself records that this ticket
					 * was previously verified.
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
		}

		/*
		 * -------------------------------------------------------------
		 * 9. Ask the AI to respond.
		 * -------------------------------------------------------------
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
		 * 10. Process AI action.
		 * -------------------------------------------------------------
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

		/*
		 * -------------------------------------------------------------
		 * 11. Store pending escalation offers.
		 * -------------------------------------------------------------
		 */
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
				}
			}
		}

		/*
		 * -------------------------------------------------------------
		 * 12. Process successful ticket creation.
		 * -------------------------------------------------------------
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

				WP_RapidRescue_Chat_Conversation::clear_pending_sensitive_escalation(
					$conversation_id
				);

				/*
				 * A ticket created for the current customer is
				 * immediately considered accessible in this
				 * conversation.
				 */
				WP_RapidRescue_Chat_Conversation::set_active_ticket(
					$conversation_id,
					$ticket_key,
					$customer_id
				);

				$assistant_text =
					self::build_ticket_confirmation(
						$ticket_key,
						$assistant_text
					);
			}
		}

		/*
		 * -------------------------------------------------------------
		 * 13. Save assistant response.
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
	 * Resolve an explicitly supplied ticket number.
	 *
	 * PHP, not the AI, owns this decision.
	 *
	 * @param string $ticket_key Ticket number.
	 * @param int    $customer_id Current customer ID.
	 * @param array  $context Tool context.
	 * @return array
	 */
	private static function resolve_explicit_ticket(
		$ticket_key,
		$customer_id,
		$context
	) {

		$ticket_key =
			strtoupper(
				sanitize_text_field(
					$ticket_key
				)
			);

		$result = array(
			'verified'       => false,
			'context'        => $context,
			'ticket_context' => array(),
		);

		if (
			'' === $ticket_key ||
			! preg_match(
				'/^RR-\d{1,10}$/',
				$ticket_key
			)
		) {
			return $result;
		}

		/*
		 * First perform the privacy-safe lookup.
		 */
		$lookup =
			WP_RapidRescue_Chat_Tool_Manager::execute(
				'lookup_ticket',
				array(
					'ticket_key' => $ticket_key,
				),
				$context
			);

		if ( is_wp_error( $lookup ) ) {

			$result['ticket_context'][] = array(
				'ticket_key' =>
					$ticket_key,
				'explicit_reference' =>
					true,
				'lookup_status' =>
					'not_verified',
			);

			return $result;
		}

		/*
		 * If the ticket does not exist, do not reveal that fact in a
		 * way that could disclose information about another customer.
		 */
		if (
			empty(
				$lookup['found']
			)
		) {

			$result['ticket_context'][] = array(
				'ticket_key' =>
					$ticket_key,
				'explicit_reference' =>
					true,
				'lookup_status' =>
					'not_verified',
			);

			return $result;
		}

		/*
		 * -------------------------------------------------------------
		 * Determine whether PHP already has a customer email.
		 * -------------------------------------------------------------
		 */
		$email = '';

		if ( $customer_id > 0 ) {

			$customer =
				WP_RapidRescue_Chat_Customer::get_by_id(
					$customer_id
				);

			if (
				$customer &&
				! empty(
					$customer->email
				)
			) {

				$email =
					sanitize_email(
						$customer->email
					);
			}
		}

		/*
		 * No customer email means the ticket cannot be verified yet.
		 *
		 * We intentionally do not expose any ticket-private fields.
		 */
		if ( '' === $email ) {

			$result['ticket_context'][] = array(
				'ticket_key' =>
					$ticket_key,
				'explicit_reference' =>
					true,
				'lookup_status' =>
					'not_verified',
				'requires_email' =>
					true,
			);

			return $result;
		}

		/*
		 * -------------------------------------------------------------
		 * Perform the REAL PHP authorization check.
		 * -------------------------------------------------------------
		 */
		$verify =
			WP_RapidRescue_Chat_Tool_Manager::execute(
				'verify_ticket',
				array(
					'ticket_key' =>
						$ticket_key,
					'email' =>
						$email,
				),
				$context
			);

		if (
			is_wp_error( $verify ) ||
			! is_array( $verify ) ||
			empty(
				$verify['verified']
			)
		) {

			/*
			 * Never reveal whether the ticket exists but belongs to
			 * someone else.
			 */
			$result['ticket_context'][] = array(
				'ticket_key' =>
					$ticket_key,
				'explicit_reference' =>
					true,
				'lookup_status' =>
					'not_verified',
			);

			return $result;
		}

		/*
		 * -------------------------------------------------------------
		 * SUCCESS.
		 *
		 * Only now may private ticket information reach the AI.
		 * -------------------------------------------------------------
		 */
		if (
			isset(
				$verify['context']
			) &&
			is_array(
				$verify['context']
			) &&
			isset(
				$verify['context']['verified_ticket_keys']
			) &&
			is_array(
				$verify['context']['verified_ticket_keys']
			)
		) {

			$result['context']['verified_ticket_keys'] =
				$verify['context']['verified_ticket_keys'];
		}

		$result['context']['customer_id'] =
			$customer_id;

		$ticket =
			isset(
				$verify['ticket']
			) &&
			is_array(
				$verify['ticket']
			)
				? $verify['ticket']
				: array();

		$result['ticket_context'][] = array(
			'ticket_key' =>
				$ticket_key,

			'subject' =>
				isset(
					$ticket['subject']
				)
					? $ticket['subject']
					: '',

			'status' =>
				isset(
					$ticket['status']
				)
					? $ticket['status']
					: '',

			'priority' =>
				isset(
					$ticket['priority']
				)
					? $ticket['priority']
					: '',

			'summary' =>
				isset(
					$ticket['summary']
				)
					? $ticket['summary']
					: '',

			'explicit_reference' =>
				true,

			'lookup_status' =>
				'verified',
		);

		$result['verified'] = true;

		return $result;
	}

	/**
	 * Build REST response.
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
	 * Build customer-facing ticket confirmation.
	 *
	 * @param string $ticket_key Authoritative ticket key.
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

		if ( '' !== $assistant_text ) {

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
	 * @param object $ticket Ticket.
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
	 * Convert a verified ticket to AI context.
	 *
	 * @param object $ticket Ticket.
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