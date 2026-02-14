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
| Custom table | `{prefix}kntnt_ad_attr_stats` |
| Capability | `kntnt_ad_attr` |
| DB version option | `kntnt_ad_attr_version` |
| Cron hook | `kntnt_ad_attr_daily_cleanup` |
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

**Data model:** Tracking URLs are stored as CPT `kntnt_ad_attr_url` (with meta `_hash`, `_target_post_id`, `_utm_*`). Click/conversion statistics are stored in `{prefix}kntnt_ad_attr_stats` with composite PK `(hash, date)` using `ON DUPLICATE KEY UPDATE` for atomic increments.

## Implementation Status

The plugin is being built incrementally per `IMPLEMENTATION-PLAN.md`. Increments 1–5 are complete. Increment 1: skeleton + data model + lifecycle. Increment 2: click handling (Click_Handler, Cookie_Manager, Consent, Bot_Detector). Increment 3: admin UI URLs tab (Admin_Page, Url_List_Table, Rest_Endpoint, select2 form, CSS/JS assets). Increment 4: conversion handling (Conversion_Handler — dedup, cookie parse, hash validation, fractional time-weighted attribution, transactional DB write). Increment 5: client-side script + REST set-cookie (pending-consent.js — sessionStorage, consent callback, REST POST; Rest_Endpoint set-cookie route with hash validation and cookie merge; Plugin script enqueue with localized REST URL and nonce). Remaining increments add campaigns/CSV, and cron/updater/translations.

## Specifications

All specs are in `docs/`. Read the relevant doc before implementing a feature:

| Doc | Covers |
|-----|--------|
| `architecture.md` | Data model, CPT args, hash generation, target URL resolving |
| `click-handling.md` | 12-step click flow, rewrite rules, bot detection, redirect |
| `cookies.md` | Cookie format, attributes, size limits, validation regex |
| `conversion-handling.md` | 10-step conversion flow, dedup, attribution formula |
| `rest-api.md` | REST endpoints (set-cookie, search-posts) |
| `client-script.md` | sessionStorage, pending consent, JS consent interface |
| `admin-ui.md` | WP_List_Table tabs, SQL queries, CSV export |
| `developer-hooks.md` | All filters/actions with implementation logic |
| `lifecycle.md` | Activation, deactivation, uninstall, migration, cron |
| `security.md` | Validation, nonces, capabilities, error handling, time zones |
| `coding-standards.md` | Full coding standards reference |

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
