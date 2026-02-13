# Conversion Handling

Conversions are triggered via the action hook `kntnt_ad_attribution_conversion` from the form plugin's submission handler.

## Flow

```
1. Read the _ad_last_conv cookie
2. If the cookie exists and is younger than dedup_days → ignore, exit
3. Read the _ad_clicks cookie
4. Validate and extract hash:timestamp pairs
5. Filter out hashes that do not exist as published tracking URLs (CPT with post_status = publish)
6. If no valid hashes remain → exit (no attribution, _ad_last_conv is NOT set)
7. Calculate attribution weights
8. Write fractional conversions to the database (in a transaction)
9. Set/update the _ad_last_conv cookie
10. Trigger kntnt_ad_attribution_conversion_recorded
```

## Deduplication

If `_ad_last_conv` exists and the timestamp is younger than `kntnt_ad_attribution_dedup_days` (default 30 days), the conversion is ignored. The dedup period is automatically capped to the cookie lifetime:

```php
$lifetime   = apply_filters( 'kntnt_ad_attribution_cookie_lifetime', 90 );
$dedup_days = apply_filters( 'kntnt_ad_attribution_dedup_days', 30 );
$dedup_days = min( $dedup_days, $lifetime );
```

## Attribution Logic

Fractional, time-weighted attribution. More recent clicks receive more weight.

```
N   = apply_filters( 'kntnt_ad_attribution_cookie_lifetime', 90 )
d_i = number of days since the hash's timestamp
w_i = max( N − d_i, 1 )
a_i = w_i / Σ w_j
```

Each hash is assigned the fractional value `a_i` that sums to 1.

## Database Write

The conversion write is wrapped in a transaction:

```php
$wpdb->query( 'START TRANSACTION' );

foreach ( $attributions as $hash => $value ) {
    $result = $wpdb->query( $wpdb->prepare(
        "INSERT INTO {$table} (hash, date, clicks, conversions)
         VALUES (%s, %s, 0, %f)
         ON DUPLICATE KEY UPDATE conversions = conversions + %f",
        $hash, gmdate( 'Y-m-d' ), $value, $value
    ) );
    if ( $result === false ) {
        $wpdb->query( 'ROLLBACK' );
        error_log( '[Kntnt Ad Attribution] Conversion write failed, rolled back.' );
        return;
    }
}

$wpdb->query( 'COMMIT' );
```
