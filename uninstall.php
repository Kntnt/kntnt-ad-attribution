<?php
/**
 * Plugin uninstall script.
 *
 * Removes all plugin data when the plugin is deleted through WordPress admin:
 * capabilities, custom table, CPT posts, options, transients, and cron.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.0.0
 */

declare( strict_types = 1 );

// WordPress calls this file directly — the namespace autoloader is not
// loaded, so we use fully qualified WordPress functions throughout.

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}

global $wpdb, $wp_roles;

// Remove the plugin capability from all roles.
if ( isset( $wp_roles ) ) {
	foreach ( array_keys( $wp_roles->roles ) as $role_name ) {
		get_role( $role_name )?->remove_cap( 'kntnt_ad_attr' );
	}
}

// Drop custom tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}kntnt_ad_attr_stats" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}kntnt_ad_attr_click_ids" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}kntnt_ad_attr_queue" );

// Delete all tracking URL posts and their meta.
$post_ids = $wpdb->get_col(
	"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'kntnt_ad_attr_url'",
);
foreach ( $post_ids as $post_id ) {
	wp_delete_post( (int) $post_id, true );
}

// Delete the stored schema version.
delete_option( 'kntnt_ad_attr_version' );

// Remove plugin transients.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE %s
		    OR option_name LIKE %s",
		'_transient_kntnt_ad_attr_%',
		'_transient_timeout_kntnt_ad_attr_%',
	),
);

// Remove scheduled cron jobs.
wp_clear_scheduled_hook( 'kntnt_ad_attr_daily_cleanup' );
wp_clear_scheduled_hook( 'kntnt_ad_attr_process_queue' );
