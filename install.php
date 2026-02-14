<?php
/**
 * Plugin activation script.
 *
 * Adds capabilities, runs database migrations, registers rewrite rules,
 * and schedules the daily cron job.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

// Prevent direct file access.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Grant the plugin capability to Administrator and Editor roles.
foreach ( [ 'administrator', 'editor' ] as $role_name ) {
	get_role( $role_name )?->add_cap( 'kntnt_ad_attr' );
}

// Run pending database migrations (creates table on first activation).
( new Migrator() )->run();

// Register the CPT and flush rewrite rules so its REST route takes effect.
( new Post_Type() )->register();
flush_rewrite_rules();

// Schedule the daily cleanup cron job if not already scheduled.
if ( ! wp_next_scheduled( 'kntnt_ad_attr_daily_cleanup' ) ) {
	wp_schedule_event( time(), 'daily', 'kntnt_ad_attr_daily_cleanup' );
}
