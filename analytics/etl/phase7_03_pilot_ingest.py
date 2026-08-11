#!/usr/bin/env python3
"""Phase 7: ingest local Cockpit Recorder / Garmin G3X flights, detect attempts, extract metrics."""

from __future__ import annotations

import hashlib
import json
import math
import re
import sqlite3
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path

import numpy as np
import pandas as pd

ROOT = Path(__file__).resolve().parents[2]
DB = ROOT / "storage/analytics/egle_training_analytics.sqlite"
VERSION = "phase7-v1"
NOW = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")

LOCAL_G3X = ROOT / "storage/cockpit_recorder/g3x"
VAULT = ROOT / "tmp/n392ea_cvr_clear/verify_final/IPCACVRUnit/GarminVault/files"


def log(msg: str) -> None:
    print(msg, flush=True)


def clear(con: sqlite3.Connection, tables: list[str]) -> None:
    for t in tables:
        con.execute(f"DELETE FROM {t}")


def parse_g3x(path: Path) -> pd.DataFrame | None:
    raw = path.read_text(errors="ignore").splitlines()
    hdr_i = None
    for i, line in enumerate(raw[:40]):
        if "Date (yyyy-mm-dd)" in line or line.lstrip("#").startswith("Date ("):
            hdr_i = i
            break
    if hdr_i is None:
        return None
    header = [c.strip() for c in raw[hdr_i].lstrip("#").split(",")]
    rows = []
    for line in raw[hdr_i + 1 :]:
        if not line or line.startswith("#"):
            continue
        parts = line.split(",")
        if len(parts) < 20:
            continue
        rows.append(parts[: len(header)])
    if len(rows) < 60:
        return None
    df = pd.DataFrame(rows, columns=header)

    def num(col_aliases: list[str]) -> pd.Series:
        for a in col_aliases:
            for c in df.columns:
                if c.strip() == a or c.strip().lower() == a.lower():
                    return pd.to_numeric(df[c], errors="coerce")
        return pd.Series([np.nan] * len(df))

    out = pd.DataFrame(
        {
            "lat": num(["Latitude (deg)"]),
            "lon": num(["Longitude (deg)"]),
            "gps_alt_ft": num(["GPS Altitude (ft)"]),
            "baro_alt_ft": num(["Baro Altitude (ft)", "Pressure Altitude (ft)"]),
            "ias_kt": num(["Indicated Airspeed (kt)"]),
            "tas_kt": num(["True Airspeed (kt)"]),
            "gs_kt": num(["GPS Ground Speed (kt)"]),
            "hdg_deg": num(["Magnetic Heading (deg)"]),
            "pitch_deg": num(["Pitch (deg)"]),
            "roll_deg": num(["Roll (deg)"]),
            "oat_c": num(["Outside Air Temp (deg C)"]),
            "da_ft": num(["Density Altitude (ft)"]),
            "wind_kt": num(["Wind Speed (kt)"]),
            "wind_dir_deg": num(["Wind Direction (deg)"]),
            "pressure_alt_ft": num(["Pressure Altitude (ft)"]),
        }
    )
    # timestamps
    date_c = next((c for c in df.columns if "Date" in c), None)
    time_c = next((c for c in df.columns if c.startswith("UTC Time") or c == "Time (hh:mm:ss)"), None)
    if date_c and time_c:
        ts = pd.to_datetime(df[date_c].astype(str) + " " + df[time_c].astype(str), errors="coerce", utc=True)
    else:
        ts = pd.Series([pd.NaT] * len(df))
    out["utc"] = ts
    out = out.dropna(subset=["ias_kt", "roll_deg"], how="all").reset_index(drop=True)
    if out.empty:
        return None
    # t_sec from first valid utc or row index
    if out["utc"].notna().any():
        t0 = out["utc"].dropna().iloc[0]
        out["t_sec"] = (out["utc"] - t0).dt.total_seconds()
        out["t_sec"] = out["t_sec"].ffill().fillna(0)
    else:
        out["t_sec"] = np.arange(len(out), dtype=float)
    # approx VS from baro alt
    alt = out["baro_alt_ft"].fillna(out["gps_alt_ft"])
    dt = out["t_sec"].diff().replace(0, np.nan)
    out["vs_fpm"] = (alt.diff() / dt) * 60.0
    out["bank_abs"] = out["roll_deg"].abs()
    # aircraft from airframe_info line
    airframe = ""
    for line in raw[:5]:
        m = re.search(r'aircraft_ident="([^"]+)"', line)
        if m:
            airframe = m.group(1)
            break
    out.attrs["aircraft_ident"] = airframe or "UNKNOWN"
    out.attrs["source_path"] = str(path)
    return out


def crosswind_component(wind_kt, wind_dir, hdg) -> float | None:
    if any(pd.isna(x) for x in (wind_kt, wind_dir, hdg)):
        return None
    # runway/heading relative
    ang = math.radians(float(wind_dir) - float(hdg))
    return float(wind_kt) * math.sin(ang)


def detect_segments(df: pd.DataFrame) -> list[dict]:
    """Telemetry-derived exercise windows using catalog-like rules. Labeled TELEMETRY_DERIVED."""
    segs = []
    t = df["t_sec"].to_numpy()
    bank = df["bank_abs"].to_numpy()
    ias = df["ias_kt"].to_numpy()
    pitch = df["pitch_deg"].to_numpy()
    gs = df["gs_kt"].to_numpy()
    vs = df["vs_fpm"].to_numpy()
    alt = df["baro_alt_ft"].fillna(df["gps_alt_ft"]).to_numpy()

    def add(code, i0, i1, conf, extra=None):
        i0 = max(0, int(i0))
        i1 = min(int(i1), len(df) - 1)
        if i1 <= i0 or (t[i1] - t[i0]) < 1.5:
            return
        segs.append(
            {
                "exercise_code": code,
                "i0": i0,
                "i1": i1,
                "t0": float(t[i0]),
                "t1": float(t[i1]),
                "confidence": conf,
                "extra": extra or {},
            }
        )

    # Steep turns: bank >= 40 for >= 3s contiguous
    mask = bank >= 40
    i = 0
    n = len(df)
    while i < n:
        if not mask[i] or np.isnan(bank[i]):
            i += 1
            continue
        j = i
        while j < n and mask[j]:
            j += 1
        if t[j - 1] - t[i] >= 3.0:
            # extend slightly for rollout
            k = j
            while k < n and k < j + 15 and bank[k] >= 15:
                k += 1
            add("steep_turn", i, k, 0.75)
        i = max(j, i + 1)

    # Slow flight: IAS <= 55 for >= 8s, airborne > 500, not on ground
    mask = (ias <= 55) & (alt > 500) & (gs > 20)
    i = 0
    while i < n:
        if not mask[i] or np.isnan(ias[i]):
            i += 1
            continue
        j = i
        while j < n and mask[j]:
            j += 1
        if t[j - 1] - t[i] >= 8.0:
            add("slow_flight", i, j, 0.65)
        i = max(j, i + 1)

    # Power-off stall candidate: pitch > 12, IAS decreasing, low power proxy (IAS low / pitch high)
    mask = (pitch >= 12) & (ias < 70) & (alt > 800)
    i = 0
    while i < n:
        if not mask[i] or np.isnan(pitch[i]):
            i += 1
            continue
        j = i
        while j < n and pitch[j] >= 8 and ias[j] < 80:
            j += 1
        if t[min(j - 1, n - 1)] - t[i] >= 2.0:
            add("power_off_stall", max(0, i - 5), min(n - 1, j + 10), 0.55)
        i = max(j, i + 1)

    # Power-on stall: high pitch + higher speed/energy
    mask = (pitch >= 15) & (ias >= 45) & (alt > 800)
    i = 0
    while i < n:
        if not mask[i] or np.isnan(pitch[i]):
            i += 1
            continue
        j = i
        while j < n and pitch[j] >= 10:
            j += 1
        if t[min(j - 1, n - 1)] - t[i] >= 2.0:
            add("power_on_stall", max(0, i - 5), min(n - 1, j + 10), 0.5)
        i = max(j, i + 1)

    # Normal approach: descending, IAS 55-90, alt 200-1500 decreasing
    mask = (vs < -200) & (ias >= 55) & (ias <= 95) & (alt < 1500) & (alt > 50)
    i = 0
    while i < n:
        if not mask[i] or np.isnan(vs[i]):
            i += 1
            continue
        j = i
        while j < n and (vs[j] < -100 or alt[j] < alt[i]) and ias[j] <= 100 and alt[j] < 1600:
            j += 1
            if j - i > 400:
                break
        if t[min(j - 1, n - 1)] - t[i] >= 20.0:
            add("normal_approach", i, j, 0.6)
            # landing if gs drops
            k = j
            while k < n and k < j + 40:
                if gs[k] < 30 and alt[k] < 100:
                    add("normal_landing", j - 5, min(n - 1, k + 5), 0.55)
                    break
                k += 1
        i = max(j, i + 1)

    # Go-around: from descending to climbing with pitch up
    for i in range(1, n - 10):
        if vs[i] < -300 and pitch[i + 5] > 5 and vs[i + 8] > 100 and ias[i] < 100:
            add("go_around", i, min(n - 1, i + 40), 0.5)
            break

    # Deduplicate overlapping same-code (keep highest conf / longest)
    segs.sort(key=lambda s: (s["exercise_code"], s["t0"]))
    cleaned = []
    for s in segs:
        if cleaned and cleaned[-1]["exercise_code"] == s["exercise_code"] and s["t0"] < cleaned[-1]["t1"] + 5:
            if (s["t1"] - s["t0"]) > (cleaned[-1]["t1"] - cleaned[-1]["t0"]):
                cleaned[-1] = s
            continue
        cleaned.append(s)
    return cleaned


def circular_diff(a, b):
    d = (a - b + 180) % 360 - 180
    return d


def extract_metrics(df: pd.DataFrame, seg: dict, tolerances: dict) -> list[dict]:
    sl = df.iloc[seg["i0"] : seg["i1"] + 1].copy()
    if sl.empty:
        return []
    code = seg["exercise_code"]
    metrics = []
    alt = sl["baro_alt_ft"].fillna(sl["gps_alt_ft"])
    t = sl["t_sec"].to_numpy()
    dt = np.diff(t, prepend=t[0])
    dt[0] = 0

    def eval_band(metric, series, target, lo, hi, unit, phase="EXECUTION"):
        s = pd.to_numeric(series, errors="coerce").dropna()
        if s.empty:
            return
        if target is None:
            target = 0.0
        # For absolute bank target, series is bank_abs
        if metric == "bank_abs_deg":
            dev = s - float(target)
            max_dev = float(np.nanmax(np.abs(dev)))
            avg_dev = float(np.nanmean(np.abs(dev)))
            outside = (s < lo) | (s > hi) if lo is not None and hi is not None else pd.Series([False] * len(s))
        elif metric.endswith("_deviation_ft") or metric.endswith("_deviation_kt") or metric.endswith("_deviation_deg"):
            # deviation from entry reference
            ref = float(s.iloc[0]) if "heading" not in metric else float(s.iloc[0])
            if "heading" in metric:
                vals = s.apply(lambda x: circular_diff(x, ref))
            else:
                vals = s - ref
            max_dev = float(np.nanmax(np.abs(vals)))
            avg_dev = float(np.nanmean(np.abs(vals)))
            outside = (vals < lo) | (vals > hi) if lo is not None and hi is not None else pd.Series([False] * len(vals))
            s = vals
        else:
            max_dev = float(np.nanmax(np.abs(s - float(target)))) if target is not None else float(np.nanmax(s))
            avg_dev = float(np.nanmean(np.abs(s - float(target)))) if target is not None else float(np.nanmean(s))
            outside = (s < lo) | (s > hi) if lo is not None and hi is not None else pd.Series([False] * len(s))

        # time outside
        if len(outside) == len(dt):
            t_out = float(np.nansum(dt[np.asarray(outside, dtype=bool)]))
        else:
            # align
            out_arr = np.asarray(outside, dtype=bool)
            t_out = float(len(out_arr[out_arr]) * (np.nanmedian(dt[dt > 0]) if np.any(dt > 0) else 1.0))
        pct = float(1.0 - (np.mean(np.asarray(outside, dtype=float)) if len(outside) else 0.0))
        within = bool(lo is not None and hi is not None and float(s.min()) >= lo and float(s.max()) <= hi) if metric == "bank_abs_deg" else bool(
            lo is not None and hi is not None and float(np.nanmax(np.abs(s))) <= max(abs(lo), abs(hi))
        )
        # Better within: fraction
        within = bool(pct >= 0.85) if metric != "bank_abs_deg" else bool(np.nanmedian(pd.to_numeric(series, errors="coerce")) >= lo and np.nanmedian(pd.to_numeric(series, errors="coerce")) <= hi)

        metrics.append(
            {
                "metric": metric,
                "phase": phase,
                "actual_value": float(np.nanmedian(pd.to_numeric(series, errors="coerce"))) if metric == "bank_abs_deg" else float(np.nanmax(np.abs(s))) if len(s) else None,
                "target_value": target,
                "lower_tolerance": lo,
                "upper_tolerance": hi,
                "unit": unit,
                "max_deviation": max_dev,
                "avg_deviation": avg_dev,
                "time_outside_tolerance_sec": t_out,
                "pct_within_tolerance": pct,
                "within_standard": int(within),
                "raw": {"n": int(len(s)), "p05": float(np.nanpercentile(s, 5)) if len(s) else None, "p95": float(np.nanpercentile(s, 95)) if len(s) else None},
            }
        )

    pack_metrics = tolerances.get(code, {})
    if code == "steep_turn":
        # altitude / airspeed deviations from entry
        if "altitude_deviation_ft" in pack_metrics:
            p = pack_metrics["altitude_deviation_ft"]
            ref = float(alt.iloc[0])
            eval_band("altitude_deviation_ft", alt - ref, 0, p["minimum"], p["maximum"], "ft")
        if "airspeed_deviation_kt" in pack_metrics:
            p = pack_metrics["airspeed_deviation_kt"]
            ref = float(sl["ias_kt"].iloc[0])
            eval_band("airspeed_deviation_kt", sl["ias_kt"] - ref, 0, p["minimum"], p["maximum"], "kt")
        if "bank_abs_deg" in pack_metrics:
            p = pack_metrics["bank_abs_deg"]
            eval_band("bank_abs_deg", sl["bank_abs"], p["target"], p["minimum"], p["maximum"], "deg")
        if "rollout_heading_error_deg" in pack_metrics:
            p = pack_metrics["rollout_heading_error_deg"]
            h0 = float(sl["hdg_deg"].iloc[0])
            h1 = float(sl["hdg_deg"].iloc[-1])
            # expect ~360 change; error vs nearest full turn
            change = (h1 - h0) % 360
            err = min(abs(change - 360), abs(change), abs(change - 180))  # coarse
            # better: distance to 0 after full circles
            turns = round(change / 360.0) if change > 180 else 0
            target_end = (h0 + 360 * max(1, turns if turns else 1)) % 360
            err = abs(circular_diff(h1, h0))  # residual after removing intent unknown
            # Use heading change modulo 360 distance to 0
            residual = min(change % 360, 360 - (change % 360))
            # If flew ~360, residual small is good
            err = residual if change > 200 else abs(360 - change) if change > 100 else abs(change)
            metrics.append(
                {
                    "metric": "rollout_heading_error_deg",
                    "phase": "EXIT",
                    "actual_value": float(err),
                    "target_value": 0,
                    "lower_tolerance": p["minimum"],
                    "upper_tolerance": p["maximum"],
                    "unit": "deg",
                    "max_deviation": float(err),
                    "avg_deviation": float(err),
                    "time_outside_tolerance_sec": 0.0,
                    "pct_within_tolerance": 1.0 if abs(err) <= abs(p["maximum"]) else 0.0,
                    "within_standard": int(abs(err) <= abs(p["maximum"])),
                    "raw": {"heading_start": h0, "heading_end": h1, "change_mod360": change},
                }
            )
    elif code == "slow_flight":
        if "altitude_deviation_ft" in pack_metrics:
            p = pack_metrics["altitude_deviation_ft"]
            ref = float(alt.iloc[0])
            eval_band("altitude_deviation_ft", alt - ref, 0, p["minimum"], p["maximum"], "ft")
        if "heading_deviation_deg" in pack_metrics:
            p = pack_metrics["heading_deviation_deg"]
            ref = float(sl["hdg_deg"].iloc[0])
            vals = sl["hdg_deg"].apply(lambda x: circular_diff(x, ref))
            eval_band("heading_deviation_deg", vals, 0, p["minimum"], p["maximum"], "deg")
        if "airspeed_deviation_kt" in pack_metrics:
            p = pack_metrics["airspeed_deviation_kt"]
            # target = median IAS in segment (specified airspeed unknown)
            target = float(sl["ias_kt"].median())
            eval_band("airspeed_deviation_kt", sl["ias_kt"] - target, 0, p["minimum"], p["maximum"], "kt")
    elif code in ("power_off_stall", "power_on_stall"):
        if "heading_deviation_deg" in pack_metrics:
            p = pack_metrics["heading_deviation_deg"]
            ref = float(sl["hdg_deg"].iloc[0])
            vals = sl["hdg_deg"].apply(lambda x: circular_diff(x, ref))
            eval_band("heading_deviation_deg", vals, 0, p["minimum"], p["maximum"], "deg")
        if "bank_abs_deg" in pack_metrics:
            p = pack_metrics["bank_abs_deg"]
            eval_band("bank_abs_deg", sl["bank_abs"], 0, p["minimum"], p["maximum"], "deg")
    elif code == "normal_approach":
        if "airspeed_deviation_kt" in pack_metrics:
            p = pack_metrics["airspeed_deviation_kt"]
            target = float(sl["ias_kt"].median())
            eval_band("airspeed_deviation_kt", sl["ias_kt"] - target, 0, p["minimum"], p["maximum"], "kt")
        if "vertical_speed_fpm" in pack_metrics:
            p = pack_metrics["vertical_speed_fpm"]
            eval_band("vertical_speed_fpm", sl["vs_fpm"], p["target"], p["minimum"], p["maximum"], "fpm")
    elif code == "normal_landing":
        if "touchdown_vs_fpm" in pack_metrics:
            p = pack_metrics["touchdown_vs_fpm"]
            near = sl.tail(min(10, len(sl)))["vs_fpm"]
            metrics.append(
                {
                    "metric": "touchdown_vs_fpm",
                    "phase": "EXIT",
                    "actual_value": float(near.median()) if len(near) else None,
                    "target_value": p["target"],
                    "lower_tolerance": p["minimum"],
                    "upper_tolerance": p["maximum"],
                    "unit": "fpm",
                    "max_deviation": float(near.min()) if len(near) else None,
                    "avg_deviation": float(near.mean()) if len(near) else None,
                    "time_outside_tolerance_sec": 0.0,
                    "pct_within_tolerance": 1.0 if len(near) and p["minimum"] <= float(near.median()) <= p["maximum"] else 0.0,
                    "within_standard": int(len(near) and p["minimum"] <= float(near.median()) <= p["maximum"]),
                    "raw": {},
                }
            )
    elif code == "go_around":
        if "pitch_positive_after_goaround" in pack_metrics:
            p = pack_metrics["pitch_positive_after_goaround"]
            pitch_med = float(sl["pitch_deg"].tail(min(15, len(sl))).median())
            ok = pitch_med >= (p["minimum"] or 0.5)
            metrics.append(
                {
                    "metric": "pitch_positive_after_goaround",
                    "phase": "EXECUTION",
                    "actual_value": pitch_med,
                    "target_value": p["target"],
                    "lower_tolerance": p["minimum"],
                    "upper_tolerance": p["maximum"],
                    "unit": "deg",
                    "max_deviation": pitch_med,
                    "avg_deviation": pitch_med,
                    "time_outside_tolerance_sec": 0.0,
                    "pct_within_tolerance": 1.0 if ok else 0.0,
                    "within_standard": int(ok),
                    "raw": {},
                }
            )
    return metrics


def load_tolerance_map(con: sqlite3.Connection, pack_id: str) -> dict:
    rows = con.execute(
        """SELECT exercise_code, metric, target, minimum, maximum, unit, applicable_phase, tolerance_class
           FROM tolerance_definition WHERE tolerance_pack_id=?""",
        (pack_id,),
    ).fetchall()
    out: dict = {}
    for ex, metric, target, lo, hi, unit, phase, tclass in rows:
        out.setdefault(ex, {})[metric] = {
            "target": target,
            "minimum": lo,
            "maximum": hi,
            "unit": unit,
            "phase": phase,
            "tolerance_class": tclass,
            "pack_id": pack_id,
        }
    return out


def collect_flight_paths(max_vault: int = 18) -> list[tuple[str, Path]]:
    paths: list[tuple[str, Path]] = []
    for p in LOCAL_G3X.rglob("*.g3x.csv"):
        paths.append(("LOCAL_G3X", p))
    vault_csvs = sorted(VAULT.glob("*.csv"), key=lambda x: -x.stat().st_size) if VAULT.exists() else []
    for p in vault_csvs[:max_vault]:
        # skip tiny metadata
        if p.stat().st_size < 200_000:
            continue
        paths.append(("GARMIN_VAULT", p))
    return paths


def ingest(con: sqlite3.Connection) -> None:
    log("Ingesting pilot flights + exercise attempts...")
    clear(
        con,
        [
            "pilot_flight",
            "pilot_exercise_attempt",
            "pilot_objective_metric",
            "pilot_independence_observation",
            "pilot_context",
            "pilot_environmental_observation",
            "pilot_intervention_event",
        ],
    )
    # Use PE training pack ≈ ACS for evaluation; also store certification comparison later
    tols = load_tolerance_map(con, "IPCA_TRAINING_PE_v1")
    acs = load_tolerance_map(con, "ACS_PPL_ASEL_v1")

    paths = collect_flight_paths()
    log(f"Candidate flight files: {len(paths)}")
    flight_n = attempt_n = 0
    for kind, path in paths:
        df = parse_g3x(path)
        if df is None or len(df) < 80:
            continue
        # Prefer airborne flights
        if float(np.nanmax(df["gs_kt"].fillna(0))) < 40:
            continue
        fid = hashlib.sha1(f"{kind}:{path.name}:{len(df)}".encode()).hexdigest()[:16]
        aircraft = df.attrs.get("aircraft_ident", "UNKNOWN")
        start_utc = str(df["utc"].dropna().iloc[0]) if df["utc"].notna().any() else None
        end_utc = str(df["utc"].dropna().iloc[-1]) if df["utc"].notna().any() else None
        con.execute(
            """INSERT INTO pilot_flight
            (pilot_flight_id,operational_session_id,source_kind,aircraft_ident,source_path,start_utc,end_utc,
             sample_count,student_id,instructor_id,quality_notes,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)""",
            (
                fid,
                fid,  # local operational session proxy
                kind,
                aircraft,
                str(path),
                start_utc,
                end_utc,
                int(len(df)),
                None,
                None,
                "Local/vault G3X ingest; exercise boundaries TELEMETRY_DERIVED (no instructor markers in local sample).",
                VERSION,
                NOW,
            ),
        )
        flight_n += 1

        # environmental subsample
        env = df.iloc[:: max(1, len(df) // 40)]
        env_rows = []
        for r in env.itertuples():
            env_rows.append(
                (
                    fid,
                    float(r.t_sec) if pd.notna(r.t_sec) else None,
                    float(r.lat) if pd.notna(r.lat) else None,
                    float(r.lon) if pd.notna(r.lon) else None,
                    float(r.oat_c) if pd.notna(r.oat_c) else None,
                    float(r.pressure_alt_ft) if pd.notna(r.pressure_alt_ft) else None,
                    float(r.da_ft) if pd.notna(r.da_ft) else None,
                    float(r.wind_kt) if pd.notna(r.wind_kt) else None,
                    float(r.wind_dir_deg) if pd.notna(r.wind_dir_deg) else None,
                    float(r.gps_alt_ft) if pd.notna(r.gps_alt_ft) else None,
                    VERSION,
                    NOW,
                )
            )
        con.executemany(
            """INSERT INTO pilot_environmental_observation
            (pilot_flight_id,t_sec,lat,lon,oat_c,pressure_altitude_ft,density_altitude_ft,wind_speed_kt,
             wind_direction_deg,gps_altitude_ft,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)""",
            env_rows,
        )

        # flight-level context
        wind_kt = float(df["wind_kt"].median()) if df["wind_kt"].notna().any() else None
        wind_dir = float(df["wind_dir_deg"].median()) if df["wind_dir_deg"].notna().any() else None
        hdg = float(df["hdg_deg"].median()) if df["hdg_deg"].notna().any() else None
        xw = crosswind_component(wind_kt, wind_dir, hdg) if wind_kt is not None else None
        turb = float(df["roll_deg"].diff().abs().median()) if len(df) > 10 else None
        day_night = "DAY"  # UTC heuristic insufficient; leave DAY unless hour known
        if df["utc"].notna().any():
            hour = int(df["utc"].dropna().iloc[0].hour)
            day_night = "NIGHT" if hour < 5 or hour >= 19 else "DAY"
        con.execute(
            """INSERT INTO pilot_context
            (pilot_flight_id,attempt_id,wind_speed_kt,wind_direction_deg,crosswind_component_kt,gust_spread_kt,
             oat_c,density_altitude_ft,turbulence_proxy,airport,runway,day_night,aircraft_ident,training_gap_days,
             flight_phase,atc_environment,exercise_complexity,raw_values_json,derivation_mode,analysis_version,generated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
            (
                fid,
                None,
                wind_kt,
                wind_dir,
                xw,
                None,
                float(df["oat_c"].median()) if df["oat_c"].notna().any() else None,
                float(df["da_ft"].median()) if df["da_ft"].notna().any() else None,
                turb,
                None,
                None,
                day_night,
                aircraft,
                None,
                None,
                None,
                None,
                json.dumps({"source": kind, "samples": len(df)}),
                "AUTO_G3X",
                VERSION,
                NOW,
            ),
        )

        segs = detect_segments(df)
        # attempt numbers per exercise
        counts: dict[str, int] = {}
        for seg in segs:
            code = seg["exercise_code"]
            counts[code] = counts.get(code, 0) + 1
            aid = f"{fid}_{code}_{counts[code]}"
            expected = "PE"  # pilot default; training pack PE
            start_idx = min(max(0, seg["i0"]), len(df) - 1)
            end_idx = min(max(0, seg["i1"]), len(df) - 1)
            if end_idx < start_idx:
                continue
            start_u = str(df.iloc[start_idx]["utc"]) if pd.notna(df.iloc[start_idx]["utc"]) else None
            end_u = str(df.iloc[end_idx]["utc"]) if pd.notna(df.iloc[end_idx]["utc"]) else None
            seg = {**seg, "i0": start_idx, "i1": end_idx}
            con.execute(
                """INSERT INTO pilot_exercise_attempt
                (attempt_id,pilot_flight_id,operational_session_id,actual_leg_id,exercise_code,attempt_number,
                 boundary_source,t_start_sec,t_end_sec,start_utc,end_utc,expected_level,entry_ok,completed,aborted,
                 detection_confidence,evidence_json,analysis_version,generated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
                (
                    aid,
                    fid,
                    fid,
                    None,
                    code,
                    counts[code],
                    "TELEMETRY_DERIVED",
                    seg["t0"],
                    seg["t1"],
                    start_u,
                    end_u,
                    expected,
                    1,
                    1,
                    0,
                    seg["confidence"],
                    json.dumps({"detector": "phase7_telemetry_v1", "extra": seg.get("extra", {})}),
                    VERSION,
                    NOW,
                ),
            )
            # default independence NOT_OBSERVED
            con.execute(
                """INSERT INTO pilot_independence_observation
                (attempt_id,independence_state,source,captured_at,analysis_version,generated_at)
                VALUES (?,?,?,?,?,?)""",
                (aid, "NOT_OBSERVED", "DEFAULT", NOW, VERSION, NOW),
            )
            # metrics vs PE training pack
            mets = extract_metrics(df, seg, tols)
            for m in mets:
                pack_meta = tols.get(code, {}).get(m["metric"], {})
                con.execute(
                    """INSERT INTO pilot_objective_metric
                    (attempt_id,metric,phase,actual_value,target_value,lower_tolerance,upper_tolerance,unit,
                     max_deviation,avg_deviation,time_outside_tolerance_sec,pct_within_tolerance,within_standard,
                     tolerance_pack_id,tolerance_class,raw_payload_json,analysis_version,generated_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
                    (
                        aid,
                        m["metric"],
                        m["phase"],
                        m["actual_value"],
                        m["target_value"],
                        m["lower_tolerance"],
                        m["upper_tolerance"],
                        m["unit"],
                        m["max_deviation"],
                        m["avg_deviation"],
                        m["time_outside_tolerance_sec"],
                        m["pct_within_tolerance"],
                        m["within_standard"],
                        pack_meta.get("pack_id", "IPCA_TRAINING_PE_v1"),
                        pack_meta.get("tolerance_class", "TRAINING_EXPECTED"),
                        json.dumps(m.get("raw", {})),
                        VERSION,
                        NOW,
                    ),
                )
            # attempt context snapshot
            sl = df.iloc[seg["i0"] : seg["i1"] + 1]
            xw_a = crosswind_component(
                float(sl["wind_kt"].median()) if sl["wind_kt"].notna().any() else None,
                float(sl["wind_dir_deg"].median()) if sl["wind_dir_deg"].notna().any() else None,
                float(sl["hdg_deg"].median()) if sl["hdg_deg"].notna().any() else None,
            )
            con.execute(
                """INSERT INTO pilot_context
                (pilot_flight_id,attempt_id,wind_speed_kt,wind_direction_deg,crosswind_component_kt,gust_spread_kt,
                 oat_c,density_altitude_ft,turbulence_proxy,airport,runway,day_night,aircraft_ident,training_gap_days,
                 flight_phase,atc_environment,exercise_complexity,raw_values_json,derivation_mode,analysis_version,generated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
                (
                    fid,
                    aid,
                    float(sl["wind_kt"].median()) if sl["wind_kt"].notna().any() else wind_kt,
                    float(sl["wind_dir_deg"].median()) if sl["wind_dir_deg"].notna().any() else wind_dir,
                    xw_a if xw_a is not None else xw,
                    None,
                    float(sl["oat_c"].median()) if sl["oat_c"].notna().any() else None,
                    float(sl["da_ft"].median()) if sl["da_ft"].notna().any() else None,
                    float(sl["roll_deg"].diff().abs().median()) if len(sl) > 5 else turb,
                    None,
                    None,
                    day_night,
                    aircraft,
                    None,
                    code,
                    None,
                    None,
                    json.dumps({"t0": seg["t0"], "t1": seg["t1"]}),
                    "AUTO_G3X",
                    VERSION,
                    NOW,
                ),
            )
            attempt_n += 1

        if flight_n >= 20:
            break

    con.commit()
    log(f"Ingested flights={flight_n} attempts={attempt_n}")


def main() -> None:
    con = sqlite3.connect(DB)
    con.execute("PRAGMA busy_timeout=60000")
    ingest(con)
    con.close()


if __name__ == "__main__":
    main()
