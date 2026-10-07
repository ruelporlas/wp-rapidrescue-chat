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
	 * Render ticket list.
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

		?>
		<div class="wrap">

			<h1>Tickets</h1>

			<p>
				View and monitor support tickets created from customer
				conversations.
			</p>

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
									'page'      => 'wp-rapidrescue-tickets',
									'ticket_id' => $ticket->id,
								),
								admin_url( 'admin.php' )
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
									if ( ! empty( $ticket->customer_id ) ) {
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
									} else {
										echo '—';
									}
									?>
								</td>

								<td>
									<?php echo esc_html( $ticket->subject ); ?>
								</td>

								<td>
									<?php echo esc_html(
										self::format_status(
											$ticket->status
										)
									); ?>
								</td>

								<td>
									<?php echo esc_html(
										ucfirst(
											$ticket->priority
										)
									); ?>
								</td>

								<td>
									<?php echo esc_html(
										$ticket->created_at
									); ?>
								</td>

								<td>
									<?php echo esc_html(
										$ticket->updated_at
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
	 * Render ticket detail.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return void
	 */
	private static function render_detail( $ticket_id ) {

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

		?>
		<div class="wrap">

			<h1>
				Ticket <?php echo esc_html( $ticket->ticket_key ); ?>
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

			<table class="widefat striped">

				<tbody>

					<tr>
						<th style="width: 180px;">
							Ticket Number
						</th>

						<td>
							<strong>
								<?php echo esc_html(
									$ticket->ticket_key
								); ?>
							</strong>
						</td>
					</tr>

					<tr>
						<th>
							Subject
						</th>

						<td>
							<?php echo esc_html(
								$ticket->subject
							); ?>
						</td>
					</tr>

					<tr>
						<th>
							Status
						</th>

						<td>
							<?php echo esc_html(
								self::format_status(
									$ticket->status
								)
							); ?>
						</td>
					</tr>

					<tr>
						<th>
							Priority
						</th>

						<td>
							<?php echo esc_html(
								ucfirst(
									$ticket->priority
								)
							); ?>
						</td>
					</tr>

					<tr>
						<th>
							Customer ID
						</th>

						<td>
							<?php
							if ( ! empty( $ticket->customer_id ) ) {
								echo esc_html(
									$ticket->customer_id
								);
							} else {
								echo 'Anonymous';
							}
							?>
						</td>
					</tr>

					<tr>
						<th>
							Customer Email
						</th>

						<td>
							<?php
							if ( ! empty( $ticket->customer_email ) ) {
								echo esc_html(
									$ticket->customer_email
								);
							} elseif ( ! empty( $ticket->customer_id ) ) {

								$customer =
									WP_RapidRescue_Chat_Customer::get_by_id(
										$ticket->customer_id
									);

								if (
									$customer &&
									! empty( $customer->email )
								) {
									echo esc_html(
										$customer->email
									);
								} else {
									echo '—';
								}

							} else {
								echo '—';
							}
							?>
						</td>
					</tr>

					<tr>
						<th>
							Conversation
						</th>

						<td>

							<?php if ( $conversation ) : ?>

								<a
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
									View Conversation #<?php echo esc_html(
										$conversation->id
									); ?>
								</a>

							<?php elseif ( ! empty( $ticket->conversation_id ) ) : ?>

								Conversation #<?php echo esc_html(
									$ticket->conversation_id
								); ?>

								<em>
									(not found)
								</em>

							<?php else : ?>

								None

							<?php endif; ?>

						</td>
					</tr>

					<tr>
						<th>
							Created
						</th>

						<td>
							<?php echo esc_html(
								$ticket->created_at
							); ?>
						</td>
					</tr>

					<tr>
						<th>
							Last Updated
						</th>

						<td>
							<?php echo esc_html(
								$ticket->updated_at
							); ?>
						</td>
					</tr>

				</tbody>

			</table>

			<h2 style="margin-top: 30px;">
				Issue Summary
			</h2>

			<div
				style="
					max-width: 900px;
					background: #fff;
					border: 1px solid #dcdcde;
					border-radius: 8px;
					padding: 20px;
				"
			>
				<?php
				echo nl2br(
					esc_html(
						$ticket->summary
					)
				);
				?>
			</div>

			<?php if ( $conversation ) : ?>

				<h2 style="margin-top: 30px;">
					Related Conversation
				</h2>

				<p>
					This ticket was created from
					Conversation #<?php echo esc_html(
						$conversation->id
					); ?>.
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
								admin_url( 'admin.php' )
							)
						); ?>"
					>
						View Full Conversation
					</a>
				</p>

			<?php endif; ?>

		</div>
		<?php
	}

	/**
	 * Format ticket status for display.
	 *
	 * @param string $status Ticket status.
	 * @return string
	 */
	private static function format_status( $status ) {

		switch ( $status ) {

			case 'in_progress':
				return 'In Progress';

			case 'waiting_customer':
				return 'Waiting for Customer';

			case 'resolved':
				return 'Resolved';

			case 'closed':
				return 'Closed';

			case 'open':
			default:
				return 'Open';
		}
	}
}