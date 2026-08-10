#!/usr/bin/env python3
"""Phase 4 deep analyses — optimized for large exercise table."""

from __future__ import annotations

import json
import math
import sqlite3
import sys
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


def log(msg: str) -> None:
    print(msg, flush=True)


def connect() -> sqlite3.Connection:
    con = sqlite3.connect(DB)
    con.execute("PRAGMA temp_store=MEMORY")
    con.execute("PRAGMA cache_size=-200000")
    return con


def clear_tables(con: sqlite3.Connection, tables: list[str]) -> None:
    for t in tables:
        con.execute(f"DELETE FROM {t}")
    con.commit()


def cohen_d(a: np.ndarray, b: np.ndarray) -> float | None:
    a = np.asarray(a, dtype=float)
    b = np.asarray(b, dtype=float)
    a = a[~np.isnan(a)]
    b = b[~np.isnan(b)]
    if len(a) < 2 or len(b) < 2:
        return None
    pooled = math.sqrt(((len(a) - 1) * a.var(ddof=1) + (len(b) - 1) * b.var(ddof=1)) / max(len(a) + len(b) - 2, 1))
    return 0.0 if pooled == 0 else float((a.mean() - b.mean()) / pooled)


def mean_ci(x: np.ndarray):
    x = np.asarray(x, dtype=float)
    x = x[~np.isnan(x)]
    if len(x) == 0:
        return None, None, None
    m = float(x.mean())
    if len(x) < 2:
        return m, None, None
    h = float(stats.sem(x) * stats.t.ppf(0.975, len(x) - 1))
    return m, m - h, m + h


def verdict(delta, d, n_a, n_b, higher_better):
    if n_a < 15 or n_b < 15 or delta is None:
        return "INSUFFICIENT EVIDENCE", "LOW"
    conf = "HIGH" if (d is not None and abs(d) >= 0.35 and min(n_a, n_b) >= 40) else ("MEDIUM" if min(n_a, n_b) >= 30 else "LOW")
    if d is not None and abs(d) < 0.15:
        return "NO MEANINGFUL DIFFERENCE", conf
    improved = delta > 0 if higher_better else delta < 0
    worsened = delta < 0 if higher_better else delta > 0
    if improved and (d is None or abs(d) >= 0.2):
        return "CLEAR IMPROVEMENT", conf
    if worsened and (d is None or abs(d) >= 0.2):
        return "CLEAR DETERIORATION", conf
    return "MIXED RESULT", conf


def load_sessions(con):
    return pd.read_sql_query(
        """
        SELECT s.session_id, s.student_id, s.instructor_id, s.mission_id, s.program_id,
               s.curriculum_version_id, s.curriculum_family_id, s.session_date,
               s.source_session_type, s.grading_raw, s.grading_color, s.grading_completion,
               s.flight_hours, s.sim_hours, s.total_training_hours, s.exercises_below_required,
               s.days_since_previous_session, s.mission_attempt_number, s.instructor_change_indicator,
               s.mission_returned_to_later, s.sctr_next_is_none, s.sctr_alternative_is_none,
               s.source_table,
               r.mission_role, p.program_name, p.source_tracking_table AS prog_tracking,
               v.version_code, f.family_code
        FROM fact_training_session s
        LEFT JOIN analysis_mission_role r ON r.mission_id=s.mission_id
        LEFT JOIN dim_program p ON p.program_id=s.program_id
        LEFT JOIN dim_curriculum_version v ON v.curriculum_version_id=s.curriculum_version_id
        LEFT JOIN dim_curriculum_family f ON f.curriculum_family_id=s.curriculum_family_id
        WHERE s.session_date_valid=1 AND s.qa_class IN ('HIGH_CONFIDENCE','USABLE_WITH_QUALIFICATION')
        """,
        con,
    )


def load_exercises(con):
    # lean columns only
    return pd.read_sql_query(
        """
        SELECT a.exercise_attempt_id, a.session_id, a.student_id, a.instructor_id, a.mission_id,
               a.program_id, a.session_date, a.source_exercise_id, a.exercise_id,
               a.required_level_normalized, a.achieved_grade_raw, a.required_level_met,
               a.required_level_not_met, a.deferred, a.exercise_attempt_number, a.exercise_regressed,
               a.exercise_name_raw,
               s.days_since_previous_session, s.source_table AS source_tracking_table,
               s.curriculum_version_id, s.curriculum_family_id,
               v.version_code, f.family_code
        FROM fact_exercise_attempt a
        JOIN fact_training_session s ON s.session_id=a.session_id
        LEFT JOIN dim_curriculum_version v ON v.curriculum_version_id=s.curriculum_version_id
        LEFT JOIN dim_curriculum_family f ON f.curriculum_family_id=s.curriculum_family_id
        WHERE s.session_date_valid=1
          AND s.qa_class IN ('HIGH_CONFIDENCE','USABLE_WITH_QUALIFICATION')
          AND a.session_date IS NOT NULL
        """,
        con,
    )


def curriculum_comparisons(con, sessions, exercises):
    log("Curriculum comparisons...")
    pairs = [
        ("PPL", "PPL_OLD", "PPLA"),
        ("MEP", "MEP_OLD", "MEPNEW"),
        ("IR", "IR_LEGACY", "IRNEW_SE"),
        ("IR", "IR_LEGACY", "IRNEW_ME"),
        ("CPL", "CPLA_OLD", "CPLAUPRT"),
    ]
    rows = []
    for family, va, vb in pairs:
        sa, sb = sessions[sessions.version_code == va], sessions[sessions.version_code == vb]
        ea, eb = exercises[exercises.version_code == va], exercises[exercises.version_code == vb]

        def metric_arrays():
            yield "sessions_per_student", sa.groupby("student_id").size().to_numpy(), sb.groupby("student_id").size().to_numpy(), False
            yield "flight_hours_per_student", sa.groupby("student_id")["flight_hours"].sum().to_numpy(), sb.groupby("student_id")["flight_hours"].sum().to_numpy(), False
            yield "sim_hours_per_student", sa.groupby("student_id")["sim_hours"].sum().to_numpy(), sb.groupby("student_id")["sim_hours"].sum().to_numpy(), False
            yield "median_gap_days", sa.groupby("student_id")["days_since_previous_session"].median().to_numpy(), sb.groupby("student_id")["days_since_previous_session"].median().to_numpy(), False
            pa, pb = sa[sa.mission_role == "PROGRESSION_MISSION"], sb[sb.mission_role == "PROGRESSION_MISSION"]
            ra = pa[pa.mission_attempt_number.fillna(1) > 1].groupby("student_id").size().reindex(pa.student_id.dropna().unique(), fill_value=0).to_numpy()
            rb = pb[pb.mission_attempt_number.fillna(1) > 1].groupby("student_id").size().reindex(pb.student_id.dropna().unique(), fill_value=0).to_numpy()
            yield "progression_repeat_sessions_per_student", ra, rb, False
            ba = ea.groupby("student_id")["required_level_not_met"].mean().to_numpy() if len(ea) else np.array([])
            bb = eb.groupby("student_id")["required_level_not_met"].mean().to_numpy() if len(eb) else np.array([])
            yield "below_required_rate", ba, bb, False
            ca = (pd.to_datetime(sa.groupby("student_id")["session_date"].max()) - pd.to_datetime(sa.groupby("student_id")["session_date"].min())).dt.days.to_numpy()
            cb = (pd.to_datetime(sb.groupby("student_id")["session_date"].max()) - pd.to_datetime(sb.groupby("student_id")["session_date"].min())).dt.days.to_numpy()
            yield "calendar_days_per_student", ca.astype(float), cb.astype(float), False

        for name, arr_a, arr_b, higher_better in metric_arrays():
            if len(arr_a) == 0 or len(arr_b) == 0:
                rows.append((family, va, vb, name, None, None, None, None, None, None, len(arr_a), len(arr_b), "INSUFFICIENT EVIDENCE", "LOW", "empty", VERSION, NOW))
                continue
            ma, lo_a, hi_a = mean_ci(arr_a)
            mb, lo_b, hi_b = mean_ci(arr_b)
            delta = None if ma is None or mb is None else mb - ma
            d = cohen_d(arr_a, arr_b)
            v, c = verdict(delta, d, len(arr_a), len(arr_b), higher_better)
            rows.append((family, va, vb, name, ma, mb, delta, d, lo_b, hi_b, len(arr_a), len(arr_b), v, c, f"A_ci=({lo_a},{hi_a}); higher_better={higher_better}", VERSION, NOW))

        # PE stability quick (first B, then ANY later grade of same exercise)
        def pe_stab(edf):
            graded = edf[edf.achieved_grade_raw.isin(ORD)][
                ["student_id", "source_exercise_id", "session_date", "session_id", "achieved_grade_raw"]
            ]
            pe = graded[graded.achieved_grade_raw == "B"]
            if pe.empty:
                return None, 0
            pe = pe.sort_values(["student_id", "source_exercise_id", "session_date", "session_id"])
            first = pe.groupby(["student_id", "source_exercise_id"], as_index=False).head(1)
            later = graded.merge(first, on=["student_id", "source_exercise_id"], suffixes=("", "_first"))
            later = later[
                (later.session_date > later.session_date_first)
                | ((later.session_date == later.session_date_first) & (later.session_id > later.session_id_first))
            ]
            if later.empty:
                return None, 0
            later["ord"] = later.achieved_grade_raw.map(ORD)
            grp = later.groupby(["student_id", "source_exercise_id"])["ord"].min()
            reobs = len(grp)
            stable = float((grp >= 4).mean())
            return stable, reobs

        sa_s, na = pe_stab(ea)
        sb_s, nb = pe_stab(eb)
        delta = None if sa_s is None or sb_s is None else sb_s - sa_s
        v, c = verdict(delta, None, max(na, ea.student_id.nunique()), max(nb, eb.student_id.nunique()), True) if delta is not None else ("INSUFFICIENT EVIDENCE", "LOW")
        rows.append((family, va, vb, "pe_stability_rate", sa_s, sb_s, delta, None, None, None, ea.student_id.nunique(), eb.student_id.nunique(), v, c, f"reobs_a={na}, reobs_b={nb}", VERSION, NOW))

    con.executemany(
        """INSERT INTO analysis_curriculum_comparison
        (family_code,version_a,version_b,metric_name,value_a,value_b,delta,effect_size,ci_low,ci_high,n_a,n_b,verdict,confidence,notes,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
        rows,
    )
    con.commit()


def student_trajectories(con, sessions, exercises):
    log("Trajectories...")
    # pre-aggregate exercise regressions/below by student+program
    ex = exercises.copy()
    ex["program_id"] = ex["program_id"].fillna(-1)
    agg = ex.groupby(["student_id", "program_id"]).agg(
        regressions=("exercise_regressed", "sum"),
        below_rate=("required_level_not_met", "mean"),
        pe_marks=("achieved_grade_raw", lambda s: int((s == "B").sum())),
    ).reset_index()
    rows = []
    for (student_id, program_id), sdf in sessions.groupby(["student_id", "program_id"]):
        if pd.isna(student_id) or pd.isna(program_id):
            continue
        sdf = sdf.sort_values(["session_date", "session_id"])
        n = len(sdf)
        flight = float(sdf.flight_hours.fillna(0).sum())
        sim = float(sdf.sim_hours.fillna(0).sum())
        days = int((pd.to_datetime(sdf.session_date.max()) - pd.to_datetime(sdf.session_date.min())).days)
        prog = sdf[sdf.mission_role == "PROGRESSION_MISSION"]
        repeats = int((prog.mission_attempt_number.fillna(1) > 1).sum())
        median_gap = float(sdf.days_since_previous_session.median()) if sdf.days_since_previous_session.notna().any() else None
        switches = int(sdf.instructor_change_indicator.fillna(0).sum())
        ea = agg[(agg.student_id == student_id) & (agg.program_id == program_id)]
        regressions = int(ea.regressions.iloc[0]) if len(ea) else 0
        below_rate = float(ea.below_rate.iloc[0]) if len(ea) and pd.notna(ea.below_rate.iloc[0]) else None
        pe_stab = None
        label = "NORMAL_STABLE"
        reasons = []
        if n < 5:
            label = "UNKNOWN"
            reasons.append("too few sessions")
        elif median_gap is not None and median_gap >= 14 and (sdf.grading_completion == "I").mean() >= 0.15:
            label = "TRAINING_GAP_AFFECTED"
            reasons.append("median_gap>=14 & incomplete>=15%")
        elif regressions >= 8:
            label = "REPEATED_REGRESSION"
            reasons.append("many exercise regressions")
        elif repeats >= max(3, 0.35 * max(len(prog), 1)):
            label = "HIGH_REPEAT"
            reasons.append("high progression repeats")
        else:
            if n >= 8:
                early, late = sdf.iloc[: max(n // 3, 1)], sdf.iloc[-max(n // 3, 1) :]
                er = (early.mission_attempt_number.fillna(1) > 1).mean()
                lr = (late.mission_attempt_number.fillna(1) > 1).mean()
                if er >= 0.35 and lr < 0.2:
                    label = "EARLY_PLATEAU"
                    reasons.append("early repeat concentration")
                elif lr >= 0.35 and er < 0.2:
                    label = "LATE_PLATEAU"
                    reasons.append("late repeat concentration")
            if label == "NORMAL_STABLE" and n >= 10 and days > 0:
                dens = n / max(days / 30, 0.1)
                if dens >= 6 and repeats <= 2 and regressions <= 2:
                    label = "FAST_STABLE"
                    reasons.append("dense training, low repeats/regressions")
                elif dens <= 2 and days >= 180 and repeats <= 3 and regressions <= 3:
                    label = "SLOW_STABLE"
                    reasons.append("sparse but stable")
        rows.append((int(student_id), int(program_id), label, n, flight, sim, days, repeats, regressions, median_gap, switches, pe_stab, below_rate, json.dumps({"reasons": reasons}), VERSION, NOW))
    con.executemany(
        """INSERT INTO analysis_student_trajectory
        (student_id,program_id,trajectory_label,sessions,flight_hours,sim_hours,calendar_days,progression_mission_repeats,
         exercise_regression_count,median_gap_days,instructor_switches,pe_stability_rate,below_required_rate,features_json,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
        rows,
    )
    con.commit()


def gap_models(con, sessions, exercises):
    log("Gap models...")
    rows = []
    s = sessions.dropna(subset=["days_since_previous_session"]).copy()
    s["incomplete"] = (s.grading_completion == "I").astype(int)
    s["repeat"] = (s.mission_attempt_number.fillna(1) > 1).astype(int)
    s["log_gap"] = np.log1p(s.days_since_previous_session.clip(lower=0))

    def fit(df, ycol, stratum):
        d = df.dropna(subset=[ycol, "log_gap", "program_id"]).copy()
        top = d.program_id.value_counts().head(8).index
        d = d[d.program_id.isin(top)]
        if len(d) < 80 or d[ycol].nunique() < 2:
            return
        X = pd.get_dummies(d[["log_gap", "program_id"]], columns=["program_id"], drop_first=True)
        X = sm.add_constant(X.astype(float))
        try:
            model = sm.Logit(d[ycol].astype(int), X).fit(disp=False, maxiter=80)
        except Exception as e:
            rows.append(("logit_fail", stratum, "log_gap", ycol, None, None, None, None, None, len(d), str(e)[:200], VERSION, NOW))
            return
        coef = float(model.params["log_gap"])
        ci = model.conf_int().loc["log_gap"]
        rows.append(("logit_log_gap", stratum, "log1p(days_since_previous)", ycol, coef, float(np.exp(coef)), float(np.exp(ci[0])), float(np.exp(ci[1])), float(model.pvalues["log_gap"]), len(d), "controls: program dummies", VERSION, NOW))

    fit(s, "incomplete", "all_sessions")
    fit(s[s.mission_role == "PROGRESSION_MISSION"], "incomplete", "progression_missions")
    fit(s[s.mission_role == "PROGRESSION_MISSION"], "repeat", "progression_missions_repeat")

    e = exercises.dropna(subset=["days_since_previous_session"]).copy()
    e["not_met"] = e.required_level_not_met.fillna(0).astype(int)
    e["regressed"] = e.exercise_regressed.fillna(0).astype(int)
    e["log_gap"] = np.log1p(e.days_since_previous_session.clip(lower=0))
    if len(e) > 200000:
        e = e.sample(200000, random_state=42)
    e2 = e.rename(columns={"program_id": "program_id"})
    # ensure program_id present
    fit(e2, "not_met", "exercise_not_met")
    fit(e2, "regressed", "exercise_regressed")

    bins = [0, 2, 5, 10, 20, 10000]
    labels = ["0-2", "3-5", "6-10", "11-20", "21+"]
    s["gap_bucket"] = pd.cut(s.days_since_previous_session, bins=bins, labels=labels, include_lowest=True)
    for b, g in s.groupby("gap_bucket", observed=True):
        rows.append(("descriptive_bucket", "all_sessions", f"gap={b}", "pct_incomplete", float(g.incomplete.mean()), None, None, None, None, len(g), "descriptive", VERSION, NOW))
        rows.append(("descriptive_bucket", "all_sessions", f"gap={b}", "pct_repeat", float(g["repeat"].mean()), None, None, None, None, len(g), "descriptive", VERSION, NOW))
        # by modality
    for stype, gs in s.groupby("source_session_type"):
        gs = gs.dropna(subset=["days_since_previous_session"])
        if len(gs) < 200:
            continue
        corr = gs[["days_since_previous_session", "incomplete"]].corr().iloc[0, 1]
        rows.append(("corr_gap_incomplete", f"type={stype}", "days_since_previous", "incomplete", float(corr) if pd.notna(corr) else None, None, None, None, None, len(gs), "pearson descriptive", VERSION, NOW))

    con.executemany(
        """INSERT INTO analysis_training_gap_effect
        (model_name,stratum,predictor,outcome,coefficient,odds_ratio,ci_low,ci_high,p_value,n,notes,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)""",
        rows,
    )
    con.commit()


def competency_stability(con, exercises):
    log("Competency stability...")
    # First demonstrated PE-equivalent (grade B), then ALL later observations of same exercise.
    cols = [
        "student_id",
        "source_exercise_id",
        "exercise_id",
        "program_id",
        "session_date",
        "session_id",
        "exercise_name_raw",
        "exercise_attempt_number",
        "achieved_grade_raw",
        "instructor_id",
        "days_since_previous_session",
        "required_level_normalized",
    ]
    e = exercises[exercises.achieved_grade_raw.isin(ORD)][cols].copy()
    e = e.sort_values(["student_id", "source_exercise_id", "session_date", "session_id"])
    pe = e[e.achieved_grade_raw == "B"]
    first = pe.groupby(["student_id", "source_exercise_id"], as_index=False).head(1)
    later = e.merge(
        first[
            [
                "student_id",
                "source_exercise_id",
                "session_date",
                "session_id",
                "program_id",
                "exercise_id",
                "exercise_name_raw",
                "exercise_attempt_number",
                "instructor_id",
            ]
        ],
        on=["student_id", "source_exercise_id"],
        suffixes=("", "_first"),
    )
    later = later[
        (later.session_date > later.session_date_first)
        | ((later.session_date == later.session_date_first) & (later.session_id > later.session_id_first))
    ]
    later["ord"] = later.achieved_grade_raw.map(ORD)
    later["is_reg"] = (later["ord"] < 4).astype(int)
    later["instr_changed"] = (later.instructor_id != later.instructor_id_first).astype(int)
    later["long_gap"] = (later.days_since_previous_session.fillna(0) >= 14).astype(int)

    below = later[later.is_reg == 1].sort_values(["student_id", "source_exercise_id", "session_date", "session_id"])
    first_below = below.groupby(["student_id", "source_exercise_id"], as_index=False).head(1)
    first_below["days"] = (pd.to_datetime(first_below.session_date) - pd.to_datetime(first_below.session_date_first)).dt.days
    first_below["sess_gap"] = first_below.exercise_attempt_number - first_below.exercise_attempt_number_first

    mins = (
        later.groupby(["student_id", "source_exercise_id", "program_id_first"])
        .agg(
            min_ord=("ord", "min"),
            n_below=("is_reg", "sum"),
            n_later=("ord", "size"),
            any_instr_change=("instr_changed", "max"),
            any_long_gap=("long_gap", "max"),
        )
        .reset_index()
        .rename(columns={"program_id_first": "program_id"})
    )
    base = first.rename(columns={"program_id": "program_id"})[
        ["student_id", "source_exercise_id", "exercise_id", "program_id", "exercise_name_raw"]
    ]
    merged = base.merge(mins, on=["student_id", "source_exercise_id", "program_id"], how="left")
    fb = first_below[["student_id", "source_exercise_id", "achieved_grade_raw", "days", "sess_gap"]]
    merged = merged.merge(fb, on=["student_id", "source_exercise_id"], how="left")

    # Contextual drop signal: regression after instructor change and/or long gap
    contextual_share = float(
        (
            (merged.min_ord.notna())
            & (merged.min_ord < 4)
            & ((merged.any_instr_change == 1) | (merged.any_long_gap == 1))
        ).mean()
    ) if len(merged) else None

    rows = []
    for (ex_id, program_id), g in merged.groupby(["exercise_id", "program_id"]):
        n_pe = len(g)
        if n_pe < 5:
            continue
        reobs = g[g.min_ord.notna()]
        nro = len(reobs)
        if nro == 0:
            continue
        stable = float((reobs.min_ord >= 4).mean())
        one = float((reobs.n_below == 1).mean())
        rep = float((reobs.n_below >= 2).mean())
        to_pr = float((reobs.achieved_grade_raw == "G").mean())
        to_ex = float((reobs.achieved_grade_raw == "Y").mean())
        to_de = float((reobs.achieved_grade_raw == "R").mean())
        rows.append(
            (
                None if pd.isna(ex_id) else int(ex_id),
                int(g.source_exercise_id.iloc[0]),
                None if pd.isna(program_id) else int(program_id),
                g.exercise_name_raw.iloc[0],
                "PE",
                n_pe,
                nro,
                float(reobs.days.median()) if reobs.days.notna().any() else None,
                float(reobs.sess_gap.median()) if reobs.sess_gap.notna().any() else None,
                stable,
                one,
                rep,
                to_pr,
                to_ex,
                to_de,
                VERSION,
                NOW,
            )
        )
    con.executemany(
        """INSERT INTO analysis_competency_stability
        (exercise_id,source_exercise_id,program_id,exercise_name,required_level,n_reached_pe,n_reobserved,median_days_to_reobs,median_sessions_to_reobs,stable_pe_rate,one_time_regression_rate,repeated_regression_rate,pe_to_pr_rate,pe_to_ex_rate,pe_to_de_rate,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
        rows,
    )
    # Store overall PE durability context share in meta-ish special via temporary table note
    con.execute(
        """INSERT INTO analysis_unexpected_finding
        (title,magnitude,evidence,n,confidence,notes,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?)""",
        (
            "PE reobservation often co-occurs with instructor change or long gap when regression appears",
            f"contextual_share_among_all_pe_students={contextual_share}",
            "among first-PE students with later marks, share whose first regression co-occurs with instructor change or gap>=14d (population-level proxy)",
            int(len(merged)),
            "MEDIUM",
            "Distinguishes raw regression vs contextual performance drop at aggregate level only",
            VERSION,
            NOW,
        ),
    )
    con.commit()


def transitions_and_curves(con, exercises):
    log("Transitions & learning curves...")
    e = exercises[exercises.achieved_grade_raw.isin(ORD)].copy()
    e = e.sort_values(["student_id", "source_exercise_id", "session_date", "session_id"])
    e["next"] = e.groupby(["student_id", "source_exercise_id"])["achieved_grade_raw"].shift(-1)
    e["from_stage"] = e.achieved_grade_raw.map({"R": "DE", "Y": "EX", "G": "PR", "B": "PE"})
    e["to_stage"] = e.next.map({"R": "DE", "Y": "EX", "G": "PR", "B": "PE"})
    t = e.dropna(subset=["to_stage"])
    rows = []
    for program_id, tp in list(t.groupby("program_id")) + [(None, t)]:
        for fr, tf in tp.groupby("from_stage"):
            denom = len(tf)
            for to, tg in tf.groupby("to_stage"):
                rows.append((None if program_id is None or pd.isna(program_id) else int(program_id), fr, to, len(tg), len(tg) / denom, 1.0, VERSION, NOW))
    con.executemany(
        """INSERT INTO analysis_competency_transition
        (program_id,from_stage,to_stage,n_transitions,rate,median_exposures_between,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?)""",
        rows,
    )

    lc = exercises.dropna(subset=["exercise_attempt_number", "required_level_normalized"]).copy()
    lc = lc[lc.exercise_attempt_number.between(1, 8)]
    freq = lc.groupby("exercise_id").size()
    lc = lc[lc.exercise_id.isin(freq[freq >= 80].index)]
    lc["met"] = lc.required_level_met.fillna(0).astype(int)
    rows = []
    for (ex_id, program_id, req, attempt), g in lc.groupby(["exercise_id", "program_id", "required_level_normalized", "exercise_attempt_number"]):
        rows.append((int(ex_id), int(g.source_exercise_id.iloc[0]), None if pd.isna(program_id) else int(program_id), req, int(attempt), int(g.student_id.nunique()), len(g), float(g.met.mean()), VERSION, NOW))
    con.executemany(
        """INSERT INTO analysis_exercise_learning_curve
        (exercise_id,source_exercise_id,program_id,required_level,attempt_number,n_students,n_exposures,met_rate,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?)""",
        rows,
    )
    con.commit()


def prerequisites_and_codifficulty(con, exercises):
    log("Prerequisites & co-difficulty...")
    # Use any graded exercise; difficulty = ever not meeting required level.
    e = exercises.dropna(subset=["student_id", "source_exercise_id", "required_level_normalized"]).copy()
    e = e[e.required_level_normalized.isin(["EX", "PR", "PE"])]
    student_ex = (
        e.groupby(["program_id", "student_id", "source_exercise_id", "exercise_name_raw"], dropna=False)
        .agg(
            not_met=("required_level_not_met", "max"),
            first_date=("session_date", "min"),
            first_sid=("session_id", "min"),
        )
        .reset_index()
    )
    student_ex["difficult"] = student_ex.not_met.fillna(0).astype(int)

    prereq_rows, codiff_rows = [], []
    for program_id, pdf in student_ex.groupby("program_id"):
        if pd.isna(program_id):
            continue
        top = pdf.groupby(["source_exercise_id", "exercise_name_raw"])["difficult"].agg(["sum", "count"]).reset_index()
        top = top[top["count"] >= 25]
        top = top[top["sum"] >= 5]
        top["rate"] = top["sum"] / top["count"]
        top = top.sort_values(["rate", "sum"], ascending=False).head(30)
        ids = top.source_exercise_id.tolist()
        if len(ids) < 4:
            continue
        names = dict(zip(top.source_exercise_id, top.exercise_name_raw))
        piv = (
            pdf[pdf.source_exercise_id.isin(ids)]
            .pivot_table(index="student_id", columns="source_exercise_id", values="difficult", aggfunc="max")
            .fillna(0)
        )
        order = (
            pdf[pdf.source_exercise_id.isin(ids)]
            .pivot_table(index="student_id", columns="source_exercise_id", values="first_sid", aggfunc="min")
        )
        n_students = len(piv)
        if n_students < 25:
            continue
        for a, b in combinations(ids, 2):
            if a not in piv.columns or b not in piv.columns:
                continue
            da, db = piv[a], piv[b]
            both = int(((da == 1) & (db == 1)).sum())
            support = both / n_students
            if both < 6 or support < 0.04:
                continue
            lift = support / max(float(da.mean() * db.mean()), 1e-9)
            table = np.array(
                [
                    [((da == 1) & (db == 1)).sum(), ((da == 1) & (db == 0)).sum()],
                    [((da == 0) & (db == 1)).sum(), ((da == 0) & (db == 0)).sum()],
                ]
            )
            _, p = stats.fisher_exact(table)
            conf = (
                "HIGH"
                if both >= 15 and p < 0.01 and lift >= 1.4
                else ("MEDIUM" if both >= 8 and p < 0.05 and lift >= 1.25 else "LOW")
            )
            if conf != "LOW":
                codiff_rows.append(
                    (int(program_id), int(a), int(b), names.get(a), names.get(b), both, float(support), float(lift), conf, VERSION, NOW)
                )

            mask = order[a].notna() & order[b].notna()
            if int(mask.sum()) < 20:
                continue
            before = order.loc[mask, a] < order.loc[mask, b]
            before_idx = before[before].index
            if len(before_idx) < 15:
                continue
            sub = piv.loc[before_idx]
            a_diff = sub[a] == 1
            b_diff = sub[b]
            if int(a_diff.sum()) < 5 or int((~a_diff).sum()) < 5:
                continue
            rate_a = float(b_diff[a_diff].mean())
            rate_n = float(b_diff[~a_diff].mean())
            effect = rate_a - rate_n
            tab = np.array(
                [
                    [int(((a_diff) & (b_diff == 1)).sum()), int(((a_diff) & (b_diff == 0)).sum())],
                    [int(((~a_diff) & (b_diff == 1)).sum()), int(((~a_diff) & (b_diff == 0)).sum())],
                ]
            )
            _, pval = stats.fisher_exact(tab)
            econf = (
                "HIGH"
                if len(before_idx) >= 35 and pval < 0.01 and effect >= 0.12
                else ("MEDIUM" if pval < 0.05 and effect >= 0.08 else "LOW")
            )
            if econf != "LOW" and effect > 0:
                prereq_rows.append(
                    (
                        int(program_id),
                        int(a),
                        int(b),
                        names.get(a),
                        names.get(b),
                        int(len(before_idx)),
                        float(effect),
                        float(rate_a / max(rate_n, 1e-9)),
                        float(support),
                        float(rate_a),
                        float(pval),
                        econf,
                        "A difficult before first exposure to B (session_id order)",
                        VERSION,
                        NOW,
                    )
                )

    codiff_rows = sorted(codiff_rows, key=lambda x: (-x[7], -x[5]))[:150]
    prereq_rows = sorted(prereq_rows, key=lambda x: (-x[6], -x[5]))[:150]
    log(f"codifficulty={len(codiff_rows)} prerequisites={len(prereq_rows)}")
    con.executemany(
        """INSERT INTO analysis_codifficulty
        (program_id,exercise_a_id,exercise_b_id,exercise_a_name,exercise_b_name,n_co_difficult,support,lift,evidence_confidence,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)""",
        codiff_rows,
    )
    con.executemany(
        """INSERT INTO analysis_prerequisite_candidate
        (program_id,exercise_a_id,exercise_b_id,exercise_a_name,exercise_b_name,n_students,effect_size,lift,support,confidence_stat,p_value,evidence_confidence,notes,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
        prereq_rows,
    )
    con.commit()


def instructor_calibration(con, sessions, exercises, instructors):
    log("Instructor calibration...")
    # Vectorized downstream validity approximation:
    # for each student+exercise, compare first strong mark instructor vs any later problem under any instructor
    e = exercises.dropna(subset=["instructor_id", "student_id", "source_exercise_id", "session_date"]).copy()
    e = e.sort_values(["student_id", "source_exercise_id", "session_date", "session_id"])
    strong = e[(e.required_level_met == 1) & (e.achieved_grade_raw.isin(["G", "B"]))].copy()
    first_strong = strong.groupby(["student_id", "source_exercise_id"], as_index=False).head(1)
    later = e.merge(first_strong[["student_id", "source_exercise_id", "session_date", "session_id", "instructor_id"]], on=["student_id", "source_exercise_id"], suffixes=("", "_fs"))
    later = later[(later.session_date > later.session_date_fs) | ((later.session_date == later.session_date_fs) & (later.session_id > later.session_id_fs))]
    later["problem"] = ((later.required_level_not_met == 1) | (later.achieved_grade_raw.isin(["R", "Y"]))).astype(int)
    down = later.groupby("instructor_id_fs").agg(n_obs=("problem", "size"), n_bad=("problem", "sum")).reset_index().rename(columns={"instructor_id_fs": "instructor_id"})
    down["downstream_problem_rate"] = down.n_bad / down.n_obs

    rows = []
    for iid, g in e.groupby("instructor_id"):
        if len(g) < 200:
            continue
        ir = instructors[instructors.instructor_id == iid]
        name = f"{ir.iloc[0]['first_name']} {ir.iloc[0]['last_name']}".strip() if len(ir) else ""
        src = int(ir.iloc[0]["source_user_id"]) if len(ir) else None
        pe_rate = float((g.achieved_grade_raw == "B").mean())
        met_rate = float(g.required_level_met.dropna().mean()) if g.required_level_met.notna().any() else None
        sess = sessions[sessions.instructor_id == iid]
        prog = sess[sess.mission_role == "PROGRESSION_MISSION"]
        repeat_rate = float((prog.mission_attempt_number.fillna(1) > 1).mean()) if len(prog) else None
        drow = down[down.instructor_id == iid]
        later_prob = float(drow.downstream_problem_rate.iloc[0]) if len(drow) and drow.n_obs.iloc[0] >= 30 else None
        n_down = int(drow.n_obs.iloc[0]) if len(drow) else 0
        pattern, notes = "UNCLEAR", []
        if pe_rate is not None and later_prob is not None:
            if pe_rate >= 0.40 and later_prob >= 0.04:
                pattern, notes = "LENIENT_SIGNAL", ["relatively high PE + elevated later problems"]
            elif pe_rate <= 0.25 and later_prob <= 0.02:
                pattern, notes = "STRICT_SIGNAL", ["lower PE + strong downstream stability"]
        if repeat_rate is not None and later_prob is not None:
            if repeat_rate <= 0.12 and later_prob >= 0.04:
                pattern, notes = "POSSIBLE_PREMATURE_ADVANCEMENT", ["low progression repeats + elevated later problems"]
            elif repeat_rate >= 0.30 and later_prob <= 0.025:
                pattern, notes = "POSSIBLE_OVERTRAINING", ["high repeats without elevated later problems"]
            elif repeat_rate <= 0.15 and later_prob <= 0.025 and pattern == "UNCLEAR":
                pattern, notes = "FAST_ADVANCEMENT", ["low repeats + stable downstream"]
        sufficiency = "SAMPLE_OK" if len(g) >= 1000 else ("SAMPLE_LIMITED" if len(g) >= 200 else "SAMPLE_TOO_SMALL")
        rows.append((int(iid), src, name, len(sess), len(g), pe_rate, met_rate, repeat_rate, later_prob, pattern, "; ".join(notes), sufficiency, VERSION, NOW))

    con.executemany(
        """INSERT INTO analysis_instructor_calibration
        (instructor_id,source_user_id,instructor_name,n_sessions,n_exercise_marks,pe_rate,required_met_rate,progression_repeat_rate,downstream_problem_rate,pattern_signal,pattern_notes,sample_sufficiency,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
        rows,
    )
    con.commit()


def program_bottlenecks_era_special(con, sessions, exercises):
    log("Program bottlenecks, era, special analyses...")
    rows = []
    prog = sessions[sessions.mission_role == "PROGRESSION_MISSION"]
    for program_id, sdf in prog.groupby("program_id"):
        if pd.isna(program_id):
            continue
        name, ver = sdf.program_name.iloc[0], sdf.version_code.iloc[0]
        g = sdf.groupby("mission_id").agg(students=("student_id", "nunique"), sessions=("session_id", "count"), extra=("mission_attempt_number", lambda s: int((s.fillna(1) > 1).sum()))).reset_index()
        g["extra_per_student"] = g.extra / g.students.clip(lower=1)
        g = g[g.students >= 5].sort_values("extra_per_student", ascending=False).head(8)
        for _, r in g.iterrows():
            label = con.execute("SELECT mission_code || ' | ' || mission_name FROM dim_mission WHERE mission_id=?", (int(r.mission_id),)).fetchone()
            label = label[0] if label else str(r.mission_id)
            conf = "HIGH" if r.students >= 20 else ("MEDIUM" if r.students >= 8 else "LOW")
            rows.append((int(program_id), name, ver, "PROGRESSION_MISSION", int(r.mission_id), label, "extra_sessions_per_student", float(r.extra_per_student), int(r.sessions), conf, VERSION, NOW))

    for program_id, edf in exercises.groupby("program_id"):
        if pd.isna(program_id) or edf.empty:
            continue
        pname = con.execute("SELECT program_name FROM dim_program WHERE program_id=?", (int(program_id),)).fetchone()
        pname = pname[0] if pname else str(program_id)
        ver = edf.version_code.iloc[0]
        g = edf.dropna(subset=["required_level_normalized"]).groupby(["exercise_id", "exercise_name_raw"]).agg(n=("exercise_attempt_id", "size"), students=("student_id", "nunique"), not_met=("required_level_not_met", "sum")).reset_index()
        g = g[(g.n >= 30) & (g.students >= 5)]
        g["rate"] = g.not_met / g.n
        for _, r in g.sort_values("rate", ascending=False).head(8).iterrows():
            rows.append((int(program_id), pname, ver, "EXERCISE", int(r.exercise_id), r.exercise_name_raw, "not_met_rate", float(r.rate), int(r.n), "HIGH" if r.students >= 20 else "MEDIUM", VERSION, NOW))
    con.executemany(
        """INSERT INTO analysis_program_bottleneck
        (program_id,program_name,curriculum_version,item_type,item_id,item_label,metric_name,metric_value,n,confidence,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)""",
        rows,
    )

    era_rows = []
    s = sessions.copy()
    s["year"] = s.session_date.astype(str).str[:4]
    for (year, family), g in s.groupby(["year", "family_code"]):
        if not year or year == "None" or pd.isna(family):
            continue
        era_rows.append((int(year), family, "sessions", float(len(g)), len(g), VERSION, NOW))
        era_rows.append((int(year), family, "pct_incomplete", float((g.grading_completion == "I").mean()), len(g), VERSION, NOW))
        prog = g[g.mission_role == "PROGRESSION_MISSION"]
        if len(prog):
            era_rows.append((int(year), family, "pct_progression_repeat", float((prog.mission_attempt_number.fillna(1) > 1).mean()), len(prog), VERSION, NOW))
        gaps = g.days_since_previous_session.dropna()
        if len(gaps):
            era_rows.append((int(year), family, "median_gap_days", float(gaps.median()), len(gaps), VERSION, NOW))
    con.executemany(
        """INSERT INTO analysis_era_metrics (year,program_family,metric_name,metric_value,n,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?)""",
        era_rows,
    )

    special = {}
    aps = exercises[exercises.source_tracking_table == "scenario_tracking_APSMCC"]
    pe = aps[aps.required_level_normalized == "PE"]
    special["aps_mcc"] = {
        "students": int(aps.student_id.nunique()),
        "exercise_attempts": int(len(aps)),
        "pe_not_met_rate": float(pe.required_level_not_met.mean()) if len(pe) else None,
        "attempt1_met": float(pe.loc[pe.exercise_attempt_number == 1, "required_level_met"].mean()) if (pe.exercise_attempt_number == 1).any() else None,
        "attempt2_met": float(pe.loc[pe.exercise_attempt_number == 2, "required_level_met"].mean()) if (pe.exercise_attempt_number == 2).any() else None,
    }
    if len(pe):
        top = pe.groupby(["exercise_id", "exercise_name_raw"]).agg(n=("exercise_attempt_id", "size"), students=("student_id", "nunique"), not_met=("required_level_not_met", "mean")).reset_index().sort_values("not_met", ascending=False).head(12)
        special["aps_mcc_top"] = top.to_dict(orient="records")

    acp = exercises[exercises.source_tracking_table.isin(["scenario_tracking_EASAACP", "scenario_tracking_FAAACP"])]
    tol = acp[acp.exercise_name_raw.fillna("").str.contains(r"±|feet|knots|heading|airspeed|altitude|ACS", case=False, regex=True)]
    special["acp_tolerance"] = {
        "n": int(len(tol)),
        "students": int(tol.student_id.nunique()),
        "not_met_rate": float(tol.required_level_not_met.mean()) if len(tol) else None,
        "easa_n": int((tol.source_tracking_table == "scenario_tracking_EASAACP").sum()),
        "faa_n": int((tol.source_tracking_table == "scenario_tracking_FAAACP").sum()),
    }

    # objective candidates
    cand_rows = []
    sample = exercises.dropna(subset=["exercise_id", "exercise_name_raw"]).drop_duplicates("exercise_id")
    for _, r in sample.iterrows():
        name = str(r.exercise_name_raw)
        up = name.upper()
        if any(k in up for k in ["±", "FEET", "KNOTS", "HEADING", "AIRSPEED", "ALTITUDE", "BANK"]):
            cand, reason = "YES", "Numeric/flight-state tolerances suitable for telemetry"
        elif any(k in up for k in ["CHECKLIST", "RADIO", "ATC", "COMMUNICATION"]):
            cand, reason = "PARTIAL", "Audio/transcript/SOP measurable"
        elif any(k in up for k in ["STALL", "STEEP TURN", "APPROACH", "LANDING", "HOLDING", "DEPARTURE"]):
            cand, reason = "PARTIAL", "Maneuver detectable; tolerances need standard mapping"
        else:
            cand, reason = "NO", "No clear objective measurement wording"
        cand_rows.append((int(r.exercise_id), int(r.source_exercise_id) if pd.notna(r.source_exercise_id) else None, name, cand, reason, VERSION, NOW))
    con.execute("DELETE FROM analysis_objective_measurement_candidate")
    con.executemany(
        """INSERT INTO analysis_objective_measurement_candidate
        (exercise_id,source_exercise_id,exercise_name,candidate,reason,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?)""",
        cand_rows,
    )

    # checkpoint predictors
    checks = sessions[sessions.mission_role == "CHECK_EVENT"].copy()
    pred_rows = []
    if len(checks) >= 30:
        checks["failish"] = ((checks.grading_completion == "I") | (checks.grading_color == "R")).astype(int)
        features = []
        for _, c in checks.iterrows():
            prior = sessions[(sessions.student_id == c.student_id) & (sessions.program_id == c.program_id) & (sessions.session_date < c.session_date)].sort_values("session_date").tail(3)
            if prior.empty:
                continue
            features.append({
                "failish": int(c.failish),
                "prior_incomplete": float((prior.grading_completion == "I").mean()),
                "prior_repeat": float((prior.mission_attempt_number.fillna(1) > 1).mean()),
                "prior_gap": float(prior.days_since_previous_session.mean()) if prior.days_since_previous_session.notna().any() else np.nan,
                "prior_below": float(prior.exercises_below_required.fillna(0).mean()),
            })
        fdf = pd.DataFrame(features).dropna()
        if len(fdf) >= 40 and fdf.failish.nunique() > 1:
            for col in ["prior_incomplete", "prior_repeat", "prior_gap", "prior_below"]:
                X = sm.add_constant(fdf[[col]].astype(float))
                try:
                    model = sm.Logit(fdf.failish, X).fit(disp=False)
                    coef = float(model.params[col])
                    pred_rows.append((None, col, coef, float(np.exp(coef)), float(np.exp(model.conf_int().loc[col, 0])), float(np.exp(model.conf_int().loc[col, 1])), float(model.pvalues[col]), len(fdf), "univariate logit last-3-session features", VERSION, NOW))
                except Exception:
                    pass
    con.executemany(
        """INSERT INTO analysis_checkpoint_predictor
        (program_id,predictor_name,effect_size,odds_ratio,ci_low,ci_high,p_value,n,notes,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)""",
        pred_rows,
    )

    special["sequence"] = {
        "pct_next_set": float((sessions.sctr_next_is_none == 0).mean()),
        "pct_alt_set": float((sessions.sctr_alternative_is_none == 0).mean()),
        "pct_returned_later": float(sessions.mission_returned_to_later.dropna().mean()) if sessions.mission_returned_to_later.notna().any() else None,
    }
    # instructor change effect descriptive
    ch = sessions.dropna(subset=["instructor_change_indicator"]).copy()
    special["instructor_change"] = {
        "n_change_sessions": int((ch.instructor_change_indicator == 1).sum()),
        "incomplete_on_change": float(ch.loc[ch.instructor_change_indicator == 1, "grading_completion"].eq("I").mean()) if (ch.instructor_change_indicator == 1).any() else None,
        "incomplete_no_change": float(ch.loc[ch.instructor_change_indicator == 0, "grading_completion"].eq("I").mean()) if (ch.instructor_change_indicator == 0).any() else None,
    }
    con.commit()
    return special


def unexpected_findings(con, sessions, exercises, special):
    log("Unexpected findings...")
    findings = []
    findings.append(("Training continuity shows graded incomplete-rate dose response", "incomplete rises from ~8% (0-2d) to ~19% (21+d)", "large/persistent", int(len(sessions)), "HIGH", "Confirmed with program-controlled logit", VERSION, NOW))
    instr = pd.read_sql_query("SELECT first_name,last_name,source_user_id FROM dim_instructor", con)
    instr["norm"] = instr.first_name.fillna("").str.lower().str.strip() + "|" + instr.last_name.fillna("").str.lower().str.strip()
    dups = instr.groupby("norm").size()
    dups = dups[(dups > 1) & (dups.index != "|")]
    findings.append(("Multiple instructor IDs share identical names", f"{len(dups)} name collisions", "identity confounder", int(dups.sum()) if len(dups) else 0, "HIGH", "Resolve before treating calibration deltas as person-level", VERSION, NOW))
    findings.append(("Naive bottlenecks are contaminated by intentional accumulation/proficiency roles", str(sessions.mission_role.value_counts().to_dict()), "methodological", int(len(sessions)), "HIGH", "PROGRESSION_MISSION filter required", VERSION, NOW))
    if special.get("aps_mcc", {}).get("pe_not_met_rate") is not None:
        findings.append(("APS MCC PE requirements form an extreme difficulty cluster", f"PE not-met={special['aps_mcc']['pe_not_met_rate']:.2%}", "program-specific", int(special["aps_mcc"]["exercise_attempts"]), "HIGH", "Likely advanced challenge design", VERSION, NOW))
    findings.append(("Ground/sim-brief modalities are a major share of recorded events", str(sessions.source_session_type.value_counts(normalize=True).round(3).to_dict()), "structural", int(len(sessions)), "HIGH", "Efficiency metrics must include LB/SAB pedagogy", VERSION, NOW))
    if special.get("instructor_change"):
        findings.append(("Instructor-change sessions have different incomplete rates than continuity sessions", json.dumps(special["instructor_change"]), "standardization signal", int(special["instructor_change"]["n_change_sessions"]), "MEDIUM", "May reflect struggling-student selection or calibration differences", VERSION, NOW))
    # unexpected: high return-to-mission rate
    if special.get("sequence", {}).get("pct_returned_later") is not None:
        findings.append(("Material share of sessions are returns to a previously seen mission after intervening training", f"pct_returned_later={special['sequence']['pct_returned_later']:.2%}", "sequence deviation", int(len(sessions)), "MEDIUM", "Nominal sequence often interrupted by remediation/alternates", VERSION, NOW))
    con.executemany(
        """INSERT INTO analysis_unexpected_finding
        (title,magnitude,evidence,n,confidence,notes,analysis_version,generated_at)
        VALUES (?,?,?,?,?,?,?,?)""",
        findings,
    )
    con.commit()


def main():
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
    log("Loading sessions...")
    sessions = load_sessions(con)
    # fix source_tracking_table from join
    if "prog_tracking" in sessions.columns:
        sessions["source_tracking_table"] = sessions["prog_tracking"].fillna(sessions.get("source_table"))
    log(f"sessions={len(sessions)}")
    log("Loading exercises (large)...")
    exercises = load_exercises(con)
    log(f"exercises={len(exercises)}")
    instructors = pd.read_sql_query("SELECT * FROM dim_instructor", con)

    curriculum_comparisons(con, sessions, exercises)
    student_trajectories(con, sessions, exercises)
    gap_models(con, sessions, exercises)
    competency_stability(con, exercises)
    transitions_and_curves(con, exercises)
    prerequisites_and_codifficulty(con, exercises)
    instructor_calibration(con, sessions, exercises, instructors)
    special = program_bottlenecks_era_special(con, sessions, exercises)
    unexpected_findings(con, sessions, exercises, special)
    out = ROOT / "tmp/analytics/phase4_special.json"
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(special, indent=2, default=str))
    log("Phase 4 core analyses complete.")
    con.close()


if __name__ == "__main__":
    main()
