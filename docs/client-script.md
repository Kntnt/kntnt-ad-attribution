# Client-Side Script

The plugin registers an external script (`js/pending-consent.js`) via `wp_enqueue_script` with handle `kntnt-ad-attribution`. The script is loaded on all public pages but does nothing unless there is a pending hash.

## sessionStorage

The script uses two keys in `sessionStorage`:

| Key | Format | Description |
|-----|--------|-------------|
| `kntnt_ad_attr_hashes` | JSON array of strings: `["a1b2…","d4e5…"]` | Pending hashes not yet saved in the `_ad_clicks` cookie |
| `kntnt_ad_attr_retries` | Integer as string: `"0"`, `"1"`, `"2"` | Number of failed REST calls. Cleared on successful call. |

Both keys are fully cleared when the hashes have been sent to the REST endpoint (or after 3 failed attempts).

## Page Load Logic

The script wraps all logic in `DOMContentLoaded` (or runs immediately if `document.readyState !== 'loading'`). This ensures that the developer's consent override script (which defines `window.kntntAdAttributionGetConsent`) has loaded before the consent function is called.

```
1. Does the _aah_pending cookie or #_aah in URL exist?
   → Yes: add hash to kntnt_ad_attr_hashes (JSON array in sessionStorage),
          clear cookie/fragment
   → No: continue

2. Are there hashes in kntnt_ad_attr_hashes?
   → No: exit
   → Yes: call window.kntntAdAttributionGetConsent( callback )
      → callback('yes'):     POST to REST endpoint, clear sessionStorage
      → callback('no'):      clear sessionStorage (accept the loss)
      → callback('unknown'): do nothing, hashes remain in sessionStorage
```

## REST Call

The script gets the REST URL and nonce via `wp_localize_script`:

```php
wp_localize_script( 'kntnt-ad-attribution', 'kntntAdAttribution', [
    'restUrl' => rest_url( 'kntnt-ad-attribution/v1/set-cookie' ),
    'nonce'   => wp_create_nonce( 'wp_rest' ),
] );
```

## Error Handling in Script

- `fetch` succeeds (`response.ok`): clear both sessionStorage keys.
- HTTP 403 (nonce expired): clear both keys, accept loss.
- Network error: increment `kntnt_ad_attr_retries`. If < 3: keep hashes, retry on next page load. If ≥ 3: clear both keys, accept loss.

## Consent Interface (JavaScript)

The plugin defines a global function that handles **both** initial check and future changes:

```javascript
window.kntntAdAttributionGetConsent = function( callback ) {
    // callback( 'yes' | 'no' | 'unknown' )
    // Default implementation: calls callback('unknown') immediately.
    // No polling — the script stays in sessionStorage wait mode.
    callback( 'unknown' );
};
```

The function is called **once** on page load. The callback is called with:

- `'yes'` — consent exists → POST to REST endpoint, clear sessionStorage.
- `'no'` — active decline → clear sessionStorage, accept loss.
- `'unknown'` — no decision yet → the hash is preserved in sessionStorage. On the next page load, the same check runs again.

**Important:** The callback may be called **asynchronously** (e.g., after the consent plugin's API responds) or **multiple times** (e.g., on consent change during the page's lifetime). The script handles all cases:

- First `'yes'` triggers POST and cleanup.
- First `'no'` triggers cleanup.
- Subsequent calls after `'yes'` or `'no'` are ignored (the script sets an internal `handled` flag).

The developer overrides the function to connect to their consent plugin's JS API. See [consent-example.md](consent-example.md) for a complete example with Real Cookie Banner.

The default implementation (`callback('unknown')`) means that sites without consent integration never get stuck in a wait state — the hash stays in sessionStorage and waits until the PHP-side consent filter (`kntnt_ad_attribution_has_consent`) returns `true`, at which point the server sets the cookie directly on the next ad click.
