<?php
/**
 * Custom post type registration for tracking URLs.
 *
 * Registers the kntnt_ad_attr_url post type used to store tracking URL
 * definitions with their hash, target page, and UTM parameters.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

/**
 * Registers and manages the tracking URL custom post type.
 *
 * The CPT is hidden from the standard WordPress admin UI. Management is
 * handled via a dedicated admin page with WP_List_Table (see Admin_Page).
 * REST API access is enabled for export/import compatibility.
 *
 * @since 1.0.0
 */
final class Post_Type {

	/**
	 * Post type identifier.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public const SLUG = 'kntnt_ad_attr_url';

	/**
	 * Registers the custom post type with WordPress.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register(): void {
		register_post_type( self::SLUG, [
			'label'           => 'Tracking URLs',
			'public'          => false,
			'show_ui'         => false,
			'show_in_menu'    => false,
			'show_in_rest'    => true,
			'rest_base'       => 'kntnt-ad-attr-urls',
			'supports'        => [ 'title' ],
			'capability_type' => 'post',
			'map_meta_cap'    => true,
		] );

		// Ensure tracking URLs restore to 'publish' when untrashed.
		// Other plugins (e.g. ACF) may override the default restored
		// status for all post types via this same filter.
		add_filter( 'wp_untrash_post_status', [ $this, 'untrash_status' ], 20, 2 );
	}

	/**
	 * Forces the untrash status to 'publish' for tracking URLs.
	 *
	 * @param string $new_status The status the post will be assigned.
	 * @param int    $post_id    The post being untrashed.
	 *
	 * @return string Corrected status.
	 * @since 1.0.0
	 */
	public function untrash_status( string $new_status, int $post_id ): string {
		if ( get_post_type( $post_id ) === self::SLUG ) {
			return 'publish';
		}
		return $new_status;
	}

	/**
	 * Returns hashes that exist as published tracking URLs in the database.
	 *
	 * Shared by Conversion_Handler (cookie validation) and Rest_Endpoint
	 * (set-cookie validation) to avoid duplicating the same query.
	 *
	 * @param string[] $hashes SHA-256 hashes to check.
	 *
	 * @return string[] Subset of input hashes that have published tracking URL posts.
	 * @since 1.5.1
	 */
	public static function get_valid_hashes( array $hashes ): array {
		global $wpdb;

		if ( empty( $hashes ) ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		$args         = array_merge( $hashes, [ self::SLUG ] );

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

	/**
	 * Retrieves distinct meta values for a given key from published tracking URLs.
	 *
	 * Used by Campaign_List_Table for filter dropdowns.
	 *
	 * @param string $meta_key The meta key to query (e.g. '_utm_source').
	 *
	 * @return string[] Sorted list of distinct non-empty values.
	 * @since 1.5.1
	 */
	public static function get_distinct_meta_values( string $meta_key ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$values = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT pm.meta_value
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = %s
			   AND p.post_type = %s
			   AND p.post_status = 'publish'
			   AND pm.meta_value != ''
			 ORDER BY pm.meta_value ASC",
			$meta_key,
			self::SLUG,
		) );

		return $values ?: [];
	}

}
