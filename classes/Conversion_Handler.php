<?php
/**
 * Conversion handling with fractional time-weighted attribution.
 *
 * Listens for the `kntnt_ad_attr_conversion` action hook (fired by the form
 * plugin) and distributes a single conversion across all previously clicked
 * tracking URLs using time-weighted fractional attribution.
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
	 * @param Click_ID_Store  $click_id_store  Platform-specific click ID retrieval.
	 * @param Queue           $queue           Async job queue.
	 * @param Queue_Processor $queue_processor Queue processing scheduler.
	 *
	 * @since 1.0.0
	 */
	public function __construct( Cookie_Manager $cookie_manager, Click_ID_Store $click_id_store, Queue $queue, Queue_Processor $queue_processor ) {
		$this->cookie_manager  = $cookie_manager;
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
	 * Processes a conversion through the 10-step attribution flow.
	 *
	 * Steps: deduplication check → cookie parse → hash validation →
	 * weight calculation → transactional DB write → dedup cookie →
	 * recorded hook.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_conversion(): void {
		global $wpdb;

		// Step 1–2: Deduplication — skip if a conversion was recorded recently.
		$last_conv = (int) ( $_COOKIE['_ad_last_conv'] ?? 0 );
		$lifetime  = (int) apply_filters( 'kntnt_ad_attr_cookie_lifetime', 90 );
		$dedup     = min( (int) apply_filters( 'kntnt_ad_attr_dedup_days', 30 ), $lifetime );

		if ( $last_conv > 0 && ( time() - $last_conv ) < ( $dedup * DAY_IN_SECONDS ) ) {
			return;
		}

		// Step 3–4: Read and parse the _ad_clicks cookie.
		$entries = $this->cookie_manager->parse();
		if ( empty( $entries ) ) {
			return;
		}

		// Step 5: Filter to hashes that exist as published tracking URLs.
		$valid_entries = $this->filter_valid_entries( $entries );
		if ( empty( $valid_entries ) ) {
			return;
		}

		// Step 6–7: Calculate fractional time-weighted attribution.
		$now     = time();
		$weights = [];

		foreach ( $valid_entries as $hash => $timestamp ) {
			$days            = ( $now - $timestamp ) / DAY_IN_SECONDS;
			$weights[ $hash ] = max( $lifetime - $days, 1 );
		}

		$total        = array_sum( $weights );
		$attributions = array_map( fn( float $w ) => $w / $total, $weights );

		// Step 8: Write fractional conversions in a transaction.
		$table = $wpdb->prefix . 'kntnt_ad_attr_stats';
		$date  = gmdate( 'Y-m-d' );

		$wpdb->query( 'START TRANSACTION' );

		foreach ( $attributions as $hash => $value ) {
			$result = $wpdb->query( $wpdb->prepare(
				"INSERT INTO {$table} (hash, date, clicks, conversions)
				 VALUES (%s, %s, 0, %f)
				 ON DUPLICATE KEY UPDATE conversions = conversions + %f",
				$hash,
				$date,
				$value,
				$value,
			) );

			if ( $result === false ) {
				$wpdb->query( 'ROLLBACK' );
				error_log( '[Kntnt Ad Attribution] Conversion write failed, rolled back.' );
				return;
			}
		}

		$wpdb->query( 'COMMIT' );

		// Step 9: Set the _ad_last_conv deduplication cookie.
		setcookie( '_ad_last_conv', (string) time(), [
			'expires'  => time() + ( $dedup * DAY_IN_SECONDS ),
			'path'     => '/',
			'secure'   => true,
			'httponly'  => true,
			'samesite' => 'Lax',
		] );

		// Step 10: Notify other components that a conversion was recorded.
		do_action( 'kntnt_ad_attr_conversion_recorded', $attributions, [
			'timestamp'  => gmdate( 'c' ),
			'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
			'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
		] );

		// Step 11: Enqueue conversion reports for registered reporters.
		$reporters = apply_filters( 'kntnt_ad_attr_conversion_reporters', [] );
		if ( ! empty( $reporters ) ) {

			// Look up click IDs and campaign data for attributed hashes.
			$hashes    = array_keys( $attributions );
			$click_ids = $this->click_id_store->get_for_hashes( $hashes );
			$campaigns = $this->get_campaign_data( $hashes );

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
				$payloads = ( $reporter['enqueue'] )( $attributions, $click_ids, $campaigns, $context );
				foreach ( (array) $payloads as $payload ) {
					$this->queue->enqueue( (string) $reporter_id, $payload );
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
		$valid_hashes = $this->get_valid_hashes( array_keys( $entries ) );

		return array_intersect_key( $entries, array_flip( $valid_hashes ) );
	}

	/**
	 * Retrieves campaign data (UTM parameters) for an array of hashes.
	 *
	 * Performs a single JOIN query against wp_postmeta to fetch all UTM
	 * meta values per hash efficiently.
	 *
	 * @param string[] $hashes SHA-256 hashes.
	 *
	 * @return array<string, array> Hash => ['utm_source' => …, 'utm_medium' => …, …].
	 * @since 1.2.0
	 */
	private function get_campaign_data( array $hashes ): array {
		global $wpdb;

		if ( empty( $hashes ) ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT pm_hash.meta_value AS hash,
			        pm_utm.meta_key,
			        pm_utm.meta_value
			 FROM {$wpdb->postmeta} pm_hash
			 JOIN {$wpdb->posts} p ON p.ID = pm_hash.post_id
			 JOIN {$wpdb->postmeta} pm_utm ON pm_utm.post_id = p.ID
			    AND pm_utm.meta_key IN ('_utm_source', '_utm_medium', '_utm_campaign', '_utm_content', '_utm_term')
			 WHERE pm_hash.meta_key = '_hash'
			   AND pm_hash.meta_value IN ({$placeholders})
			   AND p.post_type = %s
			   AND p.post_status = 'publish'",
			...array_merge( $hashes, [ Post_Type::SLUG ] ),
		) );

		$result = [];
		foreach ( $rows as $row ) {
			// Strip the leading underscore from meta_key for the output.
			$key = ltrim( $row->meta_key, '_' );
			$result[ $row->hash ][ $key ] = $row->meta_value;
		}

		return $result;
	}

	/**
	 * Returns hashes that exist as published tracking URLs in the database.
	 *
	 * @param string[] $hashes SHA-256 hashes to check.
	 *
	 * @return string[] Subset of input hashes that have published tracking URL posts.
	 * @since 1.0.0
	 */
	private function get_valid_hashes( array $hashes ): array {
		global $wpdb;

		if ( empty( $hashes ) ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );

		// Build prepare arguments: meta_key placeholder is not needed since it's
		// a known constant, but we pass the hashes and post type through prepare.
		$args = array_merge( $hashes, [ Post_Type::SLUG ] );

		return $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT pm.meta_value
			 FROM {$wpdb->postmeta} pm
			 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_hash'
			   AND pm.meta_value IN ({$placeholders})
			   AND p.post_type = %s
			   AND p.post_status = 'publish'",
			...$args,
		) );
	}

}
