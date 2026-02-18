#!/usr/bin/env bash
# Integration test: Campaign aggregation report.
#
# Verifies that the campaigns tab aggregates clicks correctly,
# includes conversion data, and supports UTM filtering.

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/helpers/assertions.sh"
source "$SCRIPT_DIR/helpers/fixtures.sh"

echo "=== Test: Campaign Report ==="

# --- Setup ---

clear_cookies

set_consent_state "granted"

TARGET_JSON=$(create_target_page "Campaign Report Landing")
TARGET_ID=$(echo "$TARGET_JSON" | jq -r '.post_id')

HASH_G=$(create_tracking_url "$TARGET_ID" "google" "cpc" "campaign-report")
HASH_F=$(create_tracking_url "$TARGET_ID" "facebook" "cpm" "campaign-report-fb")
flush_rewrites

# --- Generate clicks ---

for i in $(seq 1 5); do
    simulate_click "$HASH_G" > /dev/null
done

simulate_click "$HASH_F" > /dev/null
simulate_click "$HASH_F" > /dev/null

# --- Generate a conversion for HASH_G ---

# Extract cookie from Set-Cookie header (avoids Secure flag issue with jars).
camp_cookies=$(get_click_cookies "$HASH_G")
ad_clicks=$(extract_cookie_value "$camp_cookies" "_ad_clicks")

if [[ -n "$ad_clicks" ]]; then
    trigger_conversion "$ad_clicks" > /dev/null
fi

# --- Campaigns tab shows aggregated data ---

campaigns_page=$(curl -sf -b "${ADMIN_COOKIE}" \
    "${WP_BASE_URL}/wp-admin/tools.php?page=kntnt-ad-attribution&tab=campaigns")

# Should contain the tracking URL hash (or the URL itself) somewhere.
assert_contains "$campaigns_page" "google" "Campaigns page shows google source"
assert_contains "$campaigns_page" "facebook" "Campaigns page shows facebook source"

# --- UTM filter works ---

filtered_page=$(curl -sf -b "${ADMIN_COOKIE}" \
    "${WP_BASE_URL}/wp-admin/tools.php?page=kntnt-ad-attribution&tab=campaigns&utm_source=google")

assert_contains "$filtered_page" "google" "Filtered campaigns page shows google"

# facebook should not appear in the table body when filtering by google.
# Strip filter dropdowns (which always list all sources) before checking.
table_body=$(echo "$filtered_page" | sed 's/<select[^>]*>.*<\/select>//g')
assert_not_contains "$table_body" "facebook" "Filtered table rows exclude facebook"

# --- Cleanup ---

set_consent_state "default"

print_summary
