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
	 * Maximum number of tool rounds per customer request.
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
	public function respond(
		$message
	) {

		return $this->request(
			$message,
			array(),
			null
		);
	}

	/**
	 * Send a request with business tools.
	 *
	 * @param string $message User prompt.
	 * @param array  $context Tool execution context.
	 * @return array|WP_Error
	 */
	public function respond_with_tools(
		$message,
		$context = array()
	) {

		$tools =
			$this->get_tools();

		$input =
			$message;

		$previous_interaction_id =
			null;

		$all_tool_results =
			array();

		WP_RapidRescue_Chat_Debug::ai(
			'Gemini tool-enabled request started',
			array(
				'tool_count' =>
					is_array( $tools )
						? count( $tools )
						: 0,
				'message_length' =>
					strlen( (string) $message ),
			)
		);

		for (
			$round = 0;
			$round < self::MAX_TOOL_ROUNDS;
			$round++
		) {

			WP_RapidRescue_Chat_Debug::ai(
				'Gemini request round',
				array(
					'round' =>
						$round + 1,
					'previous_interaction_present' =>
						! empty(
							$previous_interaction_id
						),
					'input_type' =>
						is_array( $input )
							? 'array'
							: 'string',
					'input_count' =>
						is_array( $input )
							? count( $input )
							: null,
				)
			);

			$response =
				$this->request(
					$input,
					$tools,
					$previous_interaction_id
				);

			if ( is_wp_error( $response ) ) {

				WP_RapidRescue_Chat_Debug::ai(
					'Gemini request failed',
					array(
						'round' =>
							$round + 1,
						'error_code' =>
							$response->get_error_code(),
						'error_message' =>
							$response->get_error_message(),
					)
				);

				return $response;
			}

			$previous_interaction_id =
				isset(
					$response['response_id']
				)
					? $response['response_id']
					: null;

			WP_RapidRescue_Chat_Debug::ai(
				'Gemini response received',
				array(
					'round' =>
						$round + 1,
					'response_id_present' =>
						! empty(
							$previous_interaction_id
						),
				)
			);

			$tool_calls =
				isset(
					$response['tool_calls']
				) &&
				is_array(
					$response['tool_calls']
				)
					? $response['tool_calls']
					: array();

			WP_RapidRescue_Chat_Debug::ai(
				'Gemini tool calls inspected',
				array(
					'round' =>
						$round + 1,
					'tool_call_count' =>
						count( $tool_calls ),
				)
			);

			/*
			 * No tool call means the model has completed its response.
			 */
			if ( empty( $tool_calls ) ) {

				$response['tool_results'] =
					$all_tool_results;

				return $response;
			}

			$function_results =
				array();

			foreach (
				$tool_calls as $tool_call
			) {

				$tool_name =
					isset(
						$tool_call['name']
					)
						? sanitize_key(
							$tool_call['name']
						)
						: '';

				$call_id =
					isset(
						$tool_call['call_id']
					)
						? sanitize_text_field(
							$tool_call['call_id']
						)
						: '';

				$arguments =
					isset(
						$tool_call['arguments']
					)
						? $tool_call['arguments']
						: array();

				if ( ! is_array( $arguments ) ) {
					$arguments = array();
				}

				WP_RapidRescue_Chat_Debug::tool(
					'Executing Gemini function call',
					array(
						'tool' =>
							$tool_name,
						'call_id_present' =>
							'' !== $call_id,
						'argument_keys' =>
							array_keys( $arguments ),
					)
				);

				$result =
					WP_RapidRescue_Chat_Tool_Manager::execute(
						$tool_name,
						$arguments,
						$context
					);

				if ( is_wp_error( $result ) ) {

					WP_RapidRescue_Chat_Debug::tool(
						'Gemini function returned WP_Error',
						array(
							'tool' =>
								$tool_name,
							'error_code' =>
								$result->get_error_code(),
						)
					);

					$result =
						array(
							'success' =>
								false,
							'state' =>
								'tool_error',
							'next_action' =>
								'tell_customer_tool_error',
							'error' =>
								$result->get_error_message(),
						);
				}

				/*
				 * Preserve the complete result for the REST layer.
				 */
				$all_tool_results[] =
					array(
						'tool' =>
							$tool_name,
						'call_id' =>
							$call_id,
						'result' =>
							$result,
					);

				/*
				 * Carry verified customer/ticket authorization.
				 */
				if (
					'verify_ticket' ===
					$tool_name &&
					is_array( $result ) &&
					! empty(
						$result['success']
					) &&
					'verified' ===
					(
						isset(
							$result['state']
						)
							? $result['state']
							: ''
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

				/*
				 * Encode the application result separately first.
				 * This lets us detect PHP JSON encoding failures
				 * before sending anything to Gemini.
				 */
				$result_json =
					wp_json_encode(
						$result
					);

				$json_error =
					function_exists( 'json_last_error_msg' )
						? json_last_error_msg()
						: '';

				if (
					false === $result_json
				) {

					WP_RapidRescue_Chat_Debug::tool(
						'Tool result JSON encoding failed',
						array(
							'tool' =>
								$tool_name,
							'json_error' =>
								$json_error,
						)
					);

					return new WP_Error(
						'gemini_tool_result_json_error',
						'The tool result could not be encoded as JSON.'
					);
				}

				WP_RapidRescue_Chat_Debug::tool(
					'Tool result encoded for Gemini',
					array(
						'tool' =>
							$tool_name,
						'json_length' =>
							strlen( $result_json ),
						'json_error' =>
							$json_error,
					)
				);

				$function_result =
					array(
						'type' =>
							'function_result',

						'name' =>
							$tool_name,

						'call_id' =>
							$call_id,

						'result' =>
							array(
								array(
									'type' =>
										'text',

									'text' =>
										$result_json,
								),
							),
					);

				$function_results[] =
					$function_result;
			}

			/*
			 * Validate the complete next input before sending it.
			 */
			$function_results_json =
				wp_json_encode(
					$function_results
				);

			if ( false === $function_results_json ) {

				$json_error =
					function_exists( 'json_last_error_msg' )
						? json_last_error_msg()
						: '';

				WP_RapidRescue_Chat_Debug::ai(
					'Gemini function-result payload JSON encoding failed',
					array(
						'round' =>
							$round + 1,
						'json_error' =>
							$json_error,
					)
				);

				return new WP_Error(
					'gemini_function_results_json_error',
					'The Gemini function-result payload could not be encoded as JSON.'
				);
			}

			WP_RapidRescue_Chat_Debug::ai(
				'Gemini function-result payload prepared',
				array(
					'round' =>
						$round + 1,
					'function_result_count' =>
						count( $function_results ),
					'payload_length' =>
						strlen( $function_results_json ),
				)
			);

			$input =
				$function_results;
		}

		return new WP_Error(
			'gemini_tool_round_limit',
			'The AI tool execution limit was reached.'
		);
	}

	/**
	 * Get provider-specific tool definitions.
	 *
	 * @return array
	 */
	public function get_tools() {

		$definitions =
			WP_RapidRescue_Chat_Tool_Manager::get_tools();

		$tools =
			array();

		foreach (
			$definitions as $tool
		) {

			$tools[] =
				array(
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
	 * @param mixed       $input Input.
	 * @param array       $tools Tools.
	 * @param string|null $previous_interaction_id Previous interaction.
	 * @return array|WP_Error
	 */
	private function request(
		$input,
		$tools = array(),
		$previous_interaction_id = null
	) {

		$api_key =
			WP_RapidRescue_Chat_Settings::get(
				'gemini_api_key',
				''
			);

		$model =
			WP_RapidRescue_Chat_Settings::get(
				'gemini_model',
				'gemini-3.8-flash'
			);

		if ( '' === $api_key ) {

			return new WP_Error(
				'missing_gemini_api_key',
				'The Google Gemini API key has not been configured.'
			);
		}

		$request_body =
			array(
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

		if (
			! empty(
				$previous_interaction_id
			)
		) {

			$request_body['previous_interaction_id'] =
				$previous_interaction_id;
		}

		$request_json =
			wp_json_encode(
				$request_body
			);

		if ( false === $request_json ) {

			$json_error =
				function_exists( 'json_last_error_msg' )
					? json_last_error_msg()
					: '';

			WP_RapidRescue_Chat_Debug::ai(
				'Gemini request JSON encoding failed',
				array(
					'json_error' =>
						$json_error,
					'input_type' =>
						is_array( $input )
							? 'array'
							: 'string',
					'input_count' =>
						is_array( $input )
							? count( $input )
							: null,
				)
			);

			return new WP_Error(
				'gemini_request_json_error',
				'The Gemini request could not be encoded as JSON.'
			);
		}

		WP_RapidRescue_Chat_Debug::ai(
			'Sending Gemini HTTP request',
			array(
				'model' =>
					$model,
				'payload_length' =>
					strlen( $request_json ),
				'input_type' =>
					is_array( $input )
						? 'array'
						: 'string',
				'input_count' =>
					is_array( $input )
						? count( $input )
						: null,
				'tools_count' =>
					is_array( $tools )
						? count( $tools )
						: 0,
				'previous_interaction_present' =>
					! empty(
						$previous_interaction_id
					),
			)
		);

		$response =
			wp_remote_post(
				self::API_ENDPOINT,
				array(
					'timeout' =>
						60,

					'headers' =>
						array(
							'x-goog-api-key' =>
								$api_key,

							'Content-Type' =>
								'application/json',
						),

					'body' =>
						$request_json,
				)
			);

		if ( is_wp_error( $response ) ) {

			WP_RapidRescue_Chat_Debug::ai(
				'Gemini HTTP request returned WP_Error',
				array(
					'error_code' =>
						$response->get_error_code(),
				)
			);

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

		WP_RapidRescue_Chat_Debug::ai(
			'Gemini HTTP response received',
			array(
				'status_code' =>
					$status_code,
				'body_length' =>
					strlen( $body ),
			)
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

			WP_RapidRescue_Chat_Debug::ai(
				'Gemini API returned an error',
				array(
					'status_code' =>
						$status_code,
					'error_message' =>
						$error_message,
				)
			);

			return new WP_Error(
				'gemini_api_error',
				$error_message
			);
		}

		if ( ! is_array( $data ) ) {

			WP_RapidRescue_Chat_Debug::ai(
				'Gemini response JSON decoding failed',
				array(
					'json_error' =>
						function_exists( 'json_last_error_msg' )
							? json_last_error_msg()
							: '',
				)
			);

			return new WP_Error(
				'invalid_gemini_response',
				'Google Gemini returned an invalid response.'
			);
		}

		$text =
			'';

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

		$tool_calls =
			array();

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

					$tool_calls[] =
						array(
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