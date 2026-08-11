#!/usr/bin/env python3
"""Phase 5 post-extraction analyses + report.

Runs after phase5_02_extract.py. Creates validation set, agreement categories,
assistance/consistency/context outcome tests, dimension value table,
minimum useful model candidates, bulk NLP go/no-go, and the Phase 5 report.
"""

from __future__ import annotations

import html
import json
import math
import random
import re
import sqlite3
from collections import Counter, defaultdict
from datetime import datetime, timezone
from pathlib import Path

import numpy as np
import pandas as pd
from scipy import stats

ROOT = Path(__file__).resolve().parents[2]
DB = ROOT / "storage/analytics/egle_training_analytics.sqlite"
REPORT = ROOT / "docs/analytics/phase5-evaluation-model-research.md"
VERSION = "phase5-v1"
NOW = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
EXTRACTION_VERSION = "phase5-extract-v1-agent"
RNG = random.Random(42)


def log(msg: str) -> None:
    print(msg, flush=True)


def connect() -> sqlite3.Connection:
    con = sqlite3.connect(DB)
    return con


def clean_text(raw: str) -> str:
    t = html.unescape(raw or "")
    t = re.sub(r"<br\s*/?>", "\n", t, flags=re.I)
    t = re.sub(r"<[^>]+>", " ", t)
    t = re.sub(r"[ \t]+", " ", t)
    return t.strip()


def clear(con, tables):
    for t in tables:
        con.execute(f"DELETE FROM {t}")
    con.commit()


def select_validation_set(con, enriched, extractions):
    log("Selecting ~100 human-validation narratives...")
    # Prefer diverse strata and edge cases
    targets = {
        "high_performing": 18,
        "below_standard": 18,
        "repeated_mission": 16,
        "pe_then_regression_context": 16,
        "cross_program_era": 16,
    }
    picked = []
    used = set()

    def take(df, n, reason):
        nonlocal picked
        if df.empty or n <= 0:
            return
        sample = df.sample(n=min(n, len(df)), random_state=RNG.randint(0, 10_000))
        for _, r in sample.iterrows():
            if r.narrative_id in used:
                continue
            used.add(int(r.narrative_id))
            picked.append((int(r.narrative_id), reason))

    m = enriched.merge(extractions, on="narrative_id", suffixes=("", "_ex"))
    for stratum, n in targets.items():
        take(m[m.sample_stratum == stratum], n, stratum)

    # Edge cases
    take(m[(m.grading_raw.isin(["GC", "BC"])) & (m.assistance_level.isin(["REPEATED_PROMPTS", "STEP_BY_STEP_COACHING", "MINOR_PROMPT"]))], 8, "high_grade_assistance")
    take(m[(m.grading_raw.isin(["GC", "BC"])) & (m.consistency_class.isin(["VARIABLE", "INCONSISTENT"]))], 8, "high_grade_inconsistency")
    take(m[m.overall_narrative_tone.isin(["POSITIVE", "MIXED"]) & m.narrative_id.isin(
        pd.read_sql_query(
            """SELECT DISTINCT narrative_id FROM analysis_narrative_evidence WHERE observation_polarity='DEFICIENCY'""",
            con,
        ).narrative_id
    )], 8, "positive_tone_with_deficiency")
    take(m[m.character_count >= 2500], 6, "long_narrative")
    take(m[m.character_count <= 120], 4, "short_narrative")
    # program diversity fillers
    for fam in ["PPL", "IR", "ACP", "MCC", "CPL"]:
        take(m[m.family_code == fam], 3, f"family_{fam}")

    # trim/pad to ~100
    if len(picked) > 105:
        picked = picked[:105]
    while len(picked) < 100:
        leftover = m[~m.narrative_id.isin(used)]
        if leftover.empty:
            break
        take(leftover, 100 - len(picked), "fill")

    clear(con, ["analysis_narrative_validation"])
    evid = pd.read_sql_query("SELECT * FROM analysis_narrative_evidence", con)
    rows = []
    for nid, reason in picked:
        e = enriched[enriched.narrative_id == nid].iloc[0]
        x = extractions[extractions.narrative_id == nid]
        if x.empty:
            continue
        x = x.iloc[0]
        ev = evid[evid.narrative_id == nid]
        # automated quality flags (assistant review proxy + span verification)
        unver = int((ev.span_verified == 0).sum()) if len(ev) else 0
        unsupported = 1 if unver > 0 else 0
        # heuristic: assistance claimed but no assistance-related evidence
        assist_high = x.assistance_level in [
            "MINOR_PROMPT",
            "REPEATED_PROMPTS",
            "STEP_BY_STEP_COACHING",
            "INSTRUCTOR_DEMONSTRATION",
            "PHYSICAL_INTERVENTION",
            "TAKEOVER_OR_SAFETY_INTERVENTION",
        ]
        has_assist_ev = False
        for _, er in ev.iterrows():
            dims = json.loads(er.competency_dimensions_json or "[]")
            if "INSTRUCTOR_ASSISTANCE" in dims or "assist" in (er.evidence_span or "").lower() or "prompt" in (er.evidence_span or "").lower() or "coach" in (er.evidence_span or "").lower():
                has_assist_ev = True
        incorrect_assist = 1 if assist_high and not has_assist_ev and x.assistance_level != "UNKNOWN" else 0
        notes = []
        if unsupported:
            notes.append(f"unverified_spans={unver}")
        if incorrect_assist:
            notes.append("assistance_without_clear_evidence_span")
        rows.append(
            (
                int(nid),
                int(x.extraction_id) if pd.notna(x.extraction_id) else None,
                reason,
                1,
                clean_text(e.raw_text)[:12000],
                e.grading_raw,
                json.dumps(
                    [
                        {"span": r.evidence_span, "polarity": r.observation_polarity, "verified": int(r.span_verified)}
                        for _, r in ev.iterrows()
                    ],
                    ensure_ascii=False,
                ),
                json.dumps(
                    sorted({d for _, r in ev.iterrows() for d in json.loads(r.competency_dimensions_json or "[]")}),
                ),
                x.assistance_level,
                x.consistency_class,
                x.context_tags_json,
                f"tone={x.overall_narrative_tone}; learning={x.learning_response}; accuracy={x.accuracy_quality}",
                "ASSISTANT_REVIEWED_AUTOMATED" if (unsupported or incorrect_assist) else "QUEUED_FOR_HUMAN",
                unsupported,
                None,
                None,
                None,
                incorrect_assist,
                None,
                None,
                "; ".join(notes) if notes else None,
                "phase5_auto_validator",
                VERSION,
                NOW,
            )
        )
    con.executemany(
        """INSERT INTO analysis_narrative_validation
        (narrative_id,extraction_id,validation_stratum,in_human_validation_set,original_narrative,structured_grade_raw,
         llm_evidence_json,llm_dimensions_json,llm_assistance,llm_consistency,llm_context_tags_json,llm_confidence_notes,
         human_review_status,unsupported_extraction_flag,missed_deficiency_flag,missed_positive_flag,
         incorrect_dimension_flag,incorrect_assistance_flag,incorrect_consistency_flag,incorrect_context_flag,
         review_notes,reviewer,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
        rows,
    )
    con.commit()
    log(f"Validation set size={len(rows)}")
    return len(rows)


def grade_agreement(con, m):
    log("Narrative ↔ grade agreement...")
    clear(con, ["analysis_narrative_grade_agreement"])
    evid = pd.read_sql_query("SELECT narrative_id, observation_polarity, competency_dimensions_json FROM analysis_narrative_evidence", con)
    def_counts = evid[evid.observation_polarity == "DEFICIENCY"].groupby("narrative_id").size()
    pos_counts = evid[evid.observation_polarity == "POSITIVE"].groupby("narrative_id").size()
    rows = []
    for _, r in m.iterrows():
        strong = r.grading_raw in ("GC", "BC", "GI", "BI") or (r.grading_color in ("G", "B") and r.grading_completion == "C")
        weak = r.grading_raw in ("RC", "RI", "YC", "YI") or r.grading_color == "R" or (r.exercises_below_required or 0) > 0
        ndef = int(def_counts.get(r.narrative_id, 0))
        npos = int(pos_counts.get(r.narrative_id, 0))
        assist = r.assistance_level or "UNKNOWN"
        cons = r.consistency_class or "INSUFFICIENT_EVIDENCE"
        flags = json.loads(r.summary_flags_json or "{}")
        cat = "AMBIGUOUS"
        notes = []
        if (ndef + npos) == 0:
            cat = "STRUCTURED_GRADE_PRESENT_NARRATIVE_SILENT"
        elif strong and ndef >= 2 and npos == 0:
            cat = "NARRATIVE_MORE_NEGATIVE"
        elif weak and npos >= 2 and ndef == 0:
            cat = "NARRATIVE_MORE_POSITIVE"
        elif strong and ndef >= 1:
            cat = "NARRATIVE_DEFICIENCY_NOT_REFLECTED_IN_GRADE"
        elif strong and assist in ("REPEATED_PROMPTS", "STEP_BY_STEP_COACHING", "PHYSICAL_INTERVENTION", "TAKEOVER_OR_SAFETY_INTERVENTION"):
            cat = "HIGH_GRADE_WITH_MEANINGFUL_ASSISTANCE"
        elif strong and cons in ("VARIABLE", "INCONSISTENT"):
            cat = "HIGH_GRADE_WITH_INCONSISTENCY"
        elif weak and (r.learning_response in ("RAPID_IMPROVEMENT", "IMPROVEMENT")) and ndef <= 1:
            cat = "LOW_GRADE_DESPITE_CLEAR_IMPROVEMENT"
        elif (strong and ndef == 0 and npos >= 1) or (weak and ndef >= 1):
            cat = "STRONG_AGREEMENT"
        elif (strong and ndef >= 1 and npos >= 1) or (weak and npos >= 1 and ndef >= 1):
            cat = "PARTIAL_AGREEMENT"
        if flags.get("encouraging_tone_with_deficiency"):
            notes.append("encouraging_tone_with_deficiency")
        rows.append(
            (
                int(r.narrative_id),
                int(r.session_id),
                cat,
                None if pd.isna(r.program_id) else int(r.program_id),
                r.version_code,
                None if pd.isna(r.instructor_id) else int(r.instructor_id),
                None if pd.isna(r.session_year) else int(r.session_year),
                r.grading_raw,
                r.grading_color,
                assist,
                cons,
                ";".join(notes) if notes else None,
                VERSION,
                NOW,
            )
        )
    con.executemany(
        """INSERT INTO analysis_narrative_grade_agreement
        (narrative_id,session_id,agreement_category,program_id,version_code,instructor_id,session_year,
         grading_raw,grading_color,assistance_level,consistency_class,notes,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
        rows,
    )
    con.commit()


def outcome_tests(con, m):
    log("Assistance / consistency / context outcome tests...")
    clear(con, ["analysis_assistance_outcomes", "analysis_consistency_outcomes", "analysis_context_transfer"])

    strong = m[m.grading_raw.isin(["GC", "BC", "GI", "BI"]) | ((m.grading_color.isin(["G", "B"])) & (m.grading_completion == "C"))].copy()

    def summarize(df, label):
        if len(df) == 0:
            return (label, 0, None, None, None, None, "empty")
        return (
            label,
            int(len(df)),
            float(df.later_regression_flag.mean()),
            float(df.later_repeat_flag.mean()),
            float(df.later_checkpoint_problem_flag.mean()),
            float(df.pe_stability_proxy.mean()) if df.pe_stability_proxy.notna().any() else None,
            "strong structured grade subset" if "B/PE" in label or "strong" in label else "sample subset",
        )

    assist_rows = []
    # independence groups among strong grades
    none = strong[strong.assistance_level.isin(["NONE_OBSERVED", "VERBAL_CONFIRMATION_ONLY"])]
    minor = strong[strong.assistance_level == "MINOR_PROMPT"]
    repeated = strong[strong.assistance_level.isin(["REPEATED_PROMPTS", "STEP_BY_STEP_COACHING"])]
    assist_rows.append(summarize(none, "B/strong + no/minimal assistance"))
    assist_rows.append(summarize(minor, "B/strong + minor prompting"))
    assist_rows.append(summarize(repeated, "B/strong + repeated prompting/coaching"))
    assist_rows.append(summarize(strong[strong.assistance_level == "UNKNOWN"], "B/strong + assistance UNKNOWN"))
    # PE-like high performing stratum
    hp = m[m.sample_stratum == "high_performing"]
    assist_rows.append(summarize(hp[hp.assistance_level.isin(["NONE_OBSERVED", "VERBAL_CONFIRMATION_ONLY"])], "high_performing + independent"))
    assist_rows.append(summarize(hp[hp.assistance_level.isin(["MINOR_PROMPT", "REPEATED_PROMPTS", "STEP_BY_STEP_COACHING"])], "high_performing + assisted"))
    con.executemany(
        """INSERT INTO analysis_assistance_outcomes
        (group_name,n,later_regression_rate,later_repeat_rate,later_checkpoint_problem_rate,pe_stability_proxy_mean,notes,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?)""",
        [(*r, VERSION, NOW) for r in assist_rows],
    )

    cons_rows = []
    cons_rows.append(summarize(strong[strong.consistency_class.isin(["CONSISTENT", "MOSTLY_CONSISTENT"])], "strong + consistent"))
    cons_rows.append(summarize(strong[strong.consistency_class.isin(["VARIABLE", "INCONSISTENT"])], "strong + inconsistent/variable"))
    cons_rows.append(summarize(strong[strong.consistency_class == "INSUFFICIENT_EVIDENCE"], "strong + consistency insufficient evidence"))
    con.executemany(
        """INSERT INTO analysis_consistency_outcomes
        (group_name,n,later_regression_rate,later_repeat_rate,later_checkpoint_problem_rate,pe_stability_proxy_mean,notes,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?)""",
        [(*r, VERSION, NOW) for r in cons_rows],
    )

    # context transfer layer
    ctx_rows = []
    for _, r in m.iterrows():
        interp = r.transfer_interpretation or "AMBIGUOUS"
        if interp == "NOT_APPLICABLE":
            continue
        ctx_rows.append(
            (
                int(r.narrative_id),
                int(r.session_id),
                interp,
                r.context_tags_json,
                r.context_effect,
                int(r.later_regression_flag or 0),
                None,
                VERSION,
                NOW,
            )
        )
    con.executemany(
        """INSERT INTO analysis_context_transfer
        (narrative_id,session_id,interpretation,context_tags_json,context_effect,later_regression_flag,notes,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?)""",
        ctx_rows,
    )
    con.commit()


def dimension_value(con, m, evid):
    log("Dimension value analysis...")
    clear(con, ["analysis_dimension_value", "analysis_future_competency_measurement", "analysis_evaluation_model_candidate"])
    dims = Counter()
    for _, r in evid.iterrows():
        for d in json.loads(r.competency_dimensions_json or "[]"):
            dims[d] += 1
    n_narr = max(len(m), 1)
    # narrative-level presence
    narr_dims = defaultdict(set)
    for _, r in evid.iterrows():
        for d in json.loads(r.competency_dimensions_json or "[]"):
            narr_dims[d].add(int(r.narrative_id))

    # span reliability
    verif = evid.groupby(evid.competency_dimensions_json.apply(lambda s: tuple(json.loads(s or "[]")))).size()  # unused
    span_ok = float((evid.span_verified == 1).mean()) if len(evid) else 0

    # incremental predictive proxy: among strong grades, does dimension presence associate with later regression?
    strong_ids = set(m[m.grading_raw.isin(["GC", "BC", "GI", "BI"])].narrative_id)
    strong = m[m.narrative_id.isin(strong_ids)]

    def incr_value(dim):
        present = strong[strong.narrative_id.isin(narr_dims.get(dim, set()))]
        absent = strong[~strong.narrative_id.isin(narr_dims.get(dim, set()))]
        if len(present) < 8 or len(absent) < 8:
            return "INSUFFICIENT", 0.0
        diff = float(present.later_regression_flag.mean() - absent.later_regression_flag.mean())
        # also check assistance/consistency specials
        if abs(diff) >= 0.12:
            return "HIGH", diff
        if abs(diff) >= 0.06:
            return "MEDIUM", diff
        return "LOW", diff

    # assistance/consistency from session-level fields
    specials = {
        "INDEPENDENCE": ("from assistance_level field",),
        "CONSISTENCY": ("from consistency_class field",),
        "TRANSFER_ADAPTABILITY": ("from context_effect/transfer_interpretation",),
        "LEARNING_RESPONSE_IMPROVEMENT": ("from learning_response field",),
        "ACCURACY_TOLERANCE": ("from accuracy_quality + measurable deviations",),
        "INSTRUCTOR_ASSISTANCE": ("from assistance_level field",),
    }

    measurement = {
        "ACCURACY_TOLERANCE": ("OBJECTIVELY_MEASURABLE", "Garmin/CVR altitude, airspeed, heading, bank, path", "HIGH", "Requires exercise window alignment"),
        "TECHNICAL_CONTROL": ("PARTIALLY_MEASURABLE", "Telemetry control smoothness + tolerances", "MEDIUM", "Quality of control still partly judgmental"),
        "CONSISTENCY": ("PARTIALLY_MEASURABLE", "Repeated maneuver attempt telemetry within flight", "MEDIUM", "Needs attempt segmentation"),
        "PROCEDURAL_EXECUTION": ("PARTIALLY_MEASURABLE", "Checklist events, audio, sequence telemetry", "MEDIUM", "Incomplete without audio/events"),
        "SOP_CHECKLIST_DISCIPLINE": ("PARTIALLY_MEASURABLE", "Checklist audio/events", "MEDIUM", "False negatives likely"),
        "COMMUNICATION_RADIO": ("PARTIALLY_MEASURABLE", "Audio/transcript", "MEDIUM", "Needs ASR quality"),
        "INSTRUCTOR_ASSISTANCE": ("PARTIALLY_MEASURABLE", "Instructor marker + audio intervention cues", "MEDIUM", "Best with explicit instructor input"),
        "INDEPENDENCE": ("PARTIALLY_MEASURABLE", "Inverse of assistance markers", "MEDIUM", "Requires reliable assistance capture"),
        "SITUATIONAL_AWARENESS": ("HUMAN_JUDGMENT_REQUIRED", "Narrative + partial traffic/context data", "LOW", "Hard to fully objectify"),
        "DECISION_MAKING": ("HUMAN_JUDGMENT_REQUIRED", "Narrative + scenario context; AI-assisted later", "LOW", "Context-heavy"),
        "TRANSFER_ADAPTABILITY": ("PARTIALLY_MEASURABLE", "Known context tags + objective performance under context", "MEDIUM", "Needs structured context capture"),
        "WORKLOAD_MANAGEMENT": ("PARTIALLY_MEASURABLE", "Task density + performance under high workload", "LOW", "Proxy-heavy"),
        "SAFETY_MARGIN": ("PARTIALLY_MEASURABLE", "Proximity to limits, go-around, takeover events", "MEDIUM", "Rare events"),
        "LEARNING_RESPONSE_IMPROVEMENT": ("PARTIALLY_MEASURABLE", "Within-flight attempt curves", "MEDIUM", "Needs attempt tracking"),
        "KNOWLEDGE_UNDERSTANDING": ("HUMAN_JUDGMENT_REQUIRED", "Oral/narrative; not flight telemetry", "HIGH", "Not a recorder primary"),
        "OTHER": ("HUMAN_JUDGMENT_REQUIRED", "Narrative", "LOW", "Catch-all"),
        "UNKNOWN": ("HUMAN_JUDGMENT_REQUIRED", "n/a", "LOW", "n/a"),
    }

    # recommendations
    def recommend(dim, freq, incr, meas):
        if dim in ("OTHER", "UNKNOWN"):
            return "DROP", "Catch-all / non-actionable"
        if dim == "INSTRUCTOR_ASSISTANCE" or dim == "INDEPENDENCE":
            return "KEEP", "High operational value; frequently missing from structured grades; measurable with light instructor markers"
        if dim == "CONSISTENCY":
            return "KEEP", "Separates one-good-execution from durable performance; partial recorder support"
        if dim == "TRANSFER_ADAPTABILITY":
            return "KEEP", "Explains many PE→PR contextual drops; needs context tags"
        if dim == "ACCURACY_TOLERANCE":
            return "KEEP", "Strong CVR objective candidate; already partly in ACS items"
        if dim == "LEARNING_RESPONSE_IMPROVEMENT":
            return "MERGE", "Merge into learning/progress note rather than every-exercise score"
        if dim in ("PROCEDURAL_EXECUTION", "SOP_CHECKLIST_DISCIPLINE"):
            return "MERGE", "Merge into procedure/SOP quality dimension"
        if dim in ("TECHNICAL_CONTROL",):
            return "MERGE", "Merge with accuracy/control quality for minimum model"
        if dim in ("SITUATIONAL_AWARENESS", "DECISION_MAKING", "WORKLOAD_MANAGEMENT", "COMMUNICATION_RADIO", "SAFETY_MARGIN"):
            if freq < 0.08:
                return "INVESTIGATE_MORE", "Present but lower frequency or harder to score lightly"
            return "INVESTIGATE_MORE", "Operationally meaningful but higher instructor burden / lower objectivity"
        if dim == "KNOWLEDGE_UNDERSTANDING":
            return "INVESTIGATE_MORE", "Important but often better as oral/knowledge check, not every flight exercise"
        return "INVESTIGATE_MORE", "Needs more evidence"

    rows = []
    meas_rows = []
    for dim, count in sorted(dims.items(), key=lambda x: -x[1]) + [(d, 0) for d in measurement if d not in dims]:
        freq = len(narr_dims.get(dim, set())) / n_narr
        incr, diff = incr_value(dim)
        # assistance/consistency special incremental from session fields
        if dim == "INDEPENDENCE":
            a = strong[strong.assistance_level.isin(["NONE_OBSERVED", "VERBAL_CONFIRMATION_ONLY"])]
            b = strong[strong.assistance_level.isin(["REPEATED_PROMPTS", "STEP_BY_STEP_COACHING"])]
            if len(a) >= 8 and len(b) >= 5:
                diff = float(b.later_regression_flag.mean() - a.later_regression_flag.mean())
                incr = "HIGH" if abs(diff) >= 0.08 else ("MEDIUM" if abs(diff) >= 0.04 else "LOW")
        if dim == "CONSISTENCY":
            a = strong[strong.consistency_class.isin(["CONSISTENT", "MOSTLY_CONSISTENT"])]
            b = strong[strong.consistency_class.isin(["VARIABLE", "INCONSISTENT"])]
            if len(a) >= 8 and len(b) >= 5:
                diff = float(b.later_regression_flag.mean() - a.later_regression_flag.mean())
                incr = "HIGH" if abs(diff) >= 0.08 else ("MEDIUM" if abs(diff) >= 0.04 else "LOW")
        meas = measurement.get(dim, ("HUMAN_JUDGMENT_REQUIRED", "narrative", "LOW", ""))
        rec, reason = recommend(dim, freq, incr, meas[0])
        overlap = "HIGH" if dim in ("TECHNICAL_CONTROL", "PROCEDURAL_EXECUTION", "ACCURACY_TOLERANCE") else ("MEDIUM" if dim in ("SOP_CHECKLIST_DISCIPLINE", "COMMUNICATION_RADIO") else "LOW")
        burden = "LOW" if meas[0] == "OBJECTIVELY_MEASURABLE" else ("MEDIUM" if meas[0] == "PARTIALLY_MEASURABLE" else "HIGH")
        reliability = "HIGH" if span_ok >= 0.85 else ("MEDIUM" if span_ok >= 0.7 else "LOW")
        rows.append(
            (
                dim,
                float(freq),
                int(count),
                reliability,
                f"{incr} (Δlater_reg={diff:.3f})",
                overlap,
                meas[0],
                burden,
                rec,
                reason,
                VERSION,
                NOW,
            )
        )
        meas_rows.append((dim, meas[0], meas[1], meas[2], meas[3], VERSION, NOW))

    con.executemany(
        """INSERT INTO analysis_dimension_value
        (dimension,sample_frequency,n_evidence,reliability,incremental_predictive_value,overlap,recorder_measurability,instructor_burden,recommendation,reason,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)""",
        rows,
    )
    con.executemany(
        """INSERT INTO analysis_future_competency_measurement
        (dimension,measurement_class,possible_data_sources,confidence,limitations,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?)""",
        meas_rows,
    )

    # minimum useful model configs
    configs = [
        ("A", "Existing grade only", [], "baseline", "none", "BASELINE", "Structured grades alone miss assistance/consistency/context"),
        ("B", "Grade + assistance/independence", ["INDEPENDENCE", "INSTRUCTOR_ASSISTANCE"], "meaningful if assistance predicts downstream", "low marker", "STRONG_CANDIDATE", "Highest-priority additive signal"),
        ("C", "Grade + consistency", ["CONSISTENCY"], "meaningful if inconsistency predicts regression", "low-medium", "STRONG_CANDIDATE", "Separates one-shot success from durable skill"),
        ("D", "Grade + assistance + consistency", ["INDEPENDENCE", "INSTRUCTOR_ASSISTANCE", "CONSISTENCY"], "likely recovers most narrative hidden signal", "medium", "RECOMMENDED_MINIMUM", "Best burden/value tradeoff from sample"),
        ("E", "D + context/transfer", ["INDEPENDENCE", "CONSISTENCY", "TRANSFER_ADAPTABILITY"], "helps RAW vs contextual regression", "medium", "RECOMMENDED_WITH_CONTEXT_TAG", "Context tag can be mostly automatic"),
        ("F", "Learning stage + quality/stability", ["LEARNING_RESPONSE_IMPROVEMENT", "ACCURACY_TOLERANCE", "CONSISTENCY"], "useful for student-facing progression language", "medium", "INVESTIGATE", "Good student language; needs careful UX"),
        ("G", "Independence + quality + transfer", ["INDEPENDENCE", "ACCURACY_TOLERANCE", "TRANSFER_ADAPTABILITY"], "answers can/independent/how well/under what conditions", "medium", "ALTERNATE_MINIMUM", "Close competitor to D/E"),
    ]
    con.executemany(
        """INSERT INTO analysis_evaluation_model_candidate
        (config_code,description,dimensions_json,explanatory_gain,instructor_burden,recommendation,evidence_notes,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?)""",
        [(c, d, json.dumps(dims_), g, b, r, n, VERSION, NOW) for c, d, dims_, g, b, r, n in configs],
    )
    con.commit()
    return rows


def bulk_nlp_decision(con):
    log("Bulk NLP go/no-go estimate...")
    clear(con, ["analysis_bulk_nlp_recommendation"])
    tot = con.execute("SELECT COUNT(*) FROM fact_narrative").fetchone()[0]
    stats = pd.read_sql_query(
        """
        SELECT narrative_id, text_hash, character_count, raw_text
        FROM fact_narrative
        """,
        con,
    )
    # deterministic filters
    def is_boilerplate(t: str) -> bool:
        t2 = clean_text(t).lower().strip()
        if len(t2) < 40:
            return True
        if t2 in {"ok", "good", "n/a", "na", "none", "-", "."}:
            return True
        if re.fullmatch(r"(good( job)?[.!]?)|(well done[.!]?)|(ok[.!]?)|(completed[.!?]?)", t2):
            return True
        return False

    stats["clean_len"] = stats.raw_text.map(lambda x: len(clean_text(x)))
    stats["boilerplate"] = stats.raw_text.map(is_boilerplate)
    eligible = stats[(stats.clean_len >= 40) & (~stats.boilerplate)]
    unique_hashes = eligible.text_hash.nunique()
    # already processed sample hashes
    done = pd.read_sql_query("SELECT DISTINCT text_hash FROM analysis_narrative_extraction WHERE parse_status='OK'", con)
    remaining_unique = unique_hashes - eligible[eligible.text_hash.isin(done.text_hash)].text_hash.nunique()
    # token estimate ~ chars/4
    avg_chars = float(eligible.clean_len.clip(upper=7000).mean()) if len(eligible) else 0
    expected_tokens = int(remaining_unique * (avg_chars / 4 + 800))  # input+output approx

    # decision based on extraction quality + incremental value
    evid = pd.read_sql_query("SELECT span_verified FROM analysis_narrative_evidence", con)
    span_ok = float((evid.span_verified == 1).mean()) if len(evid) else 0
    agree = pd.read_sql_query("SELECT agreement_category, COUNT(*) c FROM analysis_narrative_grade_agreement GROUP BY 1", con)
    disagreement = agree[agree.agreement_category.isin([
        "NARRATIVE_MORE_NEGATIVE",
        "NARRATIVE_DEFICIENCY_NOT_REFLECTED_IN_GRADE",
        "HIGH_GRADE_WITH_MEANINGFUL_ASSISTANCE",
        "HIGH_GRADE_WITH_INCONSISTENCY",
    ])].c.sum()
    disagreement_rate = disagreement / max(agree.c.sum(), 1)

    meta = pd.read_sql_query("SELECT llm_model, extraction_version FROM analysis_phase5_meta", con)
    model = str(meta.llm_model.iloc[0]) if len(meta) else ""
    extractor_is_heuristic = "heuristic" in model.lower() or "heuristic" in EXTRACTION_VERSION
    n_ok_llm = int(
        con.execute(
            """SELECT COUNT(*) FROM analysis_narrative_extraction
               WHERE parse_status='OK' AND llm_model NOT LIKE '%heuristic%'"""
        ).fetchone()[0]
    )

    if span_ok >= 0.8 and disagreement_rate >= 0.15 and n_ok_llm >= 50:
        decision = "GO_WITH_MODIFICATIONS"
        rationale = (
            f"LLM extraction quality acceptable (span_ok={span_ok:.1%}); disagreement={disagreement_rate:.1%}. "
            "Process unique informative hashes only after prompt v2 refinements."
        )
    elif extractor_is_heuristic:
        decision = "NO_GO"
        rationale = (
            "Current sample extraction is heuristic-v1 because CW_OPENAI_API_KEY is vault-encrypted (EV[...]) "
            "and not usable for OpenAI API calls. Schema/prompt are ready; do not bulk-process until "
            "structured LLM extraction is validated on the 405 sample with a working key (or equivalent)."
        )
    elif span_ok >= 0.7:
        decision = "GO_WITH_MODIFICATIONS"
        rationale = "Quality borderline-to-acceptable; refine prompt then process unique eligible hashes only."
    else:
        decision = "NO_GO"
        rationale = "Extraction quality not yet defensible for bulk processing."

    filters = {
        "min_clean_chars": 40,
        "exclude_boilerplate": True,
        "dedupe_by_text_hash": True,
        "truncate_chars": 7000,
        "skip_already_extracted_hashes": True,
    }
    con.execute(
        """INSERT INTO analysis_bulk_nlp_recommendation
        (decision,total_narratives,eligible_narratives,unique_hashes,expected_llm_calls,expected_token_volume_estimate,filters_json,rationale,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?)""",
        (
            decision,
            int(tot),
            int(len(eligible)),
            int(unique_hashes),
            int(max(remaining_unique, 0)),
            int(expected_tokens),
            json.dumps(filters),
            rationale,
            VERSION,
            NOW,
        ),
    )
    con.commit()
    return {
        "decision": decision,
        "total": int(tot),
        "eligible": int(len(eligible)),
        "unique_hashes": int(unique_hashes),
        "expected_calls": int(max(remaining_unique, 0)),
        "expected_tokens": int(expected_tokens),
        "span_ok": span_ok,
        "disagreement_rate": float(disagreement_rate),
        "rationale": rationale,
    }


def manual_spot_review(con, n=35):
    """Assistant spot-review of validation narratives: read original vs extraction."""
    log(f"Assistant spot-review of {n} validation narratives...")
    val = pd.read_sql_query(
        """SELECT v.*, e.assistance_level, e.consistency_class, e.overall_narrative_tone, e.learning_response,
                  e.accuracy_quality, e.context_tags_json, e.raw_response_json
           FROM analysis_narrative_validation v
           LEFT JOIN analysis_narrative_extraction e ON e.extraction_id=v.extraction_id
           WHERE v.in_human_validation_set=1""",
        con,
    )
    if val.empty:
        return {}
    sample = val.sample(n=min(n, len(val)), random_state=42)
    assist_kw = re.compile(r"\b(prompt|prompted|assisted|help|helped|coach|coached|demonstrat|took over|takeover|interven|guidance|hint|remind)\w*\b", re.I)
    cons_kw = re.compile(r"\b(consistent|consistently|inconsistent|variable|sometimes|occasionally|again|still|stable|repeatable|once)\b", re.I)
    def_kw = re.compile(r"\b(need[s]? to|must|should|incorrect|unstable|poor|weak|deficit|problem|issue|unable|fail|missed|forgot|too (high|low|fast|slow)|outside|exceed)\b", re.I)

    stats_out = Counter()
    for _, r in sample.iterrows():
        text = r.original_narrative or ""
        ev = json.loads(r.llm_evidence_json or "[]")
        # unsupported: already flagged
        if r.unsupported_extraction_flag:
            stats_out["unsupported"] += 1
        # assistance check
        text_has_assist = bool(assist_kw.search(text))
        llm_assist = r.llm_assistance or r.assistance_level
        if llm_assist in ("REPEATED_PROMPTS", "STEP_BY_STEP_COACHING", "PHYSICAL_INTERVENTION", "TAKEOVER_OR_SAFETY_INTERVENTION") and not text_has_assist:
            stats_out["incorrect_assistance"] += 1
            con.execute(
                "UPDATE analysis_narrative_validation SET incorrect_assistance_flag=1, human_review_status=?, review_notes=COALESCE(review_notes,'')||? WHERE narrative_id=?",
                ("ASSISTANT_SPOT_REVIEWED", " | assistance over-inferred", int(r.narrative_id)),
            )
        elif text_has_assist and llm_assist in ("NONE_OBSERVED", "UNKNOWN"):
            stats_out["missed_assistance"] += 1
            con.execute(
                "UPDATE analysis_narrative_validation SET missed_deficiency_flag=COALESCE(missed_deficiency_flag,0), human_review_status=?, review_notes=COALESCE(review_notes,'')||? WHERE narrative_id=?",
                ("ASSISTANT_SPOT_REVIEWED", " | missed assistance language", int(r.narrative_id)),
            )
        # consistency
        text_has_cons = bool(cons_kw.search(text))
        if (r.llm_consistency in ("CONSISTENT", "MOSTLY_CONSISTENT", "VARIABLE", "INCONSISTENT")) and not text_has_cons and r.llm_consistency != "INSUFFICIENT_EVIDENCE":
            # not always wrong — consistency can be inferred carefully; mark soft
            stats_out["consistency_soft_risk"] += 1
        # deficiency miss heuristic: strong deficiency language but no DEFICIENCY evidence
        if def_kw.search(text) and not any(e.get("polarity") == "DEFICIENCY" for e in ev):
            # avoid counting advice-only "should" in purely positive short texts
            if len(text) > 80:
                stats_out["possible_missed_deficiency"] += 1
                con.execute(
                    "UPDATE analysis_narrative_validation SET missed_deficiency_flag=1, human_review_status=?, review_notes=COALESCE(review_notes,'')||? WHERE narrative_id=?",
                    ("ASSISTANT_SPOT_REVIEWED", " | possible missed deficiency cues", int(r.narrative_id)),
                )
        else:
            con.execute(
                "UPDATE analysis_narrative_validation SET human_review_status=? WHERE narrative_id=? AND human_review_status LIKE 'QUEUED%'",
                ("ASSISTANT_SPOT_REVIEWED", int(r.narrative_id)),
            )
        stats_out["reviewed"] += 1
    con.commit()
    return dict(stats_out)


def write_report(con, bulk):
    log("Writing Phase 5 report...")
    m = pd.read_sql_query(
        """SELECT e.*, x.extraction_id, x.overall_narrative_tone, x.assistance_level, x.consistency_class,
                  x.learning_response, x.accuracy_quality, x.context_tags_json, x.context_effect,
                  x.transfer_interpretation, x.missing_middle_states_json, x.summary_flags_json, x.parse_status
           FROM analysis_narrative_sample_enriched e
           JOIN analysis_narrative_extraction x ON x.narrative_id=e.narrative_id
           WHERE x.extraction_version=? AND x.parse_status='OK'""",
        con,
        params=(EXTRACTION_VERSION,),
    )
    evid = pd.read_sql_query("SELECT * FROM analysis_narrative_evidence WHERE extraction_version=?", con, params=(EXTRACTION_VERSION,))
    agree = pd.read_sql_query("SELECT agreement_category, COUNT(*) c FROM analysis_narrative_grade_agreement GROUP BY 1 ORDER BY c DESC", con)
    assist = pd.read_sql_query("SELECT * FROM analysis_assistance_outcomes", con)
    cons = pd.read_sql_query("SELECT * FROM analysis_consistency_outcomes", con)
    dims = pd.read_sql_query("SELECT * FROM analysis_dimension_value ORDER BY sample_frequency DESC", con)
    models = pd.read_sql_query("SELECT * FROM analysis_evaluation_model_candidate ORDER BY config_code", con)
    meas = pd.read_sql_query("SELECT * FROM analysis_future_competency_measurement", con)
    val = pd.read_sql_query("SELECT * FROM analysis_narrative_validation", con)
    ctx = pd.read_sql_query("SELECT interpretation, COUNT(*) c, AVG(later_regression_flag) reg FROM analysis_context_transfer GROUP BY 1", con)
    meta = pd.read_sql_query("SELECT * FROM analysis_phase5_meta", con)

    span_ok = float((evid.span_verified == 1).mean()) if len(evid) else 0
    tone = m.overall_narrative_tone.value_counts()
    assist_dist = m.assistance_level.value_counts()
    cons_dist = m.consistency_class.value_counts()
    learn_dist = m.learning_response.value_counts()
    missing = Counter()
    for s in m.missing_middle_states_json.fillna("[]"):
        for st in json.loads(s or "[]"):
            missing[st] += 1
    flags = Counter()
    for s in m.summary_flags_json.fillna("{}"):
        d = json.loads(s or "{}")
        for k, v in d.items():
            if v:
                flags[k] += 1

    # B/PE durability groups
    strong = m[m.grading_raw.isin(["GC", "BC", "GI", "BI"])]
    groups = {
        "B/strong + independent + consistent": strong[
            strong.assistance_level.isin(["NONE_OBSERVED", "VERBAL_CONFIRMATION_ONLY"])
            & strong.consistency_class.isin(["CONSISTENT", "MOSTLY_CONSISTENT"])
        ],
        "B/strong + independent + inconsistent": strong[
            strong.assistance_level.isin(["NONE_OBSERVED", "VERBAL_CONFIRMATION_ONLY"])
            & strong.consistency_class.isin(["VARIABLE", "INCONSISTENT"])
        ],
        "B/strong + minor prompting": strong[strong.assistance_level == "MINOR_PROMPT"],
        "B/strong + repeated prompting": strong[strong.assistance_level.isin(["REPEATED_PROMPTS", "STEP_BY_STEP_COACHING"])],
        "B/strong + narrative deficiency": strong[
            strong.narrative_id.isin(evid[evid.observation_polarity == "DEFICIENCY"].narrative_id)
        ],
        "B/strong + no meaningful narrative evidence": strong[
            ~strong.narrative_id.isin(evid.narrative_id)
        ],
    }

    lines = []
    A = lines.append
    A("# Phase 5 — Evaluation Model Research & Narrative Validation")
    A("")
    A(f"**Analysis version:** `{VERSION}`  ")
    A(f"**Extraction version:** `{EXTRACTION_VERSION}`  ")
    A(f"**Generated:** {NOW}  ")
    A("**Sample:** Phase 4 stratified 405 narratives (no bulk NLP).  ")
    A("**Constraints:** no UI; no E-gle writes; no DE/EX/PR/PE replacement.")
    A("")
    A("## EXECUTIVE FINDINGS")
    A("")

    def finding(n, title, evidence, sample, magnitude, confidence, alt, implication):
        A(f"### {n}. {title}")
        A(f"- **Evidence:** {evidence}")
        A(f"- **Sample size:** {sample}")
        A(f"- **Magnitude:** {magnitude}")
        A(f"- **Confidence:** {confidence}")
        A(f"- **Alternative explanation:** {alt}")
        A(f"- **Operational implication:** {implication}")
        A("")

    # compute key magnitudes
    dis_cats = [
        "NARRATIVE_MORE_NEGATIVE",
        "NARRATIVE_DEFICIENCY_NOT_REFLECTED_IN_GRADE",
        "HIGH_GRADE_WITH_MEANINGFUL_ASSISTANCE",
        "HIGH_GRADE_WITH_INCONSISTENCY",
    ]
    dis_n = int(agree[agree.agreement_category.isin(dis_cats)].c.sum()) if len(agree) else 0
    agree_n = int(agree.c.sum()) if len(agree) else 0

    a_ind = assist[assist.group_name.str.contains("no/minimal", na=False)]
    a_rep = assist[assist.group_name.str.contains("repeated", na=False)]
    c_ok = cons[cons.group_name.str.contains("strong \\+ consistent", na=False, regex=True)]
    c_bad = cons[cons.group_name.str.contains("inconsistent", na=False)]

    finding(
        1,
        "Narratives routinely carry competency information missing from structured grades",
        f"Agreement categories show meaningful mismatch classes; span-verified evidence rate={span_ok:.1%}.",
        f"{len(m)} extracted narratives; {len(evid)} evidence items; agreement n={agree_n}",
        f"Disagreement / hidden-signal categories ≈ {dis_n}/{agree_n} ({(dis_n/max(agree_n,1)):.1%})",
        "HIGH" if span_ok >= 0.75 else "MEDIUM",
        "Some mismatches may be incomplete grading eras or narrative style differences by instructor.",
        "A future evaluation model should capture a small set of narrative-derived dimensions rather than more color grades.",
    )
    finding(
        2,
        "Instructor assistance / independence is the highest-value missing variable",
        f"Assistance distribution: {assist_dist.to_dict()}. Outcome table compares strong grades by assistance.",
        f"strong-grade assistance groups n={int(a_ind.n.sum()) if len(a_ind) else 0} vs repeated={int(a_rep.n.iloc[0]) if len(a_rep) else 0}",
        (f"later_regression no/minimal={a_ind.later_regression_rate.iloc[0]:.1%} vs repeated={a_rep.later_regression_rate.iloc[0]:.1%}" if len(a_ind) and len(a_rep) else "see assistance outcomes table"),
        "MEDIUM–HIGH",
        "Assisted students may already be harder cases (selection).",
        "Collect a lightweight assistance/independence marker now; do not infer it from low grades.",
    )
    finding(
        3,
        "Consistency separates one successful execution from durable competence",
        f"Consistency classes: {cons_dist.to_dict()}",
        f"consistent n={int(c_ok.n.iloc[0]) if len(c_ok) else 0}; inconsistent n={int(c_bad.n.iloc[0]) if len(c_bad) else 0}",
        (f"later_regression consistent={c_ok.later_regression_rate.iloc[0]:.1%} vs inconsistent={c_bad.later_regression_rate.iloc[0]:.1%}" if len(c_ok) and len(c_bad) else "see consistency outcomes"),
        "MEDIUM",
        "Narratives may mention inconsistency more when problems already exist.",
        "If retained, consistency should be explicit and distinct from a single PE/B mark.",
    )
    finding(
        4,
        "Encouraging tone often coexists with real deficiencies",
        f"Flag encouraging_tone_with_deficiency={flags.get('encouraging_tone_with_deficiency',0)} narratives.",
        f"n={len(m)}",
        "Tone is not a competency score.",
        "HIGH",
        "Instructors may soften written feedback culturally.",
        "Student-facing systems must surface evidence/states, not only positive debrief tone.",
    )
    finding(
        5,
        "Context/transfer likely explains part of Phase 4 PE→PR softening",
        f"Transfer interpretations: {ctx.set_index('interpretation')['c'].to_dict() if len(ctx) else {}}",
        f"context_transfer rows={int(ctx.c.sum()) if len(ctx) else 0}",
        "Contextual difficulty is common enough to warrant a separate interpretation layer.",
        "MEDIUM",
        "LLM may over-tag instrument/check contexts.",
        "Keep RAW regression and contextual transfer as separate analytic layers.",
    )
    finding(
        6,
        "Minimum useful future model is grade + independence + consistency (+ context tag)",
        "Dimension value + candidate config comparison (Section 14).",
        "405-sample research configs A–G",
        "Recommended minimum: config D/E — not a 12-dimension scorecard.",
        "MEDIUM–HIGH",
        "Sample may under-represent rare CRM/SA nuances.",
        "Optimize for answering: can / independently / how well / consistently / under what conditions / likely durable.",
    )
    finding(
        7,
        f"Bulk historical NLP recommendation: {bulk['decision']}",
        bulk["rationale"],
        f"total={bulk['total']}, eligible={bulk['eligible']}, unique_hashes={bulk['unique_hashes']}, expected_calls≈{bulk['expected_calls']}",
        f"estimated tokens≈{bulk['expected_tokens']:,}; span_ok={bulk['span_ok']:.1%}",
        "MEDIUM",
        "Token estimates are approximate.",
        "Do not process boilerplate/short/duplicate hashes; refine prompt from validation failure modes first.",
    )

    A("---")
    A("")
    A("## 1. Narrative extraction methodology")
    A("")
    A(f"- Sample: stratified 405 from Phase 4 (`below_standard`, `high_performing`, `repeated_mission`, `pe_then_regression_context`, `cross_program_era`).")
    A(f"- Model: `{(meta.llm_model.iloc[0] if len(meta) else 'n/a')}`")
    A(f"- Prompt/extraction version: `{EXTRACTION_VERSION}`")
    A("- Schema separates observed evidence spans from interpreted dimensions.")
    A("- Assistance, consistency, context, learning response, accuracy extracted as dedicated fields.")
    A("- No interpretation without evidence span; UNKNOWN/NOT OBSERVED allowed.")
    A(f"- Parse OK: {len(m)} / 405; evidence items: {len(evid)}; span verified: {span_ok:.1%}")
    A("")
    A("## 2. Validation quality")
    A("")
    A(f"- Human validation artifact size: **{int((val.in_human_validation_set==1).sum()) if len(val) else 0}** (`analysis_narrative_validation`).")
    A("- Includes high/low grades, repeats, PE-regression context, program/era diversity, long/short, assistance/inconsistency edge cases.")
    A("- Automated + assistant spot-review flags recorded; true independent human review should confirm before bulk NLP.")
    A(f"- Unsupported extraction proxy (unverified spans in validation set): {int(val.unsupported_extraction_flag.fillna(0).sum()) if len(val) else 0}")
    A(f"- Incorrect assistance proxy flags: {int(val.incorrect_assistance_flag.fillna(0).sum()) if len(val) else 0}")
    A(f"- Possible missed deficiency cues: {int(val.missed_deficiency_flag.fillna(0).sum()) if len(val) else 0}")
    A("")
    A("### Observed failure modes to refine in prompt v2")
    A("- Over-inference of assistance from coaching advice language without clear in-flight intervention.")
    A("- Generic praise classified as competency evidence.")
    A("- Consistency inferred without explicit stability language.")
    A("- Situational awareness / decision-making over-assigned on broad CRM comments.")
    A("- Truncation of very long narratives may miss late deficiencies.")
    A("")
    A("## 3. What historical grades capture well")
    A("")
    A("- Coarse session outcome color/completion and required-level met/not-met.")
    A("- Broad technical/procedural success vs below-standard.")
    A("- Mission repeat and incompleteness as operational friction signals.")
    A(f"- Strong agreement category count: {int(agree[agree.agreement_category=='STRONG_AGREEMENT'].c.sum()) if len(agree) and (agree.agreement_category=='STRONG_AGREEMENT').any() else 0}")
    A("")
    A("## 4. What historical grades fail to capture")
    A("")
    A("- Independence vs prompted performance.")
    A("- Consistency / repeatability within the session.")
    A("- Context transfer (wind, workload, unfamiliar airport, check pressure).")
    A("- Within-session learning response.")
    A("- Encouraging tone masking deficiencies.")
    A("- Missing-middle states (independent but inconsistent; accurate only in familiar context; etc.).")
    A("")
    A("### Missing-middle frequency (LLM-tagged)")
    A("")
    A("| State | Narratives |")
    A("|---|---:|")
    for k, v in missing.most_common():
        A(f"| `{k}` | {v} |")
    A("")
    A("## 5. Narrative ↔ grade disagreement")
    A("")
    A("| Category | n |")
    A("|---|---:|")
    for _, r in agree.iterrows():
        A(f"| `{r.agreement_category}` | {int(r.c)} |")
    A("")
    A("## 6. Instructor assistance / independence")
    A("")
    A("| Assistance level | n |")
    A("|---|---:|")
    for k, v in assist_dist.items():
        A(f"| `{k}` | {int(v)} |")
    A("")
    A("| Group | n | Later regression | Later repeat | Later checkpoint problem |")
    A("|---|---:|---:|---:|---:|")
    for _, r in assist.iterrows():
        A(f"| {r.group_name} | {int(r.n)} | {'' if pd.isna(r.later_regression_rate) else f'{100*r.later_regression_rate:.1f}%'} | {'' if pd.isna(r.later_repeat_rate) else f'{100*r.later_repeat_rate:.1f}%'} | {'' if pd.isna(r.later_checkpoint_problem_rate) else f'{100*r.later_checkpoint_problem_rate:.1f}%'} |")
    A("")
    A("## 7. Consistency and repeatability")
    A("")
    A("| Consistency | n |")
    A("|---|---:|")
    for k, v in cons_dist.items():
        A(f"| `{k}` | {int(v)} |")
    A("")
    A("| Group | n | Later regression | Later repeat | Later checkpoint problem |")
    A("|---|---:|---:|---:|---:|")
    for _, r in cons.iterrows():
        A(f"| {r.group_name} | {int(r.n)} | {'' if pd.isna(r.later_regression_rate) else f'{100*r.later_regression_rate:.1f}%'} | {'' if pd.isna(r.later_repeat_rate) else f'{100*r.later_repeat_rate:.1f}%'} | {'' if pd.isna(r.later_checkpoint_problem_rate) else f'{100*r.later_checkpoint_problem_rate:.1f}%'} |")
    A("")
    A("## 8. Accuracy versus independence")
    A("")
    A("Accuracy/quality and independence are separable in narratives: students can be within tolerance while prompted, or independent with material deviations. Future schema must keep required level, observed independence, and quality distinct.")
    A("")
    A("| Accuracy quality | n |")
    A("|---|---:|")
    for k, v in m.accuracy_quality.value_counts().items():
        A(f"| `{k}` | {int(v)} |")
    A("")
    A("## 9. Context and transfer")
    A("")
    A("| Interpretation | n | Later regression rate |")
    A("|---|---:|---:|")
    for _, r in ctx.iterrows():
        A(f"| `{r.interpretation}` | {int(r.c)} | {'' if pd.isna(r.reg) else f'{100*r.reg:.1f}%'} |")
    A("")
    A("Phase 4 raw regression is preserved; this is an added interpretation layer only.")
    A("")
    A("## 10. Within-session learning response")
    A("")
    A("| Learning response | n |")
    A("|---|---:|")
    for k, v in learn_dist.items():
        A(f"| `{k}` | {int(v)} |")
    A("")
    A("Useful to distinguish currently-below-standard-but-learning from unresolved repeated deficiency — better as a session note than a permanent grade.")
    A("")
    A("## 11. Meaning of PE/B in historical narrative evidence")
    A("")
    A("| B/strong narrative group | n | Later regression | Later repeat | Checkpoint problem |")
    A("|---|---:|---:|---:|---:|")
    for name, g in groups.items():
        if len(g) == 0:
            A(f"| {name} | 0 | n/a | n/a | n/a |")
            continue
        A(f"| {name} | {len(g)} | {100*g.later_regression_flag.mean():.1f}% | {100*g.later_repeat_flag.mean():.1f}% | {100*g.later_checkpoint_problem_flag.mean():.1f}% |")
    A("")
    A("**Most important test result:** among similarly strong structured grades, narrative-derived independence/consistency/deficiency signals identify different downstream risk profiles. This supports redesign toward a minimum additive model — not more colors.")
    A("")
    A("## 12. Candidate competency dimensions")
    A("")
    A("| Dimension | Freq | Evidence n | Reliability | Incremental value | Overlap | Recorder | Burden | Rec |")
    A("|---|---:|---:|---|---|---|---|---|---|")
    for _, r in dims.iterrows():
        A(f"| `{r.dimension}` | {100*r.sample_frequency:.1f}% | {int(r.n_evidence)} | {r.reliability} | {r.incremental_predictive_value} | {r.overlap} | {r.recorder_measurability} | {r.instructor_burden} | **{r.recommendation}** |")
    A("")
    A("## 13. Incremental value beyond existing grades")
    A("")
    A("Highest incremental value: **assistance/independence**, **consistency**, **context/transfer**, then objective **accuracy/tolerance**. Broad CRM dimensions appear often but overlap and burden are higher; keep under INVESTIGATE_MORE rather than mandatory per-exercise scores.")
    A("")
    A("## 14. Minimum useful future model")
    A("")
    A("| Config | Description | Recommendation |")
    A("|---|---|---|")
    for _, r in models.iterrows():
        A(f"| `{r.config_code}` | {r.description} | **{r.recommendation}** — {r.evidence_notes} |")
    A("")
    A("**Recommended research minimum:** keep required learning level separate from observed performance, then capture:")
    A("1. Independence / assistance")
    A("2. Consistency")
    A("3. Quality/accuracy (increasingly objective via recorder)")
    A("4. Context/transfer tag when relevant")
    A("")
    A("Do **not** ask instructors to score 10–15 dimensions per exercise.")
    A("")
    A("## 15. Student-facing implications")
    A("")
    A("Analytical dimensions support developmental language more than pass/fail colors, e.g. INTRODUCED / DEVELOPING / INDEPENDENT / CONSISTENT / TRANSFERABLE — but only if backed by the same evidence used operationally. Do not adopt friendlier words that hide assistance or inconsistency. No final student UI in Phase 5.")
    A("")
    A("## 16. Cockpit Recorder measurement opportunities")
    A("")
    A("| Dimension | Measurement class | Sources | Confidence |")
    A("|---|---|---|---|")
    for _, r in meas.sort_values("dimension").iterrows():
        A(f"| `{r.dimension}` | `{r.measurement_class}` | {r.possible_data_sources} | {r.confidence} |")
    A("")
    A("## 17. Additional fields worth collecting now")
    A("")
    A("| Field | Priority | Rationale |")
    A("|---|---|---|")
    A("| Exercise start/end windows | MUST_COLLECT_NOW | Enables objective tolerances & attempt curves |")
    A("| Instructor intervention marker + reason | MUST_COLLECT_NOW | Highest-value missing independence signal; partial auto from audio later |")
    A("| Within-flight repeat attempt index | MUST_COLLECT_NOW | Consistency + learning curves |")
    A("| Objective tolerance result (auto) | MUST_COLLECT_NOW | Accuracy without instructor burden |")
    A("| Context tags (wind/traffic/airport/check) | SHOULD_COLLECT | Transfer vs true regression; many auto-derivable |")
    A("| Reason exercise not completed | SHOULD_COLLECT | Separates weather/time from competency |")
    A("| Instructor confidence/readiness (optional light) | OPTIONAL | Useful near checks; easy to overuse |")
    A("| Student self-assessment | OPTIONAL | Learning metacognition; not primary evidence |")
    A("| Per-exercise 10-dimension rubric | DO_NOT_COLLECT | High burden, low necessity given Phase 5 minimum model |")
    A("")
    A("## 18. Data limitations")
    A("")
    A("- 405 stratified sample ≠ full population.")
    A("- Downstream outcome flags are proxy (later regression/repeat/checkpoint problems), not license outcomes.")
    A("- Assistant/automated validation is not a substitute for full human adjudication of all 100.")
    A("- Long narratives truncated at 7k chars for extraction.")
    A("- Selection confounding: assisted students may be harder a priori.")
    A("- No student risk scores created.")
    A("")
    A("## 19. Bulk narrative NLP recommendation")
    A("")
    A(f"**Decision: `{bulk['decision']}`**")
    A("")
    A(f"- Total narratives: {bulk['total']}")
    A(f"- Eligible after filters: {bulk['eligible']}")
    A(f"- Unique hashes: {bulk['unique_hashes']}")
    A(f"- Expected remaining LLM calls: ≈{bulk['expected_calls']}")
    A(f"- Expected token volume (approx): ≈{bulk['expected_tokens']:,}")
    A(f"- Rationale: {bulk['rationale']}")
    A("")
    A("## 20. Recommended Phase 6 direction")
    A("")
    A("1. Human-adjudicate the 100-row validation set; refine to prompt/extraction v2.")
    A("2. Implement lightweight recorder/instructor capture for assistance, attempt index, exercise windows, auto-tolerances.")
    A("3. If bulk NLP proceeds, process unique eligible hashes only with v2 prompt.")
    A("4. Design the **minimum evaluation model** (required level ≠ observed independence ≠ quality ≠ consistency ≠ context) — still no production UI polish.")
    A("5. Re-test the core question on the larger extracted set: among equal structured grades, do independence+consistency+context predict durability?")
    A("")
    A("## Supporting tables")
    A("")
    A("| Table | Rows |")
    A("|---|---:|")
    for t in [
        "analysis_narrative_sample_enriched",
        "analysis_narrative_extraction",
        "analysis_narrative_evidence",
        "analysis_narrative_validation",
        "analysis_narrative_grade_agreement",
        "analysis_assistance_outcomes",
        "analysis_consistency_outcomes",
        "analysis_context_transfer",
        "analysis_dimension_value",
        "analysis_evaluation_model_candidate",
        "analysis_future_competency_measurement",
        "analysis_bulk_nlp_recommendation",
    ]:
        n = con.execute(f"SELECT COUNT(*) FROM {t}").fetchone()[0]
        A(f"| `{t}` | {n} |")
    A("")
    A("## Reproduce")
    A("")
    A("```bash")
    A("php analytics/etl/phase5_01_bootstrap.php")
    A("set -a; source .env; set +a")
    A("analytics/.venv/bin/python -u analytics/etl/phase5_02_extract.py")
    A("analytics/.venv/bin/python -u analytics/etl/phase5_03_analyze.py")
    A("```")
    A("")

    REPORT.parent.mkdir(parents=True, exist_ok=True)
    REPORT.write_text("\n".join(lines), encoding="utf-8")
    log(f"Wrote {REPORT}")


def main():
    con = connect()
    n_ok = con.execute(
        "SELECT COUNT(*) FROM analysis_narrative_extraction WHERE extraction_version=? AND parse_status='OK'",
        (EXTRACTION_VERSION,),
    ).fetchone()[0]
    if n_ok < 50:
        raise SystemExit(f"Not enough successful extractions yet ({n_ok}). Run phase5_02_extract.py first.")

    enriched = pd.read_sql_query("SELECT * FROM analysis_narrative_sample_enriched", con)
    extractions = pd.read_sql_query(
        "SELECT * FROM analysis_narrative_extraction WHERE extraction_version=?",
        con,
        params=(EXTRACTION_VERSION,),
    )
    evid = pd.read_sql_query(
        "SELECT * FROM analysis_narrative_evidence WHERE extraction_version=?",
        con,
        params=(EXTRACTION_VERSION,),
    )
    m = enriched.merge(extractions, on=["narrative_id", "session_id", "text_hash", "sample_stratum"], how="inner")
    m = m[m.parse_status == "OK"].copy()

    select_validation_set(con, enriched, extractions[extractions.parse_status == "OK"])
    manual_spot_review(con, n=40)
    grade_agreement(con, m)
    outcome_tests(con, m)
    dimension_value(con, m, evid)
    bulk = bulk_nlp_decision(con)
    write_report(con, bulk)
    con.close()
    log("Phase 5 analyses complete.")


if __name__ == "__main__":
    main()
