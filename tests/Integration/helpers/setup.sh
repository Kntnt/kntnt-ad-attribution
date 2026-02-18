#!/usr/bin/env bash
# Bootstrap the integration test environment.
#
# Authenticates with the Playground WordPress instance, obtains a REST nonce,
# enables pretty permalinks, and flushes rewrite rules. Exports WP_NONCE and
# ADMIN_COOKIE for use by test scripts.
#
# This script is sourced (not executed) by run-tests.sh.

set -euo pipefail

export WP_BASE_URL="${WP_BASE_URL:-http://localhost:9400}"
COOKIE_JAR="$(mktemp)"
export COOKIE_JAR

# Log in as admin (Playground auto-creates admin/password).
echo "Authenticating with WordPress..."
curl -sf -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    "${WP_BASE_URL}/wp-login.php" \
    -d "log=admin&pwd=password&wp-submit=Log+In&redirect_to=%2Fwp-admin%2F" \
    -L -o /dev/null

# Extract the REST nonce from the admin page HTML (cookie-based REST auth
# requires the nonce in X-WP-Nonce header, which creates a chicken-and-egg
# problem with a REST-based nonce endpoint).
echo "Extracting REST nonce from admin page..."
ADMIN_HTML=$(curl -sf -b "$COOKIE_JAR" "${WP_BASE_URL}/wp-admin/")
export WP_NONCE
WP_NONCE=$(echo "$ADMIN_HTML" | grep -oP '"nonce":"[^"]+' | head -1 | grep -oP '[^"]+$')

if [[ -z "$WP_NONCE" || "$WP_NONCE" == "null" ]]; then
    echo "ERROR: Could not extract REST nonce from admin page." >&2
    echo "Verify login works and the admin dashboard loads." >&2
    exit 1
fi

# Enable pretty permalinks (Playground defaults to plain permalinks, which
# disables the rewrite rule system the plugin depends on).
echo "Enabling pretty permalinks..."
curl -sf -b "$COOKIE_JAR" \
    "${WP_BASE_URL}/?rest_route=/kntnt-ad-attribution/v1/test-set-option" \
    -X POST -H "Content-Type: application/json" -H "X-WP-Nonce: ${WP_NONCE}" \
    -d '{"option":"permalink_structure","value":"/%postname%/"}' > /dev/null

# Flush rewrite rules (registers the plugin's /ad/<hash> route).
curl -sf -b "$COOKIE_JAR" \
    "${WP_BASE_URL}/?rest_route=/kntnt-ad-attribution/v1/test-flush-rewrites" \
    -X POST -H "X-WP-Nonce: ${WP_NONCE}" > /dev/null

# Export the cookie jar path for fixtures.sh.
export ADMIN_COOKIE="$COOKIE_JAR"

echo "Bootstrap complete: nonce=$WP_NONCE"
