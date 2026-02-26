<?php
/**
 * Async job queue for conversion reporting.
 *
 * Provides CRUD operations for the kntnt_ad_attr_queue table, which stores
 * jobs enqueued by registered conversion reporters. Jobs transition through
 * pending → processing → done | failed. Supports round-based retry with
 * per-job parameters and configurable delays.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

/**
 * Manages the kntnt_ad_attr_queue table.
 *
 * @since 1.2.0
 */
final class Queue {

	/**
	 * Settings instance for retry defaults.
	 *
	 * @var Settings
	 * @since 1.8.0
	 */
	private readonly Settings $settings;

	/**
	 * Initializes the queue with a Settings dependency.
	 *
	 * @param Settings $settings Plugin settings for retry defaults.
	 *
	 * @since 1.8.0
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Enqueues a new job for a given reporter.
	 *
	 * @param string $reporter     Reporter identifier.
	 * @param array  $payload      Data to be processed (will be JSON-encoded).
	 * @param string $label        Human-readable description of the job.
	 * @param array  $retry_params Per-job retry overrides (attempts_per_round, retry_delay, max_rounds, round_delay).
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function enqueue( string $reporter, array $payload, string $label = '', array $retry_params = [] ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'kntnt_ad_attr_queue';

		// Merge per-job retry params with global defaults.
		$defaults = [
			'attempts_per_round' => (int) $this->settings->get( 'attempts_per_round' ),
			'retry_delay'        => (int) $this->settings->get( 'retry_delay' ),
			'max_rounds'         => (int) $this->settings->get( 'max_rounds' ),
			'round_delay'        => (int) $this->settings->get( 'round_delay' ),
		];
		$retry = array_merge( $defaults, array_intersect_key( $retry_params, $defaults ) );

		$wpdb->insert( $table, [
			'reporter'           => $reporter,
			'payload'            => wp_json_encode( $payload ),
			'status'             => 'pending',
			'attempts'           => 0,
			'created_at'         => gmdate( 'Y-m-d H:i:s' ),
			'label'              => $label !== '' ? $label : null,
			'attempts_per_round' => $retry['attempts_per_round'],
			'retry_delay'        => $retry['retry_delay'],
			'max_rounds'         => $retry['max_rounds'],
			'round_delay'        => $retry['round_delay'],
		], [ '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%d' ] );
	}

	/**
	 * Dequeues pending jobs for processing.
	 *
	 * Selects pending jobs that are ready (retry_after has passed or is NULL),
	 * then atomically updates their status to 'processing'.
	 *
	 * @param int $limit Maximum number of jobs to dequeue.
	 *
	 * @return array Array of objects with id, reporter, payload (decoded), and retry params.
	 * @since 1.2.0
	 */
	public function dequeue( int $limit = 10 ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'kntnt_ad_attr_queue';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, reporter, payload, attempts, attempts_per_round, retry_delay, max_rounds, round_delay
			 FROM {$table}
			 WHERE status = 'pending'
			   AND (retry_after IS NULL OR retry_after <= UTC_TIMESTAMP())
			 ORDER BY retry_after ASC, created_at ASC
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

		// Decode JSON payloads and cast types for the caller.
		foreach ( $rows as $row ) {
			$row->id                 = (int) $row->id;
			$row->payload            = json_decode( $row->payload, true );
			$row->attempts           = (int) $row->attempts;
			$row->attempts_per_round = $row->attempts_per_round !== null ? (int) $row->attempts_per_round : null;
			$row->retry_delay        = $row->retry_delay !== null ? (int) $row->retry_delay : null;
			$row->max_rounds         = $row->max_rounds !== null ? (int) $row->max_rounds : null;
			$row->round_delay        = $row->round_delay !== null ? (int) $row->round_delay : null;
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
	 * Records a job failure and handles round-based retry logic.
	 *
	 * Increments the attempt counter. Uses per-job retry parameters when
	 * available, falling back to global Settings defaults for pre-migration
	 * jobs that have NULL columns.
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

		// Fetch current job state including per-job retry parameters.
		$job = $wpdb->get_row( $wpdb->prepare(
			"SELECT attempts, attempts_per_round, retry_delay, max_rounds, round_delay
			 FROM {$table} WHERE id = %d",
			$id,
		) );

		if ( ! $job ) {
			return;
		}

		$k = (int) $job->attempts + 1;

		// Resolve per-job parameters with fallback to Settings defaults.
		$attempts_per_round = $job->attempts_per_round !== null ? (int) $job->attempts_per_round : (int) $this->settings->get( 'attempts_per_round' );
		$retry_delay        = $job->retry_delay !== null ? (int) $job->retry_delay : (int) $this->settings->get( 'retry_delay' );
		$max_rounds         = $job->max_rounds !== null ? (int) $job->max_rounds : (int) $this->settings->get( 'max_rounds' );
		$round_delay        = $job->round_delay !== null ? (int) $job->round_delay : (int) $this->settings->get( 'round_delay' );

		// Calculate max total attempts across all rounds.
		$max_total = $attempts_per_round * $max_rounds;

		if ( $k >= $max_total ) {

			// Permanently failed.
			$wpdb->update(
				$table,
				[
					'attempts'      => $k,
					'status'        => 'failed',
					'error_message' => $message,
					'processed_at'  => gmdate( 'Y-m-d H:i:s' ),
					'retry_after'   => null,
				],
				[ 'id' => $id ],
				[ '%d', '%s', '%s', '%s', null ],
				[ '%d' ],
			);
		} elseif ( $attempts_per_round > 0 && $k % $attempts_per_round === 0 ) {

			// End of a round — apply round delay.
			$retry_at = gmdate( 'Y-m-d H:i:s', time() + $round_delay );
			$wpdb->update(
				$table,
				[
					'attempts'      => $k,
					'status'        => 'pending',
					'error_message' => $message,
					'retry_after'   => $retry_at,
				],
				[ 'id' => $id ],
				[ '%d', '%s', '%s', '%s' ],
				[ '%d' ],
			);
		} else {

			// Within a round — apply retry delay.
			$retry_at = gmdate( 'Y-m-d H:i:s', time() + $retry_delay );
			$wpdb->update(
				$table,
				[
					'attempts'      => $k,
					'status'        => 'pending',
					'error_message' => $message,
					'retry_after'   => $retry_at,
				],
				[ 'id' => $id ],
				[ '%d', '%s', '%s', '%s' ],
				[ '%d' ],
			);
		}
	}

	/**
	 * Permanently deletes a job from the queue.
	 *
	 * @param int $id Job ID.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function delete( int $id ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'kntnt_ad_attr_queue';
		$wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
	}

	/**
	 * Clears the retry_after timestamp for a job, making it immediately eligible.
	 *
	 * @param int $id Job ID.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function reset_retry( int $id ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'kntnt_ad_attr_queue';
		$wpdb->update(
			$table,
			[ 'retry_after' => null ],
			[ 'id' => $id ],
		);
	}

	/**
	 * Returns the earliest retry_after timestamp among pending jobs with future retry.
	 *
	 * @return int|null Unix timestamp of the next retry, or null if none.
	 * @since 1.8.0
	 */
	public function get_next_retry_time(): ?int {
		global $wpdb;

		$table = $wpdb->prefix . 'kntnt_ad_attr_queue';

		$result = $wpdb->get_var(
			"SELECT MIN(retry_after)
			 FROM {$table}
			 WHERE status = 'pending'
			   AND retry_after IS NOT NULL
			   AND retry_after > UTC_TIMESTAMP()",
		);

		if ( $result === null ) {
			return null;
		}

		return strtotime( $result . ' UTC' ) ?: null;
	}

	/**
	 * Returns all pending and failed jobs for the admin queue table.
	 *
	 * @return array Array of row objects.
	 * @since 1.8.0
	 */
	public function get_active_jobs(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'kntnt_ad_attr_queue';

		return $wpdb->get_results(
			"SELECT id, reporter, status, attempts, created_at, retry_after, error_message, label
			 FROM {$table}
			 WHERE status IN ('pending', 'failed', 'processing')
			 ORDER BY created_at DESC",
		);
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
