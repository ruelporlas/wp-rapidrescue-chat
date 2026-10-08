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
 * This engine decides what application state permits next.
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
	 * Evaluate the current application state.
	 *
	 * @param array $context Runtime application context.
	 * @return array
	 */
	public static function evaluate( $context = array() ) {

		if ( ! is_array( $context ) ) {
			$context = array();
		}

		$customer_id = absint(
			isset( $context['customer_id'] )
				? $context['customer_id']
				: 0
		);

		$conversation_id = absint(
			isset( $context['conversation_id'] )
				? $context['conversation_id']
				: 0
		);

		$pending =
			isset( $context['pending_escalation'] ) &&
			is_array( $context['pending_escalation'] )
				? $context['pending_escalation']
				: array();

		$explicit_confirmation =
			! empty(
				$context['explicit_ticket_confirmation']
			);

		$ticket_context =
			isset( $context['ticket_context'] ) &&
			is_array( $context['ticket_context'] )
				? $context['ticket_context']
				: array();

		$current_message =
			isset( $context['current_message'] )
				? sanitize_textarea_field(
					$context['current_message']
				)
				: '';

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
		 * NEW TICKET REQUEST
		 * ---------------------------------------------------------
		 *
		 * This check intentionally happens BEFORE the existing-ticket
		 * update logic and BEFORE the active-ticket fallback.
		 *
		 * If the customer explicitly says they want a NEW, SEPARATE,
		 * or BRAND-NEW ticket, the existing active ticket must not
		 * hijack the workflow.
		 *
		 * Example:
		 *
		 * "create a new one"
		 * "create a new ticket"
		 * "I need a separate ticket"
		 * "open another ticket"
		 *
		 * The existing ticket remains untouched.
		 */
		$new_ticket_request =
			self::is_new_ticket_request(
				$current_message
			);

		if ( $new_ticket_request ) {

			/*
			 * If PHP has already stored the customer's new-ticket
			 * request and the customer has confirmed it, authorize
			 * the actual create_ticket tool immediately.
			 */
			if (
				! empty( $pending ) &&
				$explicit_confirmation
			) {

				if ( $customer_id < 1 ) {

					return array(
						'state'            => self::STATE_NEEDS_IDENTITY,
						'next_action'      => 'ask_customer_for_identity',
						'required_tool'    => '',
						'tool_choice'      => 'auto',
						'customer_id'      => 0,
						'conversation_id' => $conversation_id,
						'pending'         => true,
						'confirmation'    => true,
						'new_ticket'      => true,
					);
				}

				return array(
					'state'            => self::STATE_TICKET_CREATION_AUTHORIZED,
					'next_action'      => 'create_ticket',
					'required_tool'    => 'create_ticket',
					'tool_choice'      => 'required',
					'customer_id'      => $customer_id,
					'conversation_id' => $conversation_id,
					'pending'         => true,
					'confirmation'    => true,
					'new_ticket'      => true,
				);
			}

			/*
			 * A new-ticket request with no pending escalation means
			 * the AI can prepare the ticket offer. PHP does not create
			 * anything until the normal confirmation workflow has
			 * completed.
			 */
			if ( $customer_id < 1 ) {

				return array(
					'state'            => self::STATE_NEEDS_IDENTITY,
					'next_action'      => 'ask_customer_for_identity',
					'required_tool'    => '',
					'tool_choice'      => 'auto',
					'customer_id'      => 0,
					'conversation_id' => $conversation_id,
					'pending'         => ! empty( $pending ),
					'confirmation'    => $explicit_confirmation,
					'new_ticket'      => true,
				);
			}

			if ( ! empty( $pending ) ) {

				return array(
					'state'            => self::STATE_AWAITING_CONFIRMATION,
					'next_action'      => 'ask_customer_for_confirmation',
					'required_tool'    => '',
					'tool_choice'      => 'auto',
					'customer_id'      => $customer_id,
					'conversation_id' => $conversation_id,
					'pending'         => true,
					'confirmation'    => false,
					'new_ticket'      => true,
				);
			}

			return array(
				'state'            => self::STATE_NEW_TICKET_REQUESTED,
				'next_action'      => 'prepare_new_ticket',
				'required_tool'    => '',
				'tool_choice'      => 'auto',
				'customer_id'      => $customer_id,
				'conversation_id' => $conversation_id,
				'pending'         => false,
				'confirmation'    => $explicit_confirmation,
				'new_ticket'      => true,
				'active_ticket_key' =>
					$active_ticket_key,
			);
		}

		/*
		 * ---------------------------------------------------------
		 * EXISTING TICKET UPDATE
		 * ---------------------------------------------------------
		 *
		 * A verified ticket plus a customer request to follow up,
		 * update, add information, or report that the issue remains
		 * unresolved requires the update_ticket tool.
		 *
		 * This is deliberately evaluated AFTER the explicit NEW
		 * ticket check above.
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
					'state'            => self::STATE_TICKET_UPDATE_AUTHORIZED,
					'next_action'      => 'update_ticket',
					'required_tool'    => 'update_ticket',
					'tool_choice'      => 'required',
					'customer_id'      => $customer_id,
					'conversation_id' => $conversation_id,
					'pending'         => false,
					'confirmation'    => false,
					'new_ticket'      => false,
					'ticket_key'      =>
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
				'state'            => self::STATE_TICKET_UPDATE_REQUESTED,
				'next_action'      => 'ticket_not_updateable',
				'required_tool'    => '',
				'tool_choice'      => 'auto',
				'customer_id'      => $customer_id,
				'conversation_id' => $conversation_id,
				'pending'         => false,
				'confirmation'    => false,
				'new_ticket'      => false,
				'ticket_key'      =>
					isset(
						$verified_ticket['ticket_key']
					)
						? sanitize_text_field(
							$verified_ticket['ticket_key']
						)
						: '',
			);
		}

		/*
		 * ---------------------------------------------------------
		 * PENDING TICKET CREATION
		 * ---------------------------------------------------------
		 *
		 * This is the normal confirmation workflow.
		 *
		 * Once the customer has explicitly confirmed, the
		 * create_ticket tool becomes mandatory.
		 *
		 * IMPORTANT:
		 *
		 * The existence of an active ticket does NOT matter here.
		 * The pending request represents a separate ticket request.
		 */
		if (
			! empty( $pending ) &&
			$explicit_confirmation
		) {

			if ( $customer_id < 1 ) {

				return array(
					'state'            => self::STATE_NEEDS_IDENTITY,
					'next_action'      => 'ask_customer_for_identity',
					'required_tool'    => '',
					'tool_choice'      => 'auto',
					'customer_id'      => 0,
					'conversation_id' => $conversation_id,
					'pending'         => true,
					'confirmation'    => true,
					'new_ticket'      => true,
				);
			}

			return array(
				'state'            => self::STATE_TICKET_CREATION_AUTHORIZED,
				'next_action'      => 'create_ticket',
				'required_tool'    => 'create_ticket',
				'tool_choice'      => 'required',
				'customer_id'      => $customer_id,
				'conversation_id' => $conversation_id,
				'pending'         => true,
				'confirmation'    => true,
				'new_ticket'      => true,
			);
		}

		/*
		 * ---------------------------------------------------------
		 * PENDING ESCALATION WITHOUT IDENTITY
		 * ---------------------------------------------------------
		 */
		if (
			! empty( $pending ) &&
			$customer_id < 1
		) {

			return array(
				'state'            => self::STATE_NEEDS_IDENTITY,
				'next_action'      => 'ask_customer_for_identity',
				'required_tool'    => '',
				'tool_choice'      => 'auto',
				'customer_id'      => 0,
				'conversation_id' => $conversation_id,
				'pending'         => true,
				'confirmation'    => false,
				'new_ticket'      => true,
			);
		}

		/*
		 * ---------------------------------------------------------
		 * PENDING ESCALATION WAITING FOR CONFIRMATION
		 * ---------------------------------------------------------
		 */
		if ( ! empty( $pending ) ) {

			return array(
				'state'            => self::STATE_AWAITING_CONFIRMATION,
				'next_action'      => 'ask_customer_for_confirmation',
				'required_tool'    => '',
				'tool_choice'      => 'auto',
				'customer_id'      => $customer_id,
				'conversation_id' => $conversation_id,
				'pending'         => true,
				'confirmation'    => false,
				'new_ticket'      => true,
			);
		}

		/*
		 * ---------------------------------------------------------
		 * EXISTING ACTIVE TICKET
		 * ---------------------------------------------------------
		 *
		 * This is only reached when the customer did NOT explicitly
		 * request a new ticket.
		 *
		 * Therefore an existing ticket can no longer interfere with
		 * "create a new one".
		 */
		if ( '' !== $active_ticket_key ) {

			return array(
				'state'            => self::STATE_TICKET_ACTIVE,
				'next_action'      => 'use_active_ticket',
				'required_tool'    => '',
				'tool_choice'      => 'auto',
				'customer_id'      => $customer_id,
				'conversation_id' => $conversation_id,
				'pending'         => false,
				'confirmation'    => $explicit_confirmation,
				'new_ticket'      => false,
				'ticket_key'      => $active_ticket_key,
			);
		}

		/*
		 * ---------------------------------------------------------
		 * UNVERIFIED TICKET CONTEXT
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
					'state'            => self::STATE_VERIFICATION_REQUIRED,
					'next_action'      => 'verify_ticket',
					'required_tool'    => '',
					'tool_choice'      => 'auto',
					'customer_id'      => $customer_id,
					'conversation_id' => $conversation_id,
					'pending'         => false,
					'confirmation'    => false,
					'new_ticket'      => false,
				);
			}
		}

		return array(
			'state'            => self::STATE_NORMAL,
			'next_action'      => 'continue_conversation',
			'required_tool'    => '',
			'tool_choice'      => 'auto',
			'customer_id'      => $customer_id,
			'conversation_id' => $conversation_id,
			'pending'         => false,
			'confirmation'    => false,
			'new_ticket'      => false,
		);
	}

	/**
	 * Add control-engine state to provider context.
	 *
	 * @param array $context Runtime context.
	 * @return array
	 */
	public static function prepare_context( $context = array() ) {

		if ( ! is_array( $context ) ) {
			$context = array();
		}

		$decision =
			self::evaluate( $context );

		$context['control_engine'] =
			$decision;

		return $context;
	}

	/**
	 * Determine whether the AI must call a specific tool.
	 *
	 * @param array $context Runtime context.
	 * @return string
	 */
	public static function get_required_tool( $context = array() ) {

		$decision =
			isset( $context['control_engine'] ) &&
			is_array( $context['control_engine'] )
				? $context['control_engine']
				: self::evaluate( $context );

		return isset( $decision['required_tool'] )
			? sanitize_key(
				$decision['required_tool']
			)
			: '';
	}

	/**
	 * Get a human-readable state description.
	 *
	 * @param array $decision Control decision.
	 * @return string
	 */
	public static function describe( $decision ) {

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
	 * Find a verified ticket in ticket context.
	 *
	 * @param array $ticket_context Ticket context.
	 * @return array|null
	 */
	private static function get_verified_ticket(
		$ticket_context
	) {

		if ( ! is_array( $ticket_context ) ) {
			return null;
		}

		foreach ( $ticket_context as $ticket ) {

			if ( ! is_array( $ticket ) ) {
				continue;
			}

			if (
				isset( $ticket['lookup_status'] ) &&
				'verified' ===
					sanitize_key(
						$ticket['lookup_status']
					)
			) {
				return $ticket;
			}
		}

		return null;
	}

	/**
	 * Determine whether the customer explicitly wants a NEW ticket.
	 *
	 * This must be checked before existing-ticket update logic.
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

			/*
			 * Direct new-ticket requests.
			 */
			'/\bcreate\s+(a\s+)?new\s+(support\s+)?ticket\b/i',
			'/\bopen\s+(a\s+)?new\s+(support\s+)?ticket\b/i',
			'/\bmake\s+(a\s+)?new\s+(support\s+)?ticket\b/i',
			'/\bstart\s+(a\s+)?new\s+(support\s+)?ticket\b/i',

			/*
			 * Explicitly separate/another ticket.
			 */
			'/\bcreate\s+(a\s+)?separate\s+ticket\b/i',
			'/\bopen\s+(a\s+)?separate\s+ticket\b/i',
			'/\bcreate\s+another\s+ticket\b/i',
			'/\bopen\s+another\s+ticket\b/i',
			'/\bmake\s+another\s+ticket\b/i',
			'/\bneed\s+another\s+ticket\b/i',
			'/\bneed\s+a\s+separate\s+ticket\b/i',

			/*
			 * Natural follow-up language.
			 */
			'/\bnew\s+one\b/i',
			'/\banother\s+one\b/i',
			'/\bseparate\s+one\b/i',

			/*
			 * Common imperative wording.
			 */
			'/\bcreate\s+one\b/i',
			'/\bopen\s+one\b/i',
			'/\bmake\s+one\b/i',

			/*
			 * Explicitly saying not to use the existing ticket.
			 */
			'/\bnot\s+(the|my|an?)\s+existing\s+ticket\b/i',
			'/\bdo\s+not\s+use\s+(the|my)\s+existing\s+ticket\b/i',
			'/\bdon[\'’]?t\s+use\s+(the|my)\s+existing\s+ticket\b/i',
			'/\bnot\s+add\s+(this|that)\s+to\s+(the|my)\s+existing\s+ticket\b/i',
			'/\bdon[\'’]?t\s+add\s+(this|that)\s+to\s+(the|my)\s+existing\s+ticket\b/i',
		);

		foreach ( $patterns as $pattern ) {

			if ( preg_match( $pattern, $message ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine whether the customer is requesting a ticket update.
	 *
	 * This is intentionally conservative.
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
			'/\bfollow up\b/i',
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

			if ( preg_match( $pattern, $message ) ) {
				return true;
			}
		}

		return false;
	}
}