<?php
/**
 * Migration: 1.2.0 — Click ID storage and async job queue tables.
 *
 * Creates the kntnt_ad_attr_click_ids table for platform-specific click IDs
 * captured by registered adapters, and the kntnt_ad_attr_queue table for
 * asynchronous conversion reporting.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.2.0
 */

declare( strict_types = 1 );

// Prevent direct file access.
if ( ! defined( 'WPINC' ) ) {
	die;
}

return function ( \wpdb $wpdb ): void {

	$charset = $wpdb->get_charset_collate();

	// Platform-specific click IDs captured by registered adapters.
	// Composite PK (hash, platform): a single click can carry IDs from
	// multiple platforms, but each hash/platform pair has exactly one row.
	$click_ids_table = $wpdb->prefix . 'kntnt_ad_attr_click_ids';

	$sql_click_ids = "CREATE TABLE {$click_ids_table} (
		hash       CHAR(64)     NOT NULL,
		platform   VARCHAR(50)  NOT NULL,
		click_id   VARCHAR(255) NOT NULL,
		clicked_at DATETIME     NOT NULL,
		PRIMARY KEY (hash, platform),
		INDEX idx_clicked_at (clicked_at)
	) {$charset};";

	// Async job queue for conversion reporting via registered reporters.
	// Status transitions: pending → processing → done | failed.
	$queue_table = $wpdb->prefix . 'kntnt_ad_attr_queue';

	$sql_queue = "CREATE TABLE {$queue_table} (
		id            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
		reporter      VARCHAR(50)      NOT NULL,
		payload       TEXT             NOT NULL,
		status        VARCHAR(20)      NOT NULL DEFAULT 'pending',
		attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
		created_at    DATETIME         NOT NULL,
		processed_at  DATETIME         NULL,
		error_message TEXT             NULL,
		PRIMARY KEY (id),
		INDEX idx_status (status, created_at)
	) {$charset};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql_click_ids );
	dbDelta( $sql_queue );
};
