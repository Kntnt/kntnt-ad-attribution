<?php
/**
 * Plugin Name:       Kntnt Ad Attribution
 * Plugin URI:        https://github.com/Kntnt/kntnt-ad-attribution
 * Description:       Provides internal lead attribution for ad campaigns using first-party cookies and fractional, time-weighted attribution.
 * Version:           0.0.1
 * Author:            Thomas Barregren
 * Author URI:        https://www.kntnt.com/
 * License:           GPL-2.0-or-later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires PHP:      8.3
 * Requires at least: 6.9
 * Text Domain:       kntnt-ad-attribution
 * Domain Path:       /languages
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

// Prevent direct file access.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Register the autoloader for the plugin's classes.
require_once __DIR__ . '/autoloader.php';

// Run install script on plugin activation.
register_activation_hook( __FILE__, function () {
    require_once __DIR__ . '/install.php';
} );

// Set the plugin file path for the Plugin class to use.
Plugin::set_plugin_file( __FILE__ );

// Initialize the plugin.
Plugin::get_instance();
