<?php
/**
 * REST endpoint for the admin page selector.
 *
 * Registers the `search-posts` endpoint used by the select2 component
 * on the admin page to find target pages for tracking URLs.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

use WP_REST_Request;
use WP_REST_Response;
use WP_Query;

/**
 * Provides a REST endpoint for searching published posts.
 *
 * @since 1.0.0
 */
final class Rest_Endpoint {

	/**
	 * REST namespace for plugin endpoints.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private const NAMESPACE = 'kntnt-ad-attribution/v1';

	/**
	 * Registers the REST API initialization hook.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Registers the search-posts REST route.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/search-posts', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'search_posts' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => [
				'search' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );
	}

	/**
	 * Checks if the current user has the plugin capability.
	 *
	 * @return bool True if authorized.
	 * @since 1.0.0
	 */
	public function check_permission(): bool {
		return current_user_can( 'kntnt_ad_attr' );
	}

	/**
	 * Searches published posts across all public post types.
	 *
	 * Excludes the plugin's own CPT from results. Returns an array of
	 * objects with id, title, and type for the select2 component.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 *
	 * @return WP_REST_Response Array of matching posts.
	 * @since 1.0.0
	 */
	public function search_posts( WP_REST_Request $request ): WP_REST_Response {
		$search = $request->get_param( 'search' );

		// Get all public post types except the plugin's own CPT.
		$post_types = array_values( array_diff(
			get_post_types( [ 'public' => true ] ),
			[ Post_Type::SLUG ],
		) );

		$query = new WP_Query( [
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			's'              => $search,
			'posts_per_page' => 20,
			'no_found_rows'  => true,
		] );

		$results = array_map( fn( $post ) => [
			'id'    => $post->ID,
			'title' => $post->post_title,
			'type'  => $post->post_type,
		], $query->posts );

		return new WP_REST_Response( $results );
	}

}
