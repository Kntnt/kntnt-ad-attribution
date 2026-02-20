# Consent Scenarios

This document describes the expected plugin behaviour for every combination of consent state, existing cookies, and visitor action. The three consent states correspond to the return value of `apply_filters( 'kntnt_ad_attr_has_consent', null )`: `true` (granted), `false` (denied), or `null` (undetermined). The implementation must match this specification exactly.

## Consent granted (`true`)

### Case 1 – No cookies, visits tracking URL outside dedup window

| | |
|---|---|
| **Cookies saved?** | Yes |
| **Click recorded?** | Yes |
| **Conversion attributed?** | – |

### Case 2 – No cookies, visits tracking URL within dedup window

| | |
|---|---|
| **Cookies saved?** | Yes |
| **Click recorded?** | Yes |
| **Conversion attributed?** | – |

Without a cookie to read, click deduplication cannot be performed. The click is recorded even though the dedup window is active. The dedup window is controlled by the `kntnt_ad_attr_dedup_seconds` filter (default: 0 = disabled).

### Case 3 – Has cookies, visits tracking URL outside dedup window

| | |
|---|---|
| **Cookies saved?** | Yes |
| **Click recorded?** | Yes |
| **Conversion attributed?** | – |

Existing cookie is updated with the new hash.

### Case 4 – Has cookies, visits tracking URL within dedup window

| | |
|---|---|
| **Cookies saved?** | Yes |
| **Click recorded?** | No |
| **Conversion attributed?** | – |

Existing cookie is updated. Consent allows cookie reading, so the server can detect the duplicate. The click is silently skipped. The dedup window is controlled by the `kntnt_ad_attr_dedup_seconds` filter.

### Case 5 – No cookies, submits lead form

| | |
|---|---|
| **Cookies saved?** | No |
| **Click recorded?** | – |
| **Conversion attributed?** | No |

No cookie means no data to attribute.

### Case 6 – Has cookies, submits lead form

| | |
|---|---|
| **Cookies saved?** | Yes |
| **Click recorded?** | – |
| **Conversion attributed?** | Yes |

Existing cookie is updated. Attribution uses the default last-click model. Customise via the `kntnt_ad_attr_attribution` filter.

## Consent denied (`false`)

### Case 7 – No cookies, visits tracking URL outside dedup window

| | |
|---|---|
| **Cookies saved?** | No |
| **Click recorded?** | Yes |
| **Conversion attributed?** | – |

Click records contain no personal data (only hash, timestamp, and UTM fields) and are recorded regardless of consent state.

### Case 8 – No cookies, visits tracking URL within dedup window

| | |
|---|---|
| **Cookies saved?** | No |
| **Click recorded?** | Yes |
| **Conversion attributed?** | – |

Same reasoning as case 2: no cookie to read, so dedup is impossible.

### Case 9 – Has cookies (set lawfully under prior consent), visits tracking URL outside dedup window

| | |
|---|---|
| **Cookies saved?** | No |
| **Click recorded?** | Yes |
| **Conversion attributed?** | – |

ePrivacy Directive Article 5(3) regulates two acts: storing information and gaining access to stored information. Passive existence of a lawfully set cookie is neither. The plugin does not delete or read the cookie. To delete cookies when consent is denied, a CMP can call `kntnt_ad_attribution_delete_cookies()` or fire the `kntnt_ad_attr_delete_cookies` action — see the consent integration documentation.

### Case 10 – Has cookies (set lawfully under prior consent), visits tracking URL within dedup window

| | |
|---|---|
| **Cookies saved?** | No |
| **Click recorded?** | Yes |
| **Conversion attributed?** | – |

Gaining access to information stored on the user's terminal equipment requires consent under ePrivacy Article 5(3). Without consent, the cookie cannot be read for dedup. The click is recorded.

### Case 11 – No cookies, submits lead form

| | |
|---|---|
| **Cookies saved?** | No |
| **Click recorded?** | – |
| **Conversion attributed?** | No |

### Case 12 – Has cookies (set lawfully under prior consent), submits lead form

| | |
|---|---|
| **Cookies saved?** | No |
| **Click recorded?** | – |
| **Conversion attributed?** | No |

Gaining access to the `_ad_clicks` cookie requires consent under ePrivacy Article 5(3). With consent denied, the cookie cannot be read and no attribution is performed. The cookies remain on the device (passive existence is not regulated). To delete them, the CMP can call `kntnt_ad_attribution_delete_cookies()` or fire the `kntnt_ad_attr_delete_cookies` action.

## Consent undetermined (`null`)

### Case 13 – No cookies, visits tracking URL outside dedup window

| | |
|---|---|
| **Cookies saved?** | No |
| **Click recorded?** | Yes |
| **Conversion attributed?** | – |

The new hash is transported to the landing page via the `_aah_pending` cookie (60 s, treated as strictly necessary) or a URL fragment (`#_aah=<hash>`). The method is controlled by the `kntnt_ad_attr_pending_transport` filter (`'cookie'` or `'fragment'`). Once consent is resolved on the landing page, the client-side script either persists the hash (consent granted) or discards it (consent denied).

### Case 14 – No cookies, visits tracking URL within dedup window

| | |
|---|---|
| **Cookies saved?** | No |
| **Click recorded?** | Yes |
| **Conversion attributed?** | – |

Same reasoning as cases 2 and 8: no cookie to read, so dedup is impossible regardless of the dedup window.

### Case 15 – Has cookies (set lawfully under prior consent), visits tracking URL outside dedup window

| | |
|---|---|
| **Cookies saved?** | No |
| **Click recorded?** | Yes |
| **Conversion attributed?** | – |

Cookies were set lawfully and consent has not been denied (`null` means undetermined, not denied). Existing cookies are left intact but are not read — gaining access requires confirmed consent under ePrivacy Article 5(3). The new hash is handled via pending consent transport.

### Case 16 – Has cookies (set lawfully under prior consent), visits tracking URL within dedup window

| | |
|---|---|
| **Cookies saved?** | No |
| **Click recorded?** | Yes |
| **Conversion attributed?** | – |

Existing cookies are left intact; new hash is handled via pending consent transport. Gaining access requires consent. Without confirmed consent, the cookie cannot be read for dedup, so the click is recorded. Same principle as cases 10 and 15.

### Case 17 – No cookies, submits lead form

| | |
|---|---|
| **Cookies saved?** | No |
| **Click recorded?** | – |
| **Conversion attributed?** | No |

### Case 18 – Has cookies (set lawfully under prior consent), submits lead form

| | |
|---|---|
| **Cookies saved?** | No |
| **Click recorded?** | – |
| **Conversion attributed?** | No |

Gaining access to the `_ad_clicks` cookie requires confirmed consent under ePrivacy Article 5(3). With consent undetermined, the cookie cannot be read for attribution. If consent is later granted (e.g. on a subsequent page load), the pending consent mechanism will persist any pending hashes and future conversions can be attributed.

## Bot detection (all consent states)

### Cases 19–21 – Browser identified as a bot

| | |
|---|---|
| **Consent state** | `true`, `false`, or `null` |
| **Cookies saved?** | No |
| **Click recorded?** | No |
| **Conversion attributed?** | No |

Bot detection runs before any other processing. Bots are redirected to the target page without recording a click or setting cookies. Bot signatures are filterable via `kntnt_ad_attr_is_bot`.
