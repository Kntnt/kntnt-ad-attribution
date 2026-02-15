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

}
