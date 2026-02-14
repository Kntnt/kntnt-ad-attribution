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
 * Joins the stats table with post meta to aggregate clicks and conversions
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
			'singular' => 'campaign',
			'plural'   => 'campaigns',
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
			'tracking_url'      => __( 'Tracking URL', 'kntnt-ad-attr' ),
			'target_url'        => __( 'Target URL', 'kntnt-ad-attr' ),
			'utm_source'        => __( 'Source', 'kntnt-ad-attr' ),
			'utm_medium'        => __( 'Medium', 'kntnt-ad-attr' ),
			'utm_campaign'      => __( 'Campaign', 'kntnt-ad-attr' ),
			'utm_content'       => __( 'Content', 'kntnt-ad-attr' ),
			'utm_term'          => __( 'Term', 'kntnt-ad-attr' ),
			'total_clicks'      => __( 'Clicks', 'kntnt-ad-attr' ),
			'total_conversions' => __( 'Conversions', 'kntnt-ad-attr' ),
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
			'total_clicks'      => [ 'total_clicks', true ],
			'total_conversions' => [ 'total_conversions', true ],
			'utm_source'        => [ 'utm_source', false ],
			'utm_medium'        => [ 'utm_medium', false ],
			'utm_campaign'      => [ 'utm_campaign', false ],
			'utm_content'       => [ 'utm_content', false ],
			'utm_term'          => [ 'utm_term', false ],
		];
	}

	/**
	 * Renders the tracking URL column with click-to-copy.
	 *
	 * Reconstructs the full tracking URL from the hash using the filterable
	 * prefix, consistent with Click_Handler.
	 *
	 * @param object $item The current row data.
	 *
	 * @return string HTML for the column.
	 * @since 1.0.0
	 */
	protected function column_tracking_url( object $item ): string {
		/** @var string $prefix The URL path prefix for tracking URLs. */
		$prefix       = apply_filters( 'kntnt_ad_attr_url_prefix', 'ad' );
		$tracking_url = home_url( $prefix . '/' . $item->hash );

		return '<code class="kntnt-ad-attr-copy" role="button" tabindex="0" data-clipboard-text="'
			. esc_attr( $tracking_url ) . '">' . esc_html( $tracking_url ) . '</code>';
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
	 * Builds the shared FROM/WHERE/GROUP BY portion of the query.
	 *
	 * Used by both fetch_items() (with LIMIT) and fetch_all_items() (without).
	 *
	 * @return array{0: string, 1: array} SQL fragment and bound parameters.
	 * @since 1.0.0
	 */
	private function build_base_query(): array {
		global $wpdb;

		$stats_table = $wpdb->prefix . 'kntnt_ad_attr_stats';

		$from_where = "FROM {$stats_table} s
			INNER JOIN {$wpdb->postmeta} pm_hash
				ON pm_hash.meta_key = '_hash' AND pm_hash.meta_value = s.hash
			INNER JOIN {$wpdb->posts} p
				ON p.ID = pm_hash.post_id AND p.post_type = %s
			INNER JOIN {$wpdb->postmeta} pm_target
				ON pm_target.post_id = p.ID AND pm_target.meta_key = '_target_post_id'
			INNER JOIN {$wpdb->postmeta} pm_src
				ON pm_src.post_id = p.ID AND pm_src.meta_key = '_utm_source'
			INNER JOIN {$wpdb->postmeta} pm_med
				ON pm_med.post_id = p.ID AND pm_med.meta_key = '_utm_medium'
			INNER JOIN {$wpdb->postmeta} pm_camp
				ON pm_camp.post_id = p.ID AND pm_camp.meta_key = '_utm_campaign'
			LEFT JOIN {$wpdb->postmeta} pm_cont
				ON pm_cont.post_id = p.ID AND pm_cont.meta_key = '_utm_content'
			LEFT JOIN {$wpdb->postmeta} pm_term
				ON pm_term.post_id = p.ID AND pm_term.meta_key = '_utm_term'
			WHERE p.post_status = 'publish'
			AND s.date BETWEEN %s AND %s";

		$params = $this->get_filter_params();

		$query_params = [
			Post_Type::SLUG,
			$params['date_start'],
			$params['date_end'],
		];

		// Dynamic WHERE clauses for UTM filters.
		$filter_map = [
			'utm_source'   => 'pm_src.meta_value',
			'utm_medium'   => 'pm_med.meta_value',
			'utm_campaign' => 'pm_camp.meta_value',
			'utm_content'  => 'pm_cont.meta_value',
			'utm_term'     => 'pm_term.meta_value',
		];

		$where_clauses = '';
		foreach ( $filter_map as $filter_key => $column ) {
			if ( $params[ $filter_key ] !== '' ) {
				$where_clauses .= $wpdb->prepare( " AND {$column} = %s", $params[ $filter_key ] );
			}
		}

		// Free-text search — matches tracking URL (post_title) or hash.
		if ( $params['search'] !== '' ) {
			$like           = '%' . $wpdb->esc_like( $params['search'] ) . '%';
			$where_clauses .= $wpdb->prepare(
				' AND (p.post_title LIKE %s OR pm_hash.meta_value LIKE %s)',
				$like,
				$like,
			);
		}

		$group_by = ' GROUP BY s.hash, pm_target.meta_value, pm_src.meta_value,
			pm_med.meta_value, pm_camp.meta_value,
			pm_cont.meta_value, pm_term.meta_value';

		return [ $from_where . $where_clauses . $group_by, $query_params ];
	}

	/**
	 * Returns sanitized filter parameters from the current request.
	 *
	 * Used internally and exposed publicly so Csv_Exporter can access
	 * the same filter values.
	 *
	 * @return array{date_start: string, date_end: string, utm_source: string, utm_medium: string, utm_campaign: string, utm_content: string, utm_term: string, search: string}
	 * @since 1.0.0
	 */
	public function get_filter_params(): array {
		return [
			'date_start'   => sanitize_text_field( wp_unslash( $_GET['date_start'] ?? '1970-01-01' ) ),
			'date_end'     => sanitize_text_field( wp_unslash( $_GET['date_end'] ?? '9999-12-31' ) ),
			'utm_source'   => sanitize_text_field( wp_unslash( $_GET['utm_source'] ?? '' ) ),
			'utm_medium'   => sanitize_text_field( wp_unslash( $_GET['utm_medium'] ?? '' ) ),
			'utm_campaign' => sanitize_text_field( wp_unslash( $_GET['utm_campaign'] ?? '' ) ),
			'utm_content'  => sanitize_text_field( wp_unslash( $_GET['utm_content'] ?? '' ) ),
			'utm_term'     => sanitize_text_field( wp_unslash( $_GET['utm_term'] ?? '' ) ),
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
			'utm_content'       => 'pm_cont.meta_value',
			'utm_term'          => 'pm_term.meta_value',
		];

		$orderby_param = sanitize_text_field( wp_unslash( $_GET['orderby'] ?? '' ) );
		$order_param   = strtoupper( sanitize_text_field( wp_unslash( $_GET['order'] ?? '' ) ) );

		$orderby = $allowed_orderby[ $orderby_param ] ?? 'total_clicks';
		$order   = $order_param === 'ASC' ? 'ASC' : 'DESC';

		$offset = ( $current_page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.hash,
				pm_target.meta_value AS target_post_id,
				pm_src.meta_value AS utm_source,
				pm_med.meta_value AS utm_medium,
				pm_camp.meta_value AS utm_campaign,
				pm_cont.meta_value AS utm_content,
				pm_term.meta_value AS utm_term,
				SUM(s.clicks) AS total_clicks,
				SUM(s.conversions) AS total_conversions
			{$base_query}
			ORDER BY {$orderby} {$order}
			LIMIT %d OFFSET %d",
			...[ ...$params, $per_page, $offset ],
		) );

		$this->items = $results ?: [];

		return $total_items;
	}

	/**
	 * Fetches all campaign data matching the current filters (no LIMIT).
	 *
	 * Used by Csv_Exporter to export the complete dataset.
	 *
	 * @return array List of row objects.
	 * @since 1.0.0
	 */
	public function fetch_all_items(): array {
		global $wpdb;

		[ $base_query, $params ] = $this->build_base_query();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.hash,
				pm_target.meta_value AS target_post_id,
				pm_src.meta_value AS utm_source,
				pm_med.meta_value AS utm_medium,
				pm_camp.meta_value AS utm_campaign,
				pm_cont.meta_value AS utm_content,
				pm_term.meta_value AS utm_term,
				SUM(s.clicks) AS total_clicks,
				SUM(s.conversions) AS total_conversions
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
		if ( $which !== 'top' ) {
			return;
		}

		$params = $this->get_filter_params();

		$utm_filters = [
			'utm_source'   => __( 'All Sources', 'kntnt-ad-attr' ),
			'utm_medium'   => __( 'All Mediums', 'kntnt-ad-attr' ),
			'utm_campaign' => __( 'All Campaigns', 'kntnt-ad-attr' ),
			'utm_content'  => __( 'All Contents', 'kntnt-ad-attr' ),
			'utm_term'     => __( 'All Terms', 'kntnt-ad-attr' ),
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
			$values   = $this->get_distinct_meta_values( $meta_key );
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
	 * Retrieves distinct meta values for a given key from published tracking URLs.
	 *
	 * @param string $meta_key The meta key to query (e.g. '_utm_source').
	 *
	 * @return string[] Sorted list of distinct values.
	 * @since 1.0.0
	 */
	private function get_distinct_meta_values( string $meta_key ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$values = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT pm.meta_value
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = %s
			   AND p.post_type = %s
			   AND p.post_status = 'publish'
			   AND pm.meta_value != ''
			 ORDER BY pm.meta_value ASC",
			$meta_key,
			Post_Type::SLUG,
		) );

		return $values ?: [];
	}

	/**
	 * Returns aggregated totals for the current filter set.
	 *
	 * Runs the same base query without GROUP BY to get overall sums.
	 *
	 * @return object|null Object with total_clicks and total_conversions, or null.
	 * @since 1.0.0
	 */
	public function get_totals(): ?object {
		if ( $this->totals !== null ) {
			return $this->totals;
		}

		global $wpdb;

		$stats_table = $wpdb->prefix . 'kntnt_ad_attr_stats';
		$params      = $this->get_filter_params();

		$from_where = "FROM {$stats_table} s
			INNER JOIN {$wpdb->postmeta} pm_hash
				ON pm_hash.meta_key = '_hash' AND pm_hash.meta_value = s.hash
			INNER JOIN {$wpdb->posts} p
				ON p.ID = pm_hash.post_id AND p.post_type = %s
			INNER JOIN {$wpdb->postmeta} pm_target
				ON pm_target.post_id = p.ID AND pm_target.meta_key = '_target_post_id'
			INNER JOIN {$wpdb->postmeta} pm_src
				ON pm_src.post_id = p.ID AND pm_src.meta_key = '_utm_source'
			INNER JOIN {$wpdb->postmeta} pm_med
				ON pm_med.post_id = p.ID AND pm_med.meta_key = '_utm_medium'
			INNER JOIN {$wpdb->postmeta} pm_camp
				ON pm_camp.post_id = p.ID AND pm_camp.meta_key = '_utm_campaign'
			LEFT JOIN {$wpdb->postmeta} pm_cont
				ON pm_cont.post_id = p.ID AND pm_cont.meta_key = '_utm_content'
			LEFT JOIN {$wpdb->postmeta} pm_term
				ON pm_term.post_id = p.ID AND pm_term.meta_key = '_utm_term'
			WHERE p.post_status = 'publish'
			AND s.date BETWEEN %s AND %s";

		$query_params = [
			Post_Type::SLUG,
			$params['date_start'],
			$params['date_end'],
		];

		// Dynamic WHERE clauses for UTM filters.
		$filter_map = [
			'utm_source'   => 'pm_src.meta_value',
			'utm_medium'   => 'pm_med.meta_value',
			'utm_campaign' => 'pm_camp.meta_value',
			'utm_content'  => 'pm_cont.meta_value',
			'utm_term'     => 'pm_term.meta_value',
		];

		$where_clauses = '';
		foreach ( $filter_map as $filter_key => $column ) {
			if ( $params[ $filter_key ] !== '' ) {
				$where_clauses .= $wpdb->prepare( " AND {$column} = %s", $params[ $filter_key ] );
			}
		}

		if ( $params['search'] !== '' ) {
			$like           = '%' . $wpdb->esc_like( $params['search'] ) . '%';
			$where_clauses .= $wpdb->prepare(
				' AND (p.post_title LIKE %s OR pm_hash.meta_value LIKE %s)',
				$like,
				$like,
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->totals = $wpdb->get_row( $wpdb->prepare(
			"SELECT SUM(s.clicks) AS total_clicks,
				SUM(s.conversions) AS total_conversions
			{$from_where}{$where_clauses}",
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
		$totals = $this->get_totals();
		if ( $totals && ( (int) $totals->total_clicks > 0 || (float) $totals->total_conversions > 0.0 ) ) {
			$columns = $this->get_columns();

			echo '<tr class="kntnt-ad-attr-totals-row">';

			$first = true;
			foreach ( $columns as $slug => $label ) {
				if ( $first ) {
					echo '<td><strong>' . esc_html__( 'Total', 'kntnt-ad-attr' ) . '</strong></td>';
					$first = false;
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

		parent::display_rows();
	}

}
