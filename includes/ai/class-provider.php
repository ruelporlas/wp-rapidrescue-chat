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
	 * @param string $message User message.
	 * @return array|WP_Error
	 */
	abstract public function respond( $message );

	/**
	 * Get the provider identifier.
	 *
	 * @return string
	 */
	abstract public function get_id();

	/**
	 * Get the provider display name.
	 *
	 * @return string
	 */
	abstract public function get_name();
}