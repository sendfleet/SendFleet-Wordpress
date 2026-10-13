<?php
/**
 * Plugin settings.
 *
 * @package SendFleet
 */

namespace SendFleet;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the `sendfleet_settings` option, and resolves the values
 * that may be pinned in wp-config.php instead.
 *
 * Constants always win over stored options:
 *
 * - SENDFLEET_API_KEY        The API key (prefix.secret).
 * - SENDFLEET_FROM_EMAIL     Default sender address.
 * - SENDFLEET_FROM_NAME      Default sender name.
 * - SENDFLEET_WEBHOOK_SECRET Webhook signing secret.
 */
final class Settings {

	const OPTION = 'sendfleet_settings';

	/**
	 * Request-level cache of the decoded settings.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Request-level cache of the decrypted API key.
	 *
	 * @var string|null
	 */
	private static $api_key = null;

	/**
	 * Default values for every setting.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'             => true,
			'api_key'             => '',
			'from_email'          => '',
			'from_name'           => '',
			'force_from'          => false,
			'force_from_name'     => false,
			'reply_to'            => '',
			'background'          => false,
			'fallback'            => false,
			'log_enabled'         => true,
			'log_retention'       => 30,
			'suppression_enabled' => true,
			'webhook_secret'      => '',
		);
	}

	/**
	 * Returns every setting merged over the defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}

		return self::$cache;
	}

	/**
	 * Returns one setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : null;
	}

	/**
	 * Merges values into the stored settings.
	 *
	 * @param array $values Values to store. Secrets must already be encrypted.
	 * @return void
	 */
	public static function update( array $values ) {
		$current = get_option( self::OPTION, array() );
		$current = is_array( $current ) ? $current : array();
		$merged  = array_intersect_key( array_merge( self::defaults(), $current, $values ), self::defaults() );

		update_option( self::OPTION, $merged, true );
		self::flush();
	}

	/**
	 * Clears the request caches, e.g. after an update.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache   = null;
		self::$api_key = null;
	}

	/**
	 * Whether wp_mail() should be routed through SendFleet.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) self::get( 'enabled' );
	}

	/**
	 * Where the API key comes from.
	 *
	 * @return string 'constant', 'option' or '' when no key is configured.
	 */
	public static function api_key_source() {
		if ( defined( 'SENDFLEET_API_KEY' ) && is_string( SENDFLEET_API_KEY ) && '' !== trim( SENDFLEET_API_KEY ) ) {
			return 'constant';
		}

		return '' !== (string) self::get( 'api_key' ) ? 'option' : '';
	}

	/**
	 * The API key in clear text.
	 *
	 * @return string '' when none is configured or the stored value cannot be decrypted.
	 */
	public static function api_key() {
		if ( null !== self::$api_key ) {
			return self::$api_key;
		}

		if ( 'constant' === self::api_key_source() ) {
			self::$api_key = trim( SENDFLEET_API_KEY );
		} else {
			self::$api_key = trim( Crypto::decrypt( (string) self::get( 'api_key' ) ) );
		}

		return self::$api_key;
	}

	/**
	 * Whether a key is stored but can no longer be decrypted (salts rotated).
	 *
	 * @return bool
	 */
	public static function api_key_unreadable() {
		return 'option' === self::api_key_source() && '' === self::api_key();
	}

	/**
	 * The public prefix of the API key, safe to display.
	 *
	 * @return string
	 */
	public static function api_key_prefix() {
		$key = self::api_key();

		if ( '' === $key ) {
			return '';
		}

		$dot = strpos( $key, '.' );

		return false === $dot ? substr( $key, 0, 4 ) : substr( $key, 0, $dot );
	}

	/**
	 * A short fingerprint of the key, used to tie cached account data to the key that fetched it.
	 *
	 * @return string
	 */
	public static function api_key_fingerprint() {
		$key = self::api_key();

		return '' === $key ? '' : substr( hash( 'sha256', $key ), 0, 16 );
	}

	/**
	 * Whether a string looks like a SendFleet API key.
	 *
	 * Keys are `<8-char prefix>.<32-char secret>` in the URL-safe base64 alphabet.
	 *
	 * @param string $key Candidate key.
	 * @return bool
	 */
	public static function looks_like_api_key( $key ) {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{8}\.[A-Za-z0-9_-]{32}$/', (string) $key );
	}

	/**
	 * The configured default sender address.
	 *
	 * @return string
	 */
	public static function from_email() {
		if ( defined( 'SENDFLEET_FROM_EMAIL' ) && is_string( SENDFLEET_FROM_EMAIL ) && '' !== SENDFLEET_FROM_EMAIL ) {
			return sanitize_email( SENDFLEET_FROM_EMAIL );
		}

		return (string) self::get( 'from_email' );
	}

	/**
	 * The configured default sender name, falling back to the site title.
	 *
	 * @return string
	 */
	public static function from_name() {
		if ( defined( 'SENDFLEET_FROM_NAME' ) && is_string( SENDFLEET_FROM_NAME ) && '' !== SENDFLEET_FROM_NAME ) {
			return SENDFLEET_FROM_NAME;
		}

		$name = (string) self::get( 'from_name' );

		if ( '' === $name ) {
			$name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		}

		return $name;
	}

	/**
	 * The webhook signing secret in clear text.
	 *
	 * @return string
	 */
	public static function webhook_secret() {
		if ( defined( 'SENDFLEET_WEBHOOK_SECRET' ) && is_string( SENDFLEET_WEBHOOK_SECRET ) && '' !== SENDFLEET_WEBHOOK_SECRET ) {
			return SENDFLEET_WEBHOOK_SECRET;
		}

		return Crypto::decrypt( (string) self::get( 'webhook_secret' ) );
	}

	/**
	 * Whether a setting is pinned by a wp-config.php constant.
	 *
	 * @param string $setting Setting name.
	 * @return bool
	 */
	public static function is_constant( $setting ) {
		$map = array(
			'api_key'        => 'SENDFLEET_API_KEY',
			'from_email'     => 'SENDFLEET_FROM_EMAIL',
			'from_name'      => 'SENDFLEET_FROM_NAME',
			'webhook_secret' => 'SENDFLEET_WEBHOOK_SECRET',
		);

		return isset( $map[ $setting ] ) && defined( $map[ $setting ] ) && is_string( constant( $map[ $setting ] ) ) && '' !== constant( $map[ $setting ] );
	}

	/**
	 * Log retention in days (1–365).
	 *
	 * @return int
	 */
	public static function log_retention() {
		return max( 1, min( 365, (int) self::get( 'log_retention' ) ) );
	}
}
