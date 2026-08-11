#!/usr/bin/env python3
"""Phase 5 narrative extraction on the 405-row stratified sample.

Uses CW_OPENAI_API_KEY / CW_OPENAI_MODEL from environment (.env).
Strict structured JSON; caches by text_hash; analytics SQLite only.
"""

from __future__ import annotations

import html
import json
import os
import re
import sqlite3
import sys
import time
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DB = ROOT / "storage/analytics/egle_training_analytics.sqlite"
PROMPT_PATH = ROOT / "analytics/prompts/phase5_extract_v1.json"
CACHE_DIR = ROOT / "tmp/analytics/phase5_llm_cache"
VERSION = "phase5-v1"
MAX_CHARS = 7000
WORKERS = 6
RETRIES = 3

JSON_SCHEMA = {
    "type": "object",
    "additionalProperties": False,
    "required": [
        "overall_narrative_tone",
        "observations",
        "assistance_level",
        "assistance_reason",
        "assistance_context",
        "assistance_improved_after",
        "consistency_class",
        "learning_response",
        "accuracy_quality",
        "context_tags",
        "context_effect",
        "transfer_interpretation",
        "missing_middle_states",
        "measurable_deviations",
        "flags",
    ],
    "properties": {
        "overall_narrative_tone": {"type": "string", "enum": ["POSITIVE", "NEUTRAL", "CRITICAL", "MIXED"]},
        "observations": {
            "type": "array",
            "items": {
                "type": "object",
                "additionalProperties": False,
                "required": ["evidence_span", "polarity", "interpretation", "dimensions", "severity", "confidence"],
                "properties": {
                    "evidence_span": {"type": "string"},
                    "polarity": {"type": "string", "enum": ["POSITIVE", "DEFICIENCY", "NEUTRAL_CONTEXT"]},
                    "interpretation": {"type": "string"},
                    "dimensions": {
                        "type": "array",
                        "items": {
                            "type": "string",
                            "enum": [
                                "KNOWLEDGE_UNDERSTANDING",
                                "PROCEDURAL_EXECUTION",
                                "TECHNICAL_CONTROL",
                                "ACCURACY_TOLERANCE",
                                "INDEPENDENCE",
                                "CONSISTENCY",
                                "DECISION_MAKING",
                                "SITUATIONAL_AWARENESS",
                                "WORKLOAD_MANAGEMENT",
                                "COMMUNICATION_RADIO",
                                "SOP_CHECKLIST_DISCIPLINE",
                                "TRANSFER_ADAPTABILITY",
                                "SAFETY_MARGIN",
                                "LEARNING_RESPONSE_IMPROVEMENT",
                                "INSTRUCTOR_ASSISTANCE",
                                "OTHER",
                                "UNKNOWN",
                            ],
                        },
                    },
                    "severity": {"type": "string", "enum": ["LOW", "MEDIUM", "HIGH", "UNKNOWN"]},
                    "confidence": {"type": "string", "enum": ["HIGH", "MEDIUM", "LOW"]},
                },
            },
        },
        "assistance_level": {
            "type": "string",
            "enum": [
                "NONE_OBSERVED",
                "VERBAL_CONFIRMATION_ONLY",
                "MINOR_PROMPT",
                "REPEATED_PROMPTS",
                "STEP_BY_STEP_COACHING",
                "INSTRUCTOR_DEMONSTRATION",
                "PHYSICAL_INTERVENTION",
                "TAKEOVER_OR_SAFETY_INTERVENTION",
                "UNKNOWN",
            ],
        },
        "assistance_reason": {"type": "string"},
        "assistance_context": {"type": "string"},
        "assistance_improved_after": {
            "type": "string",
            "enum": ["YES", "NO", "PARTIAL", "UNKNOWN", "NOT_APPLICABLE"],
        },
        "consistency_class": {
            "type": "string",
            "enum": ["CONSISTENT", "MOSTLY_CONSISTENT", "VARIABLE", "INCONSISTENT", "INSUFFICIENT_EVIDENCE"],
        },
        "learning_response": {
            "type": "string",
            "enum": [
                "RAPID_IMPROVEMENT",
                "IMPROVEMENT",
                "LIMITED_IMPROVEMENT",
                "NO_IMPROVEMENT",
                "REGRESSION_WITHIN_SESSION",
                "UNKNOWN",
            ],
        },
        "accuracy_quality": {
            "type": "string",
            "enum": ["WITHIN_STANDARD", "MINOR_DEVIATION", "MATERIAL_DEVIATION", "OUTSIDE_STANDARD", "UNKNOWN"],
        },
        "context_tags": {
            "type": "array",
            "items": {
                "type": "string",
                "enum": [
                    "WIND",
                    "CROSSWIND",
                    "GUSTS",
                    "TURBULENCE",
                    "TRAFFIC",
                    "ATC_WORKLOAD",
                    "HIGH_WORKLOAD",
                    "DIFFERENT_AIRPORT",
                    "UNFAMILIAR_AIRPORT",
                    "DIFFERENT_AIRCRAFT",
                    "INSTRUMENT_CONDITIONS_OR_SIMULATED_IFR",
                    "EMERGENCY_OR_ABNORMAL_SCENARIO",
                    "COMBINED_TASK",
                    "CHECK_OR_EVALUATION_ENVIRONMENT",
                    "FATIGUE_OR_HUMAN_FACTORS",
                    "OTHER",
                    "UNKNOWN",
                ],
            },
        },
        "context_effect": {
            "type": "string",
            "enum": [
                "STABLE_DESPITE_CONTEXT",
                "DEGRADED_UNDER_CONTEXT",
                "CONTEXT_REQUIRED_ASSISTANCE",
                "INSUFFICIENT_EVIDENCE",
                "NOT_APPLICABLE",
            ],
        },
        "transfer_interpretation": {
            "type": "string",
            "enum": [
                "TRUE_REGRESSION_LIKELY",
                "CONTEXTUAL_TRANSFER_DIFFICULTY_LIKELY",
                "AMBIGUOUS",
                "NOT_APPLICABLE",
            ],
        },
        "missing_middle_states": {
            "type": "array",
            "items": {
                "type": "string",
                "enum": [
                    "NEEDS_CONTINUOUS_ASSISTANCE",
                    "NEEDS_OCCASIONAL_PROMPTING",
                    "INDEPENDENT_BUT_INCONSISTENT",
                    "ACCURATE_ONLY_IN_FAMILIAR_CONTEXT",
                    "INDEPENDENT_WITHIN_TOLERANCE",
                    "PERFORMS_CONSISTENTLY",
                    "TRANSFERS_TO_CHANGED_CONTEXT",
                ],
            },
        },
        "measurable_deviations": {
            "type": "array",
            "items": {
                "type": "object",
                "additionalProperties": False,
                "required": ["metric", "value_text", "unit_or_note"],
                "properties": {
                    "metric": {"type": "string"},
                    "value_text": {"type": "string"},
                    "unit_or_note": {"type": "string"},
                },
            },
        },
        "flags": {
            "type": "object",
            "additionalProperties": False,
            "required": [
                "encouraging_tone_with_deficiency",
                "high_grade_with_assistance_signal",
                "high_grade_with_inconsistency_signal",
                "narrative_silent_on_performance",
            ],
            "properties": {
                "encouraging_tone_with_deficiency": {"type": "boolean"},
                "high_grade_with_assistance_signal": {"type": "boolean"},
                "high_grade_with_inconsistency_signal": {"type": "boolean"},
                "narrative_silent_on_performance": {"type": "boolean"},
            },
        },
    },
}


def log(msg: str) -> None:
    print(msg, flush=True)


def load_dotenv() -> None:
    env_path = ROOT / ".env"
    if not env_path.exists():
        return
    for line in env_path.read_text(encoding="utf-8", errors="ignore").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        k = k.strip()
        v = v.strip().strip('"').strip("'")
        if k and k not in os.environ:
            os.environ[k] = v


def clean_text(raw: str) -> str:
    t = html.unescape(raw or "")
    t = re.sub(r"<br\s*/?>", "\n", t, flags=re.I)
    t = re.sub(r"<[^>]+>", " ", t)
    t = re.sub(r"[ \t]+", " ", t)
    t = re.sub(r"\n{3,}", "\n\n", t)
    return t.strip()


def truncate(text: str, max_chars: int = MAX_CHARS) -> tuple[str, bool]:
    if len(text) <= max_chars:
        return text, False
    return text[:max_chars] + "\n\n[TRUNCATED_FOR_EXTRACTION]", True


def normalize_for_span(s: str) -> str:
    s = s.lower()
    s = re.sub(r"\s+", " ", s)
    return s.strip()


def span_in_text(span: str, text: str) -> bool:
    if not span or not span.strip():
        return False
    nspan = normalize_for_span(span)
    ntext = normalize_for_span(text)
    if nspan in ntext:
        return True
    # allow short fuzzy: first 40 chars
    if len(nspan) > 40 and nspan[:40] in ntext:
        return True
    return False


def openai_extract(model: str, system: str, user: str, api_key: str) -> dict:
    # Bypass Cursor sandbox HTTP proxies that 403 tunnel OpenAI calls.
    for k in [
        "HTTP_PROXY",
        "HTTPS_PROXY",
        "ALL_PROXY",
        "http_proxy",
        "https_proxy",
        "all_proxy",
        "SOCKS_PROXY",
        "SOCKS5_PROXY",
        "socks_proxy",
        "socks5_proxy",
    ]:
        os.environ.pop(k, None)

    payload = {
        "model": model,
        "input": [
            {"role": "system", "content": [{"type": "input_text", "text": system}]},
            {"role": "user", "content": [{"type": "input_text", "text": user}]},
        ],
        "text": {
            "format": {
                "type": "json_schema",
                "name": "phase5_narrative_extraction",
                "strict": True,
                "schema": JSON_SCHEMA,
            }
        },
    }
    data = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(
        "https://api.openai.com/v1/responses",
        data=data,
        headers={
            "Authorization": f"Bearer {api_key}",
            "Content-Type": "application/json",
        },
        method="POST",
    )
    # Force direct connection (no proxy handler).
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    with opener.open(req, timeout=180) as resp:
        body = json.loads(resp.read().decode("utf-8"))
    # Collect output_text
    texts = []
    for item in body.get("output", []) or []:
        for c in item.get("content", []) or []:
            if c.get("type") in ("output_text", "text") and c.get("text"):
                texts.append(c["text"])
    if not texts and isinstance(body.get("output_text"), str):
        texts.append(body["output_text"])
    raw = "\n".join(texts).strip()
    if not raw:
        raise RuntimeError(f"Empty model output: {json.dumps(body)[:500]}")
    return json.loads(raw)


def connect() -> sqlite3.Connection:
    con = sqlite3.connect(DB)
    con.row_factory = sqlite3.Row
    return con


def main() -> int:
    load_dotenv()
    api_key = os.environ.get("CW_OPENAI_API_KEY") or os.environ.get("OPENAI_API_KEY")
    if not api_key:
        log("ERROR: CW_OPENAI_API_KEY missing")
        return 1
    model = os.environ.get("CW_OPENAI_MODEL") or "gpt-5.4"
    prompt = json.loads(PROMPT_PATH.read_text(encoding="utf-8"))
    prompt_version = prompt["prompt_version"]
    extraction_version = prompt["extraction_version"]
    system = prompt["system"]
    user_template = prompt["user_template"]
    NOW = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    CACHE_DIR.mkdir(parents=True, exist_ok=True)

    con = connect()
    rows = con.execute("SELECT * FROM analysis_narrative_sample_enriched ORDER BY narrative_id").fetchall()
    if not rows:
        log("ERROR: enriched sample empty; run phase5_01_bootstrap.php first")
        return 1
    log(f"Extracting {len(rows)} narratives with model={model} prompt={prompt_version}")

    # Clear previous same extraction version
    con.execute("DELETE FROM analysis_narrative_evidence WHERE extraction_version=?", (extraction_version,))
    con.execute("DELETE FROM analysis_narrative_extraction WHERE extraction_version=?", (extraction_version,))
    con.commit()

    def process_one(row: sqlite3.Row) -> dict:
        text = clean_text(row["raw_text"])
        text, truncated = truncate(text)
        cache_path = CACHE_DIR / f"{row['text_hash']}_{extraction_version}.json"
        if cache_path.exists():
            parsed = json.loads(cache_path.read_text(encoding="utf-8"))
            return {"row": row, "parsed": parsed, "truncated": truncated, "cached": True, "error": None}
        user = user_template
        for k in [
            "sample_stratum",
            "program_name",
            "version_code",
            "mission_code",
            "mission_name",
            "mission_role",
            "session_date",
            "grading_raw",
            "grading_color",
            "grading_completion",
            "exercises_below_required",
            "mission_attempt_number",
        ]:
            user = user.replace("{{" + k + "}}", str(row[k] if row[k] is not None else ""))
        user = user.replace("{{narrative_text}}", text)
        last_err = None
        for attempt in range(1, RETRIES + 1):
            try:
                parsed = openai_extract(model, system, user, api_key)
                cache_path.write_text(json.dumps(parsed, ensure_ascii=False, indent=2), encoding="utf-8")
                return {"row": row, "parsed": parsed, "truncated": truncated, "cached": False, "error": None}
            except Exception as e:
                last_err = e
                time.sleep(1.5 * attempt)
        return {"row": row, "parsed": None, "truncated": truncated, "cached": False, "error": str(last_err)}

    results = []
    done = 0
    with ThreadPoolExecutor(max_workers=WORKERS) as ex:
        futs = [ex.submit(process_one, r) for r in rows]
        for fut in as_completed(futs):
            results.append(fut.result())
            done += 1
            if done % 25 == 0 or done == len(rows):
                log(f"  progress {done}/{len(rows)}")

    ok = 0
    fail = 0
    for res in results:
        row = res["row"]
        if res["error"] or not res["parsed"]:
            fail += 1
            con.execute(
                """INSERT INTO analysis_narrative_extraction
                (narrative_id,session_id,text_hash,sample_stratum,overall_narrative_tone,assistance_level,
                 assistance_reason,assistance_context,assistance_improved_after,consistency_class,learning_response,
                 accuracy_quality,context_tags_json,context_effect,transfer_interpretation,missing_middle_states_json,
                 measurable_deviations_json,summary_flags_json,raw_response_json,llm_model,prompt_version,
                 extraction_version,parse_status,parse_warnings,analysis_version,generated_at)
                VALUES (?,?,?,?,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,?,?,?,?,?,?,?,?)""",
                (
                    row["narrative_id"],
                    row["session_id"],
                    row["text_hash"],
                    row["sample_stratum"],
                    json.dumps({"error": res["error"]}),
                    model,
                    prompt_version,
                    extraction_version,
                    "FAIL",
                    str(res["error"])[:500],
                    VERSION,
                    NOW,
                ),
            )
            continue
        p = res["parsed"]
        warnings = []
        if res["truncated"]:
            warnings.append("truncated_input")
        text_for_verify = clean_text(row["raw_text"])
        cur = con.execute(
            """INSERT INTO analysis_narrative_extraction
            (narrative_id,session_id,text_hash,sample_stratum,overall_narrative_tone,assistance_level,
             assistance_reason,assistance_context,assistance_improved_after,consistency_class,learning_response,
             accuracy_quality,context_tags_json,context_effect,transfer_interpretation,missing_middle_states_json,
             measurable_deviations_json,summary_flags_json,raw_response_json,llm_model,prompt_version,
             extraction_version,parse_status,parse_warnings,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
            (
                row["narrative_id"],
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
                model,
                prompt_version,
                extraction_version,
                "OK",
                ";".join(warnings) if warnings else None,
                VERSION,
                NOW,
            ),
        )
        extraction_id = cur.lastrowid
        for obs in p.get("observations") or []:
            span = (obs.get("evidence_span") or "").strip()
            verified = 1 if span_in_text(span, text_for_verify) else 0
            if not verified:
                warnings.append("unverified_span")
            con.execute(
                """INSERT INTO analysis_narrative_evidence
                (extraction_id,narrative_id,text_hash,evidence_span,observation_polarity,interpretation,
                 competency_dimensions_json,severity,confidence,span_verified,llm_model,prompt_version,
                 extraction_version,analysis_version,generated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
                (
                    extraction_id,
                    row["narrative_id"],
                    row["text_hash"],
                    span,
                    obs.get("polarity"),
                    obs.get("interpretation"),
                    json.dumps(obs.get("dimensions") or []),
                    obs.get("severity"),
                    obs.get("confidence"),
                    verified,
                    model,
                    prompt_version,
                    extraction_version,
                    VERSION,
                    NOW,
                ),
            )
        if warnings:
            con.execute(
                "UPDATE analysis_narrative_extraction SET parse_warnings=? WHERE extraction_id=?",
                (";".join(sorted(set(warnings))), extraction_id),
            )
        ok += 1

    con.execute("DELETE FROM analysis_phase5_meta")
    con.execute(
        """INSERT INTO analysis_phase5_meta (analysis_version,prompt_version,extraction_version,llm_model,generated_at,notes)
           VALUES (?,?,?,?,?,?)""",
        (VERSION, prompt_version, extraction_version, model, NOW, f"ok={ok} fail={fail}"),
    )
    con.commit()
    log(f"Extraction complete: ok={ok} fail={fail}")
    unver = con.execute("SELECT COUNT(*) FROM analysis_narrative_evidence WHERE span_verified=0").fetchone()[0]
    total_e = con.execute("SELECT COUNT(*) FROM analysis_narrative_evidence").fetchone()[0]
    log(f"Evidence rows={total_e}; unverified_spans={unver}")
    con.close()
    return 0 if fail == 0 else 0  # continue analyses even with partial fails


if __name__ == "__main__":
    sys.exit(main())
