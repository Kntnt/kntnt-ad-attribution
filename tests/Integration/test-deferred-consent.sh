#!/usr/bin/env bash
# Integration test: Deferred consent carries the visitor's own click.
#
# With undetermined consent, visitors A and B click the same tracking URL a
# few seconds apart. Consent is then granted, and A posts the hash:timestamp
# pair from the pending-consent transport to set-cookie. A's _ad_clicks entry
# must carry A's click timestamp, not B's. Runs once per transport (cookie
# and fragment).

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/helpers/assertions.sh"
source "$SCRIPT_DIR/helpers/fixtures.sh"

echo "=== Test: Deferred Consent ==="

BROWSER_UA="Mozilla/5.0 Chrome/120.0.0.0"

# Set the pending-consent transport ('' restores the plugin default).
# Usage: set_pending_transport <cookie|fragment|''>
set_pending_transport() {
    curl -sf "${WP_BASE_URL}/?rest_route=/kntnt-ad-attribution/v1/test-set-option" \
        -X POST \
        -H "Content-Type: application/json" \
        -H "X-WP-Nonce: ${WP_NONCE}" \
        -b "${ADMIN_COOKIE}" \
        -d "{\"option\":\"test_pending_transport\",\"value\":\"$1\"}" > /dev/null
}

# Reset the set-cookie rate limit, which all suites share because every
# request comes from localhost.
reset_rate_limit() {
    execute_sql "DELETE FROM wp_options WHERE option_name LIKE '%kntnt_ad_attr_rl_%'" > /dev/null
}

# Click a tracking URL and print the click pair the transport hands over.
# Usage: click_pair <hash> <cookie|fragment>
click_pair() {
    local hash="$1" transport="$2"
    if [[ "$transport" == "cookie" ]]; then
        extract_cookie_value "$(get_click_cookies "$hash" "$BROWSER_UA")" "_aah_pending"
    else
        curl -sS -D - -o /dev/null -A "$BROWSER_UA" --max-redirs 0 \
            "${WP_BASE_URL}/ad/${hash}/" 2>/dev/null \
            | grep -i '^location:' | tr -d '\r' | sed 's/.*#_aah=//' || true
    fi
}

# Post click pairs to set-cookie as an anonymous visitor would.
# Usage: post_set_cookie <pair>
# Returns: response headers followed by the JSON body
post_set_cookie() {
    curl -sS -D - "${WP_BASE_URL}/?rest_route=/kntnt-ad-attribution/v1/set-cookie" \
        -X POST \
        -H "Content-Type: application/json" \
        -A "$BROWSER_UA" \
        -d "{\"hashes\":[\"$1\"]}" 2>/dev/null || true
}

# Run the A/B scenario for one transport.
# Usage: run_scenario <cookie|fragment>
run_scenario() {
    local transport="$1"

    echo ""
    echo "--- Transport: $transport ---"

    clear_cookies
    set_pending_transport "$transport"
    set_consent_state "pending"

    local hash
    hash=$(create_tracking_url "$TARGET_ID" "google" "cpc" "deferred-$transport")

    # Visitor A clicks.
    local pair_a ts_a
    pair_a=$(click_pair "$hash" "$transport")
    assert_contains "$pair_a" "${hash}:" "A's transport carries the hash and a timestamp"
    ts_a="${pair_a#*:}"

    local row_a
    row_a=$(query_db "SELECT COUNT(*) AS cnt FROM wp_kntnt_ad_attr_clicks WHERE hash='$hash' AND clicked_at='$(date -u -d "@$ts_a" '+%F %T')'" | jq -r '.cnt')
    assert_equals "1" "$row_a" "A's transport timestamp is the clicked_at of A's click row"

    # Visitor B clicks the same tracking URL later.
    sleep 2
    local pair_b ts_b
    pair_b=$(click_pair "$hash" "$transport")
    ts_b="${pair_b#*:}"
    assert_greater_than "$ts_a" "$ts_b" "B's click is later than A's"

    # A grants consent and the client script posts A's pair.
    set_consent_state "granted"
    local response success ad_clicks
    response=$(post_set_cookie "$pair_a")
    success=$(echo "$response" | tail -n 1 | jq -r '.success')
    assert_equals "true" "$success" "set-cookie accepts A's pair"

    ad_clicks=$(extract_cookie_value "$response" "_ad_clicks")
    assert_contains "$ad_clicks" "${hash}:${ts_a}" "A's _ad_clicks entry carries A's click timestamp"
    assert_not_contains "$ad_clicks" "${hash}:${ts_b}" "A's _ad_clicks entry does not carry B's click timestamp"
}

# --- Setup ---

reset_rate_limit

TARGET_JSON=$(create_target_page "Deferred Consent Landing")
TARGET_ID=$(echo "$TARGET_JSON" | jq -r '.post_id')
flush_rewrites

# --- Scenarios ---

run_scenario "cookie"
run_scenario "fragment"

# --- Cleanup ---

set_pending_transport ""
set_consent_state "default"
clear_cookies
reset_rate_limit

print_summary
