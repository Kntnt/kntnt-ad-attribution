<?php
/**
 * Migration: 1.5.0 — Individual click recording and conversion attribution.
 *
 * Creates the kntnt_ad_attr_clicks table for per-click storage and the
 * kntnt_ad_attr_conversions table for attribution. Drops the aggregated
 * kntnt_ad_attr_stats table which is superseded by these new tables.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.5.0
 */

declare( strict_types = 1 );

// Prevent direct file access.
if ( ! defined( 'WPINC' ) ) {
	die;
}

return function ( \wpdb $wpdb ): void {

	$charset = $wpdb->get_charset_collate();

	// Individual click records. Source/Medium/Campaign are fixed per tracking
	// URL and retrieved via JOIN against postmeta. Content/Term/Id/Group vary
	// per click and are stored here.
	$clicks_table = $wpdb->prefix . 'kntnt_ad_attr_clicks';

	$sql_clicks = "CREATE TABLE {$clicks_table} (
		id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		hash                CHAR(64)        NOT NULL,
		clicked_at          DATETIME        NOT NULL,
		utm_content         VARCHAR(255)    NULL,
		utm_term            VARCHAR(255)    NULL,
		utm_id              VARCHAR(255)    NULL,
		utm_source_platform VARCHAR(255)    NULL,
		PRIMARY KEY (id),
		INDEX idx_hash (hash),
		INDEX idx_clicked_at (clicked_at)
	) {$charset};";

	// Conversion attribution linked to specific clicks. A single conversion
	// creates one row per attributed click (with last-click default: one row).
	$conversions_table = $wpdb->prefix . 'kntnt_ad_attr_conversions';

	$sql_conversions = "CREATE TABLE {$conversions_table} (
		id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		click_id              BIGINT UNSIGNED NOT NULL,
		converted_at          DATETIME        NOT NULL,
		fractional_conversion DECIMAL(10,4)   NOT NULL,
		PRIMARY KEY (id),
		INDEX idx_click_id (click_id),
		INDEX idx_converted_at (converted_at)
	) {$charset};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql_clicks );
	dbDelta( $sql_conversions );

	// Drop the aggregated stats table — all data can now be derived from
	// the individual click and conversion records.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}kntnt_ad_attr_stats" );
};
