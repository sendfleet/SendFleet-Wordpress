<?php
/**
 * Admin screens and actions.
 *
 * @package SendFleet
 */

namespace SendFleet\Admin;

use SendFleet\Account;
use SendFleet\Crypto;
use SendFleet\Log;
use SendFleet\Queue;
use SendFleet\Settings;
use SendFleet\State;
use SendFleet\Status;
use SendFleet\Suppressions;
use SendFleet\Test_Email;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the SendFleet menu, renders its three screens and handles every
 * form they post (through admin-post.php, each with its own nonce).
 */
final class Admin {

	const CAPABILITY = 'manage_options';
	const SLUG       = 'sendfleet';
	const SLUG_LOG   = 'sendfleet-log';
	const SLUG_SET   = 'sendfleet-settings';

	/**
	 * Hook suffixes of the plugin's screens.
	 *
	 * @var string[]
	 */
	private static $hooks = array();

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SENDFLEET_PLUGIN_FILE ), array( __CLASS__, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );

		foreach ( array( 'save_settings', 'connect', 'disconnect', 'refresh', 'test', 'purge_log', 'suppression', 'queue_run', 'log_bulk' ) as $action ) {
			add_action( 'admin_post_sendfleet_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
	}

	/**
	 * The menu icon: the SendFleet mark as a single-colour SVG WordPress can tint.
	 *
	 * @return string
	 */
	private static function menu_icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" fill-rule="evenodd" d="M4 0h12a4 4 0 0 1 4 4v12a4 4 0 0 1-4 4H4a4 4 0 0 1-4-4V4a4 4 0 0 1 4-4zm-.5 4.6a.7.7 0 0 0-.7.7v9.4a.7.7 0 0 0 .7.7h13a.7.7 0 0 0 .7-.7V5.3a.7.7 0 0 0-.7-.7z"/><path fill="black" d="M2.8 4.6h1.02L10 8.55l6.18-3.95h1.02v.65L10 9.85 2.8 5.25zM2.8 14.8l3.7-4.26.6.52-3.8 4.34H2.8zM17.2 14.8l-3.7-4.26-.6.52 3.8 4.34h.5z"/></svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Menu icons must be data URIs.
	}

	/**
	 * `admin_menu` callback.
	 *
	 * @return void
	 */
	public static function menu() {
		self::$hooks[] = add_menu_page(
			__( 'SendFleet', 'sendfleet' ),
			__( 'SendFleet', 'sendfleet' ),
			self::CAPABILITY,
			self::SLUG,
			array( __CLASS__, 'render_overview' ),
			self::menu_icon(),
			81
		);

		self::$hooks[] = add_submenu_page( self::SLUG, __( 'SendFleet Overview', 'sendfleet' ), __( 'Overview', 'sendfleet' ), self::CAPABILITY, self::SLUG, array( __CLASS__, 'render_overview' ) );
		self::$hooks[] = add_submenu_page( self::SLUG, __( 'SendFleet Email Log', 'sendfleet' ), __( 'Email Log', 'sendfleet' ), self::CAPABILITY, self::SLUG_LOG, array( __CLASS__, 'render_log' ) );
		self::$hooks[] = add_submenu_page( self::SLUG, __( 'SendFleet Settings', 'sendfleet' ), __( 'Settings', 'sendfleet' ), self::CAPABILITY, self::SLUG_SET, array( __CLASS__, 'render_settings' ) );

		foreach ( self::$hooks as $hook ) {
			add_action( 'load-' . $hook, array( __CLASS__, 'on_load' ) );
		}
	}

	/**
	 * Runs before a plugin screen renders: refreshes stale account data.
	 *
	 * @return void
	 */
	public static function on_load() {
		Account::maybe_refresh( 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Whether the current screen is one of the plugin's.
	 *
	 * @param string $hook Hook suffix.
	 * @return bool
	 */
	private static function is_plugin_screen( $hook = '' ) {
		if ( '' === $hook ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$hook   = $screen ? $screen->id : '';
		}

		return in_array( $hook, self::$hooks, true );
	}

	/**
	 * `admin_enqueue_scripts` callback.
	 *
	 * @param string $hook Hook suffix.
	 * @return void
	 */
	public static function assets( $hook ) {
		if ( ! self::is_plugin_screen( $hook ) ) {
			return;
		}

		wp_enqueue_style( 'sendfleet-admin', SENDFLEET_PLUGIN_URL . 'assets/css/admin.css', array(), SENDFLEET_VERSION );
		wp_enqueue_script( 'sendfleet-admin', SENDFLEET_PLUGIN_URL . 'assets/js/admin.js', array(), SENDFLEET_VERSION, true );
		wp_localize_script(
			'sendfleet-admin',
			'sendfleetAdmin',
			array(
				'copied'  => __( 'Copied', 'sendfleet' ),
				'show'    => __( 'Show', 'sendfleet' ),
				'hide'    => __( 'Hide', 'sendfleet' ),
				'confirm' => __( 'Are you sure?', 'sendfleet' ),
			)
		);
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		$label = '' === Settings::api_key() ? __( 'Connect', 'sendfleet' ) : __( 'Settings', 'sendfleet' );

		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ), esc_html( $label ) ) );

		return $links;
	}

	/**
	 * Documentation links on the Plugins screen.
	 *
	 * @param array  $meta Row meta.
	 * @param string $file Plugin file.
	 * @return array
	 */
	public static function row_meta( $meta, $file ) {
		if ( plugin_basename( SENDFLEET_PLUGIN_FILE ) === $file ) {
			$meta[] = '<a href="https://docs.sendfleet.net/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'API documentation', 'sendfleet' ) . '</a>';
			$meta[] = '<a href="mailto:support@sendfleet.net">' . esc_html__( 'Support', 'sendfleet' ) . '</a>';
		}

		return $meta;
	}

	/**
	 * Notices on the Dashboard and Plugins screens (the plugin's own screens show them inline).
	 *
	 * @return void
	 */
	public static function notices() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) {
			return;
		}

		foreach ( Status::issues() as $issue ) {
			if ( 'info' === $issue['level'] ) {
				continue;
			}

			printf(
				'<div class="notice notice-%1$s"><p><strong>%2$s</strong> %3$s <a href="%4$s">%5$s</a></p></div>',
				esc_attr( 'error' === $issue['level'] ? 'error' : 'warning' ),
				esc_html__( 'SendFleet:', 'sendfleet' ),
				esc_html( $issue['message'] ),
				esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ),
				'not_connected' === $issue['id'] ? esc_html__( 'Connect SendFleet', 'sendfleet' ) : esc_html__( 'Open SendFleet', 'sendfleet' )
			);
		}
	}

	// ── Flash messages ─────────────────────────────────────────────────────

	/**
	 * Stores a message to show after the redirect.
	 *
	 * @param string $type    success, error, warning or info.
	 * @param string $message Message.
	 * @return void
	 */
	private static function flash( $type, $message ) {
		$key      = 'sendfleet_flash_' . get_current_user_id();
		$messages = get_transient( $key );
		$messages = is_array( $messages ) ? $messages : array();

		$messages[] = array(
			'type'    => $type,
			'message' => $message,
		);

		set_transient( $key, $messages, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Prints and clears stored messages.
	 *
	 * @return void
	 */
	public static function render_flash() {
		$key      = 'sendfleet_flash_' . get_current_user_id();
		$messages = get_transient( $key );

		if ( ! is_array( $messages ) || empty( $messages ) ) {
			return;
		}

		delete_transient( $key );

		foreach ( $messages as $message ) {
			$type = in_array( $message['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $message['type'] : 'info';

			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $message['message'] ) );
		}
	}

	/**
	 * Verifies capability and nonce for a form action.
	 *
	 * @param string $action Action name.
	 * @return void
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage SendFleet.', 'sendfleet' ), 403 );
		}

		check_admin_referer( 'sendfleet_' . $action );
	}

	/**
	 * Redirects back to a plugin screen.
	 *
	 * @param string $page Page slug.
	 * @param array  $args Extra query arguments.
	 * @return void
	 */
	private static function back( $page, array $args = array() ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Reads a POST field as a string (unslashed, untrimmed for secrets).
	 *
	 * @param string $name Field name.
	 * @return string
	 */
	private static function post( $name ) {
		// Nonce verified in guard() before any handler reads input.
		return isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by each caller for its field type.
	}

	/**
	 * Reads a POST checkbox.
	 *
	 * @param string $name Field name.
	 * @return bool
	 */
	private static function checkbox( $name ) {
		return isset( $_POST[ $name ] ) && '1' === $_POST[ $name ]; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
	}

	// ── Actions ────────────────────────────────────────────────────────────

	/**
	 * Validates and stores an API key.
	 *
	 * @param string $key Candidate key.
	 * @return bool Whether it was saved.
	 */
	private static function save_api_key( $key ) {
		$key = trim( preg_replace( '/\s+/', '', $key ) );

		if ( '' === $key ) {
			return false;
		}

		if ( ! Settings::looks_like_api_key( $key ) ) {
			self::flash( 'error', __( 'That does not look like a SendFleet API key. Keys have the form prefix.secret (8 characters, a dot, 32 characters). Copy the whole key from the SendFleet dashboard.', 'sendfleet' ) );
			return false;
		}

		$response = Account::refresh( $key );

		if ( 403 === $response->status && 'invalid_api_key' === $response->error_code() ) {
			/* translators: %s: API error detail. */
			self::flash( 'error', sprintf( __( 'SendFleet rejected this API key: %s Check that you copied the whole key and that it is not revoked or expired.', 'sendfleet' ), $response->detail() ) );
			return false;
		}

		$encrypted = Crypto::encrypt( $key );

		if ( '' === $encrypted ) {
			self::flash( 'error', __( 'This server cannot encrypt the key (neither libsodium nor OpenSSL is available). Define SENDFLEET_API_KEY in wp-config.php instead.', 'sendfleet' ) );
			return false;
		}

		Settings::update( array( 'api_key' => $encrypted ) );
		State::clear_all_breakers();
		State::set( 'last_me_error', null );

		if ( 200 === $response->status ) {
			/* translators: 1: account email, 2: plan name. */
			self::flash( 'success', sprintf( __( 'Connected to SendFleet as %1$s (%2$s). WordPress email now goes through SendFleet.', 'sendfleet' ), Account::email(), Account::plan_label() ) );
		} else {
			/* translators: %s: error detail. */
			self::flash( 'warning', sprintf( __( 'The API key was saved, but SendFleet could not be reached to confirm it: %s', 'sendfleet' ), $response->detail() ) );
		}

		return true;
	}

	/**
	 * Quick connect from the overview screen.
	 *
	 * @return void
	 */
	public static function handle_connect() {
		self::guard( 'connect' );

		if ( 'constant' !== Settings::api_key_source() ) {
			if ( '' === trim( self::post( 'api_key' ) ) ) {
				self::flash( 'error', __( 'Paste your SendFleet API key first.', 'sendfleet' ) );
			} else {
				self::save_api_key( self::post( 'api_key' ) );
			}
		}

		self::back( self::SLUG );
	}

	/**
	 * Removes the stored key.
	 *
	 * @return void
	 */
	public static function handle_disconnect() {
		self::guard( 'disconnect' );

		Settings::update( array( 'api_key' => '' ) );
		Account::forget();
		State::clear_all_breakers();
		State::set( 'last_me_error', null );

		self::flash( 'success', __( 'The API key was removed. WordPress is sending email the default way again.', 'sendfleet' ) );
		self::back( self::SLUG );
	}

	/**
	 * Saves the settings screen.
	 *
	 * @return void
	 */
	public static function handle_save_settings() {
		self::guard( 'save_settings' );

		$values = array(
			'enabled'             => self::checkbox( 'enabled' ),
			'force_from'          => self::checkbox( 'force_from' ),
			'force_from_name'     => self::checkbox( 'force_from_name' ),
			'background'          => self::checkbox( 'background' ),
			'fallback'            => self::checkbox( 'fallback' ),
			'log_enabled'         => self::checkbox( 'log_enabled' ),
			'suppression_enabled' => self::checkbox( 'suppression_enabled' ),
			'log_retention'       => max( 1, min( 365, absint( self::post( 'log_retention' ) ) ) ),
			'from_name'           => sanitize_text_field( self::post( 'from_name' ) ),
		);

		$errors = array();

		if ( ! Settings::is_constant( 'from_email' ) ) {
			$from = sanitize_email( self::post( 'from_email' ) );

			if ( '' !== trim( self::post( 'from_email' ) ) && ! is_email( $from ) ) {
				$errors[] = __( 'The default sender is not a valid email address and was not saved.', 'sendfleet' );
			} else {
				$values['from_email'] = $from;

				if ( '' !== $from && Account::is_known() && null === Account::route_for( $from ) ) {
					/* translators: %s: email address. */
					$errors[] = sprintf( __( '%s is not on a verified sending domain of your SendFleet account, so it cannot be used as the sender yet. Pick an address on one of the domains listed.', 'sendfleet' ), $from );
				}
			}
		}

		$reply = sanitize_email( self::post( 'reply_to' ) );

		if ( '' !== trim( self::post( 'reply_to' ) ) && ! is_email( $reply ) ) {
			$errors[] = __( 'The default Reply-To is not a valid email address and was not saved.', 'sendfleet' );
		} else {
			$values['reply_to'] = $reply;
		}

		if ( ! Settings::is_constant( 'webhook_secret' ) ) {
			if ( self::checkbox( 'clear_webhook_secret' ) ) {
				$values['webhook_secret'] = '';
			} elseif ( '' !== trim( self::post( 'webhook_secret' ) ) ) {
				$encrypted = Crypto::encrypt( trim( self::post( 'webhook_secret' ) ) );

				if ( '' === $encrypted ) {
					$errors[] = __( 'The webhook secret could not be encrypted on this server. Define SENDFLEET_WEBHOOK_SECRET in wp-config.php instead.', 'sendfleet' );
				} else {
					$values['webhook_secret'] = $encrypted;
				}
			}
		}

		Settings::update( $values );

		if ( 'constant' !== Settings::api_key_source() && '' !== trim( self::post( 'api_key' ) ) ) {
			self::save_api_key( self::post( 'api_key' ) );
		}

		foreach ( $errors as $error ) {
			self::flash( 'error', $error );
		}

		self::flash( 'success', __( 'Settings saved.', 'sendfleet' ) );
		self::back( self::SLUG_SET );
	}

	/**
	 * Refreshes the account data.
	 *
	 * @return void
	 */
	public static function handle_refresh() {
		self::guard( 'refresh' );

		$response = Account::refresh();

		if ( 200 === $response->status ) {
			self::flash( 'success', __( 'Account details refreshed from SendFleet.', 'sendfleet' ) );
		} else {
			/* translators: %s: error detail. */
			self::flash( 'error', sprintf( __( 'Could not refresh the account details: %s', 'sendfleet' ), $response->detail() ) );
		}

		self::back( self::SLUG );
	}

	/**
	 * Sends a test email.
	 *
	 * @return void
	 */
	public static function handle_test() {
		self::guard( 'test' );

		$to   = sanitize_email( self::post( 'to' ) );
		$from = sanitize_email( self::post( 'from' ) );

		if ( ! is_email( $to ) ) {
			self::flash( 'error', __( 'Enter a valid recipient address for the test email.', 'sendfleet' ) );
			self::back( self::SLUG );
		}

		$result = Test_Email::send( $to, $from );

		self::flash( $result['ok'] ? 'success' : 'error', $result['message'] );
		self::back( self::SLUG );
	}

	/**
	 * Deletes the email log.
	 *
	 * @return void
	 */
	public static function handle_purge_log() {
		self::guard( 'purge_log' );

		$deleted = Log::purge_all();

		/* translators: %s: number of entries. */
		self::flash( 'success', sprintf( _n( '%s log entry deleted.', '%s log entries deleted.', $deleted, 'sendfleet' ), number_format_i18n( $deleted ) ) );
		self::back( self::SLUG_LOG );
	}

	/**
	 * Adds to or removes from the suppression list.
	 *
	 * @return void
	 */
	public static function handle_suppression() {
		self::guard( 'suppression' );

		$operation = sanitize_key( self::post( 'operation' ) );

		if ( 'add' === $operation ) {
			$email = sanitize_email( self::post( 'email' ) );

			if ( ! is_email( $email ) ) {
				self::flash( 'error', __( 'Enter a valid email address.', 'sendfleet' ) );
			} elseif ( Suppressions::add( $email, 'manual', __( 'Added by an administrator.', 'sendfleet' ) ) ) {
				/* translators: %s: email address. */
				self::flash( 'success', sprintf( __( '%s will no longer receive email from this site.', 'sendfleet' ), $email ) );
			} else {
				/* translators: %s: email address. */
				self::flash( 'info', sprintf( __( '%s is already on the suppression list.', 'sendfleet' ), $email ) );
			}
		} elseif ( 'remove' === $operation ) {
			$emails = isset( $_POST['emails'] ) && is_array( $_POST['emails'] ) ? array_map( 'sanitize_email', wp_unslash( $_POST['emails'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
			$single = sanitize_email( self::post( 'email' ) );

			if ( '' !== $single ) {
				$emails[] = $single;
			}

			$removed = Suppressions::remove( array_filter( $emails ) );

			/* translators: %s: number of addresses. */
			self::flash( 'success', sprintf( _n( '%s address removed from the suppression list.', '%s addresses removed from the suppression list.', $removed, 'sendfleet' ), number_format_i18n( $removed ) ) );
		}

		self::back( self::SLUG_LOG, array( 'tab' => 'suppressions' ) );
	}

	/**
	 * Processes the queue now.
	 *
	 * @return void
	 */
	public static function handle_queue_run() {
		self::guard( 'queue_run' );

		$counts = Queue::process( 200 );

		self::flash(
			'success',
			sprintf(
				/* translators: 1: sent, 2: waiting, 3: failed, 4: still queued. */
				__( 'Queue processed: %1$d sent, %2$d waiting to retry, %3$d failed. %4$d still queued.', 'sendfleet' ),
				$counts['sent'],
				$counts['retry'],
				$counts['failed'],
				Log::queued_count()
			)
		);

		self::back( self::SLUG_LOG );
	}

	/**
	 * Bulk delete from the email log.
	 *
	 * @return void
	 */
	public static function handle_log_bulk() {
		self::guard( 'log_bulk' );

		$ids = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'absint', wp_unslash( $_POST['ids'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().

		if ( empty( $ids ) ) {
			$single = absint( self::post( 'id' ) );
			if ( $single ) {
				$ids[] = $single;
			}
		}

		$deleted = Log::delete( $ids );

		/* translators: %s: number of entries. */
		self::flash( 'success', sprintf( _n( '%s log entry deleted.', '%s log entries deleted.', $deleted, 'sendfleet' ), number_format_i18n( $deleted ) ) );
		self::back( self::SLUG_LOG );
	}

	// ── Screens ────────────────────────────────────────────────────────────

	/**
	 * Renders the overview screen.
	 *
	 * @return void
	 */
	public static function render_overview() {
		self::view( 'overview' );
	}

	/**
	 * Renders the email log screen.
	 *
	 * @return void
	 */
	public static function render_log() {
		self::view( 'log' );
	}

	/**
	 * Renders the settings screen.
	 *
	 * @return void
	 */
	public static function render_settings() {
		self::view( 'settings' );
	}

	/**
	 * Includes a view template.
	 *
	 * @param string $name View name.
	 * @return void
	 */
	private static function view( $name ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage SendFleet.', 'sendfleet' ), 403 );
		}

		include SENDFLEET_PLUGIN_DIR . 'includes/views/' . $name . '.php';
	}

	/**
	 * Prints the branded header and the tab bar.
	 *
	 * @param string $current Current page slug.
	 * @return void
	 */
	public static function header( $current ) {
		$tabs = array(
			self::SLUG     => __( 'Overview', 'sendfleet' ),
			self::SLUG_LOG => __( 'Email Log', 'sendfleet' ),
			self::SLUG_SET => __( 'Settings', 'sendfleet' ),
		);

		$connected = '' !== Settings::api_key() && Settings::is_enabled() && ! State::breaker( 'auth' );
		?>
		<div class="sf-header">
			<div class="sf-header__brand">
				<img class="sf-header__mark" src="<?php echo esc_url( SENDFLEET_PLUGIN_URL . 'assets/img/sendfleet-mark.svg' ); ?>" alt="" width="32" height="32" />
				<span class="sf-wordmark">Send<span>Fleet</span></span>
			</div>
			<span class="sf-pill <?php echo $connected ? 'sf-pill--ok' : 'sf-pill--off'; ?>">
				<span class="sf-pill__dot"></span>
				<?php echo $connected ? esc_html__( 'Routing wp_mail()', 'sendfleet' ) : esc_html__( 'Not routing', 'sendfleet' ); ?>
			</span>
		</div>
		<nav class="sf-tabs" aria-label="<?php esc_attr_e( 'SendFleet sections', 'sendfleet' ); ?>">
			<?php foreach ( $tabs as $slug => $label ) : ?>
				<a class="sf-tabs__item<?php echo $slug === $current ? ' is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>"<?php echo $slug === $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
		// WordPress moves notices below the first heading; keep one here for that.
		echo '<h1 class="screen-reader-text">' . esc_html( $tabs[ $current ] ) . '</h1>';
		self::render_flash();
	}

	/**
	 * Prints the current issues as callouts.
	 *
	 * @param bool $include_info Whether to include informational items.
	 * @return void
	 */
	public static function issues( $include_info = true ) {
		foreach ( Status::issues() as $issue ) {
			if ( ! $include_info && 'info' === $issue['level'] ) {
				continue;
			}
			printf( '<div class="sf-callout sf-callout--%1$s"><p>%2$s</p></div>', esc_attr( $issue['level'] ), esc_html( $issue['message'] ) );
		}
	}

	/**
	 * Prints a status badge.
	 *
	 * @param string $status Log status.
	 * @return void
	 */
	public static function badge( $status ) {
		$labels = Log::statuses();
		$tone   = 'neutral';

		if ( in_array( $status, array( Log::STATUS_ACCEPTED, Log::STATUS_SENT, Log::STATUS_DELIVERED ), true ) ) {
			$tone = 'ok';
		} elseif ( in_array( $status, array( Log::STATUS_FAILED, Log::STATUS_BOUNCED, Log::STATUS_COMPLAINED, Log::STATUS_REJECTED, Log::STATUS_UNDELIVERED ), true ) ) {
			$tone = 'bad';
		} elseif ( in_array( $status, array( Log::STATUS_QUEUED, Log::STATUS_SENDING, Log::STATUS_DELAYED, Log::STATUS_FALLBACK ), true ) ) {
			$tone = 'info';
		} elseif ( in_array( $status, array( Log::STATUS_SKIPPED, Log::STATUS_SUPPRESSED ), true ) ) {
			$tone = 'warn';
		}

		printf( '<span class="sf-badge sf-badge--%1$s">%2$s</span>', esc_attr( $tone ), esc_html( isset( $labels[ $status ] ) ? $labels[ $status ] : $status ) );
	}

	/**
	 * Formats a UTC datetime column in the site's timezone.
	 *
	 * @param string $utc Y-m-d H:i:s in UTC.
	 * @return string
	 */
	public static function datetime( $utc ) {
		if ( empty( $utc ) ) {
			return '—';
		}

		$timestamp = strtotime( $utc . ' UTC' );

		return false === $timestamp ? (string) $utc : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * The hidden fields every form needs.
	 *
	 * @param string $action Action name.
	 * @return void
	 */
	public static function form_fields( $action ) {
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( 'sendfleet_' . $action ) );
		wp_nonce_field( 'sendfleet_' . $action );
	}

	/**
	 * The admin-post.php URL.
	 *
	 * @return string
	 */
	public static function post_url() {
		return admin_url( 'admin-post.php' );
	}
}
