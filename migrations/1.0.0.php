<?php
/**
 * Migration: 1.0.0 — Initial table creation (no-op).
 *
 * This migration originally created the kntnt_ad_attr_stats table. On fresh
 * installs (stored version 0.0.0) migration 1.5.0 immediately drops the stats
 * table in the same batch, so creating it here is unnecessary work. The
 * callable signature is preserved for the Migrator's version-comparison loop.
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
	// Intentionally empty — the stats table was superseded by clicks +
	// conversions tables in migration 1.5.0.
};
