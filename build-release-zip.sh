#!/usr/bin/env bash
#
# Builds a clean kntnt-ad-attribution.zip from the latest GitHub release
# source archive.
#
# Usage:
#   build-release-zip.sh --output <dir>   Save the zip to <dir>.
#   build-release-zip.sh --upload          Upload the zip to the GitHub release.
#
# Requirements: gh (GitHub CLI), msgfmt (GNU gettext).

set -euo pipefail

# Verify that all required tools are available.
MISSING=()
for cmd in gh msgfmt unzip zip; do
  command -v "$cmd" &>/dev/null || MISSING+=("$cmd")
done
if [[ ${#MISSING[@]} -gt 0 ]]; then
  echo "Missing required tools: ${MISSING[*]}" >&2
  echo "Install them before running this script (e.g. brew install gettext)." >&2
  exit 1
fi

REPO="Kntnt/kntnt-ad-attribution"
PLUGIN_DIR="kntnt-ad-attribution"
ZIP_NAME="${PLUGIN_DIR}.zip"

# Parse arguments.
ACTION=""
OUTPUT_DIR=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --output)
      ACTION="output"
      OUTPUT_DIR=$(cd "$2" && pwd)
      shift 2
      ;;
    --upload)
      ACTION="upload"
      shift
      ;;
    *)
      echo "Unknown option: $1" >&2
      exit 1
      ;;
  esac
done

if [[ -z "$ACTION" ]]; then
  echo "Usage:" >&2
  echo "  build-release-zip.sh --output <dir>   Save the zip to <dir>." >&2
  echo "  build-release-zip.sh --upload          Upload the zip to the GitHub release." >&2
  exit 1
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

# Resolve the latest release tag.
TAG=$(gh release view --repo "$REPO" --json tagName --jq '.tagName')
echo "Latest release: $TAG"

# Work in a temporary directory that is cleaned up on exit.
TMPDIR=$(mktemp -d)
trap 'rm -rf "$TMPDIR"' EXIT
cd "$TMPDIR"

# Download and extract the source archive.
gh release download "$TAG" --repo "$REPO" --archive zip --output source.zip
unzip -q source.zip
rm source.zip

# GitHub names the extracted directory <repo>-<tag-without-leading-v>.
EXTRACTED=$(ls -d */ | head -1 | sed 's|/$||')
mv "$EXTRACTED" "$PLUGIN_DIR"

# Remove everything not in the keep list.
cd "$PLUGIN_DIR"
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
cd ..

# Also remove any dot-files/dot-dirs (e.g. .gitignore, .github).
find "$PLUGIN_DIR" -maxdepth 1 -name '.*' -exec rm -rf {} +

# Compile .po files to .mo.
for po in "$PLUGIN_DIR"/languages/*.po; do
  [[ -f "$po" ]] && msgfmt -o "${po%.po}.mo" "$po"
done

# Create the zip.
zip -qr "$ZIP_NAME" "$PLUGIN_DIR"
echo "Created: $ZIP_NAME ($(du -h "$ZIP_NAME" | cut -f1))"

# Deliver the zip.
if [[ "$ACTION" == "output" ]]; then
  cp "$ZIP_NAME" "$OUTPUT_DIR/$ZIP_NAME"
  echo "Saved: $OUTPUT_DIR/$ZIP_NAME"
else
  # Delete existing asset with the same name (if any) before uploading.
  if gh release view "$TAG" --repo "$REPO" --json assets --jq ".assets[].name" | grep -qx "$ZIP_NAME"; then
    echo "Deleting existing $ZIP_NAME from release ${TAG}…"
    gh release delete-asset "$TAG" "$ZIP_NAME" --repo "$REPO" --yes
  fi

  # Upload the new zip to the release.
  gh release upload "$TAG" "$ZIP_NAME" --repo "$REPO"
  echo "Uploaded $ZIP_NAME to release $TAG"
fi
