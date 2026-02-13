# Kntnt Ad Attribution

[![Requires WordPress: 6.9+](https://img.shields.io/badge/WordPress-6.9+-blue.svg)](https://wordpress.org)
[![Requires PHP: 8.3+](https://img.shields.io/badge/PHP-8.3+-blue.svg)](https://php.net)
[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2+-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)


A WordPress plugin that provides internal lead attribution for ad campaigns using first-party cookies and fractional, time-weighted attribution.

## Description

Kntnt Ad Attribution lets you measure which ads generate leads by tracking ad clicks through unique hash-based URLs and attributing form submissions back to the originating ads.

Each ad gets a unique tracking URL (e.g. `example.com/ad/a1b2c3…`). When a visitor clicks an ad, the server records the click, stores the ad hash in a first-party cookie, and redirects the visitor to the landing page. When the visitor later submits a lead form, the plugin reads the cookie and distributes the conversion fractionally across all clicked ads, with more recent clicks weighted higher.

The plugin is platform-agnostic and works with any ad platform — Google Ads, Meta Ads, LinkedIn Ads, Microsoft Ads, or any other source that can link to a custom URL.

The plugin does not hardcode integrations with any specific consent management or form plugin. Instead, it exposes hooks that you connect to your preferred plugins via your theme's `functions.php`, a mu-plugin, or a code snippet plugin.

### Key Features

- **Hash-based tracking URLs** — each ad gets a unique `/ad/<hash>` URL (prefix configurable via filter), independent of ad platform.
- **First-party cookie tracking** — stores clicked ad hashes in a single `HttpOnly`, `Secure`, `SameSite=Lax` cookie (`_ad_clicks`), with a configurable lifetime (default: 90 days).
- **Fractional attribution with linear time-weighting** — if a visitor clicked multiple ads, each gets a share of the conversion weighted by recency. More recent clicks count more.
- **Deduplication** — repeated form submissions within a configurable cooldown period (default: 30 days) are not counted as new conversions.
- **Cookie size management** — stores a maximum of 50 ad hashes per visitor, pruning the oldest when the limit is reached.
- **Statistics dashboard** — view clicks, conversions, and fractional attribution per campaign for any date range, with CSV export.
- **Three-state consent model** — integrates with any cookie consent plugin via a filter hook, supporting yes, no, and undefined consent states with a transport mechanism for deferred consent.
- **Platform-agnostic form support** — integrates with any form plugin via an action hook.
- **Bot detection** — filters out known bots via User-Agent matching and `robots.txt` rules.
- **Two redirect methods** — 302 redirect (default) or JavaScript redirect via filter, providing flexibility for different ITP mitigation strategies.

### The Problem

Ad platforms tell you how many clicks an ad received, but not how many of those clicks turned into actual leads on your website. The standard way to close that gap is client-side conversion tracking (a JavaScript tag that fires when a form is submitted), but this approach is increasingly unreliable:

- **Ad blockers** prevent the tracking script from loading at all.
- **Safari's Intelligent Tracking Prevention (ITP)** limits the lifetime of cookies set in a cross-site context, causing conversions to go unrecorded if the visitor returns after a few days.
- **Privacy-focused browsers** (Firefox Strict Mode, Brave, etc.) strip or block tracking parameters like `gclid` before they reach your server.

The result is that you have a blind spot: you know how much you spend on each ad and how many clicks it gets, but you don't reliably know which ads actually generate leads.

### How This Plugin Helps

Kntnt Ad Attribution moves the tracking to the server side, eliminating the dependency on client-side JavaScript for the core attribution logic:

1. Each ad gets a unique server-controlled tracking URL (`/ad/<hash>`).
2. When a visitor clicks the ad, your server — not a JavaScript tag — records the click and stores the ad hash in a first-party cookie.
3. When the visitor later submits a lead form, the server reads the cookie and attributes the conversion.

Because the click recording and attribution happen on the server, they are immune to ad blockers and JavaScript-blocking browser features. The cookie is a standard first-party HTTP cookie set by your own domain, which gives it better survival odds than third-party or JavaScript-set cookies.

### Limitations

- **ITP still applies.** Safari may cap the cookie lifetime to 7 days when the visitor arrives from a classified tracking domain (such as Google). Conversions after that window are lost. A JavaScript redirect method (configurable via filter) may improve this in some cases, but there are no guarantees.
- **No feedback to ad platforms.** The plugin provides internal statistics only. It does not send conversion data back to Google Ads, Meta, or any other platform. This means it cannot improve the ad platform's bidding or AI optimization.
- **Cookies can be cleared.** If the visitor clears their cookies or uses a private/incognito window for the return visit, the attribution link is broken.
- **Cross-device tracking is not supported.** A visitor who clicks an ad on their phone but converts on their laptop will not be attributed.
- **Impressions are not measured.** Ad views occur on the ad platform and never reach the server.
- **No multisite support.** Version 1 does not support WordPress multisite.

Despite these limitations, server-side first-party cookie tracking captures significantly more conversions than pure client-side tracking, and gives you a reliable internal baseline for comparing ad performance.

## Installation

1. [Download the latest release ZIP file](https://github.com/Kntnt/kntnt-ad-attribution/releases/latest/download/kntnt-ad-attribution.zip).
2. In your WordPress admin panel, go to **Plugins → Add New**.
3. Click **Upload Plugin** and select the downloaded ZIP file.
4. Activate the plugin.

### System Requirements

| Requirement | Minimum |
|-------------|---------|
| PHP | 8.3 |
| WordPress | 6.9 |
| HTTPS | Required (cookies are set with the `Secure` flag) |
| MySQL/MariaDB | 5.7 / 10.3 |

The plugin checks the PHP version on activation and aborts with a clear error message if the requirement is not met.

### Building from Source

If you clone the repository instead of downloading the release ZIP, you need to compile the translation files before the plugin is fully functional:

```bash
git clone https://github.com/Kntnt/kntnt-ad-attribution.git
cd kntnt-ad-attribution
wp i18n make-mo languages/
```

> [!NOTE]
> The repository does not include compiled `.mo` translation files. You must run `wp i18n make-mo languages/` after cloning to generate them from the `.po` source files. This requires [WP-CLI](https://wp-cli.org/) to be installed.

### Permissions

On activation, the plugin registers a custom capability: `kntnt_ad_attr`. This capability is automatically granted to the **Administrator** and **Editor** roles. Only users with this capability can access the Ad Attribution page and manage tracking URLs.

To grant access to other roles (e.g. Author), use a role management plugin such as [Members](https://wordpress.org/plugins/members/) or [User Role Editor](https://wordpress.org/plugins/user-role-editor/) to assign the `kntnt_ad_attr` capability.

## Cookie Consent Configuration

If you use a cookie consent plugin (such as [Real Cookie Banner](https://devowl.io/wordpress-real-cookie-banner/), which is an excellent and recommended choice), you need to register the plugin's cookies as a marketing service. Here is the information you need:

| Field              | Value                        |
|--------------------|------------------------------|
| Service name       | Kntnt Ad Attribution         |
| Service identifier | `kntnt-ad-attribution`       |
| Provider           | Kntnt (own service)          |
| Category           | Marketing                    |
| Purpose            | Stores information about which ads the visitor has clicked in order to measure which ads lead to inquiries via the website's forms. No data is shared with third parties. |
| Legal basis        | Consent                      |

Register the following cookies:

| Property | Cookie 1       | Cookie 2          | Cookie 3       |
|----------|----------------|-------------------|----------------|
| Type     | HTTP Cookie    | HTTP Cookie       | HTTP Cookie    |
| Name     | `_ad_clicks`   | `_ad_last_conv`   | `_aah_pending` |
| Host     | `.yourdomain.com` | `.yourdomain.com` | `.yourdomain.com` |
| Duration | 90 days        | 30 days           | 60 seconds     |
| Category | Marketing      | Marketing         | Necessary*     |

Replace `.yourdomain.com` with your actual domain.

*`_aah_pending` can be registered as a necessary cookie — it contains no personal data, lives at most 60 seconds, and serves only as a technical transport mechanism for deferred consent scenarios.

You also need to connect the consent plugin to this plugin via the `kntnt_ad_attribution_has_consent` filter. See [Connecting a Cookie Consent Plugin](#connecting-a-cookie-consent-plugin) for details and a complete Real Cookie Banner example.

## Usage

### Admin Interface

The plugin adds **Ad Attribution** under **Tools** in the WordPress admin menu. The page has two tabs:

#### URLs Tab (default)

This is where you create and manage tracking URLs.

- **Create new URL:** Select a target page via a searchable dropdown and fill in UTM/MTM parameters (source, medium, campaign are required; content and term are optional). The plugin generates a SHA-256 hash and produces a tracking URL: `https://yourdomain.com/ad/<hash>`.
- **URL list:** Shows all created tracking URLs with the hash (truncated), full tracking URL, target URL, and UTM/MTM values. The list can be filtered by UTM dimensions.
- **Row actions:** Edit, Trash.

#### Campaigns Tab

This is where you view attribution results.

- **Filters:** Filter by date range, UTM dimensions (source, medium, campaign, content, term), and tracking/target URL. All filters can be combined.
- **Summary:** Shows total clicks and total (fractional) conversions for the selected filters.
- **Results table:** Lists each tracking URL with its hash, target URL, UTM/MTM parameters, click count, and fractional conversion count.
- **Export:** Export the filtered results as a CSV file (UTF-8 with BOM; semicolon delimiter when the locale uses comma as decimal separator).

**Note:** The plugin tracks clicks (each request to `/ad/<hash>`) and conversions. Ad impressions are not available since they occur on the ad platform and never reach your server.

### How Attribution Works

When a visitor clicks a tracking URL, the plugin logs the click, stores the ad hash in a first-party cookie (if consent is given), and redirects the visitor to the landing page.

If consent is undefined (the visitor hasn't decided yet), the hash is transported to the landing page via a temporary cookie (`_aah_pending`, 60 seconds) or a URL fragment. A client-side script picks up the hash and stores it in `sessionStorage` until consent is resolved.

When a conversion is triggered (see [Connecting a Form Plugin](#connecting-a-form-plugin)), the plugin:

1. Checks for deduplication — if a conversion was already recorded within the cooldown period (default: 30 days), the new one is ignored.
2. Reads the `_ad_clicks` cookie and extracts all ad hashes.
3. Filters out hashes that no longer exist as registered tracking URLs.
4. If no valid hashes remain, exits without recording anything.
5. Gives each valid hash a time-weighted share of the conversion: a click *d* days old receives weight *max(N − d, 1)* where *N* is the cookie lifetime (default: 90 days), and all weights are normalized to sum to 1.
6. Stores the fractional values in the database within a transaction.

### Connecting a Form Plugin

The plugin does not integrate with any specific form plugin. Instead, you trigger a conversion by calling the `kntnt_ad_attribution_conversion` action hook from your form plugin's submission handler.

**Example with WS Form:**

In WS Form, open the specific lead form you want to track, go to the **Actions** tab, and add a WordPress action hook that fires on successful submission. Set the hook name to `kntnt_ad_attribution_conversion`. No additional code is needed — WS Form will fire the action and the plugin will handle the rest. Only add this action to the forms you want to count as lead conversions.

**Example with a custom form or another plugin:**

If your form plugin doesn't have a UI for firing action hooks, add a snippet to your theme's `functions.php`, a mu-plugin, or a code snippet plugin. Make sure to target only the specific lead form(s) you want to track — not every form on the site.

```php
// Example: trigger conversion for a specific Contact Form 7 form.
// Replace 42 with the ID of your lead form (found in the CF7 admin).
add_action( 'wpcf7_mail_sent', function ( $contact_form ) {
    if ( $contact_form->id() === 42 ) {
        do_action( 'kntnt_ad_attribution_conversion' );
    }
} );
```

```php
// Example: trigger conversion for specific Gravity Forms forms.
// Replace 5 and 12 with the IDs of your lead forms.
add_action( 'gform_after_submission', function ( $entry, $form ) {
    if ( in_array( $form['id'], [ 5, 12 ], true ) ) {
        do_action( 'kntnt_ad_attribution_conversion' );
    }
}, 10, 2 );
```

### Connecting a Cookie Consent Plugin

The plugin uses a three-state consent model:

- **Yes (`true`)** — the visitor has accepted marketing cookies. The `_ad_clicks` cookie is set normally.
- **No (`false`)** — the visitor has declined. No cookie is set. Attribution is lost (accepted trade-off).
- **Undefined (`null`)** — the visitor hasn't decided yet. The click is logged, the hash is transported to the client via a temporary mechanism, and the client-side script waits for a consent decision.

Consent is checked via the `kntnt_ad_attribution_has_consent` filter. If no filter callback is registered, the plugin falls back to `kntnt_ad_attribution_default_consent` (default: `true`), which is appropriate for sites without consent requirements.

**PHP hook example with Real Cookie Banner:**

First, create a service in Real Cookie Banner with the unique identifier `kntnt-ad-attribution` and the cookie information from the [Cookie Consent Configuration](#cookie-consent-configuration) section above.

Then add the following snippet to your theme's `functions.php`, a mu-plugin, or a code snippet plugin:

```php
add_filter( 'kntnt_ad_attribution_has_consent', function (): ?bool {

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

**JavaScript consent override:**

For deferred consent scenarios, the plugin defines a global JavaScript function `window.kntntAdAttributionGetConsent` that the client-side script calls to determine consent status. Override this function to connect to your consent plugin's JavaScript API:

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

The callback accepts `'yes'`, `'no'`, or `'unknown'`. It may be called asynchronously or multiple times (e.g. on consent change). The script handles all cases via an internal `handled` flag — only the first `'yes'` or `'no'` takes effect.

## Developer Hooks

### Filters

**`kntnt_ad_attribution_has_consent`**

Controls whether the plugin has consent to set cookies for the current visitor. Return `true` to allow cookies, `false` to block them, or `null` for undefined (triggers deferred consent transport). When no callback is registered, the plugin falls back to the value of `kntnt_ad_attribution_default_consent`.

```php
add_filter( 'kntnt_ad_attribution_has_consent', function (): ?bool {
    // Your consent logic here — return true, false, or null
    return true;
} );
```

**`kntnt_ad_attribution_default_consent`**

Controls the fallback consent behavior when no callback is registered on `kntnt_ad_attribution_has_consent`. Default: `true` (cookies are set). This is appropriate for sites that do not have consent requirements.

```php
// Block cookies by default when no consent integration is configured
add_filter( 'kntnt_ad_attribution_default_consent', '__return_false' );
```

**`kntnt_ad_attribution_redirect_method`**

Controls the redirect method used after an ad click. Default: `'302'`.

```php
// Switch to JavaScript redirect for better ITP handling
add_filter( 'kntnt_ad_attribution_redirect_method', function () {
    return 'js';
} );
```

Accepted values: `'302'` (server-side redirect) or `'js'` (JavaScript redirect via an intermediate page).

**`kntnt_ad_attribution_url_prefix`**

Filters the URL prefix used for tracking URLs. Default: `'ad'` (resulting in `/ad/<hash>`).

```php
// Change tracking URLs to /go/<hash>
add_filter( 'kntnt_ad_attribution_url_prefix', function () {
    return 'go';
} );
```

**`kntnt_ad_attribution_cookie_lifetime`**

Filters the cookie lifetime in days. Default: `90`. Also affects the attribution formula's *N* value.

```php
add_filter( 'kntnt_ad_attribution_cookie_lifetime', function () {
    return 30;
} );
```

**`kntnt_ad_attribution_dedup_days`**

Filters the deduplication cooldown in days. Form submissions within this period after a previous conversion are not counted. Default: `30`. Automatically capped to the cookie lifetime.

```php
add_filter( 'kntnt_ad_attribution_dedup_days', function () {
    return 14;
} );
```

**`kntnt_ad_attribution_pending_transport`**

Controls the transport mechanism used when consent is undefined. Default: `'cookie'`.

```php
// Use URL fragment instead of temporary cookie
add_filter( 'kntnt_ad_attribution_pending_transport', function () {
    return 'fragment';
} );
```

Accepted values: `'cookie'` (temporary `_aah_pending` cookie, 60 seconds) or `'fragment'` (URL fragment `#_aah=<hash>`).

**`kntnt_ad_attribution_is_bot`**

Controls bot detection. Default: `false`. The plugin registers its own callback that checks the User-Agent against a list of known bot signatures (Googlebot, Bingbot, facebookexternalhit, etc.) and treats empty User-Agents as bots. Bots are redirected to the target page without logging the click or setting a cookie.

```php
// Add custom bot detection logic
add_filter( 'kntnt_ad_attribution_is_bot', function ( bool $is_bot ): bool {
    if ( $is_bot ) {
        return true; // Already detected as bot
    }
    // Your additional detection logic
    return str_contains( $_SERVER['HTTP_USER_AGENT'] ?? '', 'MyCustomBot' );
} );
```

### Actions

**`kntnt_ad_attribution_conversion`**

Trigger this action to record a conversion for the current visitor. The plugin reads the visitor's cookie and performs the attribution. This is the hook you connect your form plugin to.

```php
do_action( 'kntnt_ad_attribution_conversion' );
```

**`kntnt_ad_attribution_conversion_recorded`**

Fires after a conversion has been successfully recorded. Receives the array of attributed hashes with their fractional values.

```php
add_action( 'kntnt_ad_attribution_conversion_recorded', function ( array $attributions ) {
    // $attributions = [ 'a1b2c3…' => 0.7, 'd4e5f6…' => 0.3 ]
    error_log( 'Conversion attributed: ' . print_r( $attributions, true ) );
} );
```

## Frequently Asked Questions

**What problem does this plugin solve?**

Ad platforms report clicks but not which clicks became leads on your website. Standard client-side tracking (JavaScript tags) is increasingly blocked by ad blockers, Safari's ITP, and privacy-focused browsers. This plugin moves the tracking to the server side, where it is immune to ad blockers and more resilient to browser restrictions. It gives you an internal, independent view of which ads actually generate leads. See [The Problem](#the-problem) and [Limitations](#limitations) for details.

**Which ad platforms does this plugin support (Google Ads, Meta Ads, …)?**

All of them. The plugin is platform-agnostic. It works with any ad platform that lets you set a custom destination URL — Google Ads, Meta Ads, LinkedIn Ads, Microsoft Ads, and any other platform.

**Does this plugin send conversion data back to the ad platform?**

No. This plugin provides internal attribution statistics only. It does not communicate with any ad platform's API. Its purpose is to give you an independent view of which ads generate leads.

**What happens with Safari's Intelligent Tracking Prevention (ITP)?**

Safari's ITP may limit the cookie lifetime to 7 days (or less) when the visitor arrives from a classified cross-site source such as a Google Ads click. This means conversions that happen more than 7 days after the ad click may not be attributed on Safari/iOS. The JavaScript redirect method (`kntnt_ad_attribution_redirect_method` → `'js'`) may improve this in some cases, but there are no guarantees.

**What if a visitor clicks more than 50 ads?**

The cookie stores a maximum of 50 ad hashes. When the limit is reached, the oldest hash is removed to make room for the new one.

**What if the same visitor submits the form twice?**

The plugin uses a deduplication mechanism. If a visitor triggers a conversion within the cooldown period (default: 30 days) after a previous conversion, the second submission is not counted. After the cooldown period, a new submission is counted as a new conversion. The cooldown can be changed via the `kntnt_ad_attribution_dedup_days` filter.

**What about cookie consent / GDPR?**

The plugin supports a three-state consent model (yes, no, undefined) and integrates with any cookie consent plugin via the `kntnt_ad_attribution_has_consent` filter. Click counting (aggregated statistics per tracking URL) is always performed regardless of consent, since it does not constitute personal data processing. Only the cookie — which links a click to an individual — requires consent. See [Connecting a Cookie Consent Plugin](#connecting-a-cookie-consent-plugin) for details.

**How can I get help or report a bug?**

Please visit the plugin's [issue tracker on GitHub](https://github.com/kntnt/kntnt-ad-attribution/issues) to ask questions, report bugs, or view existing discussions.

**How can I contribute?**

Contributions are welcome! Please feel free to fork the repository and submit a pull request on GitHub.
