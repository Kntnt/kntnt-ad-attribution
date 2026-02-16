<?php
/**
 * Click handling for tracking URL visits.
 *
 * Registers the rewrite rule for the tracking URL prefix, captures the hash
 * from the URL, and executes the 12-step click processing flow: validation,
 * lookup, bot filtering, statistics logging, consent-aware cookie setting,
 * and redirect.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

/**
 * Processes visits to `/ad/<hash>` tracking URLs.
 *
 * Dependencies are injected via the constructor rather than fetched from
 * the Plugin singleton, keeping the class testable and explicit about its
 * collaborators.
 *
 * @since 1.0.0
 */
final class Click_Handler {

	/**
	 * Cookie manager for reading/writing tracking cookies.
	 *
	 * @var Cookie_Manager
	 * @since 1.0.0
	 */
	private readonly Cookie_Manager $cookie_manager;

	/**
	 * Consent resolver for determining cookie storage permission.
	 *
	 * @var Consent
	 * @since 1.0.0
	 */
	private readonly Consent $consent;

	/**
	 * Bot detector for filtering automated requests.
	 *
	 * @var Bot_Detector
	 * @since 1.0.0
	 */
	private readonly Bot_Detector $bot_detector;

	/**
	 * Click ID store for capturing platform-specific click IDs.
	 *
	 * @var Click_ID_Store
	 * @since 1.2.0
	 */
	private readonly Click_ID_Store $click_id_store;

	/**
	 * Initializes the click handler with its dependencies.
	 *
	 * @param Cookie_Manager $cookie_manager  Cookie read/write operations.
	 * @param Consent        $consent         Consent state resolution.
	 * @param Bot_Detector   $bot_detector    Bot traffic detection.
	 * @param Click_ID_Store $click_id_store  Platform-specific click ID storage.
	 *
	 * @since 1.0.0
	 */
	public function __construct( Cookie_Manager $cookie_manager, Consent $consent, Bot_Detector $bot_detector, Click_ID_Store $click_id_store ) {
		$this->cookie_manager = $cookie_manager;
		$this->consent        = $consent;
		$this->bot_detector   = $bot_detector;
		$this->click_id_store = $click_id_store;
	}

	/**
	 * Registers WordPress hooks for URL rewriting and click handling.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'add_rewrite_rule' ] );
		add_filter( 'query_vars', [ $this, 'add_query_var' ] );
		add_action( 'template_redirect', [ $this, 'handle_click' ] );
	}

	/**
	 * Adds the rewrite rule that maps `/ad/<hash>` to a query var.
	 *
	 * The prefix is filterable via `kntnt_ad_attr_url_prefix`.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function add_rewrite_rule(): void {
		/** @var string $prefix The URL path prefix for tracking URLs. */
		$prefix = apply_filters( 'kntnt_ad_attr_url_prefix', 'ad' );

		add_rewrite_rule(
			'^' . preg_quote( $prefix, '/' ) . '/([a-f0-9]{64})/?$',
			'index.php?kntnt_ad_attr_hash=$matches[1]',
			'top',
		);
	}

	/**
	 * Registers the custom query variable so WordPress preserves it.
	 *
	 * @param string[] $vars Existing query variables.
	 *
	 * @return string[] Modified query variables.
	 * @since 1.0.0
	 */
	public function add_query_var( array $vars ): array {
		$vars[] = 'kntnt_ad_attr_hash';
		return $vars;
	}

	/**
	 * Processes a tracking URL visit.
	 *
	 * Implements the 12-step click flow: hash extraction → validation →
	 * database lookup → target resolution → loop guard → bot check →
	 * stats logging → consent check → cookie/transport → redirect.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_click(): void {
		global $wpdb;

		// Step 1: Extract hash from query var.
		$hash = get_query_var( 'kntnt_ad_attr_hash', '' );
		if ( $hash === '' ) {
			return;
		}

		// Step 2: Validate hash format.
		if ( ! $this->cookie_manager->validate_hash( $hash ) ) {
			$this->send_404();
		}

		// Steps 3–5: Look up the tracking URL post via its _hash meta.
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT p.ID, pm_target.meta_value AS target_post_id
			 FROM {$wpdb->postmeta} pm
			 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 LEFT JOIN {$wpdb->postmeta} pm_target ON pm_target.post_id = p.ID AND pm_target.meta_key = '_target_post_id'
			 WHERE pm.meta_key = '_hash'
			   AND pm.meta_value = %s
			   AND p.post_type = %s
			   AND p.post_status = 'publish'
			 LIMIT 1",
			$hash,
			Post_Type::SLUG,
		) );

		if ( ! $row ) {
			$this->send_404();
		}

		// Step 6: Resolve the target URL.
		$target_post_id = (int) $row->target_post_id;
		$target_url     = get_permalink( $target_post_id );

		if ( ! $target_url ) {
			$this->send_404();
		}

		// Forward incoming query parameters to the target URL. Target URL
		// parameters take precedence over incoming ones so that the site
		// owner's configuration cannot be overridden by the ad platform.
		$incoming_params = array_map( 'sanitize_text_field', $_GET );
		unset( $incoming_params['kntnt_ad_attr_hash'] );

		$target_query  = wp_parse_url( $target_url, PHP_URL_QUERY ) ?? '';
		$target_params = [];
		if ( $target_query !== '' ) {
			wp_parse_str( $target_query, $target_params );
		}

		$merged_params = array_merge( $incoming_params, $target_params );

		/**
		 * Filters the merged query parameters before building the redirect URL.
		 *
		 * @param array<string, string> $merged_params   Merged parameters (target wins on collision).
		 * @param array<string, string> $target_params   Parameters from the target URL.
		 * @param array<string, string> $incoming_params Sanitized parameters from the incoming request.
		 *
		 * @since 1.3.0
		 */
		$merged_params = apply_filters( 'kntnt_ad_attr_redirect_query_params', $merged_params, $target_params, $incoming_params );

		if ( $merged_params !== [] ) {
			$target_url = add_query_arg( $merged_params, strtok( $target_url, '?' ) );
		}

		// Step 8: Redirect loop guard — ensure target doesn't point back
		// to a tracking URL.
		/** @var string $prefix The URL path prefix for tracking URLs. */
		$prefix      = apply_filters( 'kntnt_ad_attr_url_prefix', 'ad' );
		$target_path = wp_parse_url( $target_url, PHP_URL_PATH ) ?? '';

		if ( str_starts_with( ltrim( $target_path, '/' ), $prefix . '/' ) ) {
			error_log( "kntnt-ad-attr: Redirect loop detected for hash {$hash}. Target URL starts with /{$prefix}/." );
			$this->send_404();
		}

		// Step 9: Bot check — bots get redirected without logging or cookies.
		if ( $this->bot_detector->is_bot() ) {
			$this->redirect( $target_url );
		}

		// Step 10: Log the click in the stats table.
		$table = $wpdb->prefix . 'kntnt_ad_attr_stats';
		$date  = gmdate( 'Y-m-d' );

		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$table} (hash, date, clicks)
			 VALUES (%s, %s, 1)
			 ON DUPLICATE KEY UPDATE clicks = clicks + 1",
			$hash,
			$date,
		) );

		// Capture platform-specific click IDs via registered capturers.
		// Returns early with no overhead if no capturers are registered.
		$capturers = apply_filters( 'kntnt_ad_attr_click_id_capturers', [] );
		foreach ( $capturers as $platform => $parameter ) {
			$value = sanitize_text_field( $_GET[ $parameter ] ?? '' );
			if ( $value !== '' && strlen( $value ) <= 255 ) {
				$this->click_id_store->store( $hash, (string) $platform, $value );
			}
		}

		// Notify companion plugins about the click. Fires for all non-bot
		// clicks regardless of consent state. Allows add-ons to capture
		// platform-specific parameters (e.g. gclid) from $_GET.
		do_action( 'kntnt_ad_attr_click', $hash, $target_url, [
			'post_id'      => (int) $row->ID,
			'utm_source'   => get_post_meta( (int) $row->ID, '_utm_source', true ),
			'utm_medium'   => get_post_meta( (int) $row->ID, '_utm_medium', true ),
			'utm_campaign' => get_post_meta( (int) $row->ID, '_utm_campaign', true ),
			'utm_content'  => get_post_meta( (int) $row->ID, '_utm_content', true ),
			'utm_term'     => get_post_meta( (int) $row->ID, '_utm_term', true ),
		] );

		// Step 11: Handle consent for cookie storage.
		$consent_state = $this->consent->check();

		match ( $consent_state ) {
			true  => $this->set_cookie( $hash ),
			false => null, // Consent denied — attribution lost for this visitor.
			null  => $this->handle_pending_consent( $hash, $target_url ),
		};

		// Step 12: Redirect to the target page.
		$this->redirect( $target_url );
	}

	/**
	 * Sets the _ad_clicks cookie with the current hash merged into
	 * any existing entries.
	 *
	 * @param string $hash The SHA-256 hash to add.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function set_cookie( string $hash ): void {
		$entries = $this->cookie_manager->parse();
		$entries = $this->cookie_manager->add( $entries, $hash );
		$this->cookie_manager->set_clicks_cookie( $entries );
	}

	/**
	 * Handles the transport mechanism when consent state is undetermined.
	 *
	 * The transport method is filterable: `cookie` sets a short-lived transport
	 * cookie, `fragment` appends the hash as a URL fragment.
	 *
	 * @param string $hash       The SHA-256 hash to transport.
	 * @param string &$target_url The target URL, modified in place for fragment transport.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function handle_pending_consent( string $hash, string &$target_url ): void {
		/** @var string $transport Transport method: 'cookie' or 'fragment'. */
		$transport = apply_filters( 'kntnt_ad_attr_pending_transport', 'cookie' );

		match ( $transport ) {
			'cookie'   => $this->cookie_manager->set_transport_cookie( $hash ),
			'fragment' => $target_url .= '#_aah=' . $hash,
			default    => $this->cookie_manager->set_transport_cookie( $hash ),
		};
	}

	/**
	 * Redirects the visitor to the target URL and terminates execution.
	 *
	 * The redirect method is filterable via `kntnt_ad_attr_redirect_method`:
	 * `302` (default) uses a standard HTTP redirect, `js` outputs a minimal
	 * HTML page with a JavaScript redirect.
	 *
	 * @param string $url The target URL to redirect to.
	 *
	 * @return never
	 * @since 1.0.0
	 */
	private function redirect( string $url ): never {
		/** @var string $method Redirect method: '302' or 'js'. */
		$method = apply_filters( 'kntnt_ad_attr_redirect_method', '302' );

		nocache_headers();

		if ( $method === 'js' ) {
			$this->js_redirect( $url );
		}

		wp_redirect( $url, 302 );
		exit;
	}

	/**
	 * Outputs a minimal HTML page that redirects via JavaScript.
	 *
	 * Used when the `kntnt_ad_attr_redirect_method` filter returns `js`.
	 * This allows client-side scripts (e.g. consent managers) to execute
	 * before the page navigates away.
	 *
	 * @param string $url The target URL to redirect to.
	 *
	 * @return never
	 * @since 1.0.0
	 */
	private function js_redirect( string $url ): never {
		$escaped_url = esc_js( $url );
		echo '<!DOCTYPE html><html><head><script>window.location.href="' . $escaped_url . '";</script></head><body></body></html>';
		exit;
	}

	/**
	 * Sends a 404 response and terminates execution.
	 *
	 * Uses the active theme's 404 template for a consistent visitor
	 * experience.
	 *
	 * @return never
	 * @since 1.0.0
	 */
	private function send_404(): never {
		status_header( 404 );
		nocache_headers();
		require get_404_template();
		exit;
	}

}
