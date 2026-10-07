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
 * Main plugin class.
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
		$this->register_hooks();
	}

	/**
	 * Load plugin dependencies.
	 *
	 * @return void
	 */
	private function load_dependencies() {

		require_once WP_RAPIDRESCUE_CHAT_PATH . 'includes/class-settings.php';
	}

	/**
	 * Register plugin hooks.
	 *
	 * @return void
	 */
	private function register_hooks() {

		add_action(
			'admin_init',
			array( 'WP_RapidRescue_Chat_Settings', 'register' )
		);

		add_action(
			'admin_menu',
			array( $this, 'register_admin_menu' )
		);
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
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		?>
		<div class="wrap">
			<h1>WP RapidRescue Chat</h1>

			<form method="post" action="options.php">

				<?php
				settings_fields( 'wp_rapidrescue_chat_settings_group' );
				do_settings_sections( 'wp-rapidrescue-chat' );
				submit_button();
				?>

			</form>
		</div>
		<?php
	}

	/**
	 * Run when the plugin is activated.
	 *
	 * @return void
	 */
	public static function activate() {
		// Initial activation setup will be added here.
	}

	/**
	 * Run when the plugin is deactivated.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Initial deactivation cleanup will be added here.
	}
}