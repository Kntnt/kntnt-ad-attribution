<?php
/**
 * Plugin settings manager.
 *
 * Provides read/write access to the plugin's configuration stored as a
 * single serialized WordPress option. Filter-based defaults allow companion
 * plugins to set values programmatically while the settings page allows
 * manual overrides.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.8.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

/**
 * Manages plugin settings stored in `kntnt_ad_attr_settings`.
 *
 * Saved values take precedence over filter defaults. Empty strings are
 * not persisted, so unsetting a value reverts to the filter default.
 *
 * @since 1.8.0
 */
final class Settings {

	/**
	 * WordPress option key for plugin settings.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	public const OPTION_KEY = 'kntnt_ad_attr_settings';

	/**
	 * Base defaults before filters are applied.
	 *
	 * @var array<string, mixed>
	 * @since 1.8.0
	 */
	private const BASE_DEFAULTS = [
		'cookie_lifetime'      => 90,
		'dedup_seconds'        => 0,
		'enable_logging'       => '',
		'log_file_size_max_KB' => 2048,
		'log_file_size_min_KB' => 256,
		'attempts_per_round'   => 3,
		'retry_delay'          => 60,
		'max_rounds'           => 3,
		'round_delay'          => 21600,
	];

	/**
	 * Cached filter defaults (computed once per request).
	 *
	 * @var array<string, mixed>|null
	 * @since 1.8.0
	 */
	private static ?array $filter_defaults = null;

	/**
	 * Gets a single setting value.
	 *
	 * Returns the saved value if it exists, otherwise the filter default.
	 *
	 * @param string $key Setting key.
	 *
	 * @return mixed Setting value.
	 * @since 1.8.0
	 */
	public function get( string $key ): mixed {
		$saved = $this->get_saved();

		if ( isset( $saved[ $key ] ) && $saved[ $key ] !== '' ) {
			return $saved[ $key ];
		}

		$defaults = $this->get_filter_defaults();
		return $defaults[ $key ] ?? self::BASE_DEFAULTS[ $key ] ?? '';
	}

	/**
	 * Gets the filter-computed default for a key.
	 *
	 * This is the value computed via apply_filters before any saved-option
	 * override is applied. Used for placeholder display on the settings page.
	 *
	 * @param string $key Setting key.
	 *
	 * @return mixed Filter default value.
	 * @since 1.8.0
	 */
	public function get_filter_default( string $key ): mixed {
		$defaults = $this->get_filter_defaults();
		return $defaults[ $key ] ?? self::BASE_DEFAULTS[ $key ] ?? '';
	}

	/**
	 * Gets the raw saved option array.
	 *
	 * @return array<string, mixed> Saved settings (may be empty).
	 * @since 1.8.0
	 */
	public function get_saved(): array {
		$stored = get_option( self::OPTION_KEY, [] );
		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * Saves the settings array.
	 *
	 * @param array<string, mixed> $values Settings to save.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function update( array $values ): void {
		update_option( self::OPTION_KEY, $values );
	}

	/**
	 * Computes and caches filter defaults for all known keys.
	 *
	 * Must be called (and cached) BEFORE any override filters are registered
	 * on cookie_lifetime/dedup_seconds, so that placeholders reflect the
	 * external filter value, not the saved override.
	 *
	 * @return array<string, mixed> Filter defaults for all settings.
	 * @since 1.8.0
	 */
	public function get_filter_defaults(): array {

		if ( self::$filter_defaults !== null ) {
			return self::$filter_defaults;
		}

		// Compute retry defaults from the grouped filter.
		$retry_defaults = (array) apply_filters( 'kntnt_ad_attr_queue_retry_defaults', [
			'attempts_per_round' => self::BASE_DEFAULTS['attempts_per_round'],
			'retry_delay'        => self::BASE_DEFAULTS['retry_delay'],
			'max_rounds'         => self::BASE_DEFAULTS['max_rounds'],
			'round_delay'        => self::BASE_DEFAULTS['round_delay'],
		] );

		self::$filter_defaults = [
			'cookie_lifetime'      => (int) apply_filters( 'kntnt_ad_attr_cookie_lifetime', self::BASE_DEFAULTS['cookie_lifetime'] ),
			'dedup_seconds'        => (int) apply_filters( 'kntnt_ad_attr_dedup_seconds', self::BASE_DEFAULTS['dedup_seconds'] ),
			'enable_logging'       => self::BASE_DEFAULTS['enable_logging'],
			'log_file_size_max_KB' => (int) apply_filters( 'kntnt_ad_attr_log_file_size_max_KB', self::BASE_DEFAULTS['log_file_size_max_KB'] ),
			'log_file_size_min_KB' => (int) apply_filters( 'kntnt_ad_attr_log_file_size_min_KB', self::BASE_DEFAULTS['log_file_size_min_KB'] ),
			'attempts_per_round'   => (int) ( $retry_defaults['attempts_per_round'] ?? self::BASE_DEFAULTS['attempts_per_round'] ),
			'retry_delay'          => (int) ( $retry_defaults['retry_delay'] ?? self::BASE_DEFAULTS['retry_delay'] ),
			'max_rounds'           => (int) ( $retry_defaults['max_rounds'] ?? self::BASE_DEFAULTS['max_rounds'] ),
			'round_delay'          => (int) ( $retry_defaults['round_delay'] ?? self::BASE_DEFAULTS['round_delay'] ),
		];

		return self::$filter_defaults;
	}

}
