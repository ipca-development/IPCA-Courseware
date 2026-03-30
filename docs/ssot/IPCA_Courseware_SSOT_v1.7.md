# IPCA Courseware SSOT v1.7

This document preserves SSOT v1.6 and adds the latest canonical decisions from the recent progression-finalization centralization work and internal AI console direction.

SSOT v1.7 becomes the current authoritative SSOT.

---

# 1. Status of Prior SSOT

SSOT v1.6 remains historically valid and is preserved unchanged.

Its established rules remain authoritative unless explicitly refined here, including:

- progression state machine ownership
- lesson_activity as operational state layer
- audit/history + operational update dual-write principle
- centralized navigation architecture
- structured instructor intervention logic

---

# 2. Assessed Progress Test Finalization Rule

A distinction now exists between:

1. assessment generation / grading work
2. canonical progression consequence handling

Assessment-side responsibilities may include:

- item grading
- transcript extraction
- TTS/debrief generation
- written debrief generation
- weak area extraction
- summary-quality observations

But progression-side responsibilities must remain owned by the central engine.

Canonical progression consequences must be owned by:

`src/courseware_progression_v2.php`

This includes:

- result classification
- pass gate evaluation
- counts_as_unsat determination
- required action creation/reuse
- notification queue decisions
- lesson_activity projection updates
- event log creation
- final progression consequence persistence

---

# 3. Canonical Engine Entry Point for Assessed Tests

For already-assessed progress tests, the canonical orchestration entry point is:

`CoursewareProgressionV2::finalizeAssessedProgressTest()`

Purpose:

This method accepts finalized assessment artifacts and then derives all progression consequences from canonical state, policies, and current operational truth.

The controller must not independently recreate this logic.

---

# 4. test_finalize_v2 Controller Rule

File:

`public/student/api/test_finalize_v2.php`

This file may perform:

- access validation
- ownership validation
- audio download/transcription
- item grading
- debrief generation
- result-audio generation/upload
- final JSON response formatting

This file must not be the canonical owner of:

- required action creation policy
- remediation escalation policy
- instructor escalation policy
- progression email queue decision policy
- lesson_activity canonical consequence logic
- final progression classification authority

Those belong to the engine.

---

# 5. Immediate Email Dispatch Rule

Queued progression emails may be sent immediately after finalization, but only after the progression transaction is fully committed.

Therefore:

- queue creation belongs inside the canonical engine-controlled transaction
- delivery attempts occur after commit
- email delivery failure must not roll back progression truth

This preserves auditability and prevents transport failures from corrupting training state.

---

# 6. Required Action Authority Rule

The canonical workflow authority remains:

`student_required_actions`

Creation, reuse, approval-state interpretation, and instructor/remediation consequence logic must remain centrally governed.

Controllers may display actions and submit decisions, but must not become the workflow authority.

---

# 7. lesson_activity Projection Rule

`lesson_activity` remains the canonical operational state projection table for UI gates and dashboards.

Progression finalization must update lesson_activity through canonical engine-owned projection logic, not ad hoc controller SQL.

The controller may read the resulting state for response payloads, but must not author the final state contract independently.

---

# 8. Audit / Operational Split Clarification

The following split is now explicit:

## Assessment Artifacts
Examples:
- score_pct
- ai_summary
- weak_areas
- debrief_spoken
- summary_quality
- summary_issues
- summary_corrections
- confirmed_misunderstandings

These may be computed before engine finalization.

## Progression Consequences
Examples:
- timing_status
- pass_gate_met
- counts_as_unsat
- formal_result_code
- formal_result_label
- remediation_triggered
- instructor_escalation_triggered
- required action creation
- queued email ids
- lesson_activity state changes
- event log entries

These must be engine-derived.

---

# 9. Thin Controller Rule Expanded

Thin-controller architecture now explicitly applies to:

- `public/student/api/test_prepare_start_v2.php`
- `public/student/api/test_finalize_v2.php`
- `public/instructor/instructor_approval.php`
- `public/instructor/summary_review.php`

These files may validate, collect inputs, call services, and format outputs.

They must not become parallel business-rule engines.

---

# 10. Notification Lane Clarification

`training_progression_emails` remains the authoritative progression email queue/history table.

Notification rendering and queueing may use saved templates, but final progression notification decisions must remain consistent with CoursewareProgressionV2 policy/state logic.

Email transport is output, not state truth.

---

# 11. DB-Backed SSOT Rule

The SSOT must now also live in the database, not only in markdown files.

Required outcome:

- versioned SSOT documents stored in DB
- current SSOT marker stored in DB
- structured SSOT rules stored in DB
- structured file/table ownership notes stored in DB
- decision log stored in DB

Older SSOT versions must be preserved and never overwritten.

---

# 12. Internal AI Console Direction

A private internal admin AI console is approved as a controlled productivity tool.

Initial V1 goals:

- private access only
- read SSOT from DB
- read project files
- inspect DB schema read-only
- store generated code artifacts
- support declutter analysis for files/tables
- keep the user in full manual control of:
  - editing files
  - executing SQL writes
  - deployment

The AI console must not auto-deploy, auto-write project files, or auto-run live migrations.

---

# 13. Declutter / Inventory Direction

The platform now explicitly supports a declutter/inventory layer to identify:

- unused files
- drifted files
- candidate archive files
- candidate delete files
- unused tables
- tables not mapped into SSOT
- code/schema drift candidates

This analysis must be advisory only unless the user manually acts.

---

# 14. Manual-Control Development Rule

The user remains final authority for:

- code insertion into editor
- SQL write execution
- production deployment

AI tooling may inspect, compare, analyze, and generate artifacts, but must not take final write/deploy actions automatically.

---

# 15. Versioning Rule

SSOT versions must continue to be preserved as immutable snapshots.

Recommended current progression:

- SSOT v1.6 preserved
- SSOT v1.7 current
- future updates produce v1.8, v1.9, etc.
- no overwrite-in-place of prior authoritative versions

---

END SSOT v1.7