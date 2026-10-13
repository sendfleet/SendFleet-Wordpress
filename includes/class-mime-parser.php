<?php
/**
 * Minimal MIME multipart reader.
 *
 * @package SendFleet
 */

namespace SendFleet;

defined( 'ABSPATH' ) || exit;

/**
 * Extracts the text/plain and text/html parts from a hand-built multipart
 * body (a plugin that passes `Content-Type: multipart/alternative; boundary=…`
 * and writes the MIME parts into the message itself).
 */
final class Mime_Parser {

	/**
	 * Maximum nesting depth followed.
	 */
	const MAX_DEPTH = 4;

	/**
	 * Extracts the first text/plain and text/html parts.
	 *
	 * @param string $body         Raw multipart body.
	 * @param string $content_type Content-Type value carrying the boundary.
	 * @return array{text:string,html:string}
	 */
	public static function extract( $body, $content_type ) {
		$result = array(
			'text' => '',
			'html' => '',
		);

		$boundary = self::boundary( $content_type );

		if ( '' === $boundary ) {
			return $result;
		}

		self::walk( (string) $body, $boundary, $result, 0 );

		return $result;
	}

	/**
	 * Reads the boundary parameter from a Content-Type value.
	 *
	 * @param string $content_type Header value.
	 * @return string
	 */
	private static function boundary( $content_type ) {
		if ( preg_match( '/boundary\s*=\s*"([^"]+)"/i', (string) $content_type, $m ) || preg_match( '/boundary\s*=\s*([^;\s]+)/i', (string) $content_type, $m ) ) {
			return trim( $m[1] );
		}

		return '';
	}

	/**
	 * Walks the parts under one boundary.
	 *
	 * @param string $body     Body.
	 * @param string $boundary Boundary.
	 * @param array  $result   Collected parts (by reference).
	 * @param int    $depth    Current depth.
	 * @return void
	 */
	private static function walk( $body, $boundary, array &$result, $depth ) {
		if ( $depth > self::MAX_DEPTH ) {
			return;
		}

		$body     = str_replace( "\r\n", "\n", $body );
		$sections = explode( '--' . $boundary, $body );

		// The first section is the preamble.
		array_shift( $sections );

		foreach ( $sections as $section ) {
			if ( 0 === strpos( $section, '--' ) ) {
				break; // Closing delimiter.
			}

			$section = ltrim( $section, "\n" );
			$split   = strpos( $section, "\n\n" );

			if ( false === $split ) {
				continue;
			}

			$raw_headers = substr( $section, 0, $split );
			$content     = substr( $section, $split + 2 );
			$headers     = self::parse_headers( $raw_headers );
			$type        = isset( $headers['content-type'] ) ? $headers['content-type'] : 'text/plain';
			$type_parts  = explode( ';', $type );
			$mime        = strtolower( trim( $type_parts[0] ) );
			$encoding    = isset( $headers['content-transfer-encoding'] ) ? strtolower( trim( $headers['content-transfer-encoding'] ) ) : '';

			if ( 0 === strpos( $mime, 'multipart/' ) ) {
				$inner = self::boundary( $type );
				if ( '' !== $inner ) {
					self::walk( $content, $inner, $result, $depth + 1 );
				}
				continue;
			}

			if ( isset( $headers['content-disposition'] ) && false !== stripos( $headers['content-disposition'], 'attachment' ) ) {
				continue;
			}

			$decoded = self::decode( rtrim( $content, "\n" ), $encoding );
			$charset = preg_match( '/charset\s*=\s*"?([^";\s]+)/i', $type, $cm ) ? $cm[1] : '';

			if ( '' !== $charset && 'UTF-8' !== strtoupper( $charset ) && function_exists( 'mb_convert_encoding' ) && in_array( strtoupper( $charset ), array_map( 'strtoupper', mb_list_encodings() ), true ) ) {
				$decoded = mb_convert_encoding( $decoded, 'UTF-8', $charset );
			}

			if ( 'text/plain' === $mime && '' === $result['text'] ) {
				$result['text'] = $decoded;
			} elseif ( 'text/html' === $mime && '' === $result['html'] ) {
				$result['html'] = $decoded;
			}
		}
	}

	/**
	 * Parses a header block (with folded lines) into a lower-cased map.
	 *
	 * @param string $raw Header block.
	 * @return array<string,string>
	 */
	private static function parse_headers( $raw ) {
		$headers = array();
		$raw     = preg_replace( "/\n[ \t]+/", ' ', $raw );

		foreach ( explode( "\n", (string) $raw ) as $line ) {
			$colon = strpos( $line, ':' );
			if ( false === $colon ) {
				continue;
			}
			$headers[ strtolower( trim( substr( $line, 0, $colon ) ) ) ] = trim( substr( $line, $colon + 1 ) );
		}

		return $headers;
	}

	/**
	 * Decodes a part body.
	 *
	 * @param string $content  Encoded content.
	 * @param string $encoding Content-Transfer-Encoding.
	 * @return string
	 */
	private static function decode( $content, $encoding ) {
		if ( 'base64' === $encoding ) {
			$decoded = base64_decode( preg_replace( '/\s+/', '', $content ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a MIME part.
			return false === $decoded ? $content : $decoded;
		}

		if ( 'quoted-printable' === $encoding ) {
			return quoted_printable_decode( $content );
		}

		return $content;
	}
}
