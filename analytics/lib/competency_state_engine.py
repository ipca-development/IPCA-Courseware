"""Deterministic competency state engine (conceptual / Phase 6).

Combines evidence using explicit rules. AI may supply candidate observations;
this engine never treats AI as final authority.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any


INDEPENDENCE = {"ASSISTED", "PROMPTED", "INDEPENDENT", "NOT_OBSERVED"}
CONSISTENCY = {"NOT_ENOUGH_EVIDENCE", "VARIABLE", "DEVELOPING", "CONSISTENT"}
QUALITY = {"WITHIN_STANDARD", "MINOR_DEVIATION", "OUTSIDE_STANDARD", "UNKNOWN"}
TREND = {"IMPROVING", "STABLE", "REGRESSING", "PLATEAU", "UNKNOWN"}


@dataclass
class AttemptEvidence:
    attempt_id: str
    expected_level: str | None
    independence: str = "NOT_OBSERVED"
    within_standard: bool | None = None  # from objective metrics
    intervention_types: list[str] = field(default_factory=list)
    context_keys: frozenset[str] = field(default_factory=frozenset)
    session_id: str | None = None
    session_date: str | None = None


@dataclass
class CompetencyView:
    expected_level: str | None
    observed_independence: str
    observed_quality: str
    observed_consistency: str
    context_summary: str
    trend: str
    attempt_repeatability: str
    longitudinal_stability: str
    transfer_evidence: str
    confidence: str
    explanation: str
    evidence_refs: list[str]


def _mode_non_missing(values: list[str], missing: set[str]) -> str:
    filtered = [v for v in values if v not in missing]
    if not filtered:
        return next(iter(missing))
    return max(set(filtered), key=filtered.count)


def derive_within_session_learning(attempts: list[AttemptEvidence]) -> str:
    if len(attempts) < 2:
        return "insufficient_attempts"
    standards = [a.within_standard for a in attempts]
    if any(s is None for s in standards):
        # fall back to independence progression if objective missing
        order = {"ASSISTED": 0, "PROMPTED": 1, "INDEPENDENT": 2, "NOT_OBSERVED": -1}
        seq = [order.get(a.independence, -1) for a in attempts if a.independence != "NOT_OBSERVED"]
        if len(seq) < 2:
            return "insufficient_attempts"
        if seq[-1] > seq[0]:
            return "improved_within_session"
        if seq[-1] < seq[0]:
            return "regressed_within_session"
        return "stable_within_session"
    if standards[0] is False and standards[-1] is True:
        return "improved_within_session"
    if standards[0] is True and standards[-1] is False:
        return "regressed_within_session"
    if all(standards):
        return "stable_within_session"
    return "variable_within_session"


def derive_attempt_repeatability(attempts: list[AttemptEvidence]) -> str:
    """Require ≥3 attempts before CONSISTENT."""
    if len(attempts) < 3:
        return "NOT_ENOUGH_EVIDENCE"
    known = [a for a in attempts if a.within_standard is not None]
    if len(known) < 3:
        return "NOT_ENOUGH_EVIDENCE"
    oks = [a.within_standard for a in known]
    if all(oks):
        return "CONSISTENT"
    if any(oks) and not all(oks):
        # improving last two?
        if oks[-2:] == [True, True] and oks[0] is False:
            return "DEVELOPING"
        return "VARIABLE"
    return "VARIABLE"


def derive_longitudinal_stability(session_groups: list[list[AttemptEvidence]]) -> str:
    """Successful within-standard across ≥2 sessions on different days."""
    good_sessions = 0
    for attempts in session_groups:
        if not attempts:
            continue
        if derive_attempt_repeatability(attempts) in {"CONSISTENT", "DEVELOPING"} or (
            len(attempts) >= 1 and all(a.within_standard for a in attempts if a.within_standard is not None)
            and any(a.within_standard for a in attempts if a.within_standard is not None)
        ):
            # session considered successful if majority within standard
            known = [a.within_standard for a in attempts if a.within_standard is not None]
            if known and sum(1 for x in known if x) >= (len(known) + 1) // 2:
                good_sessions += 1
    if good_sessions >= 2:
        return "STABLE"
    if good_sessions == 1:
        return "EMERGING"
    return "NOT_ENOUGH_EVIDENCE"


def derive_transfer(session_groups: list[list[AttemptEvidence]]) -> str:
    """Transfer inferred only across materially different contexts with success."""
    successful_contexts: list[frozenset[str]] = []
    for attempts in session_groups:
        known = [a for a in attempts if a.within_standard is not None]
        if not known:
            continue
        if sum(1 for a in known if a.within_standard) >= (len(known) + 1) // 2:
            ctx = frozenset().union(*(a.context_keys for a in attempts))
            successful_contexts.append(ctx)
    if len(successful_contexts) < 2:
        return "NOT_ENOUGH_EVIDENCE"
    # material difference: symmetric difference size ≥ 1 meaningful key
    base = successful_contexts[0]
    for other in successful_contexts[1:]:
        if len(base.symmetric_difference(other)) >= 1:
            return "TRANSFER_EVIDENCE_PRESENT"
    return "SAME_CONTEXT_ONLY"


def evidence_confidence(attempts: list[AttemptEvidence], has_instructor_confirm: bool, source_mix: set[str]) -> str:
    objective = "OBJECTIVE" in source_mix or "COCKPIT_RECORDER_EVENT" in source_mix or "GARMIN_G3X" in source_mix
    if objective and has_instructor_confirm and len(attempts) >= 3:
        return "HIGH"
    if objective:
        return "MEDIUM"
    if "HISTORICAL_NARRATIVE" in source_mix or "AI_AUDIO_INTERPRETATION" in source_mix:
        return "LOW"
    return "LOW"


def evaluate_competency(
    attempts: list[AttemptEvidence],
    *,
    source_mix: set[str] | None = None,
    has_instructor_confirm: bool = False,
) -> CompetencyView:
    source_mix = source_mix or set()
    if not attempts:
        return CompetencyView(
            expected_level=None,
            observed_independence="NOT_OBSERVED",
            observed_quality="UNKNOWN",
            observed_consistency="NOT_ENOUGH_EVIDENCE",
            context_summary="",
            trend="UNKNOWN",
            attempt_repeatability="NOT_ENOUGH_EVIDENCE",
            longitudinal_stability="NOT_ENOUGH_EVIDENCE",
            transfer_evidence="NOT_ENOUGH_EVIDENCE",
            confidence="LOW",
            explanation="No attempts available.",
            evidence_refs=[],
        )

    expected = attempts[-1].expected_level
    indep = _mode_non_missing([a.independence for a in attempts], {"NOT_OBSERVED"})
    if all(a.independence == "NOT_OBSERVED" for a in attempts):
        indep = "NOT_OBSERVED"

    known_q = [a.within_standard for a in attempts if a.within_standard is not None]
    if not known_q:
        quality = "UNKNOWN"
    elif all(known_q):
        quality = "WITHIN_STANDARD"
    elif any(known_q):
        quality = "MINOR_DEVIATION"
    else:
        quality = "OUTSIDE_STANDARD"

    by_session: dict[str, list[AttemptEvidence]] = {}
    for a in attempts:
        by_session.setdefault(a.session_id or a.attempt_id, []).append(a)
    session_groups = list(by_session.values())

    repeatability = derive_attempt_repeatability(
        session_groups[-1] if session_groups else attempts
    )
    longitudinal = derive_longitudinal_stability(session_groups)
    transfer = derive_transfer(session_groups)
    learning = derive_within_session_learning(session_groups[-1] if session_groups else attempts)

    if learning == "improved_within_session" or longitudinal == "STABLE":
        trend = "IMPROVING" if learning == "improved_within_session" else "STABLE"
    elif learning == "regressed_within_session":
        trend = "REGRESSING"
    else:
        trend = "UNKNOWN"

    consistency = repeatability
    if longitudinal == "STABLE" and consistency == "DEVELOPING":
        consistency = "CONSISTENT"

    ctx_keys = sorted(set().union(*(a.context_keys for a in attempts)))
    conf = evidence_confidence(attempts, has_instructor_confirm, source_mix)

    explanation = (
        f"Expected={expected}; independence={indep}; quality={quality}; "
        f"attempt_repeatability={repeatability}; longitudinal={longitudinal}; "
        f"transfer={transfer}; within_session={learning}."
    )
    return CompetencyView(
        expected_level=expected,
        observed_independence=indep,
        observed_quality=quality,
        observed_consistency=consistency,
        context_summary=",".join(ctx_keys),
        trend=trend,
        attempt_repeatability=repeatability,
        longitudinal_stability=longitudinal,
        transfer_evidence=transfer,
        confidence=conf,
        explanation=explanation,
        evidence_refs=[a.attempt_id for a in attempts],
    )


def to_developmental_card(view: CompetencyView) -> dict[str, Any]:
    """Non-score developmental representation for debrief/analytics."""
    return {
        "EXPECTED": view.expected_level,
        "OBSERVED_INDEPENDENCE": view.observed_independence,
        "QUALITY": view.observed_quality,
        "CONSISTENCY": view.observed_consistency,
        "CONTEXT": view.context_summary or "UNKNOWN",
        "TREND": view.trend,
        "ATTEMPT_REPEATABILITY": view.attempt_repeatability,
        "LONGITUDINAL_STABILITY": view.longitudinal_stability,
        "TRANSFER": view.transfer_evidence,
        "CONFIDENCE": view.confidence,
        "WHY": view.explanation,
        "EVIDENCE": view.evidence_refs,
    }
