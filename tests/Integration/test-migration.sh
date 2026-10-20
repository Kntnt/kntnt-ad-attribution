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
expected_version=$(sed -n 's/^ \* Version: *//p' "$SCRIPT_DIR/../../kntnt-ad-attribution.php")
assert_equals "$expected_version" "$version" "Version option matches plugin header ($expected_version)"

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

# --- Click IDs table is keyed by click row and platform (1.10.0) ---

# Asserts the per-click schema: (click_id, platform) primary key, the
# platform's ID in `value`, no per-hash `hash` column, and the
# (platform, value) index behind the "already recorded?" lookup.
# Usage: assert_per_click_schema <label>
assert_per_click_schema() {
    local label="$1" sql index_count
    sql=$(query_db "SELECT sql FROM sqlite_master WHERE type='table' AND name='wp_kntnt_ad_attr_click_ids'" | jq -r '.sql')
    assert_contains "$sql" '"click_id" integer' "${label}: click_ids.click_id is integer (click row ID)"
    assert_contains "$sql" '"platform"' "${label}: click_ids has platform column"
    assert_contains "$sql" '"value"' "${label}: click_ids has value column"
    assert_contains "$sql" 'PRIMARY KEY ("click_id", "platform")' "${label}: click_ids PK is (click_id, platform)"
    assert_not_contains "$sql" '"hash"' "${label}: click_ids has no hash column"
    index_count=$(query_db "SELECT COUNT(*) AS cnt FROM sqlite_master WHERE type='index' AND tbl_name='wp_kntnt_ad_attr_click_ids' AND sql LIKE '%(\"platform\", \"value\")%'" | jq -r '.cnt')
    assert_equals "1" "$index_count" "${label}: click_ids has a (platform, value) index"
}

assert_per_click_schema "Fresh install"

# --- Upgrade from v1.9.1 with rows in the old click-ID table ---

# Rebuild the v1.9.1 table (migrations/1.2.0.php) with rows that cannot be
# mapped to clicks, then let the Migrator run 1.10.0 on the next request.
execute_sql "DROP TABLE IF EXISTS wp_kntnt_ad_attr_click_ids" > /dev/null
execute_sql "CREATE TABLE wp_kntnt_ad_attr_click_ids (
    hash       CHAR(64)     NOT NULL,
    platform   VARCHAR(50)  NOT NULL,
    click_id   VARCHAR(255) NOT NULL,
    clicked_at DATETIME     NOT NULL,
    PRIMARY KEY (hash, platform),
    INDEX idx_clicked_at (clicked_at)
)" > /dev/null
old_hash=$(openssl rand -hex 32)
execute_sql "INSERT INTO wp_kntnt_ad_attr_click_ids (hash, platform, click_id, clicked_at) VALUES ('${old_hash}', 'google_ads', 'OLD-GCLID', '2026-01-01 00:00:00')" > /dev/null
old_count=$(query_db "SELECT COUNT(*) AS cnt FROM wp_kntnt_ad_attr_click_ids" | jq -r '.cnt')
assert_equals "1" "$old_count" "Upgrade: old click_ids table holds a v1.9.1 row"

set_option "kntnt_ad_attr_version" "1.9.1"

# Any request runs plugins_loaded, and with it the Migrator.
upgrade_status=$(curl -sS -o /dev/null -w "%{http_code}" "${WP_BASE_URL}/" || true)
assert_status "200" "$upgrade_status" "Upgrade: request that runs the migration succeeds"

version=$(get_option "kntnt_ad_attr_version" | jq -r '.value')
assert_equals "$expected_version" "$version" "Upgrade: version option raised to plugin header ($expected_version)"
assert_per_click_schema "Upgrade"
new_count=$(query_db "SELECT COUNT(*) AS cnt FROM wp_kntnt_ad_attr_click_ids" | jq -r '.cnt')
assert_equals "0" "$new_count" "Upgrade: unmappable v1.9.1 click-ID rows are dropped"

# --- Running the 1.10.0 migration again keeps the new table and its rows ---

execute_sql "INSERT INTO wp_kntnt_ad_attr_click_ids (click_id, platform, value) VALUES (999999999, 'google_ads', 'RERUN')" > /dev/null
set_option "kntnt_ad_attr_version" "1.9.1"
rerun_status=$(curl -sS -o /dev/null -w "%{http_code}" "${WP_BASE_URL}/" || true)
assert_status "200" "$rerun_status" "Re-run: request that re-runs the migration succeeds"
version=$(get_option "kntnt_ad_attr_version" | jq -r '.value')
assert_equals "$expected_version" "$version" "Re-run: version option equals plugin header"
assert_per_click_schema "Re-run"
rerun_count=$(query_db "SELECT COUNT(*) AS cnt FROM wp_kntnt_ad_attr_click_ids WHERE value = 'RERUN'" | jq -r '.cnt')
assert_equals "1" "$rerun_count" "Re-run: existing per-click rows survive"
execute_sql "DELETE FROM wp_kntnt_ad_attr_click_ids WHERE value = 'RERUN'" > /dev/null

# --- Clicks carry a test flag (1.10.0), added once however often it runs ---

# The fresh install and the two re-runs above have all run the 1.10.0 file.
is_test_columns=$(query_db "SELECT COUNT(*) AS cnt FROM pragma_table_info('wp_kntnt_ad_attr_clicks') WHERE name = 'is_test'" | jq -r '.cnt')
assert_equals "1" "$is_test_columns" "clicks.is_test exists exactly once after re-runs"

# A row written without the flag, as by older code, is a real click.
flag_hash=$(openssl rand -hex 32)
execute_sql "INSERT INTO wp_kntnt_ad_attr_clicks (hash, clicked_at) VALUES ('${flag_hash}', '2026-01-01 00:00:00')" > /dev/null
flag_default=$(query_db "SELECT is_test FROM wp_kntnt_ad_attr_clicks WHERE hash = '${flag_hash}'" | jq -r '.is_test')
assert_equals "0" "$flag_default" "clicks.is_test defaults to 0 (a real click)"
execute_sql "DELETE FROM wp_kntnt_ad_attr_clicks WHERE hash = '${flag_hash}'" > /dev/null

# --- Conversions and report jobs carry a conversion reference (1.10.0) ---

# Asserts that a table has the conversion_ref column exactly once and one
# index on it.
# Usage: assert_conversion_ref_column <table suffix> <label>
assert_conversion_ref_column() {
    local table="wp_kntnt_ad_attr_$1" label="$2" columns indexes
    columns=$(query_db "SELECT COUNT(*) AS cnt FROM pragma_table_info('${table}') WHERE name = 'conversion_ref'" | jq -r '.cnt')
    assert_equals "1" "$columns" "${label}: $1.conversion_ref exists exactly once"
    indexes=$(query_db "SELECT COUNT(*) AS cnt FROM sqlite_master WHERE type='index' AND tbl_name='${table}' AND sql LIKE '%conversion_ref%'" | jq -r '.cnt')
    assert_equals "1" "$indexes" "${label}: $1.conversion_ref has one index"
}

# The fresh install and the two re-runs above have all run the 1.10.0 file.
assert_conversion_ref_column "conversions" "After re-runs"
assert_conversion_ref_column "queue" "After re-runs"

# Rows as code before 1.10.0 wrote them: a conversion row and a report job
# without a reference.
execute_sql "INSERT INTO wp_kntnt_ad_attr_conversions (click_id, converted_at, fractional_conversion) VALUES (999999998, '2026-01-01 00:00:00', 1.0)" > /dev/null
legacy_conversion=$(query_db "SELECT id FROM wp_kntnt_ad_attr_conversions WHERE click_id = 999999998" | jq -r '.id')
execute_sql "INSERT INTO wp_kntnt_ad_attr_queue (reporter, payload, status, created_at, label) VALUES ('legacy', '{}', 'done', '2026-01-01 00:00:00', 'LEGACY-JOB')" > /dev/null

set_option "kntnt_ad_attr_version" "1.9.1"
legacy_status=$(curl -sS -o /dev/null -w "%{http_code}" "${WP_BASE_URL}/" || true)
assert_status "200" "$legacy_status" "Legacy rows: request that re-runs the migration succeeds"

# Each conversion row from before 1.10.0 becomes a conversion of its own.
legacy_ref=$(query_db "SELECT conversion_ref FROM wp_kntnt_ad_attr_conversions WHERE id = ${legacy_conversion}" | jq -r '.conversion_ref')
assert_equals "$legacy_conversion" "$legacy_ref" "Legacy rows: an old conversion row gets its own row ID as reference"

# Jobs from before 1.10.0 keep no reference, so no conversion action touches them.
legacy_job_ref=$(query_db "SELECT conversion_ref FROM wp_kntnt_ad_attr_queue WHERE label = 'LEGACY-JOB'" | jq -r '.conversion_ref')
assert_equals "null" "$legacy_job_ref" "Legacy rows: an old report job keeps no reference"

assert_conversion_ref_column "conversions" "Legacy rows"
assert_conversion_ref_column "queue" "Legacy rows"

execute_sql "DELETE FROM wp_kntnt_ad_attr_conversions WHERE id = ${legacy_conversion}" > /dev/null
execute_sql "DELETE FROM wp_kntnt_ad_attr_queue WHERE label = 'LEGACY-JOB'" > /dev/null

# --- Queue table has status column with default ---

queue_sql=$(query_db "SELECT sql FROM sqlite_master WHERE type='table' AND name='wp_kntnt_ad_attr_queue'" | jq -r '.sql')
assert_contains "$queue_sql" '"status"' "queue table has status column"

# --- Old stats table was dropped ---

result=$(query_db "SELECT COUNT(*) AS cnt FROM sqlite_master WHERE type='table' AND name='wp_kntnt_ad_attr_stats'")
cnt=$(echo "$result" | jq -r '.cnt')
assert_equals "0" "$cnt" "Old stats table does not exist (dropped by 1.5.0 migration)"

print_summary
