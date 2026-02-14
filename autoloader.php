<?php

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

// Prevent direct file access.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Autoloader for plugin classes.
 *
 * Automatically loads classes from the classes/ directory when they are first used.
 * Follows PSR-4 naming conventions with the plugin's namespace.
 *
 * @since 1.0.0
 */
spl_autoload_register( function ( string $class_name ): void {

	// Only handle classes in our namespace
	if ( ! str_starts_with( $class_name, __NAMESPACE__ . '\\' ) ) {
		return;
	}

	// Extract the class name without the namespace
	$relative_class_name = substr( $class_name, strlen( __NAMESPACE__ . '\\' ) );

	// Build the file path (class name maps directly to file name)
	$file_path = __DIR__ . '/classes/' . $relative_class_name . '.php';

	// Load the file if it exists
	if ( file_exists( $file_path ) ) {
		require_once $file_path;
	}

} );