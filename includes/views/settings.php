<?php
/**
 * Settings screen.
 *
 * @package SendFleet
 */

use SendFleet\Account;
use SendFleet\Admin\Admin;
use SendFleet\Settings;
use SendFleet\State;
use SendFleet\Webhooks;

defined( 'ABSPATH' ) || exit;

$sendfleet_settings     = Settings::all();
$sendfleet_routes       = Account::usable_domains();
$sendfleet_key_constant = 'constant' === Settings::api_key_source();
$sendfleet_last_webhook = State::get( 'last_webhook' );
$sendfleet_has_secret   = '' !== Settings::webhook_secret();
?>
<div class="wrap sf-wrap">
	<?php Admin::header( Admin::SLUG_SET ); ?>
	<?php Admin::issues( false ); ?>

	<form method="post" action="<?php echo esc_url( Admin::post_url() ); ?>" class="sf-settings">
		<?php Admin::form_fields( 'save_settings' ); ?>

		<section class="sf-card">
			<h2 class="sf-card__title"><?php esc_html_e( 'Connection', 'sendfleet' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="sf-api-key"><?php esc_html_e( 'API key', 'sendfleet' ); ?></label></th>
					<td>
						<?php if ( $sendfleet_key_constant ) : ?>
							<code class="sf-mono"><?php echo esc_html( Settings::api_key_prefix() ); ?>.••••••••</code>
							<p class="description"><?php esc_html_e( 'Defined by SENDFLEET_API_KEY in wp-config.php.', 'sendfleet' ); ?></p>
						<?php else : ?>
							<div class="sf-connect__row">
								<input id="sf-api-key" class="sf-input sf-input--mono regular-text" type="password" name="api_key" value="" autocomplete="off" spellcheck="false" placeholder="<?php echo esc_attr( '' !== Settings::api_key_prefix() ? Settings::api_key_prefix() . '.•••••••• ' . __( '(saved — paste a new key to replace it)', 'sendfleet' ) : 'abcd1234.••••••••••••••••••••••••••••••••' ); ?>" />
								<button type="button" class="button sf-reveal" data-target="sf-api-key"><?php esc_html_e( 'Show', 'sendfleet' ); ?></button>
							</div>
							<p class="description">
								<?php esc_html_e( 'Create keys in the SendFleet dashboard under API Keys. Stored encrypted. For a key kept out of the database, define SENDFLEET_API_KEY in wp-config.php.', 'sendfleet' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Route WordPress email', 'sendfleet' ); ?></th>
					<td>
						<label><input type="checkbox" name="enabled" value="1" <?php checked( $sendfleet_settings['enabled'] ); ?> /> <?php esc_html_e( 'Send every wp_mail() email through SendFleet', 'sendfleet' ); ?></label>
						<p class="description"><?php esc_html_e( 'Untick to pause SendFleet without removing the key; WordPress then sends the default way.', 'sendfleet' ); ?></p>
					</td>
				</tr>
			</table>
		</section>

		<section class="sf-card">
			<h2 class="sf-card__title"><?php esc_html_e( 'Sender', 'sendfleet' ); ?></h2>
			<p class="sf-help"><?php esc_html_e( 'SendFleet only sends from verified domains on your account, matched exactly (a subdomain is its own domain). When an email\'s sender is on one of them it is kept; otherwise the default sender below is used and the original sender becomes the Reply-To.', 'sendfleet' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="sf-from-email"><?php esc_html_e( 'Default sender', 'sendfleet' ); ?></label></th>
					<td>
						<?php if ( Settings::is_constant( 'from_email' ) ) : ?>
							<code><?php echo esc_html( Settings::from_email() ); ?></code>
							<p class="description"><?php esc_html_e( 'Defined by SENDFLEET_FROM_EMAIL in wp-config.php.', 'sendfleet' ); ?></p>
						<?php else : ?>
							<input id="sf-from-email" class="sf-input regular-text" type="email" name="from_email" value="<?php echo esc_attr( $sendfleet_settings['from_email'] ); ?>" placeholder="<?php echo esc_attr( Account::default_sender() ); ?>" list="sf-domain-suggestions" />
							<datalist id="sf-domain-suggestions">
								<?php foreach ( array_keys( $sendfleet_routes ) as $sendfleet_domain ) : ?>
									<option value="<?php echo esc_attr( 'wordpress@' . $sendfleet_domain ); ?>"></option>
									<option value="<?php echo esc_attr( 'no-reply@' . $sendfleet_domain ); ?>"></option>
								<?php endforeach; ?>
							</datalist>
							<p class="description">
								<?php
								if ( empty( $sendfleet_routes ) ) {
									esc_html_e( 'Leave empty to pick automatically once a verified domain exists.', 'sendfleet' );
								} else {
									/* translators: %s: list of domains. */
									echo esc_html( sprintf( __( 'Leave empty to pick automatically. Verified domains: %s', 'sendfleet' ), implode( ', ', array_keys( $sendfleet_routes ) ) ) );
								}
								?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Force sender', 'sendfleet' ); ?></th>
					<td>
						<label><input type="checkbox" name="force_from" value="1" <?php checked( $sendfleet_settings['force_from'] ); ?> /> <?php esc_html_e( 'Always send from the default sender, even when another plugin sets a verified one', 'sendfleet' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sf-from-name"><?php esc_html_e( 'Sender name', 'sendfleet' ); ?></label></th>
					<td>
						<?php if ( Settings::is_constant( 'from_name' ) ) : ?>
							<code><?php echo esc_html( Settings::from_name() ); ?></code>
							<p class="description"><?php esc_html_e( 'Defined by SENDFLEET_FROM_NAME in wp-config.php.', 'sendfleet' ); ?></p>
						<?php else : ?>
							<input id="sf-from-name" class="sf-input regular-text" type="text" name="from_name" maxlength="156" value="<?php echo esc_attr( $sendfleet_settings['from_name'] ); ?>" placeholder="<?php echo esc_attr( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Used when an email has no sender name or WordPress\' generic "WordPress". Defaults to the site title.', 'sendfleet' ); ?></p>
						<?php endif; ?>
						<label class="sf-sub"><input type="checkbox" name="force_from_name" value="1" <?php checked( $sendfleet_settings['force_from_name'] ); ?> /> <?php esc_html_e( 'Always use this name', 'sendfleet' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sf-reply-to"><?php esc_html_e( 'Default Reply-To', 'sendfleet' ); ?></label></th>
					<td>
						<input id="sf-reply-to" class="sf-input regular-text" type="email" name="reply_to" value="<?php echo esc_attr( $sendfleet_settings['reply_to'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Optional. Used when an email has no Reply-To of its own.', 'sendfleet' ); ?></p>
					</td>
				</tr>
			</table>
		</section>

		<section class="sf-card">
			<h2 class="sf-card__title"><?php esc_html_e( 'Delivery', 'sendfleet' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Background sending', 'sendfleet' ); ?></th>
					<td>
						<label><input type="checkbox" name="background" value="1" <?php checked( $sendfleet_settings['background'] ); ?> /> <?php esc_html_e( 'Queue emails and send them from WP-Cron', 'sendfleet' ); ?></label>
						<p class="description"><?php esc_html_e( 'Pages that send email (checkout, forms) respond faster. Requires a working WP-Cron. Emails larger than 2 MB are still sent immediately. Retryable failures are always queued, whatever this setting.', 'sendfleet' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Fallback', 'sendfleet' ); ?></th>
					<td>
						<label><input type="checkbox" name="fallback" value="1" <?php checked( $sendfleet_settings['fallback'] ); ?> /> <?php esc_html_e( 'If SendFleet cannot send an email, send it the default WordPress way instead', 'sendfleet' ); ?></label>
						<p class="description"><?php esc_html_e( 'Covers a rejected key, a used-up plan, an unverified domain and Free plan smoke-test recipients. Never used after a timeout, because SendFleet may already have accepted that email. Mail sent this way does not get SendFleet\'s deliverability.', 'sendfleet' ); ?></p>
					</td>
				</tr>
			</table>
		</section>

		<section class="sf-card">
			<h2 class="sf-card__title"><?php esc_html_e( 'Email log and suppressions', 'sendfleet' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Email log', 'sendfleet' ); ?></th>
					<td>
						<label><input type="checkbox" name="log_enabled" value="1" <?php checked( $sendfleet_settings['log_enabled'] ); ?> /> <?php esc_html_e( 'Keep a log of every email (recipient, sender, subject, status — never the message body)', 'sendfleet' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sf-retention"><?php esc_html_e( 'Keep entries for', 'sendfleet' ); ?></label></th>
					<td>
						<input id="sf-retention" class="small-text" type="number" min="1" max="365" name="log_retention" value="<?php echo esc_attr( (string) Settings::log_retention() ); ?>" /> <?php esc_html_e( 'days', 'sendfleet' ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Suppression list', 'sendfleet' ); ?></th>
					<td>
						<label><input type="checkbox" name="suppression_enabled" value="1" <?php checked( $sendfleet_settings['suppression_enabled'] ); ?> /> <?php esc_html_e( 'Do not send to addresses that hard-bounced, complained or were suppressed', 'sendfleet' ); ?></label>
						<p class="description"><?php esc_html_e( 'Filled automatically by webhooks (paid plans) and by hand under Email Log → Suppressions. Saves sends from your allowance and protects your sender reputation.', 'sendfleet' ); ?></p>
					</td>
				</tr>
			</table>
		</section>

		<section class="sf-card" id="webhooks">
			<h2 class="sf-card__title"><?php esc_html_e( 'Webhooks', 'sendfleet' ); ?> <span class="sf-badge sf-badge--neutral"><?php esc_html_e( 'Paid plans', 'sendfleet' ); ?></span></h2>
			<p class="sf-help"><?php esc_html_e( 'Optional. Lets SendFleet report delivered, bounced and complained emails back to this log, and keeps the suppression list current. On the BYOC plan they come from your own SES events while delivery tracking is on (the default, under Dashboard → AWS in SendFleet). The Free plan, including its smoke tests, does not fire webhooks.', 'sendfleet' ); ?></p>
			<?php if ( Account::is_known() && Account::is_free() ) : ?>
				<div class="sf-callout sf-callout--info"><p><?php esc_html_e( 'Your current plan does not deliver webhooks. You can leave this empty.', 'sendfleet' ); ?></p></div>
			<?php endif; ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Endpoint URL', 'sendfleet' ); ?></th>
					<td>
						<div class="sf-connect__row">
							<input id="sf-webhook-url" class="sf-input sf-input--mono large-text" type="text" readonly value="<?php echo esc_attr( Webhooks::url() ); ?>" />
							<button type="button" class="button sf-copy" data-target="sf-webhook-url"><?php esc_html_e( 'Copy', 'sendfleet' ); ?></button>
						</div>
						<p class="description"><?php esc_html_e( 'In the SendFleet dashboard go to Webhooks, add this URL and select the events you want (email.delivered, email.bounced, email.complained and email.suppressed are the useful ones). SendFleet then shows the signing secret once.', 'sendfleet' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sf-webhook-secret"><?php esc_html_e( 'Signing secret', 'sendfleet' ); ?></label></th>
					<td>
						<?php if ( Settings::is_constant( 'webhook_secret' ) ) : ?>
							<p class="description"><?php esc_html_e( 'Defined by SENDFLEET_WEBHOOK_SECRET in wp-config.php.', 'sendfleet' ); ?></p>
						<?php else : ?>
							<input id="sf-webhook-secret" class="sf-input sf-input--mono regular-text" type="password" name="webhook_secret" value="" autocomplete="off" placeholder="<?php echo $sendfleet_has_secret ? esc_attr__( 'Saved — paste a new secret to replace it', 'sendfleet' ) : ''; ?>" />
							<?php if ( $sendfleet_has_secret ) : ?>
								<label class="sf-sub"><input type="checkbox" name="clear_webhook_secret" value="1" /> <?php esc_html_e( 'Remove the saved secret (stops accepting webhooks)', 'sendfleet' ); ?></label>
							<?php endif; ?>
						<?php endif; ?>
						<p class="description">
							<?php
							if ( is_array( $sendfleet_last_webhook ) ) {
								/* translators: 1: event name, 2: time ago. */
								echo esc_html( sprintf( __( 'Last verified webhook: %1$s, %2$s ago.', 'sendfleet' ), $sendfleet_last_webhook['event'], human_time_diff( (int) $sendfleet_last_webhook['time'] ) ) );
							} elseif ( $sendfleet_has_secret ) {
								esc_html_e( 'No webhook received yet.', 'sendfleet' );
							}
							?>
						</p>
					</td>
				</tr>
			</table>
		</section>

		<p class="submit"><button type="submit" class="button button-primary sf-button"><?php esc_html_e( 'Save settings', 'sendfleet' ); ?></button></p>
	</form>

	<section class="sf-card sf-card--muted">
		<h2 class="sf-card__title"><?php esc_html_e( 'wp-config.php constants', 'sendfleet' ); ?></h2>
		<p class="sf-help"><?php esc_html_e( 'Pin settings in code for staging and production deployments. Constants override the fields above.', 'sendfleet' ); ?></p>
		<pre class="sf-pre">define( 'SENDFLEET_API_KEY', 'abcd1234.your-secret' );
define( 'SENDFLEET_FROM_EMAIL', 'wordpress@example.com' );
define( 'SENDFLEET_FROM_NAME', 'Example' );
define( 'SENDFLEET_WEBHOOK_SECRET', 'whsec-from-the-dashboard' );</pre>
	</section>
</div>
