<?php
/**
 * Queue processor for dispatching conversion reports.
 *
 * Dequeues jobs from the async queue and dispatches them to the appropriate
 * registered reporter's process callback. Handles success/failure tracking
 * and schedules follow-up processing if jobs remain.
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
	 * Initializes the processor with its queue dependency.
	 *
	 * @param Queue $queue The job queue.
	 *
	 * @since 1.2.0
	 */
	public function __construct( Queue $queue ) {
		$this->queue = $queue;
	}

	/**
	 * Processes pending queue jobs by dispatching to registered reporters.
	 *
	 * Fetches the list of reporters via the kntnt_ad_attr_conversion_reporters
	 * filter, dequeues pending jobs, and for each job calls the matching
	 * reporter's process callback. Jobs are marked as complete on success
	 * or failed on error/exception.
	 *
	 * If pending jobs remain after processing, a new cron event is scheduled.
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
				error_log( "[Kntnt Ad Attribution] Queue job {$item->id} threw: {$e->getMessage()}" );
			}
		}

		// Schedule another run if there are more pending jobs.
		$status = $this->queue->get_status();
		if ( $status['pending'] > 0 ) {
			$this->schedule();
		}
	}

	/**
	 * Schedules a single cron event to process the queue.
	 *
	 * Uses wp_schedule_single_event to avoid duplicate scheduling.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function schedule(): void {
		if ( ! wp_next_scheduled( 'kntnt_ad_attr_process_queue' ) ) {
			wp_schedule_single_event( time(), 'kntnt_ad_attr_process_queue' );
		}
	}

}
