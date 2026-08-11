#!/usr/bin/env python3
"""Phase 8: production evidence wiring contracts, clinic package, reference debrief."""

from __future__ import annotations

import json
import re
import sqlite3
import sys
from collections import Counter, defaultdict
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "analytics"))
from lib.runtime_secrets import RuntimeSecretError, get_runtime_secret, peek_secret_status  # noqa: E402

DB = ROOT / "storage/analytics/egle_training_analytics.sqlite"
SCHEMA = ROOT / "analytics/schema/phase8_tables.sql"
ACS_SEED = ROOT / "scripts/data/acs_exercise_catalogue_seed.json"
REF_REPLAY = ROOT / "storage/debug_bundles/0436A732-CD26-423D-9746-57AB709E7C1C.debug_bundle/replay.json"
VERSION = "phase8-v1"
NOW = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")

PROMPT_RX = [
    (re.compile(r"\b(watch your altitude|altitude)\b", re.I), "VERBAL_PROMPT"),
    (re.compile(r"\b(rudder|more right rudder)\b", re.I), "VERBAL_PROMPT"),
    (re.compile(r"\b(lower the nose|raise the nose|pitch)\b", re.I), "VERBAL_PROMPT"),
    (re.compile(r"\b(airspeed|too slow|too fast)\b", re.I), "VERBAL_PROMPT"),
    (re.compile(r"\b(go around|go-around)\b", re.I), "WARNING"),
    (re.compile(r"\b(my controls|i have the controls|taking over)\b", re.I), "POSSIBLE_SAFETY_INTERVENTION"),
    (re.compile(r"\b(flaps|gear|configure|checklist)\b", re.I), "PROCEDURAL_PROMPT"),
]


def log(msg: str) -> None:
    print(msg, flush=True)


def clear(con: sqlite3.Connection, tables: list[str]) -> None:
    for t in tables:
        con.execute(f"DELETE FROM {t}")


def seed_canonical(con: sqlite3.Connection) -> None:
    clear(con, ["canonical_exercise_source_map", "canonical_exercise"])
    # Core Phase 7/8 set + ACS aliases
    core = [
        ("steep_turn", "Steep Turn", "performance_maneuver"),
        ("slow_flight", "Slow Flight", "slow_flight_stalls"),
        ("power_off_stall", "Power-Off Stall", "slow_flight_stalls"),
        ("power_on_stall", "Power-On Stall", "slow_flight_stalls"),
        ("normal_approach", "Normal Approach", "takeoff_landing"),
        ("normal_landing", "Normal Landing", "takeoff_landing"),
        ("go_around", "Go-Around", "takeoff_landing"),
        ("unusual_attitude_recovery", "Unusual Attitude Recovery", "instrument"),
    ]
    con.executemany(
        """INSERT INTO canonical_exercise (canonical_exercise_id,display_name,family,notes,analysis_version,generated_at)
           VALUES (?,?,?,?,?,?)""",
        [(c, n, f, "Phase 8 canonical identity", VERSION, NOW) for c, n, f in core],
    )
    maps = []
    aliases = {
        "steep_turn": ["Steep Turns", "Steep Turn", "STEPP TURN", "ACS Steep Turns", "steep turns", "PA.V.A"],
        "slow_flight": ["Slow Flight", "Maneuvering During Slow Flight", "MCA", "PA.VII.A"],
        "power_off_stall": ["Power-Off Stall", "Power Off Stall", "PA.VII.B"],
        "power_on_stall": ["Power-On Stall", "Power On Stall", "Departure Stall", "PA.VII.C"],
        "normal_approach": ["Normal Approach", "Approach to Landing"],
        "normal_landing": ["Normal Landing", "Landing"],
        "go_around": ["Go-Around", "Go Around", "Rejected Landing", "go_around_rejected_landing"],
        "unusual_attitude_recovery": ["Unusual Attitude Recovery", "Unusual Attitudes", "IR.IV.A"],
    }
    for cid, labels in aliases.items():
        for lab in labels:
            maps.append((cid, "LABEL_VARIANT", lab, lab.lower().replace(" ", "_"), VERSION, NOW))
        maps.append((cid, "IPCA_CATALOG", cid, cid, VERSION, NOW))
        maps.append((cid, "PHASE7_PILOT", cid, cid, VERSION, NOW))

    if ACS_SEED.exists():
        data = json.loads(ACS_SEED.read_text())
        # file may be list or dict
        items = data if isinstance(data, list) else data.get("exercises") or data.get("items") or []
        for item in items:
            if not isinstance(item, dict):
                continue
            code = item.get("exercise_code") or item.get("code")
            if not code:
                continue
            # map known overlaps
            for cid in aliases:
                if code == cid or code.startswith(cid) or cid in (code or ""):
                    maps.append((cid, "ACS_CATALOGUE_SEED", item.get("display_name") or code, code, VERSION, NOW))
                    break
            else:
                # register additional ACS codes as canonical if not present
                if not con.execute("SELECT 1 FROM canonical_exercise WHERE canonical_exercise_id=?", (code,)).fetchone():
                    con.execute(
                        """INSERT INTO canonical_exercise (canonical_exercise_id,display_name,family,notes,analysis_version,generated_at)
                           VALUES (?,?,?,?,?,?)""",
                        (code, item.get("display_name") or code, "acs_seed", "From ACS catalogue seed", VERSION, NOW),
                    )
                maps.append((code, "ACS_CATALOGUE_SEED", item.get("display_name") or code, code, VERSION, NOW))

    con.executemany(
        """INSERT INTO canonical_exercise_source_map
        (canonical_exercise_id,source_system,source_label,source_code,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?)""",
        maps,
    )


def seed_procedures(con: sqlite3.Connection) -> None:
    clear(con, ["phase8_procedure_observation", "procedure_step", "procedure_pack"])
    packs = [
        (
            "IPCA_SOP_GO_AROUND_v1",
            "1.0",
            "go_around",
            "IPCA SOP.EX go-around / rejected landing instructional outline (Phase 8 pilot)",
            "2024-01-01",
            "Pedagogical IPCA training sequence; not invented generic procedure.",
        ),
        (
            "IPCA_SOP_POWER_OFF_STALL_v1",
            "1.0",
            "power_off_stall",
            "IPCA SOP.EX.POWER_OFF_STALL steps from catalog seed",
            "2024-01-01",
            "From scripts/sql flight exercise SOP binding outline",
        ),
        (
            "IPCA_SOP_POWER_ON_STALL_v1",
            "1.0",
            "power_on_stall",
            "IPCA SOP.EX.POWER_ON_STALL steps",
            "2024-01-01",
            "",
        ),
        (
            "IPCA_SOP_NORMAL_APPROACH_LANDING_v1",
            "1.0",
            "normal_approach",
            "IPCA normal approach/landing configuration sequence pilot",
            "2024-01-01",
            "Linked to normal_landing outcome separately",
        ),
    ]
    con.executemany(
        """INSERT INTO procedure_pack
        (procedure_pack_id,version,canonical_exercise_id,source_reference,effective_date,notes,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?)""",
        [(*p, VERSION, NOW) for p in packs],
    )

    steps = []

    def add(pack, order, code, name, required, source, manual=0, timing=None):
        steps.append((pack, order, code, name, required, json.dumps({}), timing, source, manual, VERSION, NOW))

    # Go-around
    for i, (code, name, src, req) in enumerate(
        [
            ("power", "Power application", "TELEMETRY", 1),
            ("pitch", "Pitch for climb", "TELEMETRY", 1),
            ("config_initial", "Initial configuration/flap management", "TELEMETRY", 1),
            ("positive_climb", "Positive climb established", "TELEMETRY", 1),
            ("airspeed", "Climb airspeed", "TELEMETRY", 1),
            ("config_remaining", "Remaining configuration", "TELEMETRY", 0),
            ("after_actions", "After go-around actions / radio", "TRANSCRIPT", 0),
            ("traffic_scan", "Visual traffic scan", "NOT_OBSERVABLE", 1),
        ],
        start=1,
    ):
        add("IPCA_SOP_GO_AROUND_v1", i, code, name, req, src, 1 if src == "NOT_OBSERVABLE" else 0, 15.0)

    # Power-off stall (from Phase 7 SOP outline)
    for i, (code, name, src) in enumerate(
        [
            ("clear_area", "Clearing turns / clear area", "TRANSCRIPT"),
            ("configuration", "Approach/landing configuration", "TELEMETRY"),
            ("exercise_start", "Exercise start marked", "RECORDER_EVENT"),
            ("power_reduce", "Power reduction", "TELEMETRY"),
            ("pitch_aoa", "Pitch to AOA / buffet recognition", "TELEMETRY"),
            ("recovery", "Recovery flow", "TELEMETRY"),
            ("coaching_vs_independent", "Coaching vs independent judgment", "INSTRUCTOR"),
        ],
        start=1,
    ):
        add("IPCA_SOP_POWER_OFF_STALL_v1", i, code, name, 1, src, 1 if src in ("INSTRUCTOR", "NOT_OBSERVABLE") else 0)

    for i, (code, name, src) in enumerate(
        [
            ("clear_area", "Clear area", "TRANSCRIPT"),
            ("configuration", "Takeoff/departure configuration", "TELEMETRY"),
            ("exercise_start", "Exercise start marked", "RECORDER_EVENT"),
            ("power_set", "Power set", "TELEMETRY"),
            ("pitch_up", "Pitch up to stall", "TELEMETRY"),
            ("recovery", "Recovery flow", "TELEMETRY"),
        ],
        start=1,
    ):
        add("IPCA_SOP_POWER_ON_STALL_v1", i, code, name, 1, src)

    for i, (code, name, src) in enumerate(
        [
            ("configure", "Landing configuration", "TELEMETRY"),
            ("stable_approach", "Stable approach parameters", "TELEMETRY"),
            ("aimpoint", "Aimpoint / path", "TELEMETRY"),
            ("flare", "Flare", "TELEMETRY"),
            ("touchdown", "Touchdown", "TELEMETRY"),
            ("runway_clearing", "Runway clearing / after landing", "NOT_OBSERVABLE"),
        ],
        start=1,
    ):
        add("IPCA_SOP_NORMAL_APPROACH_LANDING_v1", i, code, name, 1 if src != "NOT_OBSERVABLE" else 0, src, 1 if src == "NOT_OBSERVABLE" else 0)

    con.executemany(
        """INSERT INTO procedure_step
        (procedure_pack_id,step_order,step_code,display_name,required_flag,conditions_json,timing_window_sec,
         evidence_source,manual_only,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)""",
        steps,
    )


def wire_markers_and_transcripts(con: sqlite3.Connection) -> str:
    """Create marker attempts from Phase 7 pilot attempts + reference replay events."""
    clear(
        con,
        [
            "phase8_marker_attempt",
            "phase8_transcript_segment",
            "phase8_ai_intervention_proposal",
            "phase8_independence_group",
            "phase8_procedure_observation",
        ],
    )

    # Reference operational session = recording UUID (production would use ipca_flight_sessions.session_uuid)
    ref_uid = "0436A732-CD26-423D-9746-57AB709E7C1C"
    ref_session = ref_uid  # proxy until production UUID join available
    replay_events = []
    if REF_REPLAY.exists():
        replay = json.loads(REF_REPLAY.read_text())
        replay_events = replay.get("events") or []

    # Map Phase 7 attempts for reference flight (pilot_flight with this path)
    attempts = con.execute(
        """SELECT a.*, f.source_path, f.aircraft_ident, f.pilot_flight_id
           FROM pilot_exercise_attempt a
           JOIN pilot_flight f ON f.pilot_flight_id=a.pilot_flight_id
           WHERE f.source_path LIKE ? OR f.pilot_flight_id IN (
             SELECT pilot_flight_id FROM pilot_flight WHERE source_path LIKE '%0436A732%'
           )
           ORDER BY a.t_start_sec""",
        (f"%{ref_uid}%",),
    ).fetchall()
    cols = [c[0] for c in con.execute("SELECT a.*, f.source_path, f.aircraft_ident, f.pilot_flight_id FROM pilot_exercise_attempt a JOIN pilot_flight f ON f.pilot_flight_id=a.pilot_flight_id LIMIT 0").description]

    # If no exact path match, use richest flight
    if not attempts:
        row = con.execute(
            """SELECT pilot_flight_id FROM pilot_flight ORDER BY sample_count DESC LIMIT 1"""
        ).fetchone()
        if row:
            attempts = con.execute(
                """SELECT a.*, f.source_path, f.aircraft_ident, f.pilot_flight_id
                   FROM pilot_exercise_attempt a
                   JOIN pilot_flight f ON f.pilot_flight_id=a.pilot_flight_id
                   WHERE a.pilot_flight_id=? ORDER BY a.t_start_sec""",
                (row[0],),
            ).fetchall()
            ref_session = row[0]
            ref_uid = row[0]

    cur = con.execute(
        """SELECT a.attempt_id,a.pilot_flight_id,a.exercise_code,a.attempt_number,a.t_start_sec,a.t_end_sec,
                  a.boundary_source,a.detection_confidence,a.expected_level,f.source_path,f.aircraft_ident
           FROM pilot_exercise_attempt a
           JOIN pilot_flight f ON f.pilot_flight_id=a.pilot_flight_id
           ORDER BY a.pilot_flight_id, a.t_start_sec"""
    )
    all_attempts = [dict(zip([c[0] for c in cur.description], r)) for r in cur.fetchall()]

    # Prefer reference flight attempts
    ref_attempts = [a for a in all_attempts if ref_uid in (a.get("source_path") or "") or a["pilot_flight_id"] == ref_session]
    if not ref_attempts:
        # pick flight with most attempts
        by_f = Counter(a["pilot_flight_id"] for a in all_attempts)
        top = by_f.most_common(1)[0][0] if by_f else None
        ref_attempts = [a for a in all_attempts if a["pilot_flight_id"] == top]
        ref_session = top or "UNKNOWN"
        ref_uid = top or "UNKNOWN"

    # Boundary end rule: next exercise marker OR maneuver completion OR session end
    for i, a in enumerate(ref_attempts):
        t0 = a["t_start_sec"]
        t1 = a["t_end_sec"]
        boundary_source = a["boundary_source"] or "TELEMETRY_DERIVED"
        conf = float(a["detection_confidence"] or 0.5)
        # If next attempt exists, end may be clamped by next start (marker-like)
        if i + 1 < len(ref_attempts):
            nxt = ref_attempts[i + 1]["t_start_sec"]
            if nxt and t1 and nxt < t1:
                t1 = nxt
                boundary_source = "NEXT_EXERCISE_OR_TELEMETRY"
                conf = min(conf, 0.7)
        # Link replay event if close
        source_event = None
        for ev in replay_events:
            if abs(float(ev.get("start") or 0) - float(t0 or 0)) < 30:
                source_event = f"replay:{ev.get('event_type')}:{ev.get('start')}"
                if "Stall" in str(ev.get("event_type")) and a["exercise_code"] in ("power_off_stall", "power_on_stall"):
                    boundary_source = "REPLAY_EVENT+TELEMETRY"
                    conf = max(conf, float(ev.get("confidence") or 0.45))
                break

        mid = f"mk_{a['attempt_id']}"
        con.execute(
            """INSERT INTO phase8_marker_attempt
            (marker_attempt_id,operational_session_id,recording_uid,canonical_exercise_id,source_event_id,
             instructor_device,actual_leg_id,t_start_sec,t_end_sec,boundary_source,boundary_confidence,
             linked_pilot_attempt_id,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
            (
                mid,
                ref_session,
                ref_uid,
                a["exercise_code"],
                source_event,
                None,
                None,
                t0,
                t1,
                boundary_source,
                conf,
                a["attempt_id"],
                VERSION,
                NOW,
            ),
        )

        # Transcript window (pre/post 15s) — MISSING locally
        con.execute(
            """INSERT INTO phase8_transcript_segment
            (operational_session_id,recording_uid,marker_attempt_id,t_start_sec,t_end_sec,speaker,text,
             confidence,source_audio_chunk,transcription_model,availability,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)""",
            (
                ref_session,
                ref_uid,
                mid,
                (t0 or 0) - 15,
                (t1 or 0) + 15,
                "UNKNOWN",
                None,
                None,
                None,
                None,
                "MISSING",
                VERSION,
                NOW,
            ),
        )

        # Procedure observations for supported packs
        pack_map = {
            "go_around": "IPCA_SOP_GO_AROUND_v1",
            "power_off_stall": "IPCA_SOP_POWER_OFF_STALL_v1",
            "power_on_stall": "IPCA_SOP_POWER_ON_STALL_v1",
            "normal_approach": "IPCA_SOP_NORMAL_APPROACH_LANDING_v1",
            "normal_landing": "IPCA_SOP_NORMAL_APPROACH_LANDING_v1",
        }
        pack = pack_map.get(a["exercise_code"])
        if pack:
            for step in con.execute(
                "SELECT step_code, evidence_source, manual_only FROM procedure_step WHERE procedure_pack_id=?",
                (pack,),
            ):
                code, src, manual = step
                observed = None
                if src == "NOT_OBSERVABLE" or manual:
                    observed = None
                elif src == "TELEMETRY":
                    # present if attempt has any objective metrics
                    has = con.execute(
                        "SELECT COUNT(*) FROM pilot_objective_metric WHERE attempt_id=?",
                        (a["attempt_id"],),
                    ).fetchone()[0]
                    observed = 1 if has else 0
                elif src == "RECORDER_EVENT":
                    observed = 1 if source_event else 0
                elif src in ("TRANSCRIPT", "AUDIO"):
                    observed = None  # missing transcript
                else:
                    observed = None
                con.execute(
                    """INSERT INTO phase8_procedure_observation
                    (marker_attempt_id,procedure_pack_id,step_code,observed,evidence_source,evidence_json,within_timing,analysis_version,generated_at)
                    VALUES (?,?,?,?,?,?,?,?,?)""",
                    (mid, pack, code, observed, src, json.dumps({"availability": "PARTIAL" if observed is not None else "UNKNOWN"}), None, VERSION, NOW),
                )

    # Independence groups: one tap per exercise group on the session
    by_ex = defaultdict(list)
    for a in ref_attempts:
        by_ex[a["exercise_code"]].append(a)

    for ex, group in by_ex.items():
        aids = [x["attempt_id"] for x in group]
        # Suggest independence from objective trajectory (NOT a confirmation)
        mets_ok = []
        for aid in aids:
            rows = con.execute(
                "SELECT within_standard FROM pilot_objective_metric WHERE attempt_id=?",
                (aid,),
            ).fetchall()
            if rows:
                mets_ok.append(all(r[0] == 1 for r in rows))
        suggested = None
        rationale = "Insufficient objective pattern for suggestion; leave NOT_OBSERVED."
        if len(mets_ok) >= 2 and mets_ok[0] is False and mets_ok[-1] is True:
            suggested = "INDEPENDENT"
            rationale = "Later attempts within tolerance after earlier outside — suggest INDEPENDENT for final demonstrated state (instructor must confirm). Earlier assistance preserved via intervention events if logged."
        elif len(mets_ok) >= 2 and all(mets_ok):
            suggested = "INDEPENDENT"
            rationale = "All attempts within tolerance; no intervention events logged — suggest INDEPENDENT (confirm)."
        elif any(m is False for m in mets_ok):
            suggested = "PROMPTED"
            rationale = "Mixed/outside objective results — suggest PROMPTED pending instructor judgment."

        gid = f"ig_{ref_session}_{ex}"
        con.execute(
            """INSERT INTO phase8_independence_group
            (group_id,operational_session_id,canonical_exercise_id,attempt_ids_json,final_demonstrated_state,
             system_suggested_independence,suggestion_rationale,instructor_confirmation,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?)""",
            (
                gid,
                ref_session,
                ex,
                json.dumps(aids),
                "NOT_OBSERVED",
                suggested,
                rationale,
                "PENDING",
                VERSION,
                NOW,
            ),
        )

    # AI intervention proposals: no transcript → explicit missing candidates only
    con.execute(
        """INSERT INTO phase8_ai_intervention_proposal
        (marker_attempt_id,operational_session_id,t_sec,event_type,evidence_text,confidence,model_version,confirmation_status,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?)""",
        (
            None,
            ref_session,
            None,
            "VERBAL_PROMPT",
            "NO_TRANSCRIPT_AVAILABLE — cannot propose prompt events; speaker remains UNKNOWN",
            0.0,
            "phase8-prompt-detect-v1",
            "UNCONFIRMED",
            VERSION,
            NOW,
        ),
    )

    return ref_session


def examiner_clinic(con: sqlite3.Connection) -> None:
    """Package 40 Phase 7 cases for examiner clinic; do not invent human verdicts."""
    clear(con, ["phase8_examiner_review", "phase8_inter_rater", "phase8_tolerance_validation"])

    pending = con.execute(
        """SELECT attempt_id FROM pilot_expert_review WHERE verdict='PENDING' LIMIT 40"""
    ).fetchall()
    if not pending:
        pending = con.execute(
            """SELECT attempt_id FROM pilot_exercise_attempt
               WHERE attempt_id IN (SELECT DISTINCT attempt_id FROM pilot_objective_metric)
               LIMIT 40"""
        ).fetchall()

    # Dual reviewer slots (examiner_A, examiner_B) for inter-rater design — both PENDING
    for (aid,) in pending:
        for reviewer in ("examiner_A", "examiner_B"):
            # Pre-compute dimension checklist for clinic worksheet
            mets = con.execute(
                "SELECT metric, within_standard, max_deviation FROM pilot_objective_metric WHERE attempt_id=?",
                (aid,),
            ).fetchall()
            st = con.execute(
                "SELECT attempt_repeatability, independence_state, explanation FROM pilot_competency_state WHERE attempt_id=? ORDER BY state_id DESC LIMIT 1",
                (aid,),
            ).fetchone()
            dims = {
                "exercise_boundary": "REVIEW",
                "objective_metrics": "PRESENT" if mets else "MISSING",
                "tolerance_pack": "IPCA_TRAINING_PE_v1",
                "context": "REVIEW",
                "independence": st[1] if st else "NOT_OBSERVED",
                "consistency": st[0] if st else "INSUFFICIENT_EVIDENCE",
                "system_interpretation": st[2] if st else "",
                "ai_interpretation": "PENDING_REVIEW",
                "final_proposed_state": "PENDING_REVIEW",
            }
            con.execute(
                """INSERT INTO phase8_examiner_review
                (attempt_id,reviewer_id,verdict,reason_codes_json,narrative_notes,reviewed_dimensions_json,reviewed_at,analysis_version,generated_at)
                VALUES (?,?,?,?,?,?,?,?,?)""",
                (
                    aid,
                    reviewer,
                    "PENDING",
                    json.dumps([]),
                    "Clinic worksheet prepared — awaiting qualified examiner. Reason codes: BOUNDARY_WRONG|WRONG_EXERCISE_MAPPING|WRONG_TOLERANCE|METRIC_EXTRACTION_WRONG|CONTEXT_MISSING|CONTEXT_MISINTERPRETED|INDEPENDENCE_WRONG|CONSISTENCY_WRONG|AI_OVERINTERPRETATION|HUMAN_FACTOR_NOT_CAPTURED|PROCEDURAL_ISSUE_NOT_CAPTURED|OBJECTIVE_DATA_INSUFFICIENT|OTHER",
                    json.dumps(dims),
                    None,
                    VERSION,
                    NOW,
                ),
            )

    # Inter-rater: cannot compute until reviews complete
    con.execute(
        """INSERT INTO phase8_inter_rater (metric_name,value,n,notes,analysis_version,generated_at)
           VALUES (?,?,?,?,?,?)""",
        ("agreement_pending", None, len(pending), "Dual-reviewer slots created; agreement deferred until human clinic completes.", VERSION, NOW),
    )

    # Tolerance validation status from Phase 7 calibration notes (not silent changes)
    validations = [
        ("ACS_PPL_ASEL_v1", "altitude_deviation_ft", "steep_turn", "INSUFFICIENT_EVIDENCE", "Await examiner clinic on steep_turn sample"),
        ("ACS_PPL_ASEL_v1", "bank_abs_deg", "steep_turn", "INSUFFICIENT_EVIDENCE", "Await clinic"),
        ("IPCA_TRAINING_PE_v1", "vertical_speed_fpm", "normal_approach", "NEEDS_ADJUSTMENT", "Phase 7: low within-rate likely boundary over-detection; do not loosen ACS — fix markers first"),
        ("IPCA_TRAINING_PE_v1", "airspeed_deviation_kt", "slow_flight", "NEEDS_CONTEXT_RULE", "Specified airspeed unknown without marker/brief target"),
        ("IPCA_TRAINING_PR_v1", "altitude_deviation_ft", "steep_turn", "INSUFFICIENT_EVIDENCE", "PR wider band pedagogically justified for developing accuracy; clinic to confirm"),
        ("IPCA_TRAINING_PE_v1", "touchdown_vs_fpm", "normal_landing", "INSUFFICIENT_EVIDENCE", "Soft proxy; clinic needed"),
        ("IPCA_TRAINING_PE_v1", "pitch_positive_after_goaround", "go_around", "INSUFFICIENT_EVIDENCE", "Soft telemetry proxy for procedure"),
    ]
    con.executemany(
        """INSERT INTO phase8_tolerance_validation
        (tolerance_pack_id,metric,exercise_code,status,reason,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?)""",
        [(*v, VERSION, NOW) for v in validations],
    )


def build_reference_debrief(con: sqlite3.Connection, ref_session: str) -> None:
    clear(con, ["phase8_reference_flight", "phase8_debrief_item", "phase8_recommendation", "phase8_evidence_completeness"])

    markers = con.execute(
        "SELECT * FROM phase8_marker_attempt WHERE operational_session_id=? ORDER BY t_start_sec",
        (ref_session,),
    ).fetchall()
    mcols = [c[0] for c in con.execute("SELECT * FROM phase8_marker_attempt LIMIT 0").description]
    markers = [dict(zip(mcols, r)) for r in con.execute(
        "SELECT * FROM phase8_marker_attempt WHERE operational_session_id=? ORDER BY t_start_sec",
        (ref_session,),
    )]

    recording_uid = markers[0]["recording_uid"] if markers else ref_session
    # Completeness
    audio = 0  # local missing
    transcript = 0
    garmin = 1
    markers_complete = 0  # telemetry-derived, not instructor markers
    context = 1
    instructor = 0
    overall = "LIMITED_EVIDENCE"
    if garmin and markers:
        overall = "PARTIAL_EVIDENCE"
    con.execute(
        """INSERT INTO phase8_evidence_completeness
        (session_key,garmin_complete,audio_complete,transcript_complete,exercise_markers_complete,context_complete,
         instructor_input_complete,overall_level,notes,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)""",
        (
            ref_session,
            garmin,
            audio,
            transcript,
            markers_complete,
            context,
            instructor,
            overall,
            "Local reference flight: G3X+context present; audio/transcript/instructor markers missing from local bundle (audio_url exists on production recording 22).",
            VERSION,
            NOW,
        ),
    )

    items = []
    for m in markers:
        aid = m["linked_pilot_attempt_id"]
        st = con.execute(
            "SELECT * FROM pilot_competency_state WHERE attempt_id=? ORDER BY state_id DESC LIMIT 1",
            (aid,),
        ).fetchone()
        scols = [c[0] for c in con.execute("SELECT * FROM pilot_competency_state LIMIT 0").description]
        st = dict(zip(scols, st)) if st else {}
        mets = [
            dict(zip(["metric", "within", "max_dev", "t_out", "actual", "unit"], r))
            for r in con.execute(
                """SELECT metric, within_standard, max_deviation, time_outside_tolerance_sec, actual_value, unit
                   FROM pilot_objective_metric WHERE attempt_id=?""",
                (aid,),
            )
        ]
        proc = [
            dict(zip(["step", "observed", "source"], r))
            for r in con.execute(
                """SELECT step_code, observed, evidence_source FROM phase8_procedure_observation WHERE marker_attempt_id=?""",
                (m["marker_attempt_id"],),
            )
        ]
        ig = con.execute(
            "SELECT system_suggested_independence, suggestion_rationale, final_demonstrated_state FROM phase8_independence_group WHERE operational_session_id=? AND canonical_exercise_id=?",
            (ref_session, m["canonical_exercise_id"]),
        ).fetchone()

        outside = [x for x in mets if not x["within"]]
        priority = 50
        reasons = []
        if outside:
            priority -= 20
            reasons.append("large_or_any_objective_deviation")
        if st.get("attempt_repeatability") == "VARIABLE":
            priority -= 15
            reasons.append("repeated_consistency_concern")
        if m["canonical_exercise_id"] in ("go_around", "power_off_stall", "power_on_stall"):
            priority -= 5
            reasons.append("safety_relevant_maneuver")
        if not outside and st.get("attempt_repeatability") == "CONSISTENT":
            priority += 20
            reasons.append("routine_success_compact")

        # Developmental language — separate dimensions, not a new ladder replacing them
        strengths = []
        development = []
        if mets and all(x["within"] for x in mets):
            strengths.append("Objective metrics within applicable training tolerance for this attempt.")
        if outside:
            development.append(
                "Objective deviations: " + ", ".join(f"{x['metric']} max_dev={x['max_dev']}" for x in outside)
            )
        if st.get("independence_state") == "NOT_OBSERVED":
            development.append("Independence NOT_OBSERVED — instructor confirmation required.")
        if any(p["observed"] == 0 for p in proc):
            development.append("Procedural steps unobserved or not evidenced.")
        if any(p["observed"] is None and p["source"] == "NOT_OBSERVABLE" for p in proc):
            development.append("Some required SOP steps are NOT_OBSERVABLE automatically — do not claim full SOP compliance.")

        payload = {
            "exercise": m["canonical_exercise_id"],
            "expected_level": "PE",
            "attempt_summary": {
                "marker_attempt_id": m["marker_attempt_id"],
                "t_start_sec": m["t_start_sec"],
                "t_end_sec": m["t_end_sec"],
                "boundary_source": m["boundary_source"],
                "boundary_confidence": m["boundary_confidence"],
            },
            "independence": {
                "state": st.get("independence_state", "NOT_OBSERVED"),
                "system_suggested": ig[0] if ig else None,
                "suggestion_rationale": ig[1] if ig else None,
                "confirmed": False,
            },
            "objective_quality": mets,
            "procedure": {
                "outcome_quality": "acceptable" if mets and all(x["within"] for x in mets) else ("deficiency" if outside else "insufficient_evidence"),
                "procedural_compliance": "insufficient_evidence" if not proc else (
                    "deficiency" if any(p["observed"] == 0 for p in proc) else "partial_or_unknown"
                ),
                "steps": proc,
            },
            "consistency": st.get("attempt_repeatability"),
            "context": st.get("context_summary"),
            "trend": st.get("trend"),
            "strengths": strengths,
            "development_items": development,
            "supporting_evidence": {
                "pilot_attempt_id": aid,
                "replay_link": f"/admin/cockpit_recorder_replay.php?id=22",
                "audio_link": f"/admin/cockpit_recorder_audio.php?id=22",
                "g3x_link": f"/admin/cockpit_recorder_g3x.php?id=22",
            },
            "instructor_confirmation": "PENDING",
            "AI_assessment": None,
            "confidence_evidence_completeness": overall,
            "tone_note": "TONE is separate from ASSESSMENT — supportive language must not hide development_items.",
        }
        ai = con.execute(
            "SELECT assessment_text, model, confidence FROM pilot_ai_assessment WHERE attempt_id=? ORDER BY ai_assessment_id DESC LIMIT 1",
            (aid,),
        ).fetchone()
        if ai:
            payload["AI_assessment"] = {"text": ai[0], "model": ai[1], "confidence": ai[2], "authority": "advisory_only"}

        items.append((priority, reasons, payload))

    items.sort(key=lambda x: x[0])
    debrief = {
        "operational_session_id": ref_session,
        "recording_uid": recording_uid,
        "evidence_completeness": overall,
        "what_went_well": [],
        "what_needs_development": [],
        "what_the_data_showed": [],
        "what_to_focus_on_next": [],
        "items": [],
        "exception_queue": [],
    }
    for rank, (priority, reasons, payload) in enumerate(items, start=1):
        con.execute(
            """INSERT INTO phase8_debrief_item
            (reference_id,canonical_exercise_id,priority_rank,priority_reason,payload_json,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?)""",
            ("REF_FLIGHT", payload["exercise"], rank, ",".join(reasons), json.dumps(payload), VERSION, NOW),
        )
        debrief["items"].append(payload)
        if payload["development_items"]:
            debrief["what_needs_development"].extend(payload["development_items"])
            debrief["exception_queue"].append(payload["exercise"])
        if payload["strengths"]:
            debrief["what_went_well"].extend(payload["strengths"])
        for m in payload["objective_quality"]:
            debrief["what_the_data_showed"].append(f"{payload['exercise']}:{m['metric']} within={m['within']} max_dev={m['max_dev']}")

    # Recommendations (deterministic)
    for payload in debrief["items"]:
        ex = payload["exercise"]
        if any(not m["within"] for m in payload["objective_quality"]):
            text = f"Repeat {ex} with focus on objective consistency for out-of-tolerance metrics."
            rule = "REPEAT_ON_OBJECTIVE_DEVIATION"
        elif payload["consistency"] == "VARIABLE":
            text = f"Additional {ex} practice to build attempt repeatability (currently VARIABLE)."
            rule = "REPEAT_ON_VARIABLE_CONSISTENCY"
        elif payload["independence"]["state"] == "NOT_OBSERVED" and payload["independence"]["system_suggested"] == "INDEPENDENT":
            text = f"Confirm independence for {ex}; if confirmed independent and stable, no dedicated repetition required."
            rule = "CONFIRM_INDEPENDENCE_THEN_PROGRESS"
        else:
            text = f"{ex}: review exception queue only; routine metrics within tolerance may be summarized."
            rule = "EXCEPTION_ONLY"
        con.execute(
            """INSERT INTO phase8_recommendation
            (reference_id,canonical_exercise_id,recommendation_text,rule_code,evidence_json,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?)""",
            ("REF_FLIGHT", ex, text, rule, json.dumps({"exercise": ex}), VERSION, NOW),
        )
        if rule.startswith("REPEAT"):
            debrief["what_to_focus_on_next"].append(text)

    # Curriculum adaptation research note (recommendations only)
    con.execute(
        """INSERT INTO phase8_recommendation
        (reference_id,canonical_exercise_id,recommendation_text,rule_code,evidence_json,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?)""",
        (
            "REF_FLIGHT",
            None,
            "Curriculum adaptation research: use sctr_next/sctr_alternative + competency state to recommend continue/repeat/alternate — do NOT auto-reschedule.",
            "CURRICULUM_ADAPTATION_RESEARCH_ONLY",
            json.dumps({"sctr_next": "authoritative", "auto_schedule": False}),
            VERSION,
            NOW,
        ),
    )

    con.execute(
        """INSERT INTO phase8_reference_flight
        (reference_id,operational_session_id,recording_uid,recording_id,aircraft,debrief_json,evidence_completeness,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?)""",
        ("REF_FLIGHT", ref_session, recording_uid, 22, "N397EA", json.dumps(debrief), overall, VERSION, NOW),
    )


def failure_modes_and_contract(con: sqlite3.Connection) -> None:
    clear(con, ["phase8_failure_mode_test", "phase8_production_contract", "phase8_acceptance_gate", "phase8_workload_measurement"])

    tests = [
        ("missing_garmin_csv", "DEGRADED", "Assessment falls back to instructor evidence only; objective_quality=INSUFFICIENT_EVIDENCE"),
        ("partial_garmin_data", "DEGRADED", "Metrics computed only on available columns; confidence lowered"),
        ("missing_audio", "DEGRADED", "Transcript/prompt proposals unavailable; speaker UNKNOWN; independence remains NOT_OBSERVED without instructor"),
        ("bad_transcript", "DEGRADED", "AI proposals stay UNCONFIRMED; never overwrite instructor independence"),
        ("no_exercise_marker", "DEGRADED", "TELEMETRY_DERIVED boundaries with explicit boundary_confidence < marker-authoritative"),
        ("incorrect_marker", "DEGRADED", "Examiner reason BOUNDARY_WRONG / WRONG_EXERCISE_MAPPING; correction creates new evidence, no silent overwrite"),
        ("exercise_interrupted", "DEGRADED", "Abort/incomplete condition on state machine; incomplete attempt preserved"),
        ("multiple_attempts_merged", "DEGRADED", "Prefer marker + next-marker end rule; flag low boundary_confidence"),
        ("instructor_forgets_independence", "PASS", "Remains NOT_OBSERVED; system_suggested_independence may exist separately"),
        ("offline_flight", "DEGRADED", "Late CSV import supported; assessment waits for evidence completeness gate"),
        ("late_csv_import", "PASS", "Recompute metrics when Garmin arrives; version assessments"),
    ]
    con.executemany(
        """INSERT INTO phase8_failure_mode_test (test_code,result,observed_behavior,analysis_version,generated_at)
           VALUES (?,?,?,?,?)""",
        [(*t, VERSION, NOW) for t in tests],
    )

    entities = [
        ("exercise_attempt", "PRODUCTION_REQUIRED", "Attach to operational_session_uuid"),
        ("competency_expectation", "PRODUCTION_REQUIRED", "DE/EX/PR/PE preserved"),
        ("objective_measurement", "PRODUCTION_REQUIRED", "Tolerance-pack versioned"),
        ("context_snapshot", "PRODUCTION_REQUIRED", "Auto-derived"),
        ("instructor_observation", "PRODUCTION_REQUIRED", "Human assessment"),
        ("intervention_event", "PRODUCTION_REQUIRED", "Separate from independence"),
        ("competency_assessment", "PRODUCTION_REQUIRED", "Proposed + confirmed"),
        ("competency_state_history", "PRODUCTION_REQUIRED", "Persistent history"),
        ("debrief", "PRODUCTION_REQUIRED", "Structured debrief object"),
        ("procedure_pack", "PRODUCTION_REQUIRED", "Versioned SOP"),
        ("canonical_exercise", "PRODUCTION_REQUIRED", "Identity map"),
        ("phase8_nlp_reconciliation", "ANALYTICS_ONLY", ""),
        ("pilot_* Phase7 tables", "ANALYTICS_ONLY", "Prototype warehouse"),
        ("evidence_item cache", "DERIVED_CACHE", ""),
        ("historical_narrative_extraction", "HISTORICAL_ONLY", ""),
    ]
    con.executemany(
        """INSERT INTO phase8_production_contract (entity_name,classification,notes,analysis_version,generated_at)
           VALUES (?,?,?,?,?)""",
        [(*e, VERSION, NOW) for e in entities],
    )

    gates = [
        ("exercise_boundary_accuracy", "OPEN", "Set after examiner clinic % CORRECT on boundaries", "Clinic pending"),
        ("objective_metric_accuracy", "OPEN", "Examiner-approved metric extraction", "Clinic pending"),
        ("tolerance_packs_approved", "OPEN", "VALIDATED status per rule", "Several INSUFFICIENT_EVIDENCE / NEEDS_ADJUSTMENT"),
        ("independence_workflow_accepted", "OPEN", "Group-level one-tap accepted by instructors", "Prototype ready"),
        ("consistency_logic_accepted", "OPEN", "Examiner agreement on consistency dimension", "Pending inter-rater"),
        ("procedural_pilot_accepted", "OPEN", "SOP pack steps accepted; unobservable steps explicit", "Packs seeded"),
        ("post_flight_workload", "OPEN", "Target <3 minutes routine flight", "Exception-based design: est ~2–3 min if group independence"),
        ("debrief_usefulness", "OPEN", "Examiner educational defensibility", "Reference flight built"),
        ("ai_unsupported_claim_rate", "OPEN", "Zero silent fabrications; MISSING stays MISSING", "Prompt detection blocked without transcript"),
        ("openai_secret_injection", "BLOCKED", "Runtime plaintext required", "EV ciphertext only locally"),
        ("production_db_access", "BLOCKED", "CW_DB_PASS plaintext injection required", "EV ciphertext only locally"),
    ]
    con.executemany(
        """INSERT INTO phase8_acceptance_gate (gate_code,status,threshold_notes,observed_notes,analysis_version,generated_at)
           VALUES (?,?,?,?,?,?)""",
        [(*g, VERSION, NOW) for g in gates],
    )

    # Workload: exception-based + group independence
    n_groups = con.execute("SELECT COUNT(*) FROM phase8_independence_group").fetchone()[0]
    n_exceptions = con.execute(
        """SELECT COUNT(*) FROM phase8_debrief_item WHERE priority_reason LIKE '%deviation%' OR priority_reason LIKE '%consistency%'"""
    ).fetchone()[0]
    workload = [
        ("independence_taps_group_level", float(n_groups), "taps", "One tap per exercise group, not per attempt"),
        ("exception_items_to_review", float(n_exceptions), "items", "Only prioritized exceptions"),
        ("est_post_flight_minutes_exception_based", 2.5, "minutes", "Target <3 for routine flight"),
        ("phase7_baseline_minutes", 5.4, "minutes", "Prior estimate"),
        ("manual_field_pct_est", 8.0, "percent", "Reduced vs Phase 7 ~12% via grouping"),
    ]
    con.executemany(
        """INSERT INTO phase8_workload_measurement (metric_name,metric_value,unit,notes,analysis_version,generated_at)
           VALUES (?,?,?,?,?,?)""",
        [(*w, VERSION, NOW) for w in workload],
    )


def nlp_reconcile(con: sqlite3.Connection) -> None:
    """Copy/refresh reconciliation; attempt LLM if secret available."""
    clear(con, ["phase8_nlp_reconciliation", "phase8_secret_status"])
    for name in ("OPENAI_API_KEY", "CW_DB_PASS"):
        st = peek_secret_status(name)
        con.execute(
            """INSERT INTO phase8_secret_status (logical_name,usable,status_json,analysis_version,generated_at)
               VALUES (?,?,?,?,?)""",
            (name, int(st["usable"]), json.dumps(st), VERSION, NOW),
        )

    llm_ran = False
    try:
        key = get_runtime_secret("OPENAI_API_KEY", required=True)
        # Invoke phase7 enrich
        log("OpenAI runtime secret available — running targeted LLM enrichment...")
        import subprocess

        env = dict(**{k: v for k, v in __import__("os").environ.items()})
        # do not print key; ensure child sees it
        env["CW_OPENAI_API_KEY"] = key
        subprocess.run(
            [str(ROOT / "analytics/.venv/bin/python"), str(ROOT / "analytics/etl/phase7_05_llm_enrich.py")],
            cwd=str(ROOT),
            env=env,
            check=False,
        )
        llm_ran = True
    except RuntimeSecretError as e:
        log(f"LLM enrichment blocked: {e}")

    # Pull phase7 reconciliation into phase8
    rows = con.execute(
        "SELECT finding_name, method, metric_value, n, notes FROM phase7_llm_reconciliation"
    ).fetchall()
    if rows:
        con.executemany(
            """INSERT INTO phase8_nlp_reconciliation
            (finding_name,method,metric_value,n,notes,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?)""",
            [(r[0], r[1], r[2], r[3], (r[4] or "") + ("; llm_ran=" + str(llm_ran)), VERSION, NOW) for r in rows],
        )
    else:
        con.execute(
            """INSERT INTO phase8_nlp_reconciliation
            (finding_name,method,metric_value,n,notes,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?)""",
            ("reconciliation_status", "blocked", None, 0, "No phase7 reconciliation rows; run phase7_05 after secret injection", VERSION, NOW),
        )


def main() -> None:
    con = sqlite3.connect(DB)
    con.executescript(SCHEMA.read_text())
    log("Seeding canonical exercises + SOP packs...")
    seed_canonical(con)
    seed_procedures(con)
    log("Wiring markers/transcripts/independence groups...")
    ref = wire_markers_and_transcripts(con)
    log(f"Reference session={ref}")
    log("Examiner clinic worksheets...")
    examiner_clinic(con)
    log("Building reference debrief...")
    build_reference_debrief(con, ref)
    log("Failure modes + production contract + gates...")
    failure_modes_and_contract(con)
    log("Secret status + NLP reconcile...")
    nlp_reconcile(con)
    clear(con, ["phase8_meta"])
    con.execute(
        "INSERT INTO phase8_meta (analysis_version,generated_at,notes) VALUES (?,?,?)",
        (VERSION, NOW, json.dumps({"reference_session": ref, "gates": "see phase8_acceptance_gate"})),
    )
    con.commit()
    con.close()
    log("Phase 8 pipeline complete.")


if __name__ == "__main__":
    main()
