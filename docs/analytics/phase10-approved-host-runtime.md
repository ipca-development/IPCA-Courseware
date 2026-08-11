# Phase 10 — Approved-Host Runtime Architecture

**Analysis version:** `phase10-v1` (runtime injection revision)  
**Mode:** Live shadow validation only. Official training state is never written.

## Purpose

Run the competency shadow pipeline where production evidence and runtime secrets are available, without making the system authoritative.

## Root cause (not “missing secrets”)

Production secrets **already exist** on the approved host. They are injected into the **PHP-FPM worker** via:

`/etc/php/8.3/fpm/pool.d/www.conf` (`env[NAME] = …`)

Therefore:

| Process | Sees FPM `env[...]`? |
|---|---|
| PHP web / FPM request | Yes |
| Python CLI (`analytics/.venv/bin/python …`) | **No** — not inherited |

This is a **process-environment / injection** problem. Do not “fix” it by copying plaintext into the repository `.env`, and do not replace the secure PHP-FPM configuration.

## Confirmed FPM variable names (values never logged)

Inspected on approved host (`ipca-courseware-dev`), key names only:

| Logical need | FPM `env[...]` name | Web getenv probe |
|---|---|---|
| OpenAI | `CW_OPENAI_API_KEY` | AVAILABLE |
| OpenAI model | `CW_OPENAI_MODEL` | AVAILABLE |
| DB password | `CW_DB_PASS` | AVAILABLE |
| DB host/name/user/port | `CW_DB_HOST`, `CW_DB_NAME`, `CW_DB_USER`, `CW_DB_PORT` | AVAILABLE |
| ASR model | `CW_OPENAI_ASR_MODEL` | MISSING (ASR uses OpenAI key) |
| `OPENAI_API_KEY` alias | — | MISSING (alias not required; `CW_OPENAI_API_KEY` is canonical) |

Safe web preflight: `public/admin/runtime_secrets_preflight.php` (AVAILABLE/MISSING only).

Existing CLI file `/etc/ipca/ipca-courseware-cli.env` (mode 600) already holds DB + mail tokens but **not** `CW_OPENAI_API_KEY`. Analytics therefore uses a dedicated file:

`/etc/ipca/analytics.env`

## Preferred injection (A) — systemd EnvironmentFile

1. As root, sync allowlisted keys from FPM → analytics env (FPM untouched):

```bash
sudo PHP_FPM_POOL=/etc/php/8.3/fpm/pool.d/www.conf \
  /var/www/ipca/scripts/analytics/sync_analytics_env_from_fpm.sh
```

Creates `/etc/ipca/analytics.env` mode `600`, owner root. Prints only `SYNCED` / `ABSENT_IN_FPM` per key name.

2. Install oneshot unit (example):

`deploy/systemd/ipca-analytics-oneshot.service`

```bash
sudo install -m 644 deploy/systemd/ipca-analytics-oneshot.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl start ipca-analytics-oneshot.service
```

## Preferred injection (B) — existing central CLI env

If ops already maintains `/etc/ipca/ipca-courseware-cli.env`, RuntimeSecrets also loads it. Prefer adding OpenAI to **`/etc/ipca/analytics.env`** (analytics-scoped) rather than expanding unrelated CLI consumers — still never into git.

## Preferred injection (C) — one-off approved-host run

Without writing a second secret file, point RuntimeSecrets at the existing FPM pool (allowlisted keys only; pool is not modified):

```bash
export PHP_FPM_POOL=/etc/php/8.3/fpm/pool.d/www.conf
scripts/analytics/run-with-analytics-env.sh \
  analytics/.venv/bin/python analytics/etl/runtime_preflight.py
scripts/analytics/run-with-analytics-env.sh \
  analytics/.venv/bin/python analytics/etl/phase10_01_live_shadow.py
scripts/analytics/run-with-analytics-env.sh \
  analytics/.venv/bin/python analytics/etl/phase7_05_llm_enrich.py
```

Or create `/etc/ipca/analytics.env` once (approach A), then omit `PHP_FPM_POOL`.

`run-with-analytics-env.sh` sets `IPCA_ANALYTICS_ENV_FILE` and/or `PHP_FPM_POOL`; Python loads secrets itself (no bash `source` of secret values).

## Secret loading order (`RuntimeSecrets`)

Python: `analytics/lib/runtime_secrets.py`  
PHP: `src/RuntimeSecrets.php`

1. **Process environment** (systemd `Environment=` / already-exported vars) — never overwritten by files  
2. **Approved server-side EnvironmentFile(s)**  
   - `$IPCA_ANALYTICS_ENV_FILE` if set  
   - `/etc/ipca/analytics.env`  
   - `/etc/ipca/ipca-courseware-cli.env`  
   - `/etc/ipca/secrets.env`  
3. **Optional PHP-FPM pool allowlist** — if `PHP_FPM_POOL` / `IPCA_FPM_POOL` points at a readable pool file, load only allowlisted analytics keys into this process (Garmin-style). Does not modify FPM.  
4. **Repository `.env`** — **non-secret defaults only** (e.g. `CW_DB_HOST`, model names). Passwords / API keys / tokens are **never** loaded from the repo.

`EV[...]` is always rejected as unusable ciphertext (DigitalOcean App Platform encrypted form). This repo cannot decrypt it.

## Preflight (safe)

```bash
analytics/.venv/bin/python analytics/etl/runtime_preflight.py
```

Example output:

```
OPENAI_API_KEY: AVAILABLE
CW_DB_PASS: AVAILABLE
ASR credentials: AVAILABLE
```

or `MISSING`. Never lengths, prefixes, suffixes, hashes, or values. Exit `0` only when OpenAI + DB are AVAILABLE.

## Isolation rules

| May write | Must NOT write |
|---|---|
| Analytics SQLite shadow/validation tables | Official instructor debrief |
| Explicitly allowed shadow artifact storage | Historical grades / E-gle |
| Workload events for pilot instructors | Mission completion / flight closure |
| Examiner clinic verdicts in analytics | Student progression / scheduling / curriculum |

Operational Session UUID remains the sole flight identity.

## Feature flags

Remain **OFF** during Phase 10 unless separately instructed:

- `competency_pipeline_shadow`
- `competency_instructor_review`
- `competency_student_debrief`
- `competency_recommendations`

## This workspace vs approved host

| Environment | CLI secrets |
|---|---|
| Developer Mac (repo `.env` with `EV[...]`) | MISSING for OpenAI/DB — expected |
| Approved host PHP-FPM web | AVAILABLE via pool `env[...]` |
| Approved host Python CLI **without** injection | MISSING — FPM not inherited |
| Approved host Python CLI with `PHP_FPM_POOL=…` or `/etc/ipca/analytics.env` | AVAILABLE |

Phase 10 live run on approved host (after CLI injection): secrets gate **PASS**; live Operational Sessions ingested (≥50). Official training state untouched.

## Related artifacts

- Pipeline: `analytics/etl/phase10_01_live_shadow.py`
- Preflight: `analytics/etl/runtime_preflight.py`
- FPM→analytics sync: `scripts/analytics/sync_analytics_env_from_fpm.sh`
- CLI wrapper: `scripts/analytics/run-with-analytics-env.sh`
- Systemd example: `deploy/systemd/ipca-analytics-oneshot.service`
- PHP preflight: `public/admin/runtime_secrets_preflight.php`
- Analytics schema: `analytics/schema/phase10_tables.sql`
- Validation report: [`phase10-live-shadow-validation.md`](phase10-live-shadow-validation.md)
