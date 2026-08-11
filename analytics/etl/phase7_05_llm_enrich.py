#!/usr/bin/env python3
"""Phase 7 LLM-v1 enrichment for remaining Phase 6 targeted hashes + reconciliation.

Requires plaintext CW_OPENAI_API_KEY in the process environment (never commit).
DigitalOcean EV[...] values are NOT decryptable locally — inject runtime plaintext.

If key unavailable, writes reconciliation from existing LLM reuse + heuristic scaled.
"""

from __future__ import annotations

import hashlib
import html
import json
import math
import os
import re
import sqlite3
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

import pandas as pd

ROOT = Path(__file__).resolve().parents[2]
DB = ROOT / "storage/analytics/egle_training_analytics.sqlite"
PROMPT_PATH = ROOT / "analytics/prompts/phase5_extract_v1.json"
CACHE_DIR = ROOT / "tmp/analytics/phase7_llm_cache"
VERSION = "phase7-v1"
NOW = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
EXTRACTOR = "phase7-extract-v1-llm"
PROMPT_VERSION = "phase5-extract-v1"
SCHEMA_VERSION = "phase5-schema-v1"


def log(msg: str) -> None:
    print(msg, flush=True)


def load_dotenv():
    env = ROOT / ".env"
    if not env.exists():
        return
    for line in env.read_text(errors="ignore").splitlines():
        if not line or line.strip().startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        k = k.strip()
        v = v.strip().strip('"').strip("'")
        if k and k not in os.environ:
            os.environ[k] = v


def clean(raw: str) -> str:
    t = html.unescape(raw or "")
    t = re.sub(r"<br\s*/?>", "\n", t, flags=re.I)
    t = re.sub(r"<[^>]+>", " ", t)
    return re.sub(r"[ \t]+", " ", t).strip()


def usable_key() -> str | None:
    k = os.environ.get("CW_OPENAI_API_KEY") or os.environ.get("OPENAI_API_KEY") or ""
    if k.startswith("sk-") and not k.startswith("EV["):
        return k
    return None


def openai_chat(api_key: str, model: str, system: str, user: str) -> str:
    payload = {
        "model": model,
        "messages": [
            {"role": "system", "content": system},
            {"role": "user", "content": user},
        ],
        "temperature": 0,
    }
    req = urllib.request.Request(
        "https://api.openai.com/v1/chat/completions",
        data=json.dumps(payload).encode(),
        headers={"Authorization": f"Bearer {api_key}", "Content-Type": "application/json"},
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=120) as resp:
        data = json.loads(resp.read().decode())
    return data["choices"][0]["message"]["content"]


def reconcile(con: sqlite3.Connection) -> None:
    log("Reconciling Phase 6 scale findings (heuristic vs LLM vs combined)...")
    con.execute("DELETE FROM phase7_llm_reconciliation WHERE analysis_version=?", (VERSION,))

    # Phase 6 extractions
    df = pd.read_sql_query(
        """
        SELECT e.*, s.grading_raw, s.student_id, s.program_id,
               CASE WHEN EXISTS(
                 SELECT 1 FROM analysis_phase6_narrative_evidence ev
                 WHERE ev.extraction_id=e.extraction_id AND ev.observation_polarity='DEFICIENCY'
               ) THEN 1 ELSE 0 END AS has_deficiency
        FROM analysis_phase6_narrative_extraction e
        JOIN fact_training_session s ON s.session_id=e.session_id
        """,
        con,
    )
    if df.empty:
        log("No phase6 extractions found")
        return

    df["strong"] = df.grading_raw.isin(["GC", "BC", "GI", "BI"])
    df["encouraging_def"] = df.overall_narrative_tone.isin(["POSITIVE", "MIXED"]) & (df.has_deficiency == 1)
    df["cons"] = df.consistency_class.isin(["VARIABLE", "DEVELOPING", "CONSISTENT"])
    df["ctx"] = df.context_tags_json.map(
        lambda x: bool(json.loads(x)) if isinstance(x, str) and x not in ("", "null", "[]") else False
    )

    def add(name, method, value, n, notes=""):
        con.execute(
            """INSERT INTO phase7_llm_reconciliation
            (finding_name,method,metric_value,n,notes,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?)""",
            (name, method, float(value) if value is not None else None, int(n), notes, VERSION, NOW),
        )

    for method, g in [
        ("llm_only", df[df.extractor == "LLM_V1_REUSED"]),
        ("heuristic_only", df[df.extractor.str.contains("heuristic", case=False, na=False)]),
        ("combined", df),
    ]:
        if g.empty:
            continue
        add("deficiency_rate", method, g.has_deficiency.mean(), len(g))
        add("encouraging_tone_with_deficiency_rate", method, g.encouraging_def.mean(), len(g))
        add("consistency_signal_rate", method, g.cons.mean(), len(g))
        add("context_signal_rate", method, g.ctx.mean(), len(g))
        strong = g[g.strong]
        add("high_grade_with_deficiency_rate", method, (strong.has_deficiency.mean() if len(strong) else 0), len(strong))

    # Early warning recomputation using phase6 patterns table + method split on signals
    # Recompute VARIABLE consistency → later problem for LLM subset vs heuristic subset
    sess = pd.read_sql_query(
        """
        SELECT session_id, student_id, program_id, session_date, grading_raw, grading_color
        FROM fact_training_session WHERE session_date_valid=1
        """,
        con,
    )
    reg = pd.read_sql_query(
        """
        SELECT session_id, MAX(exercise_regressed) has_regression, MAX(required_level_not_met) has_below
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

    def later_rate(session_ids: set[int], horizon=3):
        ep = hit = 0
        for _, g in sess.groupby(["student_id", "program_id"]):
            g = g.sort_values(["session_date", "session_id"])
            ids = g.session_id.tolist()
            probs = g.problem.tolist()
            for i, sid in enumerate(ids):
                fut = probs[i + 1 : i + 1 + horizon]
                if not fut:
                    continue
                if sid in session_ids:
                    ep += 1
                    if any(fut):
                        hit += 1
        return (hit / ep if ep else 0.0), ep

    # baseline
    all_ids = set(sess.session_id)
    # approximate baseline from phase6 table
    base_row = con.execute(
        "SELECT baseline_rate FROM analysis_phase6_early_warning_pattern WHERE pattern_code='CONSISTENCY_CONCERN' LIMIT 1"
    ).fetchone()
    baseline = float(base_row[0]) if base_row else 0.289

    for method, mask in [
        ("llm_only", df.extractor == "LLM_V1_REUSED"),
        ("heuristic_only", df.extractor.str.contains("heuristic", case=False, na=False)),
        ("combined", df.extractor.notna()),
    ]:
        g = df[mask]
        var_ids = set(g[g.consistency_class == "VARIABLE"].session_id.dropna().astype(int))
        rate, n = later_rate(var_ids)
        add("early_warning_consistency_variable_later_problem", method, rate, n, f"baseline≈{baseline:.3f}")

        hgd = set(g[g.strong & (g.has_deficiency == 1)].session_id.dropna().astype(int))
        rate, n = later_rate(hgd)
        add("early_warning_high_grade_deficiency_later_problem", method, rate, n, f"baseline≈{baseline:.3f}")

        # 3 of last 5 deficiency
        sig = g[["session_id", "student_id", "program_id", "has_deficiency"]].drop_duplicates("session_id")
        repeat = set()
        for _, gg in sig.sort_values("session_id").groupby(["student_id", "program_id"]):
            flags = gg.has_deficiency.astype(int).tolist()
            ids = gg.session_id.astype(int).tolist()
            for i in range(len(ids)):
                window = flags[max(0, i - 4) : i + 1]
                if sum(window) >= 3 and len(window) >= 3:
                    repeat.add(ids[i])
        rate, n = later_rate(repeat)
        add("early_warning_repeated_deficiency_window_later_problem", method, rate, n, f"baseline≈{baseline:.3f}")

    con.commit()
    log("Reconciliation written to phase7_llm_reconciliation")


def enrich_remaining(con: sqlite3.Connection, api_key: str, limit: int | None = None) -> int:
    """Process remaining hashes with OpenAI. Returns count processed."""
    CACHE_DIR.mkdir(parents=True, exist_ok=True)
    prompt_obj = json.loads(PROMPT_PATH.read_text()) if PROMPT_PATH.exists() else {}
    system = prompt_obj.get("system") or "Extract structured aviation training narrative evidence as JSON."
    model = os.environ.get("CW_OPENAI_MODEL") or "gpt-4.1-mini"

    done_hashes = set(
        r[0]
        for r in con.execute(
            """SELECT DISTINCT text_hash FROM analysis_narrative_extraction
               WHERE parse_status='OK' AND extraction_version IN ('phase5-extract-v1-agent', ?)""",
            (EXTRACTOR,),
        )
    )
    # Also skip phase6 LLM reused
    done_hashes |= set(r[0] for r in con.execute("SELECT DISTINCT text_hash FROM analysis_phase6_narrative_extraction WHERE extractor='LLM_V1_REUSED'"))

    pop = pd.read_sql_query(
        """
        SELECT p.narrative_id, p.session_id, p.text_hash, p.sample_bucket, n.raw_text, s.grading_raw
        FROM analysis_phase6_nlp_population p
        JOIN fact_narrative n ON n.narrative_id=p.narrative_id
        JOIN fact_training_session s ON s.session_id=p.session_id
        """,
        con,
    )
    todo = pop[~pop.text_hash.isin(done_hashes)].drop_duplicates("text_hash")
    if limit:
        todo = todo.head(limit)
    log(f"LLM enrich remaining unique hashes={len(todo)}")

    processed = 0
    for r in todo.itertuples():
        text = clean(r.raw_text)[:7000]
        cache_key = hashlib.sha1(f"{r.text_hash}|{PROMPT_VERSION}|{model}|{SCHEMA_VERSION}".encode()).hexdigest()
        cache_path = CACHE_DIR / f"{cache_key}.json"
        if cache_path.exists():
            content = cache_path.read_text()
        else:
            user = f"grading_raw={r.grading_raw}\n\nNARRATIVE:\n{text}\n\nReturn JSON only matching the schema."
            try:
                content = openai_chat(api_key, model, system, user)
            except Exception as e:
                log(f"API error narrative_id={r.narrative_id}: {e}")
                time.sleep(2)
                continue
            cache_path.write_text(content)
            time.sleep(0.15)
        # Store into phase6 extraction table as LLM for reconciliation population
        try:
            # strip fences
            body = content.strip()
            if body.startswith("```"):
                body = re.sub(r"^```(?:json)?\s*", "", body)
                body = re.sub(r"\s*```$", "", body)
            parsed = json.loads(body)
        except Exception:
            parsed = {"parse_error": True, "raw": content[:2000]}

        independence = "NOT_OBSERVED"
        assist = parsed.get("assistance_level", "UNKNOWN") if isinstance(parsed, dict) else "UNKNOWN"
        # map lightly
        if assist in ("TAKEOVER_OR_SAFETY_INTERVENTION", "PHYSICAL_INTERVENTION", "INSTRUCTOR_DEMONSTRATION", "STEP_BY_STEP_COACHING", "REPEATED_PROMPTS"):
            independence = "ASSISTED"
        elif assist in ("MINOR_PROMPT", "VERBAL_CONFIRMATION_ONLY"):
            independence = "PROMPTED"
        cons = parsed.get("consistency_class", "INSUFFICIENT_EVIDENCE") if isinstance(parsed, dict) else "INSUFFICIENT_EVIDENCE"
        cons_map = {
            "INSUFFICIENT_EVIDENCE": "NOT_ENOUGH_EVIDENCE",
            "INCONSISTENT": "VARIABLE",
            "VARIABLE": "VARIABLE",
            "MOSTLY_CONSISTENT": "DEVELOPING",
            "CONSISTENT": "CONSISTENT",
        }
        # Upsert into phase6 table with extractor mark
        con.execute(
            """INSERT OR REPLACE INTO analysis_phase6_narrative_extraction
            (narrative_id,session_id,text_hash,sample_bucket,extractor,prompt_version,schema_version,model,
             overall_narrative_tone,assistance_level,independence_mapped,consistency_class,accuracy_quality,
             learning_response,context_tags_json,context_effect,transfer_interpretation,summary_flags_json,
             raw_response_json,parse_status,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
            (
                int(r.narrative_id),
                int(r.session_id),
                r.text_hash,
                r.sample_bucket,
                EXTRACTOR,
                PROMPT_VERSION,
                SCHEMA_VERSION,
                model,
                parsed.get("overall_narrative_tone") if isinstance(parsed, dict) else None,
                assist,
                independence,
                cons_map.get(cons, cons),
                parsed.get("accuracy_quality") if isinstance(parsed, dict) else None,
                parsed.get("learning_response") if isinstance(parsed, dict) else None,
                json.dumps(parsed.get("context_tags") or []) if isinstance(parsed, dict) else "[]",
                parsed.get("context_effect") if isinstance(parsed, dict) else None,
                parsed.get("transfer_interpretation") if isinstance(parsed, dict) else None,
                json.dumps(parsed.get("flags") or {}) if isinstance(parsed, dict) else "{}",
                json.dumps(parsed, ensure_ascii=False) if isinstance(parsed, dict) else content[:5000],
                "OK" if isinstance(parsed, dict) and not parsed.get("parse_error") else "PARSE_ERROR",
                VERSION,
                NOW,
            ),
        )
        processed += 1
        if processed % 25 == 0:
            con.commit()
            log(f"  llm processed {processed}/{len(todo)}")
    con.commit()
    return processed


def main() -> None:
    load_dotenv()
    con = sqlite3.connect(DB)
    api_key = usable_key()
    processed = 0
    if api_key:
        log("Runtime OpenAI key available — enriching remaining targeted hashes...")
        processed = enrich_remaining(con, api_key)
    else:
        log("No usable OpenAI key — documenting injection requirement; reconciling existing LLM/heuristic only.")
        con.execute("DELETE FROM phase7_secret_injection_status")
        remaining = con.execute(
            """SELECT COUNT(*) FROM analysis_phase6_nlp_population p
               WHERE p.text_hash NOT IN (
                 SELECT text_hash FROM analysis_phase6_narrative_extraction WHERE extractor IN ('LLM_V1_REUSED', ?)
               )""",
            (EXTRACTOR,),
        ).fetchone()[0]
        done = con.execute(
            """SELECT COUNT(*) FROM analysis_phase6_narrative_extraction WHERE extractor IN ('LLM_V1_REUSED', ?)""",
            (EXTRACTOR,),
        ).fetchone()[0]
        con.execute(
            """INSERT INTO phase7_secret_injection_status
            (status,mechanism,llm_processed_n,llm_remaining_n,notes,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?)""",
            (
                "REQUIRES_SECRET_INJECTION",
                "Export plaintext CW_OPENAI_API_KEY from DigitalOcean App Platform runtime or Control Panel into the process environment (export CW_OPENAI_API_KEY=...). EV[...] in .env is DO ciphertext and cannot be decrypted by this repo. Do not commit plaintext.",
                int(done),
                int(remaining),
                "phase7_05 ready; run again after injection to process remaining targeted hashes only.",
                VERSION,
                NOW,
            ),
        )
        con.commit()

    reconcile(con)
    con.execute(
        "INSERT INTO phase7_meta (analysis_version,generated_at,notes) VALUES (?,?,?)",
        (VERSION, NOW, json.dumps({"llm_processed_this_run": processed, "key_present": bool(api_key)})),
    )
    con.commit()
    con.close()
    log(f"phase7_05 complete processed={processed}")


if __name__ == "__main__":
    main()
