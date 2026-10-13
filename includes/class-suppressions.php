<?php
/**
 * Local suppression list.
 *
 * @package SendFleet
 */

namespace SendFleet;

defined( 'ABSPATH' ) || exit;

/**
 * Addresses this site will not send to.
 *
 * SendFleet accepts a send to an address on its own suppression list and
 * then resolves it as suppressed, so every retry still costs a send from the
 * monthly allowance. The API documentation recommends mirroring suppressions
 * locally; webhooks (hard bounces, complaints, suppressions) fill this list
 * automatically, and admins can add or remove addresses by hand.
 */
final class Suppressions {

	/**
	 * Request cache of looked-up addresses.
	 *
	 * @var array<string,bool>
	 */
	private static $cache = array();

	/**
	 * Clears the request cache.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = array();
	}

	/**
	 * Normalises an address for storage and lookup.
	 *
	 * @param string $email Address.
	 * @return string
	 */
	public static function normalize( $email ) {
		return strtolower( trim( (string) $email ) );
	}

	/**
	 * Whether sends to an address are blocked.
	 *
	 * @param string $email Address.
	 * @return bool
	 */
	public static function is_suppressed( $email ) {
		global $wpdb;

		$email = self::normalize( $email );

		if ( '' === $email ) {
			return false;
		}

		if ( ! isset( self::$cache[ $email ] ) ) {
			$table                 = Installer::suppression_table();
			self::$cache[ $email ] = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE email = %s LIMIT 1', $table, $email ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		}

		return self::$cache[ $email ];
	}

	/**
	 * Adds an address (no-op when already present).
	 *
	 * @param string $email  Address.
	 * @param string $reason bounced, complained, suppressed or manual.
	 * @param string $detail Free text.
	 * @return bool Whether a row was added.
	 */
	public static function add( $email, $reason, $detail = '' ) {
		global $wpdb;

		$email = self::normalize( $email );

		if ( ! is_email( $email ) ) {
			return false;
		}

		$table = Installer::suppression_table();

		$inserted = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (email, reason, detail, created_at) VALUES (%s, %s, %s, %s)', $table, $email, substr( sanitize_key( $reason ), 0, 32 ), sanitize_text_field( (string) $detail ), gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.

		self::$cache[ $email ] = true;

		return 1 === (int) $inserted;
	}

	/**
	 * Removes addresses.
	 *
	 * @param string[] $emails Addresses.
	 * @return int Rows removed.
	 */
	public static function remove( array $emails ) {
		global $wpdb;

		$removed = 0;

		foreach ( $emails as $email ) {
			$email = self::normalize( $email );
			unset( self::$cache[ $email ] );
			$removed += (int) $wpdb->delete( Installer::suppression_table(), array( 'email' => $email ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		}

		return $removed;
	}

	/**
	 * Lists entries.
	 *
	 * @param string $search   Optional search.
	 * @param int    $per_page Rows per page.
	 * @param int    $page     Page number.
	 * @return array[]
	 */
	public static function query( $search, $per_page, $page ) {
		global $wpdb;

		$table  = Installer::suppression_table();
		$offset = ( max( 1, (int) $page ) - 1 ) * (int) $per_page;

		if ( '' !== (string) $search ) {
			return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE email LIKE %s ORDER BY created_at DESC LIMIT %d OFFSET %d', $table, '%' . $wpdb->esc_like( (string) $search ) . '%', (int) $per_page, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		}

		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY created_at DESC LIMIT %d OFFSET %d', $table, (int) $per_page, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
	}

	/**
	 * Counts entries.
	 *
	 * @param string $search Optional search.
	 * @return int
	 */
	public static function count( $search = '' ) {
		global $wpdb;

		$table = Installer::suppression_table();

		if ( '' !== (string) $search ) {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE email LIKE %s', $table, '%' . $wpdb->esc_like( (string) $search ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		}

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
	}

	/**
	 * The entry for one address (privacy export).
	 *
	 * @param string $email Address.
	 * @return array|null
	 */
	public static function find( $email ) {
		global $wpdb;

		$table = Installer::suppression_table();
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE email = %s', $table, self::normalize( $email ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.

		return $row ? $row : null;
	}
}
