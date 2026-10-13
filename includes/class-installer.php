<?php
/**
 * Database schema, activation and deactivation.
 *
 * @package SendFleet
 */

namespace SendFleet;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin's two tables, and owns the cron schedule.
 *
 * Tables are checked against a stored schema version on every load (one
 * autoloaded option read), so multisite sub-sites and upgrades that skip the
 * activation hook are handled without a separate migration step.
 */
final class Installer {

	const DB_VERSION        = '1';
	const DB_VERSION_OPTION = 'sendfleet_db_version';

	/**
	 * Cron hook: retries and background sends.
	 */
	const CRON_QUEUE = 'sendfleet_process_queue';

	/**
	 * Cron hook: hourly housekeeping.
	 */
	const CRON_MAINTENANCE = 'sendfleet_maintenance';

	/**
	 * Cron hook: deferred account refresh.
	 */
	const CRON_REFRESH = 'sendfleet_refresh_account';

	/**
	 * Activation hook.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( $site_id );
				self::install();
				restore_current_blog();
			}
			return;
		}

		self::install();
	}

	/**
	 * Deactivation hook: removes scheduled events. Data stays until uninstall.
	 *
	 * @param bool $network_wide Whether the plugin is being network-deactivated.
	 * @return void
	 */
	public static function deactivate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( $site_id );
				self::unschedule();
				restore_current_blog();
			}
			return;
		}

		self::unschedule();
	}

	/**
	 * Creates tables and schedules events for the current site.
	 *
	 * @return void
	 */
	public static function install() {
		self::create_tables();
		self::schedule();
	}

	/**
	 * Runs the schema upgrade when the stored version is behind.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( self::DB_VERSION !== get_option( self::DB_VERSION_OPTION ) ) {
			self::create_tables();
		}
	}

	/**
	 * The log table name.
	 *
	 * @return string
	 */
	public static function log_table() {
		global $wpdb;

		return $wpdb->prefix . 'sendfleet_log';
	}

	/**
	 * The suppression table name.
	 *
	 * @return string
	 */
	public static function suppression_table() {
		global $wpdb;

		return $wpdb->prefix . 'sendfleet_suppressions';
	}

	/**
	 * Creates or updates both tables with dbDelta().
	 *
	 * @return void
	 */
	private static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$log             = self::log_table();
		$suppressions    = self::suppression_table();

		dbDelta(
			"CREATE TABLE {$log} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  mail_id char(32) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  status varchar(20) NOT NULL DEFAULT '',
  recipient varchar(255) NOT NULL DEFAULT '',
  recipient_kind varchar(3) NOT NULL DEFAULT 'to',
  from_email varchar(255) NOT NULL DEFAULT '',
  subject text NOT NULL,
  route varchar(10) NOT NULL DEFAULT '',
  mode varchar(10) NOT NULL DEFAULT '',
  message_id varchar(100) NOT NULL DEFAULT '',
  idempotency_key varchar(191) NOT NULL DEFAULT '',
  http_status smallint(5) unsigned NOT NULL DEFAULT 0,
  error_code varchar(64) NOT NULL DEFAULT '',
  error_message text NOT NULL,
  notes text NOT NULL,
  attachments smallint(5) unsigned NOT NULL DEFAULT 0,
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  retry_class varchar(12) NOT NULL DEFAULT '',
  next_attempt_at datetime DEFAULT NULL,
  locked_until datetime DEFAULT NULL,
  source varchar(191) NOT NULL DEFAULT '',
  payload longtext,
  PRIMARY KEY  (id),
  KEY status_next (status,next_attempt_at),
  KEY idempotency_key (idempotency_key),
  KEY created_at (created_at),
  KEY recipient (recipient(191)),
  KEY mail_id (mail_id)
) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$suppressions} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  email varchar(191) NOT NULL DEFAULT '',
  reason varchar(32) NOT NULL DEFAULT '',
  detail text NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY email (email)
) {$charset_collate};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
	}

	/**
	 * Schedules the recurring housekeeping event.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_MAINTENANCE ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_MAINTENANCE );
		}
	}

	/**
	 * Removes every scheduled event of the plugin.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_MAINTENANCE );
		wp_clear_scheduled_hook( self::CRON_QUEUE );
		wp_clear_scheduled_hook( self::CRON_REFRESH );
	}
}
