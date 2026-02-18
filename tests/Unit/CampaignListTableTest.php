<?php
/**
 * Unit tests for Campaign_List_Table.
 *
 * @package Tests\Unit
 * @since   1.0.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution\Campaign_List_Table;
use Kntnt\Ad_Attribution\Post_Type;
use Brain\Monkey\Functions;
use Tests\Helpers\TestFactory;

// ─── get_columns() ───

describe('Campaign_List_Table::get_columns()', function () {

    it('returns expected column keys', function () {
        $table   = new Campaign_List_Table();
        $columns = $table->get_columns();

        expect($columns)->toHaveKeys([
            'tracking_url',
            'target_url',
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'total_clicks',
            'total_conversions',
        ]);

        expect($columns)->toHaveCount(7);
    });

});

// ─── get_sortable_columns() ───

describe('Campaign_List_Table::get_sortable_columns()', function () {

    it('returns expected sortable keys', function () {
        $table = new Campaign_List_Table();

        $ref = new \ReflectionMethod($table, 'get_sortable_columns');
        $ref->setAccessible(true);
        $sortable = $ref->invoke($table);

        expect($sortable)->toHaveKeys([
            'total_clicks',
            'total_conversions',
            'utm_source',
            'utm_medium',
            'utm_campaign',
        ]);

        // total_clicks and total_conversions sort DESC first.
        expect($sortable['total_clicks'][1])->toBeTrue();
        expect($sortable['total_conversions'][1])->toBeTrue();

        // UTM columns sort ASC first.
        expect($sortable['utm_source'][1])->toBeFalse();
    });

});

// ─── get_filter_params() ───

describe('Campaign_List_Table::get_filter_params()', function () {

    afterEach(function () {
        $_GET = [];
    });

    it('returns default dates for empty input', function () {
        $_GET = [];

        $table  = new Campaign_List_Table();
        $params = $table->get_filter_params();

        expect($params['date_start'])->toBe('1970-01-01');
        expect($params['date_end'])->toBe('9999-12-31');
    });

    it('accepts valid ISO-8601 dates', function () {
        $_GET['date_start'] = '2024-06-01';
        $_GET['date_end']   = '2024-12-31';

        $table  = new Campaign_List_Table();
        $params = $table->get_filter_params();

        expect($params['date_start'])->toBe('2024-06-01');
        expect($params['date_end'])->toBe('2024-12-31');
    });

    it('falls back to defaults for invalid date format', function () {
        $_GET['date_start'] = 'not-a-date';
        $_GET['date_end']   = '2024/12/31';

        $table  = new Campaign_List_Table();
        $params = $table->get_filter_params();

        expect($params['date_start'])->toBe('1970-01-01');
        expect($params['date_end'])->toBe('9999-12-31');
    });

    it('falls back for partial date (missing day)', function () {
        $_GET['date_start'] = '2024-06';

        $table  = new Campaign_List_Table();
        $params = $table->get_filter_params();

        expect($params['date_start'])->toBe('1970-01-01');
    });

    it('passes through UTM filter values', function () {
        $_GET['utm_source']   = 'google';
        $_GET['utm_medium']   = 'cpc';
        $_GET['utm_campaign'] = 'summer';
        $_GET['s']            = 'search term';

        $table  = new Campaign_List_Table();
        $params = $table->get_filter_params();

        expect($params['utm_source'])->toBe('google');
        expect($params['utm_medium'])->toBe('cpc');
        expect($params['utm_campaign'])->toBe('summer');
        expect($params['search'])->toBe('search term');
    });

    it('returns empty strings for missing UTM filters', function () {
        $_GET = [];

        $table  = new Campaign_List_Table();
        $params = $table->get_filter_params();

        expect($params['utm_source'])->toBe('');
        expect($params['utm_medium'])->toBe('');
        expect($params['utm_campaign'])->toBe('');
        expect($params['search'])->toBe('');
    });

});

// ─── prepare_items() (SQL construction) ───

describe('Campaign_List_Table::prepare_items()', function () {

    afterEach(function () {
        unset($GLOBALS['wpdb']);
        $_GET = [];
    });

    it('uses GROUP BY for aggregation', function () {
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

        $table = new Campaign_List_Table();
        $table->prepare_items();

        // The SELECT query should include GROUP BY.
        $has_group = false;
        foreach ($captured_sql as $sql) {
            if (str_contains($sql, 'GROUP BY')) {
                $has_group = true;
                break;
            }
        }

        expect($has_group)->toBeTrue();
    });

    it('joins clicks and conversions tables', function () {
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

        $table = new Campaign_List_Table();
        $table->prepare_items();

        // Should reference both custom tables.
        $has_clicks = false;
        $has_conversions = false;
        foreach ($captured_sql as $sql) {
            if (str_contains($sql, 'kntnt_ad_attr_clicks')) {
                $has_clicks = true;
            }
            if (str_contains($sql, 'kntnt_ad_attr_conversions')) {
                $has_conversions = true;
            }
        }

        expect($has_clicks)->toBeTrue();
        expect($has_conversions)->toBeTrue();
    });

    it('uses BETWEEN for date range', function () {
        $_GET['date_start'] = '2024-01-01';
        $_GET['date_end']   = '2024-12-31';

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

        $table = new Campaign_List_Table();
        $table->prepare_items();

        // Should use BETWEEN for date filtering.
        $has_between = false;
        foreach ($captured_sql as $sql) {
            if (str_contains($sql, 'BETWEEN')) {
                $has_between = true;
                break;
            }
        }

        expect($has_between)->toBeTrue();
    });

});

// ─── fetch_all_items() ───

describe('Campaign_List_Table::fetch_all_items()', function () {

    afterEach(function () {
        unset($GLOBALS['wpdb']);
        $_GET = [];
    });

    it('includes per-click UTM fields in query', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $captured_sql = [];

        $wpdb->shouldReceive('esc_like')->andReturnArg(0);
        $wpdb->shouldReceive('prepare')->andReturnUsing(function () use (&$captured_sql) {
            $args = func_get_args();
            $captured_sql[] = $args[0];
            return 'SQL';
        });

        $wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $table = new Campaign_List_Table();
        $table->fetch_all_items();

        // Should include per-click UTM fields in SELECT.
        $has_per_click = false;
        foreach ($captured_sql as $sql) {
            if (
                str_contains($sql, 'c.utm_content') &&
                str_contains($sql, 'c.utm_term') &&
                str_contains($sql, 'c.utm_id') &&
                str_contains($sql, 'c.utm_source_platform')
            ) {
                $has_per_click = true;
                break;
            }
        }

        expect($has_per_click)->toBeTrue();
    });

    it('groups by per-click UTM fields in CSV query', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $captured_sql = [];

        $wpdb->shouldReceive('esc_like')->andReturnArg(0);
        $wpdb->shouldReceive('prepare')->andReturnUsing(function () use (&$captured_sql) {
            $args = func_get_args();
            $captured_sql[] = $args[0];
            return 'SQL';
        });

        $wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $table = new Campaign_List_Table();
        $table->fetch_all_items();

        // GROUP BY should include per-click dimensions.
        $has_group = false;
        foreach ($captured_sql as $sql) {
            if (
                str_contains($sql, 'GROUP BY') &&
                str_contains($sql, 'c.utm_content') &&
                str_contains($sql, 'c.utm_source_platform')
            ) {
                $has_group = true;
                break;
            }
        }

        expect($has_group)->toBeTrue();
    });

    it('returns empty array when no results', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $wpdb->shouldReceive('esc_like')->andReturnArg(0);
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_results')->once()->andReturn(null);

        $table  = new Campaign_List_Table();
        $result = $table->fetch_all_items();

        expect($result)->toBe([]);
    });

});

// ─── get_totals() ───

describe('Campaign_List_Table::get_totals()', function () {

    afterEach(function () {
        unset($GLOBALS['wpdb']);
        $_GET = [];
    });

    it('returns totals from database', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $wpdb->shouldReceive('esc_like')->andReturnArg(0);
        $wpdb->shouldReceive('prepare')->andReturn('SQL');

        $totals = (object) ['total_clicks' => 100, 'total_conversions' => 12.5];
        $wpdb->shouldReceive('get_row')->once()->andReturn($totals);

        $table  = new Campaign_List_Table();
        $result = $table->get_totals();

        expect($result->total_clicks)->toBe(100);
        expect($result->total_conversions)->toBe(12.5);
    });

    it('caches result on second call', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $wpdb->shouldReceive('esc_like')->andReturnArg(0);
        $wpdb->shouldReceive('prepare')->andReturn('SQL');

        $totals = (object) ['total_clicks' => 50, 'total_conversions' => 5.0];

        // get_row should be called only ONCE, even though get_totals is called twice.
        $wpdb->shouldReceive('get_row')->once()->andReturn($totals);

        $table = new Campaign_List_Table();

        $first  = $table->get_totals();
        $second = $table->get_totals();

        expect($first)->toBe($second);
        expect($first->total_clicks)->toBe(50);
    });

    it('omits pm_target JOIN from totals query', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $captured_sql = [];

        $wpdb->shouldReceive('esc_like')->andReturnArg(0);
        $wpdb->shouldReceive('prepare')->andReturnUsing(function () use (&$captured_sql) {
            $args = func_get_args();
            $captured_sql[] = $args[0];
            return 'SQL';
        });

        $wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $table = new Campaign_List_Table();
        $table->get_totals();

        // Totals query should NOT have pm_target JOIN.
        $has_target_join = false;
        foreach ($captured_sql as $sql) {
            if (str_contains($sql, 'COUNT(DISTINCT c.id)') && str_contains($sql, '_target_post_id')) {
                $has_target_join = true;
            }
        }

        expect($has_target_join)->toBeFalse();
    });

});
