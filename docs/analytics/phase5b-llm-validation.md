# Phase 5B — LLM Validation & Final Evaluation-Model Decision

**Analysis version:** `phase5b-v1`  
**Primary extractor:** `phase5-extract-v1-agent` (LLM-v1 / phase5_extract_v1)  
**Comparison extractor:** `phase5-extract-v1-heuristic` (heuristic-v1)  
**Generated:** 2026-08-11T02:31:49Z  
**Sample:** full stratified 405 narratives  
**Constraints:** no Phase 6; no bulk NLP execution; no UI redesign; no E-gle writes.

## EXECUTIVE CONCLUSION

**Yes — narrative-derived evidence provides enough reliable incremental information to justify changing how we capture student competency in the future.**

But not by mining history for independence. The justified future change is:

1. Keep **curriculum expected level** separate from observed performance.
2. Add **structured independence/assistance** going forward (historically NOT reliably reconstructable).
3. Keep **consistency** as an explicit observed state (LLM-recoverable and associated with later problems).
4. Let **quality/accuracy** be primarily objective via Cockpit Recorder/Garmin.
5. Add **context/transfer** as mostly auto-derived interpretation, not a heavy rubric.

- Heuristic mismatch/hidden-signal rate: **26.2%** (106/405)
- LLM mismatch/hidden-signal rate: **63.5%** (257/405)
- Validation-set LLM mismatch rate: **72.4%** (76/105)
- Overlap (both methods flag mismatch): **90** narratives
- Encouraging/mixed tone with deficiency (LLM): **242/405**
- Independence extractability: **NOT_RELIABLY_EXTRACTABLE**
- Consistency decision: **KEEP**
- Bulk NLP: **GO_WITH_REDUCED_SCOPE** (targeted subset ≈8774 unique hashes)

---

## 1. Heuristic vs LLM comparison

| Metric | Heuristic | LLM | Agreement | Interpretation |
|---|---:|---:|---:|---|
| assistance_level |  |  | 78.0% |  |
| assistance_present | 0.044 | 0.143 | 83.7% | Both find assistance sparsely; LLM slightly more sensitive |
| consistency_class |  |  | 50.1% |  |
| consistency_present | 0.323 | 0.528 | 63.2% | LLM recovers consistency language much more often |
| context_present | 0.612 | 0.746 | 74.8% |  |
| has_deficiency | 0.301 | 0.832 | 44.9% | LLM detects deficiencies far more often; heuristic is conservative |
| has_positive | 0.343 | 0.674 | 59.0% |  |
| learning_present | 0.160 | 0.232 | 84.9% |  |
| n_evidence | 2.237 | 5.365 | 8.4% | Mean evidence items heuristic=2.2 vs LLM=5.4 |
| tone |  |  | 40.2% |  |

Mean evidence items: heuristic **2.2** vs LLM **5.4**.

**Where LLM adds information:** deficiency detection, consistency language, richer multi-dimension evidence, tone/deficiency coexistence flags.

**Where heuristic is more conservative/reliable:** lower false invention risk; 100% span-bound by construction; less over-assignment of SA/CRM-like dimensions.

Disagreement ≠ LLM automatic win. Text-grounded adjudication is required for assistance/consistency presence claims.

## 2. Human-validation metrics

Adjudication method: text-grounded presence/absence on the 105-row validation set. **Narrative silence is coded `NOT_PRESENT_IN_NARRATIVE`, never `CONFIRMED_NO_ASSISTANCE`.**

| Field | Extractor | n | Precision | Recall | F1 | Incorrect |
|---|---|---:|---:|---:|---:|---:|
| assistance | heuristic-v1 | 105 | 1.00 | 0.94 | 0.97 | 0.06 |
| assistance | LLM-v1 | 105 | 1.00 | 0.78 | 0.88 | 0.22 |
| consistency | heuristic-v1 | 105 | 1.00 | 0.90 | 0.94 | 0.10 |
| consistency | LLM-v1 | 105 | 1.00 | 0.57 | 0.73 | 0.43 |
| context | heuristic-v1 | 105 | 1.00 | 0.88 | 0.93 | 0.12 |
| context | LLM-v1 | 105 | 1.00 | 0.70 | 0.83 | 0.30 |
| deficiency | heuristic-v1 | 105 | 1.00 | 0.78 | 0.88 | 0.22 |
| deficiency | LLM-v1 | 105 | 1.00 | 0.57 | 0.73 | 0.43 |
| learning | heuristic-v1 | 105 | 1.00 | 0.98 | 0.99 | 0.02 |
| learning | LLM-v1 | 105 | 1.00 | 0.98 | 0.99 | 0.02 |
| positive | heuristic-v1 | 105 | 1.00 | 0.69 | 0.81 | 0.31 |
| positive | LLM-v1 | 105 | 1.00 | 0.92 | 0.96 | 0.08 |
| span_support | LLM-v1 | 104 |  |  |  | 0.11 |

## 3. Extraction failure modes

- Inventing assistance from coaching-advice language without clear in-flight intervention.
- Treating absence of assistance language as independence.
- Over-assigning situational awareness / decision-making on broad CRM praise.
- Consistency inferred too aggressively from weak cues (heuristic) or under-detected (also possible).
- Unverified spans in LLM outputs (38/2173 = ~1.7%).
- Encouraging tone mistaken for overall positive competency state.

## 4. Narrative/grade mismatch final estimate

| Extractor | Category | n | rate |
|---|---|---:|---:|
| LLM-v1 | `NARRATIVE_DEFICIENCY_NOT_REFLECTED_IN_GRADE` | 205 | 50.6% |
| LLM-v1 | `STRONG_AGREEMENT` | 112 | 27.7% |
| LLM-v1 | `NARRATIVE_MORE_NEGATIVE` | 49 | 12.1% |
| LLM-v1 | `STRUCTURED_GRADE_PRESENT_NARRATIVE_SILENT` | 35 | 8.6% |
| LLM-v1 | `LOW_GRADE_DESPITE_CLEAR_IMPROVEMENT` | 2 | 0.5% |
| LLM-v1 | `NARRATIVE_MORE_POSITIVE` | 1 | 0.2% |
| LLM-v1 | `OTHER` | 1 | 0.2% |
| OVERLAP | `BOTH_METHODS_MISMATCH` | 90 | 22.2% |
| heuristic-v1 | `STRUCTURED_GRADE_PRESENT_NARRATIVE_SILENT` | 198 | 48.9% |
| heuristic-v1 | `STRONG_AGREEMENT` | 93 | 23.0% |
| heuristic-v1 | `NARRATIVE_DEFICIENCY_NOT_REFLECTED_IN_GRADE` | 68 | 16.8% |
| heuristic-v1 | `NARRATIVE_MORE_NEGATIVE` | 14 | 3.5% |
| heuristic-v1 | `HIGH_GRADE_WITH_INCONSISTENCY` | 11 | 2.7% |
| heuristic-v1 | `LOW_GRADE_DESPITE_CLEAR_IMPROVEMENT` | 10 | 2.5% |
| heuristic-v1 | `OTHER` | 8 | 2.0% |
| heuristic-v1 | `NARRATIVE_MORE_POSITIVE` | 3 | 0.7% |

The heuristic ~23% mismatch finding does **not** remain at 23% under LLM-v1. LLM-v1 yields a **higher** hidden-signal/mismatch rate (**63.5%**), driven mainly by `NARRATIVE_DEFICIENCY_NOT_REFLECTED_IN_GRADE`. This strengthens—not weakens—the case that structured grades omit narrative-critical information. Use LLM rates as the primary estimate; treat heuristic as a conservative lower bound.

## 5. Tone vs performance

| Pattern | n |
|---|---:|
| positive_or_mixed_tone_with_deficiency | 242 |
| critical_tone_with_deficiency | 52 |
| other | 36 |
| positive_or_mixed_tone_no_deficiency | 32 |
| neutral_tone_with_deficiency | 43 |

Tone and performance state are different variables. Future student-facing design must surface evidence/states, not only encouraging debrief language.

## 6. Independence findings

- Extractability: **NOT_RELIABLY_EXTRACTABLE**
- LLM assistance-present frequency: **14.3%**
- Downstream Δ later_regression (assisted − minimal) among strong grades: **-0.32913533834586467** (n=(266, 40))

**Decision:** Historical narratives are **not sufficient** to reconstruct independence. Absence of assistance language ≠ confirmed independent performance. Independence/instructor intervention must become **structured future data**.

## 7. Consistency findings

- Decision: **KEEP**
- Extractability: **PARTIALLY_EXTRACTABLE**
- Frequency of non-insufficient consistency class: **52.8%**
- Downstream Δ later_regression (inconsistent − consistent) among strong grades: **0.14316939890710378** (n=(122, 45))

## 8. Quality findings

- Decision: **MERGE**
- Usable non-UNKNOWN accuracy class frequency: **74.8%**
- Most quality/accuracy should come from objective Cockpit Recorder/Garmin tolerances; avoid duplicating the structured grade.

## 9. Context/transfer findings

- Decision: **KEEP**
- Context present frequency: **74.6%**
- LLM transfer labels: TRUE_REGRESSION_LIKELY=2, CONTEXTUAL_TRANSFER_DIFFICULTY_LIKELY=24, AMBIGUOUS=34
- Keep RAW Phase 4 regression metrics; add contextual interpretation as a separate layer.

## 10. Downstream predictive value

Among strong structured grades, LLM consistency differences show a clearer downstream separation than historical assistance mining. Assistance effects are unstable/small-n and confounded because assistance is under-documented. This supports: **capture independence structurally; use consistency + objective quality + context for durability/transfer interpretation.**

## 11. Candidate-model comparison

| Model | Description | Recommendation |
|---|---|---|
| `A` | Historical structured grade only | **BASELINE_INSUFFICIENT** — Misses ~63% LLM mismatch/hidden-signal cases and independence/consistency. |
| `B` | Grade + independence/assistance | **REQUIRED_ADDON** — Independence not reconstructable from historical silence; must be future structured data. |
| `C` | Grade + consistency | **STRONG_CANDIDATE** — Consistency often narrated and associated with later regression differences under LLM. |
| `D` | Grade + independence + consistency | **RECOMMENDED_MINIMUM_WITH_FUTURE_CAPTURE** — Best burden/value if independence is captured going forward (not only mined historically). |
| `E` | Required level + independence + quality + consistency + context | **RECOMMENDED_CONCEPTUAL_ARCHITECTURE** — Keeps curriculum expectation separate from observed state; quality largely objective; context auto-tagged. |

**Locked choice:** Model **E** as conceptual architecture; Model **D** as the practical minimum once independence is captured as structured data.

## 12. Bulk NLP GO/NO-GO

**Decision: `GO_WITH_REDUCED_SCOPE`**

LLM-v1 on 405 shows high incremental narrative signal (mismatch/hidden-signal ≈63%) and 98.3% span verification, but assistance/independence is not historically reconstructable at scale. Do not spend tokens on all ~21.7k hashes yet. Process a high-value subset first: PE/regression context, check/progress failures, repeated progression missions, high-grade later problems, curriculum-transition cohorts.

Scope:
```json
{
  "include": [
    "exercise_regressed sessions",
    "CHECK_EVENT incomplete/below-standard",
    "mission_attempt_number>=2 progression repeats",
    "high structured grade with later regression linkage",
    "curriculum transition cohorts (optional)"
  ],
  "exclude": [
    "boilerplate",
    "short <40 chars",
    "duplicate text_hash",
    "already extracted hashes"
  ],
  "prompt_version_required": "phase5-extract-v2 after human adjudication of 105-set"
}
```

## 13. Cost/scale estimate

- Eligible narratives: 23327
- Unique hashes: 21653
- Recommended scope unique hashes: 8774
- Full remaining unique hashes if unrestricted: 21254
- Avg input chars (truncated at 7k): 925
- Estimated total tokens (scope): ≈13,435,990
- Estimated runtime: ~3.5–11.0 hours depending on concurrency
- Pricing: not asserted (no authoritative model price in project config)
- Cache key: `text_hash|prompt_version|model|schema_version`

## 14. Locked conceptual evaluation architecture

Core design principle: separate **OBSERVATION** vs **ASSESSMENT** vs **COMPETENCY STATE** vs **CURRICULUM EXPECTATION**.

| Field | Purpose | Provider | Entry | Historical | Recorder |
|---|---|---|---|---|---|
| `curriculum_expected_level` | Curriculum requirement for the exercise/mission (DE/EX/PR/PE or successor) | curriculum | derived | HIGH — already in exercise naming/requirements | mission/exercise definition |
| `observed_independence` | How independently the student performed | instructor (+ optional audio) | manual_marker_preferred | LOW historically (narrative silence common) | intervention markers + audio cues |
| `observed_quality` | Accuracy/quality vs expected standard | objective_first | derived | MEDIUM — grades + some narrative deviations | Garmin/CVR tolerances |
| `observed_consistency` | Repeatability within/across attempts | instructor + objective attempts | derived_or_light_manual | MEDIUM — often narrated | within-flight attempt curves |
| `context` | Conditions affecting difficulty/transfer | system + instructor | auto_preferred | MEDIUM | weather/traffic/airport/scenario tags |
| `objective_evidence` | Machine-measurable observations (not assessments) | cockpit_recorder | derived | LOW historically / HIGH future | telemetry + events + audio features |
| `instructor_observation` | Free-text / structured qualitative observation | instructor | manual | HIGH | optional audio-linked notes |

Retained dimensions that earned their place:
- **curriculum_expected_level** — already exists; keep separate
- **observed_independence** — earned as future structured capture (not historical NLP-only)
- **observed_consistency** — earned via narrative frequency + downstream association
- **observed_quality** — earned mainly as objective/derived field
- **context** — earned as auto interpretation layer
- **objective_evidence / instructor_observation** — evidence channels, not grades

## 15. Remaining uncertainties

- Validation set adjudication is text-grounded assistant review, not multi-instructor human panel.
- OpenAI PHP-FPM key path was not available in this local environment; LLM-v1 is Cursor-agent extraction under the same schema.
- Later-outcome proxies remain coarse (any later regression/repeat/checkpoint problem).
- Assistance downstream estimates are small-n and selection-confounded.
- Prompt v2 should be locked after formal human review of the 105-set before reduced-scope bulk NLP.

## 16. Recommended Phase 6

1. Human-adjudicate the 105 validation rows; publish prompt/schema v2.
2. Implement lightweight future capture: intervention marker, attempt index, exercise windows, auto tolerances, context tags.
3. Run reduced-scope bulk NLP only on the high-value hash subset with v2.
4. Specify storage schema for Observation / Assessment / Competency state / Expected level — still no student UI polish.
5. Re-test durability prediction with newly captured independence markers on live/recorder-linked sessions.

## Supporting tables

| Table | Rows |
|---|---:|
| `analysis_phase5_extractor_comparison` | 4050 |
| `analysis_phase5_extractor_summary` | 10 |
| `analysis_phase5_human_validation` | 734 |
| `analysis_phase5_human_validation_metrics` | 13 |
| `analysis_phase5_mismatch_llm` | 16 |
| `analysis_phase5_dimension_validation` | 5 |
| `analysis_phase5_model_comparison` | 5 |
| `analysis_phase5_bulk_nlp_decision` | 1 |
| `analysis_phase5_final_architecture` | 9 |

## Reproduce

```bash
analytics/.venv/bin/python -u analytics/etl/phase5b_validate.py
```
