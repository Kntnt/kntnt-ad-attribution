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
PLAYGROUND_PORT=9400
PLAYGROUND_PID=""
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

    # Assign DDEV commands for PHP/Composer (need CWD mapping via "ddev here").
    # Node/npm stay local — node_modules has host-native binaries (rollup etc.)
    # that won't work inside the Linux container.
    [[ -z "${EXPLICIT_VARS[PHP_BIN]+x}" ]]      && PHP_BIN="ddev here php"
    [[ -z "${EXPLICIT_VARS[COMPOSER_BIN]+x}" ]]  && COMPOSER_BIN="ddev here composer"
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
        $COMPOSER_BIN install --dev --quiet
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

    if $PHP_BIN vendor/bin/pest "${pest_args[@]}"; then
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
    echo ""
    echo "═══ Level 2: Integration Tests ═══"
    echo ""
    echo "Starting WordPress Playground on port $PLAYGROUND_PORT..."

    # Playground always runs on the host (needs host ports and mount paths)
    $LOCAL_NPX @wp-playground/cli server \
        --port="$PLAYGROUND_PORT" \
        --mount="$SCRIPT_DIR:/wordpress/wp-content/plugins/kntnt-ad-attribution" \
        --mount="$SCRIPT_DIR/tests/Integration/fake-consent-plugin:/wordpress/wp-content/mu-plugins/fake-consent-plugin" \
        --mount="$SCRIPT_DIR/tests/Integration/test-helpers-plugin:/wordpress/wp-content/mu-plugins/test-helpers-plugin" \
        --blueprint="$SCRIPT_DIR/tests/Integration/blueprint.json" \
        --login &
    PLAYGROUND_PID=$!

    # Wait for server readiness (max 60 seconds)
    local max_wait=60
    local waited=0
    while ! curl -sf "http://localhost:$PLAYGROUND_PORT/" > /dev/null 2>&1; do
        sleep 1
        waited=$((waited + 1))
        if [[ $waited -ge $max_wait ]]; then
            echo "ERROR: Playground did not start within ${max_wait}s" >&2
            kill "$PLAYGROUND_PID" 2>/dev/null || true
            exit 1
        fi
    done
    echo "Playground ready (waited ${waited}s)"
}

stop_playground() {
    if [[ -n "$PLAYGROUND_PID" ]]; then
        kill "$PLAYGROUND_PID" 2>/dev/null || true
        wait "$PLAYGROUND_PID" 2>/dev/null || true
        PLAYGROUND_PID=""
        echo "Playground stopped."
    fi
}

bootstrap_integration() {
    export WP_BASE_URL="http://localhost:$PLAYGROUND_PORT"
    source "$SCRIPT_DIR/tests/Integration/helpers/setup.sh"
}

run_integration() {
    local test_files=("$SCRIPT_DIR"/tests/Integration/test-*.sh)

    # Check that integration tests exist
    if [[ ${#test_files[@]} -eq 0 ]] || [[ ! -f "${test_files[0]}" ]]; then
        echo "No integration test files found (tests/Integration/test-*.sh)."
        echo "Skipping integration tests."
        return 0
    fi

    for test_file in "${test_files[@]}"; do
        local test_name
        test_name=$(basename "$test_file")

        # Apply filter if set
        if [[ -n "$FILTER" ]] && [[ "$test_name" != *"$FILTER"* ]]; then
            continue
        fi

        echo ""
        echo "--- Running $test_name ---"

        if bash "$test_file"; then
            INTEGRATION_PASS=$((INTEGRATION_PASS + 1))
        else
            INTEGRATION_FAIL=$((INTEGRATION_FAIL + 1))
            echo "FAILED: $test_name"
        fi
    done
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

    # Ensure Playground is stopped on exit
    trap stop_playground EXIT

    case "$MODE" in
        unit)
            run_unit_php
            run_unit_js
            ;;
        integration)
            start_playground
            bootstrap_integration
            run_integration
            teardown_integration
            ;;
        all)
            run_unit_php
            run_unit_js
            start_playground
            bootstrap_integration
            run_integration
            teardown_integration
            ;;
    esac

    print_summary
    exit $?
}

main
