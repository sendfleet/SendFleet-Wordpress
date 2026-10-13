<?php
/**
 * Optional fallback to WordPress' own mailer.
 *
 * @package SendFleet
 */

namespace SendFleet;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's public properties are CamelCase.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Fires WordPress core mail hooks on purpose, exactly as wp_mail() does.

/**
 * When enabled, an email SendFleet could not take is handed to PHPMailer the
 * way WordPress would have sent it without this plugin (PHP mail(), or
 * whatever another plugin configures in `phpmailer_init`).
 *
 * Never used for timeouts: the API may already have accepted that message,
 * and sending it again could deliver it twice.
 */
final class Fallback {

	/**
	 * Whether the fallback is switched on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) Settings::get( 'fallback' );
	}

	/**
	 * Sends one request body through PHPMailer.
	 *
	 * @param array $request POST /v1/send body (with `to`, and `cc` / `bcc` when used).
	 * @param array $meta    Sender as WordPress would have used it: from_email, from_name, reply_to.
	 * @return true|\WP_Error
	 */
	public static function send( array $request, array $meta ) {
		try {
			$mailer = new Php_Mailer( true );

			$mailer::$validator = static function ( $email ) {
				return (bool) is_email( $email );
			};

			$from = ! empty( $meta['from_email'] ) ? (string) $meta['from_email'] : (string) $request['from_email'];
			$name = isset( $meta['from_name'] ) ? (string) $meta['from_name'] : ( isset( $request['from_name'] ) ? (string) $request['from_name'] : '' );

			$mailer->isMail();
			$mailer->CharSet = 'UTF-8';
			$mailer->setFrom( $from, $name, false );

			foreach ( (array) $request['to'] as $address ) {
				$mailer->addAddress( (string) $address );
			}
			foreach ( isset( $request['cc'] ) ? (array) $request['cc'] : array() as $address ) {
				$mailer->addCC( (string) $address );
			}
			foreach ( isset( $request['bcc'] ) ? (array) $request['bcc'] : array() as $address ) {
				$mailer->addBCC( (string) $address );
			}

			if ( ! empty( $meta['reply_to'] ) ) {
				$mailer->addReplyTo( (string) $meta['reply_to'] );
			} elseif ( ! empty( $request['reply_to'] ) ) {
				$mailer->addReplyTo( (string) $request['reply_to'] );
			}

			$mailer->Subject = isset( $request['subject'] ) ? (string) $request['subject'] : '';

			if ( ! empty( $request['html'] ) ) {
				$mailer->isHTML( true );
				$mailer->Body    = (string) $request['html'];
				$mailer->AltBody = isset( $request['message'] ) ? (string) $request['message'] : '';
			} else {
				$mailer->ContentType = 'text/plain';
				$mailer->Body        = isset( $request['message'] ) ? (string) $request['message'] : '';
			}

			foreach ( isset( $request['attachments'] ) ? (array) $request['attachments'] : array() as $attachment ) {
				$bytes = base64_decode( (string) $attachment['content'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Attachment content is stored base64-encoded.
				if ( false !== $bytes ) {
					$mailer->addStringAttachment( $bytes, (string) $attachment['filename'], 'base64', (string) $attachment['content_type'] );
				}
			}

			/** This action is documented in wp-includes/pluggable.php */
			do_action_ref_array( 'phpmailer_init', array( &$mailer ) );

			$mailer->send();

			return true;
		} catch ( \PHPMailer\PHPMailer\Exception $e ) {
			return new \WP_Error( 'fallback_failed', $e->getMessage() );
		} catch ( \Exception $e ) {
			return new \WP_Error( 'fallback_failed', $e->getMessage() );
		}
	}
}
