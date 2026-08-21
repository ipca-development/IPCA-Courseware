#!/bin/zsh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PROJECT="$ROOT/ipca-scheduling-ios/IPCAScheduling.xcodeproj"
OUTPUT="$ROOT/ipca-scheduling-ios/Screenshots/WeekPass"
DERIVED="${TMPDIR:-/tmp}/IPCASchedulingWeekPassDerived"
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

rotate_landscape() {
  npx -y serve-sim rotate landscape_left -d "$1" >/dev/null
  sleep 1
}

capture() {
  local device="$1"
  local preview="$2"
  local filename="$3"
  local raw="${TMPDIR:-/tmp}/ipca-week-${filename}"

  xcrun simctl terminate "$device" "$BUNDLE_ID" 2>/dev/null || true
  xcrun simctl launch "$device" "$BUNDLE_ID" --ui-preview "$preview" >/dev/null
  sleep 3
  xcrun simctl io "$device" screenshot "$raw" >/dev/null
  sips --rotate 90 "$raw" --out "$OUTPUT/$filename" >/dev/null
  rm -f "$raw"
}

start_rotation_helper "$IPAD_13"
rotate_landscape "$IPAD_13"
capture "$IPAD_13" workstation-week "01-ipad13-aircraft.png"
capture "$IPAD_13" workstation-week-instructor "02-ipad13-instructors.png"
capture "$IPAD_13" workstation-week-student "03-ipad13-students.png"
capture "$IPAD_13" workstation-week-sparse "04-busy-and-empty-days.png"
capture "$IPAD_13" workstation-week-warning "05-week-warning.png"

start_rotation_helper "$IPAD_11"
rotate_landscape "$IPAD_11"
capture "$IPAD_11" workstation-week "06-ipad11-aircraft.png"

echo "Captured Week workstation review set in $OUTPUT"
