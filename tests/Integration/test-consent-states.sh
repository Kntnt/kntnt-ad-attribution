#!/usr/bin/env bash
# Integration test: Consent-dependent cookie setting.
#
# Verifies that the correct cookies are set depending on consent state:
# granted → _ad_clicks, denied → no cookie, pending → _aah_pending.
# Click is recorded regardless of consent state.

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/helpers/assertions.sh"
source "$SCRIPT_DIR/helpers/fixtures.sh"

echo "=== Test: Consent States ==="

# --- Setup ---

clear_cookies

TARGET_JSON=$(create_target_page "Consent Landing")
TARGET_ID=$(echo "$TARGET_JSON" | jq -r '.post_id')

HASH_GRANTED=$(create_tracking_url "$TARGET_ID" "google" "cpc" "consent-granted")
HASH_DENIED=$(create_tracking_url "$TARGET_ID" "facebook" "cpm" "consent-denied")
HASH_PENDING=$(create_tracking_url "$TARGET_ID" "bing" "cpc" "consent-pending")
flush_rewrites

# --- Consent granted: _ad_clicks cookie set ---

set_consent_state "granted"
cookies=$(get_click_cookies "$HASH_GRANTED")
assert_contains "$cookies" "_ad_clicks=" "Consent granted: _ad_clicks cookie set"

# --- _ad_clicks cookie attributes ---

# Cookie attributes are lowercase in PHP's Set-Cookie output.
assert_contains "$cookies" "HttpOnly" "Cookie _ad_clicks is HttpOnly"
assert_contains "$cookies" "secure" "Cookie _ad_clicks is Secure"
assert_contains "$cookies" "SameSite=Lax" "Cookie _ad_clicks has SameSite=Lax"

# --- Consent denied: no _ad_clicks cookie ---

set_consent_state "denied"
cookies=$(get_click_cookies "$HASH_DENIED")
has_ad_clicks=$(echo "$cookies" | grep -c "_ad_clicks=" || true)
assert_equals "0" "$has_ad_clicks" "Consent denied: no _ad_clicks cookie"

# --- Consent pending: _aah_pending transport cookie set ---

set_consent_state "pending"
cookies=$(get_click_cookies "$HASH_PENDING")
assert_contains "$cookies" "_aah_pending=" "Consent pending: _aah_pending cookie set"

# --- _aah_pending has 60-second max-age ---

assert_contains "$cookies" "Max-Age=60" "Transport cookie has 60-second Max-Age"

# --- _aah_pending is NOT HttpOnly ---

# Extract only the _aah_pending cookie line.
pending_line=$(echo "$cookies" | grep "_aah_pending=" || true)
has_httponly=$(echo "$pending_line" | grep -ci "HttpOnly" || true)
assert_equals "0" "$has_httponly" "Transport cookie is NOT HttpOnly"

# --- Click recorded regardless of consent state ---

# All three hashes should have clicks recorded.
for hash in "$HASH_GRANTED" "$HASH_DENIED" "$HASH_PENDING"; do
    count=$(get_click_count "$hash")
    assert_greater_than "0" "$count" "Click recorded for hash ${hash:0:8}... regardless of consent"
done

# --- Reset consent to default ---

set_consent_state "default"

print_summary
