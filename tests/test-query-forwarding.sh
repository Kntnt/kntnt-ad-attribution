#!/usr/bin/env bash
#
# Integration test for query parameter forwarding through ad redirects.
#
# Creates temporary fixtures (page + tracking URL CPT), fires curl requests
# against the DDEV site, and asserts on the 302 Location header. Cleans up
# on exit regardless of outcome.
#
# Usage: bash wp-content/plugins/kntnt-ad-attribution/tests/test-query-forwarding.sh
#        (run from the project root where `ddev` is available)

set -euo pipefail

# ── Helpers ──────────────────────────────────────────────────────────────

FAILURES=0
TESTS=0

pass() {
    TESTS=$((TESTS + 1))
    printf "\033[32mPASS\033[0m: %s\n" "$1"
}

fail() {
    TESTS=$((TESTS + 1))
    FAILURES=$((FAILURES + 1))
    printf "\033[31mFAIL\033[0m: %s\n" "$1"
}

# Extract the last Location header from curl -L -I output (skips the 301
# trailing-slash redirect and returns the 302 from our click handler).
get_location() {
    grep -i "^location:" | tail -1 | sed 's/^[Ll]ocation: *//' | tr -d '\r'
}

# ── Phase 1: Setup fixtures ─────────────────────────────────────────────

echo ""
echo "=== Setup ==="

# Verify the plugin is active.
if ! ddev wp plugin is-active kntnt-ad-attribution 2>/dev/null; then
    echo "ERROR: Plugin kntnt-ad-attribution is not active. Aborting."
    exit 2
fi

# Resolve the site URL for host-side curl (avoids shell escaping issues
# with ddev exec when URLs contain & characters).
SITE_URL=$(ddev wp eval 'echo home_url();')
echo "Site URL: ${SITE_URL}"

# Get the database table prefix.
PREFIX=$(ddev wp eval 'global $wpdb; echo $wpdb->prefix;')
echo "Table prefix: ${PREFIX}"

# Deterministic hash for the test fixture.
HASH=$(echo -n "test-query-param-forwarding-fixture" | shasum -a 256 | cut -d' ' -f1)
echo "Test hash: ${HASH}"

# Track fixture IDs for cleanup.
TARGET_ID=""
AD_ID=""
ORIGINAL_STRUCTURE=""

cleanup() {
    echo ""
    echo "=== Teardown ==="

    # Restore permalink structure if it was changed.
    if [[ -n "$ORIGINAL_STRUCTURE" ]]; then
        ddev wp rewrite structure "$ORIGINAL_STRUCTURE" --quiet 2>/dev/null || true
        ddev wp rewrite flush --quiet 2>/dev/null || true
        echo "Restored permalink structure: ${ORIGINAL_STRUCTURE}"
    fi

    # Delete test posts.
    if [[ -n "$AD_ID" ]]; then
        ddev wp post delete "$AD_ID" --force --quiet 2>/dev/null || true
        echo "Deleted tracking URL post: ${AD_ID}"
    fi
    if [[ -n "$TARGET_ID" ]]; then
        ddev wp post delete "$TARGET_ID" --force --quiet 2>/dev/null || true
        echo "Deleted target page: ${TARGET_ID}"
    fi

    # Remove stats rows.
    ddev mysql -uroot -proot -e "DELETE FROM ${PREFIX}kntnt_ad_attr_stats WHERE hash='${HASH}';" 2>/dev/null || true
    echo "Cleaned stats rows."

    echo ""
    echo "=== Results: ${TESTS} tests, ${FAILURES} failures ==="

    if [[ $FAILURES -gt 0 ]]; then
        exit 1
    fi
    exit 0
}

trap cleanup EXIT

# Create a target page.
TARGET_ID=$(ddev wp post create --post_type=page --post_title="QP Test Landing" --post_status=publish --porcelain)
echo "Created target page: ${TARGET_ID}"

# Create the tracking URL CPT post with required meta.
AD_ID=$(ddev wp post create --post_type=kntnt_ad_attr_url --post_title="QP Test Ad" --post_status=publish --porcelain)
ddev wp post meta update "$AD_ID" _hash "$HASH"
ddev wp post meta update "$AD_ID" _target_post_id "$TARGET_ID"
echo "Created tracking URL post: ${AD_ID} → target ${TARGET_ID}"

# Flush rewrite rules so /ad/<hash> resolves.
ddev wp rewrite flush --quiet
echo "Rewrite rules flushed."

# ── Phase 2: Pretty permalink tests ─────────────────────────────────────

echo ""
echo "=== Pretty permalink tests ==="

# Test 1 & 2: Basic parameter forwarding + hash stripped.
# Uses -L to follow the WordPress 301 trailing-slash redirect, then
# captures the 302 Location from our click handler (the last one).
LOCATION=$(curl -skIL -A "Mozilla/5.0" "${SITE_URL}/ad/${HASH}?foo=bar&baz=qux" | get_location)
echo "Location: ${LOCATION}"

if echo "$LOCATION" | grep -q "foo=bar"; then
    pass "Incoming param foo=bar forwarded"
else
    fail "Incoming param foo=bar missing from redirect"
fi

if echo "$LOCATION" | grep -q "baz=qux"; then
    pass "Incoming param baz=qux forwarded"
else
    fail "Incoming param baz=qux missing from redirect"
fi

if echo "$LOCATION" | grep -qi "kntnt_ad_attr_hash"; then
    fail "Internal hash param leaked into redirect URL"
else
    pass "Internal hash param not in redirect URL"
fi

# Test 3: No params → clean redirect.
CLEAN_LOCATION=$(curl -skIL -A "Mozilla/5.0" "${SITE_URL}/ad/${HASH}" | get_location)
echo "Clean location: ${CLEAN_LOCATION}"

if echo "$CLEAN_LOCATION" | grep -q "?"; then
    fail "Unexpected query string in clean redirect"
else
    pass "No query string when no incoming params"
fi

# Test 4: Click logged in stats table (non-bot requests from tests above).
CLICKS=$(ddev mysql -uroot -proot -N -B -e "SELECT COALESCE(SUM(clicks), 0) FROM ${PREFIX}kntnt_ad_attr_stats WHERE hash='${HASH}';")

if [[ "$CLICKS" -ge 1 ]]; then
    pass "Click logged in stats table (${CLICKS} clicks)"
else
    fail "No clicks logged in stats table"
fi

# Test 5: Bot gets redirected but NOT logged.
# curl's default UA contains "curl", which the bot detector flags.
CLICKS_BEFORE=$(ddev mysql -uroot -proot -N -B -e "SELECT COALESCE(SUM(clicks), 0) FROM ${PREFIX}kntnt_ad_attr_stats WHERE hash='${HASH}';")

curl -skIL "${SITE_URL}/ad/${HASH}?bot_test=1" > /dev/null

CLICKS_AFTER=$(ddev mysql -uroot -proot -N -B -e "SELECT COALESCE(SUM(clicks), 0) FROM ${PREFIX}kntnt_ad_attr_stats WHERE hash='${HASH}';")

if [[ "$CLICKS_BEFORE" == "$CLICKS_AFTER" ]]; then
    pass "Bot click not logged"
else
    fail "Bot click was logged (before=${CLICKS_BEFORE}, after=${CLICKS_AFTER})"
fi

# Test 6: Bot redirect also forwards parameters.
BOT_LOCATION=$(curl -skIL "${SITE_URL}/ad/${HASH}?gclid=bot123" | get_location)
echo "Bot location: ${BOT_LOCATION}"

if echo "$BOT_LOCATION" | grep -q "gclid=bot123"; then
    pass "Bot redirect forwards query params"
else
    fail "Bot redirect missing query params"
fi

# ── Phase 3: Plain permalink tests (target URL precedence) ──────────────

echo ""
echo "=== Plain permalink tests (target URL precedence) ==="

# Save the current permalink structure so we can restore it.
ORIGINAL_STRUCTURE=$(ddev wp eval 'echo get_option("permalink_structure");')
echo "Saved permalink structure: '${ORIGINAL_STRUCTURE}'"

# Switch to plain permalinks so get_permalink() returns ?p=<id>.
ddev wp rewrite structure "" --quiet
ddev wp rewrite flush --quiet
echo "Switched to plain permalinks."

# With plain permalinks, the tracking URL is accessed via the query var
# directly: /?kntnt_ad_attr_hash=<hash>. Pages use page_id (not p) in
# plain permalink URLs, so we send a conflicting page_id=99999 to verify
# the target URL's page_id=<TARGET_ID> wins.
PLAIN_LOCATION=$(curl -skIL -A "Mozilla/5.0" "${SITE_URL}/?kntnt_ad_attr_hash=${HASH}&page_id=99999&foo=bar" | get_location)
echo "Plain location: ${PLAIN_LOCATION}"

if echo "$PLAIN_LOCATION" | grep -q "page_id=${TARGET_ID}"; then
    pass "Target URL param page_id=${TARGET_ID} takes precedence over incoming page_id=99999"
else
    fail "Target URL param did not take precedence (expected page_id=${TARGET_ID})"
fi

if echo "$PLAIN_LOCATION" | grep -q "foo=bar"; then
    pass "Non-conflicting incoming param foo=bar preserved"
else
    fail "Non-conflicting incoming param foo=bar lost"
fi

if echo "$PLAIN_LOCATION" | grep -qi "kntnt_ad_attr_hash"; then
    fail "Internal hash param leaked in plain permalink mode"
else
    pass "Internal hash param stripped in plain permalink mode"
fi

# Restore permalink structure (also done in trap, but do it here so
# subsequent tests or manual work aren't affected).
ddev wp rewrite structure "$ORIGINAL_STRUCTURE" --quiet
ddev wp rewrite flush --quiet
ORIGINAL_STRUCTURE=""
echo "Restored permalink structure."
