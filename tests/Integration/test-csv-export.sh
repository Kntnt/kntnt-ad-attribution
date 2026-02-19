#!/usr/bin/env bash
# Integration test: CSV export.
#
# Verifies that the CSV export returns correct content-type, headers,
# data, and UTF-8 BOM.

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/helpers/assertions.sh"
source "$SCRIPT_DIR/helpers/fixtures.sh"

echo "=== Test: CSV Export ==="

# --- Setup: create tracking URL and generate clicks ---

clear_cookies

set_consent_state "granted"

TARGET_JSON=$(create_target_page "CSV Export Landing")
TARGET_ID=$(echo "$TARGET_JSON" | jq -r '.post_id')

HASH=$(create_tracking_url "$TARGET_ID" "google" "cpc" "csv-test")
flush_rewrites

# Generate a couple of clicks.
simulate_click "$HASH" > /dev/null
simulate_click "$HASH" "Mozilla/5.0 Chrome/120" "utm_content=banner-csv" > /dev/null

# --- Extract nonce from campaigns page ---

campaigns_html=$(curl -sf -b "${ADMIN_COOKIE}" \
    "${WP_BASE_URL}/wp-admin/tools.php?page=kntnt-ad-attribution")

# Extract the export nonce from the form.
export_nonce=$(echo "$campaigns_html" | grep -oP 'name="kntnt_ad_attr_export_nonce"\s+value="\K[^"]+' || true)

if [[ -z "$export_nonce" ]]; then
    # Try alternate pattern (value before name).
    export_nonce=$(echo "$campaigns_html" | grep -oP 'value="([^"]+)"[^>]*name="kntnt_ad_attr_export_nonce"' | grep -oP 'value="\K[^"]+' || true)
fi

TESTS_RUN=$((TESTS_RUN + 1))
if [[ -n "$export_nonce" ]]; then
    echo "  PASS: Export nonce extracted from campaigns page"
else
    echo "  FAIL: Could not extract export nonce from campaigns page"
    TESTS_FAILED=$((TESTS_FAILED + 1))
    # Without nonce, remaining tests will fail — print summary and exit.
    set_consent_state "default"
    print_summary
    exit $?
fi

# --- POST export request ---

EXPORT_RESPONSE=$(mktemp)
EXPORT_HEADERS=$(mktemp)
trap "rm -f '$EXPORT_RESPONSE' '$EXPORT_HEADERS'" EXIT

# Include explicit date_start to override WASM PHP's persistent $_GET.
# The Playground reuses the PHP process, so $_GET from previous requests
# may leak into this one. Setting date_start ensures a clean date range.
curl -sf -b "${ADMIN_COOKIE}" \
    -D "$EXPORT_HEADERS" \
    -o "$EXPORT_RESPONSE" \
    "${WP_BASE_URL}/wp-admin/tools.php?page=kntnt-ad-attribution" \
    -d "kntnt_ad_attr_action=export_csv&kntnt_ad_attr_export_nonce=${export_nonce}&_wp_http_referer=%2Fwp-admin%2Ftools.php%3Fpage%3Dkntnt-ad-attribution&date_start=2020-01-01&date_end=2027-12-31"

# --- Content-Type is text/csv ---

content_type=$(grep -i "^content-type:" "$EXPORT_HEADERS" | head -1)
assert_contains "$content_type" "text/csv" "Export returns text/csv Content-Type"

# --- Content-Disposition has filename ---

content_disp=$(grep -i "^content-disposition:" "$EXPORT_HEADERS" | head -1)
assert_contains "$content_disp" "ad-attribution" "Export filename contains ad-attribution"
assert_contains "$content_disp" ".csv" "Export filename has .csv extension"

# --- UTF-8 BOM present (first 3 bytes: EF BB BF) ---

bom=$(xxd -l 3 -p "$EXPORT_RESPONSE")
assert_equals "efbbbf" "$bom" "CSV starts with UTF-8 BOM"

# --- CSV has header row ---

# Read the first line (after BOM).
first_line=$(tail -c +4 "$EXPORT_RESPONSE" | head -1)
assert_contains "$first_line" "Source" "CSV header contains Source"
assert_contains "$first_line" "Medium" "CSV header contains Medium"
assert_contains "$first_line" "Campaign" "CSV header contains Campaign"

# --- CSV contains tracking URL data ---

csv_body=$(tail -c +4 "$EXPORT_RESPONSE")
assert_contains "$csv_body" "google" "CSV body contains google source"
assert_contains "$csv_body" "cpc" "CSV body contains cpc medium"

# --- Cleanup ---

set_consent_state "default"

print_summary
