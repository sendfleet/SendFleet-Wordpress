<?php
/**
 * Attachment validation and encoding.
 *
 * @package SendFleet
 */

namespace SendFleet;

defined( 'ABSPATH' ) || exit;

/**
 * Applies SendFleet's attachment rules before a request is made, so one bad
 * file does not cause the whole email to be refused with a 400:
 *
 * - at most 20 files, 25 MB each, 37 MB in total;
 * - executable and script types are refused regardless of declared type;
 * - the content type must be on SendFleet's allow-list.
 *
 * Files that break a rule are left out and reported; the rest are sent.
 */
final class Attachments {

	const MAX_COUNT  = 20;
	const MAX_SINGLE = 26214400; // 25 MB.
	const MAX_TOTAL  = 38797312; // 37 MB.

	/**
	 * Extensions SendFleet refuses whatever the declared type.
	 *
	 * @var string[]
	 */
	private static $blocked = array( 'exe', 'bat', 'cmd', 'com', 'msi', 'vbs', 'vbe', 'js', 'jse', 'wsf', 'wsh', 'ps1', 'ps2', 'psc1', 'psc2', 'reg', 'scr', 'dll', 'hta', 'inf', 'jar', 'lnk', 'pif', 'url', 'ade', 'adp', 'app', 'bas', 'chm', 'cpl', 'crt', 'hlp', 'mde', 'msc', 'msp', 'mst', 'pcd', 'prg', 'scf', 'shs', 'sys', 'vxd', 'wsc', 'xnk' );

	/**
	 * Extension to the content type SendFleet infers for it. Every type here is on the allow-list.
	 *
	 * @var array<string,string>
	 */
	private static $types = array(
		'pdf'  => 'application/pdf',
		'doc'  => 'application/msword',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'xls'  => 'application/vnd.ms-excel',
		'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'ppt'  => 'application/vnd.ms-powerpoint',
		'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'odt'  => 'application/vnd.oasis.opendocument.text',
		'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
		'odp'  => 'application/vnd.oasis.opendocument.presentation',
		'rtf'  => 'application/rtf',
		'txt'  => 'text/plain',
		'log'  => 'text/plain',
		'csv'  => 'text/csv',
		'html' => 'text/html',
		'htm'  => 'text/html',
		'xml'  => 'application/xml',
		'json' => 'application/json',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
		'svg'  => 'image/svg+xml',
		'tif'  => 'image/tiff',
		'tiff' => 'image/tiff',
		'bmp'  => 'image/bmp',
		'heic' => 'image/heic',
		'heif' => 'image/heif',
		'zip'  => 'application/zip',
		'tar'  => 'application/x-tar',
		'gz'   => 'application/gzip',
		'bz2'  => 'application/x-bzip2',
		'7z'   => 'application/x-7z-compressed',
		'rar'  => 'application/vnd.rar',
		'ics'  => 'text/calendar',
		'vcf'  => 'text/vcard',
		'mp3'  => 'audio/mpeg',
		'm4a'  => 'audio/mp4',
		'ogg'  => 'audio/ogg',
		'wav'  => 'audio/wav',
		'flac' => 'audio/flac',
		'mp4'  => 'video/mp4',
		'mov'  => 'video/quicktime',
		'avi'  => 'video/x-msvideo',
		'webm' => 'video/webm',
	);

	/**
	 * Additional declared types SendFleet accepts.
	 *
	 * @var string[]
	 */
	private static $extra_allowed = array( 'text/rtf', 'text/xml', 'application/x-gzip', 'application/x-rar-compressed' );

	/**
	 * Validates and encodes a set of attachments.
	 *
	 * @param array $items Each: filename, and either path or content (raw bytes), optional type.
	 * @return array {
	 *     @type array    $attachments Payload entries: filename, content (base64), content_type.
	 *     @type string[] $skipped     Human-readable reasons for each file left out.
	 * }
	 */
	public static function prepare( array $items ) {
		$out     = array();
		$skipped = array();
		$total   = 0;

		foreach ( $items as $item ) {
			$filename = isset( $item['filename'] ) ? self::clean_filename( (string) $item['filename'] ) : '';

			if ( '' === $filename && ! empty( $item['path'] ) ) {
				$filename = self::clean_filename( wp_basename( (string) $item['path'] ) );
			}

			if ( '' === $filename ) {
				$filename = 'attachment';
			}

			if ( count( $out ) >= self::MAX_COUNT ) {
				/* translators: 1: file name, 2: maximum number of attachments. */
				$skipped[] = sprintf( __( '%1$s: SendFleet accepts at most %2$d attachments per email.', 'sendfleet' ), $filename, self::MAX_COUNT );
				continue;
			}

			$ext = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );

			if ( in_array( $ext, self::$blocked, true ) ) {
				/* translators: 1: file name, 2: file extension. */
				$skipped[] = sprintf( __( '%1$s: .%2$s files are refused by SendFleet for security reasons.', 'sendfleet' ), $filename, $ext );
				continue;
			}

			$type = self::content_type( $filename, isset( $item['type'] ) ? (string) $item['type'] : '' );

			if ( '' === $type ) {
				/* translators: %s: file name. */
				$skipped[] = sprintf( __( '%s: this file type is not on SendFleet\'s list of allowed attachment types.', 'sendfleet' ), $filename );
				continue;
			}

			if ( ! isset( $item['content'] ) && ! empty( $item['path'] ) && is_string( $item['path'] ) && is_file( $item['path'] ) ) {
				$on_disk = filesize( $item['path'] );

				// Do not load a file into memory that will be refused anyway.
				if ( false !== $on_disk && $on_disk > self::MAX_SINGLE ) {
					/* translators: %s: file name. */
					$skipped[] = sprintf( __( '%s: larger than the 25 MB per-file limit.', 'sendfleet' ), $filename );
					continue;
				}
			}

			$content = self::read( $item );

			if ( null === $content ) {
				/* translators: %s: file name. */
				$skipped[] = sprintf( __( '%s: the file could not be read.', 'sendfleet' ), $filename );
				continue;
			}

			$size = strlen( $content );

			if ( 0 === $size ) {
				/* translators: %s: file name. */
				$skipped[] = sprintf( __( '%s: the file is empty.', 'sendfleet' ), $filename );
				continue;
			}

			if ( $size > self::MAX_SINGLE ) {
				/* translators: %s: file name. */
				$skipped[] = sprintf( __( '%s: larger than the 25 MB per-file limit.', 'sendfleet' ), $filename );
				continue;
			}

			if ( $total + $size > self::MAX_TOTAL ) {
				/* translators: %s: file name. */
				$skipped[] = sprintf( __( '%s: would take the email over the 37 MB total attachment limit.', 'sendfleet' ), $filename );
				continue;
			}

			$total += $size;
			$out[]  = array(
				'filename'     => $filename,
				'content'      => base64_encode( $content ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The API requires base64 attachment content.
				'content_type' => $type,
			);
			unset( $content );
		}

		return array(
			'attachments' => $out,
			'skipped'     => $skipped,
		);
	}

	/**
	 * The content type to declare, or '' when SendFleet would refuse the file.
	 *
	 * The extension decides, as it does on the API; a declared type is used
	 * only when the extension is unknown and the declared type is allowed.
	 *
	 * @param string $filename Filename.
	 * @param string $declared Declared MIME type.
	 * @return string
	 */
	public static function content_type( $filename, $declared = '' ) {
		$ext = strtolower( (string) pathinfo( (string) $filename, PATHINFO_EXTENSION ) );

		if ( in_array( $ext, self::$blocked, true ) ) {
			return '';
		}

		if ( isset( self::$types[ $ext ] ) ) {
			return self::$types[ $ext ];
		}

		$parts    = explode( ';', (string) $declared );
		$declared = strtolower( trim( $parts[0] ) );

		if ( '' !== $declared && ( in_array( $declared, self::$types, true ) || in_array( $declared, self::$extra_allowed, true ) ) ) {
			return $declared;
		}

		return '';
	}

	/**
	 * Strips control characters (the API refuses them in filenames) and limits length.
	 *
	 * @param string $filename Filename.
	 * @return string
	 */
	private static function clean_filename( $filename ) {
		$filename = trim( preg_replace( '/[\x00-\x1F\x7F]/', '', $filename ) );

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $filename, 'UTF-8' ) > 255 ) {
			$ext      = (string) pathinfo( $filename, PATHINFO_EXTENSION );
			$keep     = 250 - strlen( $ext );
			$filename = mb_substr( $filename, 0, max( 1, $keep ), 'UTF-8' ) . ( '' !== $ext ? '.' . $ext : '' );
		} elseif ( strlen( $filename ) > 255 ) {
			$filename = substr( $filename, 0, 255 );
		}

		return $filename;
	}

	/**
	 * Reads an attachment's bytes.
	 *
	 * @param array $item Attachment description.
	 * @return string|null
	 */
	private static function read( array $item ) {
		if ( isset( $item['content'] ) && is_string( $item['content'] ) ) {
			return $item['content'];
		}

		if ( empty( $item['path'] ) || ! is_string( $item['path'] ) ) {
			return null;
		}

		$path = $item['path'];

		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}

		$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for an attachment.

		return false === $content ? null : $content;
	}
}
