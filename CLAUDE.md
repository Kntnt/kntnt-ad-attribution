# Kntnt Ad Attribution

Version: 1.0.0

This file and the documents in `docs/` are all a developer needs to implement the plugin. They are self-contained and do not require reading README.md or existing code.

## Overview

Kntnt Ad Attribution is a WordPress plugin that measures which ads generate leads. Each ad gets a unique tracking URL (`/ad/<hash>`). When a visitor clicks the ad, the server logs the click and stores the hash in a first-party cookie. When the visitor later submits a lead form, the server reads the cookie and fractionally attributes the conversion to all clicked ads, weighted by recency.

The plugin is platform-agnostic and works with any ad platform (Google Ads, Meta Ads, LinkedIn Ads, Microsoft Ads, etc.) that supports custom destination URLs.

The plugin does not integrate with any specific consent or form plugin. Instead, it exposes hooks that are connected to the plugins of choice via the theme's `functions.php`, a mu-plugin, or a code snippet plugin.

## Terminology

The following terms apply throughout the specification and codebase:

| Term | Meaning |
|------|---------|
| **tracking URL** | The URL the ad points to: `https://example.com/ad/<hash>` |
| **target URL** | The page the visitor lands on after redirect |
| **hash** | 64-character SHA-256 hex string that uniquely identifies a tracking URL |
| `target_url` | The target URL in code and SQL (resolved dynamically from post ID) |
| `tracking_url` | The tracking URL in code and SQL |

The term "campaign URL" shall **not** be used — it is ambiguous.

## System Requirements

| Requirement | Minimum |
|-------------|---------|
| PHP | 8.3 |
| WordPress | 6.9 |
| HTTPS | Required (cookies are set with the `Secure` flag) |
| MySQL/MariaDB | 5.7 / 10.3 (supports `ON DUPLICATE KEY UPDATE`) |

The plugin shall check the PHP version on activation and abort with a clear error message if the requirement is not met.

## Multisite

The plugin does not support WordPress multisite in version 1.

## Limitations

| Limitation | Description |
|------------|-------------|
| **ITP (Safari/iOS)** | Safari may limit cookie lifetime to 7 days from a classified cross-site source. The JS redirect method can improve this in some cases. |
| **No feedback to ad platforms** | The plugin provides internal statistics. It does not send data back to Google Ads, Meta, etc. |
| **Cookies can be deleted** | If the visitor clears cookies or uses incognito mode, the attribution link is broken. |
| **No cross-device tracking** | A click on mobile + conversion on laptop are not attributed. |
| **Impressions are not measured** | Ad impressions occur on the platform and never reach the server. |
| **No multisite support** | Version 1 does not support WordPress multisite. |

## Naming Conventions — Quick Reference

| Context | Name |
|---------|------|
| Plugin slug / text domain | `kntnt-ad-attribution` |
| Post type | `kntnt_ad_attr_url` |
| Custom table | `{prefix}kntnt_ad_attr_stats` |
| Capability | `kntnt_ad_attr` |
| DB version in options | `kntnt_ad_attr_version` |
| All filters/actions | `kntnt_ad_attribution_*` |

## Cookies — Quick Reference

| Cookie | Attributes | Lifetime |
|--------|------------|----------|
| `_ad_clicks` | Path=/, HttpOnly, Secure, SameSite=Lax | 90 days (configurable) |
| `_ad_last_conv` | Path=/, HttpOnly, Secure, SameSite=Lax | 30 days (capped to cookie_lifetime) |
| `_aah_pending` | Path=/, **Not** HttpOnly, Secure, SameSite=Lax | 60 seconds |

## Hooks — Quick Reference

### Filters

| Filter | Default | Description |
|--------|---------|-------------|
| `kntnt_ad_attribution_has_consent` | fallback → `default_consent` | Three states: `true`, `false`, `null` |
| `kntnt_ad_attribution_default_consent` | `true` | Fallback when no consent callback is registered |
| `kntnt_ad_attribution_redirect_method` | `'302'` | `'302'` or `'js'` |
| `kntnt_ad_attribution_url_prefix` | `'ad'` | URL prefix for tracking URLs |
| `kntnt_ad_attribution_cookie_lifetime` | `90` | Days. Affects cookie + attribution formula |
| `kntnt_ad_attribution_dedup_days` | `30` | Days. Capped to max cookie_lifetime |
| `kntnt_ad_attribution_pending_transport` | `'cookie'` | `'cookie'` or `'fragment'` |
| `kntnt_ad_attribution_is_bot` | `false` | Bot detection. The plugin registers its own UA callback |

### Actions

| Action | Description |
|--------|-------------|
| `kntnt_ad_attribution_conversion` | Trigger a conversion (connect your form plugin here) |
| `kntnt_ad_attribution_conversion_recorded` | Fires after a recorded conversion with `array $attributions` |

## Detailed Documentation

Each aspect of the plugin is documented in separate files:

| Document | Contents |
|----------|----------|
| [docs/architecture.md](docs/architecture.md) | Data model (CPT + stats table), hash generation, target URL resolving |
| [docs/click-handling.md](docs/click-handling.md) | Click flow, URL matching (rewrite rules), bot detection, consent, transport mechanism, redirect |
| [docs/cookies.md](docs/cookies.md) | All three cookies with format, attributes, size calculation, validation |
| [docs/conversion-handling.md](docs/conversion-handling.md) | Conversion flow, deduplication, attribution formula, database write in transaction |
| [docs/rest-api.md](docs/rest-api.md) | REST endpoints (set-cookie, search-posts), nonce/page cache |
| [docs/client-script.md](docs/client-script.md) | sessionStorage, page load logic, REST calls, error handling, JS consent interface |
| [docs/admin-ui.md](docs/admin-ui.md) | Tab navigation, URLs tab (WP_List_Table), Campaigns tab (SQL), CSV export |
| [docs/developer-hooks.md](docs/developer-hooks.md) | All filters and actions with code examples and implementation logic |
| [docs/lifecycle.md](docs/lifecycle.md) | Activation, deactivation, uninstallation, migration, cron, warnings |
| [docs/security.md](docs/security.md) | Validation, sanitization, nonces, capabilities, cookie security, error handling, time zones |
| [docs/coding-standards.md](docs/coding-standards.md) | PHP 8.3, code style (Airbnb-inspired), WordPress deviations, PSR-4, documentation rules |
| [docs/file-structure.md](docs/file-structure.md) | File structure, GitHub updates, translations |
| [docs/consent-example.md](docs/consent-example.md) | Complete Real Cookie Banner integration (PHP + JavaScript) |
