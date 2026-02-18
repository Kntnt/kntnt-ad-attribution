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
use Brain\Monkey\Filters;

describe('Consent::check()', function () {

    it('returns true when kntnt_ad_attr_has_consent filter returns true', function () {
        Functions\expect('has_filter')
            ->once()
            ->with('kntnt_ad_attr_has_consent')
            ->andReturn(true);

        Functions\expect('apply_filters')
            ->once()
            ->with('kntnt_ad_attr_has_consent', null)
            ->andReturn(true);

        expect((new Consent())->check())->toBeTrue();
    });

    it('returns false when kntnt_ad_attr_has_consent filter returns false', function () {
        Functions\expect('has_filter')
            ->once()
            ->with('kntnt_ad_attr_has_consent')
            ->andReturn(true);

        Functions\expect('apply_filters')
            ->once()
            ->with('kntnt_ad_attr_has_consent', null)
            ->andReturn(false);

        expect((new Consent())->check())->toBeFalse();
    });

    it('returns null when kntnt_ad_attr_has_consent filter returns null', function () {
        Functions\expect('has_filter')
            ->once()
            ->with('kntnt_ad_attr_has_consent')
            ->andReturn(true);

        Functions\expect('apply_filters')
            ->once()
            ->with('kntnt_ad_attr_has_consent', null)
            ->andReturn(null);

        expect((new Consent())->check())->toBeNull();
    });

    it('falls back to default_consent (true) when no has_consent filter registered', function () {
        Functions\expect('has_filter')
            ->once()
            ->with('kntnt_ad_attr_has_consent')
            ->andReturn(false);

        Functions\expect('apply_filters')
            ->once()
            ->with('kntnt_ad_attr_default_consent', true)
            ->andReturn(true);

        expect((new Consent())->check())->toBeTrue();
    });

    it('returns false when no has_consent filter but default_consent returns false', function () {
        Functions\expect('has_filter')
            ->once()
            ->with('kntnt_ad_attr_has_consent')
            ->andReturn(false);

        Functions\expect('apply_filters')
            ->once()
            ->with('kntnt_ad_attr_default_consent', true)
            ->andReturn(false);

        expect((new Consent())->check())->toBeFalse();
    });

});
