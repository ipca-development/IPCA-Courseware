# Phase 6 — Competency Evidence Model and Targeted Historical Enrichment

**Analysis version:** `phase6-v1`  
**Generated:** 2026-08-11  
**Constraints honored:** no production UI; no E-gle writes; historical grades retained; no single competency score; no opaque student-risk score.

**Artifacts**
- Schema: `analytics/schema/phase6_tables.sql`
- Bootstrap: `analytics/etl/phase6_01_bootstrap.py`
- Enrichment / scale analysis: `analytics/etl/phase6_02_enrich_analyze.py`
- Deterministic state engine: `analytics/lib/competency_state_engine.py`
- Analytics DB tables: `competency_*`, `evidence_item`, `analysis_phase6_*`, `cockpit_recorder_contract_field`, `automation_opportunity`

---

## 1. Executive conclusions

1. **Competency must be a multi-layer evidence state**, not a grade. Curriculum expectation, observed independence, objective quality, consistency, context, and human assessment remain separately queryable.
2. **Minimum independence scale is four states:** `ASSISTED | PROMPTED | INDEPENDENT | NOT_OBSERVED`. Finer 7-state ladders add instructor burden without historical recoverability. Physical intervention / safety takeover stay **separate events**.
3. **Consistency is kept**, split into `attempt_repeatability` (within session) vs `longitudinal_stability` (across sessions). Never label a single success `CONSISTENT` (minimum **3** comparable attempts).
4. **Quality is objective measurements**, not another subjective instructor score. Narrative accuracy language is transitional only.
5. **Targeted enrichment ran on 10,436 high-value unique narrative hashes** (approved reduced-scope categories; slightly above the ~8.8k Phase 5B estimate because incomplete/R sessions and all check events were included).
6. **OpenAI key remains vault-encrypted locally** (`CW_OPENAI_API_KEY=EV[...]`). Pipeline reused **277 LLM-v1 hashes** from Phase 5 and scaled the **validated heuristic extractor** for the remainder. Scale rates therefore sit between Phase 5B heuristic (conservative) and LLM (sensitive). Full LLM-v1 re-run on the population remains a Phase 7 unlock when a decryptable key is available.
7. **Phase 5B conclusions remain visible at scale**, especially: narrative deficiency under strong grades; consistency concerns preceding later problems; independence mostly `NOT_OBSERVED`; context frequently present.
8. **Early warning is explainable pattern queries**, not a risk score. Example: consistency concern → later problem rate **53.8%** vs baseline **28.9%** (horizon 3 sessions).
9. **27 evidence-based competency timelines** demonstrate developmental cards without collapsing to one number.
10. **Cockpit Recorder should own objective quality + context + attempt boundaries**; instructors should mainly supply independence/intervention and confirm AI drafts.

---

## 2. Final conceptual competency architecture

Layers (never collapsed):

| Layer | Answers | Primary future source |
|---|---|---|
| **CURRICULUM EXPECTATION** | What level is expected now? | Curriculum / exercise definition |
| **OBSERVED EXECUTION** | How independently? What happened on each attempt? | Instructor structured + interventions |
| **OBJECTIVE EVIDENCE** | How well vs tolerances? | Cockpit Recorder / Garmin / GPS |
| **HUMAN ASSESSMENT** | What only a human can judge? | Instructor observation (+ optional student self-assessment) |
| **COMPETENCY STATE** | Developmental synthesis | Deterministic engine over evidence |
| **CONTEXT** | Under what conditions? | Auto-derived weather/airport/aircraft/ATC/phase |
| **AI INTERPRETATION** | Candidate meaning of audio/telemetry/narrative | Versioned AI; confirmable; never authoritative alone |

Hierarchy:

```
EVIDENCE → OBSERVATION → ASSESSMENT → COMPETENCY STATE → CURRICULUM COMPARISON
```

Example:

- Evidence: altitude +180 ft on steep-turn attempt 1  
- Observation: altitude exceeded ACS tolerance  
- Assessment: objective quality below standard for altitude control  
- Competency state: Independent (if so recorded), Developing consistency  
- Curriculum comparison: PE expected; independence demonstrated; repeatability not yet established  

Seeded registry: table `competency_architecture_field`.

---

## 3. Required level (`curriculum_expected_level`)

Preserve **DE / EX / PR / PE** exactly as curriculum expectation.

- Historical compatibility: continue parsing from exercise names / required fields.
- Does **not** answer how well the student performed.
- Do **not** redefine DE/EX/PR/PE from observed independence or objective quality.
- Achieved R/Y/G/B (+ session RC…BI) remain **historical performance labels**, mapped into evidence (`HISTORICAL_GRADE`), not into expected level.

---

## 4. Independence

### Recommendation: `INDEPENDENCE_MIN_4`

| State | Meaning |
|---|---|
| `ASSISTED` | Demonstration, step-by-step coaching, repeated prompts, physical help |
| `PROMPTED` | Minor / single verbal or procedural prompt; verbal confirmation |
| `INDEPENDENT` | Explicit unassisted execution |
| `NOT_OBSERVED` | No structured observation (including narrative silence) |

**Rejected as live instructor scale:** FULL/SUBSTANTIAL/MINIMAL ladders and `UNKNOWN` as a tap target. Use `NOT_OBSERVED` for missing capture; reserve UNKNOWN only as historical missing-dimension metadata if needed.

### Phase 5B evidence for minimalism

- Independence historically **NOT_RELIABLY_EXTRACTABLE**.
- At Phase 6 scale: **92.6%** mapped `NOT_OBSERVED`; only **0.16%** explicit `INDEPENDENT`; assistance language ~**7.3%**.
- Silence must **never** become Independent.

### Separate intervention events

`VERBAL_PROMPT | PROCEDURAL_PROMPT | CORRECTION | DEMONSTRATION | PHYSICAL_INTERVENTION | SAFETY_TAKEOVER`

Independence can be coarse; interventions carry the safety/critical detail.

**Instructor UX (future, not built):** one quick action after an attempt (“Assisted / Prompted / Independent”) plus optional intervention chip for physical/safety.

---

## 5. Objective quality

Do **not** create a generic instructor quality score.

Canonical measurement object (`objective_measurement`):

| Field | Purpose |
|---|---|
| `metric` | e.g. altitude_deviation |
| `actual_value` | measured |
| `target_value` | intended |
| `lower_tolerance` / `upper_tolerance` | standard band |
| `unit` | ft, kt, deg, … |
| `within_standard` | boolean |
| `severity` | optional |
| `source` | GARMIN_G3X, COCKPIT_RECORDER_EVENT, … |
| `confidence` | evidence quality |
| `time_range` | timestamp or interval |

Capable statement form: *“Altitude exceeded tolerance by 180 ft”* — not *“Accuracy = 2/5.”*

Example metrics: altitude/airspeed/heading/bank/VS, GS/LOC, touchdown position, approach stability, configuration timing, checklist sequence, stall recovery parameters, go-around sequence, entry/exit conditions.

Historically: almost always `UNKNOWN` unless narrative contains an explicit measurable phrase (rare).

---

## 6. Consistency

### Recommendation: `CONSISTENCY_MIN_4`

`NOT_ENOUGH_EVIDENCE | VARIABLE | DEVELOPING | CONSISTENT`

Drop `ROBUST` until multi-session + multi-context evidence exists.

### Rules

| Concept | Rule |
|---|---|
| **attempt_repeatability** | ≥3 attempts of same exercise type in one session before `CONSISTENT` |
| **longitudinal_stability** | Within-standard success across ≥2 sessions on different days |
| **Single success** | Never `CONSISTENT` |

Consistency should be **derived** from attempts/objective outcomes (+ intervention escalation), not manually graded every maneuver.

Phase 6 scale: consistency signal present in **36.1%** of high-value narratives overall; **57.0%** on reused LLM-v1 subset (aligned with Phase 5B ~52.8%).

---

## 7. Context

Structured `context_snapshot`, primarily **AUTO**:

wind, crosswind component, gust spread, turbulence, visibility, ceiling, day/night, airport familiarity, aircraft, runway, traffic/workload, ATC environment, exercise complexity, abnormal/emergency, check/evaluation environment, training location, time of day.

Instructors should not re-enter what weather/Garmin/airport/aircraft systems already know.

Phase 6 scale: context tags present in **67.1%** of enriched high-value narratives.

Transfer is **not** a per-maneuver instructor checkbox (see §8).

---

## 8. Transfer

Transfer is **derived later** from longitudinal performance across **materially different** `context_snapshot`s.

Example: landings within standard in calm home-field conditions, then again with crosswind at another airport under traffic → `TRANSFER_EVIDENCE_PRESENT`.

Do not ask “Was this transferable?” after every maneuver.

Engine helper: `derive_transfer()` in `analytics/lib/competency_state_engine.py`.

---

## 9. Evidence / provenance model

Every `evidence_item` preserves:

- `evidence_source`
- timestamp / range
- session / exercise_attempt links
- `raw_or_derived`
- `confidence` (evidence quality, not student ability)
- `model_or_algorithm_version`
- `source_reference`
- `payload_json`

### Source enum (canonical)

`INSTRUCTOR_INPUT | GARMIN_G3X | GPS | COCKPIT_RECORDER_EVENT | AUDIO | TRANSCRIPT | AI_AUDIO_INTERPRETATION | AI_TELEMETRY_INTERPRETATION | STUDENT_INPUT | HISTORICAL_GRADE | HISTORICAL_NARRATIVE | SYSTEM_DERIVED | WEATHER | ATC_COMMUNICATION`

**AI-derived evidence must remain distinguishable** via source + model version.

Cache key for narrative NLP (unchanged):  
`text_hash | prompt_version | model | schema_version`

---

## 10. Exercise-attempt architecture

Analytics centers on **exercise attempts**, not only session grades.

One flight may contain Steep Turn attempts 1–3, each with:

start/end · objective telemetry · context · instructor intervention · audio/transcript · result · AI interpretation

Session rollups must **not** overwrite attempts.

Derived within-session labels (from evidence):

`improved_within_session | stable_within_session | regressed_within_session | insufficient_attempts`

**Historical limitation:** E-gle rarely stores multiple graded attempts of the same exercise inside one session (`exercise_attempt_number` multi-attempt improvement ≈ 0 in current facts). Within-session learning is a **future Recorder capability**; Phase 6 timelines use cross-session unmet→met plus narrative `IMPROVEMENT` language as the closest evidence-bounded proxy.

Prototype table: `exercise_attempt_proto`.

---

## 11. Instructor intervention model

Lightweight events (timestamp, attempt, optional reason/severity, confirmation_status):

| Event | Typical capture |
|---|---|
| VERBAL_PROMPT | MANUAL quick chip / AI candidate |
| PROCEDURAL_PROMPT | MANUAL / AI+confirm |
| CORRECTION | MANUAL |
| DEMONSTRATION | MANUAL |
| PHYSICAL_INTERVENTION | MANUAL (high priority) |
| SAFETY_TAKEOVER | MANUAL (highest priority) |

Do not require full manual logging of every verbal cue if audio/AI later proposes candidates — **instructor-confirmed > AI candidate**.

Existing exercise/remark UI can add one independence tap + optional intervention chip without a new heavy form.

---

## 12. Historical compatibility

| Dimension | Historical representation |
|---|---|
| Expected level | DE/EX/PR/PE from curriculum |
| Performance grade | R/Y/G/B + session RC…BI as `HISTORICAL_GRADE` |
| Narrative | `HISTORICAL_NARRATIVE` evidence (+ NLP extraction) |
| Independence | `NOT_OBSERVED` unless explicit language |
| Objective quality | `UNKNOWN` |
| Consistency | Partial from narrative; else `NOT_ENOUGH_EVIDENCE` |
| Context | Partial narrative tags; else empty snapshot |

**Do not** invent independence from Blue/B grades.  
**Do not** replace historical grades.

---

## 13. Targeted NLP results

### Population

| Metric | Value |
|---|---:|
| Unique high-value hashes enriched | **10,436** |
| Phase 5B recommended scope (approx.) | ~8,774 |
| LLM-v1 reused | 277 |
| Heuristic scaled (`phase6-extract-v1-heuristic-scaled`) | 10,159 |

**Buckets (primary):**

| Bucket | n |
|---|---:|
| HIGH_GRADE_LATER_PROBLEM | 2,873 |
| CHECKPOINT_DIFFICULTY | 2,466 |
| REPEATED_PROGRESSION | 2,113 |
| SESSION_BELOW_OR_INCOMPLETE | 1,311 |
| PE_FOLLOWED_BY_REGRESSION | 603 |
| PROGRESSION_BELOW_REQUIRED | 599 |
| EXERCISE_REGRESSED | 471 |

Boilerplate / short / duplicate hashes excluded. Already-extracted LLM hashes not reprocessed.

### Extraction method honesty

Validated LLM-v1 pipeline was used wherever cached Phase 5 agent outputs existed. Remaining population used the **same validated heuristic schema/patterns** as Phase 5 (`phase5_02c_heuristic_extract.py`), with independence remapped to the Phase 6 4-state model.

Full OpenAI LLM-v1 over ~10k hashes is blocked until `CW_OPENAI_API_KEY` is available in plaintext (or PHP-FPM injects a usable key). Local `.env` still holds `EV[...]`.

### QA monitoring

Stratified metrics written to `analysis_phase6_nlp_qa` by program, year, narrative length, extractor, bucket.

**Dimension distribution flags (investigate, may be real or artifact):**

| Stratum | Flag | Δ vs overall |
|---|---|---:|
| program_id=5 | INDEPENDENCE / INSTRUCTOR_ASSISTANCE elevated | +0.15 |
| program_id=13 | TECHNICAL_CONTROL depressed | −0.15 |

---

## 14. Phase 5B findings at scale

| Finding | Phase 5B (LLM 405) | Phase 6 high-value (mixed) | Phase 6 LLM-reuse (277) |
|---|---:|---:|---:|
| Deficiency present | ~83% | **33.3%** | **89.2%** |
| Encouraging/mixed + deficiency | 59.8% (242/405) | **15.8%** | **62.8%** |
| Consistency signal | 52.8% | **36.1%** | **57.0%** |
| Context present | 74.6% | **67.1%** | (subset similar) |
| Assistance language | 14.3% | **7.3%** | — |
| Independence NOT_OBSERVED | (architectural) | **92.6%** | — |
| High grade + narrative deficiency | mismatch driver | **25.1%** of strong grades (n=8,845) | — |
| PE-context deficiency | — | **33.2%** (n=1,018) | — |
| High-grade→later-problem bucket deficiency | — | **32.3%** (n=2,873) | — |

**Verdict:** Phase 5B conclusions **remain visible**. Absolute rates depend on extractor sensitivity: LLM-reuse matches Phase 5B; heuristic-scaled is a conservative lower bound (as in Phase 5B). Do **not** treat the blended 33% deficiency rate as a retreat from the LLM finding — it is an expected method mix until full LLM enrichment completes.

Independence remains **not reconstructable** from history at scale.

---

## 15. Early-warning evidence patterns

No numerical risk score. Patterns in `analysis_phase6_early_warning_pattern`:

| Pattern | Episodes | Later problem rate | Baseline | Explainable template |
|---|---:|---:|---:|---|
| CONSISTENCY_CONCERN (VARIABLE) | 2,372 | **53.8%** | 28.9% | “Consistency concern appeared in recent observations (VARIABLE).” |
| HIGH_GRADE_NARRATIVE_DEFICIENCY | 2,528 | **52.9%** | 28.9% | “High structured grade coexisted with narrative deficiency evidence.” |
| REPEATED_DEFICIENCY_WINDOW (≥3 of last ≤5) | 2,299 | **58.8%** | 28.9% | “Concern appeared in 3 of the last 5 observations.” |
| LONG_TRAINING_GAP (≥14d, next-session horizon) | 3,257 | 13.2% | 12.9% | Weak alone in this definition; keep as **context factor**, not standalone alarm. |
| INDEPENDENT_BUT_INCONSISTENT | 3 | n too small | — | Conceptually important; historically rare because independence is unobserved. |

These are **evidence queries** for instructors/analytics, not opaque scores.

---

## 16. Cockpit Recorder integration contract

Semantic contract (table `cockpit_recorder_contract_field`) — not coupled to current iOS internals:

**Required / core**

- `operational_session_id`
- `exercise_attempt_id`
- `exercise_type`
- `start_timestamp` / `end_timestamp`
- `algorithm_model_versions`

**Optional / linked**

- `actual_leg_id`
- `telemetry_reference`, `audio_reference`, `transcript_reference`, `context_reference`
- `instructor_events[]`
- `objective_metrics[]`
- `ai_derived_observations[]`
- `curriculum_expected_level` if known

Recorder automates: attempt boundaries (esp. with markers), objective metrics, context assembly, AI candidate observations.  
Instructor supplies: independence, critical interventions, confirmation of drafts.

---

## 17. Automation opportunities

| Field | Class |
|---|---|
| Weather / airport / aircraft / day-night | **AUTO** |
| Objective tolerances (alt/spd/hdg/bank/…) | **AUTO** |
| Exercise start marker | **AUTO_WITH_CONFIRMATION** (later auto-detect) |
| Checklist sequence | **AUTO_WITH_CONFIRMATION** |
| AI draft debrief | **AUTO_WITH_CONFIRMATION** |
| Observed independence | **MANUAL** (one tap) |
| Physical intervention / safety takeover | **MANUAL** (or AI candidate + confirm) |
| Student self-assessment | **MANUAL** (optional) |
| Consistency / transfer / competency state / within-session learning | **DERIVED_LATER** |

Principle: instructors only enter what only they can reliably know.

---

## 18. Representative competency timelines

**27 timelines** in `competency_timeline_example` (maneuver-filtered where possible):

| Pattern | n |
|---|---:|
| stable_competency | 4 |
| apparent_regression | 4 |
| high_grade_narrative_warning | 4 |
| long_gap_degradation | 3 |
| contextual_drop | 3 |
| persistent_plateau | 3 |
| within_session_improvement | 3 |
| independent_but_inconsistent | 3 |

Each timeline lists only evidenced fields; missing independence/quality remain `NOT_OBSERVED` / `UNKNOWN`.

### Example A — high grade with narrative warning (takeoff family)

Student 485 / program 18 progresses DE→EX→PR with rising grades; an enriched PR session shows **CRITICAL tone + deficiency** while independence stays `NOT_OBSERVED`. Grades alone would miss the warning.

### Example B — stable maneuver family with context

Student 669 / “(R) Low altitude maneuvering/stall/spin”: repeated PR met with G; narratives show MIXED tone, deficiency evidence, CROSSWIND/TURBULENCE tags, and at least one `ASSISTED` (demonstration). **Stable required-level attainment ≠ empty narrative / independent.**

### Example C — independent but inconsistent (rare explicit)

Only **3** enriched narratives map to `INDEPENDENT` + `VARIABLE`. Timelines preserve that rarity — validating that this pattern must be captured **structurally going forward**, not mined retrospectively.

Full markdown bodies are queryable from the analytics DB for debrief prototyping.

---

## 19. Proposed analytics schema

Applied in `phase6_tables.sql` (conceptual entities; names may refine):

| Entity | Role |
|---|---|
| `competency_expectation` | curriculum_expected_level per exercise/session |
| `exercise_attempt_proto` | first-class attempts |
| `evidence_item` | provenance-bearing evidence |
| `objective_measurement` | metric/tolerance facts |
| `context_snapshot` | auto context |
| `instructor_intervention` | lightweight events |
| `competency_observation` *(logical; stored via evidence/extraction)* | observation layer |
| `competency_assessment` *(logical; engine output)* | assessment layer |
| `competency_state` / history | developmental state + explanation |
| `student_self_assessment` *(designed, not required yet)* | separate perception |
| `ai_interpretation` *(via evidence_source + model version)* | non-authoritative AI |
| `analysis_phase6_*` | NLP population, extractions, QA, scale findings, early warnings |
| `competency_timeline_example` | Phase 6 prototypes |

Provenance rule: every competency conclusion must answer **why**, with linked evidence ids.

Confidence describes **evidence quality** (HIGH/MEDIUM/LOW), never “student confidence 62%” unless self-reported.

---

## 20. Remaining uncertainties

1. **Full LLM-v1 on the 10k population** awaits decryptable API credentials; blended rates understate LLM deficiency sensitivity.
2. **Within-session attempt multiplicity** is weak historically; Recorder attempt markers are required to validate `improved_within_session` properly.
3. **Program 5 / 13 dimension shifts** need human sampling to separate real pedagogy from extractor artifact.
4. **Long-gap pattern** alone is weak under the current next-session definition; combine with prior competency state + consistency.
5. **Identity duplicates** (Phase 3/4) still affect longitudinal student stitching for some trajectories.
6. Exact ACS/SOP tolerance tables per aircraft/exercise must be configured before objective_quality is production-grade.
7. Instructor one-tap independence UX needs field trial for compliance (capture rate).

---

## 21. Recommended Phase 7

1. **Unlock LLM-v1** for remaining high-value hashes (cache-aware); keep heuristic as conservative shadow.
2. **Human QA sample** stratified by program/era/length on scaled outputs; adjudicate program 5/13 shifts.
3. **Configure objective tolerance packs** per exercise family + aircraft; wire Recorder metric contract.
4. **Pilot structured independence + intervention chips** on a small instructor cohort (no full UI redesign yet).
5. **Implement `competency_state_history`** with explainable cards in a **read-only analytics viewer** (still not production student UI).
6. **Debrief draft engine** consuming Recorder evidence + instructor confirms (prototype only).
7. **Optional student self-assessment** A/B on one program.
8. **Do not** introduce composite risk/competency percentages.

---

## Success criteria check

| Question | Answerable now? | Future owner |
|---|---|---|
| What was expected? | Yes (DE/EX/PR/PE) | Curriculum |
| What did the student do? | Partially (grades + narrative evidence) | Attempts + evidence |
| How independently? | Mostly `NOT_OBSERVED` historically; model ready | Instructor structured |
| How well? | Model ready; historically UNKNOWN | Recorder objective_measurement |
| How repeatably? | Rules defined; partial narrative | Derived from attempts |
| Under what conditions? | Partial narrative; model ready | AUTO context |
| What changed over time? | Timelines + trend labels | State history |
| What evidence supports it? | Provenance tables + timelines | evidence_item links |
| What can Recorder determine automatically? | Objective quality, context, attempt bounds, AI candidates | Contract §16 |

**Phase 6 status: complete** for architecture, schema, targeted enrichment, scale re-tests, early-warning patterns, Recorder contract, automation map, state engine, and evidence timelines — without UI, without E-gle mutation, without opaque scores.
