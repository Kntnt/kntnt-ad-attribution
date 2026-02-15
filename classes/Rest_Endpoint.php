<?php
/**
 * REST endpoints for admin page selector and client-side cookie setting.
 *
 * Registers the `search-posts` endpoint used by the select2 component
 * on the admin page, and the `set-cookie` endpoint used by the client-side
 * script to persist pending hashes after consent is granted.
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
 * Provides REST endpoints for the admin page selector and cookie setting.
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
	 * Cookie manager for reading/writing the _ad_clicks cookie.
	 *
	 * @var Cookie_Manager
	 * @since 1.0.0
	 */
	private readonly Cookie_Manager $cookie_manager;

	/**
	 * Consent resolver for verifying storage permission.
	 *
	 * @var Consent
	 * @since 1.0.0
	 */
	private readonly Consent $consent;

	/**
	 * Initializes the REST endpoint with its dependencies.
	 *
	 * @param Cookie_Manager $cookie_manager Cookie read/write operations.
	 * @param Consent        $consent        Consent state resolution.
	 *
	 * @since 1.0.0
	 */
	public function __construct( Cookie_Manager $cookie_manager, Consent $consent ) {
		$this->cookie_manager = $cookie_manager;
		$this->consent        = $consent;
	}

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
	 * Registers REST routes for search-posts and set-cookie.
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

		register_rest_route( self::NAMESPACE, '/set-cookie', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'set_cookie' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'hashes' => [
					'required' => true,
					'type'     => 'array',
					'items'    => [
						'type' => 'string',
					],
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

	/**
	 * Persists pending hashes as the _ad_clicks cookie after consent.
	 *
	 * Called by the client-side script when the visitor grants consent.
	 * Validates hashes against format and database, checks consent state,
	 * merges with any existing cookie entries, and writes the cookie.
	 *
	 * @param WP_REST_Request $request The REST request containing hashes array.
	 *
	 * @return WP_REST_Response Success status.
	 * @since 1.0.0
	 */
	public function set_cookie( WP_REST_Request $request ): WP_REST_Response {

		// Rate limit: allow at most 10 requests per minute per IP address.
		$ip            = $_SERVER['REMOTE_ADDR'] ?? '';
		$transient_key = 'kntnt_ad_attr_rl_' . md5( $ip );
		$request_count = (int) get_transient( $transient_key );

		if ( $request_count >= 10 ) {
			return new WP_REST_Response( [ 'success' => false ], 429 );
		}

		set_transient( $transient_key, $request_count + 1, MINUTE_IN_SECONDS );

		// Validate hash format — silently discard malformed entries.
		$hashes = array_filter(
			$request->get_param( 'hashes' ),
			fn( string $hash ) => $this->cookie_manager->validate_hash( $hash ),
		);

		// Verify hashes exist as published tracking URLs.
		$hashes = $this->get_valid_hashes( $hashes );

		if ( empty( $hashes ) ) {
			return new WP_REST_Response( [ 'success' => false ] );
		}

		// Consent must be granted at the time of cookie setting.
		if ( $this->consent->check() !== true ) {
			return new WP_REST_Response( [ 'success' => false ] );
		}

		// Merge new hashes into existing cookie entries.
		$entries = $this->cookie_manager->parse();
		foreach ( $hashes as $hash ) {
			$entries = $this->cookie_manager->add( $entries, $hash );
		}
		$this->cookie_manager->set_clicks_cookie( $entries );

		return new WP_REST_Response( [ 'success' => true ] );
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
		$args         = array_merge( $hashes, [ Post_Type::SLUG ] );

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
