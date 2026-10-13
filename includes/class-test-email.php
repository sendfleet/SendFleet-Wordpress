<?php
/**
 * Test email.
 *
 * @package SendFleet
 */

namespace SendFleet;

defined( 'ABSPATH' ) || exit;

/**
 * Sends a branded test email through the normal wp_mail() path and reports
 * exactly what SendFleet answered.
 */
final class Test_Email {

	/**
	 * Sends the test.
	 *
	 * @param string $to   Recipient.
	 * @param string $from Optional sender address.
	 * @return array{ok:bool,message:string}
	 */
	public static function send( $to, $from = '' ) {
		if ( ! Mailer::should_route() ) {
			return array(
				'ok'      => false,
				'message' => __( 'Routing through SendFleet is off or no API key is saved, so the test was not sent.', 'sendfleet' ),
			);
		}

		$captured = array();
		$failure  = null;

		$on_dispatch = static function ( $results ) use ( &$captured ) {
			$captured = (array) $results;
		};
		$on_failure  = static function ( $error ) use ( &$failure ) {
			$failure = $error;
		};

		add_action( 'sendfleet_dispatched', $on_dispatch );
		add_action( 'wp_mail_failed', $on_failure );

		Dispatcher::$force_immediate = true;

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		if ( '' !== $from && is_email( $from ) ) {
			$headers[] = 'From: ' . $from;
		}

		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		/* translators: %s: site title. */
		$subject = sprintf( __( 'SendFleet test email from %s', 'sendfleet' ), $site );

		$sent = wp_mail( $to, $subject, self::html( $site ), $headers );

		Dispatcher::$force_immediate = false;

		remove_action( 'sendfleet_dispatched', $on_dispatch );
		remove_action( 'wp_mail_failed', $on_failure );

		$result = isset( $captured[0] ) ? $captured[0] : null;

		if ( $sent && $result && Log::STATUS_ACCEPTED === $result['status'] ) {
			return array(
				'ok'      => true,
				'message' => sprintf(
					/* translators: 1: recipient, 2: sending mode, 3: message id. */
					__( 'SendFleet accepted the test email to %1$s (mode: %2$s, message id: %3$s). Delivery is asynchronous; check the inbox in a minute.', 'sendfleet' ),
					$result['email'],
					'' !== $result['mode'] ? $result['mode'] : '—',
					'' !== $result['message_id'] ? $result['message_id'] : '—'
				),
			);
		}

		if ( $sent && $result && Log::STATUS_QUEUED === $result['status'] ) {
			return array(
				'ok'      => true,
				'message' => sprintf(
					/* translators: 1: recipient, 2: reason. */
					__( 'The test email to %1$s is queued and will be retried automatically: %2$s', 'sendfleet' ),
					$result['email'],
					$result['detail']
				),
			);
		}

		if ( $sent && $result && Log::STATUS_FALLBACK === $result['status'] ) {
			return array(
				'ok'      => false,
				/* translators: %s: reason. */
				'message' => sprintf( __( 'SendFleet did not accept the test email; it was sent through WordPress\' default mailer instead (fallback). %s', 'sendfleet' ), $result['detail'] ),
			);
		}

		if ( $result ) {
			return array(
				'ok'      => false,
				'message' => sprintf(
					/* translators: 1: error code, 2: error detail. */
					__( 'Not sent (%1$s): %2$s', 'sendfleet' ),
					'' !== $result['code'] ? $result['code'] : $result['status'],
					$result['detail']
				),
			);
		}

		return array(
			'ok'      => false,
			'message' => is_wp_error( $failure ) ? $failure->get_error_message() : __( 'The test email could not be sent.', 'sendfleet' ),
		);
	}

	/**
	 * The test email body.
	 *
	 * @param string $site Site title.
	 * @return string
	 */
	private static function html( $site ) {
		$time = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );

		return '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
			. '<body style="margin:0;padding:0;background:#f4f3f1;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f3f1;padding:32px 12px;"><tr><td align="center">'
			. '<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,Arial,sans-serif;">'
			. '<tr><td style="background:#0a0a0a;padding:22px 28px;font-size:22px;font-weight:800;letter-spacing:-0.02em;color:#f0ede8;">Send<span style="color:#f5a623;">Fleet</span></td></tr>'
			. '<tr><td style="padding:28px;color:#1d1d1f;font-size:15px;line-height:1.6;">'
			. '<h1 style="margin:0 0 12px;font-size:20px;line-height:1.3;color:#0a0a0a;">' . esc_html__( 'Your test email arrived.', 'sendfleet' ) . '</h1>'
			/* translators: %s: site title. */
			. '<p style="margin:0 0 12px;">' . esc_html( sprintf( __( '%s is sending its email through SendFleet. Password resets, notifications, orders and form submissions now go through the same path.', 'sendfleet' ), $site ) ) . '</p>'
			/* translators: %s: date and time. */
			. '<p style="margin:0;color:#6b6b6b;font-size:13px;">' . esc_html( sprintf( __( 'Sent %s from the SendFleet WordPress plugin.', 'sendfleet' ), $time ) ) . '</p>'
			. '</td></tr></table></td></tr></table></body></html>';
	}
}
