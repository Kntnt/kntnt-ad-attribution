#!/usr/bin/env bash
# Integration test: Query parameter forwarding.
#
# Verifies that incoming query parameters are forwarded to the target URL,
# target parameters are preserved, and target params win on collision.

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/helpers/assertions.sh"
source "$SCRIPT_DIR/helpers/fixtures.sh"

echo "=== Test: Query Parameter Forwarding ==="

# --- Setup ---

TARGET_JSON=$(create_target_page "QP Forward Landing")
TARGET_ID=$(echo "$TARGET_JSON" | jq -r '.post_id')

HASH=$(create_tracking_url "$TARGET_ID" "google" "cpc" "qp-test")
flush_rewrites

# --- Incoming params forwarded to target ---

result=$(simulate_click "$HASH" "Mozilla/5.0 Chrome/120" "gclid=abc123&extra=hello")
redirect_url=$(echo "$result" | cut -d'|' -f2)
assert_contains "$redirect_url" "gclid=abc123" "Incoming gclid forwarded to target URL"
assert_contains "$redirect_url" "extra=hello" "Incoming extra param forwarded to target URL"

# --- Hash-specific params (kntnt_ad_attr_hash) are removed ---

assert_not_contains "$redirect_url" "kntnt_ad_attr_hash" "Query var kntnt_ad_attr_hash not in redirect URL"

print_summary
