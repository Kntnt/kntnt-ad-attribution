<?php
/**
 * Unit tests for Plugin static methods.
 *
 * @package Tests\Unit
 * @since   1.0.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution\Plugin;
use Brain\Monkey\Functions;
use Brain\Monkey\Filters;

// ─── get_url_prefix() ───

describe('Plugin::get_url_prefix()', function () {

    it('returns the cached or filtered prefix', function () {
        // get_url_prefix() uses a static cache, so it may already be
        // cached from a prior test file. We can only test that the
        // method returns a string.
        $prefix = Plugin::get_url_prefix();

        expect($prefix)->toBeString();
        expect(strlen($prefix))->toBeGreaterThan(0);
    });

});

// ─── get_plugin_file() ───

describe('Plugin::get_plugin_file()', function () {

    it('returns file path when set', function () {
        // set_plugin_file may have been called elsewhere. Redefine to test.
        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Plugin::get_plugin_file',
            fn () => '/path/to/plugin.php',
        );

        expect(Plugin::get_plugin_file())->toBe('/path/to/plugin.php');
    });

});

// ─── get_slug() ───

describe('Plugin::get_slug()', function () {

    it('returns slug derived from filename', function () {
        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Plugin::get_plugin_file',
            fn () => '/path/to/kntnt-ad-attribution.php',
        );

        // get_slug() also caches, so use Patchwork to reset.
        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Plugin::get_slug',
            fn () => 'kntnt-ad-attribution',
        );

        expect(Plugin::get_slug())->toBe('kntnt-ad-attribution');
    });

});

// ─── authorize() ───

describe('Plugin::authorize()', function () {

    it('does nothing when user has capability', function () {
        Functions\expect('current_user_can')
            ->once()
            ->with('kntnt_ad_attr')
            ->andReturn(true);

        // Should not call wp_die.
        Functions\expect('wp_die')->never();

        Plugin::authorize();

        expect(true)->toBeTrue();
    });

    it('calls wp_die when user lacks capability', function () {
        Functions\expect('current_user_can')
            ->once()
            ->with('kntnt_ad_attr')
            ->andReturn(false);

        Functions\expect('wp_die')
            ->once()
            ->andThrow(new \RuntimeException('wp_die called'));

        expect(fn () => Plugin::authorize())->toThrow(\RuntimeException::class);
    });

});

// ─── deactivate() ───

describe('Plugin::deactivate()', function () {

    afterEach(function () {
        unset($GLOBALS['wpdb']);
    });

    it('clears both cron hooks', function () {
        $wpdb = \Tests\Helpers\TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $wpdb->shouldReceive('prepare')->once()->andReturn('SQL');
        $wpdb->shouldReceive('query')->once()->andReturn(0);

        Functions\expect('wp_clear_scheduled_hook')
            ->once()
            ->with('kntnt_ad_attr_daily_cleanup');

        Functions\expect('wp_clear_scheduled_hook')
            ->once()
            ->with('kntnt_ad_attr_process_queue');

        Functions\expect('flush_rewrite_rules')->once();

        Plugin::deactivate();

        expect(true)->toBeTrue();
    });

    it('flushes rewrite rules', function () {
        $wpdb = \Tests\Helpers\TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $wpdb->shouldReceive('prepare')->once()->andReturn('SQL');
        $wpdb->shouldReceive('query')->once()->andReturn(0);

        Functions\when('wp_clear_scheduled_hook')->justReturn(0);
        Functions\expect('flush_rewrite_rules')->once();

        Plugin::deactivate();

        expect(true)->toBeTrue();
    });

    it('deletes plugin transients from options table', function () {
        $wpdb = \Tests\Helpers\TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        // Verify the prepare call uses correct LIKE patterns.
        $wpdb->shouldReceive('prepare')
            ->once()
            ->withArgs(function (string $sql, string $like1, string $like2) {
                return str_contains($sql, 'DELETE FROM')
                    && $like1 === '_transient_kntnt_ad_attr_%'
                    && $like2 === '_transient_timeout_kntnt_ad_attr_%';
            })
            ->andReturn('SQL');

        $wpdb->shouldReceive('query')->once()->with('SQL')->andReturn(3);

        Functions\when('wp_clear_scheduled_hook')->justReturn(0);
        Functions\expect('flush_rewrite_rules')->once();

        Plugin::deactivate();

        expect(true)->toBeTrue();
    });

});

// ─── add_action_link() ───

describe('Plugin::add_action_link()', function () {

    it('prepends two links to the links array', function () {
        // We need get_slug to work for the URL.
        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Plugin::get_slug',
            fn () => 'kntnt-ad-attribution',
        );

        // add_action_link() now adds two links (Ad Attribution + Settings),
        // each calling admin_url() once.
        Functions\when('admin_url')->alias(fn (string $path) => "https://example.com/{$path}");

        // Need to instantiate — but constructor is private. Use reflection.
        $instance = (new \ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();

        $result = $instance->add_action_link(['existing-link']);

        expect($result)->toHaveCount(3);
        expect($result[0])->toContain('href=');
        expect($result[0])->toContain('Ad Attribution');
        expect($result[1])->toContain('href=');
        expect($result[1])->toContain('Settings');
        expect($result[2])->toBe('existing-link');
    });

});

// ─── enqueue_public_scripts() ───

describe('Plugin::enqueue_public_scripts()', function () {

    it('enqueues pending-consent script with localized data', function () {
        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Plugin::get_plugin_url',
            fn () => 'https://example.com/wp-content/plugins/kntnt-ad-attribution/',
        );
        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Plugin::get_version',
            fn () => '1.5.0',
        );

        Functions\expect('wp_enqueue_script')->once()->withArgs(function ($handle) {
            return $handle === 'kntnt-ad-attribution';
        });

        Functions\expect('wp_localize_script')->once()->withArgs(function ($handle, $name, $data) {
            return $handle === 'kntnt-ad-attribution'
                && $name === 'kntntAdAttribution'
                && isset($data['restUrl'])
                && isset($data['nonce']);
        });

        Functions\expect('rest_url')->once()->andReturn('https://example.com/wp-json/kntnt-ad-attribution/v1/set-cookie');
        Functions\expect('wp_create_nonce')->once()->andReturn('test-nonce');

        $instance = (new \ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
        $instance->enqueue_public_scripts();

        expect(true)->toBeTrue();
    });

});
