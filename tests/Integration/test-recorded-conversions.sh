#!/usr/bin/env bash
# Integration test: The list of recorded conversions and its actions.
#
# Verifies that a conversion recorded through the normal flow appears in the
# admin list with its columns and that its report jobs carry its reference;
# that Delete removes the conversion's rows and its pending and failed jobs,
# keeps its clicks and its done and processing jobs, and lowers the main
# view's conversion count by one; that Mark as test removes the click and the
# conversion from the main view, its totals and the CSV export, and Unmark
# restores them; that the bulk actions work; that Mark as test deletes the
# unfinished jobs of every conversion on the flagged click and counts them;
# and that an action without a valid nonce is refused.
#
# The capability check is covered by the unit tests: the Playground cookie
# store makes every request carry the admin login (see testing-strategy.md).

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/helpers/assertions.sh"
source "$SCRIPT_DIR/helpers/fixtures.sh"

echo "=== Test: Recorded Conversions ==="

# Unique per run, so the campaign-filtered numbers count only this run.
CAMPAIGN="recorded-conversions-$(openssl rand -hex 4)"
UA="Mozilla/5.0 Chrome/120.0.0.0"
ADMIN_URL="${WP_BASE_URL}/wp-admin/tools.php?page=kntnt-ad-attribution&date_start=2020-01-01&date_end=2027-12-31"

# --- Setup ---

clear_cookies
set_consent_state "granted"
set_probe_reporter "on"
reset_probe_calls

TARGET_JSON=$(create_target_page "Recorded Conversions Landing")
TARGET_ID=$(echo "$TARGET_JSON" | jq -r '.post_id')
HASH=$(create_tracking_url "$TARGET_ID" "google" "cpc" "$CAMPAIGN")
flush_rewrites

# Clicks the tracking URL and records a conversion for that click, as a
# visitor who converts after clicking. Prints the conversion's reference.
# Usage: click_and_convert
click_and_convert() {
    local headers clicks
    clear_cookies
    headers=$(curl -sS -D - -o /dev/null -A "$UA" --max-redirs 0 "${WP_BASE_URL}/ad/${HASH}/" 2>/dev/null || true)
    clicks=$(extract_cookie_value "$headers" "_ad_clicks")
    trigger_conversion "$clicks" > /dev/null
    query_db "SELECT cv.conversion_ref FROM wp_kntnt_ad_attr_conversions cv JOIN wp_kntnt_ad_attr_clicks c ON c.id = cv.click_id WHERE c.hash = '${HASH}' ORDER BY cv.id DESC LIMIT 1" \
        | jq -r '.conversion_ref'
}

# Prints the conversion list's HTML (the admin page from the list's section
# on), with one table row per line.
# Usage: conversion_list
conversion_list() {
    local html
    html=$(curl -sf -b "${ADMIN_COOKIE}" "$ADMIN_URL")
    echo "${html#*kntnt-ad-attr-conversions-section}" | tr -d '\n' | sed 's|</tr>|</tr>\n|g'
}

# Prints the list row of a conversion.
# Usage: conversion_row <list html> <reference>
conversion_row() {
    echo "$1" | grep "value=\"$2\"" || true
}

# Prints the URL of a row action in a list row, with entities decoded.
# Usage: action_url <row html> <action>
action_url() {
    echo "$1" | grep -oP "href=\"\K[^\"]*action=$2&[^\"]*" | head -1 | sed 's/&#038;/\&/g; s/&amp;/\&/g' || true
}

# Requests a URL as the admin and prints "<status> <redirect URL>".
# Usage: admin_get <url>
admin_get() {
    curl -sS -o /dev/null -w "%{http_code} %{redirect_url}" -b "${ADMIN_COOKIE}" "$1" 2>/dev/null || true
}

# Prints "<clicks>|<conversions>|<csv rows>" for this suite's campaign: the
# main view's totals row and the number of CSV export rows.
# Usage: reported_numbers
reported_numbers() {
    local html totals nonce csv_rows
    html=$(curl -sf -b "${ADMIN_COOKIE}" "${ADMIN_URL}&utm_campaign=${CAMPAIGN}")
    totals=$(echo "$html" | grep -oP '<tr class="kntnt-ad-attr-totals-row">.*?</tr>' | head -1 \
        | grep -oP '<strong>\K[^<]+' | tail -n 2 | paste -sd '|' - || true)
    nonce=$(echo "$html" | grep -oP 'name="kntnt_ad_attr_export_nonce"\s+value="\K[^"]+' | head -1 || true)
    csv_rows=$(curl -sf -b "${ADMIN_COOKIE}" \
        "${WP_BASE_URL}/wp-admin/tools.php?page=kntnt-ad-attribution" \
        -d "kntnt_ad_attr_action=export_csv&kntnt_ad_attr_export_nonce=${nonce}&_wp_http_referer=%2Fwp-admin%2Ftools.php%3Fpage%3Dkntnt-ad-attribution&date_start=2020-01-01&date_end=2027-12-31&utm_campaign=${CAMPAIGN}" \
        | grep -c "$CAMPAIGN" || true)
    echo "${totals:-0|0.0}|${csv_rows}"
}

# Usage: count_jobs <reference> <status>
count_jobs() {
    query_db "SELECT COUNT(*) AS cnt FROM wp_kntnt_ad_attr_queue WHERE conversion_ref = '$1' AND status = '$2'" | jq -r '.cnt'
}

# Usage: count_conversion_rows <reference>
count_conversion_rows() {
    query_db "SELECT COUNT(*) AS cnt FROM wp_kntnt_ad_attr_conversions WHERE conversion_ref = '$1'" | jq -r '.cnt'
}

# Usage: click_is_test <reference>
click_is_test() {
    query_db "SELECT c.is_test FROM wp_kntnt_ad_attr_clicks c JOIN wp_kntnt_ad_attr_conversions cv ON cv.click_id = c.id WHERE cv.conversion_ref = '$1'" | jq -r '.is_test'
}

# --- 1. A conversion recorded through the normal flow is listed ---

REF_A=$(click_and_convert)
sleep 2
REF_B=$(click_and_convert)

assert_contains "$(echo "$REF_A" | grep -xP '[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}' || true)" "$REF_A" "A conversion gets a UUID as reference"
TESTS_RUN=$((TESTS_RUN + 1))
if [[ "$REF_A" != "$REF_B" ]]; then
    echo "  PASS: Each conversion gets its own reference"
else
    echo "  FAIL: Two conversions share the reference '$REF_A'"
    TESTS_FAILED=$((TESTS_FAILED + 1))
fi
assert_equals "1" "$(count_jobs "$REF_A" pending)" "The conversion's report job carries its reference"
assert_equals "2" "$(get_probe_calls | jq 'length')" "The reporter saw both conversions"

LIST=$(conversion_list)
ROW_A=$(conversion_row "$LIST" "$REF_A")
assert_contains "$ROW_A" 'name="conversion[]"' "The conversion is listed with a checkbox"
assert_contains "$ROW_A" "google / cpc / ${CAMPAIGN}" "Tracking URL column shows source, medium and campaign"
assert_contains "$ROW_A" 'data-colname="Weight">1.00<' "Weight column shows the attribution weight"
assert_contains "$ROW_A" 'data-colname="Test">No<' "Test column shows an ordinary click"
assert_contains "$ROW_A" 'data-colname="Content">—<' "Content column shows the click's (empty) content"
assert_contains "$ROW_A" 'data-colname="Report Jobs">1 pending<' "Report Jobs column shows the pending job"
assert_contains "$ROW_A" 'action=conversion_delete' "Row offers Delete"
assert_contains "$ROW_A" 'action=conversion_mark_test' "Row offers Mark as test"
assert_contains "$LIST" "value=\"$REF_B\"" "The second conversion is listed too"

# Newest first: B's row comes before A's.
pos_a=$(echo "$LIST" | grep -n "value=\"$REF_A\"" | cut -d: -f1)
pos_b=$(echo "$LIST" | grep -n "value=\"$REF_B\"" | cut -d: -f1)
assert_greater_than "$pos_b" "$pos_a" "The newest conversion is listed first"

assert_equals "2|2.0|2" "$(reported_numbers)" "Main view, totals and CSV count both clicks and conversions"

# --- 2. Delete removes the conversion and its unfinished jobs, nothing else ---

# Give A one report job in every other state. WP-Cron leaves them alone: only
# pending jobs past their not_before are processed, and probe jobs are
# deferred a day.
for status in failed done processing; do
    execute_sql "INSERT INTO wp_kntnt_ad_attr_queue (reporter, payload, status, created_at, conversion_ref) VALUES ('probe', '{}', '${status}', '2026-01-01 00:00:00', '${REF_A}')" > /dev/null
done
ROW_A=$(conversion_row "$(conversion_list)" "$REF_A")
assert_contains "$ROW_A" 'data-colname="Report Jobs">1 pending, 1 processing, 1 done, 1 failed<' "Report Jobs column counts every status"

DELETE_A=$(action_url "$ROW_A" conversion_delete)

# A forged or missing nonce is refused and changes nothing.
forged=$(admin_get "$(echo "$DELETE_A" | sed 's/_wpnonce=[^&]*/_wpnonce=0123456789/')")
assert_equals "403" "${forged%% *}" "Delete with a forged nonce is refused"
missing=$(admin_get "$(echo "$DELETE_A" | sed 's/&_wpnonce=[^&]*//')")
assert_equals "403" "${missing%% *}" "Delete without a nonce is refused"
assert_equals "1" "$(count_conversion_rows "$REF_A")" "A refused Delete keeps the conversion"
assert_equals "1" "$(count_jobs "$REF_A" pending)" "A refused Delete keeps its jobs"

result=$(admin_get "$DELETE_A")
assert_equals "302" "${result%% *}" "Delete redirects back to the page"
assert_contains "$result" "message=conversions-deleted" "Delete redirects with its notice"
assert_contains "$result" "jobs_deleted=2" "Delete reports the two unfinished jobs it deleted"
assert_contains "$result" "jobs_processing=1" "Delete reports the processing job it left alone"
assert_contains "$result" "date_start=2020-01-01" "Delete returns to the same date range"

assert_equals "0" "$(count_conversion_rows "$REF_A")" "Delete removes the conversion's rows"
assert_equals "2" "$(get_click_count "$HASH")" "Delete keeps the click rows"
assert_equals "0" "$(count_jobs "$REF_A" pending)" "Delete removes the pending job"
assert_equals "0" "$(count_jobs "$REF_A" failed)" "Delete removes the failed job"
assert_equals "1" "$(count_jobs "$REF_A" done)" "Delete keeps the done job"
assert_equals "1" "$(count_jobs "$REF_A" processing)" "Delete keeps the processing job"
assert_equals "1" "$(count_jobs "$REF_B" pending)" "Delete leaves other conversions' jobs alone"
assert_equals "2|1.0|2" "$(reported_numbers)" "Delete lowers the main view's conversion count by one"

notice_html=$(curl -sf -b "${ADMIN_COOKIE}" "${result#* }")
assert_contains "$notice_html" "1 conversion(s) deleted. 2 unfinished report job(s) deleted." "The notice says what was deleted"
assert_contains "$notice_html" "1 report job(s) still processing were left untouched." "The notice says which jobs were left alone"
assert_not_contains "$(conversion_list)" "value=\"$REF_A\"" "A deleted conversion is no longer listed"

# --- 3. Mark as test takes the click and conversion out of the statistics ---

ROW_B=$(conversion_row "$(conversion_list)" "$REF_B")
result=$(admin_get "$(action_url "$ROW_B" conversion_mark_test)")
assert_contains "$result" "message=conversions-marked" "Mark as test redirects with its notice"
assert_contains "$result" "jobs_deleted=1" "Mark as test deletes the conversion's pending job"
assert_equals "1" "$(click_is_test "$REF_B")" "Mark as test flags the attributed click as a test click"
assert_equals "1" "$(count_conversion_rows "$REF_B")" "Mark as test keeps the conversion"
assert_equals "0" "$(count_jobs "$REF_B" pending)" "Mark as test removes the pending job"
assert_equals "1|0.0|1" "$(reported_numbers)" "Mark as test removes the click and conversion from main view, totals and CSV"

ROW_B=$(conversion_row "$(conversion_list)" "$REF_B")
assert_contains "$ROW_B" 'data-colname="Test">Yes<' "A test conversion is still listed, flagged"
assert_contains "$ROW_B" 'action=conversion_unmark_test' "A test conversion offers Unmark"

# --- 4. Unmark restores them ---

result=$(admin_get "$(action_url "$ROW_B" conversion_unmark_test)")
assert_contains "$result" "message=conversions-unmarked" "Unmark redirects with its notice"
assert_equals "0" "$(click_is_test "$REF_B")" "Unmark clears the test flag"
assert_equals "2|1.0|2" "$(reported_numbers)" "Unmark restores the click and conversion to main view, totals and CSV"

# --- 5. Bulk actions use the list's own nonce and selection ---

SECTION=$(curl -sf -b "${ADMIN_COOKIE}" "$ADMIN_URL")
SECTION="${SECTION#*kntnt-ad-attr-conversions-section}"
BULK_NONCE=$(echo "$SECTION" | grep -oP 'name="_wpnonce" value="\K[^"]+' | head -1 || true)
BULK_URL="${WP_BASE_URL}/wp-admin/tools.php?page=kntnt-ad-attribution&action=-1&action2=conversion_mark_test&conversion%5B%5D=${REF_B}&date_start=2020-01-01&date_end=2027-12-31"

refused=$(admin_get "${BULK_URL}&_wpnonce=0123456789")
assert_equals "403" "${refused%% *}" "Bulk action with a forged nonce is refused"
assert_equals "0" "$(click_is_test "$REF_B")" "A refused bulk action changes nothing"

result=$(admin_get "${BULK_URL}&_wpnonce=${BULK_NONCE}")
assert_contains "$result" "message=conversions-marked" "Bulk Mark as test redirects with its notice"
assert_contains "$result" "count=1" "Bulk Mark as test counts the selected conversion"
assert_equals "1" "$(click_is_test "$REF_B")" "Bulk Mark as test flags the click"

result=$(admin_get "${BULK_URL/conversion_mark_test/conversion_unmark_test}&_wpnonce=${BULK_NONCE}")
assert_contains "$result" "message=conversions-unmarked" "Bulk Unmark redirects with its notice"
assert_equals "0" "$(click_is_test "$REF_B")" "Bulk Unmark clears the flag"

# --- 6. Mark as test covers every conversion on the flagged clicks ---

# A second conversion by B's visitor after the same click, and an unfinished
# job for B again (Mark as test deleted B's first one above).
B_CLICKED_AT=$(query_db "SELECT c.clicked_at FROM wp_kntnt_ad_attr_clicks c JOIN wp_kntnt_ad_attr_conversions cv ON cv.click_id = c.id WHERE cv.conversion_ref = '${REF_B}'" | jq -r '.clicked_at')
B_ENTRY="${HASH}:$(jq -rn --arg d "$B_CLICKED_AT" '$d | sub(" "; "T") + "Z" | fromdate')"
clear_cookies
trigger_conversion "$B_ENTRY" > /dev/null
REF_B2=$(query_db "SELECT conversion_ref FROM wp_kntnt_ad_attr_conversions WHERE conversion_ref NOT IN ('${REF_A}', '${REF_B}') AND click_id = (SELECT click_id FROM wp_kntnt_ad_attr_conversions WHERE conversion_ref = '${REF_B}') LIMIT 1" | jq -r '.conversion_ref')
execute_sql "INSERT INTO wp_kntnt_ad_attr_queue (reporter, payload, status, created_at, conversion_ref) VALUES ('probe', '{}', 'failed', '2026-01-01 00:00:00', '${REF_B}')" > /dev/null
assert_equals "1" "$(count_jobs "$REF_B2" pending)" "The second conversion on B's click has a pending job"

ROW_B2=$(conversion_row "$(conversion_list)" "$REF_B2")
result=$(admin_get "$(action_url "$ROW_B2" conversion_mark_test)")
assert_contains "$result" "count=2" "Mark as test counts both conversions on the click"
assert_contains "$result" "jobs_deleted=2" "Mark as test reports the unfinished jobs of both conversions"
assert_equals "0" "$(count_jobs "$REF_B2" pending)" "Mark as test removes the marked conversion's pending job"
assert_equals "0" "$(count_jobs "$REF_B" failed)" "Mark as test removes the failed job of the other conversion on the click"
notice_html=$(curl -sf -b "${ADMIN_COOKIE}" "${result#* }")
assert_contains "$notice_html" "2 conversion(s) marked as test. 2 unfinished report job(s) deleted." "The notice counts both conversions and their jobs"

result=$(admin_get "$(action_url "$(conversion_row "$(conversion_list)" "$REF_B2")" conversion_unmark_test)")
assert_contains "$result" "count=2" "Unmark counts both conversions on the click"
assert_equals "0" "$(click_is_test "$REF_B")" "Unmark clears the flag of the shared click"

# --- Cleanup ---

execute_sql "DELETE FROM wp_kntnt_ad_attr_queue WHERE conversion_ref IN ('${REF_A}', '${REF_B}', '${REF_B2}')" > /dev/null
reset_probes
set_consent_state "default"
clear_cookies

print_summary
