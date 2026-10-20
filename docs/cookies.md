# Cookies

## `_ad_clicks`

Stores the visitor's clicked ads with timestamps.

| Property | Value |
|----------|-------|
| Name | `_ad_clicks` |
| Format | `<hash>:<unix_timestamp>,<hash>:<unix_timestamp>,…` |
| Attributes | `Path=/`, `HttpOnly`, `Secure`, `SameSite=Lax` |
| Lifetime | Configurable via `kntnt_ad_attr_cookie_lifetime` (default 90 days) |
| Max hashes | 50 (hardcoded based on 4 KB cookie limit) |
| Renewal | The cookie expiration date is renewed on each ad click, including deduplicated re-clicks |

**Cookie size calculation:** 50 hashes × 76 bytes per entry (64 hash + 1 separator + 10 timestamp + 1 comma) = 3,800 bytes. Cookie overhead ≈ 68 bytes. Total ≈ 3,868 bytes, fits within the 4,096 limit.

**Invariant:** every entry the plugin writes points at an existing click row in `kntnt_ad_attr_clicks` with the same hash and a `clicked_at` equal to the entry's timestamp. The conversion handler links a conversion to its click by this exact match.

**Repeated clicks:** If a visitor clicks the same ad again and the click is recorded, the timestamp is set to the new click row's time. The hash is not duplicated. If the re-click is deduplicated (within the `kntnt_ad_attr_dedup_seconds` window), no click row is recorded and the entry keeps its original timestamp; the cookie is only sent again to refresh its expiry. See [Click Deduplication](click-handling.md#click-deduplication).

**Max limit:** When 50 hashes are reached, the oldest is removed.

**Validation:** On read, the format is checked against `^[a-f0-9]{64}:\d{1,10}(,[a-f0-9]{64}:\d{1,10})*$`. If the format does not match, the cookie is ignored. In PHP, `Cookie_Manager` owns the `hash:timestamp` entry format: `format_entry()` and `parse_entry()` handle one entry wherever it appears (transport, fragment, set-cookie request), `serialize_entries()` and `parse()` a whole cookie. `js/pending-consent.js` keeps its own copy of the pattern.

## `_ad_last_conv`

Used for per-hash conversion deduplication. This cookie **only exists** when the `kntnt_ad_attr_dedup_seconds` filter returns a non-zero value (default: `0`, meaning no dedup and no cookie). The cookie is set **only** when a conversion is recorded and dedup is enabled. The same consent that applies to `_ad_clicks` implicitly applies to `_ad_last_conv` — if the visitor has an `_ad_clicks` cookie, consent has already been given. No separate consent check is needed.

| Property | Value |
|----------|-------|
| Name | `_ad_last_conv` |
| Format | `<hash>:<unix_timestamp>,<hash>:<unix_timestamp>,…` (same format as `_ad_clicks`) |
| Attributes | `Path=/`, `HttpOnly`, `Secure`, `SameSite=Lax` |
| Lifetime | Controlled by `kntnt_ad_attr_dedup_seconds` (default `0` — cookie not written), capped to max `cookie_lifetime × DAY_IN_SECONDS` |

Each entry records the last conversion timestamp for a specific hash. On subsequent conversions, hashes whose last conversion is within the dedup window are skipped, while other hashes proceed normally.

## `_aah_pending`

Temporary transport cookie for undefined consent. It hands the landing page's client-side script the click the visitor just made, so that the set-cookie endpoint can point the visitor's `_ad_clicks` entry at that exact click row once consent is granted (see [click-handling.md](click-handling.md#transport-mechanism-for-undefined-consent)).

| Property | Value |
|----------|-------|
| Name | `_aah_pending` |
| Format | `<hash>:<unix_timestamp>` — one click, same syntax as an `_ad_clicks` entry. The timestamp is the click row's `clicked_at`. Sent URL-encoded (`:` as `%3A`) by PHP's `setcookie()`. |
| Attributes | `Path=/`, **Not** HttpOnly, `Secure`, `SameSite=Lax` |
| Lifetime | 60 seconds |

**Validation:** The client-side script accepts the value only if it matches `^[a-f0-9]{64}:\d{1,10}$`, and expires the cookie after reading it whether it matched or not. Before version 1.10.0 the value was a bare hash.
