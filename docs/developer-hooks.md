# Developer Hooks

## Filters

**`kntnt_ad_attr_has_consent`**

Checks whether consent exists. Return `true` (yes), `false` (no), or `null` (undefined). Default: fallback to `kntnt_ad_attr_default_consent`.

Implementation logic in the `Consent` class:

```php
public function check(): ?bool {
    if ( ! has_filter( 'kntnt_ad_attr_has_consent' ) ) {
        // No consent plugin registered — use default
        return apply_filters( 'kntnt_ad_attr_default_consent', true );
    }

    // Consent plugin registered — query it
    // The filter MUST return true, false, or null
    return apply_filters( 'kntnt_ad_attr_has_consent', null );
}
```

Note: `has_filter()` checks if at least one callback is registered. If not, the plugin falls back to `default_consent` (which defaults to `true`). If yes, the filter is called with `null` as the initial value — the callback should return `true`, `false`, or `null`.

**`kntnt_ad_attr_default_consent`**

Fallback when no callback is registered on `has_consent`. Default: `true`. This means that sites without a consent plugin treat all visitors as having consented, which is correct if the site has no consent requirements.

**`kntnt_ad_attr_redirect_method`**

Redirect method: `'302'` (default) or `'js'`.

**`kntnt_ad_attr_url_prefix`**

URL prefix for tracking URLs. Default: `'ad'`.

**`kntnt_ad_attr_cookie_lifetime`**

Cookie lifetime in days. Default: `90`. Affects the `_ad_clicks` cookie and the attribution formula's N value.

**`kntnt_ad_attr_dedup_seconds`**

Per-hash deduplication window in seconds. Default: `0` (deduplication disabled). When non-zero, each hash is independently checked against its last conversion timestamp. Automatically capped to `cookie_lifetime × DAY_IN_SECONDS`.

**`kntnt_ad_attr_pending_transport`**

Transport mechanism for undefined consent: `'cookie'` (default) or `'fragment'`.

**`kntnt_ad_attr_is_bot`**

Bot detection. Default: `false`. The plugin registers its own callback with User-Agent matching. The developer can supplement or replace it.

**`kntnt_ad_attr_attribution`**

Filters the attribution weights for a conversion. Default: last-click (1.0 for most recent click, 0.0 for all others). Must return an array where values sum to 1.0.

Parameters:

| Parameter | Type | Description |
|-----------|------|-------------|
| `$attributions` | `array<string, float>` | Hash => fractional value. |
| `$clicks` | `array<int, array{hash: string, clicked_at: int}>` | Click data with timestamps. |

```php
add_filter( 'kntnt_ad_attr_attribution', function ( array $attributions, array $clicks ): array {
    // Custom attribution logic.
    return $attributions;
}, 10, 2 );
```

**`kntnt_ad_attr_click_retention_days`**

Number of days to retain click records. Default: `365`. Clicks older than this are deleted by the daily cron job along with their linked conversions.

```php
add_filter( 'kntnt_ad_attr_click_retention_days', fn() => 180 );
```

**`kntnt_ad_attr_utm_options`**

Predefined UTM source and medium options for the admin form dropdowns. Sources map to a default medium that is auto-filled client-side. Filterable to add or remove options.

```php
add_filter( 'kntnt_ad_attr_utm_options', function ( array $options ): array {
    $options['sources']['snapchat'] = 'paid-social';
    $options['mediums'][] = 'native';
    return $options;
} );
```

**`kntnt_ad_attr_admin_tabs`**

Filters the admin page tab list. The core no longer uses tabs (the admin page is a single merged view), but add-on plugins can still register custom views by appending slug → label entries. When `?tab=<slug>` is passed, the `kntnt_ad_attr_admin_tab_{$tab}` action fires for rendering.

```php
add_filter( 'kntnt_ad_attr_admin_tabs', function ( array $tabs ): array {
    $tabs['settings'] = __( 'Settings', 'my-addon' );
    return $tabs;
} );
```

**`kntnt_ad_attr_redirect_query_params`**

Filters the merged query parameters before building the redirect URL. When a visitor clicks a tracking URL with extra query parameters (e.g. `/ad/<hash>?gclid=abc&utm_term=x`), these are forwarded to the target page. Target URL parameters take precedence over incoming ones.

Parameters:

| Parameter | Type | Description |
|-----------|------|-------------|
| `$merged_params` | `array<string, string>` | Merged parameters (target wins on collision). |
| `$target_params` | `array<string, string>` | Parameters already present on the target URL. |
| `$incoming_params` | `array<string, string>` | Sanitized parameters from the incoming request (excluding `kntnt_ad_attr_hash`). |

```php
add_filter( 'kntnt_ad_attr_redirect_query_params', function ( array $merged, array $target, array $incoming ): array {
    // Prevent internal tracking parameters from leaking to the landing page.
    unset( $merged['fbclid'], $merged['gclid'] );
    return $merged;
}, 10, 3 );
```

**`kntnt_ad_attr_click_id_capturers`**

Registers platform-specific GET parameters that the core captures and stores at ad click time. Return an associative array mapping platform identifiers to GET parameter names. Default: `[]` (no click IDs captured).

```php
add_filter( 'kntnt_ad_attr_click_id_capturers', function ( array $capturers ): array {
    $capturers['google_ads'] = 'gclid';
    return $capturers;
} );
```

Multiple capturers can be registered by different plugins:

```php
add_filter( 'kntnt_ad_attr_click_id_capturers', function ( array $capturers ): array {
    $capturers['meta'] = 'fbclid';
    return $capturers;
} );
```

The core iterates the returned array, reads each GET parameter, sanitizes it with `sanitize_text_field()`, validates length (max 255), and stores it in the `kntnt_ad_attr_click_ids` table. Capture happens before the `kntnt_ad_attr_click` action fires.

**`kntnt_ad_attr_conversion_reporters`**

Registers conversion reporters whose `enqueue` callbacks are called at conversion time and whose `process` callbacks are called by the queue processor. Default: `[]` (no reporters).

Each reporter definition is an associative array with three keys:

| Key | Type | Description |
|-----|------|-------------|
| `label` | `string` | Name for logging and admin UI. |
| `enqueue` | `callable` | Called at conversion time. Signature: `( array $attributions, array $click_ids, array $campaigns, array $context ) → array`. Returns array of payloads. |
| `process` | `callable` | Called by queue processor. Signature: `( array $payload ) → bool`. Returns `true` on success, `false` on failure. |

`enqueue` callback parameters:

- `$attributions`: `[ hash => fractional_value, … ]` — sums to 1.0.
- `$click_ids`: `[ hash => [ platform => click_id, … ], … ]` — may be empty for a given hash.
- `$campaigns`: `[ hash => [ 'utm_source' => …, 'utm_medium' => …, 'utm_campaign' => …, 'utm_content' => …, 'utm_term' => …, 'utm_id' => …, 'utm_source_platform' => … ], … ]`.
- `$context`: `[ 'timestamp' => ISO-8601, 'ip' => string, 'user_agent' => string, 'page_url' => string ]`.

```php
add_filter( 'kntnt_ad_attr_conversion_reporters', function ( array $reporters ): array {
    $reporters['my_platform'] = [
        'label'   => 'My Platform',
        'enqueue' => function ( array $attributions, array $click_ids, array $campaigns, array $context ): array {
            $payloads = [];
            foreach ( $attributions as $hash => $value ) {
                $click_id = $click_ids[ $hash ]['my_platform'] ?? '';
                if ( $click_id === '' ) {
                    continue;
                }
                $payloads[] = [
                    'click_id'    => $click_id,
                    'value'       => $value,
                    'campaign'    => $campaigns[ $hash ]['utm_campaign'] ?? '',
                    'timestamp'   => $context['timestamp'],
                ];
            }
            return $payloads;
        },
        'process' => function ( array $payload ): bool {
            // Call external API with $payload.
            return true;
        },
    ];
    return $reporters;
} );
```

## Actions

**`kntnt_ad_attr_admin_tab_{$tab}`**

Fires when the admin page renders an unrecognized tab slug. Add-on plugins that register a custom tab via `kntnt_ad_attr_admin_tabs` must hook into this action to render the tab's content.

```php
add_action( 'kntnt_ad_attr_admin_tab_settings', function (): void {
    echo '<h2>' . esc_html__( 'Settings', 'my-addon' ) . '</h2>';
    // Render settings form.
} );
```

**`kntnt_ad_attr_click`**

Fires after a click on a tracking URL has been logged but before consent handling and redirect. Fires for all non-bot clicks regardless of consent state. Companion plugins can use this to capture platform-specific URL parameters (e.g. `gclid`, `fbclid`, `msclkid`).

Parameters:

| Parameter | Type | Description |
|-----------|------|-------------|
| `$hash` | `string` | SHA-256 hash of the clicked tracking URL. |
| `$target_url` | `string` | The resolved target URL the visitor will be redirected to. |
| `$campaign_data` | `array` | Associative array with keys: `post_id`, `utm_source`, `utm_medium`, `utm_campaign`. |

The `$_GET` superglobal is available to callbacks and contains any URL parameters appended to the tracking URL (e.g. `$_GET['gclid']`). Callbacks must sanitize all superglobal values.

```php
add_action( 'kntnt_ad_attr_click', function ( string $hash, string $target_url, array $campaign_data ): void {
    $gclid = sanitize_text_field( $_GET['gclid'] ?? '' );
    if ( $gclid !== '' ) {
        // Store gclid linked to the hash for later conversion reporting.
    }
}, 10, 3 );
```

**Performance:** Callbacks on this hook execute during a redirect request. The server must finish before the browser follows the redirect. Keep processing minimal or use `wp_schedule_single_event()` for any heavy work.

**`kntnt_ad_attr_conversion`**

Trigger to record a conversion. Connect your form plugin to this hook.

```php
do_action( 'kntnt_ad_attr_conversion' );
```

**Example with WS Form:** Add a WordPress action hook in the form's Actions tab with hook name `kntnt_ad_attr_conversion`.

**Example with Contact Form 7:**

```php
add_action( 'wpcf7_mail_sent', function( $contact_form ) {
    if ( $contact_form->id() === 42 ) {
        do_action( 'kntnt_ad_attr_conversion' );
    }
} );
```

**Example with Gravity Forms:**

```php
add_action( 'gform_after_submission', function( $entry, $form ) {
    if ( in_array( $form['id'], [5, 12], true ) ) {
        do_action( 'kntnt_ad_attr_conversion' );
    }
}, 10, 2 );
```

**`kntnt_ad_attr_conversion_recorded`**

Fires after a conversion has been successfully recorded. Receives the array of attributed hashes with their fractional values and a context array with metadata.

Parameters:

| Parameter | Type | Description |
|-----------|------|-------------|
| `$attributions` | `array<string, float>` | Hash => fractional attribution value (sums to 1.0). |
| `$context` | `array` | Associative array with keys: `timestamp` (ISO 8601 UTC), `ip` (visitor IP), `user_agent` (visitor user-agent string), `page_url` (page where conversion occurred). |

```php
add_action( 'kntnt_ad_attr_conversion_recorded', function ( array $attributions, array $context ): void {
    // $attributions = [ 'a1b2c3…' => 0.7, 'd4e5f6…' => 0.3 ]
    // $context = [ 'timestamp' => '2025-02-15T14:30:00+00:00', 'ip' => '…', 'user_agent' => '…', 'page_url' => '…' ]
}, 10, 2 );
```
