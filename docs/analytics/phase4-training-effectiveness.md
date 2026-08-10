# Phase 4 — Deep Training Effectiveness Analysis

**Analysis version:** `phase4-v1`  
**Generated:** 2026-08-10T22:00:05Z  
**Source:** Canonical analytics SQLite (`storage/analytics/egle_training_analytics.sqlite`)  
**Constraints honored:** no UI; no bulk narrative NLP; no E-gle source writes.

## EXECUTIVE FINDINGS

Ranked by operational importance and strength of evidence. Intentional repetition (proficiency, accumulation, briefing, checks, solos) is **not** treated as curriculum failure.

### 1. Training continuity is a first-order effectiveness lever
- **Evidence:** Program-controlled logits on log1p(days since previous training) predict incomplete sessions, progression-mission repeats, unmet required levels, and exercise regressions.
- **Magnitude:** Incomplete 8.2% (0–2d, n=13795) → 18.8% (21+d, n=2110); repeats 17.5% → 29.5%. Progression incomplete OR=1.36 (CI 1.29–1.43); progression repeat OR=1.31; regression OR=1.42.
- **Population/sample size:** ~24k sessions with prior gap; ~187k exercise rows in gap models
- **Confidence:** HIGH
- **Likely interpretation:** Calendar interruption creates restart friction and measurable skill softening—not a cosmetic scheduling preference.
- **Alternative explanations:** Struggling students may also space training (selection). Residual confounding remains.
- **Operational significance:** Continuity should become an explicit scheduling policy variable, especially for progression missions.
- **Recommended next investigation/action:** Fit spline/threshold models by phase and modality; pilot continuity SLAs for early PPL/ACP blocks.

### 2. Raw repeat bottlenecks are structurally contaminated; filter to PROGRESSION_MISSION
- **Evidence:** Session role mix: PROGRESSION_MISSION=14744, BRIEFING_OR_GROUND_EVENT=4648, PROFICIENCY_MISSION=3306, CHECK_EVENT=3299, SOLO_EVENT=1467, ACCUMULATION_MISSION=372. Mission classification stored in analysis_mission_role with confidence/reason.
- **Magnitude:** Naive top-repeat lists are dominated by briefing/proficiency/accumulation design. Progression bottlenecks concentrate in IR interceptions/departures, early touch-&-go, and exam prep.
- **Population/sample size:** 27836 usable sessions; 714 classified missions
- **Confidence:** HIGH
- **Likely interpretation:** Intentional curriculum repetition must not be scored as inefficiency.
- **Alternative explanations:** Some PROGRESSION_MISSION labels remain MEDIUM confidence.
- **Operational significance:** Operational bottleneck views must default to PROGRESSION_MISSION.
- **Recommended next investigation/action:** Raise role confidence using stage/phase metadata and next/alternative graphs.

### 3. PE usually means durable competency—but not uniformly across exercises
- **Evidence:** After first PE-equivalent (B), later same-exercise observations: mean stable-PE ≈94.5%; one-time regression ≈3.1%; repeated ≈2.4%. Softening is mostly PE→PR (≈5.1%), rarely PE→EX/DE.
- **Magnitude:** IR vertical-nav / holding / go-around / interception items often show <40% stable PE when reobserved, typically softening to PR rather than collapsing.
- **Population/sample size:** 1550 exercise×program cells with ≥10 reobservations; 92119 PE reaches; 31406 reobserved pairs
- **Confidence:** HIGH
- **Likely interpretation:** PE is a strong durability signal for many ACS items, but for some IR skills it behaves more like successful execution in context.
- **Alternative explanations:** Later marks may occur in harder missions or with different instructors (contextual drop).
- **Operational significance:** Future evaluation must treat PE as exercise-dependent, not a single universal meaning.
- **Recommended next investigation/action:** Tag RAW vs CONTEXTUAL regression using mission difficulty + instructor/environment change.

### 4. Replacement curricula are MIXED; newer is not automatically better—except PE durability trends better
- **Evidence:** Compared by version_code (PPL_OLD→PPLA, MEP_OLD→MEPNEW, IR_LEGACY→IRNEW_*, CPLA_OLD→CPLAUPRT), not display name.
- **Magnitude:** PPLA: more sessions/student, similar calendar span (MIXED/possible denser structure). MEPNEW: fewer flight hours, more sim (modality shift). IRNEW_*: smaller n, fewer sessions but gap issues on ME. CPLAUPRT: longer/more sessions (content expansion). Fixed PE-stability comparisons show CLEAR IMPROVEMENT for newer versions where reobservation exists.
- **Population/sample size:** See Section 10 metric tables
- **Confidence:** MEDIUM
- **Likely interpretation:** Structural redesign changed modality and recording; hours alone mislead. PE durability appears better in newer generations.
- **Alternative explanations:** Cohort mix, grading-era differences, and incomplete identity resolution confound.
- **Operational significance:** Judge curricula on multidimensional efficiency + durability.
- **Recommended next investigation/action:** Recompute completion-population outcomes after resolving high-impact identity groups.

### 5. APS MCC PE requirements look like advanced challenge standards, not curriculum failure
- **Evidence:** APS MCC PE not-met ≈18.8% overall; top items 30–58% not-met (Failure Management, System Abnormal/Emergency, Problem Solving/DM, Fuel/Diversion/TEM, CRM family). Attempt-2 met ≈100% where retries exist.
- **Magnitude:** 68 students; 26995 exercise attempts
- **Population/sample size:** n_students=68
- **Confidence:** HIGH
- **Likely interpretation:** PE functions as stretch/challenge standard with strong retry recovery.
- **Alternative explanations:** Instructor calibration or late-program selection could contribute.
- **Operational significance:** Do not brand APS MCC as failing based on first-attempt PE miss rates alone.
- **Recommended next investigation/action:** Compare instructor PE rates and check outcomes; sample narratives for these PE items.

### 6. Checkpoint difficulty associates with recent training friction
- **Evidence:** Univariate logits on last-3-session features for CHECK_EVENT sessions.
- **Magnitude:** Prior incomplete OR≈3.85; prior repeat OR≈2.48; prior gap and prior below-required also positive.
- **Population/sample size:** n≈3262 check sessions
- **Confidence:** MEDIUM–HIGH association; LOW causation
- **Likely interpretation:** Recent incomplete/repeat burden is a readiness signal.
- **Alternative explanations:** Checks may be scheduled after struggle (reverse causation).
- **Operational significance:** Useful later for readiness briefings—not a student risk score yet.
- **Recommended next investigation/action:** Multivariate models with program/phase controls.

### 7. Instructor calibration patterns are detectable without rankings
- **Evidence:** Pattern signals among sampled instructors: UNCLEAR=18, FAST_ADVANCEMENT=9, STRICT_SIGNAL=6, POSSIBLE_OVERTRAINING=2, POSSIBLE_PREMATURE_ADVANCEMENT=1.
- **Magnitude:** FAST_ADVANCEMENT often pairs low progression-repeat with low downstream problems; STRICT_SIGNAL lower PE share with strong downstream stability; POSSIBLE_OVERTRAINING = high repeat without downstream benefit; rare POSSIBLE_PREMATURE_ADVANCEMENT.
- **Population/sample size:** 36 instructors meeting sample thresholds
- **Confidence:** MEDIUM
- **Likely interpretation:** Calibration styles differ; multiple styles can be downstream-valid.
- **Alternative explanations:** Student/program mix and duplicate instructor IDs confound person-level inference (Willy Rozendaal IDs 59 & 70).
- **Operational significance:** Use for standardization discussion—never league tables.
- **Recommended next investigation/action:** Resolve Willy Rozendaal IDs; recompute calibration on merged instructor identity.

### 8. Instructor changes are common and mildly linked to incompleteness
- **Evidence:** 12595 instructor-change sessions: incomplete 13.0% vs 10.6% without change.
- **Magnitude:** ~2.4 percentage-point absolute difference
- **Population/sample size:** large n
- **Confidence:** MEDIUM
- **Likely interpretation:** May reflect struggling-student switching and/or fresh-eye detection—not proof switching harms learning.
- **Alternative explanations:** No matched trajectory counterfactuals yet.
- **Operational significance:** Handoff quality matters more than forbidding switches.
- **Recommended next investigation/action:** Difference-in-differences around switches controlling prior trajectory.

### 9. Hidden prerequisites appear around path control → approach/go-around/ACS
- **Evidence:** 12 temporally ordered candidates (A difficult before first exposure to B).
- **Magnitude:** Altitude/heading ACS tolerances predict later entry-altitude, go-around timing, touchdown speed, and Up-to-ACS difficulty (risk differences ~0.24–0.78).
- **Population/sample size:** top candidates n≈35–71 students
- **Confidence:** MEDIUM–HIGH
- **Likely interpretation:** Observed skill dependency broadly matches curriculum sequencing.
- **Alternative explanations:** Same-mission co-grading and zero-baseline lift inflation remain risks.
- **Operational significance:** Evidence for future evaluation dimensions and sequence review—not automatic rewrite.
- **Recommended next investigation/action:** Expand within-stage prerequisite search; validate with narratives.

### 10. Co-difficulty clusters support data-first competency domains
- **Evidence:** 150 high-support co-difficulty pairs retained.
- **Magnitude:** Recurring clusters: stabilized approach, crosswind, traffic pattern, steep turns/entry altitude, manufacturer approach speeds.
- **Population/sample size:** program-stratified supports
- **Confidence:** MEDIUM
- **Likely interpretation:** Weaknesses travel together; domains can be induced from data.
- **Alternative explanations:** Shared mission moments can create artificial co-occurrence.
- **Operational significance:** Do not impose taxonomy labels yet.
- **Recommended next investigation/action:** Graph-cluster with mission deconfounding.

### 11. Instructors routinely adapt the nominal sequence
- **Evidence:** sctr_next set on 86.9% of sessions; alternative on 48.9%; returns after intervening training ≈7.2%.
- **Magnitude:** ~1 in 14 sessions is a return detour; alternatives used nearly half the time.
- **Population/sample size:** n=27836
- **Confidence:** HIGH descriptive / MEDIUM interpretive
- **Likely interpretation:** Flexibility appears intentional; return hotspots align with progression bottlenecks.
- **Alternative explanations:** Cannot yet separate remediation from enrichment without narratives.
- **Operational significance:** Map high-return nodes as soft gates.
- **Recommended next investigation/action:** Join next/alternative targets to mission roles and outcomes.

### 12. Historical era effects are material—do not pool 2014 with 2026
- **Evidence:** Incomplete and progression-repeat rates vary by year; volume ramps after 2016; progression-repeat elevated ~2024–2025.
- **Magnitude:** See Section 13
- **Population/sample size:** full usable history
- **Confidence:** MEDIUM
- **Likely interpretation:** Practice, fleet/sim mix, and curriculum transitions changed the measured system.
- **Alternative explanations:** COVID-era and grading completeness differences may dominate some years.
- **Operational significance:** Always stratify management metrics by year/cohort.
- **Recommended next investigation/action:** Align era breaks to known curriculum cutovers.

### 13. ACP ACS tolerances are high-value future telemetry targets; bulk not-met is modest
- **Evidence:** ACP tolerance-tagged attempts n=63896 (60 students); overall not-met ≈1.64% (EASA 52818, FAA 11078).
- **Magnitude:** Difficulty concentrates in specific PE ACS / Up-to-ACS items. Objective candidates: NO=21903, PARTIAL=7191, YES=4018.
- **Population/sample size:** see analysis_objective_measurement_candidate
- **Confidence:** MEDIUM
- **Likely interpretation:** Numeric altitude/heading/airspeed/bank items are Cockpit Recorder-ready.
- **Alternative explanations:** Tolerance tagging may miss some ACS skills.
- **Operational significance:** Prioritize YES candidates for objective evaluation pilots.
- **Recommended next investigation/action:** Link ACS misses/regressions to ACP progress-check outcomes.

### 14. Efficiency must stay multidimensional—fewer hours is not the objective
- **Evidence:** Rule-based trajectories: UNKNOWN=387, NORMAL_STABLE=318, REPEATED_REGRESSION=183, FAST_STABLE=109, LATE_PLATEAU=71, SLOW_STABLE=38, HIGH_REPEAT=20, TRAINING_GAP_AFFECTED=15.
- **Magnitude:** FAST_STABLE vs REPEATED_REGRESSION vs TRAINING_GAP_AFFECTED are operationally distinct; hours cannot rank them.
- **Population/sample size:** 1141 student×program trajectories
- **Confidence:** MEDIUM
- **Likely interpretation:** Slightly slower but stable progress can outperform fast progress that later regresses.
- **Alternative explanations:** Labels are interpretable first-pass rules; UNKNOWN is large due to short histories; no black-box clustering yet.
- **Operational significance:** Keep progression, continuity, repeat burden, regression, and checkpoint success separate.
- **Recommended next investigation/action:** Compare rule labels to unsupervised clusters later; still no single score.

### 15. Resolve only high-impact duplicate identities; leave the rest
- **Evidence:** 17 candidate groups / 35 member rows in bridge_student_identity. Top groups affect ~200 sessions; some collisions look instructor/admin-adjacent and must not auto-merge as students.
- **Magnitude:** Material for a handful of trajectory/curriculum comparisons only.
- **Population/sample size:** 17 groups
- **Confidence:** MEDIUM
- **Likely interpretation:** Selective manual resolution beats blanket merging.
- **Alternative explanations:** False merges distort progression more than leaving splits.
- **Operational significance:** Do not block analytics conclusions on full identity cleanup.
- **Recommended next investigation/action:** Manual review of top volume groups; treat Willy Rozendaal instructor IDs separately.

---

## 1. Curriculum effectiveness

Effectiveness is multidimensional: progression per session/hour, **PROGRESSION_MISSION** repeat burden, continuity, below-required rates, PE durability, checkpoint outcomes, and calendar time. Lower session counts alone are **not** success.

### Mission eligibility layer

| Role | Missions | Confidence mix |
|---|---:|---|
| `ACCUMULATION_MISSION` | 2 | HIGH:2 |
| `BRIEFING_OR_GROUND_EVENT` | 136 | HIGH:129, MEDIUM:7 |
| `CHECK_EVENT` | 72 | HIGH:5, MEDIUM:67 |
| `PROFICIENCY_MISSION` | 58 | HIGH:1, MEDIUM:57 |
| `PROGRESSION_MISSION` | 411 | MEDIUM:411 |
| `SOLO_EVENT` | 35 | HIGH:3, MEDIUM:32 |

Fields: `mission_role`, `mission_role_confidence`, `mission_role_reason` (raw mission identity preserved).

## 2. Student progression

| Trajectory | Students×programs |
|---|---:|
| `UNKNOWN` | 387 |
| `NORMAL_STABLE` | 318 |
| `REPEATED_REGRESSION` | 183 |
| `FAST_STABLE` | 109 |
| `LATE_PLATEAU` | 71 |
| `SLOW_STABLE` | 38 |
| `HIGH_REPEAT` | 20 |
| `TRAINING_GAP_AFFECTED` | 15 |

Descriptors only—not judgments of student ability. Stored in `analysis_student_trajectory`.

## 3. Training continuity

### Descriptive dose response

| Gap (days) | % incomplete | % mission repeat | n |
|---|---:|---:|---:|
| 0-2 | 8.2% | 17.5% | 13795 |
| 3-5 | 12.1% | 18.5% | 5084 |
| 6-10 | 15.7% | 20.1% | 4016 |
| 11-20 | 18.3% | 21.9% | 2338 |
| 21+ | 18.8% | 29.5% | 2110 |

### Controlled association

| Stratum | Outcome | OR | 95% CI | p | n |
|---|---|---:|---|---:|---:|
| all_sessions | incomplete | 1.268 | 1.22–1.32 | 2.4e-36 | 23988 |
| progression_missions | incomplete | 1.359 | 1.29–1.43 | 2.9e-28 | 12758 |
| progression_missions_repeat | repeat | 1.312 | 1.25–1.38 | 1.1e-26 | 12758 |
| exercise_not_met | not_met | 1.145 | 1.09–1.20 | 3.3e-08 | 186666 |
| exercise_regressed | regressed | 1.415 | 1.32–1.51 | 9.8e-25 | 186666 |

Reading: degradation is visible by 3–5 days and continues through 11–20; repeats jump further at 21+. These are planning thresholds for experiments—not hard policy cut-points yet.

## 4. Competency development

| From | To | n | rate |
|---|---|---:|---:|
| PE | PE | 68671 | 95.3% |
| PR | PR | 61246 | 88.9% |
| EX | EX | 8949 | 64.2% |
| PR | PE | 5730 | 8.3% |
| EX | PR | 3983 | 28.6% |
| PE | PR | 3160 | 4.4% |
| PR | EX | 1867 | 2.7% |
| EX | PE | 920 | 6.6% |
| DE | DE | 861 | 54.4% |
| DE | EX | 336 | 21.2% |
| DE | PR | 241 | 15.2% |
| PE | EX | 207 | 0.3% |
| DE | PE | 144 | 9.1% |
| EX | DE | 88 | 0.6% |
| PR | DE | 57 | 0.1% |
| PE | DE | 41 | 0.1% |

Plateaus dominate (PE→PE ~95%, PR→PR ~89%). Main upward paths: EX→PR (~29%), PR→PE (~8%). Primary softening: PE→PR (~4.4%).

## 5. Competency stability / regression

After first PE-equivalent (B), later observations of the same exercise (any grade): mean stability ≈**94.5%** (cells with ≥10 reobservations).

| Exercise | Stable | 1×reg | Repeated | PE→PR | n_reobs |
|---|---:|---:|---:|---:|---:|
| Compliance with Syllabus | 0% | 9% | 91% | 100% | 11 |
| Encourages student interaction | 0% | 18% | 82% | 100% | 11 |
| Applies as many senses as possible to aid remembering | 0% | 10% | 90% | 100% | 10 |
| Praise and encouraging, motivating student | 0% | 18% | 82% | 100% | 11 |
| Vertical navigation follow-up | 5% | 45% | 50% | 91% | 22 |
| Altitude checks time/distance | 6% | 44% | 50% | 94% | 18 |
| Descent and approach checklist | 6% | 47% | 47% | 94% | 17 |
| Holding calculations | 6% | 44% | 50% | 88% | 16 |
| Starting descent at FAF | 7% | 33% | 60% | 93% | 15 |
| Personal appearance | 8% | 15% | 77% | 92% | 13 |
| Technical accuracy and understanding | 8% | 8% | 83% | 92% | 12 |
| Maintaining VS | 8% | 29% | 62% | 83% | 24 |

### Does PE mean “Perform”?

- Many exercises: **yes—durable** (high PE→PE).
- Some IR path/holding/G-A/interception families: PE often softens to PR on re-test → closer to successful execution / context-bound mastery.
- Severe PE→DE collapses are rare.

## 6. Exercise learning curves

Table `analysis_exercise_learning_curve` stores attempt-wise required-level met rates. Additional repetition helps where attempt-2/3 met-rates rise (many PR items). Where attempt-2 falls or plateaus (selected APS MCC / Up-to-ACS PE items), methodology redesign beats more of the same.

## 7. Hidden prerequisites

| A (earlier difficulty) | B (later) | n | effect | conf |
|---|---|---:|---:|---|
| (S) Maintain the specified altitude, Â±100 feet; specif | (K) Maintain a specified heading, Â±10 if in straight f | 38 | 0.78 | HIGH |
| (S) Maintain a specified heading, Â±10 if in straight f | (S) Maintain the entry altitude Â±100 feet, airspeed Â± | 36 | 0.74 | HIGH |
| (S) Maintain a specified heading, Â±10 if in straight f | (S) Execute a timely go-around if the approach cannot b | 35 | 0.71 | HIGH |
| (S) Maintain the specified altitude, Â±100 feet; specif | (S) Maintain the entry altitude Â±100 feet, airspeed Â± | 36 | 0.68 | HIGH |
| (S) Maintain a specified heading, Â±10 if in straight f | (S) Touch down at speed recommended by manufacturer (PR | 37 | 0.66 | HIGH |
| (S) Maintain a specified heading, Â±10 if in straight f | (S) Make smooth, timely, and correct control inputs dur | 37 | 0.59 | HIGH |
| (S) Maintain the entry altitude Â±100 feet, airspeed Â± | (S) Execute a timely go-around if the approach cannot b | 35 | 0.50 | HIGH |
| (S) Maintain the specified altitude, Â±100 feet; specif | (S) Touch down at speed recommended by manufacturer (PR | 36 | 0.47 | HIGH |
| (S) Maintain the specified altitude, Â±100 feet; specif | (S) Make smooth, timely, and correct control inputs dur | 36 | 0.39 | MEDIUM |
| (S) Maintain the entry altitude Â±100 feet, airspeed Â± | Up to ACS (PE) | 60 | 0.36 | HIGH |
| (K) A stabilized approach, to include energy management | Up to ACS (PE) | 71 | 0.32 | HIGH |
| (S) Establish the recommended approach and landing conf | Up to ACS (PE) | 71 | 0.24 | MEDIUM |

## 8. Instructor calibration

Analytical pattern signals only—not accusations or rankings.

| Pattern | Count |
|---|---:|
| `UNCLEAR` | 18 |
| `FAST_ADVANCEMENT` | 9 |
| `STRICT_SIGNAL` | 6 |
| `POSSIBLE_OVERTRAINING` | 2 |
| `POSSIBLE_PREMATURE_ADVANCEMENT` | 1 |

Willy Rozendaal appears as instructor_id **59** (478 sessions) and **70** (21)—resolve before person-level conclusions.

## 9. Instructor transitions

Incomplete on change 12.99% vs 10.60% without change (n_change=12595). Association only.

## 10. Curriculum / version comparisons

Compared by `version_code`, not display name.

### PPL: `PPL_OLD` → `PPLA` — **MIXED RESULT**

- **sessions_per_student**: 38.833 → 54.957 — **CLEAR DETERIORATION** (HIGH); n=84/116
- **flight_hours_per_student**: 39.589 → 45.006 — **MIXED RESULT** (MEDIUM); n=84/116
- **sim_hours_per_student**: 2.551 → 2.722 — **NO MEANINGFUL DIFFERENCE** (MEDIUM); n=84/116
- **median_gap_days**: 14.162 → 6.409 — **NO MEANINGFUL DIFFERENCE** (MEDIUM); n=84/116
- **progression_repeat_sessions_per_student**: 3.513 → 2.873 — **MIXED RESULT** (MEDIUM); n=78/110
- **below_required_rate**: n/a → 0.018 — **INSUFFICIENT EVIDENCE** (LOW); n=82/116
- **calendar_days_per_student**: 343.095 → 344.060 — **NO MEANINGFUL DIFFERENCE** (MEDIUM); n=84/116
- **pe_stability_rate**: 0.308 → 0.975 — **CLEAR IMPROVEMENT** (MEDIUM); n=82/116

### MEP: `MEP_OLD` → `MEPNEW` — **MIXED RESULT**

- **sessions_per_student**: 10.787 → 11.447 — **CLEAR DETERIORATION** (MEDIUM); n=47/85
- **flight_hours_per_student**: 11.813 → 8.986 — **CLEAR IMPROVEMENT** (HIGH); n=47/85
- **sim_hours_per_student**: 0.772 → 2.139 — **CLEAR DETERIORATION** (HIGH); n=47/85
- **median_gap_days**: 4.213 → 5.318 — **CLEAR DETERIORATION** (MEDIUM); n=47/85
- **progression_repeat_sessions_per_student**: 0.478 → 0.294 — **MIXED RESULT** (MEDIUM); n=46/85
- **below_required_rate**: n/a → 0.005 — **INSUFFICIENT EVIDENCE** (LOW); n=47/85
- **calendar_days_per_student**: 177.638 → 210.447 — **MIXED RESULT** (MEDIUM); n=47/85
- **pe_stability_rate**: 0.169 → 0.992 — **CLEAR IMPROVEMENT** (MEDIUM); n=47/85

### IR: `IR_LEGACY` → `IRNEW_SE` — **INSUFFICIENT EVIDENCE**

- **sessions_per_student**: 37.572 → 27.600 — **INSUFFICIENT EVIDENCE** (LOW); n=138/10
- **flight_hours_per_student**: 15.149 → 12.570 — **INSUFFICIENT EVIDENCE** (LOW); n=138/10
- **sim_hours_per_student**: 28.664 → 23.990 — **INSUFFICIENT EVIDENCE** (LOW); n=138/10
- **median_gap_days**: 10.333 → 5.250 — **INSUFFICIENT EVIDENCE** (LOW); n=138/10
- **progression_repeat_sessions_per_student**: 5.413 → 5.000 — **INSUFFICIENT EVIDENCE** (LOW); n=138/10
- **below_required_rate**: 0.026 → 0.039 — **INSUFFICIENT EVIDENCE** (LOW); n=138/10
- **calendar_days_per_student**: 449.130 → 240.700 — **INSUFFICIENT EVIDENCE** (LOW); n=138/10
- **pe_stability_rate**: 0.322 → 0.887 — **CLEAR IMPROVEMENT** (MEDIUM); n=138/10

### IR: `IR_LEGACY` → `IRNEW_ME` — **MIXED RESULT**

- **sessions_per_student**: 37.572 → 29.679 — **CLEAR IMPROVEMENT** (LOW); n=138/28
- **flight_hours_per_student**: 15.149 → 12.729 — **CLEAR IMPROVEMENT** (LOW); n=138/28
- **sim_hours_per_student**: 28.664 → 24.668 — **CLEAR IMPROVEMENT** (LOW); n=138/28
- **median_gap_days**: 10.333 → 70.768 — **CLEAR DETERIORATION** (LOW); n=138/28
- **progression_repeat_sessions_per_student**: 5.413 → 3.654 — **CLEAR IMPROVEMENT** (LOW); n=138/26
- **below_required_rate**: 0.026 → 0.019 — **NO MEANINGFUL DIFFERENCE** (LOW); n=138/26
- **calendar_days_per_student**: 449.130 → 297.071 — **CLEAR IMPROVEMENT** (LOW); n=138/28
- **pe_stability_rate**: 0.322 → 0.921 — **CLEAR IMPROVEMENT** (MEDIUM); n=138/26

### CPL: `CPLA_OLD` → `CPLAUPRT` — **MIXED RESULT**

- **sessions_per_student**: 7.806 → 15.529 — **CLEAR DETERIORATION** (MEDIUM); n=36/70
- **flight_hours_per_student**: 14.264 → 15.443 — **CLEAR DETERIORATION** (MEDIUM); n=36/70
- **sim_hours_per_student**: 0.000 → 0.024 — **NO MEANINGFUL DIFFERENCE** (MEDIUM); n=36/70
- **median_gap_days**: 4.071 → 2.471 — **CLEAR IMPROVEMENT** (MEDIUM); n=36/70
- **progression_repeat_sessions_per_student**: 0.333 → 0.725 — **CLEAR DETERIORATION** (MEDIUM); n=36/69
- **below_required_rate**: n/a → 0.006 — **INSUFFICIENT EVIDENCE** (LOW); n=25/70
- **calendar_days_per_student**: 57.444 → 143.229 — **CLEAR DETERIORATION** (MEDIUM); n=36/70
- **pe_stability_rate**: 0.500 → 0.967 — **CLEAR IMPROVEMENT** (LOW); n=25/70

**Reading guide:** MEPNEW’s lower flight hours with higher sim is modality redesign. PPLA’s higher session count with similar calendar days suggests denser structure/recording. CPLAUPRT’s longer span likely reflects UPRT/content expansion. PE-stability improvements in newer versions are among the clearest positive signals.

## 11. Program-specific bottlenecks

| Program | Progression mission | Extra sessions/student | n |
|---|---|---:|---:|
| EASA SE Instrument Rating - IR(A) | 3-1-1 | Exam Preparation - (2.0h SE/FSTD) | 1.83 | 17 |
| Instrument Rating - IR(A) | 2-1-1 | VOR/NDB Interceptions  | 1.79 | 327 |
| Instrument Rating - IR(A) | 2-1-2 | Flying Instrument Departures  | 1.71 | 298 |
| EASA ME Instrument Rating - IR(A) | 2-1-2 | VOR/NDB Interceptions - (5.0h ME/FSTD) | 1.71 | 46 |
| EASA SE Instrument Rating - IR(A) | 2-1-2 | VOR/NDB Interceptions - (4.0h SE/FSTD) | 1.60 | 13 |
| EASA Airline Career Program - (ACP) | 10-1-2 | VOR/NDB Interceptions - (5.0h ME/FSTD) | 1.60 | 39 |
| EASA ME Instrument Rating - IR(A) | 2-1-3 | Flying Instrument Departures - (4.0h ME/FSTD) | 1.50 | 40 |
| EASA SE Instrument Rating - IR(A) | 2-1-3 | Flying Instrument Departures - (4.0h SE/FSTD) | 1.33 | 14 |
| Instrument Rating - IR(A) | 3-1-1 | Exam Preparation  | 1.25 | 214 |
| FAA Airline Career Program - (ACP) | 1-3-5 | Your first Touch & Go session - (1.0h DUAL) | 1.14 | 15 |
| EASA Airline Career Program - (ACP) | 10-1-3 | Flying Instrument Departures - (4.0h ME/FSTD) | 1.13 | 32 |
| Flight Instructor - FI(A) | 1-1-1 | Flight Instructor Refresher (FSTD) | 1.05 | 39 |
| EASA SE Instrument Rating - IR(A) | 3-1-2 | Exam Preparation - (1.0h SE/IR DUAL) | 1.00 | 12 |
| EASA SE Instrument Rating - IR(A) | 1-1-4 | Flying Patterns - (1.0h SE/FSTD) | 1.00 | 12 |
| Private Pilot - PPL(A) | 3-1-2 | Exam Preparation | 0.90 | 129 |
| EASA Airline Career Program - (ACP) | 1-3-5 | Your first Touch & Go session - (1.0h DUAL) | 0.81 | 67 |
| FAA Airline Career Program - (ACP) | 1-3-9 | Touch and goâ€™s at a Towered Airport - (1.0h DUAL) | 0.80 | 9 |
| Private Pilot - PPL(A) Old | 1-3-8 | Handling the unexpected  | 0.80 | 97 |
| EASA Airline Career Program - (ACP) | 1-3-9 | Touch and goâ€™s at a Towered Airport - (1.0h DUAL) | 0.77 | 62 |
| EASA Airline Career Program - (ACP) | 11-1-2 | Exam Preparation - (1.0h ME/IR DUAL) | 0.70 | 17 |

**Universal vs specific:** IR interceptions/departures/exam prep recur across IR and ACP IR blocks (universal instrument-training pressure). Early touch-&-go appears in EASA/FAA ACP. PPL exam prep is a late gate. APS MCC PE stretch difficulty is program-specific.

## 12. Checkpoint readiness signals

| Predictor (last 3 sessions) | OR | 95% CI | p | n |
|---|---:|---|---:|---:|
| prior_incomplete | 3.85 | 2.71–5.46 | 4.1e-14 | 3262 |
| prior_repeat | 2.48 | 1.86–3.31 | 7.5e-10 | 3262 |
| prior_gap | 1.01 | 1.00–1.01 | 0.0088 | 3262 |
| prior_below | 1.04 | 1.01–1.07 | 0.0049 | 3262 |

No student risk score in Phase 4.

## 13. Historical changes

| Year | Sessions | Incomplete | Progression repeat |
|---:|---:|---:|---:|
| 2014 | 18 | 0.0% | 0.0% |
| 2015 | 56 | 0.0% | 11.8% |
| 2016 | 1004 | 12.9% | 14.2% |
| 2017 | 1774 | 8.8% | 13.0% |
| 2018 | 2116 | 5.9% | 8.9% |
| 2019 | 2581 | 8.7% | 10.2% |
| 2020 | 2799 | 5.9% | 6.5% |
| 2021 | 2669 | 5.1% | 7.2% |
| 2022 | 2470 | 6.2% | 6.5% |
| 2023 | 3776 | 10.0% | 5.5% |
| 2024 | 3664 | 6.0% | 15.3% |
| 2025 | 3294 | 12.9% | 15.5% |
| 2026 | 1615 | 11.2% | 13.8% |

## 14. Future Cockpit Recorder measurement opportunities

- **NO**: 21903 exercises
- **PARTIAL**: 7191 exercises
- **YES**: 4018 exercises

**YES** seeds: altitude ±100/150 ft, datum pitch/bank, rate-one turns, heading/HSI/HDG-bug items. **PARTIAL**: checklist/ATC/SOP (audio + procedure). ACP ACS tolerances are priority prototypes.

## 15. Data limitations

- Completion/license outcomes are imperfect; calendar span ≠ completion.
- Instructor/program confounding incomplete; some logits had convergence warnings.
- Prerequisite lift can explode at near-zero baseline risk—prefer effect size + p.
- Narrative NLP not run (405 stratified samples only).
- 17 duplicate identity groups mostly unmerged.
- Many PROGRESSION_MISSION labels are MEDIUM confidence.
- Contextual vs raw regression is only proxied so far.

## UNEXPECTED FINDINGS

### PE reobservation often co-occurs with instructor change or long gap when regression appears
- **Magnitude:** contextual_share_among_all_pe_students=0.0063101591814855385
- **Evidence:** among first-PE students with later marks, share whose first regression co-occurs with instructor change or gap>=14d (population-level proxy)
- **n:** 343256
- **Confidence:** MEDIUM
- **Notes:** Distinguishes raw regression vs contextual performance drop at aggregate level only

### Training continuity shows graded incomplete-rate dose response
- **Magnitude:** incomplete rises from ~8% (0-2d) to ~19% (21+d)
- **Evidence:** large/persistent
- **n:** 27836
- **Confidence:** HIGH
- **Notes:** Confirmed with program-controlled logit

### Multiple instructor IDs share identical names
- **Magnitude:** 1 name collisions
- **Evidence:** identity confounder
- **n:** 2
- **Confidence:** HIGH
- **Notes:** Resolve before treating calibration deltas as person-level

### Naive bottlenecks are contaminated by intentional accumulation/proficiency roles
- **Magnitude:** {'PROGRESSION_MISSION': 14744, 'BRIEFING_OR_GROUND_EVENT': 4648, 'PROFICIENCY_MISSION': 3306, 'CHECK_EVENT': 3299, 'SOLO_EVENT': 1467, 'ACCUMULATION_MISSION': 372}
- **Evidence:** methodological
- **n:** 27836
- **Confidence:** HIGH
- **Notes:** PROGRESSION_MISSION filter required

### APS MCC PE requirements form an extreme difficulty cluster
- **Magnitude:** PE not-met=18.77%
- **Evidence:** program-specific
- **n:** 26995
- **Confidence:** HIGH
- **Notes:** Likely advanced challenge design

### Ground/sim-brief modalities are a major share of recorded events
- **Magnitude:** {'FLIGHT': 0.48, 'FNPT': 0.246, 'LB': 0.154, 'SAB': 0.12}
- **Evidence:** structural
- **n:** 27836
- **Confidence:** HIGH
- **Notes:** Efficiency metrics must include LB/SAB pedagogy

### Instructor-change sessions have different incomplete rates than continuity sessions
- **Magnitude:** {"n_change_sessions": 12595, "incomplete_on_change": 0.1298928146089718, "incomplete_no_change": 0.10598047192839707}
- **Evidence:** standardization signal
- **n:** 12595
- **Confidence:** MEDIUM
- **Notes:** May reflect struggling-student selection or calibration differences

### Material share of sessions are returns to a previously seen mission after intervening training
- **Magnitude:** pct_returned_later=7.21%
- **Evidence:** sequence deviation
- **n:** 27836
- **Confidence:** MEDIUM
- **Notes:** Nominal sequence often interrupted by remediation/alternates

Additional deterministic surprises:

1. **LB+SAB ≈27% of recorded sessions** — flight-hours-only efficiency stories are incomplete.
2. **Alternative mission field set nearly half the time** — curriculum graph is actively edited.
3. **PE→PR softening dominates “regression”** — standard-holding under pressure, not catastrophic loss.
4. **2024–2025 progression-repeat rise** coincides with transitions/volume growth — investigate as era effect.

## What history says about DE/EX/PR/PE and R/Y/G/B (no redesign yet)

### Strengths
- Ordinal R/Y/G/B ↔ DE/EX/PR/PE supports transitions, curves, and stability.
- PE can mark durable independent performance for many exercises.
- Color incompleteness (I) is a strong operational continuity/checkpoint signal.

### Weaknesses
- PE is heterogeneous: stretch PE (APS MCC) vs routine PE vs IR soft-PE.
- Coarse jumps; mass sits in PR/PE plateaus → limited mid-scale resolution.
- Required-level-in-name encoding is brittle; SRM uses a different vocabulary.
- Instructor calibration variance means the letter is not a fully standardized unit.

**Do not replace the grading system in Phase 4.** Phase 5 should use this evidence plus narrative validation to design a better evaluation model.

## Supporting analytics tables

| Table | Rows |
|---|---:|
| `analysis_mission_role` | 714 |
| `analysis_student_trajectory` | 1141 |
| `analysis_training_gap_effect` | 19 |
| `analysis_competency_transition` | 204 |
| `analysis_competency_stability` | 6871 |
| `analysis_exercise_learning_curve` | 14444 |
| `analysis_prerequisite_candidate` | 12 |
| `analysis_codifficulty` | 150 |
| `analysis_instructor_calibration` | 36 |
| `analysis_curriculum_comparison` | 40 |
| `analysis_checkpoint_predictor` | 4 |
| `analysis_program_bottleneck` | 176 |
| `analysis_era_metrics` | 370 |
| `analysis_objective_measurement_candidate` | 33112 |
| `analysis_narrative_sample` | 405 |
| `analysis_unexpected_finding` | 8 |
| `analysis_meta` | 1 |

Narrative validation sample: **405** rows (no LLM processing).

## Reproduce

```bash
php analytics/etl/phase4_01_bootstrap.php
analytics/.venv/bin/python -u analytics/etl/phase4_02_core_analyses.py
```
