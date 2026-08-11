# Phase 9 — Shadow Production Pilot, Examiner Acceptance, and Production Readiness Gates

**Analysis version:** `phase9-v1`  
**Generated:** 2026-08-11  
**Mode:** Controlled **SHADOW** pilot — official debrief, grades, scheduling, progression, check eligibility, and E-gle records remain authoritative and untouched.

**Artifacts**
- Runtime secrets: [`docs/analytics/phase9-runtime-secrets.md`](phase9-runtime-secrets.md)
- Schema: `analytics/schema/phase9_tables.sql`
- Pipeline: `analytics/etl/phase9_01_shadow_pipeline.py`
- Production schema proposal (not applied): [`docs/analytics/phase9-production-schema-proposal.sql`](phase9-production-schema-proposal.sql)
- Admin: [`public/admin/phase9_shadow_pilot.php`](../../public/admin/phase9_shadow_pilot.php)

---

## EXECUTIVE VERDICT

**NOT READY for authoritative production use.**  
**READY as a shadow-framework on an approved host once secrets are injected and examiner clinic is completed.**

What Phase 9 delivered:

1. Documented approved-host secret injection (no weakening of secret handling).
2. Shadow pipeline with incremental evidence state machine + **assessment versioning**.
3. Local simulation cohort: **19 sessions / 183 attempts / 183 versioned assessments / 366 evidence-linked claims**.
4. Boundary source stats + **71-item** low-confidence/overlap/duration review queue.
5. Examiner clinic tooling preserved (**80 PENDING** dual reviews — **not skipped, not fabricated**).
6. Maneuver dispositions all `MORE_VALIDATION_REQUIRED` until humans sign off.
7. Claim→evidence linking; instructor correction model; role visibility; feature-flag plan (**all flags OFF**).
8. Production schema proposal without migrations.
9. Honest readiness gates: several **BLOCKED/FAIL/INSUFFICIENT_EVIDENCE**.

What blocked a true 50–100 flight live shadow:

| Blocker | Status |
|---|---|
| OpenAI runtime secret | **BLOCKED** (no plaintext on this workspace) |
| Production DB secret | **BLOCKED** |
| Live `exercise_marker` / ASR pull | **BLOCKED** (depends on DB) |
| Human examiner clinic completion | **0 / 80** completed |
| Live instructor workload timings | **n=0** |

**Core rule held:** optimize for correct evidence, clear uncertainty, minimal burden, traceability, professional judgment — not impressive AI output. Freeform AI final debriefs remain forbidden.

---

## 1. Runtime integration

See [`phase9-runtime-secrets.md`](phase9-runtime-secrets.md).

Injection paths: DigitalOcean App Platform SECRETs → plaintext env; PHP-FPM pool; `/etc/ipca/ipca-courseware-cli.env`.

Loaders: `get_runtime_secret()` / `RuntimeSecrets::get()` reject `EV[...]`.

This workspace: OpenAI **unusable**, DB pass **unusable**.

---

## 2. Final historical LLM enrichment

**Did not complete** — secret blocked.

Phase 9 copied prior Phase 7/8 reconciliation into `phase9_llm_final_findings` as **historical priors only**:

| Finding | combined | n |
|---|---:|---:|
| VARIABLE consistency → later problem | 53.8% | 2372 |
| High-grade + deficiency → later problem | 52.9% | 2528 |
| ≥3/5 deficiency window → later problem | 58.8% | 2299 |
| Deficiency rate (blended) | 33.3% | 10436 |
| LLM-only deficiency (subset) | 89.2% | 277 |

**These must NOT automatically drive production student decisions.**

After approved-host injection, re-run `phase7_05_llm_enrich.py` then refresh findings.

---

## 3. Shadow cohort

| Metric | Value |
|---|---:|
| Target | 50–100 live flights |
| Achieved this run | **19** (`LOCAL_SIMULATION`) |
| Attempts | 183 |
| Aircraft in local set | includes N397EA reference + vault-derived |
| Students/instructors linked | not available locally |
| Official process untouched | **Yes** (`official_process_untouched=1`) |

Gate `shadow_cohort_volume`: **FAIL** until ≥50 live production sessions on approved host.

---

## 4. Marker / boundary reliability

Idempotent attempts via `idempotency_key`.

Stored per attempt: `start_boundary_source`, `end_boundary_source`, `boundary_confidence`.

End-boundary distribution (local simulation) recorded in `phase9_boundary_source_stats` (primarily `NEXT_MARKER_OR_ATTEMPT` / telemetry completion variants).

Review queue flags: `LOW_CONFIDENCE_BOUNDARY`, `IMPLAUSIBLY_SHORT`, `IMPLAUSIBLY_LONG`, `OVERLAPPING_ATTEMPTS` — **71** queued.

Policy: prefer **INSUFFICIENT_EVIDENCE** over confident analysis of a bad window.

Live instructor markers: **not connected** this run (DB blocked).

---

## 5. Audio / transcript reliability

Evidence state machine advances:

`SESSION_OPEN → MARKERS_AVAILABLE → GARMIN_AVAILABLE → AUDIO_PENDING → TRANSCRIPT_PENDING → ASSESSMENT_READY`

Local cohort: audio/transcript remain pending; assessments still generated as **PARTIAL_EVIDENCE**.

Incremental enrichment design: late transcript → **new `assessment_version`**, never silent overwrite.

---

## 6. Objective measurement validation

Measurements reused from Phase 7 pilot metrics attached to shadow assessments.

Examiner acceptance: **INSUFFICIENT_EVIDENCE** (clinic incomplete).

Validation distinction encoded in process:

- MEASUREMENT CORRECT ≠ ASSESSMENT APPROPRIATE  

---

## 7. Tolerance-pack validation

Release candidates: **`NOT_CREATED`**.

No silent overwrite of `ACS_PPL_ASEL_v1` / training packs.

Gate `D_tolerance_packs_validated`: **FAIL**.

---

## 8. Procedure-pack validation

Phase 8 SOP packs remain the pilot packs. Live observability matrix incomplete without transcript.

Reporting language allowed: `SUPPORTED | PARTIALLY_SUPPORTED | INSUFFICIENT_EVIDENCE` — never “Procedure compliant” when critical steps unobservable.

Gate G: **INSUFFICIENT_EVIDENCE**.

---

## 9. Independence workflow

Group-level ASSISTED / PROMPTED / INDEPENDENT retained as production candidate.

Defaults remain **NOT_OBSERVED**.

`SYSTEM_SUGGESTED_INDEPENDENCE` stored separately from confirmation.

Live change-rate / insufficiency of group-level input: **not measured** (no instructor pilot timings).

---

## 10. Consistency validation

≥3-attempt rule retained. Historical priors still support consistency as useful.

Live instructor/examiner agreement on consistency: pending clinic.

No exercise-specific arbitrary windows introduced.

---

## 11. Context usefulness

Classified in `phase9_context_field_class`:

| Field | Class |
|---|---|
| training_gap_days, aircraft_ident | DISPLAY_BY_DEFAULT |
| crosswind, DA, wind, turbulence, day/night, airport | DISPLAY_WHEN_MATERIAL |
| oat_c | ANALYTICS_ONLY |

Avoid cluttering every steep-turn debrief with irrelevant OAT.

---

## 12. Examiner clinic

| Metric | Value |
|---|---:|
| Dual-reviewer worksheets | 80 |
| Completed | **0** |
| Pending | **80** |

UI: `phase9_shadow_pilot.php?view=clinic` (+ Phase 8 clinic view).

**Gate not skipped.** No fabricated CORRECT/INCORRECT verdicts.

---

## 13. Inter-rater agreement

`phase9_inter_rater`: agreement **null**, n_pairs=0 — deferred until overlapping human verdicts exist.

---

## 14. AI intervention detection

Still transcript-dependent. Local simulation: no live detection performance metrics.

Conservative policy retained (false positives worse than misses for instructor annoyance).

---

## 15. Claim-to-evidence accuracy

**366** `shadow_debrief_claim` rows with supporting evidence IDs.

Material statements come from structured assessment objects (objective metrics, independence defaults) — **not** freeform flight-wide AI narrative.

---

## 16. Instructor corrections

Model ready: store system assessment + correction + reason (`MISSING_CONTEXT`, `SYSTEM_MISINTERPRETATION`, `INCORRECT_BOUNDARY`, `OBJECTIVE_DATA_MISLEADING`, `HUMAN_JUDGMENT`, `OTHER`) + final human-confirmed.

No automatic rule/tolerance self-modification from corrections — calibration recommendations only.

---

## 17. Instructor workload

| Metric | median | P75 | P90 | n |
|---|---:|---:|---:|---:|
| review_to_approval_minutes | — | — | — | **0** |
| taps_per_flight | — | — | — | **0** |

Instrumentation exists (`shadow_workload_event` on shadow review save).

Phase 8 design estimate (~2.5 min) remains **unvalidated**. Target median &lt;3 min is aspirational until live pilot instructors use the UI.

---

## 18. Student debrief usefulness

Feature flag `competency_student_debrief` intended initial state: **OFF**.

Shadow assessments are **not** auto-sent to students.

Student-facing structure remains: WHAT YOU DEMONSTRATED / WHAT THE DATA SHOWED / WHAT IS STILL DEVELOPING / WHAT TO FOCUS ON NEXT — strengths and deficiencies both explicit.

---

## 19. Next-training recommendation agreement

All shadow sessions classified `PENDING` in `phase9_recommendation_agreement`.

Hierarchy locked:

competency evidence → recommend focus → curriculum engine constrains → instructor decides  

No auto mission assignment.

---

## 20. Degraded-mode tests

Documented in `phase9_degraded_mode_test`: missing/late Garmin/audio/transcript, incorrect marker, duplicate sync, offline session, etc.

Results mostly **PASS** by design; `multiple_actual_legs` **DEGRADED** in local simulation (needs live leg linkage).

Flight closure remains separate — analytics must not block OFR closure.

---

## 21. Production schema proposal

See [`phase9-production-schema-proposal.sql`](phase9-production-schema-proposal.sql).

Proposed entities: `ipca_exercise_attempts`, objective measurements, independence observations, interventions, versioned competency assessments, debriefs, debrief claims + evidence links, competency state history.

Prefer extending `ipca_flight_exercise_catalog` for canonical IDs rather than duplicating.

**No migrations applied.**

Entity classification in `phase9_entity_classification` (PRODUCTION_SOURCE_OF_TRUTH / DERIVED / ANALYTICS_ONLY / CACHE / HISTORICAL_IMPORT_ONLY).

---

## 22. Feature-flag rollout plan

| Flag | Intended initial state |
|---|---|
| `competency_pipeline_shadow` | SHADOW |
| `competency_instructor_review` | OFF → PILOT_USERS after clinic |
| `competency_student_debrief` | OFF |
| `competency_recommendations` | OFF |

**Phase 9 does not enable flags in production.**

---

## 23. Readiness gates

| Gate | Status |
|---|---|
| A marker integration reliable | INSUFFICIENT_EVIDENCE |
| B evidence synchronization reliable | PASS_WITH_CONDITIONS |
| C objective measurements examiner-acceptable | INSUFFICIENT_EVIDENCE |
| D tolerance packs validated | **FAIL** |
| E independence workflow acceptable | INSUFFICIENT_EVIDENCE |
| F consistency useful | PASS_WITH_CONDITIONS |
| G procedure assessment defensible | INSUFFICIENT_EVIDENCE |
| H AI unsupported claim rate acceptable | PASS_WITH_CONDITIONS |
| I instructor median workload acceptable | INSUFFICIENT_EVIDENCE |
| J degraded-mode safe | **PASS** |
| K debrief educationally useful | INSUFFICIENT_EVIDENCE |
| L production data model approved | PASS_WITH_CONDITIONS |
| secret_openai | **BLOCKED** |
| secret_db | **BLOCKED** |
| shadow_cohort_volume (≥50 live) | **FAIL** |
| official_process_untouched | **PASS** |

**Exit criteria for Phase 9 success are not met** on this workspace run. Framework is ready for approved-host continuation.

---

## 24. Recommended Phase 10

1. Run on **approved host** with RuntimeSecrets plaintext injection.  
2. Complete targeted LLM enrichment; freeze historical priors.  
3. Ingest **≥50–100** live Operational Sessions (marker-authoritative, async evidence).  
4. Complete **80** dual examiner reviews; set maneuver dispositions; create tolerance RCs only with approval.  
5. Pilot instructor review flag for small cohort; measure median/P75/P90 workload.  
6. Only after gates A–L are PASS / PASS_WITH_CONDITIONS: apply production migrations behind `competency_pipeline_shadow`, keep student debrief OFF.  
7. Phase 10 = limited authoritative instructor-assist rollout — still no opaque scores, still no auto progression.

---

## Success condition check

| Requirement | Status |
|---|---|
| Auto-run on normal production flights in shadow | **Not yet** (local simulation only) |
| Evidence-backed debrief | Yes (structured claims) |
| Accepted/easily corrected by instructors | Untested live |
| Survives examiner scrutiny | Clinic incomplete |
| Minimal instructor effort | Untested live |
| Degrades safely | Design/tests PASS |
| Does not alter official decisions without human confirmation | **PASS** |

**Phase 9 status:** shadow-production **framework complete**; live pilot **blocked** on secrets + clinic + cohort volume. Official training process remains sole authority.
