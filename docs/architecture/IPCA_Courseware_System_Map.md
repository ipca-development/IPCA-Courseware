# IPCA Courseware System Map

## Purpose
This document explains the major system domains of IPCA Courseware and how they relate to each other.

It is a companion to the SSOT and is intended to help:
- developers
- AI assistants
- future technical contributors
- internal stakeholders

This file does not replace the SSOT.
It visualizes and organizes the platform structure.

---

## 1. Core Platform Domains

IPCA Courseware is evolving into a modular aviation training operating system.

Primary domains:

1. Dashboard
2. Schedule
3. Theory Training
4. Flight Training
5. Operations
6. Projects
7. Compliance Monitoring
8. Safety Management
9. Settings
10. Maintenance (future)

---

## 2. Current Live Core

The currently active/live core is centered on Theory Training.

### Theory Training currently includes:
- Programs
- Courses
- Lessons
- Slides
- Slide content/canonical data
- Student summaries
- Progress tests
- Cohorts
- Deadlines
- Instructor interventions
- Summary review workflow

Primary current workflow:
Program
→ Course
→ Lesson
→ Slide
→ Summary
→ Progress Test
→ Intervention / Review
→ Completion

---

## 3. Future Flight Training Domain

The future flight training model will use:

Program
→ Stage
→ Phase
→ Scenario
→ Exercise

This domain will eventually connect to:
- instructor evaluations
- simulator sessions
- cockpit audio/video review
- AI debrief
- checkride readiness
- scheduling engine

---

## 4. Scheduling Domain

The scheduler is not just a calendar.

It will act as a training optimization engine using:
- student progression state
- instructor availability
- aircraft availability
- simulator availability
- room/device availability
- weather
- maintenance
- policy constraints

The scheduler must support:
- planning
- disruption recovery
- reallocation
- optimization

---

## 5. Safety Domain

Safety Management will support:
- safety bulletins
- safety acknowledgements
- safety occurrence reports
- investigations
- trend analysis

Student access will be limited to:
- reading safety notices
- acknowledging safety notices
- filing safety reports
- viewing their own reports

---

## 6. Compliance Domain

Compliance Monitoring will support:
- training compliance
- regulatory mapping
- audit evidence
- certification support
- policy enforcement reporting

---

## 7. Operations Domain

Operations will eventually include:
- student lifecycle
- screenings
- financials
- contracts
- tool requirements
- access keys
- downloads

---

## 8. Architectural Layers

The platform is governed by three layers:

### A. Content Structure Layer
Defines the training content and curriculum structure.

Examples:
- programs
- courses
- lessons
- slides
- slide_content
- slide_enrichment

### B. Operational State Layer
Stores current truth needed for UI and workflow gating.

Primary table:
- lesson_activity

### C. Audit / History Layer
Stores immutable historical records.

Examples:
- progress_tests_v2
- training_progression_events
- student_required_actions
- training_progression_emails
- lesson_summary_reviews

Rule:
The UI should prefer operational state over recalculating history live.

---

## 9. Role-Based Application Views

### Admin
Full system access and configuration.

### Instructor
Training operations and issue resolution.

### Student
Training consumption and limited self-service actions.

---

## 10. Multi-Organization Direction

The long-term architecture supports multiple organizations.

Future concept:
- organizations
- organization_settings
- organization_modules
- organization-scoped policies
- organization branding

All major operational modules should eventually become organization-aware.

---

## 11. AI Layer

The platform will include:
- Theory Instructor AI
- AI Progress Test Generation
- AI Debrief Engine
- AI Scheduling
- Safety Risk AI
- Training Progress AI

AI must remain:
- modular
- evidence-aware
- policy-governed

---

## 12. Platform Goal

The long-term goal is to evolve IPCA Courseware into a global aviation training operating system for training organizations worldwide.