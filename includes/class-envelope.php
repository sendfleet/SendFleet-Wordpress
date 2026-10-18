<?php
/**
 * A message prepared for SendFleet.
 *
 * @package SendFleet
 */

namespace SendFleet;

defined( 'ABSPATH' ) || exit;

/**
 * The message after SendFleet's sending rules are applied: a sender on a
 * verified domain, one entry per recipient (To, Cc and Bcc, de-duplicated;
 * the Dispatcher sends them together, up to 50 per message, and never more
 * than one in To), attachments that
 * will pass validation, and the Free plan smoke-test restrictions.
 */
final class Envelope {

	const SKIP_INVALID  = 'invalid_recipient';
	const SKIP_SUPPRESS = 'suppressed_locally';
	const SKIP_SMOKE    = 'smoke_test_recipient';
	const MAX_SUBJECT   = 256;
	const MAX_FROM_NAME = 156;

	/**
	 * Groups the per-recipient rows of one wp_mail() call.
	 *
	 * @var string
	 */
	public $mail_id = '';

	/**
	 * What called wp_mail(), e.g. plugin:woocommerce.
	 *
	 * @var string
	 */
	public $source = '';

	/**
	 * Sender address used for the API request.
	 *
	 * @var string
	 */
	public $from_email = '';

	/**
	 * Sender name.
	 *
	 * @var string
	 */
	public $from_name = '';

	/**
	 * Reply-To address ('' for none).
	 *
	 * @var string
	 */
	public $reply_to = '';

	/**
	 * Subject.
	 *
	 * @var string
	 */
	public $subject = '';

	/**
	 * Plain-text body.
	 *
	 * @var string
	 */
	public $text = '';

	/**
	 * HTML body.
	 *
	 * @var string
	 */
	public $html = '';

	/**
	 * Attachment payload entries.
	 *
	 * @var array[]
	 */
	public $attachments = array();

	/**
	 * Route of the sender domain (Account::ROUTE_*), or '' when unknown.
	 *
	 * @var string
	 */
	public $route = '';

	/**
	 * Things changed or left out, shown in the log.
	 *
	 * @var string[]
	 */
	public $notes = array();

	/**
	 * Recipients: email, kind (to/cc/bcc), skip (reason code or ''), skip_detail.
	 *
	 * @var array[]
	 */
	public $recipients = array();

	/**
	 * Sender and Reply-To as WordPress would have used them (for the fallback path).
	 *
	 * @var array
	 */
	public $original = array();

	/**
	 * Data for the wp_mail_succeeded / wp_mail_failed actions.
	 *
	 * @var array
	 */
	public $mail_data = array();

	/**
	 * Applies the sending rules to a built message.
	 *
	 * @param array  $message Output of Message_Builder::build().
	 * @param string $source  Caller.
	 * @return self
	 */
	public static function from_message( array $message, $source ) {
		$envelope            = new self();
		$envelope->mail_id   = str_replace( '-', '', wp_generate_uuid4() );
		$envelope->source    = (string) $source;
		$envelope->mail_data = isset( $message['mail_data'] ) ? (array) $message['mail_data'] : array();

		$original_from = trim( (string) $message['from_email'] );
		$original_name = trim( (string) $message['from_name'] );
		$reply_to      = '';

		foreach ( (array) $message['reply_to'] as $candidate ) {
			$candidate = trim( (string) $candidate );
			if ( is_email( $candidate ) ) {
				$reply_to = $candidate;
				break;
			}
		}

		$envelope->original = array(
			'from_email' => $original_from,
			'from_name'  => $original_name,
			'reply_to'   => $reply_to,
		);

		// Sender address.
		$from       = $original_from;
		$configured = Settings::from_email();
		$forced     = Settings::get( 'force_from' ) ? Account::default_sender() : '';

		if ( '' !== $forced ) {
			$from = $forced;
		} elseif ( Account::is_known() ) {
			if ( null === Account::route_for( $from ) ) {
				$default = Account::default_sender();
				if ( '' !== $default ) {
					$from = $default;
				}
			}
		} elseif ( '' !== $configured && Account::domain_of( $from ) !== Account::domain_of( $configured ) ) {
			$from = $configured;
		}

		/**
		 * Filters the sender address used for a SendFleet request.
		 *
		 * The domain must be a verified sending domain on the SendFleet account.
		 *
		 * @param string $from          Sender address chosen by the plugin.
		 * @param string $original_from Sender address WordPress would have used.
		 */
		$from = (string) apply_filters( 'sendfleet_from_email', $from, $original_from );

		if ( strtolower( $from ) !== strtolower( $original_from ) ) {
			if ( '' !== $forced && $from === $forced ) {
				/* translators: 1: original sender, 2: sender used. */
				$envelope->notes[] = sprintf( __( 'Sender changed from %1$s to %2$s (Force sender address is on).', 'sendfleet' ), $original_from, $from );
			} else {
				/* translators: 1: original sender, 2: sender used. */
				$envelope->notes[] = sprintf( __( 'Sender %1$s is not on a verified SendFleet domain; sent from %2$s instead.', 'sendfleet' ), $original_from, $from );
			}

			// Keep replies going where the original sender intended.
			if ( '' === $reply_to && is_email( $original_from ) && ! self::is_core_default_sender( $original_from ) ) {
				$reply_to = $original_from;
				/* translators: %s: email address. */
				$envelope->notes[] = sprintf( __( 'Reply-To set to the original sender, %s.', 'sendfleet' ), $original_from );
			}
		}

		if ( '' === $reply_to ) {
			$default_reply = (string) Settings::get( 'reply_to' );
			if ( is_email( $default_reply ) ) {
				$reply_to = $default_reply;
			}
		}

		// Sender name.
		$name = $original_name;

		if ( Settings::get( 'force_from_name' ) || '' === $name || 'WordPress' === $name ) {
			$name = Settings::from_name();
		}

		/**
		 * Filters the sender name used for a SendFleet request.
		 *
		 * @param string $name          Sender name chosen by the plugin.
		 * @param string $original_name Sender name WordPress would have used.
		 */
		$name = (string) apply_filters( 'sendfleet_from_name', $name, $original_name );

		$envelope->from_email = $from;
		$envelope->from_name  = self::limit( self::strip_controls( $name ), self::MAX_FROM_NAME );
		$envelope->reply_to   = $reply_to;
		$envelope->subject    = self::limit( preg_replace( '/ {2,}/', ' ', self::strip_controls( (string) $message['subject'], ' ' ) ), self::MAX_SUBJECT );
		$envelope->text       = (string) $message['text'];
		$envelope->html       = (string) $message['html'];

		$route           = Account::is_known() ? Account::route_for( $from ) : null;
		$envelope->route = null === $route ? '' : $route;

		// Attachments.
		$prepared              = Attachments::prepare( (array) $message['attachments'] );
		$envelope->attachments = $prepared['attachments'];

		if ( ! empty( $message['custom_headers'] ) ) {
			/* translators: %s: list of header names. */
			$envelope->notes[] = sprintf( __( 'Custom headers are not supported by the SendFleet API and were not sent: %s', 'sendfleet' ), implode( ', ', array_unique( (array) $message['custom_headers'] ) ) );
		}

		foreach ( (array) $message['unreadable'] as $file ) {
			/* translators: %s: file name. */
			$envelope->notes[] = sprintf( __( 'Attachment left out: %s could not be read.', 'sendfleet' ), $file );
		}
		foreach ( $prepared['skipped'] as $reason ) {
			/* translators: %s: reason. */
			$envelope->notes[] = sprintf( __( 'Attachment left out: %s', 'sendfleet' ), $reason );
		}

		// Recipients: one send each, de-duplicated.
		$seen           = array();
		$account_email  = Account::email();
		$check_suppress = (bool) Settings::get( 'suppression_enabled' );

		$candidates = array_merge( (array) $message['recipients'], isset( $message['rejected'] ) ? (array) $message['rejected'] : array() );

		foreach ( $candidates as $recipient ) {
			$kind  = isset( $recipient[0] ) ? (string) $recipient[0] : 'to';
			$email = isset( $recipient[1] ) ? trim( (string) $recipient[1] ) : '';
			$key   = strtolower( $email );

			if ( '' === $email || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$entry = array(
				'email'       => $email,
				'kind'        => in_array( $kind, array( 'to', 'cc', 'bcc' ), true ) ? $kind : 'to',
				'skip'        => '',
				'skip_detail' => '',
			);

			if ( ! is_email( $email ) ) {
				$entry['skip']        = self::SKIP_INVALID;
				$entry['skip_detail'] = __( 'Not a valid email address.', 'sendfleet' );
			} elseif ( $check_suppress && Suppressions::is_suppressed( $email ) ) {
				$entry['skip']        = self::SKIP_SUPPRESS;
				$entry['skip_detail'] = __( 'The address is on this site\'s SendFleet suppression list (an earlier hard bounce or spam complaint).', 'sendfleet' );
			} elseif ( Account::ROUTE_SMOKE === $envelope->route ) {
				if ( '' !== $account_email && strtolower( $email ) === strtolower( $account_email ) ) {
					// The smoke-test gate compares the local part exactly; use the account's own spelling.
					$entry['email'] = $account_email;
				} else {
					$entry['skip']        = self::SKIP_SMOKE;
					$entry['skip_detail'] = sprintf(
						/* translators: %s: SendFleet account email. */
						__( 'On the Free plan, a Shared SES domain is a smoke test: it only delivers to your SendFleet account address (%s). Add a BYOC domain or choose a Managed plan to email anyone else.', 'sendfleet' ),
						$account_email
					);
				}
			}

			$envelope->recipients[] = $entry;
		}

		/**
		 * Filters the prepared envelope before anything is sent.
		 *
		 * @param Envelope $envelope Prepared message.
		 * @param array    $message  Message as WordPress assembled it.
		 */
		$filtered = apply_filters( 'sendfleet_envelope', $envelope, $message );

		return $filtered instanceof self ? $filtered : $envelope;
	}

	/**
	 * Whether the message has nothing to send.
	 *
	 * @return bool
	 */
	public function is_empty() {
		return '' === trim( $this->text ) && '' === trim( $this->html ) && empty( $this->attachments );
	}

	/**
	 * Whether a Reply-To is allowed on the smoke-test route.
	 *
	 * Only empty, the account's own address or the sender address are accepted.
	 *
	 * @return bool
	 */
	private function smoke_reply_to_allowed() {
		if ( '' === $this->reply_to ) {
			return true;
		}

		$reply = Account::normalize_address( $this->reply_to );

		return Account::normalize_address( Account::email() ) === $reply || Account::normalize_address( $this->from_email ) === $reply;
	}

	/**
	 * The POST /v1/send body for one recipient, as the message's only `to`.
	 *
	 * @param string $to          Recipient.
	 * @param bool   $route_rules Apply the smoke-test rules (false for the WordPress fallback).
	 * @return array
	 */
	public function request_for( $to, $route_rules = true ) {
		return $this->request_for_group(
			array(
				array(
					'email' => $to,
					'kind'  => 'to',
				),
			),
			$route_rules
		);
	}

	/**
	 * The POST /v1/send body for a group of recipients sent as one message.
	 *
	 * `to` stays a plain string when the group has a single `to`, so a
	 * one-recipient request is the shape it always was; `cc` and `bcc` are
	 * present only when used. The group must contain at least one `to`.
	 *
	 * @param array[] $group       Each: email, kind (to, cc or bcc).
	 * @param bool    $route_rules Apply the smoke-test rules (false for the WordPress fallback).
	 * @return array
	 */
	public function request_for_group( array $group, $route_rules = true ) {
		$lists = array(
			'to'  => array(),
			'cc'  => array(),
			'bcc' => array(),
		);

		foreach ( $group as $member ) {
			$kind             = isset( $lists[ $member['kind'] ] ) ? $member['kind'] : 'to';
			$lists[ $kind ][] = $member['email'];
		}

		$smoke     = $route_rules && Account::ROUTE_SMOKE === $this->route;
		$from_name = $this->from_name;
		$reply_to  = $this->reply_to;
		$text      = $this->text;
		$html      = $this->html;

		if ( $smoke ) {
			// The smoke test refuses names that could carry an address.
			$from_name = trim( str_replace( array( '<', '>', '@', ',', ';', '"' ), '', $from_name ) );

			if ( ! $this->smoke_reply_to_allowed() ) {
				// e.g. a contact form's visitor address: keep it visible in the message instead.
				$note = sprintf(
					/* translators: %s: email address. */
					__( 'Reply to: %s (shown here because the SendFleet Free plan smoke test only allows your own address as Reply-To)', 'sendfleet' ),
					$reply_to
				);

				if ( '' !== $text ) {
					$text = $note . "\n\n" . $text;
				}
				if ( '' !== $html ) {
					$block = '<p style="margin:0 0 16px;padding:8px 12px;background:#fff8e6;border-left:3px solid #f5a623;font-family:Arial,sans-serif;font-size:13px;color:#333;">' . esc_html( $note ) . '</p>';
					$html  = preg_match( '/<body\b[^>]*>/i', $html ) ? preg_replace( '/(<body\b[^>]*>)/i', '$1' . str_replace( '$', '\\$', $block ), $html, 1 ) : $block . $html;
				}

				$reply_to = '';
			}
		}

		$request = array(
			'to'         => 1 === count( $lists['to'] ) ? $lists['to'][0] : $lists['to'],
			'from_email' => $this->from_email,
		);

		if ( ! empty( $lists['cc'] ) ) {
			$request['cc'] = $lists['cc'];
		}
		if ( ! empty( $lists['bcc'] ) ) {
			$request['bcc'] = $lists['bcc'];
		}

		if ( '' !== $from_name ) {
			$request['from_name'] = $from_name;
		}
		if ( '' !== $this->subject ) {
			$request['subject'] = $this->subject;
		}
		if ( '' !== trim( $text ) ) {
			$request['message'] = $text;
		}
		if ( '' !== trim( $html ) ) {
			$request['html'] = $html;
		}
		if ( '' !== $reply_to ) {
			$request['reply_to'] = $reply_to;
		}
		if ( ! empty( $this->attachments ) ) {
			$request['attachments'] = $this->attachments;
		}

		/**
		 * Filters the request body sent to POST /v1/send for one message.
		 *
		 * @param array    $request  Request body.
		 * @param Envelope $envelope Prepared message.
		 */
		return (array) apply_filters( 'sendfleet_request', $request, $this );
	}

	/**
	 * What the WordPress fallback should use as sender.
	 *
	 * @return array from_email, from_name, reply_to.
	 */
	public function fallback_meta() {
		return array(
			'from_email' => '' !== $this->original['from_email'] ? $this->original['from_email'] : $this->from_email,
			'from_name'  => '' !== $this->original['from_name'] ? $this->original['from_name'] : $this->from_name,
			'reply_to'   => '' !== $this->original['reply_to'] ? $this->original['reply_to'] : $this->reply_to,
		);
	}

	/**
	 * Whether an address is WordPress' generated default (wordpress@ the site domain).
	 *
	 * @param string $email Address.
	 * @return bool
	 */
	private static function is_core_default_sender( $email ) {
		$host = (string) wp_parse_url( network_home_url(), PHP_URL_HOST );
		$host = strtolower( preg_replace( '/^www\./i', '', $host ) );

		return strtolower( trim( $email ) ) === 'wordpress@' . $host;
	}

	/**
	 * Removes control characters (line breaks become the replacement).
	 *
	 * @param string $value       Value.
	 * @param string $replacement Replacement.
	 * @return string
	 */
	private static function strip_controls( $value, $replacement = '' ) {
		$clean = preg_replace( '/[\x00-\x1F\x7F]/u', $replacement, (string) $value );

		return null === $clean ? preg_replace( '/[\x00-\x1F\x7F]/', $replacement, (string) $value ) : $clean;
	}

	/**
	 * Truncates to a number of characters (code points).
	 *
	 * @param string $value Value.
	 * @param int    $max   Maximum characters.
	 * @return string
	 */
	private static function limit( $value, $max ) {
		$value = trim( (string) $value );

		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $value, 'UTF-8' ) > $max ? rtrim( mb_substr( $value, 0, $max - 1, 'UTF-8' ) ) . '…' : $value;
		}

		return strlen( $value ) > $max ? substr( $value, 0, $max ) : $value;
	}
}
