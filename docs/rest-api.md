# REST API

Internal endpoint — consumed by the plugin's own client-side script. The contract may change between versions.

## Set Cookie

```
POST /wp-json/kntnt-ad-attribution/v1/set-cookie
```

**Request:**

```json
{
    "hashes": [ "a1b2c3…", "d4e5f6…" ]
}
```

Content-Type: `application/json`. Header: `X-WP-Nonce: <nonce>`.

**Validation:** Each hash is validated against `/^[a-f0-9]{64}$/` and against the database (must exist as a registered tracking URL). Invalid/unknown hashes are silently ignored.

**Consent check:** The endpoint checks `kntnt_ad_attribution_has_consent`. If consent is missing, `200` is returned with `{ "success": false }` without setting a cookie.

**Response on successful request:**

```
HTTP/1.1 200 OK
Set-Cookie: _ad_clicks=<updated cookie>; Path=/; Max-Age=…; HttpOnly; Secure; SameSite=Lax
Content-Type: application/json

{ "success": true }
```

**Merge behavior:** The endpoint reads the existing `_ad_clicks` cookie (if present), merges the new hashes with the existing ones, and writes back the updated cookie. Specifically:

1. Parse the existing cookie into a `hash => timestamp` map.
2. For each new hash: if the hash already exists in the map, update the timestamp to `time()`. If the hash does not exist, add it with `time()`.
3. If total > 50 hashes: remove the oldest (lowest timestamp) until 50 remain.
4. Serialize back to cookie format and set the cookie.

This means the REST endpoint and the click handler use the same `Cookie_Manager` class to read, merge, and write the cookie.

**Permission:** `permission_callback` is set to `'__return_true'` — all visitors must be able to call the endpoint (CSRF protection is handled via nonce).

**Protection:** Nonce validation, hash validation against database, consent check, cookie limit (max 50 hashes), no database side effects.

## Search Posts

```
GET /wp-json/kntnt-ad-attribution/v1/search-posts?search=<search term>
```

Internal endpoint — consumed by the admin page's page selector (select2). Searches published posts (all public post types except `kntnt_ad_attr_url`) via `WP_Query` and returns:

```json
[
    { "id": 42, "title": "Contact Us", "type": "page" },
    { "id": 17, "title": "Free Guide", "type": "post" }
]
```

**Permission:** `permission_callback` requires capability `kntnt_ad_attr`.

## Nonce and Page Cache

The WordPress REST nonce has a lifetime of 24 hours (two tick periods of 12 hours each). If the site uses full-page cache (e.g., WP Super Cache, W3 Total Cache, Varnish), the cached page may contain an expired nonce.

**Mitigation:** The nonce is delivered via `wp_localize_script` which injects data into HTML. Full-page cache plugins should be configured to exclude nonce-containing pages, or alternatively a fragment cache strategy can be used. The plugin does not actively solve this — it is a known WordPress limitation that affects all plugins using REST nonces on cached content.

**Consequence of expired nonce:** The REST endpoint returns HTTP 403. The script handles this (see [client-script.md](client-script.md)) — sessionStorage is cleared and attribution data is lost for that visitor. This is an edge case that only occurs if: (a) the site has aggressive full-page cache, (b) the visitor has a pending hash, and (c) the nonce has expired.
