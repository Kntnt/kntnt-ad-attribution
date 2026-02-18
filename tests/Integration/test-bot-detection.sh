#!/usr/bin/env bash
# Integration test: Bot detection and filtering.
#
# Verifies that known bot user agents do not record clicks,
# while normal user agents do. All requests should still redirect.

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/helpers/assertions.sh"
source "$SCRIPT_DIR/helpers/fixtures.sh"

echo "=== Test: Bot Detection ==="

# --- Setup ---

TARGET_JSON=$(create_target_page "Bot Detection Landing")
TARGET_ID=$(echo "$TARGET_JSON" | jq -r '.post_id')

HASH=$(create_tracking_url "$TARGET_ID" "google" "cpc" "bot-test")
flush_rewrites

# Clear any existing clicks for this hash.
execute_sql "DELETE FROM wp_kntnt_ad_attr_clicks WHERE hash='${HASH}'" > /dev/null

# --- Googlebot: no click recorded, still redirects ---

result=$(simulate_click "$HASH" "Googlebot/2.1 (+http://www.google.com/bot.html)")
status=$(echo "$result" | cut -d'|' -f1)
assert_status "302" "$status" "Googlebot gets 302 redirect"

count=$(get_click_count "$HASH")
assert_equals "0" "$count" "Googlebot click not recorded"

# --- curl UA: no click recorded ---

simulate_click "$HASH" "curl/7.68.0" > /dev/null
count=$(get_click_count "$HASH")
assert_equals "0" "$count" "curl UA click not recorded"

# --- Empty UA: no click recorded ---

simulate_click "$HASH" "" > /dev/null
count=$(get_click_count "$HASH")
assert_equals "0" "$count" "Empty UA click not recorded"

# --- HeadlessChrome UA: no click recorded ---

simulate_click "$HASH" "Mozilla/5.0 HeadlessChrome/120.0" > /dev/null
count=$(get_click_count "$HASH")
assert_equals "0" "$count" "HeadlessChrome click not recorded"

# --- Normal Chrome UA: click recorded ---

simulate_click "$HASH" "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36" > /dev/null
count=$(get_click_count "$HASH")
assert_greater_than "0" "$count" "Normal Chrome UA click recorded"

print_summary
