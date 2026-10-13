<?php
/**
 * Email log screen.
 *
 * @package SendFleet
 */

use SendFleet\Admin\Admin;
use SendFleet\Admin\Log_Table;
use SendFleet\Log;
use SendFleet\Settings;
use SendFleet\Suppressions;

defined( 'ABSPATH' ) || exit;

// Read-only navigation parameters.
$sendfleet_tab  = isset( $_GET['tab'] ) && 'suppressions' === $_GET['tab'] ? 'suppressions' : 'log'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$sendfleet_view = isset( $_GET['view'] ) ? absint( $_GET['view'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$sendfleet_base = admin_url( 'admin.php?page=' . Admin::SLUG_LOG );
?>
<div class="wrap sf-wrap">
	<?php Admin::header( Admin::SLUG_LOG ); ?>

	<?php if ( $sendfleet_view ) : ?>
		<?php $sendfleet_row = Log::find( $sendfleet_view ); ?>
		<p><a href="<?php echo esc_url( $sendfleet_base ); ?>">&larr; <?php esc_html_e( 'Back to the email log', 'sendfleet' ); ?></a></p>
		<?php if ( ! $sendfleet_row ) : ?>
			<div class="sf-callout sf-callout--warning"><p><?php esc_html_e( 'This log entry no longer exists.', 'sendfleet' ); ?></p></div>
		<?php else : ?>
			<section class="sf-card">
				<div class="sf-card__head">
					<h2 class="sf-card__title"><?php echo esc_html( '' !== $sendfleet_row['subject'] ? $sendfleet_row['subject'] : __( '(no subject)', 'sendfleet' ) ); ?></h2>
					<?php Admin::badge( $sendfleet_row['status'] ); ?>
				</div>
				<dl class="sf-dl sf-dl--wide">
					<dt><?php esc_html_e( 'Recipient', 'sendfleet' ); ?></dt>
					<dd><?php echo esc_html( $sendfleet_row['recipient'] . ( 'to' !== $sendfleet_row['recipient_kind'] ? ' (' . strtoupper( $sendfleet_row['recipient_kind'] ) . ')' : '' ) ); ?></dd>
					<dt><?php esc_html_e( 'Sender', 'sendfleet' ); ?></dt>
					<dd><?php echo esc_html( $sendfleet_row['from_email'] ); ?></dd>
					<dt><?php esc_html_e( 'Created', 'sendfleet' ); ?></dt>
					<dd><?php echo esc_html( Admin::datetime( $sendfleet_row['created_at'] ) ); ?></dd>
					<dt><?php esc_html_e( 'Updated', 'sendfleet' ); ?></dt>
					<dd><?php echo esc_html( Admin::datetime( $sendfleet_row['updated_at'] ) ); ?></dd>
					<?php if ( '' !== $sendfleet_row['mode'] || '' !== $sendfleet_row['route'] ) : ?>
						<dt><?php esc_html_e( 'Route', 'sendfleet' ); ?></dt>
						<dd><?php echo esc_html( '' === $sendfleet_row['mode'] ? $sendfleet_row['route'] : $sendfleet_row['mode'] . ( '' !== $sendfleet_row['route'] && $sendfleet_row['route'] !== $sendfleet_row['mode'] ? ' (' . $sendfleet_row['route'] . ')' : '' ) ); ?></dd>
					<?php endif; ?>
					<?php if ( '' !== $sendfleet_row['message_id'] ) : ?>
						<dt><?php esc_html_e( 'Message ID', 'sendfleet' ); ?></dt>
						<dd><code class="sf-mono"><?php echo esc_html( $sendfleet_row['message_id'] ); ?></code></dd>
					<?php endif; ?>
					<?php if ( '' !== $sendfleet_row['idempotency_key'] ) : ?>
						<dt><?php esc_html_e( 'Idempotency key', 'sendfleet' ); ?></dt>
						<dd><code class="sf-mono"><?php echo esc_html( $sendfleet_row['idempotency_key'] ); ?></code></dd>
					<?php endif; ?>
					<?php if ( '' !== $sendfleet_row['error_code'] ) : ?>
						<dt><?php esc_html_e( 'Code', 'sendfleet' ); ?></dt>
						<dd><code><?php echo esc_html( $sendfleet_row['error_code'] ); ?></code><?php echo (int) $sendfleet_row['http_status'] > 0 ? ' <span class="sf-muted">HTTP ' . (int) $sendfleet_row['http_status'] . '</span>' : ''; ?></dd>
					<?php endif; ?>
					<?php if ( '' !== $sendfleet_row['error_message'] ) : ?>
						<dt><?php echo in_array( $sendfleet_row['status'], array( Log::STATUS_ACCEPTED, Log::STATUS_SENT, Log::STATUS_DELIVERED ), true ) ? esc_html__( 'Warnings', 'sendfleet' ) : esc_html__( 'Details', 'sendfleet' ); ?></dt>
						<dd class="sf-pre-line"><?php echo esc_html( $sendfleet_row['error_message'] ); ?></dd>
					<?php endif; ?>
					<?php if ( '' !== $sendfleet_row['notes'] ) : ?>
						<dt><?php esc_html_e( 'Notes', 'sendfleet' ); ?></dt>
						<dd class="sf-pre-line"><?php echo esc_html( $sendfleet_row['notes'] ); ?></dd>
					<?php endif; ?>
					<dt><?php esc_html_e( 'Attachments', 'sendfleet' ); ?></dt>
					<dd><?php echo esc_html( (string) (int) $sendfleet_row['attachments'] ); ?></dd>
					<dt><?php esc_html_e( 'API attempts', 'sendfleet' ); ?></dt>
					<dd><?php echo esc_html( (string) (int) $sendfleet_row['attempts'] ); ?></dd>
					<?php if ( Log::STATUS_QUEUED === $sendfleet_row['status'] && ! empty( $sendfleet_row['next_attempt_at'] ) ) : ?>
						<dt><?php esc_html_e( 'Next attempt', 'sendfleet' ); ?></dt>
						<dd><?php echo esc_html( Admin::datetime( $sendfleet_row['next_attempt_at'] ) ); ?></dd>
					<?php endif; ?>
					<?php if ( '' !== $sendfleet_row['source'] ) : ?>
						<dt><?php esc_html_e( 'Sent by', 'sendfleet' ); ?></dt>
						<dd><code><?php echo esc_html( $sendfleet_row['source'] ); ?></code></dd>
					<?php endif; ?>
				</dl>
				<?php if ( '' !== $sendfleet_row['mail_id'] ) : ?>
					<p><a href="<?php echo esc_url( add_query_arg( 'mail_id', $sendfleet_row['mail_id'], $sendfleet_base ) ); ?>"><?php esc_html_e( 'Other recipients of this email', 'sendfleet' ); ?></a></p>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( Admin::post_url() ); ?>">
					<?php Admin::form_fields( 'log_bulk' ); ?>
					<input type="hidden" name="id" value="<?php echo esc_attr( (string) (int) $sendfleet_row['id'] ); ?>" />
					<button type="submit" class="button-link sf-link-danger" data-sf-confirm="<?php esc_attr_e( 'Delete this log entry?', 'sendfleet' ); ?>"><?php echo Log::STATUS_QUEUED === $sendfleet_row['status'] ? esc_html__( 'Cancel and delete this queued email', 'sendfleet' ) : esc_html__( 'Delete entry', 'sendfleet' ); ?></button>
				</form>
			</section>
		<?php endif; ?>

	<?php else : ?>

		<ul class="subsubsub sf-subtabs">
			<li><a href="<?php echo esc_url( $sendfleet_base ); ?>"<?php echo 'log' === $sendfleet_tab ? ' class="current"' : ''; ?>><?php esc_html_e( 'Emails', 'sendfleet' ); ?></a> |</li>
			<li><a href="<?php echo esc_url( add_query_arg( 'tab', 'suppressions', $sendfleet_base ) ); ?>"<?php echo 'suppressions' === $sendfleet_tab ? ' class="current"' : ''; ?>><?php esc_html_e( 'Suppressions', 'sendfleet' ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( Suppressions::count() ) ); ?>)</span></a></li>
		</ul>
		<div class="clear"></div>

		<?php if ( 'log' === $sendfleet_tab ) : ?>
			<?php if ( ! Settings::get( 'log_enabled' ) ) : ?>
				<div class="sf-callout sf-callout--info"><p><?php esc_html_e( 'The email log is off. Only queued emails are listed until they are sent.', 'sendfleet' ); ?></p></div>
			<?php endif; ?>

			<?php
			$sendfleet_table = new Log_Table();
			$sendfleet_table->prepare_items();
			$sendfleet_queued = Log::queued_count();
			?>

			<form id="sf-log-form" method="post" action="<?php echo esc_url( Admin::post_url() ); ?>">
				<?php Admin::form_fields( 'log_bulk' ); ?>
			</form>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( Admin::SLUG_LOG ); ?>" />
				<?php $sendfleet_table->views(); ?>
				<?php $sendfleet_table->search_box( __( 'Search emails', 'sendfleet' ), 'sf-log' ); ?>
				<?php $sendfleet_table->display(); ?>
			</form>

			<div class="sf-log-footer">
				<?php if ( $sendfleet_queued > 0 ) : ?>
					<form method="post" action="<?php echo esc_url( Admin::post_url() ); ?>" class="sf-inline-form">
						<?php Admin::form_fields( 'queue_run' ); ?>
						<button type="submit" class="button">
							<?php
							/* translators: %s: number of queued emails. */
							echo esc_html( sprintf( _n( 'Send %s queued email now', 'Send %s queued emails now', $sendfleet_queued, 'sendfleet' ), number_format_i18n( $sendfleet_queued ) ) );
							?>
						</button>
					</form>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( Admin::post_url() ); ?>" class="sf-inline-form">
					<?php Admin::form_fields( 'purge_log' ); ?>
					<button type="submit" class="button-link sf-link-danger" data-sf-confirm="<?php esc_attr_e( 'Delete every log entry? Queued emails are kept.', 'sendfleet' ); ?>"><?php esc_html_e( 'Delete all log entries', 'sendfleet' ); ?></button>
				</form>
				<p class="sf-muted sf-small">
					<?php
					/* translators: %d: days. */
					echo esc_html( sprintf( __( 'Entries are deleted automatically after %d days. Message bodies are never stored in the log.', 'sendfleet' ), Settings::log_retention() ) );
					?>
				</p>
			</div>

		<?php else : ?>
			<?php
			// Read-only list parameters.
			$sendfleet_search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$sendfleet_page    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$sendfleet_entries = Suppressions::query( $sendfleet_search, 50, $sendfleet_page );
			$sendfleet_total   = Suppressions::count( $sendfleet_search );
			$sendfleet_reasons = array(
				'bounced'    => __( 'Hard bounce', 'sendfleet' ),
				'complained' => __( 'Spam complaint', 'sendfleet' ),
				'suppressed' => __( 'Suppressed by SendFleet', 'sendfleet' ),
				'manual'     => __( 'Added by hand', 'sendfleet' ),
			);
			?>
			<p class="sf-help"><?php esc_html_e( 'This site does not send to these addresses. Webhooks add hard bounces, spam complaints and SendFleet suppressions automatically.', 'sendfleet' ); ?></p>

			<div class="sf-grid sf-grid--2 sf-grid--tight">
				<form method="post" action="<?php echo esc_url( Admin::post_url() ); ?>" class="sf-inline-form">
					<?php Admin::form_fields( 'suppression' ); ?>
					<input type="hidden" name="operation" value="add" />
					<label class="screen-reader-text" for="sf-suppress-email"><?php esc_html_e( 'Email address', 'sendfleet' ); ?></label>
					<input id="sf-suppress-email" class="sf-input" type="email" name="email" placeholder="name@example.com" required />
					<button type="submit" class="button"><?php esc_html_e( 'Add address', 'sendfleet' ); ?></button>
				</form>
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="sf-inline-form sf-align-right">
					<input type="hidden" name="page" value="<?php echo esc_attr( Admin::SLUG_LOG ); ?>" />
					<input type="hidden" name="tab" value="suppressions" />
					<input class="sf-input" type="search" name="s" value="<?php echo esc_attr( $sendfleet_search ); ?>" placeholder="<?php esc_attr_e( 'Search addresses', 'sendfleet' ); ?>" />
					<button type="submit" class="button"><?php esc_html_e( 'Search', 'sendfleet' ); ?></button>
				</form>
			</div>

			<form method="post" action="<?php echo esc_url( Admin::post_url() ); ?>">
				<?php Admin::form_fields( 'suppression' ); ?>
				<input type="hidden" name="operation" value="remove" />
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<td class="manage-column column-cb check-column"><input type="checkbox" class="sf-check-all" /></td>
							<th><?php esc_html_e( 'Address', 'sendfleet' ); ?></th>
							<th><?php esc_html_e( 'Reason', 'sendfleet' ); ?></th>
							<th><?php esc_html_e( 'Detail', 'sendfleet' ); ?></th>
							<th><?php esc_html_e( 'Added', 'sendfleet' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $sendfleet_entries ) ) : ?>
							<tr><td colspan="5"><?php esc_html_e( 'No suppressed addresses.', 'sendfleet' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $sendfleet_entries as $sendfleet_entry ) : ?>
							<tr>
								<th scope="row" class="check-column"><input type="checkbox" name="emails[]" value="<?php echo esc_attr( $sendfleet_entry['email'] ); ?>" /></th>
								<td><?php echo esc_html( $sendfleet_entry['email'] ); ?></td>
								<td><?php echo esc_html( isset( $sendfleet_reasons[ $sendfleet_entry['reason'] ] ) ? $sendfleet_reasons[ $sendfleet_entry['reason'] ] : $sendfleet_entry['reason'] ); ?></td>
								<td class="sf-muted"><?php echo esc_html( $sendfleet_entry['detail'] ); ?></td>
								<td><?php echo esc_html( Admin::datetime( $sendfleet_entry['created_at'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( ! empty( $sendfleet_entries ) ) : ?>
					<p><button type="submit" class="button" data-sf-confirm="<?php esc_attr_e( 'Allow email to the selected addresses again?', 'sendfleet' ); ?>"><?php esc_html_e( 'Remove selected', 'sendfleet' ); ?></button></p>
				<?php endif; ?>
			</form>

			<?php
			$sendfleet_pages = (int) ceil( $sendfleet_total / 50 );
			if ( $sendfleet_pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post(
					paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $sendfleet_page,
							'total'   => $sendfleet_pages,
						)
					)
				);
				echo '</div></div>';
			}
			?>
		<?php endif; ?>
	<?php endif; ?>
</div>
