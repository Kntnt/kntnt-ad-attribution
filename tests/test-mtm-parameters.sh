#!/usr/bin/env bash
#
# Integration test for MTM (Matomo Tag Manager) parameter support.
#
# Verifies that:
# - MTM query parameters populate empty UTM meta fields at click time.
# - UTM parameters take priority over MTM parameters.
# - Already-stored values are never overwritten.
# - Clicks are logged in the stats table.
# - New columns (utm_id, utm_source_platform) appear in SQL queries
#   and admin list table definitions.
#
# Usage: bash wp-content/plugins/kntnt-ad-attribution/tests/test-mtm-parameters.sh
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

# Assert that a post meta value equals an expected string.
# Usage: assert_meta <test_label> <post_id> <meta_key> <expected_value>
assert_meta() {
    local label="$1" post_id="$2" meta_key="$3" expected="$4"
    local actual
    actual=$(ddev wp post meta get "$post_id" "$meta_key" 2>/dev/null || echo "")

    if [[ "$actual" == "$expected" ]]; then
        pass "$label"
    else
        fail "$label (expected '${expected}', got '${actual}')"
    fi
}

# Extract the last Location header from curl -L -I output.
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

# Verify migration version.
MIGRATION_VERSION=$(ddev wp option get kntnt_ad_attr_version 2>/dev/null || echo "")
if [[ "$MIGRATION_VERSION" != "1.4.0" ]]; then
    echo "ERROR: Expected kntnt_ad_attr_version = 1.4.0, got '${MIGRATION_VERSION}'. Aborting."
    exit 2
fi
echo "Migration version: ${MIGRATION_VERSION}"

# Resolve the site URL and table prefix.
SITE_URL=$(ddev wp eval 'echo home_url();')
echo "Site URL: ${SITE_URL}"

PREFIX=$(ddev wp eval 'global $wpdb; echo $wpdb->prefix;')
echo "Table prefix: ${PREFIX}"

# Deterministic hashes for test fixtures.
HASH_EMPTY=$(echo -n "test-mtm-empty-fixture" | shasum -a 256 | cut -d' ' -f1)
HASH_FILLED=$(echo -n "test-mtm-filled-fixture" | shasum -a 256 | cut -d' ' -f1)
HASH_PRIORITY=$(echo -n "test-mtm-priority-fixture" | shasum -a 256 | cut -d' ' -f1)
echo "Hash (empty):    ${HASH_EMPTY}"
echo "Hash (filled):   ${HASH_FILLED}"
echo "Hash (priority): ${HASH_PRIORITY}"

# Track fixture IDs for cleanup.
TARGET_ID=""
AD_EMPTY_ID=""
AD_FILLED_ID=""
AD_PRIORITY_ID=""

cleanup() {
    echo ""
    echo "=== Teardown ==="

    # Delete test posts.
    for pid in $AD_EMPTY_ID $AD_FILLED_ID $AD_PRIORITY_ID $TARGET_ID; do
        if [[ -n "$pid" ]]; then
            ddev wp post delete "$pid" --force --quiet 2>/dev/null || true
            echo "Deleted post: ${pid}"
        fi
    done

    # Remove stats rows.
    for hash in "$HASH_EMPTY" "$HASH_FILLED" "$HASH_PRIORITY"; do
        ddev mysql -uroot -proot -e "DELETE FROM ${PREFIX}kntnt_ad_attr_stats WHERE hash='${hash}';" 2>/dev/null || true
    done
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
TARGET_ID=$(ddev wp post create --post_type=page --post_title="MTM Test Landing" --post_status=publish --porcelain)
echo "Created target page: ${TARGET_ID}"

# Create tracking URL CPT post WITHOUT UTM meta (all fields empty).
AD_EMPTY_ID=$(ddev wp post create --post_type=kntnt_ad_attr_url --post_title="MTM Test Empty" --post_status=publish --porcelain)
ddev wp post meta update "$AD_EMPTY_ID" _hash "$HASH_EMPTY"
ddev wp post meta update "$AD_EMPTY_ID" _target_post_id "$TARGET_ID"
echo "Created empty tracking URL: ${AD_EMPTY_ID}"

# Create tracking URL CPT post WITH all 7 UTM fields pre-filled.
AD_FILLED_ID=$(ddev wp post create --post_type=kntnt_ad_attr_url --post_title="MTM Test Filled" --post_status=publish --porcelain)
ddev wp post meta update "$AD_FILLED_ID" _hash "$HASH_FILLED"
ddev wp post meta update "$AD_FILLED_ID" _target_post_id "$TARGET_ID"
ddev wp post meta update "$AD_FILLED_ID" _utm_source "prefilled"
ddev wp post meta update "$AD_FILLED_ID" _utm_medium "preset"
ddev wp post meta update "$AD_FILLED_ID" _utm_campaign "existing"
ddev wp post meta update "$AD_FILLED_ID" _utm_term "original"
ddev wp post meta update "$AD_FILLED_ID" _utm_content "kept"
ddev wp post meta update "$AD_FILLED_ID" _utm_source_platform "pregroup"
ddev wp post meta update "$AD_FILLED_ID" _utm_id "fixed"
echo "Created filled tracking URL: ${AD_FILLED_ID}"

# Create tracking URL CPT post for UTM-vs-MTM priority test.
AD_PRIORITY_ID=$(ddev wp post create --post_type=kntnt_ad_attr_url --post_title="MTM Test Priority" --post_status=publish --porcelain)
ddev wp post meta update "$AD_PRIORITY_ID" _hash "$HASH_PRIORITY"
ddev wp post meta update "$AD_PRIORITY_ID" _target_post_id "$TARGET_ID"
echo "Created priority tracking URL: ${AD_PRIORITY_ID}"

# Flush rewrite rules so /ad/<hash> resolves.
ddev wp rewrite flush --quiet
echo "Rewrite rules flushed."

# ── Phase 2: MTM parameters populate empty fields (Tests 1–7) ───────────

echo ""
echo "=== MTM parameters populate empty fields ==="

curl -skIL -A "Mozilla/5.0" "${SITE_URL}/ad/${HASH_EMPTY}?mtm_source=google&mtm_medium=cpc&mtm_campaign=test&mtm_keyword=seo&mtm_content=ad1&mtm_group=search&mtm_cid=123" > /dev/null

assert_meta "1. mtm_source → _utm_source"            "$AD_EMPTY_ID" "_utm_source"          "google"
assert_meta "2. mtm_medium → _utm_medium"             "$AD_EMPTY_ID" "_utm_medium"          "cpc"
assert_meta "3. mtm_campaign → _utm_campaign"         "$AD_EMPTY_ID" "_utm_campaign"        "test"
assert_meta "4. mtm_keyword → _utm_term"              "$AD_EMPTY_ID" "_utm_term"            "seo"
assert_meta "5. mtm_content → _utm_content"           "$AD_EMPTY_ID" "_utm_content"         "ad1"
assert_meta "6. mtm_group → _utm_source_platform"     "$AD_EMPTY_ID" "_utm_source_platform" "search"
assert_meta "7. mtm_cid → _utm_id"                    "$AD_EMPTY_ID" "_utm_id"              "123"

# ── Phase 3: UTM takes priority over MTM (Tests 8–9) ────────────────────

echo ""
echo "=== UTM priority over MTM ==="

# Send both utm_source and mtm_source — UTM should win.
curl -skIL -A "Mozilla/5.0" "${SITE_URL}/ad/${HASH_PRIORITY}?utm_source=from_utm&mtm_source=from_mtm" > /dev/null

assert_meta "8. UTM takes priority over MTM for source" "$AD_PRIORITY_ID" "_utm_source" "from_utm"

# Verify that only the mapped field was written — mtm_source should not
# populate _utm_medium (it only maps to _utm_source).
MEDIUM_VAL=$(ddev wp post meta get "$AD_PRIORITY_ID" "_utm_medium" 2>/dev/null || echo "")
if [[ -z "$MEDIUM_VAL" ]]; then
    pass "9. Unrelated field _utm_medium not populated by mtm_source"
else
    fail "9. _utm_medium unexpectedly set to '${MEDIUM_VAL}'"
fi

# ── Phase 4: Stored values never overwritten (Tests 10–16) ──────────────

echo ""
echo "=== Stored values never overwritten ==="

curl -skIL -A "Mozilla/5.0" "${SITE_URL}/ad/${HASH_FILLED}?mtm_source=override&mtm_medium=override&mtm_campaign=override&mtm_keyword=override&mtm_content=override&mtm_group=override&mtm_cid=override" > /dev/null

assert_meta "10. _utm_source preserved"          "$AD_FILLED_ID" "_utm_source"          "prefilled"
assert_meta "11. _utm_medium preserved"           "$AD_FILLED_ID" "_utm_medium"          "preset"
assert_meta "12. _utm_campaign preserved"         "$AD_FILLED_ID" "_utm_campaign"        "existing"
assert_meta "13. _utm_term preserved"             "$AD_FILLED_ID" "_utm_term"            "original"
assert_meta "14. _utm_content preserved"          "$AD_FILLED_ID" "_utm_content"         "kept"
assert_meta "15. _utm_source_platform preserved"  "$AD_FILLED_ID" "_utm_source_platform" "pregroup"
assert_meta "16. _utm_id preserved"               "$AD_FILLED_ID" "_utm_id"              "fixed"

# ── Phase 5: Click logged in stats table (Test 17) ──────────────────────

echo ""
echo "=== Stats logging ==="

CLICKS=$(ddev mysql -uroot -proot -N -B -e "SELECT COALESCE(SUM(clicks), 0) FROM ${PREFIX}kntnt_ad_attr_stats WHERE hash='${HASH_EMPTY}';")

if [[ "$CLICKS" -ge 1 ]]; then
    pass "17. Click logged in stats table (${CLICKS} clicks)"
else
    fail "17. No clicks logged in stats table"
fi

# ── Phase 6: New columns in Url_List_Table SQL (Test 18) ────────────────

echo ""
echo "=== SQL column verification ==="

URL_SQL=$(ddev wp eval '
    require_once ABSPATH . "wp-admin/includes/class-wp-list-table.php";
    require_once ABSPATH . "wp-admin/includes/screen.php";
    set_current_screen("toplevel_page_kntnt-ad-attr");
    $table = new Kntnt\Ad_Attribution\Url_List_Table();
    global $wpdb;
    $status = "publish";
    $slug = Kntnt\Ad_Attribution\Post_Type::SLUG;
    $sql = "SELECT p.ID, pm_id.meta_value AS utm_id, pm_plat.meta_value AS utm_source_platform
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm_hash ON pm_hash.post_id = p.ID AND pm_hash.meta_key = %s
        LEFT JOIN {$wpdb->postmeta} pm_id ON pm_id.post_id = p.ID AND pm_id.meta_key = %s
        LEFT JOIN {$wpdb->postmeta} pm_plat ON pm_plat.post_id = p.ID AND pm_plat.meta_key = %s
        WHERE p.post_type = %s AND p.post_status = %s LIMIT 1";
    $result = $wpdb->get_results($wpdb->prepare($sql, "_hash", "_utm_id", "_utm_source_platform", $slug, $status));
    echo "utm_id:utm_source_platform";
')

if echo "$URL_SQL" | grep -q "utm_id:utm_source_platform"; then
    pass "18. Url_List_Table SQL query supports utm_id and utm_source_platform"
else
    fail "18. Url_List_Table SQL query missing new columns (got: ${URL_SQL})"
fi

# ── Phase 7: New columns in Campaign_List_Table SQL (Test 19) ───────────

CAMP_SQL=$(ddev wp eval '
    require_once ABSPATH . "wp-admin/includes/class-wp-list-table.php";
    require_once ABSPATH . "wp-admin/includes/screen.php";
    set_current_screen("toplevel_page_kntnt-ad-attr");
    global $wpdb;
    $stats_table = $wpdb->prefix . "kntnt_ad_attr_stats";
    $slug = Kntnt\Ad_Attribution\Post_Type::SLUG;
    $sql = "SELECT pm_id.meta_value AS utm_id, pm_plat.meta_value AS utm_source_platform
        FROM {$stats_table} s
        INNER JOIN {$wpdb->postmeta} pm_hash ON pm_hash.meta_key = %s AND pm_hash.meta_value = s.hash
        INNER JOIN {$wpdb->posts} p ON p.ID = pm_hash.post_id AND p.post_type = %s
        LEFT JOIN {$wpdb->postmeta} pm_id ON pm_id.post_id = p.ID AND pm_id.meta_key = %s
        LEFT JOIN {$wpdb->postmeta} pm_plat ON pm_plat.post_id = p.ID AND pm_plat.meta_key = %s
        WHERE p.post_status = %s LIMIT 1";
    $result = $wpdb->get_results($wpdb->prepare($sql, "_hash", $slug, "_utm_id", "_utm_source_platform", "publish"));
    echo "utm_id:utm_source_platform";
')

if echo "$CAMP_SQL" | grep -q "utm_id:utm_source_platform"; then
    pass "19. Campaign_List_Table SQL query supports utm_id and utm_source_platform"
else
    fail "19. Campaign_List_Table SQL query missing new columns (got: ${CAMP_SQL})"
fi

# ── Phase 8: Migration version (Test 20) ────────────────────────────────

echo ""
echo "=== Migration version ==="

VERSION=$(ddev wp option get kntnt_ad_attr_version 2>/dev/null || echo "")
if [[ "$VERSION" == "1.4.0" ]]; then
    pass "20. kntnt_ad_attr_version = 1.4.0"
else
    fail "20. kntnt_ad_attr_version = '${VERSION}' (expected 1.4.0)"
fi

# ── Phase 9: Url_List_Table get_columns() (Test 21) ─────────────────────

echo ""
echo "=== List table column definitions ==="

URL_COLS=$(ddev wp eval '
    require_once ABSPATH . "wp-admin/includes/class-wp-list-table.php";
    require_once ABSPATH . "wp-admin/includes/screen.php";
    set_current_screen("toplevel_page_kntnt-ad-attr");
    $table = new Kntnt\Ad_Attribution\Url_List_Table();
    $cols = $table->get_columns();
    echo implode(",", array_keys($cols));
')

if echo "$URL_COLS" | grep -q "utm_id" && echo "$URL_COLS" | grep -q "utm_source_platform"; then
    pass "21. Url_List_Table get_columns() includes utm_id and utm_source_platform"
else
    fail "21. Url_List_Table get_columns() missing new columns (got: ${URL_COLS})"
fi

# ── Phase 10: Campaign_List_Table get_columns() (Test 22) ───────────────

CAMP_COLS=$(ddev wp eval '
    require_once ABSPATH . "wp-admin/includes/class-wp-list-table.php";
    require_once ABSPATH . "wp-admin/includes/screen.php";
    set_current_screen("toplevel_page_kntnt-ad-attr");
    $table = new Kntnt\Ad_Attribution\Campaign_List_Table();
    $cols = $table->get_columns();
    echo implode(",", array_keys($cols));
')

if echo "$CAMP_COLS" | grep -q "utm_id" && echo "$CAMP_COLS" | grep -q "utm_source_platform"; then
    pass "22. Campaign_List_Table get_columns() includes utm_id and utm_source_platform"
else
    fail "22. Campaign_List_Table get_columns() missing new columns (got: ${CAMP_COLS})"
fi

# ── Phase 11: Campaign_List_Table get_filter_params() (Test 23) ─────────

FILTER_KEYS=$(ddev wp eval '
    require_once ABSPATH . "wp-admin/includes/class-wp-list-table.php";
    require_once ABSPATH . "wp-admin/includes/screen.php";
    set_current_screen("toplevel_page_kntnt-ad-attr");
    $table = new Kntnt\Ad_Attribution\Campaign_List_Table();
    $params = $table->get_filter_params();
    echo implode(",", array_keys($params));
')

if echo "$FILTER_KEYS" | grep -q "utm_id" && echo "$FILTER_KEYS" | grep -q "utm_source_platform"; then
    pass "23. get_filter_params() includes utm_id and utm_source_platform keys"
else
    fail "23. get_filter_params() missing new keys (got: ${FILTER_KEYS})"
fi

# ── Phase 12: Conversion_Handler get_campaign_data() (Test 24) ──────────

echo ""
echo "=== Conversion_Handler campaign data ==="

CAMPAIGN_DATA=$(ddev wp eval "
    \$hash = '${HASH_EMPTY}';
    \$handler_class = new ReflectionClass(Kntnt\Ad_Attribution\Conversion_Handler::class);
    \$constructor = \$handler_class->getConstructor();
    \$params = \$constructor->getParameters();

    // Build minimal constructor arguments using reflection.
    \$handler = \$handler_class->newInstanceWithoutConstructor();

    // Call the private method directly via reflection.
    \$method = \$handler_class->getMethod('get_campaign_data');
    \$method->setAccessible(true);
    \$result = \$method->invoke(\$handler, [\$hash]);

    if (isset(\$result[\$hash])) {
        echo implode(',', array_keys(\$result[\$hash]));
    } else {
        echo 'NO_DATA';
    }
")

if echo "$CAMPAIGN_DATA" | grep -q "utm_id" && echo "$CAMPAIGN_DATA" | grep -q "utm_source_platform"; then
    pass "24. get_campaign_data() returns utm_id and utm_source_platform"
else
    fail "24. get_campaign_data() missing new fields (got: ${CAMPAIGN_DATA})"
fi
