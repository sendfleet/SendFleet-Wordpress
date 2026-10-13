<?php
/**
 * Sends a prepared envelope, one request per recipient.
 *
 * @package SendFleet
 */

namespace SendFleet;

defined( 'ABSPATH' ) || exit;

/**
 * Turns an Envelope into API requests and log rows.
 *
 * Per recipient the outcome is one of:
 *
 * - accepted  SendFleet accepted and queued the email (200).
 * - queued    Waiting in the local queue (background sending, or a retryable failure).
 * - fallback  SendFleet could not take it; sent through WordPress' own mailer instead.
 * - skipped   Not sent on purpose (suppressed address, smoke-test recipient, invalid address).
 * - failed    Not sent.
 */
final class Dispatcher {

	/**
	 * Send immediately even when background sending is on (test emails).
	 *
	 * @var bool
	 */
	public static $force_immediate = false;

	/**
	 * Sends every recipient of an envelope.
	 *
	 * @param Envelope $envelope Prepared message.
	 * @return array[] One result per recipient: email, status, code, detail.
	 */
	public static function dispatch( Envelope $envelope ) {
		$results     = array();
		$log_enabled = (bool) Settings::get( 'log_enabled' );
		$background  = (bool) Settings::get( 'background' ) && ! self::$force_immediate;
		$notes       = implode( "\n", $envelope->notes );

		foreach ( $envelope->recipients as $recipient ) {
			$email = $recipient['email'];
			$base  = array(
				'mail_id'        => $envelope->mail_id,
				'recipient'      => $email,
				'recipient_kind' => $recipient['kind'],
				'from_email'     => $envelope->from_email,
				'subject'        => $envelope->subject,
				'route'          => $envelope->route,
				'attachments'    => count( $envelope->attachments ),
				'source'         => $envelope->source,
				'notes'          => $notes,
			);

			// Not sent on purpose.
			if ( '' !== $recipient['skip'] ) {
				$columns = array(
					'status'        => Log::STATUS_SKIPPED,
					'error_code'    => $recipient['skip'],
					'error_message' => $recipient['skip_detail'],
				);

				if ( Envelope::SKIP_SMOKE === $recipient['skip'] && Fallback::enabled() ) {
					$columns = self::apply_fallback( $columns, Fallback::send( $envelope->request_for( $email, false ), $envelope->fallback_meta() ) );
				}

				if ( $log_enabled ) {
					Log::insert( $base + $columns );
				}

				$results[] = self::result( $email, $columns );
				continue;
			}

			$request = $envelope->request_for( $email );

			// Background sending: queue now, send from cron.
			if ( $background ) {
				$payload = Queue::encode( $request, $envelope->fallback_meta() );

				if ( Queue::fits( $payload, true ) && Queue::enqueue( $base, $payload, time() ) ) {
					$results[] = self::result( $email, array( 'status' => Log::STATUS_QUEUED ) );
					continue;
				}
			}

			$response = Transport::send( $request, true );
			$attempts = max( 1, Transport::$last_attempts );

			if ( $response->is_success() ) {
				$columns = self::success_columns( $response ) + array( 'attempts' => $attempts );

				Account::note_sent( $columns['mode'] );

				if ( $log_enabled ) {
					Log::insert( $base + $columns );
				}

				$results[] = self::result( $email, $columns );
				continue;
			}

			$class = $response->retry_class();

			// Retryable: hand it to the queue rather than wait inside this request.
			if ( in_array( $class, array( 'throttle', 'transient', 'server' ), true ) && $attempts < Queue::max_attempts( $class ) ) {
				$payload = Queue::encode( $request, $envelope->fallback_meta() );

				if ( Queue::fits( $payload, false ) ) {
					$queued = Queue::enqueue(
						$base + array(
							'attempts'      => $response->local ? 0 : $attempts,
							'retry_class'   => $class,
							'http_status'   => $response->status,
							'error_code'    => $response->error_code(),
							'error_message' => $response->detail(),
						),
						$payload,
						Queue::next_attempt( $response, $attempts )
					);

					if ( $queued ) {
						$results[] = self::result(
							$email,
							array(
								'status'        => Log::STATUS_QUEUED,
								'error_code'    => $response->error_code(),
								'error_message' => $response->detail(),
							)
						);
						continue;
					}
				}
			}

			$columns = self::failure_columns( $response ) + array( 'attempts' => $response->local ? 0 : $attempts );

			if ( 'ambiguous' !== $class && Fallback::enabled() ) {
				$columns = self::apply_fallback( $columns, Fallback::send( $request, $envelope->fallback_meta() ) );
			}

			if ( $log_enabled ) {
				Log::insert( $base + $columns );
			}

			$results[] = self::result( $email, $columns );
		}

		/**
		 * Fires after SendFleet has handled every recipient of an email.
		 *
		 * @param array[]  $results  One entry per recipient: email, status, code, detail.
		 * @param Envelope $envelope Prepared message.
		 */
		do_action( 'sendfleet_dispatched', $results, $envelope );

		return $results;
	}

	/**
	 * Log columns for an accepted send.
	 *
	 * @param Api_Response $response Successful response.
	 * @return array
	 */
	public static function success_columns( Api_Response $response ) {
		$warnings = $response->field( 'warnings', array() );

		return array(
			'status'          => Log::STATUS_ACCEPTED,
			'http_status'     => 200,
			'mode'            => sanitize_key( (string) $response->field( 'mode', '' ) ),
			'message_id'      => sanitize_text_field( (string) $response->field( 'message_id', '' ) ),
			'idempotency_key' => sanitize_text_field( (string) $response->field( 'idempotency_key', '' ) ),
			'error_code'      => '',
			'error_message'   => is_array( $warnings ) ? implode( "\n", array_map( 'sanitize_text_field', array_filter( $warnings, 'is_string' ) ) ) : '',
		);
	}

	/**
	 * Log columns for a failed send.
	 *
	 * @param Api_Response $response Failed response.
	 * @return array
	 */
	public static function failure_columns( Api_Response $response ) {
		$detail = $response->detail();

		if ( '' !== $response->request_id ) {
			/* translators: %s: request id. */
			$detail .= ' ' . sprintf( __( '(request id %s)', 'sendfleet' ), $response->request_id );
		}

		return array(
			'status'        => Log::STATUS_FAILED,
			'http_status'   => $response->status,
			'error_code'    => $response->error_code(),
			'error_message' => trim( $detail ),
			'retry_class'   => $response->retry_class(),
		);
	}

	/**
	 * Folds a fallback outcome into log columns.
	 *
	 * @param array          $columns  Columns describing why SendFleet did not send.
	 * @param true|\WP_Error $fallback Fallback result.
	 * @return array
	 */
	public static function apply_fallback( array $columns, $fallback ) {
		if ( true === $fallback ) {
			$columns['status']        = Log::STATUS_FALLBACK;
			$columns['error_message'] = trim( $columns['error_message'] . "\n" . __( 'Sent through WordPress\' default mailer instead (fallback is on).', 'sendfleet' ) );
			return $columns;
		}

		$columns['status'] = Log::STATUS_SKIPPED === $columns['status'] ? Log::STATUS_SKIPPED : Log::STATUS_FAILED;

		if ( is_wp_error( $fallback ) ) {
			/* translators: %s: error message. */
			$columns['error_message'] = trim( $columns['error_message'] . "\n" . sprintf( __( 'The WordPress fallback also failed: %s', 'sendfleet' ), $fallback->get_error_message() ) );
		}

		return $columns;
	}

	/**
	 * A result entry from log columns.
	 *
	 * @param string $email   Recipient.
	 * @param array  $columns Columns.
	 * @return array
	 */
	private static function result( $email, array $columns ) {
		return array(
			'email'      => $email,
			'status'     => $columns['status'],
			'code'       => isset( $columns['error_code'] ) ? (string) $columns['error_code'] : '',
			'detail'     => isset( $columns['error_message'] ) ? (string) $columns['error_message'] : '',
			'mode'       => isset( $columns['mode'] ) ? (string) $columns['mode'] : '',
			'message_id' => isset( $columns['message_id'] ) ? (string) $columns['message_id'] : '',
		);
	}
}
