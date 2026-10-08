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
	const API_ENDPOINT =
		'https://generativelanguage.googleapis.com/v1beta/interactions';

	/**
	 * Maximum number of tool rounds allowed.
	 *
	 * @var int
	 */
	const MAX_TOOL_ROUNDS = 6;

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
	 * Send a normal request.
	 *
	 * @param string $message User message.
	 * @return array|WP_Error
	 */
	public function respond( $message ) {

		return $this->request(
			$message,
			array(),
			null
		);
	}

	/**
	 * Send a request with business tools.
	 *
	 * @param string $message User message.
	 * @param array  $context Tool execution context.
	 * @return array|WP_Error
	 */
	public function respond_with_tools(
		$message,
		$context = array()
	) {

		$tools = $this->get_tools();

		$input = $message;

		$previous_interaction_id = null;

		for (
			$round = 0;
			$round < self::MAX_TOOL_ROUNDS;
			$round++
		) {

			$response = $this->request(
				$input,
				$tools,
				$previous_interaction_id
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$previous_interaction_id =
				isset(
					$response['response_id']
				)
					? $response['response_id']
					: null;

			$tool_calls =
				isset(
					$response['tool_calls']
				) &&
				is_array(
					$response['tool_calls']
				)
					? $response['tool_calls']
					: array();

			if ( empty( $tool_calls ) ) {
				return $response;
			}

			$function_results = array();

			foreach (
				$tool_calls as $tool_call
			) {

				$tool_name = isset(
					$tool_call['name']
				)
					? sanitize_key(
						$tool_call['name']
					)
					: '';

				$call_id = isset(
					$tool_call['call_id']
				)
					? sanitize_text_field(
						$tool_call['call_id']
					)
					: '';

				$arguments = isset(
					$tool_call['arguments']
				)
					? $tool_call['arguments']
					: array();

				if ( ! is_array( $arguments ) ) {
					$arguments = array();
				}

				$result =
					WP_RapidRescue_Chat_Tool_Manager::execute(
						$tool_name,
						$arguments,
						$context
					);

				if ( is_wp_error( $result ) ) {

					$result = array(
						'success' => false,
						'error'   => $result->get_error_message(),
					);
				}

				/*
				 * Carry successful ticket verification forward to
				 * subsequent tool calls in this request.
				 */
				if (
					'verify_ticket' === $tool_name &&
					is_array( $result ) &&
					! empty(
						$result['verified']
					)
				) {

					if (
						isset(
							$result['customer_id']
						)
					) {
						$context['customer_id'] =
							absint(
								$result['customer_id']
							);
					}

					if (
						isset(
							$result['context']
						) &&
						is_array(
							$result['context']
						) &&
						isset(
							$result['context']['verified_ticket_keys']
						)
					) {

						$context['verified_ticket_keys'] =
							$result['context']['verified_ticket_keys'];
					}
				}

				$function_results[] = array(
					'type' =>
						'function_result',
					'name' =>
						$tool_name,
					'call_id' =>
						$call_id,
					'result' => array(
						array(
							'type' => 'text',
							'text' =>
								wp_json_encode(
									$result
								),
						),
					),
				);
			}

			$input = $function_results;
		}

		return new WP_Error(
			'gemini_tool_round_limit',
			'The AI tool execution limit was reached.'
		);
	}

	/**
	 * Get provider-specific tool definitions.
	 *
	 * Gemini's Interactions API accepts function declarations in this
	 * format.
	 *
	 * @return array
	 */
	public function get_tools() {

		$definitions =
			WP_RapidRescue_Chat_Tool_Manager::get_tools();

		$tools = array();

		foreach (
			$definitions as $tool
		) {

			$tools[] = array(
				'type' =>
					'function',
				'name' =>
					$tool['name'],
				'description' =>
					$tool['description'],
				'parameters' =>
					$tool['parameters'],
			);
		}

		return $tools;
	}

	/**
	 * Make a Gemini Interactions API request.
	 *
	 * @param mixed       $input                  Input.
	 * @param array       $tools                  Tools.
	 * @param string|null $previous_interaction_id Previous interaction.
	 * @return array|WP_Error
	 */
	private function request(
		$input,
		$tools = array(),
		$previous_interaction_id = null
	) {

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
			'model' =>
				$model,
			'input' =>
				$input,
			'system_instruction' =>
				WP_RapidRescue_Chat_AI::get_system_instructions(),
			'store' =>
				false,
		);

		if ( ! empty( $tools ) ) {
			$request_body['tools'] =
				$tools;
		}

		/*
		 * Stateful Interactions API tool loop.
		 */
		if (
			! empty(
				$previous_interaction_id
			)
		) {

			$request_body['previous_interaction_id'] =
				$previous_interaction_id;
		}

		$response = wp_remote_post(
			self::API_ENDPOINT,
			array(
				'timeout' => 60,
				'headers' => array(
					'x-goog-api-key' =>
						$api_key,
					'Content-Type' =>
						'application/json',
				),
				'body' => wp_json_encode(
					$request_body
				),
			)
		);

		if ( is_wp_error( $response ) ) {

			return new WP_Error(
				'gemini_request_failed',
				$response->get_error_message()
			);
		}

		$status_code =
			wp_remote_retrieve_response_code(
				$response
			);

		$body =
			wp_remote_retrieve_body(
				$response
			);

		$data =
			json_decode(
				$body,
				true
			);

		if (
			$status_code < 200 ||
			$status_code >= 300
		) {

			$error_message =
				'The Google Gemini API request failed.';

			if (
				is_array( $data ) &&
				isset(
					$data['error']['message']
				)
			) {

				$error_message =
					sanitize_text_field(
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
			isset(
				$data['output_text']
			) &&
			is_string(
				$data['output_text']
			)
		) {

			$text =
				trim(
					$data['output_text']
				);
		}

		$tool_calls = array();

		if (
			isset(
				$data['steps']
			) &&
			is_array(
				$data['steps']
			)
		) {

			foreach (
				$data['steps'] as $step
			) {

				if (
					! isset(
						$step['type']
					)
				) {
					continue;
				}

				if (
					'function_call' ===
					$step['type']
				) {

					$arguments =
						isset(
							$step['arguments']
						)
							? $step['arguments']
							: array();

					if (
						is_string(
							$arguments
						)
					) {

						$decoded =
							json_decode(
								$arguments,
								true
							);

						$arguments =
							is_array(
								$decoded
							)
								? $decoded
								: array();
					}

					$tool_calls[] = array(
						'id' =>
							isset(
								$step['id']
							)
								? sanitize_text_field(
									$step['id']
								)
								: '',
						'call_id' =>
							isset(
								$step['id']
							)
								? sanitize_text_field(
									$step['id']
								)
								: '',
						'name' =>
							isset(
								$step['name']
							)
								? sanitize_key(
									$step['name']
								)
								: '',
						'arguments' =>
							$arguments,
					);

					continue;
				}

				/*
				 * Some responses expose model text through steps rather
				 * than output_text.
				 */
				if (
					'model_output' ===
					$step['type']
				) {

					if (
						isset(
							$step['content']
						) &&
						is_array(
							$step['content']
						)
					) {

						foreach (
							$step['content'] as $content
						) {

							if (
								isset(
									$content['type']
								) &&
								'text' ===
								$content['type'] &&
								isset(
									$content['text']
								)
							) {

								$text .=
									$content['text'];
							}
						}
					}
				}
			}

			$text =
				trim(
					$text
				);
		}

		if (
			'' === $text &&
			empty( $tool_calls )
		) {

			return new WP_Error(
				'empty_gemini_response',
				'Google Gemini returned no text response.'
			);
		}

		return array(
			'text' =>
				$text,
			'provider' =>
				$this->get_id(),
			'model' =>
				$model,
			'response_id' =>
				isset(
					$data['id']
				)
					? sanitize_text_field(
						$data['id']
					)
					: '',
			'tool_calls' =>
				$tool_calls,
		);
	}
}