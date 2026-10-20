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
use Kntnt\Ad_Attribution\Consent;
use Kntnt\Ad_Attribution\Bot_Detector;
use Kntnt\Ad_Attribution\Click_ID_Store;
use Kntnt\Ad_Attribution\Queue;
use Kntnt\Ad_Attribution\Queue_Processor;
use Kntnt\Ad_Attribution\Logger;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Tests\Helpers\TestFactory;

/**
 * Creates a Conversion_Handler with fresh mocked dependencies.
 *
 * The Bot_Detector mock defaults to is_bot() => false and the Consent mock
 * defaults to check() => true so existing tests don't need to configure them.
 *
 * @return array{0: Conversion_Handler, 1: Mockery\MockInterface, 2: Mockery\MockInterface, 3: Mockery\MockInterface, 4: Mockery\MockInterface, 5: Mockery\MockInterface, 6: Mockery\MockInterface}
 */
function make_conversion_handler(): array {
    $cm  = Mockery::mock(Cookie_Manager::class);
    $con = Mockery::mock(Consent::class);
    $bd  = Mockery::mock(Bot_Detector::class);
    $cis = Mockery::mock(Click_ID_Store::class);
    $q   = Mockery::mock(Queue::class);
    $qp  = Mockery::mock(Queue_Processor::class);

    // Default: not a bot, consent granted.
    $bd->shouldReceive('is_bot')->andReturn(false)->byDefault();
    $con->shouldReceive('check')->andReturn(true)->byDefault();

    return [new Conversion_Handler($cm, $con, $bd, $cis, $q, $qp), $cm, $con, $bd, $cis, $q, $qp];
}

/**
 * Stubs the common functions and globals needed by handle_conversion().
 * Returns everything needed for the full conversion flow.
 */
function setup_conversion_path(): array {
    [$handler, $cm, $con, $bd, $cis, $q, $qp] = make_conversion_handler();

    $hash1 = TestFactory::hash('conv-old');
    $hash2 = TestFactory::hash('conv-new');
    $now   = 1700000000;

    // Fixed timestamps.
    Functions\when('time')->justReturn($now);
    Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');

    // Cookie returns two entries. hash2 is more recent.
    $cm->shouldReceive('parse')->with('_ad_clicks')->once()->andReturn([
        $hash1 => $now - 7200,
        $hash2 => $now - 3600,
    ]);

    // Dedup cookie empty — dedup disabled by default (0).
    $cm->shouldReceive('parse')->with('_ad_last_conv')->andReturn([]);

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
    $wpdb->shouldReceive('prepare')->andReturn('SQL')->byDefault();
    $wpdb->shouldReceive('get_var')->andReturn('42')->byDefault();
    $wpdb->shouldReceive('insert')->once()->andReturn(true);

    // $_SERVER for context.
    $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'TestAgent';
    $_SERVER['REQUEST_URI']    = '/thank-you/';

    return [$handler, $cm, $con, $bd, $cis, $q, $qp, $wpdb, $hash1, $hash2, $now];
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

    it('returns early when request is from a bot', function () {
        [$handler, $cm, $con, $bd] = make_conversion_handler();

        // Override the default to detect a bot.
        $bd->shouldReceive('is_bot')->andReturn(true);

        // Cookie should never be read.
        $cm->shouldNotReceive('parse');

        $handler->handle_conversion();

        expect(true)->toBeTrue();
    });

    it('returns early when consent is false', function () {
        [$handler, $cm, $con] = make_conversion_handler();

        // Override the default to deny consent.
        $con->shouldReceive('check')->andReturn(false);

        // Cookie should never be read — ePrivacy Art. 5(3).
        $cm->shouldNotReceive('parse');

        $handler->handle_conversion();

        expect(true)->toBeTrue();
    });

    it('returns early when consent is null (undetermined)', function () {
        [$handler, $cm, $con] = make_conversion_handler();

        // Override the default to undetermined consent.
        $con->shouldReceive('check')->andReturn(null);

        // Cookie should never be read — consent not confirmed.
        $cm->shouldNotReceive('parse');

        $handler->handle_conversion();

        expect(true)->toBeTrue();
    });

    it('returns early for empty _ad_clicks cookie', function () {
        [$handler, $cm] = make_conversion_handler();

        $cm->shouldReceive('parse')->with('_ad_clicks')->once()->andReturn([]);

        $handler->handle_conversion();

        // No DB operations means no $wpdb needed.
        expect(true)->toBeTrue();
    });

    it('returns early when no valid hashes remain', function () {
        [$handler, $cm] = make_conversion_handler();
        $hash = TestFactory::hash('invalid');

        $now = 1700000000;
        Functions\when('time')->justReturn($now);

        $cm->shouldReceive('parse')->with('_ad_clicks')->once()->andReturn([$hash => $now]);

        // No hashes are valid.
        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => [],
        );

        // No DB operations should happen.
        $handler->handle_conversion();

        expect(true)->toBeTrue();
    });

    it('skips hash when click_id is not found in clicks table', function () {
        [$handler, $cm, $con, $bd, $cis, $q, $qp] = make_conversion_handler();

        $hash_missing = TestFactory::hash('no-click-id');
        $hash_found   = TestFactory::hash('has-click-id');
        $now          = 1700000000;

        Functions\when('time')->justReturn($now);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');

        // Both hashes in cookie. hash_found is more recent.
        $cm->shouldReceive('parse')->with('_ad_clicks')->once()->andReturn([
            $hash_missing => $now - 7200,
            $hash_found   => $now - 3600,
        ]);

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => $hashes,
        );

        // Attribution gives full credit to the latest (hash_found).
        // hash_missing gets 0.0 and is skipped by the value <= 0 check,
        // so we test with a custom attribution that gives both hashes credit.
        Filters\expectApplied('kntnt_ad_attr_attribution')
            ->once()
            ->andReturnUsing(fn ($attributions) => array_map(fn () => 0.5, $attributions));

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $wpdb->shouldReceive('query')->with('START TRANSACTION')->once();
        $wpdb->shouldReceive('prepare')->andReturn('SQL');

        // First get_var for hash_missing returns null (click not found).
        // Second get_var for hash_found returns a valid click ID.
        $wpdb->shouldReceive('get_var')
            ->twice()
            ->andReturn(null, '42');

        // Only one insert -- hash_missing is skipped because its click_id is null.
        $wpdb->shouldReceive('insert')->once()->andReturn(true);
        $wpdb->shouldReceive('query')->with('COMMIT')->once();

        $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent';
        $_SERVER['REQUEST_URI']    = '/';

        $handler->handle_conversion();

        expect(true)->toBeTrue();
    });

    it('writes no conversion and logs one warning when no click row matches the cookie entry', function () {
        [, $cm, $con, $bd, $cis, $q, $qp] = make_conversion_handler();
        $logger  = Mockery::mock(Logger::class);
        $handler = new Conversion_Handler($cm, $con, $bd, $cis, $q, $qp, $logger);

        $hash  = TestFactory::hash('orphan-entry');
        $now   = 1700000000;
        $entry = $now - 3600;               // 2023-11-14 21:13:20 UTC

        Functions\when('time')->justReturn($now);

        $cm->shouldReceive('parse')->with('_ad_clicks')->once()->andReturn([$hash => $entry]);

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => $hashes,
        );

        // Capture prepared queries to prove only the exact match is tried.
        $prepared = [];
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;
        $wpdb->shouldReceive('prepare')->andReturnUsing(function (...$args) use (&$prepared) {
            $prepared[] = $args;
            return 'SQL';
        });
        $wpdb->shouldReceive('query')->with('START TRANSACTION')->once();
        $wpdb->shouldReceive('query')->with('COMMIT')->once();

        // The exact lookup misses, and no other row is tried.
        $wpdb->shouldReceive('get_var')->once()->andReturn(null);
        $wpdb->shouldNotReceive('get_row');
        $wpdb->shouldNotReceive('get_results');

        // No conversion row is written.
        $wpdb->shouldNotReceive('insert');

        $_SERVER['REMOTE_ADDR']     = '203.0.113.9';
        $_SERVER['HTTP_USER_AGENT'] = 'UniqueTestAgent/1.0';
        $_SERVER['REQUEST_URI']     = '/';

        // Exactly one warning naming the hash prefix and the entry timestamp,
        // without visitor-identifying data.
        $logged = [];
        $logger->shouldReceive('warning')->once()->andReturnUsing(function (...$args) use (&$logged) {
            $logged = $args;
        });
        $logger->shouldNotReceive('error');

        $handler->handle_conversion();

        expect($prepared)->toHaveCount(1);
        expect($prepared[0][0])->toContain('wp_kntnt_ad_attr_clicks');
        expect($prepared[0][0])->toContain('clicked_at = %s');
        expect(array_slice($prepared[0], 1))->toBe([$hash, '2023-11-14 21:13:20']);

        expect($logged[0])->toBe('CORE');
        expect($logged[1])->toContain(substr($hash, 0, 8));
        expect($logged[1])->toContain((string) $entry);
        expect($logged[1])->not->toContain($hash);
        expect($logged[1])->not->toContain('203.0.113.9');
        expect($logged[1])->not->toContain('UniqueTestAgent');
    });

    it('does not deduplicate when dedup_seconds is 0 (default)', function () {
        [$handler, $cm] = make_conversion_handler();
        $hash = TestFactory::hash('no-dedup');
        $now  = 1700000000;

        Functions\when('time')->justReturn($now);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');

        $cm->shouldReceive('parse')->with('_ad_clicks')->once()->andReturn([$hash => $now]);

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => $hashes,
        );

        // dedup_seconds = 0 → parse('_ad_last_conv') should NOT be called.
        $cm->shouldNotReceive('parse')->with('_ad_last_conv');
        $cm->shouldNotReceive('set_dedup_cookie');

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;
        $wpdb->shouldReceive('query')->with('START TRANSACTION')->once();
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_var')->andReturn('42');
        $wpdb->shouldReceive('insert')->once()->andReturn(true);
        $wpdb->shouldReceive('query')->with('COMMIT')->once();

        $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent';
        $_SERVER['REQUEST_URI']    = '/';

        $handler->handle_conversion();

        expect(true)->toBeTrue();
    });

    it('deduplicates per-hash when dedup_seconds > 0', function () {
        [$handler, $cm] = make_conversion_handler();
        $hash_a = TestFactory::hash('hash-a');
        $hash_b = TestFactory::hash('hash-b');
        $now    = 1700000000;

        Functions\when('time')->justReturn($now);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');

        // Both hashes in clicks cookie (hash_b is more recent).
        $cm->shouldReceive('parse')->with('_ad_clicks')->once()->andReturn([
            $hash_a => $now - 7200,
            $hash_b => $now - 3600,
        ]);

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => $hashes,
        );

        // Enable dedup at 1 hour.
        Filters\expectApplied('kntnt_ad_attr_dedup_seconds')->once()->andReturn(3600);

        // hash_a was converted 1800s ago (within window), hash_b not in dedup cookie.
        $cm->shouldReceive('parse')->with('_ad_last_conv')->once()->andReturn([
            $hash_a => $now - 1800,
        ]);

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;
        $wpdb->shouldReceive('query')->with('START TRANSACTION')->once();
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_var')->andReturn('42');

        // Only hash_b should be inserted (hash_a is deduped).
        $wpdb->shouldReceive('insert')->once()->andReturn(true);
        $wpdb->shouldReceive('query')->with('COMMIT')->once();

        // Dedup cookie should be written with hash_b.
        $cm->shouldReceive('set_dedup_cookie')
            ->once()
            ->withArgs(function (array $entries, int $lifetime) use ($hash_a, $hash_b, $now) {
                // Should contain both old (hash_a) and newly attributed (hash_b).
                expect($entries)->toHaveKey($hash_a);
                expect($entries)->toHaveKey($hash_b);
                expect($entries[$hash_b])->toBe($now);
                expect($lifetime)->toBe(3600);
                return true;
            });

        $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent';
        $_SERVER['REQUEST_URI']    = '/';

        $handler->handle_conversion();

        expect(true)->toBeTrue();
    });

    it('returns early when all hashes are deduped', function () {
        [$handler, $cm] = make_conversion_handler();
        $hash = TestFactory::hash('all-deduped');
        $now  = 1700000000;

        Functions\when('time')->justReturn($now);

        $cm->shouldReceive('parse')->with('_ad_clicks')->once()->andReturn([$hash => $now]);

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => $hashes,
        );

        // Enable dedup.
        Filters\expectApplied('kntnt_ad_attr_dedup_seconds')->once()->andReturn(3600);

        // Hash recently converted.
        $cm->shouldReceive('parse')->with('_ad_last_conv')->once()->andReturn([
            $hash => $now - 100,
        ]);

        // No DB operations should happen.
        $handler->handle_conversion();

        expect(true)->toBeTrue();
    });

    it('caps dedup_seconds to cookie lifetime', function () {
        [$handler, $cm] = make_conversion_handler();
        $hash = TestFactory::hash('capped');
        $now  = 1700000000;

        Functions\when('time')->justReturn($now);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');

        $cm->shouldReceive('parse')->with('_ad_clicks')->once()->andReturn([$hash => $now]);

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => $hashes,
        );

        // Cookie lifetime = 1 day, dedup_seconds = 200000 (> 86400).
        Filters\expectApplied('kntnt_ad_attr_cookie_lifetime')->once()->andReturn(1);
        Filters\expectApplied('kntnt_ad_attr_dedup_seconds')->once()->andReturn(200000);

        // Hash converted 90000s ago. Capped dedup = min(200000, 86400) = 86400.
        // 90000 > 86400 → not deduped.
        $cm->shouldReceive('parse')->with('_ad_last_conv')->once()->andReturn([
            $hash => $now - 90000,
        ]);

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;
        $wpdb->shouldReceive('query')->with('START TRANSACTION')->once();
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_var')->andReturn('42');
        $wpdb->shouldReceive('insert')->once()->andReturn(true);
        $wpdb->shouldReceive('query')->with('COMMIT')->once();

        $cm->shouldReceive('set_dedup_cookie')->once();

        $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent';
        $_SERVER['REQUEST_URI']    = '/';

        $handler->handle_conversion();

        expect(true)->toBeTrue();
    });

    it('filters to only valid hashes', function () {
        [$handler, $cm, $con, $bd, $cis, $q, $qp] = make_conversion_handler();

        $valid   = TestFactory::hash('valid');
        $invalid = TestFactory::hash('invalid');
        $now     = 1700000000;

        Functions\when('time')->justReturn($now);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');

        $cm->shouldReceive('parse')->with('_ad_clicks')->once()->andReturn([
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

        $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent';
        $_SERVER['REQUEST_URI']    = '/';

        $handler->handle_conversion();

        expect(true)->toBeTrue();
    });

    it('attributes 1.0 to latest click and 0.0 to older clicks', function () {
        [$handler, $cm, $con, $bd, $cis, $q, $qp] = make_conversion_handler();

        $hash_old = TestFactory::hash('old-click');
        $hash_new = TestFactory::hash('new-click');
        $now      = 1700000000;

        Functions\when('time')->justReturn($now);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');

        // hash_new is more recent.
        $cm->shouldReceive('parse')->with('_ad_clicks')->once()->andReturn([
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

        $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent';
        $_SERVER['REQUEST_URI']    = '/';

        $handler->handle_conversion();

        expect(true)->toBeTrue();
    });

    it('uses modified weights from attribution filter', function () {
        [$handler, $cm, $con, $bd, $cis, $q, $qp] = make_conversion_handler();

        $hash1 = TestFactory::hash('attr-1');
        $hash2 = TestFactory::hash('attr-2');
        $now   = 1700000000;

        Functions\when('time')->justReturn($now);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');

        $cm->shouldReceive('parse')->with('_ad_clicks')->once()->andReturn([
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

        $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent';
        $_SERVER['REQUEST_URI']    = '/';

        $handler->handle_conversion();

        expect($insertedValues)->toBe([0.5, 0.5]);
    });

    it('wraps inserts in START TRANSACTION and COMMIT', function () {
        [$handler, $cm, $con, $bd, $cis, $q, $qp] = make_conversion_handler();
        $hash = TestFactory::hash('transaction');
        $now  = 1700000000;

        Functions\when('time')->justReturn($now);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');

        $cm->shouldReceive('parse')->with('_ad_clicks')->once()->andReturn([$hash => $now]);

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

        $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent';
        $_SERVER['REQUEST_URI']    = '/';

        $handler->handle_conversion();

        expect(true)->toBeTrue();
    });

    it('rolls back transaction on insert failure', function () {
        [$handler, $cm, $con, $bd, $cis, $q, $qp] = make_conversion_handler();
        $hash = TestFactory::hash('rollback');
        $now  = 1700000000;

        Functions\when('time')->justReturn($now);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');

        $cm->shouldReceive('parse')->with('_ad_clicks')->once()->andReturn([$hash => $now]);

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

        // set_dedup_cookie should NOT be called after rollback.
        $cm->shouldNotReceive('set_dedup_cookie');

        $handler->handle_conversion();

        expect(true)->toBeTrue();
    });

    it('fires kntnt_ad_attr_conversion_recorded action', function () {
        [$handler, $cm, $con, $bd, $cis, $q, $qp] = make_conversion_handler();
        $hash = TestFactory::hash('recorded-action');
        $now  = 1700000000;

        Functions\when('time')->justReturn($now);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');

        $cm->shouldReceive('parse')->with('_ad_clicks')->once()->andReturn([$hash => $now]);

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
        [$handler, $cm, $con, $bd, $cis, $q, $qp] = make_conversion_handler();
        $hash = TestFactory::hash('no-reporters');
        $now  = 1700000000;

        Functions\when('time')->justReturn($now);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');

        $cm->shouldReceive('parse')->with('_ad_clicks')->once()->andReturn([$hash => $now]);

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
        [$handler, $cm, $con, $bd, $cis, $q, $qp] = make_conversion_handler();
        $hash = TestFactory::hash('reporter');
        $now  = 1700000000;

        Functions\when('time')->justReturn($now);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');

        $cm->shouldReceive('parse')->with('_ad_clicks')->once()->andReturn([$hash => $now]);

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

        // get_campaign_data needs DB queries.
        $wpdb->shouldReceive('get_results')->andReturn([]);

        $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent';
        $_SERVER['REQUEST_URI']    = '/';

        Functions\when('home_url')->alias(fn ($path) => 'https://example.com' . $path);

        // Click ID store.
        $cis->shouldReceive('get_for_clicks')->once()->andReturn([]);

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
        [$handler, $cm, $con, $bd, $cis, $q, $qp] = make_conversion_handler();
        $hash = TestFactory::hash('reporter-args');
        $now  = 1700000000;

        Functions\when('time')->justReturn($now);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');

        $cm->shouldReceive('parse')->with('_ad_clicks')->once()->andReturn([$hash => $now]);

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
        $wpdb->shouldReceive('get_results')->andReturn([]);

        $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent';
        $_SERVER['REQUEST_URI']    = '/page/';

        Functions\when('home_url')->alias(fn ($path) => 'https://example.com' . $path);

        $cis->shouldReceive('get_for_clicks')->once()->andReturn(['platform_ids']);

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

    it('passes not_before from structured reporter items to Queue::enqueue()', function () {
        [$handler, $cm, $con, $bd, $cis, $q, $qp] = make_conversion_handler();
        $hash = TestFactory::hash('not-before');
        $now  = 1700000000;

        Functions\when('time')->justReturn($now);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');

        $cm->shouldReceive('parse')->with('_ad_clicks')->once()->andReturn([$hash => $now]);

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
        $wpdb->shouldReceive('get_results')->andReturn([]);

        $_SERVER['REMOTE_ADDR']     = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent';
        $_SERVER['REQUEST_URI']     = '/';

        Functions\when('home_url')->alias(fn ($path) => 'https://example.com' . $path);

        $cis->shouldReceive('get_for_clicks')->once()->andReturn([]);

        // Register a reporter returning structured items with not_before.
        $not_before_ts = 1700021600;
        Filters\expectApplied('kntnt_ad_attr_conversion_reporters')
            ->once()
            ->andReturn([
                'deferred_reporter' => [
                    'enqueue' => fn () => [[
                        'payload'      => ['data' => 'test'],
                        'label'        => 'Deferred job',
                        'retry_params' => ['attempts_per_round' => 5],
                        'not_before'   => $not_before_ts,
                    ]],
                ],
            ]);

        // Queue should receive all 5 arguments including not_before.
        $q->shouldReceive('enqueue')
            ->once()
            ->withArgs(function (string $reporter, array $payload, string $label, array $retry_params, ?int $not_before) use ($not_before_ts) {
                expect($reporter)->toBe('deferred_reporter');
                expect($payload)->toBe(['data' => 'test']);
                expect($label)->toBe('Deferred job');
                expect($retry_params)->toBe(['attempts_per_round' => 5]);
                expect($not_before)->toBe($not_before_ts);
                return true;
            });

        $qp->shouldReceive('schedule')->once();

        $handler->handle_conversion();

        expect(true)->toBeTrue();
    });

    it('skips reporters with non-callable enqueue', function () {
        [$handler, $cm, $con, $bd, $cis, $q, $qp] = make_conversion_handler();
        $hash = TestFactory::hash('bad-reporter');
        $now  = 1700000000;

        Functions\when('time')->justReturn($now);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');

        $cm->shouldReceive('parse')->with('_ad_clicks')->once()->andReturn([$hash => $now]);

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
        $wpdb->shouldReceive('get_results')->andReturn([]);

        $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent';
        $_SERVER['REQUEST_URI']    = '/';

        Functions\when('home_url')->alias(fn ($path) => 'https://example.com' . $path);
        $cis->shouldReceive('get_for_clicks')->once()->andReturn([]);

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

// ─── Recorded attributions ───

describe('Conversion_Handler::handle_conversion() recorded attributions', function () {

    afterEach(function () {
        unset($GLOBALS['wpdb']);
        $_COOKIE = [];
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'], $_SERVER['REQUEST_URI']);
    });

    it('passes only the last-click hash with weight 1.0 to the recorded action', function () {
        [$handler, , , , , , , , , $hash2] = setup_conversion_path();

        $received = null;
        Actions\expectDone('kntnt_ad_attr_conversion_recorded')
            ->once()
            ->whenHappen(function ($attributions) use (&$received) {
                $received = $attributions;
            });

        $handler->handle_conversion();

        expect($received)->toBe([$hash2 => 1.0]);
    });

    it('passes only the last-click hash with weight 1.0 to reporters', function () {
        [$handler, , , , $cis, $q, $qp, $wpdb, , $hash2] = setup_conversion_path();

        $wpdb->shouldReceive('get_results')->andReturn([]);
        Functions\when('home_url')->alias(fn ($path) => 'https://example.com' . $path);
        $cis->shouldReceive('get_for_clicks')->andReturn([]);
        $qp->shouldReceive('schedule')->once();

        $received = null;
        Filters\expectApplied('kntnt_ad_attr_conversion_reporters')
            ->once()
            ->andReturn([
                'probe' => [
                    'enqueue' => function ($attributions) use (&$received) {
                        $received = $attributions;
                        return [];
                    },
                ],
            ]);

        $handler->handle_conversion();

        expect($received)->toBe([$hash2 => 1.0]);
    });

    it('notifies nobody when no click row matches any hash', function () {
        [$handler, $cm, , , $cis, $q, $qp] = make_conversion_handler();

        $hash_a = TestFactory::hash('orphan-a');
        $hash_b = TestFactory::hash('orphan-b');
        $now    = 1700000000;

        Functions\when('time')->justReturn($now);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');

        $cm->shouldReceive('parse')->with('_ad_clicks')->once()->andReturn([
            $hash_a => $now - 7200,
            $hash_b => $now - 3600,
        ]);

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => $hashes,
        );

        // Dedup enabled, so a dedup cookie would be written if anything were recorded.
        Filters\expectApplied('kntnt_ad_attr_dedup_seconds')->andReturn(3600);
        $cm->shouldReceive('parse')->with('_ad_last_conv')->andReturn([]);

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;
        $wpdb->shouldReceive('query')->with('START TRANSACTION')->once();
        $wpdb->shouldReceive('query')->with('COMMIT')->once();
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_var')->andReturn(null);
        $wpdb->shouldNotReceive('insert');

        $_SERVER['REMOTE_ADDR']     = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent';
        $_SERVER['REQUEST_URI']     = '/';

        Functions\when('home_url')->alias(fn ($path) => 'https://example.com' . $path);
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $cis->shouldReceive('get_for_clicks')->andReturn([]);
        $qp->shouldReceive('schedule');

        $enqueue_called = false;
        Filters\expectApplied('kntnt_ad_attr_conversion_reporters')
            ->andReturn([
                'probe' => [
                    'enqueue' => function () use (&$enqueue_called) {
                        $enqueue_called = true;
                        return [];
                    },
                ],
            ]);

        Actions\expectDone('kntnt_ad_attr_conversion_recorded')->never();
        $cm->shouldNotReceive('set_dedup_cookie');
        $q->shouldNotReceive('enqueue');

        $handler->handle_conversion();

        expect($enqueue_called)->toBeFalse();
    });

    it('passes the written weight unchanged when another hash has no click row', function () {
        [$handler, $cm, , , $cis, $q, $qp] = make_conversion_handler();

        $hash_a = TestFactory::hash('split-a');
        $hash_b = TestFactory::hash('split-b');
        $now    = 1700000000;

        Functions\when('time')->justReturn($now);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');

        $cm->shouldReceive('parse')->with('_ad_clicks')->once()->andReturn([
            $hash_a => $now - 7200,
            $hash_b => $now - 3600,
        ]);

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => $hashes,
        );

        Filters\expectApplied('kntnt_ad_attr_attribution')
            ->once()
            ->andReturn([$hash_a => 0.5, $hash_b => 0.5]);

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;
        $wpdb->shouldReceive('query')->with('START TRANSACTION')->once();
        $wpdb->shouldReceive('query')->with('COMMIT')->once();

        // Only hash A has a click row: the prepared lookup carries the hash
        // as its first argument, so the fake answers per hash.
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn ($sql, ...$args) => $args[0] ?? $sql);
        $wpdb->shouldReceive('get_var')->andReturnUsing(fn ($query) => $query === $hash_a ? '42' : null);
        $wpdb->shouldReceive('insert')->once()->andReturn(true);
        $wpdb->shouldReceive('get_results')->andReturn([]);

        $_SERVER['REMOTE_ADDR']     = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent';
        $_SERVER['REQUEST_URI']     = '/';

        Functions\when('home_url')->alias(fn ($path) => 'https://example.com' . $path);
        $cis->shouldReceive('get_for_clicks')->andReturn([]);
        $qp->shouldReceive('schedule')->once();

        $action_received = null;
        Actions\expectDone('kntnt_ad_attr_conversion_recorded')
            ->once()
            ->whenHappen(function ($attributions) use (&$action_received) {
                $action_received = $attributions;
            });

        $reporter_received = null;
        Filters\expectApplied('kntnt_ad_attr_conversion_reporters')
            ->once()
            ->andReturn([
                'probe' => [
                    'enqueue' => function ($attributions) use (&$reporter_received) {
                        $reporter_received = $attributions;
                        return [];
                    },
                ],
            ]);

        $handler->handle_conversion();

        expect($action_received)->toBe([$hash_a => 0.5])
            ->and($reporter_received)->toBe([$hash_a => 0.5]);
    });

    it('passes click IDs and campaigns for recorded hashes only', function () {
        [$handler, , , , $cis, $q, $qp, $wpdb, $hash1, $hash2] = setup_conversion_path();

        // Fake stores that answer for whichever hashes they are asked about.
        $cis->shouldReceive('get_for_clicks')->andReturnUsing(
            fn (array $linked_clicks) => array_fill_keys(array_keys($linked_clicks), ['google_ads' => ['id' => 'gclid', 'captured_at' => 1]]),
        );

        $prepared = [];
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($sql, ...$args) use (&$prepared) {
            $prepared[] = $args;
            return count($prepared) - 1;
        });
        $wpdb->shouldReceive('get_results')->andReturnUsing(function ($query) use (&$prepared, $hash1, $hash2) {
            $rows = [];
            foreach (array_intersect([$hash1, $hash2], $prepared[$query]) as $hash) {
                $rows[] = (object) [
                    'hash'                => $hash,
                    'meta_key'            => '_utm_source',
                    'meta_value'          => 'google',
                    'utm_content'         => 'c',
                    'utm_term'            => 't',
                    'utm_id'              => 'i',
                    'utm_source_platform' => 'p',
                ];
            }
            return $rows;
        });

        Functions\when('home_url')->alias(fn ($path) => 'https://example.com' . $path);
        $qp->shouldReceive('schedule')->once();

        $received_click_ids = null;
        $received_campaigns = null;
        Filters\expectApplied('kntnt_ad_attr_conversion_reporters')
            ->once()
            ->andReturn([
                'probe' => [
                    'enqueue' => function ($attributions, $click_ids, $campaigns) use (&$received_click_ids, &$received_campaigns) {
                        $received_click_ids = $click_ids;
                        $received_campaigns = $campaigns;
                        return [];
                    },
                ],
            ]);

        $handler->handle_conversion();

        expect(array_keys($received_click_ids))->toBe([$hash2])
            ->and(array_keys($received_campaigns))->toBe([$hash2]);
    });

});

// ─── Per-click reporting ───

/**
 * Builds a Conversion_Handler backed by a real Click_ID_Store and an
 * in-memory fake of the clicks, click-ID and postmeta tables.
 *
 * The fake answers each query from the table it names, filtered by the
 * prepared arguments: the click lookup by (hash, clicked_at), the click-ID
 * and per-click field lookups by click row ID, and postmeta by hash. One
 * probe reporter records what enqueue() receives.
 *
 * @param array<string, int>                $cookie    Hash => cookie timestamp.
 * @param array<int, array<string, mixed>>  $clicks    Click rows: id, hash, clicked_at, utm_*.
 * @param array<int, array<string, mixed>>  $click_ids Click-ID rows: click_id, platform, value.
 * @param array<string, array<string, string>> $meta   Hash => [meta_key => meta_value].
 *
 * @return array{0: Conversion_Handler, 1: \ArrayObject} Handler and the reporter's received arguments.
 */
function make_per_click_conversion(array $cookie, array $clicks, array $click_ids, array $meta = []): array {
    $cm  = Mockery::mock(Cookie_Manager::class);
    $con = Mockery::mock(Consent::class);
    $bd  = Mockery::mock(Bot_Detector::class);
    $q   = Mockery::mock(Queue::class);
    $qp  = Mockery::mock(Queue_Processor::class);

    $bd->shouldReceive('is_bot')->andReturn(false);
    $con->shouldReceive('check')->andReturn(true);
    $cm->shouldReceive('parse')->with('_ad_clicks')->andReturn($cookie);
    $qp->shouldReceive('schedule');

    \Patchwork\redefine(
        'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
        fn (array $hashes) => $hashes,
    );

    $by_id = array_column($clicks, null, 'id');

    $wpdb = TestFactory::wpdb();
    $GLOBALS['wpdb'] = $wpdb;
    $wpdb->shouldReceive('query');
    $wpdb->shouldReceive('insert')->andReturn(1);

    $prepared = [];
    $wpdb->shouldReceive('prepare')->andReturnUsing(function ($sql, ...$args) use (&$prepared) {
        $prepared[] = [$sql, $args];
        return count($prepared) - 1;
    });

    $wpdb->shouldReceive('get_var')->andReturnUsing(function ($token) use (&$prepared, $clicks) {
        [, [$hash, $clicked_at]] = $prepared[$token];
        foreach ($clicks as $click) {
            if ($click['hash'] === $hash && $click['clicked_at'] === $clicked_at) {
                return (string) $click['id'];
            }
        }
        return null;
    });

    $wpdb->shouldReceive('get_results')->andReturnUsing(function ($token) use (&$prepared, $by_id, $click_ids, $meta) {
        [$sql, $args] = $prepared[$token];

        if (str_contains($sql, 'kntnt_ad_attr_click_ids')) {
            $rows = [];
            foreach ($click_ids as $row) {
                if (in_array($row['click_id'], $args, true)) {
                    $rows[] = (object) ($row + ['clicked_at' => $by_id[$row['click_id']]['clicked_at']]);
                }
            }
            return $rows;
        }

        if (str_contains($sql, 'postmeta')) {
            $rows = [];
            foreach ($meta as $hash => $pairs) {
                if (in_array($hash, $args, true)) {
                    foreach ($pairs as $key => $value) {
                        $rows[] = (object) ['hash' => $hash, 'meta_key' => $key, 'meta_value' => $value];
                    }
                }
            }
            return $rows;
        }

        if (str_contains($sql, 'kntnt_ad_attr_clicks')) {
            return array_values(array_map(
                fn ($click) => (object) $click,
                array_filter($by_id, fn ($click) => in_array($click['id'], $args, true)),
            ));
        }

        return [];
    });

    $_SERVER['REMOTE_ADDR']     = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'TestAgent';
    $_SERVER['REQUEST_URI']     = '/thank-you/';
    Functions\when('home_url')->alias(fn ($path) => 'https://example.com' . $path);

    $received = new \ArrayObject();
    Filters\expectApplied('kntnt_ad_attr_conversion_reporters')->andReturn([
        'probe' => [
            'enqueue' => function ($attributions, $click_ids, $campaigns) use ($received) {
                $received['click_ids'] = $click_ids;
                $received['campaigns'] = $campaigns;
                return [];
            },
        ],
    ]);

    $handler = new Conversion_Handler($cm, $con, $bd, new Click_ID_Store(), $q, $qp);

    return [$handler, $received];
}

describe('Conversion_Handler::handle_conversion() per-click reporting', function () {

    afterEach(function () {
        unset($GLOBALS['wpdb']);
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'], $_SERVER['REQUEST_URI']);
    });

    it('reports the click ID of the attributed click, not a later click on the same hash', function () {
        $hash   = TestFactory::hash('per-click-ids');
        $first  = strtotime('2024-03-01 10:00:00 UTC');
        $second = strtotime('2024-03-01 10:05:00 UTC');

        // The visitor's cookie points at the first click.
        [$handler, $received] = make_per_click_conversion(
            [$hash => $first],
            [
                ['id' => 1, 'hash' => $hash, 'clicked_at' => '2024-03-01 10:00:00'],
                ['id' => 2, 'hash' => $hash, 'clicked_at' => '2024-03-01 10:05:00'],
            ],
            [
                ['click_id' => 1, 'platform' => 'google_ads', 'value' => 'AAA'],
                ['click_id' => 2, 'platform' => 'google_ads', 'value' => 'BBB'],
            ],
        );

        $handler->handle_conversion();

        expect($received['click_ids'])->toBe([
            $hash => ['google_ads' => ['id' => 'AAA', 'captured_at' => $first]],
        ]);
    });

    it('reports no click ID when the attributed click carried none, even if a later click did', function () {
        $hash = TestFactory::hash('per-click-no-id');

        [$handler, $received] = make_per_click_conversion(
            [$hash => strtotime('2024-03-01 10:00:00 UTC')],
            [
                ['id' => 1, 'hash' => $hash, 'clicked_at' => '2024-03-01 10:00:00'],
                ['id' => 2, 'hash' => $hash, 'clicked_at' => '2024-03-01 10:05:00'],
            ],
            [
                ['click_id' => 2, 'platform' => 'google_ads', 'value' => 'BBB'],
            ],
        );

        $handler->handle_conversion();

        expect($received['click_ids'])->toBe([]);
    });

    it('reports the per-click campaign fields of the attributed click', function () {
        $hash = TestFactory::hash('per-click-fields');

        [$handler, $received] = make_per_click_conversion(
            [$hash => strtotime('2024-03-01 10:00:00 UTC')],
            [
                [
                    'id'                  => 1,
                    'hash'                => $hash,
                    'clicked_at'          => '2024-03-01 10:00:00',
                    'utm_content'         => 'banner-a',
                    'utm_term'            => 'kwA',
                    'utm_id'              => 'id-a',
                    'utm_source_platform' => 'group-a',
                ],
                [
                    'id'                  => 2,
                    'hash'                => $hash,
                    'clicked_at'          => '2024-03-01 10:05:00',
                    'utm_content'         => 'banner-b',
                    'utm_term'            => 'kwB',
                    'utm_id'              => 'id-b',
                    'utm_source_platform' => 'group-b',
                ],
            ],
            [],
            [$hash => ['_utm_source' => 'google', '_utm_medium' => 'cpc', '_utm_campaign' => 'spring']],
        );

        $handler->handle_conversion();

        expect($received['campaigns'])->toBe([
            $hash => [
                'utm_source'          => 'google',
                'utm_medium'          => 'cpc',
                'utm_campaign'        => 'spring',
                'utm_content'         => 'banner-a',
                'utm_term'            => 'kwA',
                'utm_id'              => 'id-a',
                'utm_source_platform' => 'group-a',
            ],
        ]);
    });

});
