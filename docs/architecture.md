# Architecture

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
| `_utm_source` | UTM source |
| `_utm_medium` | UTM medium |
| `_utm_campaign` | UTM campaign |
| `_utm_content` | UTM content (optional) |
| `_utm_term` | UTM term (optional) |
| `_utm_id` | Campaign ID (optional) |
| `_utm_source_platform` | Source platform / group (optional) |

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

### Click and Conversion Statistics — Custom Table

Individual events do not need to be stored. The admin UI displays totals per hash per date range. A single table with daily granularity:

```sql
CREATE TABLE {prefix}kntnt_ad_attr_stats (
    hash        CHAR(64)       NOT NULL,
    date        DATE           NOT NULL,
    clicks      INT UNSIGNED   NOT NULL DEFAULT 0,
    conversions DECIMAL(10,4)  NOT NULL DEFAULT 0,
    PRIMARY KEY (hash, date),
    INDEX idx_date (date)
) {charset}
```

**On click:**

```sql
INSERT INTO {prefix}kntnt_ad_attr_stats (hash, date, clicks, conversions)
VALUES (%s, %s, 1, 0)
ON DUPLICATE KEY UPDATE clicks = clicks + 1
```

**On conversion:**

```sql
INSERT INTO {prefix}kntnt_ad_attr_stats (hash, date, clicks, conversions)
VALUES (%s, %s, 0, %f)
ON DUPLICATE KEY UPDATE conversions = conversions + %f
```

Dates are stored in UTC using `gmdate( 'Y-m-d' )`.

### Click IDs — Custom Table

Platform-specific click IDs (e.g. `gclid`, `fbclid`, `msclkid`) are captured by registered adapters via the `kntnt_ad_attr_click_id_capturers` filter and stored in `kntnt_ad_attr_click_ids`. Each hash/platform combination has exactly one row.

```sql
CREATE TABLE {prefix}kntnt_ad_attr_click_ids (
    hash       CHAR(64)     NOT NULL,
    platform   VARCHAR(50)  NOT NULL,
    click_id   VARCHAR(255) NOT NULL,
    clicked_at DATETIME     NOT NULL,
    PRIMARY KEY (hash, platform),
    INDEX idx_clicked_at (clicked_at)
) {charset}
```

The composite PK `(hash, platform)` allows a single click to carry IDs from multiple platforms (e.g. a Google Ads ad that also has `fbclid` in the URL). `INSERT … ON DUPLICATE KEY UPDATE` is used for atomic upserts. Rows older than 120 days are cleaned up by the daily cron job.

### Report Queue — Custom Table

The `kntnt_ad_attr_queue` table provides an asynchronous job queue for conversion reporting via registered reporters. Jobs are enqueued during conversion handling and processed by `Queue_Processor` via a cron event.

```sql
CREATE TABLE {prefix}kntnt_ad_attr_queue (
    id            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    reporter      VARCHAR(50)      NOT NULL,
    payload       TEXT             NOT NULL,
    status        VARCHAR(20)      NOT NULL DEFAULT 'pending',
    attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at    DATETIME         NOT NULL,
    processed_at  DATETIME         NULL,
    error_message TEXT             NULL,
    PRIMARY KEY (id),
    INDEX idx_status (status, created_at)
) {charset}
```

Status transitions: `pending` → `processing` → `done` | `failed`. `status` is `VARCHAR(20)` instead of `ENUM` to avoid schema changes if new statuses are added. Jobs are retried up to 3 times before being marked as `failed`. Completed jobs are cleaned up after 30 days, failed jobs after 90 days.

### Target URL — Dynamic Resolving

The target URL is stored as a WordPress post ID in meta (`_target_post_id`), not as a static URL string. On redirect, the URL is resolved dynamically:

```php
$url = get_permalink( $target_post_id );
```

Permalink changes are handled automatically. Deleted pages are detected (`get_permalink()` returns `false`).

When creating a tracking URL, the user selects a page via a searchable select component that displays post type and post ID to distinguish posts with the same title. All public post types are included (page, post, custom post types). The plugin's own CPT is excluded.
