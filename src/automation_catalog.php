<?php
declare(strict_types=1);

function automation_event_options(): array
{
    return array(
        'progress_test_completed'        => 'Progress Test Completed',
        'progress_test_failed'           => 'Progress Test Failed',
        'progress_test_passed'           => 'Progress Test Passed',
        'progress_test_deadline_missed'  => 'Progress Test Deadline Missed',
        'final_deadline_missed'          => 'Final Deadline Missed',
        'summary_checked'                => 'Summary Checked',
        'summary_accepted'               => 'Summary Accepted',
        'summary_needs_revision'         => 'Summary Needs Revision',
        'instructor_decision_recorded'   => 'Instructor Decision Recorded',
        'password_reset_requested'       => 'Password Reset Requested',
        'user_registered_public'         => 'Public Registration Received',
        'user_created_admin'             => 'User Created by Admin'
    );
}

function automation_condition_field_options(): array
{
    return array(
        'attempt_count'   => 'Attempt Count',
        'score_pct'       => 'Score (%)',
        'result_code'     => 'Result Type',
        'deadline_status' => 'Deadline Status',
        'summary_status'  => 'Summary Status',
        'decision_code'   => 'Instructor Decision',
        'user_role'       => 'User Role'
    );
}

function automation_condition_operator_options(): array
{
    return array(
        'equals'           => 'Equals',
        'not_equals'       => 'Not Equals',
        'greater_or_equal' => 'Greater or Equal',
        'less_or_equal'    => 'Less or Equal',
        'greater_than'     => 'Greater Than',
        'less_than'        => 'Less Than',
        'contains'         => 'Contains'
    );
}

function automation_action_options(): array
{
    return array(
        'send_notification'        => 'Send Email Notification',
        'grant_extra_attempts'     => 'Grant Extra Attempts',
        'create_required_action'   => 'Create Required Student Action',
        'apply_deadline_extension' => 'Apply Deadline Extension',
        'log_only'                 => 'Log Only (No Action)'
    );
}

function automation_numeric_fields(): array
{
    return array(
        'attempt_count',
        'score_pct'
    );
}

function automation_condition_field_aliases(): array
{
    return array(
        'attempt'                => 'attempt_count',
        'attempts'               => 'attempt_count',
        'attempt_number'         => 'attempt_count',
        'total_attempts'         => 'attempt_count',

        'score'                  => 'score_pct',
        'score_percent'          => 'score_pct',
        'score_percentage'       => 'score_pct',
        'percentage_score'       => 'score_pct',

        'result'                 => 'result_code',
        'result_type'            => 'result_code',
        'formal_result'          => 'result_code',

        'deadline'               => 'deadline_status',
        'timing_status'          => 'deadline_status',
        'timing'                 => 'deadline_status',

        'review_status'          => 'summary_status',
        'summary_review_status'  => 'summary_status',

        'decision'               => 'decision_code',
        'instructor_decision'    => 'decision_code',

        'role'                   => 'user_role'
    );
}

function automation_condition_operator_aliases(): array
{
    return array(
        '='                      => 'equals',
        '=='                     => 'equals',
        'eq'                     => 'equals',
        'equal'                  => 'equals',
        'equals'                 => 'equals',
        'is'                     => 'equals',

        '!='                     => 'not_equals',
        '<>'                     => 'not_equals',
        'neq'                    => 'not_equals',
        'not_equal'              => 'not_equals',
        'not_equals'             => 'not_equals',
        'is_not'                 => 'not_equals',

        '>'                      => 'greater_than',
        'gt'                     => 'greater_than',
        'greater_than'           => 'greater_than',

        '>='                     => 'greater_or_equal',
        'gte'                    => 'greater_or_equal',
        'greater_or_equal'       => 'greater_or_equal',
        'greater_than_or_equal'  => 'greater_or_equal',

        '<'                      => 'less_than',
        'lt'                     => 'less_than',
        'less_than'              => 'less_than',

        '<='                     => 'less_or_equal',
        'lte'                    => 'less_or_equal',
        'less_or_equal'          => 'less_or_equal',
        'less_than_or_equal'     => 'less_or_equal',

        'contains'               => 'contains',
        'in'                     => 'contains'
    );
}

function automation_action_aliases(): array
{
    return array(
        'send_notification'       => 'send_notification',
        'send_email'              => 'send_notification',
        'notification'            => 'send_notification',
        'notify'                  => 'send_notification',
        'email'                   => 'send_notification',

        'grant_extra_attempts'    => 'grant_extra_attempts',
        'extra_attempts'          => 'grant_extra_attempts',
        'add_attempts'            => 'grant_extra_attempts',

        'create_required_action'  => 'create_required_action',
        'required_action'         => 'create_required_action',
        'create_action'           => 'create_required_action',

        'apply_deadline_extension'   => 'apply_deadline_extension',
        'grant_deadline_extension'   => 'apply_deadline_extension',
        'deadline_extension'         => 'apply_deadline_extension',
        'extend_deadline'            => 'apply_deadline_extension',

        'log_only'                => 'log_only',
        'log'                     => 'log_only'
    );
}

function automation_normalize_key(string $value): string
{
    $value = trim(strtolower($value));
    if ($value === '') {
        return '';
    }

    $value = str_replace('&', 'and', $value);
    $value = preg_replace('/[^a-z0-9]+/', '_', $value);
    $value = preg_replace('/_+/', '_', (string)$value);
    $value = trim((string)$value, '_');

    return $value;
}

function automation_build_option_lookup(array $options): array
{
    $lookup = array();

    foreach ($options as $key => $label) {
        $lookup[automation_normalize_key((string)$key)] = (string)$key;
        $lookup[automation_normalize_key((string)$label)] = (string)$key;
    }

    return $lookup;
}

function automation_resolve_condition_field_key(?string $raw): string
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return '';
    }

    $options = automation_condition_field_options();
    if (isset($options[$raw])) {
        return $raw;
    }

    $normalized = automation_normalize_key($raw);
    $aliases = automation_condition_field_aliases();

    if (isset($aliases[$normalized]) && isset($options[$aliases[$normalized]])) {
        return $aliases[$normalized];
    }

    $lookup = automation_build_option_lookup($options);
    if (isset($lookup[$normalized])) {
        return $lookup[$normalized];
    }

    return $raw;
}

function automation_resolve_condition_operator_key(?string $raw): string
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return '';
    }

    $options = automation_condition_operator_options();
    if (isset($options[$raw])) {
        return $raw;
    }

    $normalized = automation_normalize_key($raw);
    $aliases = automation_condition_operator_aliases();

    if (isset($aliases[$normalized]) && isset($options[$aliases[$normalized]])) {
        return $aliases[$normalized];
    }

    $lookup = automation_build_option_lookup($options);
    if (isset($lookup[$normalized])) {
        return $lookup[$normalized];
    }

    return $raw;
}

function automation_resolve_action_key(?string $raw): string
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return '';
    }

    $options = automation_action_options();
    if (isset($options[$raw])) {
        return $raw;
    }

    $normalized = automation_normalize_key($raw);
    $aliases = automation_action_aliases();

    if (isset($aliases[$normalized]) && isset($options[$aliases[$normalized]])) {
        return $aliases[$normalized];
    }

    $lookup = automation_build_option_lookup($options);
    if (isset($lookup[$normalized])) {
        return $lookup[$normalized];
    }

    return $raw;
}

function automation_load_notification_templates(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT
            notification_key,
            name,
            is_enabled
        FROM notification_templates
        ORDER BY
            is_enabled DESC,
            name ASC,
            notification_key ASC
    ");

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = array();

    foreach ($rows as $row) {
        $key = trim((string)($row['notification_key'] ?? ''));
        if ($key === '') {
            continue;
        }

        $label = trim((string)($row['name'] ?? ''));
        if ($label === '') {
            $label = $key;
        }

        $enabled = (int)($row['is_enabled'] ?? 0) === 1;
        $suffix = $enabled ? '' : ' [disabled]';

        $out[$key] = $label . ' (' . $key . ')' . $suffix;
    }

    return $out;
}