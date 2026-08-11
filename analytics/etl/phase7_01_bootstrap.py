#!/usr/bin/env python3
"""Phase 7 bootstrap: schema, tolerance packs, exercise state machines, secret status."""

from __future__ import annotations

import json
import os
import sqlite3
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DB = ROOT / "storage/analytics/egle_training_analytics.sqlite"
SCHEMA = ROOT / "analytics/schema/phase7_tables.sql"
VERSION = "phase7-v1"
NOW = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def clear(con: sqlite3.Connection, tables: list[str]) -> None:
    for t in tables:
        con.execute(f"DELETE FROM {t}")


def seed_tolerance_packs(con: sqlite3.Connection) -> None:
    clear(con, ["tolerance_definition", "tolerance_pack", "exercise_state_machine"])

    packs = [
        (
            "ACS_PPL_ASEL_v1",
            "1.0",
            "FAA_ACS",
            "Private Pilot Airplane",
            "ASEL",
            None,
            None,
            "FAA-S-ACS-6 (Private Pilot Airplane) — operationalized numeric tolerances for Phase 7 pilot",
            "2024-01-01",
            "Certification standards; PE/checkride target.",
        ),
        (
            "IPCA_TRAINING_PR_v1",
            "1.0",
            "IPCA_SOP",
            "IPCA training",
            "ASEL",
            None,
            None,
            "IPCA training expected tolerances at PR (wider than ACS certification)",
            "2024-01-01",
            "Training expected — not certification.",
        ),
        (
            "IPCA_TRAINING_PE_v1",
            "1.0",
            "IPCA_SOP",
            "IPCA training",
            "ASEL",
            None,
            None,
            "IPCA training expected tolerances at PE (aligned closer to ACS)",
            "2024-01-01",
            "Training expected near certification.",
        ),
    ]
    con.executemany(
        """INSERT INTO tolerance_pack
        (tolerance_pack_id,version,regulatory_framework,training_program,aircraft_category,aircraft_type,
         curriculum_version,source_reference,effective_date,notes,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)""",
        [(*p, VERSION, NOW) for p in packs],
    )

    # metric defs: (pack, exercise, metric, target, min, max, unit, phase, level, class, hard/soft, notes, provenance)
    defs = []

    def add(pack, ex, metric, target, lo, hi, unit, phase, level, tclass, hard, notes, prov):
        defs.append((pack, ex, metric, target, lo, hi, unit, phase, level, tclass, hard, notes, prov, VERSION, NOW))

    # --- ACS PPL certification ---
    # Steep turns PA.V.A
    add("ACS_PPL_ASEL_v1", "steep_turn", "altitude_deviation_ft", 0, -100, 100, "ft", "EXECUTION", "PE", "CERTIFICATION_STANDARD", "HARD", "±100 ft", "ACS PA.V.A")
    add("ACS_PPL_ASEL_v1", "steep_turn", "airspeed_deviation_kt", 0, -10, 10, "kt", "EXECUTION", "PE", "CERTIFICATION_STANDARD", "HARD", "±10 kt", "ACS PA.V.A")
    add("ACS_PPL_ASEL_v1", "steep_turn", "bank_abs_deg", 45, 40, 50, "deg", "EXECUTION", "PE", "CERTIFICATION_STANDARD", "HARD", "45° ±5°", "ACS PA.V.A")
    add("ACS_PPL_ASEL_v1", "steep_turn", "rollout_heading_error_deg", 0, -10, 10, "deg", "EXIT", "PE", "CERTIFICATION_STANDARD", "HARD", "±10°", "ACS PA.V.A")

    # Slow flight PA.VII.A — maintain altitude/heading; airspeed +10/-0 of target (target set per attempt)
    add("ACS_PPL_ASEL_v1", "slow_flight", "altitude_deviation_ft", 0, -100, 100, "ft", "EXECUTION", "PE", "CERTIFICATION_STANDARD", "HARD", "±100 ft", "ACS PA.VII.A")
    add("ACS_PPL_ASEL_v1", "slow_flight", "heading_deviation_deg", 0, -10, 10, "deg", "EXECUTION", "PE", "CERTIFICATION_STANDARD", "HARD", "±10°", "ACS PA.VII.A")
    add("ACS_PPL_ASEL_v1", "slow_flight", "airspeed_deviation_kt", 0, 0, 10, "kt", "EXECUTION", "PE", "CERTIFICATION_STANDARD", "HARD", "+10/-0 of specified", "ACS PA.VII.A")

    # Power-off stall
    add("ACS_PPL_ASEL_v1", "power_off_stall", "heading_deviation_deg", 0, -10, 10, "deg", "EXECUTION", "PE", "CERTIFICATION_STANDARD", "HARD", "±10° straight", "ACS PA.VII.B")
    add("ACS_PPL_ASEL_v1", "power_off_stall", "bank_abs_deg", 0, 0, 20, "deg", "EXECUTION", "PE", "CERTIFICATION_STANDARD", "SOFT", "≤20° unintended bank", "ACS PA.VII.B operationalized")

    # Power-on stall
    add("ACS_PPL_ASEL_v1", "power_on_stall", "heading_deviation_deg", 0, -10, 10, "deg", "EXECUTION", "PE", "CERTIFICATION_STANDARD", "HARD", "±10°", "ACS PA.VII.C")
    add("ACS_PPL_ASEL_v1", "power_on_stall", "bank_abs_deg", 0, 0, 20, "deg", "EXECUTION", "PE", "CERTIFICATION_STANDARD", "SOFT", "≤20°", "ACS PA.VII.C operationalized")

    # Normal approach / landing / go-around (training-useful; ACS landing more qualitative)
    add("ACS_PPL_ASEL_v1", "normal_approach", "airspeed_deviation_kt", 0, -5, 10, "kt", "EXECUTION", "PE", "CERTIFICATION_STANDARD", "SOFT", "Approach speed band operationalized", "IPCA/ACS hybrid")
    add("ACS_PPL_ASEL_v1", "normal_approach", "vertical_speed_fpm", -500, -800, -200, "fpm", "EXECUTION", "PE", "CERTIFICATION_STANDARD", "SOFT", "Stable approach VS band", "IPCA operationalized")
    add("ACS_PPL_ASEL_v1", "normal_landing", "touchdown_vs_fpm", -100, -300, 0, "fpm", "EXIT", "PE", "CERTIFICATION_STANDARD", "SOFT", "Firmness proxy via VS near touchdown", "IPCA operationalized")
    add("ACS_PPL_ASEL_v1", "go_around", "pitch_positive_after_goaround", 1, 0.5, None, "boolish", "EXECUTION", "PE", "CERTIFICATION_STANDARD", "SOFT", "Positive climb attitude after go-around", "IPCA operationalized")

    # --- Training PR (wider) ---
    for ex, metrics in [
        ("steep_turn", [("altitude_deviation_ft", 0, -150, 150, "ft"), ("airspeed_deviation_kt", 0, -15, 15, "kt"), ("bank_abs_deg", 45, 35, 55, "deg"), ("rollout_heading_error_deg", 0, -15, 15, "deg")]),
        ("slow_flight", [("altitude_deviation_ft", 0, -150, 150, "ft"), ("heading_deviation_deg", 0, -15, 15, "deg"), ("airspeed_deviation_kt", 0, -5, 15, "kt")]),
        ("power_off_stall", [("heading_deviation_deg", 0, -15, 15, "deg"), ("bank_abs_deg", 0, 0, 25, "deg")]),
        ("power_on_stall", [("heading_deviation_deg", 0, -15, 15, "deg"), ("bank_abs_deg", 0, 0, 25, "deg")]),
        ("normal_approach", [("airspeed_deviation_kt", 0, -8, 12, "kt"), ("vertical_speed_fpm", -500, -900, -150, "fpm")]),
        ("normal_landing", [("touchdown_vs_fpm", -100, -400, 50, "fpm")]),
        ("go_around", [("pitch_positive_after_goaround", 1, 0.3, None, "boolish")]),
    ]:
        for m, t, lo, hi, u in metrics:
            add("IPCA_TRAINING_PR_v1", ex, m, t, lo, hi, u, "EXECUTION" if "rollout" not in m and "touchdown" not in m else "EXIT", "PR", "TRAINING_EXPECTED", "SOFT", "PR training band", "IPCA Phase 7")

    # --- Training PE (near ACS) ---
    for row in list(defs):
        if row[0] == "ACS_PPL_ASEL_v1":
            add("IPCA_TRAINING_PE_v1", row[1], row[2], row[3], row[4], row[5], row[6], row[7], "PE", "TRAINING_EXPECTED", row[10], "PE training ≈ ACS", "IPCA Phase 7")

    con.executemany(
        """INSERT INTO tolerance_definition
        (tolerance_pack_id,exercise_code,metric,target,minimum,maximum,unit,applicable_phase,
         applicable_expected_level,tolerance_class,hard_or_soft,notes,provenance,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
        defs,
    )

    machines = [
        (
            "steep_turn",
            "Steep Turn",
            {"altitude_established": True, "airspeed_established": True, "clearing_turns": "preferred"},
            {"bank_abs_min_deg": 40, "min_seconds": 3},
            ["clear_area", "entry", "left_or_right_360", "rollout"],
            {"metrics": ["altitude_deviation_ft", "airspeed_deviation_kt", "bank_abs_deg", "rollout_heading_error_deg"]},
            {"heading_change_deg_abs_gte": 300, "bank_reduced_below_deg": 30},
            {"bank_never_reached_40": True, "duration_lt_sec": 3},
        ),
        (
            "slow_flight",
            "Slow Flight",
            {"configuration_set": True},
            {"ias_max_kt": 55, "min_seconds": 8},
            ["configure", "entry", "maneuver", "recover_cruise"],
            {"metrics": ["altitude_deviation_ft", "heading_deviation_deg", "airspeed_deviation_kt"]},
            {"ias_increase_after_recovery": True},
            {"duration_lt_sec": 8},
        ),
        (
            "power_off_stall",
            "Power-Off Stall",
            {"clear_area": True, "config_approach_or_landing": True},
            {"rpm_max": 2000, "pitch_min_deg": 12, "min_seconds": 2},
            ["configure", "power_reduce", "pitch_to_aoa", "recognize", "recover"],
            {"metrics": ["heading_deviation_deg", "bank_abs_deg"]},
            {"nose_down_then_power": True},
            {"no_pitch_rise": True},
        ),
        (
            "power_on_stall",
            "Power-On Stall",
            {"clear_area": True, "takeoff_config": True},
            {"rpm_min": 2000, "pitch_min_deg": 15, "min_seconds": 2},
            ["configure", "power_set", "pitch_up", "recognize", "recover"],
            {"metrics": ["heading_deviation_deg", "bank_abs_deg"]},
            {"nose_down_recovery": True},
            {"no_pitch_rise": True},
        ),
        (
            "normal_approach",
            "Normal Approach",
            {"configured_for_landing": True},
            {"descending": True, "ias_in_approach_band": True},
            ["base", "final", "stable_approach"],
            {"metrics": ["airspeed_deviation_kt", "vertical_speed_fpm"]},
            {"reached_short_final_or_landing": True},
            {"go_around_initiated": True},
        ),
        (
            "normal_landing",
            "Normal Landing",
            {"on_final": True},
            {"gs_decreasing": True, "near_ground": True},
            ["flare", "touchdown", "rollout"],
            {"metrics": ["touchdown_vs_fpm"]},
            {"groundspeed_near_zero_or_taxi": True},
            {"go_around": True},
        ),
        (
            "go_around",
            "Go-Around",
            {"approach_or_landing_phase": True},
            {"power_increase": True, "pitch_up": True},
            ["decide", "power", "pitch", "configure", "climb"],
            {"metrics": ["pitch_positive_after_goaround"]},
            {"positive_climb": True},
            {"continued_landing": True},
        ),
    ]
    con.executemany(
        """INSERT INTO exercise_state_machine
        (exercise_code,display_name,entry_conditions_json,active_state_json,procedural_sequence_json,
         measurement_window_json,completion_condition_json,abort_condition_json,marker_authoritative,
         analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,1,?,?)""",
        [
            (
                e,
                name,
                json.dumps(entry),
                json.dumps(active),
                json.dumps(seq),
                json.dumps(meas),
                json.dumps(comp),
                json.dumps(abort),
                VERSION,
                NOW,
            )
            for e, name, entry, active, seq, meas, comp, abort in machines
        ],
    )


def secret_status(con: sqlite3.Connection) -> dict:
    key = os.environ.get("CW_OPENAI_API_KEY") or os.environ.get("OPENAI_API_KEY") or ""
    # Also peek .env without treating EV as valid
    env_path = ROOT / ".env"
    env_val = ""
    if env_path.exists():
        for line in env_path.read_text(errors="ignore").splitlines():
            if line.startswith("CW_OPENAI_API_KEY="):
                env_val = line.split("=", 1)[1].strip().strip('"').strip("'")
    usable = bool(key) and key.startswith("sk-") and not key.startswith("EV[")
    env_is_ev = env_val.startswith("EV[")
    remaining = con.execute(
        """
        SELECT COUNT(*) FROM analysis_phase6_nlp_population p
        WHERE p.text_hash NOT IN (
          SELECT text_hash FROM analysis_narrative_extraction
          WHERE extraction_version='phase5-extract-v1-agent' AND parse_status='OK'
        )
        """
    ).fetchone()[0]
    done = con.execute(
        """
        SELECT COUNT(DISTINCT text_hash) FROM analysis_narrative_extraction
        WHERE extraction_version='phase5-extract-v1-agent' AND parse_status='OK'
        """
    ).fetchone()[0]
    status = "RUNTIME_KEY_AVAILABLE" if usable else "REQUIRES_SECRET_INJECTION"
    mechanism = (
        "DigitalOcean App Platform decrypts EV[...] at runtime and injects plaintext into process env. "
        "This repo has no local EV decryptor. For analytics CLI: export CW_OPENAI_API_KEY from DO "
        "Control Panel / App Platform runtime (never commit plaintext). "
        "Then run analytics/etl/phase7_05_llm_enrich.py."
    )
    notes = (
        f".env CW_OPENAI_API_KEY is EV ciphertext={env_is_ev}; "
        f"process env usable_sk={usable}; "
        f"llm_hashes_done={done}; remaining_targeted≈{remaining}."
    )
    clear(con, ["phase7_secret_injection_status"])
    con.execute(
        """INSERT INTO phase7_secret_injection_status
        (status,mechanism,llm_processed_n,llm_remaining_n,notes,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?)""",
        (status, mechanism, int(done), int(remaining), notes, VERSION, NOW),
    )
    return {"status": status, "usable": usable, "done": done, "remaining": remaining}


def main() -> None:
    con = sqlite3.connect(DB)
    con.executescript(SCHEMA.read_text())
    seed_tolerance_packs(con)
    st = secret_status(con)
    clear(con, ["phase7_meta"])
    con.execute(
        "INSERT INTO phase7_meta (analysis_version,generated_at,notes) VALUES (?,?,?)",
        (VERSION, NOW, json.dumps({"bootstrap": True, "secret": st})),
    )
    con.commit()
    con.close()
    print(f"Phase 7 bootstrap complete secret_status={st['status']} remaining_llm≈{st['remaining']}", flush=True)


if __name__ == "__main__":
    main()
