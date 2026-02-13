# Kntnt Ad Attribution

[![Requires WordPress: 698+](https://img.shields.io/badge/WordPress-6.9+-blue.svg)](https://wordpress.org)
[![Requires PHP: 8.3+](https://img.shields.io/badge/PHP-8.3+-blue.svg)](https://php.net)
[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2+-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)


A WordPress plugin that provides internal lead attribution for ad campaigns using first-party cookies and fractional, time-weighted attribution.

## Description

Kntnt Ad Attribution lets you measure which ads generate leads by tracking ad clicks through short URLs and attributing form submissions back to the originating ads.

Each ad gets a unique hash-based tracking URL (e.g. `example.com/ad/a1b2c3…`). When a visitor clicks an ad, the hash is stored in a first-party cookie. When the visitor later submits a lead form, the plugin reads the cookie and distributes the conversion fractionally across all clicked ads, with more recent clicks weighted higher.

The plugin is platform-agnostic and works with any ad platform — Google Ads, Meta Ads, LinkedIn Ads, Microsoft Ads, or any other source that can link to a custom URL.

The plugin does not hardcode integrations with any specific consent management or form plugin. Instead, it exposes hooks that you connect to your preferred plugins via your theme's `functions.php`, a mu-plugin, or a code snippet plugin.

### Key Features

- **Hash-based tracking URLs** — each ad gets a unique `/ad/<hash>` URL (prefix configurable via filter), independent of ad platform.
- **First-party cookie tracking** — stores clicked ad hashes in a single `HttpOnly`, `Secure`, `SameSite=Lax` cookie (`_ad_clicks`), with a 90-day lifetime.
- **Fractional attribution with linear time-weighting** — if a visitor clicked multiple ads, each gets a share of the conversion weighted by recency. More recent clicks count more.
- **Deduplication** — repeated form submissions within a configurable cooldown period (default: 30 days) are not counted as new conversions.
- **Cookie size management** — stores a maximum of 20 ad hashes per visitor, pruning the oldest when the limit is reached.
- **Statistics dashboard** — view clicks, conversions, and fractional attribution per campaign for any date range, with CSV export.
- **Platform-agnostic consent** — integrates with any cookie consent plugin via a filter hook.
- **Platform-agnostic form support** — integrates with any form plugin via an action hook.
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
- **No feedback to ad platforms.** The plugin provides internal statistics only. It does not send conversion data back to Google Ads, Meta, or any other platform. This means it cannot improve the ad platform's bidding or AI optimization. For that, server-side API integration (e.g. Google Ads Conversion API with `gclid`) is needed separately.
- **Cookies can be cleared.** If the visitor clears their cookies or uses a private/incognito window for the return visit, the attribution link is broken.
- **Cross-device tracking is not supported.** A visitor who clicks an ad on their phone but converts on their laptop will not be attributed.

Despite these limitations, server-side first-party cookie tracking captures significantly more conversions than pure client-side tracking, and gives you a reliable internal baseline for comparing ad performance.

## Installation

1. [Download the latest release ZIP file](https://github.com/Kntnt/kntnt-ad-attribution/releases/latest/download/kntnt-ad-attribution.zip).
2. In your WordPress admin panel, go to **Plugins → Add New**.
3. Click **Upload Plugin** and select the downloaded ZIP file.
4. Activate the plugin.

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

On activation, the plugin registers a custom capability: `kntnt_ad_attribution`. This capability is automatically granted to the **Administrator**, **Editor**, and (in multisite) **Super Admin** roles. Only users with this capability can access the Ad Conversions page and manage tracking URLs.

To grant access to other roles (e.g. Author), use a role management plugin such as [Members](https://wordpress.org/plugins/members/) or [User Role Editor](https://wordpress.org/plugins/user-role-editor/) to assign the `kntnt_ad_attribution` capability.

## Cookie Consent Configuration

If you use a cookie consent plugin (such as [Real Cookie Banner](https://devowl.io/wordpress-real-cookie-banner/), which is an excellent and recommended choice), you need to register the plugin's cookies as a marketing service. Here is the information you need:

| Field              | Value                        |
|--------------------|------------------------------|
| Service name       | Kntnt Ad Attribution         |
| Provider           | Kntnt (own service)          |
| Category           | Marketing                    |
| Purpose            | Stores information about which ads the visitor has clicked in order to measure which ads lead to inquiries via the website's forms. No data is shared with third parties. |
| Legal basis        | Consent                      |

Register the following cookies:

| Property | Cookie 1       | Cookie 2          |
|----------|----------------|-------------------|
| Type     | HTTP Cookie    | HTTP Cookie       |
| Name     | `_ad_clicks`   | `_ad_last_conv`   |
| Host     | `.yourdomain.com` | `.yourdomain.com` |
| Duration | 90 days        | 90 days           |
| Session  | No             | No                |

Replace `.yourdomain.com` with your actual domain.

You also need to connect the consent plugin to this plugin via the `kntnt_ad_attribution_has_consent` filter. See [Connecting a Cookie Consent Plugin](#connecting-a-cookie-consent-plugin) for details and a complete Real Cookie Banner example.

## Usage

### Admin Interface

The plugin adds **Ad Conversions** under **Tools** in the WordPress admin menu. The page has two tabs:

#### Campaigns Tab (default)

This is where you view attribution results.

- **Filters:** Filter by tracking URL, original URL, any UTM/MTM dimension (source, medium, campaign, content, term), and date range. All filters can be combined.
- **Summary:** Shows total clicks and total (fractional) conversions for the selected filters.
- **Results table:** Lists each tracking URL with its hash, original URL, UTM/MTM parameters, click count, fractional conversion count, and creation date.
- **Export:** Export the filtered results as a CSV file.

**Note:** The plugin tracks clicks (each request to `/ad/<hash>`) and conversions. Ad impressions are not available since they occur on the ad platform and never reach your server.

#### URLs Tab

This is where you create and manage tracking URLs.

- **Create new URL:** Select a page and fill in UTM/MTM parameters (source, medium, campaign, content, term). The plugin generates a SHA-256 hash and produces a tracking URL: `https://yourdomain.com/ad/<hash>`.
- **URL list:** Shows all created tracking URLs with the original URL, UTM/MTM values, creation date, and a delete button. The list can be filtered by campaign URL, original URL, UTM/MTM values, and creation date.
- **Bulk actions:** Select multiple entries and delete them in one operation.

### How Attribution Works

When a visitor clicks a tracking URL, the plugin logs the click, stores the ad hash in a first-party cookie, and redirects the visitor to the landing page.

When a conversion is triggered (see [Connecting a Form Plugin](#connecting-a-form-plugin)), the plugin:

1. Reads the `_ad_clicks` cookie.
2. Identifies all ad hashes the visitor has clicked.
3. Gives each hash a time-weighted share of the conversion: a click *d* days old receives weight *max(90 − d, 1)*, and all weights are normalized to sum to 1.
4. Stores the fractional values in the database with a timestamp.

If the visitor triggers another conversion within the cooldown period (default: 30 days, configurable), it is not counted.

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

The plugin does not integrate with any specific consent plugin. Instead, it checks for consent via the `kntnt_ad_attribution_has_consent` filter before setting cookies. If no filter callback is registered, the plugin falls back to a configurable default (see `kntnt_ad_attribution_default_consent`).

**Example with Real Cookie Banner:**

First, create a service in Real Cookie Banner with the unique identifier `kntnt-ad-attribution` and the cookie information from the [Cookie Consent Configuration](#cookie-consent-configuration) section above.

Then add the following snippet to your theme's `functions.php`, a mu-plugin, or a code snippet plugin:

```php
add_filter( 'kntnt_ad_attribution_has_consent', function () {

    if ( ! function_exists( 'wp_rcb_consent_given' ) ) {
        return false;
    }

    $consent = wp_rcb_consent_given( 'kntnt-ad-attribution' );

    // wp_rcb_consent_given() returns cookieOptIn = true when the service
    // doesn't exist in RCB. Guard against that by checking that the
    // service was actually found.
    return ! empty( $consent['cookie'] ) && $consent['cookieOptIn'];

} );
```

This ensures cookies are only set when the visitor has explicitly consented to the "Kntnt Ad Attribution" service in the cookie banner. If Real Cookie Banner is deactivated or the service doesn't exist, no cookies are set.

## Developer Hooks

### Filters

**`kntnt_ad_attribution_has_consent`**

Controls whether the plugin has consent to set cookies for the current visitor. Return `true` to allow cookies, `false` to block them. When no callback is registered, the plugin falls back to the value of `kntnt_ad_attribution_default_consent`.

```php
add_filter( 'kntnt_ad_attribution_has_consent', function () {
    // Your consent logic here
    return true;
} );
```

**`kntnt_ad_attribution_default_consent`**

Controls the fallback consent behavior when no callback is registered on `kntnt_ad_attribution_has_consent`. Default: `true` (cookies are set).

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

Filters the cookie lifetime in days. Default: `90`.

```php
add_filter( 'kntnt_ad_attribution_cookie_lifetime', function () {
    return 30;
} );
```

**`kntnt_ad_attribution_max_hashes`**

Filters the maximum number of ad hashes stored in the cookie. Default: `20`. When the limit is reached, the oldest hash is removed.

```php
add_filter( 'kntnt_ad_attribution_max_hashes', function () {
    return 10;
} );
```

**`kntnt_ad_attribution_dedup_days`**

Filters the deduplication cooldown in days. Form submissions within this period after a previous conversion are not counted. Default: `30`.

```php
add_filter( 'kntnt_ad_attribution_dedup_days', function () {
    return 14;
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

**What if I want even better attribution accuracy?**

The main limitations of this plugin — ITP cookie caps and no feedback to ad platforms — can be addressed by storing the ad platform's click identifier (e.g. Google's `gclid`) server-side and sending conversions back via the platform's API. A companion plugin for server-side Google Ads attribution is planned.

**What if a visitor clicks more than 20 ads?**

The cookie stores a maximum of 20 ad hashes. When the limit is reached, the oldest hash is removed to make room for the new one. The maximum can be changed via the `kntnt_ad_attribution_max_hashes` filter.

**What if the same visitor submits the form twice?**

The plugin uses a deduplication mechanism. If a visitor triggers a conversion within the cooldown period (default: 30 days) after a previous conversion, the second submission is not counted. After the cooldown period, a new submission is counted as a new conversion. The cooldown can be changed via the `kntnt_ad_attribution_dedup_days` filter.

**How can I get help or report a bug?**

Please visit the plugin's [issue tracker on GitHub](https://github.com/kntnt/kntnt-ad-attribution/issues) to ask questions, report bugs, or view existing discussions.

**How can I contribute?**

Contributions are welcome! Please feel free to fork the repository and submit a pull request on GitHub.
