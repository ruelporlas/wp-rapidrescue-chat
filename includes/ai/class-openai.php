<?php
/**
 * OpenAI provider.
 *
 * @package WP_RapidRescue_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OpenAI provider.
 */
class WP_RapidRescue_Chat_AI_OpenAI extends WP_RapidRescue_Chat_AI_Provider {

	/**
	 * Responses API endpoint.
	 *
	 * @var string
	 */
	const API_ENDPOINT = 'https://api.openai.com/v1/responses';

	/**
	 * Get provider identifier.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'openai';
	}

	/**
	 * Get provider name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'OpenAI';
	}

	/**
	 * Send a message to OpenAI.
	 *
	 * @param string $message User message.
	 * @return array|WP_Error
	 */
	public function respond( $message ) {

		$api_key = WP_RapidRescue_Chat_Settings::get(
			'openai_api_key',
			''
		);

		$model = WP_RapidRescue_Chat_Settings::get(
			'openai_model',
			'gpt-6-luna'
		);

		if ( '' === $api_key ) {
			return new WP_Error(
				'missing_openai_api_key',
				'The OpenAI API key has not been configured.'
			);
		}

		$request_body = array(
			'model'        => $model,
			'instructions' => WP_RapidRescue_Chat_AI::get_system_instructions(),
			'input'        => $message,
		);

		$response = wp_remote_post(
			self::API_ENDPOINT,
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $request_body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'openai_request_failed',
				$response->get_error_message()
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		if ( $status_code < 200 || $status_code >= 300 ) {

			$error_message = 'The OpenAI API request failed.';

			if (
				is_array( $data ) &&
				isset( $data['error']['message'] )
			) {
				$error_message = sanitize_text_field(
					$data['error']['message']
				);
			}

			return new WP_Error(
				'openai_api_error',
				$error_message
			);
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'invalid_openai_response',
				'OpenAI returned an invalid response.'
			);
		}

		$text = '';

		if (
			isset( $data['output_text'] ) &&
			is_string( $data['output_text'] )
		) {
			$text = trim( $data['output_text'] );
		}

		if ( '' === $text && isset( $data['output'] ) ) {

			foreach ( $data['output'] as $output_item ) {

				if (
					! isset( $output_item['content'] ) ||
					! is_array( $output_item['content'] )
				) {
					continue;
				}

				foreach ( $output_item['content'] as $content_item ) {

					if (
						isset( $content_item['type'] ) &&
						'output_text' === $content_item['type'] &&
						isset( $content_item['text'] )
					) {
						$text .= $content_item['text'];
					}
				}
			}

			$text = trim( $text );
		}

		if ( '' === $text ) {
			return new WP_Error(
				'empty_openai_response',
				'OpenAI returned no text response.'
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