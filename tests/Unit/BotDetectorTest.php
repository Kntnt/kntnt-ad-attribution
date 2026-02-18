<?php
/**
 * Unit tests for Bot_Detector.
 *
 * @package Tests\Unit
 * @since   1.0.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution\Bot_Detector;
use Brain\Monkey\Functions;

// ─── detect() ───

describe('Bot_Detector::detect()', function () {

    afterEach(function () {
        unset($_SERVER['HTTP_USER_AGENT']);
    });

    it('detects Googlebot', function () {
        $_SERVER['HTTP_USER_AGENT'] = 'Googlebot/2.1';
        expect((new Bot_Detector())->detect(false))->toBeTrue();
    });

    it('detects facebookexternalhit', function () {
        $_SERVER['HTTP_USER_AGENT'] = 'facebookexternalhit/1.1';
        expect((new Bot_Detector())->detect(false))->toBeTrue();
    });

    it('detects python-requests', function () {
        $_SERVER['HTTP_USER_AGENT'] = 'python-requests/2.28.0';
        expect((new Bot_Detector())->detect(false))->toBeTrue();
    });

    it('detects HeadlessChrome', function () {
        $_SERVER['HTTP_USER_AGENT'] = 'HeadlessChrome';
        expect((new Bot_Detector())->detect(false))->toBeTrue();
    });

    it('detects curl', function () {
        $_SERVER['HTTP_USER_AGENT'] = 'curl/7.68';
        expect((new Bot_Detector())->detect(false))->toBeTrue();
    });

    it('allows normal Chrome user agent', function () {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
        expect((new Bot_Detector())->detect(false))->toBeFalse();
    });

    it('treats empty user agent as bot', function () {
        $_SERVER['HTTP_USER_AGENT'] = '';
        expect((new Bot_Detector())->detect(false))->toBeTrue();
    });

    it('treats missing user agent as bot', function () {
        unset($_SERVER['HTTP_USER_AGENT']);
        expect((new Bot_Detector())->detect(false))->toBeTrue();
    });

    it('matches case-insensitively (GOOGLEBOT)', function () {
        $_SERVER['HTTP_USER_AGENT'] = 'GOOGLEBOT';
        expect((new Bot_Detector())->detect(false))->toBeTrue();
    });

    it('short-circuits when previous filter already flagged bot', function () {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Chrome/120.0.0.0';
        expect((new Bot_Detector())->detect(true))->toBeTrue();
    });

});

// ─── is_bot() ───

describe('Bot_Detector::is_bot()', function () {

    it('applies kntnt_ad_attr_is_bot filter', function () {
        Functions\expect('apply_filters')
            ->once()
            ->with('kntnt_ad_attr_is_bot', false)
            ->andReturn(false);

        (new Bot_Detector())->is_bot();
    });

    it('returns true when filter overrides detection', function () {
        Functions\expect('apply_filters')
            ->once()
            ->with('kntnt_ad_attr_is_bot', false)
            ->andReturn(true);

        expect((new Bot_Detector())->is_bot())->toBeTrue();
    });

});

// ─── add_disallow_rule() ───

describe('Bot_Detector::add_disallow_rule()', function () {

    it('does not modify output for private site', function () {
        $original = 'User-agent: *';
        $output = (new Bot_Detector())->add_disallow_rule($original, false);

        expect($output)->toBe($original);
    });

    // Plugin::get_url_prefix() caches the result in a static variable, so the
    // filter fires only on the first call within the process. We test the
    // custom prefix here — subsequent calls return the cached value.
    it('uses custom prefix from filter and appends disallow rule', function () {
        Functions\expect('apply_filters')
            ->once()
            ->with('kntnt_ad_attr_url_prefix', 'ad')
            ->andReturn('track');

        $output = (new Bot_Detector())->add_disallow_rule('User-agent: *', true);

        expect($output)->toContain("Disallow: /track/\n");
        expect($output)->toStartWith('User-agent: *');
    });

    it('appends disallow line to existing output for public site', function () {
        // get_url_prefix() is already cached from previous test, no filter call.
        $output = (new Bot_Detector())->add_disallow_rule('User-agent: *', true);

        expect($output)->toStartWith('User-agent: *');
        expect($output)->toContain('Disallow: /');
    });

});

// ─── register() ───

describe('Bot_Detector::register()', function () {

    it('registers filter and robots.txt hook', function () {
        Functions\expect('add_filter')
            ->once()
            ->with('kntnt_ad_attr_is_bot', \Mockery::type('array'));

        Functions\expect('add_filter')
            ->once()
            ->with('robots_txt', \Mockery::type('array'), 10, 2);

        (new Bot_Detector())->register();
    });

});
