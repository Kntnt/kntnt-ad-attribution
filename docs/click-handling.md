# Click Handling

The click handler is registered on `template_redirect` — the conventional hook for redirect logic in WordPress. At that point, all plugins and themes have loaded their hooks (including consent and bot filters), but WordPress has not started rendering output.

## Flow

```
1. Match URL against /<prefix>/<hash>
2. Validate hash: /^[a-f0-9]{64}$/
3. Look up hash in database → retrieve CPT post and target_post_id
4. If hash not found → 404
5. If CPT post_status ≠ publish → 404
6. Resolve target URL: get_permalink( $target_post_id )
7. If target URL not found → 404
8. Redirect loop guard: verify that target URL does not start with /<prefix>/
9. Bot check: if is_bot() → redirect without logging or setting cookie
10. Log click in database (always, regardless of consent)
11. Check consent → three outcomes (see Consent below)
12. Redirect to target URL
```

## URL Matching

The plugin captures requests to `/<prefix>/<hash>` where prefix defaults to `ad` (configurable via `kntnt_ad_attribution_url_prefix`).

**Rewrite rule:**

```php
$prefix = apply_filters( 'kntnt_ad_attribution_url_prefix', 'ad' );

add_rewrite_rule(
    '^' . preg_quote( $prefix, '/' ) . '/([a-f0-9]{64})/?$',
    'index.php?kntnt_ad_attr_hash=$matches[1]',
    'top',
);
```

**Query var:** `kntnt_ad_attr_hash` is registered via the `query_vars` filter:

```php
add_filter( 'query_vars', fn( array $vars ): array => [ ...$vars, 'kntnt_ad_attr_hash' ] );
```

**Hash retrieval in `template_redirect`:**

```php
$hash = get_query_var( 'kntnt_ad_attr_hash' );
if ( ! $hash ) {
    return; // Not a tracking URL — let WordPress handle the request
}
```

**Flush:** Rewrite rules are flushed on activation (`flush_rewrite_rules()`) and cleared on deactivation.

Query parameters (including `gclid`) are ignored in the attribution logic — they do not pass through the rewrite rule and do not affect hash matching.

## Bot Detection

Bots are redirected to the target URL, but the click is **not** logged and no cookie is set.

Bot detection is controlled by the filter `kntnt_ad_attribution_is_bot` (default `false`). The plugin registers its own callback with User-Agent matching:

Signatures matched (case-insensitive): `bot`, `crawl`, `spider`, `slurp`, `facebookexternalhit`, `LinkedInBot`, `Mediapartners-Google`, `AdsBot-Google`, `Googlebot`, `Bingbot`, `Yahoo`, `curl`, `wget`, `python-requests`, `HeadlessChrome`, `Lighthouse`, `GTmetrix`. An empty User-Agent is treated as a bot.

The plugin automatically adds `Disallow: /<prefix>/` to WordPress's virtual `robots.txt` via the `robots_txt` filter. Sites with a physical `robots.txt` need to add the line manually.

## Consent

Consent is checked via the filter `kntnt_ad_attribution_has_consent`. Three states:

- **Yes (`true`)** — the visitor has accepted marketing cookies.
- **No (`false`)** — the visitor has declined.
- **Undefined (`null`)** — the visitor has not yet made a decision.

If no callback is registered, the plugin falls back to `kntnt_ad_attribution_default_consent` (default `true`), which covers sites without consent requirements.

**Consent = yes:** Set the `_ad_clicks` cookie. Redirect to target URL.

**Consent = no:** Redirect to target URL. No cookie. Attribution is lost. Accepted.

**Consent = undefined:** Redirect to target URL, but pass the hash via the transport mechanism (see below) so the client-side script can set the cookie if/when the visitor consents.

The click is logged in the database **always** regardless of consent. Click counting per hash is aggregated statistics and does not constitute personal data processing. It is the cookie that links the click to an individual.

## Transport Mechanism for Undefined Consent

When consent is undefined, the hash needs to be transferred to the target URL. The mechanism is controlled by the filter `kntnt_ad_attribution_pending_transport`.

**`'cookie'`** (default) — the server sets a temporary cookie in the redirect response:

```
Set-Cookie: _aah_pending=<hash>; Path=/; Max-Age=60; Secure; SameSite=Lax
```

The cookie is *not* HttpOnly (the script must be able to read it). It lives for a maximum of 60 seconds and contains no personal data — it is a technical transport mechanism.

**`'fragment'`** — the server appends the hash as a URL fragment:

```
Location: https://example.com/landing-page#_aah=<hash>
```

The script reads the fragment, stores the hash in `sessionStorage`, and clears the fragment with `history.replaceState`.

## Redirect Methods

Controlled by the filter `kntnt_ad_attribution_redirect_method`:

**`'302'`** (default) — server-side redirect:

```php
nocache_headers();
wp_redirect( $target_url, 302 );
exit;
```

**`'js'`** — JavaScript redirect via `window.location.href`. Relevant for ITP mitigation. Serves a minimal 200 page that redirects via JavaScript.

All redirect responses shall include `nocache_headers()` to prevent caching.

## Unknown Hash and Broken Target URL

If the hash does not exist in the database, or if `get_permalink( $target_post_id )` returns `false`: standard WordPress 404 page.

```php
status_header( 404 );
nocache_headers();
require get_404_template();
exit;
```
