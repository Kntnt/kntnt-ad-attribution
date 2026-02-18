#!/usr/bin/env bash
# Bash assertion helpers for integration tests.
#
# Source this file from each test script. Call print_summary at the end
# to report results — its exit code is 0 only if all assertions passed.

TESTS_RUN=0
TESTS_FAILED=0

assert_status() {
    local expected="$1" actual="$2" msg="${3:-}"
    TESTS_RUN=$((TESTS_RUN + 1))
    if [[ "$actual" != "$expected" ]]; then
        echo "  FAIL: Expected status $expected, got $actual. $msg"
        TESTS_FAILED=$((TESTS_FAILED + 1))
        return 1
    fi
    echo "  PASS: $msg"
}

assert_contains() {
    local haystack="$1" needle="$2" msg="${3:-}"
    TESTS_RUN=$((TESTS_RUN + 1))
    if [[ "$haystack" != *"$needle"* ]]; then
        echo "  FAIL: Response does not contain '$needle'. $msg"
        TESTS_FAILED=$((TESTS_FAILED + 1))
        return 1
    fi
    echo "  PASS: $msg"
}

assert_not_contains() {
    local haystack="$1" needle="$2" msg="${3:-}"
    TESTS_RUN=$((TESTS_RUN + 1))
    if [[ "$haystack" == *"$needle"* ]]; then
        echo "  FAIL: Response should not contain '$needle'. $msg"
        TESTS_FAILED=$((TESTS_FAILED + 1))
        return 1
    fi
    echo "  PASS: $msg"
}

assert_equals() {
    local expected="$1" actual="$2" msg="${3:-}"
    TESTS_RUN=$((TESTS_RUN + 1))
    if [[ "$actual" != "$expected" ]]; then
        echo "  FAIL: Expected '$expected', got '$actual'. $msg"
        TESTS_FAILED=$((TESTS_FAILED + 1))
        return 1
    fi
    echo "  PASS: $msg"
}

assert_greater_than() {
    local threshold="$1" actual="$2" msg="${3:-}"
    TESTS_RUN=$((TESTS_RUN + 1))
    if [[ "$actual" -le "$threshold" ]]; then
        echo "  FAIL: Expected > $threshold, got $actual. $msg"
        TESTS_FAILED=$((TESTS_FAILED + 1))
        return 1
    fi
    echo "  PASS: $msg"
}

assert_json_field() {
    local json="$1" field="$2" expected="$3" msg="${4:-}"
    local actual
    actual=$(echo "$json" | jq -r ".$field")
    assert_equals "$expected" "$actual" "$msg"
}

print_summary() {
    echo ""
    echo "Tests run: $TESTS_RUN, Passed: $((TESTS_RUN - TESTS_FAILED)), Failed: $TESTS_FAILED"
    [[ $TESTS_FAILED -eq 0 ]]
}
