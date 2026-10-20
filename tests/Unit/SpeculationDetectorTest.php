<?php
/**
 * Unit tests for Speculation_Detector.
 *
 * @package Tests\Unit
 * @since   1.10.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution\Speculation_Detector;
use Brain\Monkey\Filters;

// ─── detect() ───

describe('Speculation_Detector::detect()', function () {

    afterEach(function () {
        unset($_SERVER['HTTP_SEC_PURPOSE'], $_SERVER['HTTP_PURPOSE']);
    });

    it('detects a Sec-Purpose prefetch', function () {
        $_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch';
        expect((new Speculation_Detector())->detect(false))->toBeTrue();
    });

    it('detects a Sec-Purpose prerender (prefetch;prerender)', function () {
        $_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch;prerender';
        expect((new Speculation_Detector())->detect(false))->toBeTrue();
    });

    it('detects the legacy Purpose: prefetch', function () {
        $_SERVER['HTTP_PURPOSE'] = 'prefetch';
        expect((new Speculation_Detector())->detect(false))->toBeTrue();
    });

    it('does not treat another Purpose value as speculative', function () {
        $_SERVER['HTTP_PURPOSE'] = 'preview';
        expect((new Speculation_Detector())->detect(false))->toBeFalse();
    });

    it('does not treat a request without purpose headers as speculative', function () {
        expect((new Speculation_Detector())->detect(false))->toBeFalse();
    });

    it('short-circuits when a previous filter already flagged the request', function () {
        expect((new Speculation_Detector())->detect(true))->toBeTrue();
    });

});

// ─── is_speculative() ───

describe('Speculation_Detector::is_speculative()', function () {

    it('asks the kntnt_ad_attr_is_speculative filter, starting from false', function () {
        Filters\expectApplied('kntnt_ad_attr_is_speculative')
            ->once()
            ->with(false)
            ->andReturn(false);

        expect((new Speculation_Detector())->is_speculative())->toBeFalse();
    });

    it('returns true when a filter callback declares the request speculative', function () {
        Filters\expectApplied('kntnt_ad_attr_is_speculative')->once()->andReturn(true);

        expect((new Speculation_Detector())->is_speculative())->toBeTrue();
    });

});

// ─── register() ───

describe('Speculation_Detector::register()', function () {

    it('hooks its default detection into kntnt_ad_attr_is_speculative', function () {
        $detector = new Speculation_Detector();

        Filters\expectAdded('kntnt_ad_attr_is_speculative')
            ->once()
            ->with([$detector, 'detect']);

        $detector->register();

        expect(true)->toBeTrue();
    });

});
