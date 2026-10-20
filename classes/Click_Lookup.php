<?php
/**
 * Exact lookup of recorded clicks.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.10.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

/**
 * Finds the click rows that cookie entries and pending clicks point at.
 *
 * A cookie entry, a pending-consent transport and a posted pair all name a
 * click as `hash:timestamp`, and point at the click row with exactly that
 * hash and `clicked_at`. Conversions are linked by that exact match, never
 * by the nearest row, because the clicks table is shared by everyone who
 * clicks a tracking URL. This class is the one place that performs it.
 *
 * @since 1.10.0
 */
final class Click_Lookup {

	/**
	 * Finds the click rows that exactly match the given clicks.
	 *
	 * One query for all clicks. When several rows match a click (two clicks
	 * on a tracking URL in the same second), the oldest row is returned.
	 *
	 * @param array<int, array{0: string, 1: int}> $clicks Hash–timestamp pairs, e.g. cookie entries.
	 *
	 * @return array<string, array<int, array{id: int, is_test: bool}>> Hash => timestamp => the matching click row's ID and test flag. Clicks without a row are absent.
	 * @since 1.10.0
	 */
	public static function find( array $clicks ): array {
		global $wpdb;

		if ( $clicks === [] ) {
			return [];
		}

		$table      = Table::Clicks->prefixed();
		$conditions = implode( ' OR ', array_fill( 0, count( $clicks ), '(hash = %s AND clicked_at = %s)' ) );
		$args       = [];
		foreach ( $clicks as [ $hash, $timestamp ] ) {
			$args[] = $hash;
			$args[] = gmdate( 'Y-m-d H:i:s', $timestamp );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, hash, clicked_at, is_test FROM {$table} WHERE {$conditions} ORDER BY id",
			...$args,
		) ) ?: [];

		// Rows by hash and clicked_at, the oldest first.
		$rows_by_click = [];
		foreach ( $rows as $row ) {
			$rows_by_click[ $row->hash ][ $row->clicked_at ] ??= [
				'id'      => (int) $row->id,
				'is_test' => (int) $row->is_test === 1,
			];
		}

		$found = [];
		foreach ( $clicks as [ $hash, $timestamp ] ) {
			$row = $rows_by_click[ $hash ][ gmdate( 'Y-m-d H:i:s', $timestamp ) ] ?? null;
			if ( $row !== null ) {
				$found[ $hash ][ $timestamp ] = $row;
			}
		}

		return $found;
	}

}
