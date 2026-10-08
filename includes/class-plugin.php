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

		/*
		 * Process admin POST actions before WordPress starts
		 * rendering the admin page.
		 *
		 * This is intentionally registered on admin_init rather
		 * than processing POST data inside the page renderer.
		 * Redirects therefore happen before menu-header.php or
		 * any other admin HTML has been sent.
		 */
		add_action(
			'admin_init',
			array(
				$this,
				'handle_admin_post_actions',
			),
			1
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
	 * Process admin POST actions before admin page output.
	 *
	 * @return void
	 */
	public function handle_admin_post_actions() {

		if ( 'POST' !== strtoupper( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		/*
		 * Conversation deletion.
		 */
		$conversation_action = '';

		if ( isset( $_POST['wp_rapidrescue_conversation_action'] ) ) {
			$conversation_action = sanitize_key(
				wp_unslash(
					$_POST['wp_rapidrescue_conversation_action']
				)
			);
		}

		if ( 'delete_conversation' === $conversation_action ) {
			$this->handle_admin_delete_conversation();
			return;
		}

		/*
		 * Ticket actions.
		 */
		$ticket_action = '';

		if ( isset( $_POST['wp_rapidrescue_ticket_action'] ) ) {
			$ticket_action = sanitize_key(
				wp_unslash(
					$_POST['wp_rapidrescue_ticket_action']
				)
			);
		}

		if ( 'delete_ticket' === $ticket_action ) {
			$this->handle_admin_delete_ticket();
			return;
		}

		if ( 'update_ticket' === $ticket_action ) {
			$this->handle_admin_update_ticket();
			return;
		}
	}

	/**
	 * Delete a conversation and its associated data.
	 *
	 * @return void
	 */
	private function handle_admin_delete_conversation() {

		check_admin_referer(
			'wp_rapidrescue_delete_conversation',
			'wp_rapidrescue_conversation_nonce'
		);

		$conversation_id = isset( $_POST['conversation_id'] )
			? absint( $_POST['conversation_id'] )
			: 0;

		if ( $conversation_id < 1 ) {
			wp_die(
				esc_html__(
					'Invalid conversation.',
					'wp-rapidrescue-chat'
				)
			);
		}

		$conversation =
			WP_RapidRescue_Chat_Conversation::get_by_id(
				$conversation_id
			);

		if ( ! $conversation ) {
			wp_die(
				esc_html__(
					'The requested conversation could not be found.',
					'wp-rapidrescue-chat'
				)
			);
		}

		global $wpdb;

		$conversation_table =
			$wpdb->prefix . 'rr_conversations';

		$messages_table =
			$wpdb->prefix . 'rr_messages';

		$tickets_table =
			$wpdb->prefix . 'rr_tickets';

		/*
		 * Delete all tickets associated with the conversation.
		 *
		 * Tickets are deleted first so no ticket remains attached
		 * to a conversation that no longer exists.
		 */
		$tickets_deleted = $wpdb->delete(
			$tickets_table,
			array(
				'conversation_id' => $conversation_id,
			),
			array(
				'%d',
			)
		);

		if ( false === $tickets_deleted ) {
			wp_die(
				esc_html__(
					'The conversation could not be deleted because its tickets could not be removed.',
					'wp-rapidrescue-chat'
				)
			);
		}

		/*
		 * Delete all messages belonging to the conversation.
		 */
		$messages_deleted = $wpdb->delete(
			$messages_table,
			array(
				'conversation_id' => $conversation_id,
			),
			array(
				'%d',
			)
		);

		if ( false === $messages_deleted ) {
			wp_die(
				esc_html__(
					'The conversation could not be deleted because its messages could not be removed.',
					'wp-rapidrescue-chat'
				)
			);
		}

		/*
		 * Delete the conversation itself.
		 */
		$conversation_deleted = $wpdb->delete(
			$conversation_table,
			array(
				'id' => $conversation_id,
			),
			array(
				'%d',
			)
		);

		if ( false === $conversation_deleted ) {
			wp_die(
				esc_html__(
					'The conversation could not be deleted.',
					'wp-rapidrescue-chat'
				)
			);
		}

		/*
		 * Redirect before any admin page output has occurred.
		 */
		$redirect_url = add_query_arg(
			array(
				'page'    => 'wp-rapidrescue-conversations',
				'deleted' => '1',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Delete a ticket.
	 *
	 * @return void
	 */
	private function handle_admin_delete_ticket() {

		check_admin_referer(
			'wp_rapidrescue_delete_ticket',
			'wp_rapidrescue_ticket_delete_nonce'
		);

		$ticket_id = isset( $_POST['ticket_id'] )
			? absint( $_POST['ticket_id'] )
			: 0;

		if ( $ticket_id < 1 ) {
			wp_die(
				esc_html__(
					'Invalid ticket.',
					'wp-rapidrescue-chat'
				)
			);
		}

		$ticket =
			WP_RapidRescue_Chat_Ticket::get_by_id(
				$ticket_id
			);

		if ( ! $ticket ) {
			wp_die(
				esc_html__(
					'The requested ticket could not be found.',
					'wp-rapidrescue-chat'
				)
			);
		}

		/*
		 * Clear the conversation's active ticket pointer when
		 * the deleted ticket is currently active.
		 */
		if ( ! empty( $ticket->conversation_id ) ) {

			$conversation_id =
				absint(
					$ticket->conversation_id
				);

			$active_ticket_key =
				WP_RapidRescue_Chat_Conversation::get_active_ticket_key(
					$conversation_id
				);

			if (
				'' !== $active_ticket_key &&
				0 === strcasecmp(
					$active_ticket_key,
					$ticket->ticket_key
				)
			) {
				WP_RapidRescue_Chat_Conversation::clear_active_ticket(
					$conversation_id
				);
			}
		}

		global $wpdb;

		$table =
			$wpdb->prefix . 'rr_tickets';

		$deleted = $wpdb->delete(
			$table,
			array(
				'id' => $ticket_id,
			),
			array(
				'%d',
			)
		);

		if ( false === $deleted ) {
			wp_die(
				esc_html__(
					'The ticket could not be deleted.',
					'wp-rapidrescue-chat'
				)
			);
		}

		/*
		 * Redirect before any admin page output has occurred.
		 */
		$redirect_url = add_query_arg(
			array(
				'page'    => 'wp-rapidrescue-tickets',
				'deleted' => '1',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Update a ticket's status and priority.
	 *
	 * @return void
	 */
	private function handle_admin_update_ticket() {

		check_admin_referer(
			'wp_rapidrescue_update_ticket',
			'wp_rapidrescue_ticket_nonce'
		);

		$ticket_id = isset( $_POST['ticket_id'] )
			? absint( $_POST['ticket_id'] )
			: 0;

		$status = isset( $_POST['status'] )
			? sanitize_key(
				wp_unslash(
					$_POST['status']
				)
			)
			: '';

		$priority = isset( $_POST['priority'] )
			? sanitize_key(
				wp_unslash(
					$_POST['priority']
				)
			)
			: '';

		if ( $ticket_id < 1 ) {
			wp_die(
				esc_html__(
					'Invalid ticket.',
					'wp-rapidrescue-chat'
				)
			);
		}

		$ticket =
			WP_RapidRescue_Chat_Ticket::get_by_id(
				$ticket_id
			);

		if ( ! $ticket ) {
			wp_die(
				esc_html__(
					'The requested ticket could not be found.',
					'wp-rapidrescue-chat'
				)
			);
		}

		$allowed_statuses = array(
			'open',
			'in_progress',
			'waiting_customer',
			'resolved',
			'closed',
		);

		$allowed_priorities = array(
			'low',
			'normal',
			'high',
			'urgent',
		);

		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			wp_die(
				esc_html__(
					'Invalid ticket status.',
					'wp-rapidrescue-chat'
				)
			);
		}

		if ( ! in_array( $priority, $allowed_priorities, true ) ) {
			wp_die(
				esc_html__(
					'Invalid ticket priority.',
					'wp-rapidrescue-chat'
				)
			);
		}

		$result =
			WP_RapidRescue_Chat_Ticket::update(
				$ticket_id,
				'',
				'',
				$status,
				$priority
			);

		if ( is_wp_error( $result ) ) {
			wp_die(
				esc_html(
					$result->get_error_message()
				)
			);
		}

		/*
		 * Redirect before any admin page output has occurred.
		 */
		$redirect_url = add_query_arg(
			array(
				'page'      => 'wp-rapidrescue-tickets',
				'ticket_id' => $ticket_id,
				'updated'   => '1',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
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
		 * Knowledge Base is registered by class-knowledge.php.
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