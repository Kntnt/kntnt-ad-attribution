#!/usr/bin/env bash
# Integration test: Database migration and schema verification.
#
# Verifies that the version option matches the plugin header version
# and that all tables have the correct column structure.

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/helpers/assertions.sh"
source "$SCRIPT_DIR/helpers/fixtures.sh"

echo "=== Test: Migration ==="

# --- Version option matches plugin header ---

result=$(get_option "kntnt_ad_attr_version")
version=$(echo "$result" | jq -r '.value')
assert_equals "1.6.0" "$version" "Version option matches plugin header (1.6.0)"

# --- Clicks table column types (using sqlite_master CREATE TABLE SQL) ---

clicks_sql=$(query_db "SELECT sql FROM sqlite_master WHERE type='table' AND name='wp_kntnt_ad_attr_clicks'" | jq -r '.sql')

# id should be integer (SQLite translates BIGINT to integer).
assert_contains "$clicks_sql" '"id" integer' "clicks.id is integer (BIGINT in SQLite)"

# hash should be text (SQLite translates CHAR to text).
assert_contains "$clicks_sql" '"hash" text' "clicks.hash is text (CHAR(64) in SQLite)"

# clicked_at should be text (SQLite translates DATETIME to text).
assert_contains "$clicks_sql" '"clicked_at" text' "clicks.clicked_at is text (datetime in SQLite)"

# --- Conversions table fractional_conversion ---

conv_sql=$(query_db "SELECT sql FROM sqlite_master WHERE type='table' AND name='wp_kntnt_ad_attr_conversions'" | jq -r '.sql')
assert_contains "$conv_sql" '"fractional_conversion" real' "conversions.fractional_conversion is real (decimal in SQLite)"

# --- Click IDs table has composite PK ---

# In SQLite, composite PKs can't use AUTOINCREMENT, so the table_info or
# CREATE TABLE SQL should show both columns. Check the SQL directly.
click_ids_sql=$(query_db "SELECT sql FROM sqlite_master WHERE type='table' AND name='wp_kntnt_ad_attr_click_ids'" | jq -r '.sql')
assert_contains "$click_ids_sql" '"hash"' "click_ids table has hash column"
assert_contains "$click_ids_sql" '"platform"' "click_ids table has platform column"

# --- Queue table has status column with default ---

queue_sql=$(query_db "SELECT sql FROM sqlite_master WHERE type='table' AND name='wp_kntnt_ad_attr_queue'" | jq -r '.sql')
assert_contains "$queue_sql" '"status"' "queue table has status column"

# --- Old stats table was dropped ---

result=$(query_db "SELECT COUNT(*) AS cnt FROM sqlite_master WHERE type='table' AND name='wp_kntnt_ad_attr_stats'")
cnt=$(echo "$result" | jq -r '.cnt')
assert_equals "0" "$cnt" "Old stats table does not exist (dropped by 1.5.0 migration)"

print_summary
