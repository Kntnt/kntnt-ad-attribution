#!/usr/bin/env bash
# Integration test: Conversion deduplication.
#
# Verifies that dedup_seconds=0 (default) allows repeated conversions
# for the same hash, since per-hash deduplication is disabled by default.

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

# --- Cleanup ---

set_consent_state "default"

print_summary
