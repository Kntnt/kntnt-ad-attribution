<?php
/**
 * CRUD operations for platform-specific click IDs.
 *
 * Stores, retrieves, and cleans up click IDs (e.g. gclid, fbclid, msclkid)
 * captured by registered adapters via the kntnt_ad_attr_click_id_capturers
 * filter. Each hash/platform combination has exactly one row.
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
 * @since 1.2.0
 */
final class Click_ID_Store {

	/**
	 * Stores a click ID for a given hash and platform.
	 *
	 * Uses INSERT … ON DUPLICATE KEY UPDATE to upsert. If the same
	 * hash/platform combination already exists, the click_id and
	 * clicked_at are updated.
	 *
	 * @param string $hash     SHA-256 hash of the tracking URL.
	 * @param string $platform Platform identifier (e.g. 'google_ads').
	 * @param string $click_id The platform-specific click ID value.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function store( string $hash, string $platform, string $click_id ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'kntnt_ad_attr_click_ids';
		$now   = gmdate( 'Y-m-d H:i:s' );

		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$table} (hash, platform, click_id, clicked_at)
			 VALUES (%s, %s, %s, %s)
			 ON DUPLICATE KEY UPDATE click_id = %s, clicked_at = %s",
			$hash,
			$platform,
			$click_id,
			$now,
			$click_id,
			$now,
		) );
	}

	/**
	 * Retrieves click IDs for a set of hashes.
	 *
	 * @param string[] $hashes SHA-256 hashes to look up.
	 *
	 * @return array<string, array<string, string>> Hash => [ platform => click_id ].
	 * @since 1.2.0
	 */
	public function get_for_hashes( array $hashes ): array {
		global $wpdb;

		if ( empty( $hashes ) ) {
			return [];
		}

		$table        = $wpdb->prefix . 'kntnt_ad_attr_click_ids';
		$placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT hash, platform, click_id
			 FROM {$table}
			 WHERE hash IN ({$placeholders})",
			...$hashes,
		) );

		$result = [];
		foreach ( $rows as $row ) {
			$result[ $row->hash ][ $row->platform ] = $row->click_id;
		}

		return $result;
	}

	/**
	 * Deletes click ID rows older than a given number of days.
	 *
	 * @param int $days Rows with clicked_at older than this are removed.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function cleanup( int $days ): void {
		global $wpdb;

		$table  = $wpdb->prefix . 'kntnt_ad_attr_click_ids';
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE clicked_at < %s",
			$cutoff,
		) );
	}

}
