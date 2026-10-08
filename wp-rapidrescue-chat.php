<?php
/**
 * Plugin Name: WP RapidRescue Chat
 * Plugin URI: https://wprapidrescue.com
 * Description: AI-powered customer support and lead qualification for WP RapidRescue.
 * Version: 0.1.0
 * Author: WP RapidRescue
 * Text Domain: wp-rapidrescue-chat
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin version.
 */
define( 'WP_RAPIDRESCUE_CHAT_VERSION', '0.1.0' );

/**
 * Plugin directory path.
 */
define( 'WP_RAPIDRESCUE_CHAT_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Plugin directory URL. 
 */
define( 'WP_RAPIDRESCUE_CHAT_URL', plugin_dir_url( __FILE__ ) );
 
/** 
 * Load the main plugin class.
 */
require_once WP_RAPIDRESCUE_CHAT_PATH . 'includes/class-plugin.php';

/**
 * Register activation hook.
 */
register_activation_hook(
	__FILE__,
	array( 'WP_RapidRescue_Chat_Plugin', 'activate' )
);

/**
 * Register deactivation hook.
 */
register_deactivation_hook(
	__FILE__,
	array( 'WP_RapidRescue_Chat_Plugin', 'deactivate' )
);

/**
 * Start the plugin.
 *
 * @return WP_RapidRescue_Chat_Plugin
 */
function wp_rapidrescue_chat() {
	return WP_RapidRescue_Chat_Plugin::instance();
}

wp_rapidrescue_chat();

