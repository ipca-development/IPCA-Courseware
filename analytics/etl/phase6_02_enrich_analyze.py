#!/usr/bin/env python3
"""Phase 6: targeted historical enrichment, scale re-tests, early warnings, timelines."""

from __future__ import annotations

import hashlib
import html
import importlib.util
import json
import math
import re
import sqlite3
import sys
from collections import Counter, defaultdict
from datetime import datetime, timezone
from pathlib import Path

import pandas as pd

ROOT = Path(__file__).resolve().parents[2]
DB = ROOT / "storage/analytics/egle_training_analytics.sqlite"
VERSION = "phase6-v1"
NOW = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")

# Validated pipelines
LLM_EXTRACTOR = "phase5-extract-v1-agent"
HEUR_EXTRACTOR = "phase5-extract-v1-heuristic"
PHASE6_HEUR = "phase6-extract-v1-heuristic-scaled"
PROMPT_VERSION = "phase5-extract-v1"
SCHEMA_VERSION = "phase5-schema-v1"
HEUR_MODEL = "heuristic-v1"
LLM_MODEL = "cursor-agent-llm-v1"

# Independence mapping (minimal 4-state)
ASSIST_TO_INDEP = {
    "TAKEOVER_OR_SAFETY_INTERVENTION": "ASSISTED",
    "PHYSICAL_INTERVENTION": "ASSISTED",
    "INSTRUCTOR_DEMONSTRATION": "ASSISTED",
    "STEP_BY_STEP_COACHING": "ASSISTED",
    "REPEATED_PROMPTS": "ASSISTED",
    "MINOR_PROMPT": "PROMPTED",
    "VERBAL_CONFIRMATION_ONLY": "PROMPTED",
    "NONE_OBSERVED": "NOT_OBSERVED",
    "UNKNOWN": "NOT_OBSERVED",
}


def log(msg: str) -> None:
    print(msg, flush=True)


def clean(raw: str) -> str:
    t = html.unescape(raw or "")
    t = re.sub(r"<br\s*/?>", "\n", t, flags=re.I)
    t = re.sub(r"<[^>]+>", " ", t)
    t = re.sub(r"[ \t]+", " ", t)
    return re.sub(r"\n{3,}", "\n\n", t).strip()


def is_boilerplate(t: str) -> bool:
    t2 = clean(t).lower().strip()
    if len(t2) < 40:
        return True
    if t2 in {"ok", "good", "n/a", "na", "none", "-", ".", "goed", "prima"}:
        return True
    return False


def load_heuristic_extract():
    path = ROOT / "analytics/etl/phase5_02c_heuristic_extract.py"
    spec = importlib.util.spec_from_file_location("phase5_heur", path)
    mod = importlib.util.module_from_spec(spec)
    assert spec.loader
    spec.loader.exec_module(mod)
    return mod.extract_one


def clear(con: sqlite3.Connection, tables: list[str]) -> None:
    for t in tables:
        con.execute(f"DELETE FROM {t}")


def map_independence(assistance_level: str | None, raw_text: str | None = None) -> str:
    base = ASSIST_TO_INDEP.get(assistance_level or "UNKNOWN", "NOT_OBSERVED")
    if base != "NOT_OBSERVED":
        return base
    # Only promote to INDEPENDENT when explicit independence language exists
    if raw_text and re.search(
        r"\b(independent(ly)?|without\s+(help|assistance|prompt)|unassisted|on\s+(his|her|their)\s+own|zelfstandig)\b",
        raw_text,
        re.I,
    ):
        return "INDEPENDENT"
    return "NOT_OBSERVED"


def map_consistency_class(c: str | None) -> str:
    c = c or "INSUFFICIENT_EVIDENCE"
    if c in ("INSUFFICIENT_EVIDENCE", "UNKNOWN", ""):
        return "NOT_ENOUGH_EVIDENCE"
    if c in ("INCONSISTENT", "VARIABLE"):
        return "VARIABLE"
    if c in ("MOSTLY_CONSISTENT",):
        return "DEVELOPING"
    if c in ("CONSISTENT",):
        return "CONSISTENT"
    return "NOT_ENOUGH_EVIDENCE"


# ---------------------------------------------------------------------------
# Population
# ---------------------------------------------------------------------------


def build_population(con: sqlite3.Connection) -> pd.DataFrame:
    log("Building high-value NLP population...")
    narr = pd.read_sql_query(
        """
        SELECT n.narrative_id, n.session_id, n.text_hash, n.raw_text, n.character_count,
               n.student_id, n.instructor_id,
               s.program_id, s.session_date, s.grading_raw, s.grading_color, s.grading_completion,
               s.mission_attempt_number, s.mission_id, s.days_since_previous_session,
               substr(s.session_date,1,4) AS session_year,
               r.mission_role, r.mission_role_reason,
               cv.version_code
        FROM fact_narrative n
        JOIN fact_training_session s ON s.session_id = n.session_id
        LEFT JOIN analysis_mission_role r ON r.mission_id = s.mission_id
        LEFT JOIN dim_curriculum_version cv ON cv.curriculum_version_id = s.curriculum_version_id
        WHERE n.comment_type = 'public'
        """,
        con,
    )

    narr["clean_len"] = narr.raw_text.map(lambda x: len(clean(x)))
    narr["boilerplate"] = narr.raw_text.map(is_boilerplate)
    eligible = narr[(narr.clean_len >= 40) & (~narr.boilerplate)].copy()

    # Session-level flags from exercises
    ex = pd.read_sql_query(
        """
        SELECT session_id,
               MAX(CASE WHEN exercise_regressed=1 THEN 1 ELSE 0 END) AS has_regression,
               MAX(CASE WHEN required_level_not_met=1 THEN 1 ELSE 0 END) AS has_below,
               MAX(CASE WHEN achieved_grade_raw='B' OR achieved_competency_stage='PE' THEN 1 ELSE 0 END) AS has_pe
        FROM fact_exercise_attempt
        GROUP BY session_id
        """,
        con,
    )
    eligible = eligible.merge(ex, on="session_id", how="left")
    for c in ("has_regression", "has_below", "has_pe"):
        eligible[c] = eligible[c].fillna(0).astype(int)

    # Later regression after high grade (student-level linkage via subsequent session)
    sess = pd.read_sql_query(
        """
        SELECT session_id, student_id, program_id, session_date, grading_raw, mission_id
        FROM fact_training_session
        WHERE session_date_valid=1
        ORDER BY student_id, program_id, session_date, session_id
        """,
        con,
    )
    high_later_problem = set()
    for (_, _), g in sess.groupby(["student_id", "program_id"]):
        g = g.sort_values(["session_date", "session_id"])
        grades = g.grading_raw.tolist()
        ids = g.session_id.tolist()
        for i, sid in enumerate(ids):
            if grades[i] in ("GC", "BC", "GI", "BI"):
                # look ahead 1–3 sessions for R or incomplete or regression flag
                for j in range(i + 1, min(i + 4, len(ids))):
                    if grades[j] in ("RC", "RI", "YC", "YI") or str(grades[j]).startswith("R"):
                        high_later_problem.add(sid)
                        break

    # Curriculum transition cohorts: version_code change for same student
    transition_sessions = set()
    if "version_code" in eligible.columns:
        st = eligible.dropna(subset=["student_id", "program_id", "session_date"]).sort_values(
            ["student_id", "program_id", "session_date", "session_id"]
        )
        prev = {}
        for row in st.itertuples():
            key = (row.student_id, row.program_id)
            vc = getattr(row, "version_code", None)
            if key in prev and vc and prev[key] and vc != prev[key]:
                transition_sessions.add(row.session_id)
            if vc:
                prev[key] = vc

    def bucket(row) -> str | None:
        reasons = []
        if row.has_regression == 1:
            reasons.append("EXERCISE_REGRESSED")
        if row.mission_role == "CHECK_EVENT":
            reasons.append("CHECKPOINT_DIFFICULTY")
        if int(row.mission_attempt_number if pd.notna(row.mission_attempt_number) else 1) >= 2:
            reasons.append("REPEATED_PROGRESSION")
        if row.session_id in high_later_problem:
            reasons.append("HIGH_GRADE_LATER_PROBLEM")
        if row.has_pe == 1 and row.has_regression == 1:
            reasons.append("PE_FOLLOWED_BY_REGRESSION")
        if row.session_id in transition_sessions:
            reasons.append("CURRICULUM_TRANSITION")
        if row.mission_role == "PROGRESSION_MISSION" and row.has_below == 1:
            reasons.append("PROGRESSION_BELOW_REQUIRED")
        if row.grading_color == "R" or row.grading_completion == "I":
            reasons.append("SESSION_BELOW_OR_INCOMPLETE")
        if not reasons:
            return None
        # priority order for primary bucket
        order = [
            "PE_FOLLOWED_BY_REGRESSION",
            "HIGH_GRADE_LATER_PROBLEM",
            "CHECKPOINT_DIFFICULTY",
            "EXERCISE_REGRESSED",
            "REPEATED_PROGRESSION",
            "SESSION_BELOW_OR_INCOMPLETE",
            "CURRICULUM_TRANSITION",
            "PROGRESSION_BELOW_REQUIRED",
        ]
        for o in order:
            if o in reasons:
                return o
        return reasons[0]

    eligible["sample_bucket"] = eligible.apply(bucket, axis=1)
    high = eligible[eligible.sample_bucket.notna()].copy()

    # Deduplicate by text_hash keeping richest / earliest narrative_id
    high = high.sort_values(["character_count", "narrative_id"], ascending=[False, True])
    unique = high.drop_duplicates("text_hash", keep="first").copy()

    done = pd.read_sql_query(
        "SELECT DISTINCT text_hash FROM analysis_narrative_extraction WHERE parse_status='OK'",
        con,
    )
    unique["already_extracted"] = unique.text_hash.isin(set(done.text_hash)).astype(int)

    clear(con, ["analysis_phase6_nlp_population"])
    rows = []
    for r in unique.itertuples():
        rows.append(
            (
                int(r.narrative_id),
                int(r.session_id) if pd.notna(r.session_id) else None,
                r.text_hash,
                r.sample_bucket,
                int(r.program_id) if pd.notna(r.program_id) else None,
                getattr(r, "version_code", None),
                int(r.session_year) if pd.notna(r.session_year) and str(r.session_year).isdigit() else None,
                int(r.character_count) if pd.notna(r.character_count) else None,
                int(r.already_extracted),
                VERSION,
                NOW,
            )
        )
    con.executemany(
        """INSERT INTO analysis_phase6_nlp_population
        (narrative_id,session_id,text_hash,sample_bucket,program_id,version_code,session_year,
         character_count,already_extracted,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)""",
        rows,
    )
    con.commit()
    log(f"Population unique hashes={len(unique)} already_extracted={int(unique.already_extracted.sum())}")
    return unique


# ---------------------------------------------------------------------------
# Enrichment
# ---------------------------------------------------------------------------


def enrich_population(con: sqlite3.Connection, pop: pd.DataFrame, extract_one) -> None:
    log("Enriching population (reuse LLM hashes + heuristic scaled)...")
    clear(con, ["analysis_phase6_narrative_extraction", "analysis_phase6_narrative_evidence"])

    # Load existing LLM extractions for overlapping hashes
    llm = pd.read_sql_query(
        f"""
        SELECT e.*, n.raw_text
        FROM analysis_narrative_extraction e
        JOIN fact_narrative n ON n.narrative_id=e.narrative_id
        WHERE e.extraction_version=? AND e.parse_status='OK'
        """,
        con,
        params=(LLM_EXTRACTOR,),
    )
    llm_by_hash = {r.text_hash: r for r in llm.itertuples()}

    # Evidence for LLM
    llm_ev = pd.read_sql_query(
        f"""
        SELECT * FROM analysis_narrative_evidence
        WHERE extraction_version=?
        """,
        con,
        params=(LLM_EXTRACTOR,),
    )
    llm_ev_by_nid = defaultdict(list)
    for r in llm_ev.itertuples():
        llm_ev_by_nid[r.narrative_id].append(r)

    # Prefer population narrative rows; fetch grading
    sess_grade = pd.read_sql_query(
        "SELECT session_id, grading_raw FROM fact_training_session",
        con,
    )
    grade_map = dict(zip(sess_grade.session_id, sess_grade.grading_raw))

    narr_map = pd.read_sql_query(
        "SELECT narrative_id, session_id, text_hash, raw_text FROM fact_narrative",
        con,
    )
    narr_by_id = {r.narrative_id: r for r in narr_map.itertuples()}

    n_llm = n_heur = 0
    for r in pop.itertuples():
        nid = int(r.narrative_id)
        n = narr_by_id.get(nid)
        if n is None:
            continue
        text = clean(n.raw_text)
        grading = grade_map.get(int(r.session_id)) if pd.notna(r.session_id) else None

        if r.text_hash in llm_by_hash:
            L = llm_by_hash[r.text_hash]
            independence = map_independence(L.assistance_level, text)
            consistency = map_consistency_class(L.consistency_class)
            flags = L.summary_flags_json
            cur = con.execute(
                """INSERT INTO analysis_phase6_narrative_extraction
                (narrative_id,session_id,text_hash,sample_bucket,extractor,prompt_version,schema_version,model,
                 overall_narrative_tone,assistance_level,independence_mapped,consistency_class,accuracy_quality,
                 learning_response,context_tags_json,context_effect,transfer_interpretation,summary_flags_json,
                 raw_response_json,parse_status,analysis_version,generated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
                (
                    nid,
                    int(r.session_id) if pd.notna(r.session_id) else None,
                    r.text_hash,
                    r.sample_bucket,
                    "LLM_V1_REUSED",
                    L.prompt_version or PROMPT_VERSION,
                    SCHEMA_VERSION,
                    L.llm_model or LLM_MODEL,
                    L.overall_narrative_tone,
                    L.assistance_level,
                    independence,
                    consistency,
                    L.accuracy_quality,
                    L.learning_response,
                    L.context_tags_json,
                    L.context_effect,
                    L.transfer_interpretation,
                    flags,
                    L.raw_response_json,
                    "OK",
                    VERSION,
                    NOW,
                ),
            )
            eid = cur.lastrowid
            # Prefer evidence from matching narrative_id; else from source extraction
            src_nid = int(L.narrative_id)
            for ev in llm_ev_by_nid.get(src_nid, []):
                con.execute(
                    """INSERT INTO analysis_phase6_narrative_evidence
                    (extraction_id,narrative_id,text_hash,evidence_span,observation_polarity,interpretation,
                     competency_dimensions_json,severity,confidence,span_verified,extractor,analysis_version,generated_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)""",
                    (
                        eid,
                        nid,
                        r.text_hash,
                        ev.evidence_span,
                        ev.observation_polarity,
                        ev.interpretation,
                        ev.competency_dimensions_json,
                        ev.severity,
                        ev.confidence,
                        int(ev.span_verified or 0),
                        "LLM_V1_REUSED",
                        VERSION,
                        NOW,
                    ),
                )
            n_llm += 1
        else:
            p = extract_one(text, grading)
            independence = map_independence(p["assistance_level"], text)
            consistency = map_consistency_class(p["consistency_class"])
            cur = con.execute(
                """INSERT INTO analysis_phase6_narrative_extraction
                (narrative_id,session_id,text_hash,sample_bucket,extractor,prompt_version,schema_version,model,
                 overall_narrative_tone,assistance_level,independence_mapped,consistency_class,accuracy_quality,
                 learning_response,context_tags_json,context_effect,transfer_interpretation,summary_flags_json,
                 raw_response_json,parse_status,analysis_version,generated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
                (
                    nid,
                    int(r.session_id) if pd.notna(r.session_id) else None,
                    r.text_hash,
                    r.sample_bucket,
                    PHASE6_HEUR,
                    "phase5-heuristic-v1",
                    SCHEMA_VERSION,
                    HEUR_MODEL,
                    p["overall_narrative_tone"],
                    p["assistance_level"],
                    independence,
                    consistency,
                    p["accuracy_quality"],
                    p["learning_response"],
                    json.dumps(p["context_tags"]),
                    p["context_effect"],
                    p["transfer_interpretation"],
                    json.dumps(p["flags"]),
                    json.dumps(p, ensure_ascii=False),
                    "OK",
                    VERSION,
                    NOW,
                ),
            )
            eid = cur.lastrowid
            for obs in p["observations"]:
                span = obs["evidence_span"]
                verified = 1 if span and span in text else 0
                con.execute(
                    """INSERT INTO analysis_phase6_narrative_evidence
                    (extraction_id,narrative_id,text_hash,evidence_span,observation_polarity,interpretation,
                     competency_dimensions_json,severity,confidence,span_verified,extractor,analysis_version,generated_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)""",
                    (
                        eid,
                        nid,
                        r.text_hash,
                        span,
                        obs["polarity"],
                        obs["interpretation"],
                        json.dumps(obs["dimensions"]),
                        obs["severity"],
                        obs["confidence"],
                        verified,
                        PHASE6_HEUR,
                        VERSION,
                        NOW,
                    ),
                )
            n_heur += 1

        if (n_llm + n_heur) % 1000 == 0:
            con.commit()
            log(f"  enriched {n_llm + n_heur}/{len(pop)}")

    con.commit()
    log(f"Enrichment done LLM_reused={n_llm} heuristic_scaled={n_heur}")


# ---------------------------------------------------------------------------
# QA monitoring + scale findings
# ---------------------------------------------------------------------------


def qa_and_scale(con: sqlite3.Connection) -> dict:
    log("QA stratification + Phase 5B findings at scale...")
    clear(con, ["analysis_phase6_nlp_qa", "analysis_phase6_scale_findings"])

    df = pd.read_sql_query(
        """
        SELECT e.extraction_id, e.narrative_id, e.session_id, e.text_hash, e.extractor,
               e.prompt_version, e.schema_version, e.model,
               e.overall_narrative_tone, e.assistance_level, e.independence_mapped,
               e.consistency_class, e.accuracy_quality, e.learning_response,
               e.context_tags_json, e.context_effect, e.transfer_interpretation,
               e.summary_flags_json,
               p.sample_bucket AS sample_bucket, p.program_id, p.session_year, p.character_count, p.version_code,
               s.grading_raw, s.grading_color, s.instructor_id, s.student_id, s.days_since_previous_session,
               s.mission_id, s.session_date, n.raw_text
        FROM analysis_phase6_narrative_extraction e
        JOIN analysis_phase6_nlp_population p ON p.narrative_id=e.narrative_id
        JOIN fact_training_session s ON s.session_id=e.session_id
        JOIN fact_narrative n ON n.narrative_id=e.narrative_id
        WHERE e.parse_status='OK'
        """,
        con,
    )
    ev = pd.read_sql_query(
        "SELECT extraction_id, observation_polarity, competency_dimensions_json FROM analysis_phase6_narrative_evidence",
        con,
    )

    def has_def(flags, tone, eid_set_def):
        try:
            f = json.loads(flags) if isinstance(flags, str) else (flags or {})
        except Exception:
            f = {}
        return bool(f.get("encouraging_tone_with_deficiency")) or eid_set_def

    def_eids = set(ev[ev.observation_polarity == "DEFICIENCY"].extraction_id)

    df["has_deficiency"] = df.extraction_id.isin(def_eids)
    df["ctx_present"] = df.context_tags_json.map(
        lambda x: bool(json.loads(x)) if isinstance(x, str) and x not in ("", "null", "[]") else False
    )
    df["cons_present"] = df.consistency_class.isin(["VARIABLE", "DEVELOPING", "CONSISTENT"])
    df["assist_present"] = ~df.assistance_level.isin(["NONE_OBSERVED", "UNKNOWN", None, ""])
    df["strong_grade"] = df.grading_raw.isin(["GC", "BC", "GI", "BI"])
    df["encouraging_def"] = df.apply(
        lambda r: r.overall_narrative_tone in ("POSITIVE", "MIXED") and bool(r.has_deficiency),
        axis=1,
    )
    df["high_grade_def"] = df.strong_grade & df.has_deficiency
    df["len_bin"] = pd.cut(
        df.character_count.fillna(0),
        bins=[-1, 120, 400, 1200, 10_000_000],
        labels=["short", "medium", "long", "very_long"],
    )

    # Dimension frequency by program for anomaly flags
    dim_rows = []
    for r in ev.itertuples():
        try:
            dims = json.loads(r.competency_dimensions_json or "[]")
        except Exception:
            dims = []
        for d in dims:
            dim_rows.append((r.extraction_id, d))
    dim_df = pd.DataFrame(dim_rows, columns=["extraction_id", "dimension"])
    merged = dim_df.merge(df[["extraction_id", "program_id"]], on="extraction_id", how="left")

    qa_rows = []
    for stratum_name, grouper in [
        ("program_id", "program_id"),
        ("session_year", "session_year"),
        ("len_bin", "len_bin"),
        ("extractor", "extractor"),
        ("sample_bucket", "sample_bucket"),
    ]:
        for key, g in df.groupby(grouper, dropna=False):
            qa_rows.append(
                (
                    f"{stratum_name}={key}",
                    "n",
                    float(len(g)),
                    int(len(g)),
                    None,
                    VERSION,
                    NOW,
                )
            )
            qa_rows.append(
                (
                    f"{stratum_name}={key}",
                    "deficiency_rate",
                    float(g.has_deficiency.mean()),
                    int(len(g)),
                    None,
                    VERSION,
                    NOW,
                )
            )
            qa_rows.append(
                (
                    f"{stratum_name}={key}",
                    "consistency_present_rate",
                    float(g.cons_present.mean()),
                    int(len(g)),
                    None,
                    VERSION,
                    NOW,
                )
            )
            qa_rows.append(
                (
                    f"{stratum_name}={key}",
                    "context_present_rate",
                    float(g.ctx_present.mean()),
                    int(len(g)),
                    None,
                    VERSION,
                    NOW,
                )
            )

    # Flag unusual program dimension distributions (vs overall)
    if not merged.empty:
        overall = merged.dimension.value_counts(normalize=True)
        for pid, g in merged.groupby("program_id"):
            if len(g) < 80:
                continue
            rates = g.dimension.value_counts(normalize=True)
            for dim, rate in rates.items():
                base = float(overall.get(dim, 0))
                if base > 0.02 and abs(rate - base) > 0.15:
                    qa_rows.append(
                        (
                            f"program_id={pid}",
                            f"dimension_shift:{dim}",
                            float(rate - base),
                            int(len(g)),
                            f"program={rate:.2f} overall={base:.2f}",
                            VERSION,
                            NOW,
                        )
                    )

    con.executemany(
        """INSERT INTO analysis_phase6_nlp_qa
        (stratum,metric_name,metric_value,n,notes,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?)""",
        qa_rows,
    )

    # Scale findings mirroring Phase 5B questions
    findings = []

    def add(name, value, n, population, notes=""):
        findings.append((name, float(value) if value is not None and not (isinstance(value, float) and math.isnan(value)) else None, int(n), population, notes, VERSION, NOW))

    add("n_extractions", len(df), len(df), "phase6_high_value", df.extractor.value_counts().to_dict().__str__())
    add("deficiency_rate", df.has_deficiency.mean(), len(df), "phase6_high_value")
    add("encouraging_tone_with_deficiency_rate", df.encouraging_def.mean(), len(df), "phase6_high_value")
    add("high_grade_with_narrative_deficiency_rate", df.high_grade_def.mean(), int(df.strong_grade.sum()), "strong_grades")
    add("consistency_signal_rate", df.cons_present.mean(), len(df), "phase6_high_value")
    add("context_signal_rate", df.ctx_present.mean(), len(df), "phase6_high_value")
    add("assistance_language_rate", df.assist_present.mean(), len(df), "phase6_high_value")
    add(
        "independence_not_observed_rate",
        (df.independence_mapped == "NOT_OBSERVED").mean(),
        len(df),
        "phase6_high_value",
        "Silence mapped to NOT_OBSERVED, never INDEPENDENT",
    )
    add(
        "independence_explicit_independent_rate",
        (df.independence_mapped == "INDEPENDENT").mean(),
        len(df),
        "phase6_high_value",
    )

    # PE followed by narrative warning: PE grade / has_pe session with deficiency
    pe_sess = df[df.grading_raw.isin(["BC", "BI"]) | df.sample_bucket.eq("PE_FOLLOWED_BY_REGRESSION")]
    add("pe_context_deficiency_rate", pe_sess.has_deficiency.mean() if len(pe_sess) else 0, len(pe_sess), "pe_context")

    # Narrative warning preceding later regression (bucket HIGH_GRADE_LATER_PROBLEM)
    hglp = df[df.sample_bucket == "HIGH_GRADE_LATER_PROBLEM"]
    add("high_grade_later_problem_deficiency_rate", hglp.has_deficiency.mean() if len(hglp) else 0, len(hglp), "high_grade_later_problem")

    # Heuristic-only vs LLM-reuse split
    for ext, g in df.groupby("extractor"):
        add(f"deficiency_rate[{ext}]", g.has_deficiency.mean(), len(g), str(ext))
        add(f"consistency_signal_rate[{ext}]", g.cons_present.mean(), len(g), str(ext))
        add(f"encouraging_def_rate[{ext}]", g.encouraging_def.mean(), len(g), str(ext))

    # Compare to Phase 5B 405 LLM rates
    add("phase5b_llm_mismatch_hidden_signal", 0.635, 405, "phase5b_reference")
    add("phase5b_llm_encouraging_def", 242 / 405, 405, "phase5b_reference")
    add("phase5b_llm_consistency_present", 0.528, 405, "phase5b_reference")

    con.executemany(
        """INSERT INTO analysis_phase6_scale_findings
        (finding_name,metric_value,n,population,notes,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?)""",
        findings,
    )
    con.commit()
    return {"df": df, "n": len(df)}


# ---------------------------------------------------------------------------
# Early warning patterns (explainable, no risk score)
# ---------------------------------------------------------------------------


def early_warnings(con: sqlite3.Connection, df: pd.DataFrame) -> None:
    log("Computing explainable early-warning patterns...")
    clear(con, ["analysis_phase6_early_warning_pattern"])

    # Build student session order with regression outcomes
    sess = pd.read_sql_query(
        """
        SELECT session_id, student_id, program_id, session_date, grading_raw, grading_color,
               days_since_previous_session, mission_attempt_number
        FROM fact_training_session
        WHERE session_date_valid=1
        """,
        con,
    )
    reg = pd.read_sql_query(
        """
        SELECT session_id, MAX(exercise_regressed) AS has_regression,
               MAX(required_level_not_met) AS has_below
        FROM fact_exercise_attempt GROUP BY session_id
        """,
        con,
    )
    sess = sess.merge(reg, on="session_id", how="left")
    sess["problem"] = (
        sess.grading_color.eq("R")
        | sess.has_regression.fillna(0).eq(1)
        | sess.has_below.fillna(0).eq(1)
        | sess.grading_raw.isin(["RC", "RI", "YC", "YI"])
    )

    # Attach narrative signals
    sig = df[
        [
            "session_id",
            "student_id",
            "program_id",
            "cons_present",
            "consistency_class",
            "has_deficiency",
            "encouraging_def",
            "independence_mapped",
            "days_since_previous_session",
        ]
    ].drop_duplicates("session_id")

    patterns = []

    def later_problem_rate(mask_sessions: set[int], horizon: int = 3) -> tuple[float, int, float, int]:
        """Return (rate, n_episodes, baseline, n_base) for later problem within horizon sessions."""
        episodes = 0
        hits = 0
        # baseline: any session later problem
        base_ep = base_hit = 0
        by_sp = {k: g.sort_values(["session_date", "session_id"]) for k, g in sess.groupby(["student_id", "program_id"])}
        for g in by_sp.values():
            ids = g.session_id.tolist()
            probs = g.problem.tolist()
            for i, sid in enumerate(ids):
                future = probs[i + 1 : i + 1 + horizon]
                if not future:
                    continue
                base_ep += 1
                if any(future):
                    base_hit += 1
                if sid in mask_sessions:
                    episodes += 1
                    if any(future):
                        hits += 1
        rate = hits / episodes if episodes else 0.0
        base = base_hit / base_ep if base_ep else 0.0
        return rate, episodes, base, base_ep

    # Pattern 1: consistency concern VARIABLE
    var_sessions = set(sig[sig.consistency_class == "VARIABLE"].session_id)
    rate, n, base, _ = later_problem_rate(var_sessions)
    patterns.append(
        (
            "CONSISTENCY_CONCERN",
            "Narrative/extracted consistency class VARIABLE preceding later training problem",
            int(sig[sig.consistency_class == "VARIABLE"].student_id.nunique()),
            n,
            rate,
            base,
            "Consistency concern appeared in recent observations (VARIABLE).",
            VERSION,
            NOW,
        )
    )

    # Pattern 2: high grade + deficiency
    hgd = set(df[df.high_grade_def].session_id)
    rate, n, base, _ = later_problem_rate(hgd)
    patterns.append(
        (
            "HIGH_GRADE_NARRATIVE_DEFICIENCY",
            "Strong structured grade with narrative deficiency signal",
            int(df[df.high_grade_def].student_id.nunique()),
            n,
            rate,
            base,
            "High structured grade coexisted with narrative deficiency evidence.",
            VERSION,
            NOW,
        )
    )

    # Pattern 3: long gap then next session
    long_gap = set(sess[sess.days_since_previous_session.fillna(0) >= 14].session_id)
    rate, n, base, _ = later_problem_rate(long_gap, horizon=1)
    patterns.append(
        (
            "LONG_TRAINING_GAP",
            "≥14-day gap before session; problem in immediate next sessions",
            int(sess[sess.days_since_previous_session.fillna(0) >= 14].student_id.nunique()),
            n,
            rate,
            base,
            "Competency may degrade after a long training gap (≥14 days).",
            VERSION,
            NOW,
        )
    )

    # Pattern 4: repeated deficiency across missions (same student, 3 of last 5)
    repeat_sessions = set()
    for (_, _), g in sig.sort_values("session_id").groupby(["student_id", "program_id"]):
        g = g.sort_values("session_id")
        flags = g.has_deficiency.astype(int).tolist()
        ids = g.session_id.tolist()
        for i in range(len(ids)):
            window = flags[max(0, i - 4) : i + 1]
            if sum(window) >= 3 and len(window) >= 3:
                repeat_sessions.add(ids[i])
    rate, n, base, _ = later_problem_rate(repeat_sessions)
    patterns.append(
        (
            "REPEATED_DEFICIENCY_WINDOW",
            "Deficiency signal in ≥3 of last ≤5 enriched observations",
            len({(s,) for s in repeat_sessions}),
            n,
            rate,
            base,
            "Consistency/deficiency concern appeared in 3 of the last 5 observations.",
            VERSION,
            NOW,
        )
    )

    # Pattern 5: independent language but VARIABLE consistency
    ind_var = set(
        sig[(sig.independence_mapped == "INDEPENDENT") & (sig.consistency_class == "VARIABLE")].session_id
    )
    rate, n, base, _ = later_problem_rate(ind_var)
    patterns.append(
        (
            "INDEPENDENT_BUT_INCONSISTENT",
            "Explicit independence language with VARIABLE consistency",
            int(
                sig[(sig.independence_mapped == "INDEPENDENT") & (sig.consistency_class == "VARIABLE")].student_id.nunique()
            ),
            n,
            rate,
            base,
            "Independent execution noted, but consistency remains VARIABLE.",
            VERSION,
            NOW,
        )
    )

    con.executemany(
        """INSERT INTO analysis_phase6_early_warning_pattern
        (pattern_code,description,n_students,n_episodes,later_problem_rate,baseline_rate,
         explainable_template,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?)""",
        patterns,
    )
    con.commit()


# ---------------------------------------------------------------------------
# Prototype entities + timelines
# ---------------------------------------------------------------------------


def populate_proto_entities(con: sqlite3.Connection, df: pd.DataFrame) -> None:
    log("Populating prototype competency entities from historical facts...")
    clear(
        con,
        [
            "competency_expectation",
            "exercise_attempt_proto",
            "evidence_item",
            "context_snapshot",
            "competency_state",
            "instructor_intervention",
            "objective_measurement",
        ],
    )

    # Expectations from exercises (sample of recent high-value sessions)
    sample_sessions = df.session_id.dropna().astype(int).unique().tolist()
    if len(sample_sessions) > 5000:
        sample_sessions = sample_sessions[:5000]
    placeholders = ",".join("?" * len(sample_sessions)) if sample_sessions else "NULL"

    if sample_sessions:
        ex = pd.read_sql_query(
            f"""
            SELECT a.*, e.exercise_name_normalized, e.required_level_normalized AS dim_req
            FROM fact_exercise_attempt a
            LEFT JOIN dim_exercise e ON e.exercise_id=a.exercise_id
            WHERE a.session_id IN ({placeholders})
              AND COALESCE(a.deferred,0)=0
            """,
            con,
            params=sample_sessions,
        )
    else:
        ex = pd.DataFrame()

    if not ex.empty:
        exp_rows = []
        for r in ex.itertuples():
            exp_rows.append(
                (
                    int(r.student_id) if pd.notna(r.student_id) else None,
                    int(r.program_id) if pd.notna(r.program_id) else None,
                    int(r.mission_id) if pd.notna(r.mission_id) else None,
                    int(r.exercise_id) if pd.notna(r.exercise_id) else None,
                    int(r.source_exercise_id) if pd.notna(r.source_exercise_id) else None,
                    r.required_level_normalized or r.dim_req,
                    "HISTORICAL_CURRICULUM",
                    int(r.session_id),
                    VERSION,
                    NOW,
                )
            )
        con.executemany(
            """INSERT INTO competency_expectation
            (student_id,program_id,mission_id,exercise_id,source_exercise_id,curriculum_expected_level,
             source,session_id,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?)""",
            exp_rows,
        )

        # Attach narrative independence/consistency by session
        by_sess = df.drop_duplicates("session_id").set_index("session_id")
        att_rows = []
        for r in ex.itertuples():
            indep = "NOT_OBSERVED"
            cons = "NOT_ENOUGH_EVIDENCE"
            if r.session_id in by_sess.index:
                indep = by_sess.loc[r.session_id, "independence_mapped"]
                cons = by_sess.loc[r.session_id, "consistency_class"]
                if isinstance(indep, pd.Series):
                    indep = indep.iloc[0]
                if isinstance(cons, pd.Series):
                    cons = cons.iloc[0]
            # objective quality unknown historically
            att_rows.append(
                (
                    int(r.student_id) if pd.notna(r.student_id) else None,
                    int(r.program_id) if pd.notna(r.program_id) else None,
                    int(r.session_id),
                    int(r.mission_id) if pd.notna(r.mission_id) else None,
                    int(r.exercise_id) if pd.notna(r.exercise_id) else None,
                    int(r.source_exercise_id) if pd.notna(r.source_exercise_id) else None,
                    int(r.exercise_attempt_number) if pd.notna(r.exercise_attempt_number) else 1,
                    r.session_date,
                    r.achieved_grade_raw,
                    r.required_level_normalized,
                    int(r.required_level_met) if pd.notna(r.required_level_met) else None,
                    indep,
                    cons if int(r.exercise_attempt_number or 1) >= 3 else "NOT_ENOUGH_EVIDENCE",
                    "UNKNOWN",
                    VERSION,
                    NOW,
                )
            )
        con.executemany(
            """INSERT INTO exercise_attempt_proto
            (student_id,program_id,session_id,mission_id,exercise_id,source_exercise_id,attempt_number,
             session_date,achieved_grade_raw,required_level,required_met,independence_state,
             consistency_within_session,objective_quality_state,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
            att_rows,
        )

    # Evidence items from phase6 extractions (narrative) + historical grades
    ev_rows = []
    for r in df.itertuples():
        ev_rows.append(
            (
                "HISTORICAL_NARRATIVE",
                int(r.student_id) if pd.notna(r.student_id) else None,
                int(r.session_id) if pd.notna(r.session_id) else None,
                int(r.narrative_id),
                None,
                r.session_date if hasattr(r, "session_date") else None,
                None,
                "DERIVED" if r.extractor == PHASE6_HEUR else "DERIVED",
                "LOW" if r.extractor == PHASE6_HEUR else "MEDIUM",
                f"{r.extractor}|{r.prompt_version}|{r.model}|{r.schema_version}",
                r.text_hash,
                json.dumps(
                    {
                        "tone": r.overall_narrative_tone,
                        "independence": r.independence_mapped,
                        "consistency": r.consistency_class,
                        "bucket": r.sample_bucket,
                    }
                ),
                VERSION,
                NOW,
            )
        )
        if pd.notna(r.grading_raw):
            ev_rows.append(
                (
                    "HISTORICAL_GRADE",
                    int(r.student_id) if pd.notna(r.student_id) else None,
                    int(r.session_id) if pd.notna(r.session_id) else None,
                    int(r.narrative_id),
                    None,
                    None,
                    None,
                    "RAW",
                    "HIGH",
                    "egle-sctr_grading",
                    f"session:{r.session_id}",
                    json.dumps({"grading_raw": r.grading_raw}),
                    VERSION,
                    NOW,
                )
            )
    # limit size
    con.executemany(
        """INSERT INTO evidence_item
        (evidence_source,student_id,session_id,narrative_id,exercise_attempt_id,timestamp_start,timestamp_end,
         raw_or_derived,confidence,model_or_algorithm_version,source_reference,payload_json,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
        ev_rows[:20000],
    )

    # Context snapshots from tags
    ctx_rows = []
    for r in df.itertuples():
        try:
            tags = json.loads(r.context_tags_json) if r.context_tags_json else []
        except Exception:
            tags = []
        if not tags:
            continue
        ctx_rows.append(
            (
                int(r.session_id) if pd.notna(r.session_id) else None,
                None,
                json.dumps({"tags": tags, "effect": r.context_effect, "transfer": r.transfer_interpretation}),
                "NARRATIVE_DERIVED",
                VERSION,
                NOW,
            )
        )
    con.executemany(
        """INSERT INTO context_snapshot
        (session_id,exercise_attempt_id,context_json,derivation_mode,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?)""",
        ctx_rows[:10000],
    )

    # Intervention candidates from assistance
    interv_map = {
        "TAKEOVER_OR_SAFETY_INTERVENTION": "SAFETY_TAKEOVER",
        "PHYSICAL_INTERVENTION": "PHYSICAL_INTERVENTION",
        "INSTRUCTOR_DEMONSTRATION": "DEMONSTRATION",
        "STEP_BY_STEP_COACHING": "CORRECTION",
        "REPEATED_PROMPTS": "VERBAL_PROMPT",
        "MINOR_PROMPT": "VERBAL_PROMPT",
        "VERBAL_CONFIRMATION_ONLY": "VERBAL_PROMPT",
    }
    irows = []
    for r in df.itertuples():
        et = interv_map.get(r.assistance_level)
        if not et:
            continue
        irows.append(
            (
                int(r.session_id) if pd.notna(r.session_id) else None,
                None,
                et,
                None,
                "historical_narrative_language",
                "MEDIUM",
                "CANDIDATE_UNCONFIRMED",
                "HISTORICAL_NARRATIVE",
                VERSION,
                NOW,
            )
        )
    con.executemany(
        """INSERT INTO instructor_intervention
        (session_id,exercise_attempt_id,event_type,timestamp,reason,severity,confirmation_status,source,
         analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?)""",
        irows,
    )
    con.commit()


def build_timelines(con: sqlite3.Connection, df: pd.DataFrame) -> list[dict]:
    log("Building 20–30 evidence-based competency timelines...")
    clear(con, ["competency_timeline_example", "competency_state"])

    # Focus exercises with repeated attempts
    ex = pd.read_sql_query(
        """
        SELECT a.student_id, a.program_id, a.session_id, a.session_date, a.source_exercise_id,
               a.exercise_id, a.achieved_grade_raw, a.required_level_normalized, a.required_level_met,
               a.exercise_regressed, a.exercise_attempt_number,
               COALESCE(e.exercise_name_normalized, a.exercise_name_raw) AS exercise_name,
               s.grading_raw, s.days_since_previous_session, s.mission_id
        FROM fact_exercise_attempt a
        JOIN fact_training_session s ON s.session_id=a.session_id
        LEFT JOIN dim_exercise e ON e.exercise_id=a.exercise_id
        WHERE COALESCE(a.deferred,0)=0
          AND a.required_level_normalized IN ('PR','PE','EX','DE')
          AND length(COALESCE(e.exercise_name_normalized, a.exercise_name_raw,'')) > 3
        """,
        con,
    )
    # Family key: strip required-level suffix noise
    def family(name: str) -> str:
        n = re.sub(r"\s*\((DE|EX|PR|PE)\)\s*", " ", name or "", flags=re.I)
        n = re.sub(r"\s+", " ", n).strip().lower()
        return n[:80]

    ex["family"] = ex.exercise_name.map(family)
    narr_by_sess = df.drop_duplicates("session_id").set_index("session_id")

    # Candidate trajectories: student+program+family with >=3 sessions
    examples = []
    pattern_quota = {
        "stable_competency": 4,
        "independent_but_inconsistent": 3,
        "apparent_regression": 4,
        "contextual_drop": 3,
        "long_gap_degradation": 3,
        "within_session_improvement": 3,
        "persistent_plateau": 3,
        "high_grade_narrative_warning": 4,
    }
    counts = Counter()

    # Precompute groups
    groups = list(ex.groupby(["student_id", "program_id", "family"]))
    # Prefer groups overlapping enriched sessions
    enriched_sessions = set(df.session_id.dropna().astype(int))

    def narr_bits(sid):
        if sid not in narr_by_sess.index:
            return None
        row = narr_by_sess.loc[sid]
        if isinstance(row, pd.DataFrame):
            row = row.iloc[0]
        return row

    for (student_id, program_id, fam), g in groups:
        if sum(counts.values()) >= 30:
            break
        g = g.sort_values(["session_date", "session_id", "exercise_attempt_number"])
        sessions = g.session_id.unique().tolist()
        if len(sessions) < 2:
            continue
        overlap = sum(1 for s in sessions if s in enriched_sessions)
        if overlap == 0 and len(sessions) < 4:
            continue

        # Classify pattern
        grades = []
        for sid, sg in g.groupby("session_id", sort=False):
            met = int(sg.required_level_met.fillna(0).max())
            regr = int(sg.exercise_regressed.fillna(0).max())
            ach = sg.achieved_grade_raw.dropna().astype(str).tolist()
            grades.append((sid, met, regr, ach, sg.session_date.iloc[0], float(sg.days_since_previous_session.iloc[0] or 0)))

        pattern = None
        # long gap
        if any(d >= 14 for *_, d in grades) and any(r == 1 or m == 0 for _, m, r, *_ in grades[1:]):
            pattern = "long_gap_degradation"
        # regression
        elif any(r == 1 for _, _, r, *_ in grades) and any(m == 1 for _, m, *_ in grades[:-1]):
            pattern = "apparent_regression"
        # plateau: many attempts still not met
        elif len(sessions) >= 4 and sum(1 for _, m, *_ in grades if m == 0) >= 3:
            pattern = "persistent_plateau"
        # stable
        elif sum(1 for _, m, *_ in grades if m == 1) >= 3 and all(r == 0 for _, _, r, *_ in grades[-3:]):
            pattern = "stable_competency"
        # high grade narrative warning
        else:
            for sid, *_ in grades:
                nb = narr_bits(sid)
                if nb is not None and bool(getattr(nb, "high_grade_def", False)):
                    pattern = "high_grade_narrative_warning"
                    break
            if pattern is None:
                for sid, *_ in grades:
                    nb = narr_bits(sid)
                    if nb is not None and nb.consistency_class == "VARIABLE" and nb.independence_mapped == "INDEPENDENT":
                        pattern = "independent_but_inconsistent"
                        break
            if pattern is None:
                for sid, *_ in grades:
                    nb = narr_bits(sid)
                    if nb is not None and nb.ctx_present and nb.has_deficiency:
                        pattern = "contextual_drop"
                        break
            if pattern is None and len(sessions) >= 2:
                # within-session: multiple attempt numbers improving
                for sid, sg in g.groupby("session_id"):
                    if sg.exercise_attempt_number.nunique() >= 2:
                        ordered = sg.sort_values("exercise_attempt_number")
                        mets = ordered.required_level_met.fillna(0).astype(int).tolist()
                        if mets[0] == 0 and mets[-1] == 1:
                            pattern = "within_session_improvement"
                            break
            if pattern is None:
                continue

        if counts[pattern] >= pattern_quota.get(pattern, 3):
            continue

        # Render timeline markdown from evidence only
        lines = [f"### {fam.upper()}", "", f"Student `{student_id}` · Program `{program_id}` · Pattern: `{pattern}`", ""]
        expected_prog = []
        for sid, met, regr, ach, sdate, gap in grades:
            sg = g[g.session_id == sid]
            reqs = sg.required_level_normalized.dropna().astype(str).unique().tolist()
            expected_prog.extend(reqs)
            lines.append(f"**Session {sdate}** (session_id={sid})")
            if gap and gap >= 14:
                lines.append(f"- Training gap before session: **{int(gap)} days**")
            lines.append(f"- Curriculum expected level(s): {', '.join(reqs) if reqs else 'UNKNOWN'}")
            lines.append(f"- Achieved grade(s): {', '.join(ach) if ach else 'UNKNOWN'}")
            lines.append(f"- Required met: {'yes' if met else 'no'}; regressed: {'yes' if regr else 'no'}")
            attempts = sg.sort_values("exercise_attempt_number")
            if len(attempts) > 1:
                lines.append("- Within-session attempts:")
                for a in attempts.itertuples():
                    lines.append(
                        f"  - Attempt {int(a.exercise_attempt_number or 1)}: grade={a.achieved_grade_raw}, "
                        f"required={a.required_level_normalized}, met={int(a.required_level_met or 0)}"
                    )
            nb = narr_bits(sid)
            if nb is not None:
                lines.append(f"- Narrative independence (mapped): {nb.independence_mapped} (assistance_level={nb.assistance_level})")
                lines.append(f"- Narrative consistency: {nb.consistency_class}")
                lines.append(f"- Narrative tone: {nb.overall_narrative_tone}; deficiency_evidence={bool(nb.has_deficiency)}")
                try:
                    tags = json.loads(nb.context_tags_json) if nb.context_tags_json else []
                except Exception:
                    tags = []
                if tags:
                    lines.append(f"- Context tags: {', '.join(tags)}")
                lines.append(f"- Evidence confidence: {'MEDIUM' if nb.extractor=='LLM_V1_REUSED' else 'LOW'} ({nb.extractor})")
            else:
                lines.append("- Narrative enrichment: NOT_OBSERVED for this session in high-value set")
            lines.append("")

        # Interpretation without inventing
        interp = []
        if pattern == "stable_competency":
            interp.append("Required level repeatedly met across sessions; no recent regression flags.")
        elif pattern == "apparent_regression":
            interp.append("Earlier required-level met, later exercise_regressed=1 — apparent regression in structured grades.")
        elif pattern == "long_gap_degradation":
            interp.append("Long gap (≥14d) co-occurs with later unmet/regressed performance in this family.")
        elif pattern == "persistent_plateau":
            interp.append("Multiple sessions without required-level met — persistent plateau in structured evidence.")
        elif pattern == "high_grade_narrative_warning":
            interp.append("Strong session grade coexisted with narrative deficiency signal — grades alone incomplete.")
        elif pattern == "independent_but_inconsistent":
            interp.append("Explicit independence language with VARIABLE consistency — do not equate independence with mastery.")
        elif pattern == "contextual_drop":
            interp.append("Deficiency evidence co-occurs with context tags — contextual performance drop candidate.")
        elif pattern == "within_session_improvement":
            interp.append("Within-session attempts move from unmet → met — learning trajectory must be preserved.")
        lines.append("**Interpretation (evidence-bounded):**")
        lines.append(interp[0] if interp else "Insufficient evidence for stronger claims.")
        lines.append("")
        lines.append(
            f"Expected progression observed in data: {' → '.join(expected_prog[:8]) if expected_prog else 'UNKNOWN'}"
        )

        md = "\n".join(lines)
        code = f"TL{len(examples)+1:02d}_{pattern}"
        examples.append(
            {
                "code": code,
                "title": f"{fam} — {pattern}",
                "student_id": int(student_id),
                "program_id": int(program_id) if pd.notna(program_id) else None,
                "family": fam,
                "pattern": pattern,
                "markdown": md,
            }
        )
        counts[pattern] += 1

        # Competency state snapshot from last session
        last_sid, last_met, last_regr, last_ach, last_date, _ = grades[-1]
        nb = narr_bits(last_sid)
        indep = nb.independence_mapped if nb is not None else "NOT_OBSERVED"
        cons = nb.consistency_class if nb is not None else "NOT_ENOUGH_EVIDENCE"
        conf = "MEDIUM" if nb is not None and nb.extractor == "LLM_V1_REUSED" else ("LOW" if nb is not None else "LOW")
        trend = "UNKNOWN"
        if pattern in ("within_session_improvement", "stable_competency"):
            trend = "IMPROVING" if pattern != "stable_competency" else "STABLE"
        elif pattern in ("apparent_regression", "long_gap_degradation"):
            trend = "REGRESSING"
        elif pattern == "persistent_plateau":
            trend = "PLATEAU"
        explanation = interp[0] if interp else ""
        con.execute(
            """INSERT INTO competency_state
            (student_id,program_id,exercise_family,source_exercise_id,as_of_date,expected_level,
             observed_independence,observed_quality,observed_consistency,context_summary,trend,confidence,
             evidence_ids_json,explanation,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
            (
                int(student_id),
                int(program_id) if pd.notna(program_id) else None,
                fam,
                int(g.source_exercise_id.dropna().iloc[-1]) if g.source_exercise_id.notna().any() else None,
                last_date,
                g.required_level_normalized.dropna().iloc[-1] if g.required_level_normalized.notna().any() else None,
                indep,
                "UNKNOWN",
                cons,
                "",
                trend,
                conf,
                json.dumps({"sessions": [int(s) for s in sessions]}),
                explanation,
                VERSION,
                NOW,
            ),
        )

    con.executemany(
        """INSERT INTO competency_timeline_example
        (example_code,title,student_id,program_id,exercise_family,pattern_type,timeline_markdown,
         evidence_notes,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?)""",
        [
            (
                e["code"],
                e["title"],
                e["student_id"],
                e["program_id"],
                e["family"],
                e["pattern"],
                e["markdown"],
                "Built only from fact_exercise_attempt + phase6 enrichment; missing dims left NOT_OBSERVED/UNKNOWN.",
                VERSION,
                NOW,
            )
            for e in examples
        ],
    )
    con.commit()
    log(f"Timelines built: {len(examples)} distribution={dict(counts)}")
    return examples


def main() -> None:
    extract_one = load_heuristic_extract()
    con = sqlite3.connect(DB)
    con.execute("PRAGMA busy_timeout=60000")

    pop = build_population(con)
    enrich_population(con, pop, extract_one)
    out = qa_and_scale(con)
    early_warnings(con, out["df"])
    # Ensure derived columns exist for timelines
    df = out["df"]
    populate_proto_entities(con, df)
    examples = build_timelines(con, df)

    con.execute("DELETE FROM phase6_meta")
    con.execute(
        """INSERT INTO phase6_meta (analysis_version,generated_at,notes) VALUES (?,?,?)""",
        (
            VERSION,
            NOW,
            json.dumps(
                {
                    "population": int(len(pop)),
                    "extractions": int(out["n"]),
                    "timelines": len(examples),
                    "openai": "unavailable_vault_encrypted",
                    "llm_reuse": "phase5-extract-v1-agent hashes",
                    "scaled_extractor": PHASE6_HEUR,
                }
            ),
        ),
    )
    con.commit()
    con.close()
    log("Phase 6 analysis pipeline complete.")


if __name__ == "__main__":
    main()
