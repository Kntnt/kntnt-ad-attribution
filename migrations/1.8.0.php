<?php
/**
 * Migration: 1.8.0 — Round-based retry columns for the queue table.
 *
 * Adds per-job retry parameters and a composite index for efficient
 * retry_after-aware dequeuing.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.8.0
 */

declare( strict_types = 1 );

// Prevent direct file access.
if ( ! defined( 'WPINC' ) ) {
	die;
}

return function ( \wpdb $wpdb ): void {

	$table = $wpdb->prefix . 'kntnt_ad_attr_queue';

	// Add retry scheduling and per-job retry parameter columns.
	// NULL defaults allow pre-migration jobs to fall back to global settings.
	$wpdb->query( "ALTER TABLE {$table}
		ADD COLUMN retry_after        DATETIME         NULL AFTER error_message,
		ADD COLUMN label              VARCHAR(255)     NULL AFTER retry_after,
		ADD COLUMN attempts_per_round TINYINT UNSIGNED NULL AFTER label,
		ADD COLUMN retry_delay        INT UNSIGNED     NULL AFTER attempts_per_round,
		ADD COLUMN max_rounds         TINYINT UNSIGNED NULL AFTER retry_delay,
		ADD COLUMN round_delay        INT UNSIGNED     NULL AFTER max_rounds" );

	// Replace the simple status index with a composite one that supports
	// retry_after-aware dequeuing: WHERE status = 'pending' AND retry_after <= NOW().
	$wpdb->query( "ALTER TABLE {$table}
		DROP INDEX idx_status,
		ADD INDEX idx_status_retry (status, retry_after, created_at)" );
};
