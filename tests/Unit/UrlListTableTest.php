<?php
/**
 * Unit tests for Url_List_Table.
 *
 * @package Tests\Unit
 * @since   1.0.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution\Url_List_Table;
use Kntnt\Ad_Attribution\Post_Type;
use Brain\Monkey\Functions;
use Tests\Helpers\TestFactory;

/**
 * Calls a protected method on an object via reflection.
 *
 * @param object $object     The object instance.
 * @param string $method     Method name.
 * @param array  $args       Arguments to pass.
 *
 * @return mixed
 */
function call_protected(object $object, string $method, array $args = []): mixed {
    $ref = new \ReflectionMethod($object, $method);
    $ref->setAccessible(true);
    return $ref->invoke($object, ...$args);
}

// ─── get_columns() ───

describe('Url_List_Table::get_columns()', function () {

    it('returns expected column keys', function () {
        $table   = new Url_List_Table();
        $columns = $table->get_columns();

        expect($columns)->toHaveKeys([
            'cb',
            'tracking_url',
            'target_url',
            'utm_source',
            'utm_medium',
            'utm_campaign',
        ]);

        expect($columns)->toHaveCount(6);
    });

});

// ─── get_sortable_columns() ───

describe('Url_List_Table::get_sortable_columns()', function () {

    it('returns expected sortable keys', function () {
        $table = new Url_List_Table();

        $sortable = call_protected($table, 'get_sortable_columns');

        expect($sortable)->toHaveKeys([
            'utm_source',
            'utm_medium',
            'utm_campaign',
        ]);

        // Sortable columns should NOT include tracking_url or target_url.
        expect($sortable)->not->toHaveKey('tracking_url');
        expect($sortable)->not->toHaveKey('target_url');
    });

});

// ─── get_bulk_actions() ───

describe('Url_List_Table::get_bulk_actions()', function () {

    afterEach(function () {
        $_GET = [];
    });

    it('returns trash action for All view', function () {
        $_GET = [];

        $table   = new Url_List_Table();
        $actions = call_protected($table, 'get_bulk_actions');

        expect($actions)->toHaveKey('trash');
        expect($actions)->not->toHaveKey('restore');
        expect($actions)->not->toHaveKey('delete');
    });

    it('returns restore and delete actions for Trash view', function () {
        $_GET['post_status'] = 'trash';

        $table   = new Url_List_Table();
        $actions = call_protected($table, 'get_bulk_actions');

        expect($actions)->toHaveKey('restore');
        expect($actions)->toHaveKey('delete');
        expect($actions)->not->toHaveKey('trash');
    });

});

// ─── prepare_items() (SQL construction) ───

describe('Url_List_Table::prepare_items()', function () {

    afterEach(function () {
        unset($GLOBALS['wpdb']);
        $_GET = [];
    });

    it('builds SQL with UTM filter WHERE clause', function () {
        $_GET['utm_source'] = 'google';

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $captured_sql = [];

        $wpdb->shouldReceive('esc_like')->andReturnArg(0);

        // Capture all prepare() calls to inspect SQL.
        $wpdb->shouldReceive('prepare')->andReturnUsing(function () use (&$captured_sql) {
            $args = func_get_args();
            $captured_sql[] = $args[0];
            return 'SQL';
        });

        $wpdb->shouldReceive('get_var')->once()->andReturn(0);
        $wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $table = new Url_List_Table();
        $table->prepare_items();

        // At least one SQL should contain the UTM filter.
        $has_filter = false;
        foreach ($captured_sql as $sql) {
            if (str_contains($sql, 'pm_src.meta_value = %s')) {
                $has_filter = true;
                break;
            }
        }

        expect($has_filter)->toBeTrue();
    });

    it('builds SQL with search LIKE clause', function () {
        $_GET['s'] = 'test-search';

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $captured_sql = [];

        $wpdb->shouldReceive('esc_like')->andReturnArg(0);

        $wpdb->shouldReceive('prepare')->andReturnUsing(function () use (&$captured_sql) {
            $args = func_get_args();
            $captured_sql[] = $args[0];
            return 'SQL';
        });

        $wpdb->shouldReceive('get_var')->once()->andReturn(0);
        $wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $table = new Url_List_Table();
        $table->prepare_items();

        // SQL should contain LIKE clause for search.
        $has_like = false;
        foreach ($captured_sql as $sql) {
            if (str_contains($sql, 'LIKE %s')) {
                $has_like = true;
                break;
            }
        }

        expect($has_like)->toBeTrue();
    });

    it('uses trash status when post_status is trash', function () {
        $_GET['post_status'] = 'trash';

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $captured_params = [];

        $wpdb->shouldReceive('esc_like')->andReturnArg(0);

        $wpdb->shouldReceive('prepare')->andReturnUsing(function () use (&$captured_params) {
            $args = func_get_args();
            $captured_params[] = $args;
            return 'SQL';
        });

        $wpdb->shouldReceive('get_var')->once()->andReturn(0);
        $wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $table = new Url_List_Table();
        $table->prepare_items();

        // The status parameter should be 'trash'.
        $found_trash = false;
        foreach ($captured_params as $call) {
            foreach ($call as $param) {
                if ($param === 'trash') {
                    $found_trash = true;
                    break 2;
                }
            }
        }

        expect($found_trash)->toBeTrue();
    });

    it('whitelists orderby parameter', function () {
        $_GET['orderby'] = 'utm_source';
        $_GET['order']   = 'ASC';

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $captured_sql = [];

        $wpdb->shouldReceive('esc_like')->andReturnArg(0);
        $wpdb->shouldReceive('prepare')->andReturnUsing(function () use (&$captured_sql) {
            $args = func_get_args();
            $captured_sql[] = $args[0];
            return 'SQL';
        });

        $wpdb->shouldReceive('get_var')->once()->andReturn(0);
        $wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $table = new Url_List_Table();
        $table->prepare_items();

        // The SELECT query should contain ORDER BY with the mapped column.
        $has_order = false;
        foreach ($captured_sql as $sql) {
            if (str_contains($sql, 'ORDER BY') && str_contains($sql, 'pm_src.meta_value') && str_contains($sql, 'ASC')) {
                $has_order = true;
                break;
            }
        }

        expect($has_order)->toBeTrue();
    });

    it('defaults to DESC order for invalid order param', function () {
        $_GET['orderby'] = 'utm_campaign';
        $_GET['order']   = 'INVALID';

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $captured_sql = [];

        $wpdb->shouldReceive('esc_like')->andReturnArg(0);
        $wpdb->shouldReceive('prepare')->andReturnUsing(function () use (&$captured_sql) {
            $args = func_get_args();
            $captured_sql[] = $args[0];
            return 'SQL';
        });

        $wpdb->shouldReceive('get_var')->once()->andReturn(0);
        $wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $table = new Url_List_Table();
        $table->prepare_items();

        // Should default to DESC.
        $has_desc = false;
        foreach ($captured_sql as $sql) {
            if (str_contains($sql, 'ORDER BY') && str_contains($sql, 'DESC')) {
                $has_desc = true;
                break;
            }
        }

        expect($has_desc)->toBeTrue();
    });

    it('populates items from query results', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $wpdb->shouldReceive('esc_like')->andReturnArg(0);
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_var')->once()->andReturn(2);
        $wpdb->shouldReceive('get_results')->once()->andReturn([
            (object) ['ID' => 1, 'tracking_url' => 'https://example.com/ad/abc', 'utm_source' => 'google'],
            (object) ['ID' => 2, 'tracking_url' => 'https://example.com/ad/def', 'utm_source' => 'facebook'],
        ]);

        $table = new Url_List_Table();
        $table->prepare_items();

        expect($table->items)->toHaveCount(2);
    });

});
