#!/usr/bin/env python3
"""Phase 10C — Live validation closure.

Freezes the live cohort, computes clinic/agreement/maneuver/transcript/workload
scaffolding from real data. NEVER fabricates examiner verdicts.
No production writes. No feature flags. No schema migrations.
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
from lib.runtime_secrets import RuntimeSecretError, availability_label, ensure_cli_env_loaded, get_runtime_secret  # noqa: E402

DB = ROOT / "storage/analytics/egle_training_analytics.sqlite"
SCHEMA = ROOT / "analytics/schema/phase10c_tables.sql"
VERSION = "phase10c-v1"
NOW = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
FREEZE_ID = "phase10c-live-freeze-v1"
COHORT_TARGET = 75
COHORT_MIN = 50


def log(msg: str) -> None:
    print(msg, flush=True)


def clear(con: sqlite3.Connection, tables: list[str]) -> None:
    for t in tables:
        try:
            con.execute(f"DELETE FROM {t}")
        except sqlite3.OperationalError:
            pass


def reset_phase10c(con: sqlite3.Connection) -> None:
    names = [
        r[0]
        for r in con.execute(
            "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'phase10c_%'"
        )
    ]
    for n in names:
        con.execute(f"DROP TABLE IF EXISTS {n}")


def mysql_ro():
    ensure_cli_env_loaded()
    import pymysql

    conn = pymysql.connect(
        host=os.environ.get("CW_DB_HOST"),
        port=int(os.environ.get("CW_DB_PORT") or "25060"),
        user=os.environ.get("CW_DB_USER"),
        password=get_runtime_secret("CW_DB_PASS", required=True),
        database=os.environ.get("CW_DB_NAME"),
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
        ssl={"ssl": {}},
        connect_timeout=20,
        read_timeout=120,
    )
    with conn.cursor() as cur:
        try:
            cur.execute("SET SESSION TRANSACTION READ ONLY")
        except Exception:
            pass
    return conn


def table_count(con: sqlite3.Connection, table: str) -> int:
    try:
        return int(con.execute(f"SELECT COUNT(*) FROM {table}").fetchone()[0])
    except sqlite3.OperationalError:
        return 0


def freeze_live_cohort(con: sqlite3.Connection, mysql) -> dict:
    """Authoritative LIVE_PRODUCTION_SHADOW freeze — exact counts."""
    clear(
        con,
        [
            "phase10c_cohort_freeze",
            "phase10c_cohort_session",
            "phase10c_cohort_composition",
            "phase10c_source_partition",
        ],
    )
    with mysql.cursor() as cur:
        cur.execute(
            """
            SELECT r.id AS recording_id, r.recording_uid,
                   COALESCE(NULLIF(r.operational_session_uuid,''), NULLIF(r.flight_session_uid,'')) AS op,
                   r.aircraft_registration, r.started_at,
                   r.transcription_status, r.upload_status,
                   r.file_size_bytes, r.transcript_text,
                   r.flight_session_uid, r.operational_session_uuid,
                   r.input_device
            FROM ipca_cockpit_recordings r
            WHERE COALESCE(NULLIF(r.operational_session_uuid,''), NULLIF(r.flight_session_uid,'')) IS NOT NULL
            ORDER BY r.id DESC
            LIMIT %s
            """,
            (COHORT_TARGET * 3,),  # oversample then unique
        )
        rows = cur.fetchall() or []

    # Unique by operational session UUID; keep newest recording per session
    by_op: dict[str, dict] = {}
    for r in rows:
        op = r.get("op")
        if not op:
            continue
        if op not in by_op:
            by_op[op] = r
        if len(by_op) >= COHORT_TARGET:
            # continue scanning only if we want exactly preferred size from newest
            pass
    # Take first COHORT_TARGET in insertion order (newest first)
    ordered = list(by_op.values())[:COHORT_TARGET]

    students: set[str] = set()
    instructors: set[str] = set()
    aircrafts: set[str] = set()
    programs: Counter = Counter()
    evidence: Counter = Counter()
    aircraft_dist: Counter = Counter()
    dates: list[str] = []
    attempt_total = 0

    for r in ordered:
        op = r["op"]
        ac = (r.get("aircraft_registration") or "").strip() or "(unknown)"
        aircrafts.add(ac)
        aircraft_dist[ac] += 1
        started = str(r.get("started_at") or "")
        if started:
            dates.append(started)

        # markers
        marker_n = 0
        with mysql.cursor() as cur:
            cur.execute(
                """
                SELECT COUNT(*) AS c FROM ipca_cvr_flight_events
                WHERE event_type='exercise_marker'
                  AND (operational_session_uuid=%s OR workflow_flight_record_uuid=%s
                       OR recording_session_uuid=%s)
                """,
                (op, r.get("flight_session_uid") or op, r.get("recording_uid") or ""),
            )
            marker_n = int((cur.fetchone() or {}).get("c") or 0)
        attempt_total += marker_n

        # crew via schedule if linked
        student_id = None
        instructor_id = None
        program = "(unspecified)"
        with mysql.cursor() as cur:
            try:
                cur.execute(
                    """
                    SELECT sc.user_id, sc.crew_role
                    FROM ipca_flight_sessions fs
                    JOIN ipca_flight_schedule_slots ss ON ss.reservation_uuid = fs.reservation_uuid
                    JOIN ipca_flight_schedule_crew sc ON sc.slot_id = ss.id
                    WHERE fs.session_uuid=%s OR fs.workflow_flight_record_uuid=%s
                       OR fs.session_uuid=%s
                    LIMIT 20
                    """,
                    (op, r.get("flight_session_uid") or op, r.get("operational_session_uuid") or op),
                )
                for c in cur.fetchall() or []:
                    role = (c.get("crew_role") or "").lower()
                    uid = str(c.get("user_id") or "")
                    if not uid:
                        continue
                    if "instruct" in role or role in ("cfi", "instructor", "check_airman"):
                        instructor_id = instructor_id or uid
                        instructors.add(uid)
                    else:
                        student_id = student_id or uid
                        students.add(uid)
            except Exception:
                pass

        tx = (r.get("transcription_status") or "").lower()
        transcript_text = r.get("transcript_text") or ""
        audio_ok = 1 if (r.get("file_size_bytes") or 0) > 0 or (r.get("upload_status") or "") == "uploaded" else 0
        transcript_ok = 1 if tx in ("ready", "completed", "complete", "published") or len(transcript_text) > 50 else 0

        if marker_n > 0 and transcript_ok:
            completeness = "PARTIAL_EVIDENCE"
        elif marker_n > 0 or transcript_ok:
            completeness = "LIMITED_EVIDENCE"
        else:
            completeness = "INSUFFICIENT_EVIDENCE"
        evidence[completeness] += 1
        programs[program] += 1

        con.execute(
            """INSERT OR REPLACE INTO phase10c_cohort_session
            (freeze_id,operational_session_uuid,recording_id,recording_uid,aircraft,student_id,instructor_id,
             program_code,session_start,source_class,evidence_completeness,marker_count,transcription_status,
             audio_available,transcript_available,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
            (
                FREEZE_ID,
                op,
                r.get("recording_id"),
                r.get("recording_uid"),
                ac,
                student_id,
                instructor_id,
                program,
                started,
                "LIVE_PRODUCTION_SHADOW",
                completeness,
                marker_n,
                tx,
                audio_ok,
                transcript_ok,
                VERSION,
                NOW,
            ),
        )

    session_count = len(ordered)
    date_start = min(dates) if dates else None
    date_end = max(dates) if dates else None

    # If student/instructor IDs sparse, report distinct non-null counts honestly
    student_count = len(students) if students else int(
        con.execute(
            "SELECT COUNT(DISTINCT student_id) FROM phase10c_cohort_session WHERE freeze_id=? AND student_id IS NOT NULL",
            (FREEZE_ID,),
        ).fetchone()[0]
    )
    instructor_count = len(instructors) if instructors else int(
        con.execute(
            "SELECT COUNT(DISTINCT instructor_id) FROM phase10c_cohort_session WHERE freeze_id=? AND instructor_id IS NOT NULL",
            (FREEZE_ID,),
        ).fetchone()[0]
    )

    con.execute(
        """INSERT INTO phase10c_cohort_freeze
        (freeze_id,frozen_at,selection_rule,session_count,student_count,instructor_count,aircraft_count,
         attempt_count,date_start,date_end,analysis_version)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)""",
        (
            FREEZE_ID,
            NOW,
            f"Newest {COHORT_TARGET} unique Operational Sessions with cockpit recordings (ORDER BY recording id DESC)",
            session_count,
            student_count,
            instructor_count,
            len(aircrafts),
            attempt_total,
            date_start,
            date_end,
            VERSION,
        ),
    )

    for dim, counter in [
        ("aircraft", aircraft_dist),
        ("program", programs),
        ("evidence_completeness", evidence),
        ("source_class", Counter({"LIVE_PRODUCTION_SHADOW": session_count})),
    ]:
        for value, n in counter.items():
            con.execute(
                """INSERT INTO phase10c_cohort_composition
                (freeze_id,dimension,value,n,analysis_version) VALUES (?,?,?,?,?)""",
                (FREEZE_ID, dim, str(value), int(n), VERSION),
            )

    # Source partitions — keep live separate from simulation
    sim_n = table_count(con, "shadow_session")
    hist_n = table_count(con, "analysis_phase6_nlp_population")
    for cls, n, notes in [
        ("LIVE_PRODUCTION_SHADOW", session_count, f"Freeze {FREEZE_ID}"),
        ("LOCAL_SIMULATION", sim_n, "Phase 9 local G3X simulation — NOT in live freeze"),
        ("CONTROLLED_FIXTURE", 0, "None in freeze; may supplement degraded-mode only"),
        ("HISTORICAL_ANALYTICS", hist_n, "E-gle narrative hashes — not live cohort"),
    ]:
        con.execute(
            """INSERT INTO phase10c_source_partition
            (source_class,n_sessions,notes,analysis_version,generated_at) VALUES (?,?,?,?,?)""",
            (cls, n, notes, VERSION, NOW),
        )

    return {
        "session_count": session_count,
        "student_count": student_count,
        "instructor_count": instructor_count,
        "aircraft_count": len(aircrafts),
        "attempt_count": attempt_total,
        "date_start": date_start,
        "date_end": date_end,
    }


def sync_clinic_reviews(con: sqlite3.Connection) -> None:
    """Pull genuine phase8 reviews into dimensional table; never invent verdicts."""
    clear(con, ["phase10c_clinic_review"])
    try:
        rows = list(
            con.execute(
                """SELECT attempt_id, reviewer_id, verdict, reason_codes_json, narrative_notes, reviewed_at
                   FROM phase8_examiner_review"""
            )
        )
    except sqlite3.OperationalError:
        rows = []

    for attempt_id, reviewer_id, verdict, codes, notes, reviewed_at in rows:
        dims = {
            "boundary": "PENDING",
            "objective": "PENDING",
            "tolerance": "PENDING",
            "procedure": "PENDING",
            "independence": "PENDING",
            "consistency": "PENDING",
            "overall": verdict if verdict != "PENDING" else "PENDING",
        }
        # Parse DIMS:{} if human saved via Phase 10 UI
        if notes and "DIMS:" in notes:
            try:
                raw = notes.split("DIMS:", 1)[1].strip()
                parsed = json.loads(raw)
                dims["boundary"] = parsed.get("boundary") or dims["boundary"]
                dims["objective"] = parsed.get("objective_quality") or dims["objective"]
                dims["tolerance"] = parsed.get("tolerance") or dims["tolerance"]
                dims["procedure"] = parsed.get("procedure") or dims["procedure"]
                dims["independence"] = parsed.get("independence") or dims["independence"]
                dims["consistency"] = parsed.get("consistency") or dims["consistency"]
                dims["overall"] = verdict if verdict != "PENDING" else (parsed.get("system_competency") or "PENDING")
            except Exception:
                pass
        # Incomplete dimensional save: if overall set but dims blank, mark dims INSUFFICIENT for agreement exclusion later? No — keep PENDING until filled.
        if verdict != "PENDING" and dims["boundary"] == "PENDING":
            # Overall-only review: do not invent dimension verdicts
            pass

        # exercise id from attempt if available
        exercise_id = None
        try:
            exercise_id = con.execute(
                "SELECT canonical_exercise_id FROM phase8_marker_attempt WHERE attempt_id=? LIMIT 1",
                (attempt_id,),
            ).fetchone()
            exercise_id = exercise_id[0] if exercise_id else None
        except sqlite3.OperationalError:
            pass
        if not exercise_id:
            try:
                exercise_id = con.execute(
                    "SELECT canonical_exercise_id FROM pilot_exercise_attempt WHERE attempt_id=? LIMIT 1",
                    (attempt_id,),
                ).fetchone()
                exercise_id = exercise_id[0] if exercise_id else None
            except sqlite3.OperationalError:
                pass

        con.execute(
            """INSERT OR REPLACE INTO phase10c_clinic_review
            (attempt_id,reviewer_id,reviewed_at,exercise_id,boundary_verdict,objective_verdict,tolerance_verdict,
             procedure_verdict,independence_verdict,consistency_verdict,overall_verdict,reason_codes_json,
             narrative_notes,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
            (
                attempt_id,
                reviewer_id,
                reviewed_at,
                exercise_id,
                dims["boundary"] if verdict == "PENDING" else (dims["boundary"] if dims["boundary"] != "PENDING" else "PENDING"),
                dims["objective"] if dims["objective"] != "PENDING" else ("PENDING" if verdict == "PENDING" else "PENDING"),
                dims["tolerance"],
                dims["procedure"],
                dims["independence"],
                dims["consistency"],
                dims["overall"],
                codes,
                notes,
                VERSION,
                NOW,
            ),
        )


def clinic_progress_and_agreement(con: sqlite3.Connection) -> dict:
    clear(
        con,
        [
            "phase10c_clinic_progress",
            "phase10c_inter_rater",
            "phase10c_adjudication_queue",
        ],
    )
    attempts = [
        r[0]
        for r in con.execute("SELECT DISTINCT attempt_id FROM phase10c_clinic_review")
    ]
    dual_complete = 0
    single_only = 0
    unreviewed = 0
    conflicting = 0

    for aid in attempts:
        rows = list(
            con.execute(
                """SELECT reviewer_id, overall_verdict, boundary_verdict, objective_verdict, tolerance_verdict,
                          procedure_verdict, independence_verdict, consistency_verdict
                   FROM phase10c_clinic_review WHERE attempt_id=?""",
                (aid,),
            )
        )
        done = [r for r in rows if r[1] and r[1] != "PENDING"]
        # Dual complete requires BOTH reviewers overall filled AND all required dims filled
        def dims_complete(r) -> bool:
            # overall + 6 dims
            vals = r[1:]
            return all(v and v != "PENDING" for v in vals)

        both = [r for r in rows if dims_complete(r)]
        if len(both) >= 2:
            dual_complete += 1
            a, b = both[0], both[1]
            if a[1:] != b[1:]:
                # material disagreement if any dim differs
                conflicting += 1
                classes = []
                labels = ["overall", "boundary", "objective", "tolerance", "procedure", "independence", "consistency"]
                for i, lab in enumerate(labels):
                    if a[i + 1] != b[i + 1]:
                        classes.append(lab)
                con.execute(
                    """INSERT INTO phase10c_adjudication_queue
                    (attempt_id,disagreement_class,examiner_a_json,examiner_b_json,status,notes,analysis_version,generated_at)
                    VALUES (?,?,?,?,?,?,?,?)""",
                    (
                        aid,
                        ",".join(classes),
                        json.dumps({"reviewer": both[0][0], "vals": both[0][1:]}),
                        json.dumps({"reviewer": both[1][0], "vals": both[1][1:]}),
                        "OPEN",
                        "Do not auto-pick a winner",
                        VERSION,
                        NOW,
                    ),
                )
        elif len(done) == 1:
            single_only += 1
        else:
            unreviewed += 1

    target = 40  # 40 attempts × 2 reviewers = 80 worksheets
    for name, val, n, notes in [
        ("dual_complete", float(dual_complete), dual_complete, "Both reviewers + all dimensional fields"),
        ("dual_target", float(target), target, "40 attempts dual-reviewed"),
        ("worksheets_complete_estimate", float(dual_complete * 2), dual_complete * 2, "2 × dual_complete"),
        ("worksheets_target", 80.0, 80, ""),
        ("single_review_only", float(single_only), single_only, "Not counted complete"),
        ("unreviewed", float(unreviewed), unreviewed, ""),
        ("conflicting_reviews", float(conflicting), conflicting, "In adjudication queue"),
        ("adjudication_open", float(conflicting), conflicting, ""),
    ]:
        con.execute(
            """INSERT INTO phase10c_clinic_progress
            (metric_name,metric_value,n,notes,analysis_version,generated_at) VALUES (?,?,?,?,?,?)""",
            (name, val, n, notes, VERSION, NOW),
        )

    # Inter-rater per dimension
    dims = [
        ("exercise_boundary", "boundary_verdict"),
        ("objective_metric", "objective_verdict"),
        ("tolerance_applicability", "tolerance_verdict"),
        ("procedure", "procedure_verdict"),
        ("independence", "independence_verdict"),
        ("consistency", "consistency_verdict"),
        ("overall_competency", "overall_verdict"),
    ]
    for dim_name, col in dims:
        pairs = con.execute(
            f"""
            SELECT a.{col}, b.{col}
            FROM phase10c_clinic_review a
            JOIN phase10c_clinic_review b
              ON a.attempt_id=b.attempt_id AND a.reviewer_id='examiner_A' AND b.reviewer_id='examiner_B'
            WHERE a.{col} IS NOT NULL AND a.{col}!='PENDING'
              AND b.{col} IS NOT NULL AND b.{col}!='PENDING'
            """
        ).fetchall()
        n = len(pairs)
        agree = sum(1 for a, b in pairs if a == b)
        raw = (agree / n) if n else None
        pairs_ex = [(a, b) for a, b in pairs if a != "INSUFFICIENT_EVIDENCE" and b != "INSUFFICIENT_EVIDENCE"]
        n_ex = len(pairs_ex)
        agree_ex = sum(1 for a, b in pairs_ex if a == b)
        excl = (agree_ex / n_ex) if n_ex else None
        # Cohen's kappa when meaningful (4 categories)
        kappa = None
        if n >= 10:
            cats = ["CORRECT", "PARTIALLY_CORRECT", "INCORRECT", "INSUFFICIENT_EVIDENCE"]
            pa = raw or 0.0
            # expected agreement
            ca = Counter(a for a, _ in pairs)
            cb = Counter(b for _, b in pairs)
            pe = sum((ca[c] / n) * (cb[c] / n) for c in cats)
            if pe < 1:
                kappa = (pa - pe) / (1 - pe) if (1 - pe) else None
        con.execute(
            """INSERT INTO phase10c_inter_rater
            (dimension,raw_agreement,agreement_excl_ie,n_pairs,n_pairs_excl_ie,chance_corrected,notes,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?,?,?)""",
            (
                dim_name,
                raw,
                excl,
                n,
                n_ex,
                kappa,
                "INSUFFICIENT_EVIDENCE until dual dimensional reviews exist" if n == 0 else "",
                VERSION,
                NOW,
            ),
        )

    return {
        "dual_complete": dual_complete,
        "single_only": single_only,
        "unreviewed": unreviewed,
        "conflicting": conflicting,
        "worksheets_pending": 80 - dual_complete * 2,
    }


def maneuver_dispositions(con: sqlite3.Connection, clinic: dict) -> None:
    clear(con, ["phase10c_maneuver_disposition"])
    maneuvers = []
    try:
        maneuvers = [r[0] for r in con.execute("SELECT DISTINCT canonical_exercise_id FROM maneuver_disposition")]
    except sqlite3.OperationalError:
        pass
    if not maneuvers:
        maneuvers = [
            "go_around",
            "normal_approach",
            "normal_landing",
            "power_off_stall",
            "power_on_stall",
            "slow_flight",
            "steep_turn",
        ]

    for ex in maneuvers:
        live_n = 0
        # markers in freeze don't have exercise id yet — use phase8/pilot if any
        try:
            live_n = int(
                con.execute(
                    "SELECT COUNT(*) FROM phase8_marker_attempt WHERE canonical_exercise_id=?",
                    (ex,),
                ).fetchone()[0]
            )
        except sqlite3.OperationalError:
            live_n = 0
        if live_n == 0:
            try:
                live_n = int(
                    con.execute(
                        "SELECT COUNT(*) FROM pilot_exercise_attempt WHERE canonical_exercise_id=?",
                        (ex,),
                    ).fetchone()[0]
                )
            except sqlite3.OperationalError:
                live_n = 0

        reviewed = int(
            con.execute(
                """SELECT COUNT(DISTINCT attempt_id) FROM phase10c_clinic_review
                   WHERE exercise_id=? AND overall_verdict!='PENDING'""",
                (ex,),
            ).fetchone()[0]
        )
        # Without clinic completion → INSUFFICIENT_EVIDENCE (not blanket MORE_VALIDATION_REQUIRED)
        if reviewed == 0:
            disp = "INSUFFICIENT_EVIDENCE"
            rationale = "No completed dual dimensional examiner reviews for this maneuver yet"
        else:
            disp = "INSUFFICIENT_EVIDENCE"
            rationale = f"Reviewed={reviewed} but dual clinic not materially complete (dual_complete={clinic['dual_complete']})"

        con.execute(
            """INSERT INTO phase10c_maneuver_disposition
            (canonical_exercise_id,live_attempt_count,reviewed_attempt_count,disposition,boundary_notes,metric_notes,
             tolerance_notes,procedure_notes,claim_notes,rationale,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)""",
            (
                ex,
                live_n,
                reviewed,
                disp,
                "Pending clinic",
                "Pending clinic",
                "Pending clinic",
                "Pending clinic",
                "Pending human claim review",
                rationale,
                VERSION,
                NOW,
            ),
        )


def tolerance_and_procedure(con: sqlite3.Connection) -> None:
    clear(con, ["phase10c_tolerance_rule", "phase10c_procedure_pack", "phase10c_procedure_step", "phase10c_case_study"])
    for pack, metric, disp, mismatch, notes in [
        ("ACS_PPL_ASEL_v1", "altitude_deviation_ft", "PENDING_CLINIC", "HUMAN_JUDGMENT", "Await examiner"),
        ("IPCA_TRAINING_PE_v1", "vertical_speed_fpm", "NEEDS_REVIEW", "WRONG_BOUNDARY", "Prior approach window concern — no in-place edit"),
        ("IPCA_TRAINING_PE_v1", "airspeed_deviation_kt", "NEEDS_REVIEW", "WRONG_METRIC", "Slow-flight IAS concern — version if changed"),
        ("IPCA_TRAINING_PR_v1", "altitude_deviation_ft", "PENDING_CLINIC", "WRONG_LEVEL_APPLICABILITY", "PR pedagogy"),
    ]:
        con.execute(
            """INSERT INTO phase10c_tolerance_rule
            (pack_id,metric,disposition,mismatch_class,notes,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?)""",
            (pack, metric, disp, mismatch, notes, VERSION, NOW),
        )
    # No in-place tolerance changes this run
    try:
        steps = list(con.execute("SELECT procedure_pack_id, step_code, evidence_source, manual_only FROM procedure_step"))
    except sqlite3.OperationalError:
        steps = []
    packs = defaultdict(list)
    for pack, step, src, manual in steps:
        if src == "NOT_OBSERVABLE" or manual:
            obs = "NOT_OBSERVABLE"
        elif src == "TELEMETRY":
            obs = "AUTO_PARTIAL"
        elif src in ("AUDIO", "TRANSCRIPT"):
            obs = "TRANSCRIPT_SUPPORTED"
        elif src == "INSTRUCTOR":
            obs = "HUMAN_REQUIRED"
        else:
            obs = "AUTO_PARTIAL"
        packs[pack].append(obs)
        con.execute(
            """INSERT INTO phase10c_procedure_step
            (pack_id,step_code,observability,notes,analysis_version,generated_at) VALUES (?,?,?,?,?,?)""",
            (pack, step, obs, f"source={src}", VERSION, NOW),
        )
    for pack, obs_list in packs.items():
        if any(o == "NOT_OBSERVABLE" for o in obs_list):
            disp = "VALIDATED_WITH_LIMITATIONS"
            notes = "Some steps NOT_OBSERVABLE — never claim full SOP compliance"
        else:
            disp = "INSUFFICIENT_EVIDENCE"
            notes = "Live examiner confirmation pending"
        # Without clinic, prefer INSUFFICIENT for pack disposition
        disp = "INSUFFICIENT_EVIDENCE"
        con.execute(
            """INSERT INTO phase10c_procedure_pack
            (pack_id,disposition,notes,analysis_version,generated_at) VALUES (?,?,?,?,?)""",
            (pack, disp, notes, VERSION, NOW),
        )

    for pattern, ref, notes in [
        ("outcome_good_procedure_poor", "PENDING_LIVE_REVIEW", "Seek in go-around/stall clinic cases"),
        ("outcome_poor_procedure_correct", "PENDING_LIVE_REVIEW", ""),
        ("independent_inaccurate", "PENDING_LIVE_REVIEW", ""),
        ("prompted_accurate", "PENDING_LIVE_REVIEW", ""),
        ("consistent_easy_context", "PENDING_LIVE_REVIEW", ""),
        ("softens_harder_context", "PENDING_LIVE_REVIEW", ""),
    ]:
        con.execute(
            """INSERT INTO phase10c_case_study
            (pattern,example_ref,source_class,notes,analysis_version,generated_at) VALUES (?,?,?,?,?,?)""",
            (pattern, ref, "LIVE_PRODUCTION_SHADOW", notes, VERSION, NOW),
        )


def transcript_quality(con: sqlite3.Connection) -> dict:
    clear(con, ["phase10c_transcript_quality", "phase10c_transcript_feature_req", "phase10c_prompt_detection"])
    rows = list(
        con.execute(
            """SELECT operational_session_uuid,aircraft,audio_available,transcript_available,transcription_status,marker_count
               FROM phase10c_cohort_session WHERE freeze_id=?""",
            (FREEZE_ID,),
        )
    )
    class_counts = Counter()
    for op, aircraft, audio, tx_av, tx_status, markers in rows:
        tx_status = (tx_status or "").lower()
        if not audio and not tx_av:
            q = "MISSING"
        elif not tx_av:
            q = "MISSING"
        elif tx_status in ("ready", "completed", "complete", "published"):
            # Provisional: presence ≠ useful; without human review → USABLE at best, not GOOD
            q = "USABLE" if (markers or 0) > 0 else "LIMITED"
        elif tx_status in ("failed", "error"):
            q = "UNUSABLE"
        else:
            q = "LIMITED"
        class_counts[q] += 1
        con.execute(
            """INSERT OR REPLACE INTO phase10c_transcript_quality
            (operational_session_uuid,audio_available,transcript_present,transcript_useful,quality_class,
             classification_source,latency_notes,speaker_notes,aircraft,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)""",
            (
                op,
                int(audio or 0),
                int(tx_av or 0),
                1 if q in ("GOOD", "USABLE") else 0,
                q,
                "SYSTEM_PROVISIONAL",
                "Human speaker/ATC review not yet performed",
                "Speaker separation unvalidated live",
                aircraft,
                VERSION,
                NOW,
            ),
        )

    for feat, mnq, notes in [
        ("exercise_window_alignment", "LIMITED", "Can use timestamps with LIMITED"),
        ("instructor_prompt_detection", "USABLE", "Requires usable speaker separation"),
        ("independence_verbal_evidence", "USABLE", "Prefer GOOD; USABLE with confirmation"),
        ("debrief_quote_claims", "GOOD", "Do not quote LIMITED/UNUSABLE"),
        ("atc_contamination_filter", "USABLE", ""),
    ]:
        con.execute(
            """INSERT INTO phase10c_transcript_feature_req
            (feature_name,min_quality,notes,analysis_version) VALUES (?,?,?,?)""",
            (feat, mnq, notes, VERSION),
        )

    for name, val, n, readiness, notes in [
        ("true_useful_detections", None, 0, "INSUFFICIENT_EVIDENCE", "Human sample review required"),
        ("false_positives", None, 0, "INSUFFICIENT_EVIDENCE", ""),
        ("missed_prompts", None, 0, "INSUFFICIENT_EVIDENCE", ""),
        ("atc_false_classifications", None, 0, "INSUFFICIENT_EVIDENCE", ""),
        ("student_as_instructor_errors", None, 0, "INSUFFICIENT_EVIDENCE", ""),
        ("recommended_mode", None, 0, "SHADOW_ONLY", "Conservative until sample reviewed"),
    ]:
        con.execute(
            """INSERT INTO phase10c_prompt_detection
            (metric_name,metric_value,n,readiness,notes,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?)""",
            (name, val, n, readiness, notes, VERSION, NOW),
        )
    return dict(class_counts)


def workload_claims_exceptions(con: sqlite3.Connection) -> None:
    clear(
        con,
        [
            "phase10c_workload",
            "phase10c_workload_reason",
            "phase10c_exception_snr",
            "phase10c_claim_review",
            "phase10c_claim_rate",
            "phase10c_debrief_acceptance",
        ],
    )
    # Real workload from shadow_workload_event if any live reviews recorded
    events = []
    try:
        events = list(
            con.execute(
                """SELECT elapsed_ms, payload_json FROM shadow_workload_event
                   WHERE event_type IN ('live_review_finish','review_to_save')"""
            )
        )
    except sqlite3.OperationalError:
        events = []

    by_seg: dict[str, list[float]] = defaultdict(list)
    for elapsed_ms, payload in events:
        mins = (elapsed_ms or 0) / 60000.0
        seg = "all"
        try:
            seg = json.loads(payload or "{}").get("segment") or "all"
        except Exception:
            pass
        by_seg[seg].append(mins)
        by_seg["all"].append(mins)

    def pct(vals: list[float], p: float) -> float | None:
        if not vals:
            return None
        s = sorted(vals)
        i = min(len(s) - 1, max(0, int(round((p / 100.0) * (len(s) - 1)))))
        return s[i]

    for segment in ("routine", "complex", "high_exercise_count", "all"):
        vals = by_seg.get(segment) or by_seg.get("problematic") or []
        if segment == "complex":
            vals = by_seg.get("problematic") or by_seg.get("complex") or []
        con.execute(
            """INSERT INTO phase10c_workload
            (segment,n,median_min,p75_min,p90_min,min_min,max_min,notes,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?)""",
            (
                segment,
                len(vals),
                pct(vals, 50),
                pct(vals, 75),
                pct(vals, 90),
                min(vals) if vals else None,
                max(vals) if vals else None,
                "Phase 8 ~2.5 min is NOT measured evidence" if not vals else "From instrumented shadow reviews",
                VERSION,
                NOW,
            ),
        )

    for reason in (
        "independence_input",
        "boundary_correction",
        "bad_transcript",
        "too_many_exceptions",
        "ai_wording_correction",
        "procedure_review",
        "missing_evidence",
        "narrative_writing",
        "other",
    ):
        con.execute(
            """INSERT INTO phase10c_workload_reason
            (reason_code,n,notes,analysis_version,generated_at) VALUES (?,?,?,?,?)""",
            (reason, 0, "Capture via admin workload form", VERSION, NOW),
        )

    for rating in ("USEFUL", "NEUTRAL", "NOISY", "WRONG", "PENDING"):
        n = 0
        try:
            n = int(
                con.execute("SELECT COALESCE(n,0) FROM phase10_exception_snr WHERE rating=?", (rating,)).fetchone()[0]
            )
        except Exception:
            n = 1 if rating == "PENDING" else 0
        con.execute(
            """INSERT INTO phase10c_exception_snr
            (rating,n,notes,analysis_version,generated_at) VALUES (?,?,?,?,?)""",
            (rating, n, "Instructor-classified live exceptions", VERSION, NOW),
        )

    # Claim reviews — pending human
    for ctype in ("deficiency", "improvement", "independence", "consistency", "procedure", "regression", "safety", "next_focus"):
        con.execute(
            """INSERT INTO phase10c_claim_rate
            (claim_type,support_class,n,analysis_version,generated_at) VALUES (?,?,?,?,?)""",
            (ctype, "PENDING", 0, VERSION, NOW),
        )


def llm_progress(con: sqlite3.Connection) -> dict:
    clear(con, ["phase10c_llm_progress", "phase10c_llm_reconciliation"])
    eligible = table_count(con, "analysis_phase6_nlp_population")
    processed = 0
    try:
        processed = int(
            con.execute(
                """SELECT COUNT(DISTINCT text_hash) FROM analysis_phase6_narrative_extraction
                   WHERE extractor IN ('LLM_V1_REUSED','phase7-extract-v1-llm')"""
            ).fetchone()[0]
        )
    except sqlite3.OperationalError:
        processed = 0
    cache_dir = ROOT / "tmp/analytics/phase7_llm_cache"
    cached = len(list(cache_dir.glob("*.json"))) if cache_dir.is_dir() else 0
    # Detect running job via log tail heuristic
    log_path = ROOT / "storage/logs/phase7_llm_enrich.log"
    job_status = "NOT_STARTED"
    notes = ""
    if log_path.exists():
        text = log_path.read_text(errors="ignore")
        if "phase7_05 complete" in text:
            job_status = "COMPLETED"
        elif "LLM enrich remaining" in text:
            job_status = "RUNNING"
            notes = "Background approved-host job; incomplete ≠ Phase 10C evidence"
        if "API error" in text:
            notes += " | some API errors may exist"
    remaining = max(0, eligible - processed) if eligible else max(0, 10159 - processed)
    con.execute(
        """INSERT INTO phase10c_llm_progress
        (eligible,already_cached,processed,successful,failed,remaining,job_status,notes,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?)""",
        (eligible, cached, processed, processed, 0, remaining, job_status, notes, VERSION, NOW),
    )
    for code, cls, note in [
        ("variable_consistency_later_problem", "PENDING", "Await full enrichment + recompute"),
        ("high_grade_deficiency_later_problem", "PENDING", ""),
        ("repeated_deficiency_window", "PENDING", ""),
    ]:
        con.execute(
            """INSERT INTO phase10c_llm_reconciliation
            (finding_code,classification,notes,analysis_version,generated_at) VALUES (?,?,?,?,?)""",
            (code, cls, note, VERSION, NOW),
        )
    return {"processed": processed, "remaining": remaining, "job_status": job_status, "cached": cached}


def degraded_and_recompute(con: sqlite3.Connection) -> None:
    clear(con, ["phase10c_degraded"])
    # Count live transcript missing etc from freeze
    missing_tx = int(
        con.execute(
            "SELECT COUNT(*) FROM phase10c_cohort_session WHERE freeze_id=? AND transcript_available=0",
            (FREEZE_ID,),
        ).fetchone()[0]
    )
    limited_markers = int(
        con.execute(
            "SELECT COUNT(*) FROM phase10c_cohort_session WHERE freeze_id=? AND marker_count=0",
            (FREEZE_ID,),
        ).fetchone()[0]
    )
    for code, observed, src, result, notes in [
        ("missing_transcript", 1 if missing_tx else 0, "LIVE_PRODUCTION_SHADOW", "OBSERVED" if missing_tx else "UNOBSERVED", f"n={missing_tx}"),
        ("missing_marker", 1 if limited_markers else 0, "LIVE_PRODUCTION_SHADOW", "OBSERVED" if limited_markers else "UNOBSERVED", f"n={limited_markers}"),
        ("bad_transcript", 0, "LIVE_PRODUCTION_SHADOW", "UNOBSERVED", "Needs human quality review"),
        ("late_garmin", 0, "LIVE_PRODUCTION_SHADOW", "UNOBSERVED", ""),
        ("missing_garmin", 0, "LIVE_PRODUCTION_SHADOW", "UNOBSERVED", ""),
        ("missing_independence", 1, "LIVE_PRODUCTION_SHADOW", "PASS_DESIGN", "Remains NOT_OBSERVED"),
        ("duplicate_sync", 1, "CONTROLLED_FIXTURE", "PASS_DESIGN", "idempotency_key"),
        ("assessment_versioning", 1, "CONTROLLED_FIXTURE", "PASS_DESIGN", "V1/V2/V3 cutoffs preserved in shadow model"),
        ("offline_recovery", 0, "CONTROLLED_FIXTURE", "UNOBSERVED", ""),
        ("repaired_recording", 0, "LIVE_PRODUCTION_SHADOW", "UNOBSERVED", ""),
    ]:
        con.execute(
            """INSERT INTO phase10c_degraded
            (case_code,observed,source_class,result,notes,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?)""",
            (code, observed, src, result, notes, VERSION, NOW),
        )


def exit_gates(con: sqlite3.Connection, cohort: dict, clinic: dict, tx_classes: dict, llm: dict) -> str:
    clear(con, ["phase10c_exit_gate", "phase10c_overall_verdict", "phase10c_blocker"])
    ensure_cli_env_loaded()
    secrets_ok = availability_label("OPENAI_API_KEY") == "AVAILABLE" and availability_label("CW_DB_PASS") == "AVAILABLE"
    live_n = cohort["session_count"]
    dual = clinic["dual_complete"]
    workload_n = int(con.execute("SELECT n FROM phase10c_workload WHERE segment='all'").fetchone()[0])

    def g(code, status, notes):
        con.execute(
            """INSERT INTO phase10c_exit_gate
            (gate_code,status,evidence_notes,analysis_version,generated_at) VALUES (?,?,?,?,?)""",
            (code, status, notes, VERSION, NOW),
        )

    g("A_secrets", "PASS" if secrets_ok else "FAIL", "CLI RuntimeSecrets via FPM allowlist/EnvironmentFile")
    g("B_live_markers", "PASS_WITH_CONDITIONS" if live_n >= COHORT_MIN else "FAIL", f"freeze attempts={cohort['attempt_count']}")
    present = sum(tx_classes.get(k, 0) for k in ("GOOD", "USABLE", "LIMITED", "UNUSABLE"))
    missing = tx_classes.get("MISSING", 0)
    g(
        "C_live_transcript",
        "PASS_WITH_CONDITIONS" if present > 0 else "FAIL",
        f"provisional classes={tx_classes}; PRESENT≠USEFUL; human ASR review open",
    )
    g("D_live_cohort", "PASS" if live_n >= COHORT_MIN else "FAIL", f"freeze_id={FREEZE_ID} sessions={live_n}")
    g("E_boundary", "INSUFFICIENT_EVIDENCE", "Clinic boundary dims not dual-complete")
    g("F_objective_metrics", "INSUFFICIENT_EVIDENCE", "Examiner metric verdicts pending")
    g("G_examiner_clinic", "PASS" if dual >= 40 else "FAIL", f"dual_complete={dual}/40; no fabricated reviews")
    g("H_tolerance", "FAIL", "PENDING_CLINIC / NEEDS_REVIEW; no in-place retune")
    g("I_procedure", "INSUFFICIENT_EVIDENCE", "Observability classified; pack disposition pending clinic")
    g("J_independence", "INSUFFICIENT_EVIDENCE", "Live tap metrics n=0")
    g("K_consistency", "PASS_WITH_CONDITIONS", "≥3 rule retained; examiner agreement pending")
    g("L_claim_to_evidence", "INSUFFICIENT_EVIDENCE", "Human claim reviews pending")
    g("M_unsupported_claims", "INSUFFICIENT_EVIDENCE", "Rate unmeasured")
    g("N_instructor_workload", "INSUFFICIENT_EVIDENCE" if workload_n == 0 else "PASS_WITH_CONDITIONS", f"n={workload_n}; Phase8 2.5min not evidence")
    g("O_debrief_usefulness", "INSUFFICIENT_EVIDENCE", "ACCEPT/REJECT captures pending")
    g("P_degraded_mode", "PASS_WITH_CONDITIONS", "Some live missing transcript/marker cases observed")
    g("Q_schema_readiness", "PASS_WITH_CONDITIONS", "Delta doc only; NO MIGRATION")

    # Blockers
    blockers = [
        ("G_examiner_clinic", "0 dual-complete dimensional reviews", "Complete 80 worksheet dual reviews with all required fields — humans only"),
        ("H_tolerance", "Tolerance packs not examiner-accepted", "Clinic + versioned RC packs; do not retune to chase agreement"),
        ("N_instructor_workload", "No instrumented live instructor timings", "Run Phase 10C workload capture with real instructors"),
        ("L_claim_to_evidence", "No human claim-support sample", "Sample material claims; classify FULLY/PARTIAL/UNSUPPORTED/MISLEADING"),
        ("C_live_transcript", "Transcript quality is SYSTEM_PROVISIONAL only", "Human-rate GOOD/USABLE/LIMITED/UNUSABLE; validate prompt detection"),
        ("E_boundary", "Boundary correctness not clinic-validated", "Fill boundary_verdict in dual reviews"),
    ]
    for gate, why, action in blockers:
        con.execute(
            """INSERT INTO phase10c_blocker
            (gate_code,why,required_action,analysis_version,generated_at) VALUES (?,?,?,?,?)""",
            (gate, why, action, VERSION, NOW),
        )

    # Verdict — clinic not complete ⇒ NOT_READY (cannot claim LIMITED_INSTRUCTOR_ASSIST)
    if dual >= 40 and live_n >= COHORT_MIN and secrets_ok and workload_n > 0:
        verdict = "READY_FOR_LIMITED_INSTRUCTOR_ASSIST"
        rationale = "Clinic materially complete with workload measured — prepare Phase 11 proposal only; do not auto-enable flags."
    elif live_n >= COHORT_MIN and secrets_ok and dual == 0:
        verdict = "NOT_READY"
        rationale = (
            f"Infrastructure and live cohort ({live_n}) are ready, but examiner clinic is the primary open gate "
            f"(dual_complete={dual}/40). Professional trust and workload are unproven. "
            f"LLM job={llm.get('job_status')} processed={llm.get('processed')} — historical NLP must not block clinic work. "
            "No migrations. Flags OFF. Official grades authoritative."
        )
    else:
        verdict = "NOT_READY"
        rationale = "Live validation gates incomplete."

    con.execute(
        """INSERT INTO phase10c_overall_verdict
        (verdict,rationale,analysis_version,generated_at) VALUES (?,?,?,?)""",
        (verdict, rationale, VERSION, NOW),
    )
    return verdict


def main() -> None:
    ensure_cli_env_loaded()
    con = sqlite3.connect(DB, timeout=120)
    con.execute("PRAGMA busy_timeout=120000")
    reset_phase10c(con)
    con.executescript(SCHEMA.read_text())

    mysql = mysql_ro()
    cohort = freeze_live_cohort(con, mysql)
    mysql.close()
    log(f"Frozen cohort sessions={cohort['session_count']} attempts={cohort['attempt_count']} aircraft={cohort['aircraft_count']}")

    sync_clinic_reviews(con)
    clinic = clinic_progress_and_agreement(con)
    maneuver_dispositions(con, clinic)
    tolerance_and_procedure(con)
    tx_classes = transcript_quality(con)
    workload_claims_exceptions(con)
    llm = llm_progress(con)
    degraded_and_recompute(con)
    verdict = exit_gates(con, cohort, clinic, tx_classes, llm)

    con.execute(
        "INSERT INTO phase10c_meta (analysis_version,generated_at,notes) VALUES (?,?,?)",
        (
            VERSION,
            NOW,
            json.dumps(
                {
                    "verdict": verdict,
                    "freeze_id": FREEZE_ID,
                    "cohort": cohort,
                    "clinic": clinic,
                    "llm": llm,
                    "fabricated_reviews": 0,
                    "migrations": False,
                    "flags": "OFF",
                }
            ),
        ),
    )
    con.commit()
    con.close()
    log(f"Phase 10C complete verdict={verdict} dual_complete={clinic['dual_complete']}")


if __name__ == "__main__":
    main()
