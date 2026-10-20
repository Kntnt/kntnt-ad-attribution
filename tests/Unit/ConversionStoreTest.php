<?php
/**
 * Unit tests for Conversion_Store.
 *
 * @package Tests\Unit
 * @since   1.10.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution\Conversion_Store;
use Tests\Helpers\TestFactory;

/**
 * Installs a mock $wpdb that records every prepared statement.
 *
 * `prepare()` returns the statement's index in the record, so the query
 * methods can tell the statements apart.
 *
 * @return array{0: Mockery\MockInterface, 1: \ArrayObject} The mock and the [sql, args] record.
 */
function wpdb_recording_statements(): array {
    $wpdb = TestFactory::wpdb();
    $GLOBALS['wpdb'] = $wpdb;
    $prepared = new \ArrayObject();
    $wpdb->shouldReceive('prepare')->andReturnUsing(function ($sql, ...$args) use ($prepared) {
        $prepared[] = [$sql, $args];
        return count($prepared) - 1;
    });
    return [$wpdb, $prepared];
}

describe('Conversion_Store', function () {

    afterEach(function () {
        unset($GLOBALS['wpdb']);
    });

    it('counts the distinct conversions among the given references', function () {
        [$wpdb, $prepared] = wpdb_recording_statements();
        $wpdb->shouldReceive('get_var')->once()->with(0)->andReturn('2');

        expect((new Conversion_Store())->count(['ref-1', 'ref-2', 'ref-3']))->toBe(2);

        [$sql, $args] = $prepared[0];
        expect($sql)->toBe('SELECT COUNT(DISTINCT conversion_ref) FROM wp_kntnt_ad_attr_conversions WHERE conversion_ref IN (%s,%s,%s)');
        expect($args)->toBe(['ref-1', 'ref-2', 'ref-3']);
    });

    it('finds the conversions sharing the given conversions\' clicks', function () {
        [$wpdb, $prepared] = wpdb_recording_statements();
        $wpdb->shouldReceive('get_col')->once()->with(0)->andReturn(['ref-1', 'ref-2']);

        expect((new Conversion_Store())->sharing_clicks(['ref-1']))->toBe(['ref-1', 'ref-2']);

        [$sql, $args] = $prepared[0];
        expect($sql)->toBe('SELECT DISTINCT conversion_ref FROM wp_kntnt_ad_attr_conversions WHERE click_id IN (SELECT click_id FROM wp_kntnt_ad_attr_conversions WHERE conversion_ref IN (%s))');
        expect($args)->toBe(['ref-1']);
    });

    it('deletes only the conversion rows of the given conversions', function () {
        [$wpdb, $prepared] = wpdb_recording_statements();
        $wpdb->shouldReceive('query')->once()->with(0)->andReturn(2);

        (new Conversion_Store())->delete(['ref-1', 'ref-2']);

        [$sql, $args] = $prepared[0];
        expect($sql)->toBe('DELETE FROM wp_kntnt_ad_attr_conversions WHERE conversion_ref IN (%s,%s)');
        expect($args)->toBe(['ref-1', 'ref-2']);
    });

    it('sets or clears the test flag on the given conversions\' attributed clicks', function (bool $is_test, int $flag) {
        [$wpdb, $prepared] = wpdb_recording_statements();
        $wpdb->shouldReceive('query')->once()->with(0)->andReturn(1);

        (new Conversion_Store())->flag_clicks_as_test(['ref-1'], $is_test);

        [$sql, $args] = $prepared[0];
        expect($sql)->toBe('UPDATE wp_kntnt_ad_attr_clicks SET is_test = %d WHERE id IN (SELECT click_id FROM wp_kntnt_ad_attr_conversions WHERE conversion_ref IN (%s))');
        expect($args)->toBe([$flag, 'ref-1']);
    })->with([
        'mark'   => [true, 1],
        'unmark' => [false, 0],
    ]);

    it('touches nothing without references', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;
        $wpdb->shouldNotReceive('prepare');

        $store = new Conversion_Store();
        expect($store->count([]))->toBe(0);
        expect($store->sharing_clicks([]))->toBe([]);
        $store->delete([]);
        $store->flag_clicks_as_test([], true);
    });

});
