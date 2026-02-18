#!/usr/bin/env bash
#
# Builds a clean kntnt-ad-attribution.zip from local files or a git tag.
#
# Usage:
#   build-release-zip.sh                              Local files → zip in $CWD
#   build-release-zip.sh --output <path>              Local files → zip in <path>
#   build-release-zip.sh --tag <tag>                  Tagged files → zip in $CWD
#   build-release-zip.sh --tag <tag> --output <path>  Tagged files → zip in <path>
#   build-release-zip.sh --tag <tag> --update         Tagged files → upload to existing release
#   build-release-zip.sh --tag <tag> --create         Tagged files → create release + upload
#
# --output can be combined with --update/--create (saves locally AND uploads).
#
# Requirements: zip, msgfmt (GNU gettext).
#   With --tag: git.
#   With --update/--create: gh (GitHub CLI).

set -euo pipefail

REPO="Kntnt/kntnt-ad-attribution"
PLUGIN_DIR="kntnt-ad-attribution"
ZIP_NAME="${PLUGIN_DIR}.zip"
ORIG_DIR=$(pwd)
SCRIPT_DIR=$(cd "$(dirname "$0")" && pwd)

# Parse arguments.
TAG=""
OUTPUT_DIR=""
RELEASE_ACTION=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --tag)
      [[ $# -lt 2 ]] && { echo "Error: --tag requires a value." >&2; exit 1; }
      TAG="$2"
      shift 2
      ;;
    --output)
      [[ $# -lt 2 ]] && { echo "Error: --output requires a value." >&2; exit 1; }
      [[ -d "$2" ]] || { echo "Error: Directory '$2' does not exist." >&2; exit 1; }
      OUTPUT_DIR=$(cd "$2" && pwd)
      shift 2
      ;;
    --update)
      [[ -n "$RELEASE_ACTION" ]] && { echo "Error: --update and --create are mutually exclusive." >&2; exit 1; }
      RELEASE_ACTION="update"
      shift
      ;;
    --create)
      [[ -n "$RELEASE_ACTION" ]] && { echo "Error: --update and --create are mutually exclusive." >&2; exit 1; }
      RELEASE_ACTION="create"
      shift
      ;;
    *)
      echo "Unknown option: $1" >&2
      exit 1
      ;;
  esac
done

# Validate flag combinations.
if [[ -n "$RELEASE_ACTION" && -z "$TAG" ]]; then
  echo "Error: --${RELEASE_ACTION} requires --tag." >&2
  exit 1
fi

# Verify that all required tools are available.
MISSING=()
for cmd in zip msgfmt; do
  command -v "$cmd" &>/dev/null || MISSING+=("$cmd")
done
if [[ -n "$RELEASE_ACTION" ]]; then
  command -v gh &>/dev/null || MISSING+=("gh")
fi
if [[ ${#MISSING[@]} -gt 0 ]]; then
  echo "Missing required tools: ${MISSING[*]}" >&2
  echo "Install them before running this script (e.g. brew install gettext)." >&2
  exit 1
fi

# Verify tag and release state.
if [[ -n "$TAG" ]]; then
  if [[ -z $(git -C "$SCRIPT_DIR" tag -l "$TAG") ]]; then
    echo "Error: Tag '$TAG' does not exist." >&2
    exit 1
  fi
  if [[ "$RELEASE_ACTION" == "update" ]]; then
    if ! gh release view "$TAG" --repo "$REPO" &>/dev/null; then
      echo "Error: Release '$TAG' does not exist. Use --create instead." >&2
      exit 1
    fi
  fi
  if [[ "$RELEASE_ACTION" == "create" ]]; then
    if gh release view "$TAG" --repo "$REPO" &>/dev/null; then
      echo "Error: Release '$TAG' already exists. Use --update instead." >&2
      exit 1
    fi
  fi
fi

# Files and directories to keep in the release zip.
KEEP=(
  autoloader.php
  classes
  css
  install.php
  js
  kntnt-ad-attribution.php
  languages
  LICENSE
  migrations
  README.md
  uninstall.php
)

# Work in a temporary directory that is cleaned up on exit.
TMPDIR=$(mktemp -d)
trap 'rm -rf "$TMPDIR"' EXIT

# Prepare source files.
if [[ -n "$TAG" ]]; then
  echo "Source: git tag $TAG"
  git -C "$SCRIPT_DIR" archive --prefix="${PLUGIN_DIR}/" "$TAG" | tar -xf - -C "$TMPDIR"
else
  echo "Source: local files"
  rsync -a "$SCRIPT_DIR/" "$TMPDIR/$PLUGIN_DIR/"
fi

# Remove everything not in the keep list.
cd "$TMPDIR/$PLUGIN_DIR"
for entry in *; do
  keep=false
  for allowed in "${KEEP[@]}"; do
    if [[ "$entry" == "$allowed" ]]; then
      keep=true
      break
    fi
  done
  if [[ "$keep" == false ]]; then
    rm -rf "$entry"
    echo "  Removed: $entry"
  fi
done
cd "$TMPDIR"

# Remove any dot-files/dot-dirs (e.g. .gitignore, .github).
find "$PLUGIN_DIR" -maxdepth 1 -name '.*' -exec rm -rf {} +

# Compile .po files to .mo.
for po in "$PLUGIN_DIR"/languages/*.po; do
  [[ -f "$po" ]] && msgfmt -o "${po%.po}.mo" "$po"
done

# Create the zip.
zip -qr "$ZIP_NAME" "$PLUGIN_DIR"
echo "Created: $ZIP_NAME ($(du -h "$ZIP_NAME" | cut -f1))"

# Deliver the zip.
if [[ -n "$OUTPUT_DIR" ]]; then
  cp "$ZIP_NAME" "$OUTPUT_DIR/$ZIP_NAME"
  echo "Saved: $OUTPUT_DIR/$ZIP_NAME"
fi

if [[ "$RELEASE_ACTION" == "create" ]]; then
  gh release create "$TAG" --generate-notes --repo "$REPO"
  echo "Created release: $TAG"
fi

if [[ "$RELEASE_ACTION" == "update" || "$RELEASE_ACTION" == "create" ]]; then
  # Delete existing asset with the same name (if any) before uploading.
  if gh release view "$TAG" --repo "$REPO" --json assets --jq ".assets[].name" | grep -qx "$ZIP_NAME"; then
    echo "Deleting existing $ZIP_NAME from release ${TAG}…"
    gh release delete-asset "$TAG" "$ZIP_NAME" --repo "$REPO" --yes
  fi
  gh release upload "$TAG" "$ZIP_NAME" --repo "$REPO"
  echo "Uploaded $ZIP_NAME to release $TAG"
fi

# Default: save zip to original working directory.
if [[ -z "$OUTPUT_DIR" && -z "$RELEASE_ACTION" ]]; then
  cp "$ZIP_NAME" "$ORIG_DIR/$ZIP_NAME"
  echo "Saved: $ORIG_DIR/$ZIP_NAME"
fi
