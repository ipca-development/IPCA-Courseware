#!/bin/zsh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PROJECT="$ROOT/ipca-scheduling-ios/IPCAScheduling.xcodeproj"
OUTPUT="$ROOT/ipca-scheduling-ios/Screenshots/InspectorPass"
DERIVED="${TMPDIR:-/tmp}/IPCASchedulingInspectorPassDerived"
APP="$DERIVED/Build/Products/Debug-iphonesimulator/IPCAScheduling.app"

IPAD_13="${IPAD_13_UDID:-367E1F92-7EAC-4514-A5B9-882FF41DD326}"
IPAD_11="${IPAD_11_UDID:-091DD6AB-2685-4853-9A98-8CD8D1C3D5FE}"
BUNDLE_ID="com.ipca.scheduling"

mkdir -p "$OUTPUT"

xcodebuild build \
  -project "$PROJECT" \
  -scheme IPCAScheduling \
  -sdk iphonesimulator \
  -destination 'generic/platform=iOS Simulator' \
  -configuration Debug \
  -derivedDataPath "$DERIVED" \
  CODE_SIGNING_ALLOWED=NO

for device in "$IPAD_13" "$IPAD_11"; do
  xcrun simctl boot "$device" 2>/dev/null || true
  xcrun simctl bootstatus "$device" -b
  xcrun simctl install "$device" "$APP"
done

cleanup() {
  npx -y serve-sim --kill >/dev/null 2>&1 || true
}
trap cleanup EXIT
cleanup

start_rotation_helper() {
  npx -y serve-sim --detach -q "$1" >/dev/null
}

rotate() {
  npx -y serve-sim rotate "$2" -d "$1" >/dev/null
  sleep 1
}

capture() {
  local device="$1"
  local preview="$2"
  local filename="$3"
  local rotated="${4:-yes}"
  local raw="${TMPDIR:-/tmp}/ipca-inspector-${filename}"

  xcrun simctl terminate "$device" "$BUNDLE_ID" 2>/dev/null || true
  xcrun simctl launch "$device" "$BUNDLE_ID" --ui-preview "$preview" >/dev/null
  sleep 3
  xcrun simctl io "$device" screenshot "$raw" >/dev/null
  if [[ "$rotated" == "yes" ]]; then
    sips --rotate 90 "$raw" --out "$OUTPUT/$filename" >/dev/null
  else
    cp "$raw" "$OUTPUT/$filename"
  fi
  rm -f "$raw"
}

start_rotation_helper "$IPAD_13"
rotate "$IPAD_13" landscape_left
capture "$IPAD_13" workstation-aircraft "01-aircraft-timeline.png"
capture "$IPAD_13" workstation-filters "02-filters-open.png"
capture "$IPAD_13" workstation-inspector "03-inspector-open.png"
capture "$IPAD_13" workstation-expanded "04-expanded-full-details.png"
capture "$IPAD_13" workstation-crew "05-multiple-crew.png"
capture "$IPAD_13" workstation-warning "06-warning.png"

start_rotation_helper "$IPAD_11"
rotate "$IPAD_11" landscape_left
capture "$IPAD_11" workstation-inspector "07-ipad11-inspector.png"
rotate "$IPAD_11" portrait
capture "$IPAD_11" workstation-narrow "08-narrow-inspector.png" no

echo "Captured inspector consistency review set in $OUTPUT"
