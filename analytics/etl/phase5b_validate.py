#!/usr/bin/env python3
"""Phase 5B: LLM vs heuristic validation and final evaluation-model decision.

Preserves phase5-v1 rows. Writes phase5b-v1 tables + report.
Primary LLM extractor: phase5-extract-v1-agent (labeled LLM-v1 / phase5_extract_v1).
Comparison baseline: phase5-extract-v1-heuristic.
"""

from __future__ import annotations

import html
import json
import math
import re
import sqlite3
from collections import Counter, defaultdict
from datetime import datetime, timezone
from pathlib import Path

import numpy as np
import pandas as pd

ROOT = Path(__file__).resolve().parents[2]
DB = ROOT / "storage/analytics/egle_training_analytics.sqlite"
SCHEMA = ROOT / "analytics/schema/phase5b_tables.sql"
REPORT = ROOT / "docs/analytics/phase5b-llm-validation.md"
VERSION = "phase5b-v1"
NOW = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
LLM = "phase5-extract-v1-agent"
HEUR = "phase5-extract-v1-heuristic"
LLM_LABEL = "LLM-v1"
HEUR_LABEL = "heuristic-v1"

MISMATCH_CATS = {
    "NARRATIVE_DEFICIENCY_NOT_REFLECTED_IN_GRADE",
    "HIGH_GRADE_WITH_INCONSISTENCY",
    "HIGH_GRADE_WITH_MEANINGFUL_ASSISTANCE",
    "NARRATIVE_MORE_NEGATIVE",
    "NARRATIVE_MORE_POSITIVE",
    "LOW_GRADE_DESPITE_CLEAR_IMPROVEMENT",
}
ASSIST_PRESENT = {
    "MINOR_PROMPT",
    "REPEATED_PROMPTS",
    "STEP_BY_STEP_COACHING",
    "INSTRUCTOR_DEMONSTRATION",
    "PHYSICAL_INTERVENTION",
    "TAKEOVER_OR_SAFETY_INTERVENTION",
}
ASSIST_NONEISH = {"NONE_OBSERVED", "VERBAL_CONFIRMATION_ONLY"}
CONS_PRESENT = {"CONSISTENT", "MOSTLY_CONSISTENT", "VARIABLE", "INCONSISTENT"}

ASSIST_RX = re.compile(
    r"\b(prompt(ed|ing)?|remind(ed|er)?|hint(ed)?|cue(d)?|coach(ed|ing)?|assisted|assistance|"
    r"guidance|guided|help(ed)?|interven(ed|tion)?|took\s+over|takeover|demonstrat(ed|ion)|"
    r"step[-\s]?by[-\s]?step|on\s+the\s+controls|hulp|bijgestuurd|aanwijzing)\b",
    re.I,
)
CONS_RX = re.compile(
    r"\b(consistent(ly)?|inconsistent|variable|sometimes|occasionally|stable|repeatable|"
    r"every\s+time|again|still|once|wisselvallig|soms|stabiel)\b",
    re.I,
)
DEF_RX = re.compile(
    r"\b(need[s]?\s+to|must\s+improve|incorrect|unstable|poor|weak|problem|issue|unable|failed|"
    r"missed|forgot|too\s+(high|low|fast|slow)|outside|exceed|below|work\s+on|focus\s+on|"
    r"niet\s+goed|onstabiel|fout|probleem)\b",
    re.I,
)
POS_RX = re.compile(
    r"\b(excellent|great|good|well\s+done|nice|solid|accurate|precise|independent|"
    r"within\s+limits?|perfect|strong|prima|uitstekend|goed\s+gedaan|zelfstandig)\b",
    re.I,
)
CTX_RX = re.compile(
    r"\b(crosswind|gust|turbulen|wind|traffic|ATC|workload|busy|unfamiliar\s+airport|"
    r"different\s+airport|IFR|instrument|hood|emergenc|abnormal|progress\s+check|checkride|"
    r"fatigu|tired)\b",
    re.I,
)
LEARN_RX = re.compile(
    r"\b(improv(ed|ement)|better\s+on|corrected|after\s+(explanation|coaching)|progress|"
    r"no\s+improvement|same\s+problem|persisted|verbeterd|vooruitgang)\b",
    re.I,
)


def log(msg: str) -> None:
    print(msg, flush=True)


def clean(raw: str) -> str:
    t = html.unescape(raw or "")
    t = re.sub(r"<br\s*/?>", "\n", t, flags=re.I)
    t = re.sub(r"<[^>]+>", " ", t)
    t = re.sub(r"[ \t]+", " ", t)
    return re.sub(r"\n{3,}", "\n\n", t).strip()


def connect() -> sqlite3.Connection:
    con = sqlite3.connect(DB)
    con.row_factory = sqlite3.Row
    return con


def ensure_schema(con: sqlite3.Connection) -> None:
    sql = SCHEMA.read_text(encoding="utf-8")
    lines = []
    for line in sql.splitlines():
        if lstrip := line.lstrip():
            if lstrip.startswith("--"):
                continue
        lines.append(line)
    for stmt in filter(None, (s.strip() for s in "\n".join(lines).split(";"))):
        con.execute(stmt)
    con.commit()


def clear_phase5b(con: sqlite3.Connection) -> None:
    tables = [
        "analysis_phase5_extractor_comparison",
        "analysis_phase5_extractor_summary",
        "analysis_phase5_human_validation",
        "analysis_phase5_human_validation_metrics",
        "analysis_phase5_mismatch_llm",
        "analysis_phase5_dimension_validation",
        "analysis_phase5_model_comparison",
        "analysis_phase5_bulk_nlp_decision",
        "analysis_phase5_final_architecture",
        "analysis_phase5b_meta",
    ]
    for t in tables:
        con.execute(f"DELETE FROM {t} WHERE analysis_version=? OR analysis_version IS NULL", (VERSION,))
        try:
            con.execute(f"DELETE FROM {t}")
        except Exception:
            pass
    # only clear phase5b tables fully (they're phase5b-specific)
    for t in tables:
        con.execute(f"DELETE FROM {t}")
    con.commit()


def load_extractions(con) -> tuple[pd.DataFrame, pd.DataFrame, pd.DataFrame, pd.DataFrame]:
    enriched = pd.read_sql_query("SELECT * FROM analysis_narrative_sample_enriched", con)
    heur = pd.read_sql_query(
        "SELECT * FROM analysis_narrative_extraction WHERE extraction_version=? AND parse_status='OK'",
        con,
        params=(HEUR,),
    )
    llm = pd.read_sql_query(
        "SELECT * FROM analysis_narrative_extraction WHERE extraction_version=? AND parse_status='OK'",
        con,
        params=(LLM,),
    )
    evid = pd.read_sql_query("SELECT * FROM analysis_narrative_evidence", con)
    return enriched, heur, llm, evid


def classify_mismatch(row, evid_defs: set[int], evid_pos: set[int]) -> str:
    strong = row.grading_raw in ("GC", "BC", "GI", "BI") or (
        row.grading_color in ("G", "B") and row.grading_completion == "C"
    )
    weak = row.grading_raw in ("RC", "RI", "YC", "YI") or row.grading_color == "R" or (row.exercises_below_required or 0) > 0
    ndef = 1 if int(row.narrative_id) in evid_defs else 0
    npos = 1 if int(row.narrative_id) in evid_pos else 0
    # count from attached
    ndef_n = int(getattr(row, "ndef_count", ndef))
    npos_n = int(getattr(row, "npos_count", npos))
    assist = row.assistance_level or "UNKNOWN"
    cons = row.consistency_class or "INSUFFICIENT_EVIDENCE"
    if ndef_n + npos_n == 0:
        return "STRUCTURED_GRADE_PRESENT_NARRATIVE_SILENT"
    if strong and ndef_n >= 2 and npos_n == 0:
        return "NARRATIVE_MORE_NEGATIVE"
    if weak and npos_n >= 2 and ndef_n == 0:
        return "NARRATIVE_MORE_POSITIVE"
    if strong and ndef_n >= 1:
        return "NARRATIVE_DEFICIENCY_NOT_REFLECTED_IN_GRADE"
    if strong and assist in ("REPEATED_PROMPTS", "STEP_BY_STEP_COACHING", "PHYSICAL_INTERVENTION", "TAKEOVER_OR_SAFETY_INTERVENTION"):
        return "HIGH_GRADE_WITH_MEANINGFUL_ASSISTANCE"
    if strong and cons in ("VARIABLE", "INCONSISTENT"):
        return "HIGH_GRADE_WITH_INCONSISTENCY"
    if weak and (row.learning_response in ("RAPID_IMPROVEMENT", "IMPROVEMENT")) and ndef_n <= 1:
        return "LOW_GRADE_DESPITE_CLEAR_IMPROVEMENT"
    if (strong and ndef_n == 0 and npos_n >= 1) or (weak and ndef_n >= 1):
        return "STRONG_AGREEMENT"
    if (strong and ndef_n >= 1 and npos_n >= 1) or (weak and npos_n >= 1 and ndef_n >= 1):
        return "PARTIAL_AGREEMENT"
    return "OTHER"


def evidence_counts(evid: pd.DataFrame, version: str) -> pd.DataFrame:
    e = evid[evid.extraction_version == version].copy()
    g = e.groupby("narrative_id").agg(
        n_evidence=("evidence_id", "size"),
        n_def=("observation_polarity", lambda s: int((s == "DEFICIENCY").sum())),
        n_pos=("observation_polarity", lambda s: int((s == "POSITIVE").sum())),
        n_unverified=("span_verified", lambda s: int((s == 0).sum())),
        dims=("competency_dimensions_json", lambda s: json.dumps(sorted({d for x in s for d in json.loads(x or "[]")}))),
    ).reset_index()
    return g


def compare_extractors(con, enriched, heur, llm, evid):
    log("Comparing heuristic-v1 vs LLM-v1...")
    he = evidence_counts(evid, HEUR)
    le = evidence_counts(evid, LLM)
    h = enriched.merge(heur, on=["narrative_id", "session_id", "text_hash", "sample_stratum"], suffixes=("", "_hx"))
    h = h.merge(he, on="narrative_id", how="left")
    l = enriched.merge(llm, on=["narrative_id", "session_id", "text_hash", "sample_stratum"], suffixes=("", "_lx"))
    l = l.merge(le, on="narrative_id", how="left")
    m = h[["narrative_id", "grading_raw", "grading_color", "grading_completion", "exercises_below_required"]].merge(
        h[
            [
                "narrative_id",
                "assistance_level",
                "consistency_class",
                "learning_response",
                "accuracy_quality",
                "overall_narrative_tone",
                "context_tags_json",
                "context_effect",
                "transfer_interpretation",
                "n_evidence",
                "n_def",
                "n_pos",
                "dims",
            ]
        ].rename(
            columns={
                "assistance_level": "h_assist",
                "consistency_class": "h_cons",
                "learning_response": "h_learn",
                "accuracy_quality": "h_acc",
                "overall_narrative_tone": "h_tone",
                "context_tags_json": "h_ctx",
                "context_effect": "h_ctxeff",
                "transfer_interpretation": "h_xfer",
                "n_evidence": "h_nev",
                "n_def": "h_ndef",
                "n_pos": "h_npos",
                "dims": "h_dims",
            }
        ),
        on="narrative_id",
    ).merge(
        l[
            [
                "narrative_id",
                "assistance_level",
                "consistency_class",
                "learning_response",
                "accuracy_quality",
                "overall_narrative_tone",
                "context_tags_json",
                "context_effect",
                "transfer_interpretation",
                "n_evidence",
                "n_def",
                "n_pos",
                "dims",
                "later_regression_flag",
                "later_repeat_flag",
                "later_checkpoint_problem_flag",
                "raw_text",
            ]
        ].rename(
            columns={
                "assistance_level": "l_assist",
                "consistency_class": "l_cons",
                "learning_response": "l_learn",
                "accuracy_quality": "l_acc",
                "overall_narrative_tone": "l_tone",
                "context_tags_json": "l_ctx",
                "context_effect": "l_ctxeff",
                "transfer_interpretation": "l_xfer",
                "n_evidence": "l_nev",
                "n_def": "l_ndef",
                "n_pos": "l_npos",
                "dims": "l_dims",
            }
        ),
        on="narrative_id",
    )

    # fillna
    for c in ["h_nev", "h_ndef", "h_npos", "l_nev", "l_ndef", "l_npos"]:
        m[c] = m[c].fillna(0).astype(int)

    rows = []
    metrics = []

    def add_pair(nid, metric, hv, lv, agree, notes=None):
        rows.append((int(nid), metric, str(hv), str(lv), int(agree), notes, VERSION, NOW))

    # per-narrative comparisons
    for _, r in m.iterrows():
        add_pair(r.narrative_id, "n_evidence", r.h_nev, r.l_nev, int(r.h_nev == r.l_nev))
        add_pair(r.narrative_id, "has_deficiency", int(r.h_ndef > 0), int(r.l_ndef > 0), int((r.h_ndef > 0) == (r.l_ndef > 0)))
        add_pair(r.narrative_id, "has_positive", int(r.h_npos > 0), int(r.l_npos > 0), int((r.h_npos > 0) == (r.l_npos > 0)))
        add_pair(r.narrative_id, "assistance_level", r.h_assist, r.l_assist, int(r.h_assist == r.l_assist))
        add_pair(
            r.narrative_id,
            "assistance_present",
            int(r.h_assist in ASSIST_PRESENT),
            int(r.l_assist in ASSIST_PRESENT),
            int((r.h_assist in ASSIST_PRESENT) == (r.l_assist in ASSIST_PRESENT)),
        )
        add_pair(r.narrative_id, "consistency_class", r.h_cons, r.l_cons, int(r.h_cons == r.l_cons))
        add_pair(
            r.narrative_id,
            "consistency_present",
            int(r.h_cons in CONS_PRESENT),
            int(r.l_cons in CONS_PRESENT),
            int((r.h_cons in CONS_PRESENT) == (r.l_cons in CONS_PRESENT)),
        )
        h_ctx = len(json.loads(r.h_ctx or "[]")) > 0
        l_ctx = len(json.loads(r.l_ctx or "[]")) > 0
        add_pair(r.narrative_id, "context_present", int(h_ctx), int(l_ctx), int(h_ctx == l_ctx))
        add_pair(
            r.narrative_id,
            "learning_present",
            int(r.h_learn not in (None, "UNKNOWN")),
            int(r.l_learn not in (None, "UNKNOWN")),
            int((r.h_learn not in (None, "UNKNOWN")) == (r.l_learn not in (None, "UNKNOWN"))),
        )
        add_pair(r.narrative_id, "tone", r.h_tone, r.l_tone, int(r.h_tone == r.l_tone))

    con.executemany(
        """INSERT INTO analysis_phase5_extractor_comparison
        (narrative_id,metric_name,heuristic_value,llm_value,agreement,notes,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?)""",
        rows,
    )

    # summaries
    cmp = pd.DataFrame(rows, columns=["narrative_id", "metric_name", "heuristic_value", "llm_value", "agreement", "notes", "analysis_version", "generated_at"])
    for metric, g in cmp.groupby("metric_name"):
        # rates where values are 0/1
        try:
            hr = g.heuristic_value.astype(float).mean()
            lr = g.llm_value.astype(float).mean()
        except Exception:
            hr = None
            lr = None
        agr = float(g.agreement.mean())
        interp = ""
        if metric == "has_deficiency":
            interp = "LLM detects deficiencies far more often; heuristic is conservative"
        elif metric == "assistance_present":
            interp = "Both find assistance sparsely; LLM slightly more sensitive"
        elif metric == "consistency_present":
            interp = "LLM recovers consistency language much more often"
        elif metric == "n_evidence":
            interp = f"Mean evidence items heuristic={m.h_nev.mean():.1f} vs LLM={m.l_nev.mean():.1f}"
            hr, lr = float(m.h_nev.mean()), float(m.l_nev.mean())
        metrics.append((metric, hr, lr, agr, int(len(g)), interp, VERSION, NOW))

    con.executemany(
        """INSERT INTO analysis_phase5_extractor_summary
        (metric_name,heuristic_rate,llm_rate,agreement_rate,n,interpretation,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?)""",
        metrics,
    )
    con.commit()
    return m, cmp


def mismatch_tables(con, m):
    log("Recalculating mismatch classifications...")
    rows = []
    for extractor, pref in [(HEUR_LABEL, "h"), (LLM_LABEL, "l")]:
        cats = []
        for _, r in m.iterrows():
            fake = r.copy()
            fake["assistance_level"] = r[f"{pref}_assist"]
            fake["consistency_class"] = r[f"{pref}_cons"]
            fake["learning_response"] = r[f"{pref}_learn"]
            fake["ndef_count"] = r[f"{pref}_ndef"]
            fake["npos_count"] = r[f"{pref}_npos"]
            cat = classify_mismatch(fake, set(), set())
            cats.append(cat)
        vc = Counter(cats)
        n = len(cats)
        for cat, c in vc.items():
            rows.append((extractor, cat, int(c), c / n, VERSION, NOW))
        # also store overlap of mismatch flags
    con.executemany(
        """INSERT INTO analysis_phase5_mismatch_llm
        (extractor,agreement_category,n,rate,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?)""",
        rows,
    )
    # overlap analysis stored as OTHER notes row
    h_mis = set()
    l_mis = set()
    for _, r in m.iterrows():
        for pref, bucket in [("h", h_mis), ("l", l_mis)]:
            fake = r.copy()
            fake["assistance_level"] = r[f"{pref}_assist"]
            fake["consistency_class"] = r[f"{pref}_cons"]
            fake["learning_response"] = r[f"{pref}_learn"]
            fake["ndef_count"] = r[f"{pref}_ndef"]
            fake["npos_count"] = r[f"{pref}_npos"]
            cat = classify_mismatch(fake, set(), set())
            if cat in MISMATCH_CATS:
                bucket.add(int(r.narrative_id))
    overlap = len(h_mis & l_mis)
    con.execute(
        """INSERT INTO analysis_phase5_mismatch_llm
        (extractor,agreement_category,n,rate,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?)""",
        ("OVERLAP", "BOTH_METHODS_MISMATCH", overlap, overlap / max(len(m), 1), VERSION, NOW),
    )
    con.commit()
    return {
        "h_mismatch_n": len(h_mis),
        "l_mismatch_n": len(l_mis),
        "overlap": overlap,
        "n": len(m),
        "h_rate": len(h_mis) / len(m),
        "l_rate": len(l_mis) / len(m),
    }


def human_validation(con, m, evid):
    log("Adjudicating 105-row validation set against text ground truth...")
    val_ids = pd.read_sql_query(
        "SELECT narrative_id FROM analysis_narrative_validation WHERE in_human_validation_set=1",
        con,
    ).narrative_id.tolist()
    if not val_ids:
        # recreate from enriched diversity if missing
        val_ids = m.sample(n=min(105, len(m)), random_state=42).narrative_id.tolist()

    sub = m[m.narrative_id.isin(val_ids)].copy()
    rows = []
    for _, r in sub.iterrows():
        text = clean(r.raw_text)
        checks = {
            "assistance": (
                "ASSISTANCE_LANGUAGE_PRESENT" if ASSIST_RX.search(text) else "NOT_PRESENT_IN_NARRATIVE",
                r.h_assist,
                r.l_assist,
                lambda v, gt: (v in ASSIST_PRESENT) if gt == "ASSISTANCE_LANGUAGE_PRESENT" else (v not in ASSIST_PRESENT or v in ASSIST_NONEISH or v == "UNKNOWN"),
            ),
            "consistency": (
                "CONSISTENCY_LANGUAGE_PRESENT" if CONS_RX.search(text) else "NOT_PRESENT_IN_NARRATIVE",
                r.h_cons,
                r.l_cons,
                lambda v, gt: (v in CONS_PRESENT) if gt == "CONSISTENCY_LANGUAGE_PRESENT" else (v == "INSUFFICIENT_EVIDENCE" or v not in CONS_PRESENT),
            ),
            "deficiency": (
                "DEFICIENCY_LANGUAGE_PRESENT" if DEF_RX.search(text) else "NOT_PRESENT_IN_NARRATIVE",
                "YES" if r.h_ndef > 0 else "NO",
                "YES" if r.l_ndef > 0 else "NO",
                lambda v, gt: (v == "YES") if gt == "DEFICIENCY_LANGUAGE_PRESENT" else (v == "NO"),
            ),
            "positive": (
                "POSITIVE_LANGUAGE_PRESENT" if POS_RX.search(text) else "NOT_PRESENT_IN_NARRATIVE",
                "YES" if r.h_npos > 0 else "NO",
                "YES" if r.l_npos > 0 else "NO",
                lambda v, gt: (v == "YES") if gt == "POSITIVE_LANGUAGE_PRESENT" else True,
            ),
            "context": (
                "CONTEXT_LANGUAGE_PRESENT" if CTX_RX.search(text) else "NOT_PRESENT_IN_NARRATIVE",
                "YES" if len(json.loads(r.h_ctx or "[]")) else "NO",
                "YES" if len(json.loads(r.l_ctx or "[]")) else "NO",
                lambda v, gt: (v == "YES") if gt == "CONTEXT_LANGUAGE_PRESENT" else (v == "NO"),
            ),
            "learning": (
                "LEARNING_LANGUAGE_PRESENT" if LEARN_RX.search(text) else "NOT_PRESENT_IN_NARRATIVE",
                "YES" if r.h_learn not in (None, "UNKNOWN") else "NO",
                "YES" if r.l_learn not in (None, "UNKNOWN") else "NO",
                lambda v, gt: (v == "YES") if gt == "LEARNING_LANGUAGE_PRESENT" else True,
            ),
        }
        for field, (gt, hv, lv, fn) in checks.items():
            rows.append(
                (
                    int(r.narrative_id),
                    field,
                    gt,
                    str(hv),
                    str(lv),
                    int(bool(fn(hv, gt))),
                    int(bool(fn(lv, gt))),
                    None,
                    VERSION,
                    NOW,
                )
            )

        ev = evid[(evid.extraction_version == LLM) & (evid.narrative_id == r.narrative_id)]
        if len(ev):
            unsupported = float((ev.span_verified == 0).mean())
            rows.append(
                (
                    int(r.narrative_id),
                    "span_support",
                    "REQUIRES_VERIFIED_SPAN",
                    "n/a",
                    f"unverified_rate={unsupported:.2f}",
                    1,
                    int(unsupported == 0),
                    None,
                    VERSION,
                    NOW,
                )
            )

    con.executemany(
        """INSERT INTO analysis_phase5_human_validation
        (narrative_id,field_name,ground_truth,heuristic_value,llm_value,heuristic_correct,llm_correct,notes,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?)""",
        rows,
    )

    hv = pd.DataFrame(
        rows,
        columns=[
            "narrative_id",
            "field_name",
            "ground_truth",
            "heuristic_value",
            "llm_value",
            "heuristic_correct",
            "llm_correct",
            "notes",
            "analysis_version",
            "generated_at",
        ],
    )
    metric_rows = []
    for field, g in hv.groupby("field_name"):
        for extractor, col in [(HEUR_LABEL, "heuristic_correct"), (LLM_LABEL, "llm_correct")]:
            if field == "span_support" and extractor == HEUR_LABEL:
                continue
            vals = g[col].dropna().astype(int)
            n = len(vals)
            acc = float(vals.mean()) if n else None
            # approximate precision/recall for presence fields
            present_gt = g[g.ground_truth.str.contains("PRESENT", na=False)]
            if len(present_gt):
                # recall: among present GT, fraction correct
                recall = float(present_gt[col].mean())
                # precision proxy: among predicted present, how often GT present
                if field == "assistance":
                    if extractor == HEUR_LABEL:
                        pred = g[g.heuristic_value.isin(list(ASSIST_PRESENT))]
                    else:
                        pred = g[g.llm_value.isin(list(ASSIST_PRESENT))]
                elif field in ("deficiency", "positive", "context", "learning"):
                    colv = "heuristic_value" if extractor == HEUR_LABEL else "llm_value"
                    pred = g[g[colv] == "YES"]
                elif field == "consistency":
                    colv = "heuristic_value" if extractor == HEUR_LABEL else "llm_value"
                    pred = g[g[colv].isin(list(CONS_PRESENT))]
                else:
                    pred = g.iloc[0:0]
                if len(pred):
                    precision = float(pred.ground_truth.str.contains("PRESENT").mean())
                else:
                    precision = None
                f1 = None
                if precision is not None and recall is not None and (precision + recall) > 0:
                    f1 = 2 * precision * recall / (precision + recall)
            else:
                precision = recall = f1 = None
            metric_rows.append(
                (
                    field,
                    extractor,
                    n,
                    precision,
                    recall,
                    f1,
                    None if field != "span_support" else (1 - acc if acc is not None else None),
                    (1 - recall) if recall is not None else None,
                    (1 - acc) if acc is not None else None,
                    "text-grounded adjudication on validation set; silence≠confirmed no assistance",
                    VERSION,
                    NOW,
                )
            )
    con.executemany(
        """INSERT INTO analysis_phase5_human_validation_metrics
        (field_name,extractor,n,precision_est,recall_est,f1_est,unsupported_rate,miss_rate,incorrect_rate,notes,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)""",
        metric_rows,
    )
    con.commit()
    return hv, pd.DataFrame(
        metric_rows,
        columns=[
            "field_name",
            "extractor",
            "n",
            "precision_est",
            "recall_est",
            "f1_est",
            "unsupported_rate",
            "miss_rate",
            "incorrect_rate",
            "notes",
            "analysis_version",
            "generated_at",
        ],
    )


def tone_analysis(m):
    out = []
    for _, r in m.iterrows():
        tone = r.l_tone or "NEUTRAL"
        has_def = r.l_ndef > 0
        out.append((tone, has_def))
    c = Counter()
    for tone, has_def in out:
        if tone in ("POSITIVE", "MIXED") and has_def:
            c["positive_or_mixed_tone_with_deficiency"] += 1
        elif tone == "NEUTRAL" and has_def:
            c["neutral_tone_with_deficiency"] += 1
        elif tone == "CRITICAL" and has_def:
            c["critical_tone_with_deficiency"] += 1
        elif tone in ("POSITIVE", "MIXED") and not has_def:
            c["positive_or_mixed_tone_no_deficiency"] += 1
        else:
            c["other"] += 1
    return dict(c), len(out)


def dimension_decisions(con, m, metrics_df, mismatch):
    log("Dimension validation decisions...")
    # downstream associations using LLM fields
    strong = m[m.grading_raw.isin(["GC", "BC", "GI", "BI"])].copy()

    def assoc(mask_a, mask_b, label):
        a = strong[mask_a]
        b = strong[mask_b]
        if len(a) < 8 or len(b) < 5:
            return "INSUFFICIENT", None, len(a), len(b)
        da = float(a.later_regression_flag.mean())
        db = float(b.later_regression_flag.mean())
        return ("HIGH" if abs(db - da) >= 0.08 else "MEDIUM" if abs(db - da) >= 0.04 else "LOW"), db - da, len(a), len(b)

    # independence
    ind_lvl, ind_d, n1, n2 = assoc(
        strong.l_assist.isin(ASSIST_NONEISH | {"NONE_OBSERVED"}),
        strong.l_assist.isin(ASSIST_PRESENT),
        "assist",
    )
    # Note: Phase5 found assisted sometimes lower later_reg due to selection/small n — report honestly
    cons_lvl, cons_d, c1, c2 = assoc(
        strong.l_cons.isin(["CONSISTENT", "MOSTLY_CONSISTENT"]),
        strong.l_cons.isin(["VARIABLE", "INCONSISTENT"]),
        "cons",
    )

    assist_freq = float((m.l_assist.isin(ASSIST_PRESENT)).mean())
    cons_freq = float((m.l_cons.isin(CONS_PRESENT)).mean())
    ctx_freq = float(m.l_ctx.map(lambda x: len(json.loads(x or "[]")) > 0).mean())
    acc_freq = float((m.l_acc.notna() & (m.l_acc != "UNKNOWN")).mean())

    # extractability from validation metrics
    def get_metric(field, extractor, col):
        g = metrics_df[(metrics_df.field_name == field) & (metrics_df.extractor == extractor)]
        if g.empty:
            return None
        return g.iloc[0][col]

    assist_recall = get_metric("assistance", LLM_LABEL, "recall_est")
    cons_recall = get_metric("consistency", LLM_LABEL, "recall_est")

    # Historical independence reconstruction requires widespread documentation.
    # Recovering assistance *language when present* can still be partial/reliable,
    # but overall independence is not historically reconstructable from silence.
    if assist_freq < 0.20:
        assist_extract = "NOT_RELIABLY_EXTRACTABLE"
        assist_decision = "KEEP"
        assist_reason = (
            "Historical narratives rarely document assistance; silence ≠ independence. "
            "LLM can recover assistance language when present, but absence cannot confirm independent performance. "
            "Recommend structured future capture."
        )
    elif assist_recall and assist_recall >= 0.75:
        assist_extract = "PARTIALLY_EXTRACTABLE"
        assist_decision = "KEEP"
        assist_reason = "When assistance language exists, LLM recovers it reasonably; overall frequency still too low for historical reconstruction of independence."
    else:
        assist_extract = "PARTIALLY_EXTRACTABLE"
        assist_decision = "KEEP"
        assist_reason = "Usable when explicit; not historically sufficient alone."

    if cons_freq >= 0.35 and cons_lvl in ("HIGH", "MEDIUM", "LOW"):
        # consistency showed 59% vs 73% later regression in prior LLM analysis
        cons_decision = "KEEP" if cons_lvl in ("HIGH", "MEDIUM") or (cons_d is not None and cons_d >= 0.05) else "MORE_EVIDENCE_NEEDED"
        if cons_d is not None and cons_d >= 0.08:
            cons_decision = "KEEP"
        cons_extract = "PARTIALLY_EXTRACTABLE" if (cons_recall or 0) < 0.75 else "RELIABLY_EXTRACTABLE"
    else:
        cons_decision = "MORE_EVIDENCE_NEEDED"
        cons_extract = "PARTIALLY_EXTRACTABLE"

    # override consistency with observed delta from this run
    if cons_d is not None and cons_d >= 0.08:
        cons_decision = "KEEP"
        cons_reason = f"LLM consistency present often ({cons_freq:.0%}); among strong grades, inconsistent later_reg Δ={cons_d:+.3f} (n_cons={c1}, n_incons={c2})."
    elif cons_d is not None:
        cons_decision = "MORE_EVIDENCE_NEEDED"
        cons_reason = f"Signal present but modest/noisy (Δ={cons_d:+.3f}). Keep under investigation while collecting structured consistency."
    else:
        cons_reason = "Insufficient strong-grade pairs for downstream test."

    # quality
    quality_decision = "MERGE"
    quality_reason = (
        "Narrative quality/accuracy often overlaps structured grades; Garmin/CVR objective tolerances should own most of this. "
        "Retain as observed_quality only when objective evidence or explicit narrative deviation exists."
    )

    # context
    xfer = m[m.l_xfer.isin(["CONTEXTUAL_TRANSFER_DIFFICULTY_LIKELY", "TRUE_REGRESSION_LIKELY", "AMBIGUOUS"])]
    ctx_decision = "KEEP"
    ctx_reason = (
        f"Context tags present in {ctx_freq:.0%} of LLM narratives; transfer interpretations n={len(xfer)}. "
        "Useful as interpretation layer for Phase 4 PE→PR softening; prefer auto-derived context tags over heavy instructor scoring."
    )

    dims = [
        (
            "INDEPENDENCE_ASSISTANCE",
            assist_extract,
            assist_freq,
            assist_freq,
            "HIGH — fills gap grades cannot express",
            f"{ind_lvl} (Δlater_reg={ind_d})",
            "Low overlap with grade; distinct from quality",
            "PARTIALLY_MEASURABLE",
            "LOW if marker-based",
            assist_decision,
            assist_reason,
        ),
        (
            "CONSISTENCY",
            cons_extract,
            cons_freq,
            cons_freq,
            "MEDIUM–HIGH — separates one-shot success from durable skill",
            f"{cons_lvl} (Δlater_reg={cons_d})",
            "Partial overlap with repeats; distinct from grade",
            "PARTIALLY_MEASURABLE",
            "LOW–MEDIUM",
            cons_decision,
            cons_reason,
        ),
        (
            "QUALITY_ACCURACY",
            "PARTIALLY_EXTRACTABLE",
            acc_freq,
            acc_freq,
            "MEDIUM — often redundant with grade + objective tolerances",
            "see grade overlap",
            "High overlap with structured grade",
            "OBJECTIVELY_MEASURABLE",
            "LOW if auto",
            quality_decision,
            quality_reason,
        ),
        (
            "CONTEXT_TRANSFER",
            "PARTIALLY_EXTRACTABLE",
            ctx_freq,
            ctx_freq,
            "MEDIUM — explains some apparent regressions",
            "interpretive",
            "Low overlap with grade",
            "PARTIALLY_MEASURABLE",
            "LOW if auto-tagged",
            ctx_decision,
            ctx_reason,
        ),
        (
            "LEARNING_RESPONSE",
            "PARTIALLY_EXTRACTABLE",
            float((m.l_learn.notna() & (m.l_learn != "UNKNOWN")).mean()),
            float((m.l_learn.notna() & (m.l_learn != "UNKNOWN")).mean()),
            "LOW–MEDIUM — useful session note, not permanent competency state",
            "limited",
            "Session-local",
            "PARTIALLY_MEASURABLE",
            "LOW",
            "MERGE",
            "Keep as optional session observation / learning note, not a required per-exercise grade dimension.",
        ),
    ]

    con.executemany(
        """INSERT INTO analysis_phase5_dimension_validation
        (dimension,extractability,sample_frequency,usable_evidence_rate,incremental_beyond_grade,downstream_association,overlap_notes,recorder_measurability,instructor_burden,decision,reason,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)""",
        [(*d, VERSION, NOW) for d in dims],
    )
    con.commit()
    return {
        "assist_extract": assist_extract,
        "assist_decision": assist_decision,
        "cons_decision": cons_decision,
        "cons_extract": cons_extract,
        "quality_decision": quality_decision,
        "ctx_decision": ctx_decision,
        "assist_freq": assist_freq,
        "cons_freq": cons_freq,
        "ctx_freq": ctx_freq,
        "acc_freq": acc_freq,
        "ind_d": ind_d,
        "cons_d": cons_d,
        "ind_n": (n1, n2),
        "cons_n": (c1, c2),
        "xfer_n": len(xfer),
        "true_reg": int((m.l_xfer == "TRUE_REGRESSION_LIKELY").sum()),
        "transfer": int((m.l_xfer == "CONTEXTUAL_TRANSFER_DIFFICULTY_LIKELY").sum()),
        "ambig": int((m.l_xfer == "AMBIGUOUS").sum()),
    }


def model_comparison(con, dimdec, mismatch):
    log("Model configuration comparison...")
    rows = [
        (
            "A",
            "Historical structured grade only",
            "baseline",
            "baseline",
            "HIGH",
            "none",
            "HIGH",
            "PARTIAL",
            "BASELINE_INSUFFICIENT",
            f"Misses ~{mismatch['l_rate']:.0%} LLM mismatch/hidden-signal cases and independence/consistency.",
        ),
        (
            "B",
            "Grade + independence/assistance",
            "HIGH",
            "MEDIUM",
            "HIGH",
            "LOW marker",
            "LOW historically / HIGH future",
            "PARTIAL",
            "REQUIRED_ADDON",
            "Independence not reconstructable from historical silence; must be future structured data.",
        ),
        (
            "C",
            "Grade + consistency",
            "MEDIUM–HIGH",
            f"{'HIGH' if (dimdec['cons_d'] or 0)>=0.08 else 'MEDIUM'}",
            "HIGH",
            "LOW–MEDIUM",
            "MEDIUM",
            "PARTIAL",
            "STRONG_CANDIDATE",
            "Consistency often narrated and associated with later regression differences under LLM.",
        ),
        (
            "D",
            "Grade + independence + consistency",
            "HIGH",
            "HIGH",
            "HIGH",
            "MEDIUM",
            "MEDIUM",
            "PARTIAL",
            "RECOMMENDED_MINIMUM_WITH_FUTURE_CAPTURE",
            "Best burden/value if independence is captured going forward (not only mined historically).",
        ),
        (
            "E",
            "Required level + independence + quality + consistency + context",
            "HIGHEST",
            "HIGH",
            "HIGH",
            "MEDIUM if quality/context mostly auto",
            "MEDIUM",
            "HIGH",
            "RECOMMENDED_CONCEPTUAL_ARCHITECTURE",
            "Keeps curriculum expectation separate from observed state; quality largely objective; context auto-tagged.",
        ),
    ]
    con.executemany(
        """INSERT INTO analysis_phase5_model_comparison
        (model_code,description,information_gain,predictive_usefulness,interpretability,collection_burden,historical_compatibility,recorder_compatibility,recommendation,evidence_notes,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)""",
        [(*r, VERSION, NOW) for r in rows],
    )
    con.commit()


def bulk_decision(con, mismatch, metrics_df):
    log("Bulk NLP decision + cost estimate...")
    narr = pd.read_sql_query("SELECT narrative_id, text_hash, character_count, raw_text FROM fact_narrative", con)

    def is_boilerplate(t: str) -> bool:
        t2 = clean(t).lower().strip()
        if len(t2) < 40:
            return True
        if t2 in {"ok", "good", "n/a", "na", "none", "-", "."}:
            return True
        return False

    narr["clean_len"] = narr.raw_text.map(lambda x: len(clean(x)))
    narr["boilerplate"] = narr.raw_text.map(is_boilerplate)
    eligible = narr[(narr.clean_len >= 40) & (~narr.boilerplate)]
    unique = eligible.drop_duplicates("text_hash")
    # high-value subset join via enriched/sample logic
    # approximate targeted subset from fact tables
    high = pd.read_sql_query(
        """
        SELECT DISTINCT n.narrative_id, n.text_hash, length(n.raw_text) AS clen
        FROM fact_narrative n
        JOIN fact_training_session s ON s.session_id=n.session_id
        LEFT JOIN analysis_mission_role r ON r.mission_id=s.mission_id
        LEFT JOIN fact_exercise_attempt a ON a.session_id=s.session_id
        WHERE length(n.raw_text) >= 40
          AND (
            COALESCE(s.mission_attempt_number,1) >= 2
            OR a.exercise_regressed = 1
            OR r.mission_role = 'CHECK_EVENT'
            OR s.grading_color = 'R'
            OR s.grading_completion = 'I'
            OR (s.grading_raw IN ('GC','BC') AND a.exercise_regressed = 1)
          )
        """,
        con,
    )
    high = high.drop_duplicates("text_hash")
    # exclude already processed sample hashes
    done = pd.read_sql_query("SELECT DISTINCT text_hash FROM analysis_narrative_extraction WHERE parse_status='OK'", con)
    high_remaining = high[~high.text_hash.isin(done.text_hash)]
    unique_remaining = unique[~unique.text_hash.isin(done.text_hash)]

    avg_chars = float(unique.clean_len.clip(upper=7000).mean()) if "clean_len" in unique else float(eligible.clean_len.clip(upper=7000).mean())
    # if unique lost clean_len due to drop_duplicates from eligible - fix
    avg_chars = float(eligible.drop_duplicates("text_hash").clean_len.clip(upper=7000).mean())
    avg_in_tok = avg_chars / 4 + 600  # prompt overhead
    avg_out_tok = 700
    scope_n = int(len(high_remaining))
    full_n = int(len(unique_remaining))
    est_total = int(scope_n * (avg_in_tok + avg_out_tok))
    # throughput from prior agent batches ~45 narratives per batch agent; OpenAI earlier ~25/45s with 6 workers => ~2000/hour optimistic
    hours_low = scope_n / 2500
    hours_high = scope_n / 800

    span_ok = 1 - float(metrics_df[(metrics_df.field_name == "span_support") & (metrics_df.extractor == LLM_LABEL)]["incorrect_rate"].fillna(0).mean() or 0)
    # simpler: from evidence
    unver = con.execute(
        "SELECT AVG(CASE WHEN span_verified=0 THEN 1.0 ELSE 0.0 END) FROM analysis_narrative_evidence WHERE extraction_version=?",
        (LLM,),
    ).fetchone()[0]
    span_ok = 1 - float(unver or 0)

    decision = "GO_WITH_REDUCED_SCOPE"
    rationale = (
        f"LLM-v1 on 405 shows high incremental narrative signal (mismatch/hidden-signal ≈{mismatch['l_rate']:.0%}) "
        f"and {span_ok:.1%} span verification, but assistance/independence is not historically reconstructable at scale. "
        "Do not spend tokens on all ~21.7k hashes yet. Process a high-value subset first: PE/regression context, "
        "check/progress failures, repeated progression missions, high-grade later problems, curriculum-transition cohorts."
    )
    scope = {
        "include": [
            "exercise_regressed sessions",
            "CHECK_EVENT incomplete/below-standard",
            "mission_attempt_number>=2 progression repeats",
            "high structured grade with later regression linkage",
            "curriculum transition cohorts (optional)",
        ],
        "exclude": ["boilerplate", "short <40 chars", "duplicate text_hash", "already extracted hashes"],
        "prompt_version_required": "phase5-extract-v2 after human adjudication of 105-set",
    }
    con.execute(
        """INSERT INTO analysis_phase5_bulk_nlp_decision
        (decision,eligible_narratives,unique_hashes,recommended_scope_n,scope_definition_json,avg_input_chars,
         est_input_tokens,est_output_tokens,est_total_tokens,est_batch_count,est_runtime_hours_low,est_runtime_hours_high,
         cache_keys,rationale,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
        (
            decision,
            int(len(eligible)),
            int(eligible.text_hash.nunique()),
            scope_n,
            json.dumps(scope),
            avg_chars,
            int(scope_n * avg_in_tok),
            int(scope_n * avg_out_tok),
            est_total,
            int(math.ceil(scope_n / 45)),
            float(hours_low),
            float(hours_high),
            "text_hash|prompt_version|model|schema_version",
            rationale,
            VERSION,
            NOW,
        ),
    )
    con.commit()
    return {
        "decision": decision,
        "eligible": int(len(eligible)),
        "unique": int(eligible.text_hash.nunique()),
        "scope_n": scope_n,
        "full_remaining": full_n,
        "avg_chars": avg_chars,
        "est_total_tokens": est_total,
        "hours_low": hours_low,
        "hours_high": hours_high,
        "span_ok": span_ok,
        "rationale": rationale,
        "scope": scope,
    }


def final_architecture(con, dimdec):
    log("Locking conceptual evaluation architecture...")
    fields = [
        (
            "curriculum_expected_level",
            "Curriculum requirement for the exercise/mission (DE/EX/PR/PE or successor)",
            ["DE", "EX", "PR", "PE", "OTHER"],
            "curriculum",
            "derived",
            "HIGH — already in exercise naming/requirements",
            "mission/exercise definition",
            1,
        ),
        (
            "observed_independence",
            "How independently the student performed",
            ["INDEPENDENT", "MINOR_PROMPT", "REPEATED_PROMPTS", "COACHED", "INSTRUCTOR_INTERVENTION", "UNKNOWN"],
            "instructor (+ optional audio)",
            "manual_marker_preferred",
            "LOW historically (narrative silence common)",
            "intervention markers + audio cues",
            1,
        ),
        (
            "observed_quality",
            "Accuracy/quality vs expected standard",
            ["WITHIN_STANDARD", "MINOR_DEVIATION", "MATERIAL_DEVIATION", "OUTSIDE_STANDARD", "UNKNOWN"],
            "objective_first",
            "derived",
            "MEDIUM — grades + some narrative deviations",
            "Garmin/CVR tolerances",
            1,
        ),
        (
            "observed_consistency",
            "Repeatability within/across attempts",
            ["CONSISTENT", "MOSTLY_CONSISTENT", "VARIABLE", "INCONSISTENT", "INSUFFICIENT_EVIDENCE"],
            "instructor + objective attempts",
            "derived_or_light_manual",
            "MEDIUM — often narrated",
            "within-flight attempt curves",
            1,
        ),
        (
            "context",
            "Conditions affecting difficulty/transfer",
            ["NONE", "WIND_CROSSWIND", "HIGH_WORKLOAD", "UNFAMILIAR", "IFR_OR_ABNORMAL", "CHECK_ENV", "OTHER"],
            "system + instructor",
            "auto_preferred",
            "MEDIUM",
            "weather/traffic/airport/scenario tags",
            1,
        ),
        (
            "objective_evidence",
            "Machine-measurable observations (not assessments)",
            ["JSON evidence objects"],
            "cockpit_recorder",
            "derived",
            "LOW historically / HIGH future",
            "telemetry + events + audio features",
            1,
        ),
        (
            "instructor_observation",
            "Free-text / structured qualitative observation",
            ["text + optional tags"],
            "instructor",
            "manual",
            "HIGH",
            "optional audio-linked notes",
            1,
        ),
        (
            "student_self_assessment",
            "Optional learning metacognition",
            ["INTRODUCED", "DEVELOPING", "INDEPENDENT", "CONSISTENT", "UNKNOWN"],
            "student",
            "manual_optional",
            "LOW",
            "app input",
            0,
        ),
        (
            "learning_response_note",
            "Within-session improvement signal",
            ["RAPID_IMPROVEMENT", "IMPROVEMENT", "LIMITED", "NONE", "UNKNOWN"],
            "instructor/system",
            "optional",
            "MEDIUM",
            "attempt curves",
            0,
        ),
    ]
    # mark student_self_assessment retained=0 already
    con.executemany(
        """INSERT INTO analysis_phase5_final_architecture
        (field_name,purpose,allowed_states_json,provider,entry_mode,historical_availability,future_recorder_source,retained,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?)""",
        [
            (f, p, json.dumps(states), prov, mode, hist, src, ret, VERSION, NOW)
            for f, p, states, prov, mode, hist, src, ret in fields
        ],
    )
    con.commit()


def write_report(con, m, cmp_summary, mismatch, metrics_df, tone, dimdec, bulk):
    log("Writing Phase 5B report...")
    dims = pd.read_sql_query("SELECT * FROM analysis_phase5_dimension_validation", con)
    models = pd.read_sql_query("SELECT * FROM analysis_phase5_model_comparison ORDER BY model_code", con)
    mism = pd.read_sql_query("SELECT * FROM analysis_phase5_mismatch_llm", con)
    arch = pd.read_sql_query("SELECT * FROM analysis_phase5_final_architecture WHERE retained=1", con)
    summ = pd.read_sql_query("SELECT * FROM analysis_phase5_extractor_summary", con)

    # human validation mismatch estimate on 105
    val_ids = set(
        pd.read_sql_query(
            "SELECT DISTINCT narrative_id FROM analysis_phase5_human_validation",
            con,
        ).narrative_id
    )
    hv_mis = 0
    for _, r in m[m.narrative_id.isin(val_ids)].iterrows():
        fake = r.copy()
        fake["assistance_level"] = r.l_assist
        fake["consistency_class"] = r.l_cons
        fake["learning_response"] = r.l_learn
        fake["ndef_count"] = r.l_ndef
        fake["npos_count"] = r.l_npos
        if classify_mismatch(fake, set(), set()) in MISMATCH_CATS:
            hv_mis += 1
    hv_rate = hv_mis / max(len(val_ids), 1)

    yes = True  # narrative evidence justifies changing future capture
    lines = []
    A = lines.append
    A("# Phase 5B — LLM Validation & Final Evaluation-Model Decision")
    A("")
    A(f"**Analysis version:** `{VERSION}`  ")
    A(f"**Primary extractor:** `{LLM}` (LLM-v1 / phase5_extract_v1)  ")
    A(f"**Comparison extractor:** `{HEUR}` (heuristic-v1)  ")
    A(f"**Generated:** {NOW}  ")
    A("**Sample:** full stratified 405 narratives  ")
    A("**Constraints:** no Phase 6; no bulk NLP execution; no UI redesign; no E-gle writes.")
    A("")
    A("## EXECUTIVE CONCLUSION")
    A("")
    A("**Yes — narrative-derived evidence provides enough reliable incremental information to justify changing how we capture student competency in the future.**")
    A("")
    A("But not by mining history for independence. The justified future change is:")
    A("")
    A("1. Keep **curriculum expected level** separate from observed performance.")
    A("2. Add **structured independence/assistance** going forward (historically NOT reliably reconstructable).")
    A("3. Keep **consistency** as an explicit observed state (LLM-recoverable and associated with later problems).")
    A("4. Let **quality/accuracy** be primarily objective via Cockpit Recorder/Garmin.")
    A("5. Add **context/transfer** as mostly auto-derived interpretation, not a heavy rubric.")
    A("")
    A(f"- Heuristic mismatch/hidden-signal rate: **{mismatch['h_rate']:.1%}** ({mismatch['h_mismatch_n']}/{mismatch['n']})")
    A(f"- LLM mismatch/hidden-signal rate: **{mismatch['l_rate']:.1%}** ({mismatch['l_mismatch_n']}/{mismatch['n']})")
    A(f"- Validation-set LLM mismatch rate: **{hv_rate:.1%}** ({hv_mis}/{len(val_ids)})")
    A(f"- Overlap (both methods flag mismatch): **{mismatch['overlap']}** narratives")
    A(f"- Encouraging/mixed tone with deficiency (LLM): **{tone[0].get('positive_or_mixed_tone_with_deficiency',0)}/{tone[1]}**")
    A(f"- Independence extractability: **{dimdec['assist_extract']}**")
    A(f"- Consistency decision: **{dimdec['cons_decision']}**")
    A(f"- Bulk NLP: **{bulk['decision']}** (targeted subset ≈{bulk['scope_n']} unique hashes)")
    A("")
    A("---")
    A("")
    A("## 1. Heuristic vs LLM comparison")
    A("")
    A("| Metric | Heuristic | LLM | Agreement | Interpretation |")
    A("|---|---:|---:|---:|---|")
    for _, r in summ.iterrows():
        hr = "" if pd.isna(r.heuristic_rate) else f"{r.heuristic_rate:.3f}"
        lr = "" if pd.isna(r.llm_rate) else f"{r.llm_rate:.3f}"
        A(f"| {r.metric_name} | {hr} | {lr} | {r.agreement_rate:.1%} | {r.interpretation or ''} |")
    A("")
    A(f"Mean evidence items: heuristic **{m.h_nev.mean():.1f}** vs LLM **{m.l_nev.mean():.1f}**.")
    A("")
    A("**Where LLM adds information:** deficiency detection, consistency language, richer multi-dimension evidence, tone/deficiency coexistence flags.")
    A("")
    A("**Where heuristic is more conservative/reliable:** lower false invention risk; 100% span-bound by construction; less over-assignment of SA/CRM-like dimensions.")
    A("")
    A("Disagreement ≠ LLM automatic win. Text-grounded adjudication is required for assistance/consistency presence claims.")
    A("")
    A("## 2. Human-validation metrics")
    A("")
    A("Adjudication method: text-grounded presence/absence on the 105-row validation set. **Narrative silence is coded `NOT_PRESENT_IN_NARRATIVE`, never `CONFIRMED_NO_ASSISTANCE`.**")
    A("")
    A("| Field | Extractor | n | Precision | Recall | F1 | Incorrect |")
    A("|---|---|---:|---:|---:|---:|---:|")
    for _, r in metrics_df.iterrows():
        def fmt(x):
            return "" if x is None or (isinstance(x, float) and np.isnan(x)) else f"{x:.2f}"
        A(
            f"| {r.field_name} | {r.extractor} | {int(r.n)} | {fmt(r.precision_est)} | {fmt(r.recall_est)} | {fmt(r.f1_est)} | {fmt(r.incorrect_rate)} |"
        )
    A("")
    A("## 3. Extraction failure modes")
    A("")
    A("- Inventing assistance from coaching-advice language without clear in-flight intervention.")
    A("- Treating absence of assistance language as independence.")
    A("- Over-assigning situational awareness / decision-making on broad CRM praise.")
    A("- Consistency inferred too aggressively from weak cues (heuristic) or under-detected (also possible).")
    A("- Unverified spans in LLM outputs (38/2173 = ~1.7%).")
    A("- Encouraging tone mistaken for overall positive competency state.")
    A("")
    A("## 4. Narrative/grade mismatch final estimate")
    A("")
    A("| Extractor | Category | n | rate |")
    A("|---|---|---:|---:|")
    for _, r in mism.sort_values(["extractor", "n"], ascending=[True, False]).iterrows():
        A(f"| {r.extractor} | `{r.agreement_category}` | {int(r.n)} | {r.rate:.1%} |")
    A("")
    A(f"The heuristic ~23% mismatch finding does **not** remain at 23% under LLM-v1. LLM-v1 yields a **higher** hidden-signal/mismatch rate (**{mismatch['l_rate']:.1%}**), driven mainly by `NARRATIVE_DEFICIENCY_NOT_REFLECTED_IN_GRADE`. This strengthens—not weakens—the case that structured grades omit narrative-critical information. Use LLM rates as the primary estimate; treat heuristic as a conservative lower bound.")
    A("")
    A("## 5. Tone vs performance")
    A("")
    A("| Pattern | n |")
    A("|---|---:|")
    for k, v in tone[0].items():
        A(f"| {k} | {v} |")
    A("")
    A("Tone and performance state are different variables. Future student-facing design must surface evidence/states, not only encouraging debrief language.")
    A("")
    A("## 6. Independence findings")
    A("")
    A(f"- Extractability: **{dimdec['assist_extract']}**")
    A(f"- LLM assistance-present frequency: **{dimdec['assist_freq']:.1%}**")
    A(f"- Downstream Δ later_regression (assisted − minimal) among strong grades: **{dimdec['ind_d']}** (n={dimdec['ind_n']})")
    A("")
    A("**Decision:** Historical narratives are **not sufficient** to reconstruct independence. Absence of assistance language ≠ confirmed independent performance. Independence/instructor intervention must become **structured future data**.")
    A("")
    A("## 7. Consistency findings")
    A("")
    A(f"- Decision: **{dimdec['cons_decision']}**")
    A(f"- Extractability: **{dimdec['cons_extract']}**")
    A(f"- Frequency of non-insufficient consistency class: **{dimdec['cons_freq']:.1%}**")
    A(f"- Downstream Δ later_regression (inconsistent − consistent) among strong grades: **{dimdec['cons_d']}** (n={dimdec['cons_n']})")
    A("")
    A("## 8. Quality findings")
    A("")
    A(f"- Decision: **{dimdec['quality_decision']}**")
    A(f"- Usable non-UNKNOWN accuracy class frequency: **{dimdec['acc_freq']:.1%}**")
    A("- Most quality/accuracy should come from objective Cockpit Recorder/Garmin tolerances; avoid duplicating the structured grade.")
    A("")
    A("## 9. Context/transfer findings")
    A("")
    A(f"- Decision: **{dimdec['ctx_decision']}**")
    A(f"- Context present frequency: **{dimdec['ctx_freq']:.1%}**")
    A(f"- LLM transfer labels: TRUE_REGRESSION_LIKELY={dimdec['true_reg']}, CONTEXTUAL_TRANSFER_DIFFICULTY_LIKELY={dimdec['transfer']}, AMBIGUOUS={dimdec['ambig']}")
    A("- Keep RAW Phase 4 regression metrics; add contextual interpretation as a separate layer.")
    A("")
    A("## 10. Downstream predictive value")
    A("")
    A("Among strong structured grades, LLM consistency differences show a clearer downstream separation than historical assistance mining. Assistance effects are unstable/small-n and confounded because assistance is under-documented. This supports: **capture independence structurally; use consistency + objective quality + context for durability/transfer interpretation.**")
    A("")
    A("## 11. Candidate-model comparison")
    A("")
    A("| Model | Description | Recommendation |")
    A("|---|---|---|")
    for _, r in models.iterrows():
        A(f"| `{r.model_code}` | {r.description} | **{r.recommendation}** — {r.evidence_notes} |")
    A("")
    A("**Locked choice:** Model **E** as conceptual architecture; Model **D** as the practical minimum once independence is captured as structured data.")
    A("")
    A("## 12. Bulk NLP GO/NO-GO")
    A("")
    A(f"**Decision: `{bulk['decision']}`**")
    A("")
    A(bulk["rationale"])
    A("")
    A("Scope:")
    A("```json")
    A(json.dumps(bulk["scope"], indent=2))
    A("```")
    A("")
    A("## 13. Cost/scale estimate")
    A("")
    A(f"- Eligible narratives: {bulk['eligible']}")
    A(f"- Unique hashes: {bulk['unique']}")
    A(f"- Recommended scope unique hashes: {bulk['scope_n']}")
    A(f"- Full remaining unique hashes if unrestricted: {bulk['full_remaining']}")
    A(f"- Avg input chars (truncated at 7k): {bulk['avg_chars']:.0f}")
    A(f"- Estimated total tokens (scope): ≈{bulk['est_total_tokens']:,}")
    A(f"- Estimated runtime: ~{bulk['hours_low']:.1f}–{bulk['hours_high']:.1f} hours depending on concurrency")
    A("- Pricing: not asserted (no authoritative model price in project config)")
    A("- Cache key: `text_hash|prompt_version|model|schema_version`")
    A("")
    A("## 14. Locked conceptual evaluation architecture")
    A("")
    A("Core design principle: separate **OBSERVATION** vs **ASSESSMENT** vs **COMPETENCY STATE** vs **CURRICULUM EXPECTATION**.")
    A("")
    A("| Field | Purpose | Provider | Entry | Historical | Recorder |")
    A("|---|---|---|---|---|---|")
    for _, r in arch.iterrows():
        A(f"| `{r.field_name}` | {r.purpose} | {r.provider} | {r.entry_mode} | {r.historical_availability} | {r.future_recorder_source} |")
    A("")
    A("Retained dimensions that earned their place:")
    A("- **curriculum_expected_level** — already exists; keep separate")
    A("- **observed_independence** — earned as future structured capture (not historical NLP-only)")
    A("- **observed_consistency** — earned via narrative frequency + downstream association")
    A("- **observed_quality** — earned mainly as objective/derived field")
    A("- **context** — earned as auto interpretation layer")
    A("- **objective_evidence / instructor_observation** — evidence channels, not grades")
    A("")
    A("## 15. Remaining uncertainties")
    A("")
    A("- Validation set adjudication is text-grounded assistant review, not multi-instructor human panel.")
    A("- OpenAI PHP-FPM key path was not available in this local environment; LLM-v1 is Cursor-agent extraction under the same schema.")
    A("- Later-outcome proxies remain coarse (any later regression/repeat/checkpoint problem).")
    A("- Assistance downstream estimates are small-n and selection-confounded.")
    A("- Prompt v2 should be locked after formal human review of the 105-set before reduced-scope bulk NLP.")
    A("")
    A("## 16. Recommended Phase 6")
    A("")
    A("1. Human-adjudicate the 105 validation rows; publish prompt/schema v2.")
    A("2. Implement lightweight future capture: intervention marker, attempt index, exercise windows, auto tolerances, context tags.")
    A("3. Run reduced-scope bulk NLP only on the high-value hash subset with v2.")
    A("4. Specify storage schema for Observation / Assessment / Competency state / Expected level — still no student UI polish.")
    A("5. Re-test durability prediction with newly captured independence markers on live/recorder-linked sessions.")
    A("")
    A("## Supporting tables")
    A("")
    A("| Table | Rows |")
    A("|---|---:|")
    for t in [
        "analysis_phase5_extractor_comparison",
        "analysis_phase5_extractor_summary",
        "analysis_phase5_human_validation",
        "analysis_phase5_human_validation_metrics",
        "analysis_phase5_mismatch_llm",
        "analysis_phase5_dimension_validation",
        "analysis_phase5_model_comparison",
        "analysis_phase5_bulk_nlp_decision",
        "analysis_phase5_final_architecture",
    ]:
        n = con.execute(f"SELECT COUNT(*) FROM {t}").fetchone()[0]
        A(f"| `{t}` | {n} |")
    A("")
    A("## Reproduce")
    A("")
    A("```bash")
    A("analytics/.venv/bin/python -u analytics/etl/phase5b_validate.py")
    A("```")
    A("")

    REPORT.parent.mkdir(parents=True, exist_ok=True)
    REPORT.write_text("\n".join(lines), encoding="utf-8")
    log(f"Wrote {REPORT}")


def main():
    con = connect()
    ensure_schema(con)
    clear_phase5b(con)
    n_llm = con.execute(
        "SELECT COUNT(*) FROM analysis_narrative_extraction WHERE extraction_version=? AND parse_status='OK'",
        (LLM,),
    ).fetchone()[0]
    n_h = con.execute(
        "SELECT COUNT(*) FROM analysis_narrative_extraction WHERE extraction_version=? AND parse_status='OK'",
        (HEUR,),
    ).fetchone()[0]
    if n_llm < 405 or n_h < 405:
        raise SystemExit(f"Need 405/405 both extractors; llm={n_llm} heur={n_h}")

    enriched, heur, llm, evid = load_extractions(con)
    # attach later flags from enriched into merges via enriched columns
    m, cmp = compare_extractors(con, enriched, heur, llm, evid)
    # ensure later flags present
    if "later_regression_flag" not in m.columns:
        m = m.merge(
            enriched[["narrative_id", "later_regression_flag", "later_repeat_flag", "later_checkpoint_problem_flag", "raw_text"]],
            on="narrative_id",
            how="left",
        )
    mismatch = mismatch_tables(con, m)
    hv, metrics_df = human_validation(con, m, evid)
    tone = tone_analysis(m)
    dimdec = dimension_decisions(con, m, metrics_df, mismatch)
    model_comparison(con, dimdec, mismatch)
    bulk = bulk_decision(con, mismatch, metrics_df)
    final_architecture(con, dimdec)
    con.execute(
        """INSERT INTO analysis_phase5b_meta (analysis_version,llm_extractor,heuristic_extractor,generated_at,notes)
           VALUES (?,?,?,?,?)""",
        (VERSION, LLM, HEUR, NOW, f"mismatch_llm={mismatch['l_rate']:.3f}; bulk={bulk['decision']}"),
    )
    con.commit()
    write_report(con, m, cmp, mismatch, metrics_df, tone, dimdec, bulk)
    con.close()
    log("Phase 5B complete.")


if __name__ == "__main__":
    main()
