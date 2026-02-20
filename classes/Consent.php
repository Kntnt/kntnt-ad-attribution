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
	 * Returns whatever `kntnt_ad_attr_has_consent` provides. When no callback
	 * is registered the default `null` (undetermined) is returned, which
	 * activates the deferred consent transport mechanism.
	 *
	 * @return bool|null True = consent granted, false = denied, null = undetermined.
	 * @since 1.0.0
	 */
	public function check(): ?bool {
		return apply_filters( 'kntnt_ad_attr_has_consent', null );
	}

}
