<?php
/**
 * Outcomes of a tracking URL request.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.10.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

/**
 * How Click_Handler counted a tracking URL request.
 *
 * The value is the `outcome` field of the request's diagnostic log line
 * (see docs/click-handling.md, "Diagnostic Log Line").
 *
 * @since 1.10.0
 */
enum Request_Outcome: string {

	/**
	 * A new click: a click row was recorded.
	 *
	 * @since 1.10.0
	 */
	case Recorded = 'recorded';

	/**
	 * A repeat of a click ID already recorded for the tracking URL.
	 *
	 * @since 1.10.0
	 */
	case Repeat = 'repeat';

	/**
	 * A click inside the deduplication window of the visitor's earlier click.
	 *
	 * @since 1.10.0
	 */
	case Deduplicated = 'deduplicated';

	/**
	 * A prefetch or prerender, declined with a 503.
	 *
	 * @since 1.10.0
	 */
	case Speculative = 'speculative';

	/**
	 * A bot, redirected without being recorded.
	 *
	 * @since 1.10.0
	 */
	case Bot = 'bot';

	/**
	 * Internal traffic, redirected without being recorded.
	 *
	 * @since 1.10.0
	 */
	case Internal = 'internal';

	/**
	 * A click whose click row could not be written.
	 *
	 * @since 1.10.0
	 */
	case Failed = 'failed';

}
