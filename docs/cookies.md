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
| Renewal | The cookie expiration date is renewed on each new ad click |

**Cookie size calculation:** 50 hashes × 76 bytes per entry (64 hash + 1 separator + 10 timestamp + 1 comma) = 3,800 bytes. Cookie overhead ≈ 68 bytes. Total ≈ 3,868 bytes, fits within the 4,096 limit.

**Repeated clicks:** If a visitor clicks the same ad again, the timestamp is updated. The hash is not duplicated.

**Max limit:** When 50 hashes are reached, the oldest is removed.

**Validation:** On read, the format is checked against `^[a-f0-9]{64}:\d{1,10}(,[a-f0-9]{64}:\d{1,10})*$`. If the format does not match, the cookie is ignored.

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

Temporary transport cookie for undefined consent.

| Property | Value |
|----------|-------|
| Name | `_aah_pending` |
| Format | SHA-256 hash (64 characters) |
| Attributes | `Path=/`, **Not** HttpOnly, `Secure`, `SameSite=Lax` |
| Lifetime | 60 seconds |
