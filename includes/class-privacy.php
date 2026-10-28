<?php
/**
 * Personal data export, erasure and privacy policy text.
 *
 * @package SendFleet
 */

namespace SendFleet;

defined( 'ABSPATH' ) || exit;

/**
 * Integrates the email log and suppression list with WordPress' privacy tools
 * (Tools → Export / Erase Personal Data).
 */
final class Privacy {

	const PAGE_SIZE = 100;

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy_content' ) );
	}

	/**
	 * Adds the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['sendfleet'] = array(
			'exporter_friendly_name' => __( 'SendFleet email log', 'sendfleet' ),
			'callback'               => array( __CLASS__, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Adds the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['sendfleet'] = array(
			'eraser_friendly_name' => __( 'SendFleet email log', 'sendfleet' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Exports log rows and the suppression entry for an address.
	 *
	 * @param string $email Address.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		$items = array();
		$rows  = Log::for_recipient( $email, self::PAGE_SIZE, $page );

		foreach ( $rows as $row ) {
			$items[] = array(
				'group_id'    => 'sendfleet-log',
				'group_label' => __( 'Emails sent through SendFleet', 'sendfleet' ),
				'item_id'     => 'sendfleet-log-' . $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Date (UTC)', 'sendfleet' ),
						'value' => $row['created_at'],
					),
					array(
						'name'  => __( 'Recipient', 'sendfleet' ),
						'value' => $row['recipient'],
					),
					array(
						'name'  => __( 'Sender', 'sendfleet' ),
						'value' => $row['from_email'],
					),
					array(
						'name'  => __( 'Subject', 'sendfleet' ),
						'value' => $row['subject'],
					),
					array(
						'name'  => __( 'Status', 'sendfleet' ),
						'value' => $row['status'],
					),
				),
			);
		}

		$done = count( $rows ) < self::PAGE_SIZE;

		if ( $done ) {
			$suppression = Suppressions::find( $email );

			if ( $suppression ) {
				$items[] = array(
					'group_id'    => 'sendfleet-suppression',
					'group_label' => __( 'SendFleet suppression list', 'sendfleet' ),
					'item_id'     => 'sendfleet-suppression-' . $suppression['id'],
					'data'        => array(
						array(
							'name'  => __( 'Address', 'sendfleet' ),
							'value' => $suppression['email'],
						),
						array(
							'name'  => __( 'Reason', 'sendfleet' ),
							'value' => $suppression['reason'],
						),
						array(
							'name'  => __( 'Added (UTC)', 'sendfleet' ),
							'value' => $suppression['created_at'],
						),
					),
				);
			}
		}

		return array(
			'data' => $items,
			'done' => $done,
		);
	}

	/**
	 * Erases log rows for an address. The suppression entry is kept, because
	 * removing it would let mail flow again to an address that bounced or
	 * complained; that is reported as retained.
	 *
	 * @param string $email Address.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) {
		unset( $page );

		$removed  = Log::delete_for_recipient( $email );
		$messages = array();
		$retained = false;

		if ( Suppressions::find( $email ) ) {
			$retained   = true;
			$messages[] = __( 'The address stays on the SendFleet suppression list so that no further email is sent to it. Remove it under SendFleet → Email Log → Suppressions if that is required.', 'sendfleet' );
		}

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Suggests privacy policy text.
	 *
	 * @return void
	 */
	public static function policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p>' . __( 'This site sends email (for example account, order and form notifications) through SendFleet, a transactional email service. To deliver a message, the recipient address, sender, subject, message content and any attachments are transmitted to SendFleet (api.sendfleet.net).', 'sendfleet' ) . '</p>'
			. '<p>' . __( 'Depending on the SendFleet plan the site uses, messages are sent either through the site owner\'s own Amazon SES account, in which case SendFleet never stores message bodies and keeps recipients, subjects and delivery status for 14 days only while the site owner has SendFleet\'s email logs turned on, or through SendFleet\'s managed infrastructure, in which case SendFleet keeps an email log including message content for 14 days.', 'sendfleet' ) . '</p>'
			. '<p>' . __( 'This site keeps its own log of sent email (recipient, sender, subject and delivery status, but not the message content) for a limited period.', 'sendfleet' ) . '</p>'
			/* translators: %s: URL of the SendFleet privacy policy. */
			. '<p>' . sprintf( __( 'SendFleet privacy policy: %s', 'sendfleet' ), '<a href="https://sendfleet.net/privacy-policy/">https://sendfleet.net/privacy-policy/</a>' ) . '</p>';

		wp_add_privacy_policy_content( 'SendFleet', wp_kses_post( $content ) );
	}
}
