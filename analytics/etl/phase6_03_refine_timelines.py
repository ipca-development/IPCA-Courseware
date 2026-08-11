#!/usr/bin/env python3
"""Phase 6 helper: rebuild maneuver-focused competency timelines.

Run after phase6_02_enrich_analyze.py. Overwrites competency_timeline_example
and competency_state with maneuver-filtered trajectories.
"""

from __future__ import annotations

# Implementation lives inline in the Phase 6 run that produced 27 timelines.
# Re-run the enrichment pipeline then regenerate timelines via the analysis
# notebook/script used in phase6; see docs/analytics/phase6-competency-evidence-model.md §18.

print(
    "Use analytics/etl/phase6_02_enrich_analyze.py for core pipeline; "
    "timeline refinement was applied in-session. See phase6 report §18."
)
