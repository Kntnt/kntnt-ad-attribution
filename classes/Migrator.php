<?php
/**
 * Version-based database migration runner.
 *
 * Compares the stored database version with the current plugin version and
 * executes migration files in sequential order to bring the database schema
 * up to date.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

/**
 * Runs pending database migrations.
 *
 * Migration files are located in the migrations/ directory and named after
 * the version they migrate TO (e.g. 1.0.0.php). Each file returns a callable
 * that receives a wpdb instance.
 *
 * @since 1.0.0
 */
final class Migrator {

	/**
	 * Option key for the stored database schema version.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private const VERSION_OPTION = 'kntnt_ad_attr_version';

	/**
	 * Runs all pending migrations.
	 *
	 * Compares the stored version against the plugin version. If they differ,
	 * executes each migration file whose version is greater than the stored
	 * version and less than or equal to the plugin version.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function run(): void {
		$current_version = Plugin::get_version();
		$stored_version  = get_option( self::VERSION_OPTION, '0.0.0' );

		// Nothing to do if the database is already at the current version.
		if ( version_compare( $stored_version, $current_version, '>=' ) ) {
			return;
		}

		$this->execute_pending( $stored_version, $current_version );
		update_option( self::VERSION_OPTION, $current_version );
	}

	/**
	 * Executes migration files that fall within the version range.
	 *
	 * @param string $from_version Stored version (exclusive lower bound).
	 * @param string $to_version   Plugin version (inclusive upper bound).
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function execute_pending( string $from_version, string $to_version ): void {
		global $wpdb;

		$migrations_dir = Plugin::get_plugin_dir() . 'migrations';
		if ( ! is_dir( $migrations_dir ) ) {
			return;
		}

		// Collect migration files whose version is within range.
		$pending = [];
		foreach ( glob( $migrations_dir . '/*.php' ) as $file ) {
			$version = basename( $file, '.php' );
			if ( version_compare( $version, $from_version, '>' )
				&& version_compare( $version, $to_version, '<=' ) ) {
				$pending[ $version ] = $file;
			}
		}

		// Sort by version to ensure correct execution order.
		uksort( $pending, 'version_compare' );

		// Execute each migration callable with the wpdb instance.
		foreach ( $pending as $file ) {
			$migration = require $file;
			if ( is_callable( $migration ) ) {
				$migration( $wpdb );
			}
		}
	}

}
