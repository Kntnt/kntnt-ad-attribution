<?php
/**
 * Unit tests for Cookie_Manager.
 *
 * @package Tests\Unit
 * @since   1.0.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution\Cookie_Manager;
use Brain\Monkey\Functions;

// ─── parse() ───

describe('Cookie_Manager::parse()', function () {

    afterEach(function () {
        unset($_COOKIE['_ad_clicks']);
    });

    it('returns hash => timestamp map for valid cookie with one entry', function () {
        $hash = str_repeat('a', 64);
        $_COOKIE['_ad_clicks'] = "{$hash}:1700000000";

        $result = (new Cookie_Manager())->parse();

        expect($result)->toBe([$hash => 1700000000]);
    });

    it('returns all entries for valid cookie with multiple entries', function () {
        $h1 = str_repeat('a', 64);
        $h2 = str_repeat('b', 64);
        $h3 = str_repeat('c', 64);
        $_COOKIE['_ad_clicks'] = "{$h1}:1000,{$h2}:2000,{$h3}:3000";

        $result = (new Cookie_Manager())->parse();

        expect($result)->toBe([
            $h1 => 1000,
            $h2 => 2000,
            $h3 => 3000,
        ]);
    });

    it('returns empty array for missing cookie', function () {
        unset($_COOKIE['_ad_clicks']);

        expect((new Cookie_Manager())->parse())->toBe([]);
    });

    it('returns empty array and logs error for corrupt cookie', function () {
        $_COOKIE['_ad_clicks'] = 'not-a-valid-cookie';

        Functions\expect('error_log')->once();

        expect((new Cookie_Manager())->parse())->toBe([]);
    });

    it('returns empty array for corrupt cookie with partial match', function () {
        $hash = str_repeat('a', 64);
        $_COOKIE['_ad_clicks'] = "{$hash}:1000,garbage";

        Functions\expect('error_log')->once();

        expect((new Cookie_Manager())->parse())->toBe([]);
    });

    it('rejects cookie with uppercase hex', function () {
        $hash = str_repeat('A', 64);
        $_COOKIE['_ad_clicks'] = "{$hash}:1000";

        Functions\expect('error_log')->once();

        expect((new Cookie_Manager())->parse())->toBe([]);
    });

});

// ─── add() ───

describe('Cookie_Manager::add()', function () {

    it('adds hash to empty array with current timestamp', function () {
        $hash = str_repeat('a', 64);

        $before = time();
        $result = (new Cookie_Manager())->add([], $hash);
        $after = time();

        expect($result)->toHaveKey($hash);
        expect($result[$hash])->toBeGreaterThanOrEqual($before);
        expect($result[$hash])->toBeLessThanOrEqual($after);
    });

    it('adds hash with explicit timestamp', function () {
        $hash = str_repeat('a', 64);

        $result = (new Cookie_Manager())->add([], $hash, 1700000000);

        expect($result)->toBe([$hash => 1700000000]);
    });

    it('updates existing hash timestamp without changing array size', function () {
        $hash = str_repeat('a', 64);
        $entries = [$hash => 1000];

        $result = (new Cookie_Manager())->add($entries, $hash, 2000);

        expect($result)->toBe([$hash => 2000]);
        expect($result)->toHaveCount(1);
    });

    it('evicts oldest entry when exceeding MAX_HASHES (50)', function () {
        $manager = new Cookie_Manager();
        $entries = [];

        // Fill with 50 entries, each with a distinct timestamp.
        for ($i = 0; $i < 50; $i++) {
            $hash = hash('sha256', "hash-{$i}");
            $entries[$hash] = 1000 + $i;
        }
        expect($entries)->toHaveCount(50);

        // Add the 51st entry.
        $new_hash = hash('sha256', 'hash-new');
        $result = $manager->add($entries, $new_hash, 9999);

        expect($result)->toHaveCount(50);
        expect($result)->toHaveKey($new_hash);
    });

    it('evicts the entry with the smallest timestamp', function () {
        $manager = new Cookie_Manager();
        $entries = [];

        // Create 50 entries with known timestamps.
        $oldest_hash = '';
        for ($i = 0; $i < 50; $i++) {
            $hash = hash('sha256', "hash-{$i}");
            $entries[$hash] = 5000 + $i; // timestamps 5000..5049
            if ($i === 0) {
                $oldest_hash = $hash; // timestamp 5000 = smallest
            }
        }

        // Add 51st entry.
        $new_hash = hash('sha256', 'hash-new');
        $result = $manager->add($entries, $new_hash, 9999);

        expect($result)->not->toHaveKey($oldest_hash);
        expect($result)->toHaveKey($new_hash);
    });

});

// ─── set_clicks_cookie() ───

describe('Cookie_Manager::set_clicks_cookie()', function () {

    it('calls setcookie with correct name', function () {
        $hash = str_repeat('a', 64);

        Functions\expect('apply_filters')
            ->once()
            ->with('kntnt_ad_attr_cookie_lifetime', 90)
            ->andReturn(90);

        Functions\expect('setcookie')
            ->once()
            ->andReturnUsing(function (string $name) {
                expect($name)->toBe('_ad_clicks');
                return true;
            });

        (new Cookie_Manager())->set_clicks_cookie([$hash => 1000]);
    });

    it('serializes entries in hash:ts,hash:ts format', function () {
        $h1 = str_repeat('a', 64);
        $h2 = str_repeat('b', 64);

        Functions\expect('apply_filters')
            ->once()
            ->with('kntnt_ad_attr_cookie_lifetime', 90)
            ->andReturn(90);

        Functions\expect('setcookie')
            ->once()
            ->andReturnUsing(function (string $name, string $value) use ($h1, $h2) {
                expect($value)->toBe("{$h1}:1000,{$h2}:2000");
                return true;
            });

        (new Cookie_Manager())->set_clicks_cookie([$h1 => 1000, $h2 => 2000]);
    });

    it('sets correct cookie attributes', function () {
        $hash = str_repeat('a', 64);

        Functions\expect('apply_filters')
            ->once()
            ->with('kntnt_ad_attr_cookie_lifetime', 90)
            ->andReturn(90);

        Functions\expect('setcookie')
            ->once()
            ->andReturnUsing(function (string $name, string $value, array $options) {
                expect($options['path'])->toBe('/');
                expect($options['httponly'])->toBeTrue();
                expect($options['secure'])->toBeTrue();
                expect($options['samesite'])->toBe('Lax');
                return true;
            });

        (new Cookie_Manager())->set_clicks_cookie([$hash => 1000]);
    });

    it('uses filtered lifetime for cookie expiry', function () {
        $hash = str_repeat('a', 64);

        Functions\expect('apply_filters')
            ->once()
            ->with('kntnt_ad_attr_cookie_lifetime', 90)
            ->andReturn(180);

        $before = time();

        Functions\expect('setcookie')
            ->once()
            ->andReturnUsing(function (string $name, string $value, array $options) use ($before) {
                $expected_min = $before + 180 * DAY_IN_SECONDS;
                $expected_max = $expected_min + 2; // small tolerance
                expect($options['expires'])->toBeGreaterThanOrEqual($expected_min);
                expect($options['expires'])->toBeLessThanOrEqual($expected_max);
                return true;
            });

        (new Cookie_Manager())->set_clicks_cookie([$hash => 1000]);
    });

});

// ─── set_transport_cookie() ───

describe('Cookie_Manager::set_transport_cookie()', function () {

    it('sets cookie name to _aah_pending', function () {
        $hash = str_repeat('a', 64);

        Functions\expect('setcookie')
            ->once()
            ->andReturnUsing(function (string $name) {
                expect($name)->toBe('_aah_pending');
                return true;
            });

        (new Cookie_Manager())->set_transport_cookie($hash);
    });

    it('sets httponly to false', function () {
        $hash = str_repeat('a', 64);

        Functions\expect('setcookie')
            ->once()
            ->andReturnUsing(function (string $name, string $value, array $options) {
                expect($options['httponly'])->toBeFalse();
                return true;
            });

        (new Cookie_Manager())->set_transport_cookie($hash);
    });

    it('sets 60-second lifetime', function () {
        $hash = str_repeat('a', 64);
        $before = time();

        Functions\expect('setcookie')
            ->once()
            ->andReturnUsing(function (string $name, string $value, array $options) use ($before) {
                $expected_min = $before + 60;
                $expected_max = $expected_min + 2;
                expect($options['expires'])->toBeGreaterThanOrEqual($expected_min);
                expect($options['expires'])->toBeLessThanOrEqual($expected_max);
                return true;
            });

        (new Cookie_Manager())->set_transport_cookie($hash);
    });

    it('sets value to the hash argument', function () {
        $hash = str_repeat('a', 64);

        Functions\expect('setcookie')
            ->once()
            ->andReturnUsing(function (string $name, string $value) use ($hash) {
                expect($value)->toBe($hash);
                return true;
            });

        (new Cookie_Manager())->set_transport_cookie($hash);
    });

});

// ─── validate_hash() ───

describe('Cookie_Manager::validate_hash()', function () {

    it('accepts valid 64-char lowercase hex', function () {
        $manager = new Cookie_Manager();
        expect($manager->validate_hash(str_repeat('a', 64)))->toBeTrue();
        expect($manager->validate_hash(hash('sha256', 'test')))->toBeTrue();
        expect($manager->validate_hash(str_repeat('0', 64)))->toBeTrue();
        expect($manager->validate_hash('abcdef0123456789' . str_repeat('0', 48)))->toBeTrue();
    });

    it('rejects 63 characters', function () {
        expect((new Cookie_Manager())->validate_hash(str_repeat('a', 63)))->toBeFalse();
    });

    it('rejects 65 characters', function () {
        expect((new Cookie_Manager())->validate_hash(str_repeat('a', 65)))->toBeFalse();
    });

    it('rejects uppercase letters', function () {
        expect((new Cookie_Manager())->validate_hash(str_repeat('A', 64)))->toBeFalse();
    });

    it('rejects non-hex characters', function () {
        expect((new Cookie_Manager())->validate_hash(str_repeat('g', 64)))->toBeFalse();
        expect((new Cookie_Manager())->validate_hash(str_repeat('z', 64)))->toBeFalse();
    });

    it('rejects empty string', function () {
        expect((new Cookie_Manager())->validate_hash(''))->toBeFalse();
    });

});
