#!/usr/bin/env bash
# Integration test: Internal traffic and test mode.
#
# Verifies that a logged-in user with kntnt_ad_attr records neither a click
# nor a conversion, including a conversion submitted over REST without a
# nonce; that the documented one-liner switches that off; that a test click
# (kntnt_ad_attr_test=1) is recorded and flagged for both anonymous and
# internal visitors without forwarding the parameter; and that a test
# conversion is written but kept out of the main view, its totals and the
# CSV export, and reaches only reporters that declare supports_test.
#
# The test environment switches the internal default off for all other
# suites (test-helpers.php); this suite switches it on explicitly.

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/helpers/assertions.sh"
source "$SCRIPT_DIR/helpers/fixtures.sh"

echo "=== Test: Internal Traffic and Test Mode ==="

CAMPAIGN="internal-traffic"
UA="Mozilla/5.0 Chrome/120.0.0.0"

# --- Setup ---

clear_cookies
set_consent_state "granted"
set_probe_reporter "on"
set_probe_recorded "on"
reset_probe_calls
set_option "test_probe_recorded_calls" ""
set_internal_default "on"

TARGET_JSON=$(create_target_page "Internal Traffic Landing")
TARGET_ID=$(echo "$TARGET_JSON" | jq -r '.post_id')
HASH=$(create_tracking_url "$TARGET_ID" "google" "cpc" "$CAMPAIGN")
flush_rewrites

# Playground's server sends every cookie any earlier response has set with
# every request, so all requests carry the admin's logged-in cookie from the
# setup login. A visitor request therefore sends X-Test-Visitor: anonymous,
# which makes the test helpers strip the login cookies (test-helpers.php).

# Requests the tracking URL with the admin's logged-in cookie and prints the
# response headers.
# Usage: click_as_admin <query>
click_as_admin() {
    curl -sS -D - -o /dev/null -b "$ADMIN_COOKIE" -A "$UA" --max-redirs 0 \
        "${WP_BASE_URL}/ad/${HASH}/?$1" 2>/dev/null || true
}

# Requests the tracking URL as a new anonymous visitor and prints the
# response headers.
# Usage: click_as_visitor <query>
click_as_visitor() {
    curl -sS -D - -o /dev/null -A "$UA" -H "X-Test-Visitor: anonymous" --max-redirs 0 \
        "${WP_BASE_URL}/ad/${HASH}/?$1" 2>/dev/null || true
}

# Usage: http_status <headers>
http_status() {
    echo "$1" | head -1 | awk '{print $2}'
}

# Usage: location_of <headers>
location_of() {
    echo "$1" | grep -i '^location:' | sed 's/^[Ll]ocation: *//' | tr -d '\r' || true
}

# Usage: count_clicks <extra condition on clicks c, e.g. "AND c.is_test = 1">
count_clicks() {
    query_db "SELECT COUNT(*) AS cnt FROM wp_kntnt_ad_attr_clicks c WHERE c.hash = '${HASH}' $1" | jq -r '.cnt'
}

# Usage: count_conversions
count_conversions() {
    query_db "SELECT COUNT(*) AS cnt FROM wp_kntnt_ad_attr_conversions cv JOIN wp_kntnt_ad_attr_clicks c ON c.id = cv.click_id WHERE c.hash = '${HASH}'" | jq -r '.cnt'
}

# Prints "<clicks>|<conversions>" from the main view's totals row, filtered to
# this suite's campaign, followed by the CSV export's row count for it, as
# "<clicks>|<conversions>|<csv rows>".
# Usage: reported_numbers
reported_numbers() {
    local html totals nonce csv_rows
    html=$(curl -sf -b "${ADMIN_COOKIE}" \
        "${WP_BASE_URL}/wp-admin/tools.php?page=kntnt-ad-attribution&date_start=2020-01-01&date_end=2027-12-31&utm_campaign=${CAMPAIGN}")
    totals=$(echo "$html" | grep -oP '<tr class="kntnt-ad-attr-totals-row">.*?</tr>' | head -1 \
        | grep -oP '<strong>\K[^<]+' | tail -n 2 | paste -sd '|' - || true)
    nonce=$(echo "$html" | grep -oP 'name="kntnt_ad_attr_export_nonce"\s+value="\K[^"]+' | head -1 || true)
    csv_rows=$(curl -sf -b "${ADMIN_COOKIE}" \
        "${WP_BASE_URL}/wp-admin/tools.php?page=kntnt-ad-attribution" \
        -d "kntnt_ad_attr_action=export_csv&kntnt_ad_attr_export_nonce=${nonce}&_wp_http_referer=%2Fwp-admin%2Ftools.php%3Fpage%3Dkntnt-ad-attribution&date_start=2020-01-01&date_end=2027-12-31&utm_campaign=${CAMPAIGN}" \
        | grep -c "$CAMPAIGN" || true)
    echo "${totals}|${csv_rows}"
}

# --- 1. A click by a logged-in user with kntnt_ad_attr is not recorded ---

headers=$(click_as_admin "gclid=INTERNAL")
assert_status "302" "$(http_status "$headers")" "Internal click is redirected"
assert_contains "$(location_of "$headers")" "gclid=INTERNAL" "Internal click keeps query forwarding"
assert_not_contains "$headers" "_ad_clicks=" "Internal click sets no _ad_clicks cookie"
assert_equals "0" "$(count_clicks "")" "Internal click records no click row"

# A visitor without a logged-in session is not internal.
headers=$(click_as_visitor "")
VISITOR_CLICKS=$(extract_cookie_value "$headers" "_ad_clicks")
assert_equals "1" "$(count_clicks "")" "Anonymous visitor's click is recorded"
assert_contains "$VISITOR_CLICKS" "$HASH" "Anonymous visitor gets an _ad_clicks cookie"

# --- 2. A nonce-less REST conversion with the admin's cookie is not recorded ---

trigger_conversion_without_nonce "$VISITOR_CLICKS" > /dev/null
assert_equals "0" "$(count_conversions)" "Internal nonce-less conversion writes no conversion row"
assert_equals "0" "$(get_recorded_calls | jq 'length')" "Internal nonce-less conversion fires no recorded action"
assert_equals "0" "$(get_probe_calls | jq 'length')" "Internal nonce-less conversion calls no reporter"

# --- 3. With the documented one-liner, the same user's click and conversion are recorded ---

# test_internal_default off makes the test helpers return false at
# PHP_INT_MAX, exactly what the documented one-liner does.
set_internal_default "off"
clear_cookies
sleep 1
headers=$(click_as_admin "")
ADMIN_CLICKS=$(extract_cookie_value "$headers" "_ad_clicks")
assert_equals "2" "$(count_clicks "")" "One-liner: the admin's click is recorded"
assert_contains "$ADMIN_CLICKS" "$HASH" "One-liner: the admin gets an _ad_clicks cookie"

trigger_conversion_without_nonce "$ADMIN_CLICKS" > /dev/null
assert_equals "1" "$(count_conversions)" "One-liner: the admin's conversion is recorded"
assert_equals "false" "$(get_recorded_calls | jq -r '.[0].context.test')" "An ordinary conversion is announced with context.test false"
assert_equals "false" "$(get_probe_calls | jq -r '.[0].context.test')" "An ordinary conversion is reported with context.test false"
set_internal_default "on"

BEFORE=$(reported_numbers)
assert_equals "2|1.0|2" "$BEFORE" "Main view, totals and CSV show the two real clicks and one conversion"

# --- 4. Test clicks are recorded and flagged, for visitors and internal users ---

clear_cookies
sleep 1
headers=$(click_as_visitor "kntnt_ad_attr_test=1&gclid=TEST")
assert_status "302" "$(http_status "$headers")" "Anonymous test click is redirected"
assert_not_contains "$(location_of "$headers")" "kntnt_ad_attr_test" "Anonymous test click: parameter is not forwarded"
assert_contains "$(location_of "$headers")" "gclid=TEST" "Anonymous test click: other parameters are forwarded"
assert_equals "1" "$(count_clicks "AND c.is_test = 1")" "Anonymous test click gives a click row flagged as a test"

sleep 1
headers=$(click_as_admin "kntnt_ad_attr_test=1")
TEST_CLICKS=$(extract_cookie_value "$headers" "_ad_clicks")
assert_status "302" "$(http_status "$headers")" "Internal test click is redirected"
assert_not_contains "$(location_of "$headers")" "kntnt_ad_attr_test" "Internal test click: parameter is not forwarded"
assert_equals "2" "$(count_clicks "AND c.is_test = 1")" "Internal test click gives a click row flagged as a test"
assert_contains "$TEST_CLICKS" "$HASH" "Internal test click sets the _ad_clicks cookie"

# --- 5. A test conversion is written, announced as a test and reported only to reporters with supports_test ---

reset_probe_calls
set_option "test_probe_recorded_calls" ""
trigger_conversion_without_nonce "$TEST_CLICKS" > /dev/null
assert_equals "2" "$(count_conversions)" "Internal conversion after a test click writes a conversion row"
assert_equals "true" "$(get_recorded_calls | jq -r '.[0].context.test')" "Test conversion is announced with context.test true"
assert_equals "0" "$(get_probe_calls | jq 'length')" "Reporter without supports_test is not called for a test conversion"

set_probe_reporter "supports_test"
trigger_conversion_without_nonce "$TEST_CLICKS" > /dev/null
assert_equals "1" "$(get_probe_calls | jq 'length')" "Reporter with supports_test is called for a test conversion"
assert_equals "true" "$(get_probe_calls | jq -r '.[0].context.test')" "Reporter with supports_test receives context.test true"

AFTER=$(reported_numbers)
assert_equals "$BEFORE" "$AFTER" "Main view, totals and CSV are unchanged by test clicks and test conversions"

# --- Cleanup ---

set_internal_default "off"
reset_probes
set_consent_state "default"
clear_cookies

print_summary
