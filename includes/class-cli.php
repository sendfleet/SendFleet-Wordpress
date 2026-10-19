<?php
/**
 * WP-CLI commands.
 *
 * @package SendFleet
 */

namespace SendFleet;

defined( 'ABSPATH' ) || exit;

/**
 * Manage SendFleet email delivery.
 *
 * ## EXAMPLES
 *
 *     wp sendfleet status
 *     wp sendfleet test you@example.com
 *     wp sendfleet queue
 */
final class Cli {

	/**
	 * Shows the connection, account and queue status.
	 *
	 * ## OPTIONS
	 *
	 * [--refresh]
	 * : Fetch fresh account data from GET /v1/me first.
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function status( $args, $assoc_args ) {
		unset( $args );

		if ( '' === Settings::api_key() ) {
			\WP_CLI::error( 'No API key configured. Use `wp sendfleet connect <key>` or define SENDFLEET_API_KEY.' );
		}

		if ( ! empty( $assoc_args['refresh'] ) || ! Account::is_known() ) {
			$response = Account::refresh();
			if ( 200 !== $response->status ) {
				\WP_CLI::warning( 'GET /v1/me failed: ' . $response->detail() );
			}
		}

		$limit = Account::limit();
		$rows  = array(
			array(
				'field' => 'routing',
				'value' => Mailer::should_route() ? 'on' : 'off',
			),
			array(
				'field' => 'api_key',
				'value' => Settings::api_key_prefix() . '.… (' . Settings::api_key_source() . ')',
			),
			array(
				'field' => 'account',
				'value' => Account::email(),
			),
			array(
				'field' => 'verified',
				'value' => Account::is_verified() ? 'yes' : 'no',
			),
			array(
				'field' => 'plan',
				'value' => Account::plan_label(),
			),
			array(
				'field' => 'usage',
				'value' => Account::used() . ' / ' . ( null === $limit ? 'unknown' : $limit ),
			),
			array(
				'field' => 'domains',
				'value' => implode(
					', ',
					array_map(
						static function ( $domain, $route ) {
							return $domain . ' (' . $route . ')';
						},
						array_keys( Account::usable_domains() ),
						Account::usable_domains()
					)
				),
			),
			array(
				'field' => 'default_sender',
				'value' => Account::default_sender(),
			),
			array(
				'field' => 'queued',
				'value' => (string) Log::queued_count(),
			),
		);

		foreach ( Status::issues() as $issue ) {
			$rows[] = array(
				'field' => 'issue:' . $issue['level'],
				'value' => $issue['message'],
			);
		}

		\WP_CLI\Utils\format_items( isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table', $rows, array( 'field', 'value' ) );
	}

	/**
	 * Saves an API key after checking it against GET /v1/me.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : The API key (prefix.secret).
	 *
	 * @param array $args Positional arguments.
	 * @return void
	 */
	public function connect( $args ) {
		$key = trim( (string) $args[0] );

		if ( 'constant' === Settings::api_key_source() ) {
			\WP_CLI::error( 'SENDFLEET_API_KEY is defined in wp-config.php; change it there.' );
		}

		$response = Account::refresh( $key );

		if ( 200 !== $response->status ) {
			\WP_CLI::error( 'SendFleet did not accept this key: ' . $response->detail() );
		}

		$encrypted = Crypto::encrypt( $key );

		if ( '' === $encrypted ) {
			\WP_CLI::error( 'The key could not be encrypted on this server (no libsodium or OpenSSL). Define SENDFLEET_API_KEY in wp-config.php instead.' );
		}

		Settings::update( array( 'api_key' => $encrypted ) );
		State::clear_all_breakers();

		\WP_CLI::success( sprintf( 'Connected to SendFleet as %s (%s).', Account::email(), Account::plan_label() ) );
	}

	/**
	 * Sends a test email through SendFleet.
	 *
	 * ## OPTIONS
	 *
	 * [<to>]
	 * : Recipient. Defaults to the SendFleet account address.
	 *
	 * [--from=<from>]
	 * : Sender address (must be on a verified domain).
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function test( $args, $assoc_args ) {
		if ( '' === Settings::api_key() ) {
			\WP_CLI::error( 'No API key configured.' );
		}

		Account::maybe_refresh( 300 );

		$to = isset( $args[0] ) ? $args[0] : Account::email();

		if ( ! is_email( $to ) ) {
			\WP_CLI::error( 'Provide a valid recipient address.' );
		}

		$result = Test_Email::send( $to, isset( $assoc_args['from'] ) ? (string) $assoc_args['from'] : '' );

		if ( $result['ok'] ) {
			\WP_CLI::success( $result['message'] );
		} else {
			\WP_CLI::error( $result['message'] );
		}
	}

	/**
	 * Lists or processes the retry/background queue.
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : list or run.
	 * ---
	 * default: list
	 * options:
	 *   - list
	 *   - run
	 * ---
	 *
	 * @param array $args Positional arguments.
	 * @return void
	 */
	public function queue( $args ) {
		$action = isset( $args[0] ) ? $args[0] : 'list';

		if ( 'run' === $action ) {
			$counts = Queue::process( 500 );
			\WP_CLI::success( sprintf( 'Sent %d, waiting for retry %d, failed %d. Still queued: %d.', $counts['sent'], $counts['retry'], $counts['failed'], Log::queued_count() ) );
			return;
		}

		$rows = Log::query(
			array(
				'status'   => Log::STATUS_QUEUED,
				'per_page' => 200,
				'order'    => 'asc',
			)
		);

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'created_at', 'recipient', 'subject', 'attempts', 'error_code' ) );
	}

	/**
	 * Fetches fresh account data from GET /v1/me.
	 *
	 * @return void
	 */
	public function refresh() {
		$response = Account::refresh();

		if ( 200 !== $response->status ) {
			\WP_CLI::error( $response->detail() );
		}

		\WP_CLI::success( sprintf( 'Account data refreshed: %s, %s.', Account::email(), Account::plan_label() ) );
	}

	/**
	 * Deletes finished email log entries.
	 *
	 * ## OPTIONS
	 *
	 * [--older-than=<days>]
	 * : Only entries older than this many days.
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 *
	 * @subcommand purge-log
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function purge_log( $args, $assoc_args ) {
		unset( $args );

		\WP_CLI::confirm( 'Delete SendFleet email log entries?', $assoc_args );

		$deleted = isset( $assoc_args['older-than'] ) ? Log::purge_older_than( (int) $assoc_args['older-than'] ) : Log::purge_all();

		\WP_CLI::success( sprintf( 'Deleted %d log entries.', $deleted ) );
	}
}
