# Conversion Handling

Conversions are triggered via the action hook `kntnt_ad_attr_conversion` from the form plugin's submission handler.

## Flow

```
0. Bot check — reject automated requests (same Bot_Detector used by Click_Handler)
0b. Consent check — reading the _ad_clicks cookie requires confirmed consent (true). Both denied (false) and undetermined (null) abort — no attribution possible without cookie access.
1. Read the _ad_clicks cookie
2. Validate and extract hash:timestamp pairs
3. Filter out hashes that do not exist as published tracking URLs (CPT with post_status = publish)
4. If no valid hashes remain → exit (no attribution)
4b. Internal traffic (kntnt_ad_attr_is_internal): keep only entries whose exact (hash, clicked_at) click row is a test click. If none remain → log and exit before writing anything (no conversion row, no recorded action, no reporters)
5. Per-hash deduplication (only when kntnt_ad_attr_dedup_seconds > 0):
   a. Read _ad_last_conv cookie (hash:timestamp pairs)
   b. Remove hashes whose last conversion is within the dedup window
   c. If no hashes remain → exit
6. Apply attribution model (default: last-click) via kntnt_ad_attr_attribution filter
7. Look up click records and write conversion rows to the database (in a transaction). The hashes that got a row, each with the weight written, are the recorded attributions.
7b. If no conversion row was written → exit (no dedup cookie, no recorded action, no reporters)
8. Write per-hash dedup cookie for the recorded hashes (only when dedup is enabled)
8b. Test flag: the conversion is a test if any linked click row has is_test = 1
9. Trigger kntnt_ad_attr_conversion_recorded with the recorded attributions and context (timestamp, IP, user-agent, test)
10. Look up the click IDs and per-click campaign fields of the click row each recorded hash is linked to
11. Call registered reporters' enqueue callbacks with the recorded attributions, insert payloads into queue. A test conversion goes only to reporters with 'supports_test' => true
12. Schedule queue processing
13. Log a test conversion with its click rows and the reporters it was passed to
```

## Deduplication

Per-hash deduplication is controlled by the `kntnt_ad_attr_dedup_seconds` filter (default: `0`, meaning deduplication is disabled). When enabled, each hash is independently checked against its last conversion timestamp in the `_ad_last_conv` cookie. A conversion for hash A does not block a conversion for hash B.

The dedup period is automatically capped to the cookie lifetime:

```php
$lifetime      = (int) apply_filters( 'kntnt_ad_attr_cookie_lifetime', 90 );
$dedup_seconds = (int) apply_filters( 'kntnt_ad_attr_dedup_seconds', 0 );
$dedup_seconds = min( $dedup_seconds, $lifetime * DAY_IN_SECONDS );
```

When `$dedup_seconds` is 0 (default), the `_ad_last_conv` cookie is neither read nor written, and all conversions are recorded regardless of previous conversions for the same hash.

When `$dedup_seconds > 0`, after a successful conversion, the recorded hashes are merged into the `_ad_last_conv` cookie with the current timestamp. On the next conversion, hashes whose `_ad_last_conv` timestamp is within the dedup window are skipped.

## Attribution Logic

Filterable last-click attribution. By default, the most recent click receives full credit (1.0), all others receive 0.0.

```php
$latest_hash = array_keys( $valid_entries, max( $valid_entries ) )[0];
$attributions = array_fill_keys( array_keys( $valid_entries ), 0.0 );
$attributions[ $latest_hash ] = 1.0;

$attributions = apply_filters( 'kntnt_ad_attr_attribution', $attributions, $clicks );
```

The `kntnt_ad_attr_attribution` filter receives `$attributions` (hash => fractional value) and `$clicks` (array of `['hash' => string, 'clicked_at' => int]`). Custom attribution models (time-weighted, linear, position-based) can be implemented via this filter.

## Database Write

The handler looks up the click record whose hash and `clicked_at` exactly match each cookie entry, then inserts conversion rows. The write is wrapped in a transaction.

The match is exact on purpose, with no fallback to the nearest earlier click row: the clicks table is shared by everyone who clicks the tracking URL, so the nearest earlier row is often another visitor's click. The click handler keeps the matching row in place by never writing an `_ad_clicks` entry without one (see [Click Deduplication](click-handling.md#click-deduplication)). If an entry still has no matching row, for example a cookie written by a version before 1.10.0, that hash is skipped and one warning is written through `Logger` (prefix `CORE`) with the first eight characters of the hash and the entry's timestamp. The IP address and user agent are not logged.

```php
$wpdb->query( 'START TRANSACTION' );

$linked_clicks = [];

foreach ( $attributions as $hash => $value ) {
    if ( $value <= 0 ) {
        continue;
    }

    $click_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$clicks_table} WHERE hash = %s AND clicked_at = %s LIMIT 1",
        $hash,
        gmdate( 'Y-m-d H:i:s', $valid_entries[ $hash ] ),
    ) );

    if ( ! $click_id ) {
        $this->logger?->warning( 'CORE', sprintf(
            'Conversion not recorded for hash %s...: no click row matches cookie timestamp %d (%s UTC).',
            substr( $hash, 0, 8 ),
            $valid_entries[ $hash ],
            gmdate( 'Y-m-d H:i:s', $valid_entries[ $hash ] ),
        ) );
        continue;
    }

    $result = $wpdb->insert( $conv_table, [
        'click_id'              => (int) $click_id,
        'converted_at'          => $converted_at,
        'fractional_conversion' => $value,
    ] );

    if ( $result === false ) {
        $wpdb->query( 'ROLLBACK' );
        error_log( '[Kntnt Ad Attribution] Conversion write failed, rolled back.' );
        return;
    }

    $linked_clicks[ $hash ] = (int) $click_id;
}

$wpdb->query( 'COMMIT' );

$recorded = array_intersect_key( $attributions, $linked_clicks );
if ( $recorded === [] ) {
    return;
}
```

## Recorded Attributions

A hash gets a conversion row only when its weight is above 0 and a click row matches its cookie timestamp. `$linked_clicks` maps each such hash to the ID of the click row its conversion row links to. `$recorded` holds the same hashes with the weight written.

Everything after the write uses `$recorded`, not `$attributions`: the dedup cookie, the `kntnt_ad_attr_conversion_recorded` action, and the reporters' `enqueue` callbacks with their click ID and campaign lookups. Weights pass on as written and are not renormalised. Under a multi-touch model they can therefore sum to less than 1.0 when a click row was missing. When no row was written, the handler returns before any of these steps.

## Internal Traffic and Test Conversions

The test flag lives on the click row (`kntnt_ad_attr_clicks.is_test`, set for a [test click](click-handling.md#test-clicks)). A conversion has no flag of its own: it inherits the flag through the click rows it links to.

**Internal traffic.** When `kntnt_ad_attr_is_internal` is true (by default, a logged-in user with `kntnt_ad_attr`, recognised by the logged-in cookie even in a REST request without a nonce), the handler keeps only the `_ad_clicks` entries whose exact `(hash, clicked_at)` row is a test click, in one query on the clicks table, before deduplication and attribution. Staff testing a form with an ordinary click in their cookie therefore record nothing; with a test click they record a test conversion credited to the test click only. If no entry remains, the handler logs `Internal conversion ignored: …` and returns before writing anything, firing the action or calling reporters.

**Test conversions.** After the write, one query checks the linked click rows (`$linked_clicks`). If any recorded attribution links to a test click, the whole conversion is a test:

- The conversion rows are written as usual; the main view, its totals and the CSV export leave them out because they exclude test clicks (see [admin-ui.md](admin-ui.md)).
- `kntnt_ad_attr_conversion_recorded` receives `$context['test'] = true`. For other conversions it is `false`; the key is always present.
- Only reporters whose definition declares `'supports_test' => true` are called, with `$context['test'] = true`, so that they can use the platform's validate-only mode. A reporter without the key never sees a test conversion. Reporters of an ordinary conversion all receive `$context['test'] = false`.
- With logging enabled, `Test conversion recorded for hash <8 chars>... (click row N); passed to reporters with test support: <ids or none>.` is written.

## Conversion Reporting

After the `kntnt_ad_attr_conversion_recorded` action fires, the handler checks for registered reporters via `apply_filters('kntnt_ad_attr_conversion_reporters', [])`, keeping only those with `'supports_test' => true` for a test conversion. If reporters remain:

1. **Look up click IDs** — calls `Click_ID_Store::get_for_clicks( $linked_clicks )` to retrieve the platform-specific click IDs captured on the click row each recorded hash is linked to. Returns structured arrays: `[ hash => [ platform => ['id' => string, 'captured_at' => int] ] ]`, where `captured_at` is that click's `clicked_at`. A hash whose linked click carried no ID for a platform has no entry for that platform, even if a later click on the same tracking URL carried one.
2. **Look up campaign data** — calls `get_campaign_data( $linked_clicks )` to retrieve Source/Medium/Campaign from the tracking URL's postmeta and Content/Term/Id/Group from the linked click row, for the recorded hashes only.

Both lookups use the exact click the conversion was credited for, so a reporter never receives another visitor's click ID or per-click fields, provided the visitor's `_ad_clicks` entry points at the visitor's own click.
3. **Build context** — assembles timestamp, IP, user-agent, page URL and the test flag (`test`).
4. **Call each reporter's `enqueue` callback** — passes the recorded attributions, `$click_ids`, `$campaigns`, and `$context`. Each reporter returns an array of structured items (with `payload`, optional `label`, optional `retry_params`, and optional `not_before` keys) or raw payload arrays (legacy format).
5. **Enqueue items** — each item's payload is JSON-encoded and inserted into the `kntnt_ad_attr_queue` table with `status = 'pending'`, along with optional `label`, per-job retry parameters, and optional `not_before` timestamp for deferred processing.
6. **Schedule processing** — calls `Queue_Processor::schedule()` to trigger a cron event.

If no reporters are registered (default), the filter returns `[]`, the `! empty()` check exits immediately, and no database queries are made against the click_ids or queue tables.

## Queue Processing

Queue jobs are processed by `Queue_Processor::process()`, triggered by the `kntnt_ad_attr_process_queue` cron hook.

1. Fetches reporters via `kntnt_ad_attr_conversion_reporters` filter.
2. Dequeues up to 10 pending jobs that are eligible (both `retry_after` and `not_before` have passed or are NULL). Atomically updates their status to `processing` and sets `last_attempt_at`.
3. For each job, finds the matching reporter by `$item->reporter` key and calls its `process` callback with the decoded payload.
4. On success (`true`): marks the job as `done`, increments attempts, sets `last_attempt_at`. Note: `true` means "this job is complete — do not retry". Reporters should return `true` both for successful uploads and for **permanent failures** that will never succeed on retry (e.g. the external service rejected the data as a duplicate or invalid). The queue has no "abandoned" status, so permanent failures are recorded as `done`.
5. On failure (`false` or exception): increments the attempt counter, sets `last_attempt_at`. Retry logic is configurable per job via `attempts_per_round`, `retry_delay`, `max_rounds`, and `round_delay` (with global defaults from `Settings`). When all rounds are exhausted, the job is marked as `failed` with the error message. Otherwise, it returns to `pending` with a `retry_after` timestamp. Reporters should return `false` only for **transient/retryable** failures (e.g. network timeout, rate limit, temporary API error).
6. If pending jobs remain after processing, schedules another cron run. Smart scheduling considers both `retry_after` and `not_before` to determine the optimal time.

Individual jobs can be run immediately via `Queue_Processor::process_single()` or deleted via `Queue::delete()`, both accessible from the admin queue management UI.
