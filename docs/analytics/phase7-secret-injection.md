# Phase 7 secret injection (OpenAI)

## What `EV[...]` is

Values in `.env` shaped like `EV[1:…:…]` are **DigitalOcean App Platform SECRET ciphertext**.

This repository **does not** decrypt them. Application code (`src/openai.php` → `cw_openai_key()`, analytics ETL) only reads `getenv('CW_OPENAI_API_KEY')` and expects a usable OpenAI key (`sk-…`).

On App Platform, DigitalOcean injects **plaintext** secrets into the process environment at runtime. Locally, exported `EV[...]` strings are opaque.

## Required injection (do not commit plaintext)

```bash
# From DO App Platform runtime, Control Panel secret value, or a local secret store:
export CW_OPENAI_API_KEY='sk-…'   # never commit; never paste into docs/fixtures/logs
export CW_OPENAI_MODEL='gpt-4.1-mini'  # optional

cd /path/to/IPCA-Courseware
analytics/.venv/bin/python analytics/etl/phase7_05_llm_enrich.py
```

Cache key remains: `text_hash|prompt_version|model|schema_version` under `tmp/analytics/phase7_llm_cache/`.

Scope: **remaining Phase 6 targeted high-value hashes only** (not full 21.7k).

## Status table

Runtime status is recorded in analytics SQLite: `phase7_secret_injection_status`.
