<?php
/**
 * Unit tests for Queue_Processor.
 *
 * @package Tests\Unit
 * @since   1.2.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution\Queue;
use Kntnt\Ad_Attribution\Queue_Processor;
use Brain\Monkey\Functions;
use Tests\Helpers\TestFactory;

// ─── process() ───

describe('Queue_Processor::process()', function () {

    it('returns early if no reporters registered', function () {
        $queue = Mockery::mock(Queue::class);

        Functions\expect('apply_filters')
            ->once()
            ->with('kntnt_ad_attr_conversion_reporters', [])
            ->andReturn([]);

        // dequeue should never be called
        $queue->shouldNotReceive('dequeue');

        (new Queue_Processor($queue))->process();
    });

    it('dispatches to correct reporter', function () {
        $queue = Mockery::mock(Queue::class);

        $called_with = null;
        $reporters = [
            'test_reporter' => [
                'process' => function (array $payload) use (&$called_with) {
                    $called_with = $payload;
                    return true;
                },
            ],
        ];

        Functions\expect('apply_filters')
            ->once()
            ->with('kntnt_ad_attr_conversion_reporters', [])
            ->andReturn($reporters);

        $item = (object) [
            'id'       => 1,
            'reporter' => 'test_reporter',
            'payload'  => ['key' => 'val'],
        ];

        $queue->shouldReceive('dequeue')->once()->andReturn([$item]);
        $queue->shouldReceive('complete')->once()->with(1);
        $queue->shouldReceive('get_status')->once()->andReturn(['pending' => 0]);

        (new Queue_Processor($queue))->process();

        expect($called_with)->toBe(['key' => 'val']);
    });

    it('fails job with unknown reporter', function () {
        $queue = Mockery::mock(Queue::class);

        Functions\expect('apply_filters')
            ->once()
            ->with('kntnt_ad_attr_conversion_reporters', [])
            ->andReturn(['known' => ['process' => fn () => true]]);

        $item = (object) [
            'id'       => 1,
            'reporter' => 'unknown_reporter',
            'payload'  => [],
        ];

        $queue->shouldReceive('dequeue')->once()->andReturn([$item]);
        $queue->shouldReceive('fail')
            ->once()
            ->withArgs(function (int $id, string $msg) {
                expect($id)->toBe(1);
                expect($msg)->toContain('Unknown or invalid reporter');
                return true;
            });
        $queue->shouldReceive('get_status')->once()->andReturn(['pending' => 0]);

        (new Queue_Processor($queue))->process();
    });

    it('catches exceptions from reporter and fails job', function () {
        $queue = Mockery::mock(Queue::class);

        $reporters = [
            'throws' => [
                'process' => function () {
                    throw new \RuntimeException('Boom');
                },
            ],
        ];

        Functions\expect('apply_filters')
            ->once()
            ->with('kntnt_ad_attr_conversion_reporters', [])
            ->andReturn($reporters);

        $item = (object) ['id' => 1, 'reporter' => 'throws', 'payload' => []];

        $queue->shouldReceive('dequeue')->once()->andReturn([$item]);
        $queue->shouldReceive('fail')
            ->once()
            ->withArgs(function (int $id, string $msg) {
                expect($msg)->toBe('Boom');
                return true;
            });
        $queue->shouldReceive('get_status')->once()->andReturn(['pending' => 0]);

        (new Queue_Processor($queue))->process();
    });

    it('completes job on success', function () {
        $queue = Mockery::mock(Queue::class);

        $reporters = [
            'ok' => ['process' => fn () => true],
        ];

        Functions\expect('apply_filters')
            ->once()
            ->with('kntnt_ad_attr_conversion_reporters', [])
            ->andReturn($reporters);

        $item = (object) ['id' => 7, 'reporter' => 'ok', 'payload' => []];

        $queue->shouldReceive('dequeue')->andReturn([$item]);
        $queue->shouldReceive('complete')->once()->with(7);
        $queue->shouldReceive('get_status')->andReturn(['pending' => 0]);

        (new Queue_Processor($queue))->process();
    });

    it('fails job when reporter returns false', function () {
        $queue = Mockery::mock(Queue::class);

        $reporters = [
            'nope' => ['process' => fn () => false],
        ];

        Functions\expect('apply_filters')
            ->once()
            ->with('kntnt_ad_attr_conversion_reporters', [])
            ->andReturn($reporters);

        $item = (object) ['id' => 3, 'reporter' => 'nope', 'payload' => []];

        $queue->shouldReceive('dequeue')->andReturn([$item]);
        $queue->shouldReceive('fail')
            ->once()
            ->withArgs(function (int $id, string $msg) {
                expect($id)->toBe(3);
                expect($msg)->toContain('returned false');
                return true;
            });
        $queue->shouldReceive('get_status')->andReturn(['pending' => 0]);

        (new Queue_Processor($queue))->process();
    });

    it('re-schedules if pending jobs remain', function () {
        $queue = Mockery::mock(Queue::class);

        Functions\expect('apply_filters')
            ->once()
            ->with('kntnt_ad_attr_conversion_reporters', [])
            ->andReturn(['r' => ['process' => fn () => true]]);

        $queue->shouldReceive('dequeue')->andReturn([]);
        $queue->shouldReceive('get_status')->andReturn(['pending' => 5]);
        $queue->shouldReceive('get_next_retry_time')->once()->andReturn(null);

        Functions\expect('wp_next_scheduled')
            ->once()
            ->with('kntnt_ad_attr_process_queue')
            ->andReturn(false);

        Functions\expect('wp_schedule_single_event')
            ->once()
            ->withArgs(function ($time, string $hook) {
                expect($hook)->toBe('kntnt_ad_attr_process_queue');
                return true;
            });

        (new Queue_Processor($queue))->process();
    });

});

// ─── process_single() ───

describe('Queue_Processor::process_single()', function () {

    afterEach(function () {
        unset($GLOBALS['wpdb']);
    });

    it('completes job on reporter success', function () {
        $queue = Mockery::mock(Queue::class);

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $job = (object) [
            'id'       => 5,
            'reporter' => 'ok',
            'payload'  => json_encode(['key' => 'val']),
            'status'   => 'pending',
        ];

        $wpdb->shouldReceive('prepare')->andReturn('sql');
        $wpdb->shouldReceive('get_row')->once()->andReturn($job);

        // Should mark as processing with last_attempt_at.
        $wpdb->shouldReceive('update')
            ->once()
            ->withArgs(function (string $table, array $data, array $where) {
                expect($data['status'])->toBe('processing');
                expect($data)->toHaveKey('last_attempt_at');
                expect($where)->toBe(['id' => 5]);
                return true;
            });

        Functions\expect('apply_filters')
            ->once()
            ->with('kntnt_ad_attr_conversion_reporters', [])
            ->andReturn(['ok' => ['process' => fn () => true]]);

        $queue->shouldReceive('complete')->once()->with(5);

        $result = (new Queue_Processor($queue))->process_single(5);

        expect($result)->toBeTrue();
    });

    it('fails pending job via Queue::fail() on reporter failure', function () {
        $queue = Mockery::mock(Queue::class);

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $job = (object) [
            'id'       => 3,
            'reporter' => 'nope',
            'payload'  => json_encode([]),
            'status'   => 'pending',
        ];

        $wpdb->shouldReceive('prepare')->andReturn('sql');
        $wpdb->shouldReceive('get_row')->once()->andReturn($job);
        $wpdb->shouldReceive('update')->once(); // mark as processing

        Functions\expect('apply_filters')
            ->once()
            ->with('kntnt_ad_attr_conversion_reporters', [])
            ->andReturn(['nope' => ['process' => fn () => false]]);

        $queue->shouldReceive('fail')->once()->with(3, 'Reporter returned false.');

        $result = (new Queue_Processor($queue))->process_single(3);

        expect($result)->toBeFalse();
    });

    it('keeps failed status on one-shot re-run of failed job', function () {
        $queue = Mockery::mock(Queue::class);

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $job = (object) [
            'id'       => 8,
            'reporter' => 'nope',
            'payload'  => json_encode([]),
            'status'   => 'failed',
        ];

        $wpdb->shouldReceive('prepare')->andReturn('sql');
        $wpdb->shouldReceive('get_row')->once()->andReturn($job);

        // Mark as processing.
        $wpdb->shouldReceive('update')
            ->once()
            ->withArgs(fn ($t, $d) => $d['status'] === 'processing');

        Functions\expect('apply_filters')
            ->once()
            ->with('kntnt_ad_attr_conversion_reporters', [])
            ->andReturn(['nope' => ['process' => fn () => false]]);

        // One-shot: should update directly via wpdb, not via Queue::fail().
        $wpdb->shouldReceive('update')
            ->once()
            ->withArgs(function ($table, $data, $where) {
                expect($data['status'])->toBe('failed');
                expect($data['error_message'])->toBe('Reporter returned false.');
                expect($where)->toBe(['id' => 8]);
                return true;
            });

        $queue->shouldNotReceive('fail');

        $result = (new Queue_Processor($queue))->process_single(8, true);

        expect($result)->toBeFalse();
    });

    it('returns false when job does not exist', function () {
        $queue = Mockery::mock(Queue::class);

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $wpdb->shouldReceive('prepare')->andReturn('sql');
        $wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $result = (new Queue_Processor($queue))->process_single(999);

        expect($result)->toBeFalse();
    });

});

// ─── schedule() ───

describe('Queue_Processor::schedule()', function () {

    it('calls wp_schedule_single_event with correct hook', function () {
        $queue = Mockery::mock(Queue::class);

        Functions\expect('wp_next_scheduled')
            ->once()
            ->with('kntnt_ad_attr_process_queue')
            ->andReturn(false);

        Functions\expect('wp_schedule_single_event')
            ->once()
            ->withArgs(function ($time, string $hook) {
                expect($hook)->toBe('kntnt_ad_attr_process_queue');
                return true;
            });

        (new Queue_Processor($queue))->schedule();
    });

    it('does not schedule if already scheduled', function () {
        $queue = Mockery::mock(Queue::class);

        Functions\expect('wp_next_scheduled')
            ->once()
            ->with('kntnt_ad_attr_process_queue')
            ->andReturn(1700000000);

        Functions\expect('wp_schedule_single_event')->never();

        (new Queue_Processor($queue))->schedule();

        // Explicit assertion so Pest does not flag this test as risky.
        expect(true)->toBeTrue();
    });

});
