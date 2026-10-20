<?php
/**
 * Unit tests for Click_Lookup.
 *
 * @package Tests\Unit
 * @since   1.10.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution\Click_Lookup;
use Tests\Helpers\TestFactory;

describe('Click_Lookup::find()', function () {

    afterEach(function () {
        unset($GLOBALS['wpdb']);
    });

    it('asks for every click by its exact hash and clicked_at in one query', function () {
        $hash_a = TestFactory::hash('lookup-a');
        $hash_b = TestFactory::hash('lookup-b');

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;
        $prepared = [];
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($sql, ...$args) use (&$prepared) {
            $prepared[] = [$sql, $args];
            return 'SQL';
        });
        $wpdb->shouldReceive('get_results')->once()->andReturn([]);

        Click_Lookup::find([[$hash_a, 1700000000], [$hash_b, 1699996400]]);

        expect($prepared)->toHaveCount(1);
        [$sql, $args] = $prepared[0];
        expect($sql)->toContain('FROM wp_kntnt_ad_attr_clicks');
        expect($sql)->toContain('(hash = %s AND clicked_at = %s) OR (hash = %s AND clicked_at = %s)');
        expect($args)->toBe([$hash_a, '2023-11-14 22:13:20', $hash_b, '2023-11-14 21:13:20']);
    });

    it('returns the ID and test flag of each click\'s row, and nothing for a click without one', function () {
        $hash_a = TestFactory::hash('lookup-found');
        $hash_b = TestFactory::hash('lookup-missing');

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_results')->andReturn([
            (object) ['id' => '7', 'hash' => $hash_a, 'clicked_at' => '2023-11-14 22:13:20', 'is_test' => '1'],
        ]);

        $found = Click_Lookup::find([[$hash_a, 1700000000], [$hash_b, 1700000000]]);

        expect($found)->toBe([$hash_a => [1700000000 => ['id' => 7, 'is_test' => true]]]);
    });

    it('does not take a row of the same hash at another time', function () {
        $hash = TestFactory::hash('lookup-other-time');

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_results')->andReturn([
            (object) ['id' => '7', 'hash' => $hash, 'clicked_at' => '2023-11-14 22:13:19', 'is_test' => '0'],
        ]);

        expect(Click_Lookup::find([[$hash, 1700000000]]))->toBe([]);
    });

    it('returns the oldest row when several match a click', function () {
        $hash = TestFactory::hash('lookup-tie');

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn ($sql) => $sql);
        $wpdb->shouldReceive('get_results')->andReturnUsing(function ($sql) use ($hash) {
            expect($sql)->toContain('ORDER BY id');
            return [
                (object) ['id' => '3', 'hash' => $hash, 'clicked_at' => '2023-11-14 22:13:20', 'is_test' => '0'],
                (object) ['id' => '8', 'hash' => $hash, 'clicked_at' => '2023-11-14 22:13:20', 'is_test' => '1'],
            ];
        });

        expect(Click_Lookup::find([[$hash, 1700000000]]))->toBe([$hash => [1700000000 => ['id' => 3, 'is_test' => false]]]);
    });

    it('queries nothing for no clicks', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;
        $wpdb->shouldNotReceive('get_results');

        expect(Click_Lookup::find([]))->toBe([]);
    });

});
