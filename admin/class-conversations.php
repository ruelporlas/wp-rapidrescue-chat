<?php
/**
 * Conversations admin screen.
 *
 * @package WP_RapidRescue_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Conversations admin screen.
 */
class WP_RapidRescue_Chat_Conversations_Admin {

	/**
	 * Kept for compatibility.
	 *
	 * The menu is now registered by the main plugin controller.
	 *
	 * @return void
	 */
	public static function register_menu() {
		// Menu registration is handled by WP_RapidRescue_Chat_Plugin.
	}

	/**
	 * Render the conversations page.
	 *
	 * @return void
	 */
	public static function render() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		self::handle_delete();

		$conversation_id = isset( $_GET['conversation_id'] )
			? absint( $_GET['conversation_id'] )
			: 0;

		if ( $conversation_id > 0 ) {
			self::render_detail( $conversation_id );
			return;
		}

		self::render_list();
	}

	/**
	 * Handle conversation deletion.
	 *
	 * Deleting a conversation also deletes:
	 * - All messages belonging to the conversation.
	 * - All tickets created from the conversation.
	 *
	 * Customer records are not deleted.
	 *
	 * @return void
	 */
	private static function handle_delete() {

		if ( 'POST' !== strtoupper( $_SERVER['REQUEST_METHOD'] ) ) {
			return;
		}

		if (
			empty( $_POST['wp_rapidrescue_conversation_action'] ) ||
			'delete_conversation' !==
				sanitize_key(
					wp_unslash(
						$_POST['wp_rapidrescue_conversation_action']
					)
				)
		) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__(
					'You do not have permission to delete conversations.',
					'wp-rapidrescue-chat'
				)
			);
		}

		check_admin_referer(
			'wp_rapidrescue_delete_conversation',
			'wp_rapidrescue_conversation_nonce'
		);

		$conversation_id = isset( $_POST['conversation_id'] )
			? absint( $_POST['conversation_id'] )
			: 0;

		if ( $conversation_id < 1 ) {
			self::add_admin_notice(
				'error',
				'Invalid conversation.'
			);

			return;
		}

		global $wpdb;

		$conversation_table =
			$wpdb->prefix . 'rr_conversations';

		$messages_table =
			$wpdb->prefix . 'rr_messages';

		$tickets_table =
			$wpdb->prefix . 'rr_tickets';

		$conversation =
			WP_RapidRescue_Chat_Conversation::get_by_id(
				$conversation_id
			);

		if ( ! $conversation ) {
			self::add_admin_notice(
				'error',
				'The requested conversation could not be found.'
			);

			return;
		}

		/*
		 * Delete tickets associated with this conversation first.
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
			self::add_admin_notice(
				'error',
				'The conversation could not be deleted because its tickets could not be removed.'
			);

			return;
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
			self::add_admin_notice(
				'error',
				'The conversation could not be deleted because its messages could not be removed.'
			);

			return;
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
			self::add_admin_notice(
				'error',
				'The conversation could not be deleted.'
			);

			return;
		}

		$redirect_url = add_query_arg(
			array(
				'page'    => 'wp-rapidrescue-conversations',
				'deleted' => '1',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect(
			$redirect_url
		);

		exit;
	}

	/**
	 * Add a temporary admin notice.
	 *
	 * @param string $type Notice type.
	 * @param string $message Notice message.
	 * @return void
	 */
	private static function add_admin_notice(
		$type,
		$message
	) {

		global $wp_rapidrescue_conversation_admin_notice;

		$wp_rapidrescue_conversation_admin_notice = array(
			'type'    => sanitize_key( $type ),
			'message' => sanitize_text_field( $message ),
		);
	}

	/**
	 * Render conversation list.
	 *
	 * @return void
	 */
	private static function render_list() {

		$conversations =
			self::get_conversations();

		?>
		<div class="wrap">

			<h1>Conversations</h1>

			<p>
				View customer conversations handled by
				WP RapidRescue Chat.
			</p>

			<?php self::render_request_notice(); ?>

			<table class="widefat fixed striped">

				<thead>
					<tr>
						<th>ID</th>
						<th>Session</th>
						<th>Status</th>
						<th>Created</th>
						<th>Updated</th>
						<th style="width:120px;">Actions</th>
					</tr>
				</thead>

				<tbody>

					<?php if ( empty( $conversations ) ) : ?>

						<tr>
							<td colspan="6">
								No conversations found.
							</td>
						</tr>

					<?php else : ?>

						<?php foreach ( $conversations as $conversation ) : ?>

							<?php
							$conversation_url = add_query_arg(
								array(
									'page' =>
										'wp-rapidrescue-conversations',
									'conversation_id' =>
										$conversation->id,
								),
								admin_url( 'admin.php' )
							);
							?>

							<tr>

								<td>
									<strong>
										<a href="<?php echo esc_url( $conversation_url ); ?>">
											#<?php echo esc_html(
												$conversation->id
											); ?>
										</a>
									</strong>
								</td>

								<td>
									<code>
										<?php echo esc_html(
											$conversation->session_id
										); ?>
									</code>
								</td>

								<td>
									<?php echo esc_html(
										ucfirst(
											$conversation->status
										)
									); ?>
								</td>

								<td>
									<?php echo esc_html(
										$conversation->created_at
									); ?>
								</td>

								<td>
									<?php echo esc_html(
										$conversation->updated_at
									); ?>
								</td>

								<td>
									<form
										method="post"
										style="display:inline;"
										onsubmit="return confirm('Delete this conversation and all messages and tickets associated with it? This cannot be undone.');"
									>

										<input
											type="hidden"
											name="wp_rapidrescue_conversation_action"
											value="delete_conversation"
										>

										<input
											type="hidden"
											name="conversation_id"
											value="<?php echo esc_attr(
												$conversation->id
											); ?>"
										>

										<?php
										wp_nonce_field(
											'wp_rapidrescue_delete_conversation',
											'wp_rapidrescue_conversation_nonce'
										);
										?>

										<button
											type="submit"
											class="button button-link-delete"
										>
											Delete
										</button>

									</form>
								</td>

							</tr>

						<?php endforeach; ?>

					<?php endif; ?>

				</tbody>

			</table>

		</div>
		<?php
	}

	/**
	 * Render conversation detail.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return void
	 */
	private static function render_detail( $conversation_id ) {

		$conversation =
			WP_RapidRescue_Chat_Conversation::get_by_id(
				$conversation_id
			);

		if ( ! $conversation ) {

			?>
			<div class="wrap">

				<h1>Conversation Not Found</h1>

				<p>
					The requested conversation could not be found.
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
						Back to Conversations
					</a>
				</p>

			</div>
			<?php

			return;
		}

		$messages =
			WP_RapidRescue_Chat_Conversation::get_recent_messages(
				$conversation_id,
				50
			);

		?>
		<div class="wrap">

			<?php self::render_request_notice(); ?>

			<h1>
				Conversation #<?php echo esc_html(
					$conversation->id
				); ?>
			</h1>

			<p>
				<a
					href="<?php echo esc_url(
						admin_url(
							'admin.php?page=wp-rapidrescue-conversations'
						)
					); ?>"
				>
					&larr; Back to Conversations
				</a>
			</p>

			<div
				style="
					display:flex;
					gap:10px;
					margin:15px 0 25px;
				"
			>

				<form
					method="post"
					onsubmit="return confirm('Delete this conversation and all messages and tickets associated with it? This cannot be undone.');"
				>

					<input
						type="hidden"
						name="wp_rapidrescue_conversation_action"
						value="delete_conversation"
					>

					<input
						type="hidden"
						name="conversation_id"
						value="<?php echo esc_attr(
							$conversation->id
						); ?>"
					>

					<?php
					wp_nonce_field(
						'wp_rapidrescue_delete_conversation',
						'wp_rapidrescue_conversation_nonce'
					);
					?>

					<button
						type="submit"
						class="button button-link-delete"
					>
						Delete Conversation
					</button>

				</form>

			</div>

			<table class="widefat striped">

				<tbody>

					<tr>
						<th style="width: 180px;">
							Session ID
						</th>

						<td>
							<code>
								<?php echo esc_html(
									$conversation->session_id
								); ?>
							</code>
						</td>
					</tr>

					<tr>
						<th>
							Status
						</th>

						<td>
							<?php echo esc_html(
								ucfirst(
									$conversation->status
								)
							); ?>
						</td>
					</tr>

					<tr>
						<th>
							Created
						</th>

						<td>
							<?php echo esc_html(
								$conversation->created_at
							); ?>
						</td>
					</tr>

					<tr>
						<th>
							Last Updated
						</th>

						<td>
							<?php echo esc_html(
								$conversation->updated_at
							); ?>
						</td>
					</tr>

				</tbody>

			</table>

			<h2 style="margin-top: 30px;">
				Messages
			</h2>

			<?php if ( empty( $messages ) ) : ?>

				<p>
					No messages found.
				</p>

			<?php else : ?>

				<div
					style="
						max-width: 900px;
						display: flex;
						flex-direction: column;
						gap: 12px;
					"
				>

					<?php foreach ( $messages as $message ) : ?>

						<?php
						$is_user =
							'user' === $message->role;
						?>

						<div
							style="
								padding: 14px 16px;
								border: 1px solid #dcdcde;
								border-radius: 8px;
								background: <?php echo $is_user
									? '#f0f6fc'
									: '#ffffff'; ?>;
							"
						>

							<div
								style="
									margin-bottom: 6px;
									font-weight: 600;
								"
							>
								<?php echo $is_user
									? 'Customer'
									: 'AI Assistant'; ?>
							</div>

							<div
								style="
									overflow-wrap: anywhere;
								"
							>
								<?php echo esc_html(
									$message->message
								); ?>
							</div>

							<div
								style="
									margin-top: 8px;
									color: #646970;
									font-size: 12px;
								"
							>
								<?php echo esc_html(
									$message->created_at
								); ?>
							</div>

						</div>

					<?php endforeach; ?>

				</div>

			<?php endif; ?>

		</div>
		<?php
	}

	/**
	 * Get conversations.
	 *
	 * @return array
	 */
	private static function get_conversations() {

		global $wpdb;

		$table =
			$wpdb->prefix . 'rr_conversations';

		return $wpdb->get_results(
			"SELECT *
			FROM {$table}
			ORDER BY updated_at DESC
			LIMIT 100"
		);
	}

	/**
	 * Render admin notices.
	 *
	 * @return void
	 */
	private static function render_request_notice() {

		global $wp_rapidrescue_conversation_admin_notice;

		if (
			is_array(
				$wp_rapidrescue_conversation_admin_notice
			)
		) {

			$type =
				isset(
					$wp_rapidrescue_conversation_admin_notice['type']
				)
					? $wp_rapidrescue_conversation_admin_notice['type']
					: 'error';

			$message =
				isset(
					$wp_rapidrescue_conversation_admin_notice['message']
				)
					? $wp_rapidrescue_conversation_admin_notice['message']
					: '';

			if ( '' !== $message ) {
				?>
				<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible">
					<p>
						<?php echo esc_html( $message ); ?>
					</p>
				</div>
				<?php
			}

			$wp_rapidrescue_conversation_admin_notice = null;
		}

		if (
			isset( $_GET['deleted'] ) &&
			'1' ===
				sanitize_text_field(
					wp_unslash(
						$_GET['deleted']
					)
				)
		) {
			?>
			<div class="notice notice-success is-dismissible">
				<p>
					Conversation, messages, and associated tickets were deleted successfully.
				</p>
			</div>
			<?php
		}
	}
}