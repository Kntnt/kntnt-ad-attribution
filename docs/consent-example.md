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

```php
add_filter( 'kntnt_ad_attr_has_consent', function(): ?bool {

    if ( ! function_exists( 'wp_rcb_consent_given' ) ) {
        return null; // Consent plugin not active
    }

    $consent = wp_rcb_consent_given( 'kntnt-ad-attribution' );

    if ( empty( $consent['cookie'] ) ) {
        return null; // Service not configured
    }

    return $consent['cookieOptIn'] === true ? true : false;

} );
```

## JavaScript Consent Interface

```javascript
window.kntntAdAttributionGetConsent = function( callback ) {

    // Check initial state
    const initialConsent = window.consentApi?.consent?.['kntnt-ad-attribution'];
    if ( initialConsent === true ) {
        callback( 'yes' );
        return;
    }
    if ( initialConsent === false ) {
        callback( 'no' );
        return;
    }

    // No decision yet — listen for future changes
    document.addEventListener( 'RCBConsentChange', function( e ) {
        if ( e.detail?.consent?.['kntnt-ad-attribution'] ) {
            callback( 'yes' );
        } else {
            callback( 'no' );
        }
    } );

};
```
