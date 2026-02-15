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
				'google'    => 'cpc',
				'meta'      => 'paid_social',
				'linkedin'  => 'paid_social',
				'microsoft' => 'cpc',
				'tiktok'    => 'paid_social',
				'pinterest' => 'paid_social',
			],
			'mediums' => [
				'cpc',
				'paid_social',
				'display',
				'video',
				'shopping',
			],
		];

		return apply_filters( 'kntnt_ad_attr_utm_options', $options );
	}

}
