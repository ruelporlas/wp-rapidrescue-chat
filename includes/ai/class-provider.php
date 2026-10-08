<?php
/**
 * Base AI provider.
 *
 * @package WP_RapidRescue_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for AI providers.
 */
abstract class WP_RapidRescue_Chat_AI_Provider {

	/**
	 * Send a message to the provider.
	 *
	 * @param string $message User message/prompt.
	 * @return array|WP_Error
	 */
	abstract public function respond( $message );

	/**
	 * Send a message with tool calling enabled.
	 *
	 * Providers that support native tools should override this.
	 *
	 * @param string $message User message/prompt.
	 * @param array  $context Tool execution context.
	 * @return array|WP_Error
	 */
	public function respond_with_tools(
		$message,
		$context = array()
	) {
		return $this->respond( $message );
	}

	/**
	 * Get provider identifier.
	 *
	 * @return string
	 */
	abstract public function get_id();

	/**
	 * Get provider display name.
	 *
	 * @return string
	 */
	abstract public function get_name();

	/**
	 * Get provider-neutral tools in provider-specific format.
	 *
	 * @return array
	 */
	public function get_tools() {

		return array();
	}

	/**
	 * Normalize a provider response.
	 *
	 * @param array $response Provider response.
	 * @return array
	 */
	protected function normalize_response( $response ) {

		if ( ! is_array( $response ) ) {
			return array(
				'text' => '',
			);
		}

		return $response;
	}
}