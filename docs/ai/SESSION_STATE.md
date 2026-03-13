IPCA Courseware — SESSION_STATE.md

Last Updated: 2026-03-13
SSOT Version: v1.6
Repository: https://github.com/ipca-development/IPCA-Courseware

⸻

Current System Status

The Theory Training System is now operational with the following core capabilities:

• Slide Player with narration and autosave summary editor
• AI-generated oral progress tests (Progress Tests V2)
• Structured training progression engine
• Instructor escalation workflow (Step-2 intervention system)
• Summary revision workflow with instructor review
• Full audit logging for regulatory evidence
• Email notification system using Postmark
• Instructor decision system controlling progression state

The system is now entering the stabilization and UI architecture phase before expanding to additional modules.

The immediate focus remains completing and stabilizing the Theory Training system so students and instructors can use it reliably.

⸻

Current Development Priority

Finish and stabilize the Theory Training system and core dashboards before expanding the platform.

Priority order:
    1.    Admin Navigation Architecture
    2.    Instructor Dashboard
    3.    Student Dashboard Improvements
    4.    Email Template Management System
    5.    System Health Dashboard
    6.    Architecture Scanner Integration
    7.    Scheduler Module Preparation

⸻

Recently Implemented Architecture

Instructor escalation decisions are now structured rather than binary approvals.

Supported instructor decisions:

• approve_additional_attempts
• approve_with_summary_revision
• approve_with_one_on_one
• suspend_training

Decisions are stored in the table student_required_actions.

Decision fields include:

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

Instructor decisions update lesson_activity structural state and control training progression gates.

⸻

lesson_activity Structural Fields

lesson_activity remains the canonical operational state layer.

Operational fields used include:

granted_extra_attempts
one_on_one_required
one_on_one_completed
training_suspended
latest_instructor_action_id

These allow progression gates to interpret instructor decisions.

Completion status mapping used:

awaiting_summary_review → awaiting_summary_review
awaiting_instructor_session → remediation_required
remediation_required → remediation_required
suspended → blocked_final

No enum expansion was introduced.

⸻

Attempt Gate Architecture

Allowed attempts are calculated as:

allowed_attempts = base_allowed_attempts + granted_extra_attempts

Base progression logic:

Attempts 1–3 available initially
Attempts 4–5 unlocked after remediation acknowledgement

Instructor decisions may extend attempts using granted_extra_attempts.

Gate enforcement files:

UI Gate: public/student/course.php
Server Gate: public/student/api/test_prepare_start_v2.php

The system must never expose max_total_attempts_without_admin_override directly to the UI.

⸻

Summary Revision Workflow

Summary revision reuses the existing lesson_summaries lifecycle.

State transitions:

acceptable
→ instructor decision sets needs_revision
→ student edits summary
→ autosave converts to pending
→ instructor review
→ acceptable OR needs_revision

Progress tests require lesson_summaries.review_status = acceptable.

Summary editing occurs in public/player/slide.php.

The editor uses autosave behavior and does not require a submit button.

⸻

lesson_summaries Snapshot Fields

lesson_summaries was extended to support instructor comparison.

New fields include:

last_reviewed_summary_html
last_reviewed_summary_plain
review_notes_by_instructor
reviewed_by_user_id

The system stores the current student summary and the last instructor-reviewed snapshot.

This supports revision comparison without building a full version history system.

⸻

Instructor Summary Review Page

Instructor review page:

public/instructor/summary_review.php

Capabilities:

• review revised summaries
• compare with previous snapshot
• AI helper panel
• approve summary
• request further revision

Access control:

admin OR chief_instructor_user_id

AI helper panel provides:

• summary diff
• revision completion hints
• recommendation approve or revise.

⸻

Student Notification Behaviour

New notification types introduced:

instructor_summary_revision_required
instructor_summary_approved

When instructor selects Needs Further Revision:

The system sends a student email and displays an alert banner in the slide player.

Slide player behavior handled in public/player/slide.php.

Banner states:

needs_revision → show instructor feedback
pending → show summary awaiting instructor review

API update:

public/student/summary_get.php now returns review_status, review_feedback, and review_notes_by_instructor.

⸻

Summary Autosave Behaviour

File: public/student/summary_save.php

If an existing summary has review_status = needs_revision, then on student save the status automatically becomes pending, meaning the student revision has been resubmitted for instructor review.

⸻

Player UX Corrections

Two UX issues were corrected.

Arrow-key navigation conflict: slide navigation is disabled when the summary editor is focused.

Autosave cursor jump issue: autosave no longer reloads editor HTML; only review banners refresh to preserve cursor position.

⸻

Gate Hierarchy

Training progression may be blocked by multiple gates simultaneously.

Summary approval clears only the summary gate.

Other gates may still apply:

deadline gate
attempt gate
instructor session gate
suspension gate

⸻

Development Reset Guidance

When resetting progression during development, normally clear:

progress_tests_v2
lesson_activity
student_required_actions
training_progression_emails
training_progression_events

Normally preserve lesson_summaries unless performing a full summary reset.

⸻

Role-Based Navigation Architecture

Admin navigation structure:

Dashboard
Schedule
Theory Training
Flight Training
Operations
Projects
Compliance Monitoring
Safety Management
Settings
Maintenance

Instructor navigation:

Dashboard
Schedule
Theory Training
Flight Training
Operations
Compliance Monitoring
Safety Management

Student navigation:

Dashboard
My Training
Theory Training
Flight Training
Schedule
Documents
Account

Navigation will be centralized through src/navigation.php and role-specific files in src/nav/.

⸻

Dashboard Responsibilities

Admin dashboard must display blocked students, pending summary reviews, instructor interventions, missed deadlines, cohort enrollment issues, and system health.

Instructor dashboard must display student theory progress, summary review tasks, intervention decisions, and deadline violations.

Student dashboard must display next lesson, next test, deadlines, summary blockers, and attempt availability.

⸻

Email / Communication Architecture

Email delivery history remains stored in training_progression_emails.

Future architecture will introduce database-backed email templates, an admin email template editor, and notification enable/disable controls.

The delivery pipeline itself remains unchanged.

⸻

Development Constraints

Developer preferences:

Prefer full drop-in file replacements.
If partial edits are required, specify exact file and location.
Minimize terminal usage.

Development workflow:

Dreamweaver → GitHub commit → DigitalOcean deploy.

Database updates handled through TablePlus on Mac.

⸻

Infrastructure Environment

PHP Version: 8.2.x
MySQL Version: 8.0.x

Hosting: DigitalOcean App Platform
Storage: DigitalOcean Spaces
Email: Postmark using domain ipca.aero

⸻

Upcoming Major Modules

After theory stabilization:
    1.    AI Scheduler System
    2.    Flight Training Evaluation System
    3.    Compliance Monitoring Integration
    4.    Safety Management System
    5.    Multi-organization SaaS architecture

⸻

Platform Vision

IPCA Courseware is designed to become a global aviation training operating system.

Domains supported include:

Theory Training
Flight Training
Scheduling
Safety Management
Compliance Monitoring
Operations Management
Instructor Tools
Student Learning Systems
AI-Assisted Training Evaluation
Multi-Organization SaaS Deployment

The strategic goal is to become the equivalent of ForeFlight or Garmin Pilot for training organizations worldwide.
