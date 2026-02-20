<?php
/**
 * Unit tests for Consent.
 *
 * @package Tests\Unit
 * @since   1.0.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution\Consent;
use Brain\Monkey\Functions;

describe('Consent::check()', function () {

    it('returns true when filter returns true', function () {
        Functions\expect('apply_filters')
            ->once()
            ->with('kntnt_ad_attr_has_consent', null)
            ->andReturn(true);

        expect((new Consent())->check())->toBeTrue();
    });

    it('returns false when filter returns false', function () {
        Functions\expect('apply_filters')
            ->once()
            ->with('kntnt_ad_attr_has_consent', null)
            ->andReturn(false);

        expect((new Consent())->check())->toBeFalse();
    });

    it('returns null when filter returns null', function () {
        Functions\expect('apply_filters')
            ->once()
            ->with('kntnt_ad_attr_has_consent', null)
            ->andReturn(null);

        expect((new Consent())->check())->toBeNull();
    });

    it('defaults to null when no filter is registered', function () {
        Functions\when('apply_filters')->returnArg(2);

        expect((new Consent())->check())->toBeNull();
    });

});
