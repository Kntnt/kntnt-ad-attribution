<?php

/**
 * Plugin uninstall script.
 *
 * Removes all plugin data when the plugin is deleted through WordPress admin.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.0.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    die;
}

// Remove capability from all roles.
global $wp_roles;
if ( isset( $wp_roles ) ) {
    foreach ( $wp_roles->roles as $role_name => $_ ) {
        get_role( $role_name )?->remove_cap( 'kntnt_ad_attribution' );
    }
}

// TODO: Drop custom database tables.
// TODO: Delete plugin options.