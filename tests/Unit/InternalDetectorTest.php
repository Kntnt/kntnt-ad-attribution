<?php
/**
 * Unit tests for Internal_Detector.
 *
 * @package Tests\Unit
 * @since   1.10.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution\Internal_Detector;
use Brain\Monkey\Functions;
use Brain\Monkey\Filters;

// ─── detect() ───

describe('Internal_Detector::detect()', function () {

    it('recognises a valid logged-in cookie of a user with the capability when WordPress has reset the current user', function () {
        // A REST request without a nonce: WordPress sets the current user to 0.
        Functions\when('current_user_can')->justReturn(false);
        Functions\expect('wp_validate_auth_cookie')->once()->with('', 'logged_in')->andReturn(7);
        Functions\expect('user_can')->once()->with(7, 'kntnt_ad_attr')->andReturn(true);

        expect((new Internal_Detector())->detect(false))->toBeTrue();
    });

    it('recognises the current user with the capability without validating the cookie again', function () {
        Functions\expect('current_user_can')->once()->with('kntnt_ad_attr')->andReturn(true);
        Functions\expect('wp_validate_auth_cookie')->never();

        expect((new Internal_Detector())->detect(false))->toBeTrue();
    });

    it('treats a visitor without a valid logged-in cookie as external', function () {
        Functions\when('current_user_can')->justReturn(false);
        Functions\expect('wp_validate_auth_cookie')->once()->andReturn(false);
        Functions\expect('user_can')->never();

        expect((new Internal_Detector())->detect(false))->toBeFalse();
    });

    it('treats a logged-in user without the capability as external', function () {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('wp_validate_auth_cookie')->justReturn(12);
        Functions\expect('user_can')->once()->with(12, 'kntnt_ad_attr')->andReturn(false);

        expect((new Internal_Detector())->detect(false))->toBeFalse();
    });

    it('keeps a previous filter\'s verdict of internal', function () {
        Functions\expect('current_user_can')->never();
        Functions\expect('wp_validate_auth_cookie')->never();

        expect((new Internal_Detector())->detect(true))->toBeTrue();
    });

});

// ─── is_internal() ───

describe('Internal_Detector::is_internal()', function () {

    it('is false by default', function () {
        expect(Internal_Detector::is_internal())->toBeFalse();
    });

    it('returns what the kntnt_ad_attr_is_internal filter decides', function () {
        Filters\expectApplied('kntnt_ad_attr_is_internal')->once()->with(false)->andReturn(true);

        expect(Internal_Detector::is_internal())->toBeTrue();
    });

});

// ─── register() ───

describe('Internal_Detector::register()', function () {

    it('registers the default callback on kntnt_ad_attr_is_internal', function () {
        $detector = new Internal_Detector();

        $detector->register();

        expect(has_filter('kntnt_ad_attr_is_internal', [$detector, 'detect']))->not->toBeFalse();
    });

});
