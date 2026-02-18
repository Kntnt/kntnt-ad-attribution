<?php
/**
 * Unit tests for Conversion_Handler.
 *
 * @package Tests\Unit
 * @since   1.0.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution\Conversion_Handler;
use Kntnt\Ad_Attribution\Cookie_Manager;
use Kntnt\Ad_Attribution\Click_ID_Store;
use Kntnt\Ad_Attribution\Queue;
use Kntnt\Ad_Attribution\Queue_Processor;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Tests\Helpers\TestFactory;

/**
 * Creates a Conversion_Handler with fresh mocked dependencies.
 *
 * @return array{0: Conversion_Handler, 1: Mockery\MockInterface, 2: Mockery\MockInterface, 3: Mockery\MockInterface, 4: Mockery\MockInterface}
 */
function make_conversion_handler(): array {
    $cm  = Mockery::mock(Cookie_Manager::class);
    $cis = Mockery::mock(Click_ID_Store::class);
    $q   = Mockery::mock(Queue::class);
    $qp  = Mockery::mock(Queue_Processor::class);
    return [new Conversion_Handler($cm, $cis, $q, $qp), $cm, $cis, $q, $qp];
}

/**
 * Stubs the common functions and globals needed by handle_conversion().
 * Returns everything needed for the full conversion flow.
 */
function setup_conversion_path(): array {
    [$handler, $cm, $cis, $q, $qp] = make_conversion_handler();

    $hash1 = TestFactory::hash('conv-old');
    $hash2 = TestFactory::hash('conv-new');
    $now   = 1700000000;

    // No recent conversion — bypass dedup.
    $_COOKIE['_ad_last_conv'] = '0';

    // Fixed timestamps.
    Functions\when('time')->justReturn($now);
    Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');

    // Cookie returns two entries. hash2 is more recent.
    $cm->shouldReceive('parse')->once()->andReturn([
        $hash1 => $now - 7200,
        $hash2 => $now - 3600,
    ]);

    // All hashes are valid (redefine static method via Patchwork).
    \Patchwork\redefine(
        'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
        fn (array $hashes) => $hashes,
    );

    // Database mock.
    $wpdb = TestFactory::wpdb();
    $GLOBALS['wpdb'] = $wpdb;

    // Transaction bookends.
    $wpdb->shouldReceive('query')->with('START TRANSACTION')->once();
    $wpdb->shouldReceive('query')->with('COMMIT')->once();

    // Click lookups — only hash2 gets attribution (last-click default).
    $wpdb->shouldReceive('prepare')->andReturn('SQL');
    $wpdb->shouldReceive('get_var')->andReturn('42');
    $wpdb->shouldReceive('insert')->once()->andReturn(true);

    // setcookie for dedup.
    Functions\expect('setcookie')->once();

    // $_SERVER for context.
    $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'TestAgent';
    $_SERVER['REQUEST_URI']    = '/thank-you/';

    return [$handler, $cm, $cis, $q, $qp, $wpdb, $hash1, $hash2, $now];
}

// ─── register() ───

describe('Conversion_Handler::register()', function () {

    it('registers kntnt_ad_attr_conversion action', function () {
        [$handler] = make_conversion_handler();

        Actions\expectAdded('kntnt_ad_attr_conversion')->once();

        $handler->register();

        expect(true)->toBeTrue();
    });

});

// ─── handle_conversion() ───

describe('Conversion_Handler::handle_conversion()', function () {

    afterEach(function () {
        unset($GLOBALS['wpdb']);
        $_COOKIE = [];
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'], $_SERVER['REQUEST_URI']);
    });

    it('returns early when recent conversion exists (dedup)', function () {
        [$handler, $cm] = make_conversion_handler();

        $now = 1700000000;
        Functions\when('time')->justReturn($now);

        // Last conversion was 1 day ago — within default 30-day dedup window.
        $_COOKIE['_ad_last_conv'] = (string) ($now - DAY_IN_SECONDS);

        // parse() should never be called if dedup kicks in.
        $cm->shouldNotReceive('parse');

        $handler->handle_conversion();

        expect(true)->toBeTrue();
    });

    it('allows conversion when last conversion is older than dedup window', function () {
        [$handler, $cm] = make_conversion_handler();

        $now = 1700000000;
        Functions\when('time')->justReturn($now);

        // Last conversion 31 days ago — outside default 30-day window.
        $_COOKIE['_ad_last_conv'] = (string) ($now - 31 * DAY_IN_SECONDS);

        // Processing continues — parse() is called but returns empty.
        $cm->shouldReceive('parse')->once()->andReturn([]);

        $handler->handle_conversion();

        expect(true)->toBeTrue();
    });

    it('caps dedup window to cookie lifetime', function () {
        [$handler, $cm] = make_conversion_handler();

        $now = 1700000000;
        Functions\when('time')->justReturn($now);

        // Last conversion 91 days ago. With dedup_days=100, lifetime=90,
        // effective dedup = min(100,90) = 90. Since 91 > 90, proceeds.
        $_COOKIE['_ad_last_conv'] = (string) ($now - 91 * DAY_IN_SECONDS);

        Filters\expectApplied('kntnt_ad_attr_cookie_lifetime')->once()->andReturn(90);
        Filters\expectApplied('kntnt_ad_attr_dedup_days')->once()->andReturn(100);

        // If capping works, parse() is called (91 > 90).
        // If not (dedup=100), parse() would not be called (91 < 100).
        $cm->shouldReceive('parse')->once()->andReturn([]);

        $handler->handle_conversion();

        expect(true)->toBeTrue();
    });

    it('returns early for empty _ad_clicks cookie', function () {
        [$handler, $cm] = make_conversion_handler();

        $now = 1700000000;
        Functions\when('time')->justReturn($now);
        $_COOKIE['_ad_last_conv'] = '0';

        $cm->shouldReceive('parse')->once()->andReturn([]);

        $handler->handle_conversion();

        // No DB operations means no $wpdb needed.
        expect(true)->toBeTrue();
    });

    it('returns early when no valid hashes remain', function () {
        [$handler, $cm] = make_conversion_handler();
        $hash = TestFactory::hash('invalid');

        $now = 1700000000;
        Functions\when('time')->justReturn($now);
        $_COOKIE['_ad_last_conv'] = '0';

        $cm->shouldReceive('parse')->once()->andReturn([$hash => $now]);

        // No hashes are valid.
        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => [],
        );

        // No DB operations should happen.
        $handler->handle_conversion();

        expect(true)->toBeTrue();
    });

    it('filters to only valid hashes', function () {
        [$handler, $cm, $cis, $q, $qp] = make_conversion_handler();

        $valid   = TestFactory::hash('valid');
        $invalid = TestFactory::hash('invalid');
        $now     = 1700000000;

        Functions\when('time')->justReturn($now);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');
        $_COOKIE['_ad_last_conv'] = '0';

        $cm->shouldReceive('parse')->once()->andReturn([
            $valid   => $now - 3600,
            $invalid => $now - 7200,
        ]);

        // Only $valid passes validation.
        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => array_filter($hashes, fn ($h) => str_contains($h, substr(hash('sha256', 'valid'), 0, 8))),
        );

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $wpdb->shouldReceive('query')->with('START TRANSACTION')->once();
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_var')->once()->andReturn('42');

        // Only one insert — for the valid hash.
        $wpdb->shouldReceive('insert')->once()->andReturn(true);
        $wpdb->shouldReceive('query')->with('COMMIT')->once();

        Functions\expect('setcookie')->once();

        $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent';
        $_SERVER['REQUEST_URI']    = '/';

        $handler->handle_conversion();

        expect(true)->toBeTrue();
    });

    it('attributes 1.0 to latest click and 0.0 to older clicks', function () {
        [$handler, $cm, $cis, $q, $qp] = make_conversion_handler();

        $hash_old = TestFactory::hash('old-click');
        $hash_new = TestFactory::hash('new-click');
        $now      = 1700000000;

        Functions\when('time')->justReturn($now);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');
        $_COOKIE['_ad_last_conv'] = '0';

        // hash_new is more recent.
        $cm->shouldReceive('parse')->once()->andReturn([
            $hash_old => $now - 7200,
            $hash_new => $now - 3600,
        ]);

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => $hashes,
        );

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $wpdb->shouldReceive('query')->with('START TRANSACTION')->once();
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_var')->andReturn('42');

        // Only one insert — the older click has 0.0 attribution and is skipped.
        $wpdb->shouldReceive('insert')
            ->once()
            ->withArgs(function ($table, $data) {
                expect($data['fractional_conversion'])->toBe(1.0);
                return true;
            })
            ->andReturn(true);

        $wpdb->shouldReceive('query')->with('COMMIT')->once();

        Functions\expect('setcookie')->once();

        $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent';
        $_SERVER['REQUEST_URI']    = '/';

        $handler->handle_conversion();

        expect(true)->toBeTrue();
    });

    it('uses modified weights from attribution filter', function () {
        [$handler, $cm, $cis, $q, $qp] = make_conversion_handler();

        $hash1 = TestFactory::hash('attr-1');
        $hash2 = TestFactory::hash('attr-2');
        $now   = 1700000000;

        Functions\when('time')->justReturn($now);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');
        $_COOKIE['_ad_last_conv'] = '0';

        $cm->shouldReceive('parse')->once()->andReturn([
            $hash1 => $now - 7200,
            $hash2 => $now - 3600,
        ]);

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => $hashes,
        );

        // Override attribution to split 50/50.
        Filters\expectApplied('kntnt_ad_attr_attribution')
            ->once()
            ->andReturnUsing(fn ($attributions) => array_map(fn () => 0.5, $attributions));

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $wpdb->shouldReceive('query')->with('START TRANSACTION')->once();
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_var')->andReturn('42');

        // Both hashes get 0.5 — two inserts.
        $insertedValues = [];
        $wpdb->shouldReceive('insert')
            ->twice()
            ->withArgs(function ($table, $data) use (&$insertedValues) {
                $insertedValues[] = $data['fractional_conversion'];
                return true;
            })
            ->andReturn(true);

        $wpdb->shouldReceive('query')->with('COMMIT')->once();

        Functions\expect('setcookie')->once();

        $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent';
        $_SERVER['REQUEST_URI']    = '/';

        $handler->handle_conversion();

        expect($insertedValues)->toBe([0.5, 0.5]);
    });

    it('wraps inserts in START TRANSACTION and COMMIT', function () {
        [$handler, $cm, $cis, $q, $qp] = make_conversion_handler();
        $hash = TestFactory::hash('transaction');
        $now  = 1700000000;

        Functions\when('time')->justReturn($now);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');
        $_COOKIE['_ad_last_conv'] = '0';

        $cm->shouldReceive('parse')->once()->andReturn([$hash => $now]);

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => $hashes,
        );

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        // Verify transaction ordering.
        $wpdb->shouldReceive('query')->with('START TRANSACTION')->once()->ordered();
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_var')->andReturn('42');
        $wpdb->shouldReceive('insert')->once()->andReturn(true)->ordered();
        $wpdb->shouldReceive('query')->with('COMMIT')->once()->ordered();

        Functions\expect('setcookie')->once();

        $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent';
        $_SERVER['REQUEST_URI']    = '/';

        $handler->handle_conversion();

        expect(true)->toBeTrue();
    });

    it('rolls back transaction on insert failure', function () {
        [$handler, $cm, $cis, $q, $qp] = make_conversion_handler();
        $hash = TestFactory::hash('rollback');
        $now  = 1700000000;

        Functions\when('time')->justReturn($now);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');
        $_COOKIE['_ad_last_conv'] = '0';

        $cm->shouldReceive('parse')->once()->andReturn([$hash => $now]);

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => $hashes,
        );

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $wpdb->shouldReceive('query')->with('START TRANSACTION')->once();
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_var')->andReturn('42');

        // Insert fails.
        $wpdb->shouldReceive('insert')->once()->andReturn(false);
        $wpdb->shouldReceive('query')->with('ROLLBACK')->once();

        // error_log called on failure.
        Functions\expect('error_log')->once();

        // setcookie should NOT be called after rollback.
        Functions\expect('setcookie')->never();

        $handler->handle_conversion();

        expect(true)->toBeTrue();
    });

    it('sets _ad_last_conv dedup cookie after successful attribution', function () {
        [$handler, $cm, $cis, $q, $qp] = make_conversion_handler();
        $hash = TestFactory::hash('dedup-cookie');
        $now  = 1700000000;

        Functions\when('time')->justReturn($now);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');
        $_COOKIE['_ad_last_conv'] = '0';

        $cm->shouldReceive('parse')->once()->andReturn([$hash => $now]);

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => $hashes,
        );

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;
        $wpdb->shouldReceive('query')->with('START TRANSACTION')->once();
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_var')->andReturn('42');
        $wpdb->shouldReceive('insert')->once()->andReturn(true);
        $wpdb->shouldReceive('query')->with('COMMIT')->once();

        // Verify setcookie args.
        Functions\expect('setcookie')
            ->once()
            ->withArgs(function ($name, $value, $options) use ($now) {
                expect($name)->toBe('_ad_last_conv');
                expect($value)->toBe((string) $now);
                expect($options['secure'])->toBeTrue();
                expect($options['httponly'])->toBeTrue();
                expect($options['samesite'])->toBe('Lax');
                return true;
            });

        $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent';
        $_SERVER['REQUEST_URI']    = '/';

        $handler->handle_conversion();

        expect(true)->toBeTrue();
    });

    it('fires kntnt_ad_attr_conversion_recorded action', function () {
        [$handler, $cm, $cis, $q, $qp] = make_conversion_handler();
        $hash = TestFactory::hash('recorded-action');
        $now  = 1700000000;

        Functions\when('time')->justReturn($now);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');
        $_COOKIE['_ad_last_conv'] = '0';

        $cm->shouldReceive('parse')->once()->andReturn([$hash => $now]);

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => $hashes,
        );

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;
        $wpdb->shouldReceive('query')->with('START TRANSACTION')->once();
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_var')->andReturn('42');
        $wpdb->shouldReceive('insert')->once()->andReturn(true);
        $wpdb->shouldReceive('query')->with('COMMIT')->once();
        Functions\expect('setcookie')->once();

        $_SERVER['REMOTE_ADDR']     = '10.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'ConversionBot';
        $_SERVER['REQUEST_URI']     = '/thanks/';

        // Expect the action to fire with attributions and context.
        Actions\expectDone('kntnt_ad_attr_conversion_recorded')
            ->once()
            ->with(
                Mockery::on(fn ($attr) => isset($attr[$hash]) && $attr[$hash] === 1.0),
                Mockery::on(fn ($ctx) => $ctx['ip'] === '10.0.0.1' && $ctx['user_agent'] === 'ConversionBot'),
            );

        $handler->handle_conversion();

        expect(true)->toBeTrue();
    });

    it('does not enqueue when no reporters registered', function () {
        [$handler, $cm, $cis, $q, $qp] = make_conversion_handler();
        $hash = TestFactory::hash('no-reporters');
        $now  = 1700000000;

        Functions\when('time')->justReturn($now);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');
        $_COOKIE['_ad_last_conv'] = '0';

        $cm->shouldReceive('parse')->once()->andReturn([$hash => $now]);

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => $hashes,
        );

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;
        $wpdb->shouldReceive('query')->with('START TRANSACTION')->once();
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_var')->andReturn('42');
        $wpdb->shouldReceive('insert')->once()->andReturn(true);
        $wpdb->shouldReceive('query')->with('COMMIT')->once();
        Functions\expect('setcookie')->once();

        $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent';
        $_SERVER['REQUEST_URI']    = '/';

        // No reporters — Queue should NOT be used.
        $q->shouldNotReceive('enqueue');
        $qp->shouldNotReceive('schedule');

        $handler->handle_conversion();

        expect(true)->toBeTrue();
    });

    it('enqueues conversion reports for registered reporters', function () {
        [$handler, $cm, $cis, $q, $qp] = make_conversion_handler();
        $hash = TestFactory::hash('reporter');
        $now  = 1700000000;

        Functions\when('time')->justReturn($now);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');
        $_COOKIE['_ad_last_conv'] = '0';

        $cm->shouldReceive('parse')->once()->andReturn([$hash => $now]);

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => $hashes,
        );

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;
        $wpdb->shouldReceive('query')->with('START TRANSACTION')->once();
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_var')->andReturn('42');
        $wpdb->shouldReceive('insert')->once()->andReturn(true);
        $wpdb->shouldReceive('query')->with('COMMIT')->once();
        Functions\expect('setcookie')->once();

        // get_campaign_data needs DB queries.
        $wpdb->shouldReceive('get_results')->andReturn([]);

        $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent';
        $_SERVER['REQUEST_URI']    = '/';

        Functions\when('home_url')->alias(fn ($path) => 'https://example.com' . $path);

        // Click ID store.
        $cis->shouldReceive('get_for_hashes')->once()->andReturn([]);

        // Register a reporter.
        $enqueueCalled = false;
        Filters\expectApplied('kntnt_ad_attr_conversion_reporters')
            ->once()
            ->andReturn([
                'test_reporter' => [
                    'enqueue' => function ($attributions, $click_ids, $campaigns, $context) use (&$enqueueCalled) {
                        $enqueueCalled = true;
                        return [['action' => 'test']];
                    },
                ],
            ]);

        // Queue should receive the payload.
        $q->shouldReceive('enqueue')
            ->once()
            ->with('test_reporter', ['action' => 'test']);

        $qp->shouldReceive('schedule')->once();

        $handler->handle_conversion();

        expect($enqueueCalled)->toBeTrue();
    });

    it('passes correct arguments to reporter enqueue callback', function () {
        [$handler, $cm, $cis, $q, $qp] = make_conversion_handler();
        $hash = TestFactory::hash('reporter-args');
        $now  = 1700000000;

        Functions\when('time')->justReturn($now);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');
        $_COOKIE['_ad_last_conv'] = '0';

        $cm->shouldReceive('parse')->once()->andReturn([$hash => $now]);

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => $hashes,
        );

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;
        $wpdb->shouldReceive('query')->with('START TRANSACTION')->once();
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_var')->andReturn('42');
        $wpdb->shouldReceive('insert')->once()->andReturn(true);
        $wpdb->shouldReceive('query')->with('COMMIT')->once();
        Functions\expect('setcookie')->once();
        $wpdb->shouldReceive('get_results')->andReturn([]);

        $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent';
        $_SERVER['REQUEST_URI']    = '/page/';

        Functions\when('home_url')->alias(fn ($path) => 'https://example.com' . $path);

        $cis->shouldReceive('get_for_hashes')->once()->andReturn(['platform_ids']);

        // Verify the callback receives correct argument types.
        Filters\expectApplied('kntnt_ad_attr_conversion_reporters')
            ->once()
            ->andReturn([
                'verify' => [
                    'enqueue' => function ($attributions, $click_ids, $campaigns, $context) use ($hash) {
                        expect($attributions)->toBeArray();
                        expect($attributions)->toHaveKey($hash);
                        expect($click_ids)->toBe(['platform_ids']);
                        expect($campaigns)->toBeArray();
                        expect($context)->toHaveKey('ip');
                        expect($context)->toHaveKey('user_agent');
                        expect($context)->toHaveKey('page_url');
                        expect($context['page_url'])->toBe('https://example.com/page/');
                        return [];
                    },
                ],
            ]);

        $qp->shouldReceive('schedule')->once();

        $handler->handle_conversion();

        expect(true)->toBeTrue();
    });

    it('skips reporters with non-callable enqueue', function () {
        [$handler, $cm, $cis, $q, $qp] = make_conversion_handler();
        $hash = TestFactory::hash('bad-reporter');
        $now  = 1700000000;

        Functions\when('time')->justReturn($now);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');
        $_COOKIE['_ad_last_conv'] = '0';

        $cm->shouldReceive('parse')->once()->andReturn([$hash => $now]);

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => $hashes,
        );

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;
        $wpdb->shouldReceive('query')->with('START TRANSACTION')->once();
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_var')->andReturn('42');
        $wpdb->shouldReceive('insert')->once()->andReturn(true);
        $wpdb->shouldReceive('query')->with('COMMIT')->once();
        Functions\expect('setcookie')->once();
        $wpdb->shouldReceive('get_results')->andReturn([]);

        $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent';
        $_SERVER['REQUEST_URI']    = '/';

        Functions\when('home_url')->alias(fn ($path) => 'https://example.com' . $path);
        $cis->shouldReceive('get_for_hashes')->once()->andReturn([]);

        // Reporter with missing enqueue — should be skipped.
        Filters\expectApplied('kntnt_ad_attr_conversion_reporters')
            ->once()
            ->andReturn(['broken' => ['enqueue' => 'not_a_function']]);

        // Queue should NOT receive anything.
        $q->shouldNotReceive('enqueue');
        $qp->shouldReceive('schedule')->once();

        $handler->handle_conversion();

        expect(true)->toBeTrue();
    });

});
