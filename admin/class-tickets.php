<?php
/**
 * Tickets admin screen.
 *
 * @package WP_RapidRescue_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Tickets admin screen.
 */
class WP_RapidRescue_Chat_Tickets_Admin {

	/**
	 * Kept for compatibility.
	 *
	 * @return void
	 */
	public static function register_menu() {
		// Menu registration is handled by the main plugin controller.
	}

	/**
	 * Render the tickets page.
	 *
	 * @return void
	 */
	public static function render() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		self::handle_update();

		$ticket_id = isset( $_GET['ticket_id'] )
			? absint( $_GET['ticket_id'] )
			: 0;

		if ( $ticket_id > 0 ) {
			self::render_detail( $ticket_id );
			return;
		}

		self::render_list();
	}

	/**
	 * Handle ticket updates.
	 *
	 * @return void
	 */
	private static function handle_update() {

		if ( 'POST' !== strtoupper( $_SERVER['REQUEST_METHOD'] ) ) {
			return;
		}

		if (
			empty( $_POST['wp_rapidrescue_ticket_action'] ) ||
			'update_ticket' !==
				sanitize_key(
					wp_unslash(
						$_POST['wp_rapidrescue_ticket_action']
					)
				)
		) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__(
					'You do not have permission to update tickets.',
					'wp-rapidrescue-chat'
				)
			);
		}

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
			self::add_admin_notice(
				'error',
				'Invalid ticket.'
			);

			return;
		}

		$ticket =
			WP_RapidRescue_Chat_Ticket::get_by_id(
				$ticket_id
			);

		if ( ! $ticket ) {
			self::add_admin_notice(
				'error',
				'The requested ticket could not be found.'
			);

			return;
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
			self::add_admin_notice(
				'error',
				'Invalid ticket status.'
			);

			return;
		}

		if ( ! in_array( $priority, $allowed_priorities, true ) ) {
			self::add_admin_notice(
				'error',
				'Invalid ticket priority.'
			);

			return;
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
			self::add_admin_notice(
				'error',
				$result->get_error_message()
			);

			return;
		}

		$redirect_url =
			add_query_arg(
				array(
					'page'      => 'wp-rapidrescue-tickets',
					'ticket_id' => $ticket_id,
					'updated'   => '1',
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

		global $wp_rapidrescue_ticket_admin_notice;

		$wp_rapidrescue_ticket_admin_notice = array(
			'type'    => sanitize_key( $type ),
			'message' => sanitize_text_field( $message ),
		);
	}

	/**
	 * Render list page.
	 *
	 * @return void
	 */
	private static function render_list() {

		global $wpdb;

		$table = $wpdb->prefix . 'rr_tickets';

		$tickets = $wpdb->get_results(
			"SELECT *
			FROM {$table}
			ORDER BY updated_at DESC
			LIMIT 100"
		);

		$counts = self::get_counts();

		?>
		<div class="wrap">

			<h1 class="wp-heading-inline">
				Tickets
			</h1>

			<hr class="wp-header-end">

			<p>
				Manage customer support requests created by
				WP RapidRescue Chat.
			</p>

			<?php self::render_request_notice(); ?>

			<div
				style="
					display:grid;
					grid-template-columns:repeat(auto-fit,minmax(150px,1fr));
					gap:12px;
					max-width:900px;
					margin:20px 0 25px;
				"
			>

				<?php
				self::render_count_card(
					'Open',
					isset( $counts['open'] )
						? $counts['open']
						: 0
				);

				self::render_count_card(
					'In Progress',
					isset( $counts['in_progress'] )
						? $counts['in_progress']
						: 0
				);

				self::render_count_card(
					'Waiting',
					isset( $counts['waiting_customer'] )
						? $counts['waiting_customer']
						: 0
				);

				self::render_count_card(
					'Resolved',
					isset( $counts['resolved'] )
						? $counts['resolved']
						: 0
				);

				self::render_count_card(
					'Closed',
					isset( $counts['closed'] )
						? $counts['closed']
						: 0
				);
				?>

			</div>

			<table class="widefat fixed striped">

				<thead>
					<tr>
						<th>Ticket</th>
						<th>Customer</th>
						<th>Email</th>
						<th>Subject</th>
						<th>Status</th>
						<th>Priority</th>
						<th>Created</th>
						<th>Updated</th>
					</tr>
				</thead>

				<tbody>

					<?php if ( empty( $tickets ) ) : ?>

						<tr>
							<td colspan="8">
								No tickets found.
							</td>
						</tr>

					<?php else : ?>

						<?php foreach ( $tickets as $ticket ) : ?>

							<?php
							$ticket_url = add_query_arg(
								array(
									'page'      =>
										'wp-rapidrescue-tickets',
									'ticket_id' =>
										$ticket->id,
								),
								admin_url( 'admin.php' )
							);

							$customer =
								self::get_customer(
									$ticket
								);
							?>

							<tr>

								<td>
									<strong>
										<a href="<?php echo esc_url( $ticket_url ); ?>">
											<?php echo esc_html( $ticket->ticket_key ); ?>
										</a>
									</strong>
								</td>

								<td>
									<?php
									if ( $customer ) {
										echo esc_html(
											$customer->name
										);
									} elseif ( ! empty( $ticket->customer_id ) ) {
										echo 'Customer #' .
											esc_html(
												$ticket->customer_id
											);
									} else {
										echo 'Anonymous';
									}
									?>
								</td>

								<td>
									<?php
									if ( ! empty( $ticket->customer_email ) ) {
										echo esc_html(
											$ticket->customer_email
										);
									} elseif ( $customer && ! empty( $customer->email ) ) {
										echo esc_html(
											$customer->email
										);
									} else {
										echo '—';
									}
									?>
								</td>

								<td>
									<a href="<?php echo esc_url( $ticket_url ); ?>">
										<?php echo esc_html( $ticket->subject ); ?>
									</a>
								</td>

								<td>
									<?php
									echo esc_html(
										self::format_status(
											$ticket->status
										)
									);
									?>
								</td>

								<td>
									<?php
									echo esc_html(
										self::format_priority(
											$ticket->priority
										)
									);
									?>
								</td>

								<td>
									<?php echo esc_html( $ticket->created_at ); ?>
								</td>

								<td>
									<?php echo esc_html( $ticket->updated_at ); ?>
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
	 * Render ticket detail.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return void
	 */
	private static function render_detail(
		$ticket_id
	) {

		$ticket =
			WP_RapidRescue_Chat_Ticket::get_by_id(
				$ticket_id
			);

		if ( ! $ticket ) {

			?>
			<div class="wrap">

				<h1>Ticket Not Found</h1>

				<p>
					The requested ticket could not be found.
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
						Back to Tickets
					</a>
				</p>

			</div>
			<?php

			return;
		}

		$conversation = null;

		if ( ! empty( $ticket->conversation_id ) ) {

			$conversation =
				WP_RapidRescue_Chat_Conversation::get_by_id(
					$ticket->conversation_id
				);
		}

		$customer =
			self::get_customer(
				$ticket
			);

		?>
		<div class="wrap">

			<?php self::render_request_notice(); ?>

			<h1>
				Ticket
				<?php echo esc_html( $ticket->ticket_key ); ?>
			</h1>

			<p>
				<a
					href="<?php echo esc_url(
						admin_url(
							'admin.php?page=wp-rapidrescue-tickets'
						)
					); ?>"
				>
					&larr; Back to Tickets
				</a>
			</p>

			<div
				style="
					display:grid;
					grid-template-columns:minmax(0,2fr) minmax(280px,1fr);
					gap:20px;
					max-width:1200px;
					align-items:start;
				"
			>

				<div>

					<div
						style="
							background:#fff;
							border:1px solid #dcdcde;
							border-radius:8px;
							padding:24px;
							margin-bottom:20px;
						"
					>

						<h2
							style="
								margin:0 0 20px 0;
								text-align:left;
								line-height:1.4;
							"
						>
							<?php echo esc_html( $ticket->subject ); ?>
						</h2>

						<h3
							style="
								margin:0 0 10px 0;
								text-align:left;
							"
						>
							Issue Summary
						</h3>

						<div
							style="
								display:block;
								width:100%;
								box-sizing:border-box;
								margin:0;
								padding:0;
								text-align:left;
								font-family:inherit;
								font-size:14px;
								font-weight:400;
								line-height:1.6;
								color:#1d2327;
								overflow-wrap:anywhere;
								word-break:normal;
							"
						>
							<?php
							echo esc_html(
								trim(
									$ticket->summary
								)
							);
							?>
						</div>

					</div>

					<?php if ( $conversation ) : ?>

						<div
							style="
								background:#fff;
								border:1px solid #dcdcde;
								border-radius:8px;
								padding:24px;
							"
						>

							<h2 style="margin-top:0;">
								Related Conversation
							</h2>

							<p>
								This ticket was created from
								Conversation #<?php
								echo esc_html(
									$conversation->id
								);
								?>.
							</p>

							<p>
								<a
									class="button button-secondary"
									href="<?php echo esc_url(
										add_query_arg(
											array(
												'page' =>
													'wp-rapidrescue-conversations',
												'conversation_id' =>
													$conversation->id,
											),
											admin_url(
												'admin.php'
											)
										)
									); ?>"
								>
									View Full Conversation
								</a>
							</p>

						</div>

					<?php endif; ?>

				</div>

				<div>

					<div
						style="
							background:#fff;
							border:1px solid #dcdcde;
							border-radius:8px;
							padding:24px;
							margin-bottom:20px;
						"
					>

						<h2 style="margin-top:0;">
							Ticket Details
						</h2>

						<table class="widefat striped">

							<tbody>

								<tr>
									<th>Ticket</th>
									<td>
										<strong>
											<?php echo esc_html(
												$ticket->ticket_key
											); ?>
										</strong>
									</td>
								</tr>

								<tr>
									<th>Customer</th>
									<td>
										<?php
										if ( $customer ) {
											echo esc_html(
												$customer->name
											);
										} elseif ( ! empty( $ticket->customer_id ) ) {
											echo 'Customer #' .
												esc_html(
													$ticket->customer_id
												);
										} else {
											echo 'Anonymous';
										}
										?>
									</td>
								</tr>

								<tr>
									<th>Email</th>
									<td>
										<?php
										if ( ! empty( $ticket->customer_email ) ) {
											echo esc_html(
												$ticket->customer_email
											);
										} elseif ( $customer && ! empty( $customer->email ) ) {
											echo esc_html(
												$customer->email
											);
										} else {
											echo '—';
										}
										?>
									</td>
								</tr>

								<tr>
									<th>Created</th>
									<td>
										<?php echo esc_html(
											$ticket->created_at
										); ?>
									</td>
								</tr>

								<tr>
									<th>Updated</th>
									<td>
										<?php echo esc_html(
											$ticket->updated_at
										); ?>
									</td>
								</tr>

							</tbody>

						</table>

					</div>

					<div
						style="
							background:#fff;
							border:1px solid #dcdcde;
							border-radius:8px;
							padding:24px;
						"
					>

						<h2 style="margin-top:0;">
							Update Ticket
						</h2>

						<form method="post">

							<input
								type="hidden"
								name="wp_rapidrescue_ticket_action"
								value="update_ticket"
							>

							<input
								type="hidden"
								name="ticket_id"
								value="<?php echo esc_attr(
									$ticket->id
								); ?>"
							>

							<?php
							wp_nonce_field(
								'wp_rapidrescue_update_ticket',
								'wp_rapidrescue_ticket_nonce'
							);
							?>

							<p>
								<label
									for="wp-rapidrescue-ticket-status"
								>
									<strong>Status</strong>
								</label>
							</p>

							<p>
								<select
									id="wp-rapidrescue-ticket-status"
									name="status"
									style="width:100%;"
								>

									<?php
									foreach (
										self::get_statuses()
										as $status_key => $status_label
									) :
										?>

										<option
											value="<?php echo esc_attr(
												$status_key
											); ?>"
											<?php selected(
												$ticket->status,
												$status_key
											); ?>
										>
											<?php echo esc_html(
												$status_label
											); ?>
										</option>

									<?php endforeach; ?>

								</select>
							</p>

							<p>
								<label
									for="wp-rapidrescue-ticket-priority"
								>
									<strong>Priority</strong>
								</label>
							</p>

							<p>
								<select
									id="wp-rapidrescue-ticket-priority"
									name="priority"
									style="width:100%;"
								>

									<?php
									foreach (
										self::get_priorities()
										as $priority_key => $priority_label
									) :
										?>

										<option
											value="<?php echo esc_attr(
												$priority_key
											); ?>"
											<?php selected(
												$ticket->priority,
												$priority_key
											); ?>
										>
											<?php echo esc_html(
												$priority_label
											); ?>
										</option>

									<?php endforeach; ?>

								</select>
							</p>

							<p>
								<button
									type="submit"
									class="button button-primary"
								>
									Save Ticket
								</button>
							</p>

						</form>

					</div>

				</div>

			</div>

		</div>

		<?php
	}

	/**
	 * Get ticket counts.
	 *
	 * @return array
	 */
	private static function get_counts() {

		global $wpdb;

		$table =
			$wpdb->prefix . 'rr_tickets';

		$rows = $wpdb->get_results(
			"SELECT status, COUNT(*) AS total
			FROM {$table}
			GROUP BY status"
		);

		$counts = array(
			'open'             => 0,
			'in_progress'      => 0,
			'waiting_customer' => 0,
			'resolved'         => 0,
			'closed'           => 0,
		);

		foreach ( $rows as $row ) {

			$status =
				sanitize_key(
					$row->status
				);

			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ] =
					absint(
						$row->total
					);
			}
		}

		return $counts;
	}

	/**
	 * Render a count card.
	 *
	 * @param string $label Card label.
	 * @param int    $count Card count.
	 * @return void
	 */
	private static function render_count_card(
		$label,
		$count
	) {

		?>
		<div
			style="
				background:#fff;
				border:1px solid #dcdcde;
				border-radius:8px;
				padding:16px;
			"
		>

			<div
				style="
					color:#646970;
					font-size:13px;
					margin-bottom:6px;
				"
			>
				<?php echo esc_html( $label ); ?>
			</div>

			<div
				style="
					font-size:24px;
					font-weight:600;
				"
			>
				<?php echo esc_html( $count ); ?>
			</div>

		</div>
		<?php
	}

	/**
	 * Get customer associated with a ticket.
	 *
	 * @param object $ticket Ticket object.
	 * @return object|null
	 */
	private static function get_customer(
		$ticket
	) {

		if (
			! $ticket ||
			! is_object( $ticket ) ||
			empty( $ticket->customer_id )
		) {
			return null;
		}

		return WP_RapidRescue_Chat_Customer::get_by_id(
			absint(
				$ticket->customer_id
			)
		);
	}

	/**
	 * Get supported statuses.
	 *
	 * @return array
	 */
	private static function get_statuses() {

		return array(
			'open'             => 'Open',
			'in_progress'      => 'In Progress',
			'waiting_customer' => 'Waiting for Customer',
			'resolved'         => 'Resolved',
			'closed'           => 'Closed',
		);
	}

	/**
	 * Get supported priorities.
	 *
	 * @return array
	 */
	private static function get_priorities() {

		return array(
			'low'    => 'Low',
			'normal' => 'Normal',
			'high'   => 'High',
			'urgent' => 'Urgent',
		);
	}

	/**
	 * Format ticket status.
	 *
	 * @param string $status Ticket status.
	 * @return string
	 */
	private static function format_status(
		$status
	) {

		$statuses =
			self::get_statuses();

		return isset( $statuses[ $status ] )
			? $statuses[ $status ]
			: 'Open';
	}

	/**
	 * Format ticket priority.
	 *
	 * @param string $priority Ticket priority.
	 * @return string
	 */
	private static function format_priority(
		$priority
	) {

		$priorities =
			self::get_priorities();

		return isset( $priorities[ $priority ] )
			? $priorities[ $priority ]
			: 'Normal';
	}

	/**
	 * Render request/update notices.
	 *
	 * @return void
	 */
	private static function render_request_notice() {

		global $wp_rapidrescue_ticket_admin_notice;

		if (
			is_array(
				$wp_rapidrescue_ticket_admin_notice
			)
		) {

			$type =
				isset(
					$wp_rapidrescue_ticket_admin_notice['type']
				)
					? $wp_rapidrescue_ticket_admin_notice['type']
					: 'error';

			$message =
				isset(
					$wp_rapidrescue_ticket_admin_notice['message']
				)
					? $wp_rapidrescue_ticket_admin_notice['message']
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

			$wp_rapidrescue_ticket_admin_notice = null;
		}

		if (
			isset( $_GET['updated'] ) &&
			'1' ===
				sanitize_text_field(
					wp_unslash(
						$_GET['updated']
					)
				)
		) {
			?>
			<div class="notice notice-success is-dismissible">
				<p>
					Ticket updated successfully.
				</p>
			</div>
			<?php
		}
	}
}

