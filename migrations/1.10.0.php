<?php
/**
 * Migration 1.10.0 — Per-click click IDs, the test flag on click rows and
 * the conversion reference on conversion rows and report jobs.
 *
 * Every section in this file must be safe to run more than once. The
 * Migrator records only the version reached, not which files or sections
 * completed, so the whole file runs again whenever the stored version is
 * set back below 1.10.0. Guard each change on the current schema instead
 * of assuming the previous version's.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.10.0
 */

declare( strict_types = 1 );

// Prevent direct file access.
if ( ! defined( 'WPINC' ) ) {
	die;
}

return function ( \wpdb $wpdb ): void {

	$charset = $wpdb->get_charset_collate();

	// Column names of a table, or an empty array if the table does not
	// exist. Checking existence first avoids a database error on a missing
	// table, which WordPress prints when WP_DEBUG is on.
	$columns_of = static function ( string $table ) use ( $wpdb ): array {
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $found !== $table ) {
			return [];
		}
		return $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
	};

	// ─── Click IDs keyed by click row ───

	// Until 1.9.x the table held one row per tracking URL and platform,
	// overwritten by every click. Those rows cannot be mapped to click rows,
	// so the table is replaced rather than altered. A table that already has
	// the per-click layout is kept, so a re-run drops no data.
	$click_ids_table = $wpdb->prefix . 'kntnt_ad_attr_click_ids';

	if ( in_array( 'hash', $columns_of( $click_ids_table ), true ) ) {
		$wpdb->query( "DROP TABLE {$click_ids_table}" );
	}

	// One row per click and platform. `click_id` is the clicks table's row
	// ID, as in the conversions table; `value` is the platform's click ID.
	// The (platform, value) index answers "has this click ID already been
	// recorded for this tracking URL?" together with the join on clicks.id
	// (see Click_ID_Store::find_original_click()). The 100-character prefix
	// keeps the key under InnoDB's 767-byte limit on older MySQL versions
	// with utf8mb4; real click IDs are unique well within it.
	$wpdb->query( "CREATE TABLE IF NOT EXISTS {$click_ids_table} (
		click_id BIGINT UNSIGNED NOT NULL,
		platform VARCHAR(50)     NOT NULL,
		value    VARCHAR(255)    NOT NULL,
		PRIMARY KEY (click_id, platform),
		INDEX idx_platform_value (platform, value(100))
	) {$charset}" );

	// ─── Test flag on click rows ───

	// A test click (tracking URL requested with kntnt_ad_attr_test=1) is
	// recorded like any other but flagged, and a conversion inherits the
	// flag through its click. Existing clicks are real clicks, hence the
	// default. The column is added only when missing, so a re-run is a no-op.
	$clicks_table   = $wpdb->prefix . 'kntnt_ad_attr_clicks';
	$clicks_columns = $columns_of( $clicks_table );

	if ( $clicks_columns !== [] && ! in_array( 'is_test', $clicks_columns, true ) ) {
		$wpdb->query( "ALTER TABLE {$clicks_table} ADD COLUMN is_test TINYINT(1) NOT NULL DEFAULT 0" );
	}

	// ─── Conversion reference on conversion rows and report jobs ───

	// A multi-touch model writes one conversion as several rows, so the rows
	// of one conversion share a reference (a UUID, see Conversion_Handler).
	// Report jobs enqueued for a conversion carry the same reference, which
	// lets the admin delete or flag a conversion together with its jobs. The
	// column and its index are added together, only when the column is
	// missing, so a re-run is a no-op.
	$conversions_table  = $wpdb->prefix . 'kntnt_ad_attr_conversions';
	$conversion_columns = $columns_of( $conversions_table );

	if ( $conversion_columns !== [] && ! in_array( 'conversion_ref', $conversion_columns, true ) ) {
		$wpdb->query( "ALTER TABLE {$conversions_table}
			ADD COLUMN conversion_ref VARCHAR(36) NULL,
			ADD INDEX idx_conversion_ref (conversion_ref)" );
	}

	// Rows written before 1.10.0 cannot be grouped reliably (rows of one
	// conversion share only a converted_at with second resolution), so each
	// becomes a conversion of its own, referenced by its row ID. A UUID never
	// looks like a row ID, and only rows without a reference are touched.
	if ( $conversion_columns !== [] ) {
		$wpdb->query( "UPDATE {$conversions_table} SET conversion_ref = id WHERE conversion_ref IS NULL" );
	}

	// Jobs created before 1.10.0 keep NULL: they belong to no conversion, and
	// no conversion action touches them.
	$queue_table   = $wpdb->prefix . 'kntnt_ad_attr_queue';
	$queue_columns = $columns_of( $queue_table );

	if ( $queue_columns !== [] && ! in_array( 'conversion_ref', $queue_columns, true ) ) {
		$wpdb->query( "ALTER TABLE {$queue_table}
			ADD COLUMN conversion_ref VARCHAR(36) NULL,
			ADD INDEX idx_queue_conversion_ref (conversion_ref)" );
	}

};
