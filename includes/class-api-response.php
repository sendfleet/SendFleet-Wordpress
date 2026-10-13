<?php
/**
 * SendFleet API response.
 *
 * @package SendFleet
 */

namespace SendFleet;

defined( 'ABSPATH' ) || exit;

/**
 * One HTTP exchange with the SendFleet API, classified the way the API
 * documentation tells clients to treat it.
 *
 * Retry classes:
 *
 * - none      Success, or a terminal error that must not be retried unchanged.
 * - throttle  Per-key rate limit (429 without an `error` code). Retry after `Retry-After`.
 * - transient Nothing was sent (502 queue_error / attachment_upload_failed, connection failures). Safe to retry.
 * - server    500 internal_error. Retry once after a few seconds.
 * - ambiguous The request may or may not have been accepted (timeouts). Not retried automatically,
 *             because a retry could deliver the message twice.
 */
final class Api_Response {

	/**
	 * HTTP status code, 0 when the request never completed.
	 *
	 * @var int
	 */
	public $status = 0;

	/**
	 * Decoded JSON body, or null.
	 *
	 * @var array|null
	 */
	public $body = null;

	/**
	 * Raw body (truncated), for diagnostics when it is not JSON.
	 *
	 * @var string
	 */
	public $raw = '';

	/**
	 * Transport error, when the request failed before a response arrived.
	 *
	 * @var \WP_Error|null
	 */
	public $transport_error = null;

	/**
	 * Seconds from the Retry-After header.
	 *
	 * @var int|null
	 */
	public $retry_after = null;

	/**
	 * The x-request-id header, to quote to support.
	 *
	 * @var string
	 */
	public $request_id = '';

	/**
	 * Whether this failure was produced locally without calling the API.
	 *
	 * @var bool
	 */
	public $local = false;

	/**
	 * Retry class of a local failure.
	 *
	 * @var string
	 */
	public $local_class = 'none';

	/**
	 * Builds a response from the WP HTTP API result.
	 *
	 * @param array|\WP_Error $result Result of wp_remote_request().
	 * @return self
	 */
	public static function from_http( $result ) {
		$response = new self();

		if ( is_wp_error( $result ) ) {
			$response->transport_error = $result;
			return $response;
		}

		$response->status = (int) wp_remote_retrieve_response_code( $result );
		$raw              = (string) wp_remote_retrieve_body( $result );
		$response->raw    = substr( $raw, 0, 2000 );
		$decoded          = json_decode( $raw, true );
		$response->body   = is_array( $decoded ) ? $decoded : null;

		$retry_after = wp_remote_retrieve_header( $result, 'retry-after' );
		if ( is_array( $retry_after ) ) {
			$retry_after = reset( $retry_after );
		}
		if ( is_numeric( $retry_after ) ) {
			$response->retry_after = max( 0, (int) $retry_after );
		}

		$request_id = wp_remote_retrieve_header( $result, 'x-request-id' );
		if ( is_array( $request_id ) ) {
			$request_id = reset( $request_id );
		}
		$response->request_id = sanitize_text_field( (string) $request_id );

		return $response;
	}

	/**
	 * Builds a synthetic failure that never reached the API.
	 *
	 * @param string $code        Error code.
	 * @param string $message     Human-readable message.
	 * @param string $retry_class How the caller may retry (none or transient).
	 * @return self
	 */
	public static function local_error( $code, $message, $retry_class = 'none' ) {
		$response                  = new self();
		$response->local           = true;
		$response->local_class     = $retry_class;
		$response->transport_error = new \WP_Error( $code, $message );
		$response->body            = array(
			'success' => false,
			'error'   => $code,
			'detail'  => $message,
		);

		return $response;
	}

	/**
	 * Builds a synthetic rate-limit response while the per-key limit is known to be exhausted.
	 *
	 * @param int $seconds Seconds until the limit lifts.
	 * @return self
	 */
	public static function local_throttle( $seconds ) {
		$response              = new self();
		$response->local       = true;
		$response->status      = 429;
		$response->retry_after = max( 1, (int) $seconds );
		$response->body        = array(
			'detail' => sprintf(
				/* translators: %d: seconds. */
				__( 'Waiting for the SendFleet rate limit (60 requests per minute per key) to lift in %d seconds.', 'sendfleet' ),
				$response->retry_after
			),
		);

		return $response;
	}

	/**
	 * Whether the request succeeded (200 with success=true).
	 *
	 * @return bool
	 */
	public function is_success() {
		return 200 === $this->status && is_array( $this->body ) && ! empty( $this->body['success'] );
	}

	/**
	 * Whether the response body carries a machine-readable `error` code.
	 *
	 * @return bool
	 */
	private function has_error_code() {
		return is_array( $this->body ) && isset( $this->body['error'] ) && is_string( $this->body['error'] ) && '' !== $this->body['error'];
	}

	/**
	 * The machine-readable error code.
	 *
	 * Uses the API's `error` field when present, and otherwise names the
	 * documented shapes that carry no code (a bare 403 is a key problem, a
	 * bare 429 is the per-key rate limit, a 400 is a validation failure).
	 *
	 * @return string '' on success.
	 */
	public function error_code() {
		if ( $this->is_success() ) {
			return '';
		}

		if ( $this->transport_error instanceof \WP_Error && 0 === $this->status ) {
			$code = $this->transport_error->get_error_code();
			if ( is_array( $this->body ) && isset( $this->body['error'] ) ) {
				return (string) $this->body['error'];
			}
			return $this->is_timeout() ? 'timeout' : ( is_string( $code ) && '' !== $code && 'http_request_failed' !== $code ? $code : 'network_error' );
		}

		if ( $this->has_error_code() ) {
			return (string) $this->body['error'];
		}

		switch ( $this->status ) {
			case 400:
				return 'validation_error';
			case 403:
				return 'invalid_api_key';
			case 404:
				return 'not_found';
			case 413:
				return 'payload_too_large';
			case 415:
				return 'unsupported_media_type';
			case 429:
				return 'rate_limited';
		}

		return 'http_' . $this->status;
	}

	/**
	 * Human-readable detail for the admin.
	 *
	 * @return string
	 */
	public function detail() {
		if ( $this->is_success() ) {
			return '';
		}

		if ( $this->local && $this->transport_error instanceof \WP_Error ) {
			return (string) $this->transport_error->get_error_message();
		}

		if ( $this->transport_error instanceof \WP_Error && 0 === $this->status ) {
			if ( $this->is_timeout() ) {
				return __( 'The SendFleet API did not answer in time. The email may or may not have been accepted, so it was not retried automatically.', 'sendfleet' );
			}
			return sprintf(
				/* translators: %s: transport error message. */
				__( 'Could not reach the SendFleet API: %s', 'sendfleet' ),
				$this->transport_error->get_error_message()
			);
		}

		if ( is_array( $this->body ) && isset( $this->body['detail'] ) && is_string( $this->body['detail'] ) ) {
			return $this->body['detail'];
		}

		if ( 400 === $this->status && is_array( $this->body ) ) {
			return self::flatten_validation_errors( $this->body );
		}

		if ( null === $this->body ) {
			return sprintf(
				/* translators: %d: HTTP status code. */
				__( 'The SendFleet API returned HTTP %d without a JSON body.', 'sendfleet' ),
				$this->status
			);
		}

		return sprintf(
			/* translators: %d: HTTP status code. */
			__( 'The SendFleet API returned HTTP %d.', 'sendfleet' ),
			$this->status
		);
	}

	/**
	 * Flattens the 400 field-error map into one line.
	 *
	 * @param array $errors Map of field to messages (or attachment index to messages).
	 * @return string
	 */
	public static function flatten_validation_errors( array $errors ) {
		$lines = array();

		foreach ( $errors as $field => $messages ) {
			if ( ! is_array( $messages ) ) {
				$lines[] = $field . ': ' . (string) $messages;
				continue;
			}

			foreach ( $messages as $index => $message ) {
				if ( is_array( $message ) ) {
					foreach ( $message as $inner ) {
						$lines[] = sprintf( '%s[%s]: %s', $field, $index, is_scalar( $inner ) ? (string) $inner : wp_json_encode( $inner ) );
					}
				} else {
					$lines[] = ( 'non_field_errors' === $field ? '' : $field . ': ' ) . (string) $message;
				}
			}
		}

		return implode( ' ', $lines );
	}

	/**
	 * Whether the transport failure was a timeout after the request was sent.
	 *
	 * @return bool
	 */
	public function is_timeout() {
		if ( ! ( $this->transport_error instanceof \WP_Error ) || 0 !== $this->status ) {
			return false;
		}

		if ( $this->is_connect_failure() ) {
			return false;
		}

		$message = strtolower( $this->transport_error->get_error_message() );

		return false !== strpos( $message, 'curl error 28' ) || false !== strpos( $message, 'timed out' ) || false !== strpos( $message, 'timeout' );
	}

	/**
	 * Whether the transport failure happened before the request could reach the API.
	 *
	 * @return bool
	 */
	private function is_connect_failure() {
		if ( ! ( $this->transport_error instanceof \WP_Error ) ) {
			return false;
		}

		$message = strtolower( $this->transport_error->get_error_message() );

		foreach ( array( 'curl error 5:', 'curl error 6:', 'curl error 7:', 'curl error 35:', 'curl error 60:', 'could not resolve', 'connection refused', 'failed to connect', 'ssl connect error', 'name or service not known', 'connection timed out', 'resolving timed out' ) as $needle ) {
			if ( false !== strpos( $message, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * How this response may be retried.
	 *
	 * @return string One of none, throttle, transient, server, ambiguous.
	 */
	public function retry_class() {
		if ( $this->is_success() ) {
			return 'none';
		}

		if ( 0 === $this->status ) {
			if ( $this->local ) {
				return $this->local_class;
			}
			if ( $this->is_connect_failure() ) {
				return 'transient';
			}
			return 'ambiguous';
		}

		$code = $this->error_code();

		if ( 429 === $this->status ) {
			return 'usage_limit_exceeded' === $code ? 'none' : 'throttle';
		}

		if ( 500 === $this->status ) {
			return $this->has_error_code() ? 'server' : 'transient';
		}

		if ( 502 === $this->status ) {
			return ( ! $this->has_error_code() || in_array( $code, array( 'queue_error', 'attachment_upload_failed' ), true ) ) ? 'transient' : 'none';
		}

		if ( 503 === $this->status ) {
			// 503 byoc_not_configured is terminal ("do not retry in a loop"); a bare 503 is the platform being unavailable.
			return $this->has_error_code() ? 'none' : 'transient';
		}

		if ( in_array( $this->status, array( 504, 520, 524 ), true ) ) {
			// Gateway timeouts: the origin may have accepted the request.
			return 'ambiguous';
		}

		if ( $this->status >= 500 ) {
			return 'transient';
		}

		return 'none';
	}

	/**
	 * Whether SendFleet refused a multi-recipient request because sending to
	 * several recipients at once is not available yet. The plugin then sends
	 * each recipient as its own message.
	 *
	 * @return bool
	 */
	public function is_multi_recipient_unavailable() {
		if ( 400 !== $this->status || ! is_array( $this->body ) || empty( $this->body['non_field_errors'] ) ) {
			return false;
		}

		foreach ( (array) $this->body['non_field_errors'] as $message ) {
			if ( is_string( $message ) && false !== stripos( $message, 'more than one recipient' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the error is the per-key rate limit.
	 *
	 * @return bool
	 */
	public function is_throttled() {
		return 'throttle' === $this->retry_class();
	}

	/**
	 * A field from the success body.
	 *
	 * @param string $key     Field name.
	 * @param mixed  $fallback Value when absent.
	 * @return mixed
	 */
	public function field( $key, $fallback = null ) {
		return is_array( $this->body ) && array_key_exists( $key, $this->body ) ? $this->body[ $key ] : $fallback;
	}
}
