#!/usr/bin/env bash
# Integration test: Conversion and click deduplication.
#
# Verifies that dedup_seconds=0 (default) allows repeated conversions
# for the same hash, since per-hash deduplication is disabled by default.
#
# With dedup enabled and consent granted, verifies that a re-click within
# the window keeps the cookie's original timestamp, so a later conversion
# links to the one recorded click, and that a cookie entry without a
# matching click row is repaired by recording the click.

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/helpers/assertions.sh"
source "$SCRIPT_DIR/helpers/fixtures.sh"

echo "=== Test: Conversion Deduplication ==="

# --- Setup ---

clear_cookies

set_consent_state "granted"

TARGET_JSON=$(create_target_page "Dedup Landing")
TARGET_ID=$(echo "$TARGET_JSON" | jq -r '.post_id')

HASH=$(create_tracking_url "$TARGET_ID" "google" "cpc" "dedup-test")
flush_rewrites

# Click and extract _ad_clicks value from Set-Cookie headers.
cookies=$(get_click_cookies "$HASH")
ad_clicks=$(extract_cookie_value "$cookies" "_ad_clicks")

# --- First conversion succeeds ---

trigger_conversion "$ad_clicks" > /dev/null

conv_count=$(query_db "SELECT COUNT(*) AS cnt FROM wp_kntnt_ad_attr_conversions AS c JOIN wp_kntnt_ad_attr_clicks AS cl ON c.click_id = cl.id WHERE cl.hash='${HASH}'")
cnt1=$(echo "$conv_count" | jq -r '.cnt')
assert_greater_than "0" "$cnt1" "First conversion recorded"

# --- Second conversion also succeeds (dedup disabled by default) ---

# kntnt_ad_attr_dedup_seconds defaults to 0, meaning no deduplication.
# Both conversions for the same hash should be recorded.
trigger_conversion "$ad_clicks" > /dev/null

conv_count2=$(query_db "SELECT COUNT(*) AS cnt FROM wp_kntnt_ad_attr_conversions AS c JOIN wp_kntnt_ad_attr_clicks AS cl ON c.click_id = cl.id WHERE cl.hash='${HASH}'")
cnt2=$(echo "$conv_count2" | jq -r '.cnt')
assert_greater_than "$cnt1" "$cnt2" "Second conversion recorded (dedup disabled by default)"

# --- Click dedup: re-click within the window keeps the original click ---

# Dedup reads _ad_clicks on click, and earlier trigger_conversion calls
# leave it in WASM PHP's persistent $_COOKIE.
clear_cookies
set_dedup_seconds 86400

HASH_DEDUP=$(create_tracking_url "$TARGET_ID" "google" "cpc" "click-dedup-test")
flush_rewrites

# First click by a visitor without plugin cookies, so a click row is
# recorded.
cookies_first=$(curl -sS -D - -o /dev/null \
    -A "Mozilla/5.0 Chrome/120" \
    -H "$(plugin_cookies)" \
    --max-redirs 0 \
    "${WP_BASE_URL}/ad/${HASH_DEDUP}/" 2>/dev/null || true)
ad_clicks_first=$(extract_cookie_value "$cookies_first" "_ad_clicks")
assert_equals "1" "$(get_click_count "$HASH_DEDUP")" "First click records a click row"
ts_first=$(echo "$ad_clicks_first" | grep -oP "${HASH_DEDUP}:\K[0-9]+" || true)
assert_greater_than "0" "${ts_first:-0}" "First click sets an _ad_clicks entry for the hash"

# clicked_at has one-second resolution; make sure a rewritten timestamp
# would differ from the original.
sleep 2

# Second click within the window, carrying the first click's cookie.
headers_second=$(curl -sS -D - -o /dev/null \
    -A "Mozilla/5.0 Chrome/120" \
    --max-redirs 0 \
    -H "$(plugin_cookies "_ad_clicks=${ad_clicks_first}")" \
    "${WP_BASE_URL}/ad/${HASH_DEDUP}/" 2>/dev/null || true)
assert_contains "$headers_second" "302" "Deduplicated re-click still redirects"

ad_clicks_second=$(extract_cookie_value "$headers_second" "_ad_clicks")
ts_second=$(echo "$ad_clicks_second" | grep -oP "${HASH_DEDUP}:\K[0-9]+" || true)
assert_equals "$ts_first" "$ts_second" "Re-click re-sends the cookie with the original timestamp"

click_rows=$(query_db "SELECT COUNT(*) AS cnt FROM wp_kntnt_ad_attr_clicks WHERE hash='${HASH_DEDUP}'")
assert_equals "1" "$(echo "$click_rows" | jq -r '.cnt')" "Re-click within the window records no second click row"

# Conversion with the cookie the browser now holds.
trigger_conversion "$ad_clicks_second" > /dev/null

click_row=$(query_db "SELECT id FROM wp_kntnt_ad_attr_clicks WHERE hash='${HASH_DEDUP}'")
click_row_id=$(echo "$click_row" | jq -r '.id')
conv_rows=$(query_db_rows "SELECT cv.click_id FROM wp_kntnt_ad_attr_conversions cv JOIN wp_kntnt_ad_attr_clicks c ON c.id = cv.click_id WHERE c.hash='${HASH_DEDUP}'")
assert_equals "1" "$(echo "$conv_rows" | jq 'length')" "Conversion after a re-click records exactly one conversion row"
assert_equals "$click_row_id" "$(echo "$conv_rows" | jq -r '.[0].click_id')" "Conversion is linked to the original click row"

# --- Click dedup: entry without a click row is repaired ---

clear_cookies

HASH_HEAL=$(create_tracking_url "$TARGET_ID" "google" "cpc" "click-dedup-heal")
flush_rewrites

# An entry inside the window that no click row matches, as written by
# versions that rewrote the timestamp on dedup, next to an entry for
# another tracking URL. The other entry surviving shows that the plugin
# read the cookie.
ts_stale=$(( $(date +%s) - 60 ))
HASH_OTHER=$(openssl rand -hex 32)
headers_heal=$(curl -sS -D - -o /dev/null \
    -A "Mozilla/5.0 Chrome/120" \
    --max-redirs 0 \
    -H "$(plugin_cookies "_ad_clicks=${HASH_OTHER}:${ts_stale},${HASH_HEAL}:${ts_stale}")" \
    "${WP_BASE_URL}/ad/${HASH_HEAL}/" 2>/dev/null || true)

heal_rows=$(query_db_rows "SELECT clicked_at FROM wp_kntnt_ad_attr_clicks WHERE hash='${HASH_HEAL}'")
assert_equals "1" "$(echo "$heal_rows" | jq 'length')" "Entry without a click row is handled as a new click"

ad_clicks_heal=$(extract_cookie_value "$headers_heal" "_ad_clicks")
assert_contains "$ad_clicks_heal" "${HASH_OTHER}:${ts_stale}" "The click read the visitor's cookie"
ts_heal=$(echo "$ad_clicks_heal" | grep -oP "${HASH_HEAL}:\K[0-9]+" || true)
assert_greater_than "$ts_stale" "${ts_heal:-0}" "Repaired entry no longer carries the stale timestamp"
heal_clicked_at=$(echo "$heal_rows" | jq -r '.[0].clicked_at')
ts_heal_utc=$(jq -rn --argjson t "${ts_heal:-0}" '$t | strftime("%Y-%m-%d %H:%M:%S")')
assert_equals "$heal_clicked_at" "$ts_heal_utc" "Repaired entry points at the new click row"

# --- Cleanup ---

set_dedup_seconds 0
set_consent_state "default"

print_summary
