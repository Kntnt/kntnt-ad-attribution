<?php
/**
 * Predefined UTM source and medium options.
 *
 * Provides filterable default values for the UTM dropdowns in the
 * tracking URL form. Sources map to a default medium that is
 * auto-filled on the client side when the medium field is empty.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

/**
 * Static helper for UTM dropdown options.
 *
 * @since 1.0.0
 */
final class Utm_Options {

	/**
	 * Returns predefined UTM source and medium options.
	 *
	 * The 'sources' key maps each source name to its default medium.
	 * The 'mediums' key lists all available medium values.
	 *
	 * Filterable via `kntnt_ad_attr_utm_options`.
	 *
	 * @return array{sources: array<string, string>, mediums: string[]}
	 * @since 1.0.0
	 */
	public static function get_options(): array {
		$options = [
			'sources' => [
				'bing'       => 'cpc',
				'event'      => 'offline',
				'google'     => 'cpc',
				'linkedin'   => 'paid-social',
				'meta'       => 'paid-social',
				'newsletter' => 'email',
				'pinterest'  => 'paid-social',
				'qr-code'    => 'print',
				'snapchat'   => 'paid-social',
				'tiktok'     => 'paid-social',
				'x'          => 'paid-social',
				'youtube'    => 'video',
			],
			'mediums' => [
				'affiliate',
				'cpc',
				'display',
				'email',
				'offline',
				'organic',
				'paid-social',
				'print',
				'sms',
				'social',
				'video',
			],
		];

		return apply_filters( 'kntnt_ad_attr_utm_options', $options );
	}

}
