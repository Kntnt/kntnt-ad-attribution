<?php
/**
 * Migration 1.10.0 — Per-click click IDs.
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

};
