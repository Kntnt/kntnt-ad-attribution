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

**`kntnt_ad_attr_dedup_days`**

Deduplication window in days. Default: `30`. Automatically capped to max `cookie_lifetime`.

**`kntnt_ad_attr_pending_transport`**

Transport mechanism for undefined consent: `'cookie'` (default) or `'fragment'`.

**`kntnt_ad_attr_is_bot`**

Bot detection. Default: `false`. The plugin registers its own callback with User-Agent matching. The developer can supplement or replace it.

## Actions

**`kntnt_ad_attr_click`**

Fires after a click on a tracking URL has been logged but before consent handling and redirect. Fires for all non-bot clicks regardless of consent state. Companion plugins can use this to capture platform-specific URL parameters (e.g. `gclid`, `fbclid`, `msclkid`).

Parameters:

| Parameter | Type | Description |
|-----------|------|-------------|
| `$hash` | `string` | SHA-256 hash of the clicked tracking URL. |
| `$target_url` | `string` | The resolved target URL the visitor will be redirected to. |
| `$campaign_data` | `array` | Associative array with keys: `post_id`, `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, `utm_term`. |

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
| `$context` | `array` | Associative array with keys: `timestamp` (ISO 8601 UTC), `ip` (visitor IP), `user_agent` (visitor user-agent string). |

```php
add_action( 'kntnt_ad_attr_conversion_recorded', function ( array $attributions, array $context ): void {
    // $attributions = [ 'a1b2c3…' => 0.7, 'd4e5f6…' => 0.3 ]
    // $context = [ 'timestamp' => '2025-02-15T14:30:00+00:00', 'ip' => '…', 'user_agent' => '…' ]
}, 10, 2 );
```
