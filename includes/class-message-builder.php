<?php
/**
 * Builds a message from wp_mail() arguments.
 *
 * @package SendFleet
 */

namespace SendFleet;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's public properties are CamelCase.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Fires WordPress core mail hooks on purpose, exactly as wp_mail() does.

/**
 * Assembles a message from the (already filtered) wp_mail() arguments the
 * same way core does, then reads the result back into a plain array.
 *
 * Header parsing, the `wp_mail_from`, `wp_mail_from_name`,
 * `wp_mail_content_type`, `wp_mail_charset` and `wp_mail_embed_args` filters
 * and the `phpmailer_init` action all behave as they do without this plugin.
 */
final class Message_Builder {

	/**
	 * Builds the message.
	 *
	 * @param array $atts wp_mail() arguments after the `wp_mail` filter.
	 * @return array|\WP_Error {
	 *     @type string     $from_email   Sender address.
	 *     @type string     $from_name    Sender name.
	 *     @type string[]   $reply_to     Reply-To addresses.
	 *     @type string     $subject      Subject.
	 *     @type string     $text         Plain-text body.
	 *     @type string     $html         HTML body ('' for plain-text mail).
	 *     @type array[]    $recipients   Each [kind, address, name].
	 *     @type array[]    $attachments  Each: filename, path|content, type.
	 *     @type array      $mail_data    The data passed to wp_mail_succeeded / wp_mail_failed.
	 *     @type Php_Mailer $phpmailer    The assembled PHPMailer object.
	 * }
	 */
	public static function build( array $atts ) {
		$to          = isset( $atts['to'] ) ? $atts['to'] : array();
		$subject     = isset( $atts['subject'] ) ? (string) $atts['subject'] : '';
		$message     = isset( $atts['message'] ) ? (string) $atts['message'] : '';
		$headers     = isset( $atts['headers'] ) ? $atts['headers'] : array();
		$attachments = isset( $atts['attachments'] ) ? $atts['attachments'] : array();
		$embeds      = isset( $atts['embeds'] ) ? $atts['embeds'] : array();

		if ( ! is_array( $to ) ) {
			$to = explode( ',', (string) $to );
		}

		if ( ! is_array( $attachments ) ) {
			$attachments = explode( "\n", str_replace( "\r\n", "\n", (string) $attachments ) );
		}

		if ( ! is_array( $embeds ) ) {
			$embeds = explode( "\n", str_replace( "\r\n", "\n", (string) $embeds ) );
		}

		$phpmailer = new Php_Mailer( true );

		$phpmailer::$validator = static function ( $email ) {
			return (bool) is_email( $email );
		};

		// Headers.
		$cc       = array();
		$bcc      = array();
		$reply_to = array();

		if ( empty( $headers ) ) {
			$headers = array();
		} else {
			if ( ! is_array( $headers ) ) {
				$tempheaders = explode( "\n", str_replace( "\r\n", "\n", (string) $headers ) );
			} else {
				$tempheaders = $headers;
			}
			$headers = array();

			if ( ! empty( $tempheaders ) ) {
				foreach ( (array) $tempheaders as $header ) {
					$header = (string) $header;

					if ( false === strpos( $header, ':' ) ) {
						if ( false !== stripos( $header, 'boundary=' ) ) {
							$parts    = preg_split( '/boundary=/i', trim( $header ) );
							$boundary = trim( str_replace( array( "'", '"' ), '', $parts[1] ) );
						}
						continue;
					}

					list( $name, $content ) = explode( ':', trim( $header ), 2 );

					$name    = trim( $name );
					$content = trim( $content );

					switch ( strtolower( $name ) ) {
						case 'from':
							$bracket_pos = strpos( $content, '<' );
							if ( false !== $bracket_pos ) {
								if ( $bracket_pos > 0 ) {
									$from_name = substr( $content, 0, $bracket_pos );
									$from_name = str_replace( '"', '', $from_name );
									$from_name = trim( $from_name );
								}

								$from_email = substr( $content, $bracket_pos + 1 );
								$from_email = str_replace( '>', '', $from_email );
								$from_email = trim( $from_email );
							} elseif ( '' !== trim( $content ) ) {
								$from_email = trim( $content );
							}
							break;
						case 'content-type':
							if ( false !== strpos( $content, ';' ) ) {
								list( $type, $charset_content ) = explode( ';', $content );
								$content_type                   = trim( $type );
								if ( false !== stripos( $charset_content, 'charset=' ) ) {
									$charset = trim( str_replace( array( 'charset=', '"' ), '', $charset_content ) );
								} elseif ( false !== stripos( $charset_content, 'boundary=' ) ) {
									$boundary = trim( str_replace( array( 'BOUNDARY=', 'boundary=', '"' ), '', $charset_content ) );
									$charset  = '';
									if ( preg_match( '~^multipart/(\S+)~', $content_type, $matches ) ) {
										$content_type = 'multipart/' . strtolower( $matches[1] ) . '; boundary="' . $boundary . '"';
									}
								}
							} elseif ( '' !== trim( $content ) ) {
								$content_type = trim( $content );
							}
							break;
						case 'cc':
							$cc = array_merge( (array) $cc, explode( ',', $content ) );
							break;
						case 'bcc':
							$bcc = array_merge( (array) $bcc, explode( ',', $content ) );
							break;
						case 'reply-to':
							$reply_to = array_merge( (array) $reply_to, explode( ',', $content ) );
							break;
						default:
							$headers[ trim( $name ) ] = trim( $content );
							break;
					}
				}
			}
		}

		$phpmailer->clearAllRecipients();
		$phpmailer->clearAttachments();
		$phpmailer->clearCustomHeaders();
		$phpmailer->clearReplyTos();
		$phpmailer->Body     = '';
		$phpmailer->AltBody  = '';
		$phpmailer->Encoding = \PHPMailer\PHPMailer\PHPMailer::ENCODING_8BIT;

		if ( ! isset( $from_name ) ) {
			$from_name = 'WordPress';
		}

		if ( ! isset( $from_email ) ) {
			$sitename   = wp_parse_url( network_home_url(), PHP_URL_HOST );
			$from_email = 'wordpress@';

			if ( null !== $sitename ) {
				if ( 0 === strpos( $sitename, 'www.' ) ) {
					$sitename = substr( $sitename, 4 );
				}

				$from_email .= $sitename;
			}
		}

		/** This filter is documented in wp-includes/pluggable.php */
		$from_email = apply_filters( 'wp_mail_from', $from_email );

		/** This filter is documented in wp-includes/pluggable.php */
		$from_name = apply_filters( 'wp_mail_from_name', $from_name );

		$mail_data = compact( 'to', 'subject', 'message', 'headers', 'attachments', 'embeds' );

		try {
			$phpmailer->setFrom( (string) $from_email, (string) $from_name, false );
		} catch ( \PHPMailer\PHPMailer\Exception $e ) {
			// Core gives up here. The sender is replaced with a verified one later
			// anyway, so fall back to that rather than lose the email.
			$fallback_from = Account::default_sender();
			$recovered     = false;

			if ( '' !== $fallback_from ) {
				try {
					$phpmailer->setFrom( $fallback_from, (string) $from_name, false );
					$recovered = true;
				} catch ( \PHPMailer\PHPMailer\Exception $inner ) {
					$recovered = false;
				}
			}

			if ( ! $recovered ) {
				$mail_data['phpmailer_exception_code'] = $e->getCode();
				return new \WP_Error( 'wp_mail_failed', $e->getMessage(), $mail_data );
			}
		}

		$phpmailer->Subject = $subject;
		$phpmailer->Body    = $message;

		$address_headers = compact( 'to', 'cc', 'bcc', 'reply_to' );
		$rejected        = array();

		foreach ( $address_headers as $address_header => $addresses ) {
			if ( empty( $addresses ) ) {
				continue;
			}

			foreach ( (array) $addresses as $address ) {
				try {
					$recipient_name = '';

					if ( preg_match( '/(.*)<(.+)>/', (string) $address, $matches ) ) {
						if ( count( $matches ) === 3 ) {
							$recipient_name = $matches[1];
							$address        = $matches[2];
						}
					}

					switch ( $address_header ) {
						case 'to':
							$phpmailer->addAddress( (string) $address, $recipient_name );
							break;
						case 'cc':
							$phpmailer->addCC( (string) $address, $recipient_name );
							break;
						case 'bcc':
							$phpmailer->addBCC( (string) $address, $recipient_name );
							break;
						case 'reply_to':
							$phpmailer->addReplyTo( (string) $address, $recipient_name );
							break;
					}
				} catch ( \PHPMailer\PHPMailer\Exception $e ) {
					// Core drops invalid addresses silently; keep them so the log can say so.
					if ( 'reply_to' !== $address_header && '' !== trim( (string) $address ) ) {
						$rejected[] = array( $address_header, trim( (string) $address ), '' );
					}
					continue;
				}
			}
		}

		$phpmailer->isMail();

		if ( ! isset( $content_type ) ) {
			$content_type = 'text/plain';
		}

		/** This filter is documented in wp-includes/pluggable.php */
		$content_type = apply_filters( 'wp_mail_content_type', $content_type );

		$phpmailer->ContentType = $content_type;

		if ( 'text/html' === $content_type ) {
			$phpmailer->isHTML( true );
		}

		if ( ! isset( $charset ) ) {
			$charset = get_bloginfo( 'charset' );
		}

		/** This filter is documented in wp-includes/pluggable.php */
		$phpmailer->CharSet = apply_filters( 'wp_mail_charset', $charset );

		if ( ! empty( $headers ) ) {
			foreach ( (array) $headers as $name => $content ) {
				if ( ! in_array( $name, array( 'MIME-Version', 'X-Mailer' ), true ) ) {
					try {
						$phpmailer->addCustomHeader( sprintf( '%1$s: %2$s', $name, $content ) );
					} catch ( \PHPMailer\PHPMailer\Exception $e ) {
						continue;
					}
				}
			}
		}

		$unreadable = array();

		if ( ! empty( $attachments ) ) {
			foreach ( $attachments as $filename => $attachment ) {
				$filename = is_string( $filename ) ? $filename : '';

				if ( '' === trim( (string) $attachment ) ) {
					continue;
				}

				try {
					$phpmailer->addAttachment( (string) $attachment, $filename );
				} catch ( \PHPMailer\PHPMailer\Exception $e ) {
					$unreadable[] = wp_basename( (string) $attachment );
					continue;
				}
			}
		}

		if ( ! empty( $embeds ) ) {
			foreach ( $embeds as $key => $embed_path ) {
				if ( '' === trim( (string) $embed_path ) ) {
					continue;
				}

				/** This filter is documented in wp-includes/pluggable.php */
				$embed_args = apply_filters(
					'wp_mail_embed_args',
					array(
						'path'        => $embed_path,
						'cid'         => (string) $key,
						'name'        => wp_basename( (string) $embed_path ),
						'encoding'    => 'base64',
						'type'        => '',
						'disposition' => 'inline',
					)
				);

				try {
					$phpmailer->addEmbeddedImage(
						$embed_args['path'],
						$embed_args['cid'],
						$embed_args['name'],
						$embed_args['encoding'],
						$embed_args['type'],
						$embed_args['disposition']
					);
				} catch ( \PHPMailer\PHPMailer\Exception $e ) {
					continue;
				}
			}
		}

		/** This action is documented in wp-includes/pluggable.php */
		do_action_ref_array( 'phpmailer_init', array( &$phpmailer ) );

		$message             = self::read_back( $phpmailer, $mail_data, $unreadable );
		$message['rejected'] = $rejected;

		return $message;
	}

	/**
	 * Reads the assembled PHPMailer object back into a plain array.
	 *
	 * @param Php_Mailer $phpmailer  Assembled mailer.
	 * @param array      $mail_data  Data for the wp_mail_* actions.
	 * @param string[]   $unreadable Attachments PHPMailer could not read.
	 * @return array
	 */
	private static function read_back( $phpmailer, array $mail_data, array $unreadable ) {
		$charset      = (string) $phpmailer->CharSet;
		$content_type = strtolower( trim( (string) $phpmailer->ContentType ) );
		$body         = (string) $phpmailer->Body;
		$alt          = (string) $phpmailer->AltBody;
		$text         = '';
		$html         = '';

		if ( 'text/html' === $content_type ) {
			$html = $body;
			$text = $alt;
		} elseif ( 0 === strpos( $content_type, 'multipart/' ) ) {
			$parts = Mime_Parser::extract( $body, (string) $phpmailer->ContentType );

			if ( '' !== $parts['text'] || '' !== $parts['html'] ) {
				$text = $parts['text'];
				$html = $parts['html'];
			} elseif ( self::looks_like_html( $body ) ) {
				// e.g. WooCommerce multipart: HTML in Body, plain text in AltBody.
				$html = $body;
				$text = $alt;
			} else {
				$text = $body;
			}
		} else {
			$text = $body;
		}

		$from_name = (string) $phpmailer->FromName;
		$subject   = (string) $phpmailer->Subject;

		if ( '' !== $charset && ! in_array( strtoupper( str_replace( '_', '-', $charset ) ), array( 'UTF-8', 'UTF8' ), true ) && function_exists( 'mb_convert_encoding' ) ) {
			$encodings = array_map( 'strtoupper', mb_list_encodings() );

			if ( in_array( strtoupper( $charset ), $encodings, true ) ) {
				$text      = mb_convert_encoding( $text, 'UTF-8', $charset );
				$html      = mb_convert_encoding( $html, 'UTF-8', $charset );
				$subject   = mb_convert_encoding( $subject, 'UTF-8', $charset );
				$from_name = mb_convert_encoding( $from_name, 'UTF-8', $charset );
			}
		}

		if ( '' !== $html && '' === trim( $text ) ) {
			$text = Html_To_Text::convert( $html );
		}

		$files = array();

		foreach ( $phpmailer->getAttachments() as $attachment ) {
			$is_string = ! empty( $attachment[5] );

			$files[] = array(
				'filename' => $is_string ? (string) $attachment[1] : (string) ( '' !== (string) $attachment[2] ? $attachment[2] : $attachment[1] ),
				'path'     => $is_string ? '' : (string) $attachment[0],
				'content'  => $is_string ? (string) $attachment[0] : null,
				'type'     => isset( $attachment[4] ) ? (string) $attachment[4] : '',
			);
		}

		$custom_headers = array();

		foreach ( (array) $phpmailer->getCustomHeaders() as $header ) {
			if ( isset( $header[0] ) && '' !== (string) $header[0] ) {
				$custom_headers[] = (string) $header[0];
			}
		}

		foreach ( $files as $index => $file ) {
			if ( null === $file['content'] ) {
				unset( $files[ $index ]['content'] );
			}
		}

		return array(
			'from_email'     => (string) $phpmailer->From,
			'from_name'      => $from_name,
			'reply_to'       => $phpmailer->sendfleet_reply_to(),
			'subject'        => $subject,
			'text'           => $text,
			'html'           => $html,
			'recipients'     => $phpmailer->sendfleet_recipients(),
			'attachments'    => array_values( $files ),
			'unreadable'     => $unreadable,
			'custom_headers' => $custom_headers,
			'mail_data'      => $mail_data,
			'phpmailer'      => $phpmailer,
		);
	}

	/**
	 * Whether a body is an HTML document or fragment.
	 *
	 * @param string $body Body.
	 * @return bool
	 */
	public static function looks_like_html( $body ) {
		return 1 === preg_match( '/<(html|body|table|div|p|br|a|span|h[1-6])\b[^>]*>/i', (string) $body );
	}
}
