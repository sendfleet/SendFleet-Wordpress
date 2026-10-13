<?php
/**
 * HTML to plain-text conversion.
 *
 * @package SendFleet
 */

namespace SendFleet;

defined( 'ABSPATH' ) || exit;

/**
 * Produces a readable plain-text alternative for HTML emails.
 *
 * SendFleet recommends always sending a plain-text body alongside HTML; most
 * WordPress plugins only produce HTML, so one is generated when absent.
 */
final class Html_To_Text {

	/**
	 * Converts an HTML document or fragment to plain text.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function convert( $html ) {
		$text = (string) $html;

		if ( '' === trim( $text ) ) {
			return '';
		}

		// Drop everything that is never visible.
		$text = self::replace( '#<(head|style|script|title|noscript|template)\b[^>]*>.*?</\1\s*>#is', '', $text );
		$text = self::replace( '#<!--.*?-->#s', '', $text );

		// Links: keep the URL when it differs from the link text.
		$linked = preg_replace_callback(
			'#<a\b[^>]*?href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a\s*>#is',
			static function ( $m ) {
				$href  = html_entity_decode( trim( $m[2] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$label = trim( wp_strip_all_tags( $m[3] ) );
				$label = html_entity_decode( $label, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

				if ( '' === $href || 0 === stripos( $href, 'javascript:' ) || 0 === strpos( $href, '#' ) ) {
					return $label;
				}

				if ( 0 === stripos( $href, 'mailto:' ) ) {
					$address = substr( $href, 7 );
					return ( '' === $label || $label === $address ) ? $address : $label . ' (' . $address . ')';
				}

				if ( '' === $label || rtrim( $label, '/' ) === rtrim( $href, '/' ) ) {
					return $href;
				}

				return $label . ' (' . $href . ')';
			},
			$text
		);
		$text   = null === $linked ? $text : $linked;

		// Images: keep meaningful alt text.
		$text = self::replace( '#<img\b[^>]*?alt\s*=\s*(["\'])(.+?)\1[^>]*>#is', '[$2]', $text );

		// Block structure to line breaks.
		$text = self::replace( '#<br\s*/?>#i', "\n", $text );
		$text = self::replace( '#<li\b[^>]*>#i', "\n- ", $text );
		$text = self::replace( '#<hr\b[^>]*>#i', "\n----------\n", $text );
		$text = self::replace( '#</(td|th)\s*>#i', ' ', $text );
		$text = self::replace( '#</?(p|div|h[1-6]|tr|table|ul|ol|blockquote|section|article|header|footer|pre|address|center)\b[^>]*>#i', "\n\n", $text );

		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Normalise whitespace: no trailing spaces, at most one blank line in a row.
		$text = str_replace( array( "\r\n", "\r", "\xC2\xA0" ), array( "\n", "\n", ' ' ), $text );
		$text = self::replace( "/[ \t]+/", ' ', $text );
		$text = self::replace( "/ *\n */", "\n", $text );
		$text = self::replace( "/\n{3,}/", "\n\n", $text );

		return trim( $text );
	}

	/**
	 * Same as preg_replace(), but keeps the subject when the regex engine gives up
	 * (backtrack or recursion limits on very large documents).
	 *
	 * @param string $pattern     Pattern.
	 * @param string $replacement Replacement.
	 * @param string $subject     Subject.
	 * @return string
	 */
	private static function replace( $pattern, $replacement, $subject ) {
		$result = preg_replace( $pattern, $replacement, $subject );

		return null === $result ? $subject : $result;
	}
}
