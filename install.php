<?php

/**
 * Plugin activation script.
 *
 * Sets up capabilities when the plugin is activated.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.0.0
 */

// Security check - ensure this is called during plugin activation.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Add custom capability to Editor and above.
foreach ( [ 'administrator', 'editor' ] as $role_name ) {
    get_role( $role_name )?->add_cap( 'kntnt_ad_attribution' );
}