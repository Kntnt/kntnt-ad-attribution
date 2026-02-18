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

/** Generates a valid 64-char hex hash. */
function fakeHash(seed = 'a') {
    return seed.repeat(64).slice(0, 64);
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

    // ─── Hash discovery ───

    describe('hash discovery', () => {

        it('reads _aah_pending cookie and stores in sessionStorage', () => {
            const hash = fakeHash('b');
            document.cookie = `_aah_pending=${hash}; path=/`;

            // Consent returns 'unknown' so hashes stay in storage.
            loadScript();

            const stored = JSON.parse(sessionStorage.getItem('kntnt_ad_attr_hashes'));
            expect(stored).toContain(hash);
        });

        it('expires _aah_pending cookie after reading', () => {
            const hash = fakeHash('c');
            document.cookie = `_aah_pending=${hash}; path=/`;

            loadScript();

            // Cookie should be expired (not readable).
            const match = document.cookie.match(/_aah_pending=([^;]*)/);
            expect(!match || match[1] === '').toBe(true);
        });

        it('reads hash from URL fragment #_aah=<hash>', () => {
            const hash = fakeHash('d');
            history.replaceState(null, '', `${window.location.pathname}#_aah=${hash}`);

            loadScript();

            const stored = JSON.parse(sessionStorage.getItem('kntnt_ad_attr_hashes'));
            expect(stored).toContain(hash);
        });

        it('ignores invalid fragment format (too short)', () => {
            history.replaceState(null, '', `${window.location.pathname}#_aah=tooshort`);

            loadScript();

            const stored = sessionStorage.getItem('kntnt_ad_attr_hashes');
            expect(stored).toBeNull();
        });

        it('clears fragment via history.replaceState', () => {
            const hash = fakeHash('e');
            history.replaceState(null, '', `${window.location.pathname}#_aah=${hash}`);

            const spy = vi.spyOn(history, 'replaceState');
            loadScript();

            expect(spy).toHaveBeenCalled();
            // After replaceState, hash should be cleared from URL.
            expect(window.location.hash).toBe('');
        });

        it('merges new hashes with existing sessionStorage entries', () => {
            const existing = fakeHash('f');
            const newHash = fakeHash('g');

            // Pre-populate storage.
            sessionStorage.setItem('kntnt_ad_attr_hashes', JSON.stringify([existing]));
            document.cookie = `_aah_pending=${newHash}; path=/`;

            loadScript();

            const stored = JSON.parse(sessionStorage.getItem('kntnt_ad_attr_hashes'));
            expect(stored).toContain(existing);
            expect(stored).toContain(newHash);
        });

    });

    // ─── Deduplication ───

    describe('deduplication', () => {

        it('deduplicates identical hashes in sessionStorage', () => {
            const hash = fakeHash('h');

            // Pre-populate with the hash, then add the same via cookie.
            sessionStorage.setItem('kntnt_ad_attr_hashes', JSON.stringify([hash]));
            document.cookie = `_aah_pending=${hash}; path=/`;

            loadScript();

            const stored = JSON.parse(sessionStorage.getItem('kntnt_ad_attr_hashes'));
            const occurrences = stored.filter(h => h === hash).length;
            expect(occurrences).toBe(1);
        });

    });

    // ─── Consent callbacks ───

    describe('consent callbacks', () => {

        it('sends POST to REST endpoint when consent is yes', async () => {
            const hash = fakeHash('i');
            sessionStorage.setItem('kntnt_ad_attr_hashes', JSON.stringify([hash]));

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
            expect(JSON.parse(options.body)).toEqual({ hashes: [hash] });
            expect(options.headers['X-WP-Nonce']).toBe('test-nonce-123');
        });

        it('clears sessionStorage when consent is no', () => {
            const hash = fakeHash('j');
            sessionStorage.setItem('kntnt_ad_attr_hashes', JSON.stringify([hash]));

            window.kntntAdAttributionGetConsent = (callback) => callback('no');

            loadScript();

            expect(sessionStorage.getItem('kntnt_ad_attr_hashes')).toBeNull();
            expect(sessionStorage.getItem('kntnt_ad_attr_retries')).toBeNull();
        });

        it('preserves sessionStorage when consent is unknown', () => {
            const hash = fakeHash('k');
            sessionStorage.setItem('kntnt_ad_attr_hashes', JSON.stringify([hash]));

            window.kntntAdAttributionGetConsent = (callback) => callback('unknown');

            loadScript();

            const stored = JSON.parse(sessionStorage.getItem('kntnt_ad_attr_hashes'));
            expect(stored).toContain(hash);
        });

        it('only processes first callback invocation', async () => {
            const hash = fakeHash('l');
            sessionStorage.setItem('kntnt_ad_attr_hashes', JSON.stringify([hash]));

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
            const hash = fakeHash('m');
            sessionStorage.setItem('kntnt_ad_attr_hashes', JSON.stringify([hash]));

            vi.spyOn(globalThis, 'fetch').mockRejectedValue(new Error('Network error'));

            window.kntntAdAttributionGetConsent = (callback) => callback('yes');

            loadScript();

            await vi.waitFor(() => {
                const retries = parseInt(sessionStorage.getItem('kntnt_ad_attr_retries') || '0', 10);
                expect(retries).toBe(1);
            });
        });

        it('clears storage after 3 failures', async () => {
            const hash = fakeHash('n');
            sessionStorage.setItem('kntnt_ad_attr_hashes', JSON.stringify([hash]));
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
            const hash = fakeHash('o');
            sessionStorage.setItem('kntnt_ad_attr_hashes', JSON.stringify([hash]));
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
            const hash = fakeHash('p');
            sessionStorage.setItem('kntnt_ad_attr_hashes', JSON.stringify([hash]));

            // Do NOT define kntntAdAttributionGetConsent — default should be used.
            // The default calls callback('unknown'), so hashes stay.
            loadScript();

            const stored = JSON.parse(sessionStorage.getItem('kntnt_ad_attr_hashes'));
            expect(stored).toContain(hash);
        });

    });

    // ─── No hashes scenario ───

    describe('no hashes', () => {

        it('exits early without calling consent function', () => {
            const consentSpy = vi.fn();
            window.kntntAdAttributionGetConsent = consentSpy;

            loadScript();

            // With no hashes, consent function should not be called.
            expect(consentSpy).not.toHaveBeenCalled();
        });

    });

});
