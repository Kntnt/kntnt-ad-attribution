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
   AND c.clicked_at BETWEEN %s AND %s
   AND c.is_test = 0
LEFT JOIN {prefix}kntnt_ad_attr_conversions cv
    ON cv.click_id = c.id
WHERE p.post_type = 'kntnt_ad_attr_url' AND p.post_status = 'publish'
-- Additional WHERE clauses based on active filters
GROUP BY p.ID, pm_hash.meta_value, pm_target.meta_value,
         pm_src.meta_value, pm_med.meta_value, pm_camp.meta_value
ORDER BY total_clicks DESC
LIMIT %d OFFSET %d
```

Starting FROM posts ensures that tracking URLs with zero clicks are included in the list. The date range and the test-click exclusion sit in the clicks JOIN condition, not in WHERE, for the same reason.

**Test traffic (v1.10.0)** is excluded in one place: `c.is_test = 0` on the clicks JOIN in `Campaign_List_Table::build_from_where()`, which the main view, the totals and the CSV query all share. Test clicks drop out of the JOIN, and their conversions with them, because conversions are joined through `cv.click_id = c.id`. A tracking URL that only has test clicks shows zero clicks. Flagging a click row as a test (`is_test = 1`) is therefore enough to remove the click and its conversions from all three. See [click-handling.md](click-handling.md#test-clicks).

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

## Conversions

Below the CSV export button (publish view only), the admin page lists the recorded conversions in a **Conversions** section, rendered by `Conversion_List_Table` (a `WP_List_Table` subclass, v1.10.0). It is where a conversion that should not count, such as a test made with a real ad click, is deleted or marked as a test.

**What a row is.** One row per conversion. A conversion is the set of conversion rows that share a `conversion_ref`: one row per credited click, so several under a multi-touch attribution model (see [conversion-handling.md](conversion-handling.md#database-write)). The per-click columns show one line per attributed click, highest weight first. A conversion recorded before 1.10.0 has its row ID as reference and is listed as a conversion of its own, even if a multi-touch model wrote it as several rows.

**Selection and order.** The list follows the page's date range (`date_start`/`date_end`, as validated by `Campaign_List_Table::get_filter_params()`) and applies it to the attributed click's `clicked_at`, the same selection the main view counts conversions by. A conversion is listed when any of its attributed clicks falls in the range, and it is then shown whole. The source, medium, campaign and search filters do not apply. Test conversions are listed, flagged, although the main view leaves them out; conversions whose tracking URL has since been trashed or deleted are listed too. Newest first by `converted_at`, then by row ID.

**Pagination.** 20 conversions per page. The table pages with its own query argument `conversions_paged` (`Conversion_List_Table::PAGE_ARG`), because the campaign table on the same screen uses `paged`. Its page links keep the rest of the current URL and drop the notice arguments of a finished action.

**Columns:**

| Column | Content |
|--------|---------|
| Checkbox | `conversion[]` with the conversion reference |
| Converted | `converted_at` (`Y-m-d H:i` in site timezone). Row actions below it. |
| Tracking URL | `source / medium / campaign` of each attributed click's tracking URL, or *(deleted)* |
| Clicked | Each attributed click's `clicked_at` |
| Content, Term | Each attributed click's `utm_content` and `utm_term`, or `—` |
| Weight | Each attribution's `fractional_conversion`, two decimals |
| Test | Each attributed click's test flag: Yes or No |
| Report Jobs | The conversion's report jobs per status, e.g. "1 pending, 2 done", or `—` |

**Row actions:** **Mark as test** (or **Unmark as test** for a test conversion, i.e. one with any test click) and **Delete**.

**Bulk actions:** Mark as test, Unmark as test, Delete.

### Actions

| Action | Effect on conversion rows | Effect on clicks | Effect on report jobs |
|--------|---------------------------|------------------|-----------------------|
| Delete (`conversion_delete`) | Deleted | Kept | `pending` and `failed` deleted |
| Mark as test (`conversion_mark_test`) | Kept | Attributed click rows get `is_test = 1` | `pending` and `failed` deleted |
| Unmark as test (`conversion_unmark_test`) | Kept | Attributed click rows get `is_test = 0` | Untouched |

- **Statistics.** Delete lowers the main view's conversions by the conversion's weight. Mark as test removes the attributed clicks and, through them, the conversion from the main view, its totals and the CSV export: the exclusion is the single `c.is_test = 0` condition described above. Unmark restores them.
- **Shared clicks.** The test flag lives on the click row, so marking a conversion as a test also takes any other conversion linked to the same click out of the statistics, for example a second form submission by the same visitor after the same ad click, and unmarking puts it back. Such a conversion is then listed with Unmark too. Its report jobs are not deleted; mark it itself to delete them.
- **Report jobs.** A job knows its conversion through `queue.conversion_ref` (see [architecture.md](architecture.md#report-queue--custom-table)). `pending` and `failed` jobs have not reached the platform and are deleted. A `processing` job may be talking to the platform right now; it is left alone and the notice says so. `done` jobs have already been sent and stay, shown in the Report Jobs column; retracting a conversion at the platform is up to the reporter's add-on. Jobs created before 1.10.0 have no reference and are never touched. Unmark does not recreate deleted jobs.

**Routing.** Row actions are GET links `tools.php?page=kntnt-ad-attribution&action=<action>&conversion=<ref>&date_start=…&date_end=…&_wpnonce=…`. Bulk actions submit the list's own GET form, with `action`/`action2` from the bulk select and `conversion[]`. `Admin_Page::handle_form_submission()` routes the three action slugs to `handle_conversion_action()` **before** the tracking-URL actions; neither the slugs nor `conversion[]` overlap with the tracking URLs' `trash`/`restore`/`delete` and `post[]`.

**Security.** Each request needs a valid nonce (row action: `{action}_{ref}`, e.g. `conversion_delete_<ref>`; bulk action: `bulk-conversions`, emitted by `WP_List_Table`) checked with `check_admin_referer()`, and the `kntnt_ad_attr` capability checked with `Plugin::authorize()`. A failed nonce ends in WordPress's 403 "link expired" page, a missing capability in a 403 `wp_die()`. References are kept only if they are up to 36 letters, digits and hyphens; all SQL is prepared.

**Notice.** The handler redirects back to the page (with the same date range) with `message=conversions-deleted|conversions-marked|conversions-unmarked`, `count` (conversions found), `jobs_deleted` and `jobs_processing`. The success notice reads e.g. "1 conversion(s) deleted. 2 unfinished report job(s) deleted."; when `jobs_processing` is above zero, a warning adds "N report job(s) still processing were left untouched. They may still reach the ad platform."

### SQL (Conversions)

```sql
-- Page of conversions (count: COUNT(DISTINCT cv.conversion_ref) with the same FROM/WHERE)
SELECT cv.conversion_ref, MAX(cv.converted_at) AS converted_at, MAX(cv.id) AS last_row
FROM {prefix}kntnt_ad_attr_conversions cv
INNER JOIN {prefix}kntnt_ad_attr_clicks c ON c.id = cv.click_id
WHERE c.clicked_at BETWEEN %s AND %s
GROUP BY cv.conversion_ref
ORDER BY converted_at DESC, last_row DESC
LIMIT %d OFFSET %d

-- All rows of the page's conversions, with click and tracking-URL fields
SELECT cv.conversion_ref, cv.fractional_conversion,
       c.clicked_at, c.utm_content, c.utm_term, c.is_test,
       pm_src.meta_value AS utm_source, pm_med.meta_value AS utm_medium, pm_camp.meta_value AS utm_campaign
FROM {prefix}kntnt_ad_attr_conversions cv
INNER JOIN {prefix}kntnt_ad_attr_clicks c ON c.id = cv.click_id
LEFT JOIN {wpdb->postmeta} pm_hash ON pm_hash.meta_key = '_hash' AND pm_hash.meta_value = c.hash
LEFT JOIN {wpdb->postmeta} pm_src  ON pm_src.post_id  = pm_hash.post_id AND pm_src.meta_key  = '_utm_source'
LEFT JOIN {wpdb->postmeta} pm_med  ON pm_med.post_id  = pm_hash.post_id AND pm_med.meta_key  = '_utm_medium'
LEFT JOIN {wpdb->postmeta} pm_camp ON pm_camp.post_id = pm_hash.post_id AND pm_camp.meta_key = '_utm_campaign'
WHERE cv.conversion_ref IN (%s, …)
ORDER BY cv.fractional_conversion DESC, c.clicked_at DESC

-- Report jobs per status (Queue::count_jobs_for_conversions)
SELECT conversion_ref, status, COUNT(*) AS cnt
FROM {prefix}kntnt_ad_attr_queue
WHERE conversion_ref IN (%s, …)
GROUP BY conversion_ref, status

-- Delete
DELETE FROM {prefix}kntnt_ad_attr_conversions WHERE conversion_ref IN (%s, …)

-- Mark as test (1) / Unmark (0)
UPDATE {prefix}kntnt_ad_attr_clicks SET is_test = %d
WHERE id IN (SELECT click_id FROM {prefix}kntnt_ad_attr_conversions WHERE conversion_ref IN (%s, …))

-- Unfinished jobs (Queue::delete_unfinished_for_conversions), then the processing count
DELETE FROM {prefix}kntnt_ad_attr_queue WHERE conversion_ref IN (%s, …) AND status IN ('pending', 'failed')
SELECT COUNT(*) FROM {prefix}kntnt_ad_attr_queue WHERE conversion_ref IN (%s, …) AND status = 'processing'
```

The writes use subqueries rather than multi-table `… JOIN` statements, so they run unchanged in the SQLite translator used by the integration tests.

## Report Queue

Below the conversion list, the admin page displays a **Report Queue** section (rendered by `Queue_List_Table`, a `WP_List_Table` subclass). This section is always present but shows "No jobs in the queue." when empty. It displays active and recently completed queue jobs.

**Columns:**

| Column | Content |
|--------|---------|
| Reporter | Reporter ID. Row actions: "Run Now" (hidden for done jobs) and "Delete". |
| Description | Human-readable label set by the reporter's `enqueue` callback. |
| Created | Job creation timestamp (`Y-m-d H:i` in site timezone). |
| Last Attempt | Timestamp of the most recent processing attempt, or `—` if never attempted. |
| Status | Human-readable status message (see below). |

**Status messages:**

| State | Display |
|-------|---------|
| `done` | "Success after N attempts" |
| `failed` | "Failed after N attempts" (red) |
| `processing` | "Attempt N running" |
| `pending`, no attempts | "Pending" |
| `pending`, future `retry_after` | "Attempt N at [date time]" |
| `pending`, eligible | "Attempt N ready" |

**Row actions:**

- **Run Now** — calls `Queue_Processor::process_single()` to process the job immediately, bypassing both `retry_after` and `not_before`. Hidden for jobs with status `done`.
- **Delete** — calls `Queue::delete()` to remove the job from the queue.

## Logging

Below the report queue, the admin page displays a **Logging** section with controls for the shared diagnostic log.

**Controls:**

- **Enable/disable toggle** — checkbox that toggles the `enable_logging` setting. Submits via POST with action `toggle_logging` and nonce verification.
- **Log file path** — displays the relative path to the log file (`wp-content/uploads/kntnt-ad-attribution/kntnt-ad-attribution.log`).
- **File size** — shown when the log file exists.
- **Download Log** — nonce-protected link to download the log file. Disabled when no log file exists.
- **Clear Log** — nonce-protected link to delete the log file. Disabled when no log file exists.
