<?php
/**
 * Detection of speculative (prefetch and prerender) requests.
 *
 * Browsers may load a link before the visitor follows it, announcing the
 * load with a `Sec-Purpose` header (or the older `Purpose` header). Such a
 * request is not a click, and the visitor may never make one.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.10.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

/**
 * Detects speculative requests via the `Sec-Purpose` and `Purpose` headers.
 *
 * Registers itself on the `kntnt_ad_attr_is_speculative` filter so
 * third-party code can extend or override detection, as with
 * `kntnt_ad_attr_is_bot`.
 *
 * @since 1.10.0
 */
final class Speculation_Detector {

	/**
	 * Registers the default detection on the `kntnt_ad_attr_is_speculative` filter.
	 *
	 * @return void
	 * @since 1.10.0
	 */
	public function register(): void {
		add_filter( 'kntnt_ad_attr_is_speculative', [ $this, 'detect' ] );
	}

	/**
	 * Returns whether the current request is a speculative load.
	 *
	 * Delegates to the `kntnt_ad_attr_is_speculative` filter so additional
	 * detectors can be registered, or the default overruled, by third-party
	 * code.
	 *
	 * @return bool True if the request is speculative.
	 * @since 1.10.0
	 */
	public function is_speculative(): bool {
		return (bool) apply_filters( 'kntnt_ad_attr_is_speculative', false );
	}

	/**
	 * Filter callback: detects speculative requests from their request headers.
	 *
	 * Short-circuits if a previous filter already flagged the request.
	 *
	 * @param bool $is_speculative Whether a previous filter already identified a speculative request.
	 *
	 * @return bool True if the request is speculative.
	 * @since 1.10.0
	 */
	public function detect( bool $is_speculative ): bool {
		if ( $is_speculative ) {
			return true;
		}

		// Sec-Purpose is a token list: `prefetch` alone, or with parameters
		// such as `prefetch;prerender` or `prefetch;anonymous-client-ip`.
		// The older Purpose header carries the bare token.
		$sec_purpose = strtolower( sanitize_text_field( wp_unslash( (string) ( $_SERVER['HTTP_SEC_PURPOSE'] ?? '' ) ) ) );
		$purpose     = strtolower( sanitize_text_field( wp_unslash( (string) ( $_SERVER['HTTP_PURPOSE'] ?? '' ) ) ) );

		return str_contains( $sec_purpose, 'prefetch' ) || $purpose === 'prefetch';
	}

}
