<?php
/**
 * Daily housekeeping and target page integrity checks.
 *
 * Handles the scheduled cron cleanup of orphaned click records, expired data,
 * and tracking URLs whose target pages no longer exist.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

/**
 * Manages daily cleanup cron job and target page integrity notices.
 *
 * Hooks into the scheduled `kntnt_ad_attr_daily_cleanup` event to remove
 * expired clicks, orphaned conversions, and deactivate tracking URLs whose
 * target pages no longer exist. Also warns when a target page is trashed.
 *
 * @since 1.0.0
 */
final class Cron {

	/**
	 * Click ID store for cleanup of expired click IDs.
	 *
	 * @var Click_ID_Store
	 * @since 1.2.0
	 */
	private readonly Click_ID_Store $click_id_store;

	/**
	 * Queue for cleanup of old completed and failed jobs.
	 *
	 * @var Queue
	 * @since 1.2.0
	 */
	private readonly Queue $queue;

	/**
	 * Initializes the cron handler with its dependencies.
	 *
	 * @param Click_ID_Store $click_id_store Platform-specific click ID storage.
	 * @param Queue          $queue          Async job queue.
	 *
	 * @since 1.2.0
	 */
	public function __construct( Click_ID_Store $click_id_store, Queue $queue ) {
		$this->click_id_store = $click_id_store;
		$this->queue          = $queue;
	}

	/**
	 * Registers WordPress hooks for cron and admin notices.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register(): void {
		add_action( 'kntnt_ad_attr_daily_cleanup', [ $this, 'run_daily_cleanup' ] );
		add_action( 'wp_trash_post', [ $this, 'warn_on_target_trash' ] );
		add_action( 'admin_notices', [ $this, 'display_admin_notices' ] );
	}

	/**
	 * Cron callback for daily housekeeping.
	 *
	 * Removes expired clicks, orphaned conversions/clicks, and drafts
	 * tracking URLs whose target pages no longer exist.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function run_daily_cleanup(): void {
		$this->cleanup_clicks();
		$this->cleanup_conversions();
		$this->delete_orphaned_clicks();
		$this->draft_orphaned_urls();

		// Clean up adapter infrastructure tables.
		$this->click_id_store->cleanup( 120 );
		$this->queue->cleanup( 30, 90 );
	}

	/**
	 * Deletes click records older than the retention period.
	 *
	 * Default retention is 365 days, filterable via
	 * `kntnt_ad_attr_click_retention_days`.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private function cleanup_clicks(): void {
		global $wpdb;

		/** @var int $days Number of days to retain click records. */
		$days = (int) apply_filters( 'kntnt_ad_attr_click_retention_days', 365 );

		$clicks_table = $wpdb->prefix . 'kntnt_ad_attr_clicks';
		$conv_table   = $wpdb->prefix . 'kntnt_ad_attr_conversions';
		$cutoff       = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// Delete conversions linked to expired clicks first.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare(
			"DELETE cv FROM {$conv_table} cv
			 INNER JOIN {$clicks_table} c ON c.id = cv.click_id
			 WHERE c.clicked_at < %s",
			$cutoff,
		) );

		// Delete the expired clicks.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$deleted = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$clicks_table} WHERE clicked_at < %s",
			$cutoff,
		) );

		if ( $deleted > 0 ) {
			error_log( sprintf( 'Kntnt Ad Attribution: Deleted %d expired click(s).', $deleted ) );
		}
	}

	/**
	 * Deletes conversion records whose click no longer exists.
	 *
	 * Defensive safety net: under normal operation, cleanup_clicks() and
	 * permanently_delete_url() already remove conversions before deleting
	 * their parent clicks. This method catches any orphans that slip
	 * through (e.g. due to interrupted transactions or manual DB edits).
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private function cleanup_conversions(): void {
		global $wpdb;

		$clicks_table = $wpdb->prefix . 'kntnt_ad_attr_clicks';
		$conv_table   = $wpdb->prefix . 'kntnt_ad_attr_conversions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$deleted = $wpdb->query(
			"DELETE cv FROM {$conv_table} cv
			 LEFT JOIN {$clicks_table} c ON c.id = cv.click_id
			 WHERE c.id IS NULL",
		);

		if ( $deleted > 0 ) {
			error_log( sprintf( 'Kntnt Ad Attribution: Deleted %d orphaned conversion(s).', $deleted ) );
		}
	}

	/**
	 * Deletes click records whose hash has no published CPT post.
	 *
	 * A click becomes orphaned when its corresponding tracking URL
	 * post is trashed or deleted.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private function delete_orphaned_clicks(): void {
		global $wpdb;

		$clicks_table = $wpdb->prefix . 'kntnt_ad_attr_clicks';
		$conv_table   = $wpdb->prefix . 'kntnt_ad_attr_conversions';

		// Delete conversions linked to orphaned clicks first.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"DELETE cv FROM {$conv_table} cv
				 INNER JOIN {$clicks_table} c ON c.id = cv.click_id
				 LEFT JOIN {$wpdb->postmeta} pm ON pm.meta_key = '_hash' AND pm.meta_value = c.hash
				 LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = %s
				 WHERE p.ID IS NULL",
				Post_Type::SLUG,
			),
		);

		// Delete orphaned click records.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE c FROM {$clicks_table} c
				 LEFT JOIN {$wpdb->postmeta} pm ON pm.meta_key = '_hash' AND pm.meta_value = c.hash
				 LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = %s
				 WHERE p.ID IS NULL",
				Post_Type::SLUG,
			),
		);

		if ( $deleted > 0 ) {
			error_log( sprintf( 'Kntnt Ad Attribution: Deleted %d orphaned click(s).', $deleted ) );
		}
	}

	/**
	 * Drafts tracking URLs whose target page is missing or unpublished.
	 *
	 * Stores affected URL titles in a transient so an admin notice can
	 * be displayed on the next page load.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function draft_orphaned_urls(): void {

		// Fetch all published tracking URLs that have a target post ID.
		$tracking_urls = get_posts( [
			'post_type'   => Post_Type::SLUG,
			'post_status' => 'publish',
			'meta_key'    => '_target_post_id',
			'numberposts' => -1,
		] );

		$orphaned = [];

		foreach ( $tracking_urls as $tracking_url ) {
			$target_id = (int) get_post_meta( $tracking_url->ID, '_target_post_id', true );

			if ( ! $target_id ) {
				continue;
			}

			$target = get_post( $target_id );

			// Draft the tracking URL if the target page is gone or not published.
			if ( ! $target || $target->post_status !== 'publish' ) {
				wp_update_post( [
					'ID'          => $tracking_url->ID,
					'post_status' => 'draft',
				] );
				$orphaned[] = $tracking_url->post_title;
			}
		}

		if ( $orphaned ) {
			set_transient( 'kntnt_ad_attr_orphaned_urls', $orphaned, DAY_IN_SECONDS );
		}
	}

	/**
	 * Warns administrators when a target page is trashed.
	 *
	 * Checks whether any published tracking URLs reference the trashed
	 * post and stores their titles in a short-lived transient.
	 *
	 * @param int $post_id ID of the post being trashed.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function warn_on_target_trash( int $post_id ): void {

		// Skip if the trashed post is our own CPT.
		if ( get_post_type( $post_id ) === Post_Type::SLUG ) {
			return;
		}

		// Find published tracking URLs pointing to this target.
		$affected = get_posts( [
			'post_type'   => Post_Type::SLUG,
			'post_status' => 'publish',
			'meta_key'    => '_target_post_id',
			'meta_value'  => $post_id,
			'numberposts' => -1,
		] );

		if ( $affected ) {
			$titles = wp_list_pluck( $affected, 'post_title' );
			set_transient( 'kntnt_ad_attr_trashed_target', $titles, 60 );
		}
	}

	/**
	 * Displays admin notices for orphaned and trashed target warnings.
	 *
	 * Only shown to users with the `kntnt_ad_attr` capability.
	 * Each transient is consumed (deleted) after display.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function display_admin_notices(): void {

		if ( ! current_user_can( 'kntnt_ad_attr' ) ) {
			return;
		}

		// Cron orphan notice.
		$orphaned = get_transient( 'kntnt_ad_attr_orphaned_urls' );

		if ( is_array( $orphaned ) && $orphaned ) {
			$count   = count( $orphaned );
			$message = sprintf(
				/* translators: %d: Number of tracking URLs deactivated. */
				_n(
					'%d tracking URL deactivated because the target page no longer exists.',
					'%d tracking URL(s) deactivated because target page(s) no longer exist.',
					$count,
					'kntnt-ad-attr',
				),
				$count,
			);

			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p><ul>',
				esc_html( $message ),
			);
			foreach ( $orphaned as $title ) {
				printf( '<li>%s</li>', esc_html( $title ) );
			}
			echo '</ul></div>';

			delete_transient( 'kntnt_ad_attr_orphaned_urls' );
		}

		// Trash warning notice.
		$trashed = get_transient( 'kntnt_ad_attr_trashed_target' );

		if ( is_array( $trashed ) && $trashed ) {
			$count   = count( $trashed );
			$message = sprintf(
				/* translators: %d: Number of tracking URLs affected. */
				_n(
					'Warning: %d tracking URL points to a page you just trashed.',
					'Warning: %d tracking URL(s) point to a page you just trashed.',
					$count,
					'kntnt-ad-attr',
				),
				$count,
			);

			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p><ul>',
				esc_html( $message ),
			);
			foreach ( $trashed as $title ) {
				printf( '<li>%s</li>', esc_html( $title ) );
			}
			echo '</ul></div>';

			delete_transient( 'kntnt_ad_attr_trashed_target' );
		}
	}

}
