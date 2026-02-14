<?php
/**
 * Consent state resolution for cookie storage.
 *
 * Provides a three-state consent check (granted / denied / undetermined)
 * that integrates with any cookie consent plugin via filter hooks.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

/**
 * Resolves the current visitor's consent state.
 *
 * The three possible states allow the click handler to decide between
 * setting a persistent cookie (granted), skipping it (denied), or using
 * a transport mechanism for deferred handling (undetermined).
 *
 * @since 1.0.0
 */
final class Consent {

	/**
	 * Checks the visitor's consent state.
	 *
	 * Resolution order:
	 * 1. If a callback is registered on `kntnt_ad_attr_has_consent`,
	 *    return whatever it provides (true, false, or null).
	 * 2. Otherwise fall back to `kntnt_ad_attr_default_consent` (default true).
	 *
	 * @return bool|null True = consent granted, false = denied, null = undetermined.
	 * @since 1.0.0
	 */
	public function check(): ?bool {
		if ( has_filter( 'kntnt_ad_attr_has_consent' ) ) {
			return apply_filters( 'kntnt_ad_attr_has_consent', null );
		}

		return apply_filters( 'kntnt_ad_attr_default_consent', true );
	}

}
