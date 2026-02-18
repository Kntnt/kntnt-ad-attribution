#!/usr/bin/env bash
# Integration test: Basic click tracking flow.
#
# Verifies that clicks on tracking URLs redirect correctly, record clicks
# in the database, store per-click UTM fields, and handle MTM fallback.

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/helpers/assertions.sh"
source "$SCRIPT_DIR/helpers/fixtures.sh"

echo "=== Test: Click Flow ==="

clear_cookies

# --- Setup: create target page and tracking URL ---

TARGET_JSON=$(create_target_page "Click Flow Landing")
TARGET_ID=$(echo "$TARGET_JSON" | jq -r '.post_id')
TARGET_PERMALINK=$(echo "$TARGET_JSON" | jq -r '.permalink')

HASH=$(create_tracking_url "$TARGET_ID" "google" "cpc" "spring-sale")
flush_rewrites

# --- Valid hash redirects with 302 ---

result=$(simulate_click "$HASH")
status=$(echo "$result" | cut -d'|' -f1)
redirect_url=$(echo "$result" | cut -d'|' -f2)
assert_status "302" "$status" "Valid hash returns 302 redirect"
assert_contains "$redirect_url" "$TARGET_PERMALINK" "Redirect URL matches target permalink"

# --- Invalid hash returns 404 ---

status=$(curl -sS -o /dev/null -w "%{http_code}" \
    --max-redirs 0 \
    "${WP_BASE_URL}/ad/not-a-valid-hash/" 2>/dev/null || true)
assert_status "404" "$status" "Invalid hash format returns 404"

# --- Unknown hash (valid format, not in DB) returns 404 ---

unknown_hash=$(openssl rand -hex 32)
status=$(curl -sS -o /dev/null -w "%{http_code}" \
    --max-redirs 0 \
    "${WP_BASE_URL}/ad/${unknown_hash}/" 2>/dev/null || true)
assert_status "404" "$status" "Unknown hash (valid format) returns 404"

# --- Click recorded in database ---

count=$(get_click_count "$HASH")
assert_greater_than "0" "$count" "Click recorded in clicks table"

# --- Per-click UTM fields stored ---

HASH2=$(create_tracking_url "$TARGET_ID" "facebook" "cpm" "summer")
flush_rewrites

simulate_click "$HASH2" "Mozilla/5.0 Chrome/120" "utm_content=banner1&utm_term=keyword1" > /dev/null
row=$(query_db "SELECT utm_content, utm_term FROM wp_kntnt_ad_attr_clicks WHERE hash='${HASH2}' ORDER BY id DESC LIMIT 1")
utm_content=$(echo "$row" | jq -r '.utm_content')
utm_term=$(echo "$row" | jq -r '.utm_term')
assert_equals "banner1" "$utm_content" "Per-click utm_content stored"
assert_equals "keyword1" "$utm_term" "Per-click utm_term stored"

# --- MTM fallback works ---

HASH3=$(create_tracking_url "$TARGET_ID" "matomo" "display" "mtm-test")
flush_rewrites

simulate_click "$HASH3" "Mozilla/5.0 Chrome/120" "mtm_content=mtm-banner&mtm_keyword=mtm-kw" > /dev/null
row=$(query_db "SELECT utm_content, utm_term FROM wp_kntnt_ad_attr_clicks WHERE hash='${HASH3}' ORDER BY id DESC LIMIT 1")
mtm_content=$(echo "$row" | jq -r '.utm_content')
mtm_term=$(echo "$row" | jq -r '.utm_term')
assert_equals "mtm-banner" "$mtm_content" "MTM fallback: mtm_content stored as utm_content"
assert_equals "mtm-kw" "$mtm_term" "MTM fallback: mtm_keyword stored as utm_term"

# --- Click count increments ---

simulate_click "$HASH" > /dev/null
count_after=$(get_click_count "$HASH")
assert_greater_than "$count" "$count_after" "Click count increments on second click"

print_summary
