/**
 * Unit tests for pending-consent.js.
 *
 * The script is an IIFE that auto-executes when loaded, so each test
 * sets up the required globals/DOM state, then dynamically imports the
 * script to trigger execution.
 *
 * @package Tests/JS
 * @since   1.0.0
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

const scriptPath = resolve(__dirname, '../../js/pending-consent.js');
const scriptSource = readFileSync(scriptPath, 'utf-8');

/**
 * Evaluates the IIFE script in the current global context.
 * This simulates the browser loading the script tag.
 */
function loadScript() {
    // eslint-disable-next-line no-eval
    const fn = new Function(scriptSource);
    fn();
}

/** Generates a valid 64-char lowercase hex hash, distinct per seed. */
function fakeHash(seed = 'a') {
    const hex = Array.from(seed, (c) => c.charCodeAt(0).toString(16).padStart(2, '0')).join('');
    return hex.repeat(64).slice(0, 64);
}

/** Generates a valid `hash:timestamp` click pair. */
function fakeClick(seed = 'a', timestamp = 1700000000) {
    return `${fakeHash(seed)}:${timestamp}`;
}

/**
 * Sets the transport cookie the way the server does: PHP's setcookie()
 * URL-encodes the value, so ':' arrives as '%3A'.
 */
function setTransportCookie(value) {
    document.cookie = `_aah_pending=${encodeURIComponent(value)}; path=/`;
}

/** Returns the stored clicks, or null when nothing is stored. */
function storedClicks() {
    const raw = sessionStorage.getItem('kntnt_ad_attr_hashes');
    return raw === null ? null : JSON.parse(raw);
}

describe('pending-consent.js', () => {

    beforeEach(() => {
        // Clean slate for each test.
        sessionStorage.clear();
        document.cookie = '_aah_pending=; max-age=0; path=/';

        // Reset window globals.
        delete window.kntntAdAttributionGetConsent;
        delete window.kntntAdAttribution;

        // Default localized config (mimics wp_localize_script output).
        window.kntntAdAttribution = {
            restUrl: 'https://example.com/wp-json/kntnt-ad-attribution/v1/set-cookie',
            nonce: 'test-nonce-123',
        };

        // Reset location hash.
        history.replaceState(null, '', window.location.pathname + window.location.search);

        // Ensure DOM is in 'complete' state so init() runs immediately.
        Object.defineProperty(document, 'readyState', {
            value: 'complete',
            writable: true,
            configurable: true,
        });
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    // ─── Click discovery ───

    describe('click discovery', () => {

        it('reads the hash:timestamp pair from the _aah_pending cookie and stores it', () => {
            const click = fakeClick('b');
            setTransportCookie(click);

            // Consent returns 'unknown' so clicks stay in storage.
            loadScript();

            expect(storedClicks()).toEqual([click]);
        });

        it('expires _aah_pending cookie after reading', () => {
            setTransportCookie(fakeClick('c'));

            loadScript();

            // Cookie should be expired (not readable).
            const match = document.cookie.match(/_aah_pending=([^;]*)/);
            expect(!match || match[1] === '').toBe(true);
        });

        it('reads the hash:timestamp pair from the URL fragment #_aah=<hash>:<timestamp>', () => {
            const click = fakeClick('d');
            history.replaceState(null, '', `${window.location.pathname}#_aah=${click}`);

            loadScript();

            expect(storedClicks()).toEqual([click]);
        });

        it('ignores invalid fragment format (too short)', () => {
            history.replaceState(null, '', `${window.location.pathname}#_aah=tooshort`);

            loadScript();

            expect(storedClicks()).toBeNull();
        });

        it('clears fragment via history.replaceState', () => {
            history.replaceState(null, '', `${window.location.pathname}#_aah=${fakeClick('e')}`);

            const spy = vi.spyOn(history, 'replaceState');
            loadScript();

            expect(spy).toHaveBeenCalled();
            // After replaceState, the fragment should be cleared from URL.
            expect(window.location.hash).toBe('');
        });

        it('merges new clicks with existing sessionStorage entries', () => {
            const existing = fakeClick('f');
            const newClick = fakeClick('g');

            // Pre-populate storage.
            sessionStorage.setItem('kntnt_ad_attr_hashes', JSON.stringify([existing]));
            setTransportCookie(newClick);

            loadScript();

            expect(storedClicks()).toEqual([existing, newClick]);
        });

    });

    // ─── Malformed values ───

    describe('malformed values', () => {

        it.each([
            ['a bare hash', fakeHash('q')],
            ['a non-numeric timestamp', `${fakeHash('q')}:17000000x0`],
            ['a timestamp longer than ten digits', `${fakeHash('q')}:17000000000`],
            ['an empty timestamp', `${fakeHash('q')}:`],
            ['an uppercase hash', `${'A'.repeat(64)}:1700000000`],
            ['a short hash', 'abc123:1700000000'],
        ])('ignores %s in the _aah_pending cookie but still expires it', (_label, value) => {
            setTransportCookie(value);

            loadScript();

            expect(storedClicks()).toBeNull();
            const match = document.cookie.match(/_aah_pending=([^;]*)/);
            expect(!match || match[1] === '').toBe(true);
        });

        it.each([
            ['a bare hash', fakeHash('r')],
            ['a non-numeric timestamp', `${fakeHash('r')}:abc`],
            ['an uppercase hash', `${'B'.repeat(64)}:1700000000`],
        ])('ignores %s in the URL fragment', (_label, value) => {
            history.replaceState(null, '', `${window.location.pathname}#_aah=${value}`);

            loadScript();

            expect(storedClicks()).toBeNull();
        });

        it('drops bare hashes left in sessionStorage by the previous script', async () => {
            const click = fakeClick('s');
            sessionStorage.setItem('kntnt_ad_attr_hashes', JSON.stringify([fakeHash('t'), click]));

            const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response('{}'));
            window.kntntAdAttributionGetConsent = (callback) => callback('yes');

            loadScript();

            await vi.waitFor(() => {
                expect(fetchSpy).toHaveBeenCalledOnce();
            });

            expect(JSON.parse(fetchSpy.mock.calls[0][1].body)).toEqual({ hashes: [click] });
        });

        it('does nothing when sessionStorage holds only bare hashes', () => {
            sessionStorage.setItem('kntnt_ad_attr_hashes', JSON.stringify([fakeHash('u')]));

            const consentSpy = vi.fn();
            window.kntntAdAttributionGetConsent = consentSpy;

            loadScript();

            expect(consentSpy).not.toHaveBeenCalled();
        });

    });

    // ─── Deduplication ───

    describe('deduplication', () => {

        it('deduplicates identical clicks in sessionStorage', () => {
            const click = fakeClick('h');

            // Pre-populate with the click, then add the same via cookie.
            sessionStorage.setItem('kntnt_ad_attr_hashes', JSON.stringify([click]));
            setTransportCookie(click);

            loadScript();

            expect(storedClicks()).toEqual([click]);
        });

        it('keeps two clicks on the same hash made at different times', () => {
            const first = fakeClick('h', 1700000000);
            const second = fakeClick('h', 1700000600);

            sessionStorage.setItem('kntnt_ad_attr_hashes', JSON.stringify([first]));
            setTransportCookie(second);

            loadScript();

            expect(storedClicks()).toEqual([first, second]);
        });

    });

    // ─── Consent callbacks ───

    describe('consent callbacks', () => {

        it('posts the stored hash:timestamp pairs to the REST endpoint when consent is yes', async () => {
            const click = fakeClick('i');
            sessionStorage.setItem('kntnt_ad_attr_hashes', JSON.stringify([click]));

            const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response('{}'));

            // Define consent function that immediately grants.
            window.kntntAdAttributionGetConsent = (callback) => callback('yes');

            loadScript();

            // fetch is called asynchronously — wait for microtasks.
            await vi.waitFor(() => {
                expect(fetchSpy).toHaveBeenCalledOnce();
            });

            const [url, options] = fetchSpy.mock.calls[0];
            expect(url).toBe('https://example.com/wp-json/kntnt-ad-attribution/v1/set-cookie');
            expect(options.method).toBe('POST');
            expect(JSON.parse(options.body)).toEqual({ hashes: [click] });
            expect(options.headers['X-WP-Nonce']).toBe('test-nonce-123');
        });

        it('posts a pair read from the transport cookie on the same page load', async () => {
            const click = fakeClick('v');
            setTransportCookie(click);

            const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response('{}'));
            window.kntntAdAttributionGetConsent = (callback) => callback('yes');

            loadScript();

            await vi.waitFor(() => {
                expect(fetchSpy).toHaveBeenCalledOnce();
            });

            expect(JSON.parse(fetchSpy.mock.calls[0][1].body)).toEqual({ hashes: [click] });
        });

        it('clears sessionStorage when consent is no', () => {
            sessionStorage.setItem('kntnt_ad_attr_hashes', JSON.stringify([fakeClick('j')]));

            window.kntntAdAttributionGetConsent = (callback) => callback('no');

            loadScript();

            expect(sessionStorage.getItem('kntnt_ad_attr_hashes')).toBeNull();
            expect(sessionStorage.getItem('kntnt_ad_attr_retries')).toBeNull();
        });

        it('preserves sessionStorage when consent is unknown', () => {
            const click = fakeClick('k');
            sessionStorage.setItem('kntnt_ad_attr_hashes', JSON.stringify([click]));

            window.kntntAdAttributionGetConsent = (callback) => callback('unknown');

            loadScript();

            expect(storedClicks()).toEqual([click]);
        });

        it('only processes first callback invocation', async () => {
            sessionStorage.setItem('kntnt_ad_attr_hashes', JSON.stringify([fakeClick('l')]));

            const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response('{}'));

            // Call callback twice.
            window.kntntAdAttributionGetConsent = (callback) => {
                callback('yes');
                callback('yes');
            };

            loadScript();

            await vi.waitFor(() => {
                expect(fetchSpy).toHaveBeenCalledOnce();
            });

            // fetch should only be called once despite two callback invocations.
            expect(fetchSpy).toHaveBeenCalledTimes(1);
        });

    });

    // ─── Retry logic ───

    describe('retry logic', () => {

        it('increments retry counter on network error', async () => {
            sessionStorage.setItem('kntnt_ad_attr_hashes', JSON.stringify([fakeClick('m')]));

            vi.spyOn(globalThis, 'fetch').mockRejectedValue(new Error('Network error'));

            window.kntntAdAttributionGetConsent = (callback) => callback('yes');

            loadScript();

            await vi.waitFor(() => {
                const retries = parseInt(sessionStorage.getItem('kntnt_ad_attr_retries') || '0', 10);
                expect(retries).toBe(1);
            });
        });

        it('clears storage after 3 failures', async () => {
            sessionStorage.setItem('kntnt_ad_attr_hashes', JSON.stringify([fakeClick('n')]));
            sessionStorage.setItem('kntnt_ad_attr_retries', '2');

            vi.spyOn(globalThis, 'fetch').mockRejectedValue(new Error('Network error'));

            window.kntntAdAttributionGetConsent = (callback) => callback('yes');

            loadScript();

            await vi.waitFor(() => {
                expect(sessionStorage.getItem('kntnt_ad_attr_hashes')).toBeNull();
                expect(sessionStorage.getItem('kntnt_ad_attr_retries')).toBeNull();
            });
        });

        it('clears storage on successful fetch', async () => {
            sessionStorage.setItem('kntnt_ad_attr_hashes', JSON.stringify([fakeClick('o')]));
            sessionStorage.setItem('kntnt_ad_attr_retries', '1');

            vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response('{}'));

            window.kntntAdAttributionGetConsent = (callback) => callback('yes');

            loadScript();

            await vi.waitFor(() => {
                expect(sessionStorage.getItem('kntnt_ad_attr_hashes')).toBeNull();
                expect(sessionStorage.getItem('kntnt_ad_attr_retries')).toBeNull();
            });
        });

    });

    // ─── Default consent function ───

    describe('default consent function', () => {

        it('calls callback with unknown when no custom function defined', () => {
            const click = fakeClick('p');
            sessionStorage.setItem('kntnt_ad_attr_hashes', JSON.stringify([click]));

            // Do NOT define kntntAdAttributionGetConsent — default should be used.
            // The default calls callback('unknown'), so clicks stay.
            loadScript();

            expect(storedClicks()).toEqual([click]);
        });

    });

    // ─── No clicks scenario ───

    describe('no clicks', () => {

        it('exits early without calling consent function', () => {
            const consentSpy = vi.fn();
            window.kntntAdAttributionGetConsent = consentSpy;

            loadScript();

            // With no clicks, consent function should not be called.
            expect(consentSpy).not.toHaveBeenCalled();
        });

    });

});
