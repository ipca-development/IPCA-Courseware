<?php
declare(strict_types=1);

function mo_remote_auth_email_html(): string
{
    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0; padding:0; width:100%; background-color:#f3f6fb;"><tr><td align="center" style="padding:24px 12px;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:600px; margin:0 auto;"><tr><td style="padding:0;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%; background:linear-gradient(180deg,#122b4a 0%,#1a3a63 100%); border-radius:18px 18px 0 0;"><tr><td align="center" style="padding:28px 24px;"><img src="https://ipca.training/assets/logo/ipca_logo_white.png" alt="IPCA" style="display:block; width:150px; max-width:100%; height:auto;"></td></tr></table><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%; background-color:#ffffff; border-left:1px solid #e5e7eb; border-right:1px solid #e5e7eb; border-bottom:1px solid #e5e7eb; border-radius:0 0 18px 18px;"><tr><td style="padding:32px 28px; font-family:Arial,Helvetica,sans-serif; color:#1f2937;"><div style="font-size:22px; font-weight:700; color:#111827; margin-bottom:18px;">Mock Oral Exam Authentication</div><div style="font-size:15px; line-height:24px; color:#374151; margin-bottom:22px;">Dear {{student_name}},<br><br>You requested mock oral exam authentication for <strong>{{area_title}}</strong>.<br><br>Use the secure link below to verify your identity with a live photo and your account password. You will receive a Mock Oral Code to enter on the mock oral page before your session is prepared.<br><br>This link expires at <strong>{{expires_at}}</strong>.</div><table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="background:#1a3a63; border-radius:10px;"><a href="{{auth_link}}" style="display:inline-block; padding:14px 22px; font-family:Arial,Helvetica,sans-serif; font-size:14px; font-weight:700; color:#ffffff; text-decoration:none;">Open Authentication Page</a></td></tr></table><div style="margin-top:22px; font-size:14px; line-height:22px; color:#6b7280;">If you did not request this email, contact <a href="mailto:{{support_email}}" style="color:#1a3a63; text-decoration:none;">{{support_email}}</a>.</div><div style="margin-top:28px; font-size:15px; line-height:24px; color:#374151;">Best regards,<br><strong style="color:#111827;">Kay Vereeken</strong><br>Head of Training</div></td></tr></table></td></tr></table></td></tr></table>';
}

function mo_remote_auth_email_text(): string
{
    return "Dear {{student_name}},\n\nYou requested mock oral exam authentication for {{area_title}}.\n\nOpen your authentication page: {{auth_link}}\n\nThis link expires at {{expires_at}}.\n\nIf you did not request this email, contact {{support_email}}.\n\nBest regards,\nKay Vereeken\nHead of Training\n";
}

function mo_remote_auth_allowed_variables_json(): string
{
    return json_encode([
        ['name' => 'student_name', 'label' => 'Student name', 'type' => 'text', 'safe_mode' => 'escaped', 'required' => true, 'sample_value' => 'John Smith', 'description' => ''],
        ['name' => 'area_title', 'label' => 'ACS area', 'type' => 'text', 'safe_mode' => 'escaped', 'required' => true, 'sample_value' => 'Pilot Qualifications', 'description' => ''],
        ['name' => 'auth_link', 'label' => 'Authentication link', 'type' => 'text', 'safe_mode' => 'escaped', 'required' => true, 'sample_value' => 'https://ipca.training/student/mock_oral_auth.php?token=example', 'description' => ''],
        ['name' => 'expires_at', 'label' => 'Link expiry', 'type' => 'text', 'safe_mode' => 'escaped', 'required' => true, 'sample_value' => '2026-06-03 22:00 UTC', 'description' => ''],
        ['name' => 'support_email', 'label' => 'Support email', 'type' => 'text', 'safe_mode' => 'escaped', 'required' => false, 'sample_value' => 'support@ipca.training', 'description' => ''],
        ['name' => 'student_email', 'label' => 'Student email', 'type' => 'text', 'safe_mode' => 'escaped', 'required' => false, 'sample_value' => 'student@example.com', 'description' => ''],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
}

function mo_template_variables_are_canonical(string $json): bool
{
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return false;
    }
    foreach ($decoded as $row) {
        if (is_array($row) && trim((string)($row['name'] ?? '')) !== '') {
            return true;
        }
    }
    return false;
}

function mo_ensure_remote_email_automation(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $notificationKey = 'mock_oral_auth_request';
    $subject = 'Your IPCA Mock Oral Exam Authentication Link';
    $html = mo_remote_auth_email_html();
    $text = mo_remote_auth_email_text();
    $allowedVars = mo_remote_auth_allowed_variables_json();

    $st = $pdo->prepare("SELECT id FROM notification_templates WHERE notification_key = ? AND channel = 'email' LIMIT 1");
    $st->execute([$notificationKey]);
    $templateId = (int)$st->fetchColumn();

    if ($templateId <= 0) {
        $ins = $pdo->prepare("
            INSERT INTO notification_templates
              (notification_key, channel, name, description, is_enabled, subject_template, html_template, text_template, allowed_variables_json, created_at, updated_at)
            VALUES (?, 'email', 'Mock Oral Exam Authentication', 'Email with secure link for mock oral exam authentication.', 1, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
        ");
        $ins->execute([$notificationKey, $subject, $html, $text, $allowedVars]);
        $templateId = (int)$pdo->lastInsertId();
    } else {
        $pdo->prepare("
            UPDATE notification_templates
            SET html_template = ?, text_template = ?, subject_template = ?, allowed_variables_json = ?, is_enabled = 1, updated_at = UTC_TIMESTAMP()
            WHERE id = ?
        ")->execute([$html, $text, $subject, $allowedVars, $templateId]);
    }

    $latestVerSt = $pdo->prepare("
        SELECT id, allowed_variables_json, version_no
        FROM notification_template_versions
        WHERE notification_template_id = ?
        ORDER BY version_no DESC, id DESC
        LIMIT 1
    ");
    $latestVerSt->execute([$templateId]);
    $latestVersion = $latestVerSt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$latestVersion) {
        $pdo->prepare("
            INSERT INTO notification_template_versions
              (notification_template_id, version_no, notification_key, subject_template, html_template, text_template, allowed_variables_json, changed_by_user_id, change_note, created_at)
            VALUES (?, 1, ?, ?, ?, ?, ?, NULL, 'Bootstrap: mock oral auth email', UTC_TIMESTAMP())
        ")->execute([$templateId, $notificationKey, $subject, $html, $text, $allowedVars]);
    } elseif (!mo_template_variables_are_canonical((string)($latestVersion['allowed_variables_json'] ?? ''))) {
        $nextVersion = ((int)($latestVersion['version_no'] ?? 0)) + 1;
        $pdo->prepare("
            INSERT INTO notification_template_versions
              (notification_template_id, version_no, notification_key, subject_template, html_template, text_template, allowed_variables_json, changed_by_user_id, change_note, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NULL, 'Bootstrap: fix mock oral auth template variables', UTC_TIMESTAMP())
        ")->execute([$templateId, $nextVersion, $notificationKey, $subject, $html, $text, $allowedVars]);
    }

    $flowSt = $pdo->prepare("SELECT id FROM automation_flows WHERE event_key = 'mock_oral_auth_requested' LIMIT 1");
    $flowSt->execute();
    $flowId = (int)$flowSt->fetchColumn();
    if ($flowId <= 0) {
        $pdo->prepare("
            INSERT INTO automation_flows (name, description, event_key, is_active, priority, created_at, updated_at)
            VALUES ('Theory — Mock oral auth email', 'send_email → mock_oral_auth_request', 'mock_oral_auth_requested', 1, 10, UTC_TIMESTAMP(), UTC_TIMESTAMP())
        ")->execute();
        $flowId = (int)$pdo->lastInsertId();
    } else {
        $pdo->prepare('UPDATE automation_flows SET is_active = 1, updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$flowId]);
    }

    if ($flowId > 0) {
        $actSt = $pdo->prepare('SELECT COUNT(*) FROM automation_flow_actions WHERE flow_id = ? AND action_key = ?');
        $actSt->execute([$flowId, 'send_email']);
        if ((int)$actSt->fetchColumn() === 0) {
            $pdo->prepare("
                INSERT INTO automation_flow_actions (flow_id, action_key, config_json, sort_order)
                VALUES (?, 'send_email', ?, 10)
            ")->execute([
                $flowId,
                '{"notification_key":"mock_oral_auth_request","to_email":"{{student_email}}","to_name":"{{student_name}}"}',
            ]);
        }
    }
}

function mo_automation_email_sent(?array $automationResult): bool
{
    if (!$automationResult || empty($automationResult['ok'])) {
        return false;
    }
    foreach ((array)($automationResult['results'] ?? []) as $row) {
        if (!is_array($row) || ($row['action_key'] ?? '') !== 'send_email') {
            continue;
        }
        if (empty($row['ok']) || !empty($row['skipped'])) {
            continue;
        }
        $send = (array)($row['result'] ?? []);
        if (!empty($send['ok']) && empty($send['suppressed'])) {
            return true;
        }
    }
    return false;
}

function mo_automation_email_failure_reason(?array $automationResult): string
{
    if (!$automationResult) {
        return 'Automation dispatch returned no result.';
    }
    if ((int)($automationResult['matched_flows'] ?? 0) === 0) {
        return 'No active automation flow matched event mock_oral_auth_requested.';
    }

    $errors = [];
    foreach ((array)($automationResult['results'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (!empty($row['error'])) {
            $errors[] = (string)$row['error'];
            continue;
        }
        if (($row['action_key'] ?? '') === 'send_email' && !empty($row['skipped'])) {
            $errors[] = 'send_email skipped: ' . (string)($row['reason'] ?? 'unknown');
            continue;
        }
        $send = (array)($row['result'] ?? []);
        if (($row['action_key'] ?? '') === 'send_email' && empty($send['ok']) && !empty($send['error'])) {
            $errors[] = (string)$send['error'];
        }
    }

    if ($errors) {
        return implode(' ', array_unique($errors));
    }

    return 'Authentication email action did not complete successfully.';
}
