<?php
/**
 * Google Gemini provider.
 *
 * @package WP_RapidRescue_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Gemini provider.
 */
class WP_RapidRescue_Chat_AI_Gemini extends WP_RapidRescue_Chat_AI_Provider {

	/**
	 * Gemini Interactions API endpoint.
	 *
	 * @var string
	 */
	const API_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/interactions';

	/**
	 * Get provider identifier.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'gemini';
	}

	/**
	 * Get provider name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'Google Gemini';
	}

	/**
	 * Send a message to Gemini.
	 *
	 * @param string $message User message.
	 * @return array|WP_Error
	 */
	public function respond( $message ) {

		$api_key = WP_RapidRescue_Chat_Settings::get(
			'gemini_api_key',
			''
		);

		$model = WP_RapidRescue_Chat_Settings::get(
			'gemini_model',
			'gemini-3.8-flash'
		);

		if ( '' === $api_key ) {
			return new WP_Error(
				'missing_gemini_api_key',
				'The Google Gemini API key has not been configured.'
			);
		}

		$request_body = array(
			'model'           => $model,
			'input'           => $message,
			'system_instruction' => WP_RapidRescue_Chat_AI::get_system_instructions(),
			'store'           => false,
		);

		$response = wp_remote_post(
			self::API_ENDPOINT,
			array(
				'timeout' => 60,
				'headers' => array(
					'x-goog-api-key' => $api_key,
					'Content-Type'   => 'application/json',
				),
				'body'    => wp_json_encode( $request_body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'gemini_request_failed',
				$response->get_error_message()
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		if ( $status_code < 200 || $status_code >= 300 ) {

			$error_message = 'The Google Gemini API request failed.';

			if (
				is_array( $data ) &&
				isset( $data['error']['message'] )
			) {
				$error_message = sanitize_text_field(
					$data['error']['message']
				);
			}

			return new WP_Error(
				'gemini_api_error',
				$error_message
			);
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'invalid_gemini_response',
				'Google Gemini returned an invalid response.'
			);
		}

		$text = '';

		if (
			isset( $data['output_text'] ) &&
			is_string( $data['output_text'] )
		) {
			$text = trim( $data['output_text'] );
		}

		if ( '' === $text && isset( $data['steps'] ) ) {

			foreach ( $data['steps'] as $step ) {

				if (
					isset( $step['type'] ) &&
					'model_output' === $step['type'] &&
					isset( $step['content'] ) &&
					is_array( $step['content'] )
				) {
					foreach ( $step['content'] as $content ) {

						if (
							isset( $content['type'] ) &&
							'text' === $content['type'] &&
							isset( $content['text'] )
						) {
							$text .= $content['text'];
						}
					}
				}
			}

			$text = trim( $text );
		}

		if ( '' === $text ) {
			return new WP_Error(
				'empty_gemini_response',
				'Google Gemini returned no text response.'
			);
		}

		return array(
			'text'        => $text,
			'provider'    => $this->get_id(),
			'model'       => $model,
			'response_id' => isset( $data['id'] )
				? sanitize_text_field( $data['id'] )
				: '',
		);
	}
}