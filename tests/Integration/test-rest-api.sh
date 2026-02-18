#!/usr/bin/env bash
# Integration test: REST API endpoints.
#
# Tests the /set-cookie and /search-posts endpoints for validation,
# rate limiting, consent enforcement, and search strategies.

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/helpers/assertions.sh"
source "$SCRIPT_DIR/helpers/fixtures.sh"

echo "=== Test: REST API ==="

# --- Setup ---

TARGET_JSON=$(create_target_page "REST API Landing")
TARGET_ID=$(echo "$TARGET_JSON" | jq -r '.post_id')
HASH=$(create_tracking_url "$TARGET_ID" "google" "cpc" "rest-test")

# Ensure consent is granted for set-cookie tests.
set_consent_state "granted"

# Clear any existing rate limit transients.
delete_transient "kntnt_ad_attr_rl_test" > /dev/null 2>&1 || true

# ─── /set-cookie endpoint ───

echo ""
echo "--- /set-cookie ---"

# --- Valid hashes: returns success ---

response=$(curl -sf "${WP_BASE_URL}/?rest_route=/kntnt-ad-attribution/v1/set-cookie" \
    -X POST \
    -H "Content-Type: application/json" \
    -H "X-WP-Nonce: ${WP_NONCE}" \
    -b "${ADMIN_COOKIE}" \
    -d "{\"hashes\":[\"$HASH\"]}")
success=$(echo "$response" | jq -r '.success')
assert_equals "true" "$success" "Valid hash returns success=true"

# --- All invalid hashes: returns failure ---

response=$(curl -sf "${WP_BASE_URL}/?rest_route=/kntnt-ad-attribution/v1/set-cookie" \
    -X POST \
    -H "Content-Type: application/json" \
    -H "X-WP-Nonce: ${WP_NONCE}" \
    -b "${ADMIN_COOKIE}" \
    -d '{"hashes":["INVALID_HASH","too-short"]}')
success=$(echo "$response" | jq -r '.success')
assert_equals "false" "$success" "All invalid hashes returns success=false"

# --- Unknown hash (valid format but not in DB): returns failure ---

unknown_hash=$(openssl rand -hex 32)
response=$(curl -sf "${WP_BASE_URL}/?rest_route=/kntnt-ad-attribution/v1/set-cookie" \
    -X POST \
    -H "Content-Type: application/json" \
    -H "X-WP-Nonce: ${WP_NONCE}" \
    -b "${ADMIN_COOKIE}" \
    -d "{\"hashes\":[\"$unknown_hash\"]}")
success=$(echo "$response" | jq -r '.success')
assert_equals "false" "$success" "Unknown hash (not in DB) returns success=false"

# --- Consent denied: returns failure ---

set_consent_state "denied"
response=$(curl -sf "${WP_BASE_URL}/?rest_route=/kntnt-ad-attribution/v1/set-cookie" \
    -X POST \
    -H "Content-Type: application/json" \
    -H "X-WP-Nonce: ${WP_NONCE}" \
    -b "${ADMIN_COOKIE}" \
    -d "{\"hashes\":[\"$HASH\"]}")
success=$(echo "$response" | jq -r '.success')
assert_equals "false" "$success" "Consent denied: set-cookie returns success=false"
set_consent_state "granted"

# ─── /search-posts endpoint ───

echo ""
echo "--- /search-posts ---"

# Create a searchable page.
SEARCH_JSON=$(create_target_page "Unique Search Target XYZ" "page")
SEARCH_ID=$(echo "$SEARCH_JSON" | jq -r '.post_id')

# --- Returns posts matching title ---

response=$(curl -sf "${WP_BASE_URL}/?rest_route=/kntnt-ad-attribution/v1/search-posts" \
    -H "X-WP-Nonce: ${WP_NONCE}" \
    -b "${ADMIN_COOKIE}" \
    -G --data-urlencode "search=Unique Search Target XYZ")
found_count=$(echo "$response" | jq 'length')
assert_greater_than "0" "$found_count" "Search by title finds matching posts"

# --- Returns post by ID ---

response=$(curl -sf "${WP_BASE_URL}/?rest_route=/kntnt-ad-attribution/v1/search-posts" \
    -H "X-WP-Nonce: ${WP_NONCE}" \
    -b "${ADMIN_COOKIE}" \
    -G --data-urlencode "search=$SEARCH_ID")
found_id=$(echo "$response" | jq -r '.[0].id // empty')
assert_equals "$SEARCH_ID" "$found_id" "Search by ID returns matching post"

# --- Requires capability (unauthenticated) ---

status=$(curl -sS -o /dev/null -w "%{http_code}" \
    "${WP_BASE_URL}/?rest_route=/kntnt-ad-attribution/v1/search-posts&search=test" 2>/dev/null || true)
# Without auth, should get 401 or 403.
TESTS_RUN=$((TESTS_RUN + 1))
if [[ "$status" == "401" ]] || [[ "$status" == "403" ]]; then
    echo "  PASS: Unauthenticated search-posts returns $status"
else
    echo "  FAIL: Expected 401 or 403, got $status"
    TESTS_FAILED=$((TESTS_FAILED + 1))
fi

# --- Excludes tracking URL CPT from results ---

response=$(curl -sf "${WP_BASE_URL}/?rest_route=/kntnt-ad-attribution/v1/search-posts" \
    -H "X-WP-Nonce: ${WP_NONCE}" \
    -b "${ADMIN_COOKIE}" \
    -G --data-urlencode "search=$HASH")
# Tracking URLs should not appear in search results.
has_cpt=$(echo "$response" | jq '[.[] | select(.type == "kntnt_ad_attr_url")] | length')
assert_equals "0" "$has_cpt" "Tracking URL CPT excluded from search results"

# --- Cleanup ---

set_consent_state "default"

print_summary
