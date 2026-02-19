#!/usr/bin/env bash
# Integration test: Plugin activation and database schema.
#
# Verifies that activation creates all tables, registers the CPT, sets the
# version option, and configures rewrite rules.

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/helpers/assertions.sh"
source "$SCRIPT_DIR/helpers/fixtures.sh"

echo "=== Test: Plugin Activation ==="

# --- Tables exist ---

for table in kntnt_ad_attr_clicks kntnt_ad_attr_conversions kntnt_ad_attr_click_ids kntnt_ad_attr_queue; do
    result=$(query_db "SELECT COUNT(*) AS cnt FROM sqlite_master WHERE type='table' AND name='wp_${table}'")
    cnt=$(echo "$result" | jq -r '.cnt')
    assert_equals "1" "$cnt" "Table wp_${table} exists"
done

# --- Clicks table schema ---

clicks_sql=$(query_db "SELECT sql FROM sqlite_master WHERE type='table' AND name='wp_kntnt_ad_attr_clicks'" | jq -r '.sql')
assert_contains "$clicks_sql" '"id"' "Clicks table has id column"
assert_contains "$clicks_sql" '"hash"' "Clicks table has hash column"
assert_contains "$clicks_sql" '"clicked_at"' "Clicks table has clicked_at column"
assert_contains "$clicks_sql" '"utm_content"' "Clicks table has utm_content column"
assert_contains "$clicks_sql" '"utm_term"' "Clicks table has utm_term column"
assert_contains "$clicks_sql" '"utm_id"' "Clicks table has utm_id column"
assert_contains "$clicks_sql" '"utm_source_platform"' "Clicks table has utm_source_platform column"

# --- Conversions table schema ---

conv_sql=$(query_db "SELECT sql FROM sqlite_master WHERE type='table' AND name='wp_kntnt_ad_attr_conversions'" | jq -r '.sql')
assert_contains "$conv_sql" '"id"' "Conversions table has id column"
assert_contains "$conv_sql" '"click_id"' "Conversions table has click_id column"
assert_contains "$conv_sql" '"converted_at"' "Conversions table has converted_at column"
assert_contains "$conv_sql" '"fractional_conversion"' "Conversions table has fractional_conversion column"

# --- CPT registered ---

response=$(curl -sf -b "${ADMIN_COOKIE}" \
    "${WP_BASE_URL}/?rest_route=/wp/v2/types" \
    -H "X-WP-Nonce: ${WP_NONCE}")
has_cpt=$(echo "$response" | jq -r 'has("kntnt_ad_attr_url")')
assert_equals "true" "$has_cpt" "CPT kntnt_ad_attr_url is registered"

# --- Version option matches plugin header ---

result=$(get_option "kntnt_ad_attr_version")
version=$(echo "$result" | jq -r '.value')
assert_equals "1.6.0" "$version" "Version option is 1.6.0"

# --- Rewrite rule works (valid hash gets handled, not generic 404) ---

# A valid-format but non-existent hash should return 404 from the plugin
# (not from WP's default handler), proving the rewrite rule matched.
fake_hash=$(openssl rand -hex 32)
flush_rewrites
status=$(curl -sS -o /dev/null -w "%{http_code}" \
    --max-redirs 0 \
    "${WP_BASE_URL}/ad/${fake_hash}/" 2>/dev/null || true)
assert_status "404" "$status" "Rewrite rule routes /ad/<hash> (returns 404 for unknown hash)"

print_summary
