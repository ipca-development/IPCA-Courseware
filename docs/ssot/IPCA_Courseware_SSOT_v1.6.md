# IPCA Courseware SSOT v1.6 (Merged Update)

This document merges SSOT v1.2 navigation architecture with the verified Step‑2 Instructor Intervention implementation already deployed in the live system.

This version becomes the authoritative **Single Source of Truth (SSOT)** for:

- architecture
- workflow gates
- database structure
- instructor intervention logic
- navigation structure

---

# 1. System Purpose

IPCA Courseware is a training platform supporting:

• Theory training lifecycle • Flight training lifecycle • Scheduling and resource planning • Operational student management • Compliance monitoring • Safety management

Immediate priority remains **completion and stabilization of the Theory Training system**.

---

# 2. Core Architecture Principles

The following components remain authoritative and must not be replaced or duplicated:

| Component                           | Role                          |
| ----------------------------------- | ----------------------------- |
| student\_required\_actions          | workflow engine               |
| instructor/instructor\_approval.php | instructor intervention UI    |
| training\_progression\_events       | legal audit log               |
| training\_progression\_emails       | email queue/history           |
| lesson\_activity                    | structural training state     |
| progress\_tests\_v2.attempt         | authoritative attempt counter |

These components form the **progression state machine** of the system.

---

# 3. Instructor Intervention System (Step‑2)

Instructor escalation is now **structured decision logic**, not a binary approval flag.

Instructor decisions supported:

• approve\_additional\_attempts • approve\_with\_summary\_revision • approve\_with\_one\_on\_one • suspend\_training

Instructor decisions are stored in **student\_required\_actions**.

## student\_required\_actions new fields

```
decision_code
decision_notes
decision_payload_json
decision_by_user_id
decision_at
granted_extra_attempts
summary_revision_required
one_on_one_required
training_suspended
major_intervention_flag
```

These fields allow the system to record:

• additional attempts • summary revision requirement • instructor session requirement • suspension decisions • intervention classification

---

# 4. lesson\_activity Structural State Extensions

lesson\_activity remains the **structural training state layer**.

Additional operational fields used:

```
granted_extra_attempts
one_on_one_required
one_on_one_completed
training_suspended
latest_instructor_action_id
```

These fields allow progression gates to interpret instructor decisions.

## Completion Status Mapping

No enum expansion was introduced.

Operational mapping:

| Logical State                 | Stored Value              |
| ----------------------------- | ------------------------- |
| awaiting\_summary\_review     | awaiting\_summary\_review |
| remediation\_required         | remediation\_required     |
| awaiting\_instructor\_session | remediation\_required     |
| suspended                     | blocked\_final            |

---

# 5. Attempt Gate Architecture

Allowed attempts are now calculated as:

```
allowed_attempts = base_allowed_attempts + granted_extra_attempts
```

Base progression:

Attempt 1–3 → available

After remediation acknowledgement:

Attempt 4–5 → unlocked

Instructor decisions may extend attempts via:

```
granted_extra_attempts
```

## Attempt Gate Locations

UI gate:

```
public/student/course.php
```

Server gate:

```
public/student/api/test_prepare_start_v2.php
```

The system must **never expose max\_total\_attempts\_without\_admin\_override directly**.

---

# 6. Summary Revision Workflow

Summary revision uses the **existing lesson\_summaries lifecycle**.

State transitions:

```
acceptable
→ instructor requests revision
→ needs_revision
→ student edits
→ pending
→ instructor review
→ acceptable OR needs_revision
```

Progress tests require:

```
lesson_summaries.review_status = acceptable
```

Summary editing occurs in:

```
public/player/slide.php
```

The editor uses **autosave behavior**.

---

# 7. lesson\_summaries Table Extensions

To support comparison and AI assistance:

```
last_reviewed_summary_html
last_reviewed_summary_plain
review_notes_by_instructor
reviewed_by_user_id
```

System stores:

• current student summary • last instructor‑reviewed snapshot

This is **not full version history**.

---

# 8. Instructor Summary Review Page

New page introduced:

```
public/instructor/summary_review.php
```

Capabilities:

• review revised summaries • compare with previous snapshot • AI assistance panel • approve summary • request further revision

Access control:

```
admin OR chief_instructor_user_id
```

AI helper panel suggests:

• what changed • whether requested revision points were addressed • approve / revise recommendation

---

# 9. Student Notification Behaviour

Two new notification types exist:

```
instructor_summary_revision_required
instructor_summary_approved
```

When instructor selects **Needs Further Revision**:

System:

• sends student email • displays player alert banner

Player banner handled in:

```
public/player/slide.php
```

States:

needs\_revision → show instructor feedback

pending → show "summary awaiting review"

API updated:

```
public/student/summary_get.php
```

Returns:

• review\_status • review\_feedback • review\_notes\_by\_instructor

---

# 10. Summary Autosave Behaviour

File:

```
public/student/summary_save.php
```

New logic:

If summary status was:

```
needs_revision
```

Student save automatically sets:

```
pending
```

Meaning:

Student resubmitted revision awaiting instructor review.

---

# 11. Player UX Corrections

Two UX fixes implemented.

## Arrow‑key navigation

Slide navigation disabled when summary editor is focused.

Prevents keyboard conflict.

## Autosave cursor jump

Autosave no longer refreshes editor HTML.

Instead it refreshes only review banners.

---

# 12. Gate Hierarchy

Multiple gates may block training progression simultaneously.

Summary approval only clears **summary gate**.

Other gates may still apply:

• deadline gate • attempt gate • instructor session gate • suspension gate

---

# 13. Development Reset Guidance

When resetting progression tests during development:

Usually clear:

```
progress_tests_v2
lesson_activity
student_required_actions
training_progression_emails
training_progression_events
```

Normally **preserve**:

```
lesson_summaries
```

Unless full summary reset required.

---

# 14. Role-Based Navigation Architecture

Approved Admin navigation:

1 Dashboard 2 Schedule 3 Theory Training 4 Flight Training 5 Operations 6 Projects 7 Compliance Monitoring 8 Safety Management 9 Settings 10 Maintenance

Instructor navigation:

1 Dashboard 2 Schedule 3 Theory Training 4 Flight Training 5 Operations 6 Compliance Monitoring 7 Safety Management

Student navigation:

1 Dashboard 2 My Training 3 Theory Training 4 Flight Training 5 Schedule 6 Documents 7 Account

Navigation must be centralized via:

```
src/navigation.php
src/nav/admin.php
src/nav/instructor.php
src/nav/student.php
```

Layout loads correct menu by role.

---

# 15. Dashboard Responsibilities

Admin dashboard must surface:

• blocked students • pending summary reviews • instructor interventions • missed deadlines • cohort enrollment issues • system health

Instructor dashboard must surface:

• student theory progress • summary reviews • intervention actions • deadline violations

Student dashboard must surface:

• next lesson/test • deadlines • summary blockers • attempt availability

---

# 16. Email / Communication System Direction

Delivery history remains:

```
training_progression_emails
```

Future architecture must introduce:

DB-backed email template system Admin email template editor Notification enable/disable settings

But delivery pipeline remains unchanged.

---

# 17. Development Constraints

Developer preferences:

• prefer full drop‑in file replacements • if partial edits required → specify exact file + location • minimize terminal usage • development workflow:

Dreamweaver → GitHub → DigitalOcean deploy

Database changes via TablePlus.

---

# 18. Next Major Development Goals

Priority order:

1 Complete Theory Training system 2 Instructor dashboards 3 Admin navigation restructuring 4 Email template management 5 AI scheduling system 6 Flight training evaluation system 7 Compliance integration 8 Safety management

---

# 19. System Stack

PHP: 8.2.x

MySQL: 8.0.x

Email: Postmark

Storage: DigitalOcean Spaces

Deployment: DigitalOcean App Platform

---

# 20. Student Safety Access

Students must have limited access to the Safety module.

Allowed:

- read safety bulletins/notices
- acknowledge safety notices (tracked)
- file safety occurrence reports
- review their own submitted reports

Not allowed:

- investigate events
- edit safety policy
- review other students' reports
- close reports

This access must be reflected in student navigation and future Safety module permissions.

---

# 21. Regulator-Safe Evidence Architecture

The platform must preserve regulator-safe evidence for training, safety, compliance, and instructor action workflows.

All major workflow actions must generate traceable evidence in audit/history tables and must never be silently overwritten.

Primary evidence tables include:

- training\_progression\_events
- progress\_tests\_v2
- progress\_test\_items\_v2
- student\_required\_actions
- lesson\_summary\_reviews
- training\_progression\_emails

Every major workflow action must generate a logged event with actor, timestamp, and legal/audit payload.

Official communications must be retained as evidence.

Future cockpit voice recorder / dashcam / debrief workflows must preserve:

- source media
- transcript
- AI analysis
- instructor review
- training linkage

---

# 22. Modular Platform Architecture

The platform must be modular so organizations can subscribe to specific modules.

Core module groups:

- Core System
- Theory Training
- Flight Training
- Scheduling
- Safety Management
- Compliance Monitoring
- Operations
- Instructor Management
- Maintenance

Recommended future tables:

- system\_modules
- organization\_modules

Module activation must be organization-scoped and support pricing tiers and AI usage controls.

---

# 23. Multi-Organization Architecture

The long-term platform must support multiple organizations (tenants).

Recommended future table:

- organizations

All operational/business-domain tables should eventually become organization-scoped via `organization_id`.

This includes future support for:

- branding overrides
- policy overrides
- email template overrides
- AI usage tracking
- module subscriptions

Recommended future supporting tables:

- organization\_settings
- organization\_branding
- organization\_modules
- ai\_usage\_logs

The system should support rapid onboarding of new organizations through a future onboarding wizard.

---

# 24. Architectural Layering Rules

The system is governed by three distinct layers:

## Content Structure Layer

Defines training content and curriculum structure. Examples:

- programs
- courses
- lessons
- slides
- slide\_content
- slide\_enrichment
- slide\_references
- templates
- backgrounds
- cohort\_lesson\_deadlines

This layer must not be modified by routine operational events.

## Operational State Layer

Stores the current truth needed by UI gates and dashboards. Primary current state table:

- lesson\_activity

lesson\_activity is the canonical per-student/per-cohort/per-lesson operational state record.

## Audit / History Layer

Stores immutable historical records. Examples:

- progress\_tests\_v2
- training\_progression\_events
- training\_progression\_emails
- student\_required\_actions
- lesson\_summary\_reviews
- student\_lesson\_deadline\_overrides
- student\_lesson\_reasons

Rule: When a major workflow event occurs, the system should both:

1. write to history/audit tables
2. update operational state tables

UI gates and dashboards should prefer operational state over full live recomputation from audit/history tables.

---

# 25. Platform Vision

IPCA Courseware is designed to evolve into a global aviation training operating system for training organizations worldwide.

Long-term platform domains include:

- Theory Training
- Flight Training
- Scheduling
- Safety Management
- Compliance Monitoring
- Operations Management
- Instructor Tools
- Student Learning Systems
- AI-Assisted Training Evaluation
- Multi-Organization SaaS Deployment

The strategic goal is to become a platform equivalent in importance to what ForeFlight/Garmin Pilot are for pilots, but for training organizations.

---

# 26. Canonical Aviation Training Data Model

The system must support two connected but distinct training hierarchies.

## Theory Training Hierarchy

Program → Course → Lesson → Slide → Summary → Progress Test

## Flight Training Hierarchy

Program → Stage → Phase → Scenario → Exercise

This model must support competency-based training and future mappings to FAA, EASA, ICAO, and other regulatory frameworks.

Recommended future flight-training data model concepts:

- training\_programs
- training\_stages
- training\_phases
- training\_scenarios
- training\_exercises
- training\_competencies
- exercise\_competencies
- training\_sessions
- exercise\_evaluations
- session\_recordings
- exercise\_progress
- lesson\_exercise\_links
- vision\_training\_modules
- ai\_training\_analysis

This canonical model will allow theory, flight, safety, and AI evaluation systems to connect cleanly.

---

# 27. AI Platform Layer

The platform architecture must support AI across multiple domains.

Planned AI roles:

- Theory Instructor AI
- AI Progress Test Generation
- AI Debrief Engine
- AI Scheduling
- Safety Risk AI
- Training Progress AI

AI systems must remain modular and evidence-aware.

---

# 28. Scheduler Architecture Direction

The future scheduler is a core top-level module and must be designed as an optimization engine, not just a calendar.

It must eventually combine:

- students
- instructors
- aircraft
- simulators
- briefing rooms
- classrooms
- Vision Pro sessions
- weather
- maintenance
- student progression state
- compliance constraints

The scheduler must support dynamic recovery when:

- students are sick
- instructors unavailable
- aircraft down for maintenance
- weather prevents flying
- briefings or sims need reassignment

The scheduler must remain policy-driven and organization-scoped.

---

# 29. Development Workflow and Versioning

SSOT files must be versioned and preserved. Older versions must never be overwritten or deleted.

Recommended structure:

- docs/ssot/IPCA\_Courseware\_SSOT\_v1.2.md
- docs/ssot/IPCA\_Courseware\_SSOT\_v1.3.md
- docs/ssot/IPCA\_Courseware\_SSOT\_v1.4.md
- docs/ssot/IPCA\_Courseware\_SSOT\_v1.5.md
- docs/ssot/IPCA\_Courseware\_SSOT\_v1.6.md
- docs/ssot/CURRENT\_SSOT.md

CURRENT\_SSOT.md should identify the currently authoritative SSOT version.

END SSOT v1.6

