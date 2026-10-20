#!/usr/bin/env bash
# Integration test: Per-click click IDs.
#
# Verifies that each platform click ID belongs to the click row it arrived
# with, that reporters receive the click ID and per-click campaign fields
# of the click a conversion is attributed to (not a later visitor's), and
# that deleting click rows by any path deletes their click IDs.

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/helpers/assertions.sh"
source "$SCRIPT_DIR/helpers/fixtures.sh"

echo "=== Test: Per-click Click IDs ==="

# --- Setup ---

clear_cookies
set_consent_state "granted"
set_probe_capturers "google_ads=gclid"
set_probe_reporter "on"
reset_probe_calls

TARGET_JSON=$(create_target_page "Click ID Landing")
TARGET_ID=$(echo "$TARGET_JSON" | jq -r '.post_id')
HASH=$(create_tracking_url "$TARGET_ID" "google" "cpc" "click-ids")
flush_rewrites

# Clicks the tracking URL as a new visitor (no plugin cookies) with the given
# query string and prints the visitor's _ad_clicks value.
# Usage: click_as_new_visitor <hash> <query>
click_as_new_visitor() {
    local headers
    headers=$(curl -sS -D - -o /dev/null \
        -A "Mozilla/5.0 Chrome/120.0.0.0" \
        -H "$(plugin_cookies)" \
        --max-redirs 0 \
        "${WP_BASE_URL}/ad/$1/?$2" 2>/dev/null || true)
    extract_cookie_value "$headers" "_ad_clicks"
}

# Prints the click ID values stored for a tracking URL, oldest click first.
# Usage: stored_click_ids <hash>
stored_click_ids() {
    query_db_rows "SELECT ci.value FROM wp_kntnt_ad_attr_click_ids ci JOIN wp_kntnt_ad_attr_clicks c ON c.id = ci.click_id WHERE c.hash = '$1' ORDER BY c.id" \
        | jq -r '[.[].value] | join(",")'
}

# ─── Visitor A and visitor B click the same tracking URL; A converts ───

cookie_a=$(click_as_new_visitor "$HASH" "gclid=AAA&utm_term=kwA")
timestamp_a="${cookie_a##*:}"
assert_equals "${HASH}:${timestamp_a}" "$cookie_a" "Visitor A's cookie holds only A's click"

# A second apart at least: clicks on the same hash in the same second
# cannot be told apart by (hash, clicked_at).
sleep 2

cookie_b=$(click_as_new_visitor "$HASH" "gclid=BBB&utm_term=kwB")
assert_not_contains "$cookie_b" "$timestamp_a" "Visitor B's cookie does not carry A's click"

assert_equals "AAA,BBB" "$(stored_click_ids "$HASH")" "Each click keeps its own click ID"

trigger_conversion "$cookie_a" > /dev/null

calls=$(get_probe_calls)
assert_equals "1" "$(echo "$calls" | jq 'length')" "Probe reporter called once for A's conversion"
assert_equals "AAA" "$(echo "$calls" | jq -r --arg h "$HASH" '.[0].click_ids[$h].google_ads.id')" \
    "Reporter receives A's click ID, not B's"
assert_equals "$timestamp_a" "$(echo "$calls" | jq -r --arg h "$HASH" '.[0].click_ids[$h].google_ads.captured_at')" \
    "captured_at is A's clicked_at"
assert_equals "kwA" "$(echo "$calls" | jq -r --arg h "$HASH" '.[0].campaigns[$h].utm_term')" \
    "Reporter receives A's utm_term, not B's"
assert_equals "google" "$(echo "$calls" | jq -r --arg h "$HASH" '.[0].campaigns[$h].utm_source')" \
    "Source still comes from the tracking URL"

# ─── Visitor C clicks without a click ID and converts ───

sleep 2
clear_cookies
reset_probe_calls

cookie_c=$(click_as_new_visitor "$HASH" "utm_term=kwC")
trigger_conversion "$cookie_c" > /dev/null

calls=$(get_probe_calls)
assert_equals "1" "$(echo "$calls" | jq 'length')" "Probe reporter called once for C's conversion"
# PHP encodes an empty $click_ids as [], so look the hash up only in an object.
assert_equals "null" "$(echo "$calls" | jq -r --arg h "$HASH" '(.[0].click_ids | objects | .[$h]) // null')" \
    "Reporter receives no click ID for a click that carried none"
assert_equals "kwC" "$(echo "$calls" | jq -r --arg h "$HASH" '.[0].campaigns[$h].utm_term')" \
    "Reporter receives C's utm_term"

# ─── Retention cleanup deletes the click IDs of expired clicks ───

HASH_OLD=$(create_tracking_url "$TARGET_ID" "google" "cpc" "click-ids-expired")
old_date=$(date -u -v-400d +"%Y-%m-%d %H:%M:%S" 2>/dev/null || date -u -d "400 days ago" +"%Y-%m-%d %H:%M:%S")
execute_sql "INSERT INTO wp_kntnt_ad_attr_clicks (hash, clicked_at) VALUES ('${HASH_OLD}', '${old_date}')" > /dev/null
old_click_id=$(query_db "SELECT id FROM wp_kntnt_ad_attr_clicks WHERE hash = '${HASH_OLD}'" | jq -r '.id')
execute_sql "INSERT INTO wp_kntnt_ad_attr_click_ids (click_id, platform, value) VALUES (${old_click_id}, 'google_ads', 'EXPIRED')" > /dev/null

do_action "kntnt_ad_attr_daily_cleanup"

assert_equals "0" "$(get_click_count "$HASH_OLD")" "Retention cleanup deleted the expired click"
assert_equals "0" "$(query_db "SELECT COUNT(*) AS cnt FROM wp_kntnt_ad_attr_click_ids WHERE click_id = ${old_click_id}" | jq -r '.cnt')" \
    "Retention cleanup deleted the expired click's click ID"
assert_equals "AAA,BBB" "$(stored_click_ids "$HASH")" "Click IDs of retained clicks survive the cleanup"

# ─── Click IDs of click rows deleted by any other path go with the next cleanup ───

HASH_GONE=$(create_tracking_url "$TARGET_ID" "google" "cpc" "click-ids-gone")
execute_sql "INSERT INTO wp_kntnt_ad_attr_clicks (hash, clicked_at) VALUES ('${HASH_GONE}', datetime('now'))" > /dev/null
gone_click_id=$(query_db "SELECT id FROM wp_kntnt_ad_attr_clicks WHERE hash = '${HASH_GONE}'" | jq -r '.id')
execute_sql "INSERT INTO wp_kntnt_ad_attr_click_ids (click_id, platform, value) VALUES (${gone_click_id}, 'google_ads', 'GONE')" > /dev/null
execute_sql "DELETE FROM wp_kntnt_ad_attr_clicks WHERE id = ${gone_click_id}" > /dev/null

do_action "kntnt_ad_attr_daily_cleanup"

assert_equals "0" "$(query_db "SELECT COUNT(*) AS cnt FROM wp_kntnt_ad_attr_click_ids WHERE click_id = ${gone_click_id}" | jq -r '.cnt')" \
    "Daily cleanup deletes click IDs whose click row is gone"

# ─── Permanently deleting a tracking URL deletes its click IDs ───

click_row_ids=$(query_db_rows "SELECT id FROM wp_kntnt_ad_attr_clicks WHERE hash = '${HASH}'" | jq -r '[.[].id] | join(",")')
post_id=$(query_db "SELECT post_id FROM wp_postmeta WHERE meta_key = '_hash' AND meta_value = '${HASH}'" | jq -r '.post_id')
update_post_status "$post_id" "trash"

trash_page=$(curl -sf -b "${ADMIN_COOKIE}" \
    "${WP_BASE_URL}/wp-admin/tools.php?page=kntnt-ad-attribution&post_status=trash&date_start=2020-01-01&date_end=2027-12-31")
delete_nonce=$(echo "$trash_page" \
    | grep -oP "action=delete(&|&#038;|&amp;)post=${post_id}(&|&#038;|&amp;)_wpnonce=\K[a-f0-9]+" \
    | head -1 || true)

TESTS_RUN=$((TESTS_RUN + 1))
if [[ -n "$delete_nonce" ]]; then
    echo "  PASS: Delete Permanently link found in trash view"
else
    echo "  FAIL: Delete Permanently link not found in trash view"
    TESTS_FAILED=$((TESTS_FAILED + 1))
fi

curl -sS -o /dev/null -b "${ADMIN_COOKIE}" --max-redirs 0 \
    "${WP_BASE_URL}/wp-admin/tools.php?page=kntnt-ad-attribution&action=delete&post=${post_id}&_wpnonce=${delete_nonce}" || true

assert_equals "0" "$(query_db "SELECT COUNT(*) AS cnt FROM wp_posts WHERE ID = ${post_id}" | jq -r '.cnt')" \
    "Tracking URL permanently deleted"
assert_equals "0" "$(get_click_count "$HASH")" "Its clicks are deleted"
assert_equals "0" "$(query_db "SELECT COUNT(*) AS cnt FROM wp_kntnt_ad_attr_click_ids WHERE click_id IN (${click_row_ids})" | jq -r '.cnt')" \
    "Its click IDs are deleted with the clicks"

# --- Cleanup ---

reset_probes
set_consent_state "default"
clear_cookies

print_summary
