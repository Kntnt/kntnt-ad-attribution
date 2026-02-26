# Admin UI

The plugin registers a single menu item **Ad Attribution** under **Tools** in the WordPress admin menu via `add_management_page`. The page requires capability `kntnt_ad_attr`.

## Page Layout

The admin page presents a single merged view combining tracking URL management and campaign reporting. There are no tabs — all functionality is on one page.

Add-on plugins can still register custom views via the `kntnt_ad_attr_admin_tabs` filter and render content via the `kntnt_ad_attr_admin_tab_{$tab}` action (dispatched when `?tab=<slug>` is passed as a GET parameter).

## Main View

The main view displays a campaign list table powered by a `WP_List_Table` subclass (`Campaign_List_Table`). The table joins CPT metadata with click/conversion data from custom tables.

**Columns (publish view):**

- Checkbox (bulk actions)
- Tracking URL (full URL, click to copy to clipboard)
- Target URL (resolved via `get_permalink()`)
- Source, Medium, Campaign (from postmeta)
- Clicks (count from clicks table)
- Conversions (fractional sum from conversions table)

**Columns (trash view):**

- Checkbox (bulk actions)
- Tracking URL
- Target URL
- Source, Medium, Campaign

Trashed URLs omit click/conversion columns since trashed URLs have no active traffic.

**Row actions:** Trash (for published URLs), Restore / Delete Permanently (for trashed URLs).

**Bulk actions:** "Move to Trash" for published view, "Restore" / "Delete Permanently" for trash view.

**Views:** "All (N)" and "Trash (N)" status links, matching the standard WordPress pattern.

**"Create Tracking URL" button:** Displayed above the list when not in trash view. Navigates to the inline form for creating a new tracking URL.

**Form (Create Tracking URL):** Displayed on the same page (action=add). Fields:

- Target URL: searchable select component (see below) that displays post type and post ID. All public post types are included. The plugin's own CPT is excluded.
- Source (required), medium (required), campaign (required). Content, Term, Id, and Group are captured per click from incoming parameters and are not set in the form.

The hash is generated automatically on save.

**Searchable page selector:** Implemented with a REST-driven select component. The plugin registers a REST endpoint:

```
GET /wp-json/kntnt-ad-attribution/v1/search-posts?search=<search term>
```

The endpoint searches published posts (all public post types except `kntnt_ad_attr_url`) using a multi-strategy lookup: exact post ID, URL resolution, slug LIKE matching, and title search via `WP_Query` (see [rest-api.md](rest-api.md) for details). It returns:

```json
[
    { "id": 42, "title": "Contact Us", "type": "page" },
    { "id": 17, "title": "Free Guide", "type": "post" }
]
```

Requires capability `kntnt_ad_attr`. The frontend component uses `select2` (loaded from cdnjs.cloudflare.com) with REST data source. Admin assets (`js/admin.js` and `css/admin.css`) are loaded only on the plugin's admin page via `admin_enqueue_scripts` with page hook check. REST URL and nonce are delivered to the admin script via `wp_localize_script`:

```php
wp_localize_script( 'kntnt-ad-attribution-admin', 'kntntAdAttrAdmin', [
    'searchUrl' => rest_url( 'kntnt-ad-attribution/v1/search-posts' ),
    'nonce'     => wp_create_nonce( 'wp_rest' ),
] );
```

**Filtering:** Search field + dropdown filter per UTM dimension. Date range filter with two HTML5 `<input type="date">` fields — defaults to the two most recent complete calendar weeks based on the WordPress "Week Starts On" setting. Page reload.

**Pagination:** Built into `WP_List_Table`. Default 20 rows (configurable via Screen Options).

## SQL Query (Main View)

```sql
SELECT p.ID AS post_id,
       pm_hash.meta_value AS hash,
       pm_target.meta_value AS target_post_id,
       pm_src.meta_value AS utm_source,
       pm_med.meta_value AS utm_medium,
       pm_camp.meta_value AS utm_campaign,
       COUNT(c.id) AS total_clicks,
       COALESCE(SUM(cv.fractional_conversion), 0) AS total_conversions
FROM {wpdb->posts} p
INNER JOIN {wpdb->postmeta} pm_hash
    ON pm_hash.post_id = p.ID AND pm_hash.meta_key = '_hash'
INNER JOIN {wpdb->postmeta} pm_target
    ON pm_target.post_id = p.ID AND pm_target.meta_key = '_target_post_id'
LEFT JOIN {wpdb->postmeta} pm_src
    ON pm_src.post_id = p.ID AND pm_src.meta_key = '_utm_source'
LEFT JOIN {wpdb->postmeta} pm_med
    ON pm_med.post_id = p.ID AND pm_med.meta_key = '_utm_medium'
LEFT JOIN {wpdb->postmeta} pm_camp
    ON pm_camp.post_id = p.ID AND pm_camp.meta_key = '_utm_campaign'
LEFT JOIN {prefix}kntnt_ad_attr_clicks c
    ON c.hash = pm_hash.meta_value
LEFT JOIN {prefix}kntnt_ad_attr_conversions cv
    ON cv.click_id = c.id
WHERE p.post_type = 'kntnt_ad_attr_url' AND p.post_status = 'publish'
-- Additional WHERE clauses based on active filters
GROUP BY p.ID, pm_hash.meta_value, pm_target.meta_value,
         pm_src.meta_value, pm_med.meta_value, pm_camp.meta_value
ORDER BY total_clicks DESC
LIMIT %d OFFSET %d
```

Starting FROM posts ensures that tracking URLs with zero clicks are included in the list.

**Trash view query** uses a simplified query without the clicks/conversions joins, since trashed URLs have no active traffic. It selects directly from the posts and postmeta tables with `post_status = 'trash'`.

**CSV query** uses the same base without GROUP BY — each row is one click–conversion pair. Includes per-click UTM fields (`c.utm_content`, `c.utm_term`, `c.utm_id`, `c.utm_source_platform`), timestamps (`c.clicked_at`, `cv.converted_at`), and fractional attribution (`cv.fractional_conversion`) in SELECT. Excludes tracking URLs with zero clicks via `AND c.id IS NOT NULL`.

**Target URL is resolved in PHP**, not in SQL: `get_permalink( (int) $row->target_post_id )`. This ensures the URL always reflects the current permalink structure.

**Summation:** Separate query without GROUP BY — totals for the entire filtered dataset using `COUNT(DISTINCT c.id)` for clicks and `COALESCE(SUM(cv.fractional_conversion), 0)` for conversions.

## CSV Export

Button below the list table (visible on publish view). Same query without LIMIT/OFFSET, streamed as `text/csv`.

**Character encoding:** UTF-8 with BOM (`\xEF\xBB\xBF`).

**Delimiter:** Respects the WordPress locale's decimal separator. If the decimal character is a comma (e.g., Swedish), semicolon is used as the field delimiter.

**Columns (one row per click–conversion pair):**

| Column | Content |
|--------|---------|
| `tracking_url` | Full tracking URL |
| `target_url` | Target URL |
| `utm_source` | Source |
| `utm_medium` | Medium |
| `utm_campaign` | Campaign |
| `utm_content` | Content |
| `utm_term` | Term |
| `utm_id` | Id |
| `utm_source_platform` | Group |
| `clicked_at` | Click timestamp (MySQL datetime `YYYY-MM-DD HH:MM:SS`) |
| `fractional_conversion` | Attribution value (4 decimals, locale's decimal character; empty if no conversion) |
| `converted_at` | Conversion timestamp (MySQL datetime; empty if no conversion) |

**Filename pattern:** `kntnt-ad-attribution-YYYY-MM-DD.csv` or `kntnt-ad-attribution-YYYY-MM-DD-to-YYYY-MM-DD.csv` if a date filter is set.

**Nonce:** CSV export requires its own nonce validation (`kntnt_ad_attr_export`).
