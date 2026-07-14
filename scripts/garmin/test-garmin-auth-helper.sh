#!/usr/bin/env bash
set -euo pipefail

if ! command -v openssl >/dev/null 2>&1; then
  printf '{"ok":false,"password_generation":"failed","error":"openssl_missing"}\n'
  exit 1
fi

password="$(openssl rand -hex 16)"
password="${password:0:14}"

if [[ "${#password}" != "14" ]]; then
  printf '{"ok":false,"password_generation":"failed","error":"password_length_invalid"}\n'
  exit 1
fi

if [[ ! "$password" =~ ^[0-9a-f]{14}$ ]]; then
  printf '{"ok":false,"password_generation":"failed","error":"password_charset_invalid"}\n'
  exit 1
fi

printf '{"ok":true,"password_generation":"passed","password_length":14}\n'

if id ipca-garmin >/dev/null 2>&1; then
  tmp_runtime="$(mktemp -d)"
  cleanup() {
    rm -rf "$tmp_runtime"
  }
  trap cleanup EXIT

  chgrp ipca-garmin "$tmp_runtime"
  chmod 0770 "$tmp_runtime"

  runuser -u ipca-garmin -- env TEST_RUNTIME_DIR="$tmp_runtime" python3 - <<'PY'
import json
import os
import pathlib

runtime = pathlib.Path(os.environ["TEST_RUNTIME_DIR"])
for name in ("browser-error.json", "verify-result.json", "browser-ready.json"):
    (runtime / name).write_text(json.dumps({"ok": True}) + "\n", encoding="utf-8")
PY

  if id nobody >/dev/null 2>&1; then
    if runuser -u nobody -- test -r "$tmp_runtime/browser-error.json" 2>/dev/null; then
      printf '{"ok":false,"runtime_permissions":"failed","error":"unrelated_user_can_read_browser_file"}\n'
      exit 1
    fi
    if runuser -u nobody -- test -x "$tmp_runtime" 2>/dev/null; then
      printf '{"ok":false,"runtime_permissions":"failed","error":"unrelated_user_can_access_runtime_dir"}\n'
      exit 1
    fi
  fi

  printf '{"ok":true,"runtime_permissions":"passed","runtime_mode":"0770","browser_file_writes":"passed"}\n'
else
  printf '{"ok":true,"runtime_permissions":"skipped","reason":"ipca-garmin user not present"}\n'
fi
