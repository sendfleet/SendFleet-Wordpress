<?php
/**
 * Retry and background queue.
 *
 * @package SendFleet
 */

namespace SendFleet;

defined( 'ABSPATH' ) || exit;

/**
 * Sends queued rows from WP-Cron.
 *
 * Rows are queued when a send hit a retryable failure (rate limit, a 502
 * that the API says sent nothing, a 500) or, with background sending on,
 * for every email. Each row is claimed atomically before it is sent, so two
 * cron runs never send the same row twice.
 */
final class Queue {

	/**
	 * Largest request body kept for a retry.
	 */
	const MAX_RETRY_PAYLOAD = 8388608; // 8 MB.

	/**
	 * Largest request body sent in the background; bigger ones are sent immediately.
	 */
	const MAX_BACKGROUND_PAYLOAD = 2097152; // 2 MB.

	/**
	 * Rows processed per run.
	 */
	const BATCH = 50;

	/**
	 * A queued row older than this is not retried any more.
	 */
	const MAX_AGE = DAY_IN_SECONDS;

	/**
	 * Whether this request queued something that is due now.
	 *
	 * @var bool
	 */
	private static $spawn = false;

	/**
	 * Registers cron callbacks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( Installer::CRON_QUEUE, array( __CLASS__, 'process' ) );
		add_action( Installer::CRON_MAINTENANCE, array( __CLASS__, 'maintenance' ) );
		add_action( Installer::CRON_REFRESH, array( __CLASS__, 'refresh_account' ) );
	}

	/**
	 * Maximum attempts per retry class.
	 *
	 * @param string $retry_class Retry class.
	 * @return int
	 */
	public static function max_attempts( $retry_class ) {
		switch ( $retry_class ) {
			case 'throttle':
				return 30;
			case 'transient':
				return 5;
			case 'server':
				return 2;
		}

		return 1;
	}

	/**
	 * When to try again.
	 *
	 * @param Api_Response $response Last response.
	 * @param int          $attempts Attempts made so far.
	 * @return int Unix time.
	 */
	public static function next_attempt( Api_Response $response, $attempts ) {
		$class = $response->retry_class();

		if ( 'throttle' === $class ) {
			$wait = null !== $response->retry_after ? $response->retry_after : 60 - ( time() % 60 );
			return time() + max( 1, $wait ) + wp_rand( 0, 3 );
		}

		if ( 'server' === $class ) {
			return time() + 5;
		}

		// Transient: 2, 4, 8, 16 seconds (cron resolution permitting).
		return time() + (int) min( 60, pow( 2, max( 1, (int) $attempts ) ) );
	}

	/**
	 * Whether a request is small enough to keep in the queue.
	 *
	 * @param string $json  Encoded payload.
	 * @param bool   $is_bg Background send (stricter limit).
	 * @return bool
	 */
	public static function fits( $json, $is_bg ) {
		return false !== $json && strlen( $json ) <= ( $is_bg ? self::MAX_BACKGROUND_PAYLOAD : self::MAX_RETRY_PAYLOAD );
	}

	/**
	 * Encodes a queue payload.
	 *
	 * @param array $request  Request body.
	 * @param array $fallback Fallback sender meta.
	 * @return string|false
	 */
	public static function encode( array $request, array $fallback ) {
		return wp_json_encode(
			array(
				'request'  => $request,
				'fallback' => $fallback,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
	}

	/**
	 * Adds one message to the queue: one row per recipient.
	 *
	 * The first row leads: it carries the payload and the schedule, and the
	 * queue sends it. The others carry neither; they share its `mail_id` and
	 * take its outcome.
	 *
	 * @param array[] $rows    Log columns per recipient, all with the same mail_id.
	 * @param string  $payload Encoded payload.
	 * @param int     $when    When to send (unix time).
	 * @return int Lead row id, 0 on failure.
	 */
	public static function enqueue( array $rows, $payload, $when ) {
		$rows = array_values( $rows );

		if ( empty( $rows ) ) {
			return 0;
		}

		$lead                    = $rows[0];
		$lead['status']          = Log::STATUS_QUEUED;
		$lead['payload']         = $payload;
		$lead['next_attempt_at'] = gmdate( 'Y-m-d H:i:s', (int) $when );

		$id = Log::insert( $lead );

		if ( ! $id ) {
			return 0;
		}

		foreach ( array_slice( $rows, 1 ) as $row ) {
			$row['status'] = Log::STATUS_QUEUED;
			Log::insert( $row );
		}

		self::schedule( $when );

		return $id;
	}

	/**
	 * Ensures a queue run is scheduled no later than $when.
	 *
	 * The new event is written before the old one is dropped, so a failure
	 * between the two writes leaves the hook with an event rather than with
	 * none. WordPress refuses a single event that duplicates one scheduled
	 * within ten minutes, so dropping the old event is followed by a repair in
	 * case that refusal left the hook empty.
	 *
	 * @param int $when Unix time.
	 * @return void
	 */
	public static function schedule( $when ) {
		$when = max( time(), (int) $when );
		$next = wp_next_scheduled( Installer::CRON_QUEUE );

		if ( false !== $next && $next <= $when ) {
			return;
		}

		wp_schedule_single_event( $when, Installer::CRON_QUEUE );

		if ( false !== $next ) {
			wp_unschedule_event( $next, Installer::CRON_QUEUE );
		}

		if ( false === wp_next_scheduled( Installer::CRON_QUEUE ) ) {
			wp_schedule_single_event( $when, Installer::CRON_QUEUE );
		}

		if ( $when <= time() + 5 && ! self::$spawn ) {
			self::$spawn = true;
			add_action( 'shutdown', array( __CLASS__, 'spawn' ) );
		}
	}

	/**
	 * Kicks WP-Cron at the end of the request so queued mail goes out promptly.
	 *
	 * @return void
	 */
	public static function spawn() {
		if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) || ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/**
	 * Processes due rows. Cron callback.
	 *
	 * @param int $max_rows Optional cap on rows (WP-CLI).
	 * @return array Counts: sent, retry, failed.
	 */
	public static function process( $max_rows = self::BATCH ) {
		$counts = array(
			'sent'   => 0,
			'retry'  => 0,
			'failed' => 0,
		);

		if ( ! self::lock() ) {
			return $counts;
		}

		$started  = time();
		$deadline = $started + 25;

		try {
			foreach ( Log::due( max( 1, (int) $max_rows ) ) as $id ) {
				if ( time() >= $deadline ) {
					break;
				}

				if ( State::breaker( 'throttle' ) || State::breaker( 'network' ) ) {
					break;
				}

				if ( ! Log::claim( $id ) ) {
					continue;
				}

				$outcome = self::send_row( $id );
				++$counts[ $outcome ];
			}
		} finally {
			self::unlock();
		}

		$next = Log::next_due();

		if ( null !== $next ) {
			foreach ( array( 'throttle', 'network' ) as $breaker ) {
				$open = State::breaker( $breaker );
				if ( $open ) {
					$next = max( $next, (int) $open['until'] + 1 );
				}
			}
			self::schedule( $next );
		}

		return $counts;
	}

	/**
	 * Sends one claimed row.
	 *
	 * @param int $id Row id.
	 * @return string sent, retry or failed.
	 */
	private static function send_row( $id ) {
		$row     = Log::find( $id );
		$payload = $row && ! empty( $row['payload'] ) ? json_decode( (string) $row['payload'], true ) : null;

		if ( ! is_array( $payload ) || empty( $payload['request'] ) || ! is_array( $payload['request'] ) ) {
			self::finish(
				$id,
				array(
					'status'        => Log::STATUS_FAILED,
					'error_code'    => 'payload_missing',
					'error_message' => __( 'The queued email could not be read back from the database.', 'sendfleet' ),
				)
			);
			return 'failed';
		}

		$response = Transport::send( $payload['request'], false );
		$attempts = (int) $row['attempts'] + max( 1, Transport::$last_attempts );
		$class    = $response->retry_class();

		if ( $response->is_success() ) {
			$columns    = Dispatcher::success_columns( $response ) + array( 'attempts' => $attempts );
			$recipients = 1 + count( Log::followers( (string) $row['mail_id'], $id ) );

			Account::note_sent( $columns['mode'], $recipients );
			self::finish( $id, $columns );
			return 'sent';
		}

		$age = time() - (int) strtotime( $row['created_at'] . ' UTC' );

		if ( in_array( $class, array( 'throttle', 'transient', 'server' ), true ) && $attempts < self::max_attempts( $class ) && $age < self::MAX_AGE ) {
			// A locally detected rate limit made no API call; it does not use up an attempt.
			$counted = $response->local ? (int) $row['attempts'] : $attempts;

			$progress = array(
				'attempts'      => $counted,
				'retry_class'   => $class,
				'http_status'   => $response->status,
				'error_code'    => $response->error_code(),
				'error_message' => $response->detail(),
			);

			Log::update(
				$id,
				$progress + array(
					'status'          => Log::STATUS_QUEUED,
					'next_attempt_at' => gmdate( 'Y-m-d H:i:s', self::next_attempt( $response, $attempts ) ),
					'locked_until'    => null,
				)
			);
			foreach ( Log::followers( (string) $row['mail_id'], $id ) as $follower ) {
				Log::update( $follower, $progress );
			}
			return 'retry';
		}

		$columns = Dispatcher::failure_columns( $response ) + array( 'attempts' => $attempts );

		if ( 'ambiguous' !== $class && Fallback::enabled() ) {
			$fallback = Fallback::send( $payload['request'], isset( $payload['fallback'] ) ? (array) $payload['fallback'] : array() );
			$columns  = Dispatcher::apply_fallback( $columns, $fallback );
		}

		self::finish( $id, $columns );

		return Log::STATUS_FALLBACK === $columns['status'] ? 'sent' : 'failed';
	}

	/**
	 * Moves a queued message — its lead row and every follower — to a final
	 * state, and drops the payload.
	 *
	 * @param int   $id      Lead row id (or a lone row).
	 * @param array $columns Final columns.
	 * @return void
	 */
	private static function finish( $id, array $columns ) {
		$columns['payload']         = null;
		$columns['next_attempt_at'] = null;
		$columns['locked_until']    = null;

		$row = Log::find( $id );
		$ids = array_merge( array( (int) $id ), $row && '' !== $row['mail_id'] ? Log::followers( $row['mail_id'], $id ) : array() );

		if ( ! Settings::get( 'log_enabled' ) ) {
			Log::delete( $ids );
			return;
		}

		foreach ( $ids as $row_id ) {
			Log::update( $row_id, $columns );
		}
	}

	/**
	 * Takes the queue lock.
	 *
	 * @return bool
	 */
	private static function lock() {
		$key = 'sendfleet_queue_lock';

		if ( add_option( $key, (string) ( time() + 120 ), '', false ) ) {
			return true;
		}

		$until = (int) get_option( $key );

		if ( $until < time() ) {
			// Stale lock from a process that died.
			update_option( $key, (string) ( time() + 120 ), false );
			return true;
		}

		return false;
	}

	/**
	 * Releases the queue lock.
	 *
	 * @return void
	 */
	private static function unlock() {
		delete_option( 'sendfleet_queue_lock' );
	}

	/**
	 * Hourly housekeeping. Cron callback.
	 *
	 * @return void
	 */
	public static function maintenance() {
		Installer::maybe_upgrade();

		// Rows whose sender died mid-request: the API may or may not have the email.
		foreach ( Log::stale_sending() as $id ) {
			self::finish(
				$id,
				array(
					'status'        => Log::STATUS_FAILED,
					'error_code'    => 'interrupted',
					'error_message' => __( 'Sending was interrupted (the PHP process stopped). The email may or may not have been accepted, so it was not retried.', 'sendfleet' ),
				)
			);
		}

		// Followers whose lead was deleted: nothing will ever send them.
		foreach ( Log::orphaned_followers() as $id ) {
			Log::update(
				$id,
				array(
					'status'        => Log::STATUS_FAILED,
					'error_code'    => 'payload_missing',
					'error_message' => __( 'This recipient\'s queued email was cancelled or lost before it was sent.', 'sendfleet' ),
				)
			);
		}

		if ( Settings::get( 'log_enabled' ) ) {
			Log::purge_older_than( Settings::log_retention() );
		} else {
			Log::purge_all();
		}

		$next = Log::next_due();

		if ( null !== $next ) {
			self::schedule( $next );
		}

		if ( Account::age() > 12 * HOUR_IN_SECONDS ) {
			Account::maybe_refresh( 12 * HOUR_IN_SECONDS );
		}
	}

	/**
	 * Deferred account refresh. Cron callback.
	 *
	 * @return void
	 */
	public static function refresh_account() {
		if ( '' !== Settings::api_key() ) {
			Account::refresh();
		}
	}
}
