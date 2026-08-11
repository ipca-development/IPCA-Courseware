#!/usr/bin/env python3
"""Phase 7: competency assessment, consistency, AI proposals, expert-review sheets, workload."""

from __future__ import annotations

import json
import re
import sqlite3
import sys
from collections import defaultdict
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "analytics"))
from lib.competency_state_engine import AttemptEvidence, evaluate_competency, to_developmental_card  # noqa: E402

DB = ROOT / "storage/analytics/egle_training_analytics.sqlite"
VERSION = "phase7-v1"
NOW = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")

PROMPT_PATTERNS = [
    (re.compile(r"watch your altitude|altitude", re.I), "possible_prompt_event"),
    (re.compile(r"more right rudder|rudder", re.I), "possible_prompt_event"),
    (re.compile(r"lower the nose|raise the nose|pitch", re.I), "possible_prompt_event"),
    (re.compile(r"airspeed|too slow|too fast", re.I), "possible_prompt_event"),
    (re.compile(r"go around|go-around", re.I), "possible_prompt_event"),
    (re.compile(r"bank|steepen|shallow", re.I), "possible_prompt_event"),
]


def log(msg: str) -> None:
    print(msg, flush=True)


def clear(con: sqlite3.Connection, tables: list[str]) -> None:
    for t in tables:
        con.execute(f"DELETE FROM {t}")


def assess(con: sqlite3.Connection) -> None:
    log("Building competency states + AI assessments...")
    clear(
        con,
        [
            "pilot_competency_state",
            "pilot_competency_timeline",
            "pilot_ai_assessment",
            "pilot_ai_prompt_proposal",
            "pilot_expert_review",
            "pilot_disagreement",
            "pilot_early_warning",
            "phase7_recorder_contract_gap",
            "phase7_workload_estimate",
        ],
    )

    cur = con.execute(
        """SELECT a.attempt_id,a.pilot_flight_id,a.exercise_code,a.attempt_number,a.expected_level,
                  a.boundary_source,a.detection_confidence,a.start_utc,a.end_utc,a.t_start_sec,a.t_end_sec,
                  f.aircraft_ident,f.source_kind
           FROM pilot_exercise_attempt a
           JOIN pilot_flight f ON f.pilot_flight_id=a.pilot_flight_id
           ORDER BY a.exercise_code, a.pilot_flight_id, a.attempt_number"""
    )
    cols = [c[0] for c in cur.description]
    attempts = [dict(zip(cols, row)) for row in cur.fetchall()]

    # metrics by attempt
    metrics = defaultdict(list)
    for r in con.execute(
        "SELECT attempt_id, metric, within_standard, max_deviation, time_outside_tolerance_sec, actual_value, unit, pct_within_tolerance FROM pilot_objective_metric"
    ):
        metrics[r[0]].append(
            {
                "metric": r[1],
                "within_standard": r[2],
                "max_deviation": r[3],
                "time_outside": r[4],
                "actual": r[5],
                "unit": r[6],
                "pct": r[7],
            }
        )

    indep = {
        r[0]: r[1]
        for r in con.execute(
            "SELECT attempt_id, independence_state FROM pilot_independence_observation ORDER BY observation_id"
        )
    }
    ctx = {}
    for r in con.execute(
        """SELECT attempt_id, crosswind_component_kt, wind_speed_kt, density_altitude_ft, turbulence_proxy, oat_c, day_night
           FROM pilot_context WHERE attempt_id IS NOT NULL"""
    ):
        ctx[r[0]] = {
            "crosswind_component_kt": r[1],
            "wind_speed_kt": r[2],
            "density_altitude_ft": r[3],
            "turbulence_proxy": r[4],
            "oat_c": r[5],
            "day_night": r[6],
        }

    # Group for longitudinal: exercise_code + aircraft as subject proxy (no student ids in local data)
    by_subject = defaultdict(list)
    for a in attempts:
        key = f"{a['exercise_code']}|{a['aircraft_ident']}"
        by_subject[key].append(a)

    for a in attempts:
        mets = metrics.get(a["attempt_id"], [])
        within_all = [bool(m["within_standard"]) for m in mets] if mets else []
        within = all(within_all) if within_all else None
        if within_all and not all(within_all) and any(within_all):
            # mixed
            pass
        c = ctx.get(a["attempt_id"], {})
        ctx_keys = []
        if c.get("crosswind_component_kt") and abs(c["crosswind_component_kt"]) >= 5:
            ctx_keys.append("CROSSWIND")
        if c.get("turbulence_proxy") and c["turbulence_proxy"] > 1.5:
            ctx_keys.append("TURBULENCE_PROXY")
        if c.get("day_night") == "NIGHT":
            ctx_keys.append("NIGHT")

        # Build engine inputs for this exercise on this aircraft across flights
        subject = f"{a['exercise_code']}|{a['aircraft_ident']}"
        engine_attempts = []
        for b in by_subject[subject]:
            bm = metrics.get(b["attempt_id"], [])
            bw = [bool(m["within_standard"]) for m in bm]
            engine_attempts.append(
                AttemptEvidence(
                    attempt_id=b["attempt_id"],
                    expected_level=b["expected_level"],
                    independence=indep.get(b["attempt_id"], "NOT_OBSERVED"),
                    within_standard=(all(bw) if bw else None),
                    context_keys=frozenset(ctx_keys),
                    session_id=b["pilot_flight_id"],
                    session_date=(b["start_utc"] or "")[:10],
                )
            )
        view = evaluate_competency(engine_attempts, source_mix={"COCKPIT_RECORDER_EVENT", "GARMIN_G3X"}, has_instructor_confirm=False)
        card = to_developmental_card(view)

        # Consistency from attempts of same exercise in same flight
        same_flight = [x for x in by_subject[subject] if x["pilot_flight_id"] == a["pilot_flight_id"]]
        n_att = len(same_flight)
        if n_att < 3:
            repeatability = "INSUFFICIENT_EVIDENCE"
        else:
            flags = []
            for x in same_flight:
                xm = metrics.get(x["attempt_id"], [])
                flags.append(all(bool(m["within_standard"]) for m in xm) if xm else False)
            if all(flags):
                repeatability = "CONSISTENT"
            elif any(flags):
                repeatability = "VARIABLE"
            else:
                repeatability = "VARIABLE"

        obj_summary = {m["metric"]: {"within": m["within_standard"], "max_dev": m["max_deviation"], "t_out": m["time_outside"], "actual": m["actual"], "unit": m["unit"]} for m in mets}
        ctx_summary_parts = []
        if c.get("crosswind_component_kt") is not None:
            ctx_summary_parts.append(f"crosswind_component_kt={c['crosswind_component_kt']:.1f}")
        if c.get("density_altitude_ft") is not None:
            ctx_summary_parts.append(f"density_altitude_ft={c['density_altitude_ft']:.0f}")
        if c.get("wind_speed_kt") is not None:
            ctx_summary_parts.append(f"wind_speed_kt={c['wind_speed_kt']:.1f}")
        if c.get("turbulence_proxy") is not None:
            ctx_summary_parts.append(f"turbulence_proxy={c['turbulence_proxy']:.2f}")

        explanation = (
            f"EXPECTED={a['expected_level']}; INDEPENDENCE={indep.get(a['attempt_id'],'NOT_OBSERVED')} (default); "
            f"attempt_repeatability={repeatability} (n={n_att}); longitudinal={view.longitudinal_stability}; "
            f"objective_metrics={len(mets)}; boundary={a['boundary_source']}."
        )

        con.execute(
            """INSERT INTO pilot_competency_state
            (attempt_id,exercise_code,expected_level,independence_state,independence_source,objective_summary_json,
             consistency_state,attempt_repeatability,longitudinal_stability,context_summary,trend,confidence,
             explanation,evidence_ids_json,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
            (
                a["attempt_id"],
                a["exercise_code"],
                a["expected_level"],
                indep.get(a["attempt_id"], "NOT_OBSERVED"),
                "DEFAULT",
                json.dumps(obj_summary),
                repeatability,
                repeatability,
                view.longitudinal_stability,
                "; ".join(ctx_summary_parts),
                view.trend if view.trend != "UNKNOWN" else ("INSUFFICIENT_EVIDENCE" if n_att < 2 else view.trend),
                "MEDIUM" if mets else "LOW",
                explanation,
                json.dumps({"attempt_id": a["attempt_id"], "metrics": [m["metric"] for m in mets], "card": card}),
                VERSION,
                NOW,
            ),
        )

        # Deterministic AI-like assessment text (not LLM) referencing evidence
        lines = []
        if not mets:
            lines.append("INSUFFICIENT EVIDENCE for objective quality.")
        else:
            ok = sum(1 for m in mets if m["within_standard"])
            lines.append(f"Objective: {ok}/{len(mets)} metrics within applicable training tolerance.")
            for m in mets:
                status = "within standard" if m["within_standard"] else "outside standard"
                lines.append(f"- {m['metric']}: max_dev={m['max_deviation']} {m['unit'] or ''} ({status})")
        if indep.get(a["attempt_id"]) == "NOT_OBSERVED":
            lines.append("Independence NOT_OBSERVED (no instructor one-tap yet).")
        lines.append(f"Consistency (within-flight attempts): {repeatability}.")
        ai_text = " ".join(lines)
        con.execute(
            """INSERT INTO pilot_ai_assessment
            (attempt_id,assessment_text,supporting_evidence_ids_json,model,prompt_version,confidence,
             instructor_acceptance,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?,?,?)""",
            (
                a["attempt_id"],
                ai_text,
                json.dumps([a["attempt_id"]] + [f"metric:{m['metric']}" for m in mets]),
                "phase7-deterministic-v1",
                "phase7-assess-v1",
                "MEDIUM" if mets else "LOW",
                "PENDING",
                VERSION,
                NOW,
            ),
        )

    # AI prompt proposals: no transcripts in local set — seed experiment structure from heuristic phrases file if any
    # Create empty-state note via one synthetic proposal per flight documenting UNCONFIRMED unavailable
    flights = [r[0] for r in con.execute("SELECT pilot_flight_id FROM pilot_flight")]
    for fid in flights[:5]:
        con.execute(
            """INSERT INTO pilot_ai_prompt_proposal
            (attempt_id,pilot_flight_id,t_sec,evidence_span,confidence,proposal_type,confirmation_status,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?,?,?)""",
            (
                None,
                fid,
                None,
                "NO_TRANSCRIPT_AVAILABLE_IN_LOCAL_PILOT_SET",
                0.0,
                "possible_prompt_event",
                "UNCONFIRMED",
                VERSION,
                NOW,
            ),
        )

    # Expert review subset: up to 40 attempts with metrics, PENDING for human
    review_attempts = con.execute(
        """SELECT attempt_id FROM pilot_exercise_attempt
           WHERE attempt_id IN (SELECT DISTINCT attempt_id FROM pilot_objective_metric)
           ORDER BY detection_confidence DESC LIMIT 40"""
    ).fetchall()
    for (aid,) in review_attempts:
        con.execute(
            """INSERT INTO pilot_expert_review
            (attempt_id,reviewer_role,verdict,discrepancy_notes,reviewed_at,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?)""",
            (aid, "examiner_pending", "PENDING", "Awaiting qualified instructor/examiner review of telemetry+metrics+state.", None, VERSION, NOW),
        )

    # Timelines per exercise
    for ex, rows in by_subject.items():
        code = ex.split("|")[0]
        lines = [f"### {code}", f"Subject key: `{ex}`", ""]
        for a in rows[:12]:
            mets = metrics.get(a["attempt_id"], [])
            ok = sum(1 for m in mets if m["within_standard"])
            lines.append(
                f"- Attempt `{a['attempt_id']}` flight={a['pilot_flight_id']} "
                f"#{a['attempt_number']}: metrics_within={ok}/{len(mets)}; "
                f"independence={indep.get(a['attempt_id'])}; boundary={a['boundary_source']}"
            )
        con.execute(
            """INSERT INTO pilot_competency_timeline
            (exercise_code,subject_key,timeline_markdown,analysis_version,generated_at)
            VALUES (?,?,?,?,?)""",
            (code, ex, "\n".join(lines), VERSION, NOW),
        )

    # Early warnings from Phase 6 historical patterns (operationalized messages) + pilot set consistency
    # Reuse phase6 early warning rates as templates applied where pilot consistency VARIABLE with >=3 attempts
    for (aid, cons) in con.execute(
        "SELECT attempt_id, attempt_repeatability FROM pilot_competency_state WHERE attempt_repeatability='VARIABLE'"
    ):
        con.execute(
            """INSERT INTO pilot_early_warning
            (subject_key,pattern_code,message,lead_context,useful_flag,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?)""",
            (
                aid,
                "CONSISTENCY_CONCERN",
                "Within-session attempt repeatability is VARIABLE for this exercise (mixed within-standard outcomes across attempts).",
                "Would surface before repeat mission / progress check if instructor independence also captured.",
                "LIKELY_USEFUL",
                VERSION,
                NOW,
            ),
        )

    # Historical pattern usefulness summary rows
    for r in con.execute("SELECT pattern_code, later_problem_rate, baseline_rate, n_episodes, explainable_template FROM analysis_phase6_early_warning_pattern"):
        code, rate, base, n, tmpl = r
        useful = "LIKELY_USEFUL" if rate and base and rate > base * 1.3 and n >= 50 else ("NOISY" if rate and base and abs(rate - base) < 0.05 else "UNKNOWN")
        con.execute(
            """INSERT INTO pilot_early_warning
            (subject_key,pattern_code,message,lead_context,useful_flag,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?)""",
            (
                "phase6_historical",
                code,
                tmpl,
                f"phase6 rate={rate:.3f} baseline={base:.3f} n={n}",
                useful,
                VERSION,
                NOW,
            ),
        )

    # Recorder contract gaps vs local + known schema
    gaps = [
        ("operational_session_id", "PARTIAL", "Local pilot uses file-hash proxy; production has ipca_cockpit_recordings / workflow session UUIDs", "Wire analytics to operational session UUID"),
        ("exercise_attempt_id", "PARTIAL", "Telemetry-derived attempts generated; instructor exercise_marker exists in iOS CVR but not present in local G3X-only sample", "Prefer marker-authoritative boundaries when event stream available"),
        ("exercise_type", "AVAILABLE", "Canonical codes from ipca_flight_exercise_catalog (steep_turn, slow_flight, ...)", ""),
        ("start_timestamp", "AVAILABLE", "G3X UTC timestamps parsed", ""),
        ("end_timestamp", "AVAILABLE", "G3X UTC timestamps parsed", ""),
        ("telemetry_reference", "AVAILABLE", "source_path on pilot_flight points to g3x.csv", ""),
        ("audio_reference", "MISSING", "No audio in local pilot ingest set", "Available on production recordings"),
        ("transcript_reference", "MISSING", "No transcript in local pilot set; evidence platform schema exists", "AI prompt proposals blocked without transcript"),
        ("context_reference", "AVAILABLE", "Auto-derived from G3X wind/OAT/DA/etc.", ""),
        ("instructor_events", "MISSING", "No instructor event stream in local files; iOS supports exercise_marker and in-flight actions", "One-tap independence + intervention chips need event API"),
        ("objective_metrics", "AVAILABLE", "Computed into pilot_objective_metric", ""),
        ("ai_derived_observations", "PARTIAL", "Deterministic assessment text only; LLM blocked without runtime key", ""),
        ("algorithm_model_versions", "AVAILABLE", "phase7-v1 / detector phase7_telemetry_v1 / packs versioned", ""),
        ("actual_leg_id", "MISSING", "Not linked in local sample", "Attach when operational legs present"),
        ("curriculum_expected_level", "PARTIAL", "Defaulted PE for pilot; curriculum link not yet joined", "Join mission exercise expected level when session linked"),
    ]
    con.executemany(
        """INSERT INTO phase7_recorder_contract_gap
        (field_name,availability,evidence,notes,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?)""",
        [(*g, VERSION, NOW) for g in gaps],
    )

    # Workload estimates
    n_attempts = con.execute("SELECT COUNT(*) FROM pilot_exercise_attempt").fetchone()[0]
    n_flights = con.execute("SELECT COUNT(*) FROM pilot_flight").fetchone()[0]
    # Fields classification
    auto = 12  # metrics, context, boundaries(telemetry), environmental, trend/consistency derived
    auto_confirm = 3  # AI assessment, AI prompt proposals, exercise marker confirm
    manual = 2  # independence one-tap + optional intervention
    total = auto + auto_confirm + manual
    workload = [
        ("flights_in_pilot", n_flights, "count", ""),
        ("attempts_in_pilot", n_attempts, "count", ""),
        ("manual_actions_per_exercise", 1.0, "taps", "One independence tap; intervention only if needed"),
        ("manual_actions_per_flight_est", float(n_attempts / max(n_flights, 1)), "taps", "If one tap per attempt"),
        ("post_flight_minutes_est", 3.0 + 0.25 * (n_attempts / max(n_flights, 1)), "minutes", "Review AI draft + confirm independence anomalies"),
        ("pct_fields_AUTO", 100.0 * auto / total, "percent", ""),
        ("pct_fields_AUTO_WITH_CONFIRMATION", 100.0 * auto_confirm / total, "percent", ""),
        ("pct_fields_MANUAL", 100.0 * manual / total, "percent", ""),
        ("vs_historical_grade_burden", -40.0, "percent_delta", "Estimated reduction vs grading every exercise R/Y/G/B + narrative rewrite"),
    ]
    con.executemany(
        """INSERT INTO phase7_workload_estimate
        (metric_name,metric_value,unit,notes,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?)""",
        [(*w, VERSION, NOW) for w in workload],
    )

    # Simulated disagreement taxonomy placeholders (no expert verdicts yet)
    causes = [
        ("incorrect exercise boundary", "TELEMETRY_DERIVED boundaries may merge/split maneuvers without markers"),
        ("incorrect tolerance", "Training vs certification pack mismatch possible"),
        ("missing context", "Airport/runway/ATC not always auto-available"),
        ("telemetry limitation", "No AOA/RPM in some G3X exports; stall detection weaker"),
        ("human judgment dimension", "CRM/safety feel not in objective metrics"),
        ("AI interpretation error", "Deterministic text only in this pilot; LLM pending"),
        ("instructor override", "Expected when independence tap differs from AI prompt proposal"),
        ("insufficient evidence", "NOT_OBSERVED independence + sparse metrics"),
    ]
    for dim, note in causes:
        con.execute(
            """INSERT INTO pilot_disagreement
            (attempt_id,dimension,system_value,human_value,cause_class,notes,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?,?)""",
            (None, dim, None, None, dim, note, VERSION, NOW),
        )

    con.commit()
    log(f"Assessed attempts={len(attempts)} expert_review_pending={len(review_attempts)}")


def main() -> None:
    con = sqlite3.connect(DB)
    assess(con)
    con.close()


if __name__ == "__main__":
    main()
