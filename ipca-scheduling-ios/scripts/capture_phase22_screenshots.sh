#!/bin/zsh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PROJECT="$ROOT/ipca-scheduling-ios/IPCAScheduling.xcodeproj"
OUTPUT="$ROOT/ipca-scheduling-ios/Screenshots/Phase2.2"
DERIVED="${TMPDIR:-/tmp}/IPCASchedulingPhase22Derived"
APP="$DERIVED/Build/Products/Debug-iphonesimulator/IPCAScheduling.app"

IPAD_13="${IPAD_13_UDID:-367E1F92-7EAC-4514-A5B9-882FF41DD326}"
IPAD_11="${IPAD_11_UDID:-091DD6AB-2685-4853-9A98-8CD8D1C3D5FE}"
IPHONE="${IPHONE_UDID:-2CDDFA13-B07C-4BCA-A109-D224587D74AD}"
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

for device in "$IPAD_13" "$IPAD_11" "$IPHONE"; do
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
  local device="$1"
  npx -y serve-sim --detach -q "$device" >/dev/null
}

rotate() {
  local device="$1"
  local orientation="$2"
  npx -y serve-sim rotate "$orientation" -d "$device" >/dev/null
  sleep 1
}

capture() {
  local device="$1"
  local preview="$2"
  local filename="$3"
  local rotated="${4:-yes}"
  local raw="${TMPDIR:-/tmp}/ipca-${filename}"

  xcrun simctl terminate "$device" "$BUNDLE_ID" 2>/dev/null || true
  xcrun simctl launch "$device" "$BUNDLE_ID" --ui-preview "$preview" >/dev/null
  sleep 2
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
capture "$IPAD_13" workstation-aircraft "01-ipad13-aircraft.png"
capture "$IPAD_13" workstation-instructor "02-ipad13-instructors.png"
capture "$IPAD_13" workstation-student "03-ipad13-students.png"
capture "$IPAD_13" workstation-inspector "04-ipad13-inspector.png"
capture "$IPAD_13" workstation-warning "05-ipad13-overlap-warning.png"
capture "$IPAD_13" workstation-full-day "06-ipad13-full-day.png"
capture "$IPAD_13" workstation-detailed "07-ipad13-detailed.png"
capture "$IPAD_13" workstation-week "08-ipad13-week.png"
capture "$IPAD_13" workstation-offline "12-ipad-offline-stale.png"
capture "$IPAD_13" workstation-sparse "15-ipad13-sparse.png"

start_rotation_helper "$IPAD_11"
rotate "$IPAD_11" landscape_left
capture "$IPAD_11" workstation-aircraft "09-ipad11-aircraft.png"
capture "$IPAD_11" workstation-inspector "10-ipad11-inspector.png"
rotate "$IPAD_11" portrait
capture "$IPAD_11" workstation-portrait "11-ipad-portrait.png" no

capture "$IPHONE" today "13-iphone-today.png" no
capture "$IPHONE" schedule "14-iphone-schedule.png" no

echo "Captured Phase 2.2 review set in $OUTPUT"
