<?php
/**
 * Main plugin class.
 *
 * @package WP_RapidRescue_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin controller.
 */
class WP_RapidRescue_Chat_Plugin {

	private static $instance = null;

	public static function instance() {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {

		$this->load_dependencies();

		WP_RapidRescue_Chat_Conversation::create_tables();
		WP_RapidRescue_Chat_Customer::create_table();
		WP_RapidRescue_Chat_Ticket::create_table();

		WP_RapidRescue_Chat_Tool_Manager::init();

		add_action(
			'init',
			array(
				'WP_RapidRescue_Chat_Knowledge',
				'register',
			)
		);

		add_action(
			'admin_menu',
			array(
				$this,
				'register_admin_menu',
			)
		);

		add_action(
			'admin_init',
			array(
				'WP_RapidRescue_Chat_Settings',
				'register',
			)
		);

		add_action(
			'admin_enqueue_scripts',
			array(
				'WP_RapidRescue_Chat_Settings',
				'enqueue_admin_assets',
			)
		);

		add_action(
			'wp_ajax_wp_rapidrescue_test_ai_connection',
			array(
				$this,
				'test_ai_connection',
			)
		);

		add_action(
			'rest_api_init',
			array(
				'WP_RapidRescue_Chat_REST_API',
				'register_routes',
			)
		);

		WP_RapidRescue_Chat_Chat_Widget::init();
	}

	private function load_dependencies() {

		require_once WP_RAPIDRESCUE_CHAT_PATH .
			'includes/class-settings.php';

		require_once WP_RAPIDRESCUE_CHAT_PATH .
			'includes/class-debug.php';

		WP_RapidRescue_Chat_Debug::init();

		require_once WP_RAPIDRESCUE_CHAT_PATH .
			'includes/class-knowledge.php';

		require_once WP_RAPIDRESCUE_CHAT_PATH .
			'includes/class-conversation.php';

		require_once WP_RAPIDRESCUE_CHAT_PATH .
			'includes/class-customer.php';

		require_once WP_RAPIDRESCUE_CHAT_PATH .
			'includes/class-ticket.php';

		require_once WP_RAPIDRESCUE_CHAT_PATH .
			'includes/class-escalation.php';

		/*
		 * Deterministic application decision engine.
		 */
		require_once WP_RAPIDRESCUE_CHAT_PATH .
			'includes/class-control-engine.php';

		require_once WP_RAPIDRESCUE_CHAT_PATH .
			'includes/tools/class-tool-security.php';

		require_once WP_RAPIDRESCUE_CHAT_PATH .
			'includes/tools/class-tool-manager.php';

		require_once WP_RAPIDRESCUE_CHAT_PATH .
			'includes/ai/class-provider.php';

		require_once WP_RAPIDRESCUE_CHAT_PATH .
			'includes/ai/class-openai.php';

		require_once WP_RAPIDRESCUE_CHAT_PATH .
			'includes/ai/class-gemini.php';

		require_once WP_RAPIDRESCUE_CHAT_PATH .
			'includes/class-ai.php';

		require_once WP_RAPIDRESCUE_CHAT_PATH .
			'includes/class-rest-api.php';

		require_once WP_RAPIDRESCUE_CHAT_PATH .
			'public/class-chat-widget.php';

		require_once WP_RAPIDRESCUE_CHAT_PATH .
			'admin/class-conversations.php';

		require_once WP_RAPIDRESCUE_CHAT_PATH .
			'admin/class-tickets.php';
	}

	public function register_admin_menu() {

		add_menu_page(
			'WP RapidRescue Chat',
			'WP RapidRescue Chat',
			'manage_options',
			'wp-rapidrescue-chat',
			array(
				$this,
				'render_admin_page',
			),
			'dashicons-format-chat',
			58
		);
	}

	public function render_admin_page() {

		echo '<div class="wrap">';
		echo '<h1>WP RapidRescue Chat</h1>';
		echo '<p>Use the submenu pages to manage settings, conversations, tickets, and business knowledge.</p>';
		echo '</div>';
	}

	public function test_ai_connection() {

		if (
			! current_user_can(
				'manage_options'
			)
		) {
			wp_send_json_error(
				array(
					'message' =>
						'You are not allowed to perform this action.',
				),
				403
			);
		}

		check_ajax_referer(
			'wp_rapidrescue_test_ai_connection',
			'nonce'
		);

		$provider_id =
			WP_RapidRescue_Chat_Settings::get(
				'ai_provider',
				'openai'
			);

		$provider =
			WP_RapidRescue_Chat_AI::get_provider(
				$provider_id
			);

		if ( is_wp_error( $provider ) ) {

			wp_send_json_error(
				array(
					'message' =>
						$provider->get_error_message(),
				)
			);
		}

		$response =
			$provider->respond(
				'Respond with exactly: Connection successful.'
			);

		if ( is_wp_error( $response ) ) {

			wp_send_json_error(
				array(
					'message' =>
						$response->get_error_message(),
				)
			);
		}

		wp_send_json_success(
			array(
				'message' =>
					'AI connection successful.',

				'provider' =>
					isset( $response['provider'] )
						? $response['provider']
						: $provider_id,

				'model' =>
					isset( $response['model'] )
						? $response['model']
						: '',
			)
		);
	}

	public static function activate() {

		$defaults =
			WP_RapidRescue_Chat_Settings::get_defaults();

		$existing =
			get_option(
				WP_RapidRescue_Chat_Settings::OPTION_NAME,
				false
			);

		if ( false === $existing ) {

			add_option(
				WP_RapidRescue_Chat_Settings::OPTION_NAME,
				$defaults
			);

			return;
		}

		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$merged =
			wp_parse_args(
				$existing,
				$defaults
			);

		update_option(
			WP_RapidRescue_Chat_Settings::OPTION_NAME,
			$merged
		);
	}

	public static function deactivate() {
		// No cleanup is performed on deactivation.
	}
}