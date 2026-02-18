<?php
/**
 * List table for tracking URLs.
 *
 * Extends WP_List_Table to display, filter, sort, and paginate
 * the kntnt_ad_attr_url custom post type entries in the URLs tab.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

use WP_List_Table;

// Load the WP_List_Table base class if not already available.
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Custom list table for tracking URL management.
 *
 * Uses direct SQL with JOINs instead of WP_Query to efficiently retrieve
 * all meta fields in a single query, consistent with the pattern used
 * by Click_Handler and the Campaigns tab.
 *
 * @since 1.0.0
 */
final class Url_List_Table extends WP_List_Table {

	/**
	 * Screen option name for per-page setting.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public const PER_PAGE_OPTION = 'kntnt_ad_attr_urls_per_page';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		parent::__construct( [
			'singular' => 'tracking-url',
			'plural'   => 'tracking-urls',
			'ajax'     => false,
		] );
	}

	/**
	 * Defines the table columns.
	 *
	 * @return array<string, string> Column slug => display name.
	 * @since 1.0.0
	 */
	public function get_columns(): array {
		return [
			'cb'           => '<input type="checkbox" />',
			'tracking_url' => __( 'Tracking URL', 'kntnt-ad-attr' ),
			'target_url'   => __( 'Target URL', 'kntnt-ad-attr' ),
			'utm_source'   => __( 'Source', 'kntnt-ad-attr' ),
			'utm_medium'   => __( 'Medium', 'kntnt-ad-attr' ),
			'utm_campaign' => __( 'Campaign', 'kntnt-ad-attr' ),
		];
	}

	/**
	 * Defines which columns are sortable.
	 *
	 * @return array<string, array{0: string, 1: bool}> Column slug => [orderby, desc_first].
	 * @since 1.0.0
	 */
	protected function get_sortable_columns(): array {
		return [
			'utm_source'   => [ 'utm_source', false ],
			'utm_medium'   => [ 'utm_medium', false ],
			'utm_campaign' => [ 'utm_campaign', false ],
		];
	}

	/**
	 * Renders the checkbox column for bulk actions.
	 *
	 * @param object $item The current row data.
	 *
	 * @return string HTML checkbox input.
	 * @since 1.0.0
	 */
	protected function column_cb( $item ): string {
		return '<input type="checkbox" name="post[]" value="' . esc_attr( (string) $item->ID ) . '">';
	}

	/**
	 * Returns available bulk actions based on the current view.
	 *
	 * @return array<string, string> Action slug => display label.
	 * @since 1.0.0
	 */
	protected function get_bulk_actions(): array {
		$is_trash = sanitize_text_field( wp_unslash( $_GET['post_status'] ?? '' ) ) === 'trash';

		if ( $is_trash ) {
			return [
				'restore' => __( 'Restore', 'kntnt-ad-attr' ),
				'delete'  => __( 'Delete Permanently', 'kntnt-ad-attr' ),
			];
		}

		return [
			'trash' => __( 'Move to Trash', 'kntnt-ad-attr' ),
		];
	}

	/**
	 * Returns the status view links (All / Trash).
	 *
	 * The Trash view is only shown when trashed tracking URLs exist.
	 *
	 * @return array<string, string> View slug => HTML link.
	 * @since 1.0.0
	 */
	protected function get_views(): array {
		global $wpdb;

		$current_status = sanitize_text_field( wp_unslash( $_GET['post_status'] ?? '' ) );
		$base_url       = admin_url( 'tools.php?page=' . Plugin::get_slug() . '&tab=urls' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$counts = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_status, COUNT(*) AS cnt
			 FROM {$wpdb->posts}
			 WHERE post_type = %s AND post_status IN ('publish', 'trash')
			 GROUP BY post_status",
			Post_Type::SLUG,
		) );

		$status_counts = [];
		foreach ( $counts as $row ) {
			$status_counts[ $row->post_status ] = (int) $row->cnt;
		}

		$publish_count = $status_counts['publish'] ?? 0;
		$trash_count   = $status_counts['trash'] ?? 0;

		$views = [];

		$all_class  = ( $current_status !== 'trash' ) ? ' class="current"' : '';
		$views['all'] = sprintf(
			'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
			esc_url( $base_url ),
			$all_class,
			esc_html__( 'All', 'kntnt-ad-attr' ),
			$publish_count,
		);

		if ( $trash_count > 0 ) {
			$trash_class    = ( $current_status === 'trash' ) ? ' class="current"' : '';
			$views['trash'] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( 'post_status', 'trash', $base_url ) ),
				$trash_class,
				esc_html__( 'Trash', 'kntnt-ad-attr' ),
				$trash_count,
			);
		}

		return $views;
	}

	/**
	 * Renders the tracking URL column with click-to-copy and row actions.
	 *
	 * Shows Trash action for published URLs and Restore/Delete Permanently
	 * actions for trashed URLs.
	 *
	 * @param object $item The current row data.
	 *
	 * @return string HTML for the column.
	 * @since 1.0.0
	 */
	protected function column_tracking_url( object $item ): string {
		$url = '<code class="kntnt-ad-attr-copy" role="button" tabindex="0" data-clipboard-text="' . esc_attr( $item->tracking_url ) . '">' . esc_html( $item->tracking_url ) . '</code>';

		$is_trash = sanitize_text_field( wp_unslash( $_GET['post_status'] ?? '' ) ) === 'trash';

		if ( $is_trash ) {

			$restore_url = wp_nonce_url(
				admin_url( sprintf(
					'tools.php?page=%s&tab=urls&action=restore&post=%d',
					Plugin::get_slug(),
					$item->ID,
				) ),
				'restore_kntnt_ad_attr_url_' . $item->ID,
			);

			$delete_url = wp_nonce_url(
				admin_url( sprintf(
					'tools.php?page=%s&tab=urls&action=delete&post=%d',
					Plugin::get_slug(),
					$item->ID,
				) ),
				'delete_kntnt_ad_attr_url_' . $item->ID,
			);

			$actions = [
				'untrash' => sprintf( '<a href="%s">%s</a>', esc_url( $restore_url ), esc_html__( 'Restore', 'kntnt-ad-attr' ) ),
				'delete'  => sprintf( '<a href="%s" class="submitdelete">%s</a>', esc_url( $delete_url ), esc_html__( 'Delete Permanently', 'kntnt-ad-attr' ) ),
			];

		} else {

			$trash_url = wp_nonce_url(
				admin_url( sprintf(
					'tools.php?page=%s&tab=urls&action=trash&post=%d',
					Plugin::get_slug(),
					$item->ID,
				) ),
				'trash_kntnt_ad_attr_url_' . $item->ID,
			);

			$actions = [
				'trash' => sprintf( '<a href="%s">%s</a>', esc_url( $trash_url ), esc_html__( 'Trash', 'kntnt-ad-attr' ) ),
			];

		}

		return $url . $this->row_actions( $actions );
	}

	/**
	 * Renders the target URL column.
	 *
	 * Resolves the post ID to a permalink. Shows "(deleted)" if the target
	 * post no longer exists.
	 *
	 * @param object $item The current row data.
	 *
	 * @return string HTML for the column.
	 * @since 1.0.0
	 */
	protected function column_target_url( object $item ): string {
		$url = get_permalink( (int) $item->target_post_id );
		if ( ! $url ) {
			return '<em>' . esc_html__( '(deleted)', 'kntnt-ad-attr' ) . '</em>';
		}
		return '<a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $url ) . '</a>';
	}

	/**
	 * Default column renderer for UTM fields.
	 *
	 * @param object $item        The current row data.
	 * @param string $column_name The column slug.
	 *
	 * @return string HTML for the column.
	 * @since 1.0.0
	 */
	protected function column_default( $item, $column_name ): string {
		return esc_html( $item->$column_name ?? '' );
	}

	/**
	 * Prepares the list of items for display.
	 *
	 * Reads per-page setting from Screen Options and delegates data fetching
	 * to fetch_items().
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function prepare_items(): void {
		$per_page     = $this->get_items_per_page( self::PER_PAGE_OPTION, 20 );
		$current_page = $this->get_pagenum();

		$total_items = $this->fetch_items( $per_page, $current_page );

		$this->set_pagination_args( [
			'total_items' => $total_items,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total_items / $per_page ),
		] );

		$this->_column_headers = [
			$this->get_columns(),
			[],
			$this->get_sortable_columns(),
		];
	}

	/**
	 * Fetches tracking URL data with filtering, sorting, and pagination.
	 *
	 * Uses direct SQL with JOINs to retrieve all meta fields in one query.
	 * All UTM fields use LEFT JOIN since they are optional.
	 *
	 * @param int $per_page     Number of items per page.
	 * @param int $current_page Current page number.
	 *
	 * @return int Total number of items matching the filters.
	 * @since 1.0.0
	 */
	private function fetch_items( int $per_page, int $current_page ): int {
		global $wpdb;

		// Determine which post status to show.
		$status_param = sanitize_text_field( wp_unslash( $_GET['post_status'] ?? '' ) );
		$post_status  = $status_param === 'trash' ? 'trash' : 'publish';

		$base_query = "FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm_hash   ON pm_hash.post_id = p.ID   AND pm_hash.meta_key = '_hash'
			INNER JOIN {$wpdb->postmeta} pm_target  ON pm_target.post_id = p.ID AND pm_target.meta_key = '_target_post_id'
			LEFT JOIN  {$wpdb->postmeta} pm_src     ON pm_src.post_id = p.ID    AND pm_src.meta_key = '_utm_source'
			LEFT JOIN  {$wpdb->postmeta} pm_med     ON pm_med.post_id = p.ID    AND pm_med.meta_key = '_utm_medium'
			LEFT JOIN  {$wpdb->postmeta} pm_camp    ON pm_camp.post_id = p.ID   AND pm_camp.meta_key = '_utm_campaign'
			WHERE p.post_type = %s AND p.post_status = %s";

		$params = [ Post_Type::SLUG, $post_status ];

		// Dynamic WHERE clauses for UTM filters.
		$filter_map = [
			'utm_source'   => 'pm_src.meta_value',
			'utm_medium'   => 'pm_med.meta_value',
			'utm_campaign' => 'pm_camp.meta_value',
		];

		$where_clauses = '';
		foreach ( $filter_map as $filter_key => $column ) {
			$value = sanitize_text_field( wp_unslash( $_GET[ $filter_key ] ?? '' ) );
			if ( $value !== '' ) {
				$where_clauses .= $wpdb->prepare( " AND {$column} = %s", $value );
			}
		}

		// Search filter — matches tracking URL (post_title) or hash.
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		if ( $search !== '' ) {
			$like            = '%' . $wpdb->esc_like( $search ) . '%';
			$where_clauses  .= $wpdb->prepare(
				' AND (p.post_title LIKE %s OR pm_hash.meta_value LIKE %s)',
				$like,
				$like,
			);
		}

		// Count total matching items for pagination.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total_items = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) {$base_query}{$where_clauses}",
			...$params,
		) );

		// Sorting — whitelist allowed columns to prevent SQL injection.
		$allowed_orderby = [
			'utm_source'   => 'pm_src.meta_value',
			'utm_medium'   => 'pm_med.meta_value',
			'utm_campaign' => 'pm_camp.meta_value',
		];

		$orderby_param = sanitize_text_field( wp_unslash( $_GET['orderby'] ?? '' ) );
		$order_param   = strtoupper( sanitize_text_field( wp_unslash( $_GET['order'] ?? '' ) ) );

		$orderby = $allowed_orderby[ $orderby_param ] ?? 'p.ID';
		$order   = $order_param === 'ASC' ? 'ASC' : 'DESC';

		// Fetch the actual rows with LIMIT/OFFSET.
		$offset = ( $current_page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title AS tracking_url,
				pm_hash.meta_value AS hash,
				pm_target.meta_value AS target_post_id,
				pm_src.meta_value AS utm_source,
				pm_med.meta_value AS utm_medium,
				pm_camp.meta_value AS utm_campaign
			{$base_query}{$where_clauses}
			ORDER BY {$orderby} {$order}
			LIMIT %d OFFSET %d",
			...[ ...$params, $per_page, $offset ],
		) );

		$this->items = $results ?: [];

		return $total_items;
	}

	/**
	 * Renders UTM filter dropdowns above the table.
	 *
	 * Only shown in the top navigation bar. Each dropdown is populated
	 * with distinct values from the post meta for published tracking URLs.
	 *
	 * @param string $which Position: 'top' or 'bottom'.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	protected function extra_tablenav( $which ): void {
		if ( $which !== 'top' ) {
			return;
		}

		// No filters in the trash view.
		$status_param = sanitize_text_field( wp_unslash( $_GET['post_status'] ?? '' ) );
		if ( $status_param === 'trash' ) {
			return;
		}

		$filters = [
			'utm_source'   => __( 'All Sources', 'kntnt-ad-attr' ),
			'utm_medium'   => __( 'All Mediums', 'kntnt-ad-attr' ),
			'utm_campaign' => __( 'All Campaigns', 'kntnt-ad-attr' ),
		];

		echo '<div class="alignleft actions kntnt-ad-attr-filters">';

		foreach ( $filters as $meta_suffix => $label ) {
			$meta_key = '_' . $meta_suffix;
			$values   = Post_Type::get_distinct_meta_values( $meta_key );
			$current  = sanitize_text_field( wp_unslash( $_GET[ $meta_suffix ] ?? '' ) );

			echo '<select name="' . esc_attr( $meta_suffix ) . '">';
			echo '<option value="">' . esc_html( $label ) . '</option>';
			foreach ( $values as $value ) {
				printf(
					'<option value="%s"%s>%s</option>',
					esc_attr( $value ),
					selected( $current, $value, false ),
					esc_html( $value ),
				);
			}
			echo '</select>';
		}

		submit_button( __( 'Filter', 'kntnt-ad-attr' ), '', 'filter_action', false );

		echo '</div>';
	}

}
