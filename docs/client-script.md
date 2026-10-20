# Client-Side Script

The plugin registers an external script (`js/pending-consent.js`) via `wp_enqueue_script` with handle `kntnt-ad-attribution`. The script is loaded on all public pages but does nothing unless there is a pending click.

## Pending Clicks

A pending click is a `<hash>:<timestamp>` pair: the tracking URL's hash and the Unix timestamp of the click row the server recorded (its `clicked_at`). It is the same syntax as one entry of the `_ad_clicks` cookie. The click handler hands the pair over through the `_aah_pending` cookie or the `#_aah=<hash>:<timestamp>` fragment (see [click-handling.md](click-handling.md#transport-mechanism-for-undefined-consent)). Carrying the timestamp lets the set-cookie endpoint write an entry that points at the visitor's own click row, not at a later click on the same tracking URL by someone else.

The script accepts only values that match `/^[a-f0-9]{64}:\d{1,10}$/`. Anything else, such as a bare hash, an uppercase hash or a non-numeric timestamp, is ignored.

## sessionStorage

The script uses two keys in `sessionStorage`:

| Key | Format | Description |
|-----|--------|-------------|
| `kntnt_ad_attr_hashes` | JSON array of `<hash>:<timestamp>` strings: `["a1b2…:1700000000","d4e5…:1700000600"]` | Pending clicks not yet saved in the `_ad_clicks` cookie |
| `kntnt_ad_attr_retries` | Integer as string: `"0"`, `"1"`, `"2"` | Number of failed REST calls. Cleared on successful call. |

Identical pairs are stored once. Two clicks on the same tracking URL at different times are two pairs. Before version 1.10.0 the script stored bare hashes under the same key; on read, the script discards any stored value that is not a well-formed pair, so such leftovers are never posted.

Both keys are fully cleared when the clicks have been sent to the REST endpoint (or after 3 failed attempts).

## Page Load Logic

The script wraps all logic in `DOMContentLoaded` (or runs immediately if `document.readyState !== 'loading'`). This ensures that the developer's consent override script (which defines `window.kntntAdAttributionGetConsent`) has loaded before the consent function is called.

```
1. Does the _aah_pending cookie exist?
   → Yes: expire it; if its value is a well-formed pair, add the pair
          to kntnt_ad_attr_hashes (JSON array in sessionStorage)
   → No: continue

2. Does the URL fragment match #_aah=<hash>:<timestamp>?
   → Yes: add the pair to kntnt_ad_attr_hashes, clear the fragment
   → No: continue (other fragments are left alone)

3. Are there well-formed pairs in kntnt_ad_attr_hashes?
   → No: exit
   → Yes: call window.kntntAdAttributionGetConsent( callback )
      → callback('yes'):     POST the pairs to the REST endpoint, clear sessionStorage
      → callback('no'):      clear sessionStorage (accept the loss)
      → callback('unknown'): do nothing, pairs remain in sessionStorage
```

## REST Call

The script gets the REST URL and nonce via `wp_localize_script`:

```php
wp_localize_script( 'kntnt-ad-attribution', 'kntntAdAttribution', [
    'restUrl' => rest_url( 'kntnt-ad-attribution/v1/set-cookie' ),
    'nonce'   => wp_create_nonce( 'wp_rest' ),
] );
```

The request body is `{ "hashes": [ "<hash>:<timestamp>", … ] }`, sent with `Content-Type: application/json` and the nonce in `X-WP-Nonce`. The parameter keeps its historical name `hashes`; each item is a pair. See [rest-api.md](rest-api.md#set-cookie).

## Error Handling in Script

- `fetch` succeeds (`response.ok`): clear both sessionStorage keys.
- HTTP 403 (nonce expired): clear both keys, accept loss.
- Network error: increment `kntnt_ad_attr_retries`. If < 3: keep the pending clicks, retry on next page load. If ≥ 3: clear both keys, accept loss.

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
- `'unknown'` — no decision yet → the pending click is preserved in sessionStorage. On the next page load, the same check runs again.

**Important:** The callback may be called **asynchronously** (e.g., after the consent plugin's API responds) or **multiple times** (e.g., on consent change during the page's lifetime). The script handles all cases:

- First `'yes'` triggers POST and cleanup.
- First `'no'` triggers cleanup.
- Subsequent calls after `'yes'` or `'no'` are ignored (the script sets an internal `handled` flag).

The developer overrides the function to connect to their consent plugin's JS API. See [consent-example.md](consent-example.md) for a complete example with Real Cookie Banner.

The default implementation (`callback('unknown')`) means that sites without consent integration never get stuck in a wait state — the pending click stays in sessionStorage and waits until the PHP-side consent filter (`kntnt_ad_attr_has_consent`) returns `true`, at which point the server sets the cookie directly on the next ad click.
