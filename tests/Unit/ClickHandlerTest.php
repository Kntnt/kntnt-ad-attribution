<?php
/**
 * Unit tests for Click_Handler.
 *
 * @package Tests\Unit
 * @since   1.0.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution\Click_Handler;
use Kntnt\Ad_Attribution\Cookie_Manager;
use Kntnt\Ad_Attribution\Consent;
use Kntnt\Ad_Attribution\Bot_Detector;
use Kntnt\Ad_Attribution\Click_ID_Store;
use Kntnt\Ad_Attribution\Speculation_Detector;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Tests\Helpers\TestFactory;

/**
 * Sentinel exception thrown from mocked functions to prevent exit().
 */
class ExitException extends \RuntimeException {}

/**
 * Creates a Click_Handler with fresh mocked dependencies.
 *
 * The speculation detector is the real one: it only reads request headers
 * through the kntnt_ad_attr_is_speculative filter, which Brain Monkey
 * passes through as false unless a test sets an expectation on it.
 *
 * @return array{0: Click_Handler, 1: Mockery\MockInterface, 2: Mockery\MockInterface, 3: Mockery\MockInterface, 4: Mockery\MockInterface}
 */
function make_click_handler(): array {
    $cm  = Mockery::mock(Cookie_Manager::class);
    $con = Mockery::mock(Consent::class);
    $bd  = Mockery::mock(Bot_Detector::class);
    $cis = Mockery::mock(Click_ID_Store::class);
    return [new Click_Handler($cm, $con, $bd, $cis, new Speculation_Detector()), $cm, $con, $bd, $cis];
}

/**
 * Stubs a request for a published tracking URL up to the bot check.
 *
 * The hash is valid, its tracking URL exists, and its target resolves to
 * the given URL. Query forwarding uses the real PHP URL functions.
 *
 * @return Mockery\MockInterface The mock wpdb, installed as $GLOBALS['wpdb'].
 */
function stub_tracking_url_request(Mockery\MockInterface $cm, string $hash, string $target_url = 'https://example.com/'): Mockery\MockInterface {
    Functions\expect('get_query_var')->once()->andReturn($hash);
    $cm->shouldReceive('validate_hash')->andReturn(true);

    $wpdb = TestFactory::wpdb();
    $wpdb->shouldReceive('prepare')->andReturn('SQL')->byDefault();
    $wpdb->shouldReceive('get_row')->once()->andReturn((object) ['ID' => 10, 'target_post_id' => '20']);
    $GLOBALS['wpdb'] = $wpdb;

    Functions\expect('get_permalink')->once()->andReturn($target_url);
    Functions\when('wp_parse_url')->alias(fn ($url, $comp) => parse_url($url, $comp));
    Functions\when('wp_parse_str')->alias(function ($str, &$result) { parse_str($str, $result); });
    Functions\when('add_query_arg')->alias(fn ($args, $url) => $url . ($args ? '?' . http_build_query($args) : ''));
    Functions\when('get_post_meta')->justReturn('existing');

    return $wpdb;
}

/**
 * Lets kntnt_ad_attr_is_speculative run the plugin's default callback,
 * which Brain Monkey would otherwise skip.
 */
function apply_default_speculation_detection(): void {
    Filters\expectApplied('kntnt_ad_attr_is_speculative')
        ->andReturnUsing(fn (bool $value) => (new Speculation_Detector())->detect($value));
}

/**
 * Stubs the WP functions needed to reach step N of handle_click().
 * Returns a mock wpdb and the handler's dependencies.
 */
function setup_happy_path(): array {
    [$handler, $cm, $con, $bd, $cis] = make_click_handler();

    $hash = TestFactory::hash('click-test');

    // Step 1: query var returns hash.
    Functions\expect('get_query_var')
        ->once()
        ->with('kntnt_ad_attr_hash', '')
        ->andReturn($hash);

    // Step 2: validate_hash returns true.
    $cm->shouldReceive('validate_hash')->with($hash)->andReturn(true);

    // Steps 3-5: DB lookup returns a row.
    $wpdb = TestFactory::wpdb();
    $row  = (object) ['ID' => 10, 'target_post_id' => '20'];
    $wpdb->shouldReceive('prepare')->andReturn('SQL');
    $wpdb->shouldReceive('get_row')->once()->andReturn($row);
    $GLOBALS['wpdb'] = $wpdb;

    // Step 6: target URL.
    Functions\expect('get_permalink')->once()->with(20)->andReturn('https://example.com/target/');

    // Query param forwarding stubs.
    Functions\when('wp_parse_url')->alias(fn ($url, $comp) => parse_url($url, $comp));
    Functions\when('wp_parse_str')->alias(function ($str, &$result) { parse_str($str, $result); });
    Functions\when('add_query_arg')->alias(fn ($args, $url) => $url . ($args ? '?' . http_build_query($args) : ''));

    // Step 8: redirect loop guard — prefix defaults to 'ad'.
    // Plugin::get_url_prefix() calls apply_filters, handled by Brain Monkey.

    // Step 9: bot check.
    $bd->shouldReceive('is_bot')->once()->andReturn(false);

    // Step 10: consent check (moved before DB insert for dedup support).
    $con->shouldReceive('check')->once()->andReturn(true);

    // Step 10b: dedup — default dedup_seconds is 0, so parse() for dedup
    // is not called. The kntnt_ad_attr_dedup_seconds filter fires via
    // Brain Monkey's passthrough.

    // Step 11: gmdate for click insert.
    Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');

    // Step 11: insert click record.
    $wpdb->shouldReceive('insert')->once()->andReturn(true);

    // Click ID capturers — none by default.

    // Postmeta — return existing values so no update.
    Functions\when('get_post_meta')->justReturn('existing');
    Functions\when('update_post_meta')->justReturn(true);

    // Step 12: consent-aware cookie setting (reuses consent_state from step 10).
    $cm->shouldReceive('parse')->andReturn([]);
    $cm->shouldReceive('add')->andReturn([$hash => time()]);
    $cm->shouldReceive('set_clicks_cookie')->once();

    // Step 12: redirect terminates.
    Functions\expect('nocache_headers')->once();
    Functions\expect('wp_redirect')->once()->andReturnUsing(function () {
        throw new ExitException('redirect');
    });

    return [$handler, $cm, $con, $bd, $cis, $wpdb, $hash];
}

// ─── register() ───

describe('Click_Handler::register()', function () {

    it('registers init, query_vars, and template_redirect hooks', function () {
        [$handler] = make_click_handler();

        Actions\expectAdded('init')->once();
        Filters\expectAdded('query_vars')->once();
        Actions\expectAdded('template_redirect')->once();

        $handler->register();

        // Explicit assertion so PHPUnit counts the test (Brain Monkey
        // hook expectations alone don't increment the assertion counter).
        expect(true)->toBeTrue();
    });

});

// ─── add_query_var() ───

describe('Click_Handler::add_query_var()', function () {

    it('appends kntnt_ad_attr_hash to vars array', function () {
        [$handler] = make_click_handler();

        $result = $handler->add_query_var(['existing_var']);

        expect($result)->toContain('existing_var');
        expect($result)->toContain('kntnt_ad_attr_hash');
    });

});

// ─── handle_click() ───

describe('Click_Handler::handle_click()', function () {

    afterEach(function () {
        unset($GLOBALS['wpdb']);
        $_GET = [];
    });

    it('returns early for empty query var', function () {
        [$handler] = make_click_handler();

        Functions\expect('get_query_var')
            ->once()
            ->with('kntnt_ad_attr_hash', '')
            ->andReturn('');

        // If it returns early, no further WP functions are called.
        $handler->handle_click();

        // Explicit assertion.
        expect(true)->toBeTrue();
    });

    it('sends 404 for invalid hash format', function () {
        [$handler, $cm] = make_click_handler();

        Functions\expect('get_query_var')
            ->once()
            ->with('kntnt_ad_attr_hash', '')
            ->andReturn('ZZZZ');

        $cm->shouldReceive('validate_hash')->with('ZZZZ')->andReturn(false);

        Functions\expect('status_header')->once()->with(404)->andReturnUsing(function () {
            throw new ExitException('404');
        });

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class);
    });

    it('sends 404 for hash with no matching CPT post', function () {
        [$handler, $cm] = make_click_handler();
        $hash = TestFactory::hash('no-match');

        Functions\expect('get_query_var')->once()->andReturn($hash);
        $cm->shouldReceive('validate_hash')->with($hash)->andReturn(true);

        $wpdb = TestFactory::wpdb();
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_row')->once()->andReturn(null);
        $GLOBALS['wpdb'] = $wpdb;

        Functions\expect('status_header')->once()->with(404)->andReturnUsing(function () {
            throw new ExitException('404');
        });

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class);
    });

    it('sends 404 when target post deleted (get_permalink returns false)', function () {
        [$handler, $cm] = make_click_handler();
        $hash = TestFactory::hash('deleted-target');

        Functions\expect('get_query_var')->once()->andReturn($hash);
        $cm->shouldReceive('validate_hash')->andReturn(true);

        $wpdb = TestFactory::wpdb();
        $row  = (object) ['ID' => 10, 'target_post_id' => '99'];
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_row')->once()->andReturn($row);
        $GLOBALS['wpdb'] = $wpdb;

        Functions\expect('get_permalink')->once()->with(99)->andReturn(false);
        Functions\expect('status_header')->once()->with(404)->andReturnUsing(function () {
            throw new ExitException('404');
        });

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class);
    });

    it('redirects bots without recording click', function () {
        [$handler, $cm, , $bd] = make_click_handler();
        $hash = TestFactory::hash('bot-click');

        Functions\expect('get_query_var')->once()->andReturn($hash);
        $cm->shouldReceive('validate_hash')->andReturn(true);

        $wpdb = TestFactory::wpdb();
        $row  = (object) ['ID' => 10, 'target_post_id' => '20'];
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_row')->once()->andReturn($row);
        $GLOBALS['wpdb'] = $wpdb;

        Functions\expect('get_permalink')->once()->with(20)->andReturn('https://example.com/');
        Functions\when('wp_parse_url')->alias(fn ($url, $comp) => parse_url($url, $comp));
        Functions\when('wp_parse_str')->alias(function ($str, &$result) { parse_str($str, $result); });
        Functions\when('add_query_arg')->alias(fn ($args, $url) => $url);

        // Bot detected — redirect without insert.
        $bd->shouldReceive('is_bot')->once()->andReturn(true);
        $wpdb->shouldNotReceive('insert');

        Functions\expect('nocache_headers')->once();
        Functions\expect('wp_redirect')->once()->andReturnUsing(function () {
            throw new ExitException('redirect');
        });

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class);
    });

    it('records click to clicks table for non-bot visitors', function () {
        [$handler, $cm, $con, $bd, $cis, $wpdb, $hash] = setup_happy_path();

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class);

        // Assertions are verified via Mockery expectations — insert() was expected once.
    });

    it('extracts per-click UTM fields from query params', function () {
        [$handler, $cm, $con, $bd, $cis] = make_click_handler();
        $hash = TestFactory::hash('utm-fields');

        $_GET['utm_content']         = 'ad-text';
        $_GET['utm_term']            = 'keyword';
        $_GET['utm_id']              = 'camp-123';
        $_GET['utm_source_platform'] = 'google';

        Functions\expect('get_query_var')->once()->andReturn($hash);
        $cm->shouldReceive('validate_hash')->andReturn(true);

        $wpdb = TestFactory::wpdb();
        $row  = (object) ['ID' => 10, 'target_post_id' => '20'];
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_row')->once()->andReturn($row);
        $GLOBALS['wpdb'] = $wpdb;

        Functions\expect('get_permalink')->once()->andReturn('https://example.com/');
        Functions\when('wp_parse_url')->alias(fn ($url, $comp) => parse_url($url, $comp));
        Functions\when('wp_parse_str')->alias(function ($str, &$result) { parse_str($str, $result); });
        Functions\when('add_query_arg')->alias(fn ($args, $url) => $url);

        $bd->shouldReceive('is_bot')->andReturn(false);
        $con->shouldReceive('check')->andReturn(true);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');

        // Verify insert receives the UTM fields.
        $wpdb->shouldReceive('insert')
            ->once()
            ->withArgs(function ($table, $data) {
                expect($data['utm_content'])->toBe('ad-text');
                expect($data['utm_term'])->toBe('keyword');
                expect($data['utm_id'])->toBe('camp-123');
                expect($data['utm_source_platform'])->toBe('google');
                return true;
            });

        Functions\when('get_post_meta')->justReturn('existing');
        $cm->shouldReceive('parse')->andReturn([]);
        $cm->shouldReceive('add')->andReturn([$hash => time()]);
        $cm->shouldReceive('set_clicks_cookie')->once();

        Functions\expect('nocache_headers')->once();
        Functions\expect('wp_redirect')->once()->andReturnUsing(fn () => throw new ExitException());

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class);
    });

    it('uses MTM fallback when UTM params empty', function () {
        [$handler, $cm, $con, $bd, $cis] = make_click_handler();
        $hash = TestFactory::hash('mtm-fallback');

        $_GET['mtm_content'] = 'mtm-text';
        $_GET['mtm_keyword'] = 'mtm-kw';

        Functions\expect('get_query_var')->once()->andReturn($hash);
        $cm->shouldReceive('validate_hash')->andReturn(true);

        $wpdb = TestFactory::wpdb();
        $row  = (object) ['ID' => 10, 'target_post_id' => '20'];
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_row')->once()->andReturn($row);
        $GLOBALS['wpdb'] = $wpdb;

        Functions\expect('get_permalink')->once()->andReturn('https://example.com/');
        Functions\when('wp_parse_url')->alias(fn ($url, $comp) => parse_url($url, $comp));
        Functions\when('wp_parse_str')->alias(function ($str, &$result) { parse_str($str, $result); });
        Functions\when('add_query_arg')->alias(fn ($args, $url) => $url);

        $bd->shouldReceive('is_bot')->andReturn(false);
        $con->shouldReceive('check')->andReturn(true);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');

        $wpdb->shouldReceive('insert')
            ->once()
            ->withArgs(function ($table, $data) {
                expect($data['utm_content'])->toBe('mtm-text');
                expect($data['utm_term'])->toBe('mtm-kw');
                return true;
            });

        Functions\when('get_post_meta')->justReturn('existing');
        $cm->shouldReceive('parse')->andReturn([]);
        $cm->shouldReceive('add')->andReturn([$hash => time()]);
        $cm->shouldReceive('set_clicks_cookie')->once();
        Functions\expect('nocache_headers')->once();
        Functions\expect('wp_redirect')->once()->andReturnUsing(fn () => throw new ExitException());

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class);
    });

    it('truncates UTM fields at 255 chars', function () {
        [$handler, $cm, $con, $bd] = make_click_handler();
        $hash = TestFactory::hash('long-utm');

        $_GET['utm_content'] = str_repeat('x', 300);

        Functions\expect('get_query_var')->once()->andReturn($hash);
        $cm->shouldReceive('validate_hash')->andReturn(true);

        $wpdb = TestFactory::wpdb();
        $row  = (object) ['ID' => 10, 'target_post_id' => '20'];
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_row')->once()->andReturn($row);
        $GLOBALS['wpdb'] = $wpdb;

        Functions\expect('get_permalink')->once()->andReturn('https://example.com/');
        Functions\when('wp_parse_url')->alias(fn ($url, $comp) => parse_url($url, $comp));
        Functions\when('wp_parse_str')->alias(function ($str, &$result) { parse_str($str, $result); });
        Functions\when('add_query_arg')->alias(fn ($args, $url) => $url);
        $bd->shouldReceive('is_bot')->andReturn(false);
        $con->shouldReceive('check')->andReturn(true);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');

        $wpdb->shouldReceive('insert')
            ->once()
            ->withArgs(function ($table, $data) {
                expect(mb_strlen($data['utm_content']))->toBe(255);
                return true;
            });

        Functions\when('get_post_meta')->justReturn('existing');
        $cm->shouldReceive('parse')->andReturn([]);
        $cm->shouldReceive('add')->andReturn([$hash => time()]);
        $cm->shouldReceive('set_clicks_cookie')->once();
        Functions\expect('nocache_headers')->once();
        Functions\expect('wp_redirect')->once()->andReturnUsing(fn () => throw new ExitException());

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class);
    });

    it('sets cookie when consent is true', function () {
        [$handler, $cm, $con, $bd, $cis, $wpdb, $hash] = setup_happy_path();

        // Consent already set to true in happy path — set_clicks_cookie expected once.
        expect(fn () => $handler->handle_click())->toThrow(ExitException::class);
    });

    it('skips cookie when consent is false', function () {
        [$handler, $cm, $con, $bd, $cis] = make_click_handler();
        $hash = TestFactory::hash('no-consent');

        Functions\expect('get_query_var')->once()->andReturn($hash);
        $cm->shouldReceive('validate_hash')->andReturn(true);

        $wpdb = TestFactory::wpdb();
        $row  = (object) ['ID' => 10, 'target_post_id' => '20'];
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_row')->once()->andReturn($row);
        $GLOBALS['wpdb'] = $wpdb;

        Functions\expect('get_permalink')->once()->andReturn('https://example.com/');
        Functions\when('wp_parse_url')->alias(fn ($url, $comp) => parse_url($url, $comp));
        Functions\when('wp_parse_str')->alias(function ($str, &$result) { parse_str($str, $result); });
        Functions\when('add_query_arg')->alias(fn ($args, $url) => $url);
        $bd->shouldReceive('is_bot')->andReturn(false);

        // Consent denied — checked before DB insert.
        $con->shouldReceive('check')->once()->andReturn(false);

        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');
        $wpdb->shouldReceive('insert')->once()->andReturn(true);
        Functions\when('get_post_meta')->justReturn('existing');

        // Cookie methods should NOT be called.
        $cm->shouldNotReceive('parse');
        $cm->shouldNotReceive('set_clicks_cookie');

        Functions\expect('nocache_headers')->once();
        Functions\expect('wp_redirect')->once()->andReturnUsing(fn () => throw new ExitException());

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class);
    });

    it('uses transport cookie when consent is null and transport is cookie', function () {
        [$handler, $cm, $con, $bd, $cis] = make_click_handler();
        $hash = TestFactory::hash('pending-cookie');

        Functions\expect('get_query_var')->once()->andReturn($hash);
        $cm->shouldReceive('validate_hash')->andReturn(true);

        $wpdb = TestFactory::wpdb();
        $row  = (object) ['ID' => 10, 'target_post_id' => '20'];
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_row')->once()->andReturn($row);
        $GLOBALS['wpdb'] = $wpdb;

        Functions\expect('get_permalink')->once()->andReturn('https://example.com/');
        Functions\when('wp_parse_url')->alias(fn ($url, $comp) => parse_url($url, $comp));
        Functions\when('wp_parse_str')->alias(function ($str, &$result) { parse_str($str, $result); });
        Functions\when('add_query_arg')->alias(fn ($args, $url) => $url);
        $bd->shouldReceive('is_bot')->andReturn(false);

        // Consent pending — checked before DB insert.
        $con->shouldReceive('check')->once()->andReturn(null);

        // The click row is recorded at 1700000000 (2023-11-14 22:13:20 UTC).
        Functions\when('time')->justReturn(1700000000);
        $wpdb->shouldReceive('insert')
            ->once()
            ->withArgs(fn ($table, $data) => $data['clicked_at'] === '2023-11-14 22:13:20')
            ->andReturn(true);
        Functions\when('get_post_meta')->justReturn('existing');

        // The transport cookie refers to that click row.
        $cm->shouldReceive('set_transport_cookie')->once()->with($hash, 1700000000);

        Functions\expect('nocache_headers')->once();
        Functions\expect('wp_redirect')->once()->andReturnUsing(fn () => throw new ExitException());

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class);
    });

    it('appends hash fragment when consent is null and transport is fragment', function () {
        [$handler, $cm, $con, $bd, $cis] = make_click_handler();
        $hash = TestFactory::hash('pending-fragment');

        Functions\expect('get_query_var')->once()->andReturn($hash);
        $cm->shouldReceive('validate_hash')->andReturn(true);

        $wpdb = TestFactory::wpdb();
        $row  = (object) ['ID' => 10, 'target_post_id' => '20'];
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_row')->once()->andReturn($row);
        $GLOBALS['wpdb'] = $wpdb;

        Functions\expect('get_permalink')->once()->andReturn('https://example.com/');
        Functions\when('wp_parse_url')->alias(fn ($url, $comp) => parse_url($url, $comp));
        Functions\when('wp_parse_str')->alias(function ($str, &$result) { parse_str($str, $result); });
        Functions\when('add_query_arg')->alias(fn ($args, $url) => $url);
        $bd->shouldReceive('is_bot')->andReturn(false);

        // Consent pending — checked before DB insert.
        $con->shouldReceive('check')->once()->andReturn(null);

        // The click row is recorded at 1700000000 (2023-11-14 22:13:20 UTC).
        Functions\when('time')->justReturn(1700000000);
        $wpdb->shouldReceive('insert')
            ->once()
            ->withArgs(fn ($table, $data) => $data['clicked_at'] === '2023-11-14 22:13:20')
            ->andReturn(true);
        Functions\when('get_post_meta')->justReturn('existing');

        // Transport set to fragment via filter.
        Filters\expectApplied('kntnt_ad_attr_pending_transport')
            ->once()
            ->andReturn('fragment');

        // Should NOT call set_transport_cookie.
        $cm->shouldNotReceive('set_transport_cookie');

        // The redirect URL's fragment refers to that click row.
        Functions\expect('nocache_headers')->once();
        Functions\expect('wp_redirect')
            ->once()
            ->withArgs(function ($url) use ($hash) {
                expect($url)->toEndWith('#_aah=' . $hash . ':1700000000');
                return true;
            })
            ->andReturnUsing(fn () => throw new ExitException());

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class);
    });

    it('sends 404 for redirect loop (target starts with prefix)', function () {
        [$handler, $cm, $con, $bd] = make_click_handler();
        $hash = TestFactory::hash('loop');

        Functions\expect('get_query_var')->once()->andReturn($hash);
        $cm->shouldReceive('validate_hash')->andReturn(true);

        $wpdb = TestFactory::wpdb();
        $row  = (object) ['ID' => 10, 'target_post_id' => '20'];
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_row')->once()->andReturn($row);
        $GLOBALS['wpdb'] = $wpdb;

        // Fetch the (possibly cached) prefix so the target URL triggers the
        // loop guard regardless of test ordering. Plugin::get_url_prefix()
        // uses a static cache, so a preceding test may have set it to a
        // non-default value (e.g. 'track' instead of 'ad').
        $prefix = \Kntnt\Ad_Attribution\Plugin::get_url_prefix();
        Functions\expect('get_permalink')->once()->andReturn("https://example.com/{$prefix}/some-hash");
        Functions\when('wp_parse_url')->alias(fn ($url, $comp) => parse_url($url, $comp));
        Functions\when('add_query_arg')->alias(fn ($args, $url) => $url);

        // error_log should be called for the loop detection.
        Functions\expect('error_log')->once();

        Functions\expect('status_header')->once()->with(404)->andReturnUsing(function () {
            throw new ExitException('404');
        });

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class);
    });

    it('fires kntnt_ad_attr_click action for non-bot clicks', function () {
        [$handler, $cm, $con, $bd, $cis, $wpdb, $hash] = setup_happy_path();

        Actions\expectDone('kntnt_ad_attr_click')
            ->once()
            ->with($hash, Mockery::type('string'), Mockery::type('array'));

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class);
    });

    it('stores the captured click ID on the click row it just inserted', function () {
        [$handler, $cm, $con, $bd, $cis] = make_click_handler();
        $hash = TestFactory::hash('click-id-test');

        $_GET['gclid'] = 'abc123';

        Functions\expect('get_query_var')->once()->andReturn($hash);
        $cm->shouldReceive('validate_hash')->andReturn(true);

        $wpdb = TestFactory::wpdb();
        $row  = (object) ['ID' => 10, 'target_post_id' => '20'];
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_row')->once()->andReturn($row);
        $GLOBALS['wpdb'] = $wpdb;

        Functions\expect('get_permalink')->once()->andReturn('https://example.com/');
        Functions\when('wp_parse_url')->alias(fn ($url, $comp) => parse_url($url, $comp));
        Functions\when('wp_parse_str')->alias(function ($str, &$result) { parse_str($str, $result); });
        Functions\when('add_query_arg')->alias(fn ($args, $url) => $url);
        $bd->shouldReceive('is_bot')->andReturn(false);
        $con->shouldReceive('check')->andReturn(true);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');

        // The click insert assigns row ID 77, the way wpdb sets insert_id.
        $wpdb->shouldReceive('insert')->once()->andReturnUsing(function () use ($wpdb) {
            $wpdb->insert_id = 77;
            return 1;
        });
        Functions\when('get_post_meta')->justReturn('existing');

        // Return click ID capturers via filter.
        Filters\expectApplied('kntnt_ad_attr_click_id_capturers')
            ->once()
            ->andReturn(['google' => 'gclid']);

        // The value is new for this tracking URL, so this is a new click.
        $cis->shouldReceive('find_original_click')->with($hash, 'google', 'abc123')->andReturn(null);

        // The click ID belongs to click row 77, not to the tracking URL.
        $cis->shouldReceive('store')->once()->with(77, 'google', 'abc123');

        $cm->shouldReceive('parse')->andReturn([]);
        $cm->shouldReceive('add')->andReturn([$hash => time()]);
        $cm->shouldReceive('set_clicks_cookie')->once();
        Functions\expect('nocache_headers')->once();
        Functions\expect('wp_redirect')->once()->andReturnUsing(fn () => throw new ExitException());

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class);
    });

    it('stores no click ID when the click row could not be inserted', function () {
        [$handler, $cm, $con, $bd, $cis] = make_click_handler();
        $hash = TestFactory::hash('click-id-insert-failed');

        $_GET['gclid'] = 'abc123';

        Functions\expect('get_query_var')->once()->andReturn($hash);
        $cm->shouldReceive('validate_hash')->andReturn(true);

        $wpdb = TestFactory::wpdb();
        $row  = (object) ['ID' => 10, 'target_post_id' => '20'];
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_row')->once()->andReturn($row);
        $GLOBALS['wpdb'] = $wpdb;

        Functions\expect('get_permalink')->once()->andReturn('https://example.com/');
        Functions\when('wp_parse_url')->alias(fn ($url, $comp) => parse_url($url, $comp));
        Functions\when('wp_parse_str')->alias(function ($str, &$result) { parse_str($str, $result); });
        Functions\when('add_query_arg')->alias(fn ($args, $url) => $url);
        $bd->shouldReceive('is_bot')->andReturn(false);
        $con->shouldReceive('check')->andReturn(true);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');
        $wpdb->shouldReceive('insert')->once()->andReturn(false);
        Functions\when('get_post_meta')->justReturn('existing');

        Filters\expectApplied('kntnt_ad_attr_click_id_capturers')
            ->andReturn(['google' => 'gclid']);
        $cis->shouldReceive('find_original_click')->andReturn(null);

        // Without a click row there is nothing to attach the click ID to.
        $cis->shouldNotReceive('store');

        $cm->shouldReceive('parse')->andReturn([]);
        $cm->shouldReceive('add')->andReturn([$hash => time()]);
        $cm->shouldReceive('set_clicks_cookie')->once();
        Functions\expect('nocache_headers')->once();
        Functions\expect('wp_redirect')->once()->andReturnUsing(fn () => throw new ExitException());

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class);
    });

    it('ignores click ID values longer than 255 chars', function () {
        [$handler, $cm, $con, $bd, $cis] = make_click_handler();
        $hash = TestFactory::hash('long-click-id');

        $_GET['gclid'] = str_repeat('x', 300);

        Functions\expect('get_query_var')->once()->andReturn($hash);
        $cm->shouldReceive('validate_hash')->andReturn(true);

        $wpdb = TestFactory::wpdb();
        $row  = (object) ['ID' => 10, 'target_post_id' => '20'];
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_row')->once()->andReturn($row);
        $GLOBALS['wpdb'] = $wpdb;

        Functions\expect('get_permalink')->once()->andReturn('https://example.com/');
        Functions\when('wp_parse_url')->alias(fn ($url, $comp) => parse_url($url, $comp));
        Functions\when('wp_parse_str')->alias(function ($str, &$result) { parse_str($str, $result); });
        Functions\when('add_query_arg')->alias(fn ($args, $url) => $url);
        $bd->shouldReceive('is_bot')->andReturn(false);
        $con->shouldReceive('check')->andReturn(true);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');
        $wpdb->shouldReceive('insert')->once()->andReturnUsing(function () use ($wpdb) {
            $wpdb->insert_id = 77;
            return 1;
        });
        Functions\when('get_post_meta')->justReturn('existing');

        Filters\expectApplied('kntnt_ad_attr_click_id_capturers')
            ->once()
            ->andReturn(['google' => 'gclid']);

        // store() should NOT be called — value too long.
        $cis->shouldNotReceive('store');

        $cm->shouldReceive('parse')->andReturn([]);
        $cm->shouldReceive('add')->andReturn([$hash => time()]);
        $cm->shouldReceive('set_clicks_cookie')->once();
        Functions\expect('nocache_headers')->once();
        Functions\expect('wp_redirect')->once()->andReturnUsing(fn () => throw new ExitException());

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class);
    });

    it('backfills empty postmeta from UTM params', function () {
        [$handler, $cm, $con, $bd, $cis] = make_click_handler();
        $hash = TestFactory::hash('backfill');

        $_GET['utm_source']   = 'google';
        $_GET['utm_medium']   = 'cpc';
        $_GET['utm_campaign'] = 'summer';

        Functions\expect('get_query_var')->once()->andReturn($hash);
        $cm->shouldReceive('validate_hash')->andReturn(true);

        $wpdb = TestFactory::wpdb();
        $row  = (object) ['ID' => 10, 'target_post_id' => '20'];
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_row')->once()->andReturn($row);
        $GLOBALS['wpdb'] = $wpdb;

        Functions\expect('get_permalink')->once()->andReturn('https://example.com/');
        Functions\when('wp_parse_url')->alias(fn ($url, $comp) => parse_url($url, $comp));
        Functions\when('wp_parse_str')->alias(function ($str, &$result) { parse_str($str, $result); });
        Functions\when('add_query_arg')->alias(fn ($args, $url) => $url);
        $bd->shouldReceive('is_bot')->andReturn(false);
        $con->shouldReceive('check')->andReturn(true);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');
        $wpdb->shouldReceive('insert')->once()->andReturn(true);

        // Return empty values for postmeta — triggers backfill.
        Functions\expect('get_post_meta')
            ->times(6) // 3 in param_map loop + 3 in do_action context
            ->andReturn('');

        Functions\expect('update_post_meta')
            ->times(3)
            ->withArgs(function ($id, $key, $value) {
                expect($id)->toBe(10);
                return true;
            });

        $cm->shouldReceive('parse')->andReturn([]);
        $cm->shouldReceive('add')->andReturn([$hash => time()]);
        $cm->shouldReceive('set_clicks_cookie')->once();
        Functions\expect('nocache_headers')->once();
        Functions\expect('wp_redirect')->once()->andReturnUsing(fn () => throw new ExitException());

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class);
    });

    it('does not overwrite existing postmeta', function () {
        [$handler, $cm, $con, $bd, $cis] = make_click_handler();
        $hash = TestFactory::hash('no-overwrite');

        $_GET['utm_source'] = 'google';

        Functions\expect('get_query_var')->once()->andReturn($hash);
        $cm->shouldReceive('validate_hash')->andReturn(true);

        $wpdb = TestFactory::wpdb();
        $row  = (object) ['ID' => 10, 'target_post_id' => '20'];
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_row')->once()->andReturn($row);
        $GLOBALS['wpdb'] = $wpdb;

        Functions\expect('get_permalink')->once()->andReturn('https://example.com/');
        Functions\when('wp_parse_url')->alias(fn ($url, $comp) => parse_url($url, $comp));
        Functions\when('wp_parse_str')->alias(function ($str, &$result) { parse_str($str, $result); });
        Functions\when('add_query_arg')->alias(fn ($args, $url) => $url);
        $bd->shouldReceive('is_bot')->andReturn(false);
        $con->shouldReceive('check')->andReturn(true);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');
        $wpdb->shouldReceive('insert')->once()->andReturn(true);

        // Existing meta values — update_post_meta should NOT be called.
        Functions\when('get_post_meta')->justReturn('existing_value');
        Functions\expect('update_post_meta')->never();

        $cm->shouldReceive('parse')->andReturn([]);
        $cm->shouldReceive('add')->andReturn([$hash => time()]);
        $cm->shouldReceive('set_clicks_cookie')->once();
        Functions\expect('nocache_headers')->once();
        Functions\expect('wp_redirect')->once()->andReturnUsing(fn () => throw new ExitException());

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class);
    });

    it('forwards query params to target URL', function () {
        [$handler, $cm, $con, $bd] = make_click_handler();
        $hash = TestFactory::hash('query-forward');

        $_GET['gclid']              = 'abc';
        $_GET['kntnt_ad_attr_hash'] = $hash; // Should be stripped.

        Functions\expect('get_query_var')->once()->andReturn($hash);
        $cm->shouldReceive('validate_hash')->andReturn(true);

        $wpdb = TestFactory::wpdb();
        $row  = (object) ['ID' => 10, 'target_post_id' => '20'];
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_row')->once()->andReturn($row);
        $GLOBALS['wpdb'] = $wpdb;

        // Use a URL without query params to avoid wp_parse_str pass-by-reference
        // issues through Brain Monkey's alias(). The merge behaviour is tested
        // implicitly by inspecting the final redirect URL.
        Functions\expect('get_permalink')->once()->andReturn('https://example.com/page/');
        Functions\when('wp_parse_url')->alias(fn ($url, $comp) => parse_url($url, $comp));
        Functions\when('add_query_arg')->alias(fn ($args, $url) => $url . '?' . http_build_query($args));

        $bd->shouldReceive('is_bot')->andReturn(false);
        $con->shouldReceive('check')->andReturn(true);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');
        $wpdb->shouldReceive('insert')->once()->andReturn(true);
        Functions\when('get_post_meta')->justReturn('existing');
        $cm->shouldReceive('parse')->andReturn([]);
        $cm->shouldReceive('add')->andReturn([$hash => time()]);
        $cm->shouldReceive('set_clicks_cookie')->once();
        Functions\expect('nocache_headers')->once();

        // Verify the redirect URL has forwarded params without the hash query var.
        Functions\expect('wp_redirect')
            ->once()
            ->withArgs(function ($url) {
                expect($url)->toContain('gclid=abc');
                expect($url)->not->toContain('kntnt_ad_attr_hash');
                return true;
            })
            ->andReturnUsing(fn () => throw new ExitException());

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class);
    });

    it('uses JS redirect when method filter returns js', function () {
        [$handler, $cm, $con, $bd] = make_click_handler();
        $hash = TestFactory::hash('js-redirect');

        Functions\expect('get_query_var')->once()->andReturn($hash);
        $cm->shouldReceive('validate_hash')->andReturn(true);

        $wpdb = TestFactory::wpdb();
        $row  = (object) ['ID' => 10, 'target_post_id' => '20'];
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_row')->once()->andReturn($row);
        $GLOBALS['wpdb'] = $wpdb;

        Functions\expect('get_permalink')->once()->andReturn('https://example.com/');
        Functions\when('wp_parse_url')->alias(fn ($url, $comp) => parse_url($url, $comp));
        Functions\when('add_query_arg')->alias(fn ($args, $url) => $url);
        $bd->shouldReceive('is_bot')->andReturn(false);
        $con->shouldReceive('check')->andReturn(true);
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');
        $wpdb->shouldReceive('insert')->once()->andReturn(true);
        Functions\when('get_post_meta')->justReturn('existing');
        $cm->shouldReceive('parse')->andReturn([]);
        $cm->shouldReceive('add')->andReturn([$hash => time()]);
        $cm->shouldReceive('set_clicks_cookie')->once();

        // JS redirect path — redirect() method calls js_redirect() which
        // uses `exit`. Brain Monkey's expect() cannot reliably override
        // stubEscapeFunctions()'s esc_js stub, so we use Patchwork directly
        // to redefine js_redirect() and throw before reaching `exit`.
        Filters\expectApplied('kntnt_ad_attr_redirect_method')
            ->once()
            ->andReturn('js');

        Functions\expect('nocache_headers')->once();
        Functions\expect('wp_redirect')->never();

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Click_Handler::js_redirect',
            fn (string $url) => throw new ExitException('js-redirect'),
        );

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class);
    });

    it('does not check dedup cookie when dedup_seconds is 0 and consent is true', function () {
        [$handler, $cm, $con, $bd, $cis] = make_click_handler();
        $hash = TestFactory::hash('dedup-disabled');
        $now  = 1700000000;

        Functions\expect('get_query_var')->once()->andReturn($hash);
        $cm->shouldReceive('validate_hash')->andReturn(true);

        $wpdb = TestFactory::wpdb();
        $row  = (object) ['ID' => 10, 'target_post_id' => '20'];
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_row')->once()->andReturn($row);
        $GLOBALS['wpdb'] = $wpdb;

        Functions\expect('get_permalink')->once()->andReturn('https://example.com/');
        Functions\when('wp_parse_url')->alias(fn ($url, $comp) => parse_url($url, $comp));
        Functions\when('wp_parse_str')->alias(function ($str, &$result) { parse_str($str, $result); });
        Functions\when('add_query_arg')->alias(fn ($args, $url) => $url);
        $bd->shouldReceive('is_bot')->andReturn(false);
        Functions\when('time')->justReturn($now);

        // Consent granted but dedup disabled (default 0).
        $con->shouldReceive('check')->once()->andReturn(true);
        Filters\expectApplied('kntnt_ad_attr_dedup_seconds')->once()->andReturn(0);

        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');
        $wpdb->shouldReceive('insert')->once()->andReturn(true);
        Functions\when('get_post_meta')->justReturn('existing');

        // parse() should be called exactly once -- in set_cookie() for merging
        // existing entries, NOT for dedup checking. The dedup branch is skipped
        // entirely when dedup_seconds is 0.
        $cm->shouldReceive('parse')->once()->andReturn([$hash => $now - 5000]);
        $cm->shouldReceive('add')->once()->andReturn([$hash => $now]);
        $cm->shouldReceive('set_clicks_cookie')->once();

        Functions\expect('nocache_headers')->once();
        Functions\expect('wp_redirect')->once()->andReturnUsing(fn () => throw new ExitException());

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class);
    });

    it('keeps the original cookie timestamp and skips DB insert on dedup hit with a matching click row', function () {
        [$handler, $cm, $con, $bd, $cis] = make_click_handler();
        $hash     = TestFactory::hash('dedup-hit');
        $now      = 1700000000;             // 2023-11-14 22:13:20 UTC
        $original = $now - 1800;            // 2023-11-14 21:43:20 UTC

        Functions\expect('get_query_var')->once()->andReturn($hash);
        $cm->shouldReceive('validate_hash')->andReturn(true);

        // Capture every prepared query so the click-row lookup can be inspected.
        $prepared = [];
        $wpdb = TestFactory::wpdb();
        $row  = (object) ['ID' => 10, 'target_post_id' => '20'];
        $wpdb->shouldReceive('prepare')->andReturnUsing(function (...$args) use (&$prepared) {
            $prepared[] = $args;
            return 'SQL';
        });
        $wpdb->shouldReceive('get_row')->once()->andReturn($row);
        $GLOBALS['wpdb'] = $wpdb;

        Functions\expect('get_permalink')->once()->andReturn('https://example.com/');
        Functions\when('wp_parse_url')->alias(fn ($url, $comp) => parse_url($url, $comp));
        Functions\when('wp_parse_str')->alias(function ($str, &$result) { parse_str($str, $result); });
        Functions\when('add_query_arg')->alias(fn ($args, $url) => $url);
        $bd->shouldReceive('is_bot')->andReturn(false);
        Functions\when('time')->justReturn($now);

        // Consent granted + dedup enabled.
        $con->shouldReceive('check')->once()->andReturn(true);
        Filters\expectApplied('kntnt_ad_attr_dedup_seconds')->once()->andReturn(3600);

        // The visitor's entry for the hash is 30 minutes old (inside the window).
        $cm->shouldReceive('parse')->andReturn([$hash => $original]);

        // A click row exists for exactly (hash, original timestamp).
        $wpdb->shouldReceive('get_var')->once()->andReturn('7');

        // Dedup hit — DB insert should NOT happen.
        $wpdb->shouldNotReceive('insert');

        // The cookie is re-sent with the ORIGINAL timestamp, not the current time.
        $cm->shouldReceive('add')->once()->with([$hash => $original], $hash, $original)->andReturn([$hash => $original]);
        $cm->shouldReceive('set_clicks_cookie')->once()->with([$hash => $original]);

        Functions\expect('nocache_headers')->once();
        Functions\expect('wp_redirect')->once()->andReturnUsing(fn () => throw new ExitException());

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class);

        // The existence check matches the hash and the entry's timestamp exactly.
        $lookup = array_values(array_filter($prepared, fn ($args) => str_contains($args[0], 'clicked_at')));
        expect($lookup)->toHaveCount(1);
        expect($lookup[0][0])->toContain('wp_kntnt_ad_attr_clicks');
        expect(array_slice($lookup[0], 1))->toBe([$hash, '2023-11-14 21:43:20']);
    });

    it('records a new click and sets the entry to the new row time when no click row matches the entry', function () {
        [$handler, $cm, $con, $bd, $cis] = make_click_handler();
        $hash     = TestFactory::hash('dedup-self-heal');
        $now      = 1700000000;             // 2023-11-14 22:13:20 UTC
        $original = $now - 1800;

        Functions\expect('get_query_var')->once()->andReturn($hash);
        $cm->shouldReceive('validate_hash')->andReturn(true);

        $wpdb = TestFactory::wpdb();
        $row  = (object) ['ID' => 10, 'target_post_id' => '20'];
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_row')->once()->andReturn($row);
        $GLOBALS['wpdb'] = $wpdb;

        Functions\expect('get_permalink')->once()->andReturn('https://example.com/');
        Functions\when('wp_parse_url')->alias(fn ($url, $comp) => parse_url($url, $comp));
        Functions\when('wp_parse_str')->alias(function ($str, &$result) { parse_str($str, $result); });
        Functions\when('add_query_arg')->alias(fn ($args, $url) => $url);
        $bd->shouldReceive('is_bot')->andReturn(false);
        Functions\when('time')->justReturn($now);
        Functions\when('get_post_meta')->justReturn('existing');

        // Consent granted + dedup enabled, entry inside the window.
        $con->shouldReceive('check')->once()->andReturn(true);
        Filters\expectApplied('kntnt_ad_attr_dedup_seconds')->once()->andReturn(3600);
        $cm->shouldReceive('parse')->andReturn([$hash => $original]);

        // No click row for the entry (e.g. a cookie rewritten by an older version).
        $wpdb->shouldReceive('get_var')->once()->andReturn(null);

        // Handled as a new click: a row is inserted at the current time …
        $wpdb->shouldReceive('insert')
            ->once()
            ->with('wp_kntnt_ad_attr_clicks', Mockery::on(fn ($data) => $data['hash'] === $hash && $data['clicked_at'] === '2023-11-14 22:13:20'))
            ->andReturn(true);

        // … and the entry is moved to that row's timestamp.
        $cm->shouldReceive('add')->once()->with([$hash => $original], $hash, $now)->andReturn([$hash => $now]);
        $cm->shouldReceive('set_clicks_cookie')->once()->with([$hash => $now]);

        Functions\expect('nocache_headers')->once();
        Functions\expect('wp_redirect')->once()->andReturnUsing(fn () => throw new ExitException());

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class);
    });

    it('records click when consent true but hash outside dedup window', function () {
        [$handler, $cm, $con, $bd, $cis] = make_click_handler();
        $hash = TestFactory::hash('dedup-expired');
        $now  = 1700000000;

        Functions\expect('get_query_var')->once()->andReturn($hash);
        $cm->shouldReceive('validate_hash')->andReturn(true);

        $wpdb = TestFactory::wpdb();
        $row  = (object) ['ID' => 10, 'target_post_id' => '20'];
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_row')->once()->andReturn($row);
        $GLOBALS['wpdb'] = $wpdb;

        Functions\expect('get_permalink')->once()->andReturn('https://example.com/');
        Functions\when('wp_parse_url')->alias(fn ($url, $comp) => parse_url($url, $comp));
        Functions\when('wp_parse_str')->alias(function ($str, &$result) { parse_str($str, $result); });
        Functions\when('add_query_arg')->alias(fn ($args, $url) => $url);
        $bd->shouldReceive('is_bot')->andReturn(false);
        Functions\when('time')->justReturn($now);

        // Consent granted + dedup enabled.
        $con->shouldReceive('check')->once()->andReturn(true);
        Filters\expectApplied('kntnt_ad_attr_dedup_seconds')->once()->andReturn(3600);

        // parse() called in dedup check and again in set_cookie().
        // Hash from 5000 seconds ago (outside 3600 window) → not a dedup hit.
        $cm->shouldReceive('parse')->andReturn([
            $hash => $now - 5000,
        ]);

        // Not a dedup hit — insert should happen.
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');
        $wpdb->shouldReceive('insert')->once()->andReturn(true);
        Functions\when('get_post_meta')->justReturn('existing');

        // Cookie set in consent handling at end.
        $cm->shouldReceive('add')->andReturn([$hash => $now]);
        $cm->shouldReceive('set_clicks_cookie')->once();

        Functions\expect('nocache_headers')->once();
        Functions\expect('wp_redirect')->once()->andReturnUsing(fn () => throw new ExitException());

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class);
    });

    it('records click when consent true, dedup enabled, but no existing cookie entry', function () {
        [$handler, $cm, $con, $bd, $cis] = make_click_handler();
        $hash = TestFactory::hash('dedup-no-cookie');
        $now  = 1700000000;

        Functions\expect('get_query_var')->once()->andReturn($hash);
        $cm->shouldReceive('validate_hash')->andReturn(true);

        $wpdb = TestFactory::wpdb();
        $row  = (object) ['ID' => 10, 'target_post_id' => '20'];
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_row')->once()->andReturn($row);
        $GLOBALS['wpdb'] = $wpdb;

        Functions\expect('get_permalink')->once()->andReturn('https://example.com/');
        Functions\when('wp_parse_url')->alias(fn ($url, $comp) => parse_url($url, $comp));
        Functions\when('wp_parse_str')->alias(function ($str, &$result) { parse_str($str, $result); });
        Functions\when('add_query_arg')->alias(fn ($args, $url) => $url);
        $bd->shouldReceive('is_bot')->andReturn(false);
        Functions\when('time')->justReturn($now);

        // Consent granted + dedup enabled.
        $con->shouldReceive('check')->once()->andReturn(true);
        Filters\expectApplied('kntnt_ad_attr_dedup_seconds')->once()->andReturn(3600);

        // parse() called in dedup check and again in set_cookie().
        // Empty cookie — hash not present → not a dedup hit.
        $cm->shouldReceive('parse')->andReturn([]);

        // No dedup hit — insert should happen.
        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');
        $wpdb->shouldReceive('insert')->once()->andReturn(true);
        Functions\when('get_post_meta')->justReturn('existing');

        $cm->shouldReceive('add')->andReturn([$hash => $now]);
        $cm->shouldReceive('set_clicks_cookie')->once();

        Functions\expect('nocache_headers')->once();
        Functions\expect('wp_redirect')->once()->andReturnUsing(fn () => throw new ExitException());

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class);
    });

    it('records click when consent false even within dedup window', function () {
        [$handler, $cm, $con, $bd, $cis] = make_click_handler();
        $hash = TestFactory::hash('dedup-no-consent');

        Functions\expect('get_query_var')->once()->andReturn($hash);
        $cm->shouldReceive('validate_hash')->andReturn(true);

        $wpdb = TestFactory::wpdb();
        $row  = (object) ['ID' => 10, 'target_post_id' => '20'];
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_row')->once()->andReturn($row);
        $GLOBALS['wpdb'] = $wpdb;

        Functions\expect('get_permalink')->once()->andReturn('https://example.com/');
        Functions\when('wp_parse_url')->alias(fn ($url, $comp) => parse_url($url, $comp));
        Functions\when('wp_parse_str')->alias(function ($str, &$result) { parse_str($str, $result); });
        Functions\when('add_query_arg')->alias(fn ($args, $url) => $url);
        $bd->shouldReceive('is_bot')->andReturn(false);

        // Consent denied — dedup cannot read cookie, so click always recorded.
        $con->shouldReceive('check')->once()->andReturn(false);

        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');
        $wpdb->shouldReceive('insert')->once()->andReturn(true);
        Functions\when('get_post_meta')->justReturn('existing');

        // No cookie operations since consent is false.
        $cm->shouldNotReceive('parse');
        $cm->shouldNotReceive('set_clicks_cookie');

        Functions\expect('nocache_headers')->once();
        Functions\expect('wp_redirect')->once()->andReturnUsing(fn () => throw new ExitException());

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class);
    });

    it('records click when consent null even within dedup window', function () {
        [$handler, $cm, $con, $bd, $cis] = make_click_handler();
        $hash = TestFactory::hash('dedup-null-consent');

        Functions\expect('get_query_var')->once()->andReturn($hash);
        $cm->shouldReceive('validate_hash')->andReturn(true);

        $wpdb = TestFactory::wpdb();
        $row  = (object) ['ID' => 10, 'target_post_id' => '20'];
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_row')->once()->andReturn($row);
        $GLOBALS['wpdb'] = $wpdb;

        Functions\expect('get_permalink')->once()->andReturn('https://example.com/');
        Functions\when('wp_parse_url')->alias(fn ($url, $comp) => parse_url($url, $comp));
        Functions\when('wp_parse_str')->alias(function ($str, &$result) { parse_str($str, $result); });
        Functions\when('add_query_arg')->alias(fn ($args, $url) => $url);
        $bd->shouldReceive('is_bot')->andReturn(false);

        // Consent undetermined — dedup cannot read cookie, so click always recorded.
        $con->shouldReceive('check')->once()->andReturn(null);

        Functions\when('gmdate')->justReturn('2024-01-01 12:00:00');
        $wpdb->shouldReceive('insert')->once()->andReturn(true);
        Functions\when('get_post_meta')->justReturn('existing');

        // Transport cookie for pending consent.
        $cm->shouldReceive('set_transport_cookie')->once()->with($hash, Mockery::type('int'));

        Functions\expect('nocache_headers')->once();
        Functions\expect('wp_redirect')->once()->andReturnUsing(fn () => throw new ExitException());

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class);
    });

});

// ─── handle_click(): speculative requests ───

describe('Click_Handler::handle_click() speculative requests', function () {

    afterEach(function () {
        unset($GLOBALS['wpdb'], $_SERVER['HTTP_SEC_PURPOSE'], $_SERVER['HTTP_PURPOSE']);
        $_GET = [];
    });

    it('declines a speculative request with an uncacheable 503 and records nothing', function (string $header, string $value) {
        [$handler, $cm, $con, $bd, $cis] = make_click_handler();
        $hash = TestFactory::hash('speculative');
        $wpdb = stub_tracking_url_request($cm, $hash);

        $_SERVER[$header] = $value;
        $bd->shouldReceive('is_bot')->andReturn(false);
        apply_default_speculation_detection();

        // No click row, no click ID, no cookie, no transport, no action.
        $wpdb->shouldNotReceive('insert');
        $cis->shouldNotReceive('store');
        $cm->shouldNotReceive('set_clicks_cookie');
        $cm->shouldNotReceive('set_transport_cookie');
        Actions\expectDone('kntnt_ad_attr_click')->never();

        // A non-2xx, non-redirect, uncacheable response.
        Functions\expect('wp_redirect')->never();
        Functions\expect('status_header')->once()->with(503);
        Functions\expect('nocache_headers')->once()->andReturnUsing(fn () => throw new ExitException('503'));

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class, '503');
    })->with([
        'Sec-Purpose: prefetch'           => ['HTTP_SEC_PURPOSE', 'prefetch'],
        'Sec-Purpose: prefetch;prerender' => ['HTTP_SEC_PURPOSE', 'prefetch;prerender'],
        'Purpose: prefetch'               => ['HTTP_PURPOSE', 'prefetch'],
    ]);

    it('records a prefetch as a click when a kntnt_ad_attr_is_speculative callback overrules detection', function () {
        [$handler, $cm, $con, $bd, $cis] = make_click_handler();
        $hash = TestFactory::hash('speculative-overruled');
        $wpdb = stub_tracking_url_request($cm, $hash);

        $_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch';
        $bd->shouldReceive('is_bot')->andReturn(false);
        Filters\expectApplied('kntnt_ad_attr_is_speculative')->once()->andReturn(false);

        $con->shouldReceive('check')->andReturn(false);
        $wpdb->shouldReceive('insert')->once()->andReturn(true);

        Functions\expect('status_header')->never();
        Functions\expect('nocache_headers')->once();
        Functions\expect('wp_redirect')->once()->andReturnUsing(fn () => throw new ExitException('redirect'));

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class, 'redirect');
    });

});

// ─── handle_click(): repeat click IDs ───

/**
 * Stubs a request whose gclid was recorded on an earlier click of the
 * tracking URL, at $original_at, and that ends in a redirect.
 *
 * @return Mockery\MockInterface The mock wpdb, which expects no insert.
 */
function stub_repeat_request(Mockery\MockInterface $cm, Mockery\MockInterface $bd, Mockery\MockInterface $cis, string $hash, int $original_at): Mockery\MockInterface {
    $wpdb = stub_tracking_url_request($cm, $hash);

    $_GET['gclid'] = 'RRR';
    Filters\expectApplied('kntnt_ad_attr_click_id_capturers')->andReturn(['google_ads' => 'gclid']);
    $bd->shouldReceive('is_bot')->andReturn(false);
    $cis->shouldReceive('find_original_click')
        ->with($hash, 'google_ads', 'RRR')
        ->andReturn(['click_id' => 7, 'clicked_at' => $original_at]);

    $wpdb->shouldNotReceive('insert');
    $cis->shouldNotReceive('store');

    Functions\expect('nocache_headers')->once();
    Functions\expect('wp_redirect')->once()->andReturnUsing(fn () => throw new ExitException('redirect'));

    return $wpdb;
}

describe('Click_Handler::handle_click() repeat click IDs', function () {

    afterEach(function () {
        unset($GLOBALS['wpdb']);
        $_GET = [];
    });

    it('points the cookie entry at the original click when consent is granted and the hash has no entry', function () {
        [$handler, $cm, $con, $bd, $cis] = make_click_handler();
        $hash     = TestFactory::hash('repeat-no-entry');
        $original = 1699990000;
        stub_repeat_request($cm, $bd, $cis, $hash, $original);
        $con->shouldReceive('check')->andReturn(true);

        $cm->shouldReceive('parse')->andReturn([]);
        $cm->shouldReceive('add')->once()->with([], $hash, $original)->andReturn([$hash => $original]);
        $cm->shouldReceive('set_clicks_cookie')->once()->with([$hash => $original]);

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class, 'redirect');
    });

    it('moves an older cookie entry forward to the original click when consent is granted', function () {
        [$handler, $cm, $con, $bd, $cis] = make_click_handler();
        $hash     = TestFactory::hash('repeat-older-entry');
        $original = 1699990000;
        $older    = $original - 3600;
        stub_repeat_request($cm, $bd, $cis, $hash, $original);
        $con->shouldReceive('check')->andReturn(true);

        $cm->shouldReceive('parse')->andReturn([$hash => $older]);
        $cm->shouldReceive('add')->once()->with([$hash => $older], $hash, $original)->andReturn([$hash => $original]);
        $cm->shouldReceive('set_clicks_cookie')->once()->with([$hash => $original]);

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class, 'redirect');
    });

    it('leaves a newer cookie entry unchanged when consent is granted and its click row exists', function () {
        [$handler, $cm, $con, $bd, $cis] = make_click_handler();
        $hash     = TestFactory::hash('repeat-newer-entry');
        $original = 1699990000;
        $newer    = $original + 3600;       // 2023-11-14 20:26:40 UTC
        $wpdb     = stub_repeat_request($cm, $bd, $cis, $hash, $original);
        $con->shouldReceive('check')->andReturn(true);

        $cm->shouldReceive('parse')->andReturn([$hash => $newer]);

        // The newer entry's click row exists, so the entry can be trusted.
        $wpdb->shouldReceive('prepare')
            ->with(Mockery::pattern('/clicked_at = %s/'), $hash, '2023-11-14 20:26:40')
            ->andReturn('SQL');
        $wpdb->shouldReceive('get_var')->once()->andReturn('9');

        $cm->shouldReceive('add')->once()->with([$hash => $newer], $hash, $newer)->andReturn([$hash => $newer]);
        $cm->shouldReceive('set_clicks_cookie')->once()->with([$hash => $newer]);

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class, 'redirect');
    });

    it('transports the original click when consent is undetermined', function () {
        [$handler, $cm, $con, $bd, $cis] = make_click_handler();
        $hash     = TestFactory::hash('repeat-pending');
        $original = 1699990000;
        stub_repeat_request($cm, $bd, $cis, $hash, $original);
        $con->shouldReceive('check')->andReturn(null);

        $cm->shouldReceive('set_transport_cookie')->once()->with($hash, $original);
        $cm->shouldNotReceive('set_clicks_cookie');

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class, 'redirect');
    });

    it('resolves to the earliest original click when several click IDs were recorded', function () {
        [$handler, $cm, $con, $bd, $cis] = make_click_handler();
        $hash = TestFactory::hash('repeat-two-platforms');
        $wpdb = stub_tracking_url_request($cm, $hash);

        $_GET['gclid']  = 'GGG';
        $_GET['msclkid'] = 'MMM';
        Filters\expectApplied('kntnt_ad_attr_click_id_capturers')->andReturn(['google_ads' => 'gclid', 'microsoft_ads' => 'msclkid']);
        $bd->shouldReceive('is_bot')->andReturn(false);
        $con->shouldReceive('check')->andReturn(null);

        $cis->shouldReceive('find_original_click')->with($hash, 'google_ads', 'GGG')->andReturn(['click_id' => 9, 'clicked_at' => 1699995000]);
        $cis->shouldReceive('find_original_click')->with($hash, 'microsoft_ads', 'MMM')->andReturn(['click_id' => 4, 'clicked_at' => 1699990000]);
        $wpdb->shouldNotReceive('insert');

        $cm->shouldReceive('set_transport_cookie')->once()->with($hash, 1699990000);

        Functions\expect('nocache_headers')->once();
        Functions\expect('wp_redirect')->once()->andReturnUsing(fn () => throw new ExitException('redirect'));

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class, 'redirect');
    });

    it('records the same click ID on another tracking URL as a new click', function () {
        [$handler, $cm, $con, $bd, $cis] = make_click_handler();
        $hash = TestFactory::hash('repeat-other-url');
        $wpdb = stub_tracking_url_request($cm, $hash);

        $_GET['gclid'] = 'RRR';
        Filters\expectApplied('kntnt_ad_attr_click_id_capturers')->andReturn(['google_ads' => 'gclid']);
        $bd->shouldReceive('is_bot')->andReturn(false);
        $con->shouldReceive('check')->andReturn(false);

        // RRR is recorded only on another tracking URL, so the lookup on this
        // one finds nothing.
        $cis->shouldReceive('find_original_click')->with($hash, 'google_ads', 'RRR')->andReturn(null);

        $wpdb->shouldReceive('insert')->once()->andReturnUsing(function () use ($wpdb) {
            $wpdb->insert_id = 31;
            return 1;
        });
        $cis->shouldReceive('store')->once()->with(31, 'google_ads', 'RRR');
        Actions\expectDone('kntnt_ad_attr_click')->once();

        Functions\expect('nocache_headers')->once();
        Functions\expect('wp_redirect')->once()->andReturnUsing(fn () => throw new ExitException('redirect'));

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class, 'redirect');
    });

    it('points a newer cookie entry without a click row at the original click when consent is granted', function () {
        [$handler, $cm, $con, $bd, $cis] = make_click_handler();
        $hash     = TestFactory::hash('repeat-newer-entry-no-row');
        $original = 1699990000;
        $newer    = $original + 3600;
        $wpdb     = stub_repeat_request($cm, $bd, $cis, $hash, $original);
        $con->shouldReceive('check')->andReturn(true);

        $cm->shouldReceive('parse')->andReturn([$hash => $newer]);

        // Every entry the plugin writes must point at a click row (#2).
        $wpdb->shouldReceive('get_var')->once()->andReturn(null);

        $cm->shouldReceive('add')->once()->with([$hash => $newer], $hash, $original)->andReturn([$hash => $original]);
        $cm->shouldReceive('set_clicks_cookie')->once()->with([$hash => $original]);

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class, 'redirect');
    });

    it('records nothing for a click ID already recorded on the tracking URL, and redirects', function () {
        [$handler, $cm, $con, $bd, $cis] = make_click_handler();
        $hash = TestFactory::hash('repeat');
        $wpdb = stub_tracking_url_request($cm, $hash);

        $_GET['gclid'] = 'RRR';
        Filters\expectApplied('kntnt_ad_attr_click_id_capturers')->andReturn(['google_ads' => 'gclid']);

        $bd->shouldReceive('is_bot')->andReturn(false);
        $con->shouldReceive('check')->andReturn(false);

        // The value was recorded on an earlier click of this tracking URL.
        $cis->shouldReceive('find_original_click')
            ->with($hash, 'google_ads', 'RRR')
            ->andReturn(['click_id' => 7, 'clicked_at' => 1699990000]);

        $wpdb->shouldNotReceive('insert');
        $cis->shouldNotReceive('store');
        Actions\expectDone('kntnt_ad_attr_click')->never();

        // Redirected as usual, with query forwarding.
        Functions\expect('nocache_headers')->once();
        Functions\expect('wp_redirect')
            ->once()
            ->with('https://example.com/?gclid=RRR', 302)
            ->andReturnUsing(fn () => throw new ExitException('redirect'));

        expect(fn () => $handler->handle_click())->toThrow(ExitException::class, 'redirect');
    });

});
