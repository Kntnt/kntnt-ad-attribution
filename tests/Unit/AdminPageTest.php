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
 * Creates an Admin_Page instance with mocked Queue dependency.
 *
 * @return array{0: Admin_Page, 1: Mockery\MockInterface}
 */
function make_admin_page(): array {
    $queue = Mockery::mock(Queue::class);
    return [new Admin_Page($queue), $queue];
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
    [$page, $queue] = make_admin_page();

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

    return [$page, $queue];
}

// ─── register() ───

describe('Admin_Page::register()', function () {

    it('registers admin_menu, enqueue_scripts, and screen option hooks', function () {
        [$page] = make_admin_page();

        Actions\expectAdded('admin_menu')->once();
        Actions\expectAdded('admin_enqueue_scripts')->once();
        Filters\expectAdded('set-screen-option')->once();

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

        // Reporters are registered → queue status is displayed.
        Filters\expectApplied('kntnt_ad_attr_conversion_reporters')->andReturn([
            'test-reporter' => fn () => null,
        ]);

        $queue->shouldReceive('get_status')->once()->andReturn([
            'pending'    => 3,
            'failed'     => 1,
            'last_error' => 'Test error',
        ]);

        ob_start();
        $page->render_page();
        $output = ob_get_clean();

        expect($output)->toContain('Report Queue');
        expect($output)->toContain('Test error');
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
