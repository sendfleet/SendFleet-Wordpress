<?php
/**
 * One send attempt against the API, with circuit breakers.
 *
 * @package SendFleet
 */

namespace SendFleet;

defined( 'ABSPATH' ) || exit;

/**
 * Sends one request, applying the documented retry strategy where waiting
 * inside the current PHP request is cheap, and opening circuit breakers for
 * failures that will repeat for every email.
 */
final class Transport {

	/**
	 * Most seconds one PHP request may spend sleeping between inline retries.
	 */
	const INLINE_BUDGET = 6;

	/**
	 * Seconds already spent on inline retries in this request.
	 *
	 * @var int
	 */
	private static $inline_spent = 0;

	/**
	 * API calls made by the last send().
	 *
	 * @var int
	 */
	public static $last_attempts = 0;

	/**
	 * Error codes that mean the cached account data may be out of date.
	 *
	 * @var string[]
	 */
	private static $account_codes = array( 'unauthorized', 'domain_not_registered', 'domain_not_allowed', 'domain_not_verified', 'dkim_not_verified', 'domain_mode_mismatch', 'usage_limit_exceeded', 'sending_suspended' );

	/**
	 * Sends one request.
	 *
	 * @param array $request POST /v1/send body.
	 * @param bool  $inline  Whether short inline retries are allowed.
	 * @return Api_Response
	 */
	public static function send( array $request, $inline = true ) {
		self::$last_attempts = 0;

		$blocked = self::blocked();

		if ( null !== $blocked ) {
			return $blocked;
		}

		$client   = new Api_Client();
		$started  = microtime( true );
		$response = $client->send( $request );

		++self::$last_attempts;

		// A slow failure is not retried inline: two in a row could outlast PHP's time limit.
		if ( $inline && microtime( true ) - $started < 5 ) {
			$class = $response->retry_class();
			$wait  = 0;

			if ( 'transient' === $class ) {
				$wait = 1;
			} elseif ( 'throttle' === $class && null !== $response->retry_after && $response->retry_after <= 2 ) {
				$wait = max( 1, $response->retry_after );
			}

			if ( $wait > 0 && self::$inline_spent + $wait <= self::INLINE_BUDGET ) {
				self::$inline_spent += $wait;
				sleep( $wait );
				$response = $client->send( $request );
				++self::$last_attempts;
			}
		}

		self::observe( $response );

		return $response;
	}

	/**
	 * Returns a local failure when an open breaker means the API call would fail anyway.
	 *
	 * @return Api_Response|null
	 */
	public static function blocked() {
		$auth = State::breaker( 'auth' );

		if ( $auth ) {
			return Api_Response::local_error(
				'invalid_api_key',
				sprintf(
					/* translators: %s: human-readable time difference. */
					__( 'Not sent: SendFleet rejected the API key %s ago. Check the key in SendFleet → Settings; sending resumes automatically once a valid key is saved.', 'sendfleet' ),
					human_time_diff( (int) $auth['since'] )
				)
			);
		}

		$suspended = State::breaker( 'suspended' );

		if ( $suspended ) {
			return Api_Response::local_error( 'sending_suspended', __( 'Not sent: sending is paused or suspended on the SendFleet account.', 'sendfleet' ) . ' ' . $suspended['detail'] );
		}

		$quota = State::breaker( 'quota' );

		if ( $quota ) {
			return Api_Response::local_error( 'usage_limit_exceeded', __( 'Not sent: the monthly SendFleet plan limit is used up.', 'sendfleet' ) . ' ' . $quota['detail'] );
		}

		$throttle = State::breaker( 'throttle' );

		if ( $throttle ) {
			return Api_Response::local_throttle( max( 1, (int) $throttle['until'] - time() ) );
		}

		if ( State::breaker( 'network' ) ) {
			return Api_Response::local_error( 'network_error', __( 'Waiting to retry: the SendFleet API did not respond to a recent request.', 'sendfleet' ), 'transient' );
		}

		return null;
	}

	/**
	 * Opens breakers and schedules an account refresh based on a response.
	 *
	 * @param Api_Response $response Response.
	 * @return void
	 */
	private static function observe( Api_Response $response ) {
		if ( $response->local || $response->is_success() ) {
			return;
		}

		$code = $response->error_code();

		switch ( $code ) {
			case 'invalid_api_key':
				State::trip_breaker( 'auth', 10 * MINUTE_IN_SECONDS, $response->detail() );
				break;
			case 'usage_limit_exceeded':
				State::trip_breaker( 'quota', max( 60, min( HOUR_IN_SECONDS, State::next_month_start_utc() - time() ) ), $response->detail() );
				break;
			case 'sending_suspended':
				State::trip_breaker( 'suspended', 15 * MINUTE_IN_SECONDS, $response->detail() );
				break;
		}

		if ( $response->is_throttled() ) {
			$wait = null !== $response->retry_after ? $response->retry_after : 60 - ( time() % 60 );
			State::trip_breaker( 'throttle', max( 1, $wait ), '' );
		}

		// The API did not answer (timeout or no connection): queue the next sends for a
		// minute instead of making every page that sends email wait for the timeout.
		if ( 0 === $response->status && in_array( $response->retry_class(), array( 'transient', 'ambiguous' ), true ) ) {
			State::trip_breaker( 'network', MINUTE_IN_SECONDS, $response->detail() );
		}

		if ( in_array( $code, self::$account_codes, true ) ) {
			Account::schedule_refresh();
		}

		if ( ! $response->is_throttled() ) {
			State::set(
				'last_error',
				array(
					'code'       => $code,
					'detail'     => $response->detail(),
					'status'     => $response->status,
					'request_id' => $response->request_id,
					'time'       => time(),
				)
			);
		}
	}
}
