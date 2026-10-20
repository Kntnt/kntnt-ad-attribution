# Architecture

## Naming Conventions

All machine-readable names use `kntnt-ad-attr` (hyphens) / `kntnt_ad_attr` (underscores) as prefix. The PHP namespace remains `Kntnt\Ad_Attribution`. The GitHub URL (`kntnt-ad-attribution`) is the only exception.

| Context | Name |
|---------|------|
| Text domain | `kntnt-ad-attr` |
| Post type | `kntnt_ad_attr_url` |
| Custom tables | `{prefix}kntnt_ad_attr_clicks`, `{prefix}kntnt_ad_attr_conversions`, `{prefix}kntnt_ad_attr_click_ids`, `{prefix}kntnt_ad_attr_queue` |
| Capability | `kntnt_ad_attr` |
| DB version option | `kntnt_ad_attr_version` |
| Settings option | `kntnt_ad_attr_settings` |
| Cron hooks | `kntnt_ad_attr_daily_cleanup`, `kntnt_ad_attr_process_queue` |
| REST namespace | `kntnt-ad-attribution/v1` |
| REST CPT base | `kntnt-ad-attr-urls` |
| All filters/actions | `kntnt_ad_attr_*` |
| Namespace | `Kntnt\Ad_Attribution` |
| Cookies | `_ad_clicks`, `_aah_pending`, `_ad_last_conv` |

## Data Model

### Tracking URLs — Custom Post Type

Tracking URLs are stored as custom post type `kntnt_ad_attr_url`. They are relatively few (hundreds to low thousands), created manually by administrators, and managed via CRUD. A CPT provides searchability via `WP_Query` and integration with WordPress export/import without custom implementation. The admin UI is built separately with `WP_List_Table` (see [admin-ui.md](admin-ui.md)) to keep everything under a single menu page with tabs.

CPT registration — key arguments:

```php
'public'       => false,
'show_ui'      => false,
'show_in_menu' => false,
'show_in_rest' => true,   // Required for Block Editor-compatible export/import
'rest_base'    => 'kntnt-ad-attr-urls',
'supports'     => [ 'title' ],
'capability_type' => 'post',
'map_meta_cap' => true,
```

`show_in_rest => true` exposes the CPT via the WordPress REST API (`/wp/v2/kntnt-ad-attr-urls`). This is not required by the plugin's core functionality but enables export/import and future third-party integrations. REST access is restricted by standard WordPress capabilities.

**Post fields:**

| Field | Content |
|-------|---------|
| `post_title` | The tracking URL (`https://example.com/ad/<hash>`) |
| `post_status` | `publish` (active), `draft` (deactivated — target page missing) or `trash` (deleted) |

**Meta keys:**

| Meta key | Value |
|----------|-------|
| `_hash` | SHA-256 hash (64 characters). Unique per application logic (see below). |
| `_target_post_id` | WordPress post ID for the target page |
| `_utm_source` | UTM source (required) |
| `_utm_medium` | UTM medium (required) |
| `_utm_campaign` | UTM campaign (required) |

The hash is stored as post meta. The hash is not the post slug — slugs have length limitations and normalization that can cause issues with exact 64-character hex strings.

**Uniqueness control:** Uniqueness is enforced at the application level via the do-while loop in hash generation (see below). The WordPress `wp_postmeta` table does not support filtered unique indexes, so uniqueness cannot be guaranteed at the database level without creating a separate lookup table (unnecessary complexity in version 1).

**Hash lookup on click:** The query joins `wp_postmeta` with `wp_posts` to retrieve the post ID and verify that the CPT post has `post_status = 'publish'`. Only active (published) tracking URLs respond to clicks; draft or trashed tracking URLs return 404. The query uses WordPress's existing index on `meta_key`. For the expected volume (hundreds to low thousands of tracking URLs), this is sufficiently fast. No custom index is needed in version 1.

### Hash Generation

The hash is generated from random input, not from the URL or UTM parameters:

```php
do {
    $hash = hash( 'sha256', random_bytes( 32 ) );
} while ( $this->hash_exists( $hash ) );
```

The hash is an identifier, not a checksum. It must be possible to create any number of tracking URLs for the same target URL and UTM combination. `random_bytes(32)` provides 256 bits of entropy.

If `INSERT` fails with a duplicate key (race condition): display an error message and ask the user to try again.

### Individual Clicks — Custom Table

Each click is recorded as an individual row (bots, speculative requests, internal traffic, repeats and duplicates are not; see [click-handling.md](click-handling.md#counting-rules)). Source/Medium/Campaign are fixed per tracking URL and stored in postmeta. Content/Term/Id/Group vary per click and are stored here.

```sql
CREATE TABLE {prefix}kntnt_ad_attr_clicks (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    hash                CHAR(64)        NOT NULL,
    clicked_at          DATETIME        NOT NULL,
    utm_content         VARCHAR(255)    NULL,
    utm_term            VARCHAR(255)    NULL,
    utm_id              VARCHAR(255)    NULL,
    utm_source_platform VARCHAR(255)    NULL,
    is_test             TINYINT(1)      NOT NULL DEFAULT 0,  -- v1.10.0
    PRIMARY KEY (id),
    INDEX idx_hash (hash),
    INDEX idx_clicked_at (clicked_at)
) {charset}
```

`is_test` (added by migration 1.10.0) is `1` for a [test click](click-handling.md#test-clicks) (`kntnt_ad_attr_test=1`). Conversions have no flag of their own: a conversion is a test when it links to a test click. The main view, its totals and the CSV export exclude test clicks and, through them, their conversions; test conversions reach only reporters with `supports_test` (see [conversion-handling.md](conversion-handling.md#internal-traffic-and-test-conversions)).

**On click:**

```php
$wpdb->insert( $clicks_table, [
    'hash'                => $hash,
    'clicked_at'          => gmdate( 'Y-m-d H:i:s', $now ),
    'utm_content'         => $click_content ?: null,
    'utm_term'            => $click_term ?: null,
    'utm_id'              => $click_id_val ?: null,
    'utm_source_platform' => $click_group ?: null,
    'is_test'             => $is_test_click ? 1 : 0,
] );
```

Timestamps are stored in UTC using `gmdate()`. Click retention defaults to 365 days, configurable via `kntnt_ad_attr_click_retention_days`.

### Conversions — Custom Table

Conversion attribution linked to specific clicks. Each conversion produces one row per attributed click. With last-click attribution (default), one row per conversion.

```sql
CREATE TABLE {prefix}kntnt_ad_attr_conversions (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    click_id              BIGINT UNSIGNED NOT NULL,
    converted_at          DATETIME        NOT NULL,
    fractional_conversion DECIMAL(10,4)   NOT NULL,
    conversion_ref        VARCHAR(36)     NULL,  -- v1.10.0
    PRIMARY KEY (id),
    INDEX idx_click_id (click_id),
    INDEX idx_converted_at (converted_at),
    INDEX idx_conversion_ref (conversion_ref)  -- v1.10.0
) {charset}
```

**On conversion:**

The handler looks up the click record matching the cookie timestamp, then inserts a conversion row with the fractional attribution value.

`conversion_ref` (added by migration 1.10.0) identifies the conversion a row belongs to. `Conversion_Handler` generates one UUID (`wp_generate_uuid4()`) per conversion and writes it on every row, so the rows a multi-touch model writes for one conversion share it; `converted_at`, with one-second resolution, cannot tell two conversions apart. The report jobs enqueued for the conversion carry the same reference (see the queue table below). The migration gives each row written before 1.10.0 its own row ID as reference, so each old row is a conversion of its own; a UUID never looks like a row ID. The admin's conversion list groups, deletes and flags conversions by this column (see [admin-ui.md](admin-ui.md#conversions)).

Aggregated statistics (total clicks, total conversions per tracking URL) are computed at query time via `COUNT()` and `SUM()` on these tables.

### Click IDs — Custom Table

Platform-specific click IDs (e.g. `gclid`, `fbclid`, `msclkid`) are captured by registered adapters via the `kntnt_ad_attr_click_id_capturers` filter and stored in `kntnt_ad_attr_click_ids` (v1.10.0 layout). Each click ID belongs to exactly one click row: the row inserted for the click that carried it.

```sql
CREATE TABLE {prefix}kntnt_ad_attr_click_ids (
    click_id BIGINT UNSIGNED NOT NULL,
    platform VARCHAR(50)     NOT NULL,
    value    VARCHAR(255)    NOT NULL,
    PRIMARY KEY (click_id, platform),
    INDEX idx_platform_value (platform, value(100))
) {charset}
```

- `click_id` is the ID of the row in `kntnt_ad_attr_clicks`, as in the conversions table. `value` holds the platform's click ID.
- The composite PK `(click_id, platform)` lets one click carry IDs from several platforms (e.g. a Google Ads ad that also has `fbclid` in the URL). A click without an ID for a platform has no row for it.
- There is no `clicked_at` column: the capture time is the click row's `clicked_at`, read through a join.
- The `(platform, value)` index answers "has this click ID value already been recorded for this tracking URL?": it narrows the click-ID rows to those carrying the value, and the join on `clicks.id` keeps those with the tracking URL's hash (`Click_ID_Store::find_original_click()`). The 100-character prefix keeps the key under InnoDB's 767-byte limit on older MySQL versions with utf8mb4.
- Click IDs live exactly as long as their click row. Whenever click rows are deleted (retention cleanup, orphan cleanup, permanent deletion of a tracking URL), their click IDs are deleted too. There is no separate age-based cleanup.

When a conversion is recorded, reporters receive the click IDs captured on the click row the conversion was linked to (`Click_ID_Store::get_for_clicks()`), not the latest IDs on the tracking URL.

Before v1.10.0 the table held one row per `(hash, platform)`, overwritten by every click. The 1.10.0 migration drops it, since its rows cannot be mapped to click rows, and creates the per-click table.

### Report Queue — Custom Table

The `kntnt_ad_attr_queue` table provides an asynchronous job queue for conversion reporting via registered reporters. Jobs are enqueued during conversion handling and processed by `Queue_Processor` via a cron event.

```sql
CREATE TABLE {prefix}kntnt_ad_attr_queue (
    id                 BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    reporter           VARCHAR(50)      NOT NULL,
    payload            TEXT             NOT NULL,
    status             VARCHAR(20)      NOT NULL DEFAULT 'pending',
    attempts           TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at         DATETIME         NOT NULL,
    processed_at       DATETIME         NULL,
    last_attempt_at    DATETIME         NULL,
    not_before         DATETIME         NULL,
    error_message      TEXT             NULL,
    retry_after        DATETIME         NULL,
    label              VARCHAR(255)     NULL,
    attempts_per_round TINYINT UNSIGNED NULL,
    retry_delay        INT UNSIGNED     NULL,
    max_rounds         TINYINT UNSIGNED NULL,
    round_delay        INT UNSIGNED     NULL,
    conversion_ref     VARCHAR(36)      NULL,  -- v1.10.0
    PRIMARY KEY (id),
    INDEX idx_status_eligible (status, not_before, retry_after, created_at),
    INDEX idx_queue_conversion_ref (conversion_ref)  -- v1.10.0
) {charset}
```

Status transitions: `pending` → `processing` → `done` | `failed`. `status` is `VARCHAR(20)` instead of `ENUM` to avoid schema changes if new statuses are added. Retry logic is configurable per job via `attempts_per_round`, `retry_delay`, `max_rounds`, and `round_delay` columns (with global defaults from `Settings`). The `not_before` column defers processing until a specific time (NULL = immediately eligible); used by reporters like Google Ads that need a delay after click capture. The `last_attempt_at` column tracks when each job was last processed. The `label` column stores a human-readable job description for the admin queue management UI. The `conversion_ref` column (v1.10.0) holds the reference of the conversion the job reports, set by `Conversion_Handler` through `Queue::enqueue()`; reporters neither see nor set it. Deleting a conversion or marking it as a test in the admin deletes its `pending` and `failed` jobs by this reference (`Queue::delete_unfinished_for_conversions()`). Jobs created before 1.10.0 have NULL and are never touched. Completed jobs are cleaned up after 30 days, failed jobs after 90 days.

### Target URL — Dynamic Resolving

The target URL is stored as a WordPress post ID in meta (`_target_post_id`), not as a static URL string. On redirect, the URL is resolved dynamically:

```php
$url = get_permalink( $target_post_id );
```

Permalink changes are handled automatically. Deleted pages are detected (`get_permalink()` returns `false`).

When creating a tracking URL, the user selects a page via a searchable select component that displays post type and post ID to distinguish posts with the same title. All public post types are included (page, post, custom post types). The plugin's own CPT is excluded.
