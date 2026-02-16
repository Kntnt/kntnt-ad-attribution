# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Plugin Does

WordPress plugin that measures which ads generate leads. Each ad gets a tracking URL (`/ad/<hash>`). Clicks are logged, a first-party cookie stores the hash, and when a form submission triggers a conversion, all clicked ads receive fractional time-weighted attribution.

## Naming Conventions

All machine-readable names use `kntnt-ad-attr` (hyphens) / `kntnt_ad_attr` (underscores) as prefix. The PHP namespace remains `Kntnt\Ad_Attribution`. The GitHub URL (`kntnt-ad-attribution`) is the only exception.

| Context | Name |
|---------|------|
| Text domain | `kntnt-ad-attr` |
| Post type | `kntnt_ad_attr_url` |
| Custom tables | `{prefix}kntnt_ad_attr_stats`, `{prefix}kntnt_ad_attr_click_ids`, `{prefix}kntnt_ad_attr_queue` |
| Capability | `kntnt_ad_attr` |
| DB version option | `kntnt_ad_attr_version` |
| Cron hooks | `kntnt_ad_attr_daily_cleanup`, `kntnt_ad_attr_process_queue` |
| REST base | `kntnt-ad-attr-urls` |
| All filters/actions | `kntnt_ad_attr_*` |
| Namespace | `Kntnt\Ad_Attribution` |

## Architecture

**Singleton bootstrap:** `kntnt-ad-attribution.php` loads `autoloader.php` (PSR-4 for `Kntnt\Ad_Attribution\*` → `classes/*.php`), then `Plugin::get_instance()` creates the singleton which instantiates all components and registers hooks.

**Lifecycle files (not autoloaded):**
- `install.php` — activation: grants capability, runs Migrator, registers CPT, flushes rewrite rules, schedules cron.
- `uninstall.php` — complete data removal. Runs outside the plugin's namespace (no autoloader available), uses raw `$wpdb` queries.
- `Plugin::deactivate()` — clears cron, transients, and rewrite rules. Preserves data.

**Migrator pattern:** Version-based migrations in `migrations/X.Y.Z.php`. Each file returns `function(\wpdb $wpdb): void`. Migrator compares `kntnt_ad_attr_version` option with the plugin header version on `plugins_loaded` and runs pending files in order.

**Data model:** Tracking URLs are stored as CPT `kntnt_ad_attr_url` (with meta `_hash`, `_target_post_id`, `_utm_*`). Click/conversion statistics are stored in `{prefix}kntnt_ad_attr_stats` with composite PK `(hash, date)` using `ON DUPLICATE KEY UPDATE` for atomic increments. Platform-specific click IDs are stored in `{prefix}kntnt_ad_attr_click_ids` with composite PK `(hash, platform)`. Async report jobs are stored in `{prefix}kntnt_ad_attr_queue` with auto-increment PK and status-based processing.

**Adapter infrastructure (v1.2.0):** Click_ID_Store, Queue, and Queue_Processor are instantiated in Plugin constructor and injected into Click_Handler (Click_ID_Store), Conversion_Handler (Click_ID_Store, Queue, Queue_Processor), Cron (Click_ID_Store, Queue), and Admin_Page (Queue). Two new filters: `kntnt_ad_attr_click_id_capturers` and `kntnt_ad_attr_conversion_reporters`. Queue processing via `kntnt_ad_attr_process_queue` cron hook.

**Admin tab extensibility (v1.3.0):** The tab list is filterable via `kntnt_ad_attr_admin_tabs`. Unrecognized tab slugs dispatch to the `kntnt_ad_attr_admin_tab_{$tab}` action, allowing add-on plugins to register custom admin tabs.

**Query parameter forwarding (v1.3.0):** Click_Handler merges incoming query parameters (e.g. `gclid`, `fbclid`) into the redirect target URL. Target URL parameters take precedence on collision. The merged set is filterable via `kntnt_ad_attr_redirect_query_params`.

**Optional UTM fields (v1.3.0):** Source, medium, and campaign are no longer required when creating tracking URLs. UTM meta fields are stored only when non-empty. Campaign_List_Table uses LEFT JOIN for all UTM fields (source, medium, campaign, content, term).

## Specifications

All specs are in `docs/`. Read the relevant doc before implementing a feature:

| Doc | Covers |
|-----|--------|
| `architecture.md` | Data model, CPT args, hash generation, target URL resolving |
| `click-handling.md` | Click flow, rewrite rules, bot detection, click ID capture, redirect |
| `cookies.md` | Cookie format, attributes, size limits, validation regex |
| `conversion-handling.md` | Conversion flow, dedup, attribution formula, reporter enqueueing |
| `rest-api.md` | REST endpoints (set-cookie, search-posts) |
| `client-script.md` | sessionStorage, pending consent, JS consent interface |
| `admin-ui.md` | WP_List_Table tabs, SQL queries, CSV export |
| `developer-hooks.md` | All filters/actions with implementation logic |
| `lifecycle.md` | Activation, deactivation, uninstall, migration, cron |
| `security.md` | Validation, nonces, capabilities, error handling, time zones |
| `coding-standards.md` | Full coding standards reference |
| `file-structure.md` | Project file organization |
| `consent-example.md` | Complete consent integration example (Real Cookie Banner) |

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

## Known Gotchas

- `Plugin::get_plugin_data()` must pass `$translate = false` to WP's `get_plugin_data()` to avoid triggering `_load_textdomain_just_in_time` warnings when called before `init` (e.g. from Migrator on `plugins_loaded`).
- The CPT label uses a static string (not `__()`) because it has `show_ui => false` and is never displayed.
- `uninstall.php` runs without the namespace autoloader — use fully qualified function calls and raw `$wpdb`.
