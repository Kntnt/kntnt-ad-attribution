# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Plugin Does

WordPress plugin that measures which ads generate leads. Each ad gets a tracking URL (`/ad/<hash>`, prefix configurable via `kntnt_ad_attr_url_prefix` filter). Non-bot clicks are logged individually in a custom table, a first-party cookie stores the hash with a timestamp, and when a form submission triggers a conversion via the `kntnt_ad_attr_conversion` action, the most recent click receives full attribution (filterable via `kntnt_ad_attr_attribution` to support multi-touch models).

## Naming Conventions

All machine-readable names use `kntnt-ad-attr` (hyphens) / `kntnt_ad_attr` (underscores) as prefix. The PHP namespace remains `Kntnt\Ad_Attribution`. The GitHub URL (`kntnt-ad-attribution`) is the only exception.

| Context | Name |
|---------|------|
| Text domain | `kntnt-ad-attr` |
| Post type | `kntnt_ad_attr_url` |
| Custom tables | `{prefix}kntnt_ad_attr_clicks`, `{prefix}kntnt_ad_attr_conversions`, `{prefix}kntnt_ad_attr_click_ids`, `{prefix}kntnt_ad_attr_queue` |
| Capability | `kntnt_ad_attr` |
| DB version option | `kntnt_ad_attr_version` |
| Cron hooks | `kntnt_ad_attr_daily_cleanup`, `kntnt_ad_attr_process_queue` |
| REST namespace | `kntnt-ad-attribution/v1` |
| REST CPT base | `kntnt-ad-attr-urls` |
| All filters/actions | `kntnt_ad_attr_*` |
| Namespace | `Kntnt\Ad_Attribution` |
| Cookies | `_ad_clicks`, `_aah_pending`, `_ad_last_conv` |

## Architecture

**Singleton bootstrap:** `kntnt-ad-attribution.php` loads `autoloader.php` (PSR-4 for `Kntnt\Ad_Attribution\*` → `classes/*.php`), then `Plugin::get_instance()` creates the singleton which instantiates all components and registers hooks. A PHP 8.3 version check aborts with an admin notice if the requirement is not met.

**Component instantiation order in `Plugin::__construct()`:**

1. `Updater` — GitHub-based update checker
2. `Migrator` — database migration runner
3. `Post_Type` — CPT registration
4. `Cookie_Manager` — cookie read/write operations (stateless)
5. `Consent` — three-state consent resolution
6. `Bot_Detector` — User-Agent filtering
7. `Click_ID_Store` — platform-specific click ID storage (v1.2.0)
8. `Queue` — async job queue (v1.2.0)
9. `Queue_Processor(Queue)` — queue job dispatcher (v1.2.0)
10. `Click_Handler(Cookie_Manager, Consent, Bot_Detector, Click_ID_Store)` — click processing & redirect
11. `Conversion_Handler(Cookie_Manager, Click_ID_Store, Queue, Queue_Processor)` — conversion attribution
12. `Cron(Click_ID_Store, Queue)` — scheduled cleanup tasks
13. `Admin_Page(Queue)` — admin UI orchestration
14. `Rest_Endpoint(Cookie_Manager, Consent)` — REST API routes

**Lifecycle files (not autoloaded):**

- `install.php` — activation: grants `kntnt_ad_attr` capability to admin/editor, runs Migrator, registers CPT, flushes rewrite rules, schedules daily cron.
- `uninstall.php` — complete data removal. Runs outside the plugin's namespace (no autoloader available), uses raw `$wpdb` queries. Drops all four custom tables, deletes CPT posts, removes capability from all roles, clears options/transients/cron.
- `Plugin::deactivate()` — clears cron (`kntnt_ad_attr_daily_cleanup` and `kntnt_ad_attr_process_queue`), transients, and rewrite rules. Preserves data.

**Migrator pattern:** Version-based migrations in `migrations/X.Y.Z.php`. Each file returns `function(\wpdb $wpdb): void`. Migrator compares `kntnt_ad_attr_version` option with the plugin header version on `plugins_loaded` and runs pending files in order.

**Data model:** Tracking URLs are stored as CPT `kntnt_ad_attr_url` (with meta `_hash`, `_target_post_id`, `_utm_source`, `_utm_medium`, `_utm_campaign`). Individual clicks are stored in `{prefix}kntnt_ad_attr_clicks` with per-click UTM fields (`utm_content`, `utm_term`, `utm_id`, `utm_source_platform`). Conversions are stored in `{prefix}kntnt_ad_attr_conversions` linked to specific clicks via `click_id`, with fractional attribution values. Platform-specific click IDs are stored in `{prefix}kntnt_ad_attr_click_ids` with composite PK `(hash, platform)`. Async report jobs are stored in `{prefix}kntnt_ad_attr_queue` with auto-increment PK and status-based processing.

**Shared utility methods (v1.5.1):** `Post_Type::get_valid_hashes(array $hashes)` returns hashes with published tracking URL posts (used by both `Conversion_Handler` and `Rest_Endpoint`). `Post_Type::get_distinct_meta_values(string $meta_key)` returns sorted distinct meta values for filter dropdowns (used by both `Url_List_Table` and `Campaign_List_Table`).

**Adapter infrastructure (v1.2.0):** Click_ID_Store, Queue, and Queue_Processor are instantiated in Plugin constructor and injected into Click_Handler (Click_ID_Store), Conversion_Handler (Click_ID_Store, Queue, Queue_Processor), Cron (Click_ID_Store, Queue), and Admin_Page (Queue). Two new filters: `kntnt_ad_attr_click_id_capturers` and `kntnt_ad_attr_conversion_reporters`. Queue processing via `kntnt_ad_attr_process_queue` cron hook. If no adapters are registered, zero overhead.

**Admin tab extensibility (v1.3.0):** The tab list is filterable via `kntnt_ad_attr_admin_tabs`. Unrecognized tab slugs dispatch to the `kntnt_ad_attr_admin_tab_{$tab}` action, allowing add-on plugins to register custom admin tabs.

**Query parameter forwarding (v1.3.0):** Click_Handler merges incoming query parameters (e.g. `gclid`, `fbclid`) into the redirect target URL. Target URL parameters take precedence on collision. The merged set is filterable via `kntnt_ad_attr_redirect_query_params`.

**MTM parameter support (v1.4.0+):** Click_Handler populates empty postmeta fields (source, medium, campaign only) at click time from incoming UTM or MTM (Matomo Tag Manager) query parameters. Priority: stored value > UTM param > MTM param. Per-click fields (Content, Term, Id, Group) are captured from incoming parameters and stored directly in the clicks table. Admin UI labels use generic names (Source, Medium, etc.) without "UTM" prefix. Internal meta keys retain their `_utm_*` naming for backwards compatibility.

**Required Source/Medium/Campaign (v1.5.0):** Source, medium, and campaign are required when creating tracking URLs. Content/Term/Id/Group are not stored in postmeta — they vary per click and are stored in the `kntnt_ad_attr_clicks` table.

**Individual click and conversion recording (v1.5.0):** Replaced the aggregated `kntnt_ad_attr_stats` table with two new tables: `kntnt_ad_attr_clicks` (individual click records with per-click UTM fields) and `kntnt_ad_attr_conversions` (conversion attribution linked to specific clicks). Attribution uses a filterable last-click model (default: 1.0 to most recent click) via `kntnt_ad_attr_attribution`. Click retention is configurable via `kntnt_ad_attr_click_retention_days` (default: 365 days).

**Cached URL prefix (v1.5.1):** `Plugin::get_url_prefix()` caches the result of the `kntnt_ad_attr_url_prefix` filter in a static variable, ensuring the filter fires only once per request despite being called from multiple locations (rewrite rule, loop guard, robots.txt, hash generation).

## Hook Reference (Quick)

**Filters (16):** `kntnt_ad_attr_has_consent`, `kntnt_ad_attr_default_consent`, `kntnt_ad_attr_redirect_method`, `kntnt_ad_attr_url_prefix`, `kntnt_ad_attr_cookie_lifetime`, `kntnt_ad_attr_dedup_days`, `kntnt_ad_attr_pending_transport`, `kntnt_ad_attr_is_bot`, `kntnt_ad_attr_attribution`, `kntnt_ad_attr_click_retention_days`, `kntnt_ad_attr_utm_options`, `kntnt_ad_attr_admin_tabs`, `kntnt_ad_attr_redirect_query_params`, `kntnt_ad_attr_click_id_capturers`, `kntnt_ad_attr_conversion_reporters`, `wp_untrash_post_status` (priority 20, for CPT only).

**Actions (5):** `kntnt_ad_attr_click` (hash, target_url, campaign_data — fires for all non-bot clicks), `kntnt_ad_attr_conversion` (trigger from form plugin), `kntnt_ad_attr_conversion_recorded` (attributions, context — fires after DB write), `kntnt_ad_attr_admin_tab_{$tab}` (custom tab rendering), `kntnt_ad_attr_daily_cleanup` (cron).

See `docs/developer-hooks.md` for full documentation.

## Tests

The test suite has three levels. See `docs/testing-strategy.md` for full details.

| Level | Framework | Tests | What it covers |
|-------|-----------|-------|----------------|
| PHP unit | Pest + Brain Monkey + Mockery | 219 | Individual class methods in isolation |
| JS unit | Vitest + happy-dom | 28 | `pending-consent.js` and `admin.js` |
| Integration | Bash + curl + WordPress Playground | 14 suites | End-to-end flows: click, conversion, admin, REST, cron |

### Running tests

```bash
# All tests (unit + integration)
bash run-tests.sh

# Unit tests only (no Playground, fast)
bash run-tests.sh --unit-only

# Integration tests only
bash run-tests.sh --integration-only

# Filter by pattern
bash run-tests.sh --filter consent
```

### Running tests individually

```bash
# PHP unit tests (requires PHP 8.3 in PATH, or use ddev)
ddev here ./vendor/bin/pest
./vendor/bin/pest --filter CookieManager

# JS unit tests
npx vitest run

# Single integration test (requires Playground running on port 9400)
bash tests/Integration/test-click-flow.sh
```

### Requirements

PHP 8.3+, Node.js 20.18+, Composer 2, npm, curl, jq. Dependencies are installed automatically by `run-tests.sh`.

### CI

GitHub Actions workflow (`.github/workflows/tests.yml`) runs all three levels on push/PR to `main`. PHP coverage via pcov; JS coverage via v8.

### Integration test architecture

Integration tests run against a disposable WordPress Playground instance (WASM-based SQLite, no MySQL needed). Two mu-plugins provide test infrastructure:

- `tests/Integration/fake-consent-plugin/` — simulates consent states via WP option
- `tests/Integration/test-helpers-plugin/` — REST endpoints for fixtures, DB queries, cookie manipulation

**Known WASM PHP gotcha:** Playground reuses the PHP process between requests. Manual `$_COOKIE`/`$_GET`/`$_POST` modifications persist across requests. The `test-clear-cookies` endpoint and `clear_cookies` fixture helper reset this state between tests.

## Specifications

All specs are in `docs/`. Read the relevant doc before implementing a feature:

| Doc | Covers |
|-----|--------|
| `architecture.md` | Data model, CPT args, hash generation, target URL resolving |
| `click-handling.md` | Click flow, rewrite rules, bot detection, click ID capture, redirect |
| `cookies.md` | Cookie format, attributes, size limits, validation regex |
| `conversion-handling.md` | Conversion flow, dedup, attribution formula, reporter enqueueing |
| `rest-api.md` | REST endpoints (set-cookie, search-posts), rate limiting |
| `client-script.md` | sessionStorage, pending consent, JS consent interface |
| `admin-ui.md` | WP_List_Table tabs, SQL queries, CSV export |
| `developer-hooks.md` | All filters/actions with implementation logic |
| `lifecycle.md` | Activation, deactivation, uninstall, migration, cron |
| `security.md` | Validation, nonces, capabilities, error handling, time zones |
| `coding-standards.md` | Full coding standards reference |
| `file-structure.md` | Project file organization, updates, translations |
| `consent-example.md` | Complete consent integration example (Real Cookie Banner) |
| `testing-strategy.md` | Test suite architecture, specifications, implementation plan |

## Coding Standards

- **PHP 8.3 features required:** typed properties, readonly, match expressions, arrow functions, null-safe operator, named arguments, `str_contains()`/`str_starts_with()`.
- `declare(strict_types=1)` in every PHP file.
- `[]` not `array()`. Natural conditions, not Yoda. Trailing commas in multi-line arrays.
- PSR-4 autoloading: `Kntnt\Ad_Attribution\Click_Handler` → `classes/Click_Handler.php`.
- PHPDoc on every class, method, property, constant. `@since 1.0.0` for initial release.
- Inline comments explain **why**, not what. Written for senior developers.
- All identifiers and comments in **English**.
- All user-facing strings translatable via `__()` / `esc_html__()` with text domain `kntnt-ad-attr`.
- All SQL via `$wpdb->prepare()`. All admin URLs via `admin_url()`. All superglobals sanitized.
- Errors are silent toward visitors, logged via `error_log()`.
- JavaScript: ES6+, IIFE with `'use strict'`, `const` default, arrow functions, `fetch` over jQuery.

## Known Gotchas

- `Plugin::get_plugin_data()` must pass `$translate = false` to WP's `get_plugin_data()` to avoid triggering `_load_textdomain_just_in_time` warnings when called before `init` (e.g. from Migrator on `plugins_loaded`).
- The CPT label uses a static string (not `__()`) because it has `show_ui => false` and is never displayed.
- `uninstall.php` runs without the namespace autoloader — use fully qualified function calls and raw `$wpdb`.
- `Post_Type` registers `wp_untrash_post_status` at priority 20 to override ACF (which overrides the default untrash status for all post types).
- The REST `set-cookie` endpoint has rate limiting: 10 requests per minute per IP via transient.
- Select2 loaded from cdnjs has SRI (Subresource Integrity) hashes added via `script_loader_tag` / `style_loader_tag` filters.
- Hash generation uses `random_bytes(32)` — the hash is an opaque identifier, not derived from UTM parameters.
- The `kntnt_ad_attr_click` action fires for all non-bot clicks regardless of consent state, enabling companion plugins to capture platform-specific parameters even before consent is resolved.
- Admin page is registered under Tools (`add_management_page`), not as a top-level menu item.
- CSV export uses POST with its own nonce (`kntnt_ad_attr_export`) and reconstructs GET filter params from POST data for `Campaign_List_Table` compatibility.
