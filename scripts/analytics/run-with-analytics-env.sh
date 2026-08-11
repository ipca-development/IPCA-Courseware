#!/usr/bin/env bash
# Run an analytics CLI command with approved server-side EnvironmentFile configured.
#
# Does NOT read repository .env for secrets.
# Does NOT source the env file into bash (avoids shell metacharacter hazards).
# Python/PHP RuntimeSecrets load /etc/ipca/analytics.env themselves.
#
# Usage:
#   scripts/analytics/run-with-analytics-env.sh analytics/.venv/bin/python analytics/etl/runtime_preflight.py
#   scripts/analytics/run-with-analytics-env.sh analytics/.venv/bin/python analytics/etl/phase10_01_live_shadow.py
set -euo pipefail

ENV_FILE="${IPCA_ANALYTICS_ENV_FILE:-/etc/ipca/analytics.env}"
FALLBACK="/etc/ipca/ipca-courseware-cli.env"
FPM_POOL="${IPCA_FPM_POOL:-${PHP_FPM_POOL:-/etc/php/8.3/fpm/pool.d/www.conf}}"

if [[ $# -lt 1 ]]; then
  echo "usage: $0 <command> [args...]" >&2
  exit 2
fi

have_source=0
if [[ -f "$ENV_FILE" ]]; then
  export IPCA_ANALYTICS_ENV_FILE="$ENV_FILE"
  have_source=1
elif [[ -f "$FALLBACK" ]]; then
  echo "note: $ENV_FILE missing; RuntimeSecrets will also try $FALLBACK" >&2
  export IPCA_ANALYTICS_ENV_FILE="$FALLBACK"
  have_source=1
fi

# Prefer EnvironmentFile when present; otherwise allow reading allowlisted
# keys from the existing PHP-FPM pool (does not modify the pool).
if [[ -r "$FPM_POOL" ]]; then
  export PHP_FPM_POOL="$FPM_POOL"
  export IPCA_FPM_POOL="$FPM_POOL"
  have_source=1
fi

if [[ "$have_source" -ne 1 ]]; then
  echo "No approved analytics secret source found." >&2
  echo "Options:" >&2
  echo "  1) sudo scripts/analytics/sync_analytics_env_from_fpm.sh  # creates /etc/ipca/analytics.env" >&2
  echo "  2) export PHP_FPM_POOL=/etc/php/8.3/fpm/pool.d/www.conf   # allowlisted in-process load" >&2
  echo "PHP-FPM pool secrets are not inherited by CLI processes automatically." >&2
  exit 1
fi

exec "$@"
