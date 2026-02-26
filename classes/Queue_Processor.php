<?php
/**
 * Queue processor for dispatching conversion reports.
 *
 * Dequeues jobs from the async queue and dispatches them to the appropriate
 * registered reporter's process callback. Handles success/failure tracking,
 * smart retry scheduling, and single-job processing for admin actions.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

/**
 * Processes queued conversion report jobs via registered reporters.
 *
 * @since 1.2.0
 */
final class Queue_Processor {

	/**
	 * Queue instance for dequeuing and updating job status.
	 *
	 * @var Queue
	 * @since 1.2.0
	 */
	private readonly Queue $queue;

	/**
	 * Logger instance for diagnostic output.
	 *
	 * @var Logger|null
	 * @since 1.8.0
	 */
	private readonly ?Logger $logger;

	/**
	 * Initializes the processor with its dependencies.
	 *
	 * @param Queue       $queue  The job queue.
	 * @param Logger|null $logger Optional diagnostic logger.
	 *
	 * @since 1.2.0
	 */
	public function __construct( Queue $queue, ?Logger $logger = null ) {
		$this->queue  = $queue;
		$this->logger = $logger;
	}

	/**
	 * Processes pending queue jobs by dispatching to registered reporters.
	 *
	 * Fetches the list of reporters via the kntnt_ad_attr_conversion_reporters
	 * filter, dequeues pending jobs, and for each job calls the matching
	 * reporter's process callback. Jobs are marked as complete on success
	 * or failed on error/exception.
	 *
	 * After processing, schedules follow-up processing based on retry timing.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function process(): void {

		/** @var array<string, array> $reporters Registered conversion reporters. */
		$reporters = apply_filters( 'kntnt_ad_attr_conversion_reporters', [] );

		if ( empty( $reporters ) ) {
			return;
		}

		$items = $this->queue->dequeue();

		foreach ( $items as $item ) {

			$reporter = $reporters[ $item->reporter ] ?? null;

			if ( ! $reporter || ! is_callable( $reporter['process'] ?? null ) ) {
				$this->queue->fail( $item->id, "Unknown or invalid reporter: {$item->reporter}" );
				continue;
			}

			try {
				$result = ( $reporter['process'] )( $item->payload );

				if ( $result === true ) {
					$this->queue->complete( $item->id );
				} else {
					$this->queue->fail( $item->id, 'Reporter returned false.' );
				}
			} catch ( \Throwable $e ) {
				$this->queue->fail( $item->id, $e->getMessage() );
				$this->logger?->error( 'CORE', "Queue job {$item->id} threw: {$e->getMessage()}" );
			}
		}

		// Smart scheduling: check if pending jobs exist and when they're ready.
		$status = $this->queue->get_status();
		if ( $status['pending'] > 0 ) {
			$next_retry = $this->queue->get_next_retry_time();

			if ( $next_retry !== null && $next_retry > time() ) {

				// All pending jobs have a future retry_after — schedule at the earliest.
				$this->schedule_at( $next_retry );
			} else {

				// Some jobs are immediately ready — schedule now.
				$this->schedule();
			}
		}
	}

	/**
	 * Processes a single job by ID, with different behavior for pending vs failed jobs.
	 *
	 * Pending jobs: on failure, normal retry via Queue::fail().
	 * Failed jobs: one-shot attempt. Success → done. Failure → stays failed, no retry.
	 *
	 * @param int  $job_id        Job ID to process.
	 * @param bool $is_failed_job Whether this is a re-run of a failed job.
	 *
	 * @return bool True if the job was processed successfully.
	 * @since 1.8.0
	 */
	public function process_single( int $job_id, bool $is_failed_job = false ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'kntnt_ad_attr_queue';

		// Fetch the job directly.
		$job = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, reporter, payload, status FROM {$table} WHERE id = %d",
			$job_id,
		) );

		if ( ! $job ) {
			return false;
		}

		// Mark as processing.
		$wpdb->update(
			$table,
			[ 'status' => 'processing' ],
			[ 'id' => $job_id ],
			[ '%s' ],
			[ '%d' ],
		);

		/** @var array<string, array> $reporters Registered conversion reporters. */
		$reporters = apply_filters( 'kntnt_ad_attr_conversion_reporters', [] );
		$reporter  = $reporters[ $job->reporter ] ?? null;

		if ( ! $reporter || ! is_callable( $reporter['process'] ?? null ) ) {
			$this->queue->fail( $job_id, "Unknown or invalid reporter: {$job->reporter}" );
			return false;
		}

		$payload = json_decode( $job->payload, true );

		try {
			$result = ( $reporter['process'] )( $payload );

			if ( $result === true ) {
				$this->queue->complete( $job_id );
				return true;
			}

			// Failure handling depends on job type.
			if ( $is_failed_job ) {

				// One-shot: update error, keep failed status, no retry.
				$wpdb->update(
					$table,
					[
						'status'        => 'failed',
						'error_message' => 'Reporter returned false.',
					],
					[ 'id' => $job_id ],
					[ '%s', '%s' ],
					[ '%d' ],
				);
			} else {
				$this->queue->fail( $job_id, 'Reporter returned false.' );
			}

			return false;

		} catch ( \Throwable $e ) {

			if ( $is_failed_job ) {
				$wpdb->update(
					$table,
					[
						'status'        => 'failed',
						'error_message' => $e->getMessage(),
					],
					[ 'id' => $job_id ],
					[ '%s', '%s' ],
					[ '%d' ],
				);
			} else {
				$this->queue->fail( $job_id, $e->getMessage() );
			}

			$this->logger?->error( 'CORE', "Queue job {$job_id} threw: {$e->getMessage()}" );
			return false;
		}
	}

	/**
	 * Schedules a single cron event to process the queue immediately.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function schedule(): void {
		if ( ! wp_next_scheduled( 'kntnt_ad_attr_process_queue' ) ) {
			wp_schedule_single_event( time(), 'kntnt_ad_attr_process_queue' );
		}
	}

	/**
	 * Schedules a single cron event at a specific time.
	 *
	 * @param int $timestamp Unix timestamp for when to run.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function schedule_at( int $timestamp ): void {
		if ( ! wp_next_scheduled( 'kntnt_ad_attr_process_queue' ) ) {
			wp_schedule_single_event( $timestamp, 'kntnt_ad_attr_process_queue' );
		}
	}

}
