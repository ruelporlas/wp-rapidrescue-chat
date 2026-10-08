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

	/**
	 * Singleton instance.
	 *
	 * @var WP_RapidRescue_Chat_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get plugin instance.
	 *
	 * @return WP_RapidRescue_Chat_Plugin
	 */
	public static function instance() {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
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

	/**
	 * Load plugin dependencies.
	 *
	 * @return void
	 */
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

	/**
	 * Register plugin admin menu and submenus.
	 *
	 * @return void
	 */
	public function register_admin_menu() {

		/*
		 * Main plugin menu.
		 */
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

		/*
		 * Dashboard.
		 *
		 * Re-register the same slug as a submenu so the top-level
		 * menu has a proper Dashboard entry.
		 */
		add_submenu_page(
			'wp-rapidrescue-chat',
			'Dashboard',
			'Dashboard',
			'manage_options',
			'wp-rapidrescue-chat',
			array(
				$this,
				'render_admin_page',
			)
		);

		/*
		 * Settings.
		 */
		add_submenu_page(
			'wp-rapidrescue-chat',
			'Settings',
			'Settings',
			'manage_options',
			'wp-rapidrescue-chat-settings',
			array(
				$this,
				'render_settings_page',
			)
		);

		/*
		 * Conversations.
		 */
		add_submenu_page(
			'wp-rapidrescue-chat',
			'Conversations',
			'Conversations',
			'manage_options',
			'wp-rapidrescue-conversations',
			array(
				'WP_RapidRescue_Chat_Conversations_Admin',
				'render',
			)
		);

		/*
		 * Tickets.
		 */
		add_submenu_page(
			'wp-rapidrescue-chat',
			'Tickets',
			'Tickets',
			'manage_options',
			'wp-rapidrescue-tickets',
			array(
				'WP_RapidRescue_Chat_Tickets_Admin',
				'render',
			)
		);

		/*
		 * Knowledge Base is registered by class-knowledge.php with:
		 *
		 * show_in_menu => wp-rapidrescue-chat
		 *
		 * Therefore WordPress automatically places it underneath
		 * this top-level menu. We intentionally do not register it
		 * again here.
		 */
	}

	/**
	 * Render the plugin dashboard.
	 *
	 * @return void
	 */
	public function render_admin_page() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="wrap">

			<h1>WP RapidRescue Chat</h1>

			<p>
				Manage your AI customer support assistant,
				conversations, tickets, settings, and business knowledge.
			</p>

			<div
				style="
					display:grid;
					grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
					gap:20px;
					max-width:1000px;
					margin-top:25px;
				"
			>

				<div
					style="
						background:#fff;
						border:1px solid #dcdcde;
						border-radius:8px;
						padding:22px;
					"
				>
					<h2 style="margin-top:0;">
						AI Settings
					</h2>

					<p>
						Configure your AI provider, model, API credentials,
						and assistant behavior.
					</p>

					<p>
						<a
							class="button button-primary"
							href="<?php echo esc_url(
								admin_url(
									'admin.php?page=wp-rapidrescue-chat-settings'
								)
							); ?>"
						>
							Open Settings
						</a>
					</p>
				</div>

				<div
					style="
						background:#fff;
						border:1px solid #dcdcde;
						border-radius:8px;
						padding:22px;
					"
				>
					<h2 style="margin-top:0;">
						Conversations
					</h2>

					<p>
						Review customer conversations handled by
						WP RapidRescue Chat.
					</p>

					<p>
						<a
							class="button"
							href="<?php echo esc_url(
								admin_url(
									'admin.php?page=wp-rapidrescue-conversations'
								)
							); ?>"
						>
							View Conversations
						</a>
					</p>
				</div>

				<div
					style="
						background:#fff;
						border:1px solid #dcdcde;
						border-radius:8px;
						padding:22px;
					"
				>
					<h2 style="margin-top:0;">
						Tickets
					</h2>

					<p>
						Manage support tickets created by the AI assistant.
					</p>

					<p>
						<a
							class="button"
							href="<?php echo esc_url(
								admin_url(
									'admin.php?page=wp-rapidrescue-tickets'
								)
							); ?>"
						>
							View Tickets
						</a>
					</p>
				</div>

				<div
					style="
						background:#fff;
						border:1px solid #dcdcde;
						border-radius:8px;
						padding:22px;
					"
				>
					<h2 style="margin-top:0;">
						Knowledge Base
					</h2>

					<p>
						Manage the business information the AI is allowed
						to use when answering customers.
					</p>

					<p>
						<a
							class="button"
							href="<?php echo esc_url(
								admin_url(
									'edit.php?post_type=rr_knowledge'
								)
							); ?>"
						>
							Manage Knowledge
						</a>
					</p>
				</div>

			</div>

		</div>
		<?php
	}

	/**
	 * Render plugin settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="wrap">

			<h1>WP RapidRescue Chat Settings</h1>

			<form
				method="post"
				action="options.php"
			>

				<?php
				settings_fields(
					'wp_rapidrescue_chat_settings_group'
				);

				do_settings_sections(
					'wp-rapidrescue-chat'
				);

				submit_button(
					'Save Settings'
				);
				?>

			</form>

		</div>
		<?php
	}

	/**
	 * Test the configured AI connection.
	 *
	 * @return void
	 */
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

	/**
	 * Plugin activation.
	 *
	 * @return void
	 */
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

	/**
	 * Plugin deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// No cleanup is performed on deactivation.
	}
}