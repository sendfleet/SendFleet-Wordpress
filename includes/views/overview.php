<?php
/**
 * Overview screen.
 *
 * @package SendFleet
 */

use SendFleet\Account;
use SendFleet\Admin\Admin;
use SendFleet\Log;
use SendFleet\Settings;
use SendFleet\State;

defined( 'ABSPATH' ) || exit;

$sendfleet_has_key   = '' !== Settings::api_key();
$sendfleet_known     = Account::is_known();
$sendfleet_routes    = Account::usable_domains();
$sendfleet_user      = wp_get_current_user();
$sendfleet_test_to   = Account::is_smoke_only() ? Account::email() : ( $sendfleet_user && $sendfleet_user->user_email ? $sendfleet_user->user_email : get_option( 'admin_email' ) );
$sendfleet_mode_name = array(
	Account::ROUTE_BYOC   => __( 'BYOC — your AWS SES', 'sendfleet' ),
	Account::ROUTE_SHARED => __( 'Shared SES', 'sendfleet' ),
	Account::ROUTE_SMOKE  => __( 'Smoke test — your address only', 'sendfleet' ),
);
?>
<div class="wrap sf-wrap">
	<?php Admin::header( Admin::SLUG ); ?>

	<?php if ( ! $sendfleet_has_key ) : ?>
		<?php if ( Settings::api_key_unreadable() ) : ?>
			<?php Admin::issues(); ?>
		<?php endif; ?>

		<section class="sf-hero">
			<h2 class="sf-hero__title"><?php esc_html_e( 'Send every WordPress email through SendFleet.', 'sendfleet' ); ?></h2>
			<p class="sf-hero__lede"><?php esc_html_e( 'Paste an API key and wp_mail() is routed through the SendFleet API: password resets, WooCommerce orders, contact forms, notifications — all of it. Your SES or ours. No SMTP settings.', 'sendfleet' ); ?></p>

			<form class="sf-connect" method="post" action="<?php echo esc_url( Admin::post_url() ); ?>">
				<?php Admin::form_fields( 'connect' ); ?>
				<label class="sf-label" for="sf-api-key"><?php esc_html_e( 'API key', 'sendfleet' ); ?></label>
				<div class="sf-connect__row">
					<input id="sf-api-key" class="sf-input sf-input--mono" type="password" name="api_key" autocomplete="off" spellcheck="false" placeholder="abcd1234.••••••••••••••••••••••••••••••••" required />
					<button type="button" class="button sf-reveal" data-target="sf-api-key"><?php esc_html_e( 'Show', 'sendfleet' ); ?></button>
					<button type="submit" class="button button-primary sf-button"><?php esc_html_e( 'Connect', 'sendfleet' ); ?></button>
				</div>
				<p class="sf-help"><?php esc_html_e( 'The key is checked against SendFleet before it is saved, and stored encrypted.', 'sendfleet' ); ?></p>
			</form>

			<ol class="sf-steps">
				<li>
					<?php
					printf(
						/* translators: %s: sign-up URL. */
						wp_kses_post( __( '<a href="%s" target="_blank" rel="noopener noreferrer">Create a free SendFleet account</a> — 50 emails a month, no card.', 'sendfleet' ) ),
						esc_url( 'https://sendfleet.net/register' )
					);
					?>
				</li>
				<li><?php esc_html_e( 'Add your domain in the SendFleet dashboard (Domains), publish the DNS records and press Verify all records.', 'sendfleet' ); ?></li>
				<li><?php esc_html_e( 'Create a key under API Keys, copy it (it is shown once) and paste it above.', 'sendfleet' ); ?></li>
			</ol>
		</section>

		<div class="sf-grid sf-grid--3">
			<div class="sf-card">
				<h3 class="sf-card__title"><?php esc_html_e( 'Two modes, one plugin', 'sendfleet' ); ?></h3>
				<p><?php esc_html_e( 'BYOC sends through your own AWS SES account — SendFleet never stores recipients, subjects or bodies. Managed plans send through SendFleet\'s SES with logs, webhooks and bounce handling.', 'sendfleet' ); ?></p>
			</div>
			<div class="sf-card">
				<h3 class="sf-card__title"><?php esc_html_e( 'Free plan', 'sendfleet' ); ?></h3>
				<p><?php esc_html_e( '50 emails a month. With a BYOC domain they go to anyone. With a Shared SES domain the Free plan is a smoke test that only reaches your own account address — enough for contact forms and admin notifications to yourself.', 'sendfleet' ); ?></p>
			</div>
			<div class="sf-card">
				<h3 class="sf-card__title"><?php esc_html_e( 'Nothing else to configure', 'sendfleet' ); ?></h3>
				<p><?php esc_html_e( 'The sender is picked from your verified domains automatically. To, Cc and Bcc go out together as one message. Rate limits and transient errors are retried for you.', 'sendfleet' ); ?></p>
			</div>
		</div>

	<?php else : ?>

		<?php Admin::issues(); ?>

		<div class="sf-grid sf-grid--2">
			<section class="sf-card">
				<div class="sf-card__head">
					<h2 class="sf-card__title"><?php esc_html_e( 'Account', 'sendfleet' ); ?></h2>
					<form method="post" action="<?php echo esc_url( Admin::post_url() ); ?>">
						<?php Admin::form_fields( 'refresh' ); ?>
						<button type="submit" class="button button-small"><?php esc_html_e( 'Refresh', 'sendfleet' ); ?></button>
					</form>
				</div>
				<dl class="sf-dl">
					<dt><?php esc_html_e( 'API key', 'sendfleet' ); ?></dt>
					<dd>
						<code class="sf-mono"><?php echo esc_html( Settings::api_key_prefix() ); ?>.••••••••</code>
						<?php if ( 'constant' === Settings::api_key_source() ) : ?>
							<span class="sf-muted"><?php esc_html_e( '(from wp-config.php)', 'sendfleet' ); ?></span>
						<?php endif; ?>
					</dd>
					<?php if ( $sendfleet_known ) : ?>
						<dt><?php esc_html_e( 'Account', 'sendfleet' ); ?></dt>
						<dd>
							<?php echo esc_html( Account::email() ); ?>
							<?php if ( Account::is_verified() ) : ?>
								<span class="sf-badge sf-badge--ok"><?php esc_html_e( 'Verified', 'sendfleet' ); ?></span>
							<?php else : ?>
								<span class="sf-badge sf-badge--bad"><?php esc_html_e( 'Not verified', 'sendfleet' ); ?></span>
							<?php endif; ?>
						</dd>
						<dt><?php esc_html_e( 'BYOC role', 'sendfleet' ); ?></dt>
						<dd><?php echo Account::byoc_configured() ? esc_html__( 'Validated', 'sendfleet' ) : esc_html__( 'Not configured', 'sendfleet' ); ?></dd>
						<dt><?php esc_html_e( 'Updated', 'sendfleet' ); ?></dt>
						<dd class="sf-muted">
							<?php
							/* translators: %s: human time difference. */
							echo esc_html( sprintf( __( '%s ago', 'sendfleet' ), human_time_diff( time() - Account::age() ) ) );
							?>
						</dd>
					<?php endif; ?>
				</dl>
				<?php if ( 'option' === Settings::api_key_source() ) : ?>
					<form method="post" action="<?php echo esc_url( Admin::post_url() ); ?>" class="sf-inline-form">
						<?php Admin::form_fields( 'disconnect' ); ?>
						<button type="submit" class="button-link sf-link-danger" data-sf-confirm="<?php esc_attr_e( 'Remove the API key? WordPress will send email the default way again.', 'sendfleet' ); ?>"><?php esc_html_e( 'Disconnect', 'sendfleet' ); ?></button>
					</form>
				<?php endif; ?>
			</section>

			<section class="sf-card">
				<h2 class="sf-card__title"><?php esc_html_e( 'Plan and usage', 'sendfleet' ); ?></h2>
				<?php if ( $sendfleet_known ) : ?>
					<?php
					$sendfleet_limit = Account::limit();
					$sendfleet_used  = Account::used();
					$sendfleet_pct   = null === $sendfleet_limit || 0 === $sendfleet_limit ? 0 : min( 100, (int) round( 100 * $sendfleet_used / $sendfleet_limit ) );
					?>
					<p class="sf-plan"><span class="sf-plan__name"><?php echo esc_html( Account::plan_label() ); ?></span></p>
					<div class="sf-stat">
						<span class="sf-stat__value"><?php echo esc_html( number_format_i18n( $sendfleet_used ) ); ?></span>
						<span class="sf-stat__label">
							<?php
							if ( null === $sendfleet_limit ) {
								esc_html_e( 'sends this month · no SendFleet cap', 'sendfleet' );
							} else {
								/* translators: %s: monthly limit. */
								echo esc_html( sprintf( __( 'of %s sends this month', 'sendfleet' ), number_format_i18n( $sendfleet_limit ) ) );
							}
							?>
						</span>
					</div>
					<?php if ( null !== $sendfleet_limit ) : ?>
						<div class="sf-meter<?php echo $sendfleet_pct >= 90 ? ' is-high' : ''; ?>" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $sendfleet_pct ); ?>"><span style="width:<?php echo esc_attr( (string) $sendfleet_pct ); ?>%"></span></div>
						<p class="sf-help"><?php esc_html_e( 'Resets on the 1st of each month (UTC).', 'sendfleet' ); ?><?php echo Account::is_free() ? ' ' . esc_html__( 'BYOC sends and Shared smoke tests count together.', 'sendfleet' ) : ''; ?></p>
					<?php endif; ?>
					<?php
					$sendfleet_data = Account::data();
					if ( Account::is_managed() && $sendfleet_data['shared_usage_current_month'] > 0 ) :
						?>
						<ul class="sf-mini-stats">
							<li><strong><?php echo esc_html( number_format_i18n( $sendfleet_data['shared_delivered_current_month'] ) ); ?></strong> <?php esc_html_e( 'delivered', 'sendfleet' ); ?></li>
							<li><strong><?php echo esc_html( number_format_i18n( $sendfleet_data['shared_bounced_current_month'] ) ); ?></strong> <?php esc_html_e( 'bounced', 'sendfleet' ); ?></li>
							<li><strong><?php echo esc_html( number_format_i18n( $sendfleet_data['shared_complained_current_month'] ) ); ?></strong> <?php esc_html_e( 'complaints', 'sendfleet' ); ?></li>
							<li><strong><?php echo esc_html( number_format_i18n( $sendfleet_data['shared_rejected_current_month'] ) ); ?></strong> <?php esc_html_e( 'rejected', 'sendfleet' ); ?></li>
						</ul>
					<?php endif; ?>
					<p><a href="https://sendfleet.net/dashboard/billing/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Manage plan in the SendFleet dashboard', 'sendfleet' ); ?></a></p>
				<?php else : ?>
					<p class="sf-muted"><?php esc_html_e( 'Account details are not available yet. Press Refresh to fetch them.', 'sendfleet' ); ?></p>
				<?php endif; ?>
			</section>

			<section class="sf-card">
				<h2 class="sf-card__title"><?php esc_html_e( 'Sending domains', 'sendfleet' ); ?></h2>
				<?php if ( ! $sendfleet_known ) : ?>
					<p class="sf-muted"><?php esc_html_e( 'Unknown until the account details load.', 'sendfleet' ); ?></p>
				<?php elseif ( empty( $sendfleet_routes ) ) : ?>
					<p><?php esc_html_e( 'No verified domain can send on your current plan yet.', 'sendfleet' ); ?></p>
					<p><a class="button" href="https://sendfleet.net/dashboard/domains/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Add a domain in SendFleet', 'sendfleet' ); ?></a></p>
				<?php else : ?>
					<ul class="sf-domains">
						<?php foreach ( $sendfleet_routes as $sendfleet_domain => $sendfleet_route ) : ?>
							<li>
								<span class="sf-mono"><?php echo esc_html( $sendfleet_domain ); ?></span>
								<span class="sf-badge sf-badge--<?php echo Account::ROUTE_SMOKE === $sendfleet_route ? 'warn' : 'ok'; ?>"><?php echo esc_html( $sendfleet_mode_name[ $sendfleet_route ] ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
					<p class="sf-help">
						<?php
						/* translators: %s: email address. */
						echo esc_html( sprintf( __( 'Emails whose sender is not on one of these domains are sent from %s.', 'sendfleet' ), Account::default_sender() ) );
						?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin::SLUG_SET ) ); ?>"><?php esc_html_e( 'Change', 'sendfleet' ); ?></a>
					</p>
				<?php endif; ?>
			</section>

			<section class="sf-card">
				<h2 class="sf-card__title"><?php esc_html_e( 'Send a test email', 'sendfleet' ); ?></h2>
				<form method="post" action="<?php echo esc_url( Admin::post_url() ); ?>" class="sf-form-stack">
					<?php Admin::form_fields( 'test' ); ?>
					<label class="sf-label" for="sf-test-to"><?php esc_html_e( 'To', 'sendfleet' ); ?></label>
					<input id="sf-test-to" class="sf-input" type="email" name="to" value="<?php echo esc_attr( $sendfleet_test_to ); ?>" required />
					<?php if ( count( $sendfleet_routes ) > 1 ) : ?>
						<label class="sf-label" for="sf-test-from"><?php esc_html_e( 'From', 'sendfleet' ); ?></label>
						<select id="sf-test-from" class="sf-input" name="from">
							<option value=""><?php echo esc_html( Account::default_sender() ); ?></option>
							<?php foreach ( $sendfleet_routes as $sendfleet_domain => $sendfleet_route ) : ?>
								<?php
								$sendfleet_candidate = 'wordpress@' . $sendfleet_domain;
								if ( Account::default_sender() === $sendfleet_candidate ) {
									continue;
								}
								?>
								<option value="<?php echo esc_attr( $sendfleet_candidate ); ?>"><?php echo esc_html( $sendfleet_candidate . ' (' . $sendfleet_mode_name[ $sendfleet_route ] . ')' ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>
					<?php if ( Account::is_smoke_only() ) : ?>
						<p class="sf-help"><?php esc_html_e( 'Smoke-test mode delivers only to your SendFleet account address.', 'sendfleet' ); ?></p>
					<?php endif; ?>
					<div><button type="submit" class="button button-primary sf-button"<?php disabled( ! Settings::is_enabled() ); ?>><?php esc_html_e( 'Send test email', 'sendfleet' ); ?></button></div>
				</form>
			</section>
		</div>

		<section class="sf-card sf-card--flush">
			<div class="sf-card__head">
				<h2 class="sf-card__title"><?php esc_html_e( 'Recent email', 'sendfleet' ); ?></h2>
				<?php
				$sendfleet_day    = Log::count_since( time() - DAY_IN_SECONDS );
				$sendfleet_ok     = 0;
				$sendfleet_failed = isset( $sendfleet_day[ Log::STATUS_FAILED ] ) ? $sendfleet_day[ Log::STATUS_FAILED ] : 0;
				$sendfleet_queued = Log::queued_count();
				foreach ( array( Log::STATUS_ACCEPTED, Log::STATUS_SENT, Log::STATUS_DELIVERED, Log::STATUS_FALLBACK ) as $sendfleet_status ) {
					$sendfleet_ok += isset( $sendfleet_day[ $sendfleet_status ] ) ? $sendfleet_day[ $sendfleet_status ] : 0;
				}
				?>
				<span class="sf-muted">
					<?php
					/* translators: 1: sent count, 2: failed count, 3: queued count. */
					echo esc_html( sprintf( __( 'Last 24 hours: %1$s sent · %2$s failed · %3$s queued', 'sendfleet' ), number_format_i18n( $sendfleet_ok ), number_format_i18n( $sendfleet_failed ), number_format_i18n( $sendfleet_queued ) ) );
					?>
				</span>
			</div>
			<?php $sendfleet_recent = Settings::get( 'log_enabled' ) ? Log::recent( 8 ) : array(); ?>
			<?php if ( ! Settings::get( 'log_enabled' ) ) : ?>
				<p class="sf-pad sf-muted"><?php esc_html_e( 'The email log is switched off in the settings.', 'sendfleet' ); ?></p>
			<?php elseif ( empty( $sendfleet_recent ) ) : ?>
				<p class="sf-pad sf-muted"><?php esc_html_e( 'Nothing sent yet. Send a test email to see it here.', 'sendfleet' ); ?></p>
			<?php else : ?>
				<table class="sf-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'sendfleet' ); ?></th>
							<th><?php esc_html_e( 'Recipient', 'sendfleet' ); ?></th>
							<th><?php esc_html_e( 'Subject', 'sendfleet' ); ?></th>
							<th><?php esc_html_e( 'Status', 'sendfleet' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $sendfleet_recent as $sendfleet_row ) : ?>
							<tr>
								<td class="sf-nowrap"><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin::SLUG_LOG . '&view=' . (int) $sendfleet_row['id'] ) ); ?>"><?php echo esc_html( Admin::datetime( $sendfleet_row['created_at'] ) ); ?></a></td>
								<td><?php echo esc_html( $sendfleet_row['recipient'] ); ?></td>
								<td><?php echo esc_html( '' !== $sendfleet_row['subject'] ? $sendfleet_row['subject'] : __( '(no subject)', 'sendfleet' ) ); ?></td>
								<td><?php Admin::badge( $sendfleet_row['status'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="sf-pad"><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin::SLUG_LOG ) ); ?>"><?php esc_html_e( 'View the full email log', 'sendfleet' ); ?> &rarr;</a></p>
			<?php endif; ?>
		</section>

		<?php $sendfleet_last_error = State::get( 'last_error' ); ?>
		<?php if ( is_array( $sendfleet_last_error ) && $sendfleet_last_error['time'] > time() - DAY_IN_SECONDS ) : ?>
			<p class="sf-muted sf-small">
				<?php
				printf(
					/* translators: 1: time ago, 2: error code, 3: detail. */
					esc_html__( 'Last API error %1$s ago: %2$s — %3$s', 'sendfleet' ),
					esc_html( human_time_diff( (int) $sendfleet_last_error['time'] ) ),
					'<code>' . esc_html( $sendfleet_last_error['code'] ) . '</code>',
					esc_html( $sendfleet_last_error['detail'] )
				);
				?>
			</p>
		<?php endif; ?>
	<?php endif; ?>
</div>
