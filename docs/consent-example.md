# Consent Integration — Complete Example (Real Cookie Banner)

Create a service in Real Cookie Banner with identifier `kntnt-ad-attribution` and the following cookies:

| Property | Cookie 1 | Cookie 2 | Cookie 3 |
|----------|----------|----------|----------|
| Type | HTTP Cookie | HTTP Cookie | HTTP Cookie |
| Name | `_ad_clicks` | `_ad_last_conv` | `_aah_pending` |
| Host | `.yourdomain.com` | `.yourdomain.com` | `.yourdomain.com` |
| Lifetime | 90 days | 30 days | 60 seconds |
| Category | Marketing | Marketing | Necessary* |

*`_aah_pending` can be registered as a necessary cookie if the consent plugin requires categorization — it contains no personal data and lives for a maximum of 60 seconds.

## PHP Hook

The `kntnt_ad_attr_has_consent` filter must distinguish three states: consent granted (`true`), consent denied (`false`), and no decision yet (`null`). Returning `false` when the visitor hasn't decided will silently prevent the deferred transport mechanism from activating.

```php
add_filter( 'kntnt_ad_attr_has_consent', function(): ?bool {

    if ( ! function_exists( 'wp_rcb_consent_given' ) ) {
        return null; // Consent plugin not active
    }

    $consent = wp_rcb_consent_given( 'kntnt-ad-attribution' );

    if ( empty( $consent['cookie'] ) ) {
        return null; // Service not configured
    }

    // consentGiven is true only after the visitor has made an explicit choice.
    // Without this check, cookieOptIn: false would be returned both when the
    // visitor has actively denied AND when they simply haven't interacted
    // with the banner yet.
    if ( ! $consent['consentGiven'] ) {
        return null; // Visitor has not made a decision yet
    }

    return $consent['cookieOptIn'] === true;

} );
```

## PHP: Server-Side Cookie Deletion on Opt-Out

The `_ad_clicks` and `_ad_last_conv` cookies are set with the `HttpOnly` flag, preventing consent plugins from deleting them via client-side JavaScript. Use the plugin's `kntnt_ad_attribution_delete_cookies()` function from Real Cookie Banner's server-side opt-out hook:

```php
add_action( 'RCB/OptOut/ByHttpCookie', function ( string $name, string $host ): void {
    if ( $name === '_ad_clicks' || $name === '_ad_last_conv' ) {
        kntnt_ad_attribution_delete_cookies();
    }
}, 10, 2 );
```

The function expires both `_ad_clicks` and `_ad_last_conv` (and any add-on cookies registered via the `kntnt_ad_attr_delete_cookies` filter) with the correct cookie attributes.

## JavaScript Consent Interface

Override `window.kntntAdAttributionGetConsent` **before `DOMContentLoaded`** to connect to Real Cookie Banner's JavaScript API:

```javascript
( function() {
    'use strict';

    window.kntntAdAttributionGetConsent = function( callback ) {

        var api = window.consentApi;
        if ( ! api || typeof api.consent !== 'function' ) {
            return; // RCB not loaded; fall back to plugin default.
        }

        var handled = false;

        function respond( answer ) {
            if ( handled ) {
                return;
            }
            handled = true;
            callback( answer );
        }

        // consent() returns a Promise that resolves when consent IS given
        // and rejects when consent is denied.
        api.consent( 'kntnt-ad-attribution' ).then( function() {
            respond( 'yes' );
        } ).catch( function() {
            respond( 'no' );
        } );

    };

} )();
```

Real Cookie Banner's `consentApi.consent()` method returns a Promise — it is a function, not a property object. The Promise resolves when the visitor grants consent (immediately if already consented) and rejects when the visitor denies consent. If the visitor hasn't decided yet, the Promise stays pending until a decision is made.

**Note:** The JavaScript example in this file uses the Promise-based API from Real Cookie Banner, which is the recommended approach. An alternative pattern using `consentApi.consent` as a property object and the `RCBConsentChange` event is shown in the README — both work, but the Promise-based pattern is simpler and handles the pending state automatically.
