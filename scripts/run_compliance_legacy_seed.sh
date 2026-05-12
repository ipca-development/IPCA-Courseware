#!/usr/bin/env bash
# Imports legacy compliance dump (findings, RCA, CAPs, MCCF links, AI runs) with user IDs / approver names cleared.
# Pass --no-ai to skip ai_finding_runs. See scripts/compliance_import_legacy_tableplus_dump.php
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DUMP="${ROOT}/scripts/sql/seeds/legacy_compliance_tableplus_dump.sql"
if [[ ! -r "$DUMP" ]]; then
  echo "Missing seed dump: $DUMP" >&2
  exit 1
fi
exec php "${ROOT}/scripts/compliance_import_legacy_tableplus_dump.php" "$DUMP" "$@"
