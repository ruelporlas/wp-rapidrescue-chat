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
 * Manages the configured AI provider.
 */
class WP_RapidRescue_Chat_AI {

	/**
	 * Provider instances.
	 *
	 * @var array
	 */
	private static $providers = array();

	/**
	 * Get a response from the configured provider.
	 *
	 * @param string $message User message.
	 * @return array|WP_Error
	 */
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

		return $provider->respond( $message );
	}

	/**
	 * Get an AI provider.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return WP_RapidRescue_Chat_AI_Provider|WP_Error
	 */
	public static function get_provider( $provider_id ) {

		$provider_id = sanitize_key( $provider_id );

		if ( isset( self::$providers[ $provider_id ] ) ) {
			return self::$providers[ $provider_id ];
		}

		switch ( $provider_id ) {

			case 'openai':
				$provider = new WP_RapidRescue_Chat_AI_OpenAI();
				break;

			case 'gemini':
				$provider = new WP_RapidRescue_Chat_AI_Gemini();
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

	/**
	 * Get available AI providers.
	 *
	 * @return array
	 */
	public static function get_providers() {

		return array(
			'openai' => 'OpenAI',
			'gemini' => 'Google Gemini',
		);
	}

	/**
	 * Get common system instructions.
	 *
	 * @return string
	 */
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