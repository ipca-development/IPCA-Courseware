# Phase 10C — Production Schema Delta (NO MIGRATION)

**Analysis version:** `phase10c-v1`  
**Rule:** Review only. Do **not** apply production migrations during Phase 10C.

Revisits [`phase9-production-schema-proposal.sql`](phase9-production-schema-proposal.sql) against live cohort + clinic tooling needs.

## Disposition summary

| Entity / column | Disposition | Notes |
|---|---|---|
| `ipca_exercise_attempts` | **KEEP** | Operational Session lineage + `idempotency_key` |
| `ipca_objective_measurements` | **KEEP** | Tie to assessment version / evidence cutoff |
| `ipca_independence_observations` | **KEEP** | Group-level ASSISTED/PROMPTED/INDEPENDENT/NOT_OBSERVED |
| `ipca_instructor_interventions` | **KEEP** | Separate from independence |
| `ipca_competency_assessments` | **KEEP** | Versioned V1/V2/V3 — never mutate silently |
| `ipca_competency_assessments.evidence_cutoff_at` | **CHANGE** | Explicit cutoff timestamp per version |
| `ipca_competency_assessments.evidence_state` | **CHANGE** | SESSION_OPEN…FINALIZED machine |
| `ipca_debriefs` / claims / claim_evidence | **KEEP** | Claim support validation requires durable links |
| `ipca_debrief_claims.support_class` | **CHANGE** | FULLY_SUPPORTED / PARTIAL / UNSUPPORTED / MISLEADING (human) |
| `transcript_quality_class` on session/attempt | **CHANGE** | GOOD/USABLE/LIMITED/UNUSABLE/MISSING + PRESENT vs USEFUL |
| `boundary_failure_class` | **CHANGE** | Live taxonomy from Phase 10 |
| `examiner_dimensional_review` | **CHANGE** | Persist boundary/objective/tolerance/procedure/independence/consistency/overall separately |
| `instructor_workload_event` | **KEEP** (or ANALYTICS_ONLY initially) | Open/finish/taps/reasons |
| `exception_snr_rating` | **ANALYTICS_ONLY** until assist pilot | USEFUL/NEUTRAL/NOISY/WRONG |
| Population calibration / risk score | **DROP** | Forbidden opaque score; calibration analytics-only if ever |
| Auto mission reschedule | **DROP** | Forbidden |
| Holding / unvalidated maneuvers | **ANALYTICS_ONLY** | Out of Phase 10C pilot scope |

## Live findings driving CHANGE

1. Late evidence arrival requires immutable assessment versions + cutoffs.
2. Transcript **presence ≠ usefulness** — schema must store both.
3. Clinic needs dimensional fields, not overall-only verdicts.
4. Workload and exception SNR are operational readiness inputs — capture early even if ANALYTICS_ONLY.

## Migration stance

**NO PRODUCTION MIGRATION** until a later phase explicitly authorizes it after `READY_FOR_PRODUCTION_MIGRATION_PREP` (not claimed in Phase 10C).
