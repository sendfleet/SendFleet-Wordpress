<?php
/**
 * Runtime state: circuit breakers and diagnostics.
 *
 * @package SendFleet
 */

namespace SendFleet;

defined( 'ABSPATH' ) || exit;

/**
 * Small key/value store in the `sendfleet_state` option.
 *
 * Circuit breakers stop the plugin from hammering the API when every send is
 * going to fail the same way:
 *
 * - auth      The key was rejected. Repeated failures from one IP get that IP
 *             temporarily blocked by SendFleet (10 per 60 s), so stop at one.
 * - quota     The monthly plan limit is exhausted.
 * - suspended Sending is paused or suspended on the account.
 * - throttle  The per-key rate limit (60/min) was hit; sends are queued until it lifts.
 * - network   The API did not answer; sends are queued for a minute rather than each
 *             waiting for a timeout.
 */
final class State {

	const OPTION = 'sendfleet_state';

	/**
	 * Request cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * All state values.
	 *
	 * @return array
	 */
	private static function all() {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = is_array( $stored ) ? $stored : array();
		}

		return self::$cache;
	}

	/**
	 * Reads a value.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Value when absent.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Writes a value (null deletes it).
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @return void
	 */
	public static function set( $key, $value ) {
		// Re-read so concurrent requests lose as little as possible.
		self::$cache = null;
		$all         = self::all();

		if ( null === $value ) {
			if ( ! array_key_exists( $key, $all ) ) {
				return;
			}
			unset( $all[ $key ] );
		} else {
			$all[ $key ] = $value;
		}

		update_option( self::OPTION, $all, true );
		self::$cache = $all;
	}

	/**
	 * Opens a breaker.
	 *
	 * @param string $name   Breaker name.
	 * @param int    $ttl    Seconds until it closes on its own.
	 * @param string $detail Message to show the admin.
	 * @return void
	 */
	public static function trip_breaker( $name, $ttl, $detail = '' ) {
		$breakers          = (array) self::get( 'breakers', array() );
		$breakers[ $name ] = array(
			'since'  => time(),
			'until'  => time() + max( 1, (int) $ttl ),
			'detail' => (string) $detail,
		);

		self::set( 'breakers', $breakers );
	}

	/**
	 * An open breaker.
	 *
	 * @param string $name Breaker name.
	 * @return array|null Keys since, until, detail. Null when closed.
	 */
	public static function breaker( $name ) {
		$breakers = (array) self::get( 'breakers', array() );

		if ( empty( $breakers[ $name ] ) || ! is_array( $breakers[ $name ] ) ) {
			return null;
		}

		if ( (int) $breakers[ $name ]['until'] <= time() ) {
			return null;
		}

		return $breakers[ $name ];
	}

	/**
	 * Closes a breaker.
	 *
	 * @param string $name Breaker name.
	 * @return void
	 */
	public static function clear_breaker( $name ) {
		$breakers = (array) self::get( 'breakers', array() );

		if ( isset( $breakers[ $name ] ) ) {
			unset( $breakers[ $name ] );
			self::set( 'breakers', $breakers );
		}
	}

	/**
	 * Closes every breaker (e.g. after the key changes).
	 *
	 * @return void
	 */
	public static function clear_all_breakers() {
		self::set( 'breakers', null );
	}

	/**
	 * Clears the request cache.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Unix time of the first day of next month, UTC — when SendFleet quotas reset.
	 *
	 * @return int
	 */
	public static function next_month_start_utc() {
		$now = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );

		return $now->modify( 'first day of next month' )->setTime( 0, 0, 0 )->getTimestamp();
	}
}
