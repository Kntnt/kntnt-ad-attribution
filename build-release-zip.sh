#!/usr/bin/env bash
#
# Builds a clean kntnt-ad-attribution.zip from local files or a git tag.
#
# Requirements: zip, msgfmt (GNU gettext).
#   With --tag: git.
#   With --update/--create: gh (GitHub CLI).

set -euo pipefail

REPO="Kntnt/kntnt-ad-attribution"
PLUGIN_DIR="kntnt-ad-attribution"
ZIP_NAME="${PLUGIN_DIR}.zip"
SCRIPT_DIR=$(cd "$(dirname "$0")" && pwd)

# Print usage and exit.
usage() {
  cat <<'HELP'
Usage:
  build-release-zip.sh --output <path>
  build-release-zip.sh --tag <tag> --output <path>
  build-release-zip.sh --tag <tag> --update
  build-release-zip.sh --tag <tag> --create
  build-release-zip.sh --help

Source:
  Without --tag, builds from the local working copy.
  With --tag <tag>, builds from files at the given git tag.

Destination (exactly one required):
  --output <path>      Save zip to <path>. If <path> is a directory or ends
                       with /, saves as kntnt-ad-attribution.zip in that
                       directory. Otherwise the last path component is used
                       as the filename. The parent directory must exist.
  --update             Upload zip to an existing GitHub release for <tag>.
                       Replaces any existing zip asset. Requires --tag.
  --create             Create a new GitHub release for <tag> and upload zip.
                       The tag must already exist. Requires --tag.

Examples:
  build-release-zip.sh --output .
  build-release-zip.sh --output ~/Desktop/custom-name.zip
  build-release-zip.sh --tag 1.7.0 --output /tmp
  build-release-zip.sh --tag 1.7.0 --create
  build-release-zip.sh --tag 1.7.0 --update
HELP
  exit "${1:-0}"
}

# Parse arguments.
TAG=""
OUTPUT_PATH=""
RELEASE_ACTION=""

[[ $# -eq 0 ]] && usage 1

while [[ $# -gt 0 ]]; do
  case "$1" in
    --help|-h)
      usage 0
      ;;
    --tag)
      [[ $# -lt 2 ]] && { echo "Error: --tag requires a value." >&2; exit 1; }
      TAG="$2"
      shift 2
      ;;
    --output)
      [[ $# -lt 2 ]] && { echo "Error: --output requires a value." >&2; exit 1; }
      OUTPUT_PATH="$2"
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
      echo "Error: Unknown option: $1" >&2
      echo >&2
      usage 1
      ;;
  esac
done

# Validate: exactly one destination is required.
if [[ -z "$OUTPUT_PATH" && -z "$RELEASE_ACTION" ]]; then
  echo "Error: specify --output, --update, or --create." >&2
  echo >&2
  usage 1
fi

if [[ -n "$OUTPUT_PATH" && -n "$RELEASE_ACTION" ]]; then
  echo "Error: --output and --${RELEASE_ACTION} cannot be combined." >&2
  exit 1
fi

# Validate: --update/--create requires --tag.
if [[ -n "$RELEASE_ACTION" && -z "$TAG" ]]; then
  echo "Error: --${RELEASE_ACTION} requires --tag." >&2
  exit 1
fi

# Resolve output path: directory → append default filename; file → parent must exist.
OUTPUT_FILE=""
if [[ -n "$OUTPUT_PATH" ]]; then
  if [[ -d "$OUTPUT_PATH" ]]; then
    OUTPUT_FILE="$(cd "$OUTPUT_PATH" && pwd)/$ZIP_NAME"
  elif [[ "$OUTPUT_PATH" == */ ]]; then
    echo "Error: Directory '${OUTPUT_PATH}' does not exist." >&2
    exit 1
  else
    parent_dir=$(dirname "$OUTPUT_PATH")
    if [[ ! -d "$parent_dir" ]]; then
      echo "Error: Directory '${parent_dir}' does not exist." >&2
      exit 1
    fi
    OUTPUT_FILE="$(cd "$parent_dir" && pwd)/$(basename "$OUTPUT_PATH")"
  fi
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
    echo "Create it first:  git tag $TAG && git push origin $TAG" >&2
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
if [[ -n "$OUTPUT_FILE" ]]; then
  cp "$ZIP_NAME" "$OUTPUT_FILE"
  echo "Saved: $OUTPUT_FILE"
fi

if [[ "$RELEASE_ACTION" == "create" ]]; then
  gh release create "$TAG" --generate-notes --repo "$REPO"
  echo "Created release: $TAG"
fi

if [[ "$RELEASE_ACTION" == "update" || "$RELEASE_ACTION" == "create" ]]; then
  # Replace existing asset with the same name (if any).
  if gh release view "$TAG" --repo "$REPO" --json assets --jq ".assets[].name" | grep -qx "$ZIP_NAME"; then
    echo "Replacing existing $ZIP_NAME in release ${TAG}…"
    gh release delete-asset "$TAG" "$ZIP_NAME" --repo "$REPO" --yes
  fi
  gh release upload "$TAG" "$ZIP_NAME" --repo "$REPO"
  echo "Uploaded $ZIP_NAME to release $TAG"
fi
