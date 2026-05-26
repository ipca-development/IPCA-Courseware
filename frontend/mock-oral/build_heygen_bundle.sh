#!/usr/bin/env bash
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$DIR/../.." && pwd)"
OUT="$ROOT/public/assets/vendor/liveavatar-web-sdk.bundle.js"

cd "$DIR"
npm install --no-audit --no-fund >/dev/null

npx esbuild heygen_entry.js \
  --bundle \
  --format=iife \
  --global-name=LiveAvatarSdk \
  --outfile="$OUT" \
  --platform=browser \
  --minify

echo "Wrote $(wc -c < "$OUT") bytes to $OUT"
