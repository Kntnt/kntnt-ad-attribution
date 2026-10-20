<?php
/**
 * Data access for the admin's actions on recorded conversions.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.10.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

/**
 * Counts, deletes and flags recorded conversions by conversion reference.
 *
 * A conversion is the set of conversion rows that share a conversion
 * reference (see Conversion_Handler). Uses global $wpdb directly, like
 * Click_ID_Store. Every write is a single-table statement with subqueries,
 * so it also runs in the SQLite translator used by the integration tests.
 *
 * @since 1.10.0
 */
final class Conversion_Store {

	/**
	 * Counts how many of the given conversions exist.
	 *
	 * @param string[] $refs Conversion references.
	 *
	 * @return int Number of distinct conversions found.
	 * @since 1.10.0
	 */
	public function count( array $refs ): int {
		global $wpdb;

		if ( $refs === [] ) {
			return 0;
		}

		$conv_table   = Table::Conversions->prefixed();
		$placeholders = self::placeholders( $refs );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT conversion_ref) FROM {$conv_table} WHERE conversion_ref IN ({$placeholders})",
			...$refs,
		) );
	}

	/**
	 * Returns the conversions linked to any attributed click of the given conversions.
	 *
	 * The test flag lives on the click, so these are the conversions that
	 * flagging the given conversions' clicks affects. The given conversions
	 * are included, as far as they exist.
	 *
	 * @param string[] $refs Conversion references.
	 *
	 * @return string[] References of the conversions sharing those clicks.
	 * @since 1.10.0
	 */
	public function sharing_clicks( array $refs ): array {
		global $wpdb;

		if ( $refs === [] ) {
			return [];
		}

		$conv_table   = Table::Conversions->prefixed();
		$placeholders = self::placeholders( $refs );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT conversion_ref FROM {$conv_table} WHERE click_id IN (SELECT click_id FROM {$conv_table} WHERE conversion_ref IN ({$placeholders}))",
			...$refs,
		) );
	}

	/**
	 * Deletes the rows of the given conversions. Their clicks stay.
	 *
	 * @param string[] $refs Conversion references.
	 *
	 * @return void
	 * @since 1.10.0
	 */
	public function delete( array $refs ): void {
		global $wpdb;

		if ( $refs === [] ) {
			return;
		}

		$conv_table   = Table::Conversions->prefixed();
		$placeholders = self::placeholders( $refs );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$conv_table} WHERE conversion_ref IN ({$placeholders})",
			...$refs,
		) );
	}

	/**
	 * Sets or clears the test flag on the attributed clicks of the given conversions.
	 *
	 * The flag lives on the click row, and the main view excludes test
	 * clicks together with their conversions (Campaign_List_Table), so
	 * this also flags any other conversion linked to the same click.
	 *
	 * @param string[] $refs    Conversion references.
	 * @param bool     $is_test True to mark as test, false to unmark.
	 *
	 * @return void
	 * @since 1.10.0
	 */
	public function flag_clicks_as_test( array $refs, bool $is_test ): void {
		global $wpdb;

		if ( $refs === [] ) {
			return;
		}

		$clicks_table = Table::Clicks->prefixed();
		$conv_table   = Table::Conversions->prefixed();
		$placeholders = self::placeholders( $refs );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$clicks_table} SET is_test = %d WHERE id IN (SELECT click_id FROM {$conv_table} WHERE conversion_ref IN ({$placeholders}))",
			$is_test ? 1 : 0,
			...$refs,
		) );
	}

	/**
	 * Returns one `%s` placeholder per reference, comma-separated.
	 *
	 * @param string[] $refs Conversion references.
	 *
	 * @return string The placeholders for an `IN (…)` list.
	 * @since 1.10.0
	 */
	private static function placeholders( array $refs ): string {
		return implode( ',', array_fill( 0, count( $refs ), '%s' ) );
	}

}
