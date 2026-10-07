<?php
/**
 * AI provider manager.
 *
 * @package WP_RapidRescue_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages AI providers and knowledge context.
 */
class WP_RapidRescue_Chat_AI {

	private static $providers = array();

	public static function respond( $message ) {

		$message = sanitize_textarea_field( $message );

		if ( '' === $message ) {
			return new WP_Error(
				'empty_message',
				'The message cannot be empty.'
			);
		}

		$provider_id = WP_RapidRescue_Chat_Settings::get(
			'ai_provider',
			'openai'
		);

		$provider = self::get_provider( $provider_id );

		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$knowledge = WP_RapidRescue_Chat_Knowledge::search(
			$message,
			5
		);

		$prompt = self::build_prompt(
			$message,
			$knowledge
		);

		return $provider->respond( $prompt );
	}

	private static function build_prompt( $message, $knowledge ) {

		$prompt = array();

		$prompt[] =
			'CUSTOMER MESSAGE:';

		$prompt[] =
			$message;

		$prompt[] = '';

		$prompt[] =
			'CONFIRMED WP RAPIDRESCUE KNOWLEDGE:';

		if ( empty( $knowledge ) ) {

			$prompt[] =
				'No matching confirmed knowledge was found.';

		} else {

			foreach ( $knowledge as $entry ) {

				$title = isset( $entry['title'] )
					? $entry['title']
					: '';

				$content = isset( $entry['content'] )
					? wp_strip_all_tags(
						$entry['content']
					)
					: '';

				$categories = isset( $entry['categories'] )
					? $entry['categories']
					: array();

				$prompt[] =
					'--- Knowledge Entry ---';

				$prompt[] =
					'Title: ' . $title;

				if ( ! empty( $categories ) ) {

					$prompt[] =
						'Categories: ' .
						implode(
							', ',
							$categories
						);
				}

				$prompt[] =
					'Content:';

				$prompt[] =
					$content;

				$prompt[] = '';
			}
		}

		$prompt[] =
			'INSTRUCTIONS:';

		$prompt[] =
			'Use the confirmed knowledge above when answering questions about WP RapidRescue.';

		$prompt[] =
			'Do not invent business information that is not supported by the confirmed knowledge.';

		$prompt[] =
			'If the confirmed knowledge does not contain the answer to a business question, clearly say that the information is not confirmed.';

		$prompt[] =
			'For WordPress troubleshooting, provide useful guidance when you can do so safely and confidently.';

		$prompt[] =
			'Do not claim that an issue has been fixed unless an actual fix has been performed and confirmed.';

		return implode(
			"\n",
			$prompt
		);
	}

	public static function get_provider( $provider_id ) {

		$provider_id = sanitize_key( $provider_id );

		if ( isset( self::$providers[ $provider_id ] ) ) {
			return self::$providers[ $provider_id ];
		}

		switch ( $provider_id ) {

			case 'openai':
				$provider =
					new WP_RapidRescue_Chat_AI_OpenAI();
				break;

			case 'gemini':
				$provider =
					new WP_RapidRescue_Chat_AI_Gemini();
				break;

			default:
				return new WP_Error(
					'unsupported_ai_provider',
					'The selected AI provider is not supported.'
				);
		}

		self::$providers[ $provider_id ] = $provider;

		return $provider;
	}

	public static function get_providers() {

		return array(
			'openai' => 'OpenAI',
			'gemini' => 'Google Gemini',
		);
	}

	public static function get_system_instructions() {

		return implode(
			"\n",
			array(
				'You are the AI support assistant for WP RapidRescue.',
				'Answer clearly, accurately, and professionally.',
				'Do not invent business information.',
				'Do not invent pricing, policies, guarantees, turnaround times, services, or procedures.',
				'If confirmed information is not available, say that the information is not confirmed.',
				'Do not claim that a WordPress problem has been fixed unless an actual fix has been performed and confirmed.',
				'When information is missing or the issue requires human assistance, recommend escalation rather than guessing.',
			)
		);
	}
}