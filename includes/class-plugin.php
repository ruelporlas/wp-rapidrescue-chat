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

	/**
	 * Get plugin instance.
	 *
	 * @return self
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

		/*
		 * Create/update plugin database tables.
		 */
		WP_RapidRescue_Chat_Conversation::create_tables();
		WP_RapidRescue_Chat_Customer::create_table();
		WP_RapidRescue_Chat_Ticket::create_table();

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
			'includes/class-knowledge.php';

		require_once WP_RAPIDRESCUE_CHAT_PATH .
			'includes/class-conversation.php';

		require_once WP_RAPIDRESCUE_CHAT_PATH .
			'includes/class-customer.php';

			require_once WP_RAPIDRESCUE_CHAT_PATH .
	'includes/class-ticket.php';

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
	}

	/**
	 * Register the main plugin admin menu.
	 *
	 * @return void
	 */
	public function register_admin_menu() {

		add_menu_page(
			'WP RapidRescue Chat',
			'WP RapidRescue Chat',
			'manage_options',
			'wp-rapidrescue-chat',
			array(
				$this,
				'render_dashboard',
			),
			'dashicons-format-chat',
			26
		);

		add_submenu_page(
			'wp-rapidrescue-chat',
			'Dashboard',
			'Dashboard',
			'manage_options',
			'wp-rapidrescue-chat',
			array(
				$this,
				'render_dashboard',
			)
		);

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
	}

	/**
	 * Render the plugin dashboard.
	 *
	 * @return void
	 */
	public function render_dashboard() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="wrap">

			<h1>WP RapidRescue Chat</h1>

			<p>
				Manage your AI assistant, business knowledge,
				conversations, and customer support workflow.
			</p>

			<div
				style="
					display: grid;
					grid-template-columns:
						repeat(auto-fit, minmax(240px, 1fr));
					gap: 20px;
					max-width: 1100px;
					margin-top: 25px;
				"
			>

				<div
					style="
						background: #fff;
						border: 1px solid #dcdcde;
						border-radius: 8px;
						padding: 22px;
					"
				>
					<h2>AI Assistant</h2>

					<p>
						Configure the AI provider and define how
						your assistant should behave.
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
							Configure AI
						</a>
					</p>
				</div>

				<div
					style="
						background: #fff;
						border: 1px solid #dcdcde;
						border-radius: 8px;
						padding: 22px;
					"
				>
					<h2>Knowledge Base</h2>

					<p>
						Add the business information the AI is
						allowed to use when answering customers.
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

				<div
					style="
						background: #fff;
						border: 1px solid #dcdcde;
						border-radius: 8px;
						padding: 22px;
					"
				>
					<h2>Conversations</h2>

					<p>
						Review conversations between visitors and
						the AI assistant.
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

			</div>

		</div>
		<?php
	}

	/**
	 * Render the settings page.
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

			<form method="post" action="options.php">

				<?php

				settings_fields(
					'wp_rapidrescue_chat_settings_group'
				);

				do_settings_sections(
					'wp-rapidrescue-chat'
				);

				submit_button();

				?>

			</form>

			<div class="rr-ai-connection-test">

				<h2>AI Connection Test</h2>

				<p>
					Test the currently selected AI provider using
					a simple server-side request.
				</p>

				<p>
					<button
						type="button"
						class="button button-secondary"
						id="wp-rapidrescue-test-ai-connection"
					>
						Test AI Connection
					</button>
				</p>

				<div
					id="wp-rapidrescue-ai-test-result"
					role="status"
					aria-live="polite"
				></div>

			</div>

		</div>
		<?php
	}

	/**
	 * Test the configured AI connection.
	 *
	 * @return void
	 */
	public function test_ai_connection() {

		if ( ! current_user_can( 'manage_options' ) ) {

			wp_send_json_error(
				array(
					'message' =>
						'You do not have permission to perform this test.',
				),
				403
			);
		}

		check_ajax_referer(
			'wp_rapidrescue_test_ai_connection',
			'nonce'
		);

		$test_message =
			'Reply with exactly: Connection successful.';

		$response =
			WP_RapidRescue_Chat_AI::respond(
				$test_message
			);

		if ( is_wp_error( $response ) ) {

			wp_send_json_error(
				array(
					'message' =>
						$response->get_error_message(),
				),
				400
			);
		}

		wp_send_json_success(
			array(
				'message'  =>
					'AI connection successful.',
				'response' =>
					isset( $response['text'] )
						? $response['text']
						: '',
				'provider' =>
					isset( $response['provider'] )
						? $response['provider']
						: '',
				'model'    =>
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