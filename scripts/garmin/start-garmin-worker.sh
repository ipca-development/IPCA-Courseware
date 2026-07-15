#!/usr/bin/env bash
set -euo pipefail

PHP_FPM_POOL="${PHP_FPM_POOL:-}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
NODE_BIN="${NODE_BIN:-/usr/bin/node}"
WORKER_COMMAND="${1:-serve}"

fail() {
  printf 'Garmin worker launcher error: %s\n' "$1" >&2
  exit 1
}

extract_env() {
  local name="$1"
  local matches count value

  case "$name" in
    GARMIN_WORKER_PORT|GARMIN_WORKER_HOST|GARMIN_WORKER_DISPLAY|GARMIN_BROWSER_PROFILE_DIR|GARMIN_PRIVATE_DOWNLOAD_DIR|GARMIN_WORKER_TOKEN|GARMIN_BROWSER_CHANNEL|GARMIN_BROWSER_LOCALE|PLAYWRIGHT_BROWSERS_PATH)
      ;;
    *)
      fail "refusing to parse non-allowlisted variable"
      ;;
  esac

  matches="$(awk -v name="$name" '
    BEGIN {
      pattern = "^[[:space:]]*env\\[" name "\\][[:space:]]*=[[:space:]]*"
    }
    $0 ~ pattern {
      sub(pattern, "", $0)
      sub(/[[:space:]]*;[[:space:]]*$/, "", $0)
      print
    }
  ' "$PHP_FPM_POOL")"

  count="$(printf '%s\n' "$matches" | sed '/^$/d' | wc -l | tr -d ' ')"
  if [[ "$count" != "1" ]]; then
    fail "$name must be defined exactly once in PHP-FPM pool"
  fi

  value="$(printf '%s\n' "$matches" | sed '/^$/d')"
  if [[ -z "$value" ]]; then
    fail "$name is empty in PHP-FPM pool"
  fi
  printf '%s' "$value"
}

[[ -n "$PHP_FPM_POOL" ]] || fail "PHP_FPM_POOL is not set"
[[ -r "$PHP_FPM_POOL" ]] || fail "PHP_FPM_POOL is not readable"
[[ -x "$NODE_BIN" ]] || fail "node binary is not executable at $NODE_BIN"

case "$WORKER_COMMAND" in
  serve|login|status)
    ;;
  *)
    fail "unsupported worker command"
    ;;
esac

export GARMIN_WORKER_PORT
GARMIN_WORKER_PORT="$(extract_env GARMIN_WORKER_PORT)"

export GARMIN_WORKER_HOST
GARMIN_WORKER_HOST="$(extract_env GARMIN_WORKER_HOST)"

export GARMIN_WORKER_DISPLAY
GARMIN_WORKER_DISPLAY="$(extract_env GARMIN_WORKER_DISPLAY)"
export DISPLAY
DISPLAY="$GARMIN_WORKER_DISPLAY"

export GARMIN_BROWSER_PROFILE_DIR
GARMIN_BROWSER_PROFILE_DIR="$(extract_env GARMIN_BROWSER_PROFILE_DIR)"

export GARMIN_PRIVATE_DOWNLOAD_DIR
GARMIN_PRIVATE_DOWNLOAD_DIR="$(extract_env GARMIN_PRIVATE_DOWNLOAD_DIR)"

export GARMIN_WORKER_TOKEN
GARMIN_WORKER_TOKEN="$(extract_env GARMIN_WORKER_TOKEN)"

export GARMIN_BROWSER_CHANNEL
GARMIN_BROWSER_CHANNEL="$(extract_env GARMIN_BROWSER_CHANNEL)"

export GARMIN_BROWSER_LOCALE
GARMIN_BROWSER_LOCALE="$(extract_env GARMIN_BROWSER_LOCALE)"

export PLAYWRIGHT_BROWSERS_PATH
PLAYWRIGHT_BROWSERS_PATH="$(extract_env PLAYWRIGHT_BROWSERS_PATH)"

if [[ "$GARMIN_WORKER_HOST" != "127.0.0.1" ]]; then
  fail "GARMIN_WORKER_HOST must be 127.0.0.1"
fi

case "$GARMIN_BROWSER_CHANNEL" in
  chrome|chromium)
    ;;
  *)
    fail "GARMIN_BROWSER_CHANNEL must be chrome or chromium"
    ;;
esac

if [[ ! "$GARMIN_BROWSER_LOCALE" =~ ^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})?$ ]]; then
  fail "GARMIN_BROWSER_LOCALE must be a stable locale such as en-US"
fi

case "$GARMIN_WORKER_PORT" in
  ''|*[!0-9]*)
    fail "GARMIN_WORKER_PORT must be numeric"
    ;;
esac

if [[ "$WORKER_COMMAND" == "serve" && -e "/run/ipca/garmin-auth/profile.lock" ]]; then
  (
    flock -n 9 || fail "Garmin authentication session is using the browser profile"
  ) 9>"/run/ipca/garmin-auth/profile.lock"
fi

exec "$NODE_BIN" "$SCRIPT_DIR/flygarmin-worker.js" "$WORKER_COMMAND"
