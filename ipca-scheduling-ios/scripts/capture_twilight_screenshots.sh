#!/bin/zsh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PROJECT="$ROOT/ipca-scheduling-ios/IPCAScheduling.xcodeproj"
OUTPUT="$ROOT/ipca-scheduling-ios/Screenshots/TwilightPass"
DERIVED="${TMPDIR:-/tmp}/IPCASchedulingTwilightPassDerived"
APP="$DERIVED/Build/Products/Debug-iphonesimulator/IPCAScheduling.app"
IPAD_13="${IPAD_13_UDID:-367E1F92-7EAC-4514-A5B9-882FF41DD326}"
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

xcrun simctl boot "$IPAD_13" 2>/dev/null || true
xcrun simctl bootstatus "$IPAD_13" -b
xcrun simctl install "$IPAD_13" "$APP"

cleanup() {
  npx -y serve-sim --kill >/dev/null 2>&1 || true
}
trap cleanup EXIT
cleanup
npx -y serve-sim --detach -q "$IPAD_13" >/dev/null
npx -y serve-sim rotate landscape_left -d "$IPAD_13" >/dev/null
sleep 1

capture() {
  local preview="$1"
  local filename="$2"
  local raw="${TMPDIR:-/tmp}/ipca-twilight-${filename}"

  xcrun simctl terminate "$IPAD_13" "$BUNDLE_ID" 2>/dev/null || true
  xcrun simctl launch "$IPAD_13" "$BUNDLE_ID" --ui-preview "$preview" >/dev/null
  sleep 3
  xcrun simctl io "$IPAD_13" screenshot "$raw" >/dev/null
  sips --rotate 90 "$raw" --out "$OUTPUT/$filename" >/dev/null
  rm -f "$raw"
}

capture workstation-twilight-morning "01-morning-transition.png"
capture workstation-twilight-evening "02-evening-transition.png"
capture workstation-twilight-full-day "03-full-day.png"
capture workstation-twilight-evening-selected "04-evening-crossing-reservation.png"
capture workstation-twilight-morning-selected "05-morning-crossing-reservation.png"

echo "Captured twilight review set in $OUTPUT"
