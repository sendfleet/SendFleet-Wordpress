<?php
/**
 * Configuration health.
 *
 * @package SendFleet
 */

namespace SendFleet;

defined( 'ABSPATH' ) || exit;

/**
 * Works out what, if anything, stands between this site and delivered email.
 * Used by the admin notices, the overview page and Site Health.
 */
final class Status {

	/**
	 * Current issues, most severe first.
	 *
	 * @return array[] Each: id, level (error|warning|info), message.
	 */
	public static function issues() {
		$issues = array();

		if ( Settings::api_key_unreadable() ) {
			$issues[] = self::issue( 'key_unreadable', 'error', __( 'The stored SendFleet API key can no longer be decrypted (the site\'s security salts changed). Paste the API key again in SendFleet → Settings.', 'sendfleet' ) );
			return $issues;
		}

		if ( '' === Settings::api_key() ) {
			$issues[] = self::issue( 'not_connected', 'warning', __( 'SendFleet is not connected yet, so WordPress is still sending email the default way. Add your API key to route email through SendFleet.', 'sendfleet' ) );
			return $issues;
		}

		if ( ! Settings::is_enabled() ) {
			$issues[] = self::issue( 'disabled', 'info', __( 'Routing through SendFleet is switched off in the settings. WordPress is sending email the default way.', 'sendfleet' ) );
		}

		$override = self::wp_mail_override();

		if ( '' !== $override ) {
			/* translators: %s: file path. */
			$issues[] = self::issue( 'wp_mail_replaced', 'error', sprintf( __( 'Another plugin replaces WordPress\' wp_mail() function (%s), so email may bypass SendFleet. Deactivate other SMTP or mailer plugins.', 'sendfleet' ), $override ) );
		}

		$auth     = State::breaker( 'auth' );
		$me_error = State::get( 'last_me_error' );

		if ( $auth || ( is_array( $me_error ) && 'invalid_api_key' === $me_error['code'] && ! Account::is_known() ) ) {
			$issues[] = self::issue( 'key_rejected', 'error', __( 'SendFleet rejected the API key (it may be mistyped, revoked or expired). Create a new key in the SendFleet dashboard and save it here.', 'sendfleet' ) );
			return $issues;
		}

		$suspended = State::breaker( 'suspended' );
		if ( $suspended ) {
			$issues[] = self::issue( 'suspended', 'error', trim( __( 'Sending is paused or suspended on the SendFleet account.', 'sendfleet' ) . ' ' . $suspended['detail'] ) );
		}

		$quota = State::breaker( 'quota' );
		if ( $quota ) {
			if ( 'byoc' === Account::plan() ) {
				$issues[] = self::issue( 'quota', 'error', __( 'The monthly cap on your SendFleet BYOC plan is used up, so email is not being sent. Raise the cap in the SendFleet dashboard (Dashboard → AWS; above 1,000,000 a month, email support@sendfleet.net) or wait for the reset on the 1st (UTC).', 'sendfleet' ) );
			} else {
				$issues[] = self::issue( 'quota', 'error', __( 'The monthly SendFleet plan limit is used up, so email is not being sent. Upgrade the plan or wait for the reset on the 1st (UTC).', 'sendfleet' ) );
			}
		}

		if ( State::breaker( 'network' ) ) {
			$issues[] = self::issue( 'network', 'warning', __( 'The SendFleet API did not respond to a recent request. New emails are queued and sent automatically once it answers again.', 'sendfleet' ) );
		}

		if ( ! Account::is_known() ) {
			if ( is_array( $me_error ) ) {
				/* translators: %s: error detail. */
				$issues[] = self::issue( 'account_unknown', 'warning', sprintf( __( 'Could not read the SendFleet account details: %s', 'sendfleet' ), $me_error['detail'] ) );
			}
			return $issues;
		}

		if ( ! Account::is_verified() ) {
			$issues[] = self::issue( 'unverified', 'error', __( 'The SendFleet account email address is not verified. SendFleet blocks sending until you click the verification link it emailed you.', 'sendfleet' ) );
		}

		if ( 'byoc' === Account::plan() && ! Account::byoc_configured() ) {
			$issues[] = self::issue( 'byoc_role', 'error', __( 'Your plan sends through your own AWS SES, but your AWS account is not connected to SendFleet. Connect it, or reconnect it, in the SendFleet dashboard (Dashboard → AWS).', 'sendfleet' ) );
		}

		$routes = Account::usable_domains();

		if ( empty( $routes ) ) {
			if ( Account::is_managed() && ! empty( Account::byoc_domains() ) && empty( Account::shared_domains() ) ) {
				$message = __( 'Your Managed plan sends through SendFleet\'s SES, but your verified domains are BYOC domains. A domain\'s sending mode is permanent: add a Shared SES domain in the SendFleet dashboard.', 'sendfleet' );
			} elseif ( ! Account::is_managed() && empty( Account::byoc_domains() ) && ! empty( Account::shared_domains() ) && ! Account::is_free() ) {
				$message = __( 'Your BYOC plan sends through your own AWS SES, but your verified domains are Shared SES domains. Add a BYOC domain in the SendFleet dashboard.', 'sendfleet' );
			} else {
				$message = __( 'There is no verified sending domain on your SendFleet account yet. Add a domain in the SendFleet dashboard (Domains), publish the DNS records and verify them.', 'sendfleet' );
			}
			$issues[] = self::issue( 'no_domain', 'error', $message );
		} else {
			$configured = Settings::from_email();

			if ( '' !== $configured && null === Account::route_for( $configured ) ) {
				/* translators: 1: configured sender, 2: sender used instead. */
				$issues[] = self::issue( 'sender_unverified', 'error', sprintf( __( 'The default sender %1$s is not on a verified sending domain of your SendFleet account, so %2$s is used instead. Choose an address on one of your verified domains.', 'sendfleet' ), $configured, Account::default_sender() ) );
			}

			if ( Account::is_smoke_only() ) {
				/* translators: %s: SendFleet account email. */
				$issues[] = self::issue( 'smoke', 'info', sprintf( __( 'Free plan smoke-test mode: your only domain is a Shared SES domain, so SendFleet delivers only to your own account address (%s). That covers notifications to yourself, such as contact forms. To email anyone else, add a BYOC domain (free) or choose a Managed plan; other recipients are skipped until then.', 'sendfleet' ), Account::email() ) );
			}
		}

		$limit = Account::limit();
		if ( null !== $limit && ! $quota ) {
			$used = Account::used();
			if ( $used >= $limit ) {
				if ( 'byoc' === Account::plan() ) {
					/* translators: 1: sends used, 2: plan limit. */
					$issues[] = self::issue( 'quota_reached', 'error', sprintf( __( 'You have used %1$s of %2$s emails this month. SendFleet refuses further sends until the 1st (UTC). For a higher limit, email support@sendfleet.net with your monthly volume.', 'sendfleet' ), number_format_i18n( $used ), number_format_i18n( $limit ) ) );
				} else {
					/* translators: 1: sends used, 2: plan limit. */
					$issues[] = self::issue( 'quota_reached', 'error', sprintf( __( 'You have used %1$s of %2$s emails this month. SendFleet refuses further sends until the 1st (UTC) unless you upgrade.', 'sendfleet' ), number_format_i18n( $used ), number_format_i18n( $limit ) ) );
				}
			} elseif ( $used >= 0.9 * $limit ) {
				/* translators: 1: sends used, 2: plan limit. */
				$issues[] = self::issue( 'quota_near', 'warning', sprintf( __( 'You have used %1$s of %2$s emails this month.', 'sendfleet' ), number_format_i18n( $used ), number_format_i18n( $limit ) ) );
			}
		}

		$next = Log::next_due();
		if ( null !== $next && $next < time() - 15 * MINUTE_IN_SECONDS ) {
			$issues[] = self::issue( 'queue_stuck', 'warning', __( 'Queued emails have been waiting for more than 15 minutes. WP-Cron does not seem to run on this site; make sure it is not disabled without a system cron job calling wp-cron.php.', 'sendfleet' ) );
		}

		usort(
			$issues,
			static function ( $a, $b ) {
				$order = array(
					'error'   => 0,
					'warning' => 1,
					'info'    => 2,
				);
				return $order[ $a['level'] ] - $order[ $b['level'] ];
			}
		);

		return $issues;
	}

	/**
	 * Where wp_mail() is defined when something other than WordPress defines it.
	 *
	 * A plugin that redefines the pluggable function may never apply the
	 * `pre_wp_mail` filter this plugin relies on.
	 *
	 * @return string Path relative to wp-content, or '' when core's own wp_mail() is in use.
	 */
	public static function wp_mail_override() {
		if ( ! function_exists( 'wp_mail' ) ) {
			return '';
		}

		try {
			$file = wp_normalize_path( (string) ( new \ReflectionFunction( 'wp_mail' ) )->getFileName() );
		} catch ( \ReflectionException $e ) {
			return '';
		}

		if ( '' === $file || wp_normalize_path( ABSPATH . WPINC . '/pluggable.php' ) === $file ) {
			return '';
		}

		$content = wp_normalize_path( WP_CONTENT_DIR ) . '/';

		return 0 === strpos( $file, $content ) ? substr( $file, strlen( $content ) ) : wp_basename( $file );
	}

	/**
	 * Builds an issue entry.
	 *
	 * @param string $id      Identifier.
	 * @param string $level   error, warning or info.
	 * @param string $message Message.
	 * @return array
	 */
	private static function issue( $id, $level, $message ) {
		return array(
			'id'      => $id,
			'level'   => $level,
			'message' => $message,
		);
	}
}
