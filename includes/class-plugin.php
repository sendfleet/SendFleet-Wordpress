<?php
/**
 * Plugin bootstrap.
 *
 * @package SendFleet
 */

namespace SendFleet;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every component to WordPress.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether boot() has run.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Returns the instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * `plugins_loaded` callback.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		Installer::maybe_upgrade();

		( new Mailer() )->register();

		Queue::register();
		Webhooks::register();
		Privacy::register();
		Site_Health::register();

		add_action( 'init', array( 'SendFleet\\Installer', 'schedule' ) );

		if ( is_admin() ) {
			Admin\Admin::register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'sendfleet', 'SendFleet\\Cli' );
		}
	}
}
