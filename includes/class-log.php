<?php
/**
 * Email log storage.
 *
 * @package SendFleet
 */

namespace SendFleet;

defined( 'ABSPATH' ) || exit;

/**
 * One row per recipient per email. The same table holds the retry and
 * background queue: a queued message's first row (its lead) carries the
 * request in `payload`, which is cleared as soon as the row reaches a final
 * state, so message bodies are never kept after delivery. The message's other
 * recipients are follower rows: queued, no payload, same `mail_id`; they take
 * the lead's outcome.
 */
final class Log {

	// Local states.
	const STATUS_QUEUED   = 'queued';
	const STATUS_SENDING  = 'sending';
	const STATUS_ACCEPTED = 'accepted';
	const STATUS_FAILED   = 'failed';
	const STATUS_SKIPPED  = 'skipped';
	const STATUS_FALLBACK = 'fallback';

	// Delivery states reported by webhooks (Managed plans).
	const STATUS_SENT        = 'sent';
	const STATUS_DELIVERED   = 'delivered';
	const STATUS_DELAYED     = 'delayed';
	const STATUS_BOUNCED     = 'bounced';
	const STATUS_COMPLAINED  = 'complained';
	const STATUS_REJECTED    = 'rejected';
	const STATUS_SUPPRESSED  = 'suppressed';
	const STATUS_UNDELIVERED = 'undelivered';

	/**
	 * Labels for every status.
	 *
	 * @return array<string,string>
	 */
	public static function statuses() {
		return array(
			self::STATUS_QUEUED      => __( 'Queued', 'sendfleet' ),
			self::STATUS_SENDING     => __( 'Sending', 'sendfleet' ),
			self::STATUS_ACCEPTED    => __( 'Accepted', 'sendfleet' ),
			self::STATUS_SENT        => __( 'Sent', 'sendfleet' ),
			self::STATUS_DELIVERED   => __( 'Delivered', 'sendfleet' ),
			self::STATUS_DELAYED     => __( 'Delayed', 'sendfleet' ),
			self::STATUS_BOUNCED     => __( 'Bounced', 'sendfleet' ),
			self::STATUS_COMPLAINED  => __( 'Complained', 'sendfleet' ),
			self::STATUS_REJECTED    => __( 'Rejected', 'sendfleet' ),
			self::STATUS_SUPPRESSED  => __( 'Suppressed', 'sendfleet' ),
			self::STATUS_UNDELIVERED => __( 'Undelivered', 'sendfleet' ),
			self::STATUS_FAILED      => __( 'Failed', 'sendfleet' ),
			self::STATUS_SKIPPED     => __( 'Skipped', 'sendfleet' ),
			self::STATUS_FALLBACK    => __( 'Sent via WordPress', 'sendfleet' ),
		);
	}

	/**
	 * Column defaults, so every insert supplies every NOT NULL column.
	 *
	 * @return array
	 */
	private static function defaults() {
		$now = gmdate( 'Y-m-d H:i:s' );

		return array(
			'mail_id'         => '',
			'created_at'      => $now,
			'updated_at'      => $now,
			'status'          => '',
			'recipient'       => '',
			'recipient_kind'  => 'to',
			'from_email'      => '',
			'subject'         => '',
			'route'           => '',
			'mode'            => '',
			'message_id'      => '',
			'idempotency_key' => '',
			'http_status'     => 0,
			'error_code'      => '',
			'error_message'   => '',
			'notes'           => '',
			'attachments'     => 0,
			'attempts'        => 0,
			'retry_class'     => '',
			'next_attempt_at' => null,
			'locked_until'    => null,
			'source'          => '',
			'payload'         => null,
		);
	}

	/**
	 * Inserts a row.
	 *
	 * @param array $row Column values.
	 * @return int Row id, 0 on failure.
	 */
	public static function insert( array $row ) {
		global $wpdb;

		$row = array_intersect_key( array_merge( self::defaults(), $row ), self::defaults() );
		$row = self::fit( $row );

		$ok = $wpdb->insert( Installer::log_table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Updates a row.
	 *
	 * @param int   $id  Row id.
	 * @param array $row Column values.
	 * @return bool
	 */
	public static function update( $id, array $row ) {
		global $wpdb;

		$row               = array_intersect_key( $row, self::defaults() );
		$row['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$row               = self::fit( $row );

		return false !== $wpdb->update( Installer::log_table(), $row, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
	}

	/**
	 * Truncates values to their column sizes.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function fit( array $row ) {
		$limits = array(
			'recipient'       => 255,
			'from_email'      => 255,
			'message_id'      => 100,
			'idempotency_key' => 191,
			'error_code'      => 64,
			'source'          => 191,
			'route'           => 10,
			'mode'            => 10,
			'status'          => 20,
			'retry_class'     => 12,
		);

		foreach ( $limits as $column => $limit ) {
			if ( isset( $row[ $column ] ) && is_string( $row[ $column ] ) && strlen( $row[ $column ] ) > $limit ) {
				$row[ $column ] = function_exists( 'mb_strcut' ) ? mb_strcut( $row[ $column ], 0, $limit, 'UTF-8' ) : substr( $row[ $column ], 0, $limit );
			}
		}

		foreach ( array( 'subject', 'error_message', 'notes' ) as $column ) {
			if ( array_key_exists( $column, $row ) && null === $row[ $column ] ) {
				$row[ $column ] = '';
			}
		}

		return $row;
	}

	/**
	 * Fetches one row.
	 *
	 * @param int $id Row id.
	 * @return array|null
	 */
	public static function find( $id ) {
		global $wpdb;

		$table = Installer::log_table();
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.

		return $row ? $row : null;
	}

	/**
	 * Rows sharing an idempotency key (webhook correlation).
	 *
	 * @param string $key Idempotency key.
	 * @return array[]
	 */
	public static function find_by_idempotency_key( $key ) {
		global $wpdb;

		$table = Installer::log_table();

		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, status, recipient, notes FROM %i WHERE idempotency_key = %s', $table, (string) $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
	}

	/**
	 * Values for the list filters, in the order the filter SQL expects them.
	 *
	 * The filter SQL is fixed (see self::query()); an empty value disables its
	 * condition, so no SQL is ever assembled at runtime.
	 *
	 * @param array $args status, search, mail_id.
	 * @return array
	 */
	private static function filter_values( array $args ) {
		global $wpdb;

		$status  = ! empty( $args['status'] ) ? (string) $args['status'] : '';
		$mail_id = ! empty( $args['mail_id'] ) ? (string) $args['mail_id'] : '';
		$search  = ! empty( $args['search'] ) ? (string) $args['search'] : '';
		$like    = '' === $search ? '' : '%' . $wpdb->esc_like( $search ) . '%';

		return array_merge( array( $status, $status, $mail_id, $mail_id, $search ), array_fill( 0, 6, $like ) );
	}

	/**
	 * Lists rows for the admin table.
	 *
	 * @param array $args status, search, mail_id, per_page, page, orderby, order.
	 * @return array[]
	 */
	public static function query( array $args ) {
		global $wpdb;

		$table    = Installer::log_table();
		$per_page = max( 1, min( 200, isset( $args['per_page'] ) ? (int) $args['per_page'] : 20 ) );
		$page     = max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );
		$orderby  = isset( $args['orderby'] ) && in_array( $args['orderby'], array( 'created_at', 'recipient', 'status', 'subject' ), true ) ? $args['orderby'] : 'created_at';
		$values   = array_merge( array( $table ), self::filter_values( $args ), array( $orderby, $per_page, ( $page - 1 ) * $per_page ) );

		if ( isset( $args['order'] ) && 'asc' === strtolower( (string) $args['order'] ) ) {
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, mail_id, created_at, updated_at, status, recipient, recipient_kind, from_email, subject, route, mode, message_id, error_code, error_message, attachments, attempts, source FROM %i WHERE ( %s = '' OR status = %s ) AND ( %s = '' OR mail_id = %s ) AND ( %s = '' OR recipient LIKE %s OR subject LIKE %s OR from_email LIKE %s OR error_code LIKE %s OR source LIKE %s OR message_id LIKE %s ) ORDER BY %i ASC, id ASC LIMIT %d OFFSET %d", $values ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom table; values passed as one array.
		}

		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, mail_id, created_at, updated_at, status, recipient, recipient_kind, from_email, subject, route, mode, message_id, error_code, error_message, attachments, attempts, source FROM %i WHERE ( %s = '' OR status = %s ) AND ( %s = '' OR mail_id = %s ) AND ( %s = '' OR recipient LIKE %s OR subject LIKE %s OR from_email LIKE %s OR error_code LIKE %s OR source LIKE %s OR message_id LIKE %s ) ORDER BY %i DESC, id DESC LIMIT %d OFFSET %d", $values ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom table; values passed as one array.
	}

	/**
	 * Counts rows for the admin table.
	 *
	 * @param array $args status, search, mail_id.
	 * @return int
	 */
	public static function count( array $args = array() ) {
		global $wpdb;

		$values = array_merge( array( Installer::log_table() ), self::filter_values( $args ) );

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE ( %s = '' OR status = %s ) AND ( %s = '' OR mail_id = %s ) AND ( %s = '' OR recipient LIKE %s OR subject LIKE %s OR from_email LIKE %s OR error_code LIKE %s OR source LIKE %s OR message_id LIKE %s )", $values ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom table; values passed as one array.
	}

	/**
	 * Row counts per status.
	 *
	 * @return array<string,int>
	 */
	public static function count_by_status() {
		global $wpdb;

		$table  = Installer::log_table();
		$rows   = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS total FROM %i GROUP BY status', $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$counts = array();

		foreach ( $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * Counts per status since a point in time.
	 *
	 * @param int $since Unix time.
	 * @return array<string,int>
	 */
	public static function count_since( $since ) {
		global $wpdb;

		$table  = Installer::log_table();
		$rows   = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS total FROM %i WHERE created_at >= %s GROUP BY status', $table, gmdate( 'Y-m-d H:i:s', (int) $since ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$counts = array();

		foreach ( $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * Deletes rows by id. Queued rows are deleted too (the email is then never sent).
	 *
	 * @param int[] $ids Row ids.
	 * @return int Rows deleted.
	 */
	public static function delete( array $ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );

		if ( empty( $ids ) ) {
			return 0;
		}

		// Deleting a queued message's lead cancels the message: its followers go too.
		foreach ( $ids as $id ) {
			$row = self::find( $id );
			if ( $row && self::STATUS_QUEUED === $row['status'] && null !== $row['payload'] && '' !== $row['mail_id'] ) {
				$ids = array_merge( $ids, self::followers( $row['mail_id'], $id ) );
			}
		}
		$ids = array_values( array_unique( $ids ) );

		$table        = Installer::log_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id IN ({$placeholders})", array_merge( array( $table ), $ids ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are generated.
	}

	/**
	 * The follower rows of a queued message: its other recipients.
	 *
	 * @param string $mail_id Message id.
	 * @param int    $lead_id The lead row.
	 * @return int[]
	 */
	public static function followers( $mail_id, $lead_id ) {
		global $wpdb;

		$table = Installer::log_table();

		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE mail_id = %s AND id <> %d AND status = %s AND payload IS NULL', $table, (string) $mail_id, (int) $lead_id, self::STATUS_QUEUED ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
	}

	/**
	 * Follower rows whose lead is gone, which nothing would ever send.
	 *
	 * @return int[]
	 */
	public static function orphaned_followers() {
		global $wpdb;

		$table = Installer::log_table();

		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT f.id FROM %i f LEFT JOIN %i l ON l.mail_id = f.mail_id AND l.payload IS NOT NULL AND l.status IN (%s, %s) WHERE f.status = %s AND f.payload IS NULL AND l.id IS NULL LIMIT 500', $table, $table, self::STATUS_QUEUED, self::STATUS_SENDING, self::STATUS_QUEUED ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
	}

	/**
	 * Deletes every finished row (queued rows are kept so nothing pending is lost).
	 *
	 * @return int Rows deleted.
	 */
	public static function purge_all() {
		global $wpdb;

		$table = Installer::log_table();

		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE status NOT IN (%s, %s)', $table, self::STATUS_QUEUED, self::STATUS_SENDING ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
	}

	/**
	 * Deletes finished rows older than the retention period.
	 *
	 * @param int $days Retention in days.
	 * @return int Rows deleted.
	 */
	public static function purge_older_than( $days ) {
		global $wpdb;

		$table  = Installer::log_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $days ) * DAY_IN_SECONDS );

		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s AND status NOT IN (%s, %s) LIMIT 5000', $table, $cutoff, self::STATUS_QUEUED, self::STATUS_SENDING ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
	}

	/**
	 * Ids of queued rows that are due.
	 *
	 * @param int $limit Maximum rows.
	 * @return int[]
	 */
	public static function due( $limit ) {
		global $wpdb;

		$table = Installer::log_table();

		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE status = %s AND payload IS NOT NULL AND next_attempt_at <= %s ORDER BY next_attempt_at ASC, id ASC LIMIT %d', $table, self::STATUS_QUEUED, gmdate( 'Y-m-d H:i:s' ), (int) $limit ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
	}

	/**
	 * The earliest pending attempt time, or null when the queue is empty.
	 *
	 * @return int|null Unix time.
	 */
	public static function next_due() {
		global $wpdb;

		$table = Installer::log_table();
		$next  = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(next_attempt_at) FROM %i WHERE status = %s AND payload IS NOT NULL', $table, self::STATUS_QUEUED ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.

		return $next ? (int) strtotime( $next . ' UTC' ) : null;
	}

	/**
	 * Number of queued rows.
	 *
	 * @return int
	 */
	public static function queued_count() {
		global $wpdb;

		$table = Installer::log_table();

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status IN (%s, %s)', $table, self::STATUS_QUEUED, self::STATUS_SENDING ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
	}

	/**
	 * Atomically claims a queued row for sending.
	 *
	 * @param int $id      Row id.
	 * @param int $seconds Lock duration.
	 * @return bool Whether this process owns the row now.
	 */
	public static function claim( $id, $seconds = 300 ) {
		global $wpdb;

		$table = Installer::log_table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		$updated = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET status = %s, locked_until = %s, updated_at = %s WHERE id = %d AND status = %s', $table, self::STATUS_SENDING, gmdate( 'Y-m-d H:i:s', time() + (int) $seconds ), $now, (int) $id, self::STATUS_QUEUED ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.

		return 1 === (int) $updated;
	}

	/**
	 * Rows stuck in "sending" past their lock (the process died mid-request).
	 *
	 * @return int[]
	 */
	public static function stale_sending() {
		global $wpdb;

		$table = Installer::log_table();

		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE status = %s AND locked_until < %s LIMIT 500', $table, self::STATUS_SENDING, gmdate( 'Y-m-d H:i:s' ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
	}

	/**
	 * Recent rows for the overview page.
	 *
	 * @param int $limit Maximum rows.
	 * @return array[]
	 */
	public static function recent( $limit = 8 ) {
		return self::query(
			array(
				'per_page' => $limit,
				'page'     => 1,
			)
		);
	}

	/**
	 * Rows for a recipient (privacy export / erasure).
	 *
	 * @param string $email Address.
	 * @param int    $limit Maximum rows.
	 * @param int    $page  Page number.
	 * @return array[]
	 */
	public static function for_recipient( $email, $limit, $page ) {
		global $wpdb;

		$table = Installer::log_table();

		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, created_at, status, recipient, from_email, subject, mode, message_id FROM %i WHERE recipient = %s ORDER BY id ASC LIMIT %d OFFSET %d', $table, (string) $email, (int) $limit, ( max( 1, (int) $page ) - 1 ) * (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
	}

	/**
	 * Deletes every row for a recipient.
	 *
	 * @param string $email Address.
	 * @return int Rows deleted.
	 */
	public static function delete_for_recipient( $email ) {
		global $wpdb;

		return (int) $wpdb->delete( Installer::log_table(), array( 'recipient' => (string) $email ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
	}
}
