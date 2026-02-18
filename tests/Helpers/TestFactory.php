<?php
/**
 * Factory methods for creating test doubles and fixture data.
 *
 * Provides convenient helper methods for building mock objects and
 * generating test data used across multiple test files.
 *
 * @package Tests\Helpers
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Tests\Helpers;

use Mockery;
use WP_Post;

/**
 * Test double factory for common mock objects.
 *
 * @since 1.0.0
 */
final class TestFactory {

    /**
     * Creates a mock $wpdb instance with common properties pre-set.
     *
     * @param array<string,string> $table_overrides Override specific table name properties.
     *
     * @return \Mockery\MockInterface&\stdClass Mock wpdb object.
     */
    public static function wpdb(array $table_overrides = []): Mockery\MockInterface {
        $wpdb = Mockery::mock('wpdb');

        // Default table properties
        $defaults = [
            'prefix'      => 'wp_',
            'posts'       => 'wp_posts',
            'postmeta'    => 'wp_postmeta',
            'options'     => 'wp_options',
        ];

        foreach (array_merge($defaults, $table_overrides) as $prop => $value) {
            $wpdb->{$prop} = $value;
        }

        return $wpdb;
    }

    /**
     * Creates a WP_Post stub with the given properties.
     *
     * @param array<string,mixed> $props Post properties to set.
     *
     * @return WP_Post
     */
    public static function post(array $props = []): WP_Post {
        $post = new WP_Post();

        $defaults = [
            'ID'            => 1,
            'post_title'    => 'Test Post',
            'post_type'     => 'post',
            'post_status'   => 'publish',
            'post_name'     => 'test-post',
            'post_content'  => '',
            'post_date'     => '2024-01-01 00:00:00',
            'post_modified' => '2024-01-01 00:00:00',
            'post_author'   => 1,
            'post_parent'   => 0,
        ];

        foreach (array_merge($defaults, $props) as $key => $value) {
            $post->{$key} = $value;
        }

        return $post;
    }

    /**
     * Generates a valid 64-character lowercase hex hash.
     *
     * @param string $seed Optional seed for reproducible hashes.
     *
     * @return string 64-character lowercase hex string.
     */
    public static function hash(string $seed = ''): string {
        if ($seed !== '') {
            return hash('sha256', $seed);
        }
        return bin2hex(random_bytes(32));
    }

    /**
     * Generates multiple unique hashes.
     *
     * @param int $count Number of hashes to generate.
     *
     * @return string[] Array of 64-character hex strings.
     */
    public static function hashes(int $count): array {
        return array_map(
            fn (int $i) => self::hash("test-hash-{$i}"),
            range(1, $count),
        );
    }

    /**
     * Creates an _ad_clicks cookie value from hash => timestamp pairs.
     *
     * @param array<string,int> $entries Hash => timestamp map.
     *
     * @return string Cookie value in `hash:ts,hash:ts` format.
     */
    public static function cookieValue(array $entries): string {
        $pairs = [];
        foreach ($entries as $hash => $timestamp) {
            $pairs[] = "{$hash}:{$timestamp}";
        }
        return implode(',', $pairs);
    }
}
