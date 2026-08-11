# Phase 5 — Evaluation Model Research & Narrative Validation

**Analysis version:** `phase5-v1`  
**Extraction version:** `phase5-extract-v1-agent`  
**Generated:** 2026-08-10T23:34:55Z  
**Sample:** Phase 4 stratified 405 narratives (no bulk NLP).  
**Constraints:** no UI; no E-gle writes; no DE/EX/PR/PE replacement.

> **Methodology note:** OpenAI API could not be used (`CW_OPENAI_API_KEY` is vault-encrypted). All 405 sample narratives were extracted with the Phase 5 structured schema via Cursor agent LLM batches (`phase5-extract-v1-agent`; 2,173 evidence items; 98.3% span-verified). Heuristic-v1 remains available for comparison. Bulk NLP is **GO_WITH_MODIFICATIONS** pending human adjudication of the 105-row validation set and prompt v2 refinements.

## EXECUTIVE FINDINGS

### 1. Narratives routinely carry competency information missing from structured grades
- **Evidence:** Agreement categories show meaningful mismatch classes; span-verified evidence rate=98.3%.
- **Sample size:** 405 extracted narratives; 2173 evidence items; agreement n=405
- **Magnitude:** Disagreement / hidden-signal categories ≈ 258/405 (63.7%)
- **Confidence:** HIGH
- **Alternative explanation:** Some mismatches may be incomplete grading eras or narrative style differences by instructor.
- **Operational implication:** A future evaluation model should capture a small set of narrative-derived dimensions rather than more color grades.

### 2. Instructor assistance / independence is the highest-value missing variable
- **Evidence:** Assistance distribution: {'NONE_OBSERVED': 330, 'MINOR_PROMPT': 22, 'REPEATED_PROMPTS': 21, 'UNKNOWN': 15, 'INSTRUCTOR_DEMONSTRATION': 10, 'STEP_BY_STEP_COACHING': 3, 'VERBAL_CONFIRMATION_ONLY': 2, 'TAKEOVER_OR_SAFETY_INTERVENTION': 1, 'PHYSICAL_INTERVENTION': 1}. Outcome table compares strong grades by assistance.
- **Sample size:** strong-grade assistance groups n=266 vs repeated=14
- **Magnitude:** later_regression no/minimal=65.4% vs repeated=28.6%
- **Confidence:** MEDIUM–HIGH
- **Alternative explanation:** Assisted students may already be harder cases (selection).
- **Operational implication:** Collect a lightweight assistance/independence marker now; do not infer it from low grades.

### 3. Consistency separates one successful execution from durable competence
- **Evidence:** Consistency classes: {'INSUFFICIENT_EVIDENCE': 191, 'MOSTLY_CONSISTENT': 114, 'VARIABLE': 71, 'INCONSISTENT': 18, 'CONSISTENT': 11}
- **Sample size:** consistent n=122; inconsistent n=45
- **Magnitude:** later_regression consistent=59.0% vs inconsistent=73.3%
- **Confidence:** MEDIUM
- **Alternative explanation:** Narratives may mention inconsistency more when problems already exist.
- **Operational implication:** If retained, consistency should be explicit and distinct from a single PE/B mark.

### 4. Encouraging tone often coexists with real deficiencies
- **Evidence:** Flag encouraging_tone_with_deficiency=221 narratives.
- **Sample size:** n=405
- **Magnitude:** Tone is not a competency score.
- **Confidence:** HIGH
- **Alternative explanation:** Instructors may soften written feedback culturally.
- **Operational implication:** Student-facing systems must surface evidence/states, not only positive debrief tone.

### 5. Context/transfer likely explains part of Phase 4 PE→PR softening
- **Evidence:** Transfer interpretations: {'AMBIGUOUS': 34, 'CONTEXTUAL_TRANSFER_DIFFICULTY_LIKELY': 24, 'TRUE_REGRESSION_LIKELY': 2}
- **Sample size:** context_transfer rows=60
- **Magnitude:** Contextual difficulty is common enough to warrant a separate interpretation layer.
- **Confidence:** MEDIUM
- **Alternative explanation:** LLM may over-tag instrument/check contexts.
- **Operational implication:** Keep RAW regression and contextual transfer as separate analytic layers.

### 6. Minimum useful future model is grade + independence + consistency (+ context tag)
- **Evidence:** Dimension value + candidate config comparison (Section 14).
- **Sample size:** 405-sample research configs A–G
- **Magnitude:** Recommended minimum: config D/E — not a 12-dimension scorecard.
- **Confidence:** MEDIUM–HIGH
- **Alternative explanation:** Sample may under-represent rare CRM/SA nuances.
- **Operational implication:** Optimize for answering: can / independently / how well / consistently / under what conditions / likely durable.

### 7. Bulk historical NLP recommendation: GO_WITH_MODIFICATIONS
- **Evidence:** LLM extraction quality acceptable (span_ok=98.8%); disagreement=63.7%. Process unique informative hashes only after prompt v2 refinements.
- **Sample size:** total=27166, eligible=23327, unique_hashes=21653, expected_calls≈21254
- **Magnitude:** estimated tokens≈21,784,363; span_ok=98.8%
- **Confidence:** MEDIUM
- **Alternative explanation:** Token estimates are approximate.
- **Operational implication:** Do not process boilerplate/short/duplicate hashes; refine prompt from validation failure modes first.

---

## 1. Narrative extraction methodology

- Sample: stratified 405 from Phase 4 (`below_standard`, `high_performing`, `repeated_mission`, `pe_then_regression_context`, `cross_program_era`).
- Model: `cursor-agent-llm`
- Prompt/extraction version: `phase5-extract-v1-agent`
- Schema separates observed evidence spans from interpreted dimensions.
- Assistance, consistency, context, learning response, accuracy extracted as dedicated fields.
- No interpretation without evidence span; UNKNOWN/NOT OBSERVED allowed.
- Parse OK: 405 / 405; evidence items: 2173; span verified: 98.3%

## 2. Validation quality

- Human validation artifact size: **105** (`analysis_narrative_validation`).
- Includes high/low grades, repeats, PE-regression context, program/era diversity, long/short, assistance/inconsistency edge cases.
- Automated + assistant spot-review flags recorded; true independent human review should confirm before bulk NLP.
- Unsupported extraction proxy (unverified spans in validation set): 11
- Incorrect assistance proxy flags: 14
- Possible missed deficiency cues: 0

### Observed failure modes to refine in prompt v2
- Over-inference of assistance from coaching advice language without clear in-flight intervention.
- Generic praise classified as competency evidence.
- Consistency inferred without explicit stability language.
- Situational awareness / decision-making over-assigned on broad CRM comments.
- Truncation of very long narratives may miss late deficiencies.

## 3. What historical grades capture well

- Coarse session outcome color/completion and required-level met/not-met.
- Broad technical/procedural success vs below-standard.
- Mission repeat and incompleteness as operational friction signals.
- Strong agreement category count: 110

## 4. What historical grades fail to capture

- Independence vs prompted performance.
- Consistency / repeatability within the session.
- Context transfer (wind, workload, unfamiliar airport, check pressure).
- Within-session learning response.
- Encouraging tone masking deficiencies.
- Missing-middle states (independent but inconsistent; accurate only in familiar context; etc.).

### Missing-middle frequency (LLM-tagged)

| State | Narratives |
|---|---:|
| `INDEPENDENT_WITHIN_TOLERANCE` | 140 |
| `NEEDS_OCCASIONAL_PROMPTING` | 118 |
| `INDEPENDENT_BUT_INCONSISTENT` | 95 |
| `PERFORMS_CONSISTENTLY` | 37 |
| `TRANSFERS_TO_CHANGED_CONTEXT` | 6 |
| `ACCURATE_ONLY_IN_FAMILIAR_CONTEXT` | 4 |
| `NEEDS_CONTINUOUS_ASSISTANCE` | 4 |

## 5. Narrative ↔ grade disagreement

| Category | n |
|---|---:|
| `NARRATIVE_DEFICIENCY_NOT_REFLECTED_IN_GRADE` | 215 |
| `STRONG_AGREEMENT` | 110 |
| `NARRATIVE_MORE_NEGATIVE` | 43 |
| `STRUCTURED_GRADE_PRESENT_NARRATIVE_SILENT` | 33 |
| `LOW_GRADE_DESPITE_CLEAR_IMPROVEMENT` | 2 |
| `NARRATIVE_MORE_POSITIVE` | 1 |
| `AMBIGUOUS` | 1 |

## 6. Instructor assistance / independence

| Assistance level | n |
|---|---:|
| `NONE_OBSERVED` | 330 |
| `MINOR_PROMPT` | 22 |
| `REPEATED_PROMPTS` | 21 |
| `UNKNOWN` | 15 |
| `INSTRUCTOR_DEMONSTRATION` | 10 |
| `STEP_BY_STEP_COACHING` | 3 |
| `VERBAL_CONFIRMATION_ONLY` | 2 |
| `TAKEOVER_OR_SAFETY_INTERVENTION` | 1 |
| `PHYSICAL_INTERVENTION` | 1 |

| Group | n | Later regression | Later repeat | Later checkpoint problem |
|---|---:|---:|---:|---:|
| B/strong + no/minimal assistance | 266 | 65.4% | 91.4% | 48.1% |
| B/strong + minor prompting | 19 | 42.1% | 84.2% | 47.4% |
| B/strong + repeated prompting/coaching | 14 | 28.6% | 71.4% | 35.7% |
| B/strong + assistance UNKNOWN | 13 | 84.6% | 100.0% | 7.7% |
| high_performing + independent | 66 | 60.6% | 93.9% | 56.1% |
| high_performing + assisted | 10 | 30.0% | 70.0% | 30.0% |

## 7. Consistency and repeatability

| Consistency | n |
|---|---:|
| `INSUFFICIENT_EVIDENCE` | 191 |
| `MOSTLY_CONSISTENT` | 114 |
| `VARIABLE` | 71 |
| `INCONSISTENT` | 18 |
| `CONSISTENT` | 11 |

| Group | n | Later regression | Later repeat | Later checkpoint problem |
|---|---:|---:|---:|---:|
| strong + consistent | 122 | 59.0% | 90.2% | 52.5% |
| strong + inconsistent/variable | 45 | 73.3% | 88.9% | 53.3% |
| strong + consistency insufficient evidence | 152 | 61.2% | 89.5% | 38.2% |

## 8. Accuracy versus independence

Accuracy/quality and independence are separable in narratives: students can be within tolerance while prompted, or independent with material deviations. Future schema must keep required level, observed independence, and quality distinct.

| Accuracy quality | n |
|---|---:|
| `MINOR_DEVIATION` | 142 |
| `UNKNOWN` | 102 |
| `WITHIN_STANDARD` | 67 |
| `MATERIAL_DEVIATION` | 65 |
| `OUTSIDE_STANDARD` | 29 |

## 9. Context and transfer

| Interpretation | n | Later regression rate |
|---|---:|---:|
| `AMBIGUOUS` | 34 | 79.4% |
| `CONTEXTUAL_TRANSFER_DIFFICULTY_LIKELY` | 24 | 66.7% |
| `TRUE_REGRESSION_LIKELY` | 2 | 50.0% |

Phase 4 raw regression is preserved; this is an added interpretation layer only.

## 10. Within-session learning response

| Learning response | n |
|---|---:|
| `UNKNOWN` | 311 |
| `IMPROVEMENT` | 70 |
| `LIMITED_IMPROVEMENT` | 17 |
| `NO_IMPROVEMENT` | 5 |
| `RAPID_IMPROVEMENT` | 1 |
| `REGRESSION_WITHIN_SESSION` | 1 |

Useful to distinguish currently-below-standard-but-learning from unresolved repeated deficiency — better as a session note than a permanent grade.

## 11. Meaning of PE/B in historical narrative evidence

| B/strong narrative group | n | Later regression | Later repeat | Checkpoint problem |
|---|---:|---:|---:|---:|
| B/strong + independent + consistent | 106 | 61.3% | 91.5% | 54.7% |
| B/strong + independent + inconsistent | 40 | 77.5% | 90.0% | 55.0% |
| B/strong + minor prompting | 19 | 42.1% | 84.2% | 47.4% |
| B/strong + repeated prompting | 14 | 28.6% | 71.4% | 35.7% |
| B/strong + narrative deficiency | 254 | 61.8% | 89.8% | 47.2% |
| B/strong + no meaningful narrative evidence | 7 | 42.9% | 71.4% | 0.0% |

**Most important test result:** among similarly strong structured grades, narrative-derived independence/consistency/deficiency signals identify different downstream risk profiles. This supports redesign toward a minimum additive model — not more colors.

## 12. Candidate competency dimensions

| Dimension | Freq | Evidence n | Reliability | Incremental value | Overlap | Recorder | Burden | Rec |
|---|---:|---:|---|---|---|---|---|---|
| `TECHNICAL_CONTROL` | 81.0% | 1055 | HIGH | LOW (Δlater_reg=-0.051) | HIGH | PARTIALLY_MEASURABLE | MEDIUM | **MERGE** |
| `PROCEDURAL_EXECUTION` | 73.3% | 741 | HIGH | LOW (Δlater_reg=-0.057) | HIGH | PARTIALLY_MEASURABLE | MEDIUM | **MERGE** |
| `ACCURACY_TOLERANCE` | 57.0% | 432 | HIGH | LOW (Δlater_reg=0.024) | HIGH | OBJECTIVELY_MEASURABLE | LOW | **KEEP** |
| `SITUATIONAL_AWARENESS` | 46.9% | 300 | HIGH | LOW (Δlater_reg=0.013) | LOW | HUMAN_JUDGMENT_REQUIRED | HIGH | **INVESTIGATE_MORE** |
| `KNOWLEDGE_UNDERSTANDING` | 46.2% | 254 | HIGH | MEDIUM (Δlater_reg=0.095) | LOW | HUMAN_JUDGMENT_REQUIRED | HIGH | **INVESTIGATE_MORE** |
| `SOP_CHECKLIST_DISCIPLINE` | 38.3% | 215 | HIGH | LOW (Δlater_reg=0.005) | MEDIUM | PARTIALLY_MEASURABLE | MEDIUM | **MERGE** |
| `SAFETY_MARGIN` | 27.7% | 157 | HIGH | MEDIUM (Δlater_reg=-0.061) | LOW | PARTIALLY_MEASURABLE | MEDIUM | **INVESTIGATE_MORE** |
| `COMMUNICATION_RADIO` | 26.4% | 147 | HIGH | LOW (Δlater_reg=-0.054) | MEDIUM | PARTIALLY_MEASURABLE | MEDIUM | **INVESTIGATE_MORE** |
| `WORKLOAD_MANAGEMENT` | 24.2% | 126 | HIGH | LOW (Δlater_reg=-0.007) | LOW | PARTIALLY_MEASURABLE | MEDIUM | **INVESTIGATE_MORE** |
| `DECISION_MAKING` | 23.7% | 134 | HIGH | MEDIUM (Δlater_reg=-0.112) | LOW | HUMAN_JUDGMENT_REQUIRED | HIGH | **INVESTIGATE_MORE** |
| `LEARNING_RESPONSE_IMPROVEMENT` | 22.5% | 110 | HIGH | MEDIUM (Δlater_reg=0.070) | LOW | PARTIALLY_MEASURABLE | MEDIUM | **MERGE** |
| `OTHER` | 18.5% | 92 | HIGH | LOW (Δlater_reg=0.033) | LOW | HUMAN_JUDGMENT_REQUIRED | HIGH | **DROP** |
| `CONSISTENCY` | 9.9% | 51 | HIGH | HIGH (Δlater_reg=0.143) | LOW | PARTIALLY_MEASURABLE | MEDIUM | **KEEP** |
| `TRANSFER_ADAPTABILITY` | 7.9% | 36 | HIGH | HIGH (Δlater_reg=-0.131) | LOW | PARTIALLY_MEASURABLE | MEDIUM | **KEEP** |
| `INSTRUCTOR_ASSISTANCE` | 5.7% | 29 | HIGH | HIGH (Δlater_reg=-0.212) | LOW | PARTIALLY_MEASURABLE | MEDIUM | **KEEP** |
| `INDEPENDENCE` | 5.7% | 28 | HIGH | HIGH (Δlater_reg=-0.368) | LOW | PARTIALLY_MEASURABLE | MEDIUM | **KEEP** |
| `UNKNOWN` | 0.0% | 0 | HIGH | INSUFFICIENT (Δlater_reg=0.000) | LOW | HUMAN_JUDGMENT_REQUIRED | HIGH | **DROP** |

## 13. Incremental value beyond existing grades

Highest incremental value: **assistance/independence**, **consistency**, **context/transfer**, then objective **accuracy/tolerance**. Broad CRM dimensions appear often but overlap and burden are higher; keep under INVESTIGATE_MORE rather than mandatory per-exercise scores.

## 14. Minimum useful future model

| Config | Description | Recommendation |
|---|---|---|
| `A` | Existing grade only | **BASELINE** — Structured grades alone miss assistance/consistency/context |
| `B` | Grade + assistance/independence | **STRONG_CANDIDATE** — Highest-priority additive signal |
| `C` | Grade + consistency | **STRONG_CANDIDATE** — Separates one-shot success from durable skill |
| `D` | Grade + assistance + consistency | **RECOMMENDED_MINIMUM** — Best burden/value tradeoff from sample |
| `E` | D + context/transfer | **RECOMMENDED_WITH_CONTEXT_TAG** — Context tag can be mostly automatic |
| `F` | Learning stage + quality/stability | **INVESTIGATE** — Good student language; needs careful UX |
| `G` | Independence + quality + transfer | **ALTERNATE_MINIMUM** — Close competitor to D/E |

**Recommended research minimum:** keep required learning level separate from observed performance, then capture:
1. Independence / assistance
2. Consistency
3. Quality/accuracy (increasingly objective via recorder)
4. Context/transfer tag when relevant

Do **not** ask instructors to score 10–15 dimensions per exercise.

## 15. Student-facing implications

Analytical dimensions support developmental language more than pass/fail colors, e.g. INTRODUCED / DEVELOPING / INDEPENDENT / CONSISTENT / TRANSFERABLE — but only if backed by the same evidence used operationally. Do not adopt friendlier words that hide assistance or inconsistency. No final student UI in Phase 5.

## 16. Cockpit Recorder measurement opportunities

| Dimension | Measurement class | Sources | Confidence |
|---|---|---|---|
| `ACCURACY_TOLERANCE` | `OBJECTIVELY_MEASURABLE` | Garmin/CVR altitude, airspeed, heading, bank, path | HIGH |
| `COMMUNICATION_RADIO` | `PARTIALLY_MEASURABLE` | Audio/transcript | MEDIUM |
| `CONSISTENCY` | `PARTIALLY_MEASURABLE` | Repeated maneuver attempt telemetry within flight | MEDIUM |
| `DECISION_MAKING` | `HUMAN_JUDGMENT_REQUIRED` | Narrative + scenario context; AI-assisted later | LOW |
| `INDEPENDENCE` | `PARTIALLY_MEASURABLE` | Inverse of assistance markers | MEDIUM |
| `INSTRUCTOR_ASSISTANCE` | `PARTIALLY_MEASURABLE` | Instructor marker + audio intervention cues | MEDIUM |
| `KNOWLEDGE_UNDERSTANDING` | `HUMAN_JUDGMENT_REQUIRED` | Oral/narrative; not flight telemetry | HIGH |
| `LEARNING_RESPONSE_IMPROVEMENT` | `PARTIALLY_MEASURABLE` | Within-flight attempt curves | MEDIUM |
| `OTHER` | `HUMAN_JUDGMENT_REQUIRED` | Narrative | LOW |
| `PROCEDURAL_EXECUTION` | `PARTIALLY_MEASURABLE` | Checklist events, audio, sequence telemetry | MEDIUM |
| `SAFETY_MARGIN` | `PARTIALLY_MEASURABLE` | Proximity to limits, go-around, takeover events | MEDIUM |
| `SITUATIONAL_AWARENESS` | `HUMAN_JUDGMENT_REQUIRED` | Narrative + partial traffic/context data | LOW |
| `SOP_CHECKLIST_DISCIPLINE` | `PARTIALLY_MEASURABLE` | Checklist audio/events | MEDIUM |
| `TECHNICAL_CONTROL` | `PARTIALLY_MEASURABLE` | Telemetry control smoothness + tolerances | MEDIUM |
| `TRANSFER_ADAPTABILITY` | `PARTIALLY_MEASURABLE` | Known context tags + objective performance under context | MEDIUM |
| `UNKNOWN` | `HUMAN_JUDGMENT_REQUIRED` | n/a | LOW |
| `WORKLOAD_MANAGEMENT` | `PARTIALLY_MEASURABLE` | Task density + performance under high workload | LOW |

## 17. Additional fields worth collecting now

| Field | Priority | Rationale |
|---|---|---|
| Exercise start/end windows | MUST_COLLECT_NOW | Enables objective tolerances & attempt curves |
| Instructor intervention marker + reason | MUST_COLLECT_NOW | Highest-value missing independence signal; partial auto from audio later |
| Within-flight repeat attempt index | MUST_COLLECT_NOW | Consistency + learning curves |
| Objective tolerance result (auto) | MUST_COLLECT_NOW | Accuracy without instructor burden |
| Context tags (wind/traffic/airport/check) | SHOULD_COLLECT | Transfer vs true regression; many auto-derivable |
| Reason exercise not completed | SHOULD_COLLECT | Separates weather/time from competency |
| Instructor confidence/readiness (optional light) | OPTIONAL | Useful near checks; easy to overuse |
| Student self-assessment | OPTIONAL | Learning metacognition; not primary evidence |
| Per-exercise 10-dimension rubric | DO_NOT_COLLECT | High burden, low necessity given Phase 5 minimum model |

## 18. Data limitations

- 405 stratified sample ≠ full population.
- Downstream outcome flags are proxy (later regression/repeat/checkpoint problems), not license outcomes.
- Assistant/automated validation is not a substitute for full human adjudication of all 100.
- Long narratives truncated at 7k chars for extraction.
- Selection confounding: assisted students may be harder a priori.
- No student risk scores created.

## 19. Bulk narrative NLP recommendation

**Decision: `GO_WITH_MODIFICATIONS`**

- Total narratives: 27166
- Eligible after filters: 23327
- Unique hashes: 21653
- Expected remaining LLM calls: ≈21254
- Expected token volume (approx): ≈21,784,363
- Rationale: LLM extraction quality acceptable (span_ok=98.8%); disagreement=63.7%. Process unique informative hashes only after prompt v2 refinements.

## 20. Recommended Phase 6 direction

1. Human-adjudicate the 100-row validation set; refine to prompt/extraction v2.
2. Implement lightweight recorder/instructor capture for assistance, attempt index, exercise windows, auto-tolerances.
3. If bulk NLP proceeds, process unique eligible hashes only with v2 prompt.
4. Design the **minimum evaluation model** (required level ≠ observed independence ≠ quality ≠ consistency ≠ context) — still no production UI polish.
5. Re-test the core question on the larger extracted set: among equal structured grades, do independence+consistency+context predict durability?

## Supporting tables

| Table | Rows |
|---|---:|
| `analysis_narrative_sample_enriched` | 405 |
| `analysis_narrative_extraction` | 810 |
| `analysis_narrative_evidence` | 3079 |
| `analysis_narrative_validation` | 105 |
| `analysis_narrative_grade_agreement` | 405 |
| `analysis_assistance_outcomes` | 6 |
| `analysis_consistency_outcomes` | 3 |
| `analysis_context_transfer` | 60 |
| `analysis_dimension_value` | 17 |
| `analysis_evaluation_model_candidate` | 7 |
| `analysis_future_competency_measurement` | 17 |
| `analysis_bulk_nlp_recommendation` | 1 |

## Reproduce

```bash
php analytics/etl/phase5_01_bootstrap.php
set -a; source .env; set +a
analytics/.venv/bin/python -u analytics/etl/phase5_02_extract.py
analytics/.venv/bin/python -u analytics/etl/phase5_03_analyze.py
```
