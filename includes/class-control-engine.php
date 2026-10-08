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
	 * @param array $context Runtime application context.
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
		 * 1. Detect a NEW ticket request.
		 * ---------------------------------------------------------
		 *
		 * This is intentionally evaluated before the existing-ticket
		 * workflow.
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
		 * A direct request starts a persistent escalation record.
		 *
		 * The request itself is authorization to create a ticket,
		 * but the ticket cannot be created until meaningful details
		 * are available.
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
		 * 2. Continue an existing pending NEW ticket request.
		 * ---------------------------------------------------------
		 *
		 * This is the important cross-turn state.
		 *
		 * Example:
		 *
		 * Turn 1:
		 * "please create a new ticket for me"
		 *
		 * Turn 2:
		 * "I need help building a new page"
		 *
		 * The second message must NOT return to normal state.
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
			 * If the current message is useful ticket information,
			 * merge it into the pending request.
			 *
			 * Do not use messages such as "yes" or "create a ticket"
			 * as the ticket summary.
			 */
			$current_detail =
				self::extract_ticket_detail(
					$current_message
				);

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
			 * A customer explicitly requesting a new ticket has already
			 * authorized creation. We only need:
			 *
			 * - customer identity
			 * - meaningful ticket details
			 *
			 * before the database operation is allowed.
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
			 * No ticket summary/details yet.
			 *
			 * Do NOT create the ticket.
			 * Do NOT ask for confirmation.
			 */
			if ( '' === trim( $summary ) ) {

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
			 * Meaningful details + identified customer =
			 * application-level authorization to create.
			 *
			 * There is no confirmation step.
			 */
			$pending['confirmed'] = true;

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
		 * 3. Existing ticket update.
		 * ---------------------------------------------------------
		 */
		$verified_ticket =
			self::get_verified_ticket(
				$ticket_context
			);

		if (
			$verified_ticket &&
			self::is_ticket_update_request(
				$current_message
			)
		) {

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
							? sanitize_text_field(
								$verified_ticket['ticket_key']
							)
							: '',
				);
			}

			return array(
				'state' =>
					self::STATE_TICKET_UPDATE_REQUESTED,

				'next_action' =>
					'ticket_not_updateable',

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

		/*
		 * ---------------------------------------------------------
		 * 4. Existing active ticket.
		 * ---------------------------------------------------------
		 */
		if ( '' !== $active_ticket_key ) {

			return array(
				'state' =>
					self::STATE_TICKET_ACTIVE,

				'next_action' =>
					'use_active_ticket,

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
		 * 5. Unverified ticket.
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

		$decision =
			self::evaluate(
				$context
			);

		$context['control_engine'] =
			$decision;

		/*
		 * This is the PHP authorization flag consumed by the
		 * Tool Security layer.
		 *
		 * It is only true when:
		 *
		 * - customer identity exists
		 * - meaningful ticket details exist
		 * - the control engine requires create_ticket
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
		 * Refresh pending state after evaluate() because evaluate()
		 * may have persisted the customer's current ticket details.
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
	 * Get the required tool.
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
	 * Start or update a pending escalation.
	 *
	 * @param int    $conversation_id Conversation ID.
	 * @param array  $pending Existing pending state.
	 * @param string $message Current message.
	 * @param int    $customer_id Customer ID.
	 * @param bool   $direct_new_ticket Direct new-ticket request.
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
		 * Only save actual ticket information.
		 *
		 * "please create a new ticket for me"
		 * is NOT ticket information.
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
		 * A direct request does not require a second confirmation.
		 *
		 * However, confirmed remains false until PHP has both:
		 * - customer identity
		 * - meaningful ticket details
		 */
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
		 * These are workflow commands, not ticket details.
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
		 * If the message is primarily a ticket command with no
		 * actual issue after it, do not treat it as a summary.
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
		 * Handle:
		 *
		 * "create a new ticket for my homepage is broken"
		 *
		 * by removing the workflow command and retaining the issue.
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
		 * An unfinished sentence such as:
		 *
		 * "please create a new ticket for"
		 *
		 * is still only an intent.
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
		 * Very short confirmations/commands are not issue details.
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
}