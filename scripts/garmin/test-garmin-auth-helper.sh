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
