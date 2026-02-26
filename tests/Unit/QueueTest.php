<?php
/**
 * Unit tests for Queue.
 *
 * @package Tests\Unit
 * @since   1.2.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution\Queue;
use Kntnt\Ad_Attribution\Settings;
use Brain\Monkey\Functions;
use Tests\Helpers\TestFactory;

// ─── enqueue() ───

describe('Queue::enqueue()', function () {

    it('inserts with status pending and attempts 0', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get')->with('attempts_per_round')->andReturn(3);
        $settings->shouldReceive('get')->with('retry_delay')->andReturn(60);
        $settings->shouldReceive('get')->with('max_rounds')->andReturn(3);
        $settings->shouldReceive('get')->with('round_delay')->andReturn(21600);

        Functions\expect('wp_json_encode')
            ->once()
            ->andReturnUsing(fn ($v) => json_encode($v));

        $wpdb->shouldReceive('insert')
            ->once()
            ->withArgs(function (string $table, array $data, array $format) {
                expect($table)->toBe('wp_kntnt_ad_attr_queue');
                expect($data['status'])->toBe('pending');
                expect($data['attempts'])->toBe(0);
                expect($data['reporter'])->toBe('test_reporter');
                return true;
            });

        (new Queue($settings))->enqueue('test_reporter', ['key' => 'value']);
    });

    it('JSON-encodes the payload', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get')->with('attempts_per_round')->andReturn(3);
        $settings->shouldReceive('get')->with('retry_delay')->andReturn(60);
        $settings->shouldReceive('get')->with('max_rounds')->andReturn(3);
        $settings->shouldReceive('get')->with('round_delay')->andReturn(21600);

        $payload = ['attribution' => [1.0], 'context' => 'test'];

        Functions\expect('wp_json_encode')
            ->once()
            ->with($payload)
            ->andReturn(json_encode($payload));

        $wpdb->shouldReceive('insert')
            ->once()
            ->withArgs(function (string $table, array $data) use ($payload) {
                expect($data['payload'])->toBe(json_encode($payload));
                return true;
            });

        (new Queue($settings))->enqueue('reporter', $payload);
    });

});

// ─── dequeue() ───

describe('Queue::dequeue()', function () {

    it('returns empty array if nothing pending', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $settings = Mockery::mock(Settings::class)->shouldIgnoreMissing();

        $wpdb->shouldReceive('prepare')
            ->once()
            ->andReturn('prepared-sql');

        $wpdb->shouldReceive('get_results')
            ->once()
            ->with('prepared-sql')
            ->andReturn([]);

        $result = (new Queue($settings))->dequeue();

        expect($result)->toBe([]);
    });

    it('atomically updates status to processing', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $settings = Mockery::mock(Settings::class)->shouldIgnoreMissing();

        $row = (object) ['id' => '1', 'reporter' => 'test', 'payload' => '{"key":"val"}', 'attempts' => '0', 'attempts_per_round' => null, 'retry_delay' => null, 'max_rounds' => null, 'round_delay' => null];

        // First prepare call = SELECT
        $wpdb->shouldReceive('prepare')
            ->twice()
            ->andReturn('select-sql', 'update-sql');

        $wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn([$row]);

        // UPDATE should be called to set status = 'processing'
        $wpdb->shouldReceive('query')
            ->once()
            ->with('update-sql');

        (new Queue($settings))->dequeue();
    });

    it('JSON-decodes payload in returned items', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $settings = Mockery::mock(Settings::class)->shouldIgnoreMissing();

        $payload = ['key' => 'val'];
        $row = (object) ['id' => '1', 'reporter' => 'test', 'payload' => json_encode($payload), 'attempts' => '0', 'attempts_per_round' => null, 'retry_delay' => null, 'max_rounds' => null, 'round_delay' => null];

        $wpdb->shouldReceive('prepare')->andReturn('sql');
        $wpdb->shouldReceive('get_results')->andReturn([$row]);
        $wpdb->shouldReceive('query');

        $result = (new Queue($settings))->dequeue();

        expect($result[0]->payload)->toBe($payload);
    });

    it('respects limit parameter in SQL', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $settings = Mockery::mock(Settings::class)->shouldIgnoreMissing();

        $wpdb->shouldReceive('prepare')
            ->withArgs(function (string $sql, int $limit) {
                expect($sql)->toContain('LIMIT %d');
                expect($limit)->toBe(5);
                return true;
            })
            ->andReturn('sql');

        $wpdb->shouldReceive('get_results')
            ->andReturn([]);

        (new Queue($settings))->dequeue(5);
    });

});

// ─── complete() ───

describe('Queue::complete()', function () {

    it('sets status to done and processed_at to current time', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $settings = Mockery::mock(Settings::class)->shouldIgnoreMissing();

        $wpdb->shouldReceive('update')
            ->once()
            ->withArgs(function (string $table, array $data, array $where) {
                expect($table)->toBe('wp_kntnt_ad_attr_queue');
                expect($data['status'])->toBe('done');
                expect($data['processed_at'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
                expect($where)->toBe(['id' => 42]);
                return true;
            });

        (new Queue($settings))->complete(42);
    });

});

// ─── fail() ───

describe('Queue::fail()', function () {

    it('increments attempts counter', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get')->with('attempts_per_round')->andReturn(3);
        $settings->shouldReceive('get')->with('retry_delay')->andReturn(60);
        $settings->shouldReceive('get')->with('max_rounds')->andReturn(3);
        $settings->shouldReceive('get')->with('round_delay')->andReturn(21600);

        $wpdb->shouldReceive('prepare')->andReturn('sql');
        $wpdb->shouldReceive('get_row')
            ->once()
            ->andReturn((object) [
                'attempts'           => '1',
                'attempts_per_round' => null,
                'retry_delay'        => null,
                'max_rounds'         => null,
                'round_delay'        => null,
            ]);

        $wpdb->shouldReceive('update')
            ->once()
            ->withArgs(function (string $table, array $data) {
                expect($data['attempts'])->toBe(2);
                return true;
            });

        (new Queue($settings))->fail(1, 'Some error');
    });

    it('sets status pending with retry_after for mid-round retry', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        // With defaults: attempts_per_round=3, max_rounds=3, max_total=9.
        // attempts=1, k=2 → 2 < 9 and 2%3≠0 → pending with retry_delay.
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get')->with('attempts_per_round')->andReturn(3);
        $settings->shouldReceive('get')->with('retry_delay')->andReturn(60);
        $settings->shouldReceive('get')->with('max_rounds')->andReturn(3);
        $settings->shouldReceive('get')->with('round_delay')->andReturn(21600);

        $wpdb->shouldReceive('prepare')->andReturn('sql');
        $wpdb->shouldReceive('get_row')
            ->once()
            ->andReturn((object) [
                'attempts'           => '1',
                'attempts_per_round' => null,
                'retry_delay'        => null,
                'max_rounds'         => null,
                'round_delay'        => null,
            ]);

        $wpdb->shouldReceive('update')
            ->once()
            ->withArgs(function (string $table, array $data) {
                expect($data['status'])->toBe('pending');
                expect($data)->toHaveKey('retry_after');
                return true;
            });

        (new Queue($settings))->fail(1, 'Retry error');
    });

    it('sets status failed when all rounds exhausted', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        // With defaults: attempts_per_round=3, max_rounds=3, max_total=9.
        // attempts=8, k=9 → 9 >= 9 → failed with processed_at.
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get')->with('attempts_per_round')->andReturn(3);
        $settings->shouldReceive('get')->with('retry_delay')->andReturn(60);
        $settings->shouldReceive('get')->with('max_rounds')->andReturn(3);
        $settings->shouldReceive('get')->with('round_delay')->andReturn(21600);

        $wpdb->shouldReceive('prepare')->andReturn('sql');
        $wpdb->shouldReceive('get_row')
            ->once()
            ->andReturn((object) [
                'attempts'           => '8',
                'attempts_per_round' => null,
                'retry_delay'        => null,
                'max_rounds'         => null,
                'round_delay'        => null,
            ]);

        $wpdb->shouldReceive('update')
            ->once()
            ->withArgs(function (string $table, array $data) {
                expect($data['status'])->toBe('failed');
                expect($data)->toHaveKey('processed_at');
                return true;
            });

        (new Queue($settings))->fail(1, 'Final failure');
    });

    it('stores error message in the update', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get')->with('attempts_per_round')->andReturn(3);
        $settings->shouldReceive('get')->with('retry_delay')->andReturn(60);
        $settings->shouldReceive('get')->with('max_rounds')->andReturn(3);
        $settings->shouldReceive('get')->with('round_delay')->andReturn(21600);

        $wpdb->shouldReceive('prepare')->andReturn('sql');
        $wpdb->shouldReceive('get_row')
            ->once()
            ->andReturn((object) [
                'attempts'           => '0',
                'attempts_per_round' => null,
                'retry_delay'        => null,
                'max_rounds'         => null,
                'round_delay'        => null,
            ]);

        $wpdb->shouldReceive('update')
            ->once()
            ->withArgs(function (string $table, array $data) {
                expect($data['error_message'])->toBe('Connection timeout');
                return true;
            });

        (new Queue($settings))->fail(1, 'Connection timeout');
    });

});

// ─── cleanup() ───

describe('Queue::cleanup()', function () {

    it('deletes done jobs older than specified days', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $settings = Mockery::mock(Settings::class)->shouldIgnoreMissing();

        $wpdb->shouldReceive('prepare')
            ->twice()
            ->andReturnUsing(function (string $sql) {
                return $sql; // pass through
            });

        $calls = [];
        $wpdb->shouldReceive('query')
            ->twice()
            ->andReturnUsing(function (string $sql) use (&$calls) {
                $calls[] = $sql;
                return 1;
            });

        (new Queue($settings))->cleanup(30, 90);

        expect($calls[0])->toContain("status = 'done'");
    });

    it('deletes failed jobs older than specified days', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $settings = Mockery::mock(Settings::class)->shouldIgnoreMissing();

        $wpdb->shouldReceive('prepare')
            ->twice()
            ->andReturnUsing(fn (string $sql) => $sql);

        $calls = [];
        $wpdb->shouldReceive('query')
            ->twice()
            ->andReturnUsing(function (string $sql) use (&$calls) {
                $calls[] = $sql;
                return 1;
            });

        (new Queue($settings))->cleanup(30, 90);

        expect($calls[1])->toContain("status = 'failed'");
    });

});

// ─── get_status() ───

describe('Queue::get_status()', function () {

    it('returns counts for each status', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $settings = Mockery::mock(Settings::class)->shouldIgnoreMissing();

        $wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn([
                (object) ['status' => 'pending', 'cnt' => '5'],
                (object) ['status' => 'done', 'cnt' => '10'],
                (object) ['status' => 'failed', 'cnt' => '2'],
            ]);

        $wpdb->shouldReceive('get_var')
            ->once()
            ->andReturn(null);

        $status = (new Queue($settings))->get_status();

        expect($status['pending'])->toBe(5);
        expect($status['done'])->toBe(10);
        expect($status['failed'])->toBe(2);
        expect($status['processing'])->toBe(0);
    });

    it('returns last error message', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $settings = Mockery::mock(Settings::class)->shouldIgnoreMissing();

        $wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn([]);

        $wpdb->shouldReceive('get_var')
            ->once()
            ->andReturn('API rate limited');

        $status = (new Queue($settings))->get_status();

        expect($status['last_error'])->toBe('API rate limited');
    });

});
