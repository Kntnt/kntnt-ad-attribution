#!/usr/bin/env bash
# Integration test: Counting rules.
#
# Verifies that a request repeating a click ID already recorded for the
# tracking URL and a speculative (prefetch) request are not counted as
# clicks, that a click ID declared non-unique (a shared gbraid) does not make
# a request a repeat, and that every tracking URL request writes exactly one
# diagnostic log line when logging is enabled, and none when it is not.

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/helpers/assertions.sh"
source "$SCRIPT_DIR/helpers/fixtures.sh"

echo "=== Test: Counting Rules ==="

BROWSER_UA="Mozilla/5.0 Chrome/120.0.0.0"

# --- Setup ---

clear_cookies
set_consent_state "granted"
set_probe_capturers "google_ads=gclid"
set_logging "on"
clear_log

TARGET_JSON=$(create_target_page "Counting Rules Landing")
TARGET_ID=$(echo "$TARGET_JSON" | jq -r '.post_id')
HASH_REPEAT=$(create_tracking_url "$TARGET_ID" "google" "cpc" "counting-repeat")
HASH_SPECULATIVE=$(create_tracking_url "$TARGET_ID" "google" "cpc" "counting-speculative")
HASH_SHARED=$(create_tracking_url "$TARGET_ID" "google" "cpc" "counting-shared-gbraid")
flush_rewrites

# Requests a tracking URL carrying exactly the given plugin cookies ("" for a
# new visitor without any) and prints the status code, the redirect URL and
# the _ad_clicks value, separated by |.
# Usage: request_tracking_url <hash> <query> <plugin cookies> [curl options…]
request_tracking_url() {
    local hash="$1" query="$2" cookies="$3"
    shift 3
    local headers
    headers=$(curl -sS -D - -o /dev/null \
        -w 'HTTP_CODE:%{http_code}|%{redirect_url}' \
        --max-redirs 0 \
        -H "$(plugin_cookies "$cookies")" \
        "$@" \
        "${WP_BASE_URL}/ad/${hash}/${query:+?$query}" 2>/dev/null || true)
    local status_and_url="${headers##*HTTP_CODE:}"
    echo "${status_and_url}|$(extract_cookie_value "$headers" "_ad_clicks")"
}

# ─── Two requests with the same gclid give one click row ───

first=$(request_tracking_url "$HASH_REPEAT" "gclid=RRR" "" -A "$BROWSER_UA")
assert_equals "302" "$(echo "$first" | cut -d'|' -f1)" "First request with gclid=RRR redirects"
assert_equals "1" "$(get_click_count "$HASH_REPEAT")" "First request with gclid=RRR is recorded"
original_clicked_at=$(query_db "SELECT clicked_at FROM wp_kntnt_ad_attr_clicks WHERE hash = '${HASH_REPEAT}'" | jq -r '.clicked_at')
original_entry="${HASH_REPEAT}:$(jq -rn --arg d "$original_clicked_at" '$d | sub(" "; "T") + "Z" | fromdate')"
assert_equals "$original_entry" "$(echo "$first" | cut -d'|' -f3)" "First request's visitor gets one entry, pointing at its click"

# A second apart at least, so a wrongly recorded repeat would get a new
# timestamp that the cookie assertion below would catch.
sleep 2

repeat=$(request_tracking_url "$HASH_REPEAT" "gclid=RRR" "" -A "$BROWSER_UA")
assert_equals "302" "$(echo "$repeat" | cut -d'|' -f1)" "Repeat request redirects"
assert_contains "$(echo "$repeat" | cut -d'|' -f2)" "gclid=RRR" "Repeat request forwards its query parameters"
assert_equals "1" "$(get_click_count "$HASH_REPEAT")" "Repeat request adds no click row"
assert_equals "1" "$(query_db "SELECT COUNT(*) AS cnt FROM wp_kntnt_ad_attr_click_ids ci JOIN wp_kntnt_ad_attr_clicks c ON c.id = ci.click_id WHERE c.hash = '${HASH_REPEAT}'" | jq -r '.cnt')" \
    "Repeat request stores no click ID"
# The repeat comes from a visitor without an entry for the hash (another
# browser, a shared link), so its new entry must point at the original click.
assert_equals "$original_entry" "$(echo "$repeat" | cut -d'|' -f3)" \
    "Repeat request by a visitor without an entry gets one pointing at the original click"

# ─── A shared non-unique gbraid does not make a request a repeat ───

set_probe_capturers "google_ads=gclid,google_ads_gbraid=gbraid:non-unique"

# Prints the _ad_clicks entry for the click row on HASH_SHARED that carries
# the given value for the given platform.
# Usage: entry_for_click_id <platform> <value>
entry_for_click_id() {
    local clicked_at
    clicked_at=$(query_db "SELECT c.clicked_at FROM wp_kntnt_ad_attr_clicks c JOIN wp_kntnt_ad_attr_click_ids ci ON ci.click_id = c.id WHERE c.hash = '${HASH_SHARED}' AND ci.platform = '$1' AND ci.value = '$2'" | jq -r '.clicked_at')
    echo "${HASH_SHARED}:$(jq -rn --arg d "$clicked_at" '$d | sub(" "; "T") + "Z" | fromdate')"
}

# Prints how many click rows on HASH_SHARED carry gbraid X.
shared_gbraid_rows() {
    query_db "SELECT COUNT(*) AS cnt FROM wp_kntnt_ad_attr_click_ids ci JOIN wp_kntnt_ad_attr_clicks c ON c.id = ci.click_id WHERE c.hash = '${HASH_SHARED}' AND ci.platform = 'google_ads_gbraid' AND ci.value = 'X'" | jq -r '.cnt'
}

visitor_a=$(request_tracking_url "$HASH_SHARED" "gclid=G1&gbraid=X" "" -A "$BROWSER_UA")
assert_equals "1" "$(get_click_count "$HASH_SHARED")" "Visitor A with gclid=G1 and gbraid=X is recorded"

# A second apart at least, so visitor B's click gets a timestamp of its own.
sleep 2

visitor_b=$(request_tracking_url "$HASH_SHARED" "gclid=G2&gbraid=X" "" -A "$BROWSER_UA")
assert_equals "2" "$(get_click_count "$HASH_SHARED")" "Visitor B with a new gclid and the same gbraid gets a click row of its own"
assert_equals "$(entry_for_click_id google_ads G2)" "$(echo "$visitor_b" | cut -d'|' -f3)" \
    "Visitor B's entry points at the click row carrying G2"
assert_equals "$(entry_for_click_id google_ads G1)" "$(echo "$visitor_a" | cut -d'|' -f3)" \
    "Visitor A's entry points at the click row carrying G1"
assert_equals "2" "$(shared_gbraid_rows)" "Both clicks store gbraid X"

sleep 2

visitor_c=$(request_tracking_url "$HASH_SHARED" "gbraid=X" "" -A "$BROWSER_UA")
assert_equals "3" "$(get_click_count "$HASH_SHARED")" "Visitor C with only the same gbraid is recorded as a new click"
assert_equals "3" "$(shared_gbraid_rows)" "Visitor C's click stores gbraid X"

reload_a=$(request_tracking_url "$HASH_SHARED" "gclid=G1&gbraid=X" "" -A "$BROWSER_UA")
assert_equals "3" "$(get_click_count "$HASH_SHARED")" "Visitor A's reload with gclid=G1 is still a repeat"
assert_equals "$(entry_for_click_id google_ads G1)" "$(echo "$reload_a" | cut -d'|' -f3)" \
    "Visitor A's reload points at visitor A's click"

set_probe_capturers "google_ads=gclid"

# ─── A prefetch followed by a normal request gives one click row ───

prefetch=$(request_tracking_url "$HASH_SPECULATIVE" "" "" -A "$BROWSER_UA" -H "Sec-Purpose: prefetch")
assert_equals "503" "$(echo "$prefetch" | cut -d'|' -f1)" "Prefetch gets 503"
assert_equals "" "$(echo "$prefetch" | cut -d'|' -f2)" "Prefetch is not redirected"
assert_equals "" "$(echo "$prefetch" | cut -d'|' -f3)" "Prefetch sets no _ad_clicks cookie"
assert_equals "0" "$(get_click_count "$HASH_SPECULATIVE")" "Prefetch adds no click row"

click=$(request_tracking_url "$HASH_SPECULATIVE" "" "" -A "$BROWSER_UA")
assert_equals "302" "$(echo "$click" | cut -d'|' -f1)" "Normal request after the prefetch redirects"
assert_equals "1" "$(get_click_count "$HASH_SPECULATIVE")" "Normal request after the prefetch is recorded once"
click_entry=$(echo "$click" | cut -d'|' -f3)

# ─── A bot and a deduplicated click, for the log ───

bot=$(request_tracking_url "$HASH_SPECULATIVE" "" "" -A "Googlebot/2.1")
assert_equals "302" "$(echo "$bot" | cut -d'|' -f1)" "Bot is redirected"

set_dedup_seconds 3600
duplicate=$(request_tracking_url "$HASH_SPECULATIVE" "" "_ad_clicks=${click_entry}" -A "$BROWSER_UA")
set_dedup_seconds 0
assert_equals "302" "$(echo "$duplicate" | cut -d'|' -f1)" "Duplicate click is redirected"
assert_equals "1" "$(get_click_count "$HASH_SPECULATIVE")" "Bot and duplicate add no click row"

# ─── Exactly one log line per request, holding outcome and headers, no IP ───

log=$(get_log)
lines_for() {
    echo "$log" | grep -F "Tracking URL request:" | grep -F "hash=${1:0:8}" || true
}
repeat_lines=$(lines_for "$HASH_REPEAT")
speculative_lines=$(lines_for "$HASH_SPECULATIVE")
shared_lines=$(lines_for "$HASH_SHARED")

assert_equals "2" "$(echo "$repeat_lines" | grep -c . || true)" "Two requests to the repeat URL wrote two lines"
assert_equals "4" "$(echo "$speculative_lines" | grep -c . || true)" "Four requests to the speculative URL wrote four lines"

assert_contains "$(echo "$repeat_lines" | sed -n 1p)" "outcome=recorded" "First gclid request is logged as recorded"
assert_contains "$(echo "$repeat_lines" | sed -n 2p)" "outcome=repeat" "Second gclid request is logged as repeat"
assert_equals "4" "$(echo "$shared_lines" | grep -c . || true)" "Four requests to the shared-gbraid URL wrote four lines"
assert_contains "$(echo "$shared_lines" | sed -n 2p)" "outcome=recorded" "Visitor B, sharing visitor A's gbraid, is logged as recorded"
assert_contains "$(echo "$shared_lines" | sed -n 3p)" "outcome=recorded" "Visitor C, with only a shared gbraid, is logged as recorded"
assert_contains "$(echo "$shared_lines" | sed -n 4p)" "outcome=repeat" "Visitor A's reload is logged as repeat"
assert_contains "$(echo "$speculative_lines" | sed -n 1p)" 'outcome=speculative hash=' "Prefetch is logged as speculative"
assert_contains "$(echo "$speculative_lines" | sed -n 1p)" 'sec_purpose="prefetch"' "Speculative line holds the Sec-Purpose header"
assert_contains "$(echo "$speculative_lines" | sed -n 2p)" "outcome=recorded" "Normal request is logged as recorded"
assert_contains "$(echo "$speculative_lines" | sed -n 2p)" 'sec_purpose=""' "Normal request carries no Sec-Purpose"
assert_contains "$(echo "$speculative_lines" | sed -n 3p)" "outcome=bot" "Bot is logged as bot"
assert_contains "$(echo "$speculative_lines" | sed -n 3p)" 'user_agent="Googlebot/2.1"' "Bot line holds the User-Agent header"
assert_contains "$(echo "$speculative_lines" | sed -n 4p)" "outcome=deduplicated" "Duplicate is logged as deduplicated"
assert_contains "$(echo "$speculative_lines" | sed -n 4p)" "user_agent=\"${BROWSER_UA}\"" "Lines hold the User-Agent header"
assert_contains "$(echo "$speculative_lines" | sed -n 4p)" 'purpose=""' "Lines hold the Purpose header"
assert_not_contains "$log" "127.0.0.1" "Log holds no IPv4 loopback address"
assert_not_contains "$log" "::1" "Log holds no IPv6 loopback address"

# ─── With logging disabled, nothing is written ───

set_logging "off"
clear_log
legacy_prefetch=$(request_tracking_url "$HASH_SPECULATIVE" "" "" -A "$BROWSER_UA" -H "Purpose: prefetch")
assert_equals "503" "$(echo "$legacy_prefetch" | cut -d'|' -f1)" "Legacy Purpose: prefetch gets 503"
assert_equals "1" "$(get_click_count "$HASH_SPECULATIVE")" "Legacy prefetch adds no click row"
assert_equals "" "$(get_log)" "Nothing is logged with logging disabled"

# --- Cleanup ---

reset_probes
set_consent_state "default"
clear_cookies

print_summary
