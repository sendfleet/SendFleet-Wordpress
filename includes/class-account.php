<?php
/**
 * Cached account snapshot from GET /v1/me.
 *
 * @package SendFleet
 */

namespace SendFleet;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the last successful GET /v1/me response and answers the questions
 * the mailer needs before every send without calling the API again:
 * which plan applies, which domains can send, and which of them only reach
 * the account owner (the Free plan's Shared smoke test).
 *
 * The API documents /v1/me as something to call at startup or on a
 * schedule, never before each email (it shares the 60/min limit with sends).
 */
final class Account {

	const OPTION = 'sendfleet_account';

	/**
	 * Route: the domain sends through the customer's own AWS SES.
	 */
	const ROUTE_BYOC = 'byoc';

	/**
	 * Route: the domain sends through SendFleet's managed SES, to anyone.
	 */
	const ROUTE_SHARED = 'shared';

	/**
	 * Route: Free plan Shared smoke test — delivers only to the account's own address.
	 */
	const ROUTE_SMOKE = 'smoke';

	/**
	 * Plan keys, display names and monthly limits (null = no SendFleet cap).
	 *
	 * @return array
	 */
	public static function plans() {
		return array(
			'starter'         => array(
				'label'   => __( 'Free BYOC', 'sendfleet' ),
				'limit'   => 50,
				'managed' => false,
			),
			'byoc'            => array(
				'label'   => __( 'BYOC', 'sendfleet' ),
				'limit'   => null,
				'managed' => false,
			),
			'managed_starter' => array(
				'label'   => __( 'Managed Starter', 'sendfleet' ),
				'limit'   => 5000,
				'managed' => true,
			),
			'growth'          => array(
				'label'   => __( 'Managed Growth', 'sendfleet' ),
				'limit'   => 25000,
				'managed' => true,
			),
			'pro'             => array(
				'label'   => __( 'Managed Pro', 'sendfleet' ),
				'limit'   => 100000,
				'managed' => true,
			),
		);
	}

	/**
	 * The stored snapshot for the current key.
	 *
	 * @return array|null Keys: data (the /me body), fetched_at (unix time). Null when none.
	 */
	public static function snapshot() {
		$stored = get_option( self::OPTION );

		if ( ! is_array( $stored ) || empty( $stored['data'] ) || ! is_array( $stored['data'] ) ) {
			return null;
		}

		$fingerprint = Settings::api_key_fingerprint();

		if ( '' === $fingerprint || ! isset( $stored['key'] ) || ! hash_equals( (string) $stored['key'], $fingerprint ) ) {
			return null;
		}

		return $stored;
	}

	/**
	 * The /me body, or an empty array when unknown.
	 *
	 * @return array
	 */
	public static function data() {
		$snapshot = self::snapshot();

		return $snapshot ? $snapshot['data'] : array();
	}

	/**
	 * Whether a snapshot is available.
	 *
	 * @return bool
	 */
	public static function is_known() {
		return null !== self::snapshot();
	}

	/**
	 * Seconds since the snapshot was fetched (PHP_INT_MAX when none).
	 *
	 * @return int
	 */
	public static function age() {
		$snapshot = self::snapshot();

		return $snapshot ? max( 0, time() - (int) $snapshot['fetched_at'] ) : PHP_INT_MAX;
	}

	/**
	 * Fetches /v1/me and stores the result.
	 *
	 * @param string|null $api_key Key to use; defaults to the configured key.
	 * @return Api_Response
	 */
	public static function refresh( $api_key = null ) {
		$client   = new Api_Client( $api_key );
		$response = $client->me();

		update_option( 'sendfleet_account_checked', time(), false );

		if ( 200 === $response->status && is_array( $response->body ) && isset( $response->body['email'] ) ) {
			$key = null === $api_key ? Settings::api_key() : trim( (string) $api_key );

			update_option(
				self::OPTION,
				array(
					'data'       => self::sanitize_me( $response->body ),
					'fetched_at' => time(),
					'key'        => substr( hash( 'sha256', $key ), 0, 16 ),
				),
				true
			);

			State::clear_breaker( 'auth' );
			State::set( 'last_me_error', null );
			self::reconcile_breakers();

			return $response;
		}

		if ( null !== $api_key ) {
			// A candidate key being tested; the configured key's state is untouched.
			return $response;
		}

		if ( 'invalid_api_key' === $response->error_code() ) {
			State::trip_breaker( 'auth', 10 * MINUTE_IN_SECONDS, $response->detail() );
		}

		State::set(
			'last_me_error',
			array(
				'code'   => $response->error_code(),
				'detail' => $response->detail(),
				'time'   => time(),
			)
		);

		return $response;
	}

	/**
	 * Refreshes the snapshot when it is older than the given age.
	 *
	 * Throttled so a failing API is not called on every admin page load.
	 *
	 * @param int $max_age Maximum acceptable age in seconds.
	 * @return void
	 */
	public static function maybe_refresh( $max_age = 600 ) {
		if ( '' === Settings::api_key() ) {
			return;
		}

		$last_check = (int) get_option( 'sendfleet_account_checked', 0 );

		if ( self::age() > $max_age && ( time() - $last_check ) > 60 ) {
			self::refresh();
		}
	}

	/**
	 * Counts an accepted send in the cached usage, so the overview stays close
	 * to the truth between /v1/me refreshes (which correct any drift).
	 *
	 * @param string $mode Mode reported by the API: byoc or shared.
	 * @return void
	 */
	public static function note_sent( $mode ) {
		$stored = get_option( self::OPTION );

		if ( ! is_array( $stored ) || empty( $stored['data'] ) || ! is_array( $stored['data'] ) ) {
			return;
		}

		// On the Free plan the BYOC counter is the plan's one counter, smoke tests included.
		$field = ( 'shared' === $mode && 'starter' !== $stored['data']['current_plan'] ) ? 'shared_usage_current_month' : 'byoc_usage_current_month';

		$stored['data'][ $field ] = (int) $stored['data'][ $field ] + 1;

		update_option( self::OPTION, $stored, true );
	}

	/**
	 * Refreshes the snapshot shortly, outside the current request.
	 *
	 * @return void
	 */
	public static function schedule_refresh() {
		if ( ! wp_next_scheduled( Installer::CRON_REFRESH ) ) {
			wp_schedule_single_event( time() + 30, Installer::CRON_REFRESH );
		}
	}

	/**
	 * Keeps only the documented /me fields with the expected types.
	 *
	 * @param array $body Raw response body.
	 * @return array
	 */
	private static function sanitize_me( array $body ) {
		$domains = static function ( $value ) {
			$out = array();
			foreach ( (array) $value as $domain ) {
				if ( is_string( $domain ) && '' !== $domain ) {
					$out[] = strtolower( sanitize_text_field( $domain ) );
				}
			}
			return array_values( array_unique( $out ) );
		};

		return array(
			'name'                            => isset( $body['name'] ) ? sanitize_text_field( (string) $body['name'] ) : '',
			'email'                           => isset( $body['email'] ) ? sanitize_text_field( (string) $body['email'] ) : '',
			'is_verified'                     => ! empty( $body['is_verified'] ),
			'current_plan'                    => isset( $body['current_plan'] ) ? sanitize_key( (string) $body['current_plan'] ) : 'starter',
			'byoc_usage_current_month'        => isset( $body['byoc_usage_current_month'] ) ? (int) $body['byoc_usage_current_month'] : 0,
			'shared_usage_current_month'      => isset( $body['shared_usage_current_month'] ) ? (int) $body['shared_usage_current_month'] : 0,
			'shared_delivered_current_month'  => isset( $body['shared_delivered_current_month'] ) ? (int) $body['shared_delivered_current_month'] : 0,
			'shared_bounced_current_month'    => isset( $body['shared_bounced_current_month'] ) ? (int) $body['shared_bounced_current_month'] : 0,
			'shared_complained_current_month' => isset( $body['shared_complained_current_month'] ) ? (int) $body['shared_complained_current_month'] : 0,
			'shared_rejected_current_month'   => isset( $body['shared_rejected_current_month'] ) ? (int) $body['shared_rejected_current_month'] : 0,
			'byoc_configured'                 => ! empty( $body['byoc_configured'] ),
			'verified_byoc_domains'           => $domains( isset( $body['verified_byoc_domains'] ) ? $body['verified_byoc_domains'] : array() ),
			'verified_shared_domains'         => $domains( isset( $body['verified_shared_domains'] ) ? $body['verified_shared_domains'] : array() ),
		);
	}

	/**
	 * Clears the quota breaker when fresh account data says it no longer applies.
	 *
	 * A suspension cannot be read from /me, so that breaker only expires with time.
	 *
	 * @return void
	 */
	private static function reconcile_breakers() {
		$limit = self::limit();

		if ( null === $limit || self::used() < $limit ) {
			State::clear_breaker( 'quota' );
		}
	}

	/**
	 * Removes the snapshot (e.g. when the key is removed).
	 *
	 * @return void
	 */
	public static function forget() {
		delete_option( self::OPTION );
		delete_option( 'sendfleet_account_checked' );
	}

	/**
	 * The plan key currently applied by SendFleet.
	 *
	 * @return string
	 */
	public static function plan() {
		$data = self::data();

		return isset( $data['current_plan'] ) && '' !== $data['current_plan'] ? $data['current_plan'] : 'starter';
	}

	/**
	 * Display name of the current plan.
	 *
	 * @return string
	 */
	public static function plan_label() {
		$plans = self::plans();
		$plan  = self::plan();

		return isset( $plans[ $plan ] ) ? $plans[ $plan ]['label'] : ucwords( str_replace( '_', ' ', $plan ) );
	}

	/**
	 * Whether the plan sends through SendFleet's managed SES.
	 *
	 * @return bool
	 */
	public static function is_managed() {
		$plans = self::plans();
		$plan  = self::plan();

		return isset( $plans[ $plan ] ) ? $plans[ $plan ]['managed'] : false;
	}

	/**
	 * Whether the plan is the Free BYOC plan.
	 *
	 * @return bool
	 */
	public static function is_free() {
		return 'starter' === self::plan();
	}

	/**
	 * Monthly send limit, or null when SendFleet applies no cap.
	 *
	 * @return int|null
	 */
	public static function limit() {
		$plans = self::plans();
		$plan  = self::plan();

		return isset( $plans[ $plan ] ) ? $plans[ $plan ]['limit'] : null;
	}

	/**
	 * Sends counted against the plan this month.
	 *
	 * On the Free plan the BYOC counter is the plan's one counter (it
	 * includes Shared smoke tests); on Managed plans the Shared counter is.
	 *
	 * @return int
	 */
	public static function used() {
		$data = self::data();

		if ( self::is_managed() ) {
			return isset( $data['shared_usage_current_month'] ) ? (int) $data['shared_usage_current_month'] : 0;
		}

		return isset( $data['byoc_usage_current_month'] ) ? (int) $data['byoc_usage_current_month'] : 0;
	}

	/**
	 * The account's own address (the only smoke-test recipient).
	 *
	 * @return string
	 */
	public static function email() {
		$data = self::data();

		return isset( $data['email'] ) ? (string) $data['email'] : '';
	}

	/**
	 * Whether the account email is verified (sending is blocked until it is).
	 *
	 * @return bool
	 */
	public static function is_verified() {
		$data = self::data();

		return ! empty( $data['is_verified'] );
	}

	/**
	 * Whether the BYOC IAM role is validated.
	 *
	 * @return bool
	 */
	public static function byoc_configured() {
		$data = self::data();

		return ! empty( $data['byoc_configured'] );
	}

	/**
	 * Verified BYOC domains.
	 *
	 * @return string[]
	 */
	public static function byoc_domains() {
		$data = self::data();

		return isset( $data['verified_byoc_domains'] ) ? (array) $data['verified_byoc_domains'] : array();
	}

	/**
	 * Verified Shared SES domains.
	 *
	 * @return string[]
	 */
	public static function shared_domains() {
		$data = self::data();

		return isset( $data['verified_shared_domains'] ) ? (array) $data['verified_shared_domains'] : array();
	}

	/**
	 * Domains that can send on the current plan, mapped to their route.
	 *
	 * Ordered by preference: domains that reach anyone first, smoke-test
	 * domains last. Mirrors the API's routing: Managed plans use Shared
	 * domains; BYOC plans use BYOC domains; the Free plan uses BYOC domains
	 * and, as a smoke test, Shared domains.
	 *
	 * @return array<string,string> domain => route
	 */
	public static function usable_domains() {
		$routes = array();
		$plans  = self::plans();
		$plan   = self::plan();

		if ( ! isset( $plans[ $plan ] ) ) {
			// Unknown (newer) plan: offer everything and let the API decide.
			foreach ( self::byoc_domains() as $domain ) {
				$routes[ $domain ] = self::ROUTE_BYOC;
			}
			foreach ( self::shared_domains() as $domain ) {
				if ( ! isset( $routes[ $domain ] ) ) {
					$routes[ $domain ] = self::ROUTE_SHARED;
				}
			}
			return $routes;
		}

		if ( self::is_managed() ) {
			foreach ( self::shared_domains() as $domain ) {
				$routes[ $domain ] = self::ROUTE_SHARED;
			}
			return $routes;
		}

		if ( self::byoc_configured() || 'byoc' === $plan ) {
			foreach ( self::byoc_domains() as $domain ) {
				$routes[ $domain ] = self::ROUTE_BYOC;
			}
		}

		if ( self::is_free() ) {
			foreach ( self::shared_domains() as $domain ) {
				if ( ! isset( $routes[ $domain ] ) ) {
					$routes[ $domain ] = self::ROUTE_SMOKE;
				}
			}
		}

		return $routes;
	}

	/**
	 * The route a sender address would take, or null when its domain cannot send.
	 *
	 * The API matches the sender's domain exactly (no subdomain matching).
	 *
	 * @param string $email Sender address.
	 * @return string|null
	 */
	public static function route_for( $email ) {
		$domain = self::domain_of( $email );
		$routes = self::usable_domains();

		return ( '' !== $domain && isset( $routes[ $domain ] ) ) ? $routes[ $domain ] : null;
	}

	/**
	 * Whether every usable domain is smoke-test only (Free plan, Shared domain, no BYOC).
	 *
	 * @return bool
	 */
	public static function is_smoke_only() {
		$routes = self::usable_domains();

		return ! empty( $routes ) && ! in_array( self::ROUTE_BYOC, $routes, true ) && ! in_array( self::ROUTE_SHARED, $routes, true );
	}

	/**
	 * Lower-cased domain of an address (after the last @).
	 *
	 * @param string $email Address.
	 * @return string
	 */
	public static function domain_of( $email ) {
		$email = strtolower( trim( (string) $email ) );
		$at    = strrpos( $email, '@' );

		return false === $at ? '' : substr( $email, $at + 1 );
	}

	/**
	 * Normalises an address the way the smoke-test gate compares it:
	 * local part exact, domain lower-cased.
	 *
	 * @param string $email Address.
	 * @return string '' when the address has no usable @.
	 */
	public static function normalize_address( $email ) {
		$email = trim( (string) $email );
		$at    = strrpos( $email, '@' );

		if ( false === $at || 0 === $at || strlen( $email ) - 1 === $at ) {
			return '';
		}

		return substr( $email, 0, $at ) . '@' . strtolower( substr( $email, $at + 1 ) );
	}

	/**
	 * The sender address to use when a message's own sender cannot send.
	 *
	 * The configured default wins, unless the account data shows its domain
	 * cannot send (a typo there would otherwise fail every email; the admin is
	 * warned instead). Otherwise: wordpress@ the site's domain when that domain
	 * is usable, else wordpress@ the first usable domain.
	 *
	 * @return string '' when nothing usable is known.
	 */
	public static function default_sender() {
		$configured = Settings::from_email();
		$routes     = self::usable_domains();

		if ( '' !== $configured && ( ! self::is_known() || empty( $routes ) || null !== self::route_for( $configured ) ) ) {
			return $configured;
		}

		if ( empty( $routes ) ) {
			return '';
		}

		$host = (string) wp_parse_url( network_home_url(), PHP_URL_HOST );
		$host = strtolower( preg_replace( '/^www\./i', '', $host ) );

		if ( '' !== $host && isset( $routes[ $host ] ) ) {
			return 'wordpress@' . $host;
		}

		// Prefer a domain that reaches anyone over a smoke-test domain.
		foreach ( $routes as $domain => $route ) {
			if ( self::ROUTE_SMOKE !== $route ) {
				return 'wordpress@' . $domain;
			}
		}

		$domains = array_keys( $routes );

		return 'wordpress@' . $domains[0];
	}
}
