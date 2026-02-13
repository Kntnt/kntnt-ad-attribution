# Implementation Plan — Incremental Development

Each increment produces a plugin that activates, deactivates, and uninstalls cleanly in WordPress. No increment introduces dead code — every file added is wired up and exercised by the end of that increment.

## Increment 1 — Plugin Skeleton + Data Model + Lifecycle

**Goal:** The plugin activates, creates its database table, registers the CPT, and can be cleanly deactivated and uninstalled.

**Files created:**

```
kntnt-ad-attribution/
├── kntnt-ad-attribution.php      ← Plugin header, PHP version check, autoloader bootstrap
├── autoloader.php                ← PSR-4 autoloader for Kntnt\Ad_Attribution
├── install.php                   ← Activation: capability, migration, flush rewrite, schedule cron
├── uninstall.php                 ← Complete data removal
├── classes/
│   ├── Plugin.php                ← Singleton, hooks registration, option access
│   ├── Post_Type.php             ← CPT registration (kntnt_ad_attr_url)
│   └── Migrator.php              ← Version-based migration runner
└── migrations/
    └── 1.0.0.php                 ← CREATE TABLE kntnt_ad_attr_stats
```

**What is implemented:**

- `kntnt-ad-attribution.php`: Plugin header with metadata (name, version, author, text domain, requires PHP 8.3). PHP version check on load. Requires `autoloader.php`. Instantiates `Plugin::instance()`. Registers activation hook → `install.php`. Registers uninstall hook → `uninstall.php`.
- `autoloader.php`: PSR-4 autoloader that maps `Kntnt\Ad_Attribution\*` to `classes/*.php`.
- `Plugin.php`: Singleton pattern. On `plugins_loaded`: runs Migrator, registers Post_Type. Stores/retrieves plugin version from `kntnt_ad_attr_version` option.
- `Post_Type.php`: Registers CPT `kntnt_ad_attr_url` with `show_ui => false`, `show_in_rest => true`, `supports => ['title']`. All arguments per architecture.md.
- `Migrator.php`: Compares stored DB version with plugin version. Executes migration files in order. Updates `kntnt_ad_attr_version`.
- `migrations/1.0.0.php`: Returns a callable that creates `{prefix}kntnt_ad_attr_stats` with the schema from architecture.md.
- `install.php`: Adds `kntnt_ad_attr` capability to Administrator and Editor. Runs migration. Calls `flush_rewrite_rules()`. Schedules `kntnt_ad_attr_daily_cleanup` cron (hook only — Cron class comes in increment 7).
- `uninstall.php`: Removes capability from all roles. Drops stats table. Deletes all CPT posts + meta. Deletes `kntnt_ad_attr_version` option. Clears transients and cron.

**Verification:**

- Activate → no errors, table exists in database, CPT is registered, capability assigned.
- Deactivate → cron cleared, rewrite rules flushed. Data preserved.
- Uninstall → table dropped, CPT posts removed, option removed, capability removed.

---

## Increment 2 — Click Handling (Server-Side)

**Goal:** Visiting `/ad/<hash>` logs a click, sets a cookie, and redirects to the target page. Bot detection and consent logic work.

**Files created:**

```
classes/
├── Cookie_Manager.php            ← Read/write/validate/merge _ad_clicks cookie
├── Consent.php                   ← Three-state consent check via filter
├── Bot_Detector.php              ← User-Agent filtering + robots.txt rule
└── Click_Handler.php             ← Rewrite rule, template_redirect, 12-step flow
```

**What is implemented:**

- `Cookie_Manager.php`: Parse `_ad_clicks` cookie (validate format against regex `^[a-f0-9]{64}:\d{1,10}(,...)*$`). Merge hashes (add/update timestamp, enforce max 50, remove oldest). Serialize to cookie format. Set cookie with correct attributes (Path=/, HttpOnly, Secure, SameSite=Lax). Set `_aah_pending` transport cookie (not HttpOnly, 60s). Read `_ad_last_conv` cookie.
- `Consent.php`: `check()` method implementing `has_filter()` → fallback to `default_consent` logic from developer-hooks.md. Returns `true`, `false`, or `null`.
- `Bot_Detector.php`: Registers callback on `kntnt_ad_attribution_is_bot`. Case-insensitive User-Agent matching for all signatures listed in click-handling.md. Empty UA = bot. Adds `Disallow: /<prefix>/` to virtual `robots.txt` via `robots_txt` filter.
- `Click_Handler.php`: Registers rewrite rule on `init`. Registers `kntnt_ad_attr_hash` query var. On `template_redirect`: implements the complete 12-step flow from click-handling.md. Hash validation, DB lookup with post_status = 'publish' check, target URL resolution, redirect loop guard, bot check, click logging (INSERT … ON DUPLICATE KEY UPDATE), consent handling (yes → cookie, no → redirect only, null → transport), redirect (302 or JS per filter).

**Plugin.php updated:** Instantiates and wires Cookie_Manager, Consent, Bot_Detector, Click_Handler.

**Verification:**

- Create a tracking URL manually (WP-CLI: `wp post create --post_type=kntnt_ad_attr_url --post_status=publish --post_title="…"` + set meta `_hash`, `_target_post_id`).
- Visit `/ad/<hash>` → redirected to target page.
- Check stats table: click count = 1.
- Check browser cookies: `_ad_clicks` present with hash:timestamp.
- Visit with bot UA → redirected but no click logged, no cookie.
- Visit with draft/trashed tracking URL → 404.

---

## Increment 3 — Admin UI: URLs Tab

**Goal:** Administrators can create, list, edit, and trash tracking URLs via the WordPress admin.

**Files created:**

```
classes/
├── Admin_Page.php                ← Menu registration, tab navigation, asset loading
├── Url_List_Table.php            ← WP_List_Table for URLs tab (list, add, edit, trash)
└── Rest_Endpoint.php             ← search-posts endpoint for page selector
js/
└── admin.js                      ← select2 initialization, REST-driven page search
css/
└── admin.css                     ← Admin styling (tabs, page selector)
```

**What is implemented:**

- `Admin_Page.php`: Registers menu page under Tools via `add_management_page`. Requires `kntnt_ad_attr` capability. Renders tab navigation (`nav-tab-wrapper`). Enqueues admin.js, admin.css, select2 (from cdnjs) only on plugin page via page hook check. Provides `wp_localize_script` data for admin script.
- `Url_List_Table.php`: Extends `WP_List_Table`. Lists tracking URLs from CPT with columns: Hash (truncated), Tracking URL, Target URL (resolved), UTM source/medium/campaign/content/term. Row actions: Edit, Trash. Add/Edit form with: target page selector (select2-driven), UTM fields (source, medium, campaign required; content, term optional). On save: generates hash via `hash('sha256', random_bytes(32))` with do-while uniqueness loop. Creates/updates CPT post with meta. Pagination (default 20, Screen Options). Search + UTM dropdown filters.
- `Rest_Endpoint.php`: Registers `GET /kntnt-ad-attribution/v1/search-posts`. Searches published posts (all public post types except own CPT) via `WP_Query`. Returns `[{id, title, type}]`. Permission: `kntnt_ad_attr` capability.
- `admin.js`: Initializes select2 on page selector field. Connects to search-posts REST endpoint. Displays post type and ID alongside title.
- `admin.css`: Styling for tab navigation and page selector component.

**Plugin.php updated:** Instantiates Admin_Page and Rest_Endpoint.

**Verification:**

- Tools → Ad Attribution menu item visible for Administrators and Editors.
- URLs tab: Add New → select target page, fill UTM fields → Save → tracking URL created with hash.
- List view shows all tracking URLs. Edit works. Trash works.
- Created tracking URLs work with click handling from increment 2.

---

## Increment 4 — Conversion Handling

**Goal:** Form submissions trigger conversions with fractional time-weighted attribution.

**Files created:**

```
classes/
└── Conversion_Handler.php        ← 10-step conversion flow
```

**What is implemented:**

- `Conversion_Handler.php`: Listens on `kntnt_ad_attribution_conversion` action. Implements the complete 10-step flow from conversion-handling.md: reads `_ad_last_conv` → dedup check → reads `_ad_clicks` → validates hash:timestamp pairs → filters to published CPT hashes → calculates weights (`w_i = max(N - d_i, 1)`, `a_i = w_i / Σw_j`) → transaction-wrapped DB write (INSERT … ON DUPLICATE KEY UPDATE) → sets `_ad_last_conv` cookie → fires `kntnt_ad_attribution_conversion_recorded` with attribution array. Dedup period capped to cookie_lifetime.

**Plugin.php updated:** Instantiates Conversion_Handler.

**Verification:**

- Click a tracking URL (from increment 2), then call `do_action('kntnt_ad_attribution_conversion')`.
- Stats table: conversion column updated with fractional value.
- Click two different tracking URLs, then convert: both hashes receive partial attribution that sums to 1.0.
- Convert again immediately: dedup prevents double counting.

---

## Increment 5 — Client-Side Script + REST Set-Cookie

**Goal:** The pending consent flow works end-to-end. Hashes transported via cookie or fragment are picked up by the script and stored via REST when consent is given.

**Files created:**

```
js/
└── pending-consent.js            ← sessionStorage, consent interface, REST call
```

**What is modified:**

- `Rest_Endpoint.php`: Adds `POST /kntnt-ad-attribution/v1/set-cookie` endpoint. Validates hashes against regex + database. Checks consent. Reads existing `_ad_clicks` cookie, merges new hashes via Cookie_Manager, writes updated cookie. Permission: `__return_true` (nonce protection).

**What is implemented:**

- `pending-consent.js`: Wrapped in `DOMContentLoaded`. Reads `_aah_pending` cookie or `#_aah` fragment → stores hash in `kntnt_ad_attr_hashes` (sessionStorage JSON array) → clears cookie/fragment. If hashes exist in sessionStorage → calls `window.kntntAdAttributionGetConsent(callback)`. Callback handling: 'yes' → POST to REST endpoint → clear sessionStorage, 'no' → clear sessionStorage, 'unknown' → do nothing (hashes remain). Internal `handled` flag prevents double execution. Error handling: 403 → clear, network error → retry counter (max 3). Default `kntntAdAttributionGetConsent` implementation calls `callback('unknown')`.

**Plugin.php updated:** Enqueues `pending-consent.js` on public pages via `wp_enqueue_scripts`. Provides `wp_localize_script` data (restUrl + nonce).

**Verification:**

- Configure consent filter to return `null` (undefined).
- Click tracking URL → redirected, `_aah_pending` cookie set (or fragment).
- Landing page loads → pending-consent.js picks up hash → stored in sessionStorage.
- Simulate consent given → script calls REST endpoint → `_ad_clicks` cookie set.
- Check that REST merge behavior works (existing + new hashes combined).

---

## Increment 6 — Campaigns Tab + CSV Export

**Goal:** Administrators can view aggregated statistics per campaign and export to CSV.

**Files created:**

```
classes/
├── Campaign_List_Table.php       ← WP_List_Table for Campaigns tab
└── Csv_Exporter.php              ← CSV streaming with UTF-8 BOM
```

**What is implemented:**

- `Campaign_List_Table.php`: Extends `WP_List_Table`. SQL query joining stats table ↔ postmeta ↔ posts per admin-ui.md. Columns: Hash, Tracking URL, Target URL (resolved in PHP), UTM source/medium/campaign/content/term, Clicks, Conversions. Filtering: date range (HTML5 date inputs), UTM dimension dropdowns, free-text search. Summation row (separate query without GROUP BY). Pagination (default 20, Screen Options).
- `Csv_Exporter.php`: Same query without LIMIT/OFFSET. Streams as `text/csv`. UTF-8 with BOM. Delimiter: semicolon if locale uses comma as decimal separator, otherwise comma. Conversions formatted with 4 decimals using locale decimal character. Filename pattern per admin-ui.md. Nonce validation (`kntnt_ad_attr_export`).

**Admin_Page.php updated:** Renders Campaigns tab. Adds CSV export button. Routes CSV export requests to Csv_Exporter.

**Verification:**

- Click some tracking URLs, trigger some conversions.
- Campaigns tab: data visible, grouped correctly. Filters work. Totals match.
- CSV export: file downloads with correct encoding, delimiter, and data.

---

## Increment 7 — Cron, Updater, Translations, Final Polish

**Goal:** Production-ready plugin with all housekeeping, update mechanism, and translation support.

**Files created:**

```
classes/
├── Cron.php                      ← Daily cleanup job
└── Updater.php                   ← GitHub release update checker
languages/
└── kntnt-ad-attribution.pot      ← Translation template
```

**What is implemented:**

- `Cron.php`: Hooks into `kntnt_ad_attr_daily_cleanup`. Step 1: Deletes stats rows whose hash has no corresponding CPT post. Step 2: Checks if tracking URLs point to deleted pages → changes post_status to `draft`, stores admin notice.
- `Updater.php`: Hooks into `pre_set_site_transient_update_plugins`. Compares installed version against latest GitHub release tag. If newer version with `.zip` asset exists, presents update in WordPress admin.
- Warning on target page deletion: Hooks into `wp_trash_post` → checks if any tracking URLs point to the trashed page → shows admin notice.
- `kntnt-ad-attribution.pot`: Generated translation template (via `wp i18n make-pot`).
- `Plugin.php`: Loads text domain via `load_plugin_textdomain`.

**Final review and polish:**

- Verify all user-facing strings use `__()` / `esc_html__()`.
- Verify all SQL uses `$wpdb->prepare()`.
- Verify all admin URLs use `admin_url()` / `wp_nonce_url()`.
- Verify all superglobal access is sanitized.
- Verify `declare(strict_types=1)` in every PHP file.
- Verify PHPDoc/JSDoc on every code element.
- Verify inline code comments per coding-standards.md.
- Verify error handling per security.md (silent to visitor, `error_log` for diagnostics).

**Verification:**

- Manually delete a target page → admin notice warns about affected tracking URLs.
- Wait for cron (or trigger manually with `wp cron event run kntnt_ad_attr_daily_cleanup`) → orphaned stats cleaned, affected tracking URLs drafted.
- Create a GitHub release → WordPress shows update notification.
- Run `wp i18n make-pot . languages/kntnt-ad-attribution.pot` → template generated.

---

## Summary

| Increment | What you get | New classes | Cumulative |
|-----------|-------------|-------------|------------|
| 1 | Activates, creates table, clean lifecycle | Plugin, Post_Type, Migrator | 3 classes |
| 2 | Click → log → cookie → redirect | Cookie_Manager, Consent, Bot_Detector, Click_Handler | 7 classes |
| 3 | Admin UI for creating tracking URLs | Admin_Page, Url_List_Table, Rest_Endpoint | 10 classes + JS/CSS |
| 4 | Conversions with fractional attribution | Conversion_Handler | 11 classes |
| 5 | Pending consent flow (client-side) | — (JS + REST update) | 11 classes + 2 JS |
| 6 | Campaign reporting + CSV export | Campaign_List_Table, Csv_Exporter | 13 classes |
| 7 | Cron, updates, translations, polish | Cron, Updater | 15 classes (complete) |
