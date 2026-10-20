<?php
/**
 * Unit tests for Admin_Page.
 *
 * @package Tests\Unit
 * @since   1.0.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution\Admin_Page;
use Kntnt\Ad_Attribution\Queue;
use Kntnt\Ad_Attribution\Queue_Processor;
use Kntnt\Ad_Attribution\Settings;
use Kntnt\Ad_Attribution\Logger;
use Kntnt\Ad_Attribution\Plugin;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Tests\Helpers\TestFactory;

/**
 * Exception used to catch exit() calls in form handlers.
 */
class AdminExitException extends \RuntimeException {}

/**
 * Creates an Admin_Page instance with mocked dependencies.
 *
 * @return array{0: Admin_Page, 1: Mockery\MockInterface}
 */
function make_admin_page(): array {
    $queue = Mockery::mock(Queue::class);
    $queue_processor = Mockery::mock(Queue_Processor::class);
    $settings = Mockery::mock(Settings::class)->shouldIgnoreMissing();
    $logger = Mockery::mock(Logger::class)->shouldIgnoreMissing();
    return [new Admin_Page($queue, $queue_processor, $settings, $logger), $queue, $settings, $logger];
}

/**
 * Sets up the environment for render_page() tests.
 *
 * Mocks wpdb for Campaign_List_Table::prepare_items(), redefines
 * Plugin statics, and stubs WordPress functions needed during rendering.
 *
 * @return array{0: Admin_Page, 1: Mockery\MockInterface}
 */
function setup_render_env(): array {
    [$page, $queue, $settings, $logger] = make_admin_page();

    $wpdb = TestFactory::wpdb();
    $GLOBALS['wpdb'] = $wpdb;

    // Campaign_List_Table::prepare_items() query mocks.
    $wpdb->shouldReceive('prepare')->andReturn('SQL');
    $wpdb->shouldReceive('esc_like')->andReturnArg(0);
    $wpdb->shouldReceive('get_var')->andReturn(0);
    $wpdb->shouldReceive('get_results')->andReturn([]);

    \Patchwork\redefine(
        'Kntnt\Ad_Attribution\Plugin::get_slug',
        fn () => 'kntnt-ad-attr',
    );

    Functions\when('admin_url')->justReturn('https://example.com/wp-admin/tools.php');
    Functions\when('wp_nonce_field')->justReturn('');
    Functions\when('submit_button')->alias(function () {
        echo '<button>Submit</button>';
    });

    // Campaign_List_Table::get_default_date_range() stubs.
    Functions\when('get_option')->alias(fn (string $opt, mixed $default = false) => $opt === 'start_of_week' ? 1 : $default);

    \Patchwork\redefine('gmdate', function (string $format, ?int $ts = null) {
        if ($ts === null) {
            return match ($format) {
                'w' => '4',
                'U' => (string) strtotime('2026-02-26 12:00:00 UTC'),
                default => (new \DateTimeImmutable('2026-02-26', new \DateTimeZone('UTC')))->format($format),
            };
        }
        return (new \DateTimeImmutable("@{$ts}", new \DateTimeZone('UTC')))->format($format);
    });

    // Logging section stubs.
    $settings->shouldReceive('get')->with('enable_logging')->andReturn(false);
    $logger->shouldReceive('exists')->andReturn(false);
    $logger->shouldReceive('get_path')->andReturn('/tmp/kntnt-ad-attribution.log');
    $logger->shouldReceive('get_relative_path')->andReturn('uploads/kntnt-ad-attribution/kntnt-ad-attribution.log');
    Functions\when('wp_nonce_url')->justReturn('https://example.com/wp-admin/admin-post.php');
    Functions\when('checked')->justReturn('');
    Functions\when('disabled')->justReturn('');

    return [$page, $queue];
}

// ─── register() ───

describe('Admin_Page::register()', function () {

    it('registers admin_menu, enqueue_scripts, screen option, and admin_post hooks', function () {
        [$page] = make_admin_page();

        Actions\expectAdded('admin_menu')->once();
        Actions\expectAdded('admin_enqueue_scripts')->once();
        Filters\expectAdded('set-screen-option')->once();
        Actions\expectAdded('admin_post_kntnt_ad_attr_download_log')->once();
        Actions\expectAdded('admin_post_kntnt_ad_attr_clear_log')->once();

        $page->register();

        expect(true)->toBeTrue();
    });

});

// ─── save_screen_option() ───

describe('Admin_Page::save_screen_option()', function () {

    it('returns int value for Campaign per-page option', function () {
        [$page] = make_admin_page();

        $result = $page->save_screen_option(false, 'kntnt_ad_attr_campaigns_per_page', '50');

        expect($result)->toBe(50);
    });

    it('passes through unrecognized options', function () {
        [$page] = make_admin_page();

        $result = $page->save_screen_option(false, 'some_other_option', '25');

        expect($result)->toBeFalse();
    });

});

// ─── add_sri_attributes() ───

describe('Admin_Page::add_sri_attributes()', function () {

    it('adds integrity attribute to select2 script', function () {
        [$page] = make_admin_page();

        $tag = '<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>';

        $result = $page->add_sri_attributes($tag, 'select2');

        expect($result)->toContain('integrity=');
        expect($result)->toContain('crossorigin="anonymous"');
    });

    it('adds integrity attribute to select2 CSS', function () {
        [$page] = make_admin_page();

        $tag = '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">';

        $result = $page->add_sri_attributes($tag, 'select2');

        expect($result)->toContain('integrity=');
        expect($result)->toContain('crossorigin="anonymous"');
    });

    it('does not modify other script handles', function () {
        [$page] = make_admin_page();

        $tag = '<script src="https://example.com/other.js"></script>';

        $result = $page->add_sri_attributes($tag, 'jquery');

        expect($result)->toBe($tag);
    });

});

// ─── add_menu_page() ───

describe('Admin_Page::add_menu_page()', function () {

    it('calls add_management_page and hooks load action', function () {
        [$page] = make_admin_page();

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Plugin::get_slug',
            fn () => 'kntnt-ad-attribution',
        );

        Functions\expect('add_management_page')
            ->once()
            ->andReturn('tools_page_kntnt-ad-attribution');

        Actions\expectAdded('load-tools_page_kntnt-ad-attribution')->once();

        $page->add_menu_page();

        expect(true)->toBeTrue();
    });

    it('does not hook load action when page registration fails', function () {
        [$page] = make_admin_page();

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Plugin::get_slug',
            fn () => 'kntnt-ad-attribution',
        );

        // add_management_page returns false on failure, but the property
        // is typed string. The production code checks `if ($this->hook_suffix)`
        // which is falsy for ''. Return '' to simulate a falsy result.
        Functions\expect('add_management_page')
            ->once()
            ->andReturn('');

        // No load- action should be added for empty hook suffix.
        $page->add_menu_page();

        expect(true)->toBeTrue();
    });

});

// ─── enqueue_assets() ───

describe('Admin_Page::enqueue_assets()', function () {

    it('returns early for wrong hook suffix', function () {
        [$page] = make_admin_page();

        // hook_suffix is '' initially, so passing any string won't match.
        Functions\expect('wp_enqueue_style')->never();
        Functions\expect('wp_enqueue_script')->never();

        $page->enqueue_assets('some-other-page');

        expect(true)->toBeTrue();
    });

});

// ─── render_page() ───

describe('Admin_Page::render_page()', function () {

    beforeEach(function () {
        $_GET = [];
    });

    afterEach(function () {
        $_GET = [];
        unset($GLOBALS['wpdb']);
    });

    it('dispatches unknown tab to kntnt_ad_attr_admin_tab_{slug} action', function () {
        [$page, $queue] = setup_render_env();

        $_GET['tab'] = 'addons';

        // The unknown tab dispatches to an action.
        Actions\expectDone('kntnt_ad_attr_admin_tab_addons')->once();

        // No reporters → no queue status.
        Filters\expectApplied('kntnt_ad_attr_conversion_reporters')->andReturn([]);

        ob_start();
        $page->render_page();
        $output = ob_get_clean();

        expect($output)->toContain('Ad Attribution');
    });

    it('renders queue status when reporters registered', function () {
        [$page, $queue] = setup_render_env();

        // Reporters are registered → queue table is displayed.
        Filters\expectApplied('kntnt_ad_attr_conversion_reporters')->andReturn([
            'test-reporter' => fn () => null,
        ]);

        $queue->shouldReceive('get_active_jobs')->once()->andReturn([]);

        ob_start();
        $page->render_page();
        $output = ob_get_clean();

        expect($output)->toContain('Report Queue');
    });

    it('outputs Create Tracking URL button on main view', function () {
        [$page, $queue] = setup_render_env();

        // No reporters.
        Filters\expectApplied('kntnt_ad_attr_conversion_reporters')->andReturn([]);

        ob_start();
        $page->render_page();
        $output = ob_get_clean();

        expect($output)->toContain('Create Tracking URL');
    });

});

// ─── Conversion actions ───

/**
 * Exception standing in for wp_die(), which ends the request.
 */
class AdminDieException extends \RuntimeException {}

/**
 * Runs the page's load hook for a conversion action request and records
 * what happened.
 *
 * `$_GET` must hold the request. Nonce checks pass unless `$nonce_ok` is
 * false, the capability check passes unless `$can` is false, and the
 * queue reports `$jobs`. Returns what the handler did: the nonce actions it
 * checked, the prepared SQL statements [sql, args] it ran, the conversions
 * it asked the queue to clean up, and the URL it redirected to, or the
 * exception that ended the request.
 *
 * @param bool  $nonce_ok Whether check_admin_referer() accepts the nonce.
 * @param bool  $can      Whether the user has the kntnt_ad_attr capability.
 * @param array $jobs     What Queue::delete_unfinished_for_conversions() returns.
 * @param int   $found    How many of the conversions exist.
 *
 * @return \ArrayObject Recorded effects.
 */
function run_conversion_action(bool $nonce_ok = true, bool $can = true, array $jobs = ['deleted' => 0, 'processing' => 0], int $found = 1): \ArrayObject {
    [$page, $queue] = make_admin_page();
    $did = new \ArrayObject(['nonces' => [], 'statements' => [], 'cleaned' => [], 'redirect' => null, 'ended_by' => null]);

    $wpdb = TestFactory::wpdb();
    $GLOBALS['wpdb'] = $wpdb;
    $prepared = [];
    $wpdb->shouldReceive('prepare')->andReturnUsing(function ($sql, ...$args) use (&$prepared) {
        $prepared[] = [$sql, $args];
        return count($prepared) - 1;
    });
    $wpdb->shouldReceive('get_var')->andReturn((string) $found);
    $wpdb->shouldReceive('query')->andReturnUsing(function ($token) use (&$prepared, $did) {
        $did['statements'] = [...$did['statements'], $prepared[$token]];
        return 1;
    });

    $queue->shouldReceive('delete_unfinished_for_conversions')->andReturnUsing(function (array $refs) use ($did, $jobs) {
        $did['cleaned'] = [...$did['cleaned'], $refs];
        return $jobs;
    });

    \Patchwork\redefine('Kntnt\Ad_Attribution\Plugin::get_slug', fn () => 'kntnt-ad-attribution');
    Functions\when('check_admin_referer')->alias(function ($action) use ($did, $nonce_ok) {
        $did['nonces'] = [...$did['nonces'], $action];
        if (!$nonce_ok) {
            throw new AdminDieException('The link you followed has expired.');
        }
        return 1;
    });
    Functions\when('current_user_can')->alias(fn ($cap) => $cap === 'kntnt_ad_attr' ? $can : false);
    Functions\when('wp_die')->alias(function ($message) {
        throw new AdminDieException((string) $message);
    });
    Functions\when('admin_url')->alias(fn ($path = '') => 'https://example.com/wp-admin/' . $path);
    Functions\when('add_query_arg')->alias(fn (array $args, string $url) => $url . '?' . http_build_query($args));
    Functions\when('wp_safe_redirect')->alias(function ($url) {
        throw new AdminExitException($url);
    });
    Functions\when('add_screen_option')->justReturn(null);

    try {
        $page->add_screen_options();
    } catch (AdminExitException $e) {
        $did['redirect'] = $e->getMessage();
    } catch (AdminDieException $e) {
        $did['ended_by'] = $e;
    }

    return $did;
}

describe('Admin_Page conversion actions', function () {

    beforeEach(function () {
        $_GET = [];
    });

    afterEach(function () {
        $_GET = [];
        unset($GLOBALS['wpdb']);
    });

    it('deletes a conversion\'s rows and its unfinished jobs, keeping its clicks', function () {
        $_GET = [
            'action'     => 'conversion_delete',
            'conversion' => '8f14e45f-ceea-4e67-a1b2-c3d4e5f60718',
            'date_start' => '2024-03-01',
            'date_end'   => '2024-03-31',
        ];

        $did = run_conversion_action(jobs: ['deleted' => 2, 'processing' => 1]);

        expect($did['nonces'])->toBe(['conversion_delete_8f14e45f-ceea-4e67-a1b2-c3d4e5f60718']);
        expect($did['statements'])->toHaveCount(1);
        [$sql, $args] = $did['statements'][0];
        expect($sql)->toStartWith('DELETE FROM wp_kntnt_ad_attr_conversions');
        expect($sql)->toContain('conversion_ref IN (%s)');
        expect($args)->toBe(['8f14e45f-ceea-4e67-a1b2-c3d4e5f60718']);
        expect($did['cleaned'])->toBe([['8f14e45f-ceea-4e67-a1b2-c3d4e5f60718']]);

        parse_str((string) parse_url($did['redirect'], PHP_URL_QUERY), $back);
        expect($back)->toBe([
            'page'            => 'kntnt-ad-attribution',
            'message'         => 'conversions-deleted',
            'count'           => '1',
            'jobs_deleted'    => '2',
            'jobs_processing' => '1',
            'date_start'      => '2024-03-01',
            'date_end'        => '2024-03-31',
        ]);
    });

    it('marks a conversion\'s attributed clicks as test clicks and deletes its unfinished jobs', function () {
        $_GET = ['action' => 'conversion_mark_test', 'conversion' => 'ref-1'];

        $did = run_conversion_action(jobs: ['deleted' => 1, 'processing' => 0]);

        expect($did['nonces'])->toBe(['conversion_mark_test_ref-1']);
        expect($did['statements'])->toHaveCount(1);
        [$sql, $args] = $did['statements'][0];
        expect($sql)->toStartWith('UPDATE wp_kntnt_ad_attr_clicks SET is_test = %d');
        expect($sql)->toContain('WHERE id IN (SELECT click_id FROM wp_kntnt_ad_attr_conversions WHERE conversion_ref IN (%s))');
        expect($args)->toBe([1, 'ref-1']);
        expect($did['cleaned'])->toBe([['ref-1']]);
        expect($did['redirect'])->toContain('message=conversions-marked');
        expect($did['redirect'])->toContain('jobs_deleted=1');
    });

    it('unmarks a conversion\'s attributed clicks without touching its jobs', function () {
        $_GET = ['action' => 'conversion_unmark_test', 'conversion' => 'ref-1'];

        $did = run_conversion_action();

        expect($did['nonces'])->toBe(['conversion_unmark_test_ref-1']);
        [$sql, $args] = $did['statements'][0];
        expect($sql)->toStartWith('UPDATE wp_kntnt_ad_attr_clicks SET is_test = %d');
        expect($args)->toBe([0, 'ref-1']);
        expect($did['cleaned'])->toBe([]);
        expect($did['redirect'])->toContain('message=conversions-unmarked');
    });

    it('applies a bulk action to every selected conversion under the list\'s bulk nonce', function () {
        $_GET = [
            'action'     => '-1',
            'action2'    => 'conversion_delete',
            'conversion' => ['ref-1', 'ref-2', 'not a reference', 'ref-1'],
        ];

        $did = run_conversion_action(found: 2);

        expect($did['nonces'])->toBe(['bulk-conversions']);
        [$sql, $args] = $did['statements'][0];
        expect($sql)->toContain('conversion_ref IN (%s,%s)');
        expect($args)->toBe(['ref-1', 'ref-2']);
        expect($did['cleaned'])->toBe([['ref-1', 'ref-2']]);
        expect($did['redirect'])->toContain('count=2');
    });

    it('refuses an action without a valid nonce', function (array $request) {
        $_GET = $request;

        $did = run_conversion_action(nonce_ok: false);

        expect($did['ended_by'])->toBeInstanceOf(AdminDieException::class);
        expect($did['statements'])->toBe([]);
        expect($did['cleaned'])->toBe([]);
        expect($did['redirect'])->toBeNull();
    })->with([
        'row delete'      => [['action' => 'conversion_delete', 'conversion' => 'ref-1']],
        'row mark'        => [['action' => 'conversion_mark_test', 'conversion' => 'ref-1']],
        'row unmark'      => [['action' => 'conversion_unmark_test', 'conversion' => 'ref-1']],
        'bulk delete'     => [['action' => 'conversion_delete', 'conversion' => ['ref-1']]],
        'bulk mark'       => [['action' => 'conversion_mark_test', 'conversion' => ['ref-1']]],
    ]);

    it('refuses an action without the kntnt_ad_attr capability', function (array $request) {
        $_GET = $request;

        $did = run_conversion_action(can: false);

        expect($did['ended_by'])->toBeInstanceOf(AdminDieException::class);
        expect($did['statements'])->toBe([]);
        expect($did['cleaned'])->toBe([]);
        expect($did['redirect'])->toBeNull();
    })->with([
        'row delete'  => [['action' => 'conversion_delete', 'conversion' => 'ref-1']],
        'row mark'    => [['action' => 'conversion_mark_test', 'conversion' => 'ref-1']],
        'bulk unmark' => [['action' => 'conversion_unmark_test', 'conversion' => ['ref-1']]],
    ]);

});

describe('Admin_Page conversions on the main view', function () {

    beforeEach(function () {
        $_GET = [];
    });

    afterEach(function () {
        $_GET = [];
        unset($GLOBALS['wpdb']);
    });

    it('lists the recorded conversions below the tracking URLs', function () {
        [$page] = setup_render_env();
        Filters\expectApplied('kntnt_ad_attr_conversion_reporters')->andReturn([]);

        ob_start();
        $page->render_page();
        $output = ob_get_clean();

        expect($output)->toContain('<h3>Conversions</h3>');
        expect($output)->toContain('class="kntnt-ad-attr-conversions"');
    });

    it('reports what a conversion action did, and the jobs it left alone because they were processing', function () {
        [$page] = setup_render_env();
        Filters\expectApplied('kntnt_ad_attr_conversion_reporters')->andReturn([]);
        $_GET = ['message' => 'conversions-deleted', 'count' => '1', 'jobs_deleted' => '2', 'jobs_processing' => '1'];

        ob_start();
        $page->render_page();
        $output = ob_get_clean();

        expect($output)->toContain('1 conversion(s) deleted. 2 unfinished report job(s) deleted.');
        expect($output)->toContain('notice-warning');
        expect($output)->toContain('1 report job(s) still processing were left untouched.');
    });

});
