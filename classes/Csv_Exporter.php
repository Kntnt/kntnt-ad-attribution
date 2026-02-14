<?php
/**
 * CSV exporter for campaign statistics.
 *
 * Streams aggregated campaign data as a downloadable CSV file with
 * locale-aware formatting (decimal separator and field delimiter).
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

/**
 * Stateless CSV exporter.
 *
 * Takes a data array and filter parameters, then streams the CSV
 * to the browser with appropriate headers.
 *
 * @since 1.0.0
 */
final class Csv_Exporter {

	/**
	 * Streams a CSV file to the browser and terminates execution.
	 *
	 * @param array  $items      Row objects from Campaign_List_Table::fetch_all_items().
	 * @param string $date_start Start date filter value ('1970-01-01' if unset).
	 * @param string $date_end   End date filter value ('9999-12-31' if unset).
	 *
	 * @return never
	 * @since 1.0.0
	 */
	public function export( array $items, string $date_start, string $date_end ): never {

		// Determine locale-aware decimal separator via WordPress i18n.
		$decimal_point = trim( number_format_i18n( 0.1, 1 ), '01' );
		$delimiter     = $decimal_point === ',' ? ';' : ',';

		// Build filename with optional date range.
		$has_date_filter = $date_start !== '1970-01-01' || $date_end !== '9999-12-31';
		if ( $has_date_filter ) {
			$filename = sprintf(
				'kntnt-ad-attribution-%s-to-%s.csv',
				sanitize_file_name( $date_start ),
				sanitize_file_name( $date_end ),
			);
		} else {
			$filename = 'kntnt-ad-attribution-' . gmdate( 'Y-m-d' ) . '.csv';
		}

		// Send headers for CSV download.
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// Open output stream.
		$output = fopen( 'php://output', 'w' );

		// UTF-8 BOM for Excel compatibility.
		fwrite( $output, "\xEF\xBB\xBF" );

		// Header row.
		fputcsv( $output, [
			__( 'Tracking URL', 'kntnt-ad-attr' ),
			__( 'Target URL', 'kntnt-ad-attr' ),
			__( 'Source', 'kntnt-ad-attr' ),
			__( 'Medium', 'kntnt-ad-attr' ),
			__( 'Campaign', 'kntnt-ad-attr' ),
			__( 'Content', 'kntnt-ad-attr' ),
			__( 'Term', 'kntnt-ad-attr' ),
			__( 'Clicks', 'kntnt-ad-attr' ),
			__( 'Conversions', 'kntnt-ad-attr' ),
		], $delimiter );

		// Build tracking URL prefix once.
		/** @var string $prefix The URL path prefix for tracking URLs. */
		$prefix = apply_filters( 'kntnt_ad_attr_url_prefix', 'ad' );

		// Data rows.
		foreach ( $items as $item ) {
			$tracking_url = home_url( $prefix . '/' . $item->hash );
			$target_url   = get_permalink( (int) $item->target_post_id );

			fputcsv( $output, [
				$tracking_url,
				$target_url ?: __( '(deleted)', 'kntnt-ad-attr' ),
				$item->utm_source ?? '',
				$item->utm_medium ?? '',
				$item->utm_campaign ?? '',
				$item->utm_content ?? '',
				$item->utm_term ?? '',
				(int) $item->total_clicks,
				number_format( (float) $item->total_conversions, 4, $decimal_point, '' ),
			], $delimiter );
		}

		fclose( $output );
		exit;
	}

}
