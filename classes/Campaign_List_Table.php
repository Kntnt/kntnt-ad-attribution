<?php
/**
 * List table for campaign reporting.
 *
 * Extends WP_List_Table to display aggregated click and conversion statistics
 * per tracking URL, with date range and UTM filtering, sortable columns,
 * pagination, and a totals summary row.
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
 * Custom list table for campaign statistics.
 *
 * Joins the clicks and conversions tables with post meta to aggregate data
 * per tracking URL, grouped by hash and UTM dimensions.
 *
 * @since 1.0.0
 */
final class Campaign_List_Table extends WP_List_Table {

	/**
	 * Screen option name for per-page setting.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public const PER_PAGE_OPTION = 'kntnt_ad_attr_campaigns_per_page';

	/**
	 * Cached totals for the current filter set.
	 *
	 * @var object|null
	 * @since 1.0.0
	 */
	private ?object $totals = null;

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
	 * Includes checkbox for bulk actions. In trash view, omits the
	 * click/conversion columns since trashed URLs have no active traffic.
	 *
	 * @return array<string, string> Column slug => display name.
	 * @since 1.0.0
	 */
	public function get_columns(): array {
		$columns = [
			'cb'           => '<input type="checkbox" />',
			'tracking_url' => __( 'Tracking URL', 'kntnt-ad-attr' ),
			'target_url'   => __( 'Target URL', 'kntnt-ad-attr' ),
			'utm_source'   => __( 'Source', 'kntnt-ad-attr' ),
			'utm_medium'   => __( 'Medium', 'kntnt-ad-attr' ),
			'utm_campaign' => __( 'Campaign', 'kntnt-ad-attr' ),
		];

		if ( ! $this->is_trash_view() ) {
			$columns['total_clicks']      = __( 'Clicks', 'kntnt-ad-attr' );
			$columns['total_conversions'] = __( 'Conversions', 'kntnt-ad-attr' );
		}

		return $columns;
	}

	/**
	 * Defines which columns are sortable.
	 *
	 * @return array<string, array{0: string, 1: bool}> Column slug => [orderby, desc_first].
	 * @since 1.0.0
	 */
	protected function get_sortable_columns(): array {
		return [
			'total_clicks'      => [ 'total_clicks', true ],
			'total_conversions' => [ 'total_conversions', true ],
			'utm_source'        => [ 'utm_source', false ],
			'utm_medium'        => [ 'utm_medium', false ],
			'utm_campaign'      => [ 'utm_campaign', false ],
		];
	}

	/**
	 * Renders the checkbox column for bulk actions.
	 *
	 * @param object $item The current row data.
	 *
	 * @return string HTML checkbox input.
	 * @since 1.6.0
	 */
	protected function column_cb( $item ): string {
		return '<input type="checkbox" name="post[]" value="' . esc_attr( (string) $item->post_id ) . '">';
	}

	/**
	 * Returns available bulk actions based on the current view.
	 *
	 * @return array<string, string> Action slug => display label.
	 * @since 1.6.0
	 */
	protected function get_bulk_actions(): array {
		if ( $this->is_trash_view() ) {
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
	 * @since 1.6.0
	 */
	protected function get_views(): array {
		global $wpdb;

		$current_status = sanitize_text_field( wp_unslash( $_GET['post_status'] ?? '' ) );
		$base_url       = admin_url( 'tools.php?page=' . Plugin::get_slug() );

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

		$all_class    = ( $current_status !== 'trash' ) ? ' class="current"' : '';
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
		$tracking_url = home_url( Plugin::get_url_prefix() . '/' . $item->hash );

		$url = '<code class="kntnt-ad-attr-copy" role="button" tabindex="0" data-clipboard-text="'
			. esc_attr( $tracking_url ) . '">' . esc_html( $tracking_url ) . '</code>';

		if ( $this->is_trash_view() ) {

			$restore_url = wp_nonce_url(
				admin_url( sprintf(
					'tools.php?page=%s&action=restore&post=%d',
					Plugin::get_slug(),
					$item->post_id,
				) ),
				'restore_kntnt_ad_attr_url_' . $item->post_id,
			);

			$delete_url = wp_nonce_url(
				admin_url( sprintf(
					'tools.php?page=%s&action=delete&post=%d',
					Plugin::get_slug(),
					$item->post_id,
				) ),
				'delete_kntnt_ad_attr_url_' . $item->post_id,
			);

			$actions = [
				'untrash' => sprintf( '<a href="%s">%s</a>', esc_url( $restore_url ), esc_html__( 'Restore', 'kntnt-ad-attr' ) ),
				'delete'  => sprintf( '<a href="%s" class="submitdelete">%s</a>', esc_url( $delete_url ), esc_html__( 'Delete Permanently', 'kntnt-ad-attr' ) ),
			];

		} else {

			$trash_url = wp_nonce_url(
				admin_url( sprintf(
					'tools.php?page=%s&action=trash&post=%d',
					Plugin::get_slug(),
					$item->post_id,
				) ),
				'trash_kntnt_ad_attr_url_' . $item->post_id,
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
	 * Renders the total clicks column with locale-formatted integer.
	 *
	 * @param object $item The current row data.
	 *
	 * @return string HTML for the column.
	 * @since 1.0.0
	 */
	protected function column_total_clicks( object $item ): string {
		return esc_html( number_format_i18n( (int) $item->total_clicks ) );
	}

	/**
	 * Renders the total conversions column with 1-decimal locale formatting.
	 *
	 * @param object $item The current row data.
	 *
	 * @return string HTML for the column.
	 * @since 1.0.0
	 */
	protected function column_total_conversions( object $item ): string {
		return esc_html( number_format_i18n( (float) $item->total_conversions, 1 ) );
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
	 * Checks if the current view is the trash view.
	 *
	 * @return bool True if viewing trashed items.
	 * @since 1.6.0
	 */
	private function is_trash_view(): bool {
		return sanitize_text_field( wp_unslash( $_GET['post_status'] ?? '' ) ) === 'trash';
	}

	/**
	 * Prepares the list of items for display.
	 *
	 * Reads per-page setting from Screen Options and delegates data fetching
	 * to the appropriate method based on the current view.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function prepare_items(): void {
		$per_page     = $this->get_items_per_page( self::PER_PAGE_OPTION, 20 );
		$current_page = $this->get_pagenum();

		$total_items = $this->is_trash_view()
			? $this->fetch_trash_items( $per_page, $current_page )
			: $this->fetch_items( $per_page, $current_page );

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
	 * Builds UTM filter and search WHERE clauses from current filter params.
	 *
	 * @param array $params Filter parameters from get_filter_params().
	 *
	 * @return string SQL WHERE clauses (each prefixed with AND).
	 * @since 1.5.1
	 */
	private function build_filter_clauses( array $params ): string {
		global $wpdb;

		$filter_map = [
			'utm_source'   => 'pm_src.meta_value',
			'utm_medium'   => 'pm_med.meta_value',
			'utm_campaign' => 'pm_camp.meta_value',
		];

		$clauses = '';
		foreach ( $filter_map as $filter_key => $column ) {
			if ( $params[ $filter_key ] !== '' ) {
				$clauses .= $wpdb->prepare( " AND {$column} = %s", $params[ $filter_key ] );
			}
		}

		// Free-text search — matches tracking URL (post_title) or hash.
		if ( $params['search'] !== '' ) {
			$like     = '%' . $wpdb->esc_like( $params['search'] ) . '%';
			$clauses .= $wpdb->prepare(
				' AND (p.post_title LIKE %s OR pm_hash.meta_value LIKE %s)',
				$like,
				$like,
			);
		}

		return $clauses;
	}

	/**
	 * Builds the shared FROM + JOINs + base WHERE + filter clauses.
	 *
	 * The `$include_target` flag controls whether the pm_target JOIN is
	 * included — get_totals() omits it since it doesn't need the column.
	 *
	 * @param bool $include_target Whether to JOIN pm_target (default: true).
	 *
	 * @return array{0: string, 1: array} SQL fragment and bound parameters.
	 * @since 1.5.1
	 */
	private function build_from_where( bool $include_target = true ): array {
		global $wpdb;

		$clicks_table = $wpdb->prefix . 'kntnt_ad_attr_clicks';
		$conv_table   = $wpdb->prefix . 'kntnt_ad_attr_conversions';

		$from_where = "FROM {$clicks_table} c
			INNER JOIN {$wpdb->postmeta} pm_hash
				ON pm_hash.meta_key = '_hash' AND pm_hash.meta_value = c.hash
			INNER JOIN {$wpdb->posts} p
				ON p.ID = pm_hash.post_id AND p.post_type = %s AND p.post_status = 'publish'";

		if ( $include_target ) {
			$from_where .= "
			INNER JOIN {$wpdb->postmeta} pm_target
				ON pm_target.post_id = p.ID AND pm_target.meta_key = '_target_post_id'";
		}

		$from_where .= "
			LEFT JOIN {$wpdb->postmeta} pm_src
				ON pm_src.post_id = p.ID AND pm_src.meta_key = '_utm_source'
			LEFT JOIN {$wpdb->postmeta} pm_med
				ON pm_med.post_id = p.ID AND pm_med.meta_key = '_utm_medium'
			LEFT JOIN {$wpdb->postmeta} pm_camp
				ON pm_camp.post_id = p.ID AND pm_camp.meta_key = '_utm_campaign'
			LEFT JOIN {$conv_table} cv
				ON cv.click_id = c.id
			WHERE c.clicked_at BETWEEN %s AND %s";

		$params = $this->get_filter_params();

		$query_params = [
			Post_Type::SLUG,
			$params['date_start'] . ' 00:00:00',
			$params['date_end'] . ' 23:59:59',
		];

		$from_where .= $this->build_filter_clauses( $params );

		return [ $from_where, $query_params ];
	}

	/**
	 * Builds the tab query with GROUP BY for the list table view.
	 *
	 * Groups by hash and postmeta-level UTM dimensions.
	 *
	 * @return array{0: string, 1: array} SQL fragment and bound parameters.
	 * @since 1.0.0
	 */
	private function build_base_query(): array {
		[ $from_where, $params ] = $this->build_from_where();

		$group_by = ' GROUP BY c.hash, pm_target.meta_value,
			pm_src.meta_value, pm_med.meta_value, pm_camp.meta_value';

		return [ $from_where . $group_by, $params ];
	}

	/**
	 * Builds the CSV query with per-click UTM fields included.
	 *
	 * Content/Term/Id/Group vary per click and are stored in the clicks table,
	 * so the CSV query groups by these additional dimensions.
	 *
	 * @return array{0: string, 1: array} SQL fragment and bound parameters.
	 * @since 1.5.0
	 */
	private function build_csv_query(): array {
		[ $from_where, $params ] = $this->build_from_where();

		$group_by = ' GROUP BY c.hash, pm_target.meta_value,
			pm_src.meta_value, pm_med.meta_value, pm_camp.meta_value,
			c.utm_content, c.utm_term, c.utm_id, c.utm_source_platform';

		return [ $from_where . $group_by, $params ];
	}

	/**
	 * Returns sanitized filter parameters from the current request.
	 *
	 * Used internally and exposed publicly so Csv_Exporter can access
	 * the same filter values.
	 *
	 * @return array{date_start: string, date_end: string, utm_source: string, utm_medium: string, utm_campaign: string, search: string}
	 * @since 1.0.0
	 */
	public function get_filter_params(): array {
		$date_start = sanitize_text_field( wp_unslash( $_GET['date_start'] ?? '' ) );
		$date_end   = sanitize_text_field( wp_unslash( $_GET['date_end'] ?? '' ) );

		// Validate ISO-8601 date format; fall back to open-ended defaults.
		$date_pattern = '/^\d{4}-\d{2}-\d{2}$/';
		$date_start   = preg_match( $date_pattern, $date_start ) ? $date_start : '1970-01-01';
		$date_end     = preg_match( $date_pattern, $date_end ) ? $date_end : '9999-12-31';

		return [
			'date_start'   => $date_start,
			'date_end'     => $date_end,
			'utm_source'   => sanitize_text_field( wp_unslash( $_GET['utm_source'] ?? '' ) ),
			'utm_medium'   => sanitize_text_field( wp_unslash( $_GET['utm_medium'] ?? '' ) ),
			'utm_campaign' => sanitize_text_field( wp_unslash( $_GET['utm_campaign'] ?? '' ) ),
			'search'       => sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ),
		];
	}

	/**
	 * Fetches campaign data with filtering, sorting, and pagination.
	 *
	 * @param int $per_page     Number of items per page.
	 * @param int $current_page Current page number.
	 *
	 * @return int Total number of grouped rows matching the filters.
	 * @since 1.0.0
	 */
	private function fetch_items( int $per_page, int $current_page ): int {
		global $wpdb;

		[ $base_query, $params ] = $this->build_base_query();

		// Count total grouped rows for pagination.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total_items = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM (
				SELECT 1 {$base_query}
			) AS grouped_rows",
			...$params,
		) );

		// Sorting — whitelist allowed columns to prevent SQL injection.
		$allowed_orderby = [
			'total_clicks'      => 'total_clicks',
			'total_conversions' => 'total_conversions',
			'utm_source'        => 'pm_src.meta_value',
			'utm_medium'        => 'pm_med.meta_value',
			'utm_campaign'      => 'pm_camp.meta_value',
		];

		$orderby_param = sanitize_text_field( wp_unslash( $_GET['orderby'] ?? '' ) );
		$order_param   = strtoupper( sanitize_text_field( wp_unslash( $_GET['order'] ?? '' ) ) );

		$orderby = $allowed_orderby[ $orderby_param ] ?? 'total_clicks';
		$order   = $order_param === 'ASC' ? 'ASC' : 'DESC';

		$offset = ( $current_page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.hash,
				p.ID AS post_id,
				pm_target.meta_value AS target_post_id,
				pm_src.meta_value AS utm_source,
				pm_med.meta_value AS utm_medium,
				pm_camp.meta_value AS utm_campaign,
				COUNT(c.id) AS total_clicks,
				COALESCE(SUM(cv.fractional_conversion), 0) AS total_conversions
			{$base_query}
			ORDER BY {$orderby} {$order}
			LIMIT %d OFFSET %d",
			...[ ...$params, $per_page, $offset ],
		) );

		$this->items = $results ?: [];

		return $total_items;
	}

	/**
	 * Fetches trashed tracking URLs with pagination.
	 *
	 * Uses a simplified query without click/conversion joins since trashed
	 * URLs have no active traffic.
	 *
	 * @param int $per_page     Number of items per page.
	 * @param int $current_page Current page number.
	 *
	 * @return int Total number of trashed items.
	 * @since 1.6.0
	 */
	private function fetch_trash_items( int $per_page, int $current_page ): int {
		global $wpdb;

		$base_query = "FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm_hash   ON pm_hash.post_id = p.ID   AND pm_hash.meta_key = '_hash'
			INNER JOIN {$wpdb->postmeta} pm_target  ON pm_target.post_id = p.ID AND pm_target.meta_key = '_target_post_id'
			LEFT JOIN  {$wpdb->postmeta} pm_src     ON pm_src.post_id = p.ID    AND pm_src.meta_key = '_utm_source'
			LEFT JOIN  {$wpdb->postmeta} pm_med     ON pm_med.post_id = p.ID    AND pm_med.meta_key = '_utm_medium'
			LEFT JOIN  {$wpdb->postmeta} pm_camp    ON pm_camp.post_id = p.ID   AND pm_camp.meta_key = '_utm_campaign'
			WHERE p.post_type = %s AND p.post_status = 'trash'";

		$params = [ Post_Type::SLUG ];

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total_items = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) {$base_query}",
			...$params,
		) );

		$offset = ( $current_page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT pm_hash.meta_value AS hash,
				p.ID AS post_id,
				pm_target.meta_value AS target_post_id,
				pm_src.meta_value AS utm_source,
				pm_med.meta_value AS utm_medium,
				pm_camp.meta_value AS utm_campaign
			{$base_query}
			ORDER BY p.ID DESC
			LIMIT %d OFFSET %d",
			...[ ...$params, $per_page, $offset ],
		) );

		$this->items = $results ?: [];

		return $total_items;
	}

	/**
	 * Fetches all campaign data matching the current filters (no LIMIT).
	 *
	 * Used by Csv_Exporter to export the complete dataset. Uses the CSV query
	 * which includes per-click Content/Term/Id/Group dimensions.
	 *
	 * @return array List of row objects.
	 * @since 1.0.0
	 */
	public function fetch_all_items(): array {
		global $wpdb;

		[ $base_query, $params ] = $this->build_csv_query();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.hash,
				pm_target.meta_value AS target_post_id,
				pm_src.meta_value AS utm_source,
				pm_med.meta_value AS utm_medium,
				pm_camp.meta_value AS utm_campaign,
				c.utm_content,
				c.utm_term,
				c.utm_id,
				c.utm_source_platform,
				COUNT(c.id) AS total_clicks,
				COALESCE(SUM(cv.fractional_conversion), 0) AS total_conversions
			{$base_query}
			ORDER BY total_clicks DESC",
			...$params,
		) );

		return $results ?: [];
	}

	/**
	 * Renders filter controls above the table.
	 *
	 * Date range inputs, UTM dropdowns, and a filter button. Only shown
	 * in the top navigation bar.
	 *
	 * @param string $which Position: 'top' or 'bottom'.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	protected function extra_tablenav( $which ): void {
		if ( $which !== 'top' || $this->is_trash_view() ) {
			return;
		}

		$params = $this->get_filter_params();

		$utm_filters = [
			'utm_source'   => __( 'All Sources', 'kntnt-ad-attr' ),
			'utm_medium'   => __( 'All Mediums', 'kntnt-ad-attr' ),
			'utm_campaign' => __( 'All Campaigns', 'kntnt-ad-attr' ),
		];

		echo '<div class="alignleft actions kntnt-ad-attr-filters">';

		// Date range inputs.
		$date_start_value = $params['date_start'] !== '1970-01-01' ? $params['date_start'] : '';
		$date_end_value   = $params['date_end'] !== '9999-12-31' ? $params['date_end'] : '';

		echo '<input type="date" name="date_start" value="' . esc_attr( $date_start_value ) . '" placeholder="' . esc_attr__( 'Start date', 'kntnt-ad-attr' ) . '">';
		echo '<input type="date" name="date_end" value="' . esc_attr( $date_end_value ) . '" placeholder="' . esc_attr__( 'End date', 'kntnt-ad-attr' ) . '">';

		// UTM dropdowns.
		foreach ( $utm_filters as $meta_suffix => $label ) {
			$meta_key = '_' . $meta_suffix;
			$values   = Post_Type::get_distinct_meta_values( $meta_key );
			$current  = $params[ $meta_suffix ];

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

	/**
	 * Returns aggregated totals for the current filter set.
	 *
	 * Queries the clicks and conversions tables without GROUP BY. Omits the
	 * pm_target JOIN since totals don't need the target post ID column.
	 *
	 * @return object|null Object with total_clicks and total_conversions, or null.
	 * @since 1.0.0
	 */
	public function get_totals(): ?object {
		if ( $this->totals !== null ) {
			return $this->totals;
		}

		global $wpdb;

		[ $from_where, $query_params ] = $this->build_from_where( include_target: false );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->totals = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(DISTINCT c.id) AS total_clicks,
				COALESCE(SUM(cv.fractional_conversion), 0) AS total_conversions
			{$from_where}",
			...$query_params,
		) );

		return $this->totals;
	}

	/**
	 * Prepends a totals summary row before the regular data rows.
	 *
	 * The row uses a sticky position so it remains visible while scrolling.
	 * Only displayed when there is at least one click or conversion.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function display_rows(): void {

		// Skip totals row in trash view.
		if ( ! $this->is_trash_view() ) {
			$totals = $this->get_totals();
			if ( $totals && ( (int) $totals->total_clicks > 0 || (float) $totals->total_conversions > 0.0 ) ) {
				$columns = $this->get_columns();

				echo '<tr class="kntnt-ad-attr-totals-row">';

				$is_first_visible = true;
				foreach ( $columns as $slug => $label ) {
					if ( $slug === 'cb' ) {
						echo '<td></td>';
						continue;
					}
					if ( $is_first_visible ) {
						echo '<td><strong>' . esc_html__( 'Total', 'kntnt-ad-attr' ) . '</strong></td>';
						$is_first_visible = false;
					} elseif ( $slug === 'total_clicks' ) {
						echo '<td><strong>' . esc_html( number_format_i18n( (int) $totals->total_clicks ) ) . '</strong></td>';
					} elseif ( $slug === 'total_conversions' ) {
						echo '<td><strong>' . esc_html( number_format_i18n( (float) $totals->total_conversions, 1 ) ) . '</strong></td>';
					} else {
						echo '<td></td>';
					}
				}

				echo '</tr>';
			}
		}

		parent::display_rows();
	}

}
