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
		 * Email addresses are checked against the WordPress customer
		 * database. The browser session is not used as the source
		 * of customer identity.
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
		 * Get or create the conversation.
		 *
		 * The session ID is used only to maintain the current
		 * conversation. It is not used as the authority for
		 * identifying an existing ticket.
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
		 * If the customer supplied an email and there are anonymous
		 * tickets in this conversation, attach those tickets to the
		 * now-identified customer.
		 *
		 * This preserves the useful relationship created during
		 * the original conversation.
		 */
		if ( absint( $customer_id ) > 0 ) {
			self::attach_conversation_tickets_to_customer(
				$conversation_id,
				$customer_id
			);
		}

		/*
		 * Detect an explicit ticket number before asking the AI
		 * anything about that ticket.
		 *
		 * Ticket lookup is performed directly against the database.
		 * It does not depend on the browser session.
		 */
		$ticket_lookup =
			self::resolve_explicit_ticket_reference(
				$message,
				$conversation_id,
				$customer_id
			);

		/*
		 * Build relevant active ticket context.
		 *
		 * If the customer is identified, tickets are retrieved
		 * from the database by customer ID.
		 *
		 * If there is no customer identity yet, tickets belonging
		 * to the current conversation are still available as context.
		 */
		$ticket_context =
			self::get_active_ticket_context(
				$conversation_id,
				$customer_id
			);

		/*
		 * Add the explicitly referenced ticket to the AI context.
		 *
		 * This ticket has already been found in the WordPress
		 * database by its ticket number.
		 */
		if (
			! empty( $ticket_lookup['ticket'] )
		) {
			$ticket_context =
				self::add_ticket_to_context(
					$ticket_context,
					$ticket_lookup['ticket'],
					true
				);
		}

		/*
		 * Tell the AI when the customer explicitly supplied a ticket
		 * number that PHP could not find.
		 *
		 * We deliberately do not expose information from another
		 * ticket when the supplied number does not exist.
		 */
		if (
			! empty( $ticket_lookup['explicit'] ) &&
			empty( $ticket_lookup['ticket'] )
		) {
			$ticket_context[] = array(
				'ticket_key'         => $ticket_lookup['ticket_key'],
				'lookup_status'      => $ticket_lookup['status'],
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

		$action = isset( $response['action'] ) &&
			is_array( $response['action'] )
			? $response['action']
			: array();

		$action_type = isset( $action['type'] )
			? sanitize_key( $action['type'] )
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

			/*
			 * The AI is not allowed to select an arbitrary ticket.
			 *
			 * It must match a ticket number that PHP already found
			 * in the customer's message or current verified context.
			 */
			$verified_ticket = null;

			if (
				'' !== $requested_ticket_key &&
				! empty( $ticket_lookup['ticket'] ) &&
				$requested_ticket_key ===
					$ticket_lookup['ticket']->ticket_key
			) {
				$verified_ticket =
					$ticket_lookup['ticket'];
			} elseif (
				'' !== $requested_ticket_key &&
				absint( $customer_id ) > 0
			) {
				/*
				 * If the customer has already been identified by
				 * email, PHP may verify the requested ticket against
				 * that customer's database record.
				 */
				$candidate_ticket =
					WP_RapidRescue_Chat_Ticket::get_by_key(
						$requested_ticket_key
					);

				if (
					$candidate_ticket &&
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
				self::ticket_is_active( $verified_ticket )
			) {
				$ticket_id =
					absint(
						$verified_ticket->id
					);

				$ticket_key =
					$verified_ticket->ticket_key;

				$escalated = true;

				$assistant_text = sprintf(
					'Your request matches your existing support ticket %s, so I\'ll keep it with that support request.',
					$ticket_key
				);
			} else {
				$assistant_text =
					'I couldn\'t safely match this request to an active support ticket. Please tell me which ticket or issue you\'re referring to.';
			}
		}

		/*
		 * New ticket.
		 */
		elseif ( 'create_ticket' === $action_type ) {
			/*
			 * PHP, not the AI, decides whether there is enough
			 * customer information to create a ticket.
			 */
			if ( absint( $customer_id ) < 1 ) {
				$assistant_text =
					'Before I create the support ticket, I need your email address so our support team knows how to follow up with you.';

				$ticket_id  = null;
				$ticket_key = null;
				$escalated  = false;
			} else {
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
					 * Never allow the AI's original text to claim
					 * that a ticket was created when PHP failed.
					 */
					if (
						in_array(
							$ticket_result->get_error_code(),
							array(
								'customer_identity_required',
								'customer_email_required',
							),
							true
						)
					) {
						$assistant_text =
							'Before I create the support ticket, I need your email address so our support team knows how to follow up with you.';
					} else {
						$assistant_text =
							'I\'m sorry, but I couldn\'t create the support request just now. No ticket has been created. Please try again in a moment.';
					}

					$ticket_id  = null;
					$ticket_key = null;
					$escalated  = false;
				} elseif (
					! empty( $ticket_result['ticket_key'] )
				) {
					$ticket_id =
						! empty( $ticket_result['ticket_id'] )
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

					/*
					 * The confirmation comes from PHP and therefore
					 * represents the actual application state.
					 */
					if ( '' !== trim( $confirmation ) ) {
						$assistant_text =
							$confirmation;
					}
				} else {
					$assistant_text =
						'I\'m sorry, but the support request could not be confirmed. No ticket has been created.';
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
	 * Resolve an explicit ticket reference from the customer message.
	 *
	 * Ticket numbers are authoritative database references.
	 * The current browser/session is NOT required.
	 *
	 * @param string   $message         Customer message.
	 * @param int      $conversation_id Conversation ID.
	 * @param int|null $customer_id     Customer ID.
	 * @return array
	 */
	private static function resolve_explicit_ticket_reference(
		$message,
		$conversation_id,
		$customer_id
	) {
		$ticket_key =
			WP_RapidRescue_Chat_Ticket::extract_ticket_key(
				$message
			);

		$result = array(
			'explicit'         => '' !== $ticket_key,
			'ticket_key'       => $ticket_key,
			'status'           => '',
			'ticket'           => null,
			'customer_verified' => false,
		);

		if ( '' === $ticket_key ) {
			return $result;
		}

		/*
		 * Look up the ticket directly in WordPress.
		 */
		$ticket =
			WP_RapidRescue_Chat_Ticket::get_by_key(
				$ticket_key
			);

		if ( ! $ticket ) {
			$result['status'] = 'not_found';

			return $result;
		}

		/*
		 * If the ticket already belongs to a known customer and
		 * the current visitor has supplied that customer's email,
		 * verify the customer relationship.
		 */
		if ( absint( $ticket->customer_id ) > 0 ) {
			if (
				absint( $customer_id ) > 0 &&
				absint( $ticket->customer_id ) ===
					absint( $customer_id )
			) {
				$result['customer_verified'] = true;
				$result['status'] = 'verified';
				$result['ticket'] = $ticket;

				return $result;
			}

			/*
			 * A known customer's ticket should not be exposed merely
			 * because somebody guessed the ticket number.
			 */
			$result['status'] = 'customer_verification_required';

			return $result;
		}

		/*
		 * Anonymous tickets can be resolved by their explicit ticket
		 * reference. The reference itself is the server-side lookup
		 * key; browser session is not required.
		 *
		 * Once the visitor provides an email, the ticket can later
		 * become associated with a customer record.
		 */
		$result['status'] = 'verified';
		$result['ticket'] = $ticket;

		return $result;
	}

	/**
	 * Get active ticket context for the AI.
	 *
	 * @param int      $conversation_id Conversation ID.
	 * @param int|null $customer_id     Customer ID.
	 * @return array
	 */
	private static function get_active_ticket_context(
		$conversation_id,
		$customer_id
	) {
		$tickets = array();

		/*
		 * Identified customer:
		 *
		 * Always retrieve tickets from the WordPress database using
		 * the customer ID. The current browser session is irrelevant.
		 */
		if ( absint( $customer_id ) > 0 ) {
			$tickets =
				WP_RapidRescue_Chat_Ticket::get_by_customer(
					absint( $customer_id ),
					20
				);
		} else {
			/*
			 * No customer identity yet:
			 *
			 * Conversation tickets can still be used for the current
			 * conversation.
			 */
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
			if ( ! self::ticket_is_active( $ticket ) ) {
				continue;
			}

			$context[] =
				self::ticket_to_context(
					$ticket,
					false
				);

			if ( count( $context ) >= 10 ) {
				break;
			}
		}

		return $context;
	}

	/**
	 * Add a ticket to AI context if it is not already present.
	 *
	 * @param array  $context            Existing context.
	 * @param object $ticket             Ticket object.
	 * @param bool   $explicit_reference Whether customer explicitly named it.
	 * @return array
	 */
	private static function add_ticket_to_context(
		$context,
		$ticket,
		$explicit_reference = false
	) {
		if ( ! is_array( $context ) || ! $ticket ) {
			return $context;
		}

		foreach ( $context as $existing ) {
			if (
				isset( $existing['ticket_key'] ) &&
				$existing['ticket_key'] === $ticket->ticket_key
			) {
				return $context;
			}
		}

		$context[] =
			self::ticket_to_context(
				$ticket,
				$explicit_reference
			);

		return $context;
	}

	/**
	 * Convert a ticket object to limited AI context.
	 *
	 * @param object $ticket             Ticket object.
	 * @param bool   $explicit_reference Whether customer explicitly named it.
	 * @return array
	 */
	private static function ticket_to_context(
		$ticket,
		$explicit_reference = false
	) {
		return array(
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
			'explicit_reference' => $explicit_reference,
			'lookup_status'      => 'verified',
		);
	}

	/**
	 * Attach anonymous tickets in this exact conversation to a customer.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $customer_id     Customer ID.
	 * @return void
	 */
	private static function attach_conversation_tickets_to_customer(
		$conversation_id,
		$customer_id
	) {
		global $wpdb;

		$conversation_id = absint( $conversation_id );
		$customer_id     = absint( $customer_id );

		if (
			$conversation_id < 1 ||
			$customer_id < 1
		) {
			return;
		}

		$table = $wpdb->prefix . 'rr_tickets';

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET customer_id = %d,
					updated_at = %s
				WHERE conversation_id = %d
				AND ( customer_id IS NULL OR customer_id = 0 )",
				$customer_id,
				current_time( 'mysql', true ),
				$conversation_id
			)
		);
	}

	/**
	 * Determine whether a ticket is active.
	 *
	 * @param object $ticket Ticket object.
	 * @return bool
	 */
	private static function ticket_is_active( $ticket ) {
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