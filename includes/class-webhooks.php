<?php
/**
 * Webhook receiver.
 *
 * @package SendFleet
 */

namespace SendFleet;

defined( 'ABSPATH' ) || exit;

/**
 * Receives SendFleet delivery events (paid plans) at
 * POST /wp-json/sendfleet/v1/webhook.
 *
 * Every request must carry `X-SendFleet-Signature: sha256=<hex>`, an
 * HMAC-SHA256 of the raw body with the endpoint's signing secret, compared
 * in constant time. Events update the matching log rows (correlated by
 * `idempotency_key`) and feed the local suppression list.
 */
final class Webhooks {

	const ROUTE_NAMESPACE = 'sendfleet/v1';
	const ROUTE           = '/webhook';

	/**
	 * Event name to log status.
	 *
	 * @var array<string,string>
	 */
	private static $event_status = array(
		'email.sent'       => Log::STATUS_SENT,
		'email.delivered'  => Log::STATUS_DELIVERED,
		'email.delayed'    => Log::STATUS_DELAYED,
		'email.bounced'    => Log::STATUS_BOUNCED,
		'email.complained' => Log::STATUS_COMPLAINED,
		'email.rejected'   => Log::STATUS_REJECTED,
		'email.suppressed' => Log::STATUS_SUPPRESSED,
		'email.failed'     => Log::STATUS_UNDELIVERED,
	);

	/**
	 * Registers the REST route.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	/**
	 * The public endpoint URL to paste into the SendFleet dashboard.
	 *
	 * @return string
	 */
	public static function url() {
		return rest_url( self::ROUTE_NAMESPACE . self::ROUTE );
	}

	/**
	 * `rest_api_init` callback.
	 *
	 * @return void
	 */
	public static function register_route() {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				// Public by design: SendFleet cannot log in. Authenticity is proven by the HMAC signature checked in handle().
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Precedence of statuses, so a late event never overwrites a later stage
	 * (e.g. email.sent arriving after email.delivered).
	 *
	 * @param string $status Status.
	 * @return int
	 */
	private static function rank( $status ) {
		$ranks = array(
			Log::STATUS_ACCEPTED    => 1,
			Log::STATUS_SENT        => 2,
			Log::STATUS_DELAYED     => 3,
			Log::STATUS_DELIVERED   => 4,
			Log::STATUS_BOUNCED     => 5,
			Log::STATUS_REJECTED    => 5,
			Log::STATUS_SUPPRESSED  => 5,
			Log::STATUS_UNDELIVERED => 5,
			Log::STATUS_COMPLAINED  => 6,
		);

		return isset( $ranks[ $status ] ) ? $ranks[ $status ] : 0;
	}

	/**
	 * Verifies a signature header against a body.
	 *
	 * @param string $body      Raw request body.
	 * @param string $signature Header value.
	 * @param string $secret    Signing secret.
	 * @return bool
	 */
	public static function verify( $body, $signature, $secret ) {
		if ( '' === (string) $secret || '' === (string) $signature ) {
			return false;
		}

		$expected = 'sha256=' . hash_hmac( 'sha256', (string) $body, (string) $secret );

		return hash_equals( $expected, trim( (string) $signature ) );
	}

	/**
	 * REST callback.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function handle( \WP_REST_Request $request ) {
		$secret = Settings::webhook_secret();

		if ( '' === $secret ) {
			return new \WP_REST_Response(
				array(
					'ok'    => false,
					'error' => 'webhooks_not_configured',
				),
				404
			);
		}

		$body = (string) $request->get_body();

		if ( ! self::verify( $body, (string) $request->get_header( 'x_sendfleet_signature' ), $secret ) ) {
			// Nothing is recorded here on purpose. This endpoint is public, so any state
			// written per bad signature is a write amplifier: the options row is autoloaded,
			// so it would be re-read and re-written for as long as an attacker keeps posting.
			return new \WP_REST_Response(
				array(
					'ok'    => false,
					'error' => 'invalid_signature',
				),
				401
			);
		}

		$payload = json_decode( $body, true );

		if ( ! is_array( $payload ) ) {
			return new \WP_REST_Response(
				array(
					'ok'    => false,
					'error' => 'invalid_payload',
				),
				400
			);
		}

		$event = sanitize_text_field( (string) $request->get_header( 'x_sendfleet_event' ) );

		if ( '' === $event && isset( $payload['status'] ) ) {
			$event = 'email.' . sanitize_key( (string) $payload['status'] );
		}

		$updated = self::apply( $event, $payload );

		State::set(
			'last_webhook',
			array(
				'time'  => time(),
				'event' => $event,
			)
		);

		/**
		 * Fires after a verified SendFleet webhook was processed.
		 *
		 * @param string $event   Event name, e.g. email.bounced.
		 * @param array  $payload Decoded payload.
		 */
		do_action( 'sendfleet_webhook', $event, $payload );

		return new \WP_REST_Response(
			array(
				'ok'      => true,
				'updated' => $updated,
			),
			200
		);
	}

	/**
	 * Applies an event to the log and the suppression list.
	 *
	 * @param string $event   Event name.
	 * @param array  $payload Payload.
	 * @return int Log rows updated.
	 */
	public static function apply( $event, array $payload ) {
		if ( ! isset( self::$event_status[ $event ] ) ) {
			return 0;
		}

		$status    = self::$event_status[ $event ];
		$key       = isset( $payload['idempotency_key'] ) ? sanitize_text_field( (string) $payload['idempotency_key'] ) : '';
		$recipient = isset( $payload['recipient'] ) ? sanitize_email( (string) $payload['recipient'] ) : '';
		$detail    = self::describe( $event, $payload );
		$updated   = 0;

		if ( '' !== $key ) {
			foreach ( Log::find_by_idempotency_key( $key ) as $row ) {
				// One send can have several recipients under one key; every event
				// concerns one of them.
				if ( '' !== $recipient && strtolower( $row['recipient'] ) !== strtolower( $recipient ) ) {
					continue;
				}

				$columns = array(
					'notes' => trim( (string) $row['notes'] . "\n" . $detail ),
				);

				if ( self::rank( $status ) >= self::rank( $row['status'] ) ) {
					$columns['status'] = $status;

					if ( ! in_array( $status, array( Log::STATUS_SENT, Log::STATUS_DELIVERED ), true ) ) {
						$columns['error_message'] = $detail;
					}
				}

				Log::update( (int) $row['id'], $columns );
				++$updated;
			}
		}

		if ( Settings::get( 'suppression_enabled' ) ) {
			$permanent_bounce = 'email.bounced' === $event && isset( $payload['bounce_type'] ) && 'permanent' === strtolower( (string) $payload['bounce_type'] );

			if ( $permanent_bounce || in_array( $event, array( 'email.complained', 'email.suppressed' ), true ) ) {
				$addresses = array( $recipient );

				foreach ( array( 'bounced_recipients', 'complained_recipients' ) as $list ) {
					if ( isset( $payload[ $list ] ) && is_array( $payload[ $list ] ) ) {
						$addresses = array_merge( $addresses, $payload[ $list ] );
					}
				}

				$reason = $permanent_bounce ? 'bounced' : ( 'email.complained' === $event ? 'complained' : 'suppressed' );

				foreach ( array_unique( array_filter( array_map( 'sanitize_email', array_map( 'strval', $addresses ) ) ) ) as $address ) {
					Suppressions::add( $address, $reason, $detail );
				}
			}
		}

		return $updated;
	}

	/**
	 * A one-line description of an event for the log.
	 *
	 * @param string $event   Event name.
	 * @param array  $payload Payload.
	 * @return string
	 */
	private static function describe( $event, array $payload ) {
		$parts = array( $event );

		foreach ( array( 'bounce_type', 'bounce_subtype', 'complaint_feedback_type', 'delay_type', 'reason', 'smtp_response', 'error_message' ) as $field ) {
			if ( isset( $payload[ $field ] ) && is_scalar( $payload[ $field ] ) && '' !== (string) $payload[ $field ] ) {
				$parts[] = $field . ': ' . sanitize_text_field( (string) $payload[ $field ] );
			}
		}

		if ( isset( $payload['timestamp'] ) && is_string( $payload['timestamp'] ) ) {
			$parts[] = 'at ' . sanitize_text_field( $payload['timestamp'] );
		}

		return implode( ' · ', $parts );
	}
}
