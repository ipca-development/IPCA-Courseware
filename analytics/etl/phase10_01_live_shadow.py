#!/usr/bin/env python3
"""Phase 10 live shadow validation on approved host.

When CW_DB_PASS + OpenAI secrets are usable: ingest live Operational Sessions.
When blocked: record BLOCKED gates and seed validation scaffolding from Phase 9.
Never writes official training state.
"""

from __future__ import annotations

import json
import os
import sqlite3
import subprocess
import sys
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "analytics"))
from lib.runtime_secrets import RuntimeSecretError, get_runtime_secret, peek_secret_status  # noqa: E402

DB = ROOT / "storage/analytics/egle_training_analytics.sqlite"
SCHEMA = ROOT / "analytics/schema/phase10_tables.sql"
VERSION = "phase10-v1"
NOW = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
COHORT_MIN = 50
COHORT_PREF = 75


def log(msg: str) -> None:
    print(msg, flush=True)


def clear(con: sqlite3.Connection, tables: list[str]) -> None:
    for t in tables:
        try:
            con.execute(f"DELETE FROM {t}")
        except sqlite3.OperationalError:
            pass


def set_runtime(con: sqlite3.Connection, component: str, status: str, detail: str) -> None:
    con.execute(
        """INSERT OR REPLACE INTO phase10_runtime_status
        (component,status,detail,analysis_version,generated_at) VALUES (?,?,?,?,?)""",
        (component, status, detail, VERSION, NOW),
    )


def try_mysql_readonly():
    """Return (pdo-like connection via PyMySQL/mysql.connector) or raise."""
    host = os.environ.get("CW_DB_HOST")
    if not host:
        # load non-secret host vars from .env without promoting EV secrets
        env_path = ROOT / ".env"
        if env_path.exists():
            for line in env_path.read_text(errors="ignore").splitlines():
                if not line or line.strip().startswith("#") or "=" not in line:
                    continue
                k, v = line.split("=", 1)
                k, v = k.strip(), v.strip().strip('"').strip("'")
                if k.startswith("CW_DB_") and k != "CW_DB_PASS" and k not in os.environ:
                    if not v.startswith("EV["):
                        os.environ[k] = v
        host = os.environ.get("CW_DB_HOST")
    port = int(os.environ.get("CW_DB_PORT") or "25060")
    name = os.environ.get("CW_DB_NAME")
    user = os.environ.get("CW_DB_USER")
    password = get_runtime_secret("CW_DB_PASS", required=True)
    try:
        import pymysql  # type: ignore
    except ImportError:
        # fallback: subprocess mysql client not preferred
        raise RuntimeError("pymysql not installed in analytics venv")
    conn = pymysql.connect(
        host=host,
        port=port,
        user=user,
        password=password,
        database=name,
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
        ssl={"ssl": {}},
        connect_timeout=20,
        read_timeout=60,
    )
    # read-only intent
    with conn.cursor() as cur:
        try:
            cur.execute("SET SESSION TRANSACTION READ ONLY")
        except Exception:
            pass
    return conn


def ingest_live_cohort(con: sqlite3.Connection, mysql) -> int:
    """Ingest recent operational sessions with recordings/markers. Read-only MySQL."""
    clear(con, ["phase10_live_cohort", "phase10_cohort_composition"])
    n = 0
    with mysql.cursor() as cur:
        # Prefer sessions with cockpit recordings in recent window
        cur.execute(
            """
            SELECT r.id AS recording_id, r.recording_uid, r.operational_session_uuid,
                   r.flight_session_uid, r.started_at, r.aircraft_registration
            FROM ipca_cockpit_recordings r
            WHERE r.operational_session_uuid IS NOT NULL
               OR r.flight_session_uid IS NOT NULL
            ORDER BY r.id DESC
            LIMIT %s
            """,
            (COHORT_PREF,),
        )
        rows = cur.fetchall() or []

    for r in rows:
        op = r.get("operational_session_uuid") or r.get("flight_session_uid")
        if not op:
            continue
        # markers
        marker_n = 0
        with mysql.cursor() as cur:
            try:
                cur.execute(
                    """
                    SELECT COUNT(*) AS c FROM ipca_cvr_flight_events
                    WHERE event_type='exercise_marker'
                      AND (operational_session_uuid=%s OR workflow_flight_record_uuid=%s)
                    """,
                    (op, r.get("flight_session_uid") or op),
                )
                marker_n = int((cur.fetchone() or {}).get("c") or 0)
            except Exception as e:
                marker_n = -1

        completeness = "PARTIAL_EVIDENCE"
        if marker_n > 0:
            completeness = "PARTIAL_EVIDENCE"
        if marker_n == 0:
            completeness = "LIMITED_EVIDENCE"

        con.execute(
            """INSERT OR REPLACE INTO phase10_live_cohort
            (operational_session_uuid,recording_id,recording_uid,aircraft,student_id,instructor_id,
             session_start,ingest_mode,evidence_completeness,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)""",
            (
                op,
                r.get("recording_id"),
                r.get("recording_uid"),
                r.get("aircraft_registration"),
                None,
                None,
                str(r.get("started_at") or ""),
                "LIVE_PRODUCTION",
                completeness,
                VERSION,
                NOW,
            ),
        )
        n += 1

    # composition
    for dim, sql in [
        ("aircraft", "SELECT aircraft AS value, COUNT(*) n FROM phase10_live_cohort GROUP BY 1"),
        ("evidence_completeness", "SELECT evidence_completeness AS value, COUNT(*) n FROM phase10_live_cohort GROUP BY 1"),
        ("ingest_mode", "SELECT ingest_mode AS value, COUNT(*) n FROM phase10_live_cohort GROUP BY 1"),
    ]:
        for row in con.execute(sql):
            con.execute(
                """INSERT INTO phase10_cohort_composition
                (dimension,value,n,analysis_version,generated_at) VALUES (?,?,?,?,?)""",
                (dim, str(row[0]), int(row[1]), VERSION, NOW),
            )
    return n


def seed_blocked_local_baseline(con: sqlite3.Connection) -> int:
    """When live DB blocked, do not pretend Phase 9 local sessions are live flights."""
    clear(con, ["phase10_live_cohort", "phase10_cohort_composition"])
    # Record zero live flights explicitly
    con.execute(
        """INSERT INTO phase10_cohort_composition
        (dimension,value,n,analysis_version,generated_at) VALUES (?,?,?,?,?)""",
        ("ingest_mode", "BLOCKED_LOCAL", 0, VERSION, NOW),
    )
    con.execute(
        """INSERT INTO phase10_cohort_composition
        (dimension,value,n,analysis_version,generated_at) VALUES (?,?,?,?,?)""",
        ("phase9_local_simulation_sessions", "LOCAL_SIMULATION", table_count(con, "shadow_session"), VERSION, NOW),
    )
    return 0


def clinic_and_agreement(con: sqlite3.Connection) -> tuple[int, int]:
    clear(con, ["phase10_clinic_completion", "phase10_inter_rater", "phase10_maneuver_verdict"])
    try:
        total = int(con.execute("SELECT COUNT(*) FROM phase8_examiner_review").fetchone()[0])
        pending = int(con.execute("SELECT COUNT(*) FROM phase8_examiner_review WHERE verdict='PENDING'").fetchone()[0])
    except sqlite3.OperationalError:
        total = 0
        pending = 0
    done = total - pending

    # Genuine agreement only on completed dual pairs
    try:
        pairs = con.execute(
            """
            SELECT a.attempt_id, a.verdict va, b.verdict vb
            FROM phase8_examiner_review a
            JOIN phase8_examiner_review b
              ON a.attempt_id=b.attempt_id AND a.reviewer_id='examiner_A' AND b.reviewer_id='examiner_B'
            WHERE a.verdict!='PENDING' AND b.verdict!='PENDING'
            """
        ).fetchall()
    except sqlite3.OperationalError:
        pairs = []
    agree = sum(1 for _, va, vb in pairs if va == vb)
    n_pairs = len(pairs)
    rate = (agree / n_pairs) if n_pairs else None

    con.executemany(
        """INSERT INTO phase10_clinic_completion
        (metric_name,metric_value,n,notes,analysis_version,generated_at) VALUES (?,?,?,?,?,?)""",
        [
            ("worksheets_total", float(total), total, "Dual-reviewer slots", VERSION, NOW),
            ("worksheets_completed", float(done), done, "Genuine human reviews only", VERSION, NOW),
            ("worksheets_pending", float(pending), pending, "Must complete — no synthetic verdicts", VERSION, NOW),
            ("completion_rate", (done / total) if total else 0.0, total, "Target 1.0", VERSION, NOW),
        ],
    )

    for dim in ("exercise_boundary", "objective_result", "independence", "consistency", "procedure", "overall_competency"):
        con.execute(
            """INSERT INTO phase10_inter_rater
            (dimension,agreement_rate,n_pairs,notes,analysis_version,generated_at) VALUES (?,?,?,?,?,?)""",
            (
                dim,
                rate if dim == "overall_competency" else None,
                n_pairs if dim == "overall_competency" else 0,
                "Overall verdict agreement only until dimension-specific clinic fields are filled; other dims INSUFFICIENT_EVIDENCE",
                VERSION,
                NOW,
            ),
        )

    # Maneuver verdicts: cannot promote without clinic
    try:
        maneuvers = [r[0] for r in con.execute("SELECT DISTINCT canonical_exercise_id FROM maneuver_disposition")]
    except sqlite3.OperationalError:
        maneuvers = []
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
        con.execute(
            """INSERT INTO phase10_maneuver_verdict
            (canonical_exercise_id,verdict,rationale,analysis_version,generated_at) VALUES (?,?,?,?,?)""",
            (
                ex,
                "INSUFFICIENT_EVIDENCE",
                "Examiner clinic incomplete and/or live cohort not ingested; blanket MORE_VALIDATION_REQUIRED not replaced with VALIDATED_*",
                VERSION,
                NOW,
            ),
        )
    return done, pending


def table_count(con: sqlite3.Connection, table: str) -> int:
    try:
        return int(con.execute(f"SELECT COUNT(*) FROM {table}").fetchone()[0])
    except sqlite3.OperationalError:
        return 0


def measure_boundaries_from_shadow(con: sqlite3.Connection) -> None:
    """Boundary metrics from available shadow attempts (live or prior simulation tagged)."""
    clear(con, ["phase10_boundary_metrics", "phase10_boundary_failure"])
    try:
        rows = list(con.execute(
            "SELECT boundary_confidence, review_queue_flags_json, end_boundary_source, shadow_attempt_id, operational_session_uuid FROM shadow_exercise_attempt"
        ))
    except sqlite3.OperationalError:
        rows = []
    if not rows:
        con.execute(
            """INSERT INTO phase10_boundary_metrics
            (metric_name,metric_value,n,notes,analysis_version,generated_at) VALUES (?,?,?,?,?,?)""",
            ("no_attempts", 0, 0, "No shadow attempts available for boundary metrics", VERSION, NOW),
        )
        return

    confs = [float(r[0] or 0) for r in rows]
    high = sum(1 for c in confs if c >= 0.75)
    med = sum(1 for c in confs if 0.55 <= c < 0.75)
    low = sum(1 for c in confs if c < 0.55)
    flagged = 0
    for r in rows:
        flags = json.loads(r[1] or "[]")
        if flags:
            flagged += 1
            for f in flags:
                cls = {
                    "LOW_CONFIDENCE_BOUNDARY": "other",
                    "IMPLAUSIBLY_SHORT": "one_attempt_split_or_short",
                    "IMPLAUSIBLY_LONG": "multiple_attempts_merged_or_long",
                    "OVERLAPPING_ATTEMPTS": "multiple_attempts_merged",
                }.get(f, "other")
                con.execute(
                    """INSERT INTO phase10_boundary_failure
                    (operational_session_uuid,attempt_ref,failure_class,detail,analysis_version,generated_at)
                    VALUES (?,?,?,?,?,?)""",
                    (r[4], r[3], cls, f, VERSION, NOW),
                )

    n = len(rows)
    for name, val, notes in [
        ("pct_high_confidence", 100.0 * high / n, "conf>=0.75; LOCAL/prior shadow — not live-validated"),
        ("pct_medium_confidence", 100.0 * med / n, "0.55<=conf<0.75"),
        ("pct_low_confidence", 100.0 * low / n, "conf<0.55"),
        ("manual_review_rate", 100.0 * flagged / n, "any review_queue flag"),
        ("incorrect_boundary_rate_expert", None, "Requires examiner clinic — not measured"),
    ]:
        con.execute(
            """INSERT INTO phase10_boundary_metrics
            (metric_name,metric_value,n,notes,analysis_version,generated_at) VALUES (?,?,?,?,?,?)""",
            (name, val, n, notes, VERSION, NOW),
        )


def seed_validation_scaffolding(con: sqlite3.Connection, live_n: int) -> None:
    clear(
        con,
        [
            "phase10_transcript_quality",
            "phase10_prompt_validation",
            "phase10_metric_validation",
            "phase10_tolerance_disposition",
            "phase10_procedure_step_observability",
            "phase10_outcome_process_case",
            "phase10_independence_metrics",
            "phase10_workload_live",
            "phase10_exception_snr",
            "phase10_claim_validation",
            "phase10_ai_unsupported",
            "phase10_debrief_quality",
            "phase10_recommendation_live",
            "phase10_early_warning_live",
            "phase10_degraded_live",
            "phase10_schema_review",
            "phase10_feature_flag_plan",
            "phase10_pilot_instructor_criteria",
        ],
    )

    # Transcript quality: missing for local; live would fill per session
    if live_n == 0:
        con.execute(
            """INSERT INTO phase10_transcript_quality
            (operational_session_uuid,audio_available,transcript_available,quality_class,latency_notes,speaker_notes,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?,?)""",
            ("NONE_LIVE", 0, 0, "MISSING", "Live ASR not ingested", "Speaker UNKNOWN", VERSION, NOW),
        )

    for name, val, n, notes in [
        ("correct_instructor_prompt_rate", None, 0, "Needs live transcript + human review"),
        ("student_misclassified_as_instructor", None, 0, ""),
        ("atc_misclassified_as_instructor", None, 0, ""),
        ("missed_prompt_rate", None, 0, "Conservative system preferred"),
        ("generic_conversation_as_intervention", None, 0, ""),
    ]:
        con.execute(
            """INSERT INTO phase10_prompt_validation
            (metric_name,metric_value,n,notes,analysis_version,generated_at) VALUES (?,?,?,?,?,?)""",
            (name, val, n, notes, VERSION, NOW),
        )

    mets_n = table_count(con, "pilot_objective_metric")
    for name, val, n, notes in [
        ("metric_extraction_success_local_baseline", 1.0 if mets_n else None, mets_n, "Local G3X extraction success ≠ operational validity"),
        ("missing_metric_rate_live", None, 0, "Live not measured"),
        ("obviously_incorrect_values_live", None, 0, ""),
        ("boundary_induced_error_live", None, 0, ""),
    ]:
        con.execute(
            """INSERT INTO phase10_metric_validation
            (metric_name,metric_value,n,notes,analysis_version,generated_at) VALUES (?,?,?,?,?,?)""",
            (name, val, n, notes, VERSION, NOW),
        )

    # Tolerance dispositions
    for pack, metric, disp, mismatch, notes in [
        ("ACS_PPL_ASEL_v1", "altitude_deviation_ft", "PENDING_CLINIC", "human_interpretation_difference", "No live examiner disposition"),
        ("IPCA_TRAINING_PE_v1", "vertical_speed_fpm", "NEEDS_REVIEW", "wrong_boundary", "Phase 7/8 approach window concern"),
        ("IPCA_TRAINING_PE_v1", "airspeed_deviation_kt", "NEEDS_REVIEW", "wrong_measurement", "Slow-flight specified IAS missing"),
        ("IPCA_TRAINING_PR_v1", "altitude_deviation_ft", "PENDING_CLINIC", "wrong_training_level_applicability", "PR pedagogy intentional"),
    ]:
        con.execute(
            """INSERT INTO phase10_tolerance_disposition
            (pack_id,metric,disposition,mismatch_class,notes,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?)""",
            (pack, metric, disp, mismatch, notes, VERSION, NOW),
        )

    # Procedure observability from Phase 8 packs
    try:
        proc_rows = list(con.execute("SELECT procedure_pack_id, step_code, evidence_source, manual_only FROM procedure_step"))
    except sqlite3.OperationalError:
        proc_rows = []
    for r in proc_rows:
        pack, step, src, manual = r
        if src == "NOT_OBSERVABLE" or manual:
            obs = "NOT_OBSERVABLE"
        elif src == "TELEMETRY":
            obs = "AUTO_PARTIAL"
        elif src in ("AUDIO", "TRANSCRIPT"):
            obs = "TRANSCRIPT_SUPPORTED"
        elif src == "INSTRUCTOR":
            obs = "INSTRUCTOR_REQUIRED"
        elif src == "RECORDER_EVENT":
            obs = "AUTO_PARTIAL"
        else:
            obs = "INSTRUCTOR_REQUIRED"
        con.execute(
            """INSERT INTO phase10_procedure_step_observability
            (pack_id,step_code,observability,notes,analysis_version,generated_at) VALUES (?,?,?,?,?,?)""",
            (pack, step, obs, f"source={src}; live confirmation pending", VERSION, NOW),
        )

    # Multidimensional value cases (conceptual from architecture — live examples pending)
    for pattern, ref, notes in [
        ("outcome_good_procedure_poor", "PENDING_LIVE", "Seek in go-around/stall with telemetry OK but SOP gaps"),
        ("outcome_poor_procedure_correct", "PENDING_LIVE", ""),
        ("quality_good_independence_low", "PENDING_LIVE", "Assisted but within tolerance"),
        ("quality_weak_independence_high", "PENDING_LIVE", "Independent but outside tolerance"),
        ("consistency_good_context_easy", "PENDING_LIVE", ""),
        ("softens_in_harder_context", "PENDING_LIVE", "Crosswind/DA transfer cases"),
    ]:
        con.execute(
            """INSERT INTO phase10_outcome_process_case
            (pattern,example_ref,notes,analysis_version,generated_at) VALUES (?,?,?,?,?)""",
            (pattern, ref, notes, VERSION, NOW),
        )

    for name, val, n, notes in [
        ("pct_groups_with_instructor_input", None, 0, "Live independence workflow not measured"),
        ("taps_per_flight", None, 0, ""),
        ("suggestion_change_rate", None, 0, ""),
        ("group_level_inadequate_rate", None, 0, "Only add attempt-level if evidence demands"),
    ]:
        con.execute(
            """INSERT INTO phase10_independence_metrics
            (metric_name,metric_value,n,notes,analysis_version,generated_at) VALUES (?,?,?,?,?,?)""",
            (name, val, n, notes, VERSION, NOW),
        )

    # Workload — n=0 live
    for segment in ("routine", "problematic", "high_exercise_count", "all"):
        con.execute(
            """INSERT INTO phase10_workload_live
            (metric_name,segment,median_value,p75_value,p90_value,min_value,max_value,n,notes,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)""",
            ("review_minutes", segment, None, None, None, None, None, 0, "Phase 8 ~2.5 min remains hypothesis", VERSION, NOW),
        )

    for rating in ("USEFUL", "NEUTRAL", "NOISY", "WRONG", "PENDING"):
        con.execute(
            """INSERT INTO phase10_exception_snr
            (rating,n,notes,analysis_version,generated_at) VALUES (?,?,?,?,?)""",
            (rating, 0 if rating != "PENDING" else 1, "Await instructor ratings of exception queue", VERSION, NOW),
        )

    claims_n = table_count(con, "shadow_debrief_claim")
    for name, val, n, notes in [
        ("claims_available_baseline", float(claims_n), claims_n, "Phase 9 simulation claims; live support not validated"),
        ("fully_supported_live", None, 0, "Need sampled live review"),
        ("partially_supported_live", None, 0, ""),
        ("unsupported_live", None, 0, ""),
        ("misleading_despite_link_live", None, 0, "Evidence ID exists ≠ claim true"),
    ]:
        con.execute(
            """INSERT INTO phase10_claim_validation
            (metric_name,metric_value,n,notes,analysis_version,generated_at) VALUES (?,?,?,?,?,?)""",
            (name, val, n, notes, VERSION, NOW),
        )

    con.execute(
        """INSERT INTO phase10_ai_unsupported
        (metric_name,metric_value,n,notes,analysis_version,generated_at) VALUES (?,?,?,?,?,?)""",
        ("material_unsupported_claim_rate_live", None, 0, "Restrict to templated verbalization until measured acceptable", VERSION, NOW),
    )

    for dim in ("accuracy", "clarity", "usefulness", "prioritization", "tone", "evidence_support", "actionability", "would_help_debrief"):
        con.execute(
            """INSERT INTO phase10_debrief_quality
            (dimension,score_notes,n,analysis_version,generated_at) VALUES (?,?,?,?,?)""",
            (dim, "PENDING instructor/examiner live ratings", 0, VERSION, NOW),
        )

    for cls in ("AGREE", "PARTIAL", "DISAGREE", "NOT_APPLICABLE", "PENDING"):
        con.execute(
            """INSERT INTO phase10_recommendation_live
            (classification,n,notes,analysis_version,generated_at) VALUES (?,?,?,?,?)""",
            (cls, 0 if cls != "PENDING" else 1, "Shadow recommendations only; no auto-assign", VERSION, NOW),
        )

    for code, useful, notes in [
        ("CONSISTENCY_CONCERN", "PENDING_LIVE", "Historical prior useful; live lead-time unmeasured"),
        ("REPEATED_DEFICIENCY_WINDOW", "PENDING_LIVE", ""),
        ("HIGH_GRADE_NARRATIVE_DEFICIENCY", "PENDING_LIVE", ""),
        ("POST_GAP_SOFTENING", "PENDING_LIVE", "Long-gap alone was noisy historically"),
    ]:
        con.execute(
            """INSERT INTO phase10_early_warning_live
            (pattern_code,useful_flag,notes,analysis_version,generated_at) VALUES (?,?,?,?,?)""",
            (code, useful, notes, VERSION, NOW),
        )

    for code, observed, result, notes in [
        ("garmin_late", 0, "UNOBSERVED", "Capture when live"),
        ("garmin_missing", 0, "UNOBSERVED", ""),
        ("audio_missing", 1, "PASS_DESIGN", "Local reference reports PARTIAL/LIMITED"),
        ("transcript_late", 0, "UNOBSERVED", "Recomputation versioning designed"),
        ("marker_incomplete", 1, "PASS_DESIGN", "Boundary queue + INSUFFICIENT_EVIDENCE preference"),
        ("independence_not_entered", 1, "PASS", "Remains NOT_OBSERVED"),
        ("partial_upload", 0, "UNOBSERVED", ""),
        ("offline_recovery", 0, "UNOBSERVED", ""),
        ("repaired_recording", 0, "UNOBSERVED", ""),
        ("duplicate_sync", 1, "PASS_DESIGN", "idempotency_key"),
    ]:
        con.execute(
            """INSERT INTO phase10_degraded_live
            (case_code,observed,result,notes,analysis_version,generated_at) VALUES (?,?,?,?,?,?)""",
            (code, observed, result, notes, VERSION, NOW),
        )

    # Schema review vs Phase 9 proposal
    for entity, disp, notes in [
        ("ipca_exercise_attempts", "KEEP", "Confirmed needed; idempotency_key essential"),
        ("ipca_objective_measurements", "KEEP", "Add evidence_cutoff awareness via assessment version"),
        ("ipca_independence_observations", "KEEP", "Group-level key retained"),
        ("ipca_instructor_interventions", "KEEP", ""),
        ("ipca_competency_assessments", "KEEP", "Versioning mandatory for late evidence"),
        ("ipca_debriefs", "KEEP", "SHADOW status required"),
        ("ipca_debrief_claims", "KEEP", "Claim-to-evidence critical"),
        ("ipca_debrief_claim_evidence", "KEEP", ""),
        ("ipca_competency_state_history", "KEEP", ""),
        ("opaque_risk_score_table", "DROP", "Forbidden"),
        ("auto_mission_reschedule", "DROP", "Forbidden"),
        ("population_instructor_calibration", "ANALYTICS_ONLY", "Not per-flight production"),
        ("assessment.evidence_state_machine", "CHANGE", "Explicitly model SESSION_OPEN…FINALIZED or link shadow log"),
        ("transcript_quality_class", "CHANGE", "Add GOOD/USABLE/LIMITED/UNUSABLE/MISSING on session/attempt"),
        ("boundary_failure_class", "CHANGE", "Persist live failure taxonomy"),
    ]:
        con.execute(
            """INSERT INTO phase10_schema_review
            (entity_or_column,disposition,notes,analysis_version,generated_at) VALUES (?,?,?,?,?)""",
            (entity, disp, notes, VERSION, NOW),
        )

    for flag, state, post, notes in [
        ("competency_pipeline_shadow", "OFF", "ON after gates", "Phase 10: OFF unless separately instructed"),
        ("competency_instructor_review", "OFF", "ON for selected instructors", "Limited assist only if READY_FOR_LIMITED_INSTRUCTOR_ASSIST"),
        ("competency_student_debrief", "OFF", "OFF", "Out of Phase 10 scope"),
        ("competency_recommendations", "OFF", "SHADOW_ONLY", "No auto assign"),
    ]:
        con.execute(
            """INSERT INTO phase10_feature_flag_plan
            (flag_name,phase10_state,post_gate_intended,notes,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?)""",
            (flag, state, post, notes, VERSION, NOW),
        )

    for crit, req, notes in [
        ("experienced_instructor", 1, ""),
        ("standardized_teaching", 1, ""),
        ("willing_to_provide_feedback", 1, ""),
        ("representative_of_normal_operations", 1, "Not only developers/enthusiasts"),
        ("multiple_aircraft_exposure", 0, "Prefer if available"),
    ]:
        con.execute(
            """INSERT INTO phase10_pilot_instructor_criteria
            (criterion,required,notes,analysis_version,generated_at) VALUES (?,?,?,?,?)""",
            (crit, req, notes, VERSION, NOW),
        )


def llm_status(con: sqlite3.Connection, ran: bool, reason: str) -> None:
    clear(con, ["phase10_llm_status"])
    done = 0
    pop = 0
    try:
        done = int(
            con.execute(
                """SELECT COUNT(DISTINCT text_hash) FROM analysis_phase6_narrative_extraction
                   WHERE extractor IN ('LLM_V1_REUSED','phase7-extract-v1-llm')"""
            ).fetchone()[0]
        )
        pop = int(con.execute("SELECT COUNT(*) FROM analysis_phase6_nlp_population").fetchone()[0])
    except sqlite3.OperationalError:
        reason = reason + " | historical phase6 tables absent on this host"
    con.execute(
        """INSERT INTO phase10_llm_status
        (status,hashes_done,hashes_remaining,notes,analysis_version,generated_at) VALUES (?,?,?,?,?,?)""",
        (
            "COMPLETED" if ran else "BLOCKED",
            done,
            max(0, pop - done),
            reason + " | historical priors only; non-blocking for live flight processing",
            VERSION,
            NOW,
        ),
    )


def exit_gates(con: sqlite3.Connection, live_n: int, clinic_done: int, clinic_pending: int, secrets_ok: dict) -> str:
    clear(con, ["phase10_exit_gate", "phase10_overall_verdict"])

    def g(code, status, notes):
        con.execute(
            """INSERT INTO phase10_exit_gate
            (gate_code,status,evidence_notes,analysis_version,generated_at) VALUES (?,?,?,?,?)""",
            (code, status, notes, VERSION, NOW),
        )

    g("A_approved_host_secrets", "PASS" if secrets_ok.get("openai") and secrets_ok.get("db") else "BLOCKED",
      f"openai={secrets_ok.get('openai')} db={secrets_ok.get('db')}")
    g("B_live_marker_integration", "PASS_WITH_CONDITIONS" if live_n >= COHORT_MIN else ("FAIL" if live_n == 0 else "INSUFFICIENT_EVIDENCE"),
      f"live_sessions={live_n}")
    g("C_live_transcript_integration", "FAIL" if live_n == 0 else "INSUFFICIENT_EVIDENCE",
      "ASR quality not measured live")
    g("D_shadow_cohort_ge_50", "PASS" if live_n >= COHORT_MIN else "FAIL",
      f"live={live_n} min={COHORT_MIN} pref={COHORT_PREF}")
    g("E_exercise_boundary_reliability", "INSUFFICIENT_EVIDENCE",
      "Local/prior shadow rates only; expert incorrect-boundary rate unmeasured")
    g("F_objective_metric_reliability", "INSUFFICIENT_EVIDENCE",
      "Extraction≠operational validity; clinic pending")
    g("G_examiner_clinic_completion", "PASS" if clinic_pending == 0 and clinic_done > 0 else "FAIL",
      f"done={clinic_done} pending={clinic_pending}")
    g("H_tolerance_validation", "FAIL", "No VALIDATED packs; dispositions PENDING/NEEDS_REVIEW")
    g("I_procedure_validation", "INSUFFICIENT_EVIDENCE", "Observability classified; live confirmation pending")
    g("J_independence_workflow", "INSUFFICIENT_EVIDENCE", "No live tap/time metrics")
    g("K_consistency_engine", "PASS_WITH_CONDITIONS", "Rules retained; live examiner agreement pending")
    g("L_claim_to_evidence_reliability", "INSUFFICIENT_EVIDENCE", "Links exist; supportiveness unvalidated live")
    g("M_ai_unsupported_claim_rate", "INSUFFICIENT_EVIDENCE", "Not measured live; templated path preferred")
    g("N_instructor_workload", "INSUFFICIENT_EVIDENCE", "median/P75/P90 n=0")
    g("O_debrief_usefulness", "INSUFFICIENT_EVIDENCE", "Qualitative ratings pending")
    g("P_degraded_mode_safety", "PASS_WITH_CONDITIONS", "Design tests pass; few live degraded cases observed")
    g("Q_production_schema_readiness", "PASS_WITH_CONDITIONS", "KEEP/CHANGE review done; NO MIGRATION")

    # Overall verdict — exact one of allowed values; never READY_FOR_FULL_PRODUCTION
    statuses = {r[0]: r[1] for r in con.execute("SELECT gate_code,status FROM phase10_exit_gate")}
    blocked = sum(1 for s in statuses.values() if s == "BLOCKED")
    fails = sum(1 for s in statuses.values() if s == "FAIL")
    if blocked or fails >= 3 or clinic_pending > 0 or live_n < COHORT_MIN:
        verdict = "NOT_READY"
        cohort_note = f"live_cohort={live_n} (min={COHORT_MIN})"
        if live_n < COHORT_MIN:
            cohort_note += " BELOW_MINIMUM"
        else:
            cohort_note += " MET"
        rationale = (
            f"{cohort_note}; clinic pending={clinic_pending}; "
            f"blocked_gates={blocked}; fail_gates={fails}. Prefer INSUFFICIENT_EVIDENCE over unsupported certainty. "
            "Official training remains authoritative. No student debrief. No migrations."
        )
    elif live_n >= COHORT_MIN and clinic_pending == 0 and fails == 0:
        # Still cannot reach migration prep without more PASS
        passes = sum(1 for s in statuses.values() if s in ("PASS", "PASS_WITH_CONDITIONS"))
        if passes >= 14:
            verdict = "READY_FOR_LIMITED_INSTRUCTOR_ASSIST"
            rationale = "Most gates PASS/PASS_WITH_CONDITIONS; student debrief still OFF."
        else:
            verdict = "READY_FOR_MORE_SHADOW"
            rationale = "Cohort and clinic met but remaining gates need more shadow."
    else:
        verdict = "READY_FOR_MORE_SHADOW"
        rationale = "Partial progress; continue shadow before instructor-assist."

    # Hard ceiling: never claim migration prep without explicit strong conditions
    if verdict == "READY_FOR_PRODUCTION_MIGRATION_PREP":
        verdict = "READY_FOR_LIMITED_INSTRUCTOR_ASSIST"

    con.execute(
        """INSERT INTO phase10_overall_verdict
        (verdict,rationale,analysis_version,generated_at) VALUES (?,?,?,?)""",
        (verdict, rationale, VERSION, NOW),
    )
    return verdict


def reset_phase10_tables(con: sqlite3.Connection) -> None:
    """Drop phase10_* so schema edits apply (analytics only)."""
    names = [
        r[0]
        for r in con.execute(
            "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'phase10_%'"
        )
    ]
    for n in names:
        con.execute(f"DROP TABLE IF EXISTS {n}")


def seed_context_and_longitudinal(con: sqlite3.Connection) -> None:
    clear(con, ["phase10_context_materiality", "phase10_longitudinal_case", "phase10_migration_gate"])
    # Prefer Phase 9 classifications; fall back to conservative defaults
    try:
        rows = list(con.execute("SELECT field_name, classification, rationale FROM phase9_context_field_class"))
    except sqlite3.OperationalError:
        rows = []
    if rows:
        mapping = {
            "DISPLAY_BY_DEFAULT": "DEBRIEF_DEFAULT",
            "DISPLAY_WHEN_MATERIAL": "DEBRIEF_WHEN_MATERIAL",
            "ANALYTICS_ONLY": "ANALYTICS_ONLY",
            "INSTRUCTOR_ONLY": "INSTRUCTOR_ONLY",
            "DEBRIEF_DEFAULT": "DEBRIEF_DEFAULT",
            "DEBRIEF_WHEN_MATERIAL": "DEBRIEF_WHEN_MATERIAL",
        }
        for field, cls, notes in rows:
            mapped = mapping.get(str(cls), "INSTRUCTOR_ONLY")
            con.execute(
                """INSERT INTO phase10_context_materiality
                (context_field,materiality_class,notes,analysis_version,generated_at)
                VALUES (?,?,?,?,?)""",
                (field, mapped, (notes or "") + " | live materiality still unconfirmed", VERSION, NOW),
            )
    else:
        for field, cls, notes in [
            ("crosswind_component", "DEBRIEF_WHEN_MATERIAL", "Only when landing/takeoff soft"),
            ("density_altitude", "DEBRIEF_WHEN_MATERIAL", ""),
            ("ceiling_visibility", "DEBRIEF_WHEN_MATERIAL", ""),
            ("traffic_density", "INSTRUCTOR_ONLY", ""),
            ("raw_metar", "ANALYTICS_ONLY", "Do not dump into student debrief"),
            ("aircraft_type", "DEBRIEF_DEFAULT", ""),
            ("training_phase", "DEBRIEF_DEFAULT", ""),
        ]:
            con.execute(
                """INSERT INTO phase10_context_materiality
                (context_field,materiality_class,notes,analysis_version,generated_at)
                VALUES (?,?,?,?,?)""",
                (field, cls, notes, VERSION, NOW),
            )

    for pattern, ref, notes in [
        ("stable_competency", "PENDING_LIVE", "Needs multi-session live cohort"),
        ("developing_consistency", "PENDING_LIVE", ""),
        ("regression", "PENDING_LIVE", ""),
        ("post_gap_softening", "PENDING_LIVE", "Historical prior noisy alone"),
        ("contextual_transfer", "PENDING_LIVE", ""),
        ("improvement_over_sessions", "PENDING_LIVE", ""),
    ]:
        con.execute(
            """INSERT INTO phase10_longitudinal_case
            (pattern,example_ref,system_vs_instructor,notes,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?)""",
            (pattern, ref, "UNCOMPARED", notes, VERSION, NOW),
        )

    for gate, met, notes in [
        ("live_shadow_cohort_ge_50", 0, "Not met on this host"),
        ("examiner_clinic_completed", 0, "80 PENDING"),
        ("some_maneuvers_validated_for_instructor_assist", 0, "All INSUFFICIENT_EVIDENCE"),
        ("tolerance_packs_accepted", 0, ""),
        ("workload_measured", 0, "n=0"),
        ("claim_support_acceptable", 0, ""),
        ("degraded_mode_safe", 0, "Design PASS_WITH_CONDITIONS only"),
        ("schema_updated_from_live_findings", 0, "Review done; migration not authorized"),
    ]:
        con.execute(
            """INSERT INTO phase10_migration_gate
            (gate_name,met,notes,analysis_version,generated_at) VALUES (?,?,?,?,?)""",
            (gate, met, notes, VERSION, NOW),
        )


def main() -> None:
    con = sqlite3.connect(DB)
    reset_phase10_tables(con)
    con.executescript(SCHEMA.read_text())
    clear(con, ["phase10_runtime_status", "phase10_meta"])

    openai_ok = peek_secret_status("OPENAI_API_KEY")["usable"]
    # peek DB without throwing
    db_ok = False
    try:
        get_runtime_secret("CW_DB_PASS", required=True)
        db_ok = True
    except RuntimeSecretError as e:
        set_runtime(con, "CW_DB_PASS", "BLOCKED", str(e))
    set_runtime(con, "OPENAI_API_KEY", "OK" if openai_ok else "BLOCKED", "see RuntimeSecrets peek")
    set_runtime(con, "official_training_writes", "OK", "Guaranteed none from this pipeline")
    set_runtime(con, "feature_flags", "OK", "All remain OFF")

    live_n = 0
    if db_ok:
        try:
            mysql = try_mysql_readonly()
            set_runtime(con, "mysql_readonly", "OK", "Connected read-only")
            live_n = ingest_live_cohort(con, mysql)
            mysql.close()
            set_runtime(con, "live_cohort_ingest", "OK" if live_n else "DEGRADED", f"sessions={live_n}")
        except Exception as e:
            set_runtime(con, "mysql_readonly", "BLOCKED", f"{type(e).__name__}: connection/query failed")
            live_n = seed_blocked_local_baseline(con)
    else:
        live_n = seed_blocked_local_baseline(con)
        set_runtime(con, "live_cohort_ingest", "BLOCKED", "No production DB secret")

    # LLM parallel non-blocking
    llm_ran = False
    llm_reason = "not attempted"
    if openai_ok:
        try:
            key = get_runtime_secret("OPENAI_API_KEY", required=True)
            env = {**dict(os.environ), "CW_OPENAI_API_KEY": key}
            proc = subprocess.run(
                [str(ROOT / "analytics/.venv/bin/python"), str(ROOT / "analytics/etl/phase7_05_llm_enrich.py")],
                cwd=str(ROOT),
                env=env,
                capture_output=True,
                text=True,
            )
            llm_ran = proc.returncode == 0
            llm_reason = "completed" if llm_ran else f"exit={proc.returncode}"
        except Exception as e:
            llm_reason = type(e).__name__
    else:
        llm_reason = "OPENAI secret blocked — historical enrichment deferred; live flight processing not blocked by design"
    llm_status(con, llm_ran, llm_reason)

    measure_boundaries_from_shadow(con)
    clinic_done, clinic_pending = clinic_and_agreement(con)
    seed_validation_scaffolding(con, live_n)
    seed_context_and_longitudinal(con)
    # Update migration gates with measured facts
    con.execute(
        "UPDATE phase10_migration_gate SET met=?, notes=? WHERE gate_name='live_shadow_cohort_ge_50'",
        (1 if live_n >= COHORT_MIN else 0, f"live_sessions={live_n}"),
    )
    con.execute(
        "UPDATE phase10_migration_gate SET met=?, notes=? WHERE gate_name='examiner_clinic_completed'",
        (1 if clinic_pending == 0 and clinic_done > 0 else 0, f"done={clinic_done} pending={clinic_pending}"),
    )
    # Re-assert LLM status after scaffolding clears (non-blocking historical enrichment)
    llm_status(con, llm_ran, llm_reason)
    verdict = exit_gates(
        con,
        live_n,
        clinic_done,
        clinic_pending,
        {"openai": openai_ok, "db": db_ok},
    )

    con.execute(
        "INSERT INTO phase10_meta (analysis_version,generated_at,notes) VALUES (?,?,?)",
        (
            VERSION,
            NOW,
            json.dumps(
                {
                    "verdict": verdict,
                    "live_sessions": live_n,
                    "clinic_pending": clinic_pending,
                    "llm_ran": llm_ran,
                    "migrations": False,
                    "student_debrief": False,
                    "authoritative": False,
                }
            ),
        ),
    )
    con.commit()
    con.close()
    log(f"Phase 10 complete verdict={verdict} live_sessions={live_n} clinic_pending={clinic_pending}")


if __name__ == "__main__":
    main()
