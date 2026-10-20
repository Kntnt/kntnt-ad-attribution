<?php
/**
 * CRUD operations for platform-specific click IDs.
 *
 * Stores, retrieves, and cleans up click IDs (e.g. gclid, fbclid, msclkid)
 * captured by registered adapters via the kntnt_ad_attr_click_id_capturers
 * filter. Each click ID belongs to exactly one click row: one row per click
 * and platform.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

/**
 * Manages the kntnt_ad_attr_click_ids table.
 *
 * Uses global $wpdb directly — no constructor dependencies.
 *
 * Since 1.10.0 the table is keyed by `(click_id, platform)`, where
 * `click_id` is the ID of the row in the clicks table (as in the
 * conversions table) and `value` holds the platform's click ID.
 *
 * @since 1.2.0
 */
final class Click_ID_Store {

	/**
	 * Stores the click ID a platform attached to one recorded click.
	 *
	 * @param int    $click_id ID of the click row in the clicks table.
	 * @param string $platform Platform identifier (e.g. 'google_ads').
	 * @param string $value    The platform-specific click ID value (e.g. a gclid).
	 *
	 * @return void
	 * @since 1.2.0
	 * @since 1.10.0 Takes the click row ID instead of the tracking URL hash.
	 */
	public function store( int $click_id, string $platform, string $value ): void {
		global $wpdb;

		$wpdb->insert(
			Table::Click_Ids->prefixed(),
			[
				'click_id' => $click_id,
				'platform' => $platform,
				'value'    => $value,
			],
			[ '%d', '%s', '%s' ],
		);
	}

	/**
	 * Retrieves the click IDs captured on specific click rows.
	 *
	 * Takes the click row each tracking URL was credited for and returns
	 * the IDs captured on exactly those rows, keyed by hash. A click that
	 * carried no ID for a platform yields no entry for that platform, and a
	 * click that carried none at all yields no entry for its hash.
	 *
	 * @param array<string, int> $linked_clicks Hash => ID of the attributed click row.
	 *
	 * @return array<string, array<string, array{id: string, captured_at: int}>> Hash => [ platform => [ 'id' => value, 'captured_at' => the click's clicked_at ] ].
	 * @since 1.10.0
	 */
	public function get_for_clicks( array $linked_clicks ): array {
		global $wpdb;

		if ( empty( $linked_clicks ) ) {
			return [];
		}

		$table        = Table::Click_Ids->prefixed();
		$clicks_table = Table::Clicks->prefixed();
		$click_ids    = array_values( $linked_clicks );
		$placeholders = implode( ',', array_fill( 0, count( $click_ids ), '%d' ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT ci.click_id, ci.platform, ci.value, c.clicked_at
			 FROM {$table} ci
			 JOIN {$clicks_table} c ON c.id = ci.click_id
			 WHERE ci.click_id IN ({$placeholders})",
			...$click_ids,
		) );

		// A click row belongs to exactly one hash, so the map inverts safely.
		$hash_by_click = array_flip( $linked_clicks );

		$result = [];
		foreach ( $rows as $row ) {
			$hash = $hash_by_click[ (int) $row->click_id ] ?? null;
			if ( $hash === null ) {
				continue;
			}
			$result[ $hash ][ $row->platform ] = [
				'id'          => $row->value,
				'captured_at' => strtotime( $row->clicked_at . ' UTC' ),
			];
		}

		return $result;
	}

	/**
	 * Finds the earliest click on a tracking URL that carried a click ID value.
	 *
	 * Answers "has this click ID already been recorded for this tracking
	 * URL?". The `(platform, value)` index narrows the click-ID rows to the
	 * few that carry the value, and the join on the click row's primary key
	 * keeps those on this tracking URL. The same value on another tracking
	 * URL does not match.
	 *
	 * @param string $hash     SHA-256 hash of the tracking URL.
	 * @param string $platform Platform identifier (e.g. 'google_ads').
	 * @param string $value    The platform-specific click ID value.
	 *
	 * @return array{click_id: int, clicked_at: int}|null The original click's row ID and Unix timestamp, or null if the value was never recorded for the hash.
	 * @since 1.10.0
	 */
	public function find_original_click( string $hash, string $platform, string $value ): ?array {
		global $wpdb;

		$table        = Table::Click_Ids->prefixed();
		$clicks_table = Table::Clicks->prefixed();

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT c.id, c.clicked_at
			 FROM {$table} ci
			 JOIN {$clicks_table} c ON c.id = ci.click_id
			 WHERE c.hash = %s AND ci.platform = %s AND ci.value = %s
			 ORDER BY c.clicked_at ASC, c.id ASC
			 LIMIT 1",
			$hash,
			$platform,
			$value,
		) );

		if ( ! $row ) {
			return null;
		}

		return [
			'click_id'   => (int) $row->id,
			'clicked_at' => strtotime( $row->clicked_at . ' UTC' ),
		];
	}

	/**
	 * Deletes click IDs whose click row no longer exists.
	 *
	 * Click IDs live exactly as long as their click row. The daily cleanup
	 * calls this after deleting expired and orphaned clicks, so the IDs of
	 * every click deleted there go with them. It also catches IDs left by
	 * any other path that deletes click rows.
	 *
	 * Uses a correlated NOT EXISTS rather than a multi-table
	 * `DELETE … LEFT JOIN`: both work in MySQL, but the SQLite translator
	 * used by WordPress Playground silently deletes nothing for the latter.
	 *
	 * @return int Number of click-ID rows deleted.
	 * @since 1.10.0
	 */
	public function delete_orphans(): int {
		global $wpdb;

		$table        = Table::Click_Ids->prefixed();
		$clicks_table = Table::Clicks->prefixed();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$deleted = $wpdb->query(
			"DELETE FROM {$table}
			 WHERE NOT EXISTS (
			     SELECT 1 FROM {$clicks_table} c WHERE c.id = {$table}.click_id
			 )",
		);

		return (int) $deleted;
	}

}
