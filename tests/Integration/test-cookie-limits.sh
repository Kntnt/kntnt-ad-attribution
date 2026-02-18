#!/usr/bin/env bash
# Integration test: Cookie capacity limits.
#
# Verifies that up to 50 hashes can be stored in the _ad_clicks cookie
# and that the 51st click evicts the oldest entry.

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/helpers/assertions.sh"
source "$SCRIPT_DIR/helpers/fixtures.sh"

echo "=== Test: Cookie Limits ==="

# --- Setup: consent granted ---

clear_cookies

set_consent_state "granted"

TARGET_JSON=$(create_target_page "Cookie Limit Landing")
TARGET_ID=$(echo "$TARGET_JSON" | jq -r '.post_id')

# --- Create 51 tracking URLs ---

declare -a HASHES
for i in $(seq 1 51); do
    h=$(create_tracking_url "$TARGET_ID" "google" "cpc" "limit-test-$i")
    HASHES+=("$h")
done
flush_rewrites

# --- Click all 50, accumulating cookies ---

COOKIE_FILE=$(mktemp)
trap "rm -f '$COOKIE_FILE'" EXIT

for i in $(seq 0 49); do
    curl -sS -o /dev/null \
        -c "$COOKIE_FILE" -b "$COOKIE_FILE" \
        -A "Mozilla/5.0 Chrome/120.0.0.0" \
        --max-redirs 0 \
        "${WP_BASE_URL}/ad/${HASHES[$i]}/" 2>/dev/null || true

    # Strip Secure flag so curl sends _ad_clicks back over HTTP.
    strip_secure_flag "$COOKIE_FILE"
done

# --- Verify 50 hashes in cookie ---

cookie_value=$(grep "_ad_clicks" "$COOKIE_FILE" | awk '{print $NF}')
if [[ -n "$cookie_value" ]]; then
    # URL-decode commas (%2C → ,) and count entries.
    decoded=$(python3 -c "import urllib.parse,sys; print(urllib.parse.unquote(sys.argv[1]))" "$cookie_value" 2>/dev/null || echo "$cookie_value")
    entry_count=$(echo "$decoded" | tr ',' '\n' | wc -l | tr -d ' ')
    assert_equals "50" "$entry_count" "Cookie holds 50 entries after 50 clicks"
else
    TESTS_RUN=$((TESTS_RUN + 1))
    echo "  FAIL: _ad_clicks cookie not found after 50 clicks"
    TESTS_FAILED=$((TESTS_FAILED + 1))
fi

# --- Click 51st: oldest entry evicted ---

curl -sS -o /dev/null \
    -c "$COOKIE_FILE" -b "$COOKIE_FILE" \
    -A "Mozilla/5.0 Chrome/120.0.0.0" \
    --max-redirs 0 \
    "${WP_BASE_URL}/ad/${HASHES[50]}/" 2>/dev/null || true
strip_secure_flag "$COOKIE_FILE"

cookie_value=$(grep "_ad_clicks" "$COOKIE_FILE" | awk '{print $NF}')
if [[ -n "$cookie_value" ]]; then
    decoded=$(python3 -c "import urllib.parse,sys; print(urllib.parse.unquote(sys.argv[1]))" "$cookie_value" 2>/dev/null || echo "$cookie_value")
    entry_count=$(echo "$decoded" | tr ',' '\n' | wc -l | tr -d ' ')
    assert_equals "50" "$entry_count" "Cookie still has 50 entries after 51st click (evicted oldest)"

    # 51st hash should be present.
    assert_contains "$decoded" "${HASHES[50]}" "51st hash present in cookie"

    # First hash should be evicted.
    assert_not_contains "$decoded" "${HASHES[0]}" "1st hash evicted from cookie"
else
    TESTS_RUN=$((TESTS_RUN + 1))
    echo "  FAIL: _ad_clicks cookie not found after 51st click"
    TESTS_FAILED=$((TESTS_FAILED + 1))
fi

# --- Cookie format valid (each entry is hash:timestamp) ---

if [[ -n "$cookie_value" ]]; then
    valid=true
    while IFS= read -r entry; do
        if [[ ! "$entry" =~ ^[a-f0-9]{64}:[0-9]+$ ]]; then
            valid=false
            break
        fi
    done <<< "$(echo "$decoded" | tr ',' '\n')"
    TESTS_RUN=$((TESTS_RUN + 1))
    if $valid; then
        echo "  PASS: Cookie format valid (all entries match hash:timestamp)"
    else
        echo "  FAIL: Cookie format invalid — entry does not match pattern"
        TESTS_FAILED=$((TESTS_FAILED + 1))
    fi
fi

# --- Cleanup ---

set_consent_state "default"

print_summary
