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

# ─── Dependency checks ───

check_requirements() {
    local errors=0

    # PHP version check
    if ! command -v php >/dev/null 2>&1; then
        echo "ERROR: PHP is required but not found." >&2
        errors=$((errors + 1))
    else
        local php_version
        php_version=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
        if [[ "$(printf '%s\n' "8.3" "$php_version" | sort -V | head -1)" != "8.3" ]]; then
            echo "ERROR: PHP 8.3+ required (found $php_version)" >&2
            errors=$((errors + 1))
        fi
    fi

    # Node.js version check
    if ! command -v node >/dev/null 2>&1; then
        echo "ERROR: Node.js is required but not found." >&2
        errors=$((errors + 1))
    else
        local node_version
        node_version=$(node --version | sed 's/^v//')
        if [[ "$(printf '%s\n' "20.18" "$node_version" | sort -V | head -1)" != "20.18" ]]; then
            echo "ERROR: Node.js 20.18+ required (found $node_version)" >&2
            errors=$((errors + 1))
        fi
    fi

    # Composer check
    if ! command -v composer >/dev/null 2>&1; then
        echo "ERROR: Composer is required but not found." >&2
        errors=$((errors + 1))
    fi

    # npm check
    if ! command -v npm >/dev/null 2>&1; then
        echo "ERROR: npm is required but not found." >&2
        errors=$((errors + 1))
    fi

    # curl check
    if ! command -v curl >/dev/null 2>&1; then
        echo "ERROR: curl is required but not found." >&2
        errors=$((errors + 1))
    fi

    # jq check (needed for integration tests)
    if [[ "$MODE" != "unit" ]] && ! command -v jq >/dev/null 2>&1; then
        echo "ERROR: jq is required for integration tests but not found." >&2
        errors=$((errors + 1))
    fi

    if [[ $errors -gt 0 ]]; then
        echo "" >&2
        echo "Missing $errors required tool(s). See docs/testing-strategy.md for requirements." >&2
        exit 1
    fi
}

# ─── Install dependencies ───

install_deps() {
    if [[ ! -d "$SCRIPT_DIR/vendor" ]]; then
        echo "Installing PHP dependencies..."
        composer install --dev --working-dir="$SCRIPT_DIR" --quiet
    fi

    if [[ ! -d "$SCRIPT_DIR/node_modules" ]]; then
        echo "Installing Node.js dependencies..."
        npm install --prefix "$SCRIPT_DIR" --silent
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

    if "$SCRIPT_DIR/vendor/bin/pest" "${pest_args[@]}"; then
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

    if npx --prefix "$SCRIPT_DIR" vitest "${vitest_args[@]}"; then
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

    npx --prefix "$SCRIPT_DIR" @wp-playground/cli server \
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

    check_requirements
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
