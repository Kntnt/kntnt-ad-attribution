<?php
/**
 * Unit tests for Click_ID_Store.
 *
 * @package Tests\Unit
 * @since   1.2.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution\Click_ID_Store;
use Tests\Helpers\TestFactory;

// ─── store() ───

describe('Click_ID_Store::store()', function () {

    afterEach(function () {
        unset($GLOBALS['wpdb']);
    });

    it('inserts one row keyed by the click row ID and platform', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_kntnt_ad_attr_click_ids',
                ['click_id' => 42, 'platform' => 'google_ads', 'value' => 'CL123'],
                ['%d', '%s', '%s'],
            )
            ->andReturn(1);

        (new Click_ID_Store())->store(42, 'google_ads', 'CL123');

        expect(true)->toBeTrue();
    });

});

// ─── get_for_clicks() ───

describe('Click_ID_Store::get_for_clicks()', function () {

    afterEach(function () {
        unset($GLOBALS['wpdb']);
    });

    it('returns an empty array without querying when no clicks are given', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $wpdb->shouldNotReceive('get_results');

        expect((new Click_ID_Store())->get_for_clicks([]))->toBe([]);
    });

    it('looks up the given click rows and keys their IDs by hash', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $wpdb->shouldReceive('prepare')
            ->once()
            ->withArgs(function (string $sql, ...$args) {
                expect($args)->toBe([7, 9]);
                return true;
            })
            ->andReturn('prepared-sql');

        $wpdb->shouldReceive('get_results')
            ->once()
            ->with('prepared-sql')
            ->andReturn([
                (object) ['click_id' => '7', 'platform' => 'google_ads', 'value' => 'CL1', 'clicked_at' => '2024-01-01 12:00:00'],
                (object) ['click_id' => '7', 'platform' => 'meta', 'value' => 'FB1', 'clicked_at' => '2024-01-01 12:00:00'],
                (object) ['click_id' => '9', 'platform' => 'google_ads', 'value' => 'CL2', 'clicked_at' => '2024-01-02 08:00:00'],
            ]);

        $result = (new Click_ID_Store())->get_for_clicks(['hash_a' => 7, 'hash_b' => 9]);

        expect($result)->toBe([
            'hash_a' => [
                'google_ads' => ['id' => 'CL1', 'captured_at' => 1704110400],
                'meta'       => ['id' => 'FB1', 'captured_at' => 1704110400],
            ],
            'hash_b' => [
                'google_ads' => ['id' => 'CL2', 'captured_at' => 1704182400],
            ],
        ]);
    });

});

// ─── delete_orphans() ───

describe('Click_ID_Store::delete_orphans()', function () {

    afterEach(function () {
        unset($GLOBALS['wpdb']);
    });

    it('deletes the click IDs whose click row no longer exists and returns the count', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        // A correlated NOT EXISTS rather than a multi-table DELETE ... JOIN:
        // the SQLite translator used by WordPress Playground silently
        // deletes nothing for the latter.
        $wpdb->shouldReceive('query')
            ->once()
            ->withArgs(function (string $sql) {
                expect($sql)->toContain('DELETE FROM wp_kntnt_ad_attr_click_ids');
                expect($sql)->toContain('NOT EXISTS');
                expect($sql)->toContain('wp_kntnt_ad_attr_clicks');
                return true;
            })
            ->andReturn(3);

        expect((new Click_ID_Store())->delete_orphans())->toBe(3);
    });

});

// ─── find_original_click() ───

describe('Click_ID_Store::find_original_click()', function () {

    afterEach(function () {
        unset($GLOBALS['wpdb']);
    });

    it('returns the earliest click row on the tracking URL that carried the value', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $wpdb->shouldReceive('prepare')
            ->once()
            ->withArgs(function (string $sql, ...$args) {
                expect($args)->toBe(['hash_a', 'google_ads', 'RRR']);
                expect($sql)->toContain('ORDER BY c.clicked_at ASC, c.id ASC');
                return true;
            })
            ->andReturn('prepared-sql');

        $wpdb->shouldReceive('get_row')
            ->once()
            ->with('prepared-sql')
            ->andReturn((object) ['id' => '5', 'clicked_at' => '2024-01-01 12:00:00']);

        $result = (new Click_ID_Store())->find_original_click('hash_a', 'google_ads', 'RRR');

        expect($result)->toBe(['click_id' => 5, 'clicked_at' => 1704110400]);
    });

    it('returns null when the value was never recorded for the tracking URL', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $wpdb->shouldReceive('prepare')->andReturn('prepared-sql');
        $wpdb->shouldReceive('get_row')->once()->andReturn(null);

        expect((new Click_ID_Store())->find_original_click('hash_a', 'google_ads', 'RRR'))->toBeNull();
    });

});
