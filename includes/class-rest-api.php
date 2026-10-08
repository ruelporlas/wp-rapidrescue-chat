<?php
/**
 * REST API endpoints.
 *
 * @package WP_RapidRescue_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Temporary debug logger.
 */
$debug_file =
	WP_RAPIDRESCUE_CHAT_PATH .
	'includes/class-debug.php';

if ( file_exists( $debug_file ) ) {
	require_once $debug_file;
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

		WP_RapidRescue_Chat_Debug::rest(
			'Message received'
		);

		$message = sanitize_textarea_field(
			$request->get_param( 'message' )
		);

		$session_id = sanitize_text_field(
			$request->get_param( 'session_id' )
		);

		if ( '' === trim( $message ) ) {

			WP_RapidRescue_Chat_Debug::rest(
				'Message rejected: empty'
			);

			return self::error_response(
				'empty_message',
				'Please enter a message.',
				400
			);
		}

		if ( '' === trim( $session_id ) ) {

			WP_RapidRescue_Chat_Debug::rest(
				'Message rejected: invalid session'
			);

			return self::error_response(
				'invalid_session',
				'The chat session is invalid.',
				400
			);
		}

		WP_RapidRescue_Chat_Debug::rest(
			'Request validated',
			array(
				'session_id' =>
					substr(
						$session_id,
						0,
						8
					) . '...',

				'message_length' =>
					strlen( $message ),
			)
		);

		/*
		 * -------------------------------------------------------------
		 * 1. Identify customer.
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

			WP_RapidRescue_Chat_Debug::rest(
				'Customer identity lookup failed',
				array(
					'error_code' =>
						$customer_id->get_error_code(),
				)
			);

			return self::error_from_wp_error(
				$customer_id
			);
		}

		$customer_id =
			absint(
				$customer_id
			);

		WP_RapidRescue_Chat_Debug::rest(
			'Customer identity processed',
			array(
				'customer_id' =>
					$customer_id,

				'identity_email_present' =>
					! empty(
						$identity['email']
					),
			)
		);

		/*
		 * -------------------------------------------------------------
		 * 2. Get or create conversation.
		 * -------------------------------------------------------------
		 */
		$conversation =
			WP_RapidRescue_Chat_Conversation::get_or_create(
				$session_id,
				$customer_id
			);

		if ( is_wp_error( $conversation ) ) {

			WP_RapidRescue_Chat_Debug::rest(
				'Conversation creation failed',
				array(
					'error_code' =>
						$conversation->get_error_code(),
				)
			);

			return self::error_from_wp_error(
				$conversation
			);
		}

		$conversation_id =
			absint(
				$conversation->id
			);

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

			WP_RapidRescue_Chat_Debug::rest(
				'Recovered customer from conversation',
				array(
					'customer_id' =>
						$customer_id,
				)
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

			WP_RapidRescue_Chat_Debug::rest(
				'Customer message could not be saved',
				array(
					'error_code' =>
						$saved_message->get_error_code(),
				)
			);

			return self::error_from_wp_error(
				$saved_message
			);
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

		WP_RapidRescue_Chat_Debug::rest(
			'Conversation state loaded',
			array(
				'history_count' =>
					is_array( $history )
						? count( $history )
						: 0,

				'pending_escalation' =>
					(bool) $pending_escalation,
			)
		);

		/*
		 * -------------------------------------------------------------
		 * 5. Pending escalation confirmation/cancellation.
		 * -------------------------------------------------------------
		 */
		$explicit_ticket_confirmation =
			false;

		if (
			$pending_escalation &&
			self::is_ticket_confirmation_message(
				$message
			)
		) {

			$explicit_ticket_confirmation =
				true;

			WP_RapidRescue_Chat_Debug::ticket(
				'Explicit ticket creation confirmation detected'
			);
		}

		if (
			$pending_escalation &&
			self::is_ticket_cancellation_message(
				$message
			)
		) {

			WP_RapidRescue_Chat_Debug::ticket(
				'Ticket creation cancelled by customer'
			);

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
		 * 6. PHP-controlled tool context.
		 * -------------------------------------------------------------
		 */
		$tool_context =
			array(
				'conversation_id' =>
					$conversation_id,

				'customer_id' =>
					$customer_id,

				'explicit_ticket_confirmation' =>
					$explicit_ticket_confirmation,

				'verified_ticket_keys' =>
					array(),
			);

		$ticket_context =
			array();

		/*
		 * -------------------------------------------------------------
		 * 7. Detect explicit ticket number.
		 * -------------------------------------------------------------
		 */
		$explicit_ticket_key =
			WP_RapidRescue_Chat_Ticket::extract_ticket_key(
				$message
			);

		if ( '' !== $explicit_ticket_key ) {

			WP_RapidRescue_Chat_Debug::ticket(
				'Ticket detected',
				array(
					'ticket_key' =>
						$explicit_ticket_key,
				)
			);
		} else {

			WP_RapidRescue_Chat_Debug::ticket(
				'No explicit ticket number detected'
			);
		}

		/*
		 * -------------------------------------------------------------
		 * 8. Explicit ticket.
		 * -------------------------------------------------------------
		 */
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

			WP_RapidRescue_Chat_Debug::ticket(
				'Explicit ticket resolution completed',
				array(
					'verified' =>
						! empty(
							$ticket_resolution['verified']
						),

					'context_count' =>
						count( $ticket_context ),
				)
			);

			if (
				! empty(
					$ticket_resolution['verified']
				)
			) {

				$verified_customer_id =
					isset(
						$ticket_resolution['verified_customer_id']
					)
						? absint(
							$ticket_resolution['verified_customer_id']
						)
						: $customer_id;

				if (
					$verified_customer_id > 0
				) {

					$customer_id =
						$verified_customer_id;
				}

				WP_RapidRescue_Chat_Conversation::set_active_ticket(
					$conversation_id,
					$explicit_ticket_key,
					$customer_id
				);

				WP_RapidRescue_Chat_Debug::ticket(
					'Ticket verified and stored as active ticket'
				);
			}
		}

		/*
		 * -------------------------------------------------------------
		 * 9. Email-only verification follow-up.
		 * -------------------------------------------------------------
		 */
		if (
			'' === $explicit_ticket_key &&
			self::looks_like_email(
				$message
			)
		) {

			WP_RapidRescue_Chat_Debug::rest(
				'Detected email-only message'
			);

			$pending_ticket_key =
				self::find_ticket_waiting_for_email(
					$history
				);

			if ( '' !== $pending_ticket_key ) {

				WP_RapidRescue_Chat_Debug::ticket(
					'Found ticket awaiting email verification',
					array(
						'ticket_key' =>
							$pending_ticket_key,
					)
				);

				$verification =
					self::verify_ticket_from_email_message(
						$pending_ticket_key,
						$message,
						$customer_id,
						$tool_context
					);

				if (
					! empty(
						$verification['verified']
					)
				) {

					$verified_customer_id =
						isset(
							$verification['verified_customer_id']
						)
							? absint(
								$verification['verified_customer_id']
							)
							: $customer_id;

					if (
						$verified_customer_id > 0
					) {

						$customer_id =
							$verified_customer_id;

						$tool_context['customer_id'] =
							$verified_customer_id;
					}

					$tool_context =
						$verification['context'];

					$ticket_context =
						$verification['ticket_context'];

					WP_RapidRescue_Chat_Conversation::set_active_ticket(
						$conversation_id,
						$pending_ticket_key,
						$customer_id
					);

					WP_RapidRescue_Chat_Debug::ticket(
						'Email verification succeeded'
					);

				} else {

					$ticket_context =
						$verification['ticket_context'];

					WP_RapidRescue_Chat_Debug::ticket(
						'Email verification failed'
					);
				}

			} else {

				WP_RapidRescue_Chat_Debug::ticket(
					'No ticket found for email verification'
				);
			}
		}

		/*
		 * -------------------------------------------------------------
		 * 10. Previously verified active ticket.
		 * -------------------------------------------------------------
		 */
		if (
			'' === $explicit_ticket_key &&
			empty( $ticket_context )
		) {

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

					WP_RapidRescue_Chat_Debug::ticket(
						'Using previously verified active ticket',
						array(
							'ticket_key' =>
								$active_ticket_key,

							'status' =>
								$active_ticket->status,
						)
					);
				}
			}
		}

		/*
		 * -------------------------------------------------------------
		 * 11. AI response.
		 * -------------------------------------------------------------
		 */
		WP_RapidRescue_Chat_Debug::ai(
			'Calling AI provider',
			array(
				'ticket_context_count' =>
					count( $ticket_context ),

				'customer_id' =>
					$customer_id,
			)
		);

		$response =
			WP_RapidRescue_Chat_AI::respond(
				$message,
				$history,
				$ticket_context,
				$tool_context
			);

		if ( is_wp_error( $response ) ) {

			WP_RapidRescue_Chat_Debug::ai(
				'AI provider returned WP_Error',
				array(
					'error_code' =>
						$response->get_error_code(),

					'error_message' =>
						$response->get_error_message(),
				)
			);

			return self::error_from_wp_error(
				$response
			);
		}

		WP_RapidRescue_Chat_Debug::ai(
			'AI response received',
			array(
				'action_present' =>
					isset( $response['action'] ) &&
					is_array( $response['action'] ),

				'tool_results_count' =>
					isset( $response['tool_results'] ) &&
					is_array( $response['tool_results'] )
						? count(
							$response['tool_results']
						)
						: 0,
			)
		);

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
		 * 12. Process AI action.
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

		WP_RapidRescue_Chat_Debug::ai(
			'AI action processed',
			array(
				'action_type' =>
					$action_type,
			)
		);

		/*
		 * -------------------------------------------------------------
		 * 13. Pending escalation offer.
		 * -------------------------------------------------------------
		 */
		if (
			'offer_sensitive_ticket' ===
			$action_type &&
			! $explicit_ticket_confirmation
		) {

			WP_RapidRescue_Chat_Debug::ticket(
				'AI offered ticket creation'
			);

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

					WP_RapidRescue_Chat_Debug::ticket(
						'Failed to store pending ticket offer',
						array(
							'error_code' =>
								$pending_result->get_error_code(),
						)
					);

					$assistant_text =
						'I\'m sorry, but I couldn\'t prepare the support request right now. No ticket has been created.';
				}
			}
		}

		/*
		 * -------------------------------------------------------------
		 * 14. Successful ticket creation.
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

				$escalated =
					true;

				WP_RapidRescue_Chat_Debug::ticket(
					'Ticket creation confirmed by PHP',
					array(
						'ticket_id' =>
							$ticket_id,

						'ticket_key' =>
							$ticket_key,
					)
				);

				WP_RapidRescue_Chat_Conversation::clear_pending_sensitive_escalation(
					$conversation_id
				);

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
		 * 15. Save assistant response.
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
	 * Return a REST error while preserving the temporary debug trace.
	 *
	 * @param string $code HTTP/application error code.
	 * @param string $message Error message.
	 * @param int    $status HTTP status.
	 * @return WP_Error
	 */
	private static function error_response(
		$code,
		$message,
		$status = 500
	) {

		return new WP_Error(
			$code,
			$message,
			array(
				'status' =>
					absint( $status ),

				'debug_trace' =>
					WP_RapidRescue_Chat_Debug::get_trace(),
			)
		);
	}

	/**
	 * Convert an existing WP_Error to a REST error with debug trace.
	 *
	 * @param WP_Error $error Existing error.
	 * @return WP_Error
	 */
	private static function error_from_wp_error(
		$error
	) {

		$status =
			500;

		$data =
			$error->get_error_data();

		if (
			is_array( $data ) &&
			isset( $data['status'] )
		) {

			$status =
				absint(
					$data['status']
				);
		}

		$new_data =
			array(
				'status' =>
					$status,

				'debug_trace' =>
					WP_RapidRescue_Chat_Debug::get_trace(),
			);

		return new WP_Error(
			$error->get_error_code(),
			$error->get_error_message(),
			$new_data
		);
	}

	/**
	 * Resolve explicit ticket.
	 *
	 * @param string $ticket_key Ticket key.
	 * @param int    $customer_id Customer ID.
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

		$result =
			array(
				'verified' =>
					false,

				'verified_customer_id' =>
					$customer_id,

				'context' =>
					$context,

				'ticket_context' =>
					array(),
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

		WP_RapidRescue_Chat_Debug::tool(
			'Calling lookup_ticket',
			array(
				'ticket_key' =>
					$ticket_key,
			)
		);

		$lookup =
			WP_RapidRescue_Chat_Tool_Manager::execute(
				'lookup_ticket',
				array(
					'ticket_key' =>
						$ticket_key,
				),
				$context
			);

		if ( is_wp_error( $lookup ) ) {

			WP_RapidRescue_Chat_Debug::tool(
				'lookup_ticket returned WP_Error',
				array(
					'error_code' =>
						$lookup->get_error_code(),
				)
			);

			$result['ticket_context'][] =
				array(
					'ticket_key' =>
						$ticket_key,

					'explicit_reference' =>
						true,

					'lookup_status' =>
						'not_verified',
				);

			return $result;
		}

		WP_RapidRescue_Chat_Debug::tool(
			'lookup_ticket returned',
			array(
				'success' =>
					isset(
						$lookup['success']
					)
						? $lookup['success']
						: false,

				'state' =>
					isset(
						$lookup['state']
					)
						? $lookup['state']
						: '',

				'next_action' =>
					isset(
						$lookup['next_action']
					)
						? $lookup['next_action']
						: '',

				'found' =>
					isset(
						$lookup['found']
					)
						? $lookup['found']
						: false,
			)
		);

		if (
			empty(
				$lookup['found']
			)
		) {

			$result['ticket_context'][] =
				array(
					'ticket_key' =>
						$ticket_key,

					'explicit_reference' =>
						true,

					'lookup_status' =>
						'not_verified',
				);

			return $result;
		}

		$email =
			'';

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

		if ( '' === $email ) {

			WP_RapidRescue_Chat_Debug::ticket(
				'Ticket requires associated email'
			);

			$result['ticket_context'][] =
				array(
					'ticket_key' =>
						$ticket_key,

					'explicit_reference' =>
						true,

					'lookup_status' =>
						'email_required',

					'requires_email' =>
						true,
				);

			return $result;
		}

		return self::perform_ticket_verification(
			$ticket_key,
			$email,
			$customer_id,
			$context
		);
	}

	/**
	 * Verify ticket from email-only follow-up.
	 *
	 * @param string $ticket_key Ticket key.
	 * @param string $email Email.
	 * @param int    $customer_id Customer ID.
	 * @param array  $context Context.
	 * @return array
	 */
	private static function verify_ticket_from_email_message(
		$ticket_key,
		$email,
		$customer_id,
		$context
	) {

		return self::perform_ticket_verification(
			$ticket_key,
			$email,
			$customer_id,
			$context
		);
	}

	/**
	 * Perform authoritative ticket verification.
	 *
	 * @param string $ticket_key Ticket key.
	 * @param string $email Email.
	 * @param int    $customer_id Customer ID.
	 * @param array  $context Context.
	 * @return array
	 */
	private static function perform_ticket_verification(
		$ticket_key,
		$email,
		$customer_id,
		$context
	) {

		$result =
			array(
				'verified' =>
					false,

				'verified_customer_id' =>
					$customer_id,

				'context' =>
					$context,

				'ticket_context' =>
					array(),
			);

		WP_RapidRescue_Chat_Debug::tool(
			'Calling verify_ticket',
			array(
				'ticket_key' =>
					$ticket_key,

				'email' =>
					$email,
			)
		);

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
			! is_array( $verify )
		) {

			WP_RapidRescue_Chat_Debug::tool(
				'verify_ticket returned invalid result'
			);

			$result['ticket_context'][] =
				array(
					'ticket_key' =>
						$ticket_key,

					'explicit_reference' =>
						true,

					'lookup_status' =>
						'not_verified',
				);

			return $result;
		}

		$state =
			isset(
				$verify['state']
			)
				? sanitize_key(
					$verify['state']
				)
				: '';

		WP_RapidRescue_Chat_Debug::tool(
			'verify_ticket returned',
			array(
				'success' =>
					! empty(
						$verify['success']
					),

				'state' =>
					$state,

				'next_action' =>
					isset(
						$verify['next_action']
					)
						? $verify['next_action']
						: '',
			)
		);

		if (
			! empty(
				$verify['success']
			) &&
			'verified' === $state
		) {

			$verified_customer_id =
				isset(
					$verify['customer_id']
				)
					? absint(
						$verify['customer_id']
					)
					: $customer_id;

			$context['customer_id'] =
				$verified_customer_id;

			if (
				isset(
					$verify['context']
				) &&
				is_array(
					$verify['context']
				)
			) {

				$context =
					array_merge(
						$context,
						$verify['context']
					);
			}

			$ticket =
				isset(
					$verify['data']['ticket']
				) &&
				is_array(
					$verify['data']['ticket']
				)
					? $verify['data']['ticket']
					: array();

			if ( empty( $ticket ) ) {

				WP_RapidRescue_Chat_Debug::ticket(
					'Verification said verified but no ticket data was returned'
				);

				$result['ticket_context'][] =
					array(
						'ticket_key' =>
							$ticket_key,

						'explicit_reference' =>
							true,

						'lookup_status' =>
							'not_verified',
					);

				return $result;
			}

			$result['verified'] =
				true;

			$result['verified_customer_id'] =
				$verified_customer_id;

			$result['context'] =
				$context;

			$result['ticket_context'][] =
				array(
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

			WP_RapidRescue_Chat_Debug::ticket(
				'Ticket successfully verified',
				array(
					'ticket_key' =>
						$ticket_key,

					'status' =>
						isset(
							$ticket['status']
						)
							? $ticket['status']
							: '',
				)
			);

			return $result;
		}

		WP_RapidRescue_Chat_Debug::ticket(
			'Ticket verification failed'
		);

		$result['ticket_context'][] =
			array(
				'ticket_key' =>
					$ticket_key,

				'explicit_reference' =>
					true,

				'lookup_status' =>
					'not_verified',

				'verification_failed' =>
					true,
			);

		return $result;
	}

	/**
	 * Find a ticket awaiting email verification.
	 *
	 * @param array $history Conversation history.
	 * @return string
	 */
	private static function find_ticket_waiting_for_email(
		$history
	) {

		if ( ! is_array( $history ) ) {

			WP_RapidRescue_Chat_Debug::ticket(
				'Cannot inspect history for pending ticket'
			);

			return '';
		}

		$history =
			array_reverse(
				$history
			);

		$latest_ticket_key =
			'';

		foreach (
			$history as $item
		) {

			$content =
				isset(
					$item->message
				)
					? sanitize_textarea_field(
						$item->message
					)
					: '';

			if ( '' === $content ) {
				continue;
			}

			$ticket_key =
				WP_RapidRescue_Chat_Ticket::extract_ticket_key(
					$content
				);

			if ( '' !== $ticket_key ) {

				$latest_ticket_key =
					$ticket_key;

				break;
			}
		}

		if ( '' === $latest_ticket_key ) {

			WP_RapidRescue_Chat_Debug::ticket(
				'No ticket reference found in recent history'
			);

			return '';
		}

		WP_RapidRescue_Chat_Debug::ticket(
			'Latest ticket reference recovered from history',
			array(
				'ticket_key' =>
					$latest_ticket_key,
			)
		);

		return $latest_ticket_key;
	}

	/**
	 * Determine whether a message is an email address.
	 *
	 * @param string $message Message.
	 * @return bool
	 */
	private static function looks_like_email(
		$message
	) {

		$message =
			trim(
				sanitize_text_field(
					$message
				)
			);

		if ( '' === $message ) {
			return false;
		}

		return false !==
			filter_var(
				$message,
				FILTER_VALIDATE_EMAIL
			);
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

		$debug_trace =
			WP_RapidRescue_Chat_Debug::get_trace();

		return rest_ensure_response(
			array(
				'success' =>
					true,

				'data' =>
					array(
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

						'debug_trace' =>
							$debug_trace,
					),
			)
		);
	}

	/**
	 * Find successful create_ticket result.
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
	 * @param string $ticket_key Ticket key.
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
	 * Convert verified ticket to AI context.
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
	 * Determine ticket confirmation.
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

		$confirmations =
			array(
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
	 * Determine ticket cancellation.
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

		$cancellations =
			array(
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