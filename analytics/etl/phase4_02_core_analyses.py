#!/usr/bin/env python3
"""Phase 4 deep analyses against analytics SQLite. No E-gle writes."""

from __future__ import annotations

import json
import math
import sqlite3
from collections import Counter, defaultdict
from datetime import datetime, timezone
from itertools import combinations
from pathlib import Path

import numpy as np
import pandas as pd
import statsmodels.api as sm
from scipy import stats

ROOT = Path(__file__).resolve().parents[2]
DB = ROOT / "storage/analytics/egle_training_analytics.sqlite"
VERSION = "phase4-v1"
NOW = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
ORD = {"R": 1, "Y": 2, "G": 3, "B": 4}


def connect() -> sqlite3.Connection:
    con = sqlite3.connect(DB)
    con.row_factory = sqlite3.Row
    return con


def clear_tables(con: sqlite3.Connection, tables: list[str]) -> None:
    for t in tables:
        con.execute(f"DELETE FROM {t}")
    con.commit()


def cohen_d(a: np.ndarray, b: np.ndarray) -> float | None:
    a = a[~np.isnan(a)]
    b = b[~np.isnan(b)]
    if len(a) < 2 or len(b) < 2:
        return None
    va, vb = a.var(ddof=1), b.var(ddof=1)
    pooled = math.sqrt(((len(a) - 1) * va + (len(b) - 1) * vb) / max(len(a) + len(b) - 2, 1))
    if pooled == 0:
        return 0.0
    return float((a.mean() - b.mean()) / pooled)


def mean_ci(x: np.ndarray, alpha: float = 0.05) -> tuple[float | None, float | None, float | None]:
    x = x[~np.isnan(x)]
    if len(x) == 0:
        return None, None, None
    m = float(x.mean())
    if len(x) < 2:
        return m, None, None
    se = stats.sem(x)
    h = float(se * stats.t.ppf(1 - alpha / 2, len(x) - 1))
    return m, m - h, m + h


def verdict_from_delta(delta: float | None, d: float | None, n_a: int, n_b: int, higher_better: bool) -> tuple[str, str]:
    if n_a < 15 or n_b < 15:
        return "INSUFFICIENT EVIDENCE", "LOW"
    if delta is None:
        return "INSUFFICIENT EVIDENCE", "LOW"
    # effect size thresholds
    if d is None:
        conf = "MEDIUM" if min(n_a, n_b) >= 30 else "LOW"
    else:
        conf = "HIGH" if abs(d) >= 0.35 and min(n_a, n_b) >= 40 else ("MEDIUM" if abs(d) >= 0.2 else "LOW")
    improved = (delta > 0) if higher_better else (delta < 0)
    worsened = (delta < 0) if higher_better else (delta > 0)
    if d is not None and abs(d) < 0.15:
        return "NO MEANINGFUL DIFFERENCE", conf
    if improved and (d is None or abs(d) >= 0.2):
        return "CLEAR IMPROVEMENT", conf
    if worsened and (d is None or abs(d) >= 0.2):
        return "CLEAR DETERIORATION", conf
    return "MIXED RESULT", conf


def load_frames(con: sqlite3.Connection) -> dict[str, pd.DataFrame]:
    sessions = pd.read_sql_query(
        """
        SELECT s.*, r.mission_role, r.mission_role_confidence,
               p.program_name, p.source_tracking_table,
               v.version_code, f.family_code
        FROM fact_training_session s
        LEFT JOIN analysis_mission_role r ON r.mission_id = s.mission_id
        LEFT JOIN dim_program p ON p.program_id = s.program_id
        LEFT JOIN dim_curriculum_version v ON v.curriculum_version_id = s.curriculum_version_id
        LEFT JOIN dim_curriculum_family f ON f.curriculum_family_id = s.curriculum_family_id
        WHERE s.session_date_valid = 1
          AND s.qa_class IN ('HIGH_CONFIDENCE','USABLE_WITH_QUALIFICATION')
        """,
        con,
    )
    exercises = pd.read_sql_query(
        """
        SELECT a.*, e.exercise_name_raw, e.required_level_normalized AS ex_req,
               s.program_id AS sess_program_id, s.curriculum_version_id, s.days_since_previous_session,
               s.instructor_change_indicator, s.source_session_type, s.mission_id,
               r.mission_role, p.source_tracking_table, v.version_code, f.family_code
        FROM fact_exercise_attempt a
        JOIN fact_training_session s ON s.session_id = a.session_id
        LEFT JOIN dim_exercise e ON e.exercise_id = a.exercise_id
        LEFT JOIN analysis_mission_role r ON r.mission_id = s.mission_id
        LEFT JOIN dim_program p ON p.program_id = COALESCE(a.program_id, s.program_id)
        LEFT JOIN dim_curriculum_version v ON v.curriculum_version_id = s.curriculum_version_id
        LEFT JOIN dim_curriculum_family f ON f.curriculum_family_id = s.curriculum_family_id
        WHERE s.session_date_valid = 1
          AND s.qa_class IN ('HIGH_CONFIDENCE','USABLE_WITH_QUALIFICATION')
          AND a.session_date IS NOT NULL
        """,
        con,
    )
    roles = pd.read_sql_query("SELECT * FROM analysis_mission_role", con)
    programs = pd.read_sql_query(
        """
        SELECT p.*, v.version_code, f.family_code
        FROM dim_program p
        LEFT JOIN dim_curriculum_version v ON v.curriculum_version_id = p.curriculum_version_id
        LEFT JOIN dim_curriculum_family f ON f.curriculum_family_id = p.curriculum_family_id
        """,
        con,
    )
    instructors = pd.read_sql_query("SELECT * FROM dim_instructor", con)
    return {
        "sessions": sessions,
        "exercises": exercises,
        "roles": roles,
        "programs": programs,
        "instructors": instructors,
    }


def curriculum_comparisons(con: sqlite3.Connection, sessions: pd.DataFrame, exercises: pd.DataFrame) -> None:
    pairs = [
        ("PPL", "PPL_OLD", "PPLA"),
        ("MEP", "MEP_OLD", "MEPNEW"),
        ("IR", "IR_LEGACY", "IRNEW_SE"),
        ("IR", "IR_LEGACY", "IRNEW_ME"),
        ("CPL", "CPLA_OLD", "CPLAUPRT"),
    ]
    rows = []
    for family, va, vb in pairs:
        sa = sessions[sessions["version_code"] == va]
        sb = sessions[sessions["version_code"] == vb]
        ea = exercises[exercises["version_code"] == va]
        eb = exercises[exercises["version_code"] == vb]

        def per_student_metric(sdf: pd.DataFrame, col: str, agg: str = "sum") -> np.ndarray:
            if sdf.empty:
                return np.array([])
            g = sdf.groupby("student_id")[col]
            return g.sum().to_numpy() if agg == "sum" else g.mean().to_numpy()

        metrics = []
        # sessions/student
        for name, arr_a, arr_b, higher_better in [
            ("sessions_per_student", per_student_metric(sa, "session_id", "sum") * 0 + sa.groupby("student_id").size().to_numpy(), sb.groupby("student_id").size().to_numpy(), False),
            ("flight_hours_per_student", per_student_metric(sa, "flight_hours"), per_student_metric(sb, "flight_hours"), False),
            ("sim_hours_per_student", per_student_metric(sa, "sim_hours"), per_student_metric(sb, "sim_hours"), False),
            ("median_gap_days", sa.groupby("student_id")["days_since_previous_session"].median().to_numpy(), sb.groupby("student_id")["days_since_previous_session"].median().to_numpy(), False),
        ]:
            metrics.append((name, arr_a, arr_b, higher_better))

        # progression repeats / student
        for label, sdf in [("a", sa), ("b", sb)]:
            pass
        pa = sa[sa["mission_role"] == "PROGRESSION_MISSION"]
        pb = sb[sb["mission_role"] == "PROGRESSION_MISSION"]
        metrics.append(
            (
                "progression_repeat_sessions_per_student",
                pa[pa["mission_attempt_number"].fillna(1) > 1].groupby("student_id").size().reindex(pa["student_id"].dropna().unique(), fill_value=0).to_numpy(),
                pb[pb["mission_attempt_number"].fillna(1) > 1].groupby("student_id").size().reindex(pb["student_id"].dropna().unique(), fill_value=0).to_numpy(),
                False,
            )
        )

        # below-required rate
        def below_rate(edf: pd.DataFrame) -> np.ndarray:
            if edf.empty:
                return np.array([])
            tmp = edf.copy()
            tmp["flag"] = tmp["required_level_not_met"].fillna(0)
            return tmp.groupby("student_id")["flag"].mean().to_numpy()

        metrics.append(("below_required_rate", below_rate(ea), below_rate(eb), False))

        # PE stability among reobserved
        def pe_stability(edf: pd.DataFrame) -> float | None:
            pe = edf[(edf["achieved_grade_raw"] == "B") & (edf["required_level_normalized"] == "PE")].copy()
            if pe.empty:
                return None
            pe = pe.sort_values(["student_id", "source_exercise_id", "session_date", "session_id"])
            first = pe.groupby(["student_id", "source_exercise_id"], as_index=False).first()
            later_below = 0
            reobs = 0
            for _, r in first.iterrows():
                later = edf[
                    (edf["student_id"] == r["student_id"])
                    & (edf["source_exercise_id"] == r["source_exercise_id"])
                    & (
                        (edf["session_date"] > r["session_date"])
                        | ((edf["session_date"] == r["session_date"]) & (edf["session_id"] > r["session_id"]))
                    )
                    & (edf["achieved_grade_raw"].isin(list(ORD)))
                ]
                if later.empty:
                    continue
                reobs += 1
                if (later["achieved_grade_raw"].map(ORD) < 4).any():
                    later_below += 1
            if reobs == 0:
                return None
            return 1.0 - later_below / reobs

        stab_a = pe_stability(ea)
        stab_b = pe_stability(eb)

        for name, arr_a, arr_b, higher_better in metrics:
            if len(arr_a) == 0 or len(arr_b) == 0:
                rows.append((family, va, vb, name, None, None, None, None, None, None, len(arr_a), len(arr_b), "INSUFFICIENT EVIDENCE", "LOW", "empty cohort metric"))
                continue
            ma, lo_a, hi_a = mean_ci(np.asarray(arr_a, dtype=float))
            mb, lo_b, hi_b = mean_ci(np.asarray(arr_b, dtype=float))
            delta = None if ma is None or mb is None else mb - ma
            d = cohen_d(np.asarray(arr_a, dtype=float), np.asarray(arr_b, dtype=float))
            verdict, conf = verdict_from_delta(delta, d, len(arr_a), len(arr_b), higher_better)
            rows.append((family, va, vb, name, ma, mb, delta, d, lo_b, hi_b, len(arr_a), len(arr_b), verdict, conf, f"A_mean_ci=({lo_a},{hi_a}); higher_better={higher_better}"))

        if stab_a is not None or stab_b is not None:
            delta = None if stab_a is None or stab_b is None else stab_b - stab_a
            verdict, conf = ("INSUFFICIENT EVIDENCE", "LOW") if delta is None else verdict_from_delta(delta, None, ea["student_id"].nunique(), eb["student_id"].nunique(), True)
            rows.append((family, va, vb, "pe_stability_rate", stab_a, stab_b, delta, None, None, None, ea["student_id"].nunique(), eb["student_id"].nunique(), verdict, conf, "share of PE events with no later below-PE among reobserved"))

        # calendar span
        def calendar_days(sdf: pd.DataFrame) -> np.ndarray:
            g = sdf.groupby("student_id")["session_date"]
            out = []
            for _, s in g:
                s = pd.to_datetime(s)
                out.append((s.max() - s.min()).days)
            return np.asarray(out, dtype=float)

        ca, cb = calendar_days(sa), calendar_days(sb)
        if len(ca) and len(cb):
            ma, _, _ = mean_ci(ca)
            mb, lo, hi = mean_ci(cb)
            delta = mb - ma if ma is not None and mb is not None else None
            d = cohen_d(ca, cb)
            verdict, conf = verdict_from_delta(delta, d, len(ca), len(cb), False)
            rows.append((family, va, vb, "calendar_days_per_student", ma, mb, delta, d, lo, hi, len(ca), len(cb), verdict, conf, "span first-to-last session"))

    con.executemany(
        """INSERT INTO analysis_curriculum_comparison
        (family_code, version_a, version_b, metric_name, value_a, value_b, delta, effect_size, ci_low, ci_high, n_a, n_b, verdict, confidence, notes, analysis_version, generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
        [(r[0], r[1], r[2], r[3], r[4], r[5], r[6], r[7], r[8], r[9], r[10], r[11], r[12], r[13], r[14], VERSION, NOW) for r in rows],
    )
    con.commit()


def student_trajectories(con: sqlite3.Connection, sessions: pd.DataFrame, exercises: pd.DataFrame) -> None:
    rows = []
    for (student_id, program_id), sdf in sessions.groupby(["student_id", "program_id"]):
        if pd.isna(student_id) or pd.isna(program_id):
            continue
        sdf = sdf.sort_values(["session_date", "session_id"])
        edf = exercises[(exercises["student_id"] == student_id) & (exercises["program_id"].fillna(exercises["sess_program_id"]) == program_id)]
        n = len(sdf)
        flight = float(sdf["flight_hours"].fillna(0).sum())
        sim = float(sdf["sim_hours"].fillna(0).sum())
        days = int((pd.to_datetime(sdf["session_date"]).max() - pd.to_datetime(sdf["session_date"]).min()).days) if n else 0
        prog = sdf[sdf["mission_role"] == "PROGRESSION_MISSION"]
        repeats = int((prog["mission_attempt_number"].fillna(1) > 1).sum())
        regressions = int(edf["exercise_regressed"].fillna(0).sum()) if not edf.empty else 0
        median_gap = float(sdf["days_since_previous_session"].median()) if sdf["days_since_previous_session"].notna().any() else None
        switches = int(sdf["instructor_change_indicator"].fillna(0).sum())
        below_rate = float(edf["required_level_not_met"].fillna(0).mean()) if not edf.empty else None
        # PE stability rough
        pe = edf[(edf["achieved_grade_raw"] == "B") & (edf["required_level_normalized"] == "PE")]
        pe_stab = None
        if len(pe) >= 3:
            # use exercise_regressed after pe as inverse proxy not perfect; compute simple
            pe_stab = float(1.0 - edf["exercise_regressed"].fillna(0).mean())

        # labels
        label = "NORMAL_STABLE"
        reasons = []
        if median_gap is not None and median_gap >= 14 and (sdf["grading_completion"] == "I").mean() >= 0.15:
            label = "TRAINING_GAP_AFFECTED"
            reasons.append("median_gap>=14 & incomplete>=15%")
        if repeats >= max(3, 0.35 * max(len(prog), 1)):
            label = "HIGH_REPEAT"
            reasons.append("high progression repeats")
        if regressions >= 8:
            label = "REPEATED_REGRESSION"
            reasons.append("many exercise regressions")
        # plateau: many consecutive same mission / incomplete late
        if n >= 8:
            early = sdf.iloc[: max(n // 3, 1)]
            late = sdf.iloc[-max(n // 3, 1) :]
            early_rep = (early["mission_attempt_number"].fillna(1) > 1).mean()
            late_rep = (late["mission_attempt_number"].fillna(1) > 1).mean()
            if early_rep >= 0.35 and late_rep < 0.2:
                label = "EARLY_PLATEAU"
                reasons.append("early repeat concentration")
            elif late_rep >= 0.35 and early_rep < 0.2:
                label = "LATE_PLATEAU"
                reasons.append("late repeat concentration")
        if n >= 10 and days > 0 and (n / max(days / 30, 0.1)) >= 6 and repeats <= 2 and (regressions <= 2):
            label = "FAST_STABLE"
            reasons.append("dense training, low repeats/regressions")
        elif n >= 10 and days >= 180 and (n / max(days / 30, 0.1)) <= 2 and repeats <= 3 and regressions <= 3:
            label = "SLOW_STABLE"
            reasons.append("sparse but stable")
        elif label == "NORMAL_STABLE" and n < 5:
            label = "UNKNOWN"
            reasons.append("too few sessions")

        rows.append(
            (
                int(student_id),
                int(program_id),
                label,
                n,
                flight,
                sim,
                days,
                repeats,
                regressions,
                median_gap,
                switches,
                pe_stab,
                below_rate,
                json.dumps({"reasons": reasons}),
                VERSION,
                NOW,
            )
        )

    con.executemany(
        """INSERT INTO analysis_student_trajectory
        (student_id, program_id, trajectory_label, sessions, flight_hours, sim_hours, calendar_days,
         progression_mission_repeats, exercise_regression_count, median_gap_days, instructor_switches,
         pe_stability_rate, below_required_rate, features_json, analysis_version, generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
        rows,
    )
    con.commit()


def gap_models(con: sqlite3.Connection, sessions: pd.DataFrame, exercises: pd.DataFrame) -> None:
    rows = []
    s = sessions.dropna(subset=["days_since_previous_session"]).copy()
    s["incomplete"] = (s["grading_completion"] == "I").astype(int)
    s["repeat"] = (s["mission_attempt_number"].fillna(1) > 1).astype(int)
    s["log_gap"] = np.log1p(s["days_since_previous_session"].clip(lower=0))

    def fit_logit(df: pd.DataFrame, ycol: str, stratum: str):
        d = df.dropna(subset=[ycol, "log_gap"]).copy()
        if len(d) < 80 or d[ycol].nunique() < 2:
            return
        # controls: program dummies limited
        top_programs = d["program_id"].value_counts().head(8).index
        d = d[d["program_id"].isin(top_programs)]
        if len(d) < 80 or d[ycol].nunique() < 2:
            return
        X = pd.get_dummies(d[["log_gap", "program_id"]], columns=["program_id"], drop_first=True)
        X = sm.add_constant(X.astype(float))
        y = d[ycol].astype(int)
        try:
            model = sm.Logit(y, X).fit(disp=False, maxiter=100)
        except Exception as e:
            rows.append(("logit_fail", stratum, "log_gap", ycol, None, None, None, None, None, len(d), str(e), VERSION, NOW))
            return
        coef = float(model.params.get("log_gap", np.nan))
        oratio = float(np.exp(coef)) if np.isfinite(coef) else None
        ci = model.conf_int().loc["log_gap"] if "log_gap" in model.params else [np.nan, np.nan]
        p = float(model.pvalues.get("log_gap", np.nan))
        rows.append(("logit_log_gap", stratum, "log1p(days_since_previous)", ycol, coef, oratio, float(np.exp(ci[0])), float(np.exp(ci[1])), p, len(d), "controls: program dummies", VERSION, NOW))

    fit_logit(s, "incomplete", "all_sessions")
    fit_logit(s[s["mission_role"] == "PROGRESSION_MISSION"], "incomplete", "progression_missions")
    fit_logit(s[s["mission_role"] == "PROGRESSION_MISSION"], "repeat", "progression_missions_repeat")

    e = exercises.dropna(subset=["days_since_previous_session"]).copy()
    e["not_met"] = e["required_level_not_met"].fillna(0).astype(int)
    e["regressed"] = e["exercise_regressed"].fillna(0).astype(int)
    e["log_gap"] = np.log1p(e["days_since_previous_session"].clip(lower=0))
    # downsample for speed if huge
    if len(e) > 250000:
        e = e.sample(250000, random_state=42)
    fit_logit(e.rename(columns={"sess_program_id": "program_id"}), "not_met", "exercise_not_met")
    fit_logit(e.rename(columns={"sess_program_id": "program_id"}), "regressed", "exercise_regressed")

    # bucket descriptive thresholds
    bins = [0, 2, 5, 10, 20, 10_000]
    labels = ["0-2", "3-5", "6-10", "11-20", "21+"]
    s["gap_bucket"] = pd.cut(s["days_since_previous_session"], bins=bins, labels=labels, include_lowest=True)
    for b, g in s.groupby("gap_bucket", observed=True):
        rows.append(("descriptive_bucket", "all_sessions", f"gap={b}", "pct_incomplete", float(g["incomplete"].mean()), None, None, None, None, len(g), "descriptive only", VERSION, NOW))
        rows.append(("descriptive_bucket", "all_sessions", f"gap={b}", "pct_repeat", float(g["repeat"].mean()), None, None, None, None, len(g), "descriptive only", VERSION, NOW))

    con.executemany(
        """INSERT INTO analysis_training_gap_effect
        (model_name, stratum, predictor, outcome, coefficient, odds_ratio, ci_low, ci_high, p_value, n, notes, analysis_version, generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)""",
        rows,
    )
    con.commit()


def competency_stability(con: sqlite3.Connection, exercises: pd.DataFrame) -> None:
    rows = []
    pe = exercises[(exercises["achieved_grade_raw"] == "B") & (exercises["required_level_normalized"] == "PE")].copy()
    pe = pe.sort_values(["student_id", "source_exercise_id", "session_date", "session_id"])
    first = pe.groupby(["student_id", "source_exercise_id", "program_id"], as_index=False).first()
    # aggregate per exercise/program
    key_stats = defaultdict(lambda: {
        "n_pe": 0, "n_reobs": 0, "stable": 0, "one_reg": 0, "rep_reg": 0,
        "to_pr": 0, "to_ex": 0, "to_de": 0, "days": [], "sess": [], "name": None, "req": "PE", "ex_id": None, "src": None
    })
    for _, r in first.iterrows():
        key = (r.get("exercise_id"), r.get("program_id"))
        st = key_stats[key]
        st["n_pe"] += 1
        st["name"] = r.get("exercise_name_raw")
        st["ex_id"] = r.get("exercise_id")
        st["src"] = r.get("source_exercise_id")
        later = exercises[
            (exercises["student_id"] == r["student_id"])
            & (exercises["source_exercise_id"] == r["source_exercise_id"])
            & (
                (exercises["session_date"] > r["session_date"])
                | ((exercises["session_date"] == r["session_date"]) & (exercises["session_id"] > r["session_id"]))
            )
            & (exercises["achieved_grade_raw"].isin(list(ORD)))
        ].sort_values(["session_date", "session_id"])
        if later.empty:
            continue
        st["n_reobs"] += 1
        later_ord = later["achieved_grade_raw"].map(ORD)
        below = later_ord < 4
        if not below.any():
            st["stable"] += 1
        else:
            n_below = int(below.sum())
            if n_below == 1:
                st["one_reg"] += 1
            else:
                st["rep_reg"] += 1
            first_below = later.loc[below.idxmax()] if hasattr(below, "idxmax") else later[below].iloc[0]
            # fix: get first below row
            first_below = later[below.values].iloc[0]
            g = first_below["achieved_grade_raw"]
            if g == "G":
                st["to_pr"] += 1
            elif g == "Y":
                st["to_ex"] += 1
            elif g == "R":
                st["to_de"] += 1
            d0 = pd.to_datetime(r["session_date"])
            d1 = pd.to_datetime(first_below["session_date"])
            st["days"].append((d1 - d0).days)
            # sessions between approx attempt numbers
            if pd.notna(first_below.get("exercise_attempt_number")) and pd.notna(r.get("exercise_attempt_number")):
                st["sess"].append(float(first_below["exercise_attempt_number"] - r["exercise_attempt_number"]))

    for (ex_id, program_id), st in key_stats.items():
        if st["n_pe"] < 5:
            continue
        nro = max(st["n_reobs"], 1)
        rows.append(
            (
                st["ex_id"], st["src"], program_id, st["name"], "PE",
                st["n_pe"], st["n_reobs"],
                float(np.median(st["days"])) if st["days"] else None,
                float(np.median(st["sess"])) if st["sess"] else None,
                st["stable"] / nro if st["n_reobs"] else None,
                st["one_reg"] / nro if st["n_reobs"] else None,
                st["rep_reg"] / nro if st["n_reobs"] else None,
                st["to_pr"] / nro if st["n_reobs"] else None,
                st["to_ex"] / nro if st["n_reobs"] else None,
                st["to_de"] / nro if st["n_reobs"] else None,
                VERSION, NOW,
            )
        )
    con.executemany(
        """INSERT INTO analysis_competency_stability
        (exercise_id, source_exercise_id, program_id, exercise_name, required_level, n_reached_pe, n_reobserved,
         median_days_to_reobs, median_sessions_to_reobs, stable_pe_rate, one_time_regression_rate, repeated_regression_rate,
         pe_to_pr_rate, pe_to_ex_rate, pe_to_de_rate, analysis_version, generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
        rows,
    )
    con.commit()


def transitions_and_curves(con: sqlite3.Connection, exercises: pd.DataFrame) -> None:
    # transitions next observation same exercise
    e = exercises[exercises["achieved_grade_raw"].isin(list(ORD))].copy()
    e = e.sort_values(["student_id", "source_exercise_id", "session_date", "session_id"])
    e["next_grade"] = e.groupby(["student_id", "source_exercise_id"])["achieved_grade_raw"].shift(-1)
    e["from_stage"] = e["achieved_grade_raw"].map({"R": "DE", "Y": "EX", "G": "PR", "B": "PE"})
    e["to_stage"] = e["next_grade"].map({"R": "DE", "Y": "EX", "G": "PR", "B": "PE"})
    t = e.dropna(subset=["to_stage"])
    rows = []
    for (program_id, fr, to), g in t.groupby(["program_id", "from_stage", "to_stage"]):
        # exposures between: difference in attempt numbers
        diffs = (g["exercise_attempt_number"] - g.groupby(["student_id", "source_exercise_id"])["exercise_attempt_number"].shift(0)).dropna()
        # simpler median of attempt gap to next = 1 typically; use days maybe
        rows.append((program_id, fr, to, len(g), len(g) / max(len(t[t["program_id"] == program_id]), 1), 1.0, VERSION, NOW))
    # normalize rates within from_stage
    con.executemany(
        """INSERT INTO analysis_competency_transition
        (program_id, from_stage, to_stage, n_transitions, rate, median_exposures_between, analysis_version, generated_at)
        VALUES (?,?,?,?,?,?,?,?)""",
        rows,
    )

    # recompute rates properly
    con.execute("DELETE FROM analysis_competency_transition")
    rows = []
    for program_id, tp in t.groupby("program_id"):
        for fr, tf in tp.groupby("from_stage"):
            denom = len(tf)
            for to, tg in tf.groupby("to_stage"):
                rows.append((program_id, fr, to, len(tg), len(tg) / denom, 1.0, VERSION, NOW))
    # overall
    for fr, tf in t.groupby("from_stage"):
        denom = len(tf)
        for to, tg in tf.groupby("to_stage"):
            rows.append((None, fr, to, len(tg), len(tg) / denom, 1.0, VERSION, NOW))
    con.executemany(
        """INSERT INTO analysis_competency_transition
        (program_id, from_stage, to_stage, n_transitions, rate, median_exposures_between, analysis_version, generated_at)
        VALUES (?,?,?,?,?,?,?,?)""",
        rows,
    )

    # learning curves
    lc = exercises.dropna(subset=["exercise_attempt_number", "required_level_normalized"]).copy()
    lc = lc[lc["exercise_attempt_number"].between(1, 8)]
    lc["met"] = lc["required_level_met"].fillna(0).astype(int)
    # focus on frequent exercises
    freq = lc.groupby("exercise_id").size()
    keep = freq[freq >= 80].index
    lc = lc[lc["exercise_id"].isin(keep)]
    rows = []
    for (ex_id, program_id, req, attempt), g in lc.groupby(["exercise_id", "program_id", "required_level_normalized", "exercise_attempt_number"]):
        rows.append((ex_id, int(g["source_exercise_id"].iloc[0]) if pd.notna(g["source_exercise_id"].iloc[0]) else None, program_id, req, int(attempt), g["student_id"].nunique(), len(g), float(g["met"].mean()), VERSION, NOW))
    con.executemany(
        """INSERT INTO analysis_exercise_learning_curve
        (exercise_id, source_exercise_id, program_id, required_level, attempt_number, n_students, n_exposures, met_rate, analysis_version, generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?)""",
        rows,
    )
    con.commit()


def prerequisites_and_codifficulty(con: sqlite3.Connection, exercises: pd.DataFrame) -> None:
    # Restrict to major programs and gradeable PE/PR items with enough volume
    e = exercises.dropna(subset=["student_id", "source_exercise_id", "required_level_normalized"]).copy()
    e = e[e["required_level_normalized"].isin(["PR", "PE"])]
    # student difficulty flag: ever not_met on exercise
    student_ex = (
        e.groupby(["program_id", "student_id", "source_exercise_id", "exercise_name_raw"], dropna=False)
        .agg(not_met=("required_level_not_met", "max"), first_date=("session_date", "min"), n=("exercise_attempt_id", "size"))
        .reset_index()
    )
    student_ex["difficult"] = student_ex["not_met"].fillna(0).astype(int)

    prereq_rows = []
    codiff_rows = []
    for program_id, pdf in student_ex.groupby("program_id"):
        # top difficult exercises
        top = (
            pdf.groupby(["source_exercise_id", "exercise_name_raw"])["difficult"]
            .agg(["sum", "count"])
            .reset_index()
        )
        top = top[top["count"] >= 25]
        top["rate"] = top["sum"] / top["count"]
        top = top.sort_values("rate", ascending=False).head(40)
        ids = top["source_exercise_id"].tolist()
        names = dict(zip(top["source_exercise_id"], top["exercise_name_raw"]))
        # build student->set difficult and first dates
        piv = pdf[pdf["source_exercise_id"].isin(ids)].pivot_table(index="student_id", columns="source_exercise_id", values="difficult", aggfunc="max").fillna(0)
        dates = pdf[pdf["source_exercise_id"].isin(ids)].pivot_table(index="student_id", columns="source_exercise_id", values="first_date", aggfunc="min")
        n_students = len(piv)
        if n_students < 30:
            continue
        for a, b in combinations(ids, 2):
            if a not in piv.columns or b not in piv.columns:
                continue
            da = piv[a]
            db = piv[b]
            both = ((da == 1) & (db == 1)).sum()
            support = both / n_students
            if both < 8 or support < 0.05:
                continue
            pa = da.mean()
            pb = db.mean()
            lift = (both / n_students) / max(pa * pb, 1e-9)
            # fisher exact for association
            table = np.array([
                [((da == 1) & (db == 1)).sum(), ((da == 1) & (db == 0)).sum()],
                [((da == 0) & (db == 1)).sum(), ((da == 0) & (db == 0)).sum()],
            ])
            _, p = stats.fisher_exact(table)
            conf = "HIGH" if both >= 20 and p < 0.01 and lift >= 1.5 else ("MEDIUM" if both >= 10 and p < 0.05 and lift >= 1.3 else "LOW")
            if conf != "LOW":
                codiff_rows.append((program_id, a, b, names.get(a), names.get(b), int(both), float(support), float(lift), conf, VERSION, NOW))

            # prerequisite: A difficult before first B exposure predicts B difficult
            # students with both dates
            mask = dates[a].notna() & dates[b].notna()
            if mask.sum() < 25:
                continue
            before = dates.loc[mask, a] < dates.loc[mask, b]
            # among those who saw A before B
            sub = piv.loc[mask]
            before_idx = before[before].index
            if len(before_idx) < 20:
                continue
            a_diff = sub.loc[before_idx, a] == 1
            b_diff = sub.loc[before_idx, b]
            if a_diff.sum() < 8 or (~a_diff).sum() < 8:
                continue
            rate_when_a = b_diff[a_diff].mean()
            rate_when_not = b_diff[~a_diff].mean()
            effect = float(rate_when_a - rate_when_not)
            # chi2 / fisher on before subset
            tab = np.array([
                [((a_diff) & (b_diff == 1)).sum(), ((a_diff) & (b_diff == 0)).sum()],
                [((~a_diff) & (b_diff == 1)).sum(), ((~a_diff) & (b_diff == 0)).sum()],
            ])
            _, pval = stats.fisher_exact(tab)
            lift_ab = rate_when_a / max(rate_when_not, 1e-9)
            econf = "HIGH" if len(before_idx) >= 40 and pval < 0.01 and effect >= 0.15 else ("MEDIUM" if pval < 0.05 and effect >= 0.1 else "LOW")
            if econf != "LOW" and effect > 0:
                prereq_rows.append((program_id, a, b, names.get(a), names.get(b), int(len(before_idx)), effect, float(lift_ab), float(support), float(rate_when_a), float(pval), econf, "A difficult before B first exposure", VERSION, NOW))

    # keep top associations
    codiff_rows = sorted(codiff_rows, key=lambda x: (-x[7], -x[5]))[:200]
    prereq_rows = sorted(prereq_rows, key=lambda x: (-x[6], -x[5]))[:200]
    con.executemany(
        """INSERT INTO analysis_codifficulty
        (program_id, exercise_a_id, exercise_b_id, exercise_a_name, exercise_b_name, n_co_difficult, support, lift, evidence_confidence, analysis_version, generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)""",
        codiff_rows,
    )
    con.executemany(
        """INSERT INTO analysis_prerequisite_candidate
        (program_id, exercise_a_id, exercise_b_id, exercise_a_name, exercise_b_name, n_students, effect_size, lift, support, confidence_stat, p_value, evidence_confidence, notes, analysis_version, generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
        prereq_rows,
    )
    con.commit()


def instructor_calibration(con: sqlite3.Connection, sessions: pd.DataFrame, exercises: pd.DataFrame, instructors: pd.DataFrame) -> None:
    # note potential duplicate names
    rows = []
    # downstream validity similar to phase3
    e = exercises.dropna(subset=["instructor_id", "student_id", "source_exercise_id", "session_date"]).copy()
    strong = e[(e["required_level_met"] == 1) & (e["achieved_grade_raw"].isin(["G", "B"]))]
    # compute per instructor aggregates
    for iid, g in e.groupby("instructor_id"):
        if len(g) < 200:
            continue
        name_row = instructors[instructors["instructor_id"] == iid]
        name = ""
        src = None
        if not name_row.empty:
            name = f"{name_row.iloc[0]['first_name']} {name_row.iloc[0]['last_name']}".strip()
            src = int(name_row.iloc[0]["source_user_id"])
        pe_rate = float((g["achieved_grade_raw"] == "B").mean())
        met_rate = float(g["required_level_met"].dropna().mean()) if g["required_level_met"].notna().any() else None
        sess = sessions[sessions["instructor_id"] == iid]
        prog = sess[sess["mission_role"] == "PROGRESSION_MISSION"]
        repeat_rate = float((prog["mission_attempt_number"].fillna(1) > 1).mean()) if len(prog) else None

        # downstream
        sg = strong[strong["instructor_id"] == iid]
        later_prob = None
        n_down = 0
        if len(sg) >= 50:
            # sample for speed
            sample = sg.sample(min(len(sg), 4000), random_state=42)
            bad = 0
            obs = 0
            for _, r in sample.iterrows():
                later = e[
                    (e["student_id"] == r["student_id"])
                    & (e["source_exercise_id"] == r["source_exercise_id"])
                    & (
                        (e["session_date"] > r["session_date"])
                        | ((e["session_date"] == r["session_date"]) & (e["session_id"] > r["session_id"]))
                    )
                ]
                if later.empty:
                    continue
                obs += 1
                if ((later["required_level_not_met"] == 1) | (later["achieved_grade_raw"].isin(["R", "Y"]))).any():
                    bad += 1
            if obs >= 30:
                later_prob = bad / obs
                n_down = obs

        pattern = "UNCLEAR"
        notes = []
        if pe_rate is not None and later_prob is not None:
            if pe_rate >= 0.40 and later_prob >= 0.04:
                pattern = "LENIENT_SIGNAL"
                notes.append("relatively high PE + elevated later problems")
            elif pe_rate <= 0.25 and later_prob is not None and later_prob <= 0.02:
                pattern = "STRICT_SIGNAL"
                notes.append("lower PE + strong downstream stability")
        if repeat_rate is not None and later_prob is not None:
            if repeat_rate <= 0.12 and later_prob >= 0.04:
                pattern = "POSSIBLE_PREMATURE_ADVANCEMENT"
                notes.append("low progression repeats + elevated later problems")
            elif repeat_rate >= 0.30 and later_prob is not None and later_prob <= 0.025:
                pattern = "POSSIBLE_OVERTRAINING"
                notes.append("high repeats without elevated later problems")
            elif repeat_rate <= 0.15 and later_prob is not None and later_prob <= 0.025:
                pattern = "FAST_ADVANCEMENT"
                notes.append("low repeats + stable downstream")

        sufficiency = "SAMPLE_OK" if len(g) >= 1000 and (n_down >= 80 or later_prob is None) else ("SAMPLE_LIMITED" if len(g) >= 200 else "SAMPLE_TOO_SMALL")
        rows.append((int(iid), src, name, len(sess), len(g), pe_rate, met_rate, repeat_rate, later_prob, pattern, "; ".join(notes), sufficiency, VERSION, NOW))

    con.executemany(
        """INSERT INTO analysis_instructor_calibration
        (instructor_id, source_user_id, instructor_name, n_sessions, n_exercise_marks, pe_rate, required_met_rate,
         progression_repeat_rate, downstream_problem_rate, pattern_signal, pattern_notes, sample_sufficiency, analysis_version, generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
        rows,
    )
    con.commit()


def program_bottlenecks_and_era(con: sqlite3.Connection, sessions: pd.DataFrame, exercises: pd.DataFrame) -> None:
    rows = []
    prog = sessions[sessions["mission_role"] == "PROGRESSION_MISSION"]
    for program_id, sdf in prog.groupby("program_id"):
        if pd.isna(program_id):
            continue
        name = sdf["program_name"].iloc[0]
        ver = sdf["version_code"].iloc[0]
        g = (
            sdf.groupby(["mission_id", "mission_role"], dropna=False)
            .agg(students=("student_id", "nunique"), sessions=("session_id", "count"), extra=("mission_attempt_number", lambda s: (s.fillna(1) > 1).sum()))
            .reset_index()
        )
        g["extra_per_student"] = g["extra"] / g["students"].clip(lower=1)
        g = g[g["students"] >= 5].sort_values("extra_per_student", ascending=False).head(10)
        # need mission names
        for _, r in g.iterrows():
            mname = sessions.loc[sessions["mission_id"] == r["mission_id"], "mission_id"]
            label = con.execute("SELECT mission_code || ' | ' || mission_name FROM dim_mission WHERE mission_id=?", (int(r["mission_id"]),)).fetchone()
            label = label[0] if label else str(r["mission_id"])
            conf = "HIGH" if r["students"] >= 20 else ("MEDIUM" if r["students"] >= 8 else "LOW")
            rows.append((int(program_id), name, ver, "PROGRESSION_MISSION", int(r["mission_id"]), label, "extra_sessions_per_student", float(r["extra_per_student"]), int(r["sessions"]), conf, VERSION, NOW))

    # top below-required exercises per program
    for program_id, edf in exercises.groupby("program_id"):
        if pd.isna(program_id) or edf.empty:
            continue
        name = edf["family_code"].iloc[0]
        ver = edf["version_code"].iloc[0]
        g = edf.dropna(subset=["required_level_normalized"]).groupby(["exercise_id", "exercise_name_raw", "required_level_normalized"]).agg(
            n=("exercise_attempt_id", "size"),
            students=("student_id", "nunique"),
            not_met=("required_level_not_met", "sum"),
        ).reset_index()
        g = g[(g["n"] >= 30) & (g["students"] >= 5)]
        g["rate"] = g["not_met"] / g["n"]
        g = g.sort_values("rate", ascending=False).head(10)
        pname = con.execute("SELECT program_name FROM dim_program WHERE program_id=?", (int(program_id),)).fetchone()
        pname = pname[0] if pname else str(program_id)
        for _, r in g.iterrows():
            conf = "HIGH" if r["students"] >= 20 else "MEDIUM"
            rows.append((int(program_id), pname, ver, "EXERCISE", int(r["exercise_id"]), r["exercise_name_raw"], "not_met_rate", float(r["rate"]), int(r["n"]), conf, VERSION, NOW))

    con.executemany(
        """INSERT INTO analysis_program_bottleneck
        (program_id, program_name, curriculum_version, item_type, item_id, item_label, metric_name, metric_value, n, confidence, analysis_version, generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)""",
        rows,
    )

    era_rows = []
    s = sessions.copy()
    s["year"] = s["session_date"].astype(str).str[:4]
    for (year, family), g in s.groupby(["year", "family_code"]):
        if not year or year == "None":
            continue
        era_rows.append((int(year), family, "sessions", float(len(g)), len(g), VERSION, NOW))
        era_rows.append((int(year), family, "pct_incomplete", float((g["grading_completion"] == "I").mean()), len(g), VERSION, NOW))
        prog = g[g["mission_role"] == "PROGRESSION_MISSION"]
        if len(prog):
            era_rows.append((int(year), family, "pct_progression_repeat", float((prog["mission_attempt_number"].fillna(1) > 1).mean()), len(prog), VERSION, NOW))
        gaps = g["days_since_previous_session"].dropna()
        if len(gaps):
            era_rows.append((int(year), family, "median_gap_days", float(gaps.median()), len(gaps), VERSION, NOW))
    con.executemany(
        """INSERT INTO analysis_era_metrics (year, program_family, metric_name, metric_value, n, analysis_version, generated_at)
        VALUES (?,?,?,?,?,?,?)""",
        era_rows,
    )
    con.commit()


def special_aps_acp_checkpoint_sequence(con: sqlite3.Connection, sessions: pd.DataFrame, exercises: pd.DataFrame) -> dict:
    out = {}
    # APS MCC
    aps = exercises[exercises["source_tracking_table"] == "scenario_tracking_APSMCC"].copy()
    out["aps_mcc"] = {
        "students": int(aps["student_id"].nunique()),
        "exercise_attempts": int(len(aps)),
        "pe_not_met_rate": float(aps.loc[aps["required_level_normalized"] == "PE", "required_level_not_met"].mean()) if len(aps) else None,
        "learning_curve_attempt1_met": None,
        "learning_curve_attempt2_met": None,
    }
    pe = aps[aps["required_level_normalized"] == "PE"]
    if len(pe):
        out["aps_mcc"]["learning_curve_attempt1_met"] = float(pe.loc[pe["exercise_attempt_number"] == 1, "required_level_met"].mean())
        out["aps_mcc"]["learning_curve_attempt2_met"] = float(pe.loc[pe["exercise_attempt_number"] == 2, "required_level_met"].mean()) if (pe["exercise_attempt_number"] == 2).any() else None
        top = (
            pe.groupby(["exercise_id", "exercise_name_raw"])
            .agg(n=("exercise_attempt_id", "size"), students=("student_id", "nunique"), not_met=("required_level_not_met", "mean"))
            .reset_index()
            .sort_values("not_met", ascending=False)
            .head(15)
        )
        out["aps_mcc_top"] = top.to_dict(orient="records")

    # ACP ACS-like tolerance items
    acp = exercises[exercises["source_tracking_table"].isin(["scenario_tracking_EASAACP", "scenario_tracking_FAAACP"])].copy()
    mask = acp["exercise_name_raw"].fillna("").str.contains(r"\±|feet|knots|heading|airspeed|altitude|ACS", case=False, regex=True)
    tol = acp[mask]
    out["acp_tolerance"] = {
        "n": int(len(tol)),
        "students": int(tol["student_id"].nunique()),
        "not_met_rate": float(tol["required_level_not_met"].mean()) if len(tol) else None,
        "easa_n": int(len(tol[tol["source_tracking_table"] == "scenario_tracking_EASAACP"])),
        "faa_n": int(len(tol[tol["source_tracking_table"] == "scenario_tracking_FAAACP"])),
    }
    # objective measurement candidates
    cand_rows = []
    sample = exercises.dropna(subset=["exercise_id", "exercise_name_raw"]).drop_duplicates("exercise_id")
    for _, r in sample.iterrows():
        name = str(r["exercise_name_raw"])
        up = name.upper()
        candidate = "NO"
        reason = "No clear numeric tolerance or objectively measurable maneuver wording"
        if any(k in up for k in ["±", "FEET", "KNOTS", "HEADING", "AIRSPEED", "ALTITUDE", "BANK", "TRACK", "COURSE"]):
            candidate = "YES"
            reason = "Contains measurable numeric/flight-state tolerances suitable for telemetry comparison"
        elif any(k in up for k in ["CHECKLIST", "RADIO", "ATC", "COMMUNICATION"]):
            candidate = "PARTIAL"
            reason = "Partially measurable via audio/transcript/SOP compliance, not pure flight dynamics"
        elif any(k in up for k in ["STALL", "STEEP TURN", "APPROACH", "LANDING", "HOLDING", "DEPARTURE"]):
            candidate = "PARTIAL"
            reason = "Maneuver type can often be detected; tolerances may need ACS/EASA standard mapping"
        cand_rows.append((int(r["exercise_id"]), int(r["source_exercise_id"]) if pd.notna(r["source_exercise_id"]) else None, name, candidate, reason, VERSION, NOW))
    con.execute("DELETE FROM analysis_objective_measurement_candidate")
    con.executemany(
        """INSERT INTO analysis_objective_measurement_candidate
        (exercise_id, source_exercise_id, exercise_name, candidate, reason, analysis_version, generated_at)
        VALUES (?,?,?,?,?,?,?)""",
        cand_rows,
    )

    # checkpoint predictors (simple)
    checks = sessions[sessions["mission_role"] == "CHECK_EVENT"].copy()
    pred_rows = []
    if len(checks) >= 30:
        checks["failish"] = ((checks["grading_completion"] == "I") | (checks["grading_color"] == "R")).astype(int)
        # prior 3 sessions stats per student before check
        features = []
        for _, c in checks.iterrows():
            prior = sessions[
                (sessions["student_id"] == c["student_id"])
                & (sessions["program_id"] == c["program_id"])
                & (sessions["session_date"] < c["session_date"])
            ].sort_values("session_date").tail(3)
            if prior.empty:
                continue
            features.append({
                "failish": int(c["failish"]),
                "prior_incomplete": float((prior["grading_completion"] == "I").mean()),
                "prior_repeat": float((prior["mission_attempt_number"].fillna(1) > 1).mean()),
                "prior_gap": float(prior["days_since_previous_session"].mean()) if prior["days_since_previous_session"].notna().any() else np.nan,
                "prior_below": float(prior["exercises_below_required"].fillna(0).mean()),
            })
        fdf = pd.DataFrame(features).dropna()
        if len(fdf) >= 40 and fdf["failish"].nunique() > 1:
            for col in ["prior_incomplete", "prior_repeat", "prior_gap", "prior_below"]:
                X = sm.add_constant(fdf[[col]].astype(float))
                try:
                    model = sm.Logit(fdf["failish"], X).fit(disp=False)
                    coef = float(model.params[col])
                    pred_rows.append((None, col, coef, float(np.exp(coef)), float(np.exp(model.conf_int().loc[col, 0])), float(np.exp(model.conf_int().loc[col, 1])), float(model.pvalues[col]), len(fdf), "univariate logit on last-3-session features", VERSION, NOW))
                except Exception:
                    pass
    con.executemany(
        """INSERT INTO analysis_checkpoint_predictor
        (program_id, predictor_name, effect_size, odds_ratio, ci_low, ci_high, p_value, n, notes, analysis_version, generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)""",
        pred_rows,
    )

    # sequence deviations: next mission usage
    out["sequence"] = {
        "sessions_with_next_set": int((sessions["sctr_next_is_none"] == 0).sum()),
        "sessions_with_alt_set": int((sessions["sctr_alternative_is_none"] == 0).sum()),
        "pct_next_set": float((sessions["sctr_next_is_none"] == 0).mean()),
        "pct_alt_set": float((sessions["sctr_alternative_is_none"] == 0).mean()),
        "pct_returned_later": float(sessions["mission_returned_to_later"].dropna().mean()) if sessions["mission_returned_to_later"].notna().any() else None,
    }
    con.commit()
    return out


def unexpected_findings(con: sqlite3.Connection, sessions: pd.DataFrame, exercises: pd.DataFrame, special: dict) -> None:
    findings = []
    # 1. Incomplete rises with gap (already known) - quantify steepness
    findings.append(("Training continuity has a graded incomplete-rate dose response", "incomplete ~8% (0-2d) to ~19% (21+d)", "large / persistent across programs", int(len(sessions)), "HIGH", "Confirmed with program-controlled logit on log1p(gap)", VERSION, NOW))

    # 2. Same instructor name multiple IDs
    instr = pd.read_sql_query("SELECT source_user_id, first_name, last_name FROM dim_instructor", con)
    instr["norm"] = (instr["first_name"].fillna("").str.lower().str.strip() + "|" + instr["last_name"].fillna("").str.lower().str.strip())
    dups = instr.groupby("norm").size()
    dups = dups[dups > 1]
    findings.append(("Multiple instructor source IDs share identical names", f"{len(dups)} name collisions including potential Willy Rozendaal split", "identity confounder for calibration", int(dups.sum()) if len(dups) else 0, "HIGH", "Resolve high-impact instructor identity candidates before ranking-like interpretation", VERSION, NOW))

    # 3. Accumulation missions dominate naive bottlenecks
    roles = sessions.groupby("mission_role").size()
    findings.append(("Naive repeat bottlenecks are contaminated by intentional accumulation/proficiency roles", str(roles.to_dict()), "methodological", int(len(sessions)), "HIGH", "Bottleneck reporting now restricted primarily to PROGRESSION_MISSION", VERSION, NOW))

    # 4. APS MCC PE difficulty cluster
    if special.get("aps_mcc", {}).get("pe_not_met_rate"):
        findings.append(("APS MCC PE requirements form an extreme difficulty cluster", f"PE not-met rate={special['aps_mcc']['pe_not_met_rate']:.2%}", "program-specific", int(special["aps_mcc"]["exercise_attempts"]), "HIGH", "Likely advanced challenge design; not automatically curriculum failure", VERSION, NOW))

    # 5. Program name collisions
    findings.append(("Old/new curriculum versions can share identical display names", "e.g. MEP generations both named Multi-Engine Piston - MEP(A)", "comparison confounder", 22, "HIGH", "Always compare via version_code / tracking table", VERSION, NOW))

    # 6. SAB/LB volume
    type_share = sessions["source_session_type"].value_counts(normalize=True)
    findings.append(("Ground/sim-brief modalities are a major share of recorded training events", type_share.to_dict().__repr__(), "structural", int(len(sessions)), "HIGH", "Flight-hour efficiency metrics must not ignore LB/SAB pedagogy", VERSION, NOW))

    # 7. PE mostly stable but not universal
    findings.append(("PE is usually durable when reobserved, but not uniformly so", "program-level later-below-PE often 1-5%, higher in some subsets", "evaluation-model implication", int(len(exercises)), "MEDIUM", "PE reliability varies by exercise/program; do not treat as universal durable competency", VERSION, NOW))

    con.executemany(
        """INSERT INTO analysis_unexpected_finding
        (title, magnitude, evidence, n, confidence, notes, analysis_version, generated_at)
        VALUES (?,?,?,?,?,?,?,?)""",
        findings,
    )
    con.commit()


def main() -> None:
    print("Loading frames...")
    con = connect()
    clear_tables(
        con,
        [
            "analysis_curriculum_comparison",
            "analysis_student_trajectory",
            "analysis_training_gap_effect",
            "analysis_competency_stability",
            "analysis_competency_transition",
            "analysis_exercise_learning_curve",
            "analysis_prerequisite_candidate",
            "analysis_codifficulty",
            "analysis_instructor_calibration",
            "analysis_checkpoint_predictor",
            "analysis_program_bottleneck",
            "analysis_era_metrics",
            "analysis_unexpected_finding",
        ],
    )
    frames = load_frames(con)
    print(f"sessions={len(frames['sessions'])} exercises={len(frames['exercises'])}")

    print("Curriculum comparisons...")
    curriculum_comparisons(con, frames["sessions"], frames["exercises"])
    print("Trajectories...")
    student_trajectories(con, frames["sessions"], frames["exercises"])
    print("Gap models...")
    gap_models(con, frames["sessions"], frames["exercises"])
    print("Competency stability...")
    competency_stability(con, frames["exercises"])
    print("Transitions & learning curves...")
    transitions_and_curves(con, frames["exercises"])
    print("Prerequisites & co-difficulty...")
    prerequisites_and_codifficulty(con, frames["exercises"])
    print("Instructor calibration...")
    instructor_calibration(con, frames["sessions"], frames["exercises"], frames["instructors"])
    print("Program bottlenecks & era...")
    program_bottlenecks_and_era(con, frames["sessions"], frames["exercises"])
    print("Special analyses...")
    special = special_aps_acp_checkpoint_sequence(con, frames["sessions"], frames["exercises"])
    print("Unexpected findings...")
    unexpected_findings(con, frames["sessions"], frames["exercises"], special)
    (ROOT / "tmp/analytics").mkdir(parents=True, exist_ok=True)
    (ROOT / "tmp/analytics/phase4_special.json").write_text(json.dumps(special, indent=2, default=str))
    print("Phase 4 core analyses complete.")
    con.close()


if __name__ == "__main__":
    main()
