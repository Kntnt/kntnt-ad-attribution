#!/usr/bin/env bash
# Fixture helpers for integration tests.
#
# Provides functions to create tracking URLs, simulate clicks, set consent
# state, and query the database through the test-helpers REST endpoint.
#
# Requires: WP_BASE_URL, WP_NONCE, ADMIN_COOKIE (exported by setup.sh).

# Strip the Secure flag from a Netscape cookie jar file. The plugin hardcodes
# Secure=true on cookies, but Playground runs over HTTP. Curl's cookie engine
# refuses to send Secure cookies over HTTP, breaking round-trip accumulation.
strip_secure_flag() {
    local file="$1"
    [[ -f "$file" ]] && perl -i -pe 's/\tTRUE\t/\tFALSE\t/g' "$file"
}

# Extract a named cookie value from Set-Cookie response headers.
# URL-decodes the value (PHP auto-decodes $_COOKIE values from HTTP headers,
# so injected values must be decoded to match the expected format).
# Usage: extract_cookie_value <set_cookie_headers> <cookie_name>
extract_cookie_value() {
    local headers="$1" name="$2"
    local raw
    raw=$(echo "$headers" | grep -i "${name}=" | sed "s/.*${name}=//; s/;.*//" | head -1)
    python3 -c "import urllib.parse,sys; print(urllib.parse.unquote(sys.argv[1]))" "$raw" 2>/dev/null || echo "$raw"
}

# Create a tracking URL via the test-helpers REST endpoint.
# Usage: create_tracking_url <target_post_id> <source> <medium> <campaign>
# Returns: the hash of the created tracking URL
create_tracking_url() {
    local target_id="$1" source="$2" medium="$3" campaign="$4"

    # Generate a random hash
    local hash
    hash=$(openssl rand -hex 32)

    # Create CPT post via test-helpers REST endpoint
    local response
    response=$(curl -sf "${WP_BASE_URL}/?rest_route=/kntnt-ad-attribution/v1/test-create-url" \
        -X POST \
        -H "Content-Type: application/json" \
        -H "X-WP-Nonce: ${WP_NONCE}" \
        -b "${ADMIN_COOKIE}" \
        -d "{\"hash\":\"$hash\",\"target_post_id\":$target_id,\"source\":\"$source\",\"medium\":\"$medium\",\"campaign\":\"$campaign\"}")

    echo "$hash"
}

# Create a target page for tracking URLs.
# Usage: create_target_page [title] [type]
# Returns: JSON with post_id and permalink
create_target_page() {
    local title="${1:-Test Page}"
    local type="${2:-page}"

    curl -sf "${WP_BASE_URL}/?rest_route=/kntnt-ad-attribution/v1/test-create-post" \
        -X POST \
        -H "Content-Type: application/json" \
        -H "X-WP-Nonce: ${WP_NONCE}" \
        -b "${ADMIN_COOKIE}" \
        -d "{\"title\":\"$title\",\"type\":\"$type\"}"
}

# Simulate a click on a tracking URL.
# Usage: simulate_click <hash> [user_agent] [extra_params]
# Returns: HTTP status code and redirect URL separated by |
simulate_click() {
    local hash="$1"
    local ua="${2-Mozilla/5.0 Chrome/120.0.0.0}"
    local extra="${3:-}"

    # Trailing slash avoids WordPress's 301 canonical redirect.
    local url="${WP_BASE_URL}/ad/${hash}/"
    if [[ -n "$extra" ]]; then
        url="${url}?${extra}"
    fi

    curl -sS -o /dev/null -w "%{http_code}|%{redirect_url}" \
        -A "$ua" \
        --max-redirs 0 \
        "$url" 2>/dev/null || true
}

# Get Set-Cookie headers from a click response.
# Usage: get_click_cookies <hash> [user_agent]
get_click_cookies() {
    local hash="$1"
    local ua="${2:-Mozilla/5.0 Chrome/120.0.0.0}"

    curl -sS -D - -o /dev/null \
        -A "$ua" \
        --max-redirs 0 \
        "${WP_BASE_URL}/ad/${hash}/" 2>/dev/null \
        | grep -i '^set-cookie:' || true
}

# Set consent state for integration tests.
# Usage: set_consent_state <granted|denied|pending|default>
set_consent_state() {
    local state="$1"
    curl -sf "${WP_BASE_URL}/?rest_route=/kntnt-ad-attribution/v1/test-set-option" \
        -X POST \
        -H "Content-Type: application/json" \
        -H "X-WP-Nonce: ${WP_NONCE}" \
        -b "${ADMIN_COOKIE}" \
        -d "{\"option\":\"test_consent_state\",\"value\":\"$state\"}" > /dev/null
}

# Query a single row from the database.
# Usage: query_db "SELECT ..."
# Returns: JSON object
query_db() {
    local sql="$1"
    curl -sf "${WP_BASE_URL}/?rest_route=/kntnt-ad-attribution/v1/test-query" \
        -H "X-WP-Nonce: ${WP_NONCE}" \
        -b "${ADMIN_COOKIE}" \
        -G --data-urlencode "sql=$sql"
}

# Query multiple rows from the database.
# Usage: query_db_rows "SELECT ..."
# Returns: JSON array
query_db_rows() {
    local sql="$1"
    curl -sf "${WP_BASE_URL}/?rest_route=/kntnt-ad-attribution/v1/test-query-rows" \
        -H "X-WP-Nonce: ${WP_NONCE}" \
        -b "${ADMIN_COOKIE}" \
        -G --data-urlencode "sql=$sql"
}

# Get click count for a specific hash.
# Usage: get_click_count <hash>
# Returns: integer count
get_click_count() {
    local hash="$1"
    query_db "SELECT COUNT(*) as cnt FROM wp_kntnt_ad_attr_clicks WHERE hash='$hash'" \
        | jq -r '.cnt'
}

# Trigger a WordPress action via REST.
# Usage: do_action <action_name>
do_action() {
    local action="$1"
    curl -sf "${WP_BASE_URL}/?rest_route=/kntnt-ad-attribution/v1/test-do-action" \
        -X POST \
        -H "Content-Type: application/json" \
        -H "X-WP-Nonce: ${WP_NONCE}" \
        -b "${ADMIN_COOKIE}" \
        -d "{\"action_name\":\"$action\"}" > /dev/null
}

# Flush rewrite rules in the Playground instance.
# Usage: flush_rewrites
flush_rewrites() {
    curl -sf "${WP_BASE_URL}/?rest_route=/kntnt-ad-attribution/v1/test-flush-rewrites" \
        -X POST \
        -H "X-WP-Nonce: ${WP_NONCE}" \
        -b "${ADMIN_COOKIE}" > /dev/null
}

# Execute arbitrary SQL (INSERT/UPDATE/DELETE).
# Usage: execute_sql "INSERT INTO ..."
execute_sql() {
    local sql="$1"
    curl -sf "${WP_BASE_URL}/?rest_route=/kntnt-ad-attribution/v1/test-execute-sql" \
        -X POST \
        -H "Content-Type: application/json" \
        -H "X-WP-Nonce: ${WP_NONCE}" \
        -b "${ADMIN_COOKIE}" \
        -d "$(jq -n --arg sql "$sql" '{sql: $sql}')"
}

# Update a post's status.
# Usage: update_post_status <post_id> <status>
update_post_status() {
    local post_id="$1" status="$2"
    curl -sf "${WP_BASE_URL}/?rest_route=/kntnt-ad-attribution/v1/test-update-post-status" \
        -X POST \
        -H "Content-Type: application/json" \
        -H "X-WP-Nonce: ${WP_NONCE}" \
        -b "${ADMIN_COOKIE}" \
        -d "{\"post_id\":$post_id,\"status\":\"$status\"}" > /dev/null
}

# Permanently delete a post.
# Usage: delete_post <post_id>
delete_post() {
    local post_id="$1"
    curl -sf "${WP_BASE_URL}/?rest_route=/kntnt-ad-attribution/v1/test-delete-post" \
        -X POST \
        -H "Content-Type: application/json" \
        -H "X-WP-Nonce: ${WP_NONCE}" \
        -b "${ADMIN_COOKIE}" \
        -d "{\"post_id\":$post_id}" > /dev/null
}

# Get an option value.
# Usage: get_option <option_name>
# Returns: JSON with option and value fields
get_option() {
    local option="$1"
    curl -sf "${WP_BASE_URL}/?rest_route=/kntnt-ad-attribution/v1/test-get-option" \
        -H "X-WP-Nonce: ${WP_NONCE}" \
        -b "${ADMIN_COOKIE}" \
        -G --data-urlencode "option=$option"
}

# Trigger conversion with specific cookie values.
# Usage: trigger_conversion <ad_clicks_cookie_value> [last_conv_cookie_value]
# The last_conv parameter should use hash:timestamp format (same as _ad_clicks)
# when dedup is enabled (kntnt_ad_attr_dedup_seconds > 0). Ignored when dedup
# is disabled (default).
# Returns: JSON with success and set_cookies
trigger_conversion() {
    local ad_clicks="${1:-}"
    local last_conv="${2:-}"

    local payload
    payload=$(jq -n \
        --arg ad_clicks "$ad_clicks" \
        --arg last_conv "$last_conv" \
        '{ad_clicks: $ad_clicks, last_conv: (if $last_conv == "" then null else $last_conv end)}')

    curl -sf "${WP_BASE_URL}/?rest_route=/kntnt-ad-attribution/v1/test-trigger-conversion" \
        -X POST \
        -H "Content-Type: application/json" \
        -H "X-WP-Nonce: ${WP_NONCE}" \
        -b "${ADMIN_COOKIE}" \
        -d "$payload"
}

# Delete a transient.
# Usage: delete_transient <name>
delete_transient() {
    local name="$1"
    curl -sf "${WP_BASE_URL}/?rest_route=/kntnt-ad-attribution/v1/test-delete-transient" \
        -X POST \
        -H "Content-Type: application/json" \
        -H "X-WP-Nonce: ${WP_NONCE}" \
        -b "${ADMIN_COOKIE}" \
        -d "{\"name\":\"$name\"}" > /dev/null
}

# Clear plugin cookies from the WASM PHP process.
# Playground reuses the same PHP process, so $_COOKIE modifications from
# previous requests persist. Call this at the start of tests that depend
# on clean cookie state.
# Usage: clear_cookies
clear_cookies() {
    curl -sf "${WP_BASE_URL}/?rest_route=/kntnt-ad-attribution/v1/test-clear-cookies" \
        -X POST \
        -H "X-WP-Nonce: ${WP_NONCE}" \
        -b "${ADMIN_COOKIE}" > /dev/null
}
