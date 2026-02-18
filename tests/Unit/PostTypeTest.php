<?php
/**
 * Unit tests for Post_Type.
 *
 * @package Tests\Unit
 * @since   1.0.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution\Post_Type;
use Brain\Monkey\Functions;
use Tests\Helpers\TestFactory;

// ─── register() ───

describe('Post_Type::register()', function () {

    it('calls register_post_type with correct slug and args', function () {
        Functions\expect('register_post_type')
            ->once()
            ->withArgs(function (string $slug, array $args) {
                expect($slug)->toBe('kntnt_ad_attr_url');
                expect($args['public'])->toBeFalse();
                expect($args['show_ui'])->toBeFalse();
                expect($args['show_in_rest'])->toBeTrue();
                expect($args['rest_base'])->toBe('kntnt-ad-attr-urls');
                return true;
            });

        Functions\expect('add_filter')
            ->once()
            ->with('wp_untrash_post_status', Mockery::type('array'), 20, 2);

        (new Post_Type())->register();
    });

    it('registers wp_untrash_post_status filter at priority 20', function () {
        Functions\expect('register_post_type')->once();

        Functions\expect('add_filter')
            ->once()
            ->withArgs(function (string $hook, $callback, int $priority, int $args) {
                expect($hook)->toBe('wp_untrash_post_status');
                expect($priority)->toBe(20);
                expect($args)->toBe(2);
                return true;
            });

        (new Post_Type())->register();
    });

});

// ─── untrash_status() ───

describe('Post_Type::untrash_status()', function () {

    it('returns publish for own CPT', function () {
        Functions\expect('get_post_type')
            ->once()
            ->with(42)
            ->andReturn('kntnt_ad_attr_url');

        $result = (new Post_Type())->untrash_status('draft', 42);

        expect($result)->toBe('publish');
    });

    it('returns unchanged status for other post types', function () {
        Functions\expect('get_post_type')
            ->once()
            ->with(99)
            ->andReturn('post');

        $result = (new Post_Type())->untrash_status('draft', 99);

        expect($result)->toBe('draft');
    });

});

// ─── get_valid_hashes() ───

describe('Post_Type::get_valid_hashes()', function () {

    it('returns empty array for empty input without DB query', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $wpdb->shouldNotReceive('prepare');
        $wpdb->shouldNotReceive('get_col');

        $result = Post_Type::get_valid_hashes([]);

        expect($result)->toBe([]);
    });

    it('returns only published hashes from database', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $hash_a = str_repeat('a', 64);
        $hash_b = str_repeat('b', 64);

        $wpdb->shouldReceive('prepare')
            ->once()
            ->withArgs(function (string $sql) {
                expect($sql)->toContain("post_status = 'publish'");
                expect($sql)->toContain("meta_key = '_hash'");
                return true;
            })
            ->andReturn('prepared-sql');

        // Only hash_a is returned (published); hash_b was draft
        $wpdb->shouldReceive('get_col')
            ->once()
            ->with('prepared-sql')
            ->andReturn([$hash_a]);

        $result = Post_Type::get_valid_hashes([$hash_a, $hash_b]);

        expect($result)->toBe([$hash_a]);
    });

});

// ─── get_distinct_meta_values() ───

describe('Post_Type::get_distinct_meta_values()', function () {

    it('returns sorted unique values from database', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $wpdb->shouldReceive('prepare')
            ->once()
            ->andReturn('prepared-sql');

        $wpdb->shouldReceive('get_col')
            ->once()
            ->with('prepared-sql')
            ->andReturn(['cpc', 'display', 'paid_social']);

        $result = Post_Type::get_distinct_meta_values('_utm_medium');

        expect($result)->toBe(['cpc', 'display', 'paid_social']);
    });

    it('filters to published posts only', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $wpdb->shouldReceive('prepare')
            ->once()
            ->withArgs(function (string $sql) {
                expect($sql)->toContain("post_status = 'publish'");
                return true;
            })
            ->andReturn('prepared-sql');

        $wpdb->shouldReceive('get_col')
            ->once()
            ->andReturn([]);

        Post_Type::get_distinct_meta_values('_utm_source');
    });

    it('returns empty array when no values found', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $wpdb->shouldReceive('prepare')->andReturn('sql');
        $wpdb->shouldReceive('get_col')->andReturn(false);

        $result = Post_Type::get_distinct_meta_values('_utm_source');

        expect($result)->toBe([]);
    });

});
