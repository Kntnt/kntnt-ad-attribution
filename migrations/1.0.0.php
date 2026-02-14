<?php
/**
 * Migration: 1.0.0 — Initial table creation.
 *
 * Creates the kntnt_ad_attr_stats table for storing click and conversion
 * statistics with daily granularity.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.0.0
 */

declare( strict_types = 1 );

// Prevent direct file access.
if ( ! defined( 'WPINC' ) ) {
	die;
}

return function ( \wpdb $wpdb ): void {

	$table   = $wpdb->prefix . 'kntnt_ad_attr_stats';
	$charset = $wpdb->get_charset_collate();

	// Daily aggregated statistics per tracking URL hash.
	// The composite primary key (hash, date) enables ON DUPLICATE KEY UPDATE
	// for atomic click/conversion increments without separate upsert logic.
	$sql = "CREATE TABLE {$table} (
		hash        CHAR(64)       NOT NULL,
		date        DATE           NOT NULL,
		clicks      INT UNSIGNED   NOT NULL DEFAULT 0,
		conversions DECIMAL(10,4)  NOT NULL DEFAULT 0,
		PRIMARY KEY (hash, date),
		INDEX idx_date (date)
	) {$charset};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
};
