#!/usr/bin/env python3
"""Phase 10C continuation — investigations + human review queues.

Does NOT fabricate examiner/claim/transcript/workload verdicts.
Does NOT change freeze membership (phase10c-live-freeze-v1).
Writes analytics-only investigation tables.
"""

from __future__ import annotations

import json
import os
import sqlite3
import sys
from collections import Counter, defaultdict
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "analytics"))
from lib.runtime_secrets import ensure_cli_env_loaded, get_runtime_secret  # noqa: E402

DB = ROOT / "storage/analytics/egle_training_analytics.sqlite"
INV_DB = ROOT / "storage/analytics/phase10c_investigations.sqlite"
VERSION = "phase10c-v1.1"
NOW = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
FREEZE_ID = "phase10c-live-freeze-v1"


def log(msg: str) -> None:
    print(msg, flush=True)


SCHEMA = """
CREATE TABLE IF NOT EXISTS phase10c_evidence_component (
  operational_session_uuid TEXT NOT NULL,
  component TEXT NOT NULL, -- MARKERS|GARMIN|AUDIO|TRANSCRIPT|CONTEXT|INSTRUCTOR_INPUT
  present INTEGER NOT NULL,
  detail TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  PRIMARY KEY (operational_session_uuid, component, analysis_version)
);

CREATE TABLE IF NOT EXISTS phase10c_evidence_summary (
  metric_name TEXT PRIMARY KEY,
  metric_value REAL,
  n INTEGER,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10c_linkage_finding (
  finding_code TEXT PRIMARY KEY,
  n INTEGER,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10c_session_crew (
  operational_session_uuid TEXT PRIMARY KEY,
  student_id TEXT,
  instructor_id TEXT,
  linkage_path TEXT, -- schedule_reservation|schedule_dispatch|none|session_missing
  reservation_uuid TEXT,
  dispatch_uuid TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10c_attempt_denominator (
  canonical_exercise_id TEXT NOT NULL,
  total_live_attempts INTEGER,
  usable_boundary INTEGER,
  usable_telemetry INTEGER,
  examiner_reviewed INTEGER,
  boundary_correct INTEGER,
  metric_correct INTEGER,
  tolerance_agree INTEGER,
  disposition TEXT,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL,
  PRIMARY KEY (canonical_exercise_id, analysis_version)
);

CREATE TABLE IF NOT EXISTS phase10c_review_queue (
  queue_id INTEGER PRIMARY KEY AUTOINCREMENT,
  queue_type TEXT NOT NULL, -- CLINIC|CLAIM|TRANSCRIPT|WORKLOAD|BOUNDARY_METRIC
  ref_id TEXT NOT NULL,
  priority INTEGER,
  status TEXT, -- OPEN|DONE
  reason TEXT,
  payload_json TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS phase10c_llm_progress_snapshot (
  eligible INTEGER,
  processed INTEGER,
  successful INTEGER,
  failed INTEGER,
  remaining INTEGER,
  job_status TEXT,
  notes TEXT,
  analysis_version TEXT NOT NULL,
  generated_at TEXT NOT NULL
);
"""


def mysql_ro():
    ensure_cli_env_loaded()
    import pymysql

    return pymysql.connect(
        host=os.environ.get("CW_DB_HOST"),
        port=int(os.environ.get("CW_DB_PORT") or "25060"),
        user=os.environ.get("CW_DB_USER"),
        password=get_runtime_secret("CW_DB_PASS", required=True),
        database=os.environ.get("CW_DB_NAME"),
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
        ssl={"ssl": {}},
        connect_timeout=20,
        read_timeout=180,
    )


def clear(con, tables):
    for t in tables:
        try:
            con.execute(f"DELETE FROM {t}")
        except sqlite3.OperationalError:
            pass


def resolve_crew(cur, op: str) -> dict:
    cur.execute(
        """SELECT session_uuid, reservation_uuid, dispatch_uuid
           FROM ipca_flight_sessions
           WHERE session_uuid=%s OR workflow_flight_record_uuid=%s LIMIT 1""",
        (op, op),
    )
    fs = cur.fetchone()
    if not fs:
        return {
            "student_id": None,
            "instructor_id": None,
            "linkage_path": "session_missing",
            "reservation_uuid": None,
            "dispatch_uuid": None,
        }

    instructor = student = None
    path = "none"
    res = fs.get("reservation_uuid")
    disp = fs.get("dispatch_uuid")

    def apply_crew(rows, path_name):
        nonlocal instructor, student, path
        for c in rows or []:
            role = (c.get("crew_role") or "").lower()
            uid = c.get("user_id")
            if uid is None:
                continue
            uid = str(uid)
            if "instruct" in role:
                instructor = instructor or uid
            elif role == "student":
                student = student or uid
        if instructor or student:
            path = path_name

    if res:
        cur.execute(
            """SELECT sc.user_id, sc.crew_role
               FROM ipca_flight_schedule_slots ss
               JOIN ipca_flight_schedule_crew sc ON sc.schedule_slot_id = ss.id
               WHERE ss.scheduler_record_id=%s""",
            (res,),
        )
        apply_crew(cur.fetchall(), "schedule_reservation")
    if disp and not (instructor and student):
        cur.execute(
            """SELECT sc.user_id, sc.crew_role
               FROM ipca_flight_schedule_slots ss
               JOIN ipca_flight_schedule_crew sc ON sc.schedule_slot_id = ss.id
               WHERE ss.claimed_dispatch_uuid=%s""",
            (disp,),
        )
        apply_crew(cur.fetchall(), "schedule_dispatch")

    return {
        "student_id": student,
        "instructor_id": instructor,
        "linkage_path": path if (instructor or student) else ("none" if (res or disp) else "none"),
        "reservation_uuid": res,
        "dispatch_uuid": disp,
        "has_session": True,
        "has_reservation": bool(res),
        "has_dispatch": bool(disp),
    }


def investigate(con: sqlite3.Connection, main: sqlite3.Connection, mysql) -> dict:
    """con = investigations sidecar; main = analytics DB (read-mostly)."""
    clear(
        con,
        [
            "phase10c_evidence_component",
            "phase10c_evidence_summary",
            "phase10c_linkage_finding",
            "phase10c_session_crew",
            "phase10c_attempt_denominator",
            "phase10c_review_queue",
            "phase10c_llm_progress_snapshot",
        ],
    )
    sessions = list(
        main.execute(
            """SELECT operational_session_uuid, recording_id, recording_uid, aircraft
               FROM phase10c_cohort_session WHERE freeze_id=?""",
            (FREEZE_ID,),
        )
    )
    miss = Counter()
    combo = Counter()
    link = Counter()
    crew_ok = 0
    near_full = 0
    full = 0

    cur = mysql.cursor()
    for op, rid, ruid, aircraft in sessions:
        cur.execute(
            """SELECT id, recording_uid, upload_status, transcription_status,
                      file_size_bytes, ahrs_sample_count, gps_sample_count, g3x_row_count,
                      LENGTH(COALESCE(transcript_text,'')) txlen, flight_session_uid
               FROM ipca_cockpit_recordings
               WHERE id=%s OR flight_session_uid=%s OR operational_session_uuid=%s
               ORDER BY id DESC LIMIT 1""",
            (rid, op, op),
        )
        rec = cur.fetchone()
        markers = 0
        if rec:
            cur.execute(
                """SELECT COUNT(*) c FROM ipca_cvr_flight_events
                   WHERE event_type='exercise_marker' AND (
                     operational_session_uuid=%s OR workflow_flight_record_uuid=%s
                     OR recording_session_uuid=%s OR workflow_flight_record_uuid=%s)""",
                (op, op, rec.get("recording_uid") or "", rec.get("flight_session_uid") or ""),
            )
            markers = int((cur.fetchone() or {}).get("c") or 0)

        audio = bool(rec and (rec.get("file_size_bytes") or 0) > 0)
        transcript = bool(
            rec
            and (
                (rec.get("txlen") or 0) > 50
                or (rec.get("transcription_status") or "") in ("ready", "completed", "published")
            )
        )
        garmin = bool(
            rec
            and (
                (rec.get("gps_sample_count") or 0) > 100
                or (rec.get("ahrs_sample_count") or 0) > 100
                or (rec.get("g3x_row_count") or 0) > 100
            )
        )
        crew = resolve_crew(cur, op)
        context = bool(crew.get("has_session"))
        instructor_input = False  # shadow independence not entered

        components = {
            "MARKERS": (markers > 0, f"marker_events={markers}"),
            "GARMIN": (
                garmin,
                f"gps={ (rec or {}).get('gps_sample_count')} g3x={ (rec or {}).get('g3x_row_count')} ahrs={ (rec or {}).get('ahrs_sample_count')}",
            ),
            "AUDIO": (audio, f"bytes={ (rec or {}).get('file_size_bytes')}"),
            "TRANSCRIPT": (
                transcript,
                f"status={ (rec or {}).get('transcription_status')} len={ (rec or {}).get('txlen')}",
            ),
            "CONTEXT": (
                context,
                f"flight_session={'yes' if context else 'no'} reservation={crew.get('reservation_uuid')}",
            ),
            "INSTRUCTOR_INPUT": (instructor_input, "independence/workload not entered in shadow"),
        }
        present = []
        for name, (ok, detail) in components.items():
            if not ok:
                miss[name] += 1
            else:
                present.append(name)
            con.execute(
                """INSERT INTO phase10c_evidence_component
                (operational_session_uuid,component,present,detail,analysis_version,generated_at)
                VALUES (?,?,?,?,?,?)""",
                (op, name, 1 if ok else 0, detail, VERSION, NOW),
            )

        combo["+".join(present) or "NONE"] += 1
        if set(present) >= {"MARKERS", "GARMIN", "AUDIO", "TRANSCRIPT", "CONTEXT", "INSTRUCTOR_INPUT"}:
            full += 1
        if set(present) >= {"MARKERS", "GARMIN", "AUDIO", "TRANSCRIPT"}:
            near_full += 1

        con.execute(
            """INSERT OR REPLACE INTO phase10c_session_crew
            (operational_session_uuid,student_id,instructor_id,linkage_path,reservation_uuid,dispatch_uuid,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?,?)""",
            (
                op,
                crew.get("student_id"),
                crew.get("instructor_id"),
                crew.get("linkage_path"),
                crew.get("reservation_uuid"),
                crew.get("dispatch_uuid"),
                VERSION,
                NOW,
            ),
        )
        if crew.get("student_id") or crew.get("instructor_id"):
            crew_ok += 1
        link[crew.get("linkage_path") or "none"] += 1
        if crew.get("has_session"):
            link["has_flight_session"] += 1
        else:
            link["session_missing"] += 1
        if crew.get("has_reservation"):
            link["has_reservation"] += 1
        else:
            link["no_reservation"] += 1

        # Update freeze row crew if resolved (best-effort; skip if main DB locked by LLM)
        if crew.get("student_id") or crew.get("instructor_id"):
            try:
                main.execute(
                    """UPDATE phase10c_cohort_session SET student_id=?, instructor_id=?
                       WHERE freeze_id=? AND operational_session_uuid=?""",
                    (crew.get("student_id"), crew.get("instructor_id"), FREEZE_ID, op),
                )
            except sqlite3.OperationalError:
                pass

    # summaries
    n = len(sessions)
    for name, val, notes in [
        ("sessions_in_freeze", float(n), FREEZE_ID),
        ("full_evidence_count", float(full), "Requires MARKERS+GARMIN+AUDIO+TRANSCRIPT+CONTEXT+INSTRUCTOR_INPUT"),
        ("near_full_no_instructor_input", float(near_full), "MARKERS+GARMIN+AUDIO+TRANSCRIPT (±context)"),
        ("missing_MARKERS", float(miss["MARKERS"]), ""),
        ("missing_GARMIN", float(miss["GARMIN"]), "gps/g3x/ahrs thresholds"),
        ("missing_AUDIO", float(miss["AUDIO"]), ""),
        ("missing_TRANSCRIPT", float(miss["TRANSCRIPT"]), ""),
        ("missing_CONTEXT", float(miss["CONTEXT"]), "no ipca_flight_sessions row for UUID"),
        ("missing_INSTRUCTOR_INPUT", float(miss["INSTRUCTOR_INPUT"]), "Expected 75 until shadow independence captured"),
        ("crew_resolved", float(crew_ok), "Via schedule_reservation/dispatch after join fix"),
    ]:
        con.execute(
            """INSERT INTO phase10c_evidence_summary
            (metric_name,metric_value,n,notes,analysis_version,generated_at) VALUES (?,?,?,?,?,?)""",
            (name, val, n, notes, VERSION, NOW),
        )

    for code, cnt in link.items():
        con.execute(
            """INSERT INTO phase10c_linkage_finding
            (finding_code,n,notes,analysis_version,generated_at) VALUES (?,?,?,?,?)""",
            (
                code,
                int(cnt),
                "Correct join: sessions.reservation_uuid = slots.scheduler_record_id; crew.schedule_slot_id = slots.id. "
                "Prior Phase 10C freeze used wrong column names (slot_id / reservation_uuid on slots).",
                VERSION,
                NOW,
            ),
        )

    maneuvers = [
        "go_around",
        "normal_approach",
        "normal_landing",
        "power_off_stall",
        "power_on_stall",
        "slow_flight",
        "steep_turn",
    ]
    freeze_markers = int(
        main.execute(
            "SELECT COALESCE(SUM(marker_count),0) FROM phase10c_cohort_session WHERE freeze_id=?",
            (FREEZE_ID,),
        ).fetchone()[0]
    )
    for ex in maneuvers:
        total = 0
        usable_b = 0
        usable_t = 0
        reviewed = 0
        notes = []
        try:
            total = int(
                main.execute(
                    "SELECT COUNT(*) FROM phase8_marker_attempt WHERE canonical_exercise_id=?",
                    (ex,),
                ).fetchone()[0]
            )
            notes.append("phase8_marker_attempt pool (may include local simulation — not freeze-session-denominator)")
        except sqlite3.OperationalError:
            total = 0
        if total == 0:
            try:
                total = int(
                    main.execute(
                        "SELECT COUNT(*) FROM pilot_exercise_attempt WHERE canonical_exercise_id=?",
                        (ex,),
                    ).fetchone()[0]
                )
                notes.append("pilot_exercise_attempt fallback")
            except sqlite3.OperationalError:
                total = 0
        notes.append(f"freeze_total_markers_all_exercises={freeze_markers}")
        try:
            reviewed = int(
                main.execute(
                    """SELECT COUNT(DISTINCT attempt_id) FROM phase10c_clinic_review
                       WHERE exercise_id=? AND overall_verdict!='PENDING'
                         AND boundary_verdict!='PENDING'""",
                    (ex,),
                ).fetchone()[0]
            )
        except sqlite3.OperationalError:
            reviewed = 0
        try:
            usable_b = int(
                main.execute(
                    """SELECT COUNT(*) FROM phase8_marker_attempt
                       WHERE canonical_exercise_id=? AND boundary_confidence >= 0.55""",
                    (ex,),
                ).fetchone()[0]
            )
        except sqlite3.OperationalError:
            usable_b = 0
        try:
            usable_t = int(
                main.execute(
                    """SELECT COUNT(DISTINCT attempt_id) FROM pilot_objective_metric m
                       JOIN pilot_exercise_attempt a ON a.attempt_id=m.attempt_id
                       WHERE a.canonical_exercise_id=?""",
                    (ex,),
                ).fetchone()[0]
            )
        except sqlite3.OperationalError:
            usable_t = 0
        con.execute(
            """INSERT INTO phase10c_attempt_denominator
            (canonical_exercise_id,total_live_attempts,usable_boundary,usable_telemetry,examiner_reviewed,
             boundary_correct,metric_correct,tolerance_agree,disposition,notes,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)""",
            (
                ex,
                total,
                usable_b,
                usable_t,
                reviewed,
                None,
                None,
                None,
                "INSUFFICIENT_EVIDENCE",
                "; ".join(notes + ["No examiner-reviewed attempts — no maneuver verdict"]),
                VERSION,
                NOW,
            ),
        )

    try:
        for aid, rev in main.execute(
            """SELECT attempt_id, reviewer_id FROM phase10c_clinic_review
               WHERE overall_verdict='PENDING' OR boundary_verdict='PENDING' OR boundary_verdict IS NULL"""
        ):
            con.execute(
                """INSERT INTO phase10c_review_queue
                (queue_type,ref_id,priority,status,reason,payload_json,analysis_version,generated_at)
                VALUES (?,?,?,?,?,?,?,?)""",
                (
                    "CLINIC",
                    f"{aid}|{rev}",
                    10,
                    "OPEN",
                    "Dual dimensional human review required",
                    json.dumps({"attempt_id": aid, "reviewer_id": rev}),
                    VERSION,
                    NOW,
                ),
            )
    except sqlite3.OperationalError:
        pass

    try:
        tx_rows = list(
            main.execute(
                """SELECT operational_session_uuid, quality_class, aircraft FROM phase10c_transcript_quality
                   WHERE operational_session_uuid IN
                     (SELECT operational_session_uuid FROM phase10c_cohort_session WHERE freeze_id=?)
                   ORDER BY CASE quality_class WHEN 'USABLE' THEN 0 WHEN 'LIMITED' THEN 1 ELSE 2 END
                   LIMIT 25""",
                (FREEZE_ID,),
            )
        )
    except sqlite3.OperationalError:
        tx_rows = []
    for i, (op, q, ac) in enumerate(tx_rows):
        con.execute(
            """INSERT INTO phase10c_review_queue
            (queue_type,ref_id,priority,status,reason,payload_json,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?,?)""",
            (
                "TRANSCRIPT",
                op,
                20 + i,
                "OPEN",
                "Human classify GOOD/USABLE/LIMITED/UNUSABLE/MISSING; check speaker/ATC/prompts",
                json.dumps({"provisional_class": q, "aircraft": ac, "source": "SYSTEM_PROVISIONAL"}),
                VERSION,
                NOW,
            ),
        )

    try:
        claims = list(main.execute("SELECT claim_id, claim_text, assessment_source FROM shadow_debrief_claim LIMIT 40"))
        for i, (cid, text, src) in enumerate(claims):
            con.execute(
                """INSERT INTO phase10c_review_queue
                (queue_type,ref_id,priority,status,reason,payload_json,analysis_version,generated_at)
                VALUES (?,?,?,?,?,?,?,?)""",
                (
                    "CLAIM",
                    cid,
                    30 + i,
                    "OPEN",
                    "Classify FULLY_SUPPORTED/PARTIAL/UNSUPPORTED/MISLEADING — evidence must justify wording",
                    json.dumps({"claim_text": (text or "")[:500], "source": src}),
                    VERSION,
                    NOW,
                ),
            )
    except sqlite3.OperationalError:
        pass

    con.execute(
        """INSERT INTO phase10c_review_queue
        (queue_type,ref_id,priority,status,reason,payload_json,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?)""",
        (
            "WORKLOAD",
            "INSTRUMENT_LIVE_REVIEWS",
            5,
            "OPEN",
            "Capture real instructor review timings via admin workload form; Phase8 2.5min is not evidence",
            json.dumps({"segments": ["routine", "complex", "high_exercise_count"]}),
            VERSION,
            NOW,
        ),
    )

    for op, markers in main.execute(
        """SELECT operational_session_uuid, marker_count FROM phase10c_cohort_session
           WHERE freeze_id=? AND marker_count>0 ORDER BY marker_count DESC LIMIT 30""",
        (FREEZE_ID,),
    ):
        con.execute(
            """INSERT INTO phase10c_review_queue
            (queue_type,ref_id,priority,status,reason,payload_json,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?,?)""",
            (
                "BOUNDARY_METRIC",
                op,
                15,
                "OPEN",
                "Reviewable live attempts — validate boundary + objective metrics (not session count)",
                json.dumps({"marker_count": markers}),
                VERSION,
                NOW,
            ),
        )

    cache_dir = ROOT / "tmp/analytics/phase7_llm_cache"
    cached = len(list(cache_dir.glob("*.json"))) if cache_dir.is_dir() else 0
    processed = 0
    try:
        processed = int(
            main.execute(
                """SELECT COUNT(DISTINCT text_hash) FROM analysis_phase6_narrative_extraction
                   WHERE extractor IN ('LLM_V1_REUSED','phase7-extract-v1-llm')"""
            ).fetchone()[0]
        )
    except sqlite3.OperationalError:
        pass
    eligible = 0
    try:
        eligible = int(main.execute("SELECT COUNT(*) FROM analysis_phase6_nlp_population").fetchone()[0])
    except sqlite3.OperationalError:
        eligible = 0
    log_path = ROOT / "storage/logs/phase7_llm_enrich.log"
    job = "NOT_STARTED"
    if log_path.exists():
        t = log_path.read_text(errors="ignore")
        if "phase7_05 complete" in t:
            job = "COMPLETED"
        elif "LLM enrich remaining" in t:
            job = "RUNNING"
    remaining = max(0, eligible - processed)
    con.execute(
        """INSERT INTO phase10c_llm_progress_snapshot
        (eligible,processed,successful,failed,remaining,job_status,notes,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?)""",
        (eligible, processed, processed, 0, remaining, job, f"cache_files={cached}; not a Phase 10C gate", VERSION, NOW),
    )

    # Distinct people from investigation crew table (authoritative for this run)
    sc = int(con.execute("SELECT COUNT(DISTINCT student_id) FROM phase10c_session_crew WHERE student_id IS NOT NULL").fetchone()[0])
    ic = int(con.execute("SELECT COUNT(DISTINCT instructor_id) FROM phase10c_session_crew WHERE instructor_id IS NOT NULL").fetchone()[0])
    try:
        main.execute(
            "UPDATE phase10c_cohort_freeze SET student_count=?, instructor_count=? WHERE freeze_id=?",
            (sc, ic, FREEZE_ID),
        )
        main.commit()
    except sqlite3.OperationalError:
        pass

    return {
        "full": full,
        "near_full": near_full,
        "miss": dict(miss),
        "crew_ok": crew_ok,
        "combo": combo.most_common(8),
        "llm": {"processed": processed, "remaining": remaining, "job": job},
        "students": sc,
        "instructors": ic,
    }


def main() -> None:
    ensure_cli_env_loaded()
    INV_DB.parent.mkdir(parents=True, exist_ok=True)
    con = sqlite3.connect(INV_DB, timeout=60)
    con.executescript(SCHEMA)
    main_db = sqlite3.connect(f"file:{DB}?mode=ro", uri=True, timeout=60)
    mysql = mysql_ro()
    try:
        result = investigate(con, main_db, mysql)
        con.commit()
    finally:
        mysql.close()
        main_db.close()
        con.close()
    log(json.dumps({"phase10c_02": result, "inv_db": str(INV_DB)}, default=str))


if __name__ == "__main__":
    main()
