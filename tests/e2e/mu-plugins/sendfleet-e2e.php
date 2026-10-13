<?php
/**
 * Plugin Name: SendFleet E2E helpers
 * Description: Test-only. Routes PHPMailer fallback mail to Mailpit when the sf_e2e_smtp option is set.
 *
 * @package SendFleet
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'phpmailer_init',
	static function ( $phpmailer ) {
		if ( get_option( 'sf_e2e_smtp' ) ) {
			$phpmailer->isSMTP();
			$phpmailer->Host        = 'mailpit'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$phpmailer->Port        = 1025; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$phpmailer->SMTPAuth    = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$phpmailer->SMTPAutoTLS = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		// Lets tests prove phpmailer_init customisations reach SendFleet.
		if ( get_option( 'sf_e2e_altbody' ) && 'text/html' === $phpmailer->ContentType ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$phpmailer->AltBody = 'ALTBODY FROM PHPMAILER_INIT'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
		if ( get_option( 'sf_e2e_bcc' ) ) {
			$phpmailer->addBCC( 'audit@byoc.example.com' );
		}
	}
);
