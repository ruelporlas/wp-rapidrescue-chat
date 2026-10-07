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
	 * Plugin instance.
	 *
	 * @var WP_RapidRescue_Chat_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the plugin instance.
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

		add_action(
			'init',
			array(
				'WP_RapidRescue_Chat_Knowledge',
				'register_post_type',
			)
		);

		add_action(
			'init',
			array(
				'WP_RapidRescue_Chat_Knowledge',
				'register_taxonomy',
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
			'includes/ai/class-provider.php';

		require_once WP_RAPIDRESCUE_CHAT_PATH .
			'includes/ai/class-openai.php';

		require_once WP_RAPIDRESCUE_CHAT_PATH .
			'includes/ai/class-gemini.php';

		require_once WP_RAPIDRESCUE_CHAT_PATH .
			'includes/class-ai.php';

		require_once WP_RAPIDRESCUE_CHAT_PATH .
			'includes/class-rest-api.php';
	}

	/**
	 * Register the plugin admin menu.
	 *
	 * @return void
	 */
	public function register_admin_menu() {

		add_options_page(
			'WP RapidRescue Chat',
			'WP RapidRescue Chat',
			'manage_options',
			'wp-rapidrescue-chat',
			array(
				$this,
				'render_settings_page',
			)
		);
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

			<h1>WP RapidRescue Chat</h1>

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
}