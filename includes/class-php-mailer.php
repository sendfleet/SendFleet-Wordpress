<?php
/**
 * PHPMailer subclass used to assemble messages.
 *
 * @package SendFleet
 */

namespace SendFleet;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's public properties are CamelCase.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.Classes.DuplicateClassName.Found -- The base class is chosen at runtime by WordPress version.

if ( ! class_exists( '\PHPMailer\PHPMailer\PHPMailer', false ) ) {
	require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
	require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
	require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
}

if ( ! class_exists( '\WP_PHPMailer', false ) && is_readable( ABSPATH . WPINC . '/class-wp-phpmailer.php' ) ) {
	require_once ABSPATH . WPINC . '/class-wp-phpmailer.php';
}

if ( class_exists( '\WP_PHPMailer', false ) ) {
	/**
	 * Base class: WordPress' own PHPMailer subclass (WordPress 6.8+).
	 */
	class Php_Mailer_Base extends \WP_PHPMailer {}
} else {
	/**
	 * Base class: PHPMailer as bundled with WordPress.
	 */
	class Php_Mailer_Base extends \PHPMailer\PHPMailer\PHPMailer {}
}

/**
 * The message is assembled exactly the way wp_mail() assembles it — same
 * header parsing, same filters, same `phpmailer_init` action — so plugins
 * that customise mail through PHPMailer (WooCommerce's multipart AltBody,
 * BCC-everything plugins, and so on) keep working. The finished object is
 * then read back and sent through the SendFleet API instead of mail()/SMTP.
 *
 * The only addition is access to recipients PHPMailer holds back until
 * send() (international domain names).
 */
class Php_Mailer extends Php_Mailer_Base {
 // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound

	/**
	 * Every recipient: [kind, address, name] where kind is to, cc or bcc.
	 *
	 * @return array[]
	 */
	public function sendfleet_recipients() {
		$out = array();

		foreach ( $this->getToAddresses() as $pair ) {
			$out[] = array( 'to', $pair[0], isset( $pair[1] ) ? $pair[1] : '' );
		}
		foreach ( $this->getCcAddresses() as $pair ) {
			$out[] = array( 'cc', $pair[0], isset( $pair[1] ) ? $pair[1] : '' );
		}
		foreach ( $this->getBccAddresses() as $pair ) {
			$out[] = array( 'bcc', $pair[0], isset( $pair[1] ) ? $pair[1] : '' );
		}
		foreach ( (array) $this->RecipientsQueue as $params ) {
			if ( is_array( $params ) && isset( $params[0], $params[1] ) ) {
				$out[] = array( strtolower( (string) $params[0] ), (string) $params[1], isset( $params[2] ) ? (string) $params[2] : '' );
			}
		}

		return $out;
	}

	/**
	 * Reply-To addresses, including any held back for IDN conversion.
	 *
	 * @return string[]
	 */
	public function sendfleet_reply_to() {
		$out = array();

		foreach ( $this->getReplyToAddresses() as $pair ) {
			$out[] = (string) $pair[0];
		}
		foreach ( (array) $this->ReplyToQueue as $params ) {
			if ( is_array( $params ) && isset( $params[1] ) ) {
				$out[] = (string) $params[1];
			}
		}

		return $out;
	}
}
