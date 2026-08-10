# Phase 3 Validation Report

Generated: 2026-08-10T19:22:35+00:00

## A. Canonical row counts

- **dim_student**: 851
- **dim_instructor**: 77
- **dim_program**: 22
- **dim_curriculum_family**: 11
- **dim_curriculum_version**: 22
- **dim_stage**: 77
- **dim_phase**: 150
- **dim_mission**: 714
- **dim_exercise**: 40824
- **dim_device**: 31
- **fact_training_session**: 27982
- **fact_exercise_attempt**: 1224545
- **fact_srm_attempt**: 155465
- **fact_logbook_leg**: 31078
- **fact_narrative**: 27166
- **bridge_student_identity**: 35
- **qa_exclusion_log**: 11
- **qa_data_issue**: 1

## B. Session mapping

- Extracted sessions: 27982
- HIGH_CONFIDENCE + USABLE_WITH_QUALIFICATION: 27836 (99.48%)

| qa_class | n |
|---|---:|
| HIGH_CONFIDENCE | 24296 |
| USABLE_WITH_QUALIFICATION | 3540 |
| AMBIGUOUS | 135 |
| EXCLUDE | 11 |

## C–D. Exercise parse

- Exercise attempt rows parsed OK: 1224545
- Sessions with ex blob OK/EMPTY/FAIL: 24456 / 3526 / 0

## E–G. Identity / mission / orphans

- Sessions without student: 54
- Sessions without mission: 94
- Exercise attempts missing dim_exercise: 0

## H. Required level distribution (dim_exercise, non-title implied in null handling)

| required_level_normalized | n |
|---|---:|
| PR | 12178 |
| PE | 9247 |
| EX | 5180 |
| DE | 4389 |
| NULL | 2146 |

## I. Achieved grade distribution

| achieved_grade_raw | stage | n |
|---|---|---:|
| G | PR | 451466 |
| B | PE | 413457 |
| Y | EX | 177461 |
| R | DE | 145090 |
| D | DEFERRED | 37071 |

## J. Session grade distribution

| grading_raw | color | completion | n |
|---|---|---|---:|
| GC | G | C | 21570 |
| GI | G | I | 2417 |
| BC | B | C | 1316 |
| YC | Y | C | 1011 |
| (blank) | BLANK | BLANK | 753 |
| YI | Y | I | 746 |
| RC | R | C | 91 |
| BI | B | I | 64 |
| RI | R | I | 14 |

## K. Session counts

### By year

| year | n |
|---|---:|
| 2014 | 18 |
| 2015 | 56 |
| 2016 | 1025 |
| 2017 | 1779 |
| 2018 | 2136 |
| 2019 | 2588 |
| 2020 | 2827 |
| 2021 | 2690 |
| 2022 | 2475 |
| 2023 | 3784 |
| 2024 | 3668 |
| 2025 | 3302 |
| 2026 | 1623 |

### By program

| program | n |
|---|---:|
| Private Pilot - PPL(A) | 6398 |
| EASA Airline Career Program - (ACP) | 5406 |
| Instrument Rating - IR(A) | 5218 |
| Private Pilot - PPL(A) Old | 3290 |
| Multi-Engine Piston - MEP(A) | 1481 |
| Commercial Pilot - CPL(A) | 1092 |
| Flight Instructor - FI(A) | 1036 |
| APS MCC | 934 |
| FAA Airline Career Program - (ACP) | 899 |
| EASA ME Instrument Rating - IR(A) | 837 |
| Advanced UPRT | 363 |
| Commercial Pilot - CPL(A) Old | 282 |
| EASA SE Instrument Rating - IR(A) | 276 |
| ACP Screening | 204 |
| Night Rating - NQ | 131 |
| Instrument Instructor - IRI(A) | 65 |
| MCC Instructor - MCCI | 52 |
| Airline Assessments | 15 |
| FAA CFI | 3 |

### By training type

| source_session_type | normalized | n |
|---|---|---:|
| FLIGHT | FLIGHT | 13387 |
| FNPT | SIMULATOR_FNPT | 6858 |
| LB | LONG_BRIEFING | 4296 |
| SAB | SIMULATOR_BRIEFING_SAB | 3347 |
| (blank) | BLANK | 94 |

## L. Candidate duplicate identities

- Groups: 17 (rows 35)
- Status: all **CANDIDATE** (no automatic merges)


## M. Curriculum family / version mapping

| family | version | gen | current | program | tracking | loc |
|---|---|---:|---:|---|---|---|
| ACP | AIRLINE | 1 | 1 | Airline Assessments | scenario_tracking_AIRLINE | BE |
| ACP | SCREENING | 1 | 1 | ACP Screening | scenario_tracking_SCREENING | BE |
| ACP | FAAACP | 1 | 1 | FAA Airline Career Program - (ACP) | scenario_tracking_FAAACP | US |
| ACP | EASAACP | 1 | 1 | EASA Airline Career Program - (ACP) | scenario_tracking_EASAACP | ALL |
| CPL | CPLA_OLD | 1 | 0 | Commercial Pilot - CPL(A) Old | scenario_tracking_CPLA | BE |
| CPL | CPLAUPRT | 2 | 1 | Commercial Pilot - CPL(A) | scenario_tracking_CPLAUPRT | BE |
| FI | FIA | 1 | 1 | Flight Instructor - FI(A) | scenario_tracking_FIA | BE |
| IR | IR_LEGACY | 1 | 1 | Instrument Rating - IR(A) | scenario_tracking_IR | BE |
| IR | IRNEW_SE | 2 | 1 | EASA SE Instrument Rating - IR(A) | scenario_tracking_IRNEW | BE |
| IR | IRNEW_ME | 2 | 1 | EASA ME Instrument Rating - IR(A) | scenario_tracking_IRNEWME | BE |
| IRI | IRI | 1 | 1 | Instrument Instructor - IRI(A) | scenario_tracking_IRI | BE |
| MCC | APSMCC | 1 | 1 | APS MCC | scenario_tracking_APSMCC | BE |
| MCC | MCCI | 1 | 1 | MCC Instructor - MCCI | scenario_tracking_MCCI | BE |
| MEP | MEP_OLD | 1 | 0 | Multi-Engine Piston - MEP(A) | scenario_tracking_MEP | BE |
| MEP | MEPNEW | 2 | 1 | Multi-Engine Piston - MEP(A) | scenario_tracking_MEPNEW | BE |
| NQ | NQ | 1 | 1 | Night Rating - NQ | scenario_tracking_NQ | US |
| OTHER | EXP | 1 | 0 | Experience Building USA | scenario_tracking_EXP | US |
| OTHER | STANDARDIZATION | 1 | 1 | EPC Standardization  |  | ALL |
| OTHER | CFI | 1 | 1 | FAA CFI | scenario_tracking_CFI | US |
| PPL | PPL_OLD | 1 | 0 | Private Pilot - PPL(A) Old | scenario_tracking_PPL | BE |
| PPL | PPLA | 2 | 1 | Private Pilot - PPL(A) | scenario_tracking_PPLA | BE |
| UPRT | AUPRT | 1 | 1 | Advanced UPRT | scenario_tracking_AUPRT | BE |

## N. Formal check / solo classifications

| class | confidence | n |
|---|---|---:|
| NORMAL TRAINING | MEDIUM | 606 |
| PROGRESS CHECK | MEDIUM | 53 |
| SOLO | MEDIUM | 25 |
| FINAL PROGRESS CHECK | MEDIUM | 14 |
| SOLO CROSS-COUNTRY | MEDIUM | 7 |
| SKILL TEST / CHECKRIDE | HIGH | 5 |
| SOLO CROSS-COUNTRY | HIGH | 2 |
| SOLO | HIGH | 1 |
| UNKNOWN | LOW | 1 |

## O. Notable discoveries

- SRM grade columns in UI are EX/PR/MD/NO while exercise grades use DE/EX/PR/PE; both store R/Y/G/B — do not conflate.
- SAB/LB confirmed in scenarios_admin.php as Simulator Briefing / Long Briefing; user also describes SAB as scenario-based simulator session.
- sctr_next/sctr_alternative are instructor-selected next/alternative scenario IDs, not session IDs.
- Overall session grades are often auto-derived from exercise colors; treat as dependent evidence.
- Curriculum families are explicit via pr_db old/new pairs and should be compared longitudinally.

### SRM dictionary

| key | UI label | confidence | freq | values |
|---|---|---|---:|---|
| SM | Safety Management (SM) | HIGH | 22217 | B,G,R,Y |
| SA | Situational Awareness (SA) | HIGH | 22215 | B,G,R,Y |
| TM | Task Management (TM) | HIGH | 22214 | B,G,R,Y |
| ADM | Aeronautical Decision Making (ADM) | HIGH | 22212 | B,G,R,Y |
| AM | Automation Management (AM) | HIGH | 22208 | B,G,R,Y |
| RM | Risk Management (RM) | HIGH | 22208 | B,G,R,Y |
| CFIT | Controlled Flight Into Terrain (CFIT) | HIGH | 22191 | B,G,R,Y |

### sctr_next / sctr_alternative

From training_record_instructor.php: sctr_next and sctr_alternative store scenario IDs selected by instructor. UI labels are Next Mission and Alternative Mission. Sentinel 999999999 means none. Email/print templates resolve them via SELECT * FROM scenarios WHERE sc_id=...

- next none: 13.17%
- alternative none: 50.98%
