# Conversion Handling

Conversions are triggered via the action hook `kntnt_ad_attr_conversion` from the form plugin's submission handler.

## Flow

```
1. Read the _ad_last_conv cookie
2. If the cookie exists and is younger than dedup_days → ignore, exit
3. Read the _ad_clicks cookie
4. Validate and extract hash:timestamp pairs
5. Filter out hashes that do not exist as published tracking URLs (CPT with post_status = publish)
6. If no valid hashes remain → exit (no attribution, _ad_last_conv is NOT set)
7. Apply attribution model (default: last-click) via kntnt_ad_attr_attribution filter
8. Look up click records and write conversion rows to the database (in a transaction)
9. Set/update the _ad_last_conv cookie
10. Trigger kntnt_ad_attr_conversion_recorded with attributions and context (timestamp, IP, user-agent)
11. Look up click IDs and campaign data for attributed hashes
12. Call registered reporters' enqueue callbacks, insert payloads into queue
13. Schedule queue processing
```

## Deduplication

If `_ad_last_conv` exists and the timestamp is younger than `kntnt_ad_attr_dedup_days` (default 30 days), the conversion is ignored. The dedup period is automatically capped to the cookie lifetime:

```php
$lifetime   = apply_filters( 'kntnt_ad_attr_cookie_lifetime', 90 );
$dedup_days = apply_filters( 'kntnt_ad_attr_dedup_days', 30 );
$dedup_days = min( $dedup_days, $lifetime );
```

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

The handler looks up click records matching the cookie timestamps, then inserts conversion rows. The write is wrapped in a transaction:

```php
$wpdb->query( 'START TRANSACTION' );

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
}

$wpdb->query( 'COMMIT' );
```

## Conversion Reporting

After the `kntnt_ad_attr_conversion_recorded` action fires, the handler checks for registered reporters via `apply_filters('kntnt_ad_attr_conversion_reporters', [])`. If reporters are registered:

1. **Look up click IDs** — calls `Click_ID_Store::get_for_hashes()` to retrieve platform-specific click IDs for all attributed hashes.
2. **Look up campaign data** — calls `get_campaign_data()` to retrieve Source/Medium/Campaign from postmeta and Content/Term/Id/Group from the clicks table for all attributed hashes.
3. **Build context** — assembles timestamp, IP, user-agent, and page URL.
4. **Call each reporter's `enqueue` callback** — passes `$attributions`, `$click_ids`, `$campaigns`, and `$context`. Each reporter returns an array of payloads.
5. **Enqueue payloads** — each payload is JSON-encoded and inserted into the `kntnt_ad_attr_queue` table with `status = 'pending'`.
6. **Schedule processing** — calls `Queue_Processor::schedule()` to trigger a cron event.

If no reporters are registered (default), the filter returns `[]`, the `! empty()` check exits immediately, and no database queries are made against the click_ids or queue tables.

## Queue Processing

Queue jobs are processed by `Queue_Processor::process()`, triggered by the `kntnt_ad_attr_process_queue` cron hook.

1. Fetches reporters via `kntnt_ad_attr_conversion_reporters` filter.
2. Dequeues up to 10 pending jobs (atomically updated to `processing` status).
3. For each job, finds the matching reporter by `$item->reporter` key and calls its `process` callback with the decoded payload.
4. On success (`true`): marks the job as `done`.
5. On failure (`false` or exception): increments the attempt counter. After 3 failed attempts, the job is marked as `failed` with the error message. Otherwise, it returns to `pending` for retry.
6. If pending jobs remain after processing, schedules another cron run.
