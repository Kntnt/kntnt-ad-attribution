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

        $row = (object) ['id' => '1', 'reporter' => 'test', 'payload' => '{"key":"val"}', 'attempts' => '0', 'attempts_per_round' => null, 'retry_delay' => null, 'max_rounds' => null, 'round_delay' => null, 'not_before' => null];

        // First prepare call = SELECT
        $wpdb->shouldReceive('prepare')
            ->twice()
            ->andReturn('select-sql', 'update-sql');

        $wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn([$row]);

        // UPDATE should be called to set status = 'processing' and last_attempt_at
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
        $row = (object) ['id' => '1', 'reporter' => 'test', 'payload' => json_encode($payload), 'attempts' => '0', 'attempts_per_round' => null, 'retry_delay' => null, 'max_rounds' => null, 'round_delay' => null, 'not_before' => null];

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

    it('sets status to done with processed_at, last_attempt_at, and increments attempts', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $settings = Mockery::mock(Settings::class)->shouldIgnoreMissing();

        $wpdb->shouldReceive('prepare')
            ->once()
            ->withArgs(function (string $sql, ...$args) {
                expect($sql)->toContain("status = 'done'");
                expect($sql)->toContain('last_attempt_at');
                expect($sql)->toContain('attempts = attempts + 1');
                // $args[0] = processed_at, $args[1] = last_attempt_at, $args[2] = id
                expect($args[0])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
                expect($args[1])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
                expect($args[2])->toBe(42);
                return true;
            })
            ->andReturn('prepared-sql');

        $wpdb->shouldReceive('query')
            ->once()
            ->with('prepared-sql');

        (new Queue($settings))->complete(42);
    });

});

// ─── fail() ───

describe('Queue::fail()', function () {

    it('increments attempts counter and sets last_attempt_at', function () {
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
                expect($data)->toHaveKey('last_attempt_at');
                expect($data['last_attempt_at'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
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

// ─── enqueue() with not_before ───

describe('Queue::enqueue() with not_before', function () {

    it('stores formatted datetime when not_before is provided', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get')->with('attempts_per_round')->andReturn(3);
        $settings->shouldReceive('get')->with('retry_delay')->andReturn(60);
        $settings->shouldReceive('get')->with('max_rounds')->andReturn(3);
        $settings->shouldReceive('get')->with('round_delay')->andReturn(21600);

        Functions\expect('wp_json_encode')->once()->andReturn('{}');

        $wpdb->shouldReceive('insert')
            ->once()
            ->withArgs(function (string $table, array $data) {
                expect($data['not_before'])->toBe('2024-01-01 18:00:00');
                return true;
            });

        (new Queue($settings))->enqueue('reporter', [], '', [], strtotime('2024-01-01 18:00:00 UTC'));
    });

    it('stores null when not_before is omitted', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get')->with('attempts_per_round')->andReturn(3);
        $settings->shouldReceive('get')->with('retry_delay')->andReturn(60);
        $settings->shouldReceive('get')->with('max_rounds')->andReturn(3);
        $settings->shouldReceive('get')->with('round_delay')->andReturn(21600);

        Functions\expect('wp_json_encode')->once()->andReturn('{}');

        $wpdb->shouldReceive('insert')
            ->once()
            ->withArgs(function (string $table, array $data) {
                expect($data['not_before'])->toBeNull();
                return true;
            });

        (new Queue($settings))->enqueue('reporter', []);
    });

});

// ─── dequeue() with not_before filtering ───

describe('Queue::dequeue() not_before filtering', function () {

    it('includes not_before condition in the SQL query', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $settings = Mockery::mock(Settings::class)->shouldIgnoreMissing();

        $wpdb->shouldReceive('prepare')
            ->once()
            ->withArgs(function (string $sql) {
                expect($sql)->toContain('not_before IS NULL OR not_before <= UTC_TIMESTAMP()');
                return true;
            })
            ->andReturn('sql');

        $wpdb->shouldReceive('get_results')->once()->andReturn([]);

        (new Queue($settings))->dequeue();
    });

});

// ─── delete() ───

describe('Queue::delete()', function () {

    it('calls wpdb->delete with correct table and ID', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $settings = Mockery::mock(Settings::class)->shouldIgnoreMissing();

        $wpdb->shouldReceive('delete')
            ->once()
            ->withArgs(function (string $table, array $where, array $format) {
                expect($table)->toBe('wp_kntnt_ad_attr_queue');
                expect($where)->toBe(['id' => 99]);
                expect($format)->toBe(['%d']);
                return true;
            });

        (new Queue($settings))->delete(99);
    });

});

// ─── reset_retry() ───

describe('Queue::reset_retry()', function () {

    it('sets retry_after to null for given job ID', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $settings = Mockery::mock(Settings::class)->shouldIgnoreMissing();

        $wpdb->shouldReceive('update')
            ->once()
            ->withArgs(function (string $table, array $data, array $where) {
                expect($table)->toBe('wp_kntnt_ad_attr_queue');
                expect($data)->toBe(['retry_after' => null]);
                expect($where)->toBe(['id' => 5]);
                return true;
            });

        (new Queue($settings))->reset_retry(5);
    });

});

// ─── get_active_jobs() ───

describe('Queue::get_active_jobs()', function () {

    it('includes done status and selects new columns', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $settings = Mockery::mock(Settings::class)->shouldIgnoreMissing();

        $wpdb->shouldReceive('get_results')
            ->once()
            ->withArgs(function (string $sql) {
                expect($sql)->toContain("'done'");
                expect($sql)->toContain('last_attempt_at');
                expect($sql)->toContain('not_before');
                expect($sql)->toContain('label');
                return true;
            })
            ->andReturn([]);

        $result = (new Queue($settings))->get_active_jobs();

        expect($result)->toBe([]);
    });

});

// ─── get_next_retry_time() ───

describe('Queue::get_next_retry_time()', function () {

    it('returns null when no pending jobs with future times', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $settings = Mockery::mock(Settings::class)->shouldIgnoreMissing();

        $wpdb->shouldReceive('get_var')->once()->andReturn(null);

        $result = (new Queue($settings))->get_next_retry_time();

        expect($result)->toBeNull();
    });

    it('considers both retry_after and not_before in the SQL', function () {
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $settings = Mockery::mock(Settings::class)->shouldIgnoreMissing();

        $wpdb->shouldReceive('get_var')
            ->once()
            ->withArgs(function (string $sql) {
                expect($sql)->toContain('COALESCE(retry_after');
                expect($sql)->toContain('COALESCE(not_before');
                expect($sql)->toContain('LEAST');
                return true;
            })
            ->andReturn('2026-01-15 12:00:00');

        $result = (new Queue($settings))->get_next_retry_time();

        expect($result)->toBe(strtotime('2026-01-15 12:00:00 UTC'));
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
