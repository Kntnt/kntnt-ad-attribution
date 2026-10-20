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
	 * Initializes the conversion handler with its dependencies.
	 *
	 * @param Cookie_Manager  $cookie_manager  Cookie read operations.
	 * @param Consent         $consent         Consent state resolution.
	 * @param Bot_Detector    $bot_detector    Bot traffic detection.
	 * @param Click_ID_Store  $click_id_store  Platform-specific click ID retrieval.
	 * @param Queue           $queue           Async job queue.
	 * @param Queue_Processor $queue_processor Queue processing scheduler.
	 *
	 * @since 1.0.0
	 */
	public function __construct( Cookie_Manager $cookie_manager, Consent $consent, Bot_Detector $bot_detector, Click_ID_Store $click_id_store, Queue $queue, Queue_Processor $queue_processor ) {
		$this->cookie_manager  = $cookie_manager;
		$this->consent         = $consent;
		$this->bot_detector    = $bot_detector;
		$this->click_id_store  = $click_id_store;
		$this->queue           = $queue;
		$this->queue_processor = $queue_processor;
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
	 * Steps: cookie parse → hash validation → per-hash deduplication →
	 * attribution calculation → conversions DB write → dedup cookie →
	 * recorded hook → reporter enqueueing.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_conversion(): void {
		global $wpdb;

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

		// Step 4: Prepare click data for the attribution filter.
		$clicks = [];
		foreach ( $valid_entries as $hash => $timestamp ) {
			$clicks[] = [ 'hash' => $hash, 'clicked_at' => $timestamp ];
		}

		// Step 5: Default last-click attribution — full credit to most recent click.
		$latest_hash  = array_keys( $valid_entries, max( $valid_entries ) )[0];
		$attributions = array_fill_keys( array_keys( $valid_entries ), 0.0 );
		$attributions[ $latest_hash ] = 1.0;

		/**
		 * Filters the attribution weights for a conversion.
		 *
		 * @param array<string, float>                       $attributions Hash => fractional value. Default: 1.0 for last click, 0.0 for rest.
		 * @param array<int, array{hash: string, clicked_at: int}> $clicks Click data with timestamps.
		 *
		 * @since 1.5.0
		 */
		$attributions = apply_filters( 'kntnt_ad_attr_attribution', $attributions, $clicks );

		// Step 6: Look up click records and write conversion rows.
		$clicks_table = $wpdb->prefix . 'kntnt_ad_attr_clicks';
		$conv_table   = $wpdb->prefix . 'kntnt_ad_attr_conversions';
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

			// Find the click record matching the cookie timestamp.
			$click_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$clicks_table} WHERE hash = %s AND clicked_at = %s LIMIT 1",
				$hash,
				gmdate( 'Y-m-d H:i:s', $valid_entries[ $hash ] ),
			) );

			if ( ! $click_id ) {
				continue;
			}

			$result = $wpdb->insert( $conv_table, [
				'click_id'              => (int) $click_id,
				'converted_at'          => $converted_at,
				'fractional_conversion' => $value,
			] );

			if ( $result === false ) {
				$wpdb->query( 'ROLLBACK' );
				error_log( '[Kntnt Ad Attribution] Conversion write failed, rolled back.' );
				return;
			}

			$linked_clicks[ $hash ] = (int) $click_id;
		}

		$wpdb->query( 'COMMIT' );

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

		// Step 8: Notify other components that a conversion was recorded.
		do_action( 'kntnt_ad_attr_conversion_recorded', $recorded, [
			'timestamp'  => gmdate( 'c' ),
			'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
			'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
		] );

		// Step 9: Enqueue conversion reports for registered reporters.
		$reporters = apply_filters( 'kntnt_ad_attr_conversion_reporters', [] );
		if ( ! empty( $reporters ) ) {

			// Report what the credited click carried: its click IDs and
			// per-click fields, not those of the latest click on the hash,
			// which may belong to another visitor.
			$click_ids = $this->click_id_store->get_for_clicks( $linked_clicks );
			$campaigns = $this->get_campaign_data( $linked_clicks );

			$context = [
				'timestamp'  => gmdate( 'c' ),
				'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
				'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
				'page_url'   => home_url( $_SERVER['REQUEST_URI'] ?? '' ),
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
						);
					} else {

						// Legacy format: raw payload array.
						$this->queue->enqueue( (string) $reporter_id, $item );
					}
				}
			}

			$this->queue_processor->schedule();
		}
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
		$clicks_table    = $wpdb->prefix . 'kntnt_ad_attr_clicks';
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
