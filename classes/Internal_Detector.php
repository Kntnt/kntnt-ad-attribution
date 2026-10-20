<?php
/**
 * Internal traffic detection.
 *
 * Keeps the site's own staff out of the statistics: requests from a user
 * who can manage ad attribution are internal, and internal requests record
 * no clicks and no conversions unless they belong to a test click.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.10.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

/**
 * Detects internal traffic: requests from users with the `kntnt_ad_attr`
 * capability.
 *
 * Registers itself on the `kntnt_ad_attr_is_internal` filter so sites can
 * extend detection (e.g. by IP) or switch it off with
 * `add_filter( 'kntnt_ad_attr_is_internal', '__return_false', PHP_INT_MAX )`.
 *
 * @since 1.10.0
 */
final class Internal_Detector {

	/**
	 * Registers the default detection callback.
	 *
	 * @return void
	 * @since 1.10.0
	 */
	public function register(): void {
		add_filter( 'kntnt_ad_attr_is_internal', [ $this, 'detect' ] );
	}

	/**
	 * Returns whether the current request is internal traffic.
	 *
	 * Static so the click and conversion handlers can ask without another
	 * constructor dependency; the filter, not this class, is the seam.
	 *
	 * @return bool True if the request is internal.
	 * @since 1.10.0
	 */
	public static function is_internal(): bool {
		return (bool) apply_filters( 'kntnt_ad_attr_is_internal', false );
	}

	/**
	 * Filter callback: detects a logged-in user with the `kntnt_ad_attr`
	 * capability.
	 *
	 * The current user alone is not enough. WordPress resets it to 0 in a
	 * REST request without a nonce, which is how some form plugins submit,
	 * so the logged-in cookie is validated as well.
	 *
	 * @param bool $is_internal Whether a previous filter already identified internal traffic.
	 *
	 * @return bool True if the request is internal.
	 * @since 1.10.0
	 */
	public function detect( bool $is_internal ): bool {
		if ( $is_internal || current_user_can( 'kntnt_ad_attr' ) ) {
			return true;
		}

		$user_id = wp_validate_auth_cookie( '', 'logged_in' );

		return $user_id !== false && user_can( $user_id, 'kntnt_ad_attr' );
	}

}
