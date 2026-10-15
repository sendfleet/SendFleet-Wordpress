<?php
/**
 * SendFleet API client.
 *
 * @package SendFleet
 */

namespace SendFleet;

defined( 'ABSPATH' ) || exit;

/**
 * Thin client over the two public endpoints:
 *
 * - POST /v1/send  One message per request, carrying its To, Cc and Bcc recipients (up to 50).
 * - GET  /v1/me    Account, plan, usage and verified domains.
 *
 * Authentication is the `X-API-Key` header. Redirects are never followed so
 * the key cannot be forwarded to another host.
 */
final class Api_Client {

	/**
	 * API key to authenticate with.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Constructor.
	 *
	 * @param string|null $api_key Key to use; defaults to the configured key.
	 */
	public function __construct( $api_key = null ) {
		$this->api_key = null === $api_key ? Settings::api_key() : trim( (string) $api_key );
	}

	/**
	 * The API origin, without a trailing slash.
	 *
	 * Overridable with the SENDFLEET_API_URL constant or the
	 * `sendfleet_api_url` filter (for staging and local testing only).
	 *
	 * @return string
	 */
	public static function base_url() {
		$url = SENDFLEET_DEFAULT_API_URL;

		if ( defined( 'SENDFLEET_API_URL' ) && is_string( SENDFLEET_API_URL ) && '' !== SENDFLEET_API_URL ) {
			$url = SENDFLEET_API_URL;
		}

		/**
		 * Filters the SendFleet API origin.
		 *
		 * @param string $url API origin, e.g. https://api.sendfleet.net.
		 */
		$url = (string) apply_filters( 'sendfleet_api_url', $url );

		return untrailingslashit( esc_url_raw( $url ) );
	}

	/**
	 * The User-Agent sent with every request.
	 *
	 * @return string
	 */
	public static function user_agent() {
		global $wp_version;

		return sprintf( 'SendFleet-WordPress/%s (WordPress/%s; PHP/%s)', SENDFLEET_VERSION, $wp_version, PHP_VERSION );
	}

	/**
	 * Sends one email.
	 *
	 * @param array $payload Request body as documented for POST /v1/send.
	 * @return Api_Response
	 */
	public function send( array $payload ) {
		$body = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $body ) {
			return Api_Response::local_error( 'encoding_failed', __( 'The email could not be encoded as JSON (invalid characters in the content).', 'sendfleet' ) );
		}

		// Allow more time for larger bodies (attachments): 20 s plus 1 s per MB, capped at 120 s.
		$timeout = (int) min( 120, 20 + ceil( strlen( $body ) / 1048576 ) );

		return $this->request( 'POST', '/v1/send', $body, $timeout );
	}

	/**
	 * Fetches the account summary.
	 *
	 * @return Api_Response
	 */
	public function me() {
		return $this->request( 'GET', '/v1/me', null, 15 );
	}

	/**
	 * Performs a request.
	 *
	 * @param string      $method  HTTP method.
	 * @param string      $path    Path, starting with a slash.
	 * @param string|null $body    JSON body.
	 * @param int         $timeout Timeout in seconds.
	 * @return Api_Response
	 */
	private function request( $method, $path, $body, $timeout ) {
		if ( '' === $this->api_key ) {
			return Api_Response::local_error( 'missing_api_key', __( 'No SendFleet API key is configured.', 'sendfleet' ) );
		}

		$args = array(
			'method'      => $method,
			'timeout'     => $timeout,
			'redirection' => 0,
			'httpversion' => '1.1',
			'user-agent'  => self::user_agent(),
			'headers'     => array(
				'X-API-Key' => $this->api_key,
				'Accept'    => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = $body;
		}

		/**
		 * Filters the HTTP arguments for a SendFleet API request.
		 *
		 * @param array  $args Arguments for wp_remote_request().
		 * @param string $path Request path.
		 */
		$args = apply_filters( 'sendfleet_http_args', $args, $path );

		$result = wp_remote_request( self::base_url() . $path, $args );

		return Api_Response::from_http( $result );
	}
}
