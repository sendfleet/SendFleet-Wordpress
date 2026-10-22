<?php
/**
 * Plugin Name:       SendFleet – Transactional Email API
 * Plugin URI:        https://docs.sendfleet.net/
 * Description:       Sends every email WordPress sends through the SendFleet API, on SendFleet's managed SES or your own (BYOC). Paste an API key and wp_mail() is handled. No SMTP settings.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            SendFleet
 * Author URI:        https://sendfleet.net/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sendfleet
 * Domain Path:       /languages
 *
 * @package SendFleet
 */

defined( 'ABSPATH' ) || exit;

define( 'SENDFLEET_VERSION', '1.0.0' );
define( 'SENDFLEET_PLUGIN_FILE', __FILE__ );
define( 'SENDFLEET_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SENDFLEET_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SENDFLEET_DEFAULT_API_URL', 'https://api.sendfleet.net' );

require_once SENDFLEET_PLUGIN_DIR . 'includes/class-autoloader.php';

SendFleet\Autoloader::register();

register_activation_hook( __FILE__, array( 'SendFleet\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SendFleet\\Installer', 'deactivate' ) );

add_action( 'plugins_loaded', array( SendFleet\Plugin::instance(), 'boot' ) );
