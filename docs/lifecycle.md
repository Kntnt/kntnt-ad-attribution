# Lifecycle

## Activation

- Add capability `kntnt_ad_attr` to the Administrator and Editor roles.
- Run migration (creates the table if it does not exist).
- Register rewrite rules and flush.
- Schedule the daily cron job.

## Deactivation

Transient resources are unregistered. Persistent data is preserved.

**Removed:**

- WP-Cron job (`wp_clear_scheduled_hook`)
- Rewrite rules (`flush_rewrite_rules`)
- Transients

**Preserved:**

- Capability (`kntnt_ad_attr`)
- Custom table (`kntnt_ad_attr_stats`)
- CPT posts (`kntnt_ad_attr_url`) and meta
- Options (`kntnt_ad_attr_version`)

## Uninstallation

Complete removal of all data:

- Remove capability `kntnt_ad_attr` from all roles.
- Drop the table `{prefix}kntnt_ad_attr_stats`.
- Delete all posts with post type `kntnt_ad_attr_url` and associated meta.
- Delete option `kntnt_ad_attr_version`.
- Delete any transients.

## Migration

The plugin uses a Migrator class that compares the stored version in `wp_options` with the current plugin version at `plugins_loaded`. If they differ, migration files are executed in order.

Migration files are located in the `migrations/` directory, named after the version they migrate TO:

```
migrations/
├── 1.0.0.php    ← initial: creates the table
├── 1.2.0.php    ← adds a column
└── 1.5.0.php    ← changes an index
```

Each file returns a callable:

```php
return function( \wpdb $wpdb ): void {
    // migration logic
};
```

## Daily Cron Job

Hook: `kntnt_ad_attr_daily_cleanup`. Performs:

1. Cleans up rows in `kntnt_ad_attr_stats` whose hash does not have a corresponding CPT post.
2. Checks if tracking URLs point to pages that no longer exist. If so: changes post status to `draft` and stores an admin notice.

## Warning on Target Page Deletion

When a page is moved to the trash, the plugin checks if tracking URLs point to it. If so: an admin notice warns the user.
