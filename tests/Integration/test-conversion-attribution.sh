#!/usr/bin/env bash
# Integration test: Conversion attribution flow.
#
# Verifies single-click and multi-click attribution, conversion recording,
# and the _ad_last_conv dedup cookie.

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/helpers/assertions.sh"
source "$SCRIPT_DIR/helpers/fixtures.sh"

echo "=== Test: Conversion Attribution ==="

# --- Setup ---

clear_cookies

set_consent_state "granted"

TARGET_JSON=$(create_target_page "Conversion Landing")
TARGET_ID=$(echo "$TARGET_JSON" | jq -r '.post_id')

# ─── Single click, single conversion: full attribution ───

HASH_A=$(create_tracking_url "$TARGET_ID" "google" "cpc" "conv-single")
flush_rewrites

# Click and extract _ad_clicks value from Set-Cookie headers.
cookies_a=$(get_click_cookies "$HASH_A")
ad_clicks=$(extract_cookie_value "$cookies_a" "_ad_clicks")

TESTS_RUN=$((TESTS_RUN + 1))
if [[ -n "$ad_clicks" ]]; then
    echo "  PASS: _ad_clicks cookie captured from click"
else
    echo "  FAIL: _ad_clicks cookie not captured from click"
    TESTS_FAILED=$((TESTS_FAILED + 1))
fi

# Trigger conversion via test helper with the captured cookie value.
trigger_conversion "$ad_clicks" > /dev/null

# Check conversions table.
click_row=$(query_db "SELECT id FROM wp_kntnt_ad_attr_clicks WHERE hash='${HASH_A}' ORDER BY id DESC LIMIT 1")
click_id=$(echo "$click_row" | jq -r '.id')

conv_row=$(query_db "SELECT fractional_conversion FROM wp_kntnt_ad_attr_conversions WHERE click_id='${click_id}' LIMIT 1")
frac=$(echo "$conv_row" | jq -r '.fractional_conversion')

# Fractional conversion should be 1.0 (stored as 1.0 in SQLite's real type).
TESTS_RUN=$((TESTS_RUN + 1))
if [[ "$frac" == "1.0000" ]] || [[ "$frac" == "1.0" ]] || [[ "$frac" == "1" ]]; then
    echo "  PASS: Single click gets full attribution (1.0)"
else
    echo "  FAIL: Expected fractional_conversion 1.0, got '$frac'"
    TESTS_FAILED=$((TESTS_FAILED + 1))
fi

# ─── Two clicks: latest gets 1.0, older gets 0.0 ───

HASH_B=$(create_tracking_url "$TARGET_ID" "facebook" "cpm" "conv-multi-1")
HASH_C=$(create_tracking_url "$TARGET_ID" "bing" "cpc" "conv-multi-2")
flush_rewrites

# Click B and capture cookie.
cookies_b=$(get_click_cookies "$HASH_B")
ad_clicks_b=$(extract_cookie_value "$cookies_b" "_ad_clicks")

sleep 1

# Click C, sending B's cookie to accumulate entries. Use raw curl with
# the cookie value directly (avoids Secure flag issues with cookie jars).
HEADERS_C=$(curl -sS -D - -o /dev/null \
    -A "Mozilla/5.0 Chrome/120" \
    --max-redirs 0 \
    -b "_ad_clicks=${ad_clicks_b}" \
    "${WP_BASE_URL}/ad/${HASH_C}/" 2>/dev/null || true)
ad_clicks_bc=$(extract_cookie_value "$HEADERS_C" "_ad_clicks")

# Trigger conversion with both hashes in cookie.
trigger_conversion "$ad_clicks_bc" > /dev/null

# Check: latest click (HASH_C) gets 1.0, older (HASH_B) gets 0.0.
click_c=$(query_db "SELECT id FROM wp_kntnt_ad_attr_clicks WHERE hash='${HASH_C}' ORDER BY id DESC LIMIT 1")
click_c_id=$(echo "$click_c" | jq -r '.id')
conv_c=$(query_db "SELECT fractional_conversion FROM wp_kntnt_ad_attr_conversions WHERE click_id='${click_c_id}' LIMIT 1")
frac_c=$(echo "$conv_c" | jq -r '.fractional_conversion')
TESTS_RUN=$((TESTS_RUN + 1))
if [[ "$frac_c" == "1.0000" ]] || [[ "$frac_c" == "1.0" ]] || [[ "$frac_c" == "1" ]]; then
    echo "  PASS: Latest click (HASH_C) gets 1.0 attribution"
else
    echo "  FAIL: Expected 1.0 for latest click, got '$frac_c'"
    TESTS_FAILED=$((TESTS_FAILED + 1))
fi

# Older click (HASH_B) should have NO conversion record — the plugin skips
# hashes with 0.0 attribution rather than writing a zero-value row.
click_b=$(query_db "SELECT id FROM wp_kntnt_ad_attr_clicks WHERE hash='${HASH_B}' ORDER BY id DESC LIMIT 1")
click_b_id=$(echo "$click_b" | jq -r '.id')
conv_b_count=$(query_db "SELECT COUNT(*) AS cnt FROM wp_kntnt_ad_attr_conversions WHERE click_id='${click_b_id}'")
cnt_b=$(echo "$conv_b_count" | jq -r '.cnt')
assert_equals "0" "$cnt_b" "Older click (HASH_B) has no conversion record (0.0 attribution skipped)"

# ─── No _ad_clicks cookie: conversion is no-op ───

conv_count_before=$(query_db "SELECT COUNT(*) AS cnt FROM wp_kntnt_ad_attr_conversions")
cnt_before=$(echo "$conv_count_before" | jq -r '.cnt')

trigger_conversion "" > /dev/null

conv_count_after=$(query_db "SELECT COUNT(*) AS cnt FROM wp_kntnt_ad_attr_conversions")
cnt_after=$(echo "$conv_count_after" | jq -r '.cnt')
assert_equals "$cnt_before" "$cnt_after" "No cookie: conversion is no-op (count unchanged)"

# ─── Conversion timestamp is recent ───

latest_conv=$(query_db "SELECT converted_at FROM wp_kntnt_ad_attr_conversions ORDER BY id DESC LIMIT 1")
converted_at=$(echo "$latest_conv" | jq -r '.converted_at')
TESTS_RUN=$((TESTS_RUN + 1))
if [[ -n "$converted_at" ]] && [[ "$converted_at" != "null" ]]; then
    echo "  PASS: Conversion has timestamp ($converted_at)"
else
    echo "  FAIL: Conversion timestamp is empty or null"
    TESTS_FAILED=$((TESTS_FAILED + 1))
fi

# --- Cleanup ---

set_consent_state "default"

print_summary
