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
        '='  => 'Equals',
        '!=' => 'Not Equals',
        '>=' => 'Greater or Equal',
        '<=' => 'Less or Equal',
        '>'  => 'Greater Than',
        '<'  => 'Less Than'
    );
}

function automation_action_options(): array
{
    return array(
        'send_notification'      => 'Send Email Notification',
        'grant_extra_attempts'   => 'Grant Extra Attempts',
        'create_required_action' => 'Create Required Student Action',
        'apply_deadline_extension' => 'Apply Deadline Extension',
        'log_only'               => 'Log Only (No Action)'
    );
}

function automation_numeric_fields(): array
{
    return array(
        'attempt_count',
        'score_pct'
    );
}

function automation_load_notification_templates(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT notification_key, name
        FROM notification_templates
        ORDER BY name ASC
    ");

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = array();

    foreach ($rows as $row) {
        $key = (string)$row['notification_key'];
        $label = trim((string)$row['name']);
        if ($label === '') {
            $label = $key;
        }
        $out[$key] = $label . ' (' . $key . ')';
    }

    return $out;
}