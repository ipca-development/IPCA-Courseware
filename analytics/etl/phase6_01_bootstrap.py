#!/usr/bin/env python3
"""Phase 6 bootstrap: apply schema and seed architecture registries."""

from __future__ import annotations

import json
import sqlite3
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DB = ROOT / "storage/analytics/egle_training_analytics.sqlite"
SCHEMA = ROOT / "analytics/schema/phase6_tables.sql"
VERSION = "phase6-v1"
NOW = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def clear(con: sqlite3.Connection, tables: list[str]) -> None:
    for t in tables:
        con.execute(f"DELETE FROM {t}")


def seed_architecture(con: sqlite3.Connection) -> None:
    clear(
        con,
        [
            "competency_architecture_field",
            "independence_state_recommendation",
            "consistency_state_recommendation",
            "cockpit_recorder_contract_field",
            "automation_opportunity",
            "phase6_meta",
        ],
    )

    fields = [
        (
            "curriculum_expected_level",
            "CURRICULUM_EXPECTATION",
            "DE/EX/PR/PE expected at this curriculum point; never redefined by observed performance.",
            json.dumps(["DE", "EX", "PR", "PE"]),
            "curriculum/mission exercise definition",
            "HISTORICAL_ONLY",
            "Available historically from exercise name/required level",
            "mission/exercise definition sync",
        ),
        (
            "observed_independence",
            "OBSERVED_EXECUTION",
            "How independently the student executed; future structured field. Silence ≠ independent.",
            json.dumps(["ASSISTED", "PROMPTED", "INDEPENDENT", "NOT_OBSERVED"]),
            "instructor structured input + intervention events",
            "MANUAL",
            "Mostly UNKNOWN/NOT_OBSERVED historically",
            "instructor quick-action + optional AI candidate",
        ),
        (
            "observed_consistency",
            "COMPETENCY_STATE",
            "Repeatability within/across sessions; derived from multiple observations.",
            json.dumps(["NOT_ENOUGH_EVIDENCE", "VARIABLE", "DEVELOPING", "CONSISTENT"]),
            "derived from attempts + narrative/objective evidence",
            "DERIVED_LATER",
            "Partially recoverable from narratives",
            "derived from exercise_attempt metrics",
        ),
        (
            "objective_quality",
            "OBJECTIVE_EVIDENCE",
            "Measurable deviations vs tolerances; not a subjective 1–5 quality score.",
            json.dumps(["WITHIN_STANDARD", "MINOR_DEVIATION", "OUTSIDE_STANDARD", "UNKNOWN"]),
            "Cockpit Recorder / Garmin / GPS",
            "AUTO",
            "Rare explicit narrative measurements only",
            "objective_measurement stream",
        ),
        (
            "context",
            "CONTEXT",
            "Conditions under which performance occurred; primarily auto-derived.",
            json.dumps(["wind", "gust", "turbulence", "airport", "aircraft", "traffic", "check_env", "..."]),
            "weather/Garmin/airport/aircraft/ATC/audio",
            "AUTO",
            "Partial narrative tags historically",
            "context_snapshot",
        ),
        (
            "evidence",
            "OBJECTIVE_EVIDENCE",
            "First-class evidence items with provenance; AI never indistinguishable from human/objective.",
            json.dumps(["see evidence_source enum"]),
            "multi-source",
            "AUTO",
            "HISTORICAL_GRADE + HISTORICAL_NARRATIVE",
            "evidence_item ingest",
        ),
        (
            "instructor_observation",
            "HUMAN_ASSESSMENT",
            "What only the instructor can reliably judge (judgment, CRM nuance, safety feel).",
            json.dumps(["free text + structured flags"]),
            "instructor",
            "MANUAL",
            "Narratives / grades",
            "debrief confirm/correct",
        ),
        (
            "student_self_assessment",
            "HUMAN_ASSESSMENT",
            "Optional future student perception; kept separate from instructor/objective.",
            json.dumps(["perceived_independence", "perceived_quality", "confidence", "improvement_area"]),
            "student",
            "MANUAL",
            "Not available historically",
            "post-session form",
        ),
        (
            "ai_interpretation",
            "AI_INTERPRETATION",
            "AI-derived observations with model version; never final authority.",
            json.dumps(["candidate observations", "draft debrief"]),
            "AI models",
            "AUTO_WITH_CONFIRMATION",
            "LLM narrative extraction only",
            "ai_interpretation table",
        ),
        (
            "competency_state",
            "COMPETENCY_STATE",
            "Deterministic developmental state from evidence layers; explainable, not a score.",
            json.dumps(["expected", "independence", "quality", "consistency", "context", "trend"]),
            "state engine",
            "DERIVED_LATER",
            "Prototype from historical trajectories",
            "competency_state + history",
        ),
    ]
    con.executemany(
        """INSERT INTO competency_architecture_field
        (field_name,conceptual_layer,purpose,allowed_states_json,provider,capture_mode,
         historical_availability,future_recorder_source,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?)""",
        [(*f, VERSION, NOW) for f in fields],
    )

    con.execute(
        """INSERT INTO independence_state_recommendation
        (recommended_scale_name,states_json,rejected_granularity_json,rationale,
         separate_intervention_events_json,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?)""",
        (
            "INDEPENDENCE_MIN_4",
            json.dumps(["ASSISTED", "PROMPTED", "INDEPENDENT", "NOT_OBSERVED"]),
            json.dumps(
                [
                    "FULL_ASSISTANCE",
                    "SUBSTANTIAL_ASSISTANCE",
                    "MINIMAL_PROMPTING",
                    "UNKNOWN_as_state",
                ]
            ),
            (
                "Phase 5B: historical independence NOT_RELIABLY_EXTRACTABLE; assistance language sparse (~14% LLM). "
                "Four states capture operational value without forcing instructors through a 7-step ladder. "
                "Map: physical/demo/step-by-step/repeated → ASSISTED; single/minor verbal → PROMPTED; "
                "explicit unassisted execution → INDEPENDENT; silence → NOT_OBSERVED (never invent). "
                "Keep UNKNOWN only as missing historical dimension, not a live instructor choice."
            ),
            json.dumps(
                [
                    "VERBAL_PROMPT",
                    "PROCEDURAL_PROMPT",
                    "CORRECTION",
                    "DEMONSTRATION",
                    "PHYSICAL_INTERVENTION",
                    "SAFETY_TAKEOVER",
                ]
            ),
            VERSION,
            NOW,
        ),
    )

    con.execute(
        """INSERT INTO consistency_state_recommendation
        (recommended_scale_name,states_json,min_attempts_for_state,attempt_repeatability_rule,
         longitudinal_stability_rule,rationale,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?)""",
        (
            "CONSISTENCY_MIN_4",
            json.dumps(["NOT_ENOUGH_EVIDENCE", "VARIABLE", "DEVELOPING", "CONSISTENT"]),
            3,
            (
                "Within-session attempt_repeatability: require ≥3 attempts of same exercise_type in one session. "
                "All within tolerance + no intervention escalation → CONSISTENT; mixed outcomes → VARIABLE; "
                "improving trajectory (fail→pass) without yet stable → DEVELOPING. Never label a single success CONSISTENT."
            ),
            (
                "Longitudinal_stability: require successful within-standard attempts across ≥2 sessions "
                "separated by ≥1 calendar day (preferably with intervening gap). Transfer is separate: "
                "same exercise family within standard across materially different context_snapshots."
            ),
            (
                "Phase 5B KEEP: consistency is predictive and partially extractable. Drop ROBUST until "
                "longitudinal + multi-context evidence accumulates; otherwise instructors over-label."
            ),
            VERSION,
            NOW,
        ),
    )

    contract = [
        ("operational_session_id", 1, "string", "Recorder session identity"),
        ("actual_leg_id", 0, "string", "Optional link to operational leg"),
        ("exercise_attempt_id", 1, "string", "First-class attempt id within session"),
        ("exercise_type", 1, "string", "Canonical exercise taxonomy code"),
        ("start_timestamp", 1, "datetime", "Attempt start"),
        ("end_timestamp", 1, "datetime", "Attempt end"),
        ("telemetry_reference", 0, "uri/id", "Pointer to telemetry blob/stream"),
        ("audio_reference", 0, "uri/id", "Pointer to audio"),
        ("transcript_reference", 0, "uri/id", "Pointer to transcript"),
        ("context_reference", 0, "uri/id", "Pointer to context_snapshot"),
        ("instructor_events", 0, "array", "Intervention events with timestamps"),
        ("objective_metrics", 0, "array", "objective_measurement payloads"),
        ("ai_derived_observations", 0, "array", "AI candidates with model version"),
        ("algorithm_model_versions", 1, "object", "Versions for detection/metrics/AI"),
        ("curriculum_expected_level", 0, "enum", "If known at capture time"),
    ]
    con.executemany(
        """INSERT INTO cockpit_recorder_contract_field
        (field_name,required,semantic_type,description,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?)""",
        [(*c, VERSION, NOW) for c in contract],
    )

    autos = [
        ("weather_context", "AUTO", "METAR/Garmin wind/ceiling/visibility"),
        ("airport_runway", "AUTO", "From flight plan / GPS / airport DB"),
        ("aircraft", "AUTO", "From device/aircraft assignment"),
        ("day_night", "AUTO", "From timestamp + location"),
        ("objective_altitude_tolerance", "AUTO", "Telemetry vs ACS/SOP tolerances"),
        ("objective_airspeed_heading_bank", "AUTO", "Telemetry metrics"),
        ("exercise_start_marker", "AUTO_WITH_CONFIRMATION", "Existing marker; later auto-detect candidates"),
        ("checklist_sequence", "AUTO_WITH_CONFIRMATION", "Audio/transcript + switch telemetry"),
        ("instructor_physical_intervention", "MANUAL", "Or AI candidate + instructor confirmation"),
        ("safety_takeover", "MANUAL", "Critical; instructor-confirmed preferred"),
        ("observed_independence", "MANUAL", "Quick one-tap after attempt; silence stays NOT_OBSERVED"),
        ("observed_consistency", "DERIVED_LATER", "From multi-attempt objective + intervention history"),
        ("transfer", "DERIVED_LATER", "From cross-context longitudinal performance"),
        ("within_session_learning", "DERIVED_LATER", "From attempt trajectory in session"),
        ("competency_state", "DERIVED_LATER", "Deterministic state engine"),
        ("student_self_assessment", "MANUAL", "Optional; never required for competency state"),
        ("ai_draft_debrief", "AUTO_WITH_CONFIRMATION", "Draft only; instructor confirms/corrects"),
    ]
    con.executemany(
        """INSERT INTO automation_opportunity
        (field_name,automation_class,rationale,analysis_version,generated_at)
        VALUES (?,?,?,?,?)""",
        [(*a, VERSION, NOW) for a in autos],
    )

    con.execute(
        """INSERT INTO phase6_meta (analysis_version,generated_at,notes) VALUES (?,?,?)""",
        (VERSION, NOW, "Bootstrap architecture registries"),
    )
    con.commit()


def main() -> None:
    sql = SCHEMA.read_text()
    con = sqlite3.connect(DB)
    con.executescript(sql)
    seed_architecture(con)
    con.close()
    print(f"Phase 6 schema applied and architecture seeded ({VERSION})", flush=True)


if __name__ == "__main__":
    main()
