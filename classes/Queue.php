<?php
/**
 * Async job queue for conversion reporting.
 *
 * Provides CRUD operations for the kntnt_ad_attr_queue table, which stores
 * jobs enqueued by registered conversion reporters. Jobs transition through
 * pending → processing → done | failed.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

/**
 * Manages the kntnt_ad_attr_queue table.
 *
 * Uses global $wpdb directly — no constructor dependencies.
 *
 * @since 1.2.0
 */
final class Queue {

	/**
	 * Maximum number of processing attempts before a job is marked as failed.
	 *
	 * @since 1.2.0
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * Enqueues a new job for a given reporter.
	 *
	 * @param string $reporter Reporter identifier.
	 * @param array  $payload  Data to be processed (will be JSON-encoded).
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function enqueue( string $reporter, array $payload ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'kntnt_ad_attr_queue';

		$wpdb->insert( $table, [
			'reporter'   => $reporter,
			'payload'    => wp_json_encode( $payload ),
			'status'     => 'pending',
			'attempts'   => 0,
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		], [ '%s', '%s', '%s', '%d', '%s' ] );
	}

	/**
	 * Dequeues pending jobs for processing.
	 *
	 * Selects pending jobs ordered by creation time, then atomically
	 * updates their status to 'processing' to prevent double-processing.
	 *
	 * @param int $limit Maximum number of jobs to dequeue.
	 *
	 * @return array Array of objects with id, reporter, payload (decoded).
	 * @since 1.2.0
	 */
	public function dequeue( int $limit = 10 ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'kntnt_ad_attr_queue';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, reporter, payload
			 FROM {$table}
			 WHERE status = 'pending'
			 ORDER BY created_at ASC
			 LIMIT %d",
			$limit,
		) );

		if ( empty( $rows ) ) {
			return [];
		}

		$ids          = array_map( fn( object $row ) => (int) $row->id, $rows );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table}
			 SET status = 'processing'
			 WHERE id IN ({$placeholders})",
			...$ids,
		) );

		// Decode JSON payloads for the caller.
		foreach ( $rows as $row ) {
			$row->id      = (int) $row->id;
			$row->payload = json_decode( $row->payload, true );
		}

		return $rows;
	}

	/**
	 * Marks a job as successfully completed.
	 *
	 * @param int $id Job ID.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function complete( int $id ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'kntnt_ad_attr_queue';

		$wpdb->update(
			$table,
			[
				'status'       => 'done',
				'processed_at' => gmdate( 'Y-m-d H:i:s' ),
			],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ],
		);
	}

	/**
	 * Records a job failure and handles retry logic.
	 *
	 * Increments the attempt counter. If attempts reach MAX_ATTEMPTS, the
	 * job is marked as 'failed' with the error message. Otherwise, the job
	 * is returned to 'pending' for retry.
	 *
	 * @param int    $id      Job ID.
	 * @param string $message Error message describing the failure.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function fail( int $id, string $message ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'kntnt_ad_attr_queue';

		// Fetch current attempts to decide whether to fail permanently.
		$attempts = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT attempts FROM {$table} WHERE id = %d",
			$id,
		) );

		$new_attempts = $attempts + 1;

		if ( $new_attempts >= self::MAX_ATTEMPTS ) {
			$wpdb->update(
				$table,
				[
					'attempts'      => $new_attempts,
					'status'        => 'failed',
					'error_message' => $message,
					'processed_at'  => gmdate( 'Y-m-d H:i:s' ),
				],
				[ 'id' => $id ],
				[ '%d', '%s', '%s', '%s' ],
				[ '%d' ],
			);
		} else {
			$wpdb->update(
				$table,
				[
					'attempts'      => $new_attempts,
					'status'        => 'pending',
					'error_message' => $message,
				],
				[ 'id' => $id ],
				[ '%d', '%s', '%s' ],
				[ '%d' ],
			);
		}
	}

	/**
	 * Cleans up old completed and failed jobs.
	 *
	 * @param int $done_days   Delete 'done' jobs older than this many days.
	 * @param int $failed_days Delete 'failed' jobs older than this many days.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function cleanup( int $done_days = 30, int $failed_days = 90 ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'kntnt_ad_attr_queue';

		$done_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $done_days * DAY_IN_SECONDS ) );
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE status = 'done' AND processed_at < %s",
			$done_cutoff,
		) );

		$failed_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $failed_days * DAY_IN_SECONDS ) );
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE status = 'failed' AND processed_at < %s",
			$failed_cutoff,
		) );
	}

	/**
	 * Returns queue status counts and the last error message.
	 *
	 * @return array{pending: int, processing: int, done: int, failed: int, last_error: ?string}
	 * @since 1.2.0
	 */
	public function get_status(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'kntnt_ad_attr_queue';

		$counts = $wpdb->get_results(
			"SELECT status, COUNT(*) AS cnt FROM {$table} GROUP BY status",
		);

		$status = [
			'pending'    => 0,
			'processing' => 0,
			'done'       => 0,
			'failed'     => 0,
			'last_error' => null,
		];

		foreach ( $counts as $row ) {
			if ( isset( $status[ $row->status ] ) ) {
				$status[ $row->status ] = (int) $row->cnt;
			}
		}

		// Fetch the most recent error message from failed jobs.
		$status['last_error'] = $wpdb->get_var(
			"SELECT error_message FROM {$table}
			 WHERE status = 'failed' AND error_message IS NOT NULL
			 ORDER BY processed_at DESC LIMIT 1",
		);

		return $status;
	}

}
