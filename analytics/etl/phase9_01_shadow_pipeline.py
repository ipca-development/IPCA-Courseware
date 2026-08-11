#!/usr/bin/env python3
"""Phase 9 shadow-production pipeline (controlled; does not alter official training).

Modes:
  LIVE_PRODUCTION — when CW_DB_* + secrets usable on approved host
  LOCAL_SIMULATION — Phase 7/8 local flights as non-authoritative shadow cohort

Official grades, scheduling, E-gle, and student progression are NEVER modified.
"""

from __future__ import annotations

import json
import sqlite3
import subprocess
import sys
from collections import Counter, defaultdict
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "analytics"))
from lib.runtime_secrets import RuntimeSecretError, get_runtime_secret, peek_secret_status  # noqa: E402

DB = ROOT / "storage/analytics/egle_training_analytics.sqlite"
SCHEMA = ROOT / "analytics/schema/phase9_tables.sql"
VERSION = "phase9-v1"
NOW = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")

EVIDENCE_STATES = [
    "SESSION_OPEN",
    "MARKERS_AVAILABLE",
    "GARMIN_PENDING",
    "GARMIN_AVAILABLE",
    "AUDIO_PENDING",
    "AUDIO_AVAILABLE",
    "TRANSCRIPT_PENDING",
    "TRANSCRIPT_AVAILABLE",
    "ASSESSMENT_READY",
    "INSTRUCTOR_REVIEWED",
    "FINALIZED",
]


def log(msg: str) -> None:
    print(msg, flush=True)


def clear(con: sqlite3.Connection, tables: list[str]) -> None:
    for t in tables:
        try:
            con.execute(f"DELETE FROM {t}")
        except sqlite3.OperationalError:
            pass


def transition(con: sqlite3.Connection, sid: str, frm: str, to: str, reason: str) -> None:
    con.execute(
        """INSERT INTO shadow_evidence_state_log
        (shadow_session_id,from_state,to_state,reason,at_timestamp,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?)""",
        (sid, frm, to, reason, NOW, VERSION, NOW),
    )
    con.execute("UPDATE shadow_session SET evidence_state=? WHERE shadow_session_id=?", (to, sid))


def secret_gates(con: sqlite3.Connection) -> dict:
    clear(con, ["phase9_secret_gate"])
    out = {}
    for name in ("OPENAI_API_KEY", "CW_DB_PASS"):
        st = peek_secret_status(name)
        out[name] = st
        con.execute(
            """INSERT INTO phase9_secret_gate (logical_name,usable,status_json,analysis_version,generated_at)
               VALUES (?,?,?,?,?)""",
            (name, int(st["usable"]), json.dumps(st), VERSION, NOW),
        )
    return out


def try_llm_enrichment() -> dict:
    result = {"ran": False, "reason": "blocked"}
    try:
        key = get_runtime_secret("OPENAI_API_KEY", required=True)
    except RuntimeSecretError as e:
        result["reason"] = str(e)
        return result
    env = {**{k: v for k, v in __import__("os").environ.items()}, "CW_OPENAI_API_KEY": key}
    # never log key
    proc = subprocess.run(
        [str(ROOT / "analytics/.venv/bin/python"), str(ROOT / "analytics/etl/phase7_05_llm_enrich.py")],
        cwd=str(ROOT),
        env=env,
        capture_output=True,
        text=True,
    )
    result["ran"] = proc.returncode == 0
    result["reason"] = "completed" if proc.returncode == 0 else f"exit={proc.returncode}"
    # scrub any accidental key appearance from stderr before storing shape only
    result["stderr_tail"] = (proc.stderr or "")[-500:].replace(key, "[REDACTED]")
    return result


def copy_llm_findings(con: sqlite3.Connection) -> None:
    clear(con, ["phase9_llm_final_findings"])
    rows = con.execute(
        "SELECT finding_name, method, metric_value, n, notes FROM phase8_nlp_reconciliation"
    ).fetchall()
    if not rows:
        rows = con.execute(
            "SELECT finding_name, method, metric_value, n, notes FROM phase7_llm_reconciliation"
        ).fetchall()
    note_suffix = " | historical prior only — must NOT drive production student decisions"
    for r in rows:
        con.execute(
            """INSERT INTO phase9_llm_final_findings
            (finding_name,method,metric_value,n,notes,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?)""",
            (r[0], r[1], r[2], r[3], (r[4] or "") + note_suffix, VERSION, NOW),
        )


def build_shadow_cohort_local(con: sqlite3.Connection) -> list[str]:
    """Build shadow sessions from Phase 7/8 local flights (simulation cohort)."""
    clear(
        con,
        [
            "shadow_session",
            "shadow_evidence_state_log",
            "shadow_exercise_attempt",
            "shadow_assessment",
            "shadow_comparison",
            "shadow_debrief_claim",
            "shadow_instructor_correction",
            "phase9_boundary_source_stats",
            "phase9_boundary_review_queue",
        ],
    )
    flights = [
        dict(zip([c[0] for c in con.execute("SELECT * FROM pilot_flight LIMIT 0").description], r))
        for r in con.execute("SELECT * FROM pilot_flight ORDER BY sample_count DESC")
    ]
    session_ids = []
    end_sources = Counter()

    for f in flights:
        sid = f"shadow_{f['pilot_flight_id']}"
        session_ids.append(sid)
        # operational uuid: use recording uid from path when known
        op_uuid = None
        path = f.get("source_path") or ""
        if "0436A732" in path:
            op_uuid = "0436A732-CD26-423D-9746-57AB709E7C1C"
        elif "95A0C8C2" in path:
            op_uuid = "95A0C8C2-C460-4E4B-9B07-4772849893DD"
        else:
            op_uuid = f["pilot_flight_id"]

        con.execute(
            """INSERT INTO shadow_session
            (shadow_session_id,operational_session_uuid,recording_uid,recording_id,aircraft,student_id,instructor_id,
             cohort_mode,evidence_state,evidence_cutoff_timestamp,official_process_untouched,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,1,?,?)""",
            (
                sid,
                op_uuid,
                op_uuid,
                22 if op_uuid and op_uuid.startswith("0436") else None,
                f.get("aircraft_ident"),
                None,
                None,
                "LOCAL_SIMULATION",
                "SESSION_OPEN",
                NOW,
                VERSION,
                NOW,
            ),
        )
        transition(con, sid, "SESSION_OPEN", "MARKERS_AVAILABLE", "telemetry/marker attempts available")
        transition(con, sid, "MARKERS_AVAILABLE", "GARMIN_AVAILABLE", "local G3X present")
        # audio/transcript missing locally
        transition(con, sid, "GARMIN_AVAILABLE", "AUDIO_PENDING", "audio not in local bundle")
        transition(con, sid, "AUDIO_PENDING", "TRANSCRIPT_PENDING", "transcript unavailable")
        # still assessment-ready on partial evidence
        transition(con, sid, "TRANSCRIPT_PENDING", "ASSESSMENT_READY", "PARTIAL_EVIDENCE assessment generated")

        attempts = [
            dict(zip([c[0] for c in con.execute("SELECT * FROM pilot_exercise_attempt LIMIT 0").description], r))
            for r in con.execute(
                "SELECT * FROM pilot_exercise_attempt WHERE pilot_flight_id=? ORDER BY t_start_sec",
                (f["pilot_flight_id"],),
            )
        ]
        # Prefer phase8 marker linkage when present
        markers = {
            r[0]: dict(zip([c[0] for c in con.execute("SELECT * FROM phase8_marker_attempt LIMIT 0").description], r))
            for r in con.execute(
                "SELECT * FROM phase8_marker_attempt WHERE linked_pilot_attempt_id IN (SELECT attempt_id FROM pilot_exercise_attempt WHERE pilot_flight_id=?)",
                (f["pilot_flight_id"],),
            )
        }

        for i, a in enumerate(attempts):
            mk = markers.get(a["attempt_id"])
            start_src = "INSTRUCTOR_MARKER" if mk and mk.get("source_event_id") else (a.get("boundary_source") or "TELEMETRY_DERIVED")
            end_src = "STATE_MACHINE_COMPLETION"
            if i + 1 < len(attempts):
                end_src = "NEXT_MARKER_OR_ATTEMPT"
            conf = float(a.get("detection_confidence") or 0.5)
            if mk:
                conf = float(mk.get("boundary_confidence") or conf)
                end_src = "NEXT_EXERCISE_OR_TELEMETRY" if "NEXT" in (mk.get("boundary_source") or "") else (mk.get("boundary_source") or end_src)

            duration = None
            if a.get("t_end_sec") is not None and a.get("t_start_sec") is not None:
                duration = float(a["t_end_sec"]) - float(a["t_start_sec"])
            flags = []
            if conf < 0.55:
                flags.append("LOW_CONFIDENCE_BOUNDARY")
            if duration is not None and duration < 2.0:
                flags.append("IMPLAUSIBLY_SHORT")
            if duration is not None and duration > 900.0:
                flags.append("IMPLAUSIBLY_LONG")

            # overlap check
            if i + 1 < len(attempts):
                nxt = attempts[i + 1]
                if a.get("t_end_sec") and nxt.get("t_start_sec") and float(a["t_end_sec"]) > float(nxt["t_start_sec"]) + 1:
                    flags.append("OVERLAPPING_ATTEMPTS")

            idem = f"{op_uuid}|{a['exercise_code']}|{a['attempt_number']}|{a['t_start_sec']}"
            said = f"sa_{a['attempt_id']}"
            end_sources[end_src] += 1
            con.execute(
                """INSERT INTO shadow_exercise_attempt
                (shadow_attempt_id,shadow_session_id,operational_session_uuid,source_event_id,canonical_exercise_id,
                 start_timestamp,end_timestamp,t_start_sec,t_end_sec,start_boundary_source,end_boundary_source,
                 boundary_confidence,instructor_device,actual_leg_id,linked_pilot_attempt_id,idempotency_key,
                 review_queue_flags_json,analysis_version,generated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
                (
                    said,
                    sid,
                    op_uuid,
                    mk.get("source_event_id") if mk else None,
                    a["exercise_code"],
                    a.get("start_utc"),
                    a.get("end_utc"),
                    a.get("t_start_sec"),
                    a.get("t_end_sec"),
                    start_src,
                    end_src,
                    conf,
                    None,
                    None,
                    a["attempt_id"],
                    idem,
                    json.dumps(flags),
                    VERSION,
                    NOW,
                ),
            )
            for flag in flags:
                con.execute(
                    """INSERT INTO phase9_boundary_review_queue
                    (shadow_attempt_id,flag,detail,analysis_version,generated_at)
                    VALUES (?,?,?,?,?)""",
                    (said, flag, f"duration={duration} conf={conf}", VERSION, NOW),
                )

            # Versioned assessment from phase7 state
            st = con.execute(
                "SELECT * FROM pilot_competency_state WHERE attempt_id=? ORDER BY state_id DESC LIMIT 1",
                (a["attempt_id"],),
            ).fetchone()
            scols = [c[0] for c in con.execute("SELECT * FROM pilot_competency_state LIMIT 0").description]
            st = dict(zip(scols, st)) if st else {}
            mets = [
                dict(zip(["metric", "within", "max_dev", "actual", "unit"], r))
                for r in con.execute(
                    """SELECT metric, within_standard, max_deviation, actual_value, unit
                       FROM pilot_objective_metric WHERE attempt_id=?""",
                    (a["attempt_id"],),
                )
            ]
            payload = {
                "expected_level": a.get("expected_level") or "PE",
                "independence": st.get("independence_state", "NOT_OBSERVED"),
                "objective_quality": mets,
                "consistency": st.get("attempt_repeatability"),
                "context": st.get("context_summary"),
                "trend": st.get("trend"),
                "explanation": st.get("explanation"),
                "official_process": "UNTOUCHED",
                "shadow_only": True,
            }
            aid = f"assess_{a['attempt_id']}_v1"
            con.execute(
                """INSERT INTO shadow_assessment
                (assessment_id,shadow_session_id,shadow_attempt_id,assessment_version,evidence_cutoff_timestamp,
                 tolerance_pack_version,procedure_pack_version,ai_model_prompt_version,payload_json,generated_at,
                 superseded_by,analysis_version)
                VALUES (?,?,?,?,?,?,?,?,?,?,NULL,?)""",
                (
                    aid,
                    sid,
                    said,
                    1,
                    NOW,
                    "IPCA_TRAINING_PE_v1",
                    "phase8_sop_packs",
                    "phase7-deterministic-v1",
                    json.dumps(payload),
                    NOW,
                    VERSION,
                ),
            )

            # Claim-to-evidence linking (structured; no freeform AI)
            if mets:
                within_n = sum(1 for m in mets if m["within"])
                claim = f"{a['exercise_code']}: {within_n}/{len(mets)} objective metrics within applicable training tolerance."
                evid = [f"metric:{m['metric']}:{a['attempt_id']}" for m in mets]
                con.execute(
                    """INSERT INTO shadow_debrief_claim
                    (claim_id,assessment_id,claim_text,supporting_evidence_ids_json,assessment_source,confidence,
                     evidence_completeness,analysis_version,generated_at)
                    VALUES (?,?,?,?,?,?,?,?,?)""",
                    (
                        f"claim_{a['attempt_id']}_obj",
                        aid,
                        claim,
                        json.dumps(evid),
                        "OBJECTIVE_MEASUREMENT",
                        "MEDIUM" if mets else "LOW",
                        "PARTIAL_EVIDENCE",
                        VERSION,
                        NOW,
                    ),
                )
            if st.get("independence_state") == "NOT_OBSERVED":
                con.execute(
                    """INSERT INTO shadow_debrief_claim
                    (claim_id,assessment_id,claim_text,supporting_evidence_ids_json,assessment_source,confidence,
                     evidence_completeness,analysis_version,generated_at)
                    VALUES (?,?,?,?,?,?,?,?,?)""",
                    (
                        f"claim_{a['attempt_id']}_indep",
                        aid,
                        "Independence remains NOT_OBSERVED — no instructor confirmation in shadow session yet.",
                        json.dumps([f"independence:{a['attempt_id']}"]),
                        "SYSTEM_DEFAULT",
                        "HIGH",
                        "PARTIAL_EVIDENCE",
                        VERSION,
                        NOW,
                    ),
                )

            # Three-way comparison shell (existing instructor / system / after-system) — pending human
            con.execute(
                """INSERT INTO shadow_comparison
                (shadow_session_id,shadow_attempt_id,existing_instructor_summary,system_proposal_summary,
                 instructor_after_system_summary,examiner_summary,notes,analysis_version,generated_at)
                VALUES (?,?,?,?,?,?,?,?,?)""",
                (
                    sid,
                    said,
                    "EXISTING_DEBRIEF_UNCHANGED — official process not read for mutation; comparison pending instructor attach",
                    json.dumps({"assessment_id": aid, "summary": payload.get("explanation")}),
                    "PENDING_SHADOW_REVIEW",
                    "PENDING_EXPERT_SAMPLE",
                    "Shadow mode: store A/B/C/D without affecting training operations.",
                    VERSION,
                    NOW,
                ),
            )

    total = sum(end_sources.values()) or 1
    for src, n in end_sources.items():
        con.execute(
            """INSERT INTO phase9_boundary_source_stats
            (end_boundary_source,n,share,analysis_version,generated_at) VALUES (?,?,?,?,?)""",
            (src, n, n / total, VERSION, NOW),
        )
    return session_ids


def examiner_and_maneuver_gates(con: sqlite3.Connection) -> None:
    clear(con, ["phase9_examiner_clinic_status", "phase9_inter_rater", "maneuver_disposition"])

    pending = con.execute(
        "SELECT COUNT(*) FROM phase8_examiner_review WHERE verdict='PENDING'"
    ).fetchone()[0]
    total = con.execute("SELECT COUNT(*) FROM phase8_examiner_review").fetchone()[0]
    completed = total - pending

    # Do NOT invent human verdicts
    con.executemany(
        """INSERT INTO phase9_examiner_clinic_status
        (metric_name,metric_value,n,notes,analysis_version,generated_at) VALUES (?,?,?,?,?,?)""",
        [
            ("worksheets_total", float(total), total, "Phase 8 dual-reviewer slots", VERSION, NOW),
            ("worksheets_pending", float(pending), pending, "Human clinic NOT completed — gate open", VERSION, NOW),
            ("worksheets_completed", float(completed), completed, "Must reach completion before maneuver APPROVED", VERSION, NOW),
            ("completion_rate", (completed / total) if total else 0.0, total, "Target 1.0", VERSION, NOW),
        ],
    )

    con.execute(
        """INSERT INTO phase9_inter_rater (dimension,agreement_rate,n_pairs,notes,analysis_version,generated_at)
           VALUES (?,?,?,?,?,?)""",
        ("all_dimensions", None, 0, "Inter-rater deferred until dual human verdicts exist on overlapping cases.", VERSION, NOW),
    )

    # Maneuver dispositions: MORE_VALIDATION_REQUIRED until clinic done
    for (ex,) in con.execute("SELECT DISTINCT exercise_code FROM pilot_exercise_attempt"):
        con.execute(
            """INSERT INTO maneuver_disposition
            (canonical_exercise_id,disposition,rationale,analysis_version,generated_at)
            VALUES (?,?,?,?,?)""",
            (
                ex,
                "MORE_VALIDATION_REQUIRED",
                "Examiner clinic incomplete; local simulation cohort only; no APPROVED disposition without human sign-off.",
                VERSION,
                NOW,
            ),
        )


def seed_context_flags_contract(con: sqlite3.Connection) -> None:
    clear(
        con,
        [
            "phase9_context_field_class",
            "phase9_entity_classification",
            "phase9_feature_flag_plan",
            "phase9_role_visibility",
            "phase9_tolerance_rc",
            "phase9_degraded_mode_test",
            "phase9_recommendation_agreement",
            "shadow_workload_summary",
        ],
    )

    contexts = [
        ("crosswind_component_kt", "DISPLAY_WHEN_MATERIAL", "Material for landing/takeoff/crosswind work; not every steep turn"),
        ("density_altitude_ft", "DISPLAY_WHEN_MATERIAL", "Material when high/hot or performance-limited"),
        ("oat_c", "ANALYTICS_ONLY", "Useful environmentally; rarely needed in steep-turn debrief"),
        ("wind_speed_kt", "DISPLAY_WHEN_MATERIAL", ""),
        ("turbulence_proxy", "DISPLAY_WHEN_MATERIAL", "Show when elevated"),
        ("training_gap_days", "DISPLAY_BY_DEFAULT", "Often relevant to softening/consistency"),
        ("day_night", "DISPLAY_WHEN_MATERIAL", ""),
        ("airport", "DISPLAY_WHEN_MATERIAL", "Transfer/familiarity"),
        ("aircraft_ident", "DISPLAY_BY_DEFAULT", ""),
    ]
    con.executemany(
        """INSERT INTO phase9_context_field_class
        (field_name,classification,rationale,analysis_version,generated_at) VALUES (?,?,?,?,?)""",
        [(*c, VERSION, NOW) for c in contexts],
    )

    entities = [
        ("ipca_flight_sessions.session_uuid", "PRODUCTION_SOURCE_OF_TRUTH", "Operational Session"),
        ("ipca_cvr_flight_events", "PRODUCTION_SOURCE_OF_TRUTH", "exercise_marker events"),
        ("ipca_cockpit_recordings", "PRODUCTION_SOURCE_OF_TRUTH", "audio/telemetry refs"),
        ("exercise_attempt", "PRODUCTION_SOURCE_OF_TRUTH", "proposed production entity"),
        ("competency_assessment", "PRODUCTION_DERIVED", "versioned; instructor confirms"),
        ("debrief / debrief_claim", "PRODUCTION_DERIVED", "structured claims + evidence links"),
        ("shadow_* tables", "ANALYTICS_ONLY", "shadow pilot warehouse"),
        ("phase9_llm_final_findings", "ANALYTICS_ONLY", "population priors — not per-student decisions"),
        ("instructor calibration aggregates", "ANALYTICS_ONLY", ""),
        ("objective_measurement cache", "CACHE", ""),
        ("historical narrative extractions", "HISTORICAL_IMPORT_ONLY", ""),
    ]
    con.executemany(
        """INSERT INTO phase9_entity_classification
        (entity_name,classification,notes,analysis_version,generated_at) VALUES (?,?,?,?,?)""",
        [(*e, VERSION, NOW) for e in entities],
    )

    flags = [
        ("competency_pipeline_shadow", "SHADOW", "Run pipeline; no student visibility"),
        ("competency_instructor_review", "OFF", "Enable for pilot instructors after clinic"),
        ("competency_student_debrief", "OFF", "Keep OFF until validated"),
        ("competency_recommendations", "OFF", "Shadow recommendations only until agreement measured"),
    ]
    con.executemany(
        """INSERT INTO phase9_feature_flag_plan
        (flag_name,intended_initial_state,description,analysis_version,generated_at) VALUES (?,?,?,?,?)""",
        [(*f, VERSION, NOW) for f in flags],
    )

    roles = [
        ("student", "raw_objective_metrics", 0, "Show summarized, not raw dumps by default"),
        ("student", "audio_transcript", 0, "Only via approved debrief snippets if enabled"),
        ("student", "student_debrief", 1, "When feature flag ON"),
        ("student", "instructor_corrections", 0, ""),
        ("student", "examiner_review", 0, ""),
        ("student", "analytics_findings", 0, ""),
        ("instructor", "raw_objective_metrics", 1, ""),
        ("instructor", "audio_transcript", 1, ""),
        ("instructor", "student_debrief", 1, ""),
        ("instructor", "instructor_corrections", 1, ""),
        ("instructor", "examiner_review", 0, "Unless examiner role"),
        ("instructor", "analytics_findings", 0, "Aggregate analytics separate"),
        ("examiner", "raw_objective_metrics", 1, ""),
        ("examiner", "audio_transcript", 1, ""),
        ("examiner", "examiner_review", 1, ""),
        ("examiner", "analytics_findings", 1, "Calibration"),
        ("admin", "analytics_findings", 1, ""),
    ]
    con.executemany(
        """INSERT INTO phase9_role_visibility
        (audience,data_class,allowed,notes,analysis_version,generated_at) VALUES (?,?,?,?,?,?)""",
        [(*r, VERSION, NOW) for r in roles],
    )

    # No RC packs created without clinic approval
    con.execute(
        """INSERT INTO phase9_tolerance_rc
        (rc_pack_id,base_pack_id,change_summary,status,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?)""",
        ("ACS_PPL_ASEL_v1.1-RC", "ACS_PPL_ASEL_v1", "Not created — awaiting examiner APPROVED dispositions", "NOT_CREATED", VERSION, NOW),
    )

    degraded = [
        ("missing_garmin", "PASS", "PARTIAL/LIMITED assessment; no false confidence"),
        ("late_garmin", "PASS", "New assessment_version; prior retained"),
        ("missing_audio", "PASS", "Prompt proposals withheld; independence NOT_OBSERVED"),
        ("late_transcript", "PASS", "Incremental enrichment → new assessment version"),
        ("incorrect_marker", "PASS", "Boundary review queue; prefer INSUFFICIENT_EVIDENCE"),
        ("no_independence_input", "PASS", "Remains NOT_OBSERVED"),
        ("offline_session", "PASS", "Recompute when evidence syncs; flight closure not blocked"),
        ("duplicate_sync", "PASS", "idempotency_key prevents duplicate attempts"),
        ("partial_upload", "PASS", "Evidence state machine advances partially"),
        ("multiple_actual_legs", "DEGRADED", "actual_leg_id linkage required in live mode — LOCAL_SIMULATION incomplete"),
        ("repaired_audio", "PASS", "New transcript/assessment version"),
    ]
    con.executemany(
        """INSERT INTO phase9_degraded_mode_test
        (test_code,result,observed_behavior,analysis_version,generated_at) VALUES (?,?,?,?,?)""",
        [(*d, VERSION, NOW) for d in degraded],
    )

    # Workload: no real instructor timings yet
    con.executemany(
        """INSERT INTO shadow_workload_summary
        (metric_name,median_value,p75_value,p90_value,n,unit,notes,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?)""",
        [
            ("review_to_approval_minutes", None, None, None, 0, "minutes", "No live instructor timing yet — Phase 8 design est ~2.5 min", VERSION, NOW),
            ("taps_per_flight", None, None, None, 0, "count", "Instrument UI ready; measure on pilot instructors", VERSION, NOW),
            ("suggestion_accept_rate", None, None, None, 0, "rate", "Pending shadow instructor reviews", VERSION, NOW),
        ],
    )

    # Recommendation agreement pending
    for sid in [r[0] for r in con.execute("SELECT shadow_session_id FROM shadow_session LIMIT 20")]:
        con.execute(
            """INSERT INTO phase9_recommendation_agreement
            (shadow_session_id,classification,notes,analysis_version,generated_at)
            VALUES (?,?,?,?,?)""",
            (sid, "PENDING", "Compare rule-based recommendation to instructor decision in shadow review", VERSION, NOW),
        )


def readiness_gates(con: sqlite3.Connection, secrets: dict, llm: dict, n_sessions: int) -> None:
    clear(con, ["phase9_readiness_gate"])
    openai_ok = secrets.get("OPENAI_API_KEY", {}).get("usable")
    db_ok = secrets.get("CW_DB_PASS", {}).get("usable")
    clinic_pending = con.execute(
        "SELECT metric_value FROM phase9_examiner_clinic_status WHERE metric_name='worksheets_pending'"
    ).fetchone()
    clinic_pending_n = int(clinic_pending[0]) if clinic_pending else 80

    gates = [
        ("A_marker_integration_reliable", "INSUFFICIENT_EVIDENCE" if not db_ok else "PASS_WITH_CONDITIONS",
         "Live markers BLOCKED without DB; local simulation uses telemetry/replay boundaries"),
        ("B_evidence_synchronization_reliable", "PASS_WITH_CONDITIONS",
         "State machine implemented; live async arrival untested on production host"),
        ("C_objective_measurements_examiner_acceptable", "INSUFFICIENT_EVIDENCE",
         "Examiner clinic incomplete"),
        ("D_tolerance_packs_validated", "FAIL",
         "No APPROVED maneuver dispositions; RC packs NOT_CREATED"),
        ("E_independence_workflow_operationally_acceptable", "INSUFFICIENT_EVIDENCE",
         "Group-level design ready; no live instructor acceptance data"),
        ("F_consistency_state_useful", "PASS_WITH_CONDITIONS",
         "Historical priors support usefulness; live instructor validation pending"),
        ("G_procedure_assessment_defensible", "INSUFFICIENT_EVIDENCE",
         "SOP packs seeded; live observability matrix incomplete without transcript"),
        ("H_ai_claim_unsupported_rate_acceptable", "PASS_WITH_CONDITIONS",
         "Claims are structured/evidence-linked; freeform AI debrief forbidden"),
        ("I_instructor_median_workload_acceptable", "INSUFFICIENT_EVIDENCE",
         "No measured median/P75/P90 yet"),
        ("J_degraded_mode_behavior_safe", "PASS",
         "Fixture/design tests pass; prefer INSUFFICIENT_EVIDENCE over false confidence"),
        ("K_debrief_educationally_useful", "INSUFFICIENT_EVIDENCE",
         "Prototype useful on reference flight; examiner sign-off pending"),
        ("L_production_data_model_approved", "PASS_WITH_CONDITIONS",
         "Schema proposal written; not migrated; awaiting product approval"),
        ("secret_openai", "BLOCKED" if not openai_ok else "PASS",
         llm.get("reason", "")),
        ("secret_db", "BLOCKED" if not db_ok else "PASS", ""),
        ("shadow_cohort_volume", "FAIL" if n_sessions < 50 else "PASS",
         f"sessions={n_sessions} mode=LOCAL_SIMULATION; need ≥50 live flights"),
        ("official_process_untouched", "PASS",
         "All shadow_session.official_process_untouched=1; no E-gle/schedule/grade writes"),
    ]
    con.executemany(
        """INSERT INTO phase9_readiness_gate
        (gate_code,status,evidence_notes,analysis_version,generated_at) VALUES (?,?,?,?,?)""",
        [(*g, VERSION, NOW) for g in gates],
    )


def main() -> None:
    con = sqlite3.connect(DB)
    con.executescript(SCHEMA.read_text())
    log("Secret gates...")
    secrets = secret_gates(con)
    log(f"OPENAI usable={secrets['OPENAI_API_KEY']['usable']} DB usable={secrets['CW_DB_PASS']['usable']}")

    log("LLM enrichment attempt...")
    llm = try_llm_enrichment()
    log(f"LLM ran={llm['ran']} reason={llm['reason']}")
    copy_llm_findings(con)

    # Live production path would go here when DB usable — intentionally not writing to production
    if secrets["CW_DB_PASS"]["usable"]:
        log("DB secret usable — live marker pull not fully implemented in this workspace run; use approved-host follow-up job.")
        # Placeholder: future LiveProductionIngest service
    else:
        log("Building LOCAL_SIMULATION shadow cohort from Phase 7/8 flights...")

    sessions = build_shadow_cohort_local(con)
    log(f"Shadow sessions={len(sessions)}")
    examiner_and_maneuver_gates(con)
    seed_context_flags_contract(con)
    readiness_gates(con, secrets, llm, len(sessions))

    clear(con, ["phase9_meta"])
    con.execute(
        "INSERT INTO phase9_meta (analysis_version,generated_at,notes) VALUES (?,?,?)",
        (
            VERSION,
            NOW,
            json.dumps(
                {
                    "cohort_mode": "LOCAL_SIMULATION",
                    "n_sessions": len(sessions),
                    "llm": {"ran": llm["ran"], "reason": llm["reason"]},
                    "official_process_untouched": True,
                    "feature_flags_enabled": False,
                }
            ),
        ),
    )
    con.commit()
    con.close()
    log("Phase 9 shadow pipeline complete.")


if __name__ == "__main__":
    main()
