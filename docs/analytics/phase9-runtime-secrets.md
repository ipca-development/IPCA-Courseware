# Phase 9 — Runtime Secrets (approved-host injection)

**Analysis version:** `phase9-v1`  
**Rule:** Never commit, log, SQLite-store, or render secret values.

## What this repository does

Shared loaders (Phase 8):

| Language | Module | API |
|---|---|---|
| Python | `analytics/lib/runtime_secrets.py` | `get_runtime_secret("OPENAI_API_KEY")` |
| PHP | `src/RuntimeSecrets.php` | `RuntimeSecrets::get('OPENAI_API_KEY')` |

Behavior:

- Reads process environment only (plus optional approved CLI env files).
- **Rejects** DigitalOcean `EV[...]` ciphertext (cannot decrypt in-repo).
- Fails clearly if plaintext is missing.
- `peek_secret_status()` / `RuntimeSecrets::peekStatus()` return **shapes only** (`missing`, `ev_ciphertext`, `openai_sk`, `present_len_N`).

## Approved injection mechanisms

This platform’s convention (same as `CompliancePostmarkConfig` / `cw_db()`):

1. **DigitalOcean App Platform** — secrets configured as SECRETs; DO injects **plaintext** into the running app/job environment at runtime. Exported app specs may show `EV[...]`; those exports are not usable locally.
2. **PHP-FPM pool** — e.g. `env[CW_OPENAI_API_KEY] = …` in `/etc/php/*/fpm/pool.d/www.conf` (web/FPM requests only). **Not inherited by Python CLI.**
3. **CLI EnvironmentFile (preferred for analytics)** — `/etc/ipca/analytics.env` (mode 600), generated with `scripts/analytics/sync_analytics_env_from_fpm.sh` or maintained by ops. Optional fallback: `/etc/ipca/ipca-courseware-cli.env`.
4. **One-off CLI** — `export PHP_FPM_POOL=/etc/php/8.3/fpm/pool.d/www.conf` then `scripts/analytics/run-with-analytics-env.sh …` so RuntimeSecrets loads allowlisted keys in-process (Garmin-style). Does not modify FPM.

See [`phase10-approved-host-runtime.md`](phase10-approved-host-runtime.md) for the full Phase 10 runtime architecture.

**Do not** copy plaintext production secrets into repository `.env`.  
**Do not** treat CLI `MISSING` as absent production secrets when FPM web shows `AVAILABLE`.

## Required logical secrets for Phase 9 shadow pilot

| Logical name | Aliases | Purpose |
|---|---|---|
| `OPENAI_API_KEY` | `CW_OPENAI_API_KEY`, `OPENAI_API_KEY` | Targeted historical LLM enrichment; advisory AI verbalization |
| `CW_DB_PASS` | `CW_DB_PASS` | Production **read** MySQL for markers/recordings/transcripts (with `CW_DB_HOST/NAME/USER/PORT`) |
| ASR (if separate) | existing evidence platform env (`CW_OPENAI_ASR_MODEL`, etc.) | Transcript jobs — same OpenAI key often reused |

Also required (non-secret): `CW_DB_HOST`, `CW_DB_PORT`, `CW_DB_NAME`, `CW_DB_USER`.

## Operator checklist (approved host)

```bash
# On the approved host only — values come from DO/Control Panel/secret store
# Verify shapes without printing secrets:
php -r 'require "src/RuntimeSecrets.php"; var_export(RuntimeSecrets::peekStatus("OPENAI_API_KEY"));'
analytics/.venv/bin/python -c "from analytics.lib.runtime_secrets import peek_secret_status; print(peek_secret_status('OPENAI_API_KEY'))"

# Then run enrichment / shadow ingest
analytics/.venv/bin/python analytics/etl/phase7_05_llm_enrich.py
analytics/.venv/bin/python analytics/etl/phase9_01_shadow_pipeline.py
```

## Explicit prohibitions

- No plaintext in `.env` committed to Git (local `.env` may hold `EV[...]` exports — treat as opaque).
- No plaintext in analytics SQLite.
- No secrets in Phase 9 reports, admin UI, fixtures, or debug bundles.
- No repository “decrypt EV” helper.

## Phase 9 local status (this workspace)

At Phase 9 authoring time on the developer Mac workspace:

- `OPENAI_API_KEY`: **not usable** (missing / EV-only in `.env`)
- `CW_DB_PASS`: **not usable** (EV-only in `.env`)
- `/etc/ipca/ipca-courseware-cli.env`: **absent**

Therefore live production shadow ingest and full LLM enrichment remain **BLOCKED** until run on an approved host with injected plaintext.
