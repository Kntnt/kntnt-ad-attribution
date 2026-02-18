<?php
/**
 * Bot detection via User-Agent filtering and robots.txt rules.
 *
 * Prevents automated requests from inflating click statistics by matching
 * the visitor's User-Agent against known bot signatures and adding a
 * Disallow rule to robots.txt for the tracking URL prefix.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

/**
 * Detects bot traffic via User-Agent inspection.
 *
 * Registers itself on the `kntnt_ad_attr_is_bot` filter so third-party
 * code can extend or override detection. Also injects a Disallow rule
 * into the WordPress-generated robots.txt.
 *
 * @since 1.0.0
 */
final class Bot_Detector {

	/**
	 * User-Agent substrings that identify automated clients.
	 *
	 * Matched case-insensitively via `str_contains()`.
	 *
	 * @var string[]
	 * @since 1.0.0
	 */
	private const BOT_SIGNATURES = [
		'bot',       // Catches LinkedInBot, AdsBot-Google, Googlebot, Bingbot, etc.
		'crawl',
		'spider',
		'slurp',
		'facebookexternalhit',
		'Mediapartners-Google',
		'Yahoo',
		'curl',
		'wget',
		'python-requests',
		'HeadlessChrome',
		'Lighthouse',
		'GTmetrix',
	];

	/**
	 * Registers bot detection filter and robots.txt rule.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register(): void {
		add_filter( 'kntnt_ad_attr_is_bot', [ $this, 'detect' ] );
		add_filter( 'robots_txt', [ $this, 'add_disallow_rule' ], 10, 2 );
	}

	/**
	 * Returns whether the current request is from a bot.
	 *
	 * Delegates to the `kntnt_ad_attr_is_bot` filter so additional detectors
	 * can be registered by third-party code.
	 *
	 * @return bool True if the request is identified as a bot.
	 * @since 1.0.0
	 */
	public function is_bot(): bool {
		return (bool) apply_filters( 'kntnt_ad_attr_is_bot', false );
	}

	/**
	 * Filter callback: detects bots by User-Agent string.
	 *
	 * Short-circuits if a previous filter already flagged the request as a bot.
	 * An empty or missing User-Agent is treated as a bot.
	 *
	 * @param bool $is_bot Whether a previous filter already identified a bot.
	 *
	 * @return bool True if the request is from a bot.
	 * @since 1.0.0
	 */
	public function detect( bool $is_bot ): bool {
		if ( $is_bot ) {
			return true;
		}

		$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

		if ( $user_agent === '' ) {
			return true;
		}

		$ua_lower = strtolower( $user_agent );
		foreach ( self::BOT_SIGNATURES as $signature ) {
			if ( str_contains( $ua_lower, strtolower( $signature ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Adds a Disallow rule for the tracking URL prefix to robots.txt.
	 *
	 * Only modifies the output when the site is public (not discouraged from
	 * search engine indexing).
	 *
	 * @param string $output The existing robots.txt content.
	 * @param bool   $public Whether the site is public.
	 *
	 * @return string Modified robots.txt content.
	 * @since 1.0.0
	 */
	public function add_disallow_rule( string $output, bool $public ): string {
		if ( $public ) {
			$output .= "\nDisallow: /" . Plugin::get_url_prefix() . "/\n";
		}
		return $output;
	}

}
