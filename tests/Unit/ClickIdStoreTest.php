<?php
/**
 * Unit tests for Click_ID_Store.
 *
 * @package Tests\Unit
 * @since   1.2.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution\Click_ID_Store;
use Brain\Monkey\Functions;
use Tests\Helpers\TestFactory;

// ─── store() ───

describe('Click_ID_Store::store()', function () {

    it('calls wpdb->query with INSERT...ON DUPLICATE KEY UPDATE', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $wpdb->shouldReceive('prepare')
            ->once()
            ->withArgs(function (string $sql, ...$args) {
                expect($sql)->toContain('INSERT INTO');
                expect($sql)->toContain('ON DUPLICATE KEY UPDATE');
                expect($args[0])->toBe('abc123hash');  // hash
                expect($args[1])->toBe('google_ads');   // platform
                expect($args[2])->toBe('CL123');        // click_id
                return true;
            })
            ->andReturn('prepared-sql');

        $wpdb->shouldReceive('query')
            ->once()
            ->with('prepared-sql');

        (new Click_ID_Store())->store('abc123hash', 'google_ads', 'CL123');
    });

    it('uses GMT timestamp in the query', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $expected_time = gmdate('Y-m-d H:i:s');

        $wpdb->shouldReceive('prepare')
            ->once()
            ->withArgs(function (string $sql, ...$args) use ($expected_time) {
                // The 4th argument (index 3) is the timestamp
                expect($args[3])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
                return true;
            })
            ->andReturn('prepared-sql');

        $wpdb->shouldReceive('query')->once();

        (new Click_ID_Store())->store('hash', 'platform', 'click_id');
    });

});

// ─── get_for_hashes() ───

describe('Click_ID_Store::get_for_hashes()', function () {

    it('returns empty array for empty hashes without DB call', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $wpdb->shouldNotReceive('get_results');
        $wpdb->shouldNotReceive('prepare');

        $result = (new Click_ID_Store())->get_for_hashes([]);

        expect($result)->toBe([]);
    });

    it('groups results by hash and platform', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $row1 = (object) ['hash' => 'hash_a', 'platform' => 'google_ads', 'click_id' => 'CL1'];
        $row2 = (object) ['hash' => 'hash_a', 'platform' => 'meta', 'click_id' => 'FB1'];
        $row3 = (object) ['hash' => 'hash_b', 'platform' => 'google_ads', 'click_id' => 'CL2'];

        $wpdb->shouldReceive('prepare')
            ->once()
            ->andReturn('prepared-sql');

        $wpdb->shouldReceive('get_results')
            ->once()
            ->with('prepared-sql')
            ->andReturn([$row1, $row2, $row3]);

        $result = (new Click_ID_Store())->get_for_hashes(['hash_a', 'hash_b']);

        expect($result)->toBe([
            'hash_a' => ['google_ads' => 'CL1', 'meta' => 'FB1'],
            'hash_b' => ['google_ads' => 'CL2'],
        ]);
    });

});

// ─── cleanup() ───

describe('Click_ID_Store::cleanup()', function () {

    it('calls DELETE with correct cutoff date', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $wpdb->shouldReceive('prepare')
            ->once()
            ->withArgs(function (string $sql, string $cutoff) {
                expect($sql)->toContain('DELETE FROM');
                expect($sql)->toContain('clicked_at < %s');
                // The cutoff should be a valid datetime string
                expect($cutoff)->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
                return true;
            })
            ->andReturn('prepared-sql');

        $wpdb->shouldReceive('query')
            ->once()
            ->with('prepared-sql');

        (new Click_ID_Store())->cleanup(120);
    });

});
