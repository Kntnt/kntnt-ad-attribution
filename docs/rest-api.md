# REST API

Internal endpoints — consumed by the plugin's own client-side script and admin UI. The contract may change between versions.

Namespace: `kntnt-ad-attribution/v1`.

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

**Rate limiting:** 10 requests per minute per IP address. Tracked via a WordPress transient (`kntnt_ad_attr_rate_{$ip_hash}`). Exceeding the limit returns HTTP 429 with `{ "code": "rate_limit_exceeded" }`.

**Validation:** Each hash is validated against `/^[a-f0-9]{64}$/` and against the database via `Post_Type::get_valid_hashes()` (must exist as a published tracking URL). Invalid/unknown hashes are silently ignored.

**Consent check:** The endpoint calls `Consent::check()`. If consent is not `true` (granted), HTTP 200 is returned with `{ "success": false }` without setting a cookie.

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

**Permission:** `permission_callback` is set to `'__return_true'` — all visitors must be able to call the endpoint (CSRF protection is handled via nonce, abuse protection via rate limiting).

**Protection:** Nonce validation, rate limiting, hash validation against database, consent check, cookie limit (max 50 hashes), no write side effects to database.

## Search Posts

```
GET /wp-json/kntnt-ad-attribution/v1/search-posts?search=<search term>
```

Internal endpoint — consumed by the admin page's page selector (select2). Searches published posts (all public post types except `kntnt_ad_attr_url`) using a multi-strategy lookup:

1. **Exact post ID** — if the search term is numeric, looks up the post directly via `get_post()`.
2. **URL resolution** — if the search term contains `/`, strips protocol and domain, then resolves it via `url_to_postid()`.
3. **Slug LIKE matching** — splits the cleaned search term on `/` and performs `LIKE` matching against `post_name` for each segment. Limited to 20 results.
4. **Title search** — falls back to `WP_Query` with `s` parameter for title/content matching. Limited to fill up to 20 total results.

Results are deduplicated across strategies (a post found by ID lookup won't appear again in slug or title results). Each strategy returns at most 20 results, and earlier strategies take priority.

**Response:**

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
