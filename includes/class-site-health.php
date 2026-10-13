<?php
/**
 * Site Health integration.
 *
 * @package SendFleet
 */

namespace SendFleet;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a SendFleet check to Tools → Site Health and a section to its Info tab.
 */
final class Site_Health {

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'site_status_tests', array( __CLASS__, 'tests' ) );
		add_filter( 'debug_information', array( __CLASS__, 'debug_information' ) );
	}

	/**
	 * Adds the direct test.
	 *
	 * @param array $tests Tests.
	 * @return array
	 */
	public static function tests( $tests ) {
		$tests['direct']['sendfleet'] = array(
			'label' => __( 'SendFleet email delivery', 'sendfleet' ),
			'test'  => array( __CLASS__, 'run_test' ),
		);

		return $tests;
	}

	/**
	 * Runs the check.
	 *
	 * @return array
	 */
	public static function run_test() {
		$result = array(
			'label'       => __( 'WordPress email is sent through SendFleet', 'sendfleet' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Email', 'sendfleet' ),
				'color' => 'blue',
			),
			'description' => '<p>' . __( 'SendFleet is connected and every email WordPress sends goes through the SendFleet API.', 'sendfleet' ) . '</p>',
			'actions'     => sprintf( '<p><a href="%s">%s</a></p>', esc_url( admin_url( 'admin.php?page=sendfleet' ) ), esc_html__( 'Open SendFleet', 'sendfleet' ) ),
			'test'        => 'sendfleet',
		);

		$issues = Status::issues();

		if ( empty( $issues ) ) {
			return $result;
		}

		$critical = false;
		$items    = '';

		foreach ( $issues as $issue ) {
			$critical = $critical || 'error' === $issue['level'];
			$items   .= '<li>' . esc_html( $issue['message'] ) . '</li>';
		}

		$result['status']      = $critical ? 'critical' : 'recommended';
		$result['label']       = $critical ? __( 'SendFleet cannot send email', 'sendfleet' ) : __( 'SendFleet needs attention', 'sendfleet' );
		$result['description'] = '<ul>' . $items . '</ul>';

		return $result;
	}

	/**
	 * Adds the Info section.
	 *
	 * @param array $info Debug info.
	 * @return array
	 */
	public static function debug_information( $info ) {
		$data   = Account::data();
		$fields = array(
			'version'    => array(
				'label' => __( 'Plugin version', 'sendfleet' ),
				'value' => SENDFLEET_VERSION,
			),
			'routing'    => array(
				'label' => __( 'Routing wp_mail()', 'sendfleet' ),
				'value' => Mailer::should_route() ? __( 'Yes', 'sendfleet' ) : __( 'No', 'sendfleet' ),
				'debug' => Mailer::should_route() ? 'yes' : 'no',
			),
			'key'        => array(
				'label'   => __( 'API key', 'sendfleet' ),
				'value'   => '' !== Settings::api_key_prefix() ? Settings::api_key_prefix() . '.•••• (' . Settings::api_key_source() . ')' : __( 'Not set', 'sendfleet' ),
				'private' => true,
			),
			'api'        => array(
				'label' => __( 'API origin', 'sendfleet' ),
				'value' => Api_Client::base_url(),
			),
			'plan'       => array(
				'label' => __( 'Plan', 'sendfleet' ),
				'value' => Account::is_known() ? Account::plan_label() : __( 'Unknown', 'sendfleet' ),
			),
			'domains'    => array(
				'label' => __( 'Usable sending domains', 'sendfleet' ),
				'value' => Account::is_known() ? self::format_routes( Account::usable_domains() ) : __( 'Unknown', 'sendfleet' ),
			),
			'sender'     => array(
				'label' => __( 'Default sender', 'sendfleet' ),
				'value' => Account::default_sender(),
			),
			'background' => array(
				'label' => __( 'Background sending', 'sendfleet' ),
				'value' => Settings::get( 'background' ) ? __( 'On', 'sendfleet' ) : __( 'Off', 'sendfleet' ),
			),
			'fallback'   => array(
				'label' => __( 'WordPress fallback', 'sendfleet' ),
				'value' => Settings::get( 'fallback' ) ? __( 'On', 'sendfleet' ) : __( 'Off', 'sendfleet' ),
			),
			'queue'      => array(
				'label' => __( 'Queued emails', 'sendfleet' ),
				'value' => (string) Log::queued_count(),
			),
			'snapshot'   => array(
				'label' => __( 'Account data age', 'sendfleet' ),
				'value' => Account::is_known() ? human_time_diff( time() - Account::age() ) : __( 'Never fetched', 'sendfleet' ),
			),
		);

		if ( ! empty( $data['email'] ) ) {
			$fields['account'] = array(
				'label'   => __( 'SendFleet account', 'sendfleet' ),
				'value'   => $data['email'],
				'private' => true,
			);
		}

		$info['sendfleet'] = array(
			'label'  => __( 'SendFleet', 'sendfleet' ),
			'fields' => $fields,
		);

		return $info;
	}

	/**
	 * Formats domain routes for display.
	 *
	 * @param array $routes domain => route.
	 * @return string
	 */
	private static function format_routes( array $routes ) {
		if ( empty( $routes ) ) {
			return __( 'None', 'sendfleet' );
		}

		$out = array();

		foreach ( $routes as $domain => $route ) {
			$out[] = $domain . ' (' . $route . ')';
		}

		return implode( ', ', $out );
	}
}
