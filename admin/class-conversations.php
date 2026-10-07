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

			<table class="widefat fixed striped">

				<thead>
					<tr>
						<th>ID</th>
						<th>Session</th>
						<th>Status</th>
						<th>Created</th>
						<th>Updated</th>
					</tr>
				</thead>

				<tbody>

					<?php if ( empty( $conversations ) ) : ?>

						<tr>
							<td colspan="5">
								No conversations found.
							</td>
						</tr>

					<?php else : ?>

						<?php foreach ( $conversations as $conversation ) : ?>

							<tr>

								<td>
									<strong>
										<a
											href="<?php echo esc_url(
												add_query_arg(
													array(
														'page' =>
															'wp-rapidrescue-conversations',
														'conversation_id' =>
															$conversation->id,
													),
													admin_url( 'admin.php' )
												)
											); ?>"
										>
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
									white-space: pre-wrap;
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
}