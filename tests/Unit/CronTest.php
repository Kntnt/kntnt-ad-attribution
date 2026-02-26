<?php
/**
 * Unit tests for Cron.
 *
 * @package Tests\Unit
 * @since   1.0.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution\Cron;
use Kntnt\Ad_Attribution\Click_ID_Store;
use Kntnt\Ad_Attribution\Queue;
use Kntnt\Ad_Attribution\Post_Type;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Tests\Helpers\TestFactory;

/**
 * Creates a Cron instance with fresh mocked dependencies.
 *
 * @return array{0: Cron, 1: Mockery\MockInterface, 2: Mockery\MockInterface}
 */
function make_cron(): array {
    $cis   = Mockery::mock(Click_ID_Store::class);
    $queue = Mockery::mock(Queue::class);
    return [new Cron($cis, $queue), $cis, $queue];
}

// ─── register() ───

describe('Cron::register()', function () {

    it('registers daily cleanup, trash warning, and admin notices hooks', function () {
        [$cron] = make_cron();

        Actions\expectAdded('kntnt_ad_attr_daily_cleanup')->once();
        Actions\expectAdded('wp_trash_post')->once();
        Actions\expectAdded('admin_notices')->once();

        $cron->register();

        expect(true)->toBeTrue();
    });

});

// ─── run_daily_cleanup() ───

describe('Cron::run_daily_cleanup()', function () {

    afterEach(function () {
        unset($GLOBALS['wpdb']);
    });

    it('calls cleanup_clicks with retention days from filter', function () {
        [$cron, $cis, $queue] = make_cron();

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        // Retention filter returns 180 days.
        Filters\expectApplied('kntnt_ad_attr_click_retention_days')
            ->once()
            ->andReturn(180);

        Functions\when('time')->justReturn(1700000000);
        Functions\when('gmdate')->justReturn('2024-01-01 00:00:00');

        // cleanup_clicks: DELETE conversions + DELETE clicks.
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('query')->andReturn(0);

        // cleanup_conversions: DELETE orphaned.
        // delete_orphaned_clicks: DELETE conversions + DELETE clicks.

        // draft_orphaned_urls.
        Functions\expect('get_posts')->once()->andReturn([]);

        // Adapter cleanup.
        $cis->shouldReceive('cleanup')->once()->with(120);
        $queue->shouldReceive('cleanup')->once()->with(30, 90);

        $cron->run_daily_cleanup();

        expect(true)->toBeTrue();
    });

    it('calls Click_ID_Store::cleanup(120) and Queue::cleanup(30, 90)', function () {
        [$cron, $cis, $queue] = make_cron();

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        Functions\when('time')->justReturn(1700000000);
        Functions\when('gmdate')->justReturn('2024-01-01 00:00:00');

        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('query')->andReturn(0);
        Functions\expect('get_posts')->once()->andReturn([]);

        $cis->shouldReceive('cleanup')->once()->with(120);
        $queue->shouldReceive('cleanup')->once()->with(30, 90);

        $cron->run_daily_cleanup();

        expect(true)->toBeTrue();
    });

    it('logs when expired clicks are deleted', function () {
        [$cron, $cis, $queue] = make_cron();

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        Functions\when('time')->justReturn(1700000000);
        Functions\when('gmdate')->justReturn('2024-01-01 00:00:00');

        $wpdb->shouldReceive('prepare')->andReturn('SQL');

        // First query (delete conversions for expired clicks) returns 0.
        // Second query (delete expired clicks) returns 5.
        // Third query (delete orphaned conversions) returns 0.
        // Fourth query (delete conversions for orphaned clicks) returns 0.
        // Fifth query (delete orphaned clicks) returns 0.
        $wpdb->shouldReceive('query')
            ->andReturn(0, 5, 0, 0, 0);

        // Logger is null in tests, so the info() call is a no-op.

        Functions\expect('get_posts')->once()->andReturn([]);

        $cis->shouldReceive('cleanup')->once();
        $queue->shouldReceive('cleanup')->once();

        $cron->run_daily_cleanup();

        expect(true)->toBeTrue();
    });

    it('drafts tracking URLs whose target is missing', function () {
        [$cron, $cis, $queue] = make_cron();

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        Functions\when('time')->justReturn(1700000000);
        Functions\when('gmdate')->justReturn('2024-01-01 00:00:00');

        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('query')->andReturn(0);

        // draft_orphaned_urls: get_posts returns one tracking URL.
        $tracking_url = TestFactory::post([
            'ID'         => 10,
            'post_title' => 'Orphaned URL',
            'post_type'  => 'kntnt_ad_attr_url',
        ]);

        Functions\expect('get_posts')->once()->andReturn([$tracking_url]);
        Functions\expect('get_post_meta')
            ->once()
            ->with(10, '_target_post_id', true)
            ->andReturn('42');

        // Target is missing.
        Functions\expect('get_post')->once()->with(42)->andReturn(null);

        // Should draft the tracking URL.
        Functions\expect('wp_update_post')->once()->with(Mockery::on(function ($args) {
            return $args['ID'] === 10 && $args['post_status'] === 'draft';
        }));

        // Should set transient with orphaned title.
        Functions\expect('set_transient')
            ->once()
            ->with('kntnt_ad_attr_orphaned_urls', ['Orphaned URL'], DAY_IN_SECONDS);

        $cis->shouldReceive('cleanup')->once();
        $queue->shouldReceive('cleanup')->once();

        $cron->run_daily_cleanup();

        expect(true)->toBeTrue();
    });

    it('drafts tracking URLs whose target is unpublished', function () {
        [$cron, $cis, $queue] = make_cron();

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        Functions\when('time')->justReturn(1700000000);
        Functions\when('gmdate')->justReturn('2024-01-01 00:00:00');

        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('query')->andReturn(0);

        $tracking_url = TestFactory::post([
            'ID'         => 10,
            'post_title' => 'Draft Target URL',
            'post_type'  => 'kntnt_ad_attr_url',
        ]);

        Functions\expect('get_posts')->once()->andReturn([$tracking_url]);
        Functions\expect('get_post_meta')
            ->once()
            ->with(10, '_target_post_id', true)
            ->andReturn('42');

        // Target exists but is draft.
        $target = TestFactory::post(['ID' => 42, 'post_status' => 'draft']);
        Functions\expect('get_post')->once()->with(42)->andReturn($target);

        Functions\expect('wp_update_post')->once();
        Functions\expect('set_transient')->once()
            ->with('kntnt_ad_attr_orphaned_urls', ['Draft Target URL'], DAY_IN_SECONDS);

        $cis->shouldReceive('cleanup')->once();
        $queue->shouldReceive('cleanup')->once();

        $cron->run_daily_cleanup();

        expect(true)->toBeTrue();
    });

    it('skips URLs with no target_post_id', function () {
        [$cron, $cis, $queue] = make_cron();

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        Functions\when('time')->justReturn(1700000000);
        Functions\when('gmdate')->justReturn('2024-01-01 00:00:00');

        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('query')->andReturn(0);

        $tracking_url = TestFactory::post([
            'ID'        => 10,
            'post_type' => 'kntnt_ad_attr_url',
        ]);

        Functions\expect('get_posts')->once()->andReturn([$tracking_url]);
        Functions\expect('get_post_meta')
            ->once()
            ->with(10, '_target_post_id', true)
            ->andReturn('0');

        // No wp_update_post or set_transient should be called.
        Functions\expect('wp_update_post')->never();
        Functions\expect('set_transient')->never();

        $cis->shouldReceive('cleanup')->once();
        $queue->shouldReceive('cleanup')->once();

        $cron->run_daily_cleanup();

        expect(true)->toBeTrue();
    });

});

// ─── warn_on_target_trash() ───

describe('Cron::warn_on_target_trash()', function () {

    it('ignores own CPT', function () {
        [$cron] = make_cron();

        Functions\expect('get_post_type')
            ->once()
            ->with(5)
            ->andReturn('kntnt_ad_attr_url');

        // Should NOT call get_posts or set_transient.
        Functions\expect('get_posts')->never();
        Functions\expect('set_transient')->never();

        $cron->warn_on_target_trash(5);

        expect(true)->toBeTrue();
    });

    it('sets transient when affected tracking URLs found', function () {
        [$cron] = make_cron();

        Functions\expect('get_post_type')
            ->once()
            ->with(42)
            ->andReturn('page');

        $affected = [
            TestFactory::post(['ID' => 10, 'post_title' => 'Ad Link A']),
            TestFactory::post(['ID' => 11, 'post_title' => 'Ad Link B']),
        ];

        Functions\expect('get_posts')->once()->andReturn($affected);
        Functions\expect('wp_list_pluck')
            ->once()
            ->with($affected, 'post_title')
            ->andReturn(['Ad Link A', 'Ad Link B']);

        Functions\expect('set_transient')
            ->once()
            ->with('kntnt_ad_attr_trashed_target', ['Ad Link A', 'Ad Link B'], 60);

        $cron->warn_on_target_trash(42);

        expect(true)->toBeTrue();
    });

    it('does not set transient when no affected URLs', function () {
        [$cron] = make_cron();

        Functions\expect('get_post_type')
            ->once()
            ->with(42)
            ->andReturn('page');

        Functions\expect('get_posts')->once()->andReturn([]);
        Functions\expect('set_transient')->never();

        $cron->warn_on_target_trash(42);

        expect(true)->toBeTrue();
    });

});

// ─── display_admin_notices() ───

describe('Cron::display_admin_notices()', function () {

    it('shows nothing when user lacks capability', function () {
        [$cron] = make_cron();

        Functions\expect('current_user_can')
            ->once()
            ->with('kntnt_ad_attr')
            ->andReturn(false);

        Functions\expect('get_transient')->never();

        ob_start();
        $cron->display_admin_notices();
        $output = ob_get_clean();

        expect($output)->toBe('');
    });

    it('shows orphaned notice and deletes transient', function () {
        [$cron] = make_cron();

        Functions\expect('current_user_can')->once()->andReturn(true);

        // Orphaned transient exists.
        Functions\expect('get_transient')
            ->with('kntnt_ad_attr_orphaned_urls')
            ->once()
            ->andReturn(['URL A', 'URL B']);

        Functions\expect('delete_transient')
            ->with('kntnt_ad_attr_orphaned_urls')
            ->once();

        // Trashed transient empty.
        Functions\expect('get_transient')
            ->with('kntnt_ad_attr_trashed_target')
            ->once()
            ->andReturn(false);

        // _n is stubbed by Brain Monkey, but we need a functional version.
        Functions\when('_n')->alias(fn ($single, $plural, $count, $domain) => $count === 1 ? $single : $plural);

        ob_start();
        $cron->display_admin_notices();
        $output = ob_get_clean();

        expect($output)->toContain('notice-warning');
        expect($output)->toContain('URL A');
        expect($output)->toContain('URL B');
    });

    it('shows trashed target notice and deletes transient', function () {
        [$cron] = make_cron();

        Functions\expect('current_user_can')->once()->andReturn(true);

        // Orphaned transient empty.
        Functions\expect('get_transient')
            ->with('kntnt_ad_attr_orphaned_urls')
            ->once()
            ->andReturn(false);

        // Trashed transient exists.
        Functions\expect('get_transient')
            ->with('kntnt_ad_attr_trashed_target')
            ->once()
            ->andReturn(['Trashed Page']);

        Functions\expect('delete_transient')
            ->with('kntnt_ad_attr_trashed_target')
            ->once();

        Functions\when('_n')->alias(fn ($single, $plural, $count, $domain) => $count === 1 ? $single : $plural);

        ob_start();
        $cron->display_admin_notices();
        $output = ob_get_clean();

        expect($output)->toContain('notice-warning');
        expect($output)->toContain('Trashed Page');
    });

});
