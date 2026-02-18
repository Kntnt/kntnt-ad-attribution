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
	 * Searches published posts by ID, URL, slug, or title.
	 *
	 * Supports multiple lookup strategies in descending priority:
	 * exact post ID, full/partial URL resolution, slug LIKE matching,
	 * and title search. Excludes the plugin's own CPT from results.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 *
	 * @return WP_REST_Response Array of matching posts.
	 * @since 1.0.0
	 * @since 1.3.0 Rewritten with multi-strategy lookup (ID, URL, slug, title).
	 */
	public function search_posts( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$search = $request->get_param( 'search' );

		// Get all public post types except the plugin's own CPT.
		$post_types = array_values( array_diff(
			get_post_types( [ 'public' => true ] ),
			[ Post_Type::SLUG ],
		) );

		// Strip protocol and domain, then trim surrounding slashes.
		$cleaned  = preg_replace( '#^https?://[^/]+/?#', '', $search );
		$cleaned  = trim( $cleaned, '/' );
		$found    = [];
		$found_ids = [];

		// Exact post ID lookup.
		if ( ctype_digit( $cleaned ) ) {
			$post = get_post( (int) $cleaned );
			if ( $post && $post->post_status === 'publish' && in_array( $post->post_type, $post_types, true ) ) {
				$found[]     = $this->format_post( $post );
				$found_ids[] = $post->ID;
			}
		}

		// URL resolution for paths containing a slash.
		if ( str_contains( $cleaned, '/' ) ) {
			$post_id = url_to_postid( home_url( $cleaned ) );
			if ( $post_id && ! in_array( $post_id, $found_ids, true ) ) {
				$post = get_post( $post_id );
				if ( $post && $post->post_status === 'publish' && in_array( $post->post_type, $post_types, true ) ) {
					$found[]     = $this->format_post( $post );
					$found_ids[] = $post->ID;
				}
			}
		}

		// Slug LIKE search on each path segment.
		if ( $cleaned !== '' ) {
			$segments    = explode( '/', $cleaned );
			$like_clauses = [];
			$like_args    = [];

			foreach ( $segments as $segment ) {
				if ( $segment === '' ) {
					continue;
				}
				$like_clauses[] = 'p.post_name LIKE %s';
				$like_args[]    = '%' . $wpdb->esc_like( $segment ) . '%';
			}

			if ( $like_clauses ) {
				$type_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
				$where_like        = implode( ' OR ', $like_clauses );

				$exclude_clause = '';
				$query_args     = [ ...$like_args, ...$post_types ];

				if ( $found_ids ) {
					$id_placeholders = implode( ',', array_fill( 0, count( $found_ids ), '%d' ) );
					$exclude_clause  = "AND p.ID NOT IN ($id_placeholders)";
					$query_args      = [ ...$like_args, ...$post_types, ...$found_ids ];
				}

				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$slug_posts = $wpdb->get_results( $wpdb->prepare(
					"SELECT p.ID, p.post_title, p.post_type
					 FROM {$wpdb->posts} p
					 WHERE ($where_like)
					   AND p.post_status = 'publish'
					   AND p.post_type IN ($type_placeholders)
					   $exclude_clause
					 LIMIT 20",
					...$query_args,
				) );

				foreach ( $slug_posts as $row ) {
					$found[] = [
						'id'    => (int) $row->ID,
						'title' => $row->post_title,
						'type'  => $row->post_type,
					];
					$found_ids[] = (int) $row->ID;
				}
			}
		}

		// Title search via WP_Query as a final fallback.
		if ( $cleaned !== '' && count( $found ) < 20 ) {
			$query = new WP_Query( [
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				's'              => $cleaned,
				'post__not_in'   => $found_ids,
				'posts_per_page' => 20 - count( $found ),
				'no_found_rows'  => true,
			] );

			foreach ( $query->posts as $post ) {
				$found[] = $this->format_post( $post );
			}
		}

		return new WP_REST_Response( $found );
	}

	/**
	 * Formats a post object for the select2 component response.
	 *
	 * @param \WP_Post $post The post to format.
	 *
	 * @return array{id: int, title: string, type: string} Formatted post data.
	 * @since 1.0.0
	 */
	private function format_post( \WP_Post $post ): array {
		return [
			'id'    => $post->ID,
			'title' => $post->post_title,
			'type'  => $post->post_type,
		];
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
		$hashes = Post_Type::get_valid_hashes( $hashes );

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

}
