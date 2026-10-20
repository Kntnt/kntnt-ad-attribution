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
7b. Forward query parameters: merge incoming request params with target URL params (target wins on collision), filter via `kntnt_ad_attr_redirect_query_params`. The test parameter `kntnt_ad_attr_test` is removed first and never forwarded
8. Redirect loop guard: verify that target URL does not start with /<prefix>/
9. Bot check: if is_bot() → redirect without recording a click or setting a cookie                       [outcome: bot]
9b. Speculative check: if is_speculative() → 503 with no-cache headers, no redirect, nothing recorded      [outcome: speculative]
9c. Internal check: if not a test click and is_internal() → redirect without recording anything, like a bot   [outcome: internal]
10. Check consent
10a. Read platform click IDs from the request via registered kntnt_ad_attr_click_id_capturers, noting which are unique per click
10b. Repeat check (skipped for a test click): if any unique one is already recorded on a click of this tracking URL → resolve to the original click, handle consent for it, redirect (skip insert)   [outcome: repeat]
10c. Click deduplication (skipped for a test click): if consent=true and dedup_seconds>0 and hash in cookie within window and a click row matches the entry → re-send cookie with the original timestamp, redirect (skip insert)   [outcome: deduplicated]
11. Extract per-click UTM fields (Content, Term, Id, Group) from incoming parameters
11b. Insert click record into kntnt_ad_attr_clicks table, with is_test = 1 for a test click. If the insert fails → redirect without click IDs, action, cookie entry or transport   [outcome: failed]
11c. Store the captured click IDs, keyed by the inserted click row's ID
11d. Populate empty postmeta fields (Source, Medium, Campaign only) from incoming UTM/MTM query parameters
11e. Fire kntnt_ad_attr_click action (hash, target URL, campaign data) — allows companion plugins to capture platform-specific parameters (e.g. gclid)
12. Handle consent for cookie storage → three outcomes (see Consent below)
13. Redirect to target URL                                                                                [outcome: recorded]
```

A **test click** is a request with `kntnt_ad_attr_test=1` (see [Test Clicks](#test-clicks)). Every request that gets past step 8 writes one diagnostic log line, with the outcome shown in brackets, just before its response (see [Diagnostic Log Line](#diagnostic-log-line)).

## Counting Rules

A tracking URL counts **clicks**: requests in which a person follows the ad link. Every other request is answered without a click row, so the counts in the admin view, its totals and the CSV export need no filtering.

The handler classifies each request for a published tracking URL with a valid target. The checks run in this order, and the first that matches decides:

| Order | Kind | Detected by | Response | Recorded |
|---|---|---|---|---|
| 1 | **Bot** | `kntnt_ad_attr_is_bot` (User-Agent signatures, see [Bot Detection](#bot-detection)) | 302 redirect with query forwarding | Nothing |
| 2 | **Speculative request** | `kntnt_ad_attr_is_speculative` (`Sec-Purpose` contains `prefetch`, or `Purpose: prefetch`) | 503, no-cache headers, no redirect | Nothing |
| 3 | **Internal request** | `kntnt_ad_attr_is_internal` (a logged-in user with `kntnt_ad_attr`); never matches a test click | 302 redirect with query forwarding | Nothing |
| 4 | **Repeat** | A unique platform click ID in the request is already recorded on a click of this tracking URL | 302 redirect with query forwarding | No click row or click ID; the cookie or transport refers to the original click |
| 5 | **Duplicate** | Click deduplication (opt-in, consent required; see [Click Deduplication](#click-deduplication)) | 302 redirect with query forwarding | No click row; the cookie entry keeps its click |
| 6 | **Click** | None of the above | 302 redirect with query forwarding | Click row (`is_test = 1` for a test click), click IDs, `kntnt_ad_attr_click`, cookie or transport per consent |

If the click row cannot be inserted, the request records nothing else either: no click IDs, no `kntnt_ad_attr_click`, no cookie entry and no transport, because every one of them must point at a click row. The visitor is redirected as usual.

Bots come first because nothing about their requests is worth recording. Speculative requests come next because declining them must not depend on consent or on what was recorded before. Internal requests follow, so that staff checking their own ads leave no trace. The repeat check precedes deduplication because it works without consent and resolves to a specific earlier click. A test click skips the internal, repeat and deduplication checks: it always gets its own click row, flagged as a test.

### Click

A request that none of the checks match. It inserts a click row, stores the click IDs it carries on that row, fires `kntnt_ad_attr_click` and sets the cookie or transport according to consent.

### Repeat

A reload, a back/forward navigation, a bookmark or a shared link reaches the tracking URL again with the platform click ID of the original ad click (for example the same `gclid`). The platform counts one click, so the plugin must too.

A request is a repeat if any unique click ID captured through `kntnt_ad_attr_click_id_capturers` equals a click ID already recorded on a click row of the **same** tracking URL (`Click_ID_Store::find_original_click()`, which uses the `(platform, value)` index). The same click ID on another tracking URL is not a repeat.

**Non-unique click IDs prove nothing.** A capturer registered with `'unique' => false` (see [Click ID Capture](#click-id-capture)) captures a value that many clicks share, such as Google's `gbraid` or `wbraid`. Another visitor's click may carry the same value, so a match on it would resolve one visitor's click to another's and report the earlier click's IDs with the later visitor's conversion. Such values are left out of the repeat check. The consequence is accepted: a reload, bookmark or shared link that carries only non-unique click IDs is not recognised as a repeat, and is counted as a click unless deduplication applies. Over-counting such clicks is preferred to linking a conversion to someone else's click.

A repeat:

- inserts no click row and stores no click ID;
- does not fire `kntnt_ad_attr_click`;
- resolves to the **original click**, the earliest click row on the tracking URL carrying one of the request's unique click IDs;
- with consent granted, sets the cookie entry for the hash to whichever is more recent: the existing entry's click or the original click. The existing entry only counts if its click row exists (the invariant under [Click Deduplication](#click-deduplication)), so the entry never moves backwards in time and never points at a missing row;
- with undetermined consent, passes the original click (hash and its `clicked_at`) through the [transport](#transport-mechanism-for-undefined-consent);
- with consent denied, stores nothing;
- redirects as usual, with query forwarding.

**No capturers, no repeat detection.** Repeats are recognised only by unique platform click IDs. Without registered capturers (the default without ad-platform add-ons), or for a request without a unique click ID, there is nothing to compare, and the request is counted as a click unless deduplication applies.

### Duplicate

A second click on the same tracking URL by a visitor whose `_ad_clicks` cookie shows a click within the deduplication window. Deduplication is off by default and needs consent, because it reads the cookie. See [Click Deduplication](#click-deduplication).

### Speculative request

Browsers may load a link before the visitor follows it: Chrome's speculation rules prefetch or prerender likely next pages and send `Sec-Purpose: prefetch` (or `prefetch;prerender`); older prefetching sends `Purpose: prefetch`. Such a request is not a click, and the visitor may never make one.

The handler declines it with a non-2xx, non-redirect, uncacheable response (503 with `nocache_headers()`) and records nothing: no click row, no click ID, no cookie, no transport and no action. Chrome treats a prefetch that ends in a non-2xx status as failed and does not use it ([Debug speculation rules](https://developer.chrome.com/docs/devtools/application/debugging-speculation-rules)). If the visitor then really clicks, the browser makes a fresh request, which is counted once. Both alternatives miscount: recording speculative requests counts clicks that never happen, and skipping them while still redirecting loses the real click whenever the browser reuses the prefetched redirect.

Detection runs through the `kntnt_ad_attr_is_speculative` filter, which works like `kntnt_ad_attr_is_bot`: `Speculation_Detector` registers the default header check, and other callbacks can add signals or overrule it.

### Internal request

A request from a logged-in user who can manage ad attribution (`kntnt_ad_attr`, granted to administrators and editors) is internal traffic. It is handled like a bot: redirected with query forwarding, with no click row, click ID, cookie, transport or `kntnt_ad_attr_click`. Detection runs through the `kntnt_ad_attr_is_internal` filter; `Internal_Detector` registers the default, which checks the current user and validates the logged-in cookie. Sites switch it off with `add_filter( 'kntnt_ad_attr_is_internal', '__return_false', PHP_INT_MAX )` or extend it, for example by IP. A test click is never internal.

### Not covered

Link-preview fetchers and security scanners that use browser User-Agents and send no purpose header are counted as clicks; there is no reliable signal for them. The [diagnostic log line](#diagnostic-log-line) records the headers needed to study what remains.

## Test Clicks

A request to a tracking URL with the query parameter `kntnt_ad_attr_test=1` (`Click_Handler::TEST_PARAM`) is a **test click**, whether the visitor is internal or not. It is processed like any click (bot and speculative checks, consent, cookie or transport, click-ID capture, `kntnt_ad_attr_click`), with three differences:

- Its click row gets `is_test = 1`. The main view, its totals and the CSV export leave test clicks out, and with them every conversion linked to them (see [admin-ui.md](admin-ui.md)).
- It is never internal, never a [repeat](#repeat) and never a [duplicate](#duplicate). Resolving it to an earlier ordinary click would leave no test row, and the conversion that follows would be reported as real.
- The parameter is removed before query forwarding, so the landing page never sees it.

A conversion linked to a test click is a test conversion (see [conversion-handling.md](conversion-handling.md#internal-traffic-and-test-conversions)). See the README section *Testing a live setup* for the end-to-end steps.

## Click Deduplication

When `kntnt_ad_attr_dedup_seconds` returns a non-zero value and the visitor has granted consent (`true`), the click handler checks the `_ad_clicks` cookie for an existing entry for the same hash. The click is treated as a duplicate when the elapsed time since the stored timestamp is less than the dedup window **and** a click row exists with the same hash and a `clicked_at` equal to the entry's timestamp (the same exact match the conversion lookup uses). Then:

- The cookie is sent again with the entry's **original** timestamp. This refreshes the cookie's expiry without moving the entry away from its click row.
- The visitor is redirected to the target URL.
- No click record is inserted, no click IDs are captured, no postmeta is populated, and no `kntnt_ad_attr_click` action fires.

This prevents inflated click counts from repeated visits (e.g. page refreshes, back-button navigation). Because the timestamp is never rewritten, the dedup window is measured from the original click: a re-click after the window has passed is recorded as a new click.

**Invariant:** every `_ad_clicks` entry the plugin writes points at an existing click row with the same hash and a `clicked_at` equal to the entry's timestamp. Conversions are linked to clicks by that exact match (see `conversion-handling.md`).

**Self-healing:** if the entry is within the window but no click row matches it, the request is not a duplicate. It is handled as a new click: a click row is inserted and the entry is set to that row's timestamp. This repairs cookies written by versions before 1.10.0, which rewrote the timestamp on duplicates.

When consent is `false` or `null`, the cookie cannot be read, so dedup is impossible — the click is always recorded, unless it is a [repeat](#repeat), which needs no cookie. This matches the consent-scenarios specification (Cases 7–16).

When dedup is disabled (default `0`), the dedup check is skipped entirely.

## Click ID Capture

Platform-specific click IDs (e.g. `gclid`, `fbclid`, `msclkid`) are captured via the `kntnt_ad_attr_click_id_capturers` filter, which returns an array mapping platform identifiers to capturers. A capturer is either a GET parameter name, whose value identifies one click, or an array `[ 'parameter' => string, 'unique' => bool ]`, where `unique` defaults to `true`. `'unique' => false` declares a parameter whose value many clicks share, such as `gbraid` or `wbraid`. A capturer without a parameter name is ignored.

If no capturers are registered (default), the filter returns `[]` and the foreach loop iterates zero times — no extra processing occurs.

Each registered capturer maps a platform to a GET parameter. The core iterates the capturers, sanitizes the parameter value with `sanitize_text_field()`, validates it is non-empty and at most 255 characters, and stores it via `Click_ID_Store::store( $click_row_id, $platform, $value )`. Unique and non-unique values are stored alike, so reporters get both; only the [repeat](#repeat) check tells them apart. A request that carries only non-unique click IDs is therefore never a repeat (see [Repeat](#repeat) for why that over-counting is accepted).

The click IDs are read from the request before anything is recorded, because a unique value already recorded for the tracking URL makes the request a [repeat](#repeat). They are stored **after** the click row is inserted and are keyed by that row's ID (`$wpdb->insert_id`), so each click ID belongs to exactly one click. If a click carries no ID for a platform, that click has no ID for that platform; it does not inherit an earlier click's. If the click insert fails, nothing is captured. A conversion linked to a click reports that click's IDs, never those of a later visitor on the same tracking URL (see [conversion-handling.md](conversion-handling.md)).

Click ID capture happens **before** the `kntnt_ad_attr_click` action fires, so click IDs are already stored when companion plugins receive the click notification. Click ID capture occurs independently of consent — like click counting, it does not set any cookies or track individuals.

Click IDs live exactly as long as their click row: retention cleanup, orphan cleanup and permanent deletion of a tracking URL delete them together with the clicks (see [lifecycle.md](lifecycle.md)).

## Per-Click UTM Fields

Content, Term, Id, and Group vary per click and are extracted from incoming UTM/MTM parameters, then stored directly in the `kntnt_ad_attr_clicks` table:

| Field | Column | UTM param | MTM param |
|---|---|---|---|
| Content | `utm_content` | `utm_content` | `mtm_content` |
| Term | `utm_term` | `utm_term` | `mtm_keyword` |
| Id | `utm_id` | `utm_id` | `mtm_cid` |
| Group | `utm_source_platform` | `utm_source_platform` | `mtm_group` |

Values are sanitized with `sanitize_text_field()` and truncated to 255 characters via `mb_substr()`. Empty values are stored as `NULL`.

## Postmeta Population (Source/Medium/Campaign)

Source, medium, and campaign are fixed per tracking URL and stored in postmeta. They are required when creating a tracking URL (v1.5.0+), but the click handler still attempts to populate empty values for backwards compatibility with pre-v1.5.0 URLs.

| Field | UTM param | MTM param | Meta key |
|---|---|---|---|
| Source | `utm_source` | `mtm_source` | `_utm_source` |
| Medium | `utm_medium` | `mtm_medium` | `_utm_medium` |
| Campaign | `utm_campaign` | `mtm_campaign` | `_utm_campaign` |

**Priority order** (highest first):

1. **Stored postmeta value** (set by admin when creating the tracking URL) — never overwritten.
2. **Incoming UTM parameter** from query string.
3. **Incoming MTM parameter** from query string.

Population occurs before the `kntnt_ad_attr_click` action fires.

## URL Matching

The plugin captures requests to `/<prefix>/<hash>` where prefix defaults to `ad` (configurable via `kntnt_ad_attr_url_prefix`).

**Rewrite rule:**

```php
$prefix = apply_filters( 'kntnt_ad_attr_url_prefix', 'ad' );

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

Query parameters (e.g. `gclid`, `fbclid`) are forwarded from the incoming request to the target URL (target URL parameters take precedence on collision). The merged parameters can be modified via the `kntnt_ad_attr_redirect_query_params` filter. Query parameters do not affect hash matching — the hash is extracted from the URL path via the rewrite rule, independently of the query string.

## Bot Detection

Bots are redirected to the target URL, but the click is **not** recorded and no cookie is set.

Bot detection is controlled by the filter `kntnt_ad_attr_is_bot` (default `false`). The plugin registers its own callback with User-Agent matching:

Signatures matched (case-insensitive substring match against User-Agent): `bot` (catches Googlebot, Bingbot, LinkedInBot, AdsBot-Google, etc.), `crawl`, `spider`, `slurp`, `facebookexternalhit`, `Mediapartners-Google`, `Yahoo`, `curl`, `wget`, `python-requests`, `HeadlessChrome`, `Lighthouse`, `GTmetrix`. An empty User-Agent is also treated as a bot. The filter `kntnt_ad_attr_is_bot` allows supplementing or replacing the built-in detection.

The plugin automatically adds `Disallow: /<prefix>/` to WordPress's virtual `robots.txt` via the `robots_txt` filter. Sites with a physical `robots.txt` need to add the line manually.

## Consent

Consent is checked via the filter `kntnt_ad_attr_has_consent`. Three states:

- **Yes (`true`)** — the visitor has accepted marketing cookies.
- **No (`false`)** — the visitor has declined.
- **Undefined (`null`)** — the visitor has not yet made a decision.

When no callback is registered, the default `null` (undetermined) activates the deferred consent transport mechanism. Sites without consent requirements should register a callback that returns `true`.

**Consent = yes:** Set the `_ad_clicks` cookie. Redirect to target URL.

**Consent = no:** Redirect to target URL. No cookie. Attribution is lost. Accepted.

**Consent = undefined:** Redirect to target URL, but pass the click (hash and click timestamp) via the transport mechanism (see below) so the client-side script can set the cookie if/when the visitor consents.

The click is logged in the database **always** regardless of consent. Click counting per hash is aggregated statistics and does not constitute personal data processing. It is the cookie that links the click to an individual.

## Transport Mechanism for Undefined Consent

When consent is undefined, the click needs to be transferred to the target URL. The transport carries it as a `<hash>:<timestamp>` pair (the same syntax as one `_ad_clicks` entry), where the timestamp is the `clicked_at` of the click row just inserted, as a Unix timestamp. Once consent is granted, the set-cookie endpoint writes an `_ad_clicks` entry only if a click row with exactly that hash and `clicked_at` exists, so the entry points at the visitor's own click and not at a later click on the same tracking URL by someone else (see [rest-api.md](rest-api.md#set-cookie)). The mechanism is controlled by the filter `kntnt_ad_attr_pending_transport`.

**`'cookie'`** (default) — the server sets a temporary cookie in the redirect response:

```
Set-Cookie: _aah_pending=<hash>%3A<timestamp>; Path=/; Max-Age=60; Secure; SameSite=Lax
```

PHP's `setcookie()` URL-encodes the `:` as `%3A`; the client-side script decodes the value. The cookie is *not* HttpOnly (the script must be able to read it). It lives for a maximum of 60 seconds and contains no personal data — it is a technical transport mechanism.

**`'fragment'`** — the server appends the pair as a URL fragment:

```
Location: https://example.com/landing-page#_aah=<hash>:<timestamp>
```

The script reads the fragment, stores the pair in `sessionStorage`, and clears the fragment with `history.replaceState`.

`Click_Handler::handle_pending_consent()` and `Cookie_Manager::set_transport_cookie()` take the timestamp as an explicit argument, so the transport can refer to any recorded click, not only the row inserted by the current request.

## Redirect Methods

Controlled by the filter `kntnt_ad_attr_redirect_method`:

**`'302'`** (default) — server-side redirect:

```php
nocache_headers();
wp_redirect( $target_url, 302 );
exit;
```

**`'js'`** — JavaScript redirect via `window.location.href`. Serves a minimal 200 page that redirects via JavaScript, so that client-side scripts (for example consent managers) can run before the page navigates away.

All redirect responses shall include `nocache_headers()` to prevent caching.

## Diagnostic Log Line

When logging is enabled (Tools → Ad Attribution → Logging), every request to a tracking URL that gets past the redirect loop guard writes exactly one line through `Logger::info( 'CORE', … )`, just before its response. A click whose row could not be inserted writes the same line through `Logger::error()`, with outcome `failed`:

```
[2026-10-20 14:03:11+02:00][CORE][INFO] Tracking URL request: outcome=repeat hash=3f9a1c0b test=0 sec_purpose="" purpose="" user_agent="Mozilla/5.0 (Macintosh; …) Chrome/141.0.0.0 Safari/537.36"
```

| Field | Value |
|---|---|
| `outcome` | `recorded`, `repeat`, `deduplicated`, `speculative`, `bot`, `internal` or `failed` |
| `hash` | The first 8 characters of the tracking URL's hash |
| `test` | `1` for a test click (`kntnt_ad_attr_test=1`), otherwise `0` |
| `sec_purpose`, `purpose`, `user_agent` | The raw `Sec-Purpose`, `Purpose` and `User-Agent` request headers, sanitized (no line breaks) and quoted; `""` when absent |

The line holds no IP address. With logging disabled, nothing is written. Requests that end in a 404 (unknown or unpublished hash, missing target, redirect loop) write no line.

The line exists to measure what is still counted as a click, for example link previews and scanners that look like browsers. Any further per-request fact belongs in this line as another field, so that each request still writes one line.

## Unknown Hash and Broken Target URL

If the hash does not exist in the database, or if `get_permalink( $target_post_id )` returns `false`: standard WordPress 404 page.

```php
status_header( 404 );
nocache_headers();
require get_404_template();
exit;
```
