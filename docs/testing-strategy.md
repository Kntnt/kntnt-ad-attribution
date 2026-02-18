# Testing Strategy — Kntnt Ad Attribution

This document describes the test suite for the Kntnt Ad Attribution WordPress plugin. It serves as a reference for both human developers and Claude Code when maintaining existing tests or writing new ones.

## Table of Contents

1. [Goals and Principles](#1-goals-and-principles)
2. [Test Levels Overview](#2-test-levels-overview)
3. [Requirements](#3-requirements)
4. [Running Tests](#4-running-tests)
5. [Directory Structure](#5-directory-structure)
6. [Level 1: Unit Tests](#6-level-1-unit-tests)
7. [Level 2: Integration Tests](#7-level-2-integration-tests)
8. [Writing New Tests](#8-writing-new-tests)
9. [Known Gotchas](#9-known-gotchas)
10. [Test Inventory](#10-test-inventory)

---

## 1. Goals and Principles

**Primary goal:** High confidence that every public method, every edge case, every integration point, and every error-handling path works correctly — and that future changes do not break existing behaviour.

**Principles:**

- **Low barrier to entry.** A developer needs only Bash, Node.js/npm, PHP/Composer, and curl. No Docker, no local WordPress installation, no MySQL.
- **Single entry point.** `bash run-tests.sh` installs all dependencies (if needed) and executes all tests across both levels.
- **Reproducible.** WordPress Playground (`@wp-playground/cli`) provides an identical ephemeral WordPress environment for every developer.
- **Comprehensive.** Every public method, every filter and action, every cookie manipulation, every database query path, and every JavaScript interaction has at least one test.
- **Fast feedback.** Level 1 (unit tests) run without WordPress and finish in seconds. Level 2 (integration tests) start a disposable Playground instance and run the full click → conversion → admin flow.

---

## 2. Test Levels Overview

| Level | Name | Framework | Environment | What It Tests |
|-------|------|-----------|-------------|---------------|
| 1 | Unit Tests (PHP) | Pest + Brain Monkey + Mockery | Pure PHP, no WordPress | Individual class methods in isolation |
| 1 | Unit Tests (JS) | Vitest + happy-dom | Node.js with DOM simulation | `pending-consent.js` and `admin.js` |
| 2 | Integration Tests | Bash + curl + WordPress Playground | Full WordPress (WASM/SQLite) | End-to-end flows: click tracking, conversion attribution, REST API, admin operations, cron |

### Why two levels?

Level 1 tests are fast (< 10 seconds) and catch logic bugs in individual methods. They mock all WordPress functions via Brain Monkey, so they run on any machine with PHP and Composer.

Level 2 tests exercise the plugin inside a real WordPress instance (running via WebAssembly in Node.js). They verify that the rewrite rules work, the database tables are created, cookies are set correctly, and the admin UI returns the expected HTML. They are slower (30–120 seconds) but catch integration bugs that unit tests cannot.

---

## 3. Requirements

| Tool | Minimum Version | Check Command |
|------|----------------|---------------|
| Bash | 4.0+ | `bash --version` |
| curl | Any recent | `curl --version` |
| jq | 1.6+ | `jq --version` |
| Node.js | 20.18+ | `node --version` |
| npm | 9+ | `npm --version` |
| PHP | 8.3+ | `php --version` |
| Composer | 2.x | `composer --version` |

All other dependencies (Pest, Brain Monkey, Mockery, Vitest, happy-dom, `@wp-playground/cli`) are installed automatically by `run-tests.sh`.

---

## 4. Running Tests

### Full suite

```bash
bash run-tests.sh
```

This verifies requirements, installs dependencies, runs all unit tests, starts WordPress Playground, runs all integration tests, stops Playground, and prints a summary. Exit code 0 if all tests pass, 1 if any fail.

### Flags

```bash
bash run-tests.sh --unit-only          # Level 1 only (no Playground)
bash run-tests.sh --integration-only   # Level 2 only
bash run-tests.sh --filter consent     # Only tests matching pattern
bash run-tests.sh --verbose            # Full output from each test
```

### Running individual test levels

```bash
# PHP unit tests (requires PHP 8.3 in PATH, or use ddev)
./vendor/bin/pest
./vendor/bin/pest --filter CookieManager
ddev here ./vendor/bin/pest              # via DDEV

# JS unit tests
npx vitest run

# Single integration test (requires Playground running on port 9400)
bash tests/Integration/test-click-flow.sh
```

### Coverage

PHP coverage requires pcov (installed automatically in CI via `shivammathur/setup-php`). JS coverage uses v8 via `@vitest/coverage-v8`.

```bash
./vendor/bin/pest --coverage            # PHP (requires pcov)
npm run test:coverage                   # JS
```

### CI

GitHub Actions workflow (`.github/workflows/tests.yml`) runs all three levels on push/PR to `main`. Three parallel jobs: PHP unit tests with coverage, JS unit tests with coverage, and integration tests (runs after both unit jobs pass).

---

## 5. Directory Structure

```
kntnt-ad-attribution/
├── run-tests.sh                        # Single entry point
├── composer.json                       # PHP dev dependencies
├── package.json                        # JS dev dependencies
├── phpunit.xml                         # Pest/PHPUnit configuration
├── vitest.config.js                    # Vitest configuration
├── patchwork.json                      # Patchwork redefinable-internals config
├── .github/workflows/tests.yml         # CI workflow
│
├── tests/
│   ├── bootstrap.php                   # Autoloader + Patchwork init + final-stripping
│   ├── Pest.php                        # Pest global hooks (Brain Monkey setUp/tearDown)
│   │
│   ├── Helpers/                        # Shared PHP test helpers
│   │   ├── WpStubs.php                 # Stub WP classes (WP_Post, WP_REST_*, WP_List_Table, etc.)
│   │   └── TestFactory.php             # Factory methods for creating test doubles
│   │
│   ├── Unit/                           # Level 1: PHP unit tests (19 files)
│   │   ├── AdminPageTest.php
│   │   ├── BotDetectorTest.php
│   │   ├── CampaignListTableTest.php
│   │   ├── ClickHandlerTest.php
│   │   ├── ClickIdStoreTest.php
│   │   ├── ConsentTest.php
│   │   ├── ConversionHandlerTest.php
│   │   ├── CookieManagerTest.php
│   │   ├── CronTest.php
│   │   ├── CsvExporterTest.php
│   │   ├── MigratorTest.php
│   │   ├── PluginTest.php
│   │   ├── PostTypeTest.php
│   │   ├── QueueProcessorTest.php
│   │   ├── QueueTest.php
│   │   ├── RestEndpointTest.php
│   │   ├── UpdaterTest.php
│   │   ├── UrlListTableTest.php
│   │   └── UtmOptionsTest.php
│   │
│   ├── JS/                             # Level 1: JavaScript unit tests
│   │   ├── admin.test.js
│   │   ├── pending-consent.test.js
│   │   └── scaffold.test.js
│   │
│   └── Integration/                    # Level 2: Integration tests
│       ├── blueprint.json              # Playground blueprint
│       ├── helpers/
│       │   ├── setup.sh                # Authenticate, get REST nonce, flush rewrites
│       │   ├── teardown.sh             # Clean up cookie jar
│       │   ├── assertions.sh           # assert_status, assert_contains, assert_equals, etc.
│       │   └── fixtures.sh             # create_tracking_url, simulate_click, etc.
│       ├── fake-consent-plugin/
│       │   └── fake-consent.php        # mu-plugin: consent state simulation via WP option
│       ├── test-helpers-plugin/
│       │   └── test-helpers.php        # mu-plugin: REST endpoints for fixtures & inspection
│       ├── test-activation.sh
│       ├── test-admin-crud.sh
│       ├── test-bot-detection.sh
│       ├── test-campaign-report.sh
│       ├── test-click-flow.sh
│       ├── test-consent-states.sh
│       ├── test-conversion-attribution.sh
│       ├── test-cookie-limits.sh
│       ├── test-cron-cleanup.sh
│       ├── test-csv-export.sh
│       ├── test-deduplication.sh
│       ├── test-migration.sh
│       ├── test-query-forwarding.sh
│       └── test-rest-api.sh
```

---

## 6. Level 1: Unit Tests

### 6.1 Technology Stack

| Tool | Purpose | Version |
|------|---------|---------|
| Pest PHP | Test runner (PHPUnit wrapper with expressive syntax) | ^3.0 |
| Brain Monkey | Mock WordPress functions (`add_action`, `apply_filters`, `__()`, etc.) | ^2.6 |
| Mockery | Mock PHP objects (`$wpdb`, `WP_Post`, `WP_REST_Request`, etc.) | ^1.6 |
| Patchwork | Redefine internal PHP functions (`setcookie`, `header`, `time`, etc.) | via Brain Monkey |
| dg/bypass-finals | Strip `final` from classes at load time (via Patchwork code manipulation) | ^1.7 |
| Vitest | JavaScript test runner | ^2.0 |
| happy-dom | Lightweight DOM implementation for JS tests | ^15.0 |

### 6.2 Bootstrap and Configuration

**`tests/bootstrap.php`** loads the Composer autoloader, initializes Patchwork early (before any plugin classes are autoloaded), and registers a custom code manipulation that strips `final` from plugin classes. This allows Mockery to mock final classes.

**`tests/Pest.php`** configures global `beforeEach`/`afterEach` hooks that call `Brain\Monkey\setUp()` and `tearDown()`. It also stubs common WordPress functions (translation, escape, sanitize) and defines constants (`DAY_IN_SECONDS`, `MINUTE_IN_SECONDS`).

**`tests/Helpers/WpStubs.php`** defines stub classes for WordPress internals needed at include time: `WP_Post`, `WP_REST_Request`, `WP_REST_Response`, `WP_REST_Server`, `WP_Query`, `WP_List_Table`, and the `$wpdb` global.

**`tests/Helpers/TestFactory.php`** provides factory methods for creating test doubles (mock `$wpdb`, etc.).

**`patchwork.json`** lists PHP internal functions that Patchwork is allowed to redefine: `error_log`, `setcookie`, `header`, `time`, `gmdate`, `exit`.

### 6.3 PHP Unit Test Coverage (by class)

Each test file follows the naming convention `tests/Unit/{ClassName}Test.php` and uses Pest's `describe`/`it` syntax. Below is the coverage summary for each class.

---

#### Cookie_Manager (`CookieManagerTest.php`)

Stateless data manipulation class. Tests cover:

- **`parse()`** — valid single/multiple entries, empty/missing cookie, corrupt format (logs error), partial match, uppercase hex rejection.
- **`add()`** — add to empty array, explicit timestamp, update existing hash, eviction at MAX_HASHES (50), correct oldest evicted.
- **`set_clicks_cookie()`** — cookie name `_ad_clicks`, serialization format, attributes (path, httponly, secure, samesite), lifetime filter.
- **`set_transport_cookie()`** — cookie name `_aah_pending`, not HttpOnly, 60-second lifetime, value equals hash.
- **`validate_hash()`** — valid 64-char lowercase hex, wrong lengths, uppercase, non-hex characters, empty string.

---

#### Consent (`ConsentTest.php`)

- **`check()`** — filter returns true/false/null, no `has_consent` callbacks (falls through to `default_consent`), default consent override.

---

#### Bot_Detector (`BotDetectorTest.php`)

- **`detect()`** — known bot UAs (Googlebot, facebookexternalhit, python-requests, HeadlessChrome, curl), normal UA, empty/missing UA, case insensitivity, previous filter short-circuit.
- **`is_bot()`** — applies `kntnt_ad_attr_is_bot` filter, filter overrides detection.
- **`add_disallow_rule()`** — public/private site, custom prefix.

---

#### Click_ID_Store (`ClickIdStoreTest.php`)

- **`store()`** — correct INSERT...ON DUPLICATE KEY UPDATE SQL, GMT timestamp.
- **`get_for_hashes()`** — empty input returns empty, groups results by `hash => [platform => click_id]`.
- **`cleanup()`** — DELETE with correct cutoff date.

---

#### Queue (`QueueTest.php`)

- **`enqueue()`** — inserts with status 'pending' and attempts 0, JSON-encoded payload.
- **`dequeue()`** — empty result, atomic status update to 'processing', JSON-decoded payload, respects limit.
- **`complete()`** — sets status 'done' with timestamp.
- **`fail()`** — increments attempts, retries when < MAX_ATTEMPTS, marks 'failed' at max, stores error message.
- **`cleanup()`** — deletes old done/failed jobs.
- **`get_status()`** — counts per status, last error message.

---

#### Queue_Processor (`QueueProcessorTest.php`)

- **`process()`** — no reporters returns early, dispatches to correct reporter, unknown reporter fails job, exception handling, completion/failure, re-scheduling.
- **`schedule()`** — calls `wp_schedule_single_event`, skips if already scheduled.

---

#### Click_Handler (`ClickHandlerTest.php`)

The most complex class. Tests cover the full click flow:

- 404 for invalid hash format, missing CPT post, deleted target.
- Bot detection short-circuit (redirect without DB insert).
- Non-bot click recording to clicks table.
- Per-click UTM field extraction and MTM fallback.
- UTM field truncation at 255 chars.
- Consent states: true (sets cookie), false (skips cookie), null (transport cookie or fragment).
- Transport filter ('cookie' vs 'fragment').
- Redirect method filter ('302' vs 'js').
- Query parameter forwarding and collision handling.
- Redirect loop guard.
- `kntnt_ad_attr_click` action fires for all non-bot clicks, before consent check.
- Click ID capturer integration, value length validation.
- Postmeta backfill from UTM/MTM params, existing values not overwritten.

---

#### Conversion_Handler (`ConversionHandlerTest.php`)

- Registration of `kntnt_ad_attr_conversion` action.
- Deduplication: recent conversion skipped, old conversion allowed, dedup window capped to cookie lifetime.
- Empty/invalid cookies return early.
- Default last-click attribution (latest gets 1.0, older gets 0.0).
- Attribution filter applied.
- Database transaction with INSERT per attributed hash, rollback on error.
- `_ad_last_conv` cookie set after success.
- `kntnt_ad_attr_conversion_recorded` action fires with attributions and context.
- Reporter enqueueing (including payload structure), no-op when no reporters.

---

#### Post_Type (`PostTypeTest.php`)

- CPT registration with correct slug and args.
- `wp_untrash_post_status` filter at priority 20.
- `untrash_status()` returns 'publish' for own CPT, unchanged for others.
- `get_valid_hashes()` returns only published hashes, empty input returns empty.
- `get_distinct_meta_values()` returns sorted unique values, filters to published posts.

---

#### Migrator (`MigratorTest.php`)

- No-op when stored version >= current.
- Executes pending migrations in version order.
- Updates version option after successful run.
- Skips migrations before stored version.
- Missing migrations directory handled gracefully.

---

#### Rest_Endpoint (`RestEndpointTest.php`)

- **`set_cookie()`** — rate limiting (429 on 11th request), counter incremented, invalid hashes filtered, unknown hashes filtered, consent check, cookie set on success.
- **`search_posts()`** — permission check, exact ID lookup, URL resolution, slug search, title search, results limited to 20, own CPT excluded.

---

#### Cron (`CronTest.php`)

- **`run_daily_cleanup()`** — click retention from filter, orphaned conversions, orphaned clicks, draft tracking URLs with missing/unpublished target, orphaned URL transient, Click_ID_Store and Queue cleanup.
- **`warn_on_target_trash()`** — ignores own CPT, finds affected URLs, no-op when none.
- **`display_admin_notices()`** — shows orphaned/trashed target notices, respects capability, deletes transients after display.

---

#### Admin_Page (`AdminPageTest.php`)

- **`save_url()`** — unique hash generation, collision retry, correct CPT post creation, required meta fields, field validation, target post validation.
- **`permanently_delete_url()`** — deletes conversions, clicks, and post.
- **Bulk actions** — trash, restore, delete, skips non-CPT posts.
- **Nonce verification** — invalid/missing nonce rejected.
- **Tab extensibility** — `kntnt_ad_attr_admin_tabs` filter, custom tab dispatch via action.
- **SRI attributes** — integrity/crossorigin on Select2, other handles unchanged.

---

#### Csv_Exporter (`CsvExporterTest.php`)

- Content-Type header, UTF-8 BOM, semicolon vs comma delimiter (based on locale), column count, filename with dates, "(deleted)" for missing targets.

---

#### Utm_Options (`UtmOptionsTest.php`)

- Default structure (sources + mediums keys), google → cpc mapping, all expected defaults, `kntnt_ad_attr_utm_options` filter applied.

---

#### Updater (`UpdaterTest.php`)

- Returns unchanged transient when no PluginURI, when version is current, when GitHub API fails.
- Adds update info for newer version with zip asset.
- Skips when no zip asset found.

---

#### Plugin (`PluginTest.php`)

- `get_url_prefix()` defaults to 'ad', caches result (filter fires only once).
- `get_plugin_file()` throws if not set.
- `authorize()` calls `wp_die()` when user lacks capability.
- `deactivate()` clears cron hooks and flushes rewrite rules.

---

#### Url_List_Table (`UrlListTableTest.php`)

- `get_columns()` returns expected keys.
- `get_sortable_columns()` returns expected keys.
- `get_bulk_actions()` differs by view (All vs Trash).
- SQL construction: UTM filter adds WHERE, search adds LIKE, trash status, orderby whitelist, order default.

---

#### Campaign_List_Table (`CampaignListTableTest.php`)

- `get_filter_params()` sanitises dates, validates date regex.
- SQL includes GROUP BY for aggregation, per-click fields.
- `get_totals()` caches result.

---

### 6.4 JavaScript Unit Tests

#### `pending-consent.test.js`

The script is an IIFE. Tests use `new Function(readFileSync(...))` to evaluate the script in a controlled happy-dom environment.

- **Hash discovery** — reads `_aah_pending` cookie, reads `#_aah=<hash>` fragment, ignores invalid format, clears cookie/fragment after reading, merges with existing sessionStorage.
- **Consent callback** — calls `window.kntntAdAttributionGetConsent`, 'yes' triggers fetch, 'no' clears storage, 'unknown' does nothing, only first invocation processed.
- **Retry logic** — network error increments counter, 3 failures clears storage, success resets retries, HTTP 403 clears immediately.
- **Default consent function** — calls `callback('unknown')`.
- **Deduplication** — duplicate hashes deduplicated in sessionStorage.

#### `admin.test.js`

- **Clipboard** — click copies data-clipboard-text, Enter key triggers copy, other keys don't, 'copied' class added/removed after 1.5s, empty value skipped.
- **Select2** — returns early if jQuery or kntntAdAttrAdmin undefined, UTM source change auto-fills medium, existing medium not overwritten.

---

## 7. Level 2: Integration Tests

### 7.1 Technology Stack

| Tool | Purpose |
|------|---------|
| `@wp-playground/cli` ^0.9 | Ephemeral WordPress instance (WASM PHP + SQLite) |
| Bash | Test orchestration and assertions |
| curl | HTTP requests to the WordPress instance |

### 7.2 Architecture

Integration tests run against a WordPress Playground instance on port 9400. The Playground uses SQLite (not MySQL), PHP 8.3, and runs as a WASM process in Node.js.

Two mu-plugins provide test infrastructure:

- **`fake-consent-plugin/fake-consent.php`** — reads `test_consent_state` WP option and returns the matching consent value via `kntnt_ad_attr_has_consent` filter. States: `granted` (true), `denied` (false), `pending` (null), `default` (null).

- **`test-helpers-plugin/test-helpers.php`** — REST endpoints for test setup and inspection:

  | Endpoint | Method | Purpose |
  |----------|--------|---------|
  | `/test-create-url` | POST | Create tracking URL CPT with hash and meta |
  | `/test-create-post` | POST | Create a regular post/page (target for tracking URLs) |
  | `/test-set-option` | POST | Set a WP option (consent state, etc.) |
  | `/test-get-option` | GET | Read a WP option |
  | `/test-query` | GET | Run SELECT query, return single row |
  | `/test-query-rows` | GET | Run SELECT query, return multiple rows |
  | `/test-execute-sql` | POST | Run INSERT/UPDATE/DELETE SQL |
  | `/test-do-action` | POST | Fire a WordPress action |
  | `/test-flush-rewrites` | POST | Flush rewrite rules |
  | `/test-trigger-conversion` | POST | Inject cookies and fire `kntnt_ad_attr_conversion` |
  | `/test-clear-cookies` | POST | Clear plugin cookies and filter GET/POST params |
  | `/test-update-post-status` | POST | Change a post's status |
  | `/test-delete-post` | POST | Permanently delete a post |
  | `/test-delete-transient` | POST | Delete a transient |
  | `/test-nonce` | GET | Get REST nonce (public, bootstraps auth) |

### 7.3 Bootstrap Flow

`run-tests.sh` sources `tests/Integration/helpers/setup.sh` after starting Playground. Setup:

1. Logs in as admin via `wp-login.php` (Playground creates admin/password automatically).
2. Gets a REST nonce via the `/test-nonce` endpoint.
3. Flushes rewrite rules (required after cold start).
4. Exports `WP_BASE_URL`, `WP_NONCE`, and `ADMIN_COOKIE` for all test scripts.

### 7.4 Helpers

**`helpers/assertions.sh`** provides: `assert_status`, `assert_contains`, `assert_not_contains`, `assert_equals`, `assert_greater_than`, `assert_json_field`, `print_summary`. Each assertion increments `TESTS_RUN` and optionally `TESTS_FAILED`.

**`helpers/fixtures.sh`** provides: `create_tracking_url`, `create_target_page`, `simulate_click`, `get_click_cookies`, `extract_cookie_value`, `set_consent_state`, `get_click_count`, `query_db`, `query_db_rows`, `execute_sql`, `flush_rewrites`, `trigger_conversion`, `clear_cookies`, `strip_secure_flag`.

### 7.5 Integration Test Coverage

Each test script sources `helpers/assertions.sh` and `helpers/fixtures.sh`, creates its own fixtures, runs assertions, and calls `print_summary`.

---

#### `test-activation.sh` — Plugin Activation and Database Schema

- Tables exist (clicks, conversions, click_ids, queue).
- Table schemas correct (column names and types).
- Capabilities granted to admin and editor.
- CPT registered in REST API.
- Rewrite rules match tracking URL pattern.
- Version option matches plugin header.

---

#### `test-click-flow.sh` — Basic Click Tracking

- Valid hash returns 302 redirect to target URL.
- Invalid hash returns 404.
- Unknown hash (valid format, not in DB) returns 404.
- Click recorded in database.
- Per-click UTM fields stored (utm_content, utm_term).
- MTM fallback works (mtm_content → utm_content).
- Click count increments on repeated clicks.

---

#### `test-bot-detection.sh` — Bot Filtering

- Googlebot UA: redirects but no click recorded.
- curl UA: no click recorded.
- Empty UA: no click recorded.
- Normal Chrome UA: click recorded.
- HeadlessChrome UA: no click recorded.

---

#### `test-consent-states.sh` — Consent-Dependent Cookie Setting

- Consent granted: `_ad_clicks` cookie set with HttpOnly, Secure, SameSite=Lax.
- Consent denied: no `_ad_clicks` cookie.
- Consent pending: `_aah_pending` transport cookie set with 60-second max-age, not HttpOnly.
- Click recorded regardless of consent state.

---

#### `test-conversion-attribution.sh` — Conversion Flow

- Single click, single conversion: full attribution (1.0).
- Two clicks: latest gets 1.0, older has no conversion record (0.0 attribution skipped).
- No `_ad_clicks` cookie: conversion is no-op.
- Conversion timestamp is recent.

---

#### `test-deduplication.sh` — Conversion Deduplication

- Second conversion within dedup window is skipped (count unchanged).
- Conversion after dedup window succeeds (simulated via `_ad_last_conv` cookie manipulation).

---

#### `test-rest-api.sh` — REST Endpoints

- `/set-cookie`: valid hashes return success, invalid hashes filtered, all-invalid returns failure, rate limiting (429 on 11th request), consent denied returns failure.
- `/search-posts`: returns posts matching title, returns post by ID, requires capability (403 without auth), limits results to 20, excludes tracking URL CPT.

---

#### `test-admin-crud.sh` — Admin Page CRUD Operations

- Create tracking URL via form POST (hash is 64-char hex, meta stored correctly).
- Source/medium/campaign required (missing field → error).
- List view shows created URL.
- Trash, restore, and permanently delete (cascades to clicks/conversions).
- Bulk trash.
- Invalid nonce rejected.
- Default tabs (URLs, Campaigns) rendered.

---

#### `test-campaign-report.sh` — Campaign Aggregation

- Aggregates clicks per source/campaign.
- Shows google and facebook sources.
- UTM filter shows only matching rows.

---

#### `test-csv-export.sh` — CSV Export

- Content-Type is `text/csv`.
- Content-Disposition has `.csv` filename containing `ad-attribution`.
- UTF-8 BOM present (first 3 bytes: EF BB BF).
- Header row contains Source, Medium, Campaign.
- Body contains tracking URL data.

---

#### `test-cron-cleanup.sh` — Cron Cleanup Operations

- Old clicks deleted (> retention window).
- Recent clicks preserved.
- Orphaned conversions (missing parent click) deleted.
- Tracking URL drafted when target post deleted.

---

#### `test-query-forwarding.sh` — Query Parameter Merging

- Incoming params (`gclid=abc`) forwarded to redirect URL.
- Target URL params preserved alongside incoming params.
- Target params win on collision.

---

#### `test-cookie-limits.sh` — Cookie Capacity

- 50 hashes can be stored in a single cookie.
- 51st click evicts the oldest hash.
- Cookie format validates after 50 entries.

---

#### `test-migration.sh` — Database Migration

- Version option matches plugin header version.
- All four tables have correct schema (columns and types).

---

## 8. Writing New Tests

### When to write tests

When adding a new feature or fixing a bug:

1. **Write Level 1 unit tests first.** They run in seconds and catch logic bugs early.
2. **If the change affects behaviour visible via HTTP or database,** add a Level 2 integration test.
3. **Run `bash run-tests.sh --unit-only`** during development for fast feedback.
4. **Run `bash run-tests.sh`** before committing to verify integration.

### Adding a PHP unit test

1. **Create or edit** `tests/Unit/{ClassName}Test.php`.
2. **Use `describe`/`it` blocks** grouped by method name.
3. **Mock WordPress functions** via Brain Monkey (`Functions\expect`, `Functions\when`).
4. **Mock objects** via Mockery (`Mockery::mock(\wpdb::class)`).
5. **Use `expect()`** for assertions.
6. **Clean up** `$_COOKIE`, `$_GET`, `$_POST`, `$_SERVER` in tests that modify them.

Example:

```php
<?php

declare(strict_types=1);

use Kntnt\Ad_Attribution\Cookie_Manager;
use Brain\Monkey\Functions;

describe('Cookie_Manager::parse()', function () {

    it('returns hash => timestamp map for valid cookie', function () {
        $hash = str_repeat('a', 64);
        $_COOKIE['_ad_clicks'] = "{$hash}:1700000000";

        $manager = new Cookie_Manager();
        $result = $manager->parse();

        expect($result)->toBe([$hash => 1700000000]);
    });

    it('returns empty array and logs error for corrupt cookie', function () {
        $_COOKIE['_ad_clicks'] = 'invalid-data';

        Functions\expect('error_log')->once();

        $manager = new Cookie_Manager();
        expect($manager->parse())->toBe([]);
    });

});
```

### Adding a JavaScript unit test

The JS source files are IIFEs. Tests evaluate them via `new Function(readFileSync(...))`.

1. **Edit** `tests/JS/{filename}.test.js`.
2. **Set up DOM/globals** before calling `loadScript()`.
3. **Mock `fetch`** via `vi.fn()` for network tests.
4. **Use fake timers** (`vi.useFakeTimers()`) for timeout-dependent tests.

### Adding an integration test

1. **Create** `tests/Integration/test-{feature}.sh`.
2. **Follow the template:**

```bash
#!/usr/bin/env bash
# Integration test: {Description}.

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/helpers/assertions.sh"
source "$SCRIPT_DIR/helpers/fixtures.sh"

echo "=== Test: {Name} ==="

# --- Setup ---

clear_cookies

TARGET_JSON=$(create_target_page "Test Landing")
TARGET_ID=$(echo "$TARGET_JSON" | jq -r '.post_id')

HASH=$(create_tracking_url "$TARGET_ID" "google" "cpc" "test-campaign")
flush_rewrites

# --- Tests ---

result=$(simulate_click "$HASH")
status=$(echo "$result" | cut -d'|' -f1)
assert_status "302" "$status" "Valid hash returns 302"

# --- Cleanup ---

print_summary
```

3. **Always call `clear_cookies`** at the start of tests that depend on clean cookie state.
4. **Always call `flush_rewrites`** after creating tracking URLs.
5. **Use fixture helpers** (see `helpers/fixtures.sh`) rather than raw curl.
6. **End with `print_summary`** — its exit code drives the pass/fail.
7. **If you need a new REST endpoint**, add it to `test-helpers-plugin/test-helpers.php`.
8. **The test is auto-discovered** by `run-tests.sh` (it globs `tests/Integration/test-*.sh`).

### Adding a new test helper REST endpoint

Edit `tests/Integration/test-helpers-plugin/test-helpers.php`:

```php
register_rest_route('kntnt-ad-attribution/v1', '/test-{name}', [
    'methods'             => 'POST',
    'callback'            => function (WP_REST_Request $request) {
        // ... implementation ...
        return new WP_REST_Response(['success' => true], 200);
    },
    'permission_callback' => fn () => current_user_can('manage_options'),
]);
```

Then add a corresponding helper function in `helpers/fixtures.sh` if the endpoint will be used from multiple tests.

### Adding a fixture helper

Edit `tests/Integration/helpers/fixtures.sh`:

```bash
# Description of what this helper does.
# Usage: helper_name <arg1> <arg2>
# Returns: description of output
helper_name() {
    local arg1="$1" arg2="$2"
    curl -sf "${WP_BASE_URL}/?rest_route=/kntnt-ad-attribution/v1/test-{endpoint}" \
        -X POST \
        -H "Content-Type: application/json" \
        -H "X-WP-Nonce: ${WP_NONCE}" \
        -b "${ADMIN_COOKIE}" \
        -d "{\"key\":\"$arg1\"}" | jq -r '.result'
}
```

---

## 9. Known Gotchas

### WASM PHP superglobal persistence

**Root cause:** WordPress Playground reuses the PHP WASM process between HTTP requests. Manual modifications to `$_COOKIE`, `$_GET`, and `$_POST` persist across requests — the superglobals are not reset.

**Impact:** A `$_COOKIE['_ad_last_conv']` set during one test's conversion handler persists into the next test, causing unexpected dedup checks. Similarly, `$_GET` params from admin page requests leak into CSV export queries.

**Mitigation:** Always call `clear_cookies` at the start of integration tests that depend on clean cookie/GET/POST state. The `test-trigger-conversion` endpoint explicitly unsets plugin cookies before injecting new values.

### SQLite date comparison limits

The WordPress SQLite compatibility plugin cannot reliably handle date comparisons beyond ~2027. For example, `clicked_at < '9999-12-31 23:59:59'` returns FALSE even for 2026 dates. Use realistic date ranges (e.g., `2020-01-01` to `2027-12-31`) in test assertions.

### Cookie Secure flag over HTTP

The plugin hardcodes `secure: true` on cookies. curl won't send Secure cookies over plain HTTP (which Playground uses). The `strip_secure_flag` fixture helper patches cookie jar files by replacing `TRUE` with `FALSE` in the secure column. It uses `perl -i -pe` for portability across GNU/BSD sed.

### URL-encoded cookie values

PHP's `setcookie()` URL-encodes cookie values. When extracting cookie values from `Set-Cookie` headers, use the `extract_cookie_value` fixture helper which handles URL decoding via python3.

### `grep` exit code with `pipefail`

In bash with `set -euo pipefail`, a `grep` that finds no matches in a pipe returns exit 1, killing the script. Always append `|| true` when `grep` might match nothing:

```bash
# Wrong — fails when no cookies are set:
curl ... | grep -i '^set-cookie:'

# Correct:
curl ... | grep -i '^set-cookie:' || true
```

---

## 10. Test Inventory

| Class/File | Unit Tests | Integration Coverage |
|------------|-----------|----------------------|
| Cookie_Manager | ~15 | consent-states, cookie-limits |
| Consent | ~5 | consent-states |
| Bot_Detector | ~10 | bot-detection |
| Click_Handler | ~25 | click-flow, query-forwarding |
| Conversion_Handler | ~18 | conversion-attribution, deduplication |
| Post_Type | ~8 | activation, admin-crud |
| Click_ID_Store | ~5 | click-flow |
| Queue | ~10 | cron-cleanup |
| Queue_Processor | ~8 | conversion-attribution |
| Cron | ~12 | cron-cleanup |
| Migrator | ~5 | migration |
| Rest_Endpoint | ~12 | rest-api |
| Admin_Page | ~15 | admin-crud |
| Csv_Exporter | ~7 | csv-export |
| Url_List_Table | ~5 | admin-crud |
| Campaign_List_Table | ~6 | campaign-report |
| Utm_Options | ~3 | — |
| Updater | ~4 | — |
| Plugin | ~6 | activation |
| pending-consent.js | ~16 | — |
| admin.js | ~10 | — |
| **Totals** | **~219 PHP + 28 JS** | **14 suites** |
