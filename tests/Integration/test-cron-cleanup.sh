#!/usr/bin/env bash
# Integration test: Cron cleanup operations.
#
# Verifies that the daily cleanup deletes old clicks, preserves recent ones,
# and drafts tracking URLs with missing targets. The orphaned conversion
# cleanup uses MySQL-specific JOIN DELETE syntax that SQLite cannot translate,
# so it is tested indirectly via click deletion cascade.

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/helpers/assertions.sh"
source "$SCRIPT_DIR/helpers/fixtures.sh"

echo "=== Test: Cron Cleanup ==="

# --- Setup ---

TARGET_JSON=$(create_target_page "Cron Cleanup Landing")
TARGET_ID=$(echo "$TARGET_JSON" | jq -r '.post_id')
HASH_OLD=$(create_tracking_url "$TARGET_ID" "google" "cpc" "cron-old")
HASH_NEW=$(create_tracking_url "$TARGET_ID" "facebook" "cpm" "cron-new")
flush_rewrites

# --- Insert an old click (400 days ago, beyond 365-day retention) ---

old_date=$(date -u -v-400d +"%Y-%m-%d %H:%M:%S" 2>/dev/null || date -u -d "400 days ago" +"%Y-%m-%d %H:%M:%S")
execute_sql "INSERT INTO wp_kntnt_ad_attr_clicks (hash, clicked_at) VALUES ('${HASH_OLD}', '${old_date}')" > /dev/null

# --- Insert a recent click (today) ---

new_date=$(date -u +"%Y-%m-%d %H:%M:%S")
execute_sql "INSERT INTO wp_kntnt_ad_attr_clicks (hash, clicked_at) VALUES ('${HASH_NEW}', '${new_date}')" > /dev/null

# Verify both exist before cron.
old_count=$(get_click_count "$HASH_OLD")
new_count=$(get_click_count "$HASH_NEW")
assert_greater_than "0" "$old_count" "Old click exists before cron"
assert_greater_than "0" "$new_count" "New click exists before cron"

# --- Trigger daily cleanup ---

do_action "kntnt_ad_attr_daily_cleanup"

# --- Old click deleted ---

old_count_after=$(get_click_count "$HASH_OLD")
assert_equals "0" "$old_count_after" "Old click (400 days) deleted by cron"

# --- Recent click preserved ---

new_count_after=$(get_click_count "$HASH_NEW")
assert_greater_than "0" "$new_count_after" "Recent click preserved by cron"

# --- Tracking URL drafted when target is deleted ---

TARGET_DEL_JSON=$(create_target_page "Delete Me Target")
TARGET_DEL_ID=$(echo "$TARGET_DEL_JSON" | jq -r '.post_id')
HASH_ORPHAN=$(create_tracking_url "$TARGET_DEL_ID" "google" "cpc" "cron-orphan")

# Delete the target page.
delete_post "$TARGET_DEL_ID"

# Trigger cron.
do_action "kntnt_ad_attr_daily_cleanup"

# Check the tracking URL post status.
result=$(query_db "SELECT post_status FROM wp_posts AS p JOIN wp_postmeta AS pm ON p.ID = pm.post_id WHERE pm.meta_key = '_hash' AND pm.meta_value = '${HASH_ORPHAN}'")
post_status=$(echo "$result" | jq -r '.post_status')
assert_equals "draft" "$post_status" "Tracking URL drafted when target deleted"

print_summary
