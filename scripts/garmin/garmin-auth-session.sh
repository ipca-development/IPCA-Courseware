#!/usr/bin/env bash
set -euo pipefail

ACTION="${1:-}"
APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT_DIR="$APP_ROOT/scripts/garmin"
PHP_FPM_POOL="${PHP_FPM_POOL:-/etc/php/8.3/fpm/pool.d/www.conf}"
NODE_BIN="${NODE_BIN:-/usr/bin/node}"
SERVICE_USER="ipca-garmin"
WORKER_SERVICE="garmin-worker"
RUNTIME_DIR="/run/ipca/garmin-auth"
STATE_FILE="$RUNTIME_DIR/session.json"
COMMAND_FILE="$RUNTIME_DIR/command.json"
RESULT_FILE="$RUNTIME_DIR/verify-result.json"
LOCK_FILE="$RUNTIME_DIR/profile.lock"
DISPLAY_ID=":95"
VNC_HOST="127.0.0.1"
VNC_PORT="5905"
TTL_SECONDS="900"

fail() {
  printf '{"ok":false,"status":"failed","error":"%s"}\n' "$1"
  exit 1
}

require_root() {
  [[ "$(id -u)" = "0" ]] || fail "helper_must_run_as_root"
}

generate_vnc_password() {
  command -v openssl >/dev/null 2>&1 || fail "openssl_is_required_to_generate_temporary_vnc_password"
  local password
  password="$(openssl rand -hex 16)"
  password="${password:0:14}"
  [[ "${#password}" = "14" ]] || fail "temporary_vnc_password_length_invalid"
  [[ "$password" =~ ^[0-9a-f]{14}$ ]] || fail "temporary_vnc_password_charset_invalid"
  printf '%s' "$password"
}

extract_env() {
  local name="$1"
  local matches count value
  case "$name" in
    GARMIN_WORKER_PORT|GARMIN_WORKER_HOST|GARMIN_BROWSER_PROFILE_DIR|GARMIN_PRIVATE_DOWNLOAD_DIR|GARMIN_WORKER_TOKEN|PLAYWRIGHT_BROWSERS_PATH)
      ;;
    *)
      fail "non_allowlisted_env"
      ;;
  esac
  matches="$(awk -v name="$name" '
    BEGIN { pattern = "^[[:space:]]*env\\[" name "\\][[:space:]]*=[[:space:]]*" }
    $0 ~ pattern {
      sub(pattern, "", $0)
      sub(/[[:space:]]*;[[:space:]]*$/, "", $0)
      print
    }
  ' "$PHP_FPM_POOL")"
  count="$(printf '%s\n' "$matches" | sed '/^$/d' | wc -l | tr -d ' ')"
  [[ "$count" = "1" ]] || fail "${name}_must_be_defined_once"
  value="$(printf '%s\n' "$matches" | sed '/^$/d')"
  [[ -n "$value" ]] || fail "${name}_is_empty"
  printf '%s' "$value"
}

load_env() {
  [[ -r "$PHP_FPM_POOL" ]] || fail "php_fpm_pool_not_readable"
  [[ -x "$NODE_BIN" ]] || fail "node_not_executable"
  export GARMIN_WORKER_PORT
  GARMIN_WORKER_PORT="$(extract_env GARMIN_WORKER_PORT)"
  export GARMIN_WORKER_HOST
  GARMIN_WORKER_HOST="$(extract_env GARMIN_WORKER_HOST)"
  export GARMIN_BROWSER_PROFILE_DIR
  GARMIN_BROWSER_PROFILE_DIR="$(extract_env GARMIN_BROWSER_PROFILE_DIR)"
  export GARMIN_PRIVATE_DOWNLOAD_DIR
  GARMIN_PRIVATE_DOWNLOAD_DIR="$(extract_env GARMIN_PRIVATE_DOWNLOAD_DIR)"
  export GARMIN_WORKER_TOKEN
  GARMIN_WORKER_TOKEN="$(extract_env GARMIN_WORKER_TOKEN)"
  export PLAYWRIGHT_BROWSERS_PATH
  PLAYWRIGHT_BROWSERS_PATH="$(extract_env PLAYWRIGHT_BROWSERS_PATH)"
  [[ "$GARMIN_WORKER_HOST" = "127.0.0.1" ]] || fail "worker_host_must_be_localhost"
  [[ "$GARMIN_WORKER_PORT" =~ ^[0-9]+$ ]] || fail "worker_port_must_be_numeric"
}

ensure_runtime_dir() {
  install -d -m 0770 -o root -g "$SERVICE_USER" "$RUNTIME_DIR"
}

preflight_browser_runtime() {
  runuser -u "$SERVICE_USER" -- env GARMIN_AUTH_RUNTIME_DIR="$RUNTIME_DIR" python3 - <<'PY' || fail "runtime_dir_not_writable_by_ipca_garmin"
import json
import os
import pathlib

runtime = pathlib.Path(os.environ["GARMIN_AUTH_RUNTIME_DIR"])
probe = runtime / ".browser-write-test.json"
probe.write_text(json.dumps({"ok": True}) + "\n", encoding="utf-8")
probe.unlink()
PY
}

now_epoch() {
  date -u +%s
}

iso_now() {
  date -u +"%Y-%m-%dT%H:%M:%SZ"
}

write_state() {
  local status="$1"
  local safe_error="${2:-}"
  local started_at="${STARTED_AT:-$(iso_now)}"
  local expires_at="${EXPIRES_AT:-$(date -u -d "@$(( $(now_epoch) + TTL_SECONDS ))" +"%Y-%m-%dT%H:%M:%SZ")}"
  umask 077
  cat > "$STATE_FILE" <<EOF
{
  "ok": true,
  "status": "$status",
  "browser_running": $(browser_running_json),
  "started_at": "$started_at",
  "expires_at": "$expires_at",
  "vnc_host": "$VNC_HOST",
  "vnc_port": $VNC_PORT,
  "mac_ssh_command": "ssh -L 5905:127.0.0.1:5905 root@157.230.237.72",
  "mac_vnc_url": "vnc://localhost:5905",
  "vnc_password": "${VNC_PASSWORD:-}",
  "credentials_stored": false,
  "error": "$safe_error"
}
EOF
}

write_final_state() {
  local status="$1"
  local safe_error="${2:-}"
  umask 077
  cat > "$STATE_FILE" <<EOF
{
  "ok": true,
  "status": "$status",
  "browser_running": false,
  "started_at": "${STARTED_AT:-}",
  "expires_at": "${EXPIRES_AT:-}",
  "vnc_host": "$VNC_HOST",
  "vnc_port": $VNC_PORT,
  "mac_ssh_command": "ssh -L 5905:127.0.0.1:5905 root@157.230.237.72",
  "mac_vnc_url": "vnc://localhost:5905",
  "credentials_stored": false,
  "error": "$safe_error"
}
EOF
}

browser_running_json() {
  if [[ -f "$RUNTIME_DIR/browser.pid" ]] && kill -0 "$(cat "$RUNTIME_DIR/browser.pid")" 2>/dev/null; then
    printf 'true'
  else
    printf 'false'
  fi
}

state_value() {
  local key="$1"
  [[ -f "$STATE_FILE" ]] || return 0
  python3 - "$STATE_FILE" "$key" <<'PY'
import json, sys
try:
    data = json.load(open(sys.argv[1], encoding='utf-8'))
    value = data.get(sys.argv[2], '')
    print(value if value is not None else '')
except Exception:
    print('')
PY
}

session_expired() {
  local expires
  expires="$(state_value expires_at)"
  [[ -n "$expires" ]] || return 1
  local expires_epoch
  expires_epoch="$(date -u -d "$expires" +%s 2>/dev/null || printf '0')"
  [[ "$(now_epoch)" -ge "$expires_epoch" ]]
}

kill_pid_file() {
  local file="$1"
  if [[ -f "$file" ]]; then
    local pid
    pid="$(cat "$file" 2>/dev/null || true)"
    if [[ "$pid" =~ ^[0-9]+$ ]] && kill -0 "$pid" 2>/dev/null; then
      kill "$pid" 2>/dev/null || true
      sleep 1
      kill -9 "$pid" 2>/dev/null || true
    fi
    rm -f "$file"
  fi
}

cleanup_processes() {
  kill_pid_file "$RUNTIME_DIR/browser.pid"
  kill_pid_file "$RUNTIME_DIR/x11vnc.pid"
  kill_pid_file "$RUNTIME_DIR/openbox.pid"
  kill_pid_file "$RUNTIME_DIR/xvfb.pid"
  kill_pid_file "$RUNTIME_DIR/lock-holder.pid"
  rm -f "$COMMAND_FILE" "$RESULT_FILE" "$RUNTIME_DIR/browser-ready.json" "$RUNTIME_DIR/browser-error.json" "$RUNTIME_DIR/vnc.pass"
}

restart_worker() {
  systemctl restart "$WORKER_SERVICE" >/dev/null 2>&1 || true
}

stop_worker() {
  systemctl stop "$WORKER_SERVICE" >/dev/null 2>&1 || true
}

active_session_exists() {
  [[ -f "$STATE_FILE" ]] || return 1
  local status
  status="$(state_value status)"
  case "$status" in
    starting|awaiting_admin_login|verifying|failed)
      ! session_expired
      ;;
    *)
      return 1
      ;;
  esac
}

start_lock_holder() {
  (
    flock -x 9
    sleep "$TTL_SECONDS"
  ) 9>"$LOCK_FILE" &
  echo $! > "$RUNTIME_DIR/lock-holder.pid"
}

start_auth_session() {
  require_root
  load_env
  ensure_runtime_dir
  if active_session_exists; then
    cat "$STATE_FILE"
    exit 0
  fi
  cleanup_processes
  preflight_browser_runtime
  STARTED_AT="$(iso_now)"
  EXPIRES_AT="$(date -u -d "@$(( $(now_epoch) + TTL_SECONDS ))" +"%Y-%m-%dT%H:%M:%SZ")"
  VNC_PASSWORD="$(generate_vnc_password)"
  write_state "starting"
  stop_worker
  start_lock_holder
  x11vnc -storepasswd "$VNC_PASSWORD" "$RUNTIME_DIR/vnc.pass" >/dev/null 2>&1
  chown "$SERVICE_USER:$SERVICE_USER" "$RUNTIME_DIR/vnc.pass"
  chmod 600 "$RUNTIME_DIR/vnc.pass"
  runuser -u "$SERVICE_USER" -- env XAUTHORITY="$RUNTIME_DIR/xauth" Xvfb "$DISPLAY_ID" -screen 0 1280x900x24 -nolisten tcp >"$RUNTIME_DIR/xvfb.log" 2>&1 &
  echo $! > "$RUNTIME_DIR/xvfb.pid"
  sleep 1
  runuser -u "$SERVICE_USER" -- env DISPLAY="$DISPLAY_ID" openbox >"$RUNTIME_DIR/openbox.log" 2>&1 &
  echo $! > "$RUNTIME_DIR/openbox.pid"
  sleep 1
  runuser -u "$SERVICE_USER" -- env DISPLAY="$DISPLAY_ID" x11vnc -display "$DISPLAY_ID" -localhost -rfbport "$VNC_PORT" -rfbauth "$RUNTIME_DIR/vnc.pass" -forever -shared -quiet >"$RUNTIME_DIR/x11vnc.log" 2>&1 &
  echo $! > "$RUNTIME_DIR/x11vnc.pid"
  sleep 1
  runuser -u "$SERVICE_USER" -- env \
    DISPLAY="$DISPLAY_ID" \
    GARMIN_BROWSER_PROFILE_DIR="$GARMIN_BROWSER_PROFILE_DIR" \
    GARMIN_PRIVATE_DOWNLOAD_DIR="$GARMIN_PRIVATE_DOWNLOAD_DIR" \
    PLAYWRIGHT_BROWSERS_PATH="$PLAYWRIGHT_BROWSERS_PATH" \
    GARMIN_AUTH_RUNTIME_DIR="$RUNTIME_DIR" \
    "$NODE_BIN" "$SCRIPT_DIR/garmin-auth-browser.js" >"$RUNTIME_DIR/browser.log" 2>&1 &
  echo $! > "$RUNTIME_DIR/browser.pid"
  sleep 2
  write_state "awaiting_admin_login"
  cat "$STATE_FILE"
}

status_session() {
  require_root
  ensure_runtime_dir
  if [[ ! -f "$STATE_FILE" ]]; then
    printf '{"ok":true,"status":"idle","browser_running":false,"vnc_port":%s,"credentials_stored":false}\n' "$VNC_PORT"
    exit 0
  fi
  local status
  status="$(state_value status)"
  if [[ "$status" =~ ^(starting|awaiting_admin_login|verifying|failed)$ ]] && session_expired; then
    STARTED_AT="$(state_value started_at)"
    EXPIRES_AT="$(state_value expires_at)"
    cleanup_processes
    restart_worker
    write_final_state "expired"
  fi
  cat "$STATE_FILE"
}

verify_session() {
  require_root
  load_env
  ensure_runtime_dir
  if ! active_session_exists; then
    status_session
    exit 0
  fi
  if session_expired; then
    STARTED_AT="$(state_value started_at)"
    EXPIRES_AT="$(state_value expires_at)"
    cleanup_processes
    restart_worker
    write_final_state "expired"
    cat "$STATE_FILE"
    exit 0
  fi
  STARTED_AT="$(state_value started_at)"
  EXPIRES_AT="$(state_value expires_at)"
  VNC_PASSWORD="$(state_value vnc_password)"
  write_state "verifying"
  local command_id
  command_id="$(date -u +%s)-$$"
  umask 077
  cat > "$COMMAND_FILE" <<EOF
{"command_id":"$command_id","action":"verify"}
EOF
  chown root:"$SERVICE_USER" "$COMMAND_FILE"
  chmod 640 "$COMMAND_FILE"
  rm -f "$RESULT_FILE"
  for _ in $(seq 1 30); do
    if [[ -f "$RESULT_FILE" ]] && grep -q "\"command_id\": \"$command_id\"" "$RESULT_FILE"; then
      break
    fi
    sleep 1
  done
  if [[ ! -f "$RESULT_FILE" ]]; then
    write_state "failed" "verification_timeout"
    cat "$STATE_FILE"
    exit 0
  fi
  local ok
  ok="$(python3 - "$RESULT_FILE" <<'PY'
import json, sys
try:
    print('1' if json.load(open(sys.argv[1], encoding='utf-8')).get('ok') else '0')
except Exception:
    print('0')
PY
)"
  if [[ "$ok" != "1" ]]; then
    write_state "failed" "verification_failed"
    cat "$RESULT_FILE"
    exit 0
  fi
  cleanup_processes
  restart_worker
  sleep 2
  local worker_response
  worker_response="$(curl -sS -X POST "http://${GARMIN_WORKER_HOST}:${GARMIN_WORKER_PORT}/garmin-worker" \
    -H "Content-Type: application/json" \
    -H "Authorization: Bearer ${GARMIN_WORKER_TOKEN}" \
    -d '{"operation":"test-connection"}' || true)"
  write_final_state "authenticated"
  python3 - "$STATE_FILE" "$RESULT_FILE" "$worker_response" <<'PY'
import json, sys
state = json.load(open(sys.argv[1], encoding='utf-8'))
verify = json.load(open(sys.argv[2], encoding='utf-8'))
try:
    worker = json.loads(sys.argv[3])
except Exception:
    worker = {"ok": False, "status": "sync_error"}
state["verification"] = verify
state["fresh_worker_test"] = {
    "ok": bool(worker.get("ok")),
    "status": worker.get("status"),
    "operation": worker.get("operation"),
}
with open(sys.argv[1], "w", encoding="utf-8") as fh:
    json.dump(state, fh, indent=2)
    fh.write("\n")
PY
  cat "$STATE_FILE"
}

stop_session() {
  require_root
  ensure_runtime_dir
  STARTED_AT="$(state_value started_at)"
  EXPIRES_AT="$(state_value expires_at)"
  cleanup_processes
  restart_worker
  write_final_state "cancelled"
  cat "$STATE_FILE"
}

self_test() {
  command -v openssl >/dev/null 2>&1 || fail "openssl_missing"
  command -v Xvfb >/dev/null 2>&1 || fail "xvfb_missing"
  command -v openbox >/dev/null 2>&1 || fail "openbox_missing"
  command -v x11vnc >/dev/null 2>&1 || fail "x11vnc_missing"
  [[ -x "$NODE_BIN" ]] || fail "node_missing"
  [[ "$RUNTIME_DIR" = "/run/ipca/garmin-auth" ]] || fail "runtime_dir_invalid"
  bash -n "$0" >/dev/null 2>&1 || fail "helper_syntax_invalid"
  local password
  password="$(generate_vnc_password)"
  [[ "${#password}" = "14" ]] || fail "password_generation_failed"
  [[ "$password" =~ ^[0-9a-f]{14}$ ]] || fail "password_charset_failed"
  printf '{"ok":true,"password_generation":"passed","password_length":14,"required_binaries":"passed","helper_syntax":"passed"}\n'
}

case "$ACTION" in
  self-test)
    self_test
    ;;
  start)
    start_auth_session
    ;;
  status)
    status_session
    ;;
  verify)
    verify_session
    ;;
  stop)
    stop_session
    ;;
  *)
    fail "unsupported_action"
    ;;
esac
