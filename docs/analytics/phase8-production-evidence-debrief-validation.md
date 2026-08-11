# Phase 8 — Production Evidence Wiring, Examiner Validation, and Debrief Workflow Design

**Analysis version:** `phase8-v1`  
**Generated:** 2026-08-11  
**Constraints honored:** production debrief not replaced; DE/EX/PR/PE preserved; no opaque score; AI not final authority; no large instructor rubric; no plaintext secrets in git/SQLite/logs/reports.

**Artifacts**
- Secret loaders: `analytics/lib/runtime_secrets.py`, `src/RuntimeSecrets.php`
- Schema: `analytics/schema/phase8_tables.sql`
- Pipeline: `analytics/etl/phase8_01_pipeline.py`
- Dev prototype: `public/admin/phase8_evidence_debrief.php`
- Prior: Phase 7 pilot + `public/admin/phase7_competency_pilot.php`

---

## EXECUTIVE VERDICT

**Partially achieved — architecture and debrief workflow are designed and exercised on a real reference recording; production gates for secrets and live MySQL/marker/transcript wiring remain blocked locally.**

What Phase 8 closed:

1. **Reusable secret abstraction** that refuses `EV[...]` and fails clearly without plaintext injection.
2. **Canonical exercise identity** (88 IDs, 131 source maps) so Steep Turn label variants collapse.
3. **Marker/attempt contract** with explicit `boundary_source` + `boundary_confidence` (no silent uncertain inference).
4. **Transcript temporal windows** per attempt with `availability=MISSING` when audio/ASR absent (speaker stays `UNKNOWN`).
5. **Group-level independence** + separate `SYSTEM_SUGGESTED_INDEPENDENCE` (never defaults to Independent).
6. **SOP/procedure packs** (4 packs, 27 steps) with TELEMETRY vs NOT_OBSERVABLE classification; **outcome ≠ procedure**.
7. **Examiner clinic worksheets**: 40 attempts × 2 reviewers = **80 PENDING** reviews with structured reason codes.
8. **End-to-end reference flight** (recording 22 / `0436A732-…` / N397EA): structured debrief object, exception queue, student + instructor prototypes, rule-based next-training recommendations.
9. **Failure-mode degradation rules** and **production data-contract classification** (no migrations yet).
10. **Exception-based review** targeting **~2.5 minutes** post-flight (&lt;3 min goal) via group taps + exception queue.

What remains open (honest):

- OpenAI + DB secrets still DO ciphertext locally → LLM enrichment and live `ipca_cvr_flight_events` / evidence ASR not pulled this run.
- Examiner clinic not human-completed → inter-rater agreement and tolerance VALIDATED statuses deferred.
- Instructor exercise markers not present in local bundles → boundaries remain telemetry-derived / replay-assisted.

**Product principle held:** the debrief is a **human-confirmed interpretation of traceable evidence**, not an AI opinion.

---

## 1. Secret injection status

| Logical name | Usable locally | Mechanism |
|---|---|---|
| `OPENAI_API_KEY` | **No** | `get_runtime_secret("OPENAI_API_KEY")` reads `CW_OPENAI_API_KEY` / `OPENAI_API_KEY`; rejects `EV[...]` |
| `CW_DB_PASS` | **No** | Same pattern for production MySQL |

Approved injection paths (platform convention, matching `CompliancePostmarkConfig`):

- DigitalOcean App Platform runtime plaintext  
- PHP-FPM pool env  
- `/etc/ipca/ipca-courseware-cli.env` for CLI  

**No** plaintext in git, SQLite, logs, reports, debug screens, or `.env.example`.

After injection:

```bash
export CW_OPENAI_API_KEY='…'   # from approved store; never commit
analytics/.venv/bin/python analytics/etl/phase7_05_llm_enrich.py
analytics/.venv/bin/python analytics/etl/phase8_01_pipeline.py
```

Acceptance gate: `openai_secret_injection` = **BLOCKED**; `production_db_access` = **BLOCKED**.

---

## 2. Final targeted NLP reconciliation

LLM enrichment **did not run** (secret blocked). Phase 8 copied Phase 7 reconciliation into `phase8_nlp_reconciliation` (24 rows).

| Pattern | heuristic_only | llm_only (n≈277) | combined |
|---|---:|---:|---:|
| VARIABLE → later problem | 53.6% (2302) | 62.9% (70) | **53.8%** (2372) |
| High-grade + deficiency → later problem | 54.1% (2355) | **37.0%** (173) | **52.9%** (2528) |
| ≥3/5 deficiency window → later problem | 59.3% (2103) | 61.5% (13) | **58.8%** (2299) |
| Deficiency rate | 31.8% | 89.2% | 33.3% |
| Encouraging + deficiency | 14.5% | 62.8% | 15.8% |

**Do not force** LLM-only high-grade effect to match combined. Full targeted LLM pass remains required to finalize LLM-only effect sizes.

---

## 3. Production exercise-marker integration

### Contract (authoritative production)

| Field | Source |
|---|---|
| `operational_session_id` | `ipca_flight_sessions.session_uuid` |
| Exercise marker events | `ipca_cvr_flight_events` (`event_type=exercise_marker`) |
| Detected windows | `ipca_detected_flight_exercises` |
| Recording link | `ipca_cockpit_recordings.operational_session_uuid` |

Markers are **point starts** (iOS tap). End boundary rule (explicit, ranked):

1. Next exercise marker start  
2. Explicit instructor end (when available)  
3. Maneuver-state completion  
4. Flight phase change  
5. Session end  

Always store `boundary_source` + `boundary_confidence`. Never silently treat uncertain ends as certain.

### This run

- Reference session wired from recording **22** / UUID `0436A732-…`
- **11** `phase8_marker_attempt` rows linked to Phase 7 pilot attempts  
- Boundaries: `TELEMETRY_DERIVED` / `NEXT_EXERCISE_OR_TELEMETRY` / `REPLAY_EVENT+TELEMETRY` when replay stall event aligns  
- Live MySQL marker pull: **blocked** (DB secret)

---

## 4. Transcript / audio integration

Per attempt: temporal window **t_start−15s … t_end+15s**.

Local reference:

| Asset | Status |
|---|---|
| G3X / AHRS / GPS | Present |
| Audio on disk | Missing (production `audio_url` exists for id=22) |
| Transcript segments | Rows created with `availability=MISSING`, `speaker=UNKNOWN` |

Speaker policy: **INSTRUCTOR | STUDENT | ATC | UNKNOWN** — never fabricate diarization.

---

## 5. AI prompt / intervention detection

Detector version `phase8-prompt-detect-v1` ready (VERBAL_PROMPT, PROCEDURAL_PROMPT, WARNING, POSSIBLE_SAFETY_INTERVENTION, …).

On reference flight: **no transcript** → single proposal documenting inability; `confirmation_status=UNCONFIRMED`.

**Rule:** AI proposals never overwrite instructor independence.

---

## 6. Examiner clinic results

| Item | Count |
|---|---:|
| Phase 7 pending cases packaged | 40 |
| Reviewer slots (A + B) | 80 |
| Completed human verdicts | **0** |

Clinic UI: `public/admin/phase8_evidence_debrief.php?view=clinic`  
Verdicts: CORRECT / PARTIALLY_CORRECT / INCORRECT / INSUFFICIENT_EVIDENCE  
Reason codes: BOUNDARY_WRONG … OTHER (full list in UI).

---

## 7. Inter-rater findings

`phase8_inter_rater.agreement_pending` — **deferred** until overlapping human reviews exist.

Principle locked: if examiners disagree on independence/consistency, the system must not pretend those dimensions have objective precision.

---

## 8. Tolerance-pack validation

| Pack / metric focus | Status |
|---|---|
| ACS steep-turn altitude/bank | INSUFFICIENT_EVIDENCE (await clinic) |
| PE approach VS | **NEEDS_ADJUSTMENT** — fix boundaries/markers first; do not loosen ACS |
| PE slow-flight airspeed | **NEEDS_CONTEXT_RULE** — need specified target IAS |
| PR steep-turn altitude | INSUFFICIENT_EVIDENCE — wider PR band pedagogically intentional |
| Landing/go-around soft proxies | INSUFFICIENT_EVIDENCE |

No silent historical pack mutation. Future adjustments require new version + reason + review evidence + effective date.

### Training progression

- **PR:** developing accuracy while procedure forms — wider training tolerances with SME/pedagogical basis  
- **PE:** near certification expectation  
- Not “ACS × arbitrary %”

---

## 9. Procedural / SOP pilot

| Pack | Exercise | Steps |
|---|---|---:|
| `IPCA_SOP_GO_AROUND_v1` | go_around | 8 |
| `IPCA_SOP_POWER_OFF_STALL_v1` | power_off_stall | 7 |
| `IPCA_SOP_POWER_ON_STALL_v1` | power_on_stall | 6 |
| `IPCA_SOP_NORMAL_APPROACH_LANDING_v1` | normal_approach/landing | 6 |

Evidence sources classified per step (TELEMETRY / TRANSCRIPT / RECORDER_EVENT / INSTRUCTOR / NOT_OBSERVABLE).

**Result vs process** encoded in debrief payload:

- `objective_quality` (outcome)  
- `procedure.procedural_compliance` (process)  
- Explicitly: may be outcome acceptable + procedure deficiency  

Do not claim full SOP compliance when steps are NOT_OBSERVABLE.

---

## 10. End-to-end reference flight

| Field | Value |
|---|---|
| Recording | 22 / `0436A732-CD26-423D-9746-57AB709E7C1C` |
| Aircraft | N397EA |
| Completeness | **PARTIAL_EVIDENCE** |
| Marker attempts | 11 |
| Independence groups | 4 |
| Debrief items | 11 |
| Recommendations | 12 |

Chain exercised:

Operational Session (UUID proxy) → attempts → telemetry metrics → context → independence groups (suggested) → procedure observations → AI advisory (deterministic) → structured debrief → instructor confirmation UI → student summary → persistent analytics tables.

Drill-down contracts: replay / audio / g3x admin URLs for id=22 (media players reuse existing components).

---

## 11. Competency assessment quality

Assessments remain multi-dimensional:

expectation · independence · objective multi-metric · procedure · consistency · context · trend · evidence completeness  

Missing evidence → **INSUFFICIENT_EVIDENCE** / NOT_OBSERVED — never fabricated Independent.

---

## 12. Exception-based review

Instructor path:

1. Exception queue only (deviations, consistency, safety-relevant, interventions)  
2. Group independence confirm/correct  
3. Intervention review  
4. Deficiency review  
5. Optional note  
6. Approve  

Routine within-tolerance metrics are summarized, not individually confirmed.

---

## 13. Instructor workload

| Metric | Value |
|---|---:|
| Phase 7 baseline | ~5.4 min |
| Phase 8 exception-based estimate | **~2.5 min** |
| Independence model | **1 tap / exercise group** (not per attempt) |
| Manual field % (est.) | ~8% (down from ~12%) |

Target **&lt;3 minutes** for routine flights is **design-achievable**; measure actual clinic times when examiners run the UI.

---

## 14. Student debrief prototype

`?view=student` shows:

- WHAT WENT WELL  
- WHAT NEEDS DEVELOPMENT  
- WHAT THE DATA SHOWED  
- WHAT TO FOCUS ON NEXT  
- Rule-based recommendations  

Tone ≠ assessment. Encouraging framing cannot hide development items (Phase 5B lesson).

Developmental clarity uses the locked dimensions (independence / quality / consistency / context) rather than inventing a competing grade ladder.

---

## 15. Instructor debrief prototype

`?view=instructor` + `?view=exceptions`: more detail, suggestions, confirmation, evidence links, historical hooks via competency state.

Separate from student view by design.

---

## 16. Evidence drill-down

Each debrief item carries:

- `supporting_evidence.replay_link`  
- `audio_link`  
- `g3x_link`  
- attempt/metric IDs  

Full media players deferred to existing replay stack.

---

## 17. Next-training recommendations

Deterministic `rule_code`s:

| Rule | Intent |
|---|---|
| `REPEAT_ON_OBJECTIVE_DEVIATION` | Practice focus on out-of-tolerance metrics |
| `REPEAT_ON_VARIABLE_CONSISTENCY` | Build repeatability |
| `CONFIRM_INDEPENDENCE_THEN_PROGRESS` | Confirm then avoid unnecessary repetition |
| `EXCEPTION_ONLY` | Summarize successes |
| `CURRICULUM_ADAPTATION_RESEARCH_ONLY` | Uses sctr_next/alternative conceptually — **no auto-reschedule** |

No free-form LLM curriculum decisions.

---

## 18. Data-quality failure handling

| Failure | Behavior |
|---|---|
| Missing Garmin | DEGRADED — instructor-only assessment |
| Missing audio/transcript | DEGRADED — no prompt proposals; UNKNOWN speaker |
| No / wrong marker | DEGRADED — low `boundary_confidence`; examiner BOUNDARY_WRONG |
| Forgotten independence | PASS — stays NOT_OBSERVED; suggestion separate |
| Late CSV | PASS — recompute when evidence arrives |

Session completeness: GARMIN / AUDIO / TRANSCRIPT / MARKERS / CONTEXT / INSTRUCTOR → overall FULL / PARTIAL / LIMITED.

---

## 19. Production data-contract proposal

| Entity | Class |
|---|---|
| exercise_attempt, competency_expectation, objective_measurement, context_snapshot, instructor_observation, intervention_event, competency_assessment, competency_state_history, debrief, procedure_pack, canonical_exercise | **PRODUCTION_REQUIRED** |
| phase8_nlp_*, pilot_* Phase 7 warehouse | **ANALYTICS_ONLY** |
| evidence caches | **DERIVED_CACHE** |
| historical narrative extractions | **HISTORICAL_ONLY** |

**No production migrations in Phase 8.**

Operational Session UUID remains authoritative — analytics maps, does not compete.

---

## 20. Open production gates

| Gate | Status |
|---|---|
| openai_secret_injection | BLOCKED |
| production_db_access | BLOCKED |
| exercise_boundary_accuracy | OPEN (clinic) |
| objective_metric_accuracy | OPEN |
| tolerance_packs_approved | OPEN |
| independence_workflow_accepted | OPEN |
| consistency_logic_accepted | OPEN |
| procedural_pilot_accepted | OPEN |
| post_flight_workload | OPEN (design &lt;3 min) |
| debrief_usefulness | OPEN |
| ai_unsupported_claim_rate | OPEN |

Thresholds must be set from **observed examiner clinic**, not invented percentages.

---

## 21. Recommended Phase 9

1. Inject runtime secrets on an approved host; finish targeted LLM enrichment; freeze NLP effect sizes.  
2. Pull production Operational Sessions with real `exercise_marker` + audio/ASR; re-run reference chain marker-authoritative.  
3. Complete examiner clinic (dual review); compute inter-rater; version any tolerance adjustments.  
4. Field-trial group independence + exception review; measure actual minutes/taps.  
5. Only then: production migration of PRODUCTION_REQUIRED entities + replace/augment debrief UI behind feature flag.  
6. Keep AI advisory; keep multi-dimensional competency; keep no opaque score.

---

## Success criteria check

| Criterion | Status |
|---|---|
| Evidence → proposed debrief automatically | **Yes** (reference flight, PARTIAL_EVIDENCE) |
| Instructor reviews exceptions + human-only judgments | **Designed + prototyped** |
| Examiner educational defensibility | **Clinic ready; not yet human-signed** |
| Expectation / independence / result / procedure / consistency / context distinct | **Yes** |
| Student clarity (well / develop / why) | **Prototype yes** |
| Minimal manual work | **Group tap + exceptions; &lt;3 min design target** |

**Phase 8 status:** validation and workflow design complete for local/reference evidence; production secret + live marker/transcript + examiner sign-off are the remaining rollout gates.
