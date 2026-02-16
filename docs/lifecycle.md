# Lifecycle

## Activation

- Add capability `kntnt_ad_attr` to the Administrator and Editor roles.
- Run migration (creates the table if it does not exist).
- Register rewrite rules and flush.
- Schedule the daily cron job.

## Deactivation

Transient resources are unregistered. Persistent data is preserved.

**Removed:**

- WP-Cron jobs: `kntnt_ad_attr_daily_cleanup` and `kntnt_ad_attr_process_queue`
- Rewrite rules (`flush_rewrite_rules`)
- Transients

**Preserved:**

- Capability (`kntnt_ad_attr`)
- Custom tables (`kntnt_ad_attr_clicks`, `kntnt_ad_attr_conversions`, `kntnt_ad_attr_click_ids`, `kntnt_ad_attr_queue`)
- CPT posts (`kntnt_ad_attr_url`) and meta
- Options (`kntnt_ad_attr_version`)

## Uninstallation

Complete removal of all data:

- Remove capability `kntnt_ad_attr` from all roles.
- Drop tables: `kntnt_ad_attr_conversions`, `kntnt_ad_attr_clicks`, `kntnt_ad_attr_click_ids`, `kntnt_ad_attr_queue`.
- Delete all posts with post type `kntnt_ad_attr_url` and associated meta.
- Delete option `kntnt_ad_attr_version`.
- Delete any transients.
- Clear cron hooks: `kntnt_ad_attr_daily_cleanup`, `kntnt_ad_attr_process_queue`.

## Migration

The plugin uses a Migrator class that compares the stored version in `wp_options` with the current plugin version at `plugins_loaded`. If they differ, migration files are executed in order.

Migration files are located in the `migrations/` directory, named after the version they migrate TO:

```
migrations/
├── 1.0.0.php    ← initial: creates the stats table
├── 1.2.0.php    ← click ID and queue tables
└── 1.5.0.php    ← clicks + conversions tables, drops stats
```

Each file returns a callable:

```php
return function( \wpdb $wpdb ): void {
    // migration logic
};
```

## Daily Cron Job

Hook: `kntnt_ad_attr_daily_cleanup`. Performs:

1. Deletes click records older than the retention period (default 365 days, filterable via `kntnt_ad_attr_click_retention_days`) and their linked conversions.
2. Deletes orphaned conversion records whose click no longer exists (cascade cleanup).
3. Deletes click records whose hash has no published tracking URL post, and their linked conversions.
4. Checks if tracking URLs point to pages that no longer exist. If so: changes post status to `draft` and stores an admin notice.
5. Cleans up click IDs older than 120 days from `kntnt_ad_attr_click_ids`.
6. Cleans up queue jobs: `done` older than 30 days, `failed` older than 90 days from `kntnt_ad_attr_queue`.

## Warning on Target Page Deletion

When a page is moved to the trash, the plugin checks if tracking URLs point to it. If so: an admin notice warns the user.
