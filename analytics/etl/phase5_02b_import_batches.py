#!/usr/bin/env python3
"""Import Phase 5 batch LLM extraction outputs into analytics tables.

Accepts out_batch_*.json written by extraction agents / OpenAI runner.
extraction_version defaults to phase5-extract-v1-agent.
"""

from __future__ import annotations

import html
import json
import re
import sqlite3
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DB = ROOT / "storage/analytics/egle_training_analytics.sqlite"
BATCH_DIR = ROOT / "tmp/analytics/phase5_batches"
VERSION = "phase5-v1"
EXTRACTION_VERSION = "phase5-extract-v1-agent"
PROMPT_VERSION = "phase5-extract-v1"
MODEL = "cursor-agent-llm"
NOW = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def clean_text(raw: str) -> str:
    t = html.unescape(raw or "")
    t = re.sub(r"<br\s*/?>", "\n", t, flags=re.I)
    t = re.sub(r"<[^>]+>", " ", t)
    t = re.sub(r"[ \t]+", " ", t)
    return t.strip()


def normalize(s: str) -> str:
    return re.sub(r"\s+", " ", (s or "").lower()).strip()


def span_ok(span: str, text: str) -> bool:
    if not span or not span.strip():
        return False
    ns, nt = normalize(span), normalize(text)
    if ns in nt:
        return True
    return len(ns) > 40 and ns[:40] in nt


def main() -> int:
    files = sorted(BATCH_DIR.glob("out_batch_*.json"))
    if not files:
        print("No out_batch_*.json found", flush=True)
        return 1
    con = sqlite3.connect(DB)
    con.row_factory = sqlite3.Row
    enriched = {
        int(r["narrative_id"]): r
        for r in con.execute("SELECT * FROM analysis_narrative_sample_enriched").fetchall()
    }
    con.execute("DELETE FROM analysis_narrative_evidence WHERE extraction_version=?", (EXTRACTION_VERSION,))
    con.execute("DELETE FROM analysis_narrative_extraction WHERE extraction_version=?", (EXTRACTION_VERSION,))
    con.commit()

    ok = fail = 0
    seen = set()
    for fp in files:
        data = json.loads(fp.read_text(encoding="utf-8"))
        if not isinstance(data, list):
            print(f"skip bad file {fp}", flush=True)
            continue
        for p in data:
            nid = int(p.get("narrative_id"))
            if nid in seen:
                continue
            seen.add(nid)
            row = enriched.get(nid)
            if not row:
                fail += 1
                continue
            text = clean_text(row["raw_text"])
            warnings = []
            cur = con.execute(
                """INSERT INTO analysis_narrative_extraction
                (narrative_id,session_id,text_hash,sample_stratum,overall_narrative_tone,assistance_level,
                 assistance_reason,assistance_context,assistance_improved_after,consistency_class,learning_response,
                 accuracy_quality,context_tags_json,context_effect,transfer_interpretation,missing_middle_states_json,
                 measurable_deviations_json,summary_flags_json,raw_response_json,llm_model,prompt_version,
                 extraction_version,parse_status,parse_warnings,analysis_version,generated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
                (
                    nid,
                    row["session_id"],
                    row["text_hash"],
                    row["sample_stratum"],
                    p.get("overall_narrative_tone"),
                    p.get("assistance_level"),
                    p.get("assistance_reason"),
                    p.get("assistance_context"),
                    p.get("assistance_improved_after"),
                    p.get("consistency_class"),
                    p.get("learning_response"),
                    p.get("accuracy_quality"),
                    json.dumps(p.get("context_tags") or []),
                    p.get("context_effect"),
                    p.get("transfer_interpretation"),
                    json.dumps(p.get("missing_middle_states") or []),
                    json.dumps(p.get("measurable_deviations") or []),
                    json.dumps(p.get("flags") or {}),
                    json.dumps(p, ensure_ascii=False),
                    MODEL,
                    PROMPT_VERSION,
                    EXTRACTION_VERSION,
                    "OK",
                    None,
                    VERSION,
                    NOW,
                ),
            )
            eid = cur.lastrowid
            for obs in p.get("observations") or []:
                span = (obs.get("evidence_span") or "").strip()
                verified = 1 if span_ok(span, text) else 0
                if not verified:
                    warnings.append("unverified_span")
                con.execute(
                    """INSERT INTO analysis_narrative_evidence
                    (extraction_id,narrative_id,text_hash,evidence_span,observation_polarity,interpretation,
                     competency_dimensions_json,severity,confidence,span_verified,llm_model,prompt_version,
                     extraction_version,analysis_version,generated_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
                    (
                        eid,
                        nid,
                        row["text_hash"],
                        span,
                        obs.get("polarity"),
                        obs.get("interpretation"),
                        json.dumps(obs.get("dimensions") or []),
                        obs.get("severity"),
                        obs.get("confidence"),
                        verified,
                        MODEL,
                        PROMPT_VERSION,
                        EXTRACTION_VERSION,
                        VERSION,
                        NOW,
                    ),
                )
            if warnings:
                con.execute(
                    "UPDATE analysis_narrative_extraction SET parse_warnings=? WHERE extraction_id=?",
                    (";".join(sorted(set(warnings))), eid),
                )
            ok += 1
        print(f"imported {fp.name}", flush=True)
    con.execute("DELETE FROM analysis_phase5_meta")
    con.execute(
        """INSERT INTO analysis_phase5_meta (analysis_version,prompt_version,extraction_version,llm_model,generated_at,notes)
           VALUES (?,?,?,?,?,?)""",
        (VERSION, PROMPT_VERSION, EXTRACTION_VERSION, MODEL, NOW, f"ok={ok} fail={fail} files={len(files)}"),
    )
    con.commit()
    evid = con.execute("SELECT COUNT(*) FROM analysis_narrative_evidence WHERE extraction_version=?", (EXTRACTION_VERSION,)).fetchone()[0]
    unver = con.execute(
        "SELECT COUNT(*) FROM analysis_narrative_evidence WHERE extraction_version=? AND span_verified=0",
        (EXTRACTION_VERSION,),
    ).fetchone()[0]
    print(f"Import complete ok={ok} fail={fail} evidence={evid} unverified={unver} coverage={ok}/405", flush=True)
    con.close()
    return 0 if ok >= 300 else 1


if __name__ == "__main__":
    # Allow analyzer to use agent extraction version
    sys.exit(main())
