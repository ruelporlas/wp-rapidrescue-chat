<?php
/**
 * Human-support escalation workflow.
 *
 * @package WP_RapidRescue_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles customer escalation into the human-support ticket system.
 */
class WP_RapidRescue_Chat_Escalation {

	/**
	 * Create a support ticket from a conversation.
	 *
	 * IMPORTANT:
	 * This method requires a real customer record. The customer record
	 * currently requires an email address.
	 *
	 * @param int    $conversation_id Conversation ID.
	 * @param int    $customer_id     Customer ID.
	 * @param string $subject         Ticket subject.
	 * @param string $summary         Ticket summary.
	 * @param string $priority        Ticket priority.
	 * @return array|WP_Error
	 */
	public static function create_ticket(
		$conversation_id,
		$customer_id = null,
		$subject = '',
		$summary = '',
		$priority = 'normal'
	) {
		$conversation_id = absint( $conversation_id );
		$customer_id     = absint( $customer_id );

		$subject = sanitize_text_field( $subject );
		$summary = sanitize_textarea_field( $summary );
		$priority = sanitize_key( $priority );

		if (
			! in_array(
				$priority,
				array(
					'low',
					'normal',
					'high',
					'urgent',
				),
				true
			)
		) {
			$priority = 'normal';
		}

		if ( ! $conversation_id ) {
			return new WP_Error(
				'invalid_conversation',
				'The conversation could not be identified.'
			);
		}

		$conversation =
			WP_RapidRescue_Chat_Conversation::get_by_id(
				$conversation_id
			);

		if ( ! $conversation ) {
			return new WP_Error(
				'conversation_not_found',
				'The conversation could not be found.'
			);
		}

		if ( ! $customer_id ) {
			$customer_id = absint(
				$conversation->customer_id
			);
		}

		/*
		 * A ticket cannot be created without a real customer record.
		 * The current customer model requires an email address before
		 * creating that record.
		 */
		if ( ! $customer_id ) {
			return new WP_Error(
				'customer_identity_required',
				'An email address is required before a support ticket can be created.'
			);
		}

		$customer =
			WP_RapidRescue_Chat_Customer::get_by_id(
				$customer_id
			);

		if ( ! $customer ) {
			return new WP_Error(
				'customer_not_found',
				'The customer could not be confirmed.'
			);
		}

		if ( empty( $customer->email ) ) {
			return new WP_Error(
				'customer_email_required',
				'An email address is required before a support ticket can be created.'
			);
		}

		if ( '' === trim( $summary ) ) {
			$summary =
				self::build_summary(
					$conversation_id
				);
		}

		if ( '' === trim( $subject ) ) {
			$subject = 'Customer support request';
		}

		/*
		 * The AI has classified this as a genuinely new issue.
		 * Existing-ticket handling is performed separately by REST.
		 */
		return WP_RapidRescue_Chat_Ticket::create_from_conversation(
			$conversation_id,
			$customer_id,
			$subject,
			$summary,
			$priority,
			true
		);
	}

	/**
	 * Build a summary from recent conversation messages.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return string
	 */
	private static function build_summary( $conversation_id ) {
		$messages =
			WP_RapidRescue_Chat_Conversation::get_recent_messages(
				$conversation_id,
				20
			);

		if ( empty( $messages ) ) {
			return 'Customer requested human support.';
		}

		$summary_lines = array();

		foreach ( $messages as $message ) {
			$role = isset( $message->role )
				? $message->role
				: '';

			$content = isset( $message->message )
				? trim( $message->message )
				: '';

			if ( '' === $content ) {
				continue;
			}

			if ( 'user' === $role ) {
				$label = 'Customer';
			} elseif ( 'assistant' === $role ) {
				$label = 'Assistant';
			} else {
				$label = 'Message';
			}

			$summary_lines[] =
				$label . ': ' . $content;
		}

		if ( empty( $summary_lines ) ) {
			return 'Customer requested human support.';
		}

		return implode(
			"\n",
			$summary_lines
		);
	}

	/**
	 * Build a customer-facing confirmation message.
	 *
	 * @param array $result Ticket creation result.
	 * @return string
	 */
	public static function get_confirmation_message( $result ) {
		if ( ! is_array( $result ) ) {
			return '';
		}

		$ticket_key = isset( $result['ticket_key'] )
			? sanitize_text_field( $result['ticket_key'] )
			: '';

		if ( '' === $ticket_key ) {
			return '';
		}

		if ( ! empty( $result['already_exists'] ) ) {
			return sprintf(
				'Your support request is already with our support team. Your ticket number is %s.',
				$ticket_key
			);
		}

		return sprintf(
			'Your support request has been escalated to our support team. Your ticket number is %s.',
			$ticket_key
		);
	}
} 