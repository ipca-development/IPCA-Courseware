# Phase 10C — Live Validation Closure

**Analysis version:** `phase10c-v1`  
**Freeze ID:** `phase10c-live-freeze-v1`  
**Generated:** 2026-08-11  

**Purpose:** Close remaining validation gates using the live cohort. Architecture frozen — no new maneuver families, no production migrations, no feature flags, no fabricated examiner verdicts.

**Artifacts**
- Pipeline: `analytics/etl/phase10c_01_validation_closure.py`
- Schema: `analytics/schema/phase10c_tables.sql`
- Admin: [`public/admin/phase10c_validation.php`](../../public/admin/phase10c_validation.php)
- Schema delta: [`phase10c-production-schema-delta.md`](phase10c-production-schema-delta.md)
- Durable LLM unit: `deploy/systemd/ipca-analytics-llm-enrich.service`

---

## EXECUTIVE VERDICT

**`NOT_READY`**

Infrastructure and live cohort are no longer the bottleneck. The primary open gate is **professional examiner trust** (clinic **0 / 40 dual-complete**), plus unmeasured instructor workload, tolerance acceptance, and human claim-support sampling.

| Gate | Status |
|---|---|
| A Secrets | **PASS** |
| D Live cohort ≥50 | **PASS** (exact **75**) |
| G Examiner clinic | **FAIL** (dual_complete **0**/40) |
| H Tolerances | **FAIL** |
| N Workload | **INSUFFICIENT_EVIDENCE** (n=0) |

Official training state untouched. Flags OFF. No migrations. Historical LLM job **RUNNING** (must not block clinic).

### Continuation note (`phase10c-v1.1` investigations)

Human clinic/claim/transcript/workload **reviews were not fabricated**. Queues are ready in admin UI. Investigation findings below explain FULL_EVIDENCE=0 and crew linkage. Re-evaluate gates only after humans complete meaningful review volume.

---

## 1. Exact live cohort

**Freeze:** `phase10c-live-freeze-v1`  
**Selection rule:** Newest **75** unique Operational Sessions with cockpit recordings (`ORDER BY recording id DESC`).

| Metric | Exact value |
|---|---:|
| Session count | **75** |
| Student count (linked) | **11** distinct (14 sessions resolved via corrected schedule join) |
| Instructor count (linked) | **3** distinct |
| Aircraft count | **5** |
| Exercise-attempt count (markers) | **53** |
| Date range | **2026-07-27 02:46:18 → 2026-08-11 00:29:16** |

### Aircraft distribution

| Aircraft | n |
|---|---:|
| N397EA | 30 |
| N428EA | 22 |
| N392EA | 20 |
| N446CS | 1 |
| (unknown) | 2 |

### Program distribution

| Program | n |
|---|---:|
| (unspecified) | 75 |

### Evidence completeness

| Class | n |
|---|---:|
| LIMITED_EVIDENCE | 53 |
| PARTIAL_EVIDENCE | 22 |

All subsequent Phase 10C metrics use this freeze unless explicitly stated otherwise.

---

## 2. Runtime status

| Item | Status |
|---|---|
| OPENAI_API_KEY (CLI) | AVAILABLE |
| CW_DB_PASS (CLI) | AVAILABLE |
| ASR credentials | AVAILABLE |
| PHP-FPM pool | Untouched |
| Repo `.env` plaintext secrets | None added |

---

## 3. Evidence completeness

### Why FULL_EVIDENCE = 0 (freeze of 75)

Definition used: MARKERS + GARMIN + AUDIO + TRANSCRIPT + CONTEXT + INSTRUCTOR_INPUT.

| Missing component | Sessions missing it |
|---|---:|
| INSTRUCTOR_INPUT | **75** (shadow independence/workload not entered — expected until instrumented) |
| MARKERS | **53** |
| GARMIN (gps/g3x/ahrs usable) | **38** |
| CONTEXT (`ipca_flight_sessions` row) | **35** |
| AUDIO | **0** |
| TRANSCRIPT | **0** |

**Near-full (MARKERS+GARMIN+AUDIO+TRANSCRIPT):** 14 sessions — still not FULL because instructor input is absent everywhere.

Top present-component patterns:

| Pattern | n |
|---|---:|
| AUDIO+TRANSCRIPT only | 24 |
| GARMIN+AUDIO+TRANSCRIPT+CONTEXT | 14 |
| MARKERS+GARMIN+AUDIO+TRANSCRIPT+CONTEXT | 12 |
| GARMIN+AUDIO+TRANSCRIPT | 9 |
| MARKERS+AUDIO+TRANSCRIPT+CONTEXT | 8 |

**Conclusion:** PARTIAL/LIMITED is not “normal success.” Production still lacks exercise markers and Garmin/G3X on large shares of the freeze; instructor shadow input is systematically absent. Audio+transcript presence is strong.

See admin **Evidence gaps** view / `storage/analytics/phase10c_investigations.sqlite`.

---

## 4. Marker / boundary validation

Live marker ingestion works (Gate B **PASS_WITH_CONDITIONS**). Boundary **correctness** remains **INSUFFICIENT_EVIDENCE** until clinic dimensional `boundary_verdict` dual reviews exist. No architecture change to boundary logic this phase.

---

## 5. Transcript / ASR quality

**Provisional system classes on freeze (NOT human-validated):**

| Class | n |
|---|---:|
| USABLE | 22 |
| LIMITED | 50 |
| UNUSABLE | 3 |
| GOOD | 0 |

**PRESENT ≠ USEFUL.** Classification source: `SYSTEM_PROVISIONAL`.  
Feature minimums documented in `phase10c_transcript_feature_req` (e.g. debrief quotes require GOOD; prompt detection requires USABLE+).

Gate C: **PASS_WITH_CONDITIONS** (presence observed; human quality rating open).

---

## 6. AI intervention / prompt detection

Readiness: **SHADOW_ONLY** / **INSUFFICIENT_EVIDENCE** until a human sample of true/false positives, ATC misclass, and student-as-instructor errors is completed. Conservative default retained.

---

## 7. Examiner clinic

| Metric | Value |
|---|---:|
| Dual-complete (all dims) | **0** / 40 |
| Worksheets complete (est.) | **0** / 80 |
| Single-review only | 0 |
| Unreviewed attempt pairs | 40 |
| Conflicting | 0 |
| Fabricated reviews | **0** |

Admin clinic UI requires: reviewer, timestamp, exercise, boundary, objective, tolerance, procedure, independence, consistency, overall + reason codes. Partial fills do **not** count as complete.

**This is the primary Phase 10C gate.**

---

## 8. Inter-rater agreement

All dimensions: **n_pairs=0** → raw / excl-IE / κ = unavailable. Will compute separately for boundary, objective, tolerance, procedure, independence, consistency, overall when dual reviews exist. Adjudication queue ready (no auto-winner).

---

## 9. Maneuver-specific dispositions

Blanket `MORE_VALIDATION_REQUIRED` replaced with evidence-based labels — currently all **`INSUFFICIENT_EVIDENCE`** (no completed dual dimensional reviews):

| Maneuver | Live attempts (prior tables) | Reviewed | Disposition |
|---|---:|---:|---|
| go_around | (see DB) | 0 | INSUFFICIENT_EVIDENCE |
| normal_approach | | 0 | INSUFFICIENT_EVIDENCE |
| normal_landing | | 0 | INSUFFICIENT_EVIDENCE |
| power_off_stall | | 0 | INSUFFICIENT_EVIDENCE |
| power_on_stall | | 0 | INSUFFICIENT_EVIDENCE |
| slow_flight | | 0 | INSUFFICIENT_EVIDENCE |
| steep_turn | | 0 | INSUFFICIENT_EVIDENCE |

Narrow `VALIDATED_FOR_INSTRUCTOR_ASSIST` is allowed later **per maneuver** once clinic evidence supports it — not claimed now.

---

## 10. Tolerance packs

| Pack / metric | Disposition | Mismatch class |
|---|---|---|
| ACS_PPL_ASEL_v1 / altitude | PENDING_CLINIC | HUMAN_JUDGMENT |
| IPCA_TRAINING_PE_v1 / VS | NEEDS_REVIEW | WRONG_BOUNDARY |
| IPCA_TRAINING_PE_v1 / airspeed | NEEDS_REVIEW | WRONG_METRIC |
| IPCA_TRAINING_PR_v1 / altitude | PENDING_CLINIC | WRONG_LEVEL_APPLICABILITY |

**No in-place retune.** Any future change → versioned RC (e.g. `IPCA_TRAINING_PE_v1.1-RC`) with old/new/reason/cases/approval.

---

## 11. Procedure packs

Steps classified AUTO_PARTIAL / TRANSCRIPT_SUPPORTED / HUMAN_REQUIRED / NOT_OBSERVABLE from existing packs. Pack dispositions remain **INSUFFICIENT_EVIDENCE** pending clinic. Never claim full SOP compliance when steps are NOT_OBSERVABLE.

---

## 12. Independence workflow

Model unchanged: ASSISTED | PROMPTED | INDEPENDENT | NOT_OBSERVED. Live tap/completion metrics **n=0**. Gate J **INSUFFICIENT_EVIDENCE**.

---

## 13. Consistency

≥3-attempt rule retained. Gate K **PASS_WITH_CONDITIONS** (rules stable; examiner agreement pending). No extra levels added.

---

## 14. Instructor workload

| Segment | n | median / P75 / P90 |
|---|---:|---|
| routine / complex / high_exercise_count / all | **0** | — |

Phase 8 ~2.5 minutes is **not** measured evidence. Capture via admin workload form (reasons: independence, boundary, transcript, exceptions, AI wording, procedure, missing evidence, narrative, other).

---

## 15. Exception queue

Ratings PENDING until instructors classify USEFUL / NEUTRAL / NOISY / WRONG on live flights.

---

## 16. Claim-to-evidence

Human support classes PENDING for deficiency / improvement / independence / consistency / procedure / regression / safety / next_focus. Evidence IDs alone ≠ support.

---

## 17. Debrief acceptance

Capture path ready: ACCEPT / ACCEPT_WITH_MINOR_EDITS / MAJOR_CORRECTION / REJECT / INSUFFICIENT_EVIDENCE. Counts currently empty.

---

## 18. System vs human

`phase10c_system_human` stores system proposal, human correction, and final confirmed state separately. System proposals are never overwritten.

---

## 19. Historical LLM reconciliation

| Metric | Value |
|---|---:|
| Job status | **RUNNING** |
| Processed (distinct LLM hashes) | **652** |
| Remaining (approx) | **9784** |
| Cache files | **376** |

Findings classification CONFIRMED/REVISED/NOT_CONFIRMED = **PENDING** until job completes.  
**Does not block** clinic / live readiness work. Durable unit installed: `ipca-analytics-llm-enrich.service`.

---

## 20. Degraded modes

| Case | Source | Result |
|---|---|---|
| missing_transcript | LIVE | OBSERVED |
| missing_marker | LIVE | OBSERVED |
| missing_independence | LIVE | PASS_DESIGN (NOT_OBSERVED) |
| duplicate_sync / versioning | FIXTURE | PASS_DESIGN |
| late/missing Garmin, repaired, offline | — | UNOBSERVED |

---

## 21. Schema delta

See [`phase10c-production-schema-delta.md`](phase10c-production-schema-delta.md). **NO MIGRATION.**

---

## 22. Exit-gate matrix

| Gate | Status |
|---|---|
| A. Secrets | **PASS** |
| B. Live markers | **PASS_WITH_CONDITIONS** |
| C. Live transcript | **PASS_WITH_CONDITIONS** |
| D. Live cohort | **PASS** |
| E. Boundary reliability | **INSUFFICIENT_EVIDENCE** |
| F. Objective metrics | **INSUFFICIENT_EVIDENCE** |
| G. Examiner clinic | **FAIL** |
| H. Tolerance packs | **FAIL** |
| I. Procedure packs | **INSUFFICIENT_EVIDENCE** |
| J. Independence | **INSUFFICIENT_EVIDENCE** |
| K. Consistency | **PASS_WITH_CONDITIONS** |
| L. Claim-to-evidence | **INSUFFICIENT_EVIDENCE** |
| M. Unsupported claims | **INSUFFICIENT_EVIDENCE** |
| N. Instructor workload | **INSUFFICIENT_EVIDENCE** |
| O. Debrief usefulness | **INSUFFICIENT_EVIDENCE** |
| P. Degraded-mode safety | **PASS_WITH_CONDITIONS** |
| Q. Schema readiness | **PASS_WITH_CONDITIONS** |

---

## 23. Overall readiness verdict

**`NOT_READY`**

Not `READY_FOR_LIMITED_INSTRUCTOR_ASSIST` — clinic not materially complete, workload unmeasured, tolerances unaccepted, claim support unsampled.

---

## 24. Exact remaining blockers

| Gate | Why | Required action |
|---|---|---|
| G | 0 dual-complete dimensional reviews | Humans complete 80 worksheets via `phase10c_validation.php` (queues ready; **no AI verdicts**) |
| H | Tolerances PENDING/NEEDS_REVIEW | Examiner review; versioned RC only — no agreement-chasing retune |
| N | Workload n=0 | Instrumented live instructor reviews (median/P75/P90) |
| L / M | No human claim sample | 40 OPEN claim queue items — classify FULLY/PARTIAL/UNSUPPORTED/MISLEADING |
| C / prompt | SYSTEM_PROVISIONAL only | 25 OPEN transcript queue items — HUMAN_REVIEW class + speaker/ATC/prompts |
| E / F | No clinic boundary/metric verdicts | 22 OPEN boundary/metric queue sessions with markers>0 |
| Evidence | FULL_EVIDENCE=0 | Fix marker capture + Garmin linkage; capture instructor independence input |

### Student / instructor linkage (resolved path)

| Finding | Result |
|---|---|
| Prior join bug | Used nonexistent `slots.reservation_uuid` / `crew.slot_id` |
| Correct path | `sessions.reservation_uuid = slots.scheduler_record_id`; `crew.schedule_slot_id = slots.id` |
| Sessions with reservation/dispatch in freeze | **14 / 75** |
| Crew resolved after fix | **14** sessions → **11** students, **3** instructors |
| Remaining gap | **61 / 75** lack reservation/dispatch (Operational Session without schedule claim) — not a guessable identity |

This does **not** block objective attempt review where markers+telemetry exist; it **does** block longitudinal per-student analysis until more sessions carry reservation linkage.

### Human review queues (OPEN)

| Queue | OPEN |
|---|---:|
| CLINIC | 80 |
| CLAIM | 40 |
| TRANSCRIPT | 25 |
| BOUNDARY_METRIC | 22 |
| WORKLOAD | 1 |

### Attempt denominators

Do **not** use 75 sessions as maneuver denominator. Freeze has **53** marker events total (exercise IDs often unset on live markers). Examiner-reviewed attempts: **0**. All maneuver dispositions remain **INSUFFICIENT_EVIDENCE**.

### LLM (not a gate)

eligible 10436 / processed **802** / remaining **9634** / status **RUNNING**

### Source partition reminder

| Class | n | Mix into live stats? |
|---|---:|---|
| LIVE_PRODUCTION_SHADOW | 75 | Yes (freeze) |
| LOCAL_SIMULATION | 19 | **No** |
| CONTROLLED_FIXTURE | 0 | Label if used |
| HISTORICAL_ANALYTICS | 10436 | Analytics only |

**No Phase 11.** Continue human validation only.
