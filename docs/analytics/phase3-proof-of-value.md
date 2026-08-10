# Phase 3 Proof-of-Value Analyses

Generated: 2026-08-10T19:22:40+00:00

These analyses use the normalized analytics SQLite DB. They are descriptive / associative, not causal claims.

## 1. Top mission bottlenecks

Rule: among valid sessions, extra sessions beyond first attempt for a student+mission, normalized by student count.

| program | code | mission | students | sessions | extra/student | sessions/student | confidence |
|---|---|---|---:|---:|---:|---:|---|
| Flight Instructor - FI(A) | FI-LB | Long Briefing Practicum | 19 | 317 | 15.684 | 16.684 | MEDIUM |
| EASA Airline Career Program - (ACP) | 7-1-8 | Extra Mission(s) - (As required to obtain TT of 102.0h PIC) | 35 | 324 | 8.257 | 9.257 | HIGH |
| FAA Airline Career Program - (ACP) | 4-4-6 | Extra Mission(s) - (As required to obtain 101.5h PIC) | 7 | 48 | 5.857 | 6.857 | LOW |
| Instrument Rating - IR(A) | 2-2-1 | Performing Holdings | 110 | 435 | 2.955 | 3.955 | HIGH |
| FAA Airline Career Program - (ACP) | 3-1-3 | Final Progress Test - (1.5h DUAL/0.3h B-IR) | 5 | 18 | 2.6 | 3.6 | LOW |
| Instrument Rating - IR(A) | 2-3-3 | Flying Non-Precision Approaches and Circle to Land | 101 | 339 | 2.356 | 3.356 | HIGH |
| EASA SE Instrument Rating - IR(A) | 2-2-2 | Performing Holdings - (6.0h SE/FSTD) | 6 | 19 | 2.167 | 3.167 | LOW |
| EASA Airline Career Program - (ACP) | 10-2-2 | Performing Holdings - (6.0h ME/FSTD) | 15 | 47 | 2.133 | 3.133 | MEDIUM |
| Private Pilot - PPL(A) Old | 1-3-11 | Phase 3 - Progress check | 56 | 169 | 2.018 | 3.018 | HIGH |
| EASA ME Instrument Rating - IR(A) | 2-2-2 | Performing Holdings - (6.0h ME/FSTD) | 16 | 48 | 2 | 3 | MEDIUM |
| EASA ME Instrument Rating - IR(A) | 2-3-4 | Flying 2D Approaches and Circle to Land - (5.0 ME/FSTD) | 20 | 59 | 1.95 | 2.95 | HIGH |
| FAA Airline Career Program - (ACP) | 1-2-12 | Phase 2 - Progress Check - (2.0h DUAL) | 8 | 23 | 1.875 | 2.875 | MEDIUM |
| Instrument Rating - IR(A) | 2-3-1 | Flying Precision Approaches | 103 | 296 | 1.874 | 2.874 | HIGH |
| EASA Airline Career Program - (ACP) | 1-3-6 | Takeoff and landing consolidation - (4.0h DUAL) | 35 | 100 | 1.857 | 2.857 | HIGH |
| EASA SE Instrument Rating - IR(A) | 3-1-1 | Exam Preparation - (2.0h SE/FSTD) | 6 | 17 | 1.833 | 2.833 | LOW |
| EASA ME Instrument Rating - IR(A) | 2-3-2 | Flying 3D Approaches - (4.0h ME/FSTD) | 20 | 56 | 1.8 | 2.8 | HIGH |
| EASA SE Instrument Rating - IR(A) | 2-3-2 | Flying 3D Approaches - (4.0h SE/FSTD) | 5 | 14 | 1.8 | 2.8 | LOW |
| Instrument Rating - IR(A) | 2-1-1 | VOR/NDB Interceptions  | 117 | 327 | 1.795 | 2.795 | HIGH |
| Instrument Rating - IR(A) | 2-1-2 | Flying Instrument Departures  | 110 | 298 | 1.709 | 2.709 | HIGH |
| EASA ME Instrument Rating - IR(A) | 2-1-2 | VOR/NDB Interceptions - (5.0h ME/FSTD) | 17 | 46 | 1.706 | 2.706 | MEDIUM |
| FAA Airline Career Program - (ACP) | 1-3-6 | Takeoff and landing consolidation - (4.0h DUAL) | 6 | 16 | 1.667 | 2.667 | LOW |
| EASA Airline Career Program - (ACP) | 14-1-6 | Long Cross-Country & Final Progress Check - (4.0h ME/DUAL) | 13 | 34 | 1.615 | 2.615 | MEDIUM |
| EASA Airline Career Program - (ACP) | 10-1-2 | VOR/NDB Interceptions - (5.0h ME/FSTD) | 15 | 39 | 1.6 | 2.6 | MEDIUM |
| EASA SE Instrument Rating - IR(A) | 2-1-2 | VOR/NDB Interceptions - (4.0h SE/FSTD) | 5 | 13 | 1.6 | 2.6 | LOW |
| EASA Airline Career Program - (ACP) | 10-3-4 | Flying 2D Approaches and Circle to Land - (5.0 ME/FSTD) | 14 | 36 | 1.571 | 2.571 | MEDIUM |

## 2. Top exercise difficulties

Rule: required_level_not_met rate where required level is known; exposure>=30, students>=5.

| program | required | not_met% | exposure | students | exercise |
|---|---|---:|---:|---:|---|
| APS MCC | PE | 57.63 | 59 | 59 | (S) 3.5.6 Failure Management (PE) |
| APS MCC | PE | 56.9 | 58 | 58 | (PI) 1.12 SYSTEM ABNORMAL AND EMERGENCY OPERATIONS (PE) |
| APS MCC | PE | 50 | 58 | 58 | (PI) 1.5 PROBLEM SOLVING AND DECISION MAKING (PE) |
| APS MCC | PE | 40.68 | 59 | 59 | (S) 3.5.4 Fuel Management (and awareness) (PE) |
| APS MCC | PE | 40.68 | 59 | 59 | (S) 3.5.2 Diversion Decision Making and Execution (PE) |
| APS MCC | PE | 40.68 | 59 | 59 | (S) 3.5.1 Threat and Error Management (PE) |
| APS MCC | PE | 40.68 | 59 | 59 | (PI) 1.6 MONITORING AND CROSS CHECKING (PE) |
| APS MCC | PE | 37.29 | 59 | 59 | (PI) 1.4 WORKLOAD MANAGEMENT (PE) |
| APS MCC | PE | 35.59 | 59 | 59 | (PI)1.14 CREW RESOURCE MANAGEMENT (PE) |
| APS MCC | PE | 33.9 | 59 | 59 | (PI) 1.3 SITUATIONAL AWARNESS (PE) |
| EASA Airline Career Program - (ACP) | PE | 32.5 | 40 | 36 | (R) Failure to use the proper checklist for a system or equipment malfunction (PE) |
| APS MCC | PE | 32.2 | 59 | 59 | (PI) 1.1 COMMUNICATION (PE) |
| APS MCC | PE | 30.51 | 59 | 59 | (PI) 1.13 ENVIRONMENT, WEATHER AND ATC (PE) |
| APS MCC | PE | 30.51 | 59 | 59 | (PI) 1.2 LEADERSHIP AND TEAMWORK (PE) |
| APS MCC | PE | 28.81 | 59 | 59 | (S) 3.5.5 Passenger and Crew Care (PE) |
| APS MCC | PE | 27.12 | 59 | 59 | (S) 3.6.1 Practical understanding of airline Ops, Applying SOPâ€™s and Procedures des |
| APS MCC | PE | 27.12 | 59 | 59 | (S) 3.1.1 Check In Procedures in accordance with OM A (PE) |
| APS MCC | PE | 27.12 | 59 | 59 | (PI) 2.8.4 Crew Member Responsibilities (PE) |
| APS MCC | PE | 27.12 | 59 | 59 | (PI) 2.8.2 Challenges (PE) |
| APS MCC | PE | 27.12 | 59 | 59 | (PI) 2.3 USE OF LIFT AND DRAG DEVICES (PE) |
| APS MCC | PE | 27.12 | 59 | 59 | (PI) 2.2 SPEED AND THRUST MANAGEMENT (PE) |
| APS MCC | PE | 27.12 | 59 | 59 | (PI) 2.1 THRUST AND ATTITUDE FLYING (PE) |
| APS MCC | PE | 27.12 | 59 | 59 | (PI) 1.7 TASK SHARING (PE) |
| EASA Airline Career Program - (ACP) | PR | 26.79 | 56 | 36 | (S) Maintain the entry altitude Â±100 feet, airspeed Â±10 knots, bank Â±5Â°, and r |
| EASA Airline Career Program - (ACP) | PE | 25.42 | 59 | 38 | (S) Maintain the specified altitude, Â±100 feet; specified heading, Â±10Â°, airspeed |

## 3. Competency stability after PE

Rule: first time an exercise is marked B while required PE; among those later re-observed, share with a later R/Y/G.

| program | PE events | reobserved | later below PE | % |
|---|---:|---:|---:|---:|
| Private Pilot - PPL(A) | 122847 | 21188 | 494 | 2.33 |
| EASA Airline Career Program - (ACP) | 77659 | 11525 | 132 | 1.15 |
| Commercial Pilot - CPL(A) | 57600 | 7687 | 238 | 3.1 |
| Multi-Engine Piston - MEP(A) | 16326 | 2312 | 19 | 0.82 |
| Advanced UPRT | 14055 | 141 | 0 | 0 |
| FAA Airline Career Program - (ACP) | 10544 | 3111 | 165 | 5.3 |
| ACP Screening | 8233 | 230 | 1 | 0.43 |
| APS MCC | 3485 | 4 | 0 | 0 |
| EASA ME Instrument Rating - IR(A) | 3186 | 683 | 3 | 0.44 |
| EASA SE Instrument Rating - IR(A) | 1123 | 297 | 31 | 10.44 |
| MCC Instructor - MCCI | 492 | 107 | 0 | 0 |
| Airline Assessments | 75 | 0 | 0 |  |
| FAA CFI | 15 | 0 | 0 |  |

### Exercises with highest later-below-PE rates (reobserved students ≥ 8)

| % later below | reobserved | exercise |
|---:|---:|---|
| 25 | 8 | Up to ACS (PE) |
| 25 | 8 | (S) Verify position within 2 nautical miles of the flight-planned route (PE) |
| 22.22 | 9 | (S) Inspect the airplane with reference to an appropriate checklist (PE) |
| 22.22 | 9 | (S) Verify the airplane is airworthy and in condition for safe flight (PE) |
| 20 | 10 | (R) Confirmation or expectation bias as related to taxi instructions (PE) |
| 15.38 | 13 | (K) Engine limitations as they relate to starting (PE) |
| 12.5 | 24 | (R) Effects of: Crosswind, to include exceeding maximum demonstrated crosswind component (PE) |
| 12.5 | 8 | (S) Level off from a climb at a height equal to 10 percent of the climb rate, by reducing Pitch, Pow |
| 12.5 | 8 | (R) Poor communication (PE) |
| 12.5 | 8 | Up to ACS (PE) |
| 12.5 | 8 | Shows Knowledge (K) and Risk Management (R) (PE)  |
| 12.5 | 8 | (S) Prepare, present and explain a cross-country flight plan, including a risk analysis based on rea |
| 12.5 | 8 | (S) Apply pertinent information from appropriate and current aeronautical charts, chart supplements; |
| 12.5 | 8 | (S) Create a navigation log and simulate filing a VFR flight plan (PE) |
| 12.5 | 8 | (S) Recalculate fuel reserves based on this scenario (PE) |
| 12.5 | 8 | (S) Correctly identify airspace and operate in accordance with associated communication and equipmen |
| 12.5 | 8 | (S) Identify the requirements for operating in airspaces with certain restrictions (PE) |
| 12.5 | 8 | Shows Knowledge (K) and Risk Management (R) (PE)  |
| 12.5 | 8 | (S) Secure all items in the flight deck and cabin (PE) |
| 12.5 | 8 | (S) Maintain manufacturerâ€™s recommended approach airspeed, or in its absence, not more than 1 |

## 4. Training gap effect

Overall association (not causal):

| gap days | sessions | % incomplete | % red | avg exercises below | % repeat attempt |
|---|---:|---:|---:|---:|---:|
| 0-2 | 13795 | 8.17 | 0.31 | 0.37 | 17.55 |
| 3-5 | 5084 | 12.12 | 0.49 | 0.68 | 18.51 |
| 6-10 | 4016 | 15.71 | 0.3 | 0.45 | 20.12 |
| 11-20 | 2338 | 18.35 | 0.38 | 0.4 | 21.9 |
| 21+ | 2110 | 18.77 | 0.33 | 0.29 | 29.53 |

## 5. Instructor downstream validity (no rankings)

Among exercise marks where required level was met and achieved was G or B, and the same student+exercise was observed again later: share where a later attempt was required_level_not_met or achieved R/Y.

Caveats:
- Not adjusted for student ability, mission difficulty, or curriculum version in this proof-of-value pass.
- Instructors teaching harder phases may show higher later-problem rates without being poorer instructors.
- Later problems may occur under a different instructor; this metric is about durability after a strong mark, not blame.
- Do not rank instructors from this table alone.

| instructor | strong marks w/ later obs | later problematic | % | sample |
|---|---:|---:|---:|---|
| Stef Van De Sompele (408) | 20095 | 455 | 2.26 | SAMPLE_OK |
| Jasper De Hertog (53) | 15152 | 303 | 2 | SAMPLE_OK |
| Zane  Haley (988) | 13713 | 371 | 2.71 | SAMPLE_OK |
| Koen Maes (274) | 11680 | 330 | 2.83 | SAMPLE_OK |
| Laurent Philips (463) | 8508 | 70 | 0.82 | SAMPLE_OK |
| Judson Graham (1006) | 8148 | 217 | 2.66 | SAMPLE_OK |
| Brent  Dormaels (321) | 7433 | 110 | 1.48 | SAMPLE_OK |
| Lucas Nauwelaerts (362) | 5102 | 276 | 5.41 | SAMPLE_OK |
| Kay Vereeken (1) | 4076 | 75 | 1.84 | SAMPLE_OK |
| Jerome Auplat (651) | 3215 | 80 | 2.49 | SAMPLE_OK |
| Willy Rozendaal (664) | 2817 | 33 | 1.17 | SAMPLE_OK |
| Geert Vergauwen (205) | 1361 | 14 | 1.03 | SAMPLE_OK |
| Nils Bastiaensen (14) | 1227 | 39 | 3.18 | SAMPLE_OK |
| Daniel Poelman (258) | 1212 | 1 | 0.08 | SAMPLE_OK |
| Guido Verbist (305) | 992 | 10 | 1.01 | SAMPLE_OK |
| Ward Goethals (760) | 882 | 36 | 4.08 | SAMPLE_OK |
| Dana (Instructor) James (751) | 402 | 0 | 0 | SAMPLE_OK |
| Tom Gielens (649) | 341 | 7 | 2.05 | SAMPLE_OK |
| Anthony FI Rasseneur (461) | 311 | 1 | 0.32 | SAMPLE_OK |
| Willy Rozendaal (879) | 201 | 0 | 0 | SAMPLE_OK |
| Miguel FI De Volder  (353) | 104 | 3 | 2.88 | SAMPLE_OK |
| Pieter Huylebroeck (465) | 75 | 3 | 4 | SAMPLE_LIMITED |
| Max Weterings FI (363) | 61 | 0 | 0 | SAMPLE_LIMITED |
| Glenn Crab (3) | 54 | 0 | 0 | SAMPLE_LIMITED |
| Christophe Severijns (239) | 53 | 0 | 0 | SAMPLE_LIMITED |

---

Full JSON: `tmp/analytics/phase3_proof_of_value.json`
