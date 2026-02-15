# Kntnt Ad Attribution

[![Requires WordPress: 6.9+](https://img.shields.io/badge/WordPress-6.9+-blue.svg)](https://wordpress.org)
[![Requires PHP: 8.3+](https://img.shields.io/badge/PHP-8.3+-blue.svg)](https://php.net)
[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2+-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)


A privacy-friendly WordPress plugin for lead attribution that keeps all data on your own server — no personal data is sent to Google or any other third party. Works with any ad platform.

## Description

Ad platforms like Google Ads offer conversion tracking features — such as Google's Enhanced Conversions — that improve attribution by sending hashed personal data (email addresses, phone numbers, names) from your website to the ad platform's servers. This raises significant privacy concerns: personal data leaves your domain, is transferred to servers that may be located outside the EU, and is used by the ad platform for its own purposes beyond your control. With the EU-US Data Privacy Framework under ongoing legal challenge and the possibility that it may be invalidated (as its predecessors Safe Harbor and Privacy Shield were), relying on such mechanisms creates regulatory risk for EU-based businesses.

Kntnt Ad Attribution takes a different approach. It gives you the same core benefit — knowing which ads actually generate leads — but keeps all data on your own server. No personal data is sent to Google, Meta, or any other third party.

Each ad gets a unique tracking URL (e.g. `example.com/ad/a1b2c3…`). When a visitor clicks an ad, your server records the click, stores the ad hash in a first-party cookie, and redirects the visitor to the landing page. When the visitor later submits a lead form, the server reads the cookie and distributes the conversion fractionally across all clicked ads, with more recent clicks weighted higher. The entire process happens on your infrastructure.

The plugin is platform-agnostic and works with any ad platform — Google Ads, Meta Ads, LinkedIn Ads, Microsoft Ads, or any other source that can link to a custom URL. This means you get a single, consistent attribution method across all your advertising channels.

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

Ad platforms tell you how many clicks an ad received, but not how many of those clicks turned into actual leads on your website. The standard solution is client-side conversion tracking — a JavaScript tag that fires when a form is submitted — but this approach has two categories of problems.

**It is increasingly unreliable:**

- **Ad blockers** prevent the tracking script from loading at all.
- **Safari's Intelligent Tracking Prevention (ITP)** limits the lifetime of cookies set in a cross-site context, causing conversions to go unrecorded if the visitor returns after a few days.
- **Privacy-focused browsers** (Firefox Strict Mode, Brave, etc.) strip or block tracking parameters like `gclid` before they reach your server.

**It raises privacy concerns:**

Ad platforms have responded to the decline in client-side tracking with features like Google's Enhanced Conversions and Meta's Advanced Matching, which improve attribution by sending hashed first-party data (email addresses, phone numbers, names) to the ad platform's servers. While the data is hashed before transmission, this approach has significant implications:

- **Hashing is not anonymization.** Regulatory bodies including the US Federal Trade Commission (FTC) have noted that hashed data can still enable identification of individuals. Under GDPR, hashed personal data remains personal data.
- **Personal data leaves your domain** and is transferred to the ad platform's servers, which may be located outside the EU.
- **The ad platform uses the data for its own purposes**, including automated bidding optimization and audience modelling, beyond the advertiser's direct control.
- **The legal basis for EU-US data transfers is uncertain.** The current EU-US Data Privacy Framework (DPF) is under active legal challenge. Its two predecessors — Safe Harbor and Privacy Shield — were both invalidated by the EU Court of Justice. A "Schrems III" ruling or political changes to the underlying US executive order could leave businesses without a valid transfer mechanism.

The result is a dilemma: you need attribution to spend your ad budget wisely, but the available solutions are either unreliable, privacy-invasive, or both.

### How This Plugin Helps

Kntnt Ad Attribution solves both problems. It moves the tracking to the server side, eliminating the dependency on client-side JavaScript, and it keeps all data on your own server, eliminating the need to share personal data with third parties.

1. Each ad gets a unique server-controlled tracking URL (`/ad/<hash>`).
2. When a visitor clicks the ad, your server — not a JavaScript tag — records the click and stores the ad hash in a first-party cookie.
3. When the visitor later submits a lead form, the server reads the cookie and attributes the conversion.

Because the click recording and attribution happen on your server, they are immune to ad blockers and JavaScript-blocking browser features. The cookie is a standard first-party HTTP cookie set by your own domain, which makes it more resilient than third-party or JavaScript-set cookies (though Safari's ITP may still limit its lifetime — see [Limitations](#limitations)).

Unlike Enhanced Conversions and similar features, the plugin never sends personal data — hashed or otherwise — to any external server. No email addresses, no phone numbers, no names. The only data stored is a list of opaque hashes in a cookie on the visitor's browser and aggregated click/conversion counts in your WordPress database. This means there is no third-country transfer issue and no dependency on the EU-US Data Privacy Framework or any other international data transfer mechanism.

### Limitations

- **ITP still applies.** Safari may cap the cookie lifetime to 7 days when the visitor arrives from a classified tracking domain (such as Google). Conversions after that window are lost. A JavaScript redirect method (configurable via filter) may improve this in some cases, but there are no guarantees.
- **No feedback to ad platforms.** The plugin provides internal statistics only. It does not send conversion data back to Google Ads, Meta, or any other platform. This means it cannot improve the ad platform's bidding or AI optimization.
- **Cookies can be cleared.** If the visitor clears their cookies or uses a private/incognito window for the return visit, the attribution link is broken.
- **Cross-device tracking is not supported.** A visitor who clicks an ad on their phone but converts on their laptop will not be attributed.
- **Impressions are not measured.** Ad views occur on the ad platform and never reach the server.
- **No multisite support.** Version 1 does not support WordPress multisite.

Despite these limitations, server-side first-party cookie tracking captures significantly more conversions than pure client-side tracking, and gives you a reliable internal baseline for comparing ad performance.

### Privacy and GDPR

This plugin is designed with data minimization and data locality as core principles. Here is how it relates to common privacy concerns:

**No personal data leaves your server.** Unlike Google's Enhanced Conversions or Meta's Advanced Matching, this plugin never transmits personal data — hashed or otherwise — to any third party. All attribution data stays in your WordPress database on your own infrastructure. This is the plugin's fundamental privacy advantage.

**No third-country transfer problem.** Because no data is sent to external servers, there is no dependency on the EU-US Data Privacy Framework, Standard Contractual Clauses, or any other international data transfer mechanism. If the Data Privacy Framework is invalidated by a future court ruling (as Safe Harbor and Privacy Shield were before it), this plugin is unaffected.

**The cookie constitutes personal data under GDPR.** The `_ad_clicks` cookie links a visitor's ad clicks to their subsequent form submissions, which makes it personal data processing. The plugin therefore requires consent for the cookie, and implements a three-state consent model (yes, no, undefined) that integrates with any cookie consent plugin. See [Cookie Consent Configuration](#cookie-consent-configuration) for details.

**Click counting does not require consent.** The plugin always logs that a click occurred on a tracking URL (incrementing an aggregate counter), regardless of consent status. This is analogous to server access logs and does not constitute personal data processing, since no individual is identified or identifiable from the aggregate count alone.

**Hashing is used for URL generation, not for pseudonymization of personal data.** The SHA-256 hashes in this plugin are derived from the ad's UTM parameters (source, medium, campaign, etc.) and serve as opaque identifiers for tracking URLs. They are not hashes of personal data such as email addresses or phone numbers. This is a fundamental difference from Enhanced Conversions, where personal data is hashed and sent to Google.

**The `_aah_pending` cookie is a borderline case.** This temporary cookie (maximum 60 seconds) contains only an ad hash and serves as a technical transport mechanism for deferred consent scenarios. It contains no personal data in itself. Whether it should be classified as "necessary" or "marketing" is a judgment call that depends on your interpretation; the plugin's consent configuration section presents both options. See [Cookie Consent Configuration](#cookie-consent-configuration).

**Data minimization.** The plugin stores the minimum data needed for attribution: opaque hashes in a cookie and aggregate click/conversion counts in the database. No names, email addresses, IP addresses, or other directly identifying information is stored by the plugin.

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

*`_aah_pending` contains no personal data, lives at most 60 seconds, and serves only as a technical transport mechanism for deferred consent scenarios. Whether to classify it as "Necessary" or "Marketing" is a judgment call — see [Privacy and GDPR](#privacy-and-gdpr) for a discussion.

You also need to connect the consent plugin to this plugin via the `kntnt_ad_attr_has_consent` filter. See [Connecting a Cookie Consent Plugin](#connecting-a-cookie-consent-plugin) for details and a complete Real Cookie Banner example.

## Usage

### Admin Interface

The plugin adds **Ad Attribution** under **Tools** in the WordPress admin menu. The page has two tabs:

#### URLs Tab (default)

This is where you create and manage tracking URLs.

- **Create new URL:** Select a target page via a searchable dropdown and fill in UTM parameters. Source, medium, and campaign are required; content and term are optional. Source and medium offer predefined options (configurable via the `kntnt_ad_attr_utm_options` filter) but also accept custom values. The plugin generates a SHA-256 hash and produces a tracking URL: `https://yourdomain.com/ad/<hash>`.
- **URL list:** Shows all created tracking URLs with full tracking URL, target URL, and UTM values. Click a tracking URL to copy it to the clipboard. The list can be filtered by UTM dimensions.
- **Row actions:** Trash (or Restore / Delete Permanently for trashed URLs).

#### Campaigns Tab

This is where you view attribution results.

- **Filters:** Filter by date range and UTM dimensions (source, medium, campaign, content, term). A search box allows searching by tracking URL or hash. All filters can be combined.
- **Summary:** Shows total clicks and total (fractional) conversions for the selected filters.
- **Results table:** Lists each tracking URL with its target URL, UTM parameters, click count, and fractional conversion count.
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

The plugin does not integrate with any specific form plugin. Instead, you trigger a conversion by calling the `kntnt_ad_attr_conversion` action hook from your form plugin's submission handler.

**Example with WS Form:**

In WS Form, open the specific lead form you want to track, go to the **Actions** tab, and add a WordPress action hook that fires on successful submission. Set the hook name to `kntnt_ad_attr_conversion`. No additional code is needed — WS Form will fire the action and the plugin will handle the rest. Only add this action to the forms you want to count as lead conversions.

**Example with a custom form or another plugin:**

If your form plugin doesn't have a UI for firing action hooks, add a snippet to your theme's `functions.php`, a mu-plugin, or a code snippet plugin. Make sure to target only the specific lead form(s) you want to track — not every form on the site.

```php
// Example: trigger conversion for a specific Contact Form 7 form.
// Replace 42 with the ID of your lead form (found in the CF7 admin).
add_action( 'wpcf7_mail_sent', function ( $contact_form ) {
    if ( $contact_form->id() === 42 ) {
        do_action( 'kntnt_ad_attr_conversion' );
    }
} );
```

```php
// Example: trigger conversion for specific Gravity Forms forms.
// Replace 5 and 12 with the IDs of your lead forms.
add_action( 'gform_after_submission', function ( $entry, $form ) {
    if ( in_array( $form['id'], [ 5, 12 ], true ) ) {
        do_action( 'kntnt_ad_attr_conversion' );
    }
}, 10, 2 );
```

### Connecting a Cookie Consent Plugin

The plugin uses a three-state consent model:

- **Yes (`true`)** — the visitor has accepted marketing cookies. The `_ad_clicks` cookie is set normally.
- **No (`false`)** — the visitor has declined. No cookie is set. Attribution is lost (accepted trade-off).
- **Undefined (`null`)** — the visitor hasn't decided yet. The click is logged, the hash is transported to the client via a temporary mechanism, and the client-side script waits for a consent decision.

Consent is checked via the `kntnt_ad_attr_has_consent` filter. If no filter callback is registered, the plugin falls back to `kntnt_ad_attr_default_consent` (default: `true`), which is appropriate for sites without consent requirements.

**PHP hook example with Real Cookie Banner:**

First, create a service in Real Cookie Banner with the unique identifier `kntnt-ad-attribution` and the cookie information from the [Cookie Consent Configuration](#cookie-consent-configuration) section above.

Then add the following snippet to your theme's `functions.php`, a mu-plugin, or a code snippet plugin:

```php
add_filter( 'kntnt_ad_attr_has_consent', function (): ?bool {

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

**`kntnt_ad_attr_has_consent`**

Controls whether the plugin has consent to set cookies for the current visitor. Return `true` to allow cookies, `false` to block them, or `null` for undefined (triggers deferred consent transport). When no callback is registered, the plugin falls back to the value of `kntnt_ad_attr_default_consent`.

```php
add_filter( 'kntnt_ad_attr_has_consent', function (): ?bool {
    // Your consent logic here — return true, false, or null
    return true;
} );
```

**`kntnt_ad_attr_default_consent`**

Controls the fallback consent behavior when no callback is registered on `kntnt_ad_attr_has_consent`. Default: `true` (cookies are set). This is appropriate for sites that do not have consent requirements.

```php
// Block cookies by default when no consent integration is configured
add_filter( 'kntnt_ad_attr_default_consent', '__return_false' );
```

**`kntnt_ad_attr_redirect_method`**

Controls the redirect method used after an ad click. Default: `'302'`.

```php
// Switch to JavaScript redirect for better ITP handling
add_filter( 'kntnt_ad_attr_redirect_method', function () {
    return 'js';
} );
```

Accepted values: `'302'` (server-side redirect) or `'js'` (JavaScript redirect via an intermediate page).

**`kntnt_ad_attr_url_prefix`**

Filters the URL prefix used for tracking URLs. Default: `'ad'` (resulting in `/ad/<hash>`).

```php
// Change tracking URLs to /go/<hash>
add_filter( 'kntnt_ad_attr_url_prefix', function () {
    return 'go';
} );
```

**`kntnt_ad_attr_cookie_lifetime`**

Filters the cookie lifetime in days. Default: `90`. Also affects the attribution formula's *N* value.

```php
add_filter( 'kntnt_ad_attr_cookie_lifetime', function () {
    return 30;
} );
```

**`kntnt_ad_attr_dedup_days`**

Filters the deduplication cooldown in days. Form submissions within this period after a previous conversion are not counted. Default: `30`. Automatically capped to the cookie lifetime.

```php
add_filter( 'kntnt_ad_attr_dedup_days', function () {
    return 14;
} );
```

**`kntnt_ad_attr_pending_transport`**

Controls the transport mechanism used when consent is undefined. Default: `'cookie'`.

```php
// Use URL fragment instead of temporary cookie
add_filter( 'kntnt_ad_attr_pending_transport', function () {
    return 'fragment';
} );
```

Accepted values: `'cookie'` (temporary `_aah_pending` cookie, 60 seconds) or `'fragment'` (URL fragment `#_aah=<hash>`).

**`kntnt_ad_attr_is_bot`**

Controls bot detection. Default: `false`. The plugin registers its own callback that checks the User-Agent against a list of known bot signatures (Googlebot, Bingbot, facebookexternalhit, etc.) and treats empty User-Agents as bots. Bots are redirected to the target page without logging the click or setting a cookie.

```php
// Add custom bot detection logic
add_filter( 'kntnt_ad_attr_is_bot', function ( bool $is_bot ): bool {
    if ( $is_bot ) {
        return true; // Already detected as bot
    }
    // Your additional detection logic
    return str_contains( $_SERVER['HTTP_USER_AGENT'] ?? '', 'MyCustomBot' );
} );
```

**`kntnt_ad_attr_utm_options`**

Filters the predefined UTM options shown in the source and medium dropdowns when creating a tracking URL. The array contains `sources` (a map of source names to their default medium) and `mediums` (a list of available medium values). Both accept custom values typed by the user; this filter only controls the predefined suggestions.

```php
add_filter( 'kntnt_ad_attr_utm_options', function ( array $options ): array {
    $options['sources']['snapchat'] = 'paid_social';
    $options['mediums'][] = 'native';
    return $options;
} );
```

Default:

```php
[
    'sources' => [
        'google'    => 'cpc',
        'meta'      => 'paid_social',
        'linkedin'  => 'paid_social',
        'microsoft' => 'cpc',
        'tiktok'    => 'paid_social',
        'pinterest' => 'paid_social',
    ],
    'mediums' => [ 'cpc', 'paid_social', 'display', 'video', 'shopping' ],
]
```

### Actions

**`kntnt_ad_attr_conversion`**

Trigger this action to record a conversion for the current visitor. The plugin reads the visitor's cookie and performs the attribution. This is the hook you connect your form plugin to.

```php
do_action( 'kntnt_ad_attr_conversion' );
```

**`kntnt_ad_attr_conversion_recorded`**

Fires after a conversion has been successfully recorded. Receives the array of attributed hashes with their fractional values.

```php
add_action( 'kntnt_ad_attr_conversion_recorded', function ( array $attributions ) {
    // $attributions = [ 'a1b2c3…' => 0.7, 'd4e5f6…' => 0.3 ]
    error_log( 'Conversion attributed: ' . print_r( $attributions, true ) );
} );
```

## Frequently Asked Questions

**How does this compare to Google's Enhanced Conversions?**

Both this plugin and Enhanced Conversions aim to improve ad attribution beyond what basic client-side tracking provides. The key difference is where the data goes. Enhanced Conversions sends hashed personal data (email, phone, name) to Google's servers, where it is matched against Google accounts. This plugin keeps all data on your own server and never sends personal data to any third party. The trade-off is that this plugin cannot feed conversion data back to the ad platform's bidding algorithms — it provides internal attribution statistics only. See [Privacy and GDPR](#privacy-and-gdpr) for a detailed comparison.

**What problem does this plugin solve?**

Ad platforms report clicks but not which clicks became leads on your website. Standard client-side tracking (JavaScript tags) is increasingly blocked by ad blockers, Safari's ITP, and privacy-focused browsers. This plugin moves the tracking to the server side, where it is immune to ad blockers and more resilient to browser restrictions. It gives you an internal, independent view of which ads actually generate leads. See [The Problem](#the-problem) and [Limitations](#limitations) for details.

**Which ad platforms does this plugin support (Google Ads, Meta Ads, …)?**

All of them. The plugin is platform-agnostic. It works with any ad platform that lets you set a custom destination URL — Google Ads, Meta Ads, LinkedIn Ads, Microsoft Ads, and any other platform.

**Does this plugin send conversion data back to the ad platform?**

No. This plugin provides internal attribution statistics only. It does not communicate with any ad platform's API. Its purpose is to give you an independent view of which ads generate leads.

**What happens with Safari's Intelligent Tracking Prevention (ITP)?**

Safari's ITP may limit the cookie lifetime to 7 days (or less) when the visitor arrives from a classified cross-site source such as a Google Ads click. This means conversions that happen more than 7 days after the ad click may not be attributed on Safari/iOS. The JavaScript redirect method (`kntnt_ad_attr_redirect_method` → `'js'`) may improve this in some cases, but there are no guarantees.

**What if a visitor clicks more than 50 ads?**

The cookie stores a maximum of 50 ad hashes. When the limit is reached, the oldest hash is removed to make room for the new one.

**What if the same visitor submits the form twice?**

The plugin uses a deduplication mechanism. If a visitor triggers a conversion within the cooldown period (default: 30 days) after a previous conversion, the second submission is not counted. After the cooldown period, a new submission is counted as a new conversion. The cooldown can be changed via the `kntnt_ad_attr_dedup_days` filter.

**What about cookie consent / GDPR?**

The plugin is designed to keep all data on your own server — no personal data is sent to any third party. This eliminates the third-country transfer issues that affect solutions like Google's Enhanced Conversions. However, the `_ad_clicks` cookie constitutes personal data processing under GDPR and requires consent. The plugin supports a three-state consent model (yes, no, undefined) and integrates with any cookie consent plugin via the `kntnt_ad_attr_has_consent` filter. Click counting (aggregated statistics per tracking URL) is always performed regardless of consent, since it does not identify or track individuals. See [Privacy and GDPR](#privacy-and-gdpr) for a full discussion and [Connecting a Cookie Consent Plugin](#connecting-a-cookie-consent-plugin) for implementation details.

**How can I get help or report a bug?**

Please visit the plugin's [issue tracker on GitHub](https://github.com/kntnt/kntnt-ad-attribution/issues) to ask questions, report bugs, or view existing discussions.

**How can I contribute?**

Contributions are welcome! Please feel free to fork the repository and submit a pull request on GitHub.