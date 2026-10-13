<?php
/**
 * The wp_mail() integration.
 *
 * @package SendFleet
 */

namespace SendFleet;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Fires WordPress core mail hooks on purpose, exactly as wp_mail() does.

/**
 * Takes over wp_mail() through the `pre_wp_mail` filter (WordPress 5.7+)
 * instead of redefining the pluggable function, so it coexists with any
 * plugin that only filters or observes mail.
 */
final class Mailer {

	/**
	 * Registers the hook.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'pre_wp_mail', array( $this, 'pre_wp_mail' ), 10, 2 );
	}

	/**
	 * Whether wp_mail() should go through SendFleet right now.
	 *
	 * @param array $atts wp_mail() arguments.
	 * @return bool
	 */
	public static function should_route( array $atts = array() ) {
		$route = Settings::is_enabled() && '' !== Settings::api_key();

		/**
		 * Filters whether an email is sent through SendFleet.
		 *
		 * Return false to let WordPress send this email the default way.
		 *
		 * @param bool  $route Whether to route through SendFleet.
		 * @param array $atts  wp_mail() arguments.
		 */
		return (bool) apply_filters( 'sendfleet_should_route', $route, $atts );
	}

	/**
	 * `pre_wp_mail` callback.
	 *
	 * @param null|bool $short_circuit Value from earlier callbacks.
	 * @param array     $atts          wp_mail() arguments.
	 * @return null|bool
	 */
	public function pre_wp_mail( $short_circuit, $atts ) {
		if ( null !== $short_circuit ) {
			return $short_circuit; // Another plugin already handled this email.
		}

		$atts = is_array( $atts ) ? $atts : array();

		if ( ! self::should_route( $atts ) ) {
			return null;
		}

		try {
			return self::send( $atts, self::detect_source() );
		} catch ( \Throwable $e ) {
			do_action(
				'wp_mail_failed',
				new \WP_Error(
					'wp_mail_failed',
					/* translators: %s: error message. */
					sprintf( __( 'SendFleet: %s', 'sendfleet' ), $e->getMessage() ),
					$atts
				)
			);

			return false;
		}
	}

	/**
	 * Sends an email through SendFleet.
	 *
	 * @param array  $atts   wp_mail() arguments.
	 * @param string $source Caller label for the log.
	 * @return bool Whether every recipient was accepted (or queued) and at least one was.
	 */
	public static function send( array $atts, $source = '' ) {
		$message = Message_Builder::build( $atts );

		if ( is_wp_error( $message ) ) {
			do_action( 'wp_mail_failed', $message );
			return false;
		}

		$envelope  = Envelope::from_message( $message, $source );
		$mail_data = $envelope->mail_data;

		if ( empty( $envelope->recipients ) ) {
			do_action( 'wp_mail_failed', new \WP_Error( 'wp_mail_failed', __( 'You must provide at least one recipient email address.', 'sendfleet' ), $mail_data ) );
			return false;
		}

		if ( $envelope->is_empty() ) {
			do_action( 'wp_mail_failed', new \WP_Error( 'wp_mail_failed', __( 'Message body empty', 'sendfleet' ), $mail_data ) );
			return false;
		}

		$results   = Dispatcher::dispatch( $envelope );
		$delivered = 0;
		$errors    = array();

		foreach ( $results as $result ) {
			if ( in_array( $result['status'], array( Log::STATUS_ACCEPTED, Log::STATUS_QUEUED, Log::STATUS_FALLBACK ), true ) ) {
				++$delivered;
			} elseif ( Log::STATUS_FAILED === $result['status'] ) {
				$errors[] = $result;
			}
		}

		if ( $delivered > 0 && empty( $errors ) ) {
			/** This action is documented in wp-includes/pluggable.php */
			do_action( 'wp_mail_succeeded', $mail_data );
			return true;
		}

		$problems = empty( $errors ) ? $results : $errors;
		$lines    = array();

		foreach ( $problems as $problem ) {
			$lines[] = $problem['email'] . ': ' . ( '' !== $problem['detail'] ? $problem['detail'] : $problem['code'] );
		}

		$mail_data['sendfleet'] = $results;

		do_action(
			'wp_mail_failed',
			new \WP_Error(
				'wp_mail_failed',
				/* translators: %s: list of failures. */
				sprintf( __( 'SendFleet did not send this email. %s', 'sendfleet' ), implode( ' | ', $lines ) ),
				$mail_data
			)
		);

		return false;
	}

	/**
	 * Names the plugin, theme or file that called wp_mail().
	 *
	 * @return string e.g. "plugin:woocommerce", "theme:astra", "core".
	 */
	public static function detect_source() {
		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 30 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Used to label log entries with the calling plugin.
		$file   = '';

		foreach ( $frames as $frame ) {
			if ( isset( $frame['function'] ) && 'wp_mail' === $frame['function'] && empty( $frame['class'] ) && ! empty( $frame['file'] ) ) {
				$file = wp_normalize_path( $frame['file'] );
				break;
			}
		}

		if ( '' === $file ) {
			return '';
		}

		$candidates = array(
			'plugin'    => defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '',
			'mu-plugin' => defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : '',
			'theme'     => function_exists( 'get_theme_root' ) ? get_theme_root() : '',
		);

		foreach ( $candidates as $type => $root ) {
			if ( '' === $root ) {
				continue;
			}

			$root = trailingslashit( wp_normalize_path( $root ) );

			if ( 0 === strpos( $file, $root ) ) {
				$relative = substr( $file, strlen( $root ) );
				$parts    = explode( '/', $relative );
				$slug     = 'mu-plugin' === $type ? preg_replace( '/\.php$/', '', $parts[0] ) : $parts[0];

				return $type . ':' . sanitize_key( $slug );
			}
		}

		$abspath = trailingslashit( wp_normalize_path( ABSPATH ) );

		if ( 0 === strpos( $file, $abspath . 'wp-includes/' ) || 0 === strpos( $file, $abspath . 'wp-admin/' ) || 0 === strpos( $file, $abspath . 'wp-login.php' ) ) {
			return 'core';
		}

		return 'other';
	}
}
