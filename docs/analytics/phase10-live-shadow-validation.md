# Phase 10 — Approved-Host Live Shadow Validation and Limited Instructor-Assist Readiness

**Analysis version:** `phase10-v1`  
**Generated:** 2026-08-11  
**Mode:** Live shadow validation on approved host. Official debriefs, grades, student progression, scheduling, and curriculum decisions remain authoritative and **untouched**.

**Artifacts**
- Runtime architecture: [`phase10-approved-host-runtime.md`](phase10-approved-host-runtime.md)
- Schema (analytics SQLite only): `analytics/schema/phase10_tables.sql`
- Pipeline: `analytics/etl/phase10_01_live_shadow.py`
- Admin: [`public/admin/phase10_live_shadow.php`](../../public/admin/phase10_live_shadow.php)
- Prior schema proposal (not applied): [`phase9-production-schema-proposal.sql`](phase9-production-schema-proposal.sql)

---

## EXECUTIVE VERDICT

**Overall readiness: `NOT_READY`**

Phase 10 established the approved-host live shadow framework and closed the **runtime injection** misunderstanding: secrets were never missing on the server — PHP-FPM had them; Python CLI did not inherit them.

After CLI injection via `PHP_FPM_POOL` allowlisted load:

| Item | Status |
|---|---|
| OpenAI / production DB runtime secrets | **PASS** (CLI preflight AVAILABLE) |
| Live Operational Sessions ingested | **~70–75** (≥50 met; preferred band) |
| Live production exercise markers | **PASS_WITH_CONDITIONS** (ingested; quality gates remain) |
| Live ASR / transcript quality | **INSUFFICIENT_EVIDENCE** |
| Examiner clinic | **FAIL** (0 / 80 completed; none fabricated) |
| Tolerance-pack validation | **FAIL** (PENDING / NEEDS_REVIEW) |
| Real instructor workload | **INSUFFICIENT_EVIDENCE** (n=0) |
| Historical LLM enrichment | **In progress** on approved host (non-blocking) |

**Official training state was not modified. No production schema migration. No student debrief. Competency system is not authoritative.**

Runtime architecture: [`phase10-approved-host-runtime.md`](phase10-approved-host-runtime.md).

---

## 1. Approved-host runtime

See [`phase10-approved-host-runtime.md`](phase10-approved-host-runtime.md).

| Component | Status |
|---|---|
| `OPENAI_API_KEY` (CLI via FPM allowlist / EnvironmentFile) | **OK / AVAILABLE** |
| `CW_DB_PASS` | **OK / AVAILABLE** |
| Live MySQL read-only ingest | **OK** |
| Official training writes | OK — none |
| Feature flags | OK — all OFF |

**Root cause corrected:** secrets were present in PHP-FPM; Python CLI does not inherit FPM env. Fixed via RuntimeSecrets + `PHP_FPM_POOL` allowlisted load / `/etc/ipca/analytics.env` (optional systemd EnvironmentFile). No plaintext in repository `.env`. FPM pool not replaced.

---

## 2. Live cohort description

| Metric | Value |
|---|---:|
| Live Operational Sessions ingested | **~70 unique / 75 attempted** |
| Minimum acceptable | 50 — **MET** |
| Preferred | 75–100 — at lower preferred edge |

**Composition (actual, LIVE_PRODUCTION):**

| Dimension | Value | n |
|---|---|---:|
| aircraft | N397EA | 30 |
| aircraft | N428EA | 22 |
| aircraft | N392EA | 15 |
| aircraft | N446CS | 1 |
| aircraft | (blank) | 2 |
| evidence_completeness | LIMITED_EVIDENCE | 49 |
| evidence_completeness | PARTIAL_EVIDENCE | 21 |
| ingest_mode | LIVE_PRODUCTION | 70 |

---

## 3. Evidence ingestion

**Design:** Operational Session UUID is the sole flight identity. Ingest targets: session, actual-leg linkage, exercise markers, Garmin refs, audio, transcript, context, instructor events — all lineage to Operational Session.

**This run:** production read blocked → no live evidence rows in `phase10_live_cohort`. Shadow artifacts remain in analytics SQLite only.

Isolation held: no writes to official debrief, grades, mission completion, flight closure, progression, curriculum, or scheduling.

---

## 4. Marker / boundary validation

Prior shadow attempts (n=183) used as **non-live** diagnostic rates only:

| Metric | Value | Notes |
|---|---:|---|
| HIGH confidence (conf≥0.75) | 8.2% | Not live-validated |
| MEDIUM | 79.8% | |
| LOW | 12.0% | |
| Manual review rate (any flag) | 33.3% | |
| Incorrect boundary rate (expert) | — | **Requires clinic** |

Failure taxonomy seeded from review flags (missing end, truncation, merge/split, etc.) for live classification once markers arrive. Versioned logic changes only — no silent retuning.

---

## 5. ASR / transcript validation

| Quality class | Live n |
|---|---:|
| MISSING | recorded (no live sessions) |
| GOOD / USABLE / LIMITED / UNUSABLE | 0 measured |

Transcript presence ≠ transcript quality. Speaker / ATC / instructor-student separation and exercise-window alignment are **unmeasured live**.

---

## 6. Objective measurement validation

Local extraction success remains a baseline (~415 metrics historically) and is **not** treated as operational validity. Live rates for missing metrics, incorrect values, boundary-induced error, and aircraft anomalies: **INSUFFICIENT_EVIDENCE**.

---

## 7. Examiner clinic

| Metric | Value |
|---|---:|
| Dual-reviewer worksheets | 80 |
| Completed (genuine human) | **0** |
| Pending | **80** |
| Synthetic verdicts | **0** (forbidden) |

Dimensional capture supported in admin UI: boundary, objective quality, tolerance, procedure, independence, consistency, context, system competency interpretation — with CORRECT / PARTIALLY_CORRECT / INCORRECT / INSUFFICIENT_EVIDENCE + reason codes.

### Inter-rater agreement

| Dimension | Agreement | n_pairs |
|---|---:|---:|
| overall_competency | — | 0 |
| exercise_boundary | — | 0 |
| objective_result | — | 0 |
| independence | — | 0 |
| consistency | — | 0 |
| procedure | — | 0 |

Low human agreement will be reported openly when clinic completes; judgment-dependent dimensions will be labeled as such.

---

## 8. Maneuver-specific verdicts

Blanket `MORE_VALIDATION_REQUIRED` replaced with evidence-based Phase 10 dispositions — currently all **`INSUFFICIENT_EVIDENCE`** (clinic + live cohort incomplete):

| Maneuver | Verdict |
|---|---|
| go_around | INSUFFICIENT_EVIDENCE |
| normal_approach | INSUFFICIENT_EVIDENCE |
| normal_landing | INSUFFICIENT_EVIDENCE |
| power_off_stall | INSUFFICIENT_EVIDENCE |
| power_on_stall | INSUFFICIENT_EVIDENCE |
| slow_flight | INSUFFICIENT_EVIDENCE |
| steep_turn | INSUFFICIENT_EVIDENCE |

No broad exercise expansion in Phase 10. Pilot scope unchanged.

---

## 9. Tolerance-pack disposition

| Pack | Metric | Disposition | Mismatch class |
|---|---|---|---|
| ACS_PPL_ASEL_v1 | altitude_deviation_ft | PENDING_CLINIC | human_interpretation_difference |
| IPCA_TRAINING_PE_v1 | vertical_speed_fpm | NEEDS_REVIEW | wrong_boundary |
| IPCA_TRAINING_PE_v1 | airspeed_deviation_kt | NEEDS_REVIEW | wrong_measurement |
| IPCA_TRAINING_PR_v1 | altitude_deviation_ft | PENDING_CLINIC | wrong_training_level_applicability |

Standards are not tuned merely to match instructor opinion. Changes remain versioned.

---

## 10. Procedure-pack disposition

27 steps classified for live observability (design-level; live confirmation pending). Examples:

| Pack | Step | Observability |
|---|---|---|
| IPCA_SOP_GO_AROUND_v1 | power / pitch / climb | AUTO_PARTIAL |
| IPCA_SOP_GO_AROUND_v1 | after_actions | TRANSCRIPT_SUPPORTED |
| IPCA_SOP_GO_AROUND_v1 | traffic_scan | NOT_OBSERVABLE |
| IPCA_SOP_POWER_OFF_STALL_v1 | coaching_vs_independent | NOT_OBSERVABLE |

**Rule held:** never claim complete SOP compliance when required steps are NOT_OBSERVABLE / INSTRUCTOR_REQUIRED without human confirmation.

---

## 11. Independence workflow

Group-level model retained: ASSISTED | PROMPTED | INDEPENDENT | NOT_OBSERVED (silence ≠ independent).

Live metrics (tap count, time, suggestion change rate, group inadequacy): **n=0**. Intervention events (DEMONSTRATION / PHYSICAL_INTERVENTION / SAFETY_TAKEOVER) remain modeled separately; live utility unmeasured.

---

## 12. Consistency engine

≥3-attempt threshold retained. Within-session vs longitudinal separation retained. Gate: **PASS_WITH_CONDITIONS** (rules stable; examiner agreement on live cases pending). CONSISTENT is not loosened silently.

---

## 13. Longitudinal examples

All patterns `PENDING_LIVE`: stable competency, developing consistency, regression, post-gap softening, contextual transfer, multi-session improvement. System-vs-instructor comparison: **UNCOMPARED**.

---

## 14. Context materiality

Mapped from Phase 9 classifications into Phase 10 classes:

| Class | Count (fields) |
|---|---:|
| DEBRIEF_DEFAULT | 2 |
| DEBRIEF_WHEN_MATERIAL | 6 |
| ANALYTICS_ONLY | 1 |

Live operational materiality still unconfirmed — do not surface weather/env detail merely because it exists.

---

## 15. Instructor workload

| Segment | median / P75 / P90 / min / max | n |
|---|---|---:|
| routine / problematic / high_exercise_count / all | — | **0** |

Phase 8 ~2.5 min remains a **hypothesis**. Design target median &lt;3 minutes is not an acceptance criterion until measured. Admin captures review start→finish, taps, corrections, observations, drill-downs, exceptions.

---

## 16. Exception queue quality

Ratings pending: USEFUL / NEUTRAL / NOISY / WRONG. Priority remains reducing noisy exceptions.

---

## 17. Claim-to-evidence validation

Phase 9 baseline: **366** claim→evidence links. Live supportiveness (fully / partially / unsupported / misleading despite link): **not sampled**. Evidence ID ≠ claim true.

---

## 18. AI unsupported claims

Material unsupported-claim rate (deficiency, independence, improvement, consistency, safety, procedure, focus): **unmeasured live**. Prefer templated verbalization until expert-reviewed rate is acceptable. Freeform AI final debriefs remain forbidden.

---

## 19. Debrief usefulness

Qualitative dimensions pending instructor/examiner ratings: accuracy, clarity, usefulness, prioritization, tone, evidence support, actionability, and “Would this have helped you debrief this student?”

Supportive + precise language (Phase 5B): deficiencies clear, not exaggerated, not buried in praise; progress recognized; encouraging tone ≠ competence — **not yet live-rated**.

---

## 20. Recommendation agreement

Shadow-only recommendations. Classifications AGREE / PARTIAL / DISAGREE / NOT_APPLICABLE: **PENDING**. No automatic assign/reschedule.

---

## 21. Early-warning usefulness

Patterns retained (consistency concern, repeated deficiency window, high-grade + deficiency, post-gap softening). Live lead-time / false-warning / usefulness: **PENDING_LIVE**. No opaque risk score.

---

## 22. Degraded-mode behavior

| Case | Observed live | Result |
|---|---:|---|
| audio_missing | design | PASS_DESIGN → PARTIAL/LIMITED |
| marker_incomplete | design | PASS_DESIGN → queue + INSUFFICIENT_EVIDENCE preference |
| independence_not_entered | design | PASS → NOT_OBSERVED |
| duplicate_sync | design | PASS_DESIGN → idempotency_key |
| garmin_late / missing, transcript_late, partial upload, offline, repaired | 0 | UNOBSERVED |

No silent confidence inflation by design. Shadow recomputation versioning designed for late telemetry→transcript→instructor confirmation.

---

## 23. Historical LLM reconciliation

| Status | hashes done | remaining |
|---|---:|---:|
| BLOCKED | 277 | 10,159 |

Non-blocking for live flight processing by design. Historical priors only; must not drive student decisions.

---

## 24. Production schema changes

Review of [`phase9-production-schema-proposal.sql`](phase9-production-schema-proposal.sql) against Phase 10 findings:

| Entity / column | Disposition |
|---|---|
| Core attempt / measurement / independence / intervention / assessment / debrief / claim / evidence / state history tables | **KEEP** |
| `assessment.evidence_state_machine` | **CHANGE** (explicit SESSION_OPEN…FINALIZED / version links) |
| `transcript_quality_class` | **CHANGE** (GOOD/USABLE/LIMITED/UNUSABLE/MISSING) |
| `boundary_failure_class` | **CHANGE** (live failure taxonomy) |
| Population instructor calibration | **ANALYTICS_ONLY** |
| Opaque risk score / auto mission reschedule | **DROP** |

**Migration recommendation: NO MIGRATION.** All migration prerequisites unmet (see §25 / migration gate table).

---

## 25. Exit-gate matrix

| Gate | Status |
|---|---|
| A. Approved-host secrets | **PASS** |
| B. Live marker integration | **PASS_WITH_CONDITIONS** |
| C. Live transcript integration | **INSUFFICIENT_EVIDENCE** |
| D. ≥50-flight shadow cohort | **PASS** |
| E. Exercise-boundary reliability | **INSUFFICIENT_EVIDENCE** |
| F. Objective metric reliability | **INSUFFICIENT_EVIDENCE** |
| G. Examiner clinic completion | **FAIL** |
| H. Tolerance validation | **FAIL** |
| I. Procedure validation | **INSUFFICIENT_EVIDENCE** |
| J. Independence workflow | **INSUFFICIENT_EVIDENCE** |
| K. Consistency engine | **PASS_WITH_CONDITIONS** |
| L. Claim-to-evidence reliability | **INSUFFICIENT_EVIDENCE** |
| M. AI unsupported-claim rate | **INSUFFICIENT_EVIDENCE** |
| N. Instructor workload | **INSUFFICIENT_EVIDENCE** |
| O. Debrief usefulness | **INSUFFICIENT_EVIDENCE** |
| P. Degraded-mode safety | **PASS_WITH_CONDITIONS** |
| Q. Production schema readiness | **PASS_WITH_CONDITIONS** (review only; no migrate) |

**Migration gate checklist (all unmet):** live cohort ≥50; clinic complete; ≥1 maneuver VALIDATED_FOR_INSTRUCTOR_ASSIST; tolerances accepted; workload measured; claim support acceptable; degraded mode safe; schema updated from live findings.

---

## 26. Recommendation for Phase 11

Do **not** enable instructor-assist or student debrief. Do **not** migrate production schema.

**Phase 11 should only begin after Phase 10 is re-run on an approved host that closes secrets + live ingest**, then:

1. Accumulate ≥50 (prefer 75–100) live Operational Sessions naturally.
2. Complete the 80 dual-reviewer clinic worksheets with genuine humans.
3. Produce maneuver-level VALIDATED_* / NEEDS_REVISION / NOT_SUITABLE dispositions.
4. Measure workload (median/P75/P90) and exception SNR.
5. Sample live claim support and unsupported AI claim rate.
6. Only then consider limited flags:  
   `competency_pipeline_shadow=ON`, `competency_instructor_review=ON` (selected instructors), `competency_student_debrief=OFF`, `competency_recommendations=SHADOW_ONLY`.

**Pilot instructor selection criteria (for later):** experienced, standardized, feedback-willing, representative of normal operations — not only developers or enthusiasts.

---

## Limited instructor-assist feature-flag plan (not enabled)

| Flag | Phase 10 state | Post-gate intended |
|---|---|---|
| competency_pipeline_shadow | OFF | ON after gates |
| competency_instructor_review | OFF | ON for selected instructors |
| competency_student_debrief | OFF | OFF |
| competency_recommendations | OFF | SHADOW_ONLY |

---

## Core principle check

| Preference | Held? |
|---|---|
| Find where it does **not** work | Yes — gates FAIL/BLOCKED honestly |
| INSUFFICIENT_EVIDENCE over unsupported certainty | Yes |
| Human correction over automated overreach | Yes (clinic pending; no synthetic verdicts) |
| Narrower reliable system over broad impressive one | Yes (no exercise expansion) |

**Exact overall verdict: `NOT_READY`**
