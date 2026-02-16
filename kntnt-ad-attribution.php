<?php
/**
 * Plugin Name:       Kntnt Ad Attribution
 * Plugin URI:        https://github.com/Kntnt/kntnt-ad-attribution
 * Description:       Provides internal lead attribution for ad campaigns using first-party cookies and fractional, time-weighted attribution.
 * Version:           1.1.0
 * Author:            Kntnt Sweden AB
 * Author URI:        https://www.kntnt.com/
 * License:           GPL-2.0-or-later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires PHP:      8.3
 * Requires at least: 6.9
 * Text Domain:       kntnt-ad-attr
 * Domain Path:       /languages
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

// Abort with an admin notice if the PHP version is too old.
if ( version_compare( PHP_VERSION, '8.3', '<' ) ) {
	add_action( 'admin_notices', function (): void {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: Required PHP version, 2: Current PHP version. */
					__( 'Kntnt Ad Attribution requires PHP %1$s or later. You are running PHP %2$s. The plugin has been deactivated.', 'kntnt-ad-attr' ),
					'8.3',
					PHP_VERSION,
				),
			),
		);
	} );
	return;
}

// Register the autoloader for the plugin's classes.
require_once __DIR__ . '/autoloader.php';

// Run install script on plugin activation.
register_activation_hook( __FILE__, function (): void {
	require_once __DIR__ . '/install.php';
} );

// Run cleanup on plugin deactivation.
register_deactivation_hook( __FILE__, [ Plugin::class, 'deactivate' ] );

// Set the plugin file path for the Plugin class to use.
Plugin::set_plugin_file( __FILE__ );

// Initialize the plugin.
Plugin::get_instance();
