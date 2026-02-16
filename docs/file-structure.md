# File Structure, Updates, and Translations

## File Structure

```
kntnt-ad-attribution/
├── kntnt-ad-attribution.php      ← Main plugin file
├── autoloader.php                ← PSR-4 autoloader
├── install.php                   ← Activation script
├── uninstall.php                 ← Uninstall script
├── classes/
│   ├── Plugin.php                ← Singleton, hooks, options
│   ├── Updater.php               ← GitHub update checker
│   ├── Migrator.php              ← Database migration runner
│   ├── Click_Handler.php         ← Ad click processing
│   ├── Conversion_Handler.php    ← Conversion attribution
│   ├── Cookie_Manager.php        ← Cookie read/write/validate
│   ├── Consent.php               ← Consent check logic
│   ├── Bot_Detector.php          ← User-Agent filtering
│   ├── Rest_Endpoint.php         ← REST API (set-cookie, search-posts)
│   ├── Admin_Page.php            ← Tools page with tab navigation
│   ├── Url_List_Table.php        ← WP_List_Table for the URLs tab
│   ├── Campaign_List_Table.php   ← WP_List_Table for the Campaigns tab
│   ├── Post_Type.php             ← CPT registration (no admin UI)
│   ├── Csv_Exporter.php          ← CSV export handler
│   ├── Utm_Options.php           ← Predefined UTM source/medium options
│   ├── Cron.php                  ← Daily cleanup job
│   ├── Click_ID_Store.php        ← Click ID storage (v1.2.0)
│   ├── Queue.php                 ← Async job queue (v1.2.0)
│   └── Queue_Processor.php       ← Queue processing via cron (v1.2.0)
├── migrations/
│   ├── 1.0.0.php                 ← Initial table creation
│   ├── 1.1.0.php                 ← No schema changes (companion hooks)
│   └── 1.2.0.php                 ← Click ID and queue tables
├── js/
│   ├── pending-consent.js        ← Client-side consent/cookie script
│   └── admin.js                  ← Admin: select2 initialization, page selector
├── css/
│   └── admin.css                 ← Admin: styling for page selector and tabs
└── languages/
    ├── kntnt-ad-attribution.pot
    └── kntnt-ad-attribution-sv_SE.po
```

Classes follow PSR-4: `Kntnt\Ad_Attribution\Click_Handler` → `classes/Click_Handler.php`.

## Updates from GitHub

The plugin is distributed via GitHub Releases, not wordpress.org. An Updater class hooks into `pre_set_site_transient_update_plugins` and compares the installed version against the latest GitHub release tag. If a new version exists and the release contains a `.zip` asset, the update is presented in WordPress admin.

## Translations

The plugin supports translation via `load_plugin_textdomain`. Translation files (`.pot`, `.po`) are located in the `/languages` directory. Compiled `.mo` files are generated with `wp i18n make-mo languages/` (requires WP-CLI). The repository does not include `.mo` files.
