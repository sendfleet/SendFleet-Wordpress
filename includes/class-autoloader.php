<?php
/**
 * Class autoloader.
 *
 * @package SendFleet
 */

namespace SendFleet;

defined( 'ABSPATH' ) || exit;

/**
 * Maps `SendFleet\Foo_Bar` to `includes/class-foo-bar.php` and
 * `SendFleet\Admin\Foo_Bar` to `includes/admin/class-foo-bar.php`.
 */
final class Autoloader {

	/**
	 * Registers the autoloader with SPL.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Loads a class file when the class belongs to this plugin.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function load( $class_name ) {
		if ( 0 !== strpos( $class_name, __NAMESPACE__ . '\\' ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( __NAMESPACE__ ) + 1 );
		$parts    = explode( '\\', $relative );
		$class    = array_pop( $parts );
		$dir      = '';

		foreach ( $parts as $part ) {
			$dir .= strtolower( str_replace( '_', '-', $part ) ) . '/';
		}

		$file = SENDFLEET_PLUGIN_DIR . 'includes/' . $dir . 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
