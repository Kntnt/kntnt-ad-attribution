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

        Functions\expect('error_log')->once();

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
