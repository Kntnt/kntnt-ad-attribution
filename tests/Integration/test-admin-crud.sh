#!/usr/bin/env bash
# Integration test: Admin page CRUD operations.
#
# Tests creating, listing, trashing, restoring, and permanently deleting
# tracking URLs via the admin interface.

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/helpers/assertions.sh"
source "$SCRIPT_DIR/helpers/fixtures.sh"

echo "=== Test: Admin CRUD ==="

# --- Setup ---

TARGET_JSON=$(create_target_page "Admin CRUD Landing")
TARGET_ID=$(echo "$TARGET_JSON" | jq -r '.post_id')

# ─── Create tracking URL via test helper and verify in DB ───

HASH=$(create_tracking_url "$TARGET_ID" "google" "cpc" "admin-test")

# Verify it was created.
result=$(query_db "SELECT p.post_status, pm.meta_value AS hash FROM wp_posts p JOIN wp_postmeta pm ON p.ID = pm.post_id WHERE pm.meta_key = '_hash' AND pm.meta_value = '${HASH}'")
post_status=$(echo "$result" | jq -r '.post_status')
assert_equals "publish" "$post_status" "Tracking URL created with publish status"

# Verify hash format (64-char lowercase hex).
TESTS_RUN=$((TESTS_RUN + 1))
if [[ "$HASH" =~ ^[a-f0-9]{64}$ ]]; then
    echo "  PASS: Hash is 64-char lowercase hex"
else
    echo "  FAIL: Hash format invalid: $HASH"
    TESTS_FAILED=$((TESTS_FAILED + 1))
fi

# Verify meta fields stored correctly.
meta=$(query_db "SELECT meta_value FROM wp_postmeta WHERE meta_key = '_utm_source' AND post_id = (SELECT post_id FROM wp_postmeta WHERE meta_key = '_hash' AND meta_value = '${HASH}')")
assert_equals "google" "$(echo "$meta" | jq -r '.meta_value')" "utm_source stored correctly"

meta=$(query_db "SELECT meta_value FROM wp_postmeta WHERE meta_key = '_utm_medium' AND post_id = (SELECT post_id FROM wp_postmeta WHERE meta_key = '_hash' AND meta_value = '${HASH}')")
assert_equals "cpc" "$(echo "$meta" | jq -r '.meta_value')" "utm_medium stored correctly"

meta=$(query_db "SELECT meta_value FROM wp_postmeta WHERE meta_key = '_utm_campaign' AND post_id = (SELECT post_id FROM wp_postmeta WHERE meta_key = '_hash' AND meta_value = '${HASH}')")
assert_equals "admin-test" "$(echo "$meta" | jq -r '.meta_value')" "utm_campaign stored correctly"

# ─── Generate a click so the URL appears in the merged campaign view ───

set_consent_state "granted"
flush_rewrites
simulate_click "$HASH" > /dev/null

# ─── List view shows created URL ───

admin_page=$(curl -sf -b "${ADMIN_COOKIE}" \
    "${WP_BASE_URL}/wp-admin/tools.php?page=kntnt-ad-attribution")
assert_contains "$admin_page" "$HASH" "List view shows the tracking URL hash"

# ─── Merged view with Create button ───

assert_contains "$admin_page" "Create Tracking URL" "Admin page shows Create Tracking URL button"

# ─── Trash URL via test helper ---

post_id=$(query_db "SELECT post_id FROM wp_postmeta WHERE meta_key = '_hash' AND meta_value = '${HASH}'" | jq -r '.post_id')
update_post_status "$post_id" "trash"

result=$(query_db "SELECT post_status FROM wp_posts WHERE ID = ${post_id}")
assert_equals "trash" "$(echo "$result" | jq -r '.post_status')" "Tracking URL trashed"

# ─── Restore URL ---

update_post_status "$post_id" "publish"

result=$(query_db "SELECT post_status FROM wp_posts WHERE ID = ${post_id}")
assert_equals "publish" "$(echo "$result" | jq -r '.post_status')" "Tracking URL restored to publish"

# ─── Permanently delete URL (verify clicks/conversions also deleted) ───

# Add another click for the permanent delete test.
simulate_click "$HASH" > /dev/null

click_count_before=$(get_click_count "$HASH")
assert_greater_than "0" "$click_count_before" "Click exists before permanent delete"

# Delete the post permanently.
delete_post "$post_id"

# Post should be gone.
result=$(query_db "SELECT COUNT(*) AS cnt FROM wp_posts WHERE ID = ${post_id}")
assert_equals "0" "$(echo "$result" | jq -r '.cnt')" "Post permanently deleted"

# Cleanup: reset consent.
set_consent_state "default"

print_summary
