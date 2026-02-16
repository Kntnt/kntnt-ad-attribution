<?php
/**
 * Cookie management for ad click tracking.
 *
 * Handles reading, parsing, validating, merging, and writing the first-party
 * cookies used to associate ad clicks with visitor sessions.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

/**
 * Stateless cookie manager for the _ad_clicks and _aah_pending cookies.
 *
 * All methods receive data as arguments and return results — no internal
 * state is maintained between calls.
 *
 * @since 1.0.0
 */
final class Cookie_Manager {

	/**
	 * Maximum number of hashes stored in the cookie.
	 *
	 * Oldest entries are evicted when the limit is exceeded.
	 *
	 * @var int
	 * @since 1.0.0
	 */
	private const MAX_HASHES = 50;

	/**
	 * Regex pattern for a valid SHA-256 hash (64 hex characters).
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private const HASH_PATTERN = '/^[a-f0-9]{64}$/';

	/**
	 * Regex pattern for the full cookie value.
	 *
	 * Validates the entire `hash:timestamp[,hash:timestamp]*` format in one pass.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private const COOKIE_PATTERN = '/^[a-f0-9]{64}:\d{1,10}(,[a-f0-9]{64}:\d{1,10})*$/';

	/**
	 * Parses the _ad_clicks cookie into an associative array.
	 *
	 * Reads directly from `$_COOKIE`, validates format with regex, and returns
	 * a map of hash => timestamp. Logs a warning on corrupt data.
	 *
	 * @return array<string, int> Map of hash => Unix timestamp. Empty if cookie is
	 *                            missing or invalid.
	 * @since 1.0.0
	 */
	public function parse(): array {
		if ( ! isset( $_COOKIE['_ad_clicks'] ) ) {
			return [];
		}

		$raw = $_COOKIE['_ad_clicks'];

		if ( ! preg_match( self::COOKIE_PATTERN, $raw ) ) {
			error_log( 'kntnt-ad-attr: Corrupt _ad_clicks cookie discarded.' );
			return [];
		}

		$entries = [];
		foreach ( explode( ',', $raw ) as $pair ) {
			[ $hash, $timestamp ] = explode( ':', $pair );
			$entries[ $hash ] = (int) $timestamp;
		}

		return $entries;
	}

	/**
	 * Adds or updates a hash in the entries array.
	 *
	 * Sets the timestamp to the provided value (or current time). If the
	 * resulting array exceeds MAX_HASHES, the oldest entry is removed.
	 *
	 * @param array<string, int> $entries   Existing hash => timestamp map.
	 * @param string             $hash      The SHA-256 hash to add or update.
	 * @param int|null           $timestamp Unix timestamp to store, or null for time().
	 *
	 * @return array<string, int> Updated hash => timestamp map.
	 * @since 1.0.0
	 */
	public function add( array $entries, string $hash, ?int $timestamp = null ): array {
		$entries[ $hash ] = $timestamp ?? time();

		// Evict oldest entries if we exceed the limit.
		if ( count( $entries ) > self::MAX_HASHES ) {
			asort( $entries );
			$entries = array_slice( $entries, count( $entries ) - self::MAX_HASHES, null, true );
		}

		return $entries;
	}

	/**
	 * Serializes a hash => timestamp map to cookie format.
	 *
	 * @param array<string, int> $entries Hash => timestamp map.
	 *
	 * @return string Cookie value in `hash:timestamp,hash:timestamp,...` format.
	 * @since 1.0.0
	 */
	public function serialize( array $entries ): string {
		$pairs = [];
		foreach ( $entries as $hash => $timestamp ) {
			$pairs[] = $hash . ':' . $timestamp;
		}
		return implode( ',', $pairs );
	}

	/**
	 * Writes the _ad_clicks cookie with the provided entries.
	 *
	 * Cookie attributes: Path=/, HttpOnly, Secure, SameSite=Lax.
	 * Lifetime is filterable via `kntnt_ad_attr_cookie_lifetime` (default 90 days).
	 *
	 * @param array<string, int> $entries Hash => timestamp map to persist.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function set_clicks_cookie( array $entries ): void {
		/** @var int $lifetime_days Number of days the cookie should persist. */
		$lifetime_days = (int) apply_filters( 'kntnt_ad_attr_cookie_lifetime', 90 );

		setcookie( '_ad_clicks', $this->serialize( $entries ), [
			'expires'  => time() + ( $lifetime_days * DAY_IN_SECONDS ),
			'path'     => '/',
			'secure'   => true,
			'httponly'  => true,
			'samesite' => 'Lax',
		] );
	}

	/**
	 * Writes the _aah_pending transport cookie for deferred consent handling.
	 *
	 * Short-lived cookie (60 seconds) readable by JavaScript (not HttpOnly)
	 * so the client-side script can pick up the hash and store it once consent
	 * is granted.
	 *
	 * @param string $hash The SHA-256 hash to transport.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function set_transport_cookie( string $hash ): void {
		setcookie( '_aah_pending', $hash, [
			'expires'  => time() + 60,
			'path'     => '/',
			'secure'   => true,
			'httponly'  => false,
			'samesite' => 'Lax',
		] );
	}

	/**
	 * Validates a hash string against the expected SHA-256 format.
	 *
	 * @param string $hash The string to validate.
	 *
	 * @return bool True if the hash is a valid 64-character hex string.
	 * @since 1.0.0
	 */
	public function validate_hash( string $hash ): bool {
		return (bool) preg_match( self::HASH_PATTERN, $hash );
	}

}
