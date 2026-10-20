<?php
/**
 * Statuses of a report job in the queue.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.10.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

/**
 * The status of a job in the queue table.
 *
 * A job goes pending → processing → done, or back to pending for a retry,
 * or to failed once its retries are used up.
 *
 * @since 1.10.0
 */
enum Job_Status: string {

	/**
	 * Waiting to be processed, possibly not before a given time.
	 *
	 * @since 1.10.0
	 */
	case Pending = 'pending';

	/**
	 * Being processed, so possibly talking to the platform right now.
	 *
	 * @since 1.10.0
	 */
	case Processing = 'processing';

	/**
	 * Processed successfully: the report has reached the platform.
	 *
	 * @since 1.10.0
	 */
	case Done = 'done';

	/**
	 * Given up after its last retry.
	 *
	 * @since 1.10.0
	 */
	case Failed = 'failed';

}
