<?php
/**
 * Unit tests for Conversion_List_Table.
 *
 * @package Tests\Unit
 * @since   1.10.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution\Conversion_List_Table;
use Kntnt\Ad_Attribution\Queue;
use Brain\Monkey\Functions;
use Tests\Helpers\TestFactory;

/**
 * Builds a Conversion_List_Table over a fake database.
 *
 * The page query (the one with GROUP BY) returns `$page`; the query for the
 * page's conversion rows returns those rows of `$rows` whose reference is
 * among its arguments. Every prepared statement is recorded in `$prepared`
 * as [sql, args].
 *
 * @param array<int, object> $page     Rows of the page query: conversion_ref, converted_at.
 * @param array<int, object> $rows     Conversion rows with click and campaign fields.
 * @param int                $total    Number of conversions the count query reports.
 * @param array              $jobs     What Queue::count_jobs_for_conversions() returns.
 * @param array              $prepared Receives every prepared statement.
 *
 * @return array{0: Conversion_List_Table, 1: Mockery\MockInterface} Table and Queue mock.
 */
function make_conversion_list(array $page, array $rows = [], int $total = 0, array $jobs = [], ?array &$prepared = null): array {
    $prepared = [];

    $wpdb = TestFactory::wpdb();
    $GLOBALS['wpdb'] = $wpdb;

    $wpdb->shouldReceive('prepare')->andReturnUsing(function ($sql, ...$args) use (&$prepared) {
        $prepared[] = [$sql, $args];
        return count($prepared) - 1;
    });
    $wpdb->shouldReceive('get_var')->andReturn((string) $total);
    $wpdb->shouldReceive('get_results')->andReturnUsing(function ($token) use (&$prepared, $page, $rows) {
        [$sql, $args] = $prepared[$token];
        if (str_contains($sql, 'GROUP BY')) {
            return $page;
        }
        return array_values(array_filter($rows, fn ($row) => in_array($row->conversion_ref, $args, true)));
    });

    $queue = Mockery::mock(Queue::class);
    $queue->shouldReceive('count_jobs_for_conversions')->andReturn($jobs)->byDefault();

    return [new Conversion_List_Table($queue, '2024-03-01', '2024-03-31'), $queue];
}

/**
 * A conversion row as the row query returns it.
 *
 * @param string $ref    Conversion reference.
 * @param array  $fields Fields overriding the defaults.
 *
 * @return object Row object.
 */
function conversion_row(string $ref, array $fields = []): object {
    return (object) ($fields + [
        'conversion_ref'        => $ref,
        'converted_at'          => '2024-03-10 12:00:00',
        'fractional_conversion' => '1.0000',
        'clicked_at'            => '2024-03-10 11:00:00',
        'utm_content'           => null,
        'utm_term'              => null,
        'is_test'               => '0',
        'utm_source'            => 'google',
        'utm_medium'            => 'cpc',
        'utm_campaign'          => 'spring',
    ]);
}

/**
 * Calls a protected method of the table, as WP_List_Table does when it renders.
 *
 * @param Conversion_List_Table $table  The table.
 * @param string                $method Method name.
 * @param mixed                 ...$args Arguments.
 *
 * @return mixed The method's return value.
 */
function render_conversion_list(Conversion_List_Table $table, string $method, mixed ...$args): mixed {
    return (new \ReflectionMethod($table, $method))->invoke($table, ...$args);
}

// ─── prepare_items() ───

describe('Conversion_List_Table::prepare_items()', function () {

    afterEach(function () {
        unset($GLOBALS['wpdb']);
        $_GET = [];
    });

    it('lists one item per conversion, newest first, with its attributed clicks and report jobs', function () {
        [$table] = make_conversion_list(
            page: [
                (object) ['conversion_ref' => 'ref-new', 'converted_at' => '2024-03-12 09:00:00'],
                (object) ['conversion_ref' => 'ref-old', 'converted_at' => '2024-03-02 09:00:00'],
            ],
            rows: [
                conversion_row('ref-new', ['fractional_conversion' => '0.6000', 'utm_campaign' => 'a']),
                conversion_row('ref-new', ['fractional_conversion' => '0.4000', 'utm_campaign' => 'b', 'is_test' => '1']),
                conversion_row('ref-old'),
            ],
            total: 2,
            jobs: ['ref-new' => ['pending' => 1, 'done' => 1]],
            prepared: $prepared,
        );

        $table->prepare_items();

        expect(array_column($table->items, 'conversion_ref'))->toBe(['ref-new', 'ref-old']);
        expect(array_map(fn ($row) => $row->utm_campaign, $table->items[0]->attributions))->toBe(['a', 'b']);
        expect($table->items[0]->is_test)->toBeTrue();
        expect($table->items[0]->jobs)->toBe(['pending' => 1, 'done' => 1]);
        expect($table->items[1]->attributions)->toHaveCount(1);
        expect($table->items[1]->is_test)->toBeFalse();
        expect($table->items[1]->jobs)->toBe([]);

        // The page query groups the rows of a conversion, puts the newest
        // first and selects by the attributed click's time, like the main view.
        [$page_sql, $page_args] = array_values(array_filter($prepared, fn ($p) => str_contains($p[0], 'GROUP BY')))[0];
        expect($page_sql)->toContain('GROUP BY cv.conversion_ref');
        expect($page_sql)->toContain('ORDER BY converted_at DESC');
        expect($page_sql)->toContain('c.clicked_at BETWEEN %s AND %s');
        expect(array_slice($page_args, 0, 2))->toBe(['2024-03-01 00:00:00', '2024-03-31 23:59:59']);
    });

    it('pages with its own query argument, not the main view\'s', function () {
        $_GET['paged']             = '2';
        $_GET['conversions_paged'] = '3';

        [$table] = make_conversion_list(page: [], total: 100, prepared: $prepared);

        $table->prepare_items();

        [, $page_args] = array_values(array_filter($prepared, fn ($p) => str_contains($p[0], 'GROUP BY')))[0];
        expect(array_slice($page_args, -2))->toBe([20, 40]);
        expect($table->get_pagenum())->toBe(3);
    });

    it('asks for no rows or jobs when the page is empty', function () {
        [$table, $queue] = make_conversion_list(page: [], total: 0, prepared: $prepared);
        $queue->shouldNotReceive('count_jobs_for_conversions');

        $table->prepare_items();

        expect($table->items)->toBe([]);
        expect(array_filter($prepared, fn ($p) => str_contains($p[0], 'IN (')))->toBe([]);
    });

});

// ─── Rendering ───

describe('Conversion_List_Table rendering', function () {

    beforeEach(function () {
        \Patchwork\redefine('Kntnt\Ad_Attribution\Plugin::get_slug', fn () => 'kntnt-ad-attribution');
        Functions\when('admin_url')->alias(fn ($path = '') => 'https://example.com/wp-admin/' . $path);
        Functions\when('wp_nonce_url')->alias(fn ($url, $action) => $url . '&_wpnonce=' . $action);
        Functions\when('wp_date')->alias(fn ($format, $ts) => gmdate($format, $ts));
        Functions\when('number_format_i18n')->alias(fn ($n, $d = 0) => number_format((float) $n, $d));
    });

    afterEach(function () {
        unset($GLOBALS['wpdb']);
        $_GET = [];
    });

    /**
     * Loads one page holding the given conversion rows and returns the table
     * and its single item.
     */
    $load = function (array $rows, array $jobs = []): array {
        [$table] = make_conversion_list(
            page: [(object) ['conversion_ref' => 'ref-1', 'converted_at' => '2024-03-10 12:00:00']],
            rows: $rows,
            total: 1,
            jobs: $jobs,
        );
        $table->prepare_items();
        return [$table, $table->items[0]];
    };

    it('selects conversions with its own checkbox name and action slugs, clear of the tracking URLs\' post[] and trash/restore/delete', function () use ($load) {
        [$table, $item] = $load([conversion_row('ref-1')]);

        $checkbox = render_conversion_list($table, 'column_cb', $item);
        $actions  = render_conversion_list($table, 'get_bulk_actions');

        expect($checkbox)->toContain('name="conversion[]"');
        expect($checkbox)->toContain('value="ref-1"');
        expect(array_keys($actions))->toBe(['conversion_mark_test', 'conversion_unmark_test', 'conversion_delete']);
    });

    it('offers Mark as test and Delete for an ordinary conversion, each with its own nonce', function () use ($load) {
        [$table, $item] = $load([conversion_row('ref-1')]);

        expect(render_conversion_list($table, 'column_converted_at', $item))->toBe('2024-03-10 12:00');

        $html = render_conversion_list($table, 'handle_row_actions', $item, 'converted_at', 'converted_at');

        expect($html)->toContain('action=conversion_mark_test&conversion=ref-1');
        expect($html)->toContain('_wpnonce=conversion_mark_test_ref-1');
        expect($html)->toContain('action=conversion_delete&conversion=ref-1');
        expect($html)->toContain('_wpnonce=conversion_delete_ref-1');
        expect($html)->toContain('date_start=2024-03-01&date_end=2024-03-31');
        expect($html)->not->toContain('conversion_unmark_test');
    });

    it('offers Unmark instead of Mark as test for a test conversion', function () use ($load) {
        [$table, $item] = $load([conversion_row('ref-1', ['is_test' => '1'])]);

        $html = render_conversion_list($table, 'handle_row_actions', $item, 'converted_at', 'converted_at');

        expect($html)->toContain('action=conversion_unmark_test&conversion=ref-1');
        expect($html)->toContain('_wpnonce=conversion_unmark_test_ref-1');
        expect($html)->not->toContain('action=conversion_mark_test');
    });

    it('puts the row actions only under the conversion time', function () use ($load) {
        [$table, $item] = $load([conversion_row('ref-1')]);

        expect(render_conversion_list($table, 'handle_row_actions', $item, 'tracking_url', 'converted_at'))->toBe('');
    });

    it('shows each attributed click of a multi-touch conversion on its own line', function () use ($load) {
        [$table, $item] = $load([
            conversion_row('ref-1', ['fractional_conversion' => '0.7500', 'utm_campaign' => 'spring', 'utm_content' => 'banner', 'clicked_at' => '2024-03-09 08:30:00']),
            conversion_row('ref-1', ['fractional_conversion' => '0.2500', 'utm_source' => 'meta', 'utm_medium' => 'paid', 'utm_campaign' => 'retarget', 'utm_term' => 'shoes', 'is_test' => '1']),
        ]);

        expect(render_conversion_list($table, 'column_tracking_url', $item))->toBe('google / cpc / spring<br>meta / paid / retarget');
        expect(render_conversion_list($table, 'column_clicked_at', $item))->toBe('2024-03-09 08:30<br>2024-03-10 11:00');
        expect(render_conversion_list($table, 'column_utm_content', $item))->toBe('banner<br>—');
        expect(render_conversion_list($table, 'column_utm_term', $item))->toBe('—<br>shoes');
        expect(render_conversion_list($table, 'column_weight', $item))->toBe('0.75<br>0.25');
        expect(render_conversion_list($table, 'column_is_test', $item))->toBe('No<br>Yes');
    });

    it('marks a click whose tracking URL is gone as deleted', function () use ($load) {
        [$table, $item] = $load([conversion_row('ref-1', ['utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null])]);

        expect(render_conversion_list($table, 'column_tracking_url', $item))->toBe('<em>(deleted)</em>');
    });

    it('sums up the conversion\'s report jobs per status', function () use ($load) {
        [$table, $item] = $load([conversion_row('ref-1')], ['ref-1' => ['done' => 2, 'failed' => 1, 'pending' => 1, 'processing' => 1]]);

        expect(render_conversion_list($table, 'column_jobs', $item))->toBe('1 pending, 1 processing, 2 done, 1 failed');
    });

    it('shows a dash for a conversion without report jobs', function () use ($load) {
        [$table, $item] = $load([conversion_row('ref-1')]);

        expect(render_conversion_list($table, 'column_jobs', $item))->toBe('—');
    });

    it('links to the other pages with its own query argument, dropping action notices', function () {
        $_GET['conversions_paged'] = '2';
        $_SERVER['REQUEST_URI']    = '/wp-admin/tools.php?page=kntnt-ad-attribution&paged=4&message=conversions-deleted&count=1';

        // Without a URL, remove_query_arg() works on the current request URI.
        Functions\when('wp_removable_query_args')->justReturn(['message']);
        Functions\when('remove_query_arg')->alias(function (array $keys, string|false $url = false) {
            [$base, $query] = explode('?', $url === false ? $_SERVER['REQUEST_URI'] : $url, 2);
            parse_str($query, $args);
            return $base . '?' . http_build_query(array_diff_key($args, array_flip($keys)));
        });
        Functions\when('add_query_arg')->alias(fn ($key, $value, $url) => $url . '&' . $key . '=' . $value);
        Functions\when('_n')->alias(fn ($single, $plural, $n) => $n === 1 ? $single : $plural);

        [$table] = make_conversion_list(page: [], total: 45);
        $table->prepare_items();

        ob_start();
        render_conversion_list($table, 'pagination', 'top');
        $html = ob_get_clean();

        expect($html)->toContain('45 conversions');
        expect($html)->toContain('2 of 3');
        expect($html)->toContain('/wp-admin/tools.php?page=kntnt-ad-attribution&paged=4&conversions_paged=1"');
        expect($html)->toContain('/wp-admin/tools.php?page=kntnt-ad-attribution&paged=4&conversions_paged=3"');
        expect($html)->not->toContain('message=');
        expect($html)->not->toContain('count=');

        unset($_SERVER['REQUEST_URI']);
    });

});
