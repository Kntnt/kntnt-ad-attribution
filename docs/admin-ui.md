# Admin UI

The plugin registers a single menu item **Ad Attribution** under **Tools** in the WordPress admin menu via `add_management_page`. The page requires capability `kntnt_ad_attr`.

## Navigation

The page has two tabs rendered as horizontal links at the top of the page using the WordPress standard CSS class `nav-tab-wrapper`:

```
URLs | Campaigns
```

The active tab is marked with `nav-tab-active`. The tabs are regular `<a>` elements that navigate to the same admin page with GET parameter `tab`:

```
tools_page_kntnt-ad-attribution&tab=urls
tools_page_kntnt-ad-attribution&tab=campaigns
```

Default tab (if `tab` is missing): `urls`. No JavaScript required — full page reload on tab switch. Version 1 has no Settings tab — all behavior is configured via filters (see [developer-hooks.md](developer-hooks.md)).

## URLs Tab

Tracking URLs are displayed and managed via a custom `WP_List_Table` subclass (not the CPT's `edit.php`). The reason is that everything should live under a single menu page with tabs. The CPT `kntnt_ad_attr_url` is still used as the data model, but the admin UI is built separately.

**Columns:**

- Hash (truncated, e.g., first 12 characters)
- Tracking URL (full)
- Target URL (resolved via `get_permalink()`)
- UTM source, medium, campaign, content, term

**Row actions:** Trash (or Restore / Delete Permanently for trashed URLs).

**Form (Add New):** Displayed on the same page. Fields:

- Target URL: searchable select component (see below) that displays post type and post ID. All public post types are included. The plugin's own CPT is excluded.
- UTM source (required), medium (required), campaign (required), content (optional), term (optional).

The hash is generated automatically on save.

**Searchable page selector:** Implemented with a REST-driven select component. The plugin registers a REST endpoint:

```
GET /wp-json/kntnt-ad-attribution/v1/search-posts?search=<search term>
```

The endpoint searches published posts (all public post types except `kntnt_ad_attr_url`) via `WP_Query` and returns:

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

**Filtering:** Search field + dropdown filter per UTM dimension. Page reload.

**Pagination:** Built into `WP_List_Table`. Default 20 rows (configurable via Screen Options).

## Campaigns Tab

Report view that joins CPT metadata with aggregated stats. Rendered as a `WP_List_Table` subclass.

**Filtering** (GET parameters, page reload):

- Date range: two HTML5 `<input type="date">` fields.
- UTM dimensions: one dropdown per dimension, populated with distinct values from post meta.
- Tracking URL / target URL: free-text field.

**SQL query:**

```sql
SELECT s.hash,
       pm_target.meta_value AS target_post_id,
       pm_src.meta_value AS utm_source,
       pm_med.meta_value AS utm_medium,
       pm_camp.meta_value AS utm_campaign,
       pm_cont.meta_value AS utm_content,
       pm_term.meta_value AS utm_term,
       SUM(s.clicks) AS total_clicks,
       SUM(s.conversions) AS total_conversions
FROM {prefix}kntnt_ad_attr_stats s
INNER JOIN {wpdb->postmeta} pm_hash
    ON pm_hash.meta_key = '_hash' AND pm_hash.meta_value = s.hash
INNER JOIN {wpdb->posts} p
    ON p.ID = pm_hash.post_id AND p.post_type = 'kntnt_ad_attr_url'
INNER JOIN {wpdb->postmeta} pm_target
    ON pm_target.post_id = p.ID AND pm_target.meta_key = '_target_post_id'
INNER JOIN {wpdb->postmeta} pm_src
    ON pm_src.post_id = p.ID AND pm_src.meta_key = '_utm_source'
INNER JOIN {wpdb->postmeta} pm_med
    ON pm_med.post_id = p.ID AND pm_med.meta_key = '_utm_medium'
INNER JOIN {wpdb->postmeta} pm_camp
    ON pm_camp.post_id = p.ID AND pm_camp.meta_key = '_utm_campaign'
LEFT JOIN {wpdb->postmeta} pm_cont
    ON pm_cont.post_id = p.ID AND pm_cont.meta_key = '_utm_content'
LEFT JOIN {wpdb->postmeta} pm_term
    ON pm_term.post_id = p.ID AND pm_term.meta_key = '_utm_term'
WHERE s.date BETWEEN %s AND %s
-- Additional WHERE clauses based on active filters
GROUP BY s.hash, pm_target.meta_value, pm_src.meta_value,
         pm_med.meta_value, pm_camp.meta_value,
         pm_cont.meta_value, pm_term.meta_value
ORDER BY total_clicks DESC
LIMIT %d OFFSET %d
```

**Target URL is resolved in PHP**, not in SQL: `get_permalink( (int) $row->target_post_id )`. This ensures the URL always reflects the current permalink structure.

**Summation:** Separate query without GROUP BY — totals for the entire filtered dataset.

**Pagination:** Built into `WP_List_Table`. Default 20 rows (configurable via Screen Options).

## CSV Export

Button in the Campaigns tab. Same query without LIMIT/OFFSET, streamed as `text/csv`.

**Character encoding:** UTF-8 with BOM (`\xEF\xBB\xBF`).

**Delimiter:** Respects the WordPress locale's decimal separator. If the decimal character is a comma (e.g., Swedish), semicolon is used as the field delimiter.

**Columns:**

| Column | Content |
|--------|---------|
| `tracking_url` | Full tracking URL |
| `target_url` | Target URL |
| `utm_source` | UTM source |
| `utm_medium` | UTM medium |
| `utm_campaign` | UTM campaign |
| `utm_content` | UTM content |
| `utm_term` | UTM term |
| `clicks` | Total clicks (integer) |
| `conversions` | Fractional conversions (4 decimals, locale's decimal character) |

**Filename pattern:** `kntnt-ad-attribution-YYYY-MM-DD.csv` or `kntnt-ad-attribution-YYYY-MM-DD-to-YYYY-MM-DD.csv` if a date filter is set.

**Nonce:** CSV export requires its own nonce validation (`kntnt_ad_attr_export`).
