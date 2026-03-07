<?php
/**
 * Migration 1.9.0 — Add deferred processing and last-attempt tracking columns.
 *
 * Adds `last_attempt_at` and `not_before` columns to the queue table, and
 * replaces the retry index with one that covers the new eligibility logic.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.9.0
 */

declare( strict_types = 1 );

return function ( \wpdb $wpdb ): void {
	$table = $wpdb->prefix . 'kntnt_ad_attr_queue';

	// Track when each job was last attempted (processing, done, or failed).
	$wpdb->query( "ALTER TABLE {$table} ADD COLUMN last_attempt_at DATETIME NULL AFTER processed_at" );

	// Defer processing until a specific time (NULL = immediately eligible).
	$wpdb->query( "ALTER TABLE {$table} ADD COLUMN not_before DATETIME NULL AFTER last_attempt_at" );

	// Replace the old retry index with one covering both retry_after and not_before.
	$wpdb->query( "ALTER TABLE {$table} DROP INDEX idx_status_retry" );
	$wpdb->query( "ALTER TABLE {$table} ADD INDEX idx_status_eligible (status, not_before, retry_after, created_at)" );
};
