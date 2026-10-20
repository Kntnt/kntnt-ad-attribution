<?php
/**
 * Conversion handling with filterable last-click attribution.
 *
 * Listens for the `kntnt_ad_attr_conversion` action hook (fired by the form
 * plugin) and attributes the conversion to clicked tracking URLs using a
 * filterable attribution model (default: last-click).
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

/**
 * Processes conversions triggered by form submissions.
 *
 * Dependencies are injected via the constructor rather than fetched from
 * the Plugin singleton, keeping the class testable and explicit about its
 * collaborators.
 *
 * @since 1.0.0
 */
final class Conversion_Handler {

	/**
	 * Cookie manager for reading the _ad_clicks cookie.
	 *
	 * @var Cookie_Manager
	 * @since 1.0.0
	 */
	private readonly Cookie_Manager $cookie_manager;

	/**
	 * Consent resolver for determining cookie read permission.
	 *
	 * @var Consent
	 * @since 1.7.0
	 */
	private readonly Consent $consent;

	/**
	 * Bot detector for filtering automated requests.
	 *
	 * @var Bot_Detector
	 * @since 1.6.0
	 */
	private readonly Bot_Detector $bot_detector;

	/**
	 * Click ID store for retrieving platform-specific click IDs.
	 *
	 * @var Click_ID_Store
	 * @since 1.2.0
	 */
	private readonly Click_ID_Store $click_id_store;

	/**
	 * Queue for enqueuing conversion report jobs.
	 *
	 * @var Queue
	 * @since 1.2.0
	 */
	private readonly Queue $queue;

	/**
	 * Queue processor for scheduling job processing.
	 *
	 * @var Queue_Processor
	 * @since 1.2.0
	 */
	private readonly Queue_Processor $queue_processor;

	/**
	 * Logger for diagnostic output, such as cookie entries without a click row.
	 *
	 * @var Logger|null
	 * @since 1.10.0
	 */
	private readonly ?Logger $logger;

	/**
	 * Initializes the conversion handler with its dependencies.
	 *
	 * @param Cookie_Manager  $cookie_manager  Cookie read operations.
	 * @param Consent         $consent         Consent state resolution.
	 * @param Bot_Detector    $bot_detector    Bot traffic detection.
	 * @param Click_ID_Store  $click_id_store  Platform-specific click ID retrieval.
	 * @param Queue           $queue           Async job queue.
	 * @param Queue_Processor $queue_processor Queue processing scheduler.
	 * @param Logger|null     $logger          Optional diagnostic logger.
	 *
	 * @since 1.0.0
	 */
	public function __construct( Cookie_Manager $cookie_manager, Consent $consent, Bot_Detector $bot_detector, Click_ID_Store $click_id_store, Queue $queue, Queue_Processor $queue_processor, ?Logger $logger = null ) {
		$this->cookie_manager  = $cookie_manager;
		$this->consent         = $consent;
		$this->bot_detector    = $bot_detector;
		$this->click_id_store  = $click_id_store;
		$this->queue           = $queue;
		$this->queue_processor = $queue_processor;
		$this->logger          = $logger;
	}

	/**
	 * Registers the conversion action hook.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register(): void {
		add_action( 'kntnt_ad_attr_conversion', [ $this, 'handle_conversion' ] );
	}

	/**
	 * Processes a conversion through the attribution flow.
	 *
	 * Steps: cookie parse → hash validation → internal-traffic restriction
	 * to test clicks → per-hash deduplication → attribution calculation →
	 * conversions DB write (one conversion reference on every row) → dedup
	 * cookie → test flag → recorded hook → reporter enqueueing with the
	 * conversion reference (test conversions only to reporters with
	 * `supports_test`).
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_conversion(): void {

		// Reject automated requests to prevent fake conversions.
		if ( $this->bot_detector->is_bot() ) {
			return;
		}

		// Reading the _ad_clicks cookie requires confirmed consent under
		// ePrivacy Article 5(3). Without consent, no attribution possible.
		if ( $this->consent->check() !== true ) {
			return;
		}

		// Step 1: Read and parse the _ad_clicks cookie.
		$entries = $this->cookie_manager->parse( '_ad_clicks' );
		if ( empty( $entries ) ) {
			return;
		}

		// Step 2: Filter to hashes that exist as published tracking URLs.
		$valid_entries = $this->filter_valid_entries( $entries );
		if ( empty( $valid_entries ) ) {
			return;
		}

		// Step 2b: Internal traffic (e.g. logged-in staff) converts only on
		// test clicks. Their other entries are ignored rather than credited,
		// so staff testing a form cannot create a real conversion.
		if ( Internal_Detector::is_internal() ) {
			$valid_entries = $this->filter_test_entries( $valid_entries );
			if ( empty( $valid_entries ) ) {
				$this->logger?->info( 'CORE', 'Internal conversion ignored: no cookie entry points at a test click.' );
				return;
			}
		}

		// Step 3: Per-hash deduplication — remove hashes converted recently.
		$lifetime      = (int) apply_filters( 'kntnt_ad_attr_cookie_lifetime', 90 );
		$dedup_seconds = (int) apply_filters( 'kntnt_ad_attr_dedup_seconds', 0 );
		$dedup_seconds = min( $dedup_seconds, $lifetime * DAY_IN_SECONDS );

		$last_conv_entries = [];
		if ( $dedup_seconds > 0 ) {
			$last_conv_entries = $this->cookie_manager->parse( '_ad_last_conv' );
			$now = time();

			foreach ( $valid_entries as $hash => $timestamp ) {
				if ( isset( $last_conv_entries[ $hash ] ) && ( $now - $last_conv_entries[ $hash ] ) < $dedup_seconds ) {
					unset( $valid_entries[ $hash ] );
				}
			}

			if ( empty( $valid_entries ) ) {
				return;
			}
		}

		// Steps 4–5: Attribution weights, last click by default.
		$attributions = $this->attribution_weights( $valid_entries );

		// One reference for the whole conversion. A multi-touch model writes
		// one row per credited click, and the shared reference is what makes
		// those rows one conversion; every report job enqueued below carries
		// it too, so the admin can delete or flag a conversion and its jobs.
		$conversion_ref = wp_generate_uuid4();

		// Step 6: Look up click records and write conversion rows.
		$linked_clicks = $this->write_conversion( $attributions, $valid_entries, $conversion_ref );
		if ( $linked_clicks === null ) {
			return;
		}

		// Everything after the write sees only the recorded attributions:
		// hashes that got a conversion row, with the weight written. Weights
		// are not renormalised, so a multi-touch split can sum to less than
		// 1.0 when a click row was missing.
		$recorded = array_intersect_key( $attributions, $linked_clicks );

		// Nothing was written, so there is no conversion to dedup, announce
		// or report.
		if ( $recorded === [] ) {
			return;
		}

		// Step 7: Write the per-hash dedup cookie (only when dedup is enabled).
		if ( $dedup_seconds > 0 ) {
			$now = time();
			foreach ( array_keys( $recorded ) as $hash ) {
				$last_conv_entries[ $hash ] = $now;
			}
			$this->cookie_manager->set_dedup_cookie( $last_conv_entries, $dedup_seconds );
		}

		// A conversion inherits the test flag from its clicks: if any
		// recorded attribution links to a test click, the whole conversion
		// is a test.
		$is_test = $this->links_test_click( $linked_clicks );

		// Step 8: Notify other components that a conversion was recorded.
		do_action( 'kntnt_ad_attr_conversion_recorded', $recorded, [
			'timestamp'  => gmdate( 'c' ),
			'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
			'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
			'test'       => $is_test,
		] );

		// Step 9: Enqueue conversion reports for registered reporters.
		$reporters = $this->enqueue_reports( $recorded, $linked_clicks, $is_test, $conversion_ref );

		// A test is meant to be checked, so say where it went.
		if ( $is_test ) {
			$this->logger?->info( 'CORE', sprintf(
				'Test conversion recorded for %s; passed to reporters with test support: %s.',
				implode( ', ', array_map(
					fn ( $hash, $click_row_id ) => sprintf( 'hash %s... (click row %d)', substr( $hash, 0, Logger::HASH_PREFIX_LENGTH ), $click_row_id ),
					array_keys( $linked_clicks ),
					$linked_clicks,
				) ),
				$reporters === [] ? 'none' : implode( ', ', array_keys( $reporters ) ),
			) );
		}
	}

	/**
	 * Returns the attribution weights of a conversion.
	 *
	 * By default the most recent click gets full credit; the
	 * `kntnt_ad_attr_attribution` filter can replace the model.
	 *
	 * @param array<string, int> $entries Hash => click timestamp of the clicks that may be credited.
	 *
	 * @return array<string, float> Hash => fractional value.
	 * @since 1.10.0
	 */
	private function attribution_weights( array $entries ): array {
		$clicks = [];
		foreach ( $entries as $hash => $timestamp ) {
			$clicks[] = [ 'hash' => $hash, 'clicked_at' => $timestamp ];
		}

		$latest_hash  = array_keys( $entries, max( $entries ) )[0];
		$attributions = array_fill_keys( array_keys( $entries ), 0.0 );
		$attributions[ $latest_hash ] = 1.0;

		/**
		 * Filters the attribution weights for a conversion.
		 *
		 * @param array<string, float>                       $attributions Hash => fractional value. Default: 1.0 for last click, 0.0 for rest.
		 * @param array<int, array{hash: string, clicked_at: int}> $clicks Click data with timestamps.
		 *
		 * @since 1.5.0
		 */
		return apply_filters( 'kntnt_ad_attr_attribution', $attributions, $clicks );
	}

	/**
	 * Writes one conversion row per positive weight, in one transaction.
	 *
	 * Each row links to the click row that exactly matches the hash's cookie
	 * entry. A hash without such a row gets no conversion row; that breaks
	 * the invariant that every _ad_clicks entry points at a click row, so it
	 * is logged. A failed insert rolls the whole conversion back.
	 *
	 * @param array<string, float> $attributions   Hash => fractional value.
	 * @param array<string, int>   $entries        Hash => cookie timestamp.
	 * @param string               $conversion_ref Reference written on every row.
	 *
	 * @return array<string, int>|null Recorded hash => ID of the click row its conversion row links to, or null if the write was rolled back.
	 * @since 1.10.0
	 */
	private function write_conversion( array $attributions, array $entries, string $conversion_ref ): ?array {
		global $wpdb;

		// Find the click rows matching the credited hashes' cookie entries.
		// The match stays exact on purpose: the clicks table is shared by
		// everyone who clicks the tracking URL, so the nearest earlier row
		// is often another visitor's click.
		$credited   = array_intersect_key( $entries, array_filter( $attributions, fn ( $value ) => $value > 0 ) );
		$click_rows = Click_Lookup::find( array_map( null, array_keys( $credited ), $credited ) );

		$conv_table   = Table::Conversions->prefixed();
		$converted_at = gmdate( 'Y-m-d H:i:s' );

		$wpdb->query( 'START TRANSACTION' );

		// Recorded hash => ID of the click row its conversion row links to.
		// It defines what was recorded, and it identifies the exact click
		// each hash was credited for, not just any click on that hash.
		$linked_clicks = [];

		foreach ( $attributions as $hash => $value ) {
			if ( $value <= 0 ) {
				continue;
			}

			$click_id = $click_rows[ $hash ][ $entries[ $hash ] ]['id'] ?? null;

			// A miss is logged without visitor-identifying data.
			if ( ! $click_id ) {
				$this->logger?->warning( 'CORE', sprintf(
					'Conversion not recorded for hash %s...: no click row matches cookie timestamp %d (%s UTC).',
					substr( $hash, 0, Logger::HASH_PREFIX_LENGTH ),
					$entries[ $hash ],
					gmdate( 'Y-m-d H:i:s', $entries[ $hash ] ),
				) );
				continue;
			}

			$result = $wpdb->insert( $conv_table, [
				'click_id'              => (int) $click_id,
				'converted_at'          => $converted_at,
				'fractional_conversion' => $value,
				'conversion_ref'        => $conversion_ref,
			] );

			if ( $result === false ) {
				$wpdb->query( 'ROLLBACK' );
				error_log( '[Kntnt Ad Attribution] Conversion write failed, rolled back.' );
				return null;
			}

			$linked_clicks[ $hash ] = (int) $click_id;
		}

		$wpdb->query( 'COMMIT' );

		return $linked_clicks;
	}

	/**
	 * Hands a recorded conversion to the registered reporters and queues their jobs.
	 *
	 * A test conversion reaches only reporters that declare test support,
	 * so a reporter unaware of test mode can never upload one by accident.
	 * Every job carries the conversion reference.
	 *
	 * @param array<string, float> $recorded       Hash => recorded fractional value.
	 * @param array<string, int>   $linked_clicks  Hash => ID of the attributed click row.
	 * @param bool                 $is_test        Whether the conversion is a test.
	 * @param string               $conversion_ref Reference of the conversion.
	 *
	 * @return array<string, array> The reporters the conversion was passed to, by ID.
	 * @since 1.10.0
	 */
	private function enqueue_reports( array $recorded, array $linked_clicks, bool $is_test, string $conversion_ref ): array {
		$reporters = apply_filters( 'kntnt_ad_attr_conversion_reporters', [] );

		if ( $is_test ) {
			$reporters = array_filter( $reporters, fn ( $reporter ) => ( $reporter['supports_test'] ?? false ) === true );
		}

		if ( empty( $reporters ) ) {
			return $reporters;
		}

		// Report what the credited click carried: its click IDs and per-click
		// fields, not those of the latest click on the hash, which may belong
		// to another visitor.
		$click_ids = $this->click_id_store->get_for_clicks( $linked_clicks );
		$campaigns = $this->get_campaign_data( $linked_clicks );

		$context = [
			'timestamp'  => gmdate( 'c' ),
			'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
			'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
			'page_url'   => home_url( $_SERVER['REQUEST_URI'] ?? '' ),
			'test'       => $is_test,
		];

		foreach ( $reporters as $reporter_id => $reporter ) {
			if ( ! is_callable( $reporter['enqueue'] ?? null ) ) {
				continue;
			}
			$items = (array) ( $reporter['enqueue'] )( $recorded, $click_ids, $campaigns, $context );
			foreach ( $items as $item ) {
				if ( isset( $item['payload'] ) ) {

					// Structured format with optional label, retry params, and deferred processing.
					$this->queue->enqueue(
						(string) $reporter_id,
						$item['payload'],
						$item['label'] ?? '',
						$item['retry_params'] ?? [],
						$item['not_before'] ?? null,
						$conversion_ref,
					);
				} else {

					// Legacy format: raw payload array.
					$this->queue->enqueue( (string) $reporter_id, $item, conversion_ref: $conversion_ref );
				}
			}
		}

		$this->queue_processor->schedule();

		return $reporters;
	}

	/**
	 * Filters cookie entries to only those with published tracking URLs.
	 *
	 * Queries the database for hashes that exist as `_hash` meta on published
	 * tracking URL posts, then returns only the matching entries.
	 *
	 * @param array<string, int> $entries Hash => timestamp map from the cookie.
	 *
	 * @return array<string, int> Filtered map containing only valid entries.
	 * @since 1.0.0
	 */
	private function filter_valid_entries( array $entries ): array {
		$valid_hashes = Post_Type::get_valid_hashes( array_keys( $entries ) );

		return array_intersect_key( $entries, array_flip( $valid_hashes ) );
	}

	/**
	 * Filters cookie entries to those that point at a test click row.
	 *
	 * Uses the exact match of the click lookup (Click_Lookup), so an entry
	 * kept here is one whose conversion links to a test click.
	 *
	 * @param array<string, int> $entries Hash => timestamp map from the cookie.
	 *
	 * @return array<string, int> The entries whose click row is a test click.
	 * @since 1.10.0
	 */
	private function filter_test_entries( array $entries ): array {
		$click_rows = Click_Lookup::find( array_map( null, array_keys( $entries ), $entries ) );

		return array_filter(
			$entries,
			fn ( $timestamp, $hash ) => $click_rows[ $hash ][ $timestamp ]['is_test'] ?? false,
			ARRAY_FILTER_USE_BOTH,
		);
	}

	/**
	 * Checks whether any of the given click rows is a test click.
	 *
	 * @param array<string, int> $linked_clicks Hash => ID of the attributed click row.
	 *
	 * @return bool True if at least one of the click rows is flagged as a test.
	 * @since 1.10.0
	 */
	private function links_test_click( array $linked_clicks ): bool {
		global $wpdb;

		$clicks_table  = Table::Clicks->prefixed();
		$click_row_ids = array_values( $linked_clicks );
		$placeholders  = implode( ',', array_fill( 0, count( $click_row_ids ), '%d' ) );

		$test_click_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$clicks_table} WHERE is_test = 1 AND id IN ({$placeholders})",
			...$click_row_ids,
		) );

		return $test_click_ids !== [];
	}

	/**
	 * Retrieves campaign data (UTM parameters) for the attributed clicks.
	 *
	 * Source/Medium/Campaign are fixed per tracking URL and fetched from
	 * postmeta. Content/Term/Id/Group vary per click and are fetched from
	 * the click row each hash was credited for.
	 *
	 * @param array<string, int> $linked_clicks Hash => ID of the attributed click row.
	 *
	 * @return array<string, array> Hash => ['utm_source' => ..., 'utm_medium' => ..., ...].
	 * @since 1.2.0
	 * @since 1.10.0 Takes the attributed click rows instead of hashes.
	 */
	private function get_campaign_data( array $linked_clicks ): array {
		global $wpdb;

		if ( empty( $linked_clicks ) ) {
			return [];
		}

		$hashes       = array_keys( $linked_clicks );
		$placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );

		// Fetch Source/Medium/Campaign from postmeta.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT pm_hash.meta_value AS hash,
			        pm_utm.meta_key,
			        pm_utm.meta_value
			 FROM {$wpdb->postmeta} pm_hash
			 JOIN {$wpdb->posts} p ON p.ID = pm_hash.post_id
			 JOIN {$wpdb->postmeta} pm_utm ON pm_utm.post_id = p.ID
			    AND pm_utm.meta_key IN ('_utm_source', '_utm_medium', '_utm_campaign')
			 WHERE pm_hash.meta_key = '_hash'
			   AND pm_hash.meta_value IN ({$placeholders})
			   AND p.post_type = %s
			   AND p.post_status = 'publish'",
			...array_merge( $hashes, [ Post_Type::SLUG ] ),
		) );

		$result = [];
		foreach ( $rows as $row ) {
			$key = ltrim( $row->meta_key, '_' );
			$result[ $row->hash ][ $key ] = $row->meta_value;
		}

		// Fetch Content/Term/Id/Group from the attributed click rows.
		$clicks_table    = Table::Clicks->prefixed();
		$click_row_ids   = array_values( $linked_clicks );
		$id_placeholders = implode( ',', array_fill( 0, count( $click_row_ids ), '%d' ) );

		$click_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT hash, utm_content, utm_term, utm_id, utm_source_platform
			 FROM {$clicks_table}
			 WHERE id IN ({$id_placeholders})",
			...$click_row_ids,
		) );

		foreach ( $click_rows as $row ) {
			$result[ $row->hash ]['utm_content']         = $row->utm_content ?? '';
			$result[ $row->hash ]['utm_term']            = $row->utm_term ?? '';
			$result[ $row->hash ]['utm_id']              = $row->utm_id ?? '';
			$result[ $row->hash ]['utm_source_platform'] = $row->utm_source_platform ?? '';
		}

		return $result;
	}

}
