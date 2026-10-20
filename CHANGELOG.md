# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- A click-ID capturer can be an array, `[ 'parameter' => 'gbraid', 'unique' => false ]`, to declare that its parameter is not unique per click, as with Google's `gbraid` and `wbraid`. Such values are stored per click and reach reporters as before, but take no part in repeat detection. Capturers registered as a parameter name are unique, as before.

### Fixed

- A click ID shared by many clicks could make a new visitor's click count as a repeat of an earlier visitor's click. The new click was not recorded, and its conversion was reported with the earlier click's click IDs. Declaring such a capturer non-unique prevents this.

## [1.10.0] – 2026-10-20

### Added

- A list of recorded conversions on **Tools → Ad Attribution**, with actions to delete a conversion or mark it as a test (and unmark it). Both actions also delete the conversion's report jobs that have not been sent. Each report job now records which conversion it belongs to.
- Internal traffic is excluded by default. Clicks and conversions by logged-in users with the `kntnt_ad_attr` capability are not recorded. The new `kntnt_ad_attr_is_internal` filter controls this, and one documented line switches it off.
- Test mode. Appending `?kntnt_ad_attr_test=1` to a tracking URL makes a test click. Its conversion is recorded but left out of the statistics and the CSV export. It reaches only conversion reporters that declare `'supports_test' => true`, and they get `$context['test'] = true`.
- The `kntnt_ad_attr_is_speculative` filter. Speculative prefetch and prerender requests to a tracking URL get a 503 response and are not recorded.
- With logging enabled, every request to a tracking URL writes one diagnostic line with its outcome (recorded, repeat, deduplicated, speculative, bot, internal or failed) and its `Sec-Purpose`, `Purpose` and `User-Agent` headers.
- README sections on testing a live setup and on excluding tracking URLs from page caches.

### Changed

- Platform click IDs are stored per click instead of per tracking URL. Reporters get the click ID and the per-click fields (content, term, id, group) of the click the conversion is attributed to, and `captured_at` is that click's time. The 1.10.0 migration drops click IDs stored by earlier versions, because they cannot be linked to a click. Queued report jobs keep their payloads.
- Click IDs are deleted together with their click rows instead of after a fixed 120 days.
- A request that repeats a platform click ID already recorded on the same tracking URL, such as a reload or a bookmarked ad URL, counts as a repeat and not as a new click.
- The deferred-consent transport (`_aah_pending` cookie, `#_aah=` fragment) and the internal `set-cookie` REST endpoint carry `hash:timestamp` pairs instead of bare hashes.

### Fixed

- Conversions were reported with the click ID of the latest visitor to the tracking URL instead of the converting visitor's own click ID.
- A deduplicated re-click moved the cookie entry to a time with no click row, so the visitor's later conversion was not recorded.
- The `kntnt_ad_attr_conversion_recorded` action and conversion reporters received attributions that were not recorded, including zero-weight ones. They now receive only recorded attributions with a weight above 0, and nothing fires when no conversion row was written.
- After deferred consent, the visitor's cookie pointed at the latest click on the tracking URL instead of the visitor's own click.
- A failed click insert no longer writes a cookie entry or fires `kntnt_ad_attr_click` for a click that was not recorded.
- The README overstated Safari ITP's effect on the server-set `_ad_clicks` cookie and presented the `js` redirect method as an ITP mitigation.
