<?php
declare(strict_types=1);

function theory_control_policy_keys(): array
{
    return array(
        'progress_test_pass_pct',
        'summary_required_before_test_start',
        'summary_min_characters',
        'summary_acceptance_min_score',
        'initial_attempt_limit',
        'extra_attempts_after_threshold_fail',
        'threshold_attempt_for_remediation_email',
        'max_total_attempts_without_admin_override',
        'deadline_extension_1_hours',
        'deadline_extension_2_hours',
        'allow_first_deadline_extension_automatic',
        'require_reason_after_extension_1_missed',
        'final_extension_requires_ai_reason_approval',
        'multiple_unsat_same_lesson_threshold',
        'multiple_unsat_coursewide_threshold',
        'multiple_unsat_window_days',
        'send_email_after_third_fail',
        'send_email_after_deadline_miss',
        'send_email_after_multiple_unsat',
        'late_pass_counts_as_valid_if_within_effective_deadline',
        'chief_instructor_user_id',
    );
}

function theory_control_notification_keys(): array
{
    return array(
        'third_fail_remediation',
        'instructor_approval_required',
        'instructor_approval_required_chief',
        'instructor_approval_decision_student',
        'instructor_approval_decision_chief',
        'summary_needs_revision',
        'summary_approved',
        'deadline_missed_reason_required',
        'deadline_extension_granted',
        'deadline_final_warning',
        'deadline_instructor_intervention_required',
        'progress_test_passed',
        'progress_test_failed',
    );
}

function theory_control_event_keys(): array
{
    return array(
        'summary_reviewed',
        'progress_test_failed',
        'progress_test_passed',
        'lesson_deadline_missed',
        'deadline_extension_granted',
        'deadline_extension_missed',
        'required_action_completed',
        'instructor_decision_recorded',
        'one_on_one_completed',
        'deadline_approaching_48h',
        'deadline_today',
        'deadline_passed',
    );
}

function theory_control_filter_rows_by_notification_keys(array $rows): array
{
    $allowed = array_fill_keys(theory_control_notification_keys(), true);

    return array_values(array_filter($rows, function ($row) use ($allowed) {
        $key = trim((string)($row['notification_key'] ?? ''));
        return isset($allowed[$key]);
    }));
}

function theory_control_is_notification_key(string $notificationKey): bool
{
    static $map = null;

    if ($map === null) {
        $map = array_fill_keys(theory_control_notification_keys(), true);
    }

    return isset($map[trim($notificationKey)]);
}

function theory_control_is_policy_key(string $policyKey): bool
{
    static $map = null;

    if ($map === null) {
        $map = array_fill_keys(theory_control_policy_keys(), true);
    }

    return isset($map[trim($policyKey)]);
}

function theory_control_is_event_key(string $eventKey): bool
{
    static $map = null;

    if ($map === null) {
        $map = array_fill_keys(theory_control_event_keys(), true);
    }

    return isset($map[trim($eventKey)]);
}