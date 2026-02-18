# Kntnt Ad Attribution

[![Requires WordPress: 6.9+](https://img.shields.io/badge/WordPress-6.9+-blue.svg)](https://wordpress.org)
[![Requires PHP: 8.3+](https://img.shields.io/badge/PHP-8.3+-blue.svg)](https://php.net)
[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2+-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)


A privacy-friendly WordPress plugin for lead attribution that keeps all data on your own server — no personal data is sent to Google or any other third party. Works with any ad platform.

## Description

Ad platforms like Google Ads offer conversion tracking features — such as Google's Enhanced Conversions — that improve attribution by sending hashed personal data (email addresses, phone numbers, names) from your website to the ad platform's servers. This raises significant privacy concerns: personal data leaves your domain, is transferred to servers that may be located outside the EU, and is used by the ad platform for its own purposes beyond your control. With the EU-US Data Privacy Framework under ongoing legal challenge and the possibility that it may be invalidated (as its predecessors Safe Harbor and Privacy Shield were), relying on such mechanisms creates regulatory risk for EU-based businesses.

Kntnt Ad Attribution takes a different approach. It gives you the same core benefit — knowing which ads actually generate leads — but keeps all data on your own server. No personal data is sent to Google, Meta, or any other third party.

Each ad gets a unique tracking URL (e.g. `example.com/ad/a1b2c3…`). When a visitor clicks an ad, your server records the click, stores the ad hash in a first-party cookie, and redirects the visitor to the landing page. When the visitor later submits a lead form, the server reads the cookie and attributes the conversion to the most recently clicked ad (filterable to support multi-touch models). The entire process happens on your infrastructure.

The plugin is platform-agnostic and works with any ad platform — Google Ads, Meta Ads, LinkedIn Ads, Microsoft Ads, or any other source that can link to a custom URL. This means you get a single, consistent attribution method across all your advertising channels.

The plugin does not hardcode integrations with any specific consent management or form plugin. Instead, it exposes hooks that you connect to your preferred plugins via your theme's `functions.php`, a mu-plugin, or a code snippet plugin.

### Key Features

- **Hash-based tracking URLs** — each ad gets a unique `/ad/<hash>` URL (prefix configurable via filter), independent of ad platform.
- **First-party cookie tracking** — stores clicked ad hashes in a single `HttpOnly`, `Secure`, `SameSite=Lax` cookie (`_ad_clicks`), with a configurable lifetime (default: 90 days).
- **Filterable last-click attribution** — by default, the most recently clicked ad receives full conversion credit. The attribution model is filterable via the `kntnt_ad_attr_attribution` hook, enabling multi-touch models (e.g. time-weighted, linear, position-based).
- **Deduplication** — repeated form submissions within a configurable cooldown period (default: 30 days) are not counted as new conversions.
- **Cookie size management** — stores a maximum of 50 ad hashes per visitor, pruning the oldest when the limit is reached.
- **Campaign dashboard** — view clicks, conversions, and fractional attribution per campaign for any date range, with CSV export. Individual click records include per-click Content, Term, Id, and Group fields.
- **Three-state consent model** — integrates with any cookie consent plugin via a filter hook, supporting yes, no, and undefined consent states with a transport mechanism for deferred consent.
- **Platform-agnostic form support** — integrates with any form plugin via an action hook.
- **Bot detection** — filters out known bots via User-Agent matching and `robots.txt` rules.
- **Two redirect methods** — 302 redirect (default) or JavaScript redirect via filter, providing flexibility for different ITP mitigation strategies.
- **Companion plugin hooks** — fires `kntnt_ad_attr_click` on every non-bot click with hash, target URL, campaign data, and access to URL parameters (gclid, fbclid, etc.). Companion plugins can capture platform-specific data and implement server-side API integrations without modifying the core plugin.
- **Query parameter forwarding** — ad platform parameters (gclid, fbclid, msclkid, etc.) appended to the tracking URL are automatically forwarded to the target page through the redirect. Target URL parameters take precedence, and the merged set is filterable.
- **Adapter infrastructure for add-ons** — a built-in adapter system lets add-on plugins or code snippets register click-ID capturers (for `gclid`, `fbclid`, `msclkid`, etc.) and conversion reporters (for Google Ads, Meta, Matomo, GA4, etc.). The core captures click IDs and processes a report queue; adapters define what to capture and where to report. If no adapters are registered, the plugin behaves identically to previous versions.

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
- **No built-in feedback to ad platforms.** The core plugin provides internal statistics only and does not include integrations with any external API. However, the adapter system allows add-on plugins to register click-ID capturers and conversion reporters for any ad platform or analytics tool. See [Adapter System](#adapter-system) for details.
- **Cookies can be cleared.** If the visitor clears their cookies or uses a private/incognito window for the return visit, the attribution link is broken.
- **Cross-device tracking is not supported.** A visitor who clicks an ad on their phone but converts on their laptop will not be attributed.
- **Impressions are not measured.** Ad views occur on the ad platform and never reach the server.
- **No multisite support.** Version 1 does not support WordPress multisite.

Despite these limitations, server-side first-party cookie tracking captures significantly more conversions than pure client-side tracking, and gives you a reliable internal baseline for comparing ad performance.

### Privacy and GDPR

This plugin is designed with data minimization and data locality as core principles. Here is how it relates to common privacy concerns:

**No personal data leaves your server.** Unlike Google's Enhanced Conversions or Meta's Advanced Matching, this plugin never transmits personal data — hashed or otherwise — to any third party. All attribution data stays in your WordPress database on your own infrastructure. This is the plugin's fundamental privacy advantage. Note: if you install add-on plugins that report conversions to external services (such as Google Ads or Matomo), those add-ons will transmit data to external servers. The core plugin itself never does — it only provides the infrastructure that add-ons use.

**No third-country transfer problem.** Because no data is sent to external servers, there is no dependency on the EU-US Data Privacy Framework, Standard Contractual Clauses, or any other international data transfer mechanism. If the Data Privacy Framework is invalidated by a future court ruling (as Safe Harbor and Privacy Shield were before it), this plugin is unaffected.

**The cookie constitutes personal data under GDPR.** The `_ad_clicks` cookie links a visitor's ad clicks to their subsequent form submissions, which makes it personal data processing. The plugin therefore requires consent for the cookie, and implements a three-state consent model (yes, no, undefined) that integrates with any cookie consent plugin. See [Cookie Consent Configuration](#cookie-consent-configuration) for details.

**Click counting does not require consent.** The plugin always logs that a click occurred on a tracking URL (recording a click record), regardless of consent status. This is analogous to server access logs and does not constitute personal data processing, since no individual is identified or identifiable from the click record alone.

**Hashing is used for URL generation, not for pseudonymization of personal data.** The SHA-256 hashes in this plugin are generated from random bytes (`random_bytes(32)`) and serve as opaque identifiers for tracking URLs. They are not derived from any personal data or UTM parameters — they are purely random identifiers. This is a fundamental difference from Enhanced Conversions, where personal data is hashed and sent to Google.

**The `_aah_pending` cookie is a borderline case.** This temporary cookie (maximum 60 seconds) contains only an ad hash and serves as a technical transport mechanism for deferred consent scenarios. It contains no personal data in itself. Whether it should be classified as "necessary" or "marketing" is a judgment call that depends on your interpretation; the plugin's consent configuration section presents both options. See [Cookie Consent Configuration](#cookie-consent-configuration).

**Data minimization.** The plugin stores the minimum data needed for attribution: opaque hashes in a cookie and individual click/conversion records in the database. No names, email addresses, IP addresses, or other directly identifying information is stored by the plugin.

---

## For Users

This section is for marketers, website owners, WordPress administrators, and agency staff who want to install, configure, and use the plugin.

### Installation

1. [Download the latest release ZIP file](https://github.com/Kntnt/kntnt-ad-attribution/releases/latest/download/kntnt-ad-attribution.zip).
2. In your WordPress admin panel, go to **Plugins → Add New**.
3. Click **Upload Plugin** and select the downloaded ZIP file.
4. Activate the plugin.

The plugin is distributed via GitHub Releases and updated through the standard WordPress plugin update UI. When a new version is available, you will see it on the WordPress Updates page.

#### System Requirements

| Requirement | Minimum |
|-------------|---------|
| PHP | 8.3 |
| WordPress | 6.9 |
| HTTPS | Required (cookies are set with the `Secure` flag) |
| MySQL/MariaDB | 5.7 / 10.3 |

The plugin checks the PHP version on activation and aborts with a clear error message if the requirement is not met.

#### Permissions

On activation, the plugin registers a custom capability: `kntnt_ad_attr`. This capability is automatically granted to the **Administrator** and **Editor** roles. Only users with this capability can access the Ad Attribution page and manage tracking URLs.

To grant access to other roles (e.g. Author), use a role management plugin such as [Members](https://wordpress.org/plugins/members/) or [User Role Editor](https://wordpress.org/plugins/user-role-editor/) to assign the `kntnt_ad_attr` capability.

### Cookie Consent Configuration

If you use a cookie consent plugin (such as [Real Cookie Banner](https://devowl.io/wordpress-real-cookie-banner/), which is an excellent and recommended choice), you need to register the plugin's cookies as a marketing service. Here is the information you need:

| Field | Value |
|-------|-------|
| Service name | Ad Attribution |
| Service identifier | `kntnt-ad-attribution` |
| Provider | Website owner (first-party service) |
| Category | Marketing |
| Purpose | Stores information about which ads the visitor has clicked in order to measure which ads lead to inquiries via the website's forms. No data is shared with third parties. |
| Legal basis | Consent (opt-in) |
| Data processing countries | The country (or countries) where the site is hosted, and potentially other countries (consult your legal advisor). |

Register the following cookies:

| Property | Cookie 1 | Cookie 2 | Cookie 3 |
|----------|----------|----------|----------|
| Name | `_ad_clicks` | `_ad_last_conv` | `_aah_pending` |
| Type | HTTP Cookie | HTTP Cookie | HTTP Cookie |
| Host | `.yourdomain.com` | `.yourdomain.com` | `.yourdomain.com` |
| Duration | 90 days* | 30 days** | 60 seconds |
| Purpose | Stores which ads the visitor has clicked in order to attribute form inquiries to the ads that generated them. | Stores the time of the most recent form inquiry to prevent duplicate counting of the same visitor. | Temporary transport of ad hash to the landing page while the visitor has not yet made a cookie consent decision. Automatically deleted after 60 seconds. |
| Category | Marketing | Marketing | Necessary or Marketing*** |

Replace `.yourdomain.com` with your actual domain.

\* Configurable via the `kntnt_ad_attr_cookie_lifetime` filter (default: 90 days).

\** Configurable via the `kntnt_ad_attr_dedup_days` filter (default: 30 days).

\*** `_aah_pending` contains no personal data, lives at most 60 seconds, and serves only as a technical transport mechanism for deferred consent scenarios. Whether to classify it as "Necessary" or "Marketing" is a judgment call — see [Privacy and GDPR](#privacy-and-gdpr) for a discussion.

### Usage

#### Admin Interface

The plugin adds **Ad Attribution** under **Tools** in the WordPress admin menu. The page has two tabs:

##### URLs Tab (default)

This is where you create and manage tracking URLs.

- **Create new URL:** Select a target page via a searchable dropdown and fill in the required parameter fields: source, medium, and campaign. Source and medium offer predefined options (configurable via the `kntnt_ad_attr_utm_options` filter) but also accept custom values. Content, Term, Id, and Group are not set at creation time — they vary per click and are captured automatically from incoming UTM or MTM parameters (see [Click-Time Parameter Population](#click-time-parameter-population)). The plugin generates a SHA-256 hash and produces a tracking URL: `https://yourdomain.com/ad/<hash>`.
- **URL list:** Shows all created tracking URLs with full tracking URL, target URL, source, medium, and campaign. Click a tracking URL to copy it to the clipboard. The list can be filtered by source, medium, and campaign.
- **Row actions:** Trash (or Restore / Delete Permanently for trashed URLs).

##### Campaigns Tab

This is where you view attribution results.

- **Filters:** Filter by date range and dimensions (source, medium, campaign). A search box allows searching by tracking URL or hash. All filters can be combined.
- **Summary:** Shows total clicks and total (fractional) conversions for the selected filters.
- **Results table:** Lists each tracking URL with its target URL, source, medium, campaign, click count, and fractional conversion count.
- **Export:** Export the filtered results as a CSV file (UTF-8 with BOM; semicolon delimiter when the locale uses comma as decimal separator). The CSV includes all fields including per-click Content, Term, Id, and Group from the clicks table.

**Note:** The plugin tracks clicks (each request to `/ad/<hash>`) and conversions. Ad impressions are not available since they occur on the ad platform and never reach your server.

#### Click-Time Parameter Population

Source, medium, and campaign are set when creating the tracking URL. If any are left empty (e.g. from pre-v1.5.0 URLs), the click handler populates them at click time from incoming query parameters. Content, Term, Id, and Group are always captured per click — they vary between clicks on the same tracking URL and are stored in the clicks table, not in postmeta.

Both UTM and MTM (Matomo Tag Manager) parameter formats are supported:

| Field | Storage | UTM param | MTM param |
|---|---|---|---|
| Source | Postmeta (fixed per URL) | `utm_source` | `mtm_source` |
| Medium | Postmeta (fixed per URL) | `utm_medium` | `mtm_medium` |
| Campaign | Postmeta (fixed per URL) | `utm_campaign` | `mtm_campaign` |
| Content | Clicks table (per click) | `utm_content` | `mtm_content` |
| Term | Clicks table (per click) | `utm_term` | `mtm_keyword` |
| Id | Clicks table (per click) | `utm_id` | `mtm_cid` |
| Group | Clicks table (per click) | `utm_source_platform` | `mtm_group` |

For Source/Medium/Campaign, the **priority order** (highest first) is:

1. **Stored value** (set by admin when creating the tracking URL) — never overwritten.
2. **Incoming UTM parameter** from query string.
3. **Incoming MTM parameter** from query string.

#### How Attribution Works

When a visitor clicks a tracking URL, the plugin logs the click, stores the ad hash in a first-party cookie (if consent is given), and redirects the visitor to the landing page.

If consent is undefined (the visitor hasn't decided yet), the hash is transported to the landing page via a temporary cookie (`_aah_pending`, 60 seconds) or a URL fragment. A client-side script picks up the hash and stores it in `sessionStorage` until consent is resolved.

When a conversion is triggered (see [Connecting a Form Plugin](#connecting-a-form-plugin) in the Builders section), the plugin:

1. Checks for deduplication — if a conversion was already recorded within the cooldown period (default: 30 days), the new one is ignored.
2. Reads the `_ad_clicks` cookie and extracts all ad hashes.
3. Filters out hashes that no longer exist as registered tracking URLs.
4. If no valid hashes remain, exits without recording anything.
5. Applies the attribution model (default: last-click — the most recent click receives `1.0`, all others receive `0.0`). The model is filterable via `kntnt_ad_attr_attribution`.
6. Looks up the matching click records and stores conversion rows in the database within a transaction.

### User FAQ

**What problem does this plugin solve?**

Ad platforms report clicks but not which clicks became leads on your website. Standard client-side tracking (JavaScript tags) is increasingly blocked by ad blockers, Safari's ITP, and privacy-focused browsers. This plugin moves the tracking to the server side, where it is immune to ad blockers and more resilient to browser restrictions. It gives you an internal, independent view of which ads actually generate leads. See [The Problem](#the-problem) and [Limitations](#limitations) for details.

**Which ad platforms does this plugin support (Google Ads, Meta Ads, …)?**

All of them. The plugin is platform-agnostic. It works with any ad platform that lets you set a custom destination URL — Google Ads, Meta Ads, LinkedIn Ads, Microsoft Ads, and any other platform.

**How does this compare to Google's Enhanced Conversions?**

Both this plugin and Enhanced Conversions aim to improve ad attribution beyond what basic client-side tracking provides. The key difference is where the data goes. Enhanced Conversions sends hashed personal data (email, phone, name) to Google's servers, where it is matched against Google accounts. This plugin keeps all data on your own server and never sends personal data to any third party. The trade-off is that this plugin cannot feed conversion data back to the ad platform's bidding algorithms — it provides internal attribution statistics only. See [Privacy and GDPR](#privacy-and-gdpr) for a detailed comparison.

**What about cookie consent / GDPR?**

The plugin is designed to keep all data on your own server — no personal data is sent to any third party. This eliminates the third-country transfer issues that affect solutions like Google's Enhanced Conversions. However, the `_ad_clicks` cookie constitutes personal data processing under GDPR and requires consent. The plugin supports a three-state consent model (yes, no, undefined) and integrates with any cookie consent plugin. See [Privacy and GDPR](#privacy-and-gdpr) for a full discussion and [Cookie Consent Configuration](#cookie-consent-configuration) for the cookie details to register.

**What happens with Safari's Intelligent Tracking Prevention (ITP)?**

Safari's ITP may limit the cookie lifetime to 7 days (or less) when the visitor arrives from a classified cross-site source such as a Google Ads click. This means conversions that happen more than 7 days after the ad click may not be attributed on Safari/iOS. A JavaScript redirect method is available as a filter option that may improve this in some cases, but there are no guarantees.

**What if a visitor clicks more than 50 ads?**

The cookie stores a maximum of 50 ad hashes. When the limit is reached, the oldest hash is removed to make room for the new one.

**What if the same visitor submits the form twice?**

The plugin uses a deduplication mechanism. If a visitor triggers a conversion within the cooldown period (default: 30 days) after a previous conversion, the second submission is not counted. After the cooldown period, a new submission is counted as a new conversion. The cooldown can be changed via the `kntnt_ad_attr_dedup_days` filter.

**Does the plugin send conversion data back to the ad platform?**

Not by itself. The core plugin provides internal attribution statistics only and never communicates with external APIs. However, the adapter system lets add-on plugins register conversion reporters that report to any ad platform or analytics tool. See the [Adapter System](#adapter-system) section under Builders.

**How can I get help or report a bug?**

Please visit the plugin's [issue tracker on GitHub](https://github.com/kntnt/kntnt-ad-attribution/issues) to ask questions, report bugs, or view existing discussions.

---

## For Builders

This section is for developers who want to integrate the plugin with form plugins, consent plugins, or other systems using WordPress hooks. It assumes familiarity with PHP and WordPress development.

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

Consent integration requires two parts: a **PHP filter** for server-side consent checks (at click time and when setting cookies via REST), and a **JavaScript override** for client-side deferred consent handling (when the visitor hasn't decided yet at the time of the click).

If no filter callback is registered on `kntnt_ad_attr_has_consent`, the plugin falls back to `kntnt_ad_attr_default_consent` (default: `true`), which is appropriate for sites without consent requirements.

#### PHP: Server-side consent filter

The `kntnt_ad_attr_has_consent` filter must return `true`, `false`, or `null`. It is critical to distinguish between "the visitor has not made a decision yet" (`null`) and "the visitor has actively denied consent" (`false`). Returning `false` when the visitor simply hasn't decided yet will prevent the deferred transport mechanism from activating, and the attribution will be silently lost.

**Example with Real Cookie Banner:**

First, create a service in Real Cookie Banner with the unique identifier `kntnt-ad-attribution` and the cookie information from the [Cookie Consent Configuration](#cookie-consent-configuration) section above.

Then add the following snippet to your theme's `functions.php`, a mu-plugin, or a code snippet plugin:

```php
add_filter( 'kntnt_ad_attr_has_consent', function (): ?bool {
    if ( ! function_exists( 'wp_rcb_consent_given' ) ) {
        return null; // RCB not active.
    }
    $consent = wp_rcb_consent_given( 'kntnt-ad-attribution' );
    if ( empty( $consent['cookie'] ) ) {
        return null; // Service not configured in RCB.
    }
    if ( ! $consent['consentGiven'] ) {
        return null; // Visitor has not made a decision yet.
    }
    return $consent['cookieOptIn'] === true;
} );
```

Note the `consentGiven` check: `wp_rcb_consent_given()` returns `cookieOptIn: false` both when the visitor has actively denied consent *and* when they simply haven't interacted with the banner yet. The `consentGiven` field distinguishes between these two cases — it is `true` only after the visitor has made an explicit choice.

#### PHP: Server-side cookie deletion on opt-out

The `_ad_clicks` cookie is set with the `HttpOnly` flag for security, which prevents consent plugins from deleting it via client-side JavaScript. If your consent plugin provides a server-side hook for cookie deletion, use it to expire the cookie when consent is revoked.

**Example with Real Cookie Banner:**

```php
add_action( 'RCB/OptOut/ByHttpCookie', function ( string $name, string $host ): void {
    if ( $name === '_ad_clicks' ) {
        setcookie( '_ad_clicks', '', [
            'expires'  => 1,
            'path'     => '/',
            'secure'   => true,
            'httponly'  => true,
            'samesite' => 'Lax',
        ] );
    }
}, 10, 2 );
```

This hook fires during the REST request that Real Cookie Banner makes when the visitor revokes consent, allowing the `HttpOnly` cookie to be expired server-side. For other consent plugins, consult their documentation for an equivalent server-side opt-out hook or event.

#### JavaScript: Client-side deferred consent

For deferred consent scenarios, the plugin defines a default `window.kntntAdAttributionGetConsent` function that the client-side script (`pending-consent.js`) calls to determine consent status. The default implementation calls the callback with `'unknown'`, which keeps the hashes in `sessionStorage` indefinitely. Override this function **before `DOMContentLoaded`** to connect to your consent plugin's JavaScript API.

The callback accepts `'yes'`, `'no'`, or `'unknown'`. The script handles multiple invocations via an internal `handled` flag — only the first `'yes'` or `'no'` takes effect.

**Example with Real Cookie Banner:**

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

### Developer Hooks

#### Filters

**`kntnt_ad_attr_has_consent`**

Controls whether the plugin has consent to set cookies for the current visitor. Return `true` to allow cookies, `false` to block them, or `null` for undefined (triggers deferred consent transport). When no callback is registered, the plugin falls back to the value of `kntnt_ad_attr_default_consent`.

> [!IMPORTANT]
> Returning `false` when the visitor simply hasn't decided yet will silently prevent the deferred transport mechanism from activating. Make sure your implementation distinguishes "no decision yet" (`null`) from "actively denied" (`false`). See [Connecting a Cookie Consent Plugin](#connecting-a-cookie-consent-plugin) for a detailed example.

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

Controls bot detection. Default: `false`. The plugin registers its own callback that checks the User-Agent (case-insensitive substring match) against these signatures: `bot` (catches Googlebot, Bingbot, LinkedInBot, AdsBot-Google, etc.), `crawl`, `spider`, `slurp`, `facebookexternalhit`, `Mediapartners-Google`, `Yahoo`, `curl`, `wget`, `python-requests`, `HeadlessChrome`, `Lighthouse`, `GTmetrix`. Empty User-Agents are also treated as bots. Bots are redirected to the target page without logging the click or setting a cookie. The plugin also adds `Disallow: /<prefix>/` to the virtual `robots.txt`.

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

**`kntnt_ad_attr_click_id_capturers`**

Registers platform-specific GET parameters to capture at ad click time. Return an associative array mapping platform identifiers to GET parameter names. Default: `[]`.

```php
add_filter( 'kntnt_ad_attr_click_id_capturers', function ( array $capturers ): array {
    $capturers['google_ads'] = 'gclid';
    $capturers['meta']       = 'fbclid';
    return $capturers;
} );
```

**`kntnt_ad_attr_conversion_reporters`**

Registers conversion reporters for async processing. Return an associative array mapping reporter IDs to reporter definitions. Default: `[]`.

Reporter definition:

| Key | Type | Description |
|-----|------|-------------|
| `label` | `string` | Name for logging and admin UI. |
| `enqueue` | `callable` | Called at conversion time. Signature: `( array $attributions, array $click_ids, array $campaigns, array $context ) → array` of payloads. |
| `process` | `callable` | Called by queue processor. Signature: `( array $payload ) → bool`. |

See [Adapter System](#adapter-system) for full examples and documentation.

**`kntnt_ad_attr_admin_tabs`**

Filters the admin page tab list. Add-on plugins can register custom tabs by adding slug → label entries. Unrecognized tab slugs dispatch to the `kntnt_ad_attr_admin_tab_{$tab}` action for rendering.

```php
add_filter( 'kntnt_ad_attr_admin_tabs', function ( array $tabs ): array {
    $tabs['settings'] = __( 'Settings', 'my-addon' );
    return $tabs;
} );
```

**`kntnt_ad_attr_redirect_query_params`**

Filters the merged query parameters before building the redirect URL. When a visitor clicks a tracking URL with extra query parameters (e.g. `/ad/<hash>?gclid=abc`), these are forwarded to the target page. Target URL parameters take precedence over incoming ones.

```php
add_filter( 'kntnt_ad_attr_redirect_query_params', function ( array $merged, array $target, array $incoming ): array {
    // Prevent internal tracking parameters from leaking to the landing page.
    unset( $merged['fbclid'], $merged['gclid'] );
    return $merged;
}, 10, 3 );
```

**`kntnt_ad_attr_attribution`**

Filters the attribution weights for a conversion. Receives an associative array of hash => fractional value (default: 1.0 for the most recent click, 0.0 for all others) and an array of click data with timestamps. Must return an array where values sum to 1.0.

```php
// Example: time-weighted multi-click attribution.
add_filter( 'kntnt_ad_attr_attribution', function ( array $attributions, array $clicks ): array {
    $lifetime = (int) apply_filters( 'kntnt_ad_attr_cookie_lifetime', 90 );
    $now      = time();
    $weights  = [];
    foreach ( $clicks as $click ) {
        $days = ( $now - $click['clicked_at'] ) / DAY_IN_SECONDS;
        $weights[ $click['hash'] ] = max( $lifetime - $days, 1 );
    }
    $total = array_sum( $weights );
    return array_map( fn( float $w ) => $w / $total, $weights );
}, 10, 2 );
```

**`kntnt_ad_attr_click_retention_days`**

Filters the number of days to retain click records. Default: `365`. Clicks older than this are deleted by the daily cron job, along with their linked conversions.

```php
add_filter( 'kntnt_ad_attr_click_retention_days', function () {
    return 180;
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

#### Actions

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

Trigger this action to record a conversion for the current visitor. The plugin reads the visitor's cookie and performs the attribution. This is the hook you connect your form plugin to.

```php
do_action( 'kntnt_ad_attr_conversion' );
```

**`kntnt_ad_attr_conversion_recorded`**

Fires after a conversion has been successfully recorded. Receives the array of attributed hashes with their fractional values and a context array with metadata.

Parameters:

| Parameter | Type | Description |
|-----------|------|-------------|
| `$attributions` | `array<string, float>` | Hash => fractional attribution value (sums to 1.0). |
| `$context` | `array` | Associative array with keys: `timestamp` (ISO 8601 UTC), `ip` (visitor IP), `user_agent` (visitor user-agent string), `page_url` (URL of the page where conversion occurred). |

```php
add_action( 'kntnt_ad_attr_conversion_recorded', function ( array $attributions, array $context ): void {
    // $attributions = [ 'a1b2c3…' => 0.7, 'd4e5f6…' => 0.3 ]
    // $context = [ 'timestamp' => '2025-02-15T14:30:00+00:00', 'ip' => '…', 'user_agent' => '…', 'page_url' => '…' ]
}, 10, 2 );
```

### Adapter System

The adapter system lets add-on plugins (or code snippets) extend the core with platform-specific functionality without modifying the core plugin. It has two components:

#### Click-ID Capturers

A *click-ID capturer* tells the core which URL parameter to capture at ad click time. For example, Google Ads appends `gclid` to the landing page URL, Meta appends `fbclid`, and Microsoft Ads appends `msclkid`. Register a capturer via the `kntnt_ad_attr_click_id_capturers` filter:

```php
add_filter( 'kntnt_ad_attr_click_id_capturers', function ( array $capturers ): array {
    $capturers['google_ads'] = 'gclid';
    return $capturers;
} );
```

The core handles sanitization, validation, and storage. Click IDs are stored in a dedicated database table and associated with the tracking URL hash.

#### Conversion Reporters

A *conversion reporter* tells the core how to report a conversion to an external service. Each reporter defines two callbacks:

- **`enqueue`** — called synchronously at conversion time. Receives attribution data, click IDs, campaign data, and context. Returns an array of payloads to be queued for async processing.
- **`process`** — called asynchronously by the queue processor. Receives a single payload and performs the actual API call. Returns `true` on success, `false` on failure.

```php
add_filter( 'kntnt_ad_attr_conversion_reporters', function ( array $reporters ): array {
    $reporters['my_platform'] = [
        'label'   => 'My Platform',
        'enqueue' => function ( array $attributions, array $click_ids, array $campaigns, array $context ): array {
            // Build payloads for each attributed hash that has a click ID.
            $payloads = [];
            foreach ( $attributions as $hash => $value ) {
                $click_id = $click_ids[ $hash ]['my_platform'] ?? '';
                if ( $click_id !== '' ) {
                    $payloads[] = [
                        'click_id'  => $click_id,
                        'value'     => $value,
                        'timestamp' => $context['timestamp'],
                    ];
                }
            }
            return $payloads;
        },
        'process' => function ( array $payload ): bool {
            // Make HTTP request to external API.
            $response = wp_remote_post( 'https://api.example.com/conversions', [
                'body' => wp_json_encode( $payload ),
            ] );
            return ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200;
        },
    ];
    return $reporters;
} );
```

The core handles infrastructure: storage, queueing, retry logic (max 3 attempts), and daily cleanup. The adapter handles only the platform-specific logic. A simple click-ID capturer requires ~5 lines of PHP. A full HTTP-based reporter requires ~60 lines. Integrations that require Composer dependencies (e.g. Google Ads API client library) should be implemented as separate plugins.

If no adapters are registered, the plugin behaves identically to previous versions — the adapter infrastructure adds zero overhead.

### Builder FAQ

**What is the adapter system?**

The adapter system is a set of filter hooks that let add-on plugins (or code snippets) extend the core with platform-specific functionality. A *click-ID capturer* tells the core which URL parameter to capture (e.g. `gclid` for Google Ads), and a *conversion reporter* tells the core how to report a conversion to an external service. The core handles storage, queueing, retry logic, and cleanup — the adapter handles only the platform-specific logic. If no adapters are registered, the plugin behaves identically to previous versions.

**Do I need to write code to use this plugin?**

For basic usage you need to connect at least a form plugin to trigger conversions (via the `kntnt_ad_attr_conversion` action). If you use a cookie consent plugin, you also need to connect it via the `kntnt_ad_attr_has_consent` filter. Some form plugins (like WS Form) support firing action hooks directly from the UI, which requires no code. For other form and consent plugins, a short PHP snippet is needed — see [Connecting a Form Plugin](#connecting-a-form-plugin) and [Connecting a Cookie Consent Plugin](#connecting-a-cookie-consent-plugin) for ready-to-use examples.

**Can I implement a custom attribution model?**

Yes. The default is last-click (the most recent click gets 100% credit). You can implement any model — time-weighted, linear, position-based, or something custom — by hooking into the `kntnt_ad_attr_attribution` filter. See the [`kntnt_ad_attr_attribution` filter](#filters) for a working time-weighted example.

**Can I extend the admin interface with custom tabs?**

Yes. Use the `kntnt_ad_attr_admin_tabs` filter to register a tab slug and label, then hook into `kntnt_ad_attr_admin_tab_{$slug}` to render its content. This is intended for add-on plugins that need their own settings or reports page within the plugin's admin area.

**Where can I find detailed technical specifications?**

The repository includes a `docs/` directory with in-depth documentation covering architecture, data model, click flow, conversion flow, cookie handling, consent logic, REST API, client-side script behavior, security, and more. These docs are equally useful for AI coding assistants and human developers.

---

## For Contributors

This section is for developers who want to contribute code to the plugin — cloning the repository, understanding the architecture, running tests, building releases, and submitting pull requests.

### Building from Source

```bash
git clone https://github.com/Kntnt/kntnt-ad-attribution.git
cd kntnt-ad-attribution
wp i18n make-mo languages/
```

> [!NOTE]
> The repository does not include compiled `.mo` translation files. You must compile them from the `.po` source files using either `wp i18n make-mo` ([WP-CLI](https://wp-cli.org/)) or `msgfmt` ([GNU gettext](https://www.gnu.org/software/gettext/)).

> [!TIP]
> The repository includes `CLAUDE.md` and a `docs/` directory with detailed technical documentation. These files are primarily written for [Claude Code](https://docs.anthropic.com/en/docs/claude-code) (an AI coding assistant), giving it the context it needs to work effectively with this codebase. However, they are equally useful for human developers — covering architecture, data model, click and conversion flows, cookie handling, consent logic, security considerations, coding standards, and more.

### Building a Release ZIP

The `build-release-zip.sh` script creates a clean distribution ZIP by removing development files (docs, CLAUDE.md, etc.), compiling `.mo` translation files, and packaging the result. It can build from local files (default) or from a specific git tag.

```bash
# Build from local files → zip in current directory
./build-release-zip.sh

# Build from local files → zip in /tmp
./build-release-zip.sh --output /tmp

# Build from a git tag → zip in current directory
./build-release-zip.sh --tag v1.5.1

# Build from a git tag and upload to an existing GitHub release
./build-release-zip.sh --tag v1.5.1 --update

# Build from a git tag, create a new GitHub release, and upload
./build-release-zip.sh --tag v1.5.1 --create

# Combine --output with --update/--create to save locally AND upload
./build-release-zip.sh --tag v1.5.1 --output . --update
```

Requires `zip` and `msgfmt` (GNU gettext). `gh` ([GitHub CLI](https://cli.github.com/)) is required only with `--update` or `--create`.

### Architecture Overview

**Singleton bootstrap:** `kntnt-ad-attribution.php` loads `autoloader.php` (PSR-4 for `Kntnt\Ad_Attribution\*` → `classes/*.php`), then `Plugin::get_instance()` creates the singleton which instantiates all components and registers hooks. A PHP 8.3 version check aborts with an admin notice if the requirement is not met.

**Component instantiation order in `Plugin::__construct()`:**

1. `Updater` — GitHub-based update checker
2. `Migrator` — database migration runner
3. `Post_Type` — CPT registration
4. `Cookie_Manager` — cookie read/write operations (stateless)
5. `Consent` — three-state consent resolution
6. `Bot_Detector` — User-Agent filtering
7. `Click_ID_Store` — platform-specific click ID storage
8. `Queue` — async job queue
9. `Queue_Processor(Queue)` — queue job dispatcher
10. `Click_Handler(Cookie_Manager, Consent, Bot_Detector, Click_ID_Store)` — click processing & redirect
11. `Conversion_Handler(Cookie_Manager, Click_ID_Store, Queue, Queue_Processor)` — conversion attribution
12. `Cron(Click_ID_Store, Queue)` — scheduled cleanup tasks
13. `Admin_Page(Queue)` — admin UI orchestration
14. `Rest_Endpoint(Cookie_Manager, Consent)` — REST API routes

**Data model:** Tracking URLs are stored as a custom post type `kntnt_ad_attr_url` (with meta `_hash`, `_target_post_id`, `_utm_source`, `_utm_medium`, `_utm_campaign`). Individual clicks are stored in `{prefix}kntnt_ad_attr_clicks` with per-click UTM fields. Conversions are stored in `{prefix}kntnt_ad_attr_conversions` linked to specific clicks via `click_id`, with fractional attribution values. Platform-specific click IDs are stored in `{prefix}kntnt_ad_attr_click_ids` with composite PK `(hash, platform)`. Async report jobs are stored in `{prefix}kntnt_ad_attr_queue` with auto-increment PK and status-based processing.

**Lifecycle:** On activation, the plugin grants capabilities, runs migrations, registers rewrite rules, and schedules cron. On deactivation, it clears cron, transients, and rewrite rules but preserves all data. On uninstallation, it performs complete data removal — dropping all custom tables, deleting CPT posts, removing capabilities, and clearing options.

**Migrator pattern:** Version-based migrations in `migrations/X.Y.Z.php`. Each file returns `function(\wpdb $wpdb): void`. The Migrator compares `kntnt_ad_attr_version` option with the plugin header version on `plugins_loaded` and runs pending files in order.

**Daily cron job** (`kntnt_ad_attr_daily_cleanup`) performs six cleanup tasks: deleting expired click records and their linked conversions, removing orphaned conversions, cleaning up records for deleted tracking URLs, detecting tracking URLs whose target pages no longer exist (setting them to draft with an admin notice), cleaning up old click IDs, and purging completed/failed queue jobs.

### File Structure

```
kntnt-ad-attribution/
├── kntnt-ad-attribution.php      ← Main plugin file (version header, PHP check, bootstrap)
├── autoloader.php                ← PSR-4 autoloader for Kntnt\Ad_Attribution namespace
├── install.php                   ← Activation script (capability, migration, cron)
├── uninstall.php                 ← Uninstall script (complete data removal)
├── README.md                     ← This file
├── CLAUDE.md                     ← AI-focused codebase guidance
├── classes/
│   ├── Plugin.php                ← Singleton, component wiring, hooks, path helpers
│   ├── Updater.php               ← GitHub release update checker
│   ├── Migrator.php              ← Database migration runner (version-based)
│   ├── Post_Type.php             ← CPT registration, shared query helpers
│   ├── Click_Handler.php         ← Ad click processing, redirect, parameter forwarding
│   ├── Conversion_Handler.php    ← Conversion attribution, reporter enqueueing
│   ├── Cookie_Manager.php        ← Cookie read/write/validate (stateless)
│   ├── Consent.php               ← Three-state consent check logic
│   ├── Bot_Detector.php          ← User-Agent filtering, robots.txt rule
│   ├── Rest_Endpoint.php         ← REST API (set-cookie with rate limiting, search-posts)
│   ├── Admin_Page.php            ← Tools page with tab navigation, URL CRUD, CSV export
│   ├── Url_List_Table.php        ← WP_List_Table for the URLs tab
│   ├── Campaign_List_Table.php   ← WP_List_Table for the Campaigns tab
│   ├── Csv_Exporter.php          ← CSV export with locale-aware formatting
│   ├── Utm_Options.php           ← Predefined UTM source/medium options (filterable)
│   ├── Cron.php                  ← Daily cleanup job, target page warnings
│   ├── Click_ID_Store.php        ← Platform-specific click ID storage
│   ├── Queue.php                 ← Async job queue with retry logic
│   └── Queue_Processor.php       ← Queue processing via cron
├── migrations/
│   ├── 1.0.0.php                 ← No-op (legacy stats table, superseded by 1.5.0)
│   ├── 1.2.0.php                 ← Click ID and queue tables
│   └── 1.5.0.php                 ← Clicks + conversions tables, drops stats
├── js/
│   ├── pending-consent.js        ← Client-side: pending consent, sessionStorage, REST call
│   └── admin.js                  ← Admin: select2, page selector, UTM field auto-fill
├── css/
│   └── admin.css                 ← Admin: styling for tabs, page selector, list tables
├── tests/                        ← Integration test scripts (gitignored, local only)
├── docs/                         ← Technical specifications (see below)
└── languages/
    ├── kntnt-ad-attribution.pot          ← Translation template
    └── kntnt-ad-attribution-sv_SE.po     ← Swedish translation source
```

### Technical Documentation

The `docs/` directory contains detailed specifications for every aspect of the plugin. Read the relevant doc before implementing a feature:

| Doc | Covers |
|-----|--------|
| `architecture.md` | Data model, CPT args, hash generation, target URL resolving |
| `click-handling.md` | Click flow, rewrite rules, bot detection, click ID capture, redirect |
| `cookies.md` | Cookie format, attributes, size limits, validation regex |
| `conversion-handling.md` | Conversion flow, dedup, attribution formula, reporter enqueueing |
| `rest-api.md` | REST endpoints (set-cookie, search-posts), rate limiting |
| `client-script.md` | sessionStorage, pending consent, JS consent interface |
| `admin-ui.md` | WP_List_Table tabs, SQL queries, CSV export |
| `developer-hooks.md` | All filters/actions with implementation logic |
| `lifecycle.md` | Activation, deactivation, uninstall, migration, cron |
| `security.md` | Validation, nonces, capabilities, error handling, time zones |
| `coding-standards.md` | Full coding standards reference |
| `file-structure.md` | Project file organization, updates, translations |
| `consent-example.md` | Complete consent integration example (Real Cookie Banner) |
| `testing-strategy.md` | Test plan, frameworks, test inventory |

### Naming Conventions

All machine-readable names use `kntnt-ad-attr` (hyphens) / `kntnt_ad_attr` (underscores) as prefix. The PHP namespace is `Kntnt\Ad_Attribution`. The GitHub URL (`kntnt-ad-attribution`) is the only exception.

| Context | Name |
|---------|------|
| Text domain | `kntnt-ad-attr` |
| Post type | `kntnt_ad_attr_url` |
| Custom tables | `{prefix}kntnt_ad_attr_clicks`, `{prefix}kntnt_ad_attr_conversions`, `{prefix}kntnt_ad_attr_click_ids`, `{prefix}kntnt_ad_attr_queue` |
| Capability | `kntnt_ad_attr` |
| DB version option | `kntnt_ad_attr_version` |
| Cron hooks | `kntnt_ad_attr_daily_cleanup`, `kntnt_ad_attr_process_queue` |
| REST namespace | `kntnt-ad-attribution/v1` |
| REST CPT base | `kntnt-ad-attr-urls` |
| All filters/actions | `kntnt_ad_attr_*` |
| Namespace | `Kntnt\Ad_Attribution` |
| Cookies | `_ad_clicks`, `_aah_pending`, `_ad_last_conv` |

### Coding Standards

The plugin follows WordPress coding standards as a baseline, with several intentional deviations to leverage modern PHP and improve readability. The full reference is in `docs/coding-standards.md`. Key points:

**PHP 8.3 features required:** typed properties, readonly, match expressions, arrow functions, null-safe operator, named arguments, `str_contains()`/`str_starts_with()`, enums, first-class callable syntax, array spread.

**Syntax and style:** `declare(strict_types=1)` in every PHP file. `[]` not `array()`. Natural conditions, not Yoda. Trailing commas in multi-line arrays. Early returns, small functions, descriptive names. PSR-4 autoloading: `Kntnt\Ad_Attribution\Click_Handler` → `classes/Click_Handler.php`.

**WordPress deviations:** No Yoda conditions (strict types eliminate the risk). No `array()` syntax. Namespaces instead of function prefixes. PSR-4 file naming instead of `class-*.php`.

**Documentation:** PHPDoc on every class, method, property, constant. `@since 1.0.0` for initial release. Inline comments explain **why**, not what. Written for senior developers. All identifiers and comments in English.

**JavaScript:** ES6+, IIFE with `'use strict'`, `const` default, arrow functions, `fetch` over jQuery, `async`/`await`, template literals.

**General:** All user-facing strings translatable via `__()` / `esc_html__()` with text domain `kntnt-ad-attr`. All SQL via `$wpdb->prepare()`. All admin URLs via `admin_url()`. All superglobals sanitized. Errors are silent toward visitors, logged via `error_log()`.

### Running Tests

The test suite has two levels: Level 1 (unit tests) and Level 2 (integration tests). The `run-tests.sh` script is the single entry point for running all tests. It checks prerequisites, installs dependencies (Composer and npm), runs the selected test levels, and prints a summary.

```bash
# Run all tests (unit + integration)
bash run-tests.sh

# Run only unit tests (no WordPress Playground needed)
bash run-tests.sh --unit-only

# Run only integration tests
bash run-tests.sh --integration-only

# Filter tests by name pattern
bash run-tests.sh --filter "click"

# Show full test output
bash run-tests.sh --verbose
```

**Level 1 — Unit tests** use Pest with Brain Monkey for PHP and Vitest with happy-dom for JavaScript. They run without WordPress and test individual classes in isolation.

**Level 2 — Integration tests** use Bash scripts with `curl` against a WordPress Playground instance. The runner starts Playground automatically on port 9400, mounts the plugin and test helper mu-plugins, runs all `tests/Integration/test-*.sh` scripts, and stops Playground when done.

Requirements: PHP 8.3+, Node.js 20.18+, Composer, npm, curl, and jq (for integration tests).

A comprehensive test strategy is documented in `docs/testing-strategy.md`, covering test levels, frameworks, directory structure, and a full test inventory.

### Known Gotchas

- `Plugin::get_plugin_data()` must pass `$translate = false` to WP's `get_plugin_data()` to avoid triggering `_load_textdomain_just_in_time` warnings when called before `init` (e.g. from Migrator on `plugins_loaded`).
- The CPT label uses a static string (not `__()`) because it has `show_ui => false` and is never displayed.
- `uninstall.php` runs without the namespace autoloader — use fully qualified function calls and raw `$wpdb`.
- `Post_Type` registers `wp_untrash_post_status` at priority 20 to override ACF (which overrides the default untrash status for all post types).
- The REST `set-cookie` endpoint has rate limiting: 10 requests per minute per IP via transient.
- Select2 loaded from cdnjs has SRI (Subresource Integrity) hashes added via `script_loader_tag` / `style_loader_tag` filters.
- Hash generation uses `random_bytes(32)` — the hash is an opaque identifier, not derived from UTM parameters.
- The `kntnt_ad_attr_click` action fires for all non-bot clicks regardless of consent state, enabling companion plugins to capture platform-specific parameters even before consent is resolved.
- Admin page is registered under Tools (`add_management_page`), not as a top-level menu item.
- CSV export uses POST with its own nonce (`kntnt_ad_attr_export`) and reconstructs GET filter params from POST data for `Campaign_List_Table` compatibility.

### Contributor FAQ

**How can I contribute?**

Contributions are welcome! Fork the repository, make your changes, and submit a pull request on GitHub. Please read the coding standards in `docs/coding-standards.md` and follow the existing patterns in the codebase.

**Where are the detailed specs?**

All specifications live in the `docs/` directory. Read the relevant doc before implementing a feature — see the [Technical Documentation](#technical-documentation) table for a guide to which doc covers what.

**How do I run the tests?**

The integration tests require a local [DDEV](https://ddev.readthedocs.io/) environment with WordPress. Run them with `bash tests/<test-script>.sh` from the project root. See `docs/testing-strategy.md` for the full test plan.

**How does the migration system work?**

Version-based migration files live in `migrations/` and are named after the version they migrate to (e.g. `1.5.0.php`). Each file returns a callable that receives `$wpdb`. The Migrator runs pending files in order when the stored version in `wp_options` differs from the plugin header version.

**What is `CLAUDE.md`?**

It is a context file for [Claude Code](https://docs.anthropic.com/en/docs/claude-code), an AI coding assistant. It provides the AI with architecture, naming conventions, hook references, and coding standards so it can work effectively with the codebase. The information is equally useful for human developers.

**How are releases distributed?**

The plugin is distributed via GitHub Releases, not wordpress.org. The `Updater` class hooks into the WordPress plugin update system and checks for new GitHub releases automatically. Use `build-release-zip.sh` to create a distribution ZIP — see [Building a Release ZIP](#building-a-release-zip).
