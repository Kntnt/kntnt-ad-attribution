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

- **Low barrier to entry.** With DDEV, a developer only needs DDEV itself plus Node.js/npm. Without DDEV: Bash, Node.js/npm, PHP 8.3+/Composer, and curl. No local WordPress installation, no MySQL.
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

### Environment detection

`run-tests.sh` resolves tool paths in three steps (highest priority first):

1. **Explicit overrides:** Set `PHP_BIN`, `COMPOSER_BIN`, `NODE_BIN`, `NPM_BIN` as environment variables, or define them in `.env.testing` (see `.env.testing.example`). Environment variables take precedence over the file.

2. **DDEV (recommended):** If the project has a DDEV configuration (`.ddev/config.yaml` in any parent directory), `run-tests.sh` detects it automatically and uses `ddev php` and `ddev composer` for PHP tests. Node.js/npm always run on the host since `node_modules` contains host-native binaries.

3. **Local PATH fallback:** If no DDEV project is found, the script resolves tools from PATH.

WordPress Playground (integration tests) always runs on the host via the local `npx` in PATH, even when DDEV is active.

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

### Custom tool paths

```bash
# Explicit PHP override via environment variable
PHP_BIN=/opt/php83/bin/php bash run-tests.sh

# Via .env.testing file
cp .env.testing.example .env.testing
# Edit .env.testing, then:
bash run-tests.sh
```

### Running individual test levels

```bash
# PHP unit tests (requires PHP 8.3 in PATH, or use ddev)
./vendor/bin/pest
./vendor/bin/pest --filter CookieManager
ddev php vendor/bin/pest                  # via DDEV

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
├── run-tests.sh                        # Single entry point (env detection + test runner)
├── .env.testing.example                # Override template for tool paths
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
│   ├── Unit/                           # Level 1: PHP unit tests (21 files)
│   │   ├── AdminPageTest.php
│   │   ├── BotDetectorTest.php
│   │   ├── CampaignListTableTest.php
│   │   ├── ClickHandlerTest.php
│   │   ├── ClickIdStoreTest.php
│   │   ├── ConsentTest.php
│   │   ├── ConversionHandlerTest.php
│   │   ├── ConversionListTableTest.php
│   │   ├── CookieManagerTest.php
│   │   ├── CronTest.php
│   │   ├── CsvExporterTest.php
│   │   ├── InternalDetectorTest.php
│   │   ├── MigratorTest.php
│   │   ├── PluginTest.php
│   │   ├── PostTypeTest.php
│   │   ├── QueueProcessorTest.php
│   │   ├── QueueTest.php
│   │   ├── RestEndpointTest.php
│   │   ├── SpeculationDetectorTest.php
│   │   ├── UpdaterTest.php
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
│       ├── test-click-ids.sh
│       ├── test-consent-states.sh
│       ├── test-conversion-attribution.sh
│       ├── test-cookie-limits.sh
│       ├── test-counting-rules.sh
│       ├── test-cron-cleanup.sh
│       ├── test-csv-export.sh
│       ├── test-deduplication.sh
│       ├── test-deferred-consent.sh
│       ├── test-internal-traffic.sh
│       ├── test-migration.sh
│       ├── test-query-forwarding.sh
│       ├── test-recorded-conversions.sh
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
- **`set_transport_cookie()`** — cookie name `_aah_pending`, not HttpOnly, 60-second lifetime, value is the `hash:timestamp` pair.
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

#### Speculation_Detector (`SpeculationDetectorTest.php`)

- **`detect()`** — `Sec-Purpose: prefetch` and `prefetch;prerender`, `Purpose: prefetch`, another `Purpose` value, no purpose headers, previous filter short-circuit.
- **`is_speculative()`** — applies `kntnt_ad_attr_is_speculative` starting from `false`, filter overrides detection.
- **`register()`** — hooks `detect()` into the filter.

---

#### Internal_Detector (`InternalDetectorTest.php`)

- **`detect()`** — the current user with `kntnt_ad_attr` (no cookie validation needed); a valid logged-in cookie of a user with the capability when WordPress has reset the current user to 0 (`wp_validate_auth_cookie( '', 'logged_in' )` + `user_can()`); no valid cookie; a logged-in user without the capability; previous filter short-circuit.
- **`is_internal()`** — `false` by default, returns what `kntnt_ad_attr_is_internal` decides.
- **`register()`** — hooks `detect()` into the filter.

---

#### Click_ID_Store (`ClickIdStoreTest.php`)

- **`store()`** — inserts one row keyed by click row ID and platform.
- **`get_for_clicks()`** — empty input returns empty without a query; looks up the given click rows and keys their IDs by hash, with `captured_at` from the click's `clicked_at`.
- **`find_original_click()`** — earliest click row on the hash carrying the value; null when never recorded.
- **`delete_orphans()`** — deletes click IDs whose click row is gone (NOT EXISTS, not a multi-table DELETE) and returns the count.

---

#### Queue (`QueueTest.php`)

- **`enqueue()`** — inserts with status 'pending' and attempts 0, JSON-encoded payload; stores the conversion reference (`%s`), NULL when none is given.
- **`delete_unfinished_for_conversions()`** — deletes only `pending` and `failed` jobs of the given references, then counts their `processing` jobs; no query for an empty list.
- **`count_jobs_for_conversions()`** — jobs per status for each reference (`GROUP BY conversion_ref, status`); no query for an empty list.
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
- `kntnt_ad_attr_click` action fires for every recorded click, before consent check.
- Click ID capturer integration: stored on the inserted click row's ID, value length validation.
- Failed click insert: no click ID, cookie entry, transport cookie, fragment or `kntnt_ad_attr_click`; one CORE ERROR line with outcome `failed`; redirect as usual.
- Speculative requests (`Sec-Purpose: prefetch`, `prefetch;prerender`, `Purpose: prefetch`): 503 via `status_header` + `nocache_headers`, no redirect, insert, click ID, cookie, transport or action; a `kntnt_ad_attr_is_speculative` callback can overrule detection. The handler gets a real `Speculation_Detector`, and `apply_default_speculation_detection()` makes the filter run its default callback, which Brain Monkey would otherwise skip.
- Repeats (click ID already recorded on the tracking URL, via a mocked `Click_ID_Store::find_original_click()`): no insert, click ID or action, redirect with query forwarding; with consent the entry points at the original click unless a newer entry has a click row; with undetermined consent the transport carries the original click; the earliest original wins across platforms; the same value on another tracking URL is recorded.
- Diagnostic log line (the handler gets a `Logger` spy; `capture_log_lines()` collects every level): exactly one CORE INFO line per outcome (`recorded`, `repeat`, `deduplicated`, `speculative`, `bot`) with the hash prefix and the raw `Sec-Purpose`, `Purpose` and `User-Agent` values, and without the `REMOTE_ADDR`.
- Internal traffic and test clicks (`setup_click_request()` stubs a request with given query parameters and captures the redirect URL): an internal request redirects with query forwarding and records nothing (no insert, click ID, consent check, cookie, transport or action); a test click (`kntnt_ad_attr_test=1`) by an external or internal visitor is inserted with `is_test` 1 and the parameter is not forwarded; an ordinary click gets `is_test` 0; a test click is neither deduplicated nor resolved as a repeat of an earlier ordinary click; the request log line has outcome `internal` for an internal request and `test=1`/`test=0` on every line.
- Postmeta backfill from UTM/MTM params, existing values not overwritten.
- Click dedup: a duplicate (entry within the window with a matching click row) re-sends the original timestamp without inserting; an entry with no matching row is recorded as a new click.

---

#### Conversion_Handler (`ConversionHandlerTest.php`)

- Registration of `kntnt_ad_attr_conversion` action.
- Deduplication: recent conversion skipped, old conversion allowed, dedup window capped to cookie lifetime.
- Empty/invalid cookies return early.
- Default last-click attribution (latest gets 1.0, older gets 0.0).
- Attribution filter applied.
- Database transaction with INSERT per attributed hash, rollback on error.
- A cookie entry with no exactly matching click row writes no conversion row and one `Logger` warning, with no fallback lookup.
- `_ad_last_conv` cookie set after success.
- `kntnt_ad_attr_conversion_recorded` action fires with attributions and context.
- Only recorded, positive-weight attributions reach the action and reporters, with click IDs and campaigns for those hashes only; nothing fires or is enqueued when no conversion row was written.
- Reporter enqueueing (including payload structure), no-op when no reporters.
- Conversion reference: a file-level `beforeEach` stubs `wp_generate_uuid4()` with a new value per call, so the tests can tell one reference per conversion from one per row. Every row of a multi-touch conversion gets the same reference, and it reaches `Queue::enqueue()` for structured and legacy items.
- `wpdb_without_test_clicks()` answers the post-write test-click lookup (`get_col`) with "none", so tests of ordinary conversions need not stub it.
- Internal traffic and test conversions, with `make_test_mode_conversion()` (an in-memory fake of the clicks table with `is_test`, recording conversion rows, the recorded action and each reporter's `enqueue`): an internal conversion without test clicks writes, announces and reports nothing (no transaction); an internal conversion credits only the test click even when an ordinary click is more recent; a conversion after a test click is written and announced with `context['test']` true, an ordinary one with `false`; a multi-touch conversion with any test click is a test; a test conversion reaches only reporters with `'supports_test' => true`, with `context['test']` true, while an ordinary one reaches every reporter with `false`; test and ignored internal conversions are logged, ordinary ones are not.
- Per-click reporting, with a real `Click_ID_Store` over an in-memory fake of the clicks, click-ID and postmeta tables: reporters get the click ID of the attributed click (not a later click on the same hash) with that click's `clicked_at`, no ID when the attributed click carried none, and the attributed click's per-click campaign fields.

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

- **`set_cookie()`** — rate limiting (429 on 11th request), counter incremented, malformed items (including bare hashes) ignored, unknown hashes filtered, consent check. The click rows are given by a fake `$wpdb` (`fake_clicks_table()`): a pair is written with its own timestamp even when a later click on the same hash exists; a pair whose timestamp matches no click row writes nothing; only matching pairs are written; the latest matching pair wins per hash; matched pairs merge with existing entries.
- **`search_posts()`** — permission check, exact ID lookup, URL resolution, slug search, title search, results limited to 20, own CPT excluded.

---

#### Cron (`CronTest.php`)

- **`run_daily_cleanup()`** — click retention from filter, orphaned conversions, orphaned clicks, draft tracking URLs with missing/unpublished target, orphaned URL transient, click IDs of deleted clicks swept after both click deletions (`Click_ID_Store::delete_orphans()`, no age-based cleanup), Queue cleanup.
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
- **Conversion actions** — `run_conversion_action()` drives `add_screen_options()` with a request in `$_GET`, a fake `$wpdb` and `wp_safe_redirect`/`wp_die` throwing, and records nonces checked, statements run, conversions passed to the queue and the redirect. Delete runs one `DELETE … conversion_ref IN (…)` on conversions only and cleans up the jobs; Mark/Unmark run one `UPDATE clicks SET is_test = 1/0 WHERE id IN (SELECT click_id …)`, and only Mark cleans up jobs; a bulk action checks `bulk-conversions` and drops malformed and duplicate references; every row and bulk action is refused, with nothing written, without a valid nonce or without `kntnt_ad_attr`.
- **Conversions section** — rendered on the main view; the notice reports conversions and deleted jobs, with a warning for processing jobs left alone.

---

#### Conversion_List_Table (`ConversionListTableTest.php`)

- `make_conversion_list()` fakes `$wpdb`: the page query (with `GROUP BY`) returns the given page, the row query returns the rows of the requested references, and every prepared statement is recorded.
- **`prepare_items()`** — one item per conversion, newest first, with its rows, test flag (any test click) and job counts; the page query groups by `cv.conversion_ref`, orders by `converted_at DESC` and selects on `c.clicked_at BETWEEN` the range; pages with `conversions_paged`, not `paged`; no row or job query for an empty page.
- **Rendering** (protected column methods through reflection, as WordPress calls them) — `conversion[]` checkbox and `conversion_*` bulk slugs; row actions under the primary column only, Mark as test or Unmark (for a test conversion) and Delete, each with an `{action}_{ref}` nonce and the date range; one line per attributed click; *(deleted)* for a missing tracking URL; job counts per status or a dash; page links with `conversions_paged` that drop notice arguments.

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

#### Campaign_List_Table (`CampaignListTableTest.php`)

- `get_filter_params()` sanitises dates, validates date regex.
- SQL includes GROUP BY for aggregation, per-click fields.
- `get_totals()` caches result.
- The main view, the totals and the CSV query exclude test clicks with `c.is_test = 0` in the clicks JOIN condition (not in WHERE), so their conversions drop out too.

---

### 6.4 JavaScript Unit Tests

#### `pending-consent.test.js`

The script is an IIFE. Tests use `new Function(readFileSync(...))` to evaluate the script in a controlled happy-dom environment.

- **Click discovery** — reads the `hash:timestamp` pair from the URL-encoded `_aah_pending` cookie and from the `#_aah=<hash>:<timestamp>` fragment, clears cookie/fragment after reading, merges with existing sessionStorage.
- **Malformed values** — bare hashes, bad or over-long timestamps, uppercase or short hashes are ignored in the cookie (which is still expired) and in the fragment; bare hashes left in sessionStorage by the previous script are dropped and never posted.
- **Consent callback** — calls `window.kntntAdAttributionGetConsent`, 'yes' posts the stored pairs as `{ hashes: [...] }`, 'no' clears storage, 'unknown' does nothing, only first invocation processed.
- **Retry logic** — network error increments counter, 3 failures clears storage, success resets retries, HTTP 403 clears immediately.
- **Default consent function** — calls `callback('unknown')`.
- **Deduplication** — identical pairs deduplicated in sessionStorage; two clicks on the same hash at different times are kept.

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
  | `/test-trigger-conversion-public` | POST | The same without a permission check; called with the admin cookie and **no** nonce, like a form plugin submitting over REST (WordPress resets the current user to 0) |
  | `/test-clear-cookies` | POST | Clear plugin cookies and filter GET/POST params |
  | `/test-update-post-status` | POST | Change a post's status |
  | `/test-delete-post` | POST | Permanently delete a post |
  | `/test-delete-transient` | POST | Delete a transient |
  | `/test-set-logging` | POST | Switch diagnostic logging (`enable_logging` in `kntnt_ad_attr_settings`) `on` or `off` |
  | `/test-log` | GET, DELETE | Read or delete the plugin's log file |
  | `/test-nonce` | GET | Get REST nonce (public, bootstraps auth) |

  The same mu-plugin registers **probe adapters**, off unless their option is set (all string options, so `test-set-option` can set them):

  | Option | Effect |
  |--------|--------|
  | `test_probe_capturers` | `platform=param[,platform=param…]` (e.g. `google_ads=gclid`) adds those `kntnt_ad_attr_click_id_capturers` entries. |
  | `test_probe_reporter` | `on` registers reporter `probe`; `supports_test` also sets `'supports_test' => true`. Its `enqueue` appends everything it receives (`attributions`, `click_ids`, `campaigns`, `context`) to `test_probe_calls` and returns one job per attribution with `not_before` a day ahead, so WP-Cron leaves the jobs pending; its `process` returns `true`. |
  | `test_probe_calls` | The list of recorded `enqueue` calls. |
  | `test_probe_recorded` | `on` records every `kntnt_ad_attr_conversion_recorded` call (`attributions`, `context`) in `test_probe_recorded_calls`. |

  Suites switch them on with `set_probe_capturers`, `set_probe_reporter` and `set_probe_recorded`, read calls with `get_probe_calls` and `get_recorded_calls`, and must call `reset_probes` at the end.

  It also sets up two pieces of request handling:

  - **Internal traffic is off.** Suites trigger conversions as the logged-in admin, which the plugin treats as internal traffic and does not record. A `kntnt_ad_attr_is_internal` callback at `PHP_INT_MAX` therefore returns `false`, as the documented one-liner does, unless option `test_internal_default` is `on` (`set_internal_default on|off`). A suite that turns it on must turn it off at its end.
  - **Anonymous visitors.** A request with the header `X-Test-Visitor: anonymous` is stripped of the WordPress login cookies before WordPress determines the current user. See [Playground's cookie store](#playgrounds-cookie-store).
  - **Plugin cookies.** A request with the header `X-Test-Plugin-Cookies` carries exactly the plugin cookies (`_ad_clicks`, `_aah_pending`, `_ad_last_conv`) the header names, in Cookie-header syntax, and none for the value `none`; they replace those in `$_COOKIE` before the plugin reads it. `plugin_cookies "_ad_clicks=<value>"` prints the header, and `plugin_cookies` alone prints it for a visitor without plugin cookies. See [Playground's cookie store](#playgrounds-cookie-store).

### 7.3 Bootstrap Flow

`run-tests.sh` sources `tests/Integration/helpers/setup.sh` after starting Playground. Setup:

1. Logs in as admin via `wp-login.php` (Playground creates admin/password automatically).
2. Gets a REST nonce via the `/test-nonce` endpoint.
3. Flushes rewrite rules (required after cold start).
4. Exports `WP_BASE_URL`, `WP_NONCE`, and `ADMIN_COOKIE` for all test scripts.

### 7.4 Helpers

**`helpers/assertions.sh`** provides: `assert_status`, `assert_contains`, `assert_not_contains`, `assert_equals`, `assert_greater_than`, `assert_json_field`, `print_summary`. Each assertion increments `TESTS_RUN` and optionally `TESTS_FAILED`.

**`helpers/fixtures.sh`** provides: `create_tracking_url`, `create_target_page`, `simulate_click`, `get_click_cookies`, `extract_cookie_value`, `set_consent_state`, `get_click_count`, `query_db`, `query_db_rows`, `execute_sql`, `flush_rewrites`, `trigger_conversion`, `clear_cookies`, `strip_secure_flag`, `set_option`, `set_dedup_seconds`, `set_internal_default` (`on`|`off`), `trigger_conversion_without_nonce`, `plugin_cookies` (the `X-Test-Plugin-Cookies` header), the logging helpers `set_logging` (`on`|`off`), `get_log` and `clear_log`, and the probe adapter switches `set_probe_capturers`, `set_probe_reporter` (`on`|`supports_test`|`off`), `get_probe_calls` (JSON array of recorded `enqueue` calls), `reset_probe_calls`, `set_probe_recorded` (`on`|`off`), `get_recorded_calls` and `reset_probes`.

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

#### `test-deduplication.sh` — Conversion and Click Deduplication

- With dedup disabled (default `0`), repeated conversions for the same hash are all recorded.
- With dedup enabled (`set_dedup_seconds`, backed by the `test_dedup_seconds` option in the test-helpers mu-plugin) and consent granted: a first click by a visitor without plugin cookies records a click row; a re-click within the window carrying that visitor's cookie (`plugin_cookies`) re-sends the cookie with the original timestamp and records no second click row; a later conversion records exactly one conversion row, linked to the original click row.
- A cookie entry within the window that no click row matches is recorded as a new click, and the entry is repointed at that row. The cookie also holds an entry for another hash, which the response keeps, so the test shows the plugin read the cookie.

---

#### `test-deferred-consent.sh` — Deferred Consent Carries the Visitor's Own Click

Runs once with the `cookie` transport and once with the `fragment` transport, selected through the `test_pending_transport` option (the test-helpers mu-plugin feeds it to `kntnt_ad_attr_pending_transport`; `''` restores the default).

- With consent pending, visitor A's transport (`_aah_pending` cookie or `#_aah=` in the `Location` header) carries `hash:timestamp`, and the timestamp is the `clicked_at` of A's click row.
- Visitor B clicks the same tracking URL two seconds later.
- With consent granted, posting A's pair to `/set-cookie` anonymously returns success, and the `_ad_clicks` cookie it sets carries A's timestamp, not B's.
- The suite clears the shared set-cookie rate-limit transient at start and end, and resets the transport option and consent state.

---

#### `test-counting-rules.sh` — Repeats, Speculative Requests and the Diagnostic Log

Uses the probe capturer (`google_ads=gclid`) and logging (`set_logging on`, `get_log`, `clear_log`).

- Every request carries exactly the plugin cookies the test gives it (`plugin_cookies`), none unless stated.
- Two requests with `gclid=RRR` give one click row and one click-ID row. The repeat, by a visitor without an entry for the hash, redirects with its query parameters and gets an `_ad_clicks` entry with the original click's timestamp.
- A request with `Sec-Purpose: prefetch` gets 503, no redirect, no `_ad_clicks` cookie and no click row; a normal request after it gives one click row. `Purpose: prefetch` also gets 503.
- With logging enabled, each request writes exactly one `Tracking URL request:` line, with the outcome (`recorded`, `repeat`, `speculative`, `bot`, `deduplicated`), the hash prefix and the `Sec-Purpose`, `Purpose` and `User-Agent` values, and no IP address. With logging disabled, nothing is written.
- The suite switches logging off, clears the log, and resets the probes, the dedup window and the consent state.

---

#### `test-rest-api.sh` — REST Endpoints

- `/set-cookie`: a `hash:timestamp` pair matching a recorded click returns success; a pair whose timestamp matches no click row, a bare hash, all-invalid items and an unknown hash return failure; consent denied returns failure. The suite clears the shared rate-limit transient first.
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
- All four tables have correct schema (columns and types), including the per-click click-ID table and its `(platform, value)` index.
- Upgrade from v1.9.1: rebuilds the old per-hash click-ID table with a row, sets `kntnt_ad_attr_version` to `1.9.1`, makes a request, and asserts the version, the new table and that the old rows are dropped.
- Re-running 1.10.0 keeps the new table and its rows.
- After the re-runs, `clicks.is_test` exists exactly once (checked through `pragma_table_info`, because SQLite records a column added by `ALTER TABLE` unquoted in the `CREATE` statement), and a row inserted without it is `0`.
- `conversions.conversion_ref` and `queue.conversion_ref` each exist exactly once with one index after the re-runs; another re-run gives a conversion row without a reference its row ID and leaves a job without a reference NULL.

---

#### `test-internal-traffic.sh` — Internal Traffic and Test Mode

Switches the internal default on (`set_internal_default on`) and uses the probe reporter and recorded-action listener. Visitor requests send `X-Test-Visitor: anonymous`; admin requests carry the logged-in cookie.

- An admin's click is redirected with query forwarding and gets no click row and no `_ad_clicks` cookie; an anonymous visitor's click is recorded.
- A nonce-less REST conversion with the admin cookie writes no conversion row, fires no recorded action and calls no reporter.
- With the one-liner equivalent (`set_internal_default off`), the admin's click and conversion are recorded, with `context.test` false for the action and the reporter.
- A test click (`kntnt_ad_attr_test=1`) by an anonymous visitor and by the admin each give a row with `is_test = 1`, and the parameter is absent from the redirect URL while other parameters are forwarded.
- A conversion after the admin's test click writes a conversion row and is announced with `context.test` true; the reporter without `supports_test` is not called, the one with it receives `context.test` true.
- The main view's totals and the CSV row count for the suite's campaign are unchanged by the test clicks and test conversions.
- With logging enabled, both test clicks are logged as `outcome=recorded … test=1` and the test conversions as `Test conversion recorded …`, naming the reporter.

---

#### `test-recorded-conversions.sh` — Recorded Conversions

Uses the probe reporter, whose jobs are deferred a day so WP-Cron leaves them `pending`. Other statuses are inserted directly. The campaign name is unique per run, so the campaign-filtered numbers count only this run.

- Two conversions through the normal flow get distinct UUID references, carried by their probe jobs, and are listed newest first with checkbox, tracking URL (source / medium / campaign), weight, test flag, content and job status columns and the row actions.
- Delete with a forged or missing nonce gets 403 and changes nothing. Delete removes the conversion's rows and its `pending` and `failed` jobs, keeps the click rows, its `done` and `processing` jobs and the other conversion's jobs, lowers the main view's conversions by one, returns to the same date range, and the notice reports the deleted jobs and the processing job left alone.
- Mark as test flags the attributed click, deletes the pending job and removes the click and the conversion from the main view, its totals and the CSV export; the conversion stays listed, flagged, with Unmark. Unmark restores the numbers.
- The bulk actions with the list's `bulk-conversions` nonce mark and unmark; a forged bulk nonce gets 403.
- The capability refusal is covered by the unit tests: the Playground cookie store makes every request carry the admin login.

---

#### `test-click-ids.sh` — Per-click Click IDs

Uses the probe capturer (`google_ads=gclid`) and probe reporter.

- Visitor A clicks with `gclid=AAA&utm_term=kwA`, visitor B with `gclid=BBB&utm_term=kwB`, each without plugin cookies (`plugin_cookies`); A's cookie holds only A's click. A converts. The reporter receives `AAA` with A's `clicked_at` and `kwA`.
- A click without `gclid` converts with no click ID for the hash.
- Retention cleanup, the daily orphan sweep and permanent deletion of a tracking URL (via the admin trash view's nonce link) leave no click-ID rows for deleted clicks.

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

### Playground's cookie store

Playground's server (php-wasm `PHPRequestHandler`, `@wp-playground/cli` 0.9.x) does not pass a request's own `Cookie` header to PHP. It replaces it with the contents of a server-side `HttpCookieStore`, which remembers every cookie that any response has set. Every request therefore carries every cookie seen so far, including the admin's logged-in cookie from the setup login, whatever curl sends with `-b`; a cookie sent only by curl (e.g. `-b foo=bar`) does not arrive at all.

**Impact:** a test cannot make a request "without cookies". With internal-traffic detection on, every request would be internal. A later "visitor" also carries the `_ad_clicks` and `_aah_pending` that earlier visitors' responses set.

**Mitigation:** the test environment keeps internal-traffic detection off unless a suite switches it on, and a request that sends `X-Test-Visitor: anonymous` (custom headers do reach PHP) is stripped of the WordPress login cookies by the test-helpers mu-plugin before WordPress determines the current user. To control which plugin cookies a tracking-URL request carries, send `X-Test-Plugin-Cookies` (fixture `plugin_cookies`): the mu-plugin replaces `_ad_clicks`, `_aah_pending` and `_ad_last_conv` in `$_COOKIE` with the ones the header names, or none. A conversion takes its `_ad_clicks` value from `trigger_conversion`, which injects it into `$_COOKIE`. Never use `curl -b` for plugin cookies.

### SQLite date comparison limits

The WordPress SQLite compatibility plugin cannot reliably handle date comparisons beyond ~2027. For example, `clicked_at < '9999-12-31 23:59:59'` returns FALSE even for 2026 dates. Use realistic date ranges (e.g., `2020-01-01` to `2027-12-31`) in test assertions.

### Playground breaks after 400 requests

Playground swaps its PHP runtime after 400 requests, and the new runtime loses the SQLite driver: every request then fails with "Error establishing a database connection". `run-tests.sh` therefore restarts Playground every 8 suites. It also gives each run a private Playground cache (`HOME` for the CLI), because the shared `~/.wordpress-playground` cache, which other Playground runs rewrite on every boot, could boot WordPress into maintenance mode (HTTP 503). The test-helpers mu-plugin blocks update checks against `api.wordpress.org` and `api.github.com` for the same reason.

### Multi-table DELETE in SQLite

The SQLite translator accepts MySQL's multi-table `DELETE x FROM a x JOIN b … WHERE …` (with `INNER` or `LEFT JOIN`) and reports success, but deletes nothing. Code paths that must be verified in Playground use single-table deletes with subqueries instead: `DELETE FROM a WHERE col IN (SELECT …)` or `DELETE FROM a WHERE NOT EXISTS (SELECT 1 FROM b WHERE b.id = a.col)`. Both are valid MySQL.

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
| Speculation_Detector | ~9 | counting-rules |
| Internal_Detector | ~8 | internal-traffic |
| Click_Handler | ~60 | click-flow, query-forwarding, deferred-consent, counting-rules, internal-traffic |
| Conversion_Handler | ~33 | conversion-attribution, deduplication, internal-traffic, recorded-conversions |
| Post_Type | ~8 | activation, admin-crud |
| Click_ID_Store | ~6 | click-ids, migration |
| Queue | ~14 | cron-cleanup, recorded-conversions |
| Queue_Processor | ~8 | conversion-attribution |
| Cron | ~12 | cron-cleanup |
| Migrator | ~5 | migration |
| Rest_Endpoint | ~16 | rest-api, deferred-consent |
| Admin_Page | ~26 | admin-crud, recorded-conversions |
| Csv_Exporter | ~7 | csv-export |
| Campaign_List_Table | ~9 | campaign-report, csv-export, internal-traffic, recorded-conversions |
| Conversion_List_Table | ~12 | recorded-conversions |
| Utm_Options | ~3 | — |
| Updater | ~4 | — |
| Plugin | ~6 | activation |
| pending-consent.js | ~29 | — |
| admin.js | ~10 | — |
| **Totals** | **~352 PHP + 39 JS** | **19 suites** |
