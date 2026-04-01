# Theory Training Policies

This document describes the operational rules governing theory training progression.

Policies are enforced by the Courseware Progression Engine.

---

# Summary Requirement Policy

Students must create a lesson summary before starting a progress test.

Requirements:

• minimum character length  
• instructor review  
• acceptable review score  

Test start gate requires:

lesson_summaries.review_status = acceptable

---

# Progress Test Pass Policy

Default pass score:

75%

Policy key:

progress_test_pass_pct

---

# Attempt Policy

Initial attempts allowed:

3

After third failure:

Student must acknowledge remediation instructions.

After remediation acknowledgement:

Attempts 4 and 5 become available.

Maximum attempts before instructor intervention:

5

Policy keys:

initial_attempt_limit  
extra_attempts_after_threshold_fail  
max_total_attempts_without_admin_override  

---

# Remediation Policy

After repeated failures:

System generates remediation actions including:

• AI feedback  
• weak area identification  
• remedial study acknowledgement  

Remediation actions are stored in:

student_required_actions

---

# Instructor Intervention Policy

After maximum attempts are reached, instructor intervention is required.

Instructor decisions include:

approve_additional_attempts  
approve_with_summary_revision  
approve_with_one_on_one  
suspend_training  

Instructor decision data is stored in:

student_required_actions

Instructor actions update:

lesson_activity

---

# Summary Revision Policy

If instructor requires summary revision:

lesson_summaries.review_status becomes:

needs_revision

Student edits summary.

Autosave converts status to:

pending

Instructor re-reviews summary.

---

# Deadline Policy

Deadlines are calculated based on:

reading speed assumption  
study factor multiplier  
progress test time allocation  
maximum study minutes per day  

Automatic deadline extensions:

First extension:

48 hours

Second extension:

requires reason submission

Manual override allowed by instructor/admin.

Deadline overrides stored in:

student_lesson_deadline_overrides

---

# Instructor Intervention Threshold Policy

If a student accumulates repeated instructor interventions:

Training may be suspended pending formal review.

Intervention classification stored using:

major_intervention_flag

Future policy:

max_total_major_interventions_before_formal_review

---

# Notification Policy

System generates notifications for:

• remediation triggers  
• instructor intervention  
• summary revision requests  
• summary approval  

Notifications stored in:

training_progression_emails

Future system will centralize templates and enable/disable settings.

---

# Gate Hierarchy

Progression gates may include:

• summary gate  
• attempt gate  
• deadline gate  
• instructor session gate  
• suspension gate  

A student must pass all gates to progress.

---

# Audit Evidence Policy

All workflow events must generate audit records.

Audit tables:

training_progression_events  
training_progression_emails  
student_required_actions  
progress_tests_v2  
lesson_summary_reviews  

The system must always preserve regulator-safe training evidence.