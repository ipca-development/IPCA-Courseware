# Phase 7 — Operational Competency Pilot and Objective Tolerance Framework

**Analysis version:** `phase7-v1`  
**Generated:** 2026-08-11  
**Constraints honored:** no production UI polish; no E-gle writes; no opaque student score; curriculum expectation separate from performance; evidence-traceable states only.

**Key artifacts**
- Schema: `analytics/schema/phase7_tables.sql`
- ETL: `analytics/etl/phase7_01_bootstrap.py`, `phase7_03_pilot_ingest.py`, `phase7_04_assess.py`, `phase7_05_llm_enrich.py`
- Secret injection: `docs/analytics/phase7-secret-injection.md`
- Dev/admin prototype: `public/admin/phase7_competency_pilot.php`
- State engine (reused): `analytics/lib/competency_state_engine.py`

---

## EXECUTIVE CONCLUSION

**Yes — with caveats.** The Phase 6 competency architecture can evaluate real Garmin/Cockpit Recorder telemetry into explainable attempt-level cards (expected / objective metrics / consistency / context / evidence) while defaulting independence to `NOT_OBSERVED` until a single instructor tap.

What works now on real G3X data (**19 flights, 183 attempts, 415 metrics**):

- Versioned tolerance packs (certification vs training PR/PE)
- Telemetry-derived exercise windows for 7 pilot maneuvers
- Multi-metric objective quality (not a single score)
- Auto context (wind, crosswind component, OAT, density altitude, turbulence proxy)
- Deterministic consistency + developmental cards
- Minimal admin one-tap independence + separate intervention events

What blocked full Phase 7 closure:

1. **OpenAI key:** `.env` holds DigitalOcean `EV[...]` ciphertext; this repo cannot decrypt it. LLM enrichment of remaining ~10k targeted hashes requires runtime secret injection ([phase7-secret-injection.md](phase7-secret-injection.md)).
2. **Instructor markers / audio / transcripts** absent from the local pilot file set → boundaries are `TELEMETRY_DERIVED` (not marker-authoritative); AI prompt-detection is structurally ready but not empirically measured.
3. **Expert review** sheets are prepared (**40 PENDING**); human examiner verdicts are not yet collected — system-vs-instructor agreement remains a Phase 8 field task.

**Instructor burden estimate:** ~**71% AUTO**, ~**18% AUTO_WITH_CONFIRMATION**, ~**12% MANUAL** (one independence tap per attempt + rare intervention). Estimated ~**5 minutes** post-flight review vs historical full R/Y/G/B + narrative rewrite (~40% burden reduction estimate).

---

## 1. LLM targeted-enrichment reconciliation

### Secret status

| Item | Result |
|---|---|
| Local `.env` `CW_OPENAI_API_KEY` | `EV[...]` (DO App Platform ciphertext) |
| In-repo decryptor | **None** (by design) |
| Runtime usable key this run | **No** |
| LLM hashes already available | 277 (`LLM_V1_REUSED` from Phase 5/6) |
| Remaining targeted hashes | ≈10,159 |
| Runner ready | `analytics/etl/phase7_05_llm_enrich.py` |

### Recomputed findings (Phase 6 population)

| Finding | heuristic_only | llm_only (n=277) | combined |
|---|---:|---:|---:|
| Deficiency rate | 31.8% (10159) | **89.2%** (277) | 33.3% (10436) |
| Encouraging tone + deficiency | 14.5% | **62.8%** | 15.8% |
| Consistency signal | 35.6% | **57.0%** | 36.1% |
| Context signal | 66.7% | **80.5%** | 67.1% |
| High grade + deficiency | 28.3% (8639) | **86.9%** (206) | 29.6% (8845) |

### Early-warning effect sizes (revised)

| Pattern | heuristic_only | llm_only | combined | Phase 6 headline |
|---|---:|---:|---:|---|
| VARIABLE consistency → later problem | **53.6%** (n=2302) | **62.9%** (n=70) | **53.8%** (n=2372) | ~53.8% vs ~28.9% baseline |
| High grade + deficiency → later problem | **54.1%** (n=2355) | **37.0%** (n=173) | **52.9%** (n=2528) | ~52.9% |
| ≥3/5 deficiency window → later problem | **59.3%** (n=2103) | **61.5%** (n=13) | **58.8%** (n=2299) | ~58.8% |

**Interpretation:** Consistency and repeated-deficiency patterns remain **materially supported**. The high-grade+deficiency → later-problem association is strong on heuristic/combined populations; the LLM-only subset (n=173) shows a **lower** later-problem rate (37%) — do **not** force similarity. Possible causes: selection (LLM reuse was Phase 5 stratified sample, not identical to high-value buckets), small-n volatility, or LLM labeling more deficiencies among sessions that did not later regress. Full LLM enrichment of the targeted set is required before locking the LLM-only effect size.

Heuristic results remain preserved separately (`phase6-extract-v1-heuristic-scaled`).

---

## 2. Tolerance-pack architecture

Versioned packs in `tolerance_pack` / `tolerance_definition` (45 definitions):

| Pack ID | Role |
|---|---|
| `ACS_PPL_ASEL_v1` | FAA ACS Private Pilot ASEL **certification_standard** |
| `IPCA_TRAINING_PR_v1` | Wider **training_expected_tolerance** at PR |
| `IPCA_TRAINING_PE_v1` | Near-ACS **training_expected_tolerance** at PE |

Each definition stores: pack, exercise, metric, target, min, max, unit, phase, expected level, CERTIFICATION_STANDARD vs TRAINING_EXPECTED, hard/soft, provenance.

Pilot evaluation used `IPCA_TRAINING_PE_v1` (not beginner-vs-checkride collapse).

---

## 3. Selected pilot exercises

Canonical codes from `ipca_flight_exercise_catalog` (no duplicate names):

| Code | Attempts in pilot |
|---|---:|
| `steep_turn` | 15 |
| `slow_flight` | 50 |
| `power_off_stall` | 12 |
| `power_on_stall` | 12 |
| `normal_approach` | 63 |
| `normal_landing` | 21 |
| `go_around` | 10 |

Holding/instrument tracking deferred (telemetry support not validated in this local set).

---

## 4. Exercise state-machine definitions

Stored in `exercise_state_machine` with entry / active / sequence / measurement / completion / abort JSON.

**Authoritative boundary policy:** instructor `exercise_marker` is preferred when present. This pilot used **`TELEMETRY_DERIVED`** detectors because local G3X/vault files had no event stream.

Example — steep turn:

- ENTRY: altitude/airspeed established  
- ACTIVE: |bank| ≥ 40° for ≥ 3 s  
- MEASURE: altitude, airspeed, bank, rollout heading  
- EXIT: bank reduced / ~360° heading change  
- ABORT: bank never reached 40° or duration &lt; 3 s  

---

## 5. Objective measurement results

Raw metrics preserved (max deviation, time outside, pct within, within_standard flag). **Never PASS/FAIL only.**

Metric-level within-standard rates (PE training pack):

| Exercise | Within-standard rate | n metrics |
|---|---:|---:|
| steep_turn | 71.7% | 60 |
| slow_flight | 37.3% | 150 |
| power_off_stall | 70.8% | 24 |
| power_on_stall | 70.8% | 24 |
| normal_approach | 11.1% | 126 |
| normal_landing | 95.2% | 21 |
| go_around | 90.0% | 10 |

**Calibration note:** `normal_approach` and `slow_flight` show low within rates — likely **boundary over-detection** and/or **tolerance/target ambiguity** (approach VS band; slow-flight specified airspeed unknown → median used). Do **not** loosen packs blindly; fix boundaries/markers first (see §13).

Example multi-metric steep turn (not collapsed):

- altitude_deviation_ft  
- airspeed_deviation_kt  
- bank_abs_deg  
- rollout_heading_error_deg  

---

## 6. One-tap independence findings

Prototype controls (admin page): **ASSISTED | PROMPTED | INDEPENDENT**

- Default remains **`NOT_OBSERVED`** (not a button).
- Three active choices are sufficient for the pilot; no subcategories added.
- All 183 attempts ingested as `NOT_OBSERVED` / `DEFAULT` until instructor taps in the prototype.

**Finding:** Interaction model is viable. Capture compliance must be measured in a live instructor cohort (Phase 8).

---

## 7. Consistency engine validation

Reused Phase 6 deterministic engine.

Within-flight attempt_repeatability distribution on pilot set:

| State | n attempts |
|---|---:|
| INSUFFICIENT_EVIDENCE (&lt;3 attempts) | 57 |
| VARIABLE | 122 |
| CONSISTENT | 4 |

Rule held: **&lt;3 comparable attempts → insufficient**; mixed within-standard → VARIABLE; all within → CONSISTENT.

Intermediate `DEVELOPING` remains available in the engine for longitudinal/improving trajectories but was not required for the within-session pilot summary.

---

## 8. Longitudinal stability

Rule (documented in engine + Phase 6):

- Qualifying within-standard majority across **≥2 sessions** on different days → `STABLE` / `EMERGING`
- Local pilot subject key = `exercise_code|aircraft_ident` (no student IDs on vault files)
- Most longitudinal results: `NOT_ENOUGH_EVIDENCE` — expected without multi-day student-linked sessions

Production must attach attempts to **student + operational_session** for true longitudinal stability.

---

## 9. Context auto-derivation

From G3X (actual values stored, not only labels):

- wind_speed_kt, wind_direction_deg  
- **crosswind_component_kt** (computed)  
- oat_c, density_altitude_ft  
- turbulence_proxy (roll-rate magnitude)  
- day/night heuristic from UTC hour  
- aircraft_ident  

Example context line: `crosswind_component_kt=-0.5; density_altitude_ft=7428; wind_speed_kt=4.2; turbulence_proxy=1.04`

**Weather-station / microclimate path:** `pilot_environmental_observation` stores lat/lon/OAT/alt/wind samples separately from competency logic for later Coachella Valley environmental analysis.

Airport/runway/ATC: not reliably present in G3X-only ingest → contract gap.

---

## 10. AI prompt-detection experiment

- Pattern library ready (`watch your altitude`, `rudder`, `airspeed`, `go around`, …)
- Local pilot set: **no transcripts** → proposals recorded as `NO_TRANSCRIPT_AVAILABLE_IN_LOCAL_PILOT_SET` / `UNCONFIRMED`
- Policy confirmed: **AI proposes → instructor confirms**; never silently overrides independence

Cannot yet measure manual-input elimination rate.

---

## 11. Human vs system comparison

Not quantitatively measurable until expert reviews are completed.

Disagreement cause taxonomy seeded in `pilot_disagreement`:

incorrect exercise boundary · incorrect tolerance · missing context · telemetry limitation · human judgment dimension · AI interpretation error · instructor override · insufficient evidence

---

## 12. Expert-review disagreements

- **40** attempts queued in `pilot_expert_review` with verdict `PENDING`
- Dev UI supports CORRECT / PARTIALLY_CORRECT / INCORRECT + cause class
- **0** completed examiner reviews in this run → no discrepancy statistics yet

---

## 13. Tolerance calibration findings

| Observation | Likely cause | Action |
|---|---|---|
| Approach within-rate ~11% | Over-broad TELEMETRY_DERIVED windows; VS band strict | Prefer instructor markers; refine detector; keep ACS/training packs |
| Slow flight within-rate ~37% | Unknown “specified airspeed”; median proxy | Capture target IAS at marker / brief |
| Steep turn / stalls ~70% | Plausible | Retain; expert-check sample |
| Landing / go-around high within | Soft proxies | Keep soft; do not treat as certification alone |

**Do not** loosen certification packs to chase agreement.

Training PR pack remains wider than PE/ACS by design (§24 of brief).

---

## 14. Early-warning evidence usefulness

Operationalized messages (no “HIGH RISK” labels):

| Pattern | useful_flag | Notes |
|---|---|---|
| CONSISTENCY_CONCERN | LIKELY_USEFUL | Combined later-problem 53.8% vs ~28.9% baseline |
| HIGH_GRADE_NARRATIVE_DEFICIENCY | LIKELY_USEFUL | Combined 52.9%; LLM-only lower — recheck after full LLM |
| REPEATED_DEFICIENCY_WINDOW | LIKELY_USEFUL | Combined 58.8% |
| LONG_TRAINING_GAP alone | NOISY | Near baseline |
| INDEPENDENT_BUT_INCONSISTENT | UNKNOWN | Historically rare |

Pilot-set VARIABLE consistency attempts also tagged with explainable consistency warnings. **No notifications deployed.**

---

## 15. Cockpit Recorder contract gaps

| Field | Availability |
|---|---|
| operational_session_id | PARTIAL (file-hash proxy locally; production UUID exists) |
| exercise markers / attempt_id | PARTIAL (telemetry-derived; iOS markers exist but not in local files) |
| timestamps / telemetry_reference | AVAILABLE |
| audio / transcript | MISSING (local set) |
| context | AVAILABLE (G3X-derived) |
| instructor_events | MISSING (local set) |
| objective_metrics / versions | AVAILABLE |
| actual_leg_id | MISSING |
| curriculum_expected_level | PARTIAL (defaulted PE) |

Hierarchy preserved conceptually: Reservation → **Operational Session** → Legs → Evidence/Attempts. Competency attaches to session + attempt.

Production MySQL was not queried this run: `CW_DB_PASS` is also `EV[...]` locally.

---

## 16. Instructor workload estimate

| Metric | Value |
|---|---:|
| Manual actions / exercise | **1 tap** (independence) |
| Manual actions / flight (if tap each attempt) | ~9.6 (pilot mix; will drop with session-level confirm) |
| Post-flight minutes (est.) | ~5.4 |
| AUTO fields | **70.6%** |
| AUTO_WITH_CONFIRMATION | **17.6%** |
| MANUAL | **11.8%** |
| vs historical grade burden | **≈ −40%** (estimate) |

Goal directionally met: less evaluation effort than historical full structured grading + free-text rewrite — **if** independence compliance stays one tap and objective metrics stay trusted.

---

## 17. Automation percentage

See §16. Net: **~88%** of competency fields are AUTO or AUTO_WITH_CONFIRMATION; **~12%** require instructor judgment (independence + rare safety interventions).

---

## 18. Remaining human-judgment requirements

Must stay human (or human-confirmed):

- Independence / assistance level  
- Physical intervention & safety takeover  
- CRM / judgment / “feel” not in telemetry  
- Accept/correct AI draft assessments  
- Confirm AI prompt proposals  
- Curriculum nuance (when training tolerance should apply vs certification)

---

## 19. Production-readiness gaps

1. Inject plaintext OpenAI key → finish targeted LLM enrichment  
2. Link production operational sessions + exercise_marker events + audio/transcripts  
3. Complete 25–40 expert reviews; calibrate detectors before pack changes  
4. Student identity on recorder flights for longitudinal stability  
5. Join curriculum expected level from mission/exercise definitions  
6. Field-trial one-tap independence with real instructors  
7. Still **no** production student/instructor debrief UI (Phase 8)

---

## 20. Recommended Phase 8

1. **Secret injection** + complete LLM-v1 on remaining targeted hashes; freeze LLM-only early-warning effect sizes  
2. **Wire production recorder**: operational_session_id, exercise_marker-authoritative attempts, audio/transcript prompt proposals  
3. **Run examiner review clinic** on the 40 queued attempts; classify disagreements; calibrate detectors  
4. **Instructor field pilot** of one-tap independence (measure capture rate & time)  
5. **Design debrief UX** around the validated card (EXPECTED / INDEPENDENCE / OBJECTIVE multi-metric / CONSISTENCY / CONTEXT / TREND / EVIDENCE) — only after the above  
6. Keep **no opaque risk score**; keep evidence patterns as explainable queries  

---

## Success criteria check

| Criterion | Status |
|---|---|
| What was expected? | Yes (PE default; packs support DE/EX/PR/PE) |
| What happened? | Yes (attempt windows + metrics) |
| How independently? | Model ready; default NOT_OBSERVED until tap |
| How accurately? | Yes (multi-metric tolerances) |
| How repeatably? | Yes (engine; often insufficient evidence) |
| Under what conditions? | Yes (numeric auto context) |
| vs previous evidence? | Partial (needs student-linked sessions) |
| Evidence links? | Yes |
| INSUFFICIENT EVIDENCE allowed? | Yes |
| Minimal instructor input? | Designed + prototyped; not field-proven |

**Phase 7 status:** architecture and recorder-based prototype **validated on real G3X data**; LLM unlock and expert/field validation remain explicit open gates before production debrief design.
