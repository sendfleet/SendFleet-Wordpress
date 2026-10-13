<?php
/**
 * Seeds a demo site for screenshots (test-only).
 *
 * @package SendFleet
 */

// phpcs:ignoreFile -- Test script.

use SendFleet\Account;
use SendFleet\Crypto;
use SendFleet\Installer;
use SendFleet\Settings;
use SendFleet\State;
use SendFleet\Suppressions;

global $wpdb;
update_option( 'blogname', 'Acme Store' );
$wpdb->query( 'DELETE FROM ' . Installer::log_table() );
$wpdb->query( 'DELETE FROM ' . Installer::suppression_table() );
delete_option( State::OPTION );
delete_option( Settings::OPTION );
Settings::flush();
State::flush();
Settings::update( array( 'api_key' => Crypto::encrypt( 'byoc0001.' . str_repeat( 'a', 32 ) ) ) );
wp_remote_post( 'http://mock-api:8080/__reset' );
Account::refresh();

$emails = array(
	array( 'plugin:woocommerce', 'maria.lopez@gmail.com', '[Acme Store] Your order #1042 has been received!', '<p>Thanks for your order.</p>', array( 'Content-Type: text/html', 'From: Acme Store <orders@gmail.com>' ), array() ),
	array( 'plugin:woocommerce', 'shop-admin@acme-store.com', '[Acme Store] New order #1042', '<p>New order.</p>', array( 'Content-Type: text/html' ), array() ),
	array( 'core', 'j.chen@outlook.com', '[Acme Store] Password Reset', 'Someone has requested a password reset.', array(), array() ),
	array( 'plugin:contact-form-7', 'owner@byoc.example.com', 'Website enquiry: wholesale pricing', 'Name: Sam', array( 'Reply-To: sam@company.io' ), array() ),
	array( 'plugin:woocommerce', 'invoices@partner.co', 'Invoice INV-2026-0042', 'Your invoice is attached.', array(), array( wp_upload_dir()['basedir'] . '/invoice.pdf' ) ),
	array( 'core', 'new.member@yahoo.com', '[Acme Store] Login Details', 'Username: newmember', array(), array() ),
	array( 'plugin:wpforms', 'fail502@gmail.com', 'Your quote request', 'We will be in touch.', array(), array() ),
	array( 'plugin:mailpoet', 'old-list@bouncy.example', 'October newsletter', 'Hi', array(), array() ),
);
Suppressions::add( 'old-list@bouncy.example', 'bounced', 'email.bounced · bounce_type: Permanent · bounce_subtype: General' );
Suppressions::add( 'spam-reporter@example.net', 'complained', 'email.complained · complaint_feedback_type: abuse' );
file_put_contents( wp_upload_dir()['basedir'] . '/invoice.pdf', '%PDF-1.4 demo' );
foreach ( $emails as $e ) {
	wp_mail( $e[1], $e[2], $e[3], $e[4], $e[5] );
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . Installer::log_table() . ' SET source = %s WHERE recipient = %s', $e[0], $e[1] ) );
	sleep( 1 );
}
$wpdb->query( $wpdb->prepare( 'UPDATE ' . Installer::log_table() . ' SET recipient = %s WHERE recipient = %s', 'quotes@brightline.co', 'fail502@gmail.com' ) );
echo "seeded\n";
