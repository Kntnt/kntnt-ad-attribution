<?php
/**
 * List table of recorded conversions.
 *
 * Lists the conversions behind the main view's numbers, one row per
 * conversion, with row and bulk actions to delete a conversion or to mark
 * it as a test (see Admin_Page).
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.10.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

use WP_List_Table;

// Load the WP_List_Table base class if not already available.
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Recorded conversions for the admin page, newest first and paginated.
 *
 * A conversion is the set of conversion rows that share a conversion
 * reference: one row per credited click, several under a multi-touch
 * model. A conversion is listed when any of its attributed clicks falls in
 * the date range, the same selection the main view counts by.
 *
 * @since 1.10.0
 */
final class Conversion_List_Table extends WP_List_Table {

	/**
	 * Query argument for the page number. The main view's table on the same
	 * screen uses `paged`, so this table pages independently.
	 *
	 * @var string
	 * @since 1.10.0
	 */
	public const PAGE_ARG = 'conversions_paged';

	/**
	 * Conversions per page.
	 *
	 * @var int
	 * @since 1.10.0
	 */
	private const PER_PAGE = 20;

	/**
	 * Queue for the status of each conversion's report jobs.
	 *
	 * @var Queue
	 * @since 1.10.0
	 */
	private readonly Queue $queue;

	/**
	 * First day of the date range (Y-m-d).
	 *
	 * @var string
	 * @since 1.10.0
	 */
	private readonly string $date_start;

	/**
	 * Last day of the date range (Y-m-d), inclusive.
	 *
	 * @var string
	 * @since 1.10.0
	 */
	private readonly string $date_end;

	/**
	 * Initializes the table with its queue and the page's date range.
	 *
	 * @param Queue  $queue      Queue for the report jobs' status.
	 * @param string $date_start First day of the range (Y-m-d), as validated by Campaign_List_Table::get_filter_params().
	 * @param string $date_end   Last day of the range (Y-m-d), inclusive.
	 *
	 * @since 1.10.0
	 */
	public function __construct( Queue $queue, string $date_start, string $date_end ) {
		parent::__construct( [
			'singular' => 'conversion',
			'plural'   => 'conversions',
			'ajax'     => false,
		] );
		$this->queue      = $queue;
		$this->date_start = $date_start;
		$this->date_end   = $date_end;
	}

	/**
	 * Defines the table columns.
	 *
	 * @return array<string, string> Column slug => label.
	 * @since 1.10.0
	 */
	public function get_columns(): array {
		return [
			'cb'           => '<input type="checkbox" />',
			'converted_at' => __( 'Converted', 'kntnt-ad-attr' ),
			'tracking_url' => __( 'Tracking URL', 'kntnt-ad-attr' ),
			'clicked_at'   => __( 'Clicked', 'kntnt-ad-attr' ),
			'utm_content'  => __( 'Content', 'kntnt-ad-attr' ),
			'utm_term'     => __( 'Term', 'kntnt-ad-attr' ),
			'weight'       => __( 'Weight', 'kntnt-ad-attr' ),
			'is_test'      => __( 'Test', 'kntnt-ad-attr' ),
			'jobs'         => __( 'Report Jobs', 'kntnt-ad-attr' ),
		];
	}

	/**
	 * Returns the current page number from this table's own query argument.
	 *
	 * @return int Page number, at least 1 and at most the number of pages.
	 * @since 1.10.0
	 */
	public function get_pagenum(): int {
		$pagenum     = absint( $_GET[ self::PAGE_ARG ] ?? 0 );
		$total_pages = (int) $this->get_pagination_arg( 'total_pages' );

		if ( $total_pages > 0 && $pagenum > $total_pages ) {
			$pagenum = $total_pages;
		}

		return max( 1, $pagenum );
	}

	/**
	 * Loads the current page of conversions.
	 *
	 * Each item is one conversion: its reference, its time, its attributed
	 * clicks (`attributions`, one row per conversion row with the click's
	 * time, content, term and test flag and the tracking URL's source,
	 * medium and campaign), whether it is a test (any attributed click is),
	 * and its report jobs per status (`jobs`).
	 *
	 * @return void
	 * @since 1.10.0
	 */
	public function prepare_items(): void {
		global $wpdb;

		$conv_table   = Table::Conversions->prefixed();
		$clicks_table = Table::Clicks->prefixed();

		$from_where = "FROM {$conv_table} cv
			INNER JOIN {$clicks_table} c ON c.id = cv.click_id
			WHERE c.clicked_at BETWEEN %s AND %s";
		$range      = [ $this->date_start . ' 00:00:00', $this->date_end . ' 23:59:59' ];

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total_items = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT cv.conversion_ref) {$from_where}",
			...$range,
		) );

		$this->set_pagination_args( [
			'total_items' => $total_items,
			'per_page'    => self::PER_PAGE,
			'total_pages' => (int) ceil( $total_items / self::PER_PAGE ),
		] );

		$offset = ( $this->get_pagenum() - 1 ) * self::PER_PAGE;

		// The row ID breaks ties between conversions in the same second.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$page = $wpdb->get_results( $wpdb->prepare(
			"SELECT cv.conversion_ref, MAX(cv.converted_at) AS converted_at, MAX(cv.id) AS last_row
			{$from_where}
			GROUP BY cv.conversion_ref
			ORDER BY converted_at DESC, last_row DESC
			LIMIT %d OFFSET %d",
			...[ ...$range, self::PER_PAGE, $offset ],
		) ) ?: [];

		$this->_column_headers = [ $this->get_columns(), [], [] ];

		if ( $page === [] ) {
			$this->items = [];
			return;
		}

		$refs         = array_map( fn ( object $row ) => (string) $row->conversion_ref, $page );
		$attributions = $this->fetch_attributions( $refs );
		$jobs         = $this->queue->count_jobs_for_conversions( $refs );

		$this->items = array_map( function ( object $row ) use ( $attributions, $jobs ): object {
			$ref  = (string) $row->conversion_ref;
			$rows = $attributions[ $ref ] ?? [];
			return (object) [
				'conversion_ref' => $ref,
				'converted_at'   => $row->converted_at,
				'attributions'   => $rows,
				'is_test'        => array_filter( $rows, fn ( object $r ) => (int) $r->is_test === 1 ) !== [],
				'jobs'           => $jobs[ $ref ] ?? [],
			];
		}, $page );
	}

	/**
	 * Renders the checkbox column for bulk actions.
	 *
	 * Named `conversion[]`, not `post[]`, so that Admin_Page cannot mistake
	 * a selection of conversions for one of tracking URLs.
	 *
	 * @param object $item Conversion item.
	 *
	 * @return string Checkbox HTML.
	 * @since 1.10.0
	 */
	protected function column_cb( $item ): string {
		return '<input type="checkbox" name="conversion[]" value="' . esc_attr( $item->conversion_ref ) . '">';
	}

	/**
	 * Returns the bulk actions.
	 *
	 * @return array<string, string> Action slug => label.
	 * @since 1.10.0
	 */
	protected function get_bulk_actions(): array {
		$actions = [];
		foreach ( [ Conversion_Action::Mark_As_Test, Conversion_Action::Unmark_As_Test, Conversion_Action::Delete ] as $action ) {
			$actions[ $action->value ] = $action->label();
		}
		return $actions;
	}

	/**
	 * Renders the conversion time.
	 *
	 * @param object $item Conversion item.
	 *
	 * @return string Column HTML.
	 * @since 1.10.0
	 */
	protected function column_converted_at( object $item ): string {
		return esc_html( $this->format_time( $item->converted_at ) );
	}

	/**
	 * Renders the row actions under the primary column, the conversion time.
	 *
	 * A test conversion offers Unmark, any other Mark as test; both offer
	 * Delete. Each link carries a nonce for its action and conversion, and
	 * the date range, so the page returns to the same view.
	 *
	 * @param object $item        Conversion item.
	 * @param string $column_name Column being rendered.
	 * @param string $primary     Primary column.
	 *
	 * @return string Row actions HTML for the primary column, otherwise empty.
	 * @since 1.10.0
	 */
	protected function handle_row_actions( $item, $column_name, $primary ): string {
		if ( $column_name !== $primary ) {
			return '';
		}

		$flag_action = $item->is_test ? Conversion_Action::Unmark_As_Test : Conversion_Action::Mark_As_Test;
		$delete      = Conversion_Action::Delete;

		return $this->row_actions( [
			'mark_test' => sprintf( '<a href="%s">%s</a>', esc_url( $this->action_url( $flag_action, $item->conversion_ref ) ), esc_html( $flag_action->label() ) ),
			'delete'    => sprintf( '<a href="%s" class="submitdelete">%s</a>', esc_url( $this->action_url( $delete, $item->conversion_ref ) ), esc_html( $delete->label() ) ),
		] );
	}

	/**
	 * Renders source, medium and campaign of each attributed click's tracking URL.
	 *
	 * @param object $item Conversion item.
	 *
	 * @return string Column HTML, one line per attributed click.
	 * @since 1.10.0
	 */
	protected function column_tracking_url( object $item ): string {
		return $this->lines( $item, function ( object $row ): string {
			if ( $row->utm_source === null && $row->utm_medium === null && $row->utm_campaign === null ) {
				return '<em>' . esc_html__( '(deleted)', 'kntnt-ad-attr' ) . '</em>';
			}
			return esc_html( "{$row->utm_source} / {$row->utm_medium} / {$row->utm_campaign}" );
		} );
	}

	/**
	 * Renders the time of each attributed click.
	 *
	 * @param object $item Conversion item.
	 *
	 * @return string Column HTML, one line per attributed click.
	 * @since 1.10.0
	 */
	protected function column_clicked_at( object $item ): string {
		return $this->lines( $item, fn ( object $row ) => esc_html( $this->format_time( $row->clicked_at ) ) );
	}

	/**
	 * Renders the content of each attributed click.
	 *
	 * @param object $item Conversion item.
	 *
	 * @return string Column HTML, one line per attributed click.
	 * @since 1.10.0
	 */
	protected function column_utm_content( object $item ): string {
		return $this->lines( $item, fn ( object $row ) => esc_html( $row->utm_content ?: '—' ) );
	}

	/**
	 * Renders the term of each attributed click.
	 *
	 * @param object $item Conversion item.
	 *
	 * @return string Column HTML, one line per attributed click.
	 * @since 1.10.0
	 */
	protected function column_utm_term( object $item ): string {
		return $this->lines( $item, fn ( object $row ) => esc_html( $row->utm_term ?: '—' ) );
	}

	/**
	 * Renders the attribution weight of each attributed click.
	 *
	 * @param object $item Conversion item.
	 *
	 * @return string Column HTML, one line per attributed click.
	 * @since 1.10.0
	 */
	protected function column_weight( object $item ): string {
		return $this->lines( $item, fn ( object $row ) => esc_html( number_format_i18n( (float) $row->fractional_conversion, 2 ) ) );
	}

	/**
	 * Renders the test flag of each attributed click.
	 *
	 * @param object $item Conversion item.
	 *
	 * @return string Column HTML, one line per attributed click.
	 * @since 1.10.0
	 */
	protected function column_is_test( object $item ): string {
		return $this->lines( $item, fn ( object $row ) => (int) $row->is_test === 1 ? esc_html__( 'Yes', 'kntnt-ad-attr' ) : esc_html__( 'No', 'kntnt-ad-attr' ) );
	}

	/**
	 * Renders the number of the conversion's report jobs per status.
	 *
	 * @param object $item Conversion item.
	 *
	 * @return string Column HTML, e.g. "1 pending, 2 done", or a dash without jobs.
	 * @since 1.10.0
	 */
	protected function column_jobs( object $item ): string {
		$parts = [];
		foreach ( Job_Status::cases() as $status ) {
			if ( empty( $item->jobs[ $status->value ] ) ) {
				continue;
			}
			$label   = match ( $status ) {
				/* translators: %d: Number of report jobs */
				Job_Status::Pending    => __( '%d pending', 'kntnt-ad-attr' ),
				/* translators: %d: Number of report jobs */
				Job_Status::Processing => __( '%d processing', 'kntnt-ad-attr' ),
				/* translators: %d: Number of report jobs */
				Job_Status::Done       => __( '%d done', 'kntnt-ad-attr' ),
				/* translators: %d: Number of report jobs */
				Job_Status::Failed     => __( '%d failed', 'kntnt-ad-attr' ),
			};
			$parts[] = sprintf( $label, $item->jobs[ $status->value ] );
		}

		return $parts === [] ? '—' : esc_html( implode( ', ', $parts ) );
	}

	/**
	 * Renders the item count and the page links.
	 *
	 * Replaces WP_List_Table's pagination, which links with `paged`, the
	 * main view's page argument on the same screen. The links keep the rest
	 * of the current URL, so the main view stays where it is, but drop the
	 * notice arguments of a finished action.
	 *
	 * @param string $which Position: 'top' or 'bottom'.
	 *
	 * @return void
	 * @since 1.10.0
	 */
	protected function pagination( $which ): void {
		$total_items = (int) $this->get_pagination_arg( 'total_items' );
		$total_pages = (int) $this->get_pagination_arg( 'total_pages' );
		$current     = $this->get_pagenum();

		$output = '<span class="displaying-num">' . esc_html( sprintf(
			/* translators: %s: Number of conversions */
			_n( '%s conversion', '%s conversions', $total_items, 'kntnt-ad-attr' ),
			number_format_i18n( $total_items ),
		) ) . '</span>';

		if ( $total_pages > 1 ) {
			// The current request's URL, as WordPress builds it without a URL
			// argument; every link is escaped with esc_url() on output.
			$current_url = remove_query_arg( [ ...wp_removable_query_args(), 'count', 'jobs_deleted', 'jobs_processing' ] );

			$link = fn ( int $page, string $label, string $arrow ): string => sprintf(
				'<a class="button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">%s</span></a>',
				esc_url( add_query_arg( self::PAGE_ARG, $page, $current_url ) ),
				esc_html( $label ),
				$arrow,
			);
			$no_link = fn ( string $arrow ): string => '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">' . $arrow . '</span>';

			$links = [
				$current > 1 ? $link( $current - 1, __( 'Newer conversions', 'kntnt-ad-attr' ), '&lsaquo;' ) : $no_link( '&lsaquo;' ),
				'<span class="paging-input">' . esc_html( sprintf(
					/* translators: 1: Current page, 2: Total pages */
					__( '%1$d of %2$d', 'kntnt-ad-attr' ),
					$current,
					$total_pages,
				) ) . '</span>',
				$current < $total_pages ? $link( $current + 1, __( 'Older conversions', 'kntnt-ad-attr' ), '&rsaquo;' ) : $no_link( '&rsaquo;' ),
			];

			$output .= ' <span class="pagination-links">' . implode( ' ', $links ) . '</span>';
		}

		$page_class = $total_pages < 2 ? ' one-page' : '';
		echo '<div class="tablenav-pages' . esc_attr( $page_class ) . '">' . $output . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/**
	 * Message displayed when no conversions are found.
	 *
	 * @return void
	 * @since 1.10.0
	 */
	public function no_items(): void {
		esc_html_e( 'No conversions for clicks in this date range.', 'kntnt-ad-attr' );
	}

	/**
	 * Builds the URL of a row action for one conversion, nonce included.
	 *
	 * @param Conversion_Action $action The action.
	 * @param string            $ref    Conversion reference.
	 *
	 * @return string Unescaped URL.
	 * @since 1.10.0
	 */
	private function action_url( Conversion_Action $action, string $ref ): string {
		return wp_nonce_url(
			admin_url( sprintf(
				'tools.php?page=%s&action=%s&conversion=%s&date_start=%s&date_end=%s',
				Plugin::get_slug(),
				$action->value,
				rawurlencode( $ref ),
				$this->date_start,
				$this->date_end,
			) ),
			"{$action->value}_{$ref}",
		);
	}

	/**
	 * Renders one line per attributed click of a conversion.
	 *
	 * @param object   $item   Conversion item.
	 * @param callable $render Returns the escaped HTML for one conversion row.
	 *
	 * @return string The lines, separated by line breaks.
	 * @since 1.10.0
	 */
	private function lines( object $item, callable $render ): string {
		return implode( '<br>', array_map( $render, $item->attributions ) );
	}

	/**
	 * Formats a stored UTC datetime in the site's time zone.
	 *
	 * @param string $datetime UTC datetime (Y-m-d H:i:s).
	 *
	 * @return string Formatted local date and time, or the input if unparsable.
	 * @since 1.10.0
	 */
	private function format_time( string $datetime ): string {
		$timestamp = strtotime( $datetime . ' UTC' );
		return $timestamp ? wp_date( 'Y-m-d H:i', $timestamp ) : $datetime;
	}

	/**
	 * Fetches the conversion rows of the given conversions with their clicks.
	 *
	 * All rows of a listed conversion are included, also clicks outside the
	 * date range, so a conversion is always shown whole.
	 *
	 * @param string[] $refs Conversion references.
	 *
	 * @return array<string, array<int, object>> Conversion reference => rows, highest weight first.
	 * @since 1.10.0
	 */
	private function fetch_attributions( array $refs ): array {
		global $wpdb;

		$conv_table   = Table::Conversions->prefixed();
		$clicks_table = Table::Clicks->prefixed();
		$placeholders = implode( ',', array_fill( 0, count( $refs ), '%s' ) );

		// The tracking URL is found by its hash; LEFT JOINs keep conversions
		// whose tracking URL has since been deleted.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT cv.conversion_ref,
				cv.fractional_conversion,
				c.clicked_at,
				c.utm_content,
				c.utm_term,
				c.is_test,
				pm_src.meta_value AS utm_source,
				pm_med.meta_value AS utm_medium,
				pm_camp.meta_value AS utm_campaign
			FROM {$conv_table} cv
			INNER JOIN {$clicks_table} c ON c.id = cv.click_id
			LEFT JOIN {$wpdb->postmeta} pm_hash
				ON pm_hash.meta_key = '_hash' AND pm_hash.meta_value = c.hash
			LEFT JOIN {$wpdb->postmeta} pm_src
				ON pm_src.post_id = pm_hash.post_id AND pm_src.meta_key = '_utm_source'
			LEFT JOIN {$wpdb->postmeta} pm_med
				ON pm_med.post_id = pm_hash.post_id AND pm_med.meta_key = '_utm_medium'
			LEFT JOIN {$wpdb->postmeta} pm_camp
				ON pm_camp.post_id = pm_hash.post_id AND pm_camp.meta_key = '_utm_campaign'
			WHERE cv.conversion_ref IN ({$placeholders})
			ORDER BY cv.fractional_conversion DESC, c.clicked_at DESC",
			...$refs,
		) ) ?: [];

		$by_ref = [];
		foreach ( $rows as $row ) {
			$by_ref[ (string) $row->conversion_ref ][] = $row;
		}

		return $by_ref;
	}

}
