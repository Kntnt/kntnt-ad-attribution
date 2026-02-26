# File Structure, Updates, and Translations

## File Structure

```
kntnt-ad-attribution/
├── kntnt-ad-attribution.php      ← Main plugin file (version header, PHP check, bootstrap)
├── autoloader.php                ← PSR-4 autoloader for Kntnt\Ad_Attribution namespace
├── install.php                   ← Activation script (capability, migration, cron)
├── uninstall.php                 ← Uninstall script (complete data removal)
├── README.md                     ← User-facing documentation
├── CLAUDE.md                     ← AI-focused codebase guidance
├── classes/
│   ├── Plugin.php                ← Singleton, component wiring, hooks, path helpers
│   ├── Updater.php               ← GitHub release update checker
│   ├── Migrator.php              ← Database migration runner (version-based)
│   ├── Settings.php              ← Centralized settings manager (kntnt_ad_attr_settings option)
│   ├── Logger.php                ← Shared diagnostic logger (file-based, credential masking)
│   ├── Post_Type.php             ← CPT registration, shared query helpers (v1.5.1)
│   ├── Click_Handler.php         ← Ad click processing, redirect, parameter forwarding
│   ├── Conversion_Handler.php    ← Conversion attribution, reporter enqueueing
│   ├── Cookie_Manager.php        ← Cookie read/write/validate (stateless)
│   ├── Consent.php               ← Three-state consent check logic
│   ├── Bot_Detector.php          ← User-Agent filtering, robots.txt rule
│   ├── Rest_Endpoint.php         ← REST API (set-cookie with rate limiting, search-posts)
│   ├── Admin_Page.php            ← Tools page with merged view, URL CRUD, CSV export
│   ├── Campaign_List_Table.php   ← WP_List_Table for campaign reporting
│   ├── Queue_List_Table.php      ← WP_List_Table for queue job management (run/delete)
│   ├── Csv_Exporter.php          ← CSV export with locale-aware formatting
│   ├── Utm_Options.php           ← Predefined UTM source/medium options (filterable)
│   ├── Settings_Page.php         ← Settings page under Settings > Ad Attribution
│   ├── Cron.php                  ← Daily cleanup job, target page warnings
│   ├── Click_ID_Store.php        ← Platform-specific click ID storage (v1.2.0)
│   ├── Queue.php                 ← Async job queue with configurable per-job retry (v1.2.0)
│   └── Queue_Processor.php       ← Queue processing via cron (v1.2.0)
├── migrations/
│   ├── 1.0.0.php                 ← No-op (legacy stats table, superseded by 1.5.0)
│   ├── 1.2.0.php                 ← Click ID and queue tables
│   ├── 1.5.0.php                 ← Clicks + conversions tables, drops stats
│   └── 1.8.0.php                 ← Per-job retry columns and index on queue table
├── js/
│   ├── pending-consent.js        ← Client-side: pending consent, sessionStorage, REST call
│   └── admin.js                  ← Admin: select2, page selector, UTM field auto-fill
├── css/
│   └── admin.css                 ← Admin: styling for tabs, page selector, list tables
├── tests/                        ← Test suite (see docs/testing-strategy.md)
│   ├── bootstrap.php
│   ├── Pest.php
│   ├── Helpers/
│   ├── Unit/                    ← PHP unit tests (Pest + Brain Monkey)
│   ├── JS/                      ← JavaScript unit tests (Vitest + happy-dom)
│   └── Integration/             ← End-to-end tests (Bash + curl + Playground)
├── docs/                         ← Technical specifications
│   ├── architecture.md
│   ├── click-handling.md
│   ├── cookies.md
│   ├── conversion-handling.md
│   ├── rest-api.md
│   ├── client-script.md
│   ├── admin-ui.md
│   ├── developer-hooks.md
│   ├── lifecycle.md
│   ├── security.md
│   ├── coding-standards.md
│   ├── file-structure.md
│   ├── consent-example.md
│   ├── consent-scenarios.md
│   └── testing-strategy.md
└── languages/
    ├── kntnt-ad-attribution.pot          ← Translation template
    └── kntnt-ad-attribution-sv_SE.po     ← Swedish translation source
```

Classes follow PSR-4: `Kntnt\Ad_Attribution\Click_Handler` → `classes/Click_Handler.php`.

## Updates from GitHub

The plugin is distributed via GitHub Releases, not wordpress.org. The `Updater` class hooks into `pre_set_site_transient_update_plugins` and compares the installed version against the latest GitHub release tag. If a new version exists and the release contains a `.zip` asset, the update is presented in WordPress admin. The update mechanism uses the standard WordPress plugin update UI — no custom update screen.

## Translations

The plugin supports translation via `load_plugin_textdomain` with text domain `kntnt-ad-attr`. Translation files (`.pot`, `.po`) are located in the `/languages` directory. Compiled `.mo` files are generated with `wp i18n make-mo languages/` (requires WP-CLI). The repository does not include `.mo` files — they must be generated after cloning.

## Distribution

GitHub Releases include a pre-built `.zip` file that can be installed directly via WordPress admin. The `.zip` excludes development files (`tests/`, `docs/`, `.github/`, etc.) and includes compiled `.mo` files.
