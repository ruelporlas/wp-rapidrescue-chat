<?php
/**
 * Deterministic application control engine.
 *
 * @package WP_RapidRescue_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Controls application workflow independently of the AI model.
 *
 * The AI interprets language.
 * PHP determines what actions are permitted.
 */
class WP_RapidRescue_Chat_Control_Engine {

	const STATE_NORMAL                     = 'normal';
	const STATE_NEEDS_IDENTITY             = 'needs_customer_identity';
	const STATE_ESCALATION_PENDING         = 'escalation_pending';
	const STATE_AWAITING_CONFIRMATION      = 'escalation_awaiting_confirmation';
	const STATE_TICKET_CREATION_AUTHORIZED = 'ticket_creation_authorized';
	const STATE_NEW_TICKET_REQUESTED       = 'new_ticket_requested';
	const STATE_TICKET_CREATED             = 'ticket_created';
	const STATE_TICKET_ACTIVE              = 'ticket_active';
	const STATE_VERIFICATION_REQUIRED      = 'ticket_verification_required';
	const STATE_TICKET_UPDATE_REQUESTED    = 'ticket_update_requested';
	const STATE_TICKET_UPDATE_AUTHORIZED   = 'ticket_update_authorized';

	/**
	 * Evaluate application state.
	 *
	 * The order of evaluation is intentional.
	 *
	 * Existing-ticket update intent is evaluated BEFORE:
	 *
	 * - human escalation
	 * - new-ticket requests
	 * - pending ticket creation
	 *
	 * This prevents phrases such as "I need a person" inside an
	 * update request from incorrectly starting a new ticket workflow.
	 *
	 * @param array $context Runtime context.
	 * @return array
	 */
	public static function evaluate( $context = array() ) {

		if ( ! is_array( $context ) ) {
			$context = array();
		}

		$customer_id =
			absint(
				isset( $context['customer_id'] )
					? $context['customer_id']
					: 0
			);

		$conversation_id =
			absint(
				isset( $context['conversation_id'] )
					? $context['conversation_id']
					: 0
			);

		$current_message =
			isset( $context['current_message'] )
				? sanitize_textarea_field(
					$context['current_message']
				)
				: '';

		$pending =
			isset( $context['pending_escalation'] ) &&
			is_array( $context['pending_escalation'] )
				? $context['pending_escalation']
				: array();

		$ticket_context =
			isset( $context['ticket_context'] ) &&
			is_array( $context['ticket_context'] )
				? $context['ticket_context']
				: array();

		$active_ticket_key =
			isset( $context['active_ticket_key'] )
				? strtoupper(
					sanitize_text_field(
						$context['active_ticket_key']
					)
				)
				: '';

		/*
		 * ---------------------------------------------------------
		 * 1. Resolve verified ticket context FIRST.
		 * ---------------------------------------------------------
		 *
		 * This is critical for multi-step conversations.
		 *
		 * After create_ticket succeeds, the newly-created ticket
		 * becomes the active verified ticket. On the next customer
		 * message, REST may represent that ticket with:
		 *
		 * lookup_status = verified
		 *
		 * rather than:
		 *
		 * verified = true
		 *
		 * The Control Engine accepts both representations.
		 */
		$verified_ticket =
			self::get_verified_ticket(
				$ticket_context
			);

		/*
		 * If the conversation has an active ticket key but the
		 * ticket context did not explicitly include it, try to
		 * resolve that ticket from the supplied context.
		 */
		if (
			! $verified_ticket &&
			'' !== $active_ticket_key
		) {

			foreach ( $ticket_context as $ticket ) {

				if ( ! is_array( $ticket ) ) {
					continue;
				}

				$ticket_key =
					isset( $ticket['ticket_key'] )
						? strtoupper(
							sanitize_text_field(
								$ticket['ticket_key']
							)
						)
						: '';

				if (
					$ticket_key ===
					$active_ticket_key &&
					self::ticket_context_is_verified(
						$ticket
					)
				) {

					$verified_ticket = $ticket;

					break;
				}
			}
		}

		/*
		 * ---------------------------------------------------------
		 * 2. EXISTING TICKET UPDATE HAS HIGHEST PRIORITY.
		 * ---------------------------------------------------------
		 *
		 * This must happen BEFORE human escalation detection.
		 *
		 * Example:
		 *
		 * "Update the ticket and say I need a person with good
		 * design skills."
		 *
		 * The phrase "I need a person" is NOT a new escalation
		 * workflow because the customer explicitly said:
		 *
		 * "Update the ticket."
		 */
		if (
			self::is_ticket_update_request(
				$current_message
			)
		) {

			if ( $verified_ticket ) {

				$ticket_status =
					isset( $verified_ticket['status'] )
						? sanitize_key(
							$verified_ticket['status']
						)
						: '';

				if (
					in_array(
						$ticket_status,
						array(
							'open',
							'in_progress',
							'waiting_customer',
						),
						true
					)
				) {

					return array(
						'state' =>
							self::STATE_TICKET_UPDATE_AUTHORIZED,

						'next_action' =>
							'update_ticket',

						'required_tool' =>
							'update_ticket',

						'tool_choice' =>
							'required',

						'customer_id' =>
							$customer_id,

						'conversation_id' =>
							$conversation_id,

						'pending' =>
							false,

						'confirmation' =>
							false,

						'new_ticket' =>
							false,

						'ticket_key' =>
							isset(
								$verified_ticket['ticket_key']
							)
								? strtoupper(
									sanitize_text_field(
										$verified_ticket['ticket_key']
									)
								)
								: '',
					);
				}
			}

			/*
			 * The customer explicitly requested an update, but PHP
			 * does not currently have an authorized ticket context.
			 *
			 * Do NOT turn this into a new-ticket request.
			 */
			return array(
				'state' =>
					self::STATE_TICKET_UPDATE_REQUESTED,

				'next_action' =>
					'require_ticket_verification',

				'required_tool' =>
					'',

				'tool_choice' =>
					'auto',

				'customer_id' =>
					$customer_id,

				'conversation_id' =>
					$conversation_id,

				'pending' =>
					false,

				'confirmation' =>
					false,

				'new_ticket' =>
					false,

				'ticket_key' =>
					$verified_ticket &&
					isset(
						$verified_ticket['ticket_key']
					)
						? strtoupper(
							sanitize_text_field(
								$verified_ticket['ticket_key']
							)
						)
						: $active_ticket_key,
			);
		}

		/*
		 * ---------------------------------------------------------
		 * 3. Detect NEW TICKET and HUMAN ESCALATION intent.
		 * ---------------------------------------------------------
		 *
		 * This happens only after existing-ticket update intent
		 * has been ruled out.
		 */
		$new_ticket_request =
			self::is_new_ticket_request(
				$current_message
			);

		$human_escalation =
			self::is_human_escalation_request(
				$current_message
			);

		/*
		 * ---------------------------------------------------------
		 * 4. Start/update pending ticket workflow.
		 * ---------------------------------------------------------
		 */
		if (
			$new_ticket_request ||
			$human_escalation
		) {

			$pending =
				self::start_or_update_pending(
					$conversation_id,
					$pending,
					$current_message,
					$customer_id,
					$new_ticket_request
				);
		}

		/*
		 * ---------------------------------------------------------
		 * 5. Continue pending NEW TICKET workflow.
		 * ---------------------------------------------------------
		 */
		if ( ! empty( $pending ) ) {

			$summary =
				isset( $pending['summary'] )
					? trim(
						sanitize_textarea_field(
							$pending['summary']
						)
					)
					: '';

			/*
			 * If an old pending summary is merely a workflow command,
			 * discard it. Never allow an old command to become the
			 * actual ticket issue.
			 */
			if (
				'' !== $summary &&
				'' === self::extract_ticket_detail(
					$summary
				)
			) {
				$summary = '';
				$pending['summary'] = '';
				$pending['confirmed'] = false;
			}

			$current_detail =
				self::extract_ticket_detail(
					$current_message
				);

			/*
			 * Only the current customer message can add new details
			 * when the pending request does not already contain
			 * meaningful details.
			 */
			if (
				'' === $summary &&
				'' !== $current_detail
			) {

				$summary =
					$current_detail;

				$pending['summary'] =
					$summary;
			}

			/*
			 * Customer identity is required before creation.
			 */
			if ( $customer_id < 1 ) {

				self::save_pending(
					$conversation_id,
					$pending
				);

				return array(
					'state' =>
						self::STATE_NEEDS_IDENTITY,

					'next_action' =>
						'ask_customer_for_identity',

					'required_tool' =>
						'',

					'tool_choice' =>
						'auto',

					'customer_id' =>
						0,

					'conversation_id' =>
						$conversation_id,

					'pending' =>
						true,

					'confirmation' =>
						false,

					'new_ticket' =>
						true,
				);
			}

			/*
			 * Do not create an empty ticket.
			 */
			if ( '' === trim( $summary ) ) {

				$pending['confirmed'] =
					false;

				self::save_pending(
					$conversation_id,
					$pending
				);

				return array(
					'state' =>
						self::STATE_NEW_TICKET_REQUESTED,

					'next_action' =>
						'collect_ticket_details',

					'required_tool' =>
						'',

					'tool_choice' =>
						'auto',

					'customer_id' =>
						$customer_id,

					'conversation_id' =>
						$conversation_id,

					'pending' =>
						true,

					'confirmation' =>
						false,

					'new_ticket' =>
						true,
				);
			}

			/*
			 * Customer identity + meaningful ticket details are
			 * sufficient for automatic creation.
			 */
			$pending['summary'] =
				$summary;

			$pending['confirmed'] =
				true;

			self::save_pending(
				$conversation_id,
				$pending
			);

			return array(
				'state' =>
					self::STATE_TICKET_CREATION_AUTHORIZED,

				'next_action' =>
					'create_ticket',

				'required_tool' =>
					'create_ticket',

				'tool_choice' =>
					'required',

				'customer_id' =>
					$customer_id,

				'conversation_id' =>
					$conversation_id,

				'pending' =>
					true,

				'confirmation' =>
					true,

				'new_ticket' =>
					true,
			);
		}

		/*
		 * ---------------------------------------------------------
		 * 6. Existing active verified ticket.
		 * ---------------------------------------------------------
		 */
		if ( $verified_ticket ) {

			$ticket_key =
				isset(
					$verified_ticket['ticket_key']
				)
					? strtoupper(
						sanitize_text_field(
							$verified_ticket['ticket_key']
						)
					)
					: '';

			if ( '' !== $ticket_key ) {

				return array(
					'state' =>
						self::STATE_TICKET_ACTIVE,

					'next_action' =>
						'use_active_ticket',

					'required_tool' =>
						'',

					'tool_choice' =>
						'auto',

					'customer_id' =>
						$customer_id,

					'conversation_id' =>
						$conversation_id,

					'pending' =>
						false,

					'confirmation' =>
						false,

					'new_ticket' =>
						false,

					'ticket_key' =>
						$ticket_key,
				);
			}
		}

		/*
		 * ---------------------------------------------------------
		 * 7. Existing active ticket key without full ticket context.
		 * ---------------------------------------------------------
		 */
		if ( '' !== $active_ticket_key ) {

			return array(
				'state' =>
					self::STATE_TICKET_ACTIVE,

				'next_action' =>
					'use_active_ticket',

				'required_tool' =>
					'',

				'tool_choice' =>
					'auto',

				'customer_id' =>
					$customer_id,

				'conversation_id' =>
					$conversation_id,

				'pending' =>
					false,

				'confirmation' =>
					false,

				'new_ticket' =>
					false,

				'ticket_key' =>
					$active_ticket_key,
			);
		}

		/*
		 * ---------------------------------------------------------
		 * 8. Unverified ticket requires verification.
		 * ---------------------------------------------------------
		 */
		foreach ( $ticket_context as $ticket ) {

			if (
				is_array( $ticket ) &&
				isset( $ticket['lookup_status'] ) &&
				'email_required' ===
					sanitize_key(
						$ticket['lookup_status']
					)
			) {

				return array(
					'state' =>
						self::STATE_VERIFICATION_REQUIRED,

					'next_action' =>
						'verify_ticket',

					'required_tool' =>
						'',

					'tool_choice' =>
						'auto',

					'customer_id' =>
						$customer_id,

					'conversation_id' =>
						$conversation_id,

					'pending' =>
						false,

					'confirmation' =>
						false,

					'new_ticket' =>
						false,
				);
			}
		}

		return array(
			'state' =>
				self::STATE_NORMAL,

			'next_action' =>
				'continue_conversation',

			'required_tool' =>
				'',

			'tool_choice' =>
				'auto',

			'customer_id' =>
				$customer_id,

			'conversation_id' =>
				$conversation_id,

			'pending' =>
				false,

			'confirmation' =>
				false,

			'new_ticket' =>
				false,
		);
	}

	/**
	 * Prepare provider context.
	 *
	 * @param array $context Runtime context.
	 * @return array
	 */
	public static function prepare_context(
		$context = array()
	) {

		if ( ! is_array( $context ) ) {
			$context = array();
		}

		/*
		 * Populate active ticket state directly from the
		 * conversation record when possible.
		 *
		 * This makes the multi-tool state explicit and prevents
		 * the AI from having to infer the active ticket from history.
		 */
		$conversation_id =
			absint(
				isset(
					$context['conversation_id']
				)
					? $context['conversation_id']
					: 0
			);

		if ( $conversation_id > 0 ) {

			$active_ticket_key =
				WP_RapidRescue_Chat_Conversation::get_active_ticket_key(
					$conversation_id
				);

			if ( '' !== $active_ticket_key ) {

				$context['active_ticket_key'] =
					$active_ticket_key;
			}
		}

		/*
		 * Evaluate AFTER active ticket state has been restored.
		 */
		$decision =
			self::evaluate(
				$context
			);

		$context['control_engine'] =
			$decision;

		/*
		 * This flag is generated entirely by PHP.
		 *
		 * It means:
		 *
		 * - customer identity exists
		 * - meaningful ticket details exist
		 * - the Control Engine authorized creation
		 *
		 * It does NOT mean that the customer typed "yes".
		 */
		$context['explicit_ticket_confirmation'] =
			(
				isset(
					$decision['required_tool']
				) &&
				'create_ticket' ===
					sanitize_key(
						$decision['required_tool']
					) &&
				! empty(
					$decision['confirmation']
				) &&
				! empty(
					$decision['new_ticket']
				)
			);

		/*
		 * Refresh persisted pending state.
		 */
		if ( $conversation_id > 0 ) {

			$pending =
				WP_RapidRescue_Chat_Conversation::get_pending_sensitive_escalation(
					$conversation_id
				);

			$context['pending_escalation'] =
				$pending
					? $pending
					: array();
		}

		return $context;
	}

	/**
	 * Get required tool.
	 *
	 * @param array $context Runtime context.
	 * @return string
	 */
	public static function get_required_tool(
		$context = array()
	) {

		$decision =
			isset(
				$context['control_engine']
			) &&
			is_array(
				$context['control_engine']
			)
				? $context['control_engine']
				: self::evaluate(
					$context
				);

		return isset(
			$decision['required_tool']
		)
			? sanitize_key(
				$decision['required_tool']
			)
			: '';
	}

	/**
	 * Describe a control decision.
	 *
	 * @param array $decision Decision.
	 * @return string
	 */
	public static function describe(
		$decision
	) {

		if ( ! is_array( $decision ) ) {
			return 'No control decision is available.';
		}

		$state =
			isset( $decision['state'] )
				? sanitize_key(
					$decision['state']
				)
				: self::STATE_NORMAL;

		$next_action =
			isset( $decision['next_action'] )
				? sanitize_key(
					$decision['next_action']
				)
				: 'continue_conversation';

		return sprintf(
			'CONTROL ENGINE STATE: %s. NEXT APPLICATION ACTION: %s.',
			$state,
			$next_action
		);
	}

	/**
	 * Start or update pending escalation.
	 *
	 * @param int    $conversation_id Conversation ID.
	 * @param array  $pending Existing pending state.
	 * @param string $message Current message.
	 * @param int    $customer_id Customer ID.
	 * @param bool   $direct_new_ticket Whether this was a direct ticket request.
	 * @return array
	 */
	private static function start_or_update_pending(
		$conversation_id,
		$pending,
		$message,
		$customer_id,
		$direct_new_ticket
	) {

		$conversation_id =
			absint(
				$conversation_id
			);

		$customer_id =
			absint(
				$customer_id
			);

		if ( $conversation_id < 1 ) {
			return is_array( $pending )
				? $pending
				: array();
		}

		$pending =
			is_array( $pending )
				? $pending
				: array();

		$subject =
			isset( $pending['subject'] )
				? sanitize_text_field(
					$pending['subject']
				)
				: '';

		$summary =
			isset( $pending['summary'] )
				? sanitize_textarea_field(
					$pending['summary']
				)
				: '';

		$priority =
			isset( $pending['priority'] )
				? sanitize_key(
					$pending['priority']
				)
				: 'normal';

		$reason =
			isset( $pending['reason'] )
				? sanitize_textarea_field(
					$pending['reason']
				)
				: '';

		if ( '' === $subject ) {
			$subject =
				'Customer support request';
		}

		if ( '' === $reason ) {

			$reason =
				$direct_new_ticket
					? 'Customer explicitly requested a new support ticket.'
					: 'Customer explicitly requested human support.';
		}

		/*
		 * Only use the current message when it contains meaningful
		 * ticket information.
		 */
		if ( '' === trim( $summary ) ) {

			$detail =
				self::extract_ticket_detail(
					$message
				);

			if ( '' !== $detail ) {
				$summary = $detail;
			}
		}

		/*
		 * Never treat a workflow command as ticket details.
		 */
		if (
			'' !== trim( $summary ) &&
			'' === self::extract_ticket_detail(
				$summary
			)
		) {
			$summary = '';
		}

		$confirmed =
			(
				$customer_id > 0 &&
				'' !== trim( $summary )
			);

		$result =
			WP_RapidRescue_Chat_Conversation::set_pending_sensitive_escalation(
				$conversation_id,
				$subject,
				$summary,
				$priority,
				$reason,
				$confirmed
			);

		if ( is_wp_error( $result ) ) {
			return $pending;
		}

		$stored =
			WP_RapidRescue_Chat_Conversation::get_pending_sensitive_escalation(
				$conversation_id
			);

		return $stored && is_array( $stored )
			? $stored
			: $pending;
	}

	/**
	 * Save pending state.
	 *
	 * @param int   $conversation_id Conversation ID.
	 * @param array $pending Pending state.
	 * @return void
	 */
	private static function save_pending(
		$conversation_id,
		$pending
	) {

		if (
			absint( $conversation_id ) < 1 ||
			! is_array( $pending )
		) {
			return;
		}

		WP_RapidRescue_Chat_Conversation::set_pending_sensitive_escalation(
			$conversation_id,

			isset( $pending['subject'] )
				? $pending['subject']
				: 'Customer support request',

			isset( $pending['summary'] )
				? $pending['summary']
				: '',

			isset( $pending['priority'] )
				? $pending['priority']
				: 'normal',

			isset( $pending['reason'] )
				? $pending['reason']
				: '',

			! empty(
				$pending['confirmed']
			)
		);
	}

	/**
	 * Extract meaningful ticket details from a message.
	 *
	 * @param string $message Customer message.
	 * @return string
	 */
	private static function extract_ticket_detail(
		$message
	) {

		$message =
			trim(
				sanitize_textarea_field(
					$message
				)
			);

		if ( '' === $message ) {
			return '';
		}

		$normalized =
			strtolower(
				$message
			);

		/*
		 * Workflow commands are not ticket details.
		 */
		$command_messages = array(
			'create a new ticket',
			'create new ticket',
			'create a new ticket for me',
			'please create a new ticket for me',
			'open a new ticket',
			'open new ticket',
			'open a new ticket for me',
			'please open a new ticket for me',
			'create a ticket',
			'create a ticket for me',
			'please create a ticket',
			'please create a ticket for me',
			'open a ticket',
			'open a ticket for me',
			'new ticket',
			'create another ticket',
			'open another ticket',
			'create another one',
			'open another one',
			'create a new one',
			'open a new one',
			'new one',
			'another one',
			'yes',
			'yes please',
			'confirm',
			'confirmed',
			'go ahead',
			'go ahead and create it',
			'go ahead and create the ticket',
			'do it',
		);

		if (
			in_array(
				$normalized,
				$command_messages,
				true
			)
		) {
			return '';
		}

		/*
		 * Reject a message that is only a ticket command.
		 */
		if (
			preg_match(
				'/^(please\s+)?(create|open|make|start)\s+(a\s+)?(new\s+|separate\s+|another\s+)?(support\s+)?ticket(\s+for\s+me)?[.!]?\s*$/i',
				$message
			)
		) {
			return '';
		}

		/*
		 * Extract details from:
		 *
		 * "create a new ticket about my homepage being broken"
		 */
		$cleaned =
			preg_replace(
				'/^(please\s+)?(create|open|make|start)\s+(a\s+)?(new\s+|separate\s+|another\s+)?(support\s+)?ticket\s+(for|about|regarding|concerning)\s+/i',
				'',
				$message
			);

		if ( null === $cleaned ) {
			$cleaned = $message;
		}

		$cleaned =
			trim(
				$cleaned
			);

		if ( '' === $cleaned ) {
			return '';
		}

		/*
		 * Reject incomplete workflow commands.
		 */
		if (
			preg_match(
				'/(^|\s)(for|about|regarding|concerning|because|that|this|on)\s*$/i',
				$cleaned
			)
		) {
			return '';
		}

		/*
		 * Very short commands/confirmations are not ticket details.
		 */
		if (
			strlen( $cleaned ) < 8
		) {
			return '';
		}

		return $cleaned;
	}

	/**
	 * Determine whether a message explicitly requests a new ticket.
	 *
	 * @param string $message Customer message.
	 * @return bool
	 */
	private static function is_new_ticket_request(
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

		if ( '' === $message ) {
			return false;
		}

		$patterns = array(

			'/\bcreate\s+(a\s+)?new\s+(support\s+)?ticket\b/i',
			'/\bopen\s+(a\s+)?new\s+(support\s+)?ticket\b/i',
			'/\bmake\s+(a\s+)?new\s+(support\s+)?ticket\b/i',
			'/\bstart\s+(a\s+)?new\s+(support\s+)?ticket\b/i',

			'/\bcreate\s+(a\s+)?separate\s+ticket\b/i',
			'/\bopen\s+(a\s+)?separate\s+ticket\b/i',

			'/\bcreate\s+another\s+ticket\b/i',
			'/\bopen\s+another\s+ticket\b/i',
			'/\bmake\s+another\s+ticket\b/i',

			'/\bneed\s+another\s+ticket\b/i',
			'/\bneed\s+a\s+separate\s+ticket\b/i',

			'/\bcreate\s+(a\s+)?new\s+one\b/i',
			'/\bopen\s+(a\s+)?new\s+one\b/i',
			'/\bcreate\s+another\s+one\b/i',
			'/\bopen\s+another\s+one\b/i',

			'/\bnew\s+one\b/i',
			'/\banother\s+one\b/i',
			'/\bseparate\s+one\b/i',

			'/\bdo\s+not\s+use\s+(the|my)\s+existing\s+ticket\b/i',
			'/\bdon[\'’]?t\s+use\s+(the|my)\s+existing\s+ticket\b/i',
		);

		foreach ( $patterns as $pattern ) {

			if (
				preg_match(
					$pattern,
					$message
				)
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine whether a message explicitly requests human support.
	 *
	 * @param string $message Customer message.
	 * @return bool
	 */
	private static function is_human_escalation_request(
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

		if ( '' === $message ) {
			return false;
		}

		$patterns = array(

			'/\b(i|we)\s+want\s+(a\s+)?(person|human|agent|support\s+person)\b/i',

			'/\b(i|we)\s+need\s+(a\s+)?(person|human|agent|support\s+person)\b/i',

			'/\b(can|could|would)\s+(someone|a\s+person|an\s+agent)\s+(help|assist)\b/i',

			'/\b(talk|speak|chat)\s+(to|with)\s+(a\s+)?(person|human|agent)\b/i',

			'/\bhuman\s+(help|support|assistance)\b/i',

			'/\b(person|agent)\s+(help|support|assistance)\b/i',

			'/\bconnect\s+me\s+(with|to)\s+(a\s+)?(person|human|agent)\b/i',

			'/\bneed\s+someone\s+to\s+help\b/i',
		);

		foreach ( $patterns as $pattern ) {

			if (
				preg_match(
					$pattern,
					$message
				)
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine whether the customer wants an existing ticket updated.
	 *
	 * @param string $message Customer message.
	 * @return bool
	 */
	private static function is_ticket_update_request(
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

		if ( '' === $message ) {
			return false;
		}

		$patterns = array(

			'/\bupdate\b.*\bticket\b/i',
			'/\bticket\b.*\bupdate\b/i',

			'/\bfollow[\s-]?up\b/i',

			'/\bnot resolved\b/i',
			'/\bnot yet resolved\b/i',
			'/\bstill not resolved\b/i',
			'/\bstill unresolved\b/i',
			'/\bstill not fixed\b/i',
			'/\bnot fixed yet\b/i',

			'/\bissue is still\b/i',
			'/\bproblem is still\b/i',

			'/\badd (this|that|some) (to|on) (my|the) ticket\b/i',
			'/\badd (this|that|some) information\b/i',
			'/\badd (a )?note\b.*\bticket\b/i',

			'/\bplease (put|add|note)\b.*\bticket\b/i',
			'/\bplease update\b/i',

			'/\bupdate my case\b/i',
			'/\bupdate my support request\b/i',

			'/\bupdate the ticket\b/i',
			'/\bupdate this ticket\b/i',
			'/\badd this to the ticket\b/i',
			'/\badd that to the ticket\b/i',
		);

		foreach ( $patterns as $pattern ) {

			if (
				preg_match(
					$pattern,
					$message
				)
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine whether ticket context represents a verified ticket.
	 *
	 * Both forms are accepted:
	 *
	 * 1. verified = true
	 * 2. lookup_status = verified
	 *
	 * The second form is currently used by REST ticket context.
	 *
	 * @param array $ticket Ticket context.
	 * @return bool
	 */
	private static function ticket_context_is_verified(
		$ticket
	) {

		if ( ! is_array( $ticket ) ) {
			return false;
		}

		if (
			! empty(
				$ticket['verified']
			)
		) {
			return true;
		}

		return (
			isset(
				$ticket['lookup_status']
			) &&
			'verified' ===
				sanitize_key(
					$ticket['lookup_status']
				)
		);
	}

	/**
	 * Get a verified ticket from PHP-controlled ticket context.
	 *
	 * @param array $ticket_context Ticket context.
	 * @return array|false
	 */
	private static function get_verified_ticket(
		$ticket_context
	) {

		if ( ! is_array( $ticket_context ) ) {
			return false;
		}

		foreach ( $ticket_context as $ticket ) {

			if (
				! is_array( $ticket )
			) {
				continue;
			}

			if (
				! self::ticket_context_is_verified(
					$ticket
				)
			) {
				continue;
			}

			if (
				empty(
					$ticket['ticket_key']
				)
			) {
				continue;
			}

			return $ticket;
		}

		return false;
	}
}