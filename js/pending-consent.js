/**
 * Client-side pending consent handler.
 *
 * Picks up ad click hashes from the transport cookie (_aah_pending) or
 * URL fragment (#_aah=<hash>), stores them in sessionStorage, and sends
 * them to the REST set-cookie endpoint when consent is granted.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.0.0
 */

/* global kntntAdAttribution */

(function () {
    'use strict';

    const STORAGE_KEY_HASHES = 'kntnt_ad_attr_hashes';
    const STORAGE_KEY_RETRIES = 'kntnt_ad_attr_retries';
    const MAX_RETRIES = 3;

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
     * Gets the stored hashes from sessionStorage.
     *
     * @returns {string[]} Array of hash strings.
     */
    const getHashes = () => {
        try {
            const raw = sessionStorage.getItem(STORAGE_KEY_HASHES);
            return raw ? JSON.parse(raw) : [];
        } catch {
            return [];
        }
    };

    /**
     * Saves hashes to sessionStorage, deduplicating entries.
     *
     * @param {string[]} hashes - Hash strings to store.
     */
    const setHashes = (hashes) => {
        const unique = [...new Set(hashes)];
        sessionStorage.setItem(STORAGE_KEY_HASHES, JSON.stringify(unique));
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
        sessionStorage.removeItem(STORAGE_KEY_HASHES);
        sessionStorage.removeItem(STORAGE_KEY_RETRIES);
    };

    /**
     * Main logic — runs on DOMContentLoaded (or immediately if DOM is ready).
     */
    const init = () => {
        const existing = getHashes();

        // Collect hash from transport cookie.
        const cookieHash = getCookie('_aah_pending');
        if (cookieHash) {
            existing.push(cookieHash);
            expireCookie('_aah_pending');
        }

        // Collect hash from URL fragment.
        const fragmentMatch = window.location.hash.match(/^#_aah=([a-f0-9]{64})$/);
        if (fragmentMatch) {
            existing.push(fragmentMatch[1]);
            history.replaceState(null, '', window.location.pathname + window.location.search);
        }

        // Persist any newly discovered hashes.
        if (cookieHash || fragmentMatch) {
            setHashes(existing);
        }

        // Nothing to process — exit early.
        const hashes = getHashes();
        if (hashes.length === 0) {
            return;
        }

        // Guard against multiple callback invocations.
        let handled = false;

        const { restUrl, nonce } = kntntAdAttribution;

        /**
         * Sends hashes to the REST endpoint and clears sessionStorage.
         *
         * @param {string[]} hashesToSend - Validated hashes.
         */
        const postHashes = async (hashesToSend) => {
            try {
                await fetch(restUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': nonce,
                    },
                    body: JSON.stringify({ hashes: hashesToSend }),
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
                postHashes(hashes);
            } else if (state === 'no') {
                handled = true;
                clearStorage();
            }
            // 'unknown' — do nothing, hashes stay in sessionStorage.
        });
    };

    // Run when DOM is ready.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
