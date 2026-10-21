#!/usr/bin/env bash
# Single entry point for the Kntnt Ad Attribution test suite.
#
# Runs Level 1 (unit tests) and Level 2 (integration tests).
# See docs/testing-strategy.md for details.
#
# Usage:
#   bash run-tests.sh              # Run all tests
#   bash run-tests.sh --unit-only  # Level 1 only (no Playground)
#   bash run-tests.sh --integration-only  # Level 2 only
#   bash run-tests.sh --filter <pattern>  # Filter tests by pattern
#   bash run-tests.sh --verbose    # Show full test output
#
# Environment detection (in priority order):
#   1. Explicit env vars or .env.testing overrides (PHP_BIN, COMPOSER_BIN, etc.)
#   2. DDEV auto-detection (if .ddev/config.yaml found in parent dirs)
#   3. Local PATH fallback

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PLAYGROUND_PORT="${PLAYGROUND_PORT:-9400}"
PLAYGROUND_PID=""
PLAYGROUND_HOME=""
UNIT_PHP_EXIT=0
UNIT_JS_EXIT=0
INTEGRATION_PASS=0
INTEGRATION_FAIL=0
VERBOSE=false
FILTER=""
MODE="all"

# Resolved tool paths (set by load_overrides / detect_ddev / resolve_local)
PHP_BIN="${PHP_BIN:-}"
COMPOSER_BIN="${COMPOSER_BIN:-}"
NODE_BIN="${NODE_BIN:-}"
NPM_BIN="${NPM_BIN:-}"
NPX_BIN=""
LOCAL_NPX=""
ENV_SOURCE=""
DDEV_PLUGIN_DIR=""

# ─── Parse arguments ───

while [[ $# -gt 0 ]]; do
    case "$1" in
        --unit-only)
            MODE="unit"
            shift
            ;;
        --integration-only)
            MODE="integration"
            shift
            ;;
        --verbose)
            VERBOSE=true
            shift
            ;;
        --filter)
            FILTER="$2"
            shift 2
            ;;
        *)
            echo "Unknown option: $1" >&2
            echo "Usage: bash run-tests.sh [--unit-only|--integration-only] [--verbose] [--filter <pattern>]" >&2
            exit 1
            ;;
    esac
done

# ─── Load overrides from env vars and .env.testing ───

load_overrides() {

    # Track which variables were explicitly set via environment
    declare -gA EXPLICIT_VARS=()
    for var in PHP_BIN COMPOSER_BIN NODE_BIN NPM_BIN; do
        if [[ -n "${!var}" ]]; then
            EXPLICIT_VARS[$var]=1
        fi
    done

    # Read .env.testing if it exists (env vars take precedence)
    local env_file="$SCRIPT_DIR/.env.testing"
    if [[ -f "$env_file" ]]; then
        while IFS= read -r line; do

            # Skip blank lines and comments
            [[ -z "$line" || "$line" =~ ^[[:space:]]*# ]] && continue

            # Match KEY=VALUE for known variables
            if [[ "$line" =~ ^[[:space:]]*(PHP_BIN|COMPOSER_BIN|NODE_BIN|NPM_BIN)[[:space:]]*=[[:space:]]*(.*) ]]; then
                local key="${BASH_REMATCH[1]}"
                local val="${BASH_REMATCH[2]}"

                # Trim surrounding quotes
                val="${val#\"}" ; val="${val%\"}"
                val="${val#\'}" ; val="${val%\'}"

                # Only set if not already explicitly provided
                if [[ -z "${EXPLICIT_VARS[$key]+x}" ]]; then
                    declare -g "$key=$val"
                    EXPLICIT_VARS[$key]=1
                fi
            fi
        done < "$env_file"
    fi
}

# ─── DDEV auto-detection ───

detect_ddev() {

    # Walk upward from SCRIPT_DIR looking for .ddev/config.yaml
    local dir="$SCRIPT_DIR"
    local ddev_root=""
    while [[ "$dir" != "/" ]]; do
        if [[ -f "$dir/.ddev/config.yaml" ]]; then
            ddev_root="$dir"
            break
        fi
        dir="$(dirname "$dir")"
    done

    if [[ -z "$ddev_root" ]]; then
        return 1
    fi

    # Verify ddev command is available
    if ! command -v ddev >/dev/null 2>&1; then
        echo "WARNING: DDEV project found at $ddev_root but 'ddev' command not in PATH." >&2
        echo "         Falling back to local tools." >&2
        return 1
    fi

    # Get DDEV status
    local ddev_json
    ddev_json=$(cd "$ddev_root" && ddev describe -j 2>/dev/null) || {
        echo "WARNING: 'ddev describe' failed. Falling back to local tools." >&2
        return 1
    }

    # Check service statuses
    local web_status db_status
    web_status=$(echo "$ddev_json" | jq -r '.raw.services.web.status // "unknown"')
    db_status=$(echo "$ddev_json" | jq -r '.raw.services.db.status // "unknown"')

    if [[ "$web_status" != "running" || "$db_status" != "running" ]]; then
        echo "DDEV project found but not running. Starting DDEV..."
        (cd "$ddev_root" && ddev start) || {
            echo "ERROR: 'ddev start' failed." >&2
            exit 1
        }

        # Re-read status after start
        ddev_json=$(cd "$ddev_root" && ddev describe -j 2>/dev/null) || {
            echo "ERROR: 'ddev describe' failed after start." >&2
            exit 1
        }
    fi

    # Compute the container path for the plugin directory.
    # DDEV maps the project root to /var/www/html; strip the host prefix
    # to get the relative path, then prepend the container root.
    local rel_path="${SCRIPT_DIR#"$ddev_root"/}"
    DDEV_PLUGIN_DIR="/var/www/html/$rel_path"

    # Assign DDEV commands for PHP/Composer.
    # Node/npm stay local — node_modules has host-native binaries (rollup etc.)
    # that won't work inside the Linux container.
    [[ -z "${EXPLICIT_VARS[PHP_BIN]+x}" ]]      && PHP_BIN="ddev php"
    [[ -z "${EXPLICIT_VARS[COMPOSER_BIN]+x}" ]]  && COMPOSER_BIN="ddev composer"
    [[ -z "${EXPLICIT_VARS[NODE_BIN]+x}" ]]      && NODE_BIN=$(command -v node 2>/dev/null || true)
    [[ -z "${EXPLICIT_VARS[NPM_BIN]+x}" ]]       && NPM_BIN=$(command -v npm 2>/dev/null || true)

    # Derive NPX from NPM
    if [[ -n "$NPM_BIN" ]]; then
        NPX_BIN="${NPM_BIN%npm}npx"
    fi

    # Extract version info for summary
    DETECTED_PHP_VERSION=$(echo "$ddev_json" | jq -r '.raw.php_version // "unknown"')
    DETECTED_NODE_VERSION=$(echo "$ddev_json" | jq -r '.raw.nodejs_version // "unknown"')
    DETECTED_WP_URL=$(echo "$ddev_json" | jq -r '.raw.primary_url // ""')
    DETECTED_PROJECT_NAME=$(echo "$ddev_json" | jq -r '.raw.name // "unknown"')

    ENV_SOURCE="ddev"
    return 0
}

# ─── Local PATH fallback ───

resolve_local() {

    # Resolve each tool from PATH if not explicitly set
    [[ -z "$PHP_BIN" ]]      && PHP_BIN=$(command -v php 2>/dev/null || true)
    [[ -z "$COMPOSER_BIN" ]] && COMPOSER_BIN=$(command -v composer 2>/dev/null || true)
    [[ -z "$NODE_BIN" ]]     && NODE_BIN=$(command -v node 2>/dev/null || true)
    [[ -z "$NPM_BIN" ]]      && NPM_BIN=$(command -v npm 2>/dev/null || true)

    # Derive NPX from NPM
    if [[ -n "$NPM_BIN" ]]; then
        NPX_BIN="${NPM_BIN%npm}npx"
    fi

    # Verify PHP version >= 8.3
    if [[ -n "$PHP_BIN" ]]; then
        local php_version
        php_version=$($PHP_BIN -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null) || php_version="0.0"
        if [[ "$(printf '%s\n' "8.3" "$php_version" | sort -V | head -1)" != "8.3" ]]; then
            echo "ERROR: PHP 8.3+ required (found $php_version)." >&2
            echo "" >&2
            echo "Options:" >&2
            echo "  - Set up DDEV for the project (zero-configuration)" >&2
            echo "  - Set PHP_BIN to a PHP 8.3+ binary in .env.testing" >&2
            exit 1
        fi
    fi

    ENV_SOURCE="local"
}

# ─── Verify that all required tools are available ───

verify_environment() {
    local missing=()

    # Check required tools
    [[ -z "$PHP_BIN" ]]      && missing+=("PHP (set PHP_BIN)")
    [[ -z "$COMPOSER_BIN" ]] && missing+=("Composer (set COMPOSER_BIN)")
    [[ -z "$NODE_BIN" ]]     && missing+=("Node.js (set NODE_BIN)")
    [[ -z "$NPM_BIN" ]]      && missing+=("npm (set NPM_BIN)")

    # Integration tests need local npx (for Playground) plus curl and jq
    if [[ "$MODE" != "unit" ]]; then
        LOCAL_NPX=$(command -v npx 2>/dev/null || true)
        if [[ -z "$LOCAL_NPX" ]]; then
            missing+=("npx in host PATH (needed for WordPress Playground)")
        fi

        if ! command -v curl >/dev/null 2>&1; then
            missing+=("curl")
        fi

        if ! command -v jq >/dev/null 2>&1; then
            missing+=("jq")
        fi
    fi

    if [[ ${#missing[@]} -gt 0 ]]; then
        echo "ERROR: Missing required tool(s):" >&2
        for tool in "${missing[@]}"; do
            echo "  - $tool" >&2
        done
        echo "" >&2
        echo "Set the corresponding *_BIN variable via environment or .env.testing," >&2
        echo "or install the tool in PATH. See .env.testing.example for details." >&2
        echo "" >&2
        echo "Tip: DDEV provides all tools with zero configuration." >&2
        exit 1
    fi

    # Collect version info for the summary
    local php_version node_version
    if [[ "$ENV_SOURCE" == "ddev" ]]; then
        php_version="${DETECTED_PHP_VERSION}"
        node_version="${DETECTED_NODE_VERSION}"
    else
        php_version=$($PHP_BIN -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null || echo "?")
        node_version=$($NODE_BIN --version 2>/dev/null | sed 's/^v//' || echo "?")
    fi

    # Print environment summary
    echo ""
    echo "═══ Test Environment ═══"
    if [[ "$ENV_SOURCE" == "ddev" ]]; then
        echo "  Source:      DDEV ($DETECTED_PROJECT_NAME)"
    else
        echo "  Source:      Local"
    fi
    echo "  PHP:         $PHP_BIN ($php_version)"
    echo "  Composer:    $COMPOSER_BIN"
    echo "  Node:        $NODE_BIN ($node_version)"
    echo "  npm:         $NPM_BIN"
    echo "  npx:         $NPX_BIN"
    if [[ "$ENV_SOURCE" == "ddev" && -n "${DETECTED_WP_URL:-}" ]]; then
        echo "  WP URL:      $DETECTED_WP_URL"
    fi
    if [[ "$MODE" != "unit" ]]; then
        echo "  Playground:  $LOCAL_NPX (host)"
    fi
    echo "═════════════════════════"
    echo ""
}

# ─── Install dependencies ───

install_deps() {
    if [[ ! -d "$SCRIPT_DIR/vendor" ]]; then
        echo "Installing PHP dependencies..."
        if [[ -n "$DDEV_PLUGIN_DIR" ]]; then
            $COMPOSER_BIN install --dev --quiet --working-dir="$DDEV_PLUGIN_DIR"
        else
            $COMPOSER_BIN install --dev --quiet
        fi
    fi

    if [[ ! -d "$SCRIPT_DIR/node_modules" ]]; then
        echo "Installing Node.js dependencies..."
        $NPM_BIN install --silent
    fi
}

# ─── Level 1: PHP Unit Tests ───

run_unit_php() {
    echo ""
    echo "═══ Level 1: PHP Unit Tests (Pest) ═══"
    echo ""

    local pest_args=(--colors=always)

    if [[ -n "$FILTER" ]]; then
        pest_args+=(--filter "$FILTER")
    fi

    # In DDEV mode, ddev php runs from /var/www/html (the DDEV project root),
    # not the plugin dir. Use absolute paths for the Pest binary and config.
    local pest_path="vendor/bin/pest"
    if [[ -n "$DDEV_PLUGIN_DIR" ]]; then
        pest_path="$DDEV_PLUGIN_DIR/vendor/bin/pest"
        pest_args+=(--configuration "$DDEV_PLUGIN_DIR/phpunit.xml")
    fi

    if $PHP_BIN "$pest_path" "${pest_args[@]}"; then
        UNIT_PHP_EXIT=0
    else
        UNIT_PHP_EXIT=$?
    fi
}

# ─── Level 1: JS Unit Tests ───

run_unit_js() {
    echo ""
    echo "═══ Level 1: JS Unit Tests (Vitest) ═══"
    echo ""

    local vitest_args=(run)

    if [[ -n "$FILTER" ]]; then
        vitest_args+=(-t "$FILTER")
    fi

    if $NPX_BIN vitest "${vitest_args[@]}"; then
        UNIT_JS_EXIT=0
    else
        UNIT_JS_EXIT=$?
    fi
}

# ─── Level 2: Integration Tests ───

start_playground() {
    # A server already answering on the port would pass the readiness probe
    # below, and the suite would silently run against the wrong code.
    if curl -s -o /dev/null "http://localhost:$PLAYGROUND_PORT/" 2>/dev/null; then
        echo "ERROR: Port $PLAYGROUND_PORT is already in use. Stop that server or set PLAYGROUND_PORT." >&2
        exit 1
    fi

    echo "Starting WordPress Playground on port $PLAYGROUND_PORT..."

    # Playground caches the WordPress build it boots from, database
    # included, in $HOME/.wordpress-playground and rewrites it on every
    # boot. Every Playground run on the machine shares that cache, and
    # booting from a build another run wrote can leave WordPress in
    # maintenance mode (HTTP 503). This run gets a private cache, shared
    # only by its own boots.
    if [[ -z "$PLAYGROUND_HOME" ]]; then
        PLAYGROUND_HOME="$(mktemp -d)"
    fi

    # The server is not the process started here: npx runs npm exec, which
    # runs the CLI through "sh -c". Where /bin/sh is dash (Ubuntu, Debian)
    # the shell does not exec the CLI, so killing the npx PID orphans the
    # server and it keeps the port. Job control (set -m) starts the job in
    # a process group of its own, whose ID is the npx PID, and
    # stop_playground signals that whole group. Job control also stops
    # redirecting a background job's stdin, hence the explicit </dev/null.
    # Playground always runs on the host (needs host ports and mount paths).
    set -m
    HOME="$PLAYGROUND_HOME" $LOCAL_NPX @wp-playground/cli server \
        --port="$PLAYGROUND_PORT" \
        --mount="$SCRIPT_DIR:/wordpress/wp-content/plugins/kntnt-ad-attribution" \
        --mount="$SCRIPT_DIR/tests/Integration/fake-consent-plugin:/wordpress/wp-content/mu-plugins/fake-consent-plugin" \
        --mount="$SCRIPT_DIR/tests/Integration/test-helpers-plugin:/wordpress/wp-content/mu-plugins/test-helpers-plugin" \
        --blueprint="$SCRIPT_DIR/tests/Integration/blueprint.json" \
        --login </dev/null &
    PLAYGROUND_PID=$!
    set +m

    # Wait until the blueprint has finished (max 60 seconds). The server
    # answers before the blueprint has activated the plugin, so probe for
    # one of the plugin's own REST routes rather than for any response.
    local max_wait=60
    local waited=0
    local routes=""
    while true; do
        routes=$(curl -s "http://localhost:$PLAYGROUND_PORT/?rest_route=/kntnt-ad-attribution/v1" 2>/dev/null || true)
        [[ "$routes" == *set-cookie* ]] && break
        sleep 1
        waited=$((waited + 1))
        if [[ $waited -ge $max_wait ]]; then
            echo "ERROR: Playground did not start within ${max_wait}s" >&2
            stop_playground || true
            exit 1
        fi
    done
    echo "Playground ready (waited ${waited}s)"
}

stop_playground() {
    if [[ -n "$PLAYGROUND_PID" ]]; then

        # Signal the job's whole process group, not just npx (see
        # start_playground), and kill what is left of it after 10 seconds.
        kill -TERM -- "-$PLAYGROUND_PID" 2>/dev/null || true
        local waited=0
        while kill -0 -- "-$PLAYGROUND_PID" 2>/dev/null; do
            if [[ $waited -ge 10 ]]; then
                echo "WARNING: Playground ignored SIGTERM; sending SIGKILL." >&2
                kill -KILL -- "-$PLAYGROUND_PID" 2>/dev/null || true
                break
            fi
            sleep 1
            waited=$((waited + 1))
        done
        wait "$PLAYGROUND_PID" 2>/dev/null || true
        PLAYGROUND_PID=""

        # Wait for the port to be released, so the next start cannot take
        # a dying instance for a ready one. A port that never frees belongs
        # to a server this run cannot stop, so give up loudly.
        waited=0
        while curl -s -o /dev/null --max-time 2 "http://localhost:$PLAYGROUND_PORT/" 2>/dev/null; do
            if [[ $waited -ge 30 ]]; then
                echo "ERROR: Port $PLAYGROUND_PORT still answers 30s after stopping Playground." >&2
                return 1
            fi
            sleep 1
            waited=$((waited + 1))
        done
        echo "Playground stopped."
    fi
}

cleanup() {
    stop_playground || true
    if [[ -n "$PLAYGROUND_HOME" ]]; then
        rm -rf "$PLAYGROUND_HOME"
        PLAYGROUND_HOME=""
    fi
}

bootstrap_integration() {
    export WP_BASE_URL="http://localhost:$PLAYGROUND_PORT"
    source "$SCRIPT_DIR/tests/Integration/helpers/setup.sh"
}

run_integration() {
    echo ""
    echo "═══ Level 2: Integration Tests ═══"

    local test_files=("$SCRIPT_DIR"/tests/Integration/test-*.sh)

    # Check that integration tests exist
    if [[ ${#test_files[@]} -eq 0 ]] || [[ ! -f "${test_files[0]}" ]]; then
        echo "No integration test files found (tests/Integration/test-*.sh)."
        echo "Skipping integration tests."
        return 0
    fi

    # Suites share one Playground, restarted every suites_per_playground
    # suites. Playground swaps its PHP runtime after 400 requests, and the
    # swapped runtime loses the SQLite driver ("Error establishing a database
    # connection"), so one instance cannot serve every suite.
    local suites_per_playground=8
    local suites_on_playground=0

    for test_file in "${test_files[@]}"; do
        local test_name
        test_name=$(basename "$test_file")

        # Apply filter if set
        if [[ -n "$FILTER" ]] && [[ "$test_name" != *"$FILTER"* ]]; then
            continue
        fi

        if [[ $suites_on_playground -ge $suites_per_playground ]]; then
            teardown_integration
            stop_playground || exit 1
            suites_on_playground=0
        fi
        if [[ $suites_on_playground -eq 0 ]]; then
            start_playground
            bootstrap_integration
        fi
        suites_on_playground=$((suites_on_playground + 1))

        echo ""
        echo "--- Running $test_name ---"

        if bash "$test_file"; then
            INTEGRATION_PASS=$((INTEGRATION_PASS + 1))
        else
            INTEGRATION_FAIL=$((INTEGRATION_FAIL + 1))
            echo "FAILED: $test_name"
        fi
    done

    if [[ $suites_on_playground -gt 0 ]]; then
        teardown_integration
        stop_playground || exit 1
    fi
}

teardown_integration() {
    source "$SCRIPT_DIR/tests/Integration/helpers/teardown.sh"
}

# ─── Summary ───

print_summary() {
    echo ""
    echo "═══════════════════════════"
    echo "       Test Summary"
    echo "═══════════════════════════"

    local total_failures=0

    if [[ "$MODE" != "integration" ]]; then
        if [[ $UNIT_PHP_EXIT -eq 0 ]]; then
            echo "  PHP unit tests:    PASSED"
        else
            echo "  PHP unit tests:    FAILED"
            total_failures=$((total_failures + 1))
        fi

        if [[ $UNIT_JS_EXIT -eq 0 ]]; then
            echo "  JS unit tests:     PASSED"
        else
            echo "  JS unit tests:     FAILED"
            total_failures=$((total_failures + 1))
        fi
    fi

    if [[ "$MODE" != "unit" ]]; then
        local integration_total=$((INTEGRATION_PASS + INTEGRATION_FAIL))
        echo "  Integration:       $INTEGRATION_PASS/$integration_total suites passed"
        total_failures=$((total_failures + INTEGRATION_FAIL))
    fi

    echo "═══════════════════════════"

    if [[ $total_failures -eq 0 ]]; then
        echo "  ALL TESTS PASSED"
    else
        echo "  SOME TESTS FAILED"
    fi

    echo "═══════════════════════════"

    return $total_failures
}

# ─── Main ───

main() {
    cd "$SCRIPT_DIR"

    load_overrides
    detect_ddev || resolve_local
    verify_environment
    install_deps

    # Ensure Playground is stopped and its private cache removed on exit
    trap cleanup EXIT

    case "$MODE" in
        unit)
            run_unit_php
            run_unit_js
            ;;
        integration)
            run_integration
            ;;
        all)
            run_unit_php
            run_unit_js
            run_integration
            ;;
    esac

    print_summary
    exit $?
}

main
