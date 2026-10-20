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
	 * Query parameter that makes a tracking-URL request a test click.
	 *
	 * A test click is processed like any other click, but its click row is
	 * flagged as a test, so it and its conversions stay out of the
	 * statistics and away from reporters that do not support tests.
	 *
	 * @var string
	 * @since 1.10.0
	 */
	public const TEST_PARAM = 'kntnt_ad_attr_test';

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
	 * Speculation detector for declining prefetch and prerender requests.
	 *
	 * @var Speculation_Detector
	 * @since 1.10.0
	 */
	private readonly Speculation_Detector $speculation_detector;

	/**
	 * Logger for the per-request diagnostic line.
	 *
	 * @var Logger|null
	 * @since 1.10.0
	 */
	private readonly ?Logger $logger;

	/**
	 * Initializes the click handler with its dependencies.
	 *
	 * @param Cookie_Manager       $cookie_manager       Cookie read/write operations.
	 * @param Consent              $consent              Consent state resolution.
	 * @param Bot_Detector         $bot_detector         Bot traffic detection.
	 * @param Click_ID_Store       $click_id_store       Platform-specific click ID storage.
	 * @param Speculation_Detector $speculation_detector Speculative request detection.
	 * @param Logger|null          $logger               Optional diagnostic logger.
	 *
	 * @since 1.0.0
	 * @since 1.10.0 Takes a Speculation_Detector and an optional Logger.
	 */
	public function __construct( Cookie_Manager $cookie_manager, Consent $consent, Bot_Detector $bot_detector, Click_ID_Store $click_id_store, Speculation_Detector $speculation_detector, ?Logger $logger = null ) {
		$this->cookie_manager       = $cookie_manager;
		$this->consent              = $consent;
		$this->bot_detector         = $bot_detector;
		$this->click_id_store       = $click_id_store;
		$this->speculation_detector = $speculation_detector;
		$this->logger               = $logger;
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
		$prefix = Plugin::get_url_prefix();

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
	 * Implements the click flow: hash extraction → validation → database
	 * lookup → target resolution → loop guard → bot check → speculative
	 * check → internal-traffic check → consent check → repeat check →
	 * deduplication → click recording → cookie/transport → redirect. Each
	 * check that ends the request records no click; see docs/click-handling.md,
	 * "Counting rules". A test click skips the internal-traffic, repeat and
	 * deduplication checks and is recorded with its test flag set.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_click(): void {

		// Step 1: Extract hash from query var.
		$hash = get_query_var( 'kntnt_ad_attr_hash', '' );
		if ( $hash === '' ) {
			return;
		}

		// Step 2: Validate hash format. The rewrite rule regex already constrains
		// the hash to 64 hex chars, but we re-validate here as defense-in-depth
		// in case the rewrite rule is bypassed or modified by another plugin.
		if ( ! $this->cookie_manager->validate_hash( $hash ) ) {
			$this->send_404();
		}

		// Steps 3–5: Look up the tracking URL post via its _hash meta.
		$row = $this->find_tracking_url( $hash );

		if ( ! $row ) {
			$this->send_404();
		}

		// Step 6: Resolve the target URL.
		$target_post_id = (int) $row->target_post_id;
		$target_url     = get_permalink( $target_post_id );

		if ( ! $target_url ) {
			$this->send_404();
		}

		$is_test_click = sanitize_text_field( $_GET[ self::TEST_PARAM ] ?? '' ) === '1';
		$target_url    = $this->forward_query_params( $target_url );

		// Step 8: Redirect loop guard — ensure target doesn't point back
		// to a tracking URL.
		$prefix      = Plugin::get_url_prefix();
		$target_path = wp_parse_url( $target_url, PHP_URL_PATH ) ?? '';

		if ( str_starts_with( ltrim( $target_path, '/' ), $prefix . '/' ) ) {
			error_log( "kntnt-ad-attr: Redirect loop detected for hash {$hash}. Target URL starts with /{$prefix}/." );
			$this->send_404();
		}

		// Step 9: Bot check — bots get redirected without a click row or cookies.
		if ( $this->bot_detector->is_bot() ) {
			$this->log_request( Request_Outcome::Bot, $hash, $is_test_click );
			$this->redirect( $target_url );
		}

		// Speculative check — a prefetch or prerender is not a click, and the
		// visitor may never make one. Declining it with a non-2xx response
		// makes the browser discard it, so a real click that follows reaches
		// the server as a fresh request and is counted once.
		if ( $this->speculation_detector->is_speculative() ) {
			$this->log_request( Request_Outcome::Speculative, $hash, $is_test_click );
			$this->decline_speculative();
		}

		// Internal traffic (e.g. logged-in staff) is handled like a bot, so
		// checking the site's own ads leaves no trace. A test click is the
		// exception: it is how staff test the setup end to end.
		if ( ! $is_test_click && Internal_Detector::is_internal() ) {
			$this->log_request( Request_Outcome::Internal, $hash, $is_test_click );
			$this->redirect( $target_url );
		}

		// Capture the current time once for consistent timestamps across
		// click recording and cookie storage.
		$now     = time();
		$post_id = (int) $row->ID;

		// Step 10: Check consent early — needed for click deduplication.
		$consent_state = $this->consent->check();

		// Platform click IDs are read before anything is recorded, because a
		// value already recorded for this tracking URL makes the request a
		// repeat rather than a click.
		$click_ids = $this->capture_click_ids();

		// Repeat check — a reload, back/forward navigation, bookmark or shared
		// link carries a click ID that an earlier click of this tracking URL
		// already recorded. It is not a new click. A test click is never a
		// repeat, for the same reason it is never a duplicate (below).
		$original_click = $is_test_click ? null : $this->find_original_click( $hash, $click_ids );
		if ( $original_click !== null ) {
			match ( $consent_state ) {
				true  => $this->set_cookie( $hash, $this->repeat_entry_timestamp( $hash, $original_click['clicked_at'] ) ),
				false => null,
				null  => $this->handle_pending_consent( $hash, $original_click['clicked_at'], $target_url ),
			};
			$this->log_request( Request_Outcome::Repeat, $hash, $is_test_click );
			$this->redirect( $target_url );
		}

		// Step 10b: Click deduplication — skip DB insert when the same hash
		// was already clicked within the dedup window and consent allows
		// reading the cookie. Without consent we cannot read the cookie,
		// so dedup is only possible when consent is granted. A test click is
		// never a duplicate: reusing an earlier ordinary click would leave no
		// test row, and a conversion would then be reported as real.
		$dedup_seconds = (int) apply_filters( 'kntnt_ad_attr_dedup_seconds', 0 );

		if ( $consent_state === true && $dedup_seconds > 0 && ! $is_test_click ) {
			$duplicate_of = $this->duplicated_click( $hash, $now, $dedup_seconds );
			if ( $duplicate_of !== null ) {

				// Conversions link to a click row by an exact (hash, clicked_at)
				// match with the cookie timestamp, so the entry must keep the
				// original click's time. Re-sending it only refreshes the expiry,
				// and the window stays anchored to the original click.
				$this->set_cookie( $hash, $duplicate_of );
				$this->log_request( Request_Outcome::Deduplicated, $hash, $is_test_click );
				$this->redirect( $target_url );
			}
		}

		// Step 11: Record the click.
		$click_row_id = $this->insert_click( $hash, $now, $is_test_click );

		// Every cookie entry, pending click and announced click must point at
		// a click row, because conversions are linked by an exact match on
		// it. Without a row there is nothing to point at, so the visitor is
		// only redirected and the failure logged.
		if ( $click_row_id === null ) {
			$this->log_request( Request_Outcome::Failed, $hash, $is_test_click );
			$this->redirect( $target_url );
		}

		// Store the captured platform click IDs. Each ID belongs to the click
		// row just inserted, so a conversion linked to this click reports
		// this click's IDs and no other visitor's.
		foreach ( $click_ids as $platform => $value ) {
			$this->click_id_store->store( $click_row_id, $platform, $value );
		}

		$this->fill_campaign_meta( $post_id );

		// Notify companion plugins about the click. Fires for every recorded
		// click regardless of consent state. Allows add-ons to capture
		// platform-specific parameters (e.g. gclid) from $_GET.
		do_action( 'kntnt_ad_attr_click', $hash, $target_url, [
			'post_id'     => $post_id,
			'utm_source'  => get_post_meta( $post_id, '_utm_source', true ),
			'utm_medium'  => get_post_meta( $post_id, '_utm_medium', true ),
			'utm_campaign' => get_post_meta( $post_id, '_utm_campaign', true ),
		] );

		// Step 12: Handle consent for cookie storage.
		match ( $consent_state ) {
			true  => $this->set_cookie( $hash, $now ),
			false => null, // Consent denied — attribution lost for this visitor.
			null  => $this->handle_pending_consent( $hash, $now, $target_url ),
		};

		// Step 13: Redirect to the target page.
		$this->log_request( Request_Outcome::Recorded, $hash, $is_test_click );
		$this->redirect( $target_url );
	}

	/**
	 * Looks up the published tracking URL with the given hash.
	 *
	 * @param string $hash The tracking URL hash.
	 *
	 * @return object|null Row with the post `ID` and its `target_post_id`, or null if there is none.
	 * @since 1.10.0
	 */
	private function find_tracking_url( string $hash ): ?object {
		global $wpdb;

		return $wpdb->get_row( $wpdb->prepare(
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
		) ) ?: null;
	}

	/**
	 * Forwards the request's query parameters to the target URL.
	 *
	 * Target URL parameters take precedence over incoming ones so that the
	 * site owner's configuration cannot be overridden by the ad platform.
	 * The test parameter is the plugin's own and never reaches the landing
	 * page.
	 *
	 * @param string $target_url The tracking URL's target.
	 *
	 * @return string The target URL with the merged query parameters.
	 * @since 1.10.0
	 */
	private function forward_query_params( string $target_url ): string {
		$incoming_params = array_map( 'sanitize_text_field', $_GET );
		unset( $incoming_params['kntnt_ad_attr_hash'], $incoming_params[ self::TEST_PARAM ] );

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

		return $target_url;
	}

	/**
	 * Returns the click a click inside the deduplication window duplicates.
	 *
	 * A click duplicates the visitor's cookie entry for the hash if the
	 * entry is inside the window and a click row exists for it. An entry
	 * without a matching row (written by an older version) is not
	 * duplicated: the click is recorded as new and the entry repointed at
	 * it, which repairs the cookie.
	 *
	 * @param string $hash          The tracking URL hash.
	 * @param int    $now           Unix timestamp of the request.
	 * @param int    $dedup_seconds Length of the deduplication window.
	 *
	 * @return int|null Unix timestamp of the duplicated click, or null if the click is not a duplicate.
	 * @since 1.10.0
	 */
	private function duplicated_click( string $hash, int $now, int $dedup_seconds ): ?int {
		$existing = $this->cookie_manager->parse()[ $hash ] ?? null;

		if ( $existing === null || ( $now - $existing ) >= $dedup_seconds ) {
			return null;
		}

		return $this->click_row_exists( $hash, $existing ) ? $existing : null;
	}

	/**
	 * Inserts the click row, with the per-click fields from the request.
	 *
	 * Content, Term, Id and Group come from the UTM parameters, or from the
	 * MTM parameters when a UTM parameter is empty, truncated to the column
	 * width.
	 *
	 * @param string $hash          The tracking URL hash.
	 * @param int    $now           Unix timestamp of the click.
	 * @param bool   $is_test_click Whether the click is a test click.
	 *
	 * @return int|null ID of the inserted click row, or null if the insert failed.
	 * @since 1.10.0
	 */
	private function insert_click( string $hash, int $now, bool $is_test_click ): ?int {
		global $wpdb;

		$click_content = sanitize_text_field( $_GET['utm_content'] ?? '' )
			?: sanitize_text_field( $_GET['mtm_content'] ?? '' );
		$click_term    = sanitize_text_field( $_GET['utm_term'] ?? '' )
			?: sanitize_text_field( $_GET['mtm_keyword'] ?? '' );
		$click_id_val  = sanitize_text_field( $_GET['utm_id'] ?? '' )
			?: sanitize_text_field( $_GET['mtm_cid'] ?? '' );
		$click_group   = sanitize_text_field( $_GET['utm_source_platform'] ?? '' )
			?: sanitize_text_field( $_GET['mtm_group'] ?? '' );

		$inserted = $wpdb->insert( Table::Clicks->prefixed(), [
			'hash'                => $hash,
			'clicked_at'          => gmdate( 'Y-m-d H:i:s', $now ),
			'utm_content'         => $click_content !== '' ? mb_substr( $click_content, 0, 255 ) : null,
			'utm_term'            => $click_term !== '' ? mb_substr( $click_term, 0, 255 ) : null,
			'utm_id'              => $click_id_val !== '' ? mb_substr( $click_id_val, 0, 255 ) : null,
			'utm_source_platform' => $click_group !== '' ? mb_substr( $click_group, 0, 255 ) : null,
			'is_test'             => $is_test_click ? 1 : 0,
		] );

		return $inserted ? (int) $wpdb->insert_id : null;
	}

	/**
	 * Fills the tracking URL's empty Source/Medium/Campaign from the request.
	 *
	 * These are fixed per tracking URL and stored in postmeta. Kept for
	 * backwards compatibility with pre-v1.5.0 URLs that may lack them. A
	 * UTM parameter wins over its MTM counterpart.
	 *
	 * @param int $post_id The tracking URL post ID.
	 *
	 * @return void
	 * @since 1.10.0
	 */
	private function fill_campaign_meta( int $post_id ): void {
		$param_map = [
			'_utm_source'   => [ 'utm_source', 'mtm_source' ],
			'_utm_medium'   => [ 'utm_medium', 'mtm_medium' ],
			'_utm_campaign' => [ 'utm_campaign', 'mtm_campaign' ],
		];

		foreach ( $param_map as $meta_key => [ $utm_param, $mtm_param ] ) {
			$stored = get_post_meta( $post_id, $meta_key, true );
			if ( $stored !== '' && $stored !== false ) {
				continue;
			}

			$value = sanitize_text_field( $_GET[ $utm_param ] ?? '' );
			if ( $value === '' ) {
				$value = sanitize_text_field( $_GET[ $mtm_param ] ?? '' );
			}

			if ( $value !== '' ) {
				update_post_meta( $post_id, $meta_key, mb_substr( $value, 0, 255 ) );
			}
		}
	}

	/**
	 * Sets the _ad_clicks cookie with the current hash merged into
	 * any existing entries.
	 *
	 * @param string $hash      The SHA-256 hash to add.
	 * @param int    $timestamp Unix timestamp to store in the cookie entry.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function set_cookie( string $hash, int $timestamp ): void {
		$entries = $this->cookie_manager->parse();
		$entries = $this->cookie_manager->add( $entries, $hash, $timestamp );
		$this->cookie_manager->set_clicks_cookie( $entries );
	}

	/**
	 * Writes the diagnostic line for a tracking URL request.
	 *
	 * Called exactly once per request that reaches a decision, just before
	 * the response ends it, so the log shows how every request was counted.
	 * The line holds the outcome, the hash prefix and the raw speculation
	 * and User-Agent headers, which is what is needed to measure what
	 * prefetches, previews and scanners still get counted. It deliberately
	 * holds no IP address. Another per-request fact belongs in the same
	 * line as one more field, never in a second line. Logger writes nothing
	 * unless logging is enabled.
	 *
	 * @param Request_Outcome $outcome How the request was counted. Failed is logged as an error.
	 * @param string          $hash    The tracking URL hash.
	 * @param bool            $is_test Whether the request is a test click (`kntnt_ad_attr_test=1`).
	 *
	 * @return void
	 * @since 1.10.0
	 */
	private function log_request( Request_Outcome $outcome, string $hash, bool $is_test ): void {
		$fields = [
			'outcome'     => $outcome->value,
			'hash'        => substr( $hash, 0, Logger::HASH_PREFIX_LENGTH ),
			'test'        => $is_test ? '1' : '0',
			'sec_purpose' => self::quote_header( 'HTTP_SEC_PURPOSE' ),
			'purpose'     => self::quote_header( 'HTTP_PURPOSE' ),
			'user_agent'  => self::quote_header( 'HTTP_USER_AGENT' ),
		];

		$pairs = array_map( fn( string $key, string $value ) => "{$key}={$value}", array_keys( $fields ), $fields );

		$message = 'Tracking URL request: ' . implode( ' ', $pairs );

		if ( $outcome === Request_Outcome::Failed ) {
			$this->logger?->error( 'CORE', $message );
		} else {
			$this->logger?->info( 'CORE', $message );
		}
	}

	/**
	 * Returns a request header from $_SERVER as a double-quoted log value.
	 *
	 * Sanitizing strips line breaks, so a crafted header cannot forge a log
	 * line; escaping keeps quotes inside the value unambiguous.
	 *
	 * @param string $key The $_SERVER key, e.g. 'HTTP_USER_AGENT'.
	 *
	 * @return string The value in double quotes, `""` if the header is absent.
	 * @since 1.10.0
	 */
	private static function quote_header( string $key ): string {
		$value = sanitize_text_field( wp_unslash( (string) ( $_SERVER[ $key ] ?? '' ) ) );
		return '"' . addcslashes( $value, '"\\' ) . '"';
	}

	/**
	 * Reads the platform click IDs carried by the request.
	 *
	 * Asks the registered capturers (`kntnt_ad_attr_click_id_capturers`)
	 * which query parameter holds each platform's click ID. Empty values and
	 * values longer than the column (255 characters) are ignored.
	 *
	 * @return array<string, string> Platform => click ID value.
	 * @since 1.10.0
	 */
	private function capture_click_ids(): array {
		$click_ids = [];

		$capturers = apply_filters( 'kntnt_ad_attr_click_id_capturers', [] );
		foreach ( $capturers as $platform => $parameter ) {
			$value = sanitize_text_field( $_GET[ $parameter ] ?? '' );
			if ( $value !== '' && strlen( $value ) <= 255 ) {
				$click_ids[ (string) $platform ] = $value;
			}
		}

		return $click_ids;
	}

	/**
	 * Finds the click a repeat request resolves to.
	 *
	 * A request is a repeat if any of its click IDs was already recorded on
	 * a click of the same tracking URL. The original click is the earliest
	 * click row carrying any of those values. Without click IDs, for example
	 * when no capturers are registered, there is nothing to compare.
	 *
	 * @param string                $hash      The tracking URL hash.
	 * @param array<string, string> $click_ids Platform => click ID value carried by the request.
	 *
	 * @return array{click_id: int, clicked_at: int}|null The original click, or null if the request is not a repeat.
	 * @since 1.10.0
	 */
	private function find_original_click( string $hash, array $click_ids ): ?array {
		$original = null;

		foreach ( $click_ids as $platform => $value ) {
			$match = $this->click_id_store->find_original_click( $hash, $platform, $value );
			if ( $match !== null && ( $original === null || [ $match['clicked_at'], $match['click_id'] ] < [ $original['clicked_at'], $original['click_id'] ] ) ) {
				$original = $match;
			}
		}

		return $original;
	}

	/**
	 * Returns the click time a repeat leaves in the visitor's cookie entry.
	 *
	 * The entry ends up at the more recent of the existing entry's click and
	 * the original click, so a repeat never moves it backwards in time. The
	 * existing entry only counts if its click row exists, which keeps every
	 * entry the plugin writes pointing at a click row.
	 *
	 * @param string $hash                The tracking URL hash.
	 * @param int    $original_clicked_at Unix timestamp of the original click.
	 *
	 * @return int Unix timestamp for the cookie entry.
	 * @since 1.10.0
	 */
	private function repeat_entry_timestamp( string $hash, int $original_clicked_at ): int {
		$existing = $this->cookie_manager->parse()[ $hash ] ?? null;

		if ( $existing !== null && $existing > $original_clicked_at && $this->click_row_exists( $hash, $existing ) ) {
			return $existing;
		}

		return $original_clicked_at;
	}

	/**
	 * Checks whether a click row exists for a cookie entry.
	 *
	 * Uses the conversion lookup's exact match (Click_Lookup), so an entry
	 * this returns true for is one a conversion can be attributed to.
	 *
	 * @param string $hash      The tracking URL hash.
	 * @param int    $timestamp Unix timestamp of the cookie entry.
	 *
	 * @return bool True if a matching click row exists.
	 * @since 1.10.0
	 */
	private function click_row_exists( string $hash, int $timestamp ): bool {
		return isset( Click_Lookup::find( [ [ $hash, $timestamp ] ] )[ $hash ][ $timestamp ] );
	}

	/**
	 * Handles the transport mechanism when consent state is undetermined.
	 *
	 * Hands the landing page the click as a `hash:timestamp` pair, where the
	 * timestamp is the `clicked_at` of the click row the visitor's cookie
	 * entry should point at once consent is granted. The transport method is
	 * filterable: `cookie` sets a short-lived transport cookie, `fragment`
	 * appends the pair as a URL fragment.
	 *
	 * @param string $hash        The SHA-256 hash to transport.
	 * @param int    $timestamp   Unix timestamp of the click row to refer to.
	 * @param string &$target_url The target URL, modified in place for fragment transport.
	 *
	 * @return void
	 * @since 1.0.0
	 * @since 1.10.0 Transports the click timestamp along with the hash.
	 */
	private function handle_pending_consent( string $hash, int $timestamp, string &$target_url ): void {
		/** @var string $transport Transport method: 'cookie' or 'fragment'. */
		$transport = apply_filters( 'kntnt_ad_attr_pending_transport', 'cookie' );

		match ( $transport ) {
			'fragment' => $target_url .= '#_aah=' . Cookie_Manager::format_entry( $hash, $timestamp ),
			default    => $this->cookie_manager->set_transport_cookie( $hash, $timestamp ),
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
	 * Declines a speculative request and terminates execution.
	 *
	 * Chrome treats a prefetch or prerender that ends in a non-2xx status as
	 * failed and does not use it, so the visitor's real navigation, if any,
	 * makes a fresh request. A redirect would be followed and cached as the
	 * prefetched response instead, so the real click would never reach the
	 * server. The response must not be cached for the same reason.
	 *
	 * @return never
	 * @since 1.10.0
	 */
	private function decline_speculative(): never {
		status_header( 503 );
		nocache_headers();
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
