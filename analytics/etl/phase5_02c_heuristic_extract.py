#!/usr/bin/env python3
"""Deterministic linguistic fallback extractor for Phase 5 (same schema).

Used if LLM API/agent batches are unavailable. Evidence spans are always
taken from matched narrative sentences. Marked llm_model=heuristic-v1.
"""

from __future__ import annotations

import html
import json
import re
import sqlite3
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DB = ROOT / "storage/analytics/egle_training_analytics.sqlite"
VERSION = "phase5-v1"
EXTRACTION_VERSION = "phase5-extract-v1-heuristic"
PROMPT_VERSION = "phase5-heuristic-v1"
MODEL = "heuristic-v1"
NOW = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")

ASSIST_PATTERNS = [
    (re.compile(r"\b(took\s+over|take\s*over|intervened|safety\s+intervention|overname|overgenomen)\b", re.I), "TAKEOVER_OR_SAFETY_INTERVENTION"),
    (re.compile(r"\b(physical(ly)?\s+(helped|assisted|interven)|on\s+the\s+controls|controls?\s+taken)\b", re.I), "PHYSICAL_INTERVENTION"),
    (re.compile(r"\b((I|instructor)\s+(demo(nstrat(ed|ion))?|showed|flew)|instructor\s+demonstration|voorgedaan|gedemonstreerd\s+door\s+instruct)\b", re.I), "INSTRUCTOR_DEMONSTRATION"),
    (re.compile(r"\b(step[-\s]?by[-\s]?step|coached|coaching|guided\s+through|walked\s+through|hand[\s-]?hold)\b", re.I), "STEP_BY_STEP_COACHING"),
    (re.compile(r"\b(repeated\s+prompts?|several\s+prompts?|multiple\s+reminders?|had\s+to\s+prompt\s+again|kept\s+prompting|veel\s+prompts?)\b", re.I), "REPEATED_PROMPTS"),
    (re.compile(
        r"\b(prompt(ed|ing)?|remind(ed|er)?|hint(ed)?|cue(d)?|nudge|needed\s+help|with\s+help|assisted|assistance|"
        r"guidance|guided|I\s+had\s+to\s+(tell|say|correct|help)|needed\s+guidance|help\s+from\s+instructor|"
        r"hulp\s+nodig|bijgestuurd|gecorrigeerd|aanwijzing(en)?|geholpen)\b",
        re.I,
    ), "MINOR_PROMPT"),
    (re.compile(r"\b(confirm(ed|ation)|verbal\s+check|asked\s+to\s+confirm)\b", re.I), "VERBAL_CONFIRMATION_ONLY"),
]

CONS_POS = re.compile(r"\b(consistent(ly)?|stable|repeatable|solid|every\s+time|throughout|steeds|stabiel|constantly)\b", re.I)
CONS_NEG = re.compile(
    r"\b(inconsistent|variable|sometimes|occasionally|not\s+always|once|still\s+needs|ups?\s+and\s+downs?|"
    r"again|same\s+issue|keeps?\s+(happening|coming)|wisselvallig|soms|opnieuw|nog\s+steeds)\b",
    re.I,
)
LEARN_POS = re.compile(
    r"\b(improved|improvement|better\s+on\s+(the\s+)?(2nd|second|next)|quick(ly)?\s+corrected|"
    r"after\s+(explanation|coaching)|learned|progress|getting\s+better|verbeterd|vooruitgang)\b",
    re.I,
)
LEARN_NEG = re.compile(r"\b(no\s+improvement|same\s+problem|persisted|still\s+struggling|did\s+not\s+improve|geen\s+verbetering)\b", re.I)
DEF = re.compile(
    r"\b(need[s]?\s+to|must\s+improve|incorrect|unstable|poor|weak|problem|issue|unable|failed|missed|forgot|"
    r"too\s+(high|low|fast|slow)|outside|exceed|below|deficient|unsafe|work\s+on|focus\s+on|attention\s+to|"
    r"niet\s+goed|onstabiel|te\s+(hoog|laag|snel|traag)|fout|probleem)\b",
    re.I,
)
POS = re.compile(
    r"\b(excellent|great|good|well\s+done|nice|solid|accurate|precise|independent|without\s+help|"
    r"standard\s+met|within\s+limits?|perfect|strong|goed\s+gedaan|prima|uitstekend|zelfstandig)\b",
    re.I,
)
INDEP = re.compile(r"\b(independent(ly)?|without\s+(help|assistance|prompt)|unassisted|on\s+(his|her|their)\s+own|zelfstandig)\b", re.I)

CONTEXT_MAP = [
    (re.compile(r"\bcrosswind\b", re.I), "CROSSWIND"),
    (re.compile(r"\bgust", re.I), "GUSTS"),
    (re.compile(r"\bturbulen", re.I), "TURBULENCE"),
    (re.compile(r"\bwind\b", re.I), "WIND"),
    (re.compile(r"\btraffic\b", re.I), "TRAFFIC"),
    (re.compile(r"\bATC\b|\bradio\s+congest", re.I), "ATC_WORKLOAD"),
    (re.compile(r"\bhigh\s+workload|busy\b", re.I), "HIGH_WORKLOAD"),
    (re.compile(r"\bunfamiliar\s+airport|new\s+airport", re.I), "UNFAMILIAR_AIRPORT"),
    (re.compile(r"\bdifferent\s+airport|another\s+airport", re.I), "DIFFERENT_AIRPORT"),
    (re.compile(r"\bdifferent\s+aircraft|other\s+aircraft|new\s+type", re.I), "DIFFERENT_AIRCRAFT"),
    (re.compile(r"\bIFR\b|instrument|hood|simulated\s+IMC", re.I), "INSTRUMENT_CONDITIONS_OR_SIMULATED_IFR"),
    (re.compile(r"\bemergenc|abnormal|engine\s+fail|fire\b", re.I), "EMERGENCY_OR_ABNORMAL_SCENARIO"),
    (re.compile(r"\bprogress\s+check|checkride|skill\s+test|exam\b", re.I), "CHECK_OR_EVALUATION_ENVIRONMENT"),
    (re.compile(r"\bfatigue|tired|stress", re.I), "FATIGUE_OR_HUMAN_FACTORS"),
]

DIM_MAP = [
    (re.compile(r"\bchecklist|SOP|procedure\s+flow|memory\s+item", re.I), ["SOP_CHECKLIST_DISCIPLINE", "PROCEDURAL_EXECUTION"]),
    (re.compile(r"\baltitude|airspeed|heading|bank|tolerance|within\s*\d+|±", re.I), ["ACCURACY_TOLERANCE", "TECHNICAL_CONTROL"]),
    (re.compile(r"\bflare|landing|touchdown|roundout|pitch|roll|yaw|rudder|aileron", re.I), ["TECHNICAL_CONTROL"]),
    (re.compile(r"\bradio|ATC|callsign|readback|communication", re.I), ["COMMUNICATION_RADIO"]),
    (re.compile(r"\btraffic|scan|lookout|situational|SA\b|aware", re.I), ["SITUATIONAL_AWARENESS"]),
    (re.compile(r"\bdecision|divert|go[\s-]?around|chose|judgment|TEM|threat", re.I), ["DECISION_MAKING"]),
    (re.compile(r"\bworkload|task\s+saturat|priorit", re.I), ["WORKLOAD_MANAGEMENT"]),
    (re.compile(r"\bknowledge|understand|explain|theory|why\b", re.I), ["KNOWLEDGE_UNDERSTANDING"]),
    (re.compile(r"\bprompt|assist|coach|help|interven|demo", re.I), ["INSTRUCTOR_ASSISTANCE", "INDEPENDENCE"]),
    (re.compile(r"\bconsistent|inconsistent|variable|stable", re.I), ["CONSISTENCY"]),
    (re.compile(r"\bimprov|learning|corrected|better", re.I), ["LEARNING_RESPONSE_IMPROVEMENT"]),
    (re.compile(r"\bsafety|margin|unsafe|go[\s-]?around", re.I), ["SAFETY_MARGIN"]),
    (re.compile(r"\btransfer|under\s+(wind|stress|pressure)|in\s+crosswind|busy", re.I), ["TRANSFER_ADAPTABILITY"]),
]

MEAS = re.compile(
    r"(altitude|airspeed|heading|bank|path|centerline).{0,40}?(\d+\s*(ft|feet|kt|knots|deg|degrees|°)|\+\-?\s*\d+|±\s*\d+)",
    re.I,
)


def clean(raw: str) -> str:
    t = html.unescape(raw or "")
    t = re.sub(r"<br\s*/?>", "\n", t, flags=re.I)
    t = re.sub(r"<[^>]+>", " ", t)
    t = re.sub(r"[ \t]+", " ", t)
    return re.sub(r"\n{3,}", "\n\n", t).strip()


def sentences(text: str) -> list[str]:
    parts = re.split(r"(?<=[\.\!\?\n])\s+", text)
    return [p.strip() for p in parts if len(p.strip()) >= 12]


def extract_one(text: str, grading_raw: str | None) -> dict:
    sents = sentences(text)
    observations = []
    for s in sents:
        dims = []
        for rx, dlist in DIM_MAP:
            if rx.search(s):
                dims.extend(dlist)
        dims = sorted(set(dims))
        if not dims:
            continue
        if DEF.search(s) and not (POS.search(s) and not DEF.search(s)):
            pol = "DEFICIENCY"
            sev = "MEDIUM"
        elif POS.search(s):
            pol = "POSITIVE"
            sev = "LOW"
        else:
            pol = "NEUTRAL_CONTEXT"
            sev = "UNKNOWN"
        # skip pure advice without performance claim if no pos/def
        if pol == "NEUTRAL_CONTEXT" and not any(k in s.lower() for k in ["wind", "traffic", "airport", "ifr", "check", "busy"]):
            if not any(d in dims for d in ["INSTRUCTOR_ASSISTANCE", "CONSISTENCY", "TRANSFER_ADAPTABILITY"]):
                continue
        observations.append(
            {
                "evidence_span": s[:300],
                "polarity": pol,
                "interpretation": f"Narrative states performance/context related to {', '.join(dims[:3])}",
                "dimensions": dims[:4],
                "severity": sev,
                "confidence": "MEDIUM",
            }
        )
        if len(observations) >= 12:
            break

    assist = "NONE_OBSERVED"
    assist_span = None
    for rx, level in ASSIST_PATTERNS:
        m = rx.search(text)
        if m:
            assist = level
            # find sentence
            for s in sents:
                if rx.search(s):
                    assist_span = s
                    break
            break
    if assist == "NONE_OBSERVED" and INDEP.search(text):
        assist = "NONE_OBSERVED"

    if CONS_NEG.search(text) and CONS_POS.search(text):
        cons = "VARIABLE"
    elif CONS_NEG.search(text):
        cons = "INCONSISTENT" if re.search(r"inconsistent", text, re.I) else "VARIABLE"
    elif CONS_POS.search(text):
        cons = "CONSISTENT" if re.search(r"consistent", text, re.I) else "MOSTLY_CONSISTENT"
    else:
        cons = "INSUFFICIENT_EVIDENCE"

    if LEARN_NEG.search(text):
        learn = "NO_IMPROVEMENT"
    elif LEARN_POS.search(text) and re.search(r"quick|rapid|immediately", text, re.I):
        learn = "RAPID_IMPROVEMENT"
    elif LEARN_POS.search(text):
        learn = "IMPROVEMENT"
    else:
        learn = "UNKNOWN"

    ctx = []
    for rx, tag in CONTEXT_MAP:
        if rx.search(text):
            ctx.append(tag)
    ctx = sorted(set(ctx))

    if ctx and DEF.search(text):
        ctx_effect = "DEGRADED_UNDER_CONTEXT"
        transfer = "CONTEXTUAL_TRANSFER_DIFFICULTY_LIKELY"
    elif ctx and POS.search(text):
        ctx_effect = "STABLE_DESPITE_CONTEXT"
        transfer = "NOT_APPLICABLE"
    elif ctx:
        ctx_effect = "INSUFFICIENT_EVIDENCE"
        transfer = "AMBIGUOUS"
    else:
        ctx_effect = "NOT_APPLICABLE"
        transfer = "NOT_APPLICABLE"

    if assist in ("REPEATED_PROMPTS", "STEP_BY_STEP_COACHING") and ctx:
        ctx_effect = "CONTEXT_REQUIRED_ASSISTANCE"

    # accuracy
    if re.search(r"within\s+(limits?|standard|tolerance)|on\s+speed|stable\s+approach", text, re.I):
        acc = "WITHIN_STANDARD"
    elif re.search(r"slightly|minor\s+deviation|a\s+bit\s+(high|low|fast|slow)", text, re.I):
        acc = "MINOR_DEVIATION"
    elif re.search(r"outside|well\s+(above|below)|significantly|unstable", text, re.I):
        acc = "OUTSIDE_STANDARD"
    elif DEF.search(text) and re.search(r"altitude|airspeed|heading|path", text, re.I):
        acc = "MATERIAL_DEVIATION"
    else:
        acc = "UNKNOWN"

    ndef = sum(1 for o in observations if o["polarity"] == "DEFICIENCY")
    npos = sum(1 for o in observations if o["polarity"] == "POSITIVE")
    tone = "MIXED"
    if npos and not ndef:
        tone = "POSITIVE"
    elif ndef and not npos:
        tone = "CRITICAL"
    elif not observations:
        tone = "NEUTRAL"

    missing = []
    if assist in ("STEP_BY_STEP_COACHING", "REPEATED_PROMPTS"):
        missing.append("NEEDS_OCCASIONAL_PROMPTING" if assist == "REPEATED_PROMPTS" else "NEEDS_CONTINUOUS_ASSISTANCE")
    if assist == "NONE_OBSERVED" and cons in ("VARIABLE", "INCONSISTENT"):
        missing.append("INDEPENDENT_BUT_INCONSISTENT")
    if assist == "NONE_OBSERVED" and acc == "WITHIN_STANDARD":
        missing.append("INDEPENDENT_WITHIN_TOLERANCE")
    if cons in ("CONSISTENT", "MOSTLY_CONSISTENT"):
        missing.append("PERFORMS_CONSISTENTLY")
    if transfer == "CONTEXTUAL_TRANSFER_DIFFICULTY_LIKELY":
        missing.append("ACCURATE_ONLY_IN_FAMILIAR_CONTEXT")
    if ctx_effect == "STABLE_DESPITE_CONTEXT":
        missing.append("TRANSFERS_TO_CHANGED_CONTEXT")

    meas = []
    for m in MEAS.finditer(text):
        meas.append({"metric": m.group(1), "value_text": m.group(2), "unit_or_note": "explicit in narrative"})

    strong = (grading_raw or "") in ("GC", "BC", "GI", "BI")
    flags = {
        "encouraging_tone_with_deficiency": bool(tone in ("POSITIVE", "MIXED") and ndef > 0),
        "high_grade_with_assistance_signal": bool(strong and assist not in ("NONE_OBSERVED", "UNKNOWN", "VERBAL_CONFIRMATION_ONLY")),
        "high_grade_with_inconsistency_signal": bool(strong and cons in ("VARIABLE", "INCONSISTENT")),
        "narrative_silent_on_performance": bool(len(observations) == 0),
    }

    if assist_span and not any(o["evidence_span"] == assist_span for o in observations):
        observations.insert(
            0,
            {
                "evidence_span": assist_span[:300],
                "polarity": "NEUTRAL_CONTEXT",
                "interpretation": "Instructor assistance language present",
                "dimensions": ["INSTRUCTOR_ASSISTANCE", "INDEPENDENCE"],
                "severity": "MEDIUM",
                "confidence": "MEDIUM",
            },
        )

    return {
        "overall_narrative_tone": tone,
        "observations": observations,
        "assistance_level": assist,
        "assistance_reason": "matched assistance language" if assist != "NONE_OBSERVED" else "",
        "assistance_context": ", ".join(ctx[:3]),
        "assistance_improved_after": "YES" if assist != "NONE_OBSERVED" and learn in ("IMPROVEMENT", "RAPID_IMPROVEMENT") else ("UNKNOWN" if assist != "NONE_OBSERVED" else "NOT_APPLICABLE"),
        "consistency_class": cons,
        "learning_response": learn,
        "accuracy_quality": acc,
        "context_tags": ctx,
        "context_effect": ctx_effect,
        "transfer_interpretation": transfer,
        "missing_middle_states": sorted(set(missing)),
        "measurable_deviations": meas[:5],
        "flags": flags,
    }


def main():
    con = sqlite3.connect(DB)
    con.row_factory = sqlite3.Row
    rows = con.execute("SELECT * FROM analysis_narrative_sample_enriched ORDER BY narrative_id").fetchall()
    con.execute("DELETE FROM analysis_narrative_evidence WHERE extraction_version=?", (EXTRACTION_VERSION,))
    con.execute("DELETE FROM analysis_narrative_extraction WHERE extraction_version=?", (EXTRACTION_VERSION,))
    ok = 0
    for row in rows:
        text = clean(row["raw_text"])
        p = extract_one(text, row["grading_raw"])
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
                p["overall_narrative_tone"],
                p["assistance_level"],
                p["assistance_reason"],
                p["assistance_context"],
                p["assistance_improved_after"],
                p["consistency_class"],
                p["learning_response"],
                p["accuracy_quality"],
                json.dumps(p["context_tags"]),
                p["context_effect"],
                p["transfer_interpretation"],
                json.dumps(p["missing_middle_states"]),
                json.dumps(p["measurable_deviations"]),
                json.dumps(p["flags"]),
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
        for obs in p["observations"]:
            con.execute(
                """INSERT INTO analysis_narrative_evidence
                (extraction_id,narrative_id,text_hash,evidence_span,observation_polarity,interpretation,
                 competency_dimensions_json,severity,confidence,span_verified,llm_model,prompt_version,
                 extraction_version,analysis_version,generated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
                (
                    eid,
                    row["narrative_id"],
                    row["text_hash"],
                    obs["evidence_span"],
                    obs["polarity"],
                    obs["interpretation"],
                    json.dumps(obs["dimensions"]),
                    obs["severity"],
                    obs["confidence"],
                    1,
                    MODEL,
                    PROMPT_VERSION,
                    EXTRACTION_VERSION,
                    VERSION,
                    NOW,
                ),
            )
        ok += 1
    con.execute("DELETE FROM analysis_phase5_meta")
    con.execute(
        """INSERT INTO analysis_phase5_meta (analysis_version,prompt_version,extraction_version,llm_model,generated_at,notes)
           VALUES (?,?,?,?,?,?)""",
        (VERSION, PROMPT_VERSION, EXTRACTION_VERSION, MODEL, NOW, f"heuristic ok={ok}"),
    )
    con.commit()
    print(f"Heuristic extraction complete ok={ok}", flush=True)
    con.close()


if __name__ == "__main__":
    main()
