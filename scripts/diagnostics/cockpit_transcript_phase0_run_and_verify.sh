#!/usr/bin/env bash
# Run on App Platform console (has audio, OpenAI key, and DB):
#   bash scripts/diagnostics/cockpit_transcript_phase0_run_and_verify.sh
set -euo pipefail
cd "$(dirname "$0")/../.."

RECORDING_ID="${1:-552}"
CHUNK="${2:-0}"

echo "=== Phase 0 probe with typed persistence (recording=${RECORDING_ID}, chunk=${CHUNK}) ==="
PROBE_JSON="$(php scripts/diagnostics/cockpit_transcript_phase0_provider_probe.php \
  --recording-id="${RECORDING_ID}" \
  --probe-chunk="${CHUNK}" \
  --persist=1)"
echo "${PROBE_JSON}"

UUID="$(echo "${PROBE_JSON}" | php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["probe_execution_uuid"] ?? ($j["persistence"]["probe_execution_uuid"] ?? "");')"
if [[ -z "${UUID}" ]]; then
  echo "ERROR: probe_execution_uuid not found in probe output" >&2
  exit 1
fi

echo ""
echo "=== Persistence verification (${UUID}) ==="
php scripts/diagnostics/cockpit_transcript_phase0_provider_probe.php --verify-persistence="${UUID}"

echo ""
echo "=== Full schema + persistence verification ==="
php scripts/diagnostics/cockpit_transcript_phase0_verify_all.php --probe-execution-uuid="${UUID}"
