<?php
/**
 * Sends a prepared envelope: one request per message, with To, Cc and Bcc.
 *
 * @package SendFleet
 */

namespace SendFleet;

defined( 'ABSPATH' ) || exit;

/**
 * Turns an Envelope into API requests and log rows.
 *
 * The recipients of one wp_mail() call go out as one SendFleet request — `to`,
 * `cc` and `bcc` together, at most 50 per message (SendFleet's and SES's
 * limit; more are split across several messages). Each recipient still gets
 * its own log row, because SendFleet counts, logs and reports every recipient
 * separately.
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
	 * Recipients per message: SendFleet's limit, which is SES's.
	 */
	const MAX_RECIPIENTS = 50;

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
		$sendable    = array();

		foreach ( $envelope->recipients as $recipient ) {
			if ( '' === $recipient['skip'] ) {
				$sendable[] = $recipient;
				continue;
			}

			// Not sent on purpose.
			$email   = $recipient['email'];
			$columns = array(
				'status'        => Log::STATUS_SKIPPED,
				'error_code'    => $recipient['skip'],
				'error_message' => $recipient['skip_detail'],
			);

			if ( Envelope::SKIP_SMOKE === $recipient['skip'] && Fallback::enabled() ) {
				$columns = self::apply_fallback( $columns, Fallback::send( $envelope->request_for( $email, false ), $envelope->fallback_meta() ) );
			}

			if ( $log_enabled ) {
				Log::insert( self::row_base( $envelope, $recipient, $envelope->mail_id ) + $columns );
			}

			$results[] = self::result( $email, $columns );
		}

		$groups = self::groups( $sendable );

		foreach ( $groups as $index => $group ) {
			// The rows of one message share a mail id; a second message gets its own.
			$mail_id = 0 === $index ? $envelope->mail_id : str_replace( '-', '', wp_generate_uuid4() );
			$results = array_merge( $results, self::send_group( $envelope, $group, $mail_id ) );
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
	 * Splits the recipients into messages.
	 *
	 * At most 50 per message. A message needs a `to`, so a chunk left with
	 * only Cc and Bcc recipients (every `to` was skipped) sends each of them
	 * as their own message's `to` — which also keeps a Bcc recipient out of
	 * every header. While SendFleet answers that several recipients are not
	 * available on this account yet, every recipient is its own message.
	 *
	 * @param array[] $recipients Envelope recipient entries.
	 * @return array[] Groups of entries with email and kind.
	 */
	private static function groups( array $recipients ) {
		if ( empty( $recipients ) ) {
			return array();
		}

		$single = (bool) State::breaker( 'single_recipient' );
		$groups = array();

		foreach ( array_chunk( $recipients, $single ? 1 : self::MAX_RECIPIENTS ) as $chunk ) {
			$kinds = wp_list_pluck( $chunk, 'kind' );

			if ( in_array( 'to', $kinds, true ) ) {
				$groups[] = $chunk;
				continue;
			}

			foreach ( $chunk as $member ) {
				$member['kind'] = 'to';
				$groups[]       = array( $member );
			}
		}

		return $groups;
	}

	/**
	 * Log columns every row of a recipient starts from.
	 *
	 * @param Envelope $envelope  Prepared message.
	 * @param array    $recipient Recipient entry.
	 * @param string   $mail_id   Message the row belongs to.
	 * @return array
	 */
	private static function row_base( Envelope $envelope, array $recipient, $mail_id ) {
		return array(
			'mail_id'        => $mail_id,
			'recipient'      => $recipient['email'],
			'recipient_kind' => $recipient['kind'],
			'from_email'     => $envelope->from_email,
			'subject'        => $envelope->subject,
			'route'          => $envelope->route,
			'attachments'    => count( $envelope->attachments ),
			'source'         => $envelope->source,
			'notes'          => implode( "\n", $envelope->notes ),
		);
	}

	/**
	 * Sends one message to a group of recipients.
	 *
	 * @param Envelope $envelope Prepared message.
	 * @param array[]  $group    Recipient entries, at least one of them a `to`.
	 * @param string   $mail_id  Message id for the group's rows.
	 * @return array[] One result per recipient.
	 */
	private static function send_group( Envelope $envelope, array $group, $mail_id ) {
		$log_enabled = (bool) Settings::get( 'log_enabled' );
		$background  = (bool) Settings::get( 'background' ) && ! self::$force_immediate;
		$request     = $envelope->request_for_group( $group );
		$rows        = array();

		foreach ( $group as $member ) {
			$rows[] = self::row_base( $envelope, $member, $mail_id );
		}

		// Background sending: queue now, send from cron.
		if ( $background ) {
			$payload = Queue::encode( $request, $envelope->fallback_meta() );

			if ( Queue::fits( $payload, true ) && Queue::enqueue( $rows, $payload, time() ) ) {
				return self::results_for( $group, array( 'status' => Log::STATUS_QUEUED ) );
			}
		}

		$response = Transport::send( $request, true );
		$attempts = max( 1, Transport::$last_attempts );

		if ( $response->is_success() ) {
			$columns = self::success_columns( $response ) + array( 'attempts' => $attempts );

			Account::note_sent( $columns['mode'], count( $group ) );

			if ( $log_enabled ) {
				foreach ( $rows as $row ) {
					Log::insert( $row + $columns );
				}
			}

			return self::results_for( $group, $columns );
		}

		// This account cannot send to several recipients at once yet: send them
		// one message each, and keep doing so for a while without asking again.
		if ( count( $group ) > 1 && $response->is_multi_recipient_unavailable() ) {
			State::trip_breaker( 'single_recipient', 12 * HOUR_IN_SECONDS, $response->detail() );

			$results = array();
			foreach ( $group as $index => $member ) {
				$member['kind'] = 'to';
				$id             = 0 === $index ? $mail_id : str_replace( '-', '', wp_generate_uuid4() );
				$results        = array_merge( $results, self::send_group( $envelope, array( $member ), $id ) );
			}
			return $results;
		}

		$class = $response->retry_class();

		// Retryable: hand it to the queue rather than wait inside this request.
		if ( in_array( $class, array( 'throttle', 'transient', 'server' ), true ) && $attempts < Queue::max_attempts( $class ) ) {
			$payload = Queue::encode( $request, $envelope->fallback_meta() );

			if ( Queue::fits( $payload, false ) ) {
				$columns         = array(
					'attempts'      => $response->local ? 0 : $attempts,
					'retry_class'   => $class,
					'http_status'   => $response->status,
					'error_code'    => $response->error_code(),
					'error_message' => $response->detail(),
				);
				$rows_with_error = array_map(
					static function ( $row ) use ( $columns ) {
						return $row + $columns;
					},
					$rows
				);

				if ( Queue::enqueue( $rows_with_error, $payload, Queue::next_attempt( $response, $attempts ) ) ) {
					return self::results_for(
						$group,
						array(
							'status'        => Log::STATUS_QUEUED,
							'error_code'    => $response->error_code(),
							'error_message' => $response->detail(),
						)
					);
				}
			}
		}

		$columns = self::failure_columns( $response ) + array( 'attempts' => $response->local ? 0 : $attempts );

		if ( 'ambiguous' !== $class && Fallback::enabled() ) {
			$columns = self::apply_fallback( $columns, Fallback::send( $request, $envelope->fallback_meta() ) );
		}

		if ( $log_enabled ) {
			foreach ( $rows as $row ) {
				Log::insert( $row + $columns );
			}
		}

		return self::results_for( $group, $columns );
	}

	/**
	 * The same result for every recipient of a group.
	 *
	 * @param array[] $group   Recipient entries.
	 * @param array   $columns Outcome columns.
	 * @return array[]
	 */
	private static function results_for( array $group, array $columns ) {
		$results = array();

		foreach ( $group as $member ) {
			$results[] = self::result( $member['email'], $columns );
		}

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
