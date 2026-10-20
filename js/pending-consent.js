/**
 * Client-side pending consent handler.
 *
 * Picks up ad clicks as `<hash>:<timestamp>` pairs from the transport cookie
 * (_aah_pending) or URL fragment (#_aah=<hash>:<timestamp>), stores them in
 * sessionStorage, and sends them to the REST set-cookie endpoint when consent
 * is granted.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.0.0
 */

/* global kntntAdAttribution */

(function () {
    'use strict';

    // The key name predates the pair format; it is kept so that bare hashes
    // stored by the previous script are found and discarded on read.
    const STORAGE_KEY_CLICKS = 'kntnt_ad_attr_hashes';
    const STORAGE_KEY_RETRIES = 'kntnt_ad_attr_retries';
    const MAX_RETRIES = 3;

    // One click: a SHA-256 hash and the Unix timestamp of its click row,
    // the same syntax as an _ad_clicks cookie entry.
    const CLICK_PATTERN = /^[a-f0-9]{64}:\d{1,10}$/;

    // Define the default consent function if the site hasn't provided one.
    // Sites integrate their consent plugin by defining this function before
    // DOMContentLoaded fires.
    if (typeof window.kntntAdAttributionGetConsent !== 'function') {
        window.kntntAdAttributionGetConsent = (callback) => {
            callback('unknown');
        };
    }

    /**
     * Reads a cookie value by name.
     *
     * @param {string} name - Cookie name.
     * @returns {string|null} Cookie value or null if not found.
     */
    const getCookie = (name) => {
        const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));
        return match ? decodeURIComponent(match[1]) : null;
    };

    /**
     * Expires a cookie by setting its max-age to 0.
     *
     * @param {string} name - Cookie name to expire.
     */
    const expireCookie = (name) => {
        document.cookie = `${name}=; max-age=0; path=/; secure; samesite=lax`;
    };

    /**
     * Tells whether a value is a well-formed `<hash>:<timestamp>` click.
     *
     * @param {*} value - Value to check.
     * @returns {boolean} True for a well-formed click.
     */
    const isClick = (value) => typeof value === 'string' && CLICK_PATTERN.test(value);

    /**
     * Gets the stored clicks from sessionStorage, discarding malformed ones.
     *
     * @returns {string[]} Array of `<hash>:<timestamp>` strings.
     */
    const getClicks = () => {
        try {
            const raw = sessionStorage.getItem(STORAGE_KEY_CLICKS);
            const stored = raw ? JSON.parse(raw) : [];
            return Array.isArray(stored) ? stored.filter(isClick) : [];
        } catch {
            return [];
        }
    };

    /**
     * Saves clicks to sessionStorage, deduplicating entries.
     *
     * @param {string[]} clicks - `<hash>:<timestamp>` strings to store.
     */
    const setClicks = (clicks) => {
        const unique = [...new Set(clicks)];
        sessionStorage.setItem(STORAGE_KEY_CLICKS, JSON.stringify(unique));
    };

    /**
     * Gets the current retry count.
     *
     * @returns {number} Number of failed REST attempts.
     */
    const getRetries = () => {
        return parseInt(sessionStorage.getItem(STORAGE_KEY_RETRIES) || '0', 10);
    };

    /**
     * Increments the retry counter.
     */
    const incrementRetries = () => {
        sessionStorage.setItem(STORAGE_KEY_RETRIES, String(getRetries() + 1));
    };

    /**
     * Clears all plugin keys from sessionStorage.
     */
    const clearStorage = () => {
        sessionStorage.removeItem(STORAGE_KEY_CLICKS);
        sessionStorage.removeItem(STORAGE_KEY_RETRIES);
    };

    /**
     * Main logic — runs on DOMContentLoaded (or immediately if DOM is ready).
     */
    const init = () => {
        const existing = getClicks();
        let discovered = false;

        // Collect the click from the transport cookie. The cookie is expired
        // even when malformed, so it cannot linger.
        const cookieClick = getCookie('_aah_pending');
        if (cookieClick !== null) {
            expireCookie('_aah_pending');
            if (isClick(cookieClick)) {
                existing.push(cookieClick);
                discovered = true;
            }
        }

        // Collect the click from the URL fragment.
        const fragmentMatch = window.location.hash.match(/^#_aah=([a-f0-9]{64}:\d{1,10})$/);
        if (fragmentMatch) {
            existing.push(fragmentMatch[1]);
            discovered = true;
            history.replaceState(null, '', window.location.pathname + window.location.search);
        }

        // Persist any newly discovered clicks.
        if (discovered) {
            setClicks(existing);
        }

        // Nothing to process — exit early.
        const clicks = getClicks();
        if (clicks.length === 0) {
            return;
        }

        // Guard against multiple callback invocations.
        let handled = false;

        const { restUrl, nonce } = kntntAdAttribution;

        /**
         * Sends clicks to the REST endpoint and clears sessionStorage.
         *
         * @param {string[]} clicksToSend - Validated `<hash>:<timestamp>` strings.
         */
        const postClicks = async (clicksToSend) => {
            try {
                await fetch(restUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': nonce,
                    },
                    body: JSON.stringify({ hashes: clicksToSend }),
                });
                clearStorage();
            } catch {
                incrementRetries();
                if (getRetries() >= MAX_RETRIES) {
                    clearStorage();
                }
            }
        };

        window.kntntAdAttributionGetConsent((state) => {
            if (handled) {
                return;
            }

            if (state === 'yes') {
                handled = true;
                postClicks(clicks);
            } else if (state === 'no') {
                handled = true;
                clearStorage();
            }
            // 'unknown' — do nothing, clicks stay in sessionStorage.
        });
    };

    // Run when DOM is ready.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
