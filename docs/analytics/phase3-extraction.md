# Phase 3 — Canonical Analytics Extraction

## Architecture

```
Combell E-gle MySQL (READ ONLY SELECT)
        │
        ▼
analytics/adapters via analytics/lib/egle_readonly.php
        │
        ▼
storage/analytics/egle_training_analytics.sqlite   ← separate analytics DB
        │
        ├── docs/analytics/phase3-validation.md
        └── docs/analytics/phase3-proof-of-value.md
```

- Source adapter rejects non-SELECT statements.
- Prefer a dedicated MySQL user with **SELECT-only** privileges on `ID127947_egl1`.
- Analytics SQLite is disposable/rebuildable; E-gle is never modified.

## How to rebuild

```bash
export EGLE_DB_HOST=...
export EGLE_DB_NAME=ID127947_egl1
export EGLE_DB_USER=...   # read-only user
export EGLE_DB_PASS=...
export EGLE_DB_PORT=3306

php analytics/etl/run_phase3_extract.php
php analytics/etl/run_validation_report.php
php analytics/etl/run_proof_of_value.php
```

## Grade mapping (documented)

### Exercise required level
Parsed from `ex_name` markers `(DE)|(EX)|(PR)|(PE)`.

### Exercise achieved grade (`sctr_ex` values)
| Raw | UI column | Competency stage | Ordinal |
|---|---|---|---:|
| R | DE | DE | 1 |
| Y | EX | EX | 2 |
| G | PR | PR | 3 |
| B | PE | PE | 4 |
| D / blank | NO | DEFERRED | null |

`required_level_met` = achieved_ordinal >= required_ordinal (when both known).

### Session grade (`sctr_grading`)
`{R|Y|G|B}{C|I}` → color + completion. Often auto-derived from exercise colors in instructor UI — treat as dependent evidence.

### SRM grades
Same raw `R/Y/G/B`, but UI columns are **EX / PR / MD / NO** (not DE/EX/PR/PE). Do not conflate with exercise stages.

## sctr_next / sctr_alternative

Confirmed in `training_record_instructor.php` / print templates:

- Written from instructor form fields `post_next` / `post_alternative`
- Read as **scenario IDs** (`SELECT * FROM scenarios WHERE sc_id = ...`)
- UI labels: **Next Mission** / **Alternative Mission**
- Sentinel `999999999` = none
- Used in student email/briefing links

Retained raw on `fact_training_session`.

## Identity

`dim_student.source_user_id` vs `canonical_student_id` (currently 1:1).  
`bridge_student_identity` holds **CANDIDATE** groups only — no automatic merges.

## Key outputs

| Path | Purpose |
|---|---|
| `storage/analytics/egle_training_analytics.sqlite` | Analytics DB |
| `docs/analytics/phase3-validation.md` | Validation A–O |
| `docs/analytics/phase3-proof-of-value.md` | Five analyses |
| `docs/analytics/database-discovery.md` | Phase 0–2 discovery |
