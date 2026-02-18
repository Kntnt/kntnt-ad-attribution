#!/usr/bin/env bash
# Clean up integration test environment.
#
# Removes the temporary cookie jar. Playground itself is stopped by
# the EXIT trap in run-tests.sh.

[[ -f "${COOKIE_JAR:-}" ]] && rm -f "$COOKIE_JAR"
