# IPCA Courseware — Master Context Pack (SSOT)

## Purpose
This document is the single source of truth for the current architecture, workflow rules, progression logic, active database structures, and implementation decisions for the IPCA Courseware system. It is intended to prevent context loss across chats and to keep code and policy development aligned.

---

# 1. Core System Goal
IPCA Courseware is an AI-assisted training delivery and progression control system for structured aviation training.

The system currently includes:
- Program → Course → Lesson → Slide delivery
- Student cohort enrollment and sequencing
- Lesson summaries with AI review
- Voice-based progress tests (V2)
- Policy-driven progression logic
- Required remedial/instructor actions
- Deadline extension and reason workflows
- Audit-grade event logging
- AI-generated email communication

The system must evolve into a compliance-defensible, policy-driven training control engine.

---

# 2. Current Domain Structure
## 2.1 Training hierarchy
- `programs`
- `courses`
- `lessons`
- `slides`
- `cohorts`
- `cohort_lesson_deadlines`
- `cohort_students`

## 2.2 Student learning flow
For each lesson, the intended sequence is:
1. Student studies lesson slides
2. Student writes lesson summary
3. AI/system reviews summary
4. Once acceptable, progress test becomes available
5. Student takes voice-based progress test
6. System finalizes result based on score + timing + policy
7. Depending on outcome, lesson may:
   - complete
   - remain in progress
   - require remediation acknowledgement
   - require instructor approval
   - later require deadline reason workflow

---

# 3. Active Progression Engine (Current Real State)
## 3.1 Main engine files
Primary logic currently lives in:
- `src/courseware_progression_v2.php`
- `student/api/test_finalize_v2.php`
- `student/course.php`
- `student/remediation_action.php`
- `instructor/instructor_approval.php`

## 3.2 Main progression data tables
### Core lesson/test state
- `lesson_activity`
- `progress_tests_v2`
- `progress_test_items_v2`
- `lesson_summaries`

### Policy + control tables
- `system_policy_definitions`
- `system_policy_values`
- `system_policy_audit`

### Required workflow / escalation tables
- `student_required_actions`
- `training_progression_events`
- `training_progression_emails`

### Deadline workflow tables
- `student_lesson_deadline_overrides`
- `student_lesson_reasons`

---

# 4. Current Progress Test V2 Logic
## 4.1 Gate to start a test
A progress test should only be available when:
- lesson is unlocked
- summary is acceptable
- lesson is not yet passed/completed
- attempts are available under active policy state

## 4.2 Test grading model
Current V2 supports:
- `yesno`
- `mcq`
- `open`

Open questions use AI grading with schema enforcement plus alias/key point safeguards.

## 4.3 Formal result classification
Finalization classifies a test using:
- score percentage
- effective deadline
- attempt number
- pass threshold policy

Important fields in `progress_tests_v2`:
- `timing_status`
- `formal_result_code`
- `formal_result_label`
- `pass_gate_met`
- `counts_as_unsat`
- `remediation_triggered`

## 4.4 Current intended attempt policy
Global policies currently indicate:
- `initial_attempt_limit = 3`
- `extra_attempts_after_threshold_fail = 2`
- `max_total_attempts_without_admin_override = 5`
- threshold remediation email at attempt 3

### Current intended business rule
- Attempts 1–3 are initially available
- After failed attempt 3, student must complete remediation acknowledgement
- Only after remediation acknowledgement should extra attempts become available
- Attempt 4 should be silent (no new email)
- Attempt 5 failure should trigger instructor approval workflow

### Current intended email behavior
- Attempt 3 fail:
  - student gets remediation email
  - chief instructor may be CC’d on that email
- Attempt 4 fail:
  - no new student/instructor escalation email should be sent
- Attempt 5 fail:
  - student gets instructor review email
  - chief instructor gets instructor approval email with secure token link

---

# 5. Current Remediation Logic
## 5.1 Student remediation acknowledgement
Table: `student_required_actions`
Action type:
- `remediation_acknowledgement`

Workflow:
- created after threshold failure (currently attempt 3)
- student opens page via token
- student acknowledges required study
- completion is timestamped and logged
- completion unlocks extra retakes

## 5.2 Current state of remediation unlock logic
The student course page has been modified so that:
- by default only initial attempts are available
- extra attempts should become available only if a completed remediation acknowledgement exists for that lesson/user/cohort

This logic is essential and must remain aligned with `student_required_actions` status.

---

# 6. Current Instructor Approval Logic
## 6.1 Current table usage
Table: `student_required_actions`
Action type:
- `instructor_approval`

Triggered when:
- student reaches max allowed attempts without passing (currently attempt 5 failure)

## 6.2 Current implemented page
- `instructor/instructor_approval.php`

Access:
- admin or chief instructor user

Current behavior:
- token opens page
- action is marked opened
- instructor can approve further progression
- approval is logged

## 6.3 Important current limitation
Instructor approval currently only records approval.
It does **not yet** fully decide:
- how many extra retakes are granted
- whether summary revision is mandatory
- whether a one-on-one session is required
- whether training is suspended
- how granted retakes are represented structurally in policy/state

This is a major next implementation block.

---

# 7. Future Instructor Intervention Policy (Agreed Direction)
## 7.1 Agreed policy direction
Instructor intervention after failed progress test escalation must become policy-driven and auditable.

Instructor decision should eventually support:
### Option A — Further training allowed with required study changes
Instructor may require student to:
- review study area
- revise summary based on guided feedback
- receive a chosen number of extra retakes

### Option B — One-on-one intervention
Instructor may:
- require one-on-one session
- log that session took place
- choose number of extra retakes

## 7.2 Required system behavior after instructor decision
The system must:
- email the instructor decision to the student
- log all actions and communication
- clearly state retakes granted and remaining retakes
- preserve audit trail of why progression continued

## 7.3 Critical major intervention policy
Agreed rule:
- if a student reaches **3 instructor intervention events related to failed progress test escalation** within active training scope, training must be suspended pending formal review

This must be policy-driven and editable later from admin UI.

Preferred aggregate concept:
- `max_total_major_interventions_before_formal_review`

---

# 8. Deadline Intervention Policy (Manual Basis Already Approved)
## 8.1 Approved manual logic
When a progress test deadline is missed:
1. automatic email to student + chief instructor
2. automatic 48-hour extension
3. if extension missed again:
   - email student + chief instructor
   - written reason required from student
   - chief instructor can manually extend only after review

## 8.2 Current database structures intended for this
- `student_lesson_deadline_overrides`
- `student_lesson_reasons`
- `student_required_actions` with `deadline_reason_submission`
- `training_progression_events`
- `training_progression_emails`

## 8.3 Important architectural note
Deadline interventions and failed-test interventions are separate categories but both are major signals of unsatisfactory training progression.

Agreed direction:
- keep them as separate event classes
- combine them for higher-level formal review / suspension logic

---

# 9. Database Status Assessment
## 9.1 Clearly active and central tables
These are active and architecturally central:
- `progress_tests_v2`
- `progress_test_items_v2`
- `lesson_activity`
- `lesson_summaries`
- `student_required_actions`
- `student_lesson_deadline_overrides`
- `student_lesson_reasons`
- `training_progression_events`
- `training_progression_emails`
- `system_policy_definitions`
- `system_policy_values`
- `system_policy_audit`

## 9.2 Legacy or older-generation tables
Likely legacy / transitional / no longer primary:
- `progress_tests`
- `progress_test_items`
- `deadline_events` (unclear whether still actively used)
- `lesson_summary_reviews` (may be partially used, but `lesson_summaries` currently appears primary for gating)

These should later be classified explicitly as:
- ACTIVE
- LEGACY
- TRANSITIONAL
- UNUSED

---

# 10. Policy System Status
## 10.1 Active editable policies currently present
Examples already in DB:
- `progress_test_pass_pct`
- `initial_attempt_limit`
- `extra_attempts_after_threshold_fail`
- `threshold_attempt_for_remediation_email`
- `max_total_attempts_without_admin_override`
- `deadline_extension_1_hours`
- `deadline_extension_2_hours`
- `allow_first_deadline_extension_automatic`
- `require_reason_after_extension_1_missed`
- `final_extension_requires_ai_reason_approval`
- `multiple_unsat_same_lesson_threshold`
- `multiple_unsat_coursewide_threshold`
- `multiple_unsat_window_days`
- `send_email_after_third_fail`
- `send_email_after_deadline_miss`
- `send_email_after_multiple_unsat`
- `chief_instructor_user_id`

## 10.2 Missing future policy keys likely needed
Future likely additions:
- `max_total_major_interventions_before_formal_review`
- `max_failed_test_instructor_interventions_before_suspension`
- `max_deadline_interventions_before_suspension`
- `default_extra_attempts_after_instructor_approval`
- `max_extra_attempts_per_instructor_decision`
- `require_summary_revision_after_instructor_intervention`
- `allow_one_on_one_intervention_logging`
- `suspend_training_after_formal_review_trigger`

---

# 11. Current Debugging Lessons Learned
## 11.1 Repeated classes of implementation bugs already encountered
- duplicate methods in `courseware_progression_v2.php`
- parse errors from partial edits in large PHP files
- enum mismatches causing SQL truncation warnings
- wrong path for instructor approval URL (`/admin/...` vs `/instructor/...`)
- attempt counter UI not matching business rules
- lesson pass logic relying on obsolete `lesson_activity.status`
- course page showing wrong attempt availability because remediation completion was not tied into display logic

## 11.2 Strong workflow preference
User strongly prefers:
- full drop-in replacements when possible
- exact file path + exact replacement block when small patches are needed
- explicit honesty when logic is not yet fully implemented

---

# 12. Current Immediate Next Implementation Block
## 12.1 Priority order
1. Stabilize SSOT architecture document
2. Audit repo files against DB structures
3. Mark which DB tables are active vs legacy
4. Complete instructor decision workflow
5. Complete deadline cron/periodic enforcement workflow
6. Add policy-driven major intervention suspension logic
7. Build admin policy editor page

## 12.2 Instructor workflow must next evolve into:
- decision form, not just approval button
- decision storage in events and/or dedicated action payload
- retake grant logic
- summary revision requirement option
- one-on-one session logging option
- student notification email
- intervention count accumulation
- formal review trigger when max interventions reached

---

# 13. Rules for Future Chats
When continuing work in a new chat:
1. Treat this document as SSOT
2. Do not invent alternate progression logic
3. Do not reintroduce legacy logic without explicit approval
4. Prefer database-backed policy/state over hardcoded behavior
5. When uncertain, compare code against this SSOT first
6. Always keep progression logic audit-defensible

---

# 14. Known Current Reality Snapshot
As of this SSOT version:
- remediation acknowledgement flow exists and works
- instructor approval page exists and opens correctly in `/instructor/instructor_approval.php`
- attempt display logic was being updated so extra retakes only appear after remediation completion
- instructor approval is currently too simple and needs expansion
- deadline workflow structures exist but full periodic enforcement still needs completion
- intervention aggregation/suspension policy is agreed conceptually but not yet fully implemented

---

# 15. What This Document Is For
This document should be updated whenever any of the following changes:
- progression rule changes
- policy keys added/removed
- table purpose changes
- workflow pages added/changed
- instructor/deadline intervention behavior changes
- lesson/test gating behavior changes

This document is the master architectural memory for future development chats.

---

# 16. Repository Module Inventory Status
## 16.1 Important clarification
The current SSOT was created first around the **training progression / remediation / instructor approval / deadline control** architecture because that was the active debugging area.

It is **not yet a full repository inventory**.

That means the current SSOT does **not yet comprehensively document** all existing modules already present in the repo, especially in:
- `/admin`
- `/assets`
- `/instructor`
- `/student`
- slide import / enrichment / translation / designer workflows

## 16.2 Modules confirmed to exist in repo and must be added into SSOT
### `/admin`
Confirmed from current repo structure/screenshots:
- `backgrounds.php`
- `bulk_enrich.php`
- `courses.php`
- `dashboard.php`
- `import_lab.php`
- `lessons.php`
- `slide_designer.php`
- `slide_edit.php`
- `slide_overlay_editor.php`
- `slide_preview.php`
- `slides.php`
- `templates.php`
- `/admin/api` folder exists

### `/assets`
Confirmed current important asset areas:
- `app.css`
- `/avatars`
- `/bg`
- `/logo`
- `/overlay`
- image assets such as `ipca_bg.jpeg`, `ipca_logo_white.png`, `header.png`, `footer.png`, etc.

### `/instructor`
Confirmed current instructor-side modules:
- `cohort.php`
- `cohorts.php`
- `cohort_students.php`
- `dashboard.php`
- `instructor_approval.php`
- multiple backup files also exist

### `/student`
Confirmed current student-side module set includes:
- `/student/api`
- progress test V2 preparation/finalization/upload endpoints
- summary save/get endpoints
- test audio / ASR / TTS-related endpoints
- older and backup files also exist

## 16.3 Important conclusion
The repo already contains a broader operational system than the current SSOT first captured.

So the next SSOT expansion must explicitly cover:
1. content import pipeline
2. slide creation / enrichment / translation pipeline
3. admin authoring tools
4. instructor cohort management tools
5. student learning delivery tools
6. progression engine integration points with those modules

---

# 17. SSOT Expansion Plan (Next Required Pass)
The SSOT should next be expanded into these architectural blocks:

## A. Content Authoring / Import
- LAB import flow
- screenshot/slides import path
- enrichment/transcription/translation logic
- slide templates and backgrounds

## B. Slide Delivery System
- slide player/render path
- HTML content storage
- overlays, hotspots, mentor video, media assets

## C. Admin System
- course/lesson/slide admin pages
- designer/editor roles and current maturity
- which admin pages are active vs partial vs unfinished

## D. Instructor System
- cohort creation and assignment
- instructor dashboard purpose
- instructor approval workflow
- cohort student management

## E. Student System
- dashboard
- course page
- summary page/APIs
- progress test V2 flow
- remediation flow

## F. Shared Infrastructure
- auth
- bootstrap
- mailer
- OpenAI integration
- Spaces integration
- layout/render helpers

---

# 18. Current Truth Statement
At this moment, the SSOT is accurate for the progression-control subsystem, but it is **not yet complete for the full courseware platform**.

That is why some repo files and folders are not yet represented in the canvas.

This is not because they were ignored permanently; it is because the first pass intentionally captured the subsystem we were actively debugging first.

The next pass must broaden the SSOT from:
- **progression-engine SSOT**

to:
- **full IPCA Courseware platform SSOT**

---

# 19. Confirmed Repository Root Structure
## 19.1 Repo root
Confirmed repo root contains:

### Files
- `.gitattributes`
- `.gitignore`
- `.htaccess`
- `composer.json`
- `composer.lock`
- `Dockerfile`

### Folders
- `.git/`
- `public/`
- `src/`
- `vendor/`

## 19.2 Architectural implication
This is a classic PHP app with:
- `public/` as the web-accessible document root
- `src/` as shared/core application logic
- `vendor/` for Composer dependencies

---

# 20. Confirmed Student Entry and Delivery Flow
## 20.1 Student login landing
After login with a student account, the student lands on:
- `dashboard.php`

Current state:
- functional but not finalized in layout/content
- shows which programs and cohorts the student is enrolled in

## 20.2 Course entry
From dashboard, clicking **Open Course** goes to:
- `/student/course.php?cohort_id=1`
- `/student/course.php?cohort_id=2`
- etc.

Purpose of `student/course.php`:
- course and lesson overview
- deadlines
- lesson progression visibility
- launch point for player and progress tests

## 20.3 Player launch
From `student/course.php`, student can launch the lesson player:
- `/player/slide.php?slide_id=1`

Confirmed player location:
- `public/player/slide.php`

Also present in same area:
- `public/player/api/`
- `public/player/BACKUP/` (user-created backup folder)

## 20.4 Progress test launch
From `student/course.php`, student can launch:
- `student/progress_test_v2.php`

This means the student lesson flow is currently:

1. login
2. dashboard
3. course overview
4. launch slide player and/or progress test

---

# 21. Confirmed Need for Admin-System Expansion Pass
The next architecture pass must document the admin subsystem in detail.

Areas specifically expected based on repo and user description:
- course/program admin management
- cohort setup/management
- lesson management
- slide import / screenshot import
- AI transcription / enrichment / translation workflow
- slide designer/editor/overlay tools
- template/background administration
- instructor-side cohort/admin integration

This admin pass is required because the repo already contains significant authoring and management functionality beyond progression control.

---

# 22. Admin Subsystem — Detailed Current-State Map

## 22.1 `public/admin/dashboard.php`
### Purpose
- first page shown after admin login
- intended to become a high-level operational overview for the training organization

### Current working behavior
- shows counts of courses, lessons, and slides currently in the system

### Intended future role
- organization-wide at-a-glance overview
- should surface urgent / important items requiring human attention

### Current status
- functional but minimal
- not yet a true operational dashboard

---

## 22.2 `public/admin/courses.php`
### Purpose
- manual creation and editing of courses under an existing program
- complements bulk import from `admin/import_lab.php`

### Current working behavior
Admin can create a course by setting:
- program
- course title
- slug (auto-generated)
- sort/order value (currently using values like 10, 20, 30)
- published checkbox
- background selector (`Inherit default` visible)

Below the create area, the page also lists/edit existing courses with:
- course id
- program
- title
- slug
- order
- published
- background
- save button

### Important observations
- all current courses were primarily imported automatically via `admin/import_lab.php`
- there is **no current UI to create a new Program**
- there is **no current UI for Program Versioning** (example: Private 1.0, Private 2.0)
- this is considered urgent by user
- background selector appears to be legacy / likely no longer used
- published checkbox may not currently drive visible behavior and should be verified in code/database
- page currently does **not adequately support viewing/editing courses across other programs** in the way needed

### Current status
- partially functional
- lacks proper program/version management
- needs cleanup and verification of legacy fields

---

## 22.3 `public/admin/lessons.php`
### Purpose
- manual creation and editing of lesson metadata
- mostly used for editing lesson metadata rather than primary bulk creation
- bulk creation is primarily done through `admin/import_lab.php`

### Current working behavior
Fields include:
- Course
- External Lesson ID (example: 10002)
- Title
- Page count
- Order

Editing behavior:
- user selects/edit button in lesson list
- record loads into top form for editing

### Important observations
- no Program / Version selection layer here either
- course selection is directly by course title
- current design is workable but not scalable for multi-version program architecture

### Current status
- functional for metadata maintenance
- structurally incomplete for future versioned program management

---

## 22.4 `public/admin/slides.php`
### Purpose
- primary slide-management overview page
- entry point to review lesson slides and launch the current editor

### Current working behavior
Flow:
1. admin selects course
2. lesson list is filtered accordingly
3. admin clicks `Load Slides`
4. page shows all pages/slides for that lesson

For each slide/page shown:
- left side: original screenshot
- right side: final rendered HTML slide with header/footer branding

Buttons / actions:
- `Designer` opens `slide_overlay_editor.php?slide_id=...`
- `Delete` is actually a soft-delete / disable behavior
- restore re-enables the slide

### Important observations
- this page no longer uses `slide_designer.php`
- this page is connected to the **current active editing philosophy**: screenshot-based slide with canonical overlay/editor data

### Current status
- core active slide admin page
- important and actively used

---

## 22.5 `public/admin/slide_edit.php`
### Purpose
- unclear at present

### Current best understanding
- likely helper/include file used when editing slides
- not believed to be a primary standalone admin workflow page

### Current status
- needs code inspection and classification:
  - helper/include
  - legacy
  - replaceable
  - removable

---

## 22.6 `public/admin/slide_designer.php`
### Purpose
- old designer tool from an earlier slide-generation philosophy

### Historical role
- attempted to build pure HTML slides from OCR-recognized screenshot text
- aimed to recreate slide content without depending on screenshot image background

### Important observations
- user considers this **previous / unused**
- current system no longer uses this as the active slide editor

### Current status
- legacy / likely deprecate candidate

---

## 22.7 `public/admin/slide_overlay_editor.php`
### Purpose
- **current active slide editor**
- launched from `admin/slides.php` via the `Designer` button

### This is the correct editor currently in use
It is the main editor for canonical instructional slide data.

### Current working behavior / managed data
For an existing slide, the editor manages:
- screenshot background image
- hotspot data for clickable video area in player
- canonical English extracted text content
- canonical Spanish translated text content
- narration script in English
- narration script in Spanish
- PHAK references
- ACS references
- save canonical data
- reload

### Canonical meaning of these data blocks
#### English extracted text
- foundational canonical content
- used by AI to generate oral questions for progress tests

#### Spanish translation
- derived from English extracted canonical content

#### Narration scripts (EN / ES)
- more humanized audio-ready version of slide content
- used to generate narration audio in the student player
- intentionally different from raw extraction because raw OCR-style extraction sounds too artificial

#### PHAK references
- FAA Pilot’s Handbook of Aeronautical Knowledge references

#### ACS references
- FAA Airman Certification Standards references
- strategically important for future linkage to formal theory exam prep question banks

### Important observations
- editor does **not create new slides**
- editor is for **existing slide editing only**
- this page is one of the most strategically important admin tools in the platform

### Current status
- active / central / mission-critical

---

## 22.8 `public/admin/slide_preview.php`
### Purpose
- former preview file connected to older editor workflow

### Current status
- user states it is no longer used
- likely legacy / cleanup candidate

---

## 22.9 `public/admin/import_lab.php`
### Purpose
- high-value bulk import tool
- creates structural courseware content from manifest data

### Current working behavior
Bulk import pipeline can create:
- Courses
- Lessons
- Slides

Also detects / sets:
- page count via CDN/assets
- optional AI course-title detection per lab
- optional AI lesson-title detection per lesson

### Inputs / controls
- Program Key (`private`, `instrument`, `commercial`)
- Default Template (legacy concept, likely no longer active in practice)
- AI detect COURSE titles checkbox
- AI detect LESSON titles checkbox
- Bulk lab JSON manifest input area
- single-lab mode import below (currently not used)

### Important observations
- this tool works very well according to user
- intended as a fast ingestion mechanism, not final content-authoring method
- imported content is expected to be progressively improved later
- versioning is not yet implemented here either
- default template concept is legacy from the older pure-HTML slide generation approach

### Current status
- extremely useful active import tool
- should remain in SSOT as core ingestion subsystem

---

## 22.10 `public/admin/bulk_enrich.php`
### Purpose
- bulk canonical enrichment automation for slides
- strategically critical, even if not directly exposed in admin dashboard menu

### Current role
Builds canonical data in bulk for slides, including:
- English extraction
- Spanish translation
- narration
- PHAK references
- ACS references
- auto video hotspot detection

Without this tool, equivalent work would need to be done slide-by-slide.

### Inputs / controls
- Course
- Scope (whole course or single lesson)
- Lesson (if scoped to one lesson)
- Program Key (for video)
- Actions:
  - Extract English
  - Translate Spanish
  - Narration Script
  - PHAK + ACS references
  - Auto Video hotspot detection
  - Skip slides already processed
- Limit (`0 = no limit`)

### Important dependency note
The page references:
- `public/assets/kings_videos_manifest.json`
for auto hotspot behavior.

### Current status
- important automation tool
- should be considered a core admin-side pipeline component
- currently hidden from dashboard menu but operationally valuable

---

## 22.11 `public/admin/backgrounds.php`
### Purpose
- legacy configuration page from older editor system

### Current status
- not used anymore
- likely cleanup/deprecation candidate

---

## 22.12 `public/admin/templates.php`
### Purpose
- legacy template configuration page from older editor system

### Current status
- not used anymore
- likely cleanup/deprecation candidate

---

# 23. Storage Architecture — DigitalOcean Spaces (`ipca-media`)
## 23.1 General
Primary media/storage backend is DigitalOcean Spaces:
- bucket: `ipca-media`

This stores screenshots, videos, and progress-test media.

## 23.2 Background folder
- `ipca-media/bg`
- currently not used

## 23.3 Screenshot image storage
### Private
- `ipca-media/ks_images/private`
- contains lesson folders such as:
  - `lesson_10002/lesson_10002_page_001.png`

### Instrument
- `ipca-media/ks_images/instrument`
- same structure pattern as private

### Commercial
- `ipca-media/ks_images/commercial`
- currently empty / commercial lesson images not yet downloaded

## 23.4 Video storage
### Private
- `ipca-media/ks_videos/private`
- contains lesson folders with files such as:
  - `lesson_10002/page_003_AN00001_vA.mp4`

### Instrument
- `ipca-media/ks_videos/instrument`
- populated

### Commercial
- `ipca-media/ks_videos/commercial`
- currently empty

## 23.5 Progress test storage
### Folder
- `ipca-media/progress_tests_v2/`

### Structure
- subfolders named by progress test id
- example:
  - `ipca-media/progress_tests_v2/11/`

Contents include:
- generated question mp3 files
- intro mp3
- `answers/` subfolder
  - student response recordings such as `q01.webm`

### Progress test processing significance
- student answer recordings are uploaded here
- later transcribed during server-side evaluation/finalization
- this folder is mission-critical for the progress test v2 engine

---

# 24. Admin-System Strategic Observations
## 24.1 Active vs legacy admin split
The admin system currently contains a mixture of:
- active pages still in operational use
- legacy pages from earlier design philosophies
- partially wired configuration pages
- hidden but important automation tools

## 24.2 Active admin core (current reality)
Most important active admin subsystem pages appear to be:
- `admin/dashboard.php`
- `admin/courses.php`
- `admin/lessons.php`
- `admin/slides.php`
- `admin/slide_overlay_editor.php`
- `admin/import_lab.php`
- `admin/bulk_enrich.php`

## 24.3 Legacy / probable cleanup candidates
Likely legacy or deprecation candidates:
- `admin/slide_designer.php`
- `admin/slide_preview.php`
- `admin/backgrounds.php`
- `admin/templates.php`
- possibly `admin/slide_edit.php` pending code review

## 24.4 Known near-term gaps
Important structural gaps explicitly identified by user:
- no Program creation UI
- no Program versioning UI
- no clean version-aware course administration
- incomplete admin dashboard
- incomplete course filtering/management across programs
- no finalized high-level policy/admin UI yet

---

# 25. Immediate Next SSOT Expansion Needed
Next pass should document:
1. instructor subsystem pages and role separation
2. admin menu structure vs hidden utility pages
3. exact file ownership and dependency graph
4. which DB tables are active, partially active, legacy, or orphaned
5. cleanup candidates in code, tables, and folders
6. final canonical workflows for:
   - import
   - enrich
   - edit
   - player delivery
   - summary
   - progress test
   - remediation
   - instructor intervention
   - deadline intervention

---

# 26. Instructor Subsystem — Detailed Current-State Map

## 26.1 Instructor role: current reality vs intended future
### Current reality
The current instructor-side area exists, but the present landing page is not yet the true long-term instructor dashboard.

### Intended future role
The future instructor dashboard should become an at-a-glance operational control panel for instructors, including:
- student progress overview
- immediate action items requiring instructor input
- filtering by instructor role/type:
  - Ground
  - Flight
  - Private
  - Instrument
  - Commercial
  - etc.
- personal/instructor operational data such as:
  - schedule
  - work time log
  - duty/rest
  - financials
  - student training status
  - own license / medical recency / currency / validity reminders

This future dashboard is a significant planned subsystem and is not yet implemented in its final form.

---

## 26.2 `public/instructor/cohorts.php`
### Purpose
- currently acts as the temporary main instructor landing page/dashboard
- main cohort creation and cohort overview page

### Current working behavior
Allows creation of a new cohort by entering:
- Program
  - currently: `private`, `instrument`, `commercial`
- Cohort name
- Start date
- End date
- Time zone

Below this, existing cohorts are shown with columns such as:
- Cohort ID
- Program
- Name
- Start
- End
- Time zone
- `Open` button

When clicking `Open`, user is taken to:
- `/instructor/cohort.php?cohort_id=2`

### Important observations
- this page is functioning as a temporary dashboard
- long-term, cohort management should likely belong primarily to the **admin** role, not instructor

### Current status
- active and operational
- functionally important
- conceptually temporary in dashboard role

---

## 26.3 `public/instructor/cohort.php`
### Purpose
- detailed cohort configuration and management page
- currently one of the most important instructor-side pages

### Current working behavior
This page allows the user to:
1. select which Courses belong to the Cohort’s study objectives
2. view selected courses and expand them
3. inspect lessons and their calculated deadlines
4. manage student membership in the cohort

### Deadline calculation role
A major strength of this page is deadline calculation and recalculation.

User states it appears to use logic based on factors such as:
- word count
- estimated average study time (example: 2h study)
- progress test time allowance (example: 30 min)
- per-day capacity assumptions
- a factor such as 2.5 in the calculation logic

This means the cohort page is not only a grouping page; it is also an important scheduling/deadline planning tool.

### Strategic meaning of a cohort
A cohort is used to group students who must complete:
- the same training course objectives
- the same lessons
- within the same period
- under the same default deadline structure

### Current status
- active
- strategically important
- currently carries both planning and operational deadline responsibilities

---

## 26.4 `public/instructor/cohort_students.php`
### Purpose
- included/helper file used by `instructor/cohort.php`
- manages adding/removing students from a cohort

### Current working behavior
- student can be added by entering email address
- student can be removed from cohort

### Important observations / known issues
- should become selectable from a list instead of raw email entry
- currently allows one student to be enrolled in multiple cohorts
  - user states this should be disabled
- system should instead support **moving** a student from one cohort to another

### Current status
- operational but needs enrollment rule hardening

---

## 26.5 Deadline management policy implications from instructor side
### Current behavior
- deadlines are updated at cohort level
- this is acceptable as default behavior

### Needed future behavior
There must also be support for:
- student-specific deadline updates
- student-specific manual overrides
- automatic handling wherever possible when a student fails to meet:
  - study deadline
  - summary deadline
  - progress test deadline

### Strategic distinction
- cohort-level deadlines = default training structure
- student-level overrides = exception management

This is consistent with existing progression/deadline tables such as:
- `student_lesson_deadline_overrides`
- `student_lesson_reasons`
- `student_required_actions`

---

## 26.6 `public/instructor/instructor_approval.php`
### Purpose
- already known from progression subsystem
- handles instructor approval after escalation at max failed test attempts

### Current status
- active
- already part of progression SSOT
- belongs both to instructor subsystem and progression subsystem

---

## 26.7 Role-boundary decision already emerging
User explicitly states:
- cohort creation should likely move from instructor role to **administrator** role
- cohort deadline editing should likely also move to **administrator** role
- approving student deadline extensions, remedials, and related training interventions remains appropriate for instructors

### Architectural implication
Future role separation should likely be:

#### Admin owns
- program creation
- program versioning
- cohort creation
- course structure configuration
- master deadlines / cohort setup
- content architecture

#### Instructor owns
- approval workflows
- remedial review actions
- training intervention actions
- student progress oversight
- exception handling related to training progress

This role split should later be formalized in the SSOT and UI design.

---

## 26.8 Future Instructor Dashboard Requirements
The instructor dashboard should eventually become a highly efficient “no digging required” operational overview.

Key desired characteristics:
- immediate visibility of students needing attention
- training-progress overview
- action-item driven workflow
- minimal digging/navigation burden
- easy communication on training-related matters with students

Likely dashboard categories in future:
- students behind schedule
- students blocked on summary/test/remediation
- instructor approvals pending
- deadline issues pending
- personal instructor recency/currency alerts
- assigned cohort/course status
- possibly quick communication tools

---

# 27. Current Role Architecture (Emerging)
## 27.1 Student
Uses:
- dashboard
- course page
- player
- progress tests
- remediation pages

## 27.2 Instructor
Currently uses:
- cohorts overview/creation (temporary ownership)
- cohort configuration
- student enrollment in cohort
- instructor approval workflow

## 27.3 Admin
Currently uses:
- course/lesson/slide structure management
- import pipeline
- enrichment pipeline
- canonical slide editing
- broader content architecture

## 27.4 Important future correction
Some functionality currently under instructor should likely be moved to admin:
- cohort creation
- cohort master deadline editing

---

# 28. Cleanup / Refactor Opportunities Now Visible
## 28.1 Naming confusion
Known naming confusion:
- `cohorts.php` = list/create page
- `cohort.php` = detail/config page

This naming is workable but not ideal for long-term maintainability.

## 28.2 Enrollment constraints needed
Need rules preventing:
- same student being actively enrolled in multiple incompatible cohorts

Need support for:
- transfer/move between cohorts

## 28.3 Ownership boundary refactor needed
Instructor vs admin responsibilities should be clarified and then reflected in routing/UI.

---

# 29. Shared Infrastructure / Environment / Working Method

## 29.1 Shared infrastructure files that matter
### `src/layout.php`
Purpose:
- shared layout/render wrapper for pages
- responsible for common page shell behavior and UI consistency
- important infrastructure file that affects both admin/student/instructor-facing pages

Architectural role:
- shared presentation layer helper
- should be treated as a core framework file, not just a utility include

### `src/mailer.php`
Purpose:
- shared email sending layer
- used by progression/email workflows and future communication workflows
- infrastructure dependency for all automated/student/instructor notifications

Architectural role:
- shared communications backend
- must remain aligned with training progression email system and future messaging features

### Important note
These files are part of the platform infrastructure and must be included in the SSOT as shared core files.

---

## 29.2 PHP / MySQL environment constraints
### PHP
Persistent rule:
- user prefers broad compatibility and historically requires conservative PHP support
- much of existing legacy ecosystem has required **PHP 5.3 compatibility or older**

For current IPCA Courseware / active platform work:
- exact deployed version should be verified against current DigitalOcean deployment/runtime
- architecture and code guidance should remain conservative and explicit unless user states otherwise

### MySQL
- platform uses MySQL/MariaDB-style schema and workflow
- current working DB operations are performed manually via TablePlus
- exact server version should be documented from deployment/runtime when confirmed

### Important SSOT rule
Until explicitly updated with a newer confirmed deployment target, recommendations should avoid assuming modern-only PHP/MySQL features without checking against actual project runtime.

---

## 29.3 Development / deployment workflow
Confirmed current working workflow:
1. local editing in **Adobe Dreamweaver**
2. save code locally
3. GitHub commit/push
4. DigitalOcean deployment for each code test
5. database updates manually via **TablePlus on Mac**

This workflow must be respected in all implementation guidance.

### Architectural implication
Advice should favor:
- explicit file-based changes
- deterministic drop-in replacements
- minimal ambiguity
- limited dependence on shell-heavy workflows

---

## 29.4 User implementation preferences (critical)
### Full drop-ins preferred
Persistent user preference:
- prefer **full drop-in file replacements** whenever possible

If only a small edit is needed:
- instructions must be **extremely specific**
- always state:
  - exact file path
  - exact section / location
  - exactly what to replace with what

This is a critical working preference and must be honored.

### Terminal usage preference
Persistent user preference:
- avoid relying heavily on macOS Terminal
- basic/simple terminal usage is acceptable
- terminal-heavy workflows are not preferred

### Practical implication
Prefer:
- code snippets
- full file replacements
- TablePlus SQL
- GitHub/DigitalOcean-friendly manual steps

Avoid defaulting to:
- long shell scripts
- complex CLI pipelines
- terminal-centric debugging unless absolutely necessary

---

# 30. Platform Roadmap / Strategic Integration Goals
## 30.1 Near-future major integration
The existing **Compliance Monitoring system** is intended to be integrated into the platform.

Important characteristics:
- it has its own structure
- it has its own database
- future integration may require data exchange with current IPCA Courseware system

This must be treated as a strategic future platform merge point.

## 30.2 After theory-system stabilization
Once the theory/courseware system is stable, the next major target is:
- **Flight Training Evaluation system**

Planned characteristics:
- Cockpit Voice Recorder integration
- AI evaluation/debriefing
- dashcam-like video recordings
- used for debriefing and safety management
- iPhone app on student/training side
- connected to instructor iPad

## 30.3 After that
Next strategic major system:
- **AI Booking / Training Scheduling system**

Current status:
- already exists in PHP
- layout/functionality are considered strong
- not yet based on the latest stable PHP/MySQL foundation

## 30.4 Canonical-data dependency for scheduling
Before advanced scheduling can be built properly, platform needs **perfect canonical data** for approved EASA and FAA training programs, including:
- flight sessions
- simulator sessions
- briefings
- future Apple Vision Pro sessions
- instructor/device/student compatibility rules
- scheduling constraints and pairing logic

This canonical data is foundational to future scheduling intelligence.

## 30.5 Future scheduling intelligence goals
Scheduling system is expected to support dynamic optimization using factors such as:
- instructor availability/qualification
- student phase/status
- aircraft/simulator/device availability
- lesson/session requirements
- illness/cancellation rescheduling
- weather data from internal weather system and external sources
- maintenance/expiration reminders for airplanes
- fuel station operational data / refuel planning

## 30.6 Broader platform growth areas
Future platform scope also includes:
- financials / student ledger
- deployment/distribution of course keys
- uniform items
- purchases / additional items
- download sections
- communication channels

---

# 31. Current Truth Statement
The SSOT now includes:
- progression subsystem
- repo root structure
- student flow
- admin subsystem
- instructor subsystem
- shared infrastructure files (`layout.php`, `mailer.php`)
- development workflow
- deployment workflow
- user implementation preferences
- strategic roadmap for compliance, flight evaluation, and scheduling integration

## 31.1 Remaining items that still require explicit confirmation later
The following should still be explicitly confirmed in future SSOT passes if needed:
- exact currently deployed PHP version
- exact currently deployed MySQL version
- exact mail transport/provider configuration details beyond current shared mail layer
- exact DigitalOcean deployment mechanism details if they become operationally important

## 31.2 Working rule until then
Until explicitly redefined, future work should assume:
- conservative compatibility
- Dreamweaver + GitHub + DigitalOcean workflow
- TablePlus for DB changes
- full drop-ins preferred
- minimal terminal dependence
- architecture must remain compatible with future compliance + evaluation + scheduling platform expansion

