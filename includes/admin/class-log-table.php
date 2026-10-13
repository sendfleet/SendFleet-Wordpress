<?php
/**
 * Email log list table.
 *
 * @package SendFleet
 */

namespace SendFleet\Admin;

use SendFleet\Log;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists email log rows.
 */
final class Log_Table extends \WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'sendfleet-email',
				'plural'   => 'sendfleet-emails',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Reads a GET parameter.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private static function arg( $name ) {
		return isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ? sanitize_text_field( wp_unslash( $_GET[ $name ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filters.
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'created_at' => __( 'Date', 'sendfleet' ),
			'recipient'  => __( 'Recipient', 'sendfleet' ),
			'subject'    => __( 'Subject', 'sendfleet' ),
			'status'     => __( 'Status', 'sendfleet' ),
			'mode'       => __( 'Route', 'sendfleet' ),
			'source'     => __( 'Sent by', 'sendfleet' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'created_at' => array( 'created_at', true ),
			'recipient'  => array( 'recipient', false ),
			'status'     => array( 'status', false ),
		);
	}

	/**
	 * No built-in bulk actions; the view prints its own delete button.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array();
	}

	/**
	 * The delete button above the table. It submits the separate POST form the
	 * row checkboxes belong to (form="sf-log-form"), so the table itself can
	 * stay inside the GET form used for search, sorting and paging.
	 *
	 * @param string $which top or bottom.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which || empty( $this->items ) ) {
			return;
		}

		printf(
			'<div class="alignleft actions"><button type="submit" form="sf-log-form" class="button" data-sf-confirm="%1$s">%2$s</button></div>',
			esc_attr__( 'Delete the selected entries? Selected queued emails will not be sent.', 'sendfleet' ),
			esc_html__( 'Delete selected', 'sendfleet' )
		);
	}

	/**
	 * Status filter links.
	 *
	 * @return array
	 */
	protected function get_views() {
		$counts  = Log::count_by_status();
		$current = self::arg( 'status' );
		$base    = admin_url( 'admin.php?page=' . Admin::SLUG_LOG );
		$labels  = Log::statuses();
		$views   = array(
			'all' => sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( $base ),
				'' === $current ? ' class="current" aria-current="page"' : '',
				esc_html__( 'All', 'sendfleet' ),
				esc_html( number_format_i18n( array_sum( $counts ) ) )
			),
		);

		foreach ( $labels as $status => $label ) {
			if ( empty( $counts[ $status ] ) ) {
				continue;
			}

			$views[ $status ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( add_query_arg( 'status', $status, $base ) ),
				$status === $current ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $counts[ $status ] ) )
			);
		}

		return $views;
	}

	/**
	 * Loads the rows.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = 25;
		$args     = array(
			'status'   => self::arg( 'status' ),
			'search'   => self::arg( 's' ),
			'mail_id'  => self::arg( 'mail_id' ),
			'orderby'  => self::arg( 'orderby' ),
			'order'    => self::arg( 'order' ),
			'per_page' => $per_page,
			'page'     => $this->get_pagenum(),
		);

		$this->items = Log::query( $args );

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'recipient' );

		$this->set_pagination_args(
			array(
				'total_items' => Log::count( $args ),
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Empty state.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No emails logged yet. Everything WordPress sends through SendFleet appears here.', 'sendfleet' );
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="ids[]" value="%d" form="sf-log-form" />', (int) $item['id'] );
	}

	/**
	 * Date column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_created_at( $item ) {
		$url = add_query_arg(
			array(
				'page' => Admin::SLUG_LOG,
				'view' => (int) $item['id'],
			),
			admin_url( 'admin.php' )
		);

		return sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( Admin::datetime( $item['created_at'] ) ) );
	}

	/**
	 * Recipient column with row actions.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_recipient( $item ) {
		$url = add_query_arg(
			array(
				'page' => Admin::SLUG_LOG,
				'view' => (int) $item['id'],
			),
			admin_url( 'admin.php' )
		);

		$kind = 'to' !== $item['recipient_kind'] ? ' <span class="sf-muted">(' . esc_html( strtoupper( $item['recipient_kind'] ) ) . ')</span>' : '';

		return sprintf( '<strong><a href="%s">%s</a></strong>%s', esc_url( $url ), esc_html( $item['recipient'] ), $kind )
			. $this->row_actions(
				array(
					'view' => sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Details', 'sendfleet' ) ),
				)
			);
	}

	/**
	 * Subject column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_subject( $item ) {
		$subject = '' !== $item['subject'] ? $item['subject'] : __( '(no subject)', 'sendfleet' );
		$extra   = (int) $item['attachments'] > 0 ? ' <span class="sf-muted sf-attach" title="' . esc_attr__( 'Attachments', 'sendfleet' ) . '"><span class="dashicons dashicons-paperclip" aria-hidden="true"></span>' . (int) $item['attachments'] . '</span>' : '';

		return esc_html( $subject ) . $extra;
	}

	/**
	 * Status column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_status( $item ) {
		ob_start();
		Admin::badge( $item['status'] );
		$badge = (string) ob_get_clean();

		if ( '' !== $item['error_code'] && ! in_array( $item['status'], array( Log::STATUS_ACCEPTED, Log::STATUS_DELIVERED, Log::STATUS_SENT ), true ) ) {
			$badge .= '<br /><code class="sf-code-sm">' . esc_html( $item['error_code'] ) . '</code>';
		}

		return $badge;
	}

	/**
	 * Mode column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_mode( $item ) {
		$labels = array(
			'byoc'   => __( 'BYOC', 'sendfleet' ),
			'shared' => __( 'Shared SES', 'sendfleet' ),
			'smoke'  => __( 'Smoke test', 'sendfleet' ),
		);

		$value = '' !== $item['mode'] ? $item['mode'] : $item['route'];

		if ( 'shared' === $item['mode'] && 'smoke' === $item['route'] ) {
			$value = 'smoke';
		}

		return isset( $labels[ $value ] ) ? esc_html( $labels[ $value ] ) : '—';
	}

	/**
	 * Source column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_source( $item ) {
		return '' !== $item['source'] ? '<code class="sf-code-sm">' . esc_html( $item['source'] ) . '</code>' : '—';
	}

	/**
	 * Fallback for other columns.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( (string) $item[ $column_name ] ) : '';
	}
}
