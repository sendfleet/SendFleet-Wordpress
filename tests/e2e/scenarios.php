<?php
/**
 * End-to-end scenarios against the mock SendFleet API.
 *
 * Run inside the WP-CLI container:
 *   wp eval-file wp-content/plugins/sendfleet/tests/e2e/scenarios.php
 *
 * Every scenario drives the real wp_mail() and inspects what reached the
 * mock API, the log table and the return value.
 *
 * @package SendFleet
 */

// phpcs:ignoreFile -- Test script.

use SendFleet\Account;
use SendFleet\Crypto;
use SendFleet\Installer;
use SendFleet\Log;
use SendFleet\Queue;
use SendFleet\Settings;
use SendFleet\State;
use SendFleet\Suppressions;
use SendFleet\Webhooks;

const SF_MOCK = 'http://mock-api:8080';

$GLOBALS['sf_pass'] = 0;
$GLOBALS['sf_fail'] = 0;

function sf_key( $prefix ) {
	return $prefix . '.' . str_repeat( 'a', 32 );
}

function sf_check( $label, $condition, $extra = '' ) {
	if ( $condition ) {
		++$GLOBALS['sf_pass'];
		echo "  PASS  {$label}\n";
	} else {
		++$GLOBALS['sf_fail'];
		echo "  FAIL  {$label}" . ( '' !== $extra ? "  -> {$extra}" : '' ) . "\n";
	}
}

function sf_reset_mock() {
	wp_remote_post( SF_MOCK . '/__reset' );
}

function sf_requests( $path = '/v1/send' ) {
	$res  = wp_remote_get( SF_MOCK . '/__requests' );
	$all  = json_decode( wp_remote_retrieve_body( $res ), true );
	$out  = array();
	foreach ( (array) $all as $r ) {
		if ( $r['path'] === $path ) {
			$out[] = $r;
		}
	}
	return $out;
}

function sf_use_key( $prefix, array $settings = array() ) {
	global $wpdb;
	$wpdb->query( 'DELETE FROM ' . Installer::log_table() );
	$wpdb->query( 'DELETE FROM ' . Installer::suppression_table() );
	delete_option( Settings::OPTION );
	delete_option( State::OPTION );
	delete_option( Account::OPTION );
	delete_option( 'sendfleet_account_checked' );
	Settings::flush();
	State::flush();
	Settings::update( array_merge( array( 'api_key' => Crypto::encrypt( sf_key( $prefix ) ) ), $settings ) );
	sf_reset_mock();
	$r = Account::refresh();
	sf_reset_mock();
	return $r;
}

function sf_rows() {
	global $wpdb;
	return $wpdb->get_results( 'SELECT * FROM ' . Installer::log_table() . ' ORDER BY id ASC', ARRAY_A );
}

function sf_make_due() {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . Installer::log_table() . ' SET next_attempt_at = %s WHERE status = %s', gmdate( 'Y-m-d H:i:s', time() - 5 ), Log::STATUS_QUEUED ) );
	State::clear_breaker( 'throttle' );
}

function sf_last_failure() {
	return $GLOBALS['sf_last_failure'];
}

add_action(
	'wp_mail_failed',
	static function ( $e ) {
		$GLOBALS['sf_last_failure'] = $e;
	}
);

echo "\n== Crypto ==\n";
$enc = Crypto::encrypt( 'secret-value' );
sf_check( 'encrypts with a prefix and no plaintext', 0 === strpos( $enc, 'sf1:' ) && false === strpos( $enc, 'secret-value' ), $enc );
sf_check( 'decrypts back', 'secret-value' === Crypto::decrypt( $enc ) );
sf_check( 'tampered value decrypts to empty', '' === Crypto::decrypt( substr( $enc, 0, -4 ) . 'AAAA' ) );

echo "\n== Not connected: WordPress keeps its default mailer ==\n";
delete_option( Settings::OPTION );
Settings::flush();
sf_check( 'should_route() is false without a key', false === SendFleet\Mailer::should_route() );

echo "\n== Free plan, Shared smoke test only ==\n";
$r = sf_use_key( 'free0001' );
sf_check( '/v1/me loaded', Account::is_known() && 'starter' === Account::plan(), $r->detail() );
sf_check( 'smoke-only detected', Account::is_smoke_only() );
sf_check( 'default sender picked from the verified domain', 'wordpress@smoke.example.com' === Account::default_sender(), Account::default_sender() );

$ok = wp_mail( 'someone@else.com', 'Hello', 'Body' );
sf_check( 'mail to a stranger returns false', false === $ok );
sf_check( 'no API call for a smoke-test refusal', 0 === count( sf_requests() ) );
$rows = sf_rows();
sf_check( 'logged as skipped/smoke_test_recipient', 1 === count( $rows ) && 'skipped' === $rows[0]['status'] && 'smoke_test_recipient' === $rows[0]['error_code'], wp_json_encode( $rows ) );

sf_reset_mock();
$ok   = wp_mail( 'owner@example.com', "Contact form\r\nBcc: injected@evil.com", 'Name: Visitor', array( 'From: "Visitor, Jr." <visitor@gmail.com>', 'Reply-To: visitor@gmail.com' ) );
$reqs = sf_requests();
$body = isset( $reqs[0]['body'] ) ? $reqs[0]['body'] : array();
sf_check( 'mail to the account owner returns true', true === $ok, is_wp_error( sf_last_failure() ) ? sf_last_failure()->get_error_message() : '' );
sf_check( 'recipient rewritten to the account spelling', isset( $body['to'] ) && 'Owner@example.com' === $body['to'], wp_json_encode( $body ) );
sf_check( 'sender replaced by the verified domain', isset( $body['from_email'] ) && 'wordpress@smoke.example.com' === $body['from_email'] );
sf_check( 'from_name stripped of , < > @ ; "', isset( $body['from_name'] ) && 'Visitor Jr.' === $body['from_name'], isset( $body['from_name'] ) ? $body['from_name'] : 'none' );
sf_check( 'subject has no line break', isset( $body['subject'] ) && false === strpos( $body['subject'], "\n" ) && false === strpos( $body['subject'], "\r" ), isset( $body['subject'] ) ? $body['subject'] : '' );
sf_check( 'foreign Reply-To dropped for the smoke test', ! isset( $body['reply_to'] ) );
sf_check( 'visitor address kept visible in the body', isset( $body['message'] ) && false !== strpos( $body['message'], 'visitor@gmail.com' ) );
sf_check( 'User-Agent identifies the plugin', isset( $reqs[0]['user_agent'] ) && 0 === strpos( $reqs[0]['user_agent'], 'SendFleet-WordPress/' ), isset( $reqs[0]['user_agent'] ) ? $reqs[0]['user_agent'] : '' );
$rows = sf_rows();
$last = end( $rows );
sf_check( 'cached usage counts the accepted send', 1 === Account::used(), (string) Account::used() );
sf_check( 'accepted row with message id, mode shared, route smoke', 'accepted' === $last['status'] && '' !== $last['message_id'] && 'shared' === $last['mode'] && 'smoke' === $last['route'], wp_json_encode( $last ) );
sf_check( 'source detected (WP-CLI eval => other/core)', '' !== $last['source'], $last['source'] );

echo "\n== Free plan with a BYOC domain ==\n";
sf_use_key( 'byoc0001' );
$routes = Account::usable_domains();
sf_check( 'BYOC domain first, smoke domain second', array( 'byoc.example.com' => 'byoc', 'smoke.example.com' => 'smoke' ) === $routes, wp_json_encode( $routes ) );
sf_check( 'not smoke-only', ! Account::is_smoke_only() );

$ok   = wp_mail( 'Customer <customer@gmail.com>', 'Order', '<html><head><style>p{color:red}</style></head><body><h1>Thanks</h1><p>Your <a href="https://shop.example.com/o/1">order</a> &amp; receipt.</p></body></html>', array( 'Content-Type: text/html; charset=UTF-8', 'Cc: cc@gmail.com', 'Bcc: bcc@gmail.com, customer@gmail.com' ) );
$reqs = sf_requests();
sf_check( 'returns true', true === $ok );
sf_check( 'one request for the whole email (to, cc, bcc deduped)', 1 === count( $reqs ), (string) count( $reqs ) );
$b = $reqs[0]['body'];
sf_check( 'to is a plain string, cc and bcc are lists', 'customer@gmail.com' === $b['to'] && array( 'cc@gmail.com' ) === $b['cc'] && array( 'bcc@gmail.com' ) === $b['bcc'], wp_json_encode( array( $b['to'], $b['cc'] ?? null, $b['bcc'] ?? null ) ) );
sf_check( 'BYOC sender chosen', 'wordpress@byoc.example.com' === $b['from_email'], $b['from_email'] );
sf_check( 'HTML passed through', isset( $b['html'] ) && false !== strpos( $b['html'], '<h1>Thanks</h1>' ) );
sf_check( 'plain text generated from HTML', isset( $b['message'] ) && false !== strpos( $b['message'], 'Thanks' ) && false !== strpos( $b['message'], 'order (https://shop.example.com/o/1)' ) && false === strpos( $b['message'], 'color:red' ) && false !== strpos( $b['message'], '& receipt' ), isset( $b['message'] ) ? $b['message'] : '' );
sf_check( 'core default sender not used as Reply-To', ! isset( $b['reply_to'] ), isset( $b['reply_to'] ) ? $b['reply_to'] : '' );
$rows = sf_rows();
sf_check( 'one log row per recipient with its kind', 3 === count( $rows ) && 'to' === $rows[0]['recipient_kind'] && 'cc' === $rows[1]['recipient_kind'] && 'bcc' === $rows[2]['recipient_kind'] );
sf_check( 'the rows share the message id', 1 === count( array_unique( wp_list_pluck( $rows, 'message_id' ) ) ) && 1 === count( array_unique( wp_list_pluck( $rows, 'mail_id' ) ) ) );
sf_check( 'cached usage counts every recipient', 3 === Account::used(), (string) Account::used() );

// Several To recipients: a list.
sf_reset_mock();
wp_mail( array( 'one@gmail.com', 'two@gmail.com' ), 'Two', 'Body' );
$b = sf_requests()[0]['body'];
sf_check( 'several to addresses go as a list in one request', 1 === count( sf_requests() ) && array( 'one@gmail.com', 'two@gmail.com' ) === $b['to'] && ! isset( $b['cc'] ), wp_json_encode( $b['to'] ) );

// More than 50 recipients: split into messages of at most 50. On the paid BYOC
// plan, because every recipient counts against the free plan's 50.
sf_use_key( 'paid0001' );
$many = array();
for ( $i = 0; $i < 53; $i++ ) {
	$many[] = "many{$i}@gmail.com";
}
$ok   = wp_mail( 'lead@gmail.com', 'Many', 'Body', array( 'Bcc: ' . implode( ', ', $many ) ) );
$reqs = sf_requests();
$first = $reqs[0]['body'];
sf_check( 'the first 50 recipients go as one message', true === $ok && 'lead@gmail.com' === $first['to'] && 49 === count( $first['bcc'] ), (string) count( $reqs ) );
$rest = array_map( static function ( $r ) { return $r['body']['to']; }, array_slice( $reqs, 1 ) );
sf_check( 'the remaining Bcc-only chunk goes one message each, as To', array( 'many49@gmail.com', 'many50@gmail.com', 'many51@gmail.com', 'many52@gmail.com' ) === $rest && ! isset( $reqs[1]['body']['bcc'] ), wp_json_encode( $rest ) );
sf_check( 'every recipient logged', 54 === count( array_filter( sf_rows(), static function ( $r ) { return 'Many' === $r['subject']; } ) ) );

// Only Cc and Bcc (the To was invalid): each becomes its own message's To.
sf_reset_mock();
wp_mail( 'not-an-address', 'NoTo', 'Body', array( 'Cc: c1@gmail.com', 'Bcc: b1@gmail.com' ) );
$tos = array_map( static function ( $r ) { return $r['body']['to']; }, sf_requests() );
sf_check( 'without a valid To, each recipient is sent alone', array( 'c1@gmail.com', 'b1@gmail.com' ) === $tos && ! isset( sf_requests()[1]['body']['bcc'] ), wp_json_encode( $tos ) );

// The API refuses several recipients while its rollout flag is off: send one each.
sf_reset_mock();
wp_remote_post( SF_MOCK . '/__multi', array( 'body' => '0' ) );
$ok   = wp_mail( 'x1@gmail.com', 'Split', 'Body', array( 'Cc: x2@gmail.com' ) );
$reqs = sf_requests();
sf_check( 'multi-recipient unavailable: split into one request each', true === $ok && 3 === count( $reqs ) && 'x1@gmail.com' === $reqs[1]['body']['to'] && 'x2@gmail.com' === $reqs[2]['body']['to'], (string) count( $reqs ) );
sf_reset_mock();
wp_remote_post( SF_MOCK . '/__multi', array( 'body' => '0' ) );
wp_mail( 'y1@gmail.com', 'Split again', 'Body', array( 'Cc: y2@gmail.com' ) );
sf_check( 'and keeps splitting without asking again', 2 === count( sf_requests() ) );
State::clear_breaker( 'single_recipient' );
wp_remote_post( SF_MOCK . '/__multi', array( 'body' => '1' ) );

// A send larger than what is left of the free plan is refused whole.
sf_use_key( 'byoc0001' );
$many = array();
for ( $i = 0; $i < 51; $i++ ) {
	$many[] = "free{$i}@gmail.com";
}
$ok   = wp_mail( 'freelead@gmail.com', 'Too many for free', 'Body', array( 'Bcc: ' . implode( ', ', array_slice( $many, 0, 49 ) ) ) );
sf_check( 'exactly 50 recipients fit the free plan', true === $ok && 1 === count( sf_requests() ) );
$ok   = wp_mail( 'freelead@gmail.com', 'Over', 'Body', array( 'Cc: over@gmail.com' ) );
sf_check( 'none fit once it is used up', false === $ok );
sf_use_key( 'byoc0001' );

// Third-party sender on an unverified domain becomes Reply-To.
sf_reset_mock();
wp_mail( 'x@gmail.com', 'Woo', 'Body', array( 'From: Shop <shop@gmail.com>' ) );
$b = sf_requests()[0]['body'];
sf_check( 'unverified sender replaced, original kept as Reply-To', 'wordpress@byoc.example.com' === $b['from_email'] && 'shop@gmail.com' === $b['reply_to'] && 'Shop' === $b['from_name'], wp_json_encode( $b ) );

// A sender already on a verified domain is kept.
sf_reset_mock();
wp_mail( 'x@gmail.com', 'Kept', 'Body', array( 'From: Billing <billing@byoc.example.com>' ) );
$b = sf_requests()[0]['body'];
sf_check( 'verified sender kept', 'billing@byoc.example.com' === $b['from_email'] && ! isset( $b['reply_to'] ), wp_json_encode( $b ) );

// Generic "WordPress" name replaced with the site title.
sf_check( 'name of a kept sender is kept', 'Billing' === sf_requests()[0]['body']['from_name'], sf_requests()[0]['body']['from_name'] );
sf_reset_mock();
wp_mail( 'x@gmail.com', 'Name', 'Body' );
sf_check( '"WordPress" from name becomes the site title', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) === sf_requests()[0]['body']['from_name'], sf_requests()[0]['body']['from_name'] );

echo "\n== Attachments ==\n";
sf_reset_mock();
$dir = wp_upload_dir()['basedir'];
wp_mkdir_p( $dir );
file_put_contents( $dir . '/invoice.pdf', '%PDF-1.4 test' );
file_put_contents( $dir . '/evil.exe', 'MZ' );
file_put_contents( $dir . '/data.bin', 'xx' );
$ok   = wp_mail( 'x@gmail.com', 'Files', 'See attached', '', array( $dir . '/invoice.pdf', $dir . '/evil.exe', $dir . '/data.bin', $dir . '/missing.pdf' ) );
$b    = sf_requests()[0]['body'];
sf_check( 'sent', true === $ok );
sf_check( 'only the allowed file attached', isset( $b['attachments'] ) && 1 === count( $b['attachments'] ) && 'invoice.pdf' === $b['attachments'][0]['filename'] && 'application/pdf' === $b['attachments'][0]['content_type'] && base64_decode( $b['attachments'][0]['content'] ) === '%PDF-1.4 test', isset( $b['attachments'] ) ? wp_json_encode( $b['attachments'] ) : 'none' );
$rows = sf_rows();
$last = end( $rows );
sf_check( 'skipped attachments noted in the log', false !== strpos( $last['notes'], 'evil.exe' ) && false !== strpos( $last['notes'], 'data.bin' ) && false !== strpos( $last['notes'], 'missing.pdf' ), $last['notes'] );
sf_check( 'attachment count logged', 1 === (int) $last['attachments'] );

echo "\n== phpmailer_init compatibility ==\n";
update_option( 'sf_e2e_altbody', 1 );
update_option( 'sf_e2e_bcc', 1 );
sf_reset_mock();
wp_mail( 'x@gmail.com', 'Alt', '<p>Hi</p>', array( 'Content-Type: text/html' ) );
$reqs = sf_requests();
sf_check( 'AltBody set in phpmailer_init used as plain text', 'ALTBODY FROM PHPMAILER_INIT' === $reqs[0]['body']['message'], $reqs[0]['body']['message'] );
sf_check( 'BCC added in phpmailer_init is sent as bcc', 1 === count( $reqs ) && array( 'audit@byoc.example.com' ) === $reqs[0]['body']['bcc'], wp_json_encode( $reqs[0]['body'] ) );
delete_option( 'sf_e2e_altbody' );
delete_option( 'sf_e2e_bcc' );

// wp_mail_content_type filter.
sf_reset_mock();
$ct = static function () { return 'text/html'; };
add_filter( 'wp_mail_content_type', $ct );
wp_mail( 'x@gmail.com', 'CT', '<b>bold</b>' );
remove_filter( 'wp_mail_content_type', $ct );
$b = sf_requests()[0]['body'];
sf_check( 'wp_mail_content_type filter respected', isset( $b['html'] ) && '<b>bold</b>' === $b['html'] && 'bold' === $b['message'], wp_json_encode( $b ) );

// Hand-built multipart body.
sf_reset_mock();
$boundary = 'XYZ123';
$mp       = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nPlain part\r\n--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n<p>HTML=20part</p>\r\n--$boundary--";
wp_mail( 'x@gmail.com', 'MP', $mp, array( 'Content-Type: multipart/alternative; boundary="' . $boundary . '"' ) );
$b = sf_requests()[0]['body'];
sf_check( 'multipart parts extracted', 'Plain part' === $b['message'] && '<p>HTML part</p>' === $b['html'], wp_json_encode( $b ) );

// Long subject truncated to 256 characters.
sf_reset_mock();
wp_mail( 'x@gmail.com', str_repeat( 'é', 300 ), 'Body' );
$b = sf_requests()[0]['body'];
sf_check( 'subject truncated to 256 characters', 256 === mb_strlen( $b['subject'] ), (string) mb_strlen( $b['subject'] ) );

// Empty message.
sf_reset_mock();
$ok = wp_mail( 'x@gmail.com', 'Empty', '' );
sf_check( 'empty message fails without an API call', false === $ok && 0 === count( sf_requests() ) );

// No recipients.
$ok   = wp_mail( 'not-an-email', 'Bad', 'Body' );
$rows = sf_rows();
$last = end( $rows );
sf_check( 'no valid recipient fails and is logged', false === $ok && 'not-an-email' === $last['recipient'] && 'invalid_recipient' === $last['error_code'], wp_json_encode( $last ) );

// Valid plus invalid recipient: the valid one is sent.
sf_reset_mock();
$ok = wp_mail( 'good@gmail.com, broken@', 'Mixed', 'Body' );
sf_check( 'valid recipient sent despite an invalid one', true === $ok && 1 === count( sf_requests() ) );

// Custom headers are reported.
sf_reset_mock();
wp_mail( 'x@gmail.com', 'Hdr', 'Body', array( 'X-Campaign: fall', 'List-Unsubscribe: <https://example.com/u>' ) );
$rows = sf_rows();
$last = end( $rows );
sf_check( 'unsupported custom headers noted', false !== strpos( $last['notes'], 'X-Campaign' ) && false !== strpos( $last['notes'], 'List-Unsubscribe' ), $last['notes'] );

// Force sender overrides a verified sender too.
Settings::update( array( 'force_from' => true, 'from_email' => 'noreply@byoc.example.com', 'from_name' => 'Acme', 'force_from_name' => true ) );
sf_reset_mock();
wp_mail( 'x@gmail.com', 'Forced', 'Body', array( 'From: Billing <billing@byoc.example.com>' ) );
$b = sf_requests()[0]['body'];
sf_check( 'force sender and name applied', 'noreply@byoc.example.com' === $b['from_email'] && 'Acme' === $b['from_name'] && 'billing@byoc.example.com' === $b['reply_to'], wp_json_encode( $b ) );
Settings::update( array( 'force_from' => false, 'from_email' => '', 'from_name' => '', 'force_from_name' => false ) );

// Routing switched off: WordPress handles mail (pre_wp_mail returns null).
Settings::update( array( 'enabled' => false ) );
sf_check( 'routing off leaves wp_mail to WordPress', null === apply_filters( 'pre_wp_mail', null, array( 'to' => 'x@gmail.com', 'subject' => 's', 'message' => 'm' ) ) );
Settings::update( array( 'enabled' => true ) );

// Another plugin short-circuiting first is respected.
sf_check( 'earlier pre_wp_mail result respected', false === apply_filters( 'pre_wp_mail', false, array() ) );

echo "\n== Retries and queue ==\n";
sf_reset_mock();
$ok   = wp_mail( 'flaky502@gmail.com', 'Flaky', 'Body' );
$rows = sf_rows();
$last = end( $rows );
sf_check( '502 then success: retried inline', true === $ok && 'accepted' === $last['status'] && 2 === (int) $last['attempts'], wp_json_encode( $last ) );

sf_reset_mock();
$t0   = time();
$ok   = wp_mail( 'throttle@gmail.com', 'Throttle', 'Body' );
$rows = sf_rows();
$last = end( $rows );
sf_check( '429 with Retry-After 2: waited and retried inline', true === $ok && 'accepted' === $last['status'] && time() - $t0 >= 2, wp_json_encode( $last ) );

sf_reset_mock();
$ok   = wp_mail( 'fail502@gmail.com', 'Fail', 'Body' );
$rows = sf_rows();
$last = end( $rows );
sf_check( 'persistent 502: wp_mail true, row queued with payload', true === $ok && 'queued' === $last['status'] && ! empty( $last['payload'] ) && 'transient' === $last['retry_class'], wp_json_encode( array_diff_key( $last, array( 'payload' => 1 ) ) ) );
sf_check( 'queue run scheduled', false !== wp_next_scheduled( Installer::CRON_QUEUE ) );
for ( $i = 0; $i < 6; $i++ ) {
	sf_make_due();
	Queue::process();
}
$row = Log::find( (int) $last['id'] );
sf_check( 'gives up after 5 attempts and drops the payload', 'failed' === $row['status'] && 5 === (int) $row['attempts'] && null === $row['payload'], wp_json_encode( array( $row['status'], $row['attempts'], $row['payload'] ) ) );
sf_check( '5 API calls in total (2 inline + 3 from the queue)', 5 === count( sf_requests() ), (string) count( sf_requests() ) );

sf_reset_mock();
$ok   = wp_mail( 'fail500@gmail.com', 'Fail500', 'Body' );
$rows = sf_rows();
$last = end( $rows );
sf_check( '500: queued for one retry', true === $ok && 'queued' === $last['status'] && 'server' === $last['retry_class'] );
sf_make_due();
Queue::process();
$row = Log::find( (int) $last['id'] );
sf_check( '500: fails after the second attempt', 'failed' === $row['status'] && 2 === (int) $row['attempts'], wp_json_encode( array( $row['status'], $row['attempts'] ) ) );

// One queued message, several recipients: the lead row carries the payload,
// the follower rows take its outcome.
sf_reset_mock();
$ok    = wp_mail( 'fail502@gmail.com', 'Group', 'Body', array( 'Cc: follower@gmail.com' ) );
$group = array_values( array_filter( sf_rows(), static function ( $r ) { return 'Group' === $r['subject']; } ) );
sf_check( 'a retryable multi-recipient send queues a lead and a follower', true === $ok && 2 === count( $group ) && 'queued' === $group[0]['status'] && ! empty( $group[0]['payload'] ) && 'queued' === $group[1]['status'] && null === $group[1]['payload'], wp_json_encode( wp_list_pluck( $group, 'status' ) ) );
for ( $i = 0; $i < 6; $i++ ) {
	sf_make_due();
	Queue::process();
}
$after = array( Log::find( (int) $group[0]['id'] ), Log::find( (int) $group[1]['id'] ) );
sf_check( 'the follower takes the lead\'s final outcome', 'failed' === $after[0]['status'] && 'failed' === $after[1]['status'] && 'queue_error' === $after[1]['error_code'], wp_json_encode( wp_list_pluck( $after, 'status' ) ) );
sf_check( 'only the lead was ever sent: one API call per attempt', 5 === count( sf_requests() ), (string) count( sf_requests() ) );

echo "\n== Background sending ==\n";
Settings::update( array( 'background' => true ) );
sf_reset_mock();
$ok   = wp_mail( 'bg@gmail.com', 'Background', 'Body' );
$rows = sf_rows();
$last = end( $rows );
sf_check( 'returns true immediately and queues', true === $ok && 'queued' === $last['status'] && 0 === count( sf_requests() ) );
sf_make_due();
$counts = Queue::process();
$row    = Log::find( (int) $last['id'] );
sf_check( 'cron sends it', 1 === $counts['sent'] && 'accepted' === $row['status'] && null === $row['payload'] && 'byoc' === $row['mode'], wp_json_encode( $counts ) );
sf_reset_mock();
wp_mail( 'bg1@gmail.com', 'Background group', 'Body', array( 'Bcc: bg2@gmail.com' ) );
sf_make_due();
Queue::process();
$group = array_values( array_filter( sf_rows(), static function ( $r ) { return 'Background group' === $r['subject']; } ) );
sf_check( 'a queued multi-recipient email goes as one request and every row is accepted', 1 === count( sf_requests() ) && array( 'accepted', 'accepted' ) === wp_list_pluck( $group, 'status' ) && array( 'bg2@gmail.com' ) === sf_requests()[0]['body']['bcc'], wp_json_encode( wp_list_pluck( $group, 'status' ) ) );
Settings::update( array( 'background' => false ) );

echo "\n== Local suppression list ==\n";
Suppressions::add( 'Blocked@Gmail.com', 'manual' );
sf_reset_mock();
$ok   = wp_mail( 'blocked@gmail.com', 'Sup', 'Body' );
$rows = sf_rows();
$last = end( $rows );
sf_check( 'suppressed address skipped without an API call', false === $ok && 'skipped' === $last['status'] && 'suppressed_locally' === $last['error_code'] && 0 === count( sf_requests() ) );
Settings::update( array( 'suppression_enabled' => false ) );
sf_reset_mock();
$ok = wp_mail( 'blocked@gmail.com', 'Sup', 'Body' );
sf_check( 'suppression check can be switched off', true === $ok && 1 === count( sf_requests() ) );
Settings::update( array( 'suppression_enabled' => true ) );

echo "\n== Timeout (ambiguous) ==\n";
sf_reset_mock();
$t0   = time();
$ok   = wp_mail( 'slow@gmail.com', 'Slow', 'Body' );
$rows = sf_rows();
$last = end( $rows );
sf_check( 'timeout is not retried and not queued', false === $ok && 'failed' === $last['status'] && 'timeout' === $last['error_code'] && 'ambiguous' === $last['retry_class'] && 1 === count( sf_requests() ), wp_json_encode( array( $last['status'], $last['error_code'], $last['retry_class'], time() - $t0 ) ) );
$t0   = time();
$ok   = wp_mail( 'after-timeout@gmail.com', 'Next', 'Body' );
$rows = sf_rows();
$last = end( $rows );
sf_check( 'next send is queued at once instead of waiting for another timeout', true === $ok && 'queued' === $last['status'] && 1 === count( sf_requests() ) && time() - $t0 < 3 && 0 === (int) $last['attempts'], wp_json_encode( array( $last['status'], $last['attempts'], time() - $t0 ) ) );
sf_make_due();
Queue::process();
sf_check( 'queue holds it while the API is considered down', 'queued' === Log::find( (int) $last['id'] )['status'] );
State::clear_breaker( 'network' );
sf_make_due();
Queue::process();
sf_check( 'sent once the API answers again', 'accepted' === Log::find( (int) $last['id'] )['status'] );

echo "\n== Fallback to WordPress mail (Mailpit) ==\n";
update_option( 'sf_e2e_smtp', 1 );
wp_remote_request( 'http://mailpit:8025/api/v1/messages', array( 'method' => 'DELETE' ) );
sf_use_key( 'free0001', array( 'fallback' => true ) );
$ok   = wp_mail( 'stranger@gmail.com', 'Fallback please', 'Body via PHPMailer', array( 'From: Site <site@gmail.com>' ) );
$rows = sf_rows();
$last = end( $rows );
sleep( 1 );
$mp = json_decode( wp_remote_retrieve_body( wp_remote_get( 'http://mailpit:8025/api/v1/messages' ) ), true );
sf_check( 'smoke-test recipient sent through the fallback', true === $ok && 'fallback' === $last['status'], wp_json_encode( array( $last['status'], $last['error_message'] ) ) );
sf_check( 'Mailpit received it from the original sender', isset( $mp['messages'][0] ) && 'Fallback please' === $mp['messages'][0]['Subject'] && 'site@gmail.com' === $mp['messages'][0]['From']['Address'], wp_json_encode( isset( $mp['messages'][0] ) ? $mp['messages'][0]['From'] : $mp ) );
delete_option( 'sf_e2e_smtp' );

echo "\n== Circuit breakers ==\n";
sf_use_key( 'quota001' );
$ok = wp_mail( 'x@gmail.com', 'Q1', 'Body' );
$ok2 = wp_mail( 'y@gmail.com', 'Q2', 'Body' );
$rows = sf_rows();
sf_check( 'quota exhausted: failed with usage_limit_exceeded', false === $ok && 'usage_limit_exceeded' === $rows[0]['error_code'] );
sf_check( 'second send blocked locally (one API call only)', false === $ok2 && 1 === count( sf_requests() ) && 'usage_limit_exceeded' === $rows[1]['error_code'] && 0 === (int) $rows[1]['attempts'], (string) count( sf_requests() ) );
sf_check( 'quota issue reported', in_array( 'quota', wp_list_pluck( SendFleet\Status::issues(), 'id' ), true ) );

sf_use_key( 'byoc0001' );
Settings::update( array( 'api_key' => Crypto::encrypt( sf_key( 'revoked1' ) ) ) );
sf_reset_mock();
$ok  = wp_mail( 'x@gmail.com', 'K1', 'Body', array( 'From: a@byoc.example.com' ) );
$ok2 = wp_mail( 'y@gmail.com', 'K2', 'Body', array( 'From: a@byoc.example.com' ) );
sf_check( 'rejected key: one API call, then blocked locally', false === $ok && false === $ok2 && 1 === count( sf_requests() ), (string) count( sf_requests() ) );
sf_check( 'key_rejected issue reported', in_array( 'key_rejected', wp_list_pluck( SendFleet\Status::issues(), 'id' ), true ) );

sf_use_key( 'susp0001' );
$ok = wp_mail( 'x@gmail.com', 'S', 'Body', array( 'From: a@susp.example.com' ) );
sf_check( 'suspended account: sending_suspended recorded', false === $ok && 'sending_suspended' === sf_rows()[0]['error_code'] );

echo "\n== Managed plan + webhooks ==\n";
sf_use_key( 'mngd0001', array( 'webhook_secret' => Crypto::encrypt( 'whsec_test' ) ) );
$ok   = wp_mail( 'bounce-me@gmail.com', 'Managed', 'Body', array( 'Cc: stays-fine@gmail.com' ) );
$rows = sf_rows();
$row  = $rows[0];
$cc   = $rows[1];
sf_check( 'both recipients share the idempotency key', 2 === count( $rows ) && $row['idempotency_key'] === $cc['idempotency_key'] );
sf_check( 'shared send accepted with idempotency key and warnings', true === $ok && 'shared' === $row['mode'] && '' !== $row['idempotency_key'] && false !== strpos( $row['error_message'], 'DMARC' ), wp_json_encode( $row ) );

$payload = wp_json_encode(
	array(
		'idempotency_key'    => $row['idempotency_key'],
		'ses_message_id'     => '0100018f',
		'recipient'          => 'bounce-me@gmail.com',
		'status'             => 'bounced',
		'bounce_type'        => 'Permanent',
		'bounce_subtype'     => 'General',
		'bounced_recipients' => array( 'bounce-me@gmail.com' ),
		'timestamp'          => '2026-10-13T12:00:00.000Z',
	)
);
$url  = ( defined( 'SF_E2E_SELF' ) ? SF_E2E_SELF : 'http://wordpress' ) . '/?rest_route=/sendfleet/v1/webhook';
$bad  = wp_remote_post( $url, array( 'body' => $payload, 'headers' => array( 'Content-Type' => 'application/json', 'X-SendFleet-Signature' => 'sha256=deadbeef', 'X-SendFleet-Event' => 'email.bounced' ) ) );
sf_check( 'bad signature rejected with 401', 401 === wp_remote_retrieve_response_code( $bad ), (string) wp_remote_retrieve_response_code( $bad ) . ' ' . wp_remote_retrieve_body( $bad ) );
$good = wp_remote_post( $url, array( 'body' => $payload, 'headers' => array( 'Content-Type' => 'application/json', 'X-SendFleet-Signature' => 'sha256=' . hash_hmac( 'sha256', $payload, 'whsec_test' ), 'X-SendFleet-Event' => 'email.bounced', 'User-Agent' => 'SendFleet-Webhook/1.0' ) ) );
sf_check( 'valid signature accepted', 200 === wp_remote_retrieve_response_code( $good ), wp_remote_retrieve_body( $good ) );
$row = Log::find( (int) $row['id'] );
sf_check( 'log row marked bounced', 'bounced' === $row['status'] && false !== strpos( $row['error_message'], 'Permanent' ), wp_json_encode( array( $row['status'], $row['error_message'] ) ) );
sf_check( 'the event reached only its recipient\'s row', 'accepted' === Log::find( (int) $cc['id'] )['status'] );
Suppressions::flush();
sf_check( 'hard bounce added to suppression list', Suppressions::is_suppressed( 'bounce-me@gmail.com' ) );

$late = wp_json_encode( array( 'idempotency_key' => $row['idempotency_key'], 'recipient' => 'bounce-me@gmail.com', 'status' => 'sent' ) );
wp_remote_post( $url, array( 'body' => $late, 'headers' => array( 'Content-Type' => 'application/json', 'X-SendFleet-Signature' => 'sha256=' . hash_hmac( 'sha256', $late, 'whsec_test' ), 'X-SendFleet-Event' => 'email.sent' ) ) );
$row = Log::find( (int) $row['id'] );
sf_check( 'a late email.sent does not overwrite bounced', 'bounced' === $row['status'], $row['status'] );

$ok = wp_mail( 'bounce-me@gmail.com', 'Again', 'Body' );
sf_check( 'next send to the bounced address is skipped', false === $ok );

echo "\n== Rate limit burst (60/min) ==\n";
sf_use_key( 'paid0001' );
while ( (int) gmdate( 's' ) > 40 ) {
	sleep( 1 ); // Keep the burst inside one fixed rate-limit window.
}
sf_reset_mock();
$results = array();
for ( $i = 0; $i < 63; $i++ ) {
	$results[] = wp_mail( "burst{$i}@gmail.com", 'Burst', 'Body' );
}
$statuses = array_count_values( wp_list_pluck( sf_rows(), 'status' ) );
sf_check( 'all 63 wp_mail() calls report success', 63 === count( array_filter( $results ) ) );
sf_check( '60 accepted, the rest queued for after the window', 60 === ( isset( $statuses['accepted'] ) ? $statuses['accepted'] : 0 ) && 3 === ( isset( $statuses['queued'] ) ? $statuses['queued'] : 0 ), wp_json_encode( $statuses ) );
sf_check( 'only 61 API calls (requests after the 429 wait locally)', 61 === count( sf_requests() ), (string) count( sf_requests() ) );

echo "\n== Status / Site Health ==\n";
sf_use_key( 'unvr0001' );
sf_check( 'unverified account reported', in_array( 'unverified', wp_list_pluck( SendFleet\Status::issues(), 'id' ), true ) );
Settings::update( array( 'from_email' => 'me@notmine.com' ) );
sf_check( 'unverified default sender reported', in_array( 'sender_unverified', wp_list_pluck( SendFleet\Status::issues(), 'id' ), true ) );
sf_check( 'unverified default sender is not used', 'wordpress@unverified.example.com' === Account::default_sender(), Account::default_sender() );
$health = SendFleet\Site_Health::run_test();
sf_check( 'Site Health reports critical', 'critical' === $health['status'] );

echo "\n== Privacy ==\n";
sf_use_key( 'byoc0001' );
wp_mail( 'privacy@gmail.com', 'P', 'Body' );
$export = SendFleet\Privacy::export( 'privacy@gmail.com' );
sf_check( 'export returns the log row', 1 === count( $export['data'] ) );
$erase = SendFleet\Privacy::erase( 'privacy@gmail.com' );
sf_check( 'erase removes it', true === $erase['items_removed'] && 0 === count( SendFleet\Privacy::export( 'privacy@gmail.com' )['data'] ) );

echo "\n== Maintenance ==\n";
global $wpdb;
$id = Log::insert( array( 'status' => 'accepted', 'recipient' => 'old@gmail.com', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) ) );
$stale = Log::insert( array( 'status' => 'sending', 'recipient' => 'stale@gmail.com', 'locked_until' => gmdate( 'Y-m-d H:i:s', time() - 60 ), 'payload' => '{}' ) );
Queue::maintenance();
sf_check( 'old rows purged after retention', null === Log::find( $id ) );
$s = Log::find( $stale );
sf_check( 'stale sending row marked interrupted', 'failed' === $s['status'] && 'interrupted' === $s['error_code'] && null === $s['payload'] );

// Leave a clean, connected state for manual testing.
sf_use_key( 'byoc0001' );

echo "\n" . $GLOBALS['sf_pass'] . ' passed, ' . $GLOBALS['sf_fail'] . " failed\n";
if ( $GLOBALS['sf_fail'] > 0 ) {
	exit( 1 );
}
