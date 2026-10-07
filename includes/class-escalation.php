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
	 * @param int    $conversation_id Conversation ID.
	 * @param int    $customer_id     Optional customer ID.
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

		$conversation_id = absint(
			$conversation_id
		);

		$customer_id = $customer_id
			? absint( $customer_id )
			: null;

		$subject = sanitize_text_field(
			$subject
		);

		$summary = sanitize_textarea_field(
			$summary
		);

		$priority = sanitize_key(
			$priority
		);

		if ( ! in_array(
			$priority,
			array(
				'low',
				'normal',
				'high',
				'urgent',
			),
			true
		) ) {
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

		/*
		 * If no customer was supplied, use the customer already
		 * attached to the conversation.
		 */
		if ( ! $customer_id && ! empty( $conversation->customer_id ) ) {
			$customer_id = absint(
				$conversation->customer_id
			);
		}

		/*
		 * Build a summary from the conversation if the AI did not
		 * provide one.
		 */
		if ( '' === trim( $summary ) ) {
			$summary = self::build_summary(
				$conversation_id
			);
		}

		/*
		 * Provide a safe fallback subject.
		 */
		if ( '' === trim( $subject ) ) {
			$subject = 'Customer support request';
		}

		$result =
			WP_RapidRescue_Chat_Ticket::create_from_conversation(
				$conversation_id,
				$customer_id,
				$subject,
				$summary,
				$priority
			);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result;
	}

	/**
	 * Build a summary from recent conversation messages.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return string
	 */
	private static function build_summary(
		$conversation_id
	) {

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
	 * This method only confirms an escalation when the application
	 * has actually created or located a real ticket.
	 *
	 * @param array $result Ticket creation result.
	 * @return string
	 */
	public static function get_confirmation_message(
		$result
	) {

		if ( ! is_array( $result ) ) {
			return '';
		}

		$ticket_key = isset(
			$result['ticket_key']
		)
			? sanitize_text_field(
				$result['ticket_key']
			)
			: '';

		if ( '' === $ticket_key ) {
			return '';
		}

		/*
		 * An existing active ticket means the customer's request is
		 * already with the support workflow.
		 */
		if (
			! empty( $result['already_exists'] )
		) {

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