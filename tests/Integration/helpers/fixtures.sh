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

# Set the click and conversion dedup window (kntnt_ad_attr_dedup_seconds).
# Usage: set_dedup_seconds <seconds>   (0 restores the plugin default)
set_dedup_seconds() {
    local seconds="$1"
    curl -sf "${WP_BASE_URL}/?rest_route=/kntnt-ad-attribution/v1/test-set-option" \
        -X POST \
        -H "Content-Type: application/json" \
        -H "X-WP-Nonce: ${WP_NONCE}" \
        -b "${ADMIN_COOKIE}" \
        -d "{\"option\":\"test_dedup_seconds\",\"value\":\"$seconds\"}" > /dev/null
}

# Set a WordPress option to a string value (sanitize_text_field applies).
# Usage: set_option <option_name> <value>
set_option() {
    local option="$1" value="$2"
    curl -sf "${WP_BASE_URL}/?rest_route=/kntnt-ad-attribution/v1/test-set-option" \
        -X POST \
        -H "Content-Type: application/json" \
        -H "X-WP-Nonce: ${WP_NONCE}" \
        -b "${ADMIN_COOKIE}" \
        -d "$(jq -n --arg option "$option" --arg value "$value" '{option: $option, value: $value}')" > /dev/null
}

# Register probe click-ID capturers (see test-helpers.php).
# Usage: set_probe_capturers "google_ads=gclid[,meta=fbclid]"  ("" removes them)
set_probe_capturers() {
    set_option "test_probe_capturers" "$1"
}

# Switch the probe conversion reporter (see test-helpers.php).
# Usage: set_probe_reporter on|supports_test|off
set_probe_reporter() {
    local mode="$1"
    [[ "$mode" == "off" ]] && mode=""
    set_option "test_probe_reporter" "$mode"
}

# Print everything the probe reporter's enqueue received, as a JSON array
# with one {attributions, click_ids, campaigns, context} object per call.
# Usage: get_probe_calls
get_probe_calls() {
    get_option "test_probe_calls" | jq -c '.value | if type == "array" then . else [] end'
}

# Forget the probe reporter's recorded calls.
# Usage: reset_probe_calls
reset_probe_calls() {
    set_option "test_probe_calls" ""
}

# Switch the probe listener on kntnt_ad_attr_conversion_recorded (see
# test-helpers.php).
# Usage: set_probe_recorded on|off
set_probe_recorded() {
    local mode="$1"
    [[ "$mode" == "off" ]] && mode=""
    set_option "test_probe_recorded" "$mode"
}

# Print every kntnt_ad_attr_conversion_recorded call the probe listener saw,
# as a JSON array with one {attributions, context} object per call.
# Usage: get_recorded_calls
get_recorded_calls() {
    get_option "test_probe_recorded_calls" | jq -c '.value | if type == "array" then . else [] end'
}

# Switch off the probe capturers, reporter and listener, and forget their
# recorded calls. Call at the end of every suite that uses them.
# Usage: reset_probes
reset_probes() {
    set_probe_capturers ""
    set_probe_reporter "off"
    reset_probe_calls
    set_probe_recorded "off"
    set_option "test_probe_recorded_calls" ""
}

# Switch the plugin's default internal-traffic detection on or off. The test
# environment keeps it off (see test-helpers.php) because suites trigger
# conversions as the logged-in admin.
# Usage: set_internal_default on|off
set_internal_default() {
    local mode="$1"
    [[ "$mode" == "off" ]] && mode=""
    set_option "test_internal_default" "$mode"
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

# Trigger a conversion in a REST request that carries the admin's logged-in
# cookie but no nonce, like a form plugin submitting through the REST API.
# WordPress resets the current user to 0 in such a request.
# Usage: trigger_conversion_without_nonce <ad_clicks_cookie_value>
# Returns: JSON with success and set_cookies
trigger_conversion_without_nonce() {
    local ad_clicks="${1:-}"

    curl -sf "${WP_BASE_URL}/?rest_route=/kntnt-ad-attribution/v1/test-trigger-conversion-public" \
        -X POST \
        -H "Content-Type: application/json" \
        -b "${ADMIN_COOKIE}" \
        -d "$(jq -n --arg ad_clicks "$ad_clicks" '{ad_clicks: $ad_clicks}')"
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
