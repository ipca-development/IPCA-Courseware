#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

# Read-only E-gle credentials via environment only.
# Prefer a dedicated MySQL user with SELECT-only privileges on ID127947_egl1.
: "${EGLE_DB_HOST:?}"
: "${EGLE_DB_NAME:?}"
: "${EGLE_DB_USER:?}"
: "${EGLE_DB_PASS:?}"
export EGLE_DB_PORT="${EGLE_DB_PORT:-3306}"

php analytics/etl/run_phase3_extract.php
php analytics/etl/run_validation_report.php
php analytics/etl/run_proof_of_value.php
