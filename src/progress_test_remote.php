<?php
declare(strict_types=1);

require_once __DIR__ . '/progress_test_access.php';

const PTR_AUTH_TTL_MINUTES = 60;
const PTR_MAX_REQUESTS_PER_HOUR = 5;
const PTR_MAX_CODE_FAILURES = 5;
const PTR_ACTIVE_ATTEMPT_MINUTES = 15;

function ptr_ensure_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $migration = dirname(__DIR__) . '/scripts/sql/2026_05_26_progress_test_remote_authorization.sql';
    if (!is_readable($migration)) {
        return;
    }
    $sql = (string)file_get_contents($migration);
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql) ?: [])) as $stmt) {
        if ($stmt === '' || stripos($stmt, 'SET @') === 0) {
            continue;
        }
        try {
            $pdo->exec($stmt);
        } catch (Throwable $e) {
            // idempotent
        }
    }

    ptr_ensure_remote_email_automation($pdo);
}

function ptr_remote_auth_email_html(): string
{
    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0; padding:0; width:100%; background-color:#f3f6fb;"><tr><td align="center" style="padding:24px 12px;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:600px; margin:0 auto;"><tr><td style="padding:0;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%; background:linear-gradient(180deg,#122b4a 0%,#1a3a63 100%); border-radius:18px 18px 0 0;"><tr><td align="center" style="padding:28px 24px;"><img src="https://ipca.training/assets/logo/ipca_logo_white.png" alt="IPCA" style="display:block; width:150px; max-width:100%; height:auto;"></td></tr></table><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%; background-color:#ffffff; border-left:1px solid #e5e7eb; border-right:1px solid #e5e7eb; border-bottom:1px solid #e5e7eb; border-radius:0 0 18px 18px;"><tr><td style="padding:32px 28px; font-family:Arial,Helvetica,sans-serif; color:#1f2937;"><div style="font-size:22px; font-weight:700; color:#111827; margin-bottom:18px;">Remote Progress Test Authentication</div><div style="font-size:15px; line-height:24px; color:#374151; margin-bottom:22px;">Dear {{student_name}},<br><br>You requested remote progress test authentication for <strong>{{lesson_title}}</strong> in <strong>{{course_title}}</strong>.<br><br>Use the secure link below to verify your identity with a live photo and your account password. You will receive a Progress Test Code to enter on your course page before the test begins.<br><br>This link expires at <strong>{{expires_at}}</strong>.</div><table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="background:#1a3a63; border-radius:10px;"><a href="{{auth_link}}" style="display:inline-block; padding:14px 22px; font-family:Arial,Helvetica,sans-serif; font-size:14px; font-weight:700; color:#ffffff; text-decoration:none;">Open Authentication Page</a></td></tr></table><div style="margin-top:22px; font-size:14px; line-height:22px; color:#6b7280;">If you did not request this email, contact <a href="mailto:{{support_email}}" style="color:#1a3a63; text-decoration:none;">{{support_email}}</a>.</div><div style="margin-top:28px; font-size:15px; line-height:24px; color:#374151;">Best regards,<br><strong style="color:#111827;">Kay Vereeken</strong><br>Head of Training</div></td></tr></table></td></tr></table></td></tr></table>';
}

function ptr_remote_auth_email_text(): string
{
    return "Dear {{student_name}},\n\nYou requested remote progress test authentication for {{lesson_title}} in {{course_title}}.\n\nOpen your authentication page: {{auth_link}}\n\nThis link expires at {{expires_at}}.\n\nIf you did not request this email, contact {{support_email}}.\n\nBest regards,\nKay Vereeken\nHead of Training\n";
}

function ptr_ensure_remote_email_automation(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $notificationKey = 'remote_progress_test_auth_request';
    $subject = 'Your IPCA Progress Test Authentication Link';
    $html = ptr_remote_auth_email_html();
    $text = ptr_remote_auth_email_text();
    $allowedVars = '["student_name","lesson_title","course_title","auth_link","expires_at","support_email","student_email"]';

    $st = $pdo->prepare("SELECT id FROM notification_templates WHERE notification_key = ? AND channel = 'email' LIMIT 1");
    $st->execute([$notificationKey]);
    $templateId = (int)$st->fetchColumn();

    if ($templateId <= 0) {
        $ins = $pdo->prepare("
            INSERT INTO notification_templates
              (notification_key, channel, name, description, is_enabled, subject_template, html_template, text_template, allowed_variables_json, created_at, updated_at)
            VALUES (?, 'email', 'Remote Progress Test Authentication', 'Email with secure link for off-site progress test authentication.', 1, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
        ");
        $ins->execute([$notificationKey, $subject, $html, $text, $allowedVars]);
        $templateId = (int)$pdo->lastInsertId();
    } else {
        $pdo->prepare("
            UPDATE notification_templates
            SET html_template = ?, text_template = ?, subject_template = ?, is_enabled = 1, updated_at = UTC_TIMESTAMP()
            WHERE id = ?
        ")->execute([$html, $text, $subject, $templateId]);
    }

    $verSt = $pdo->prepare('SELECT COUNT(*) FROM notification_template_versions WHERE notification_template_id = ?');
    $verSt->execute([$templateId]);
    if ((int)$verSt->fetchColumn() === 0) {
        $pdo->prepare("
            INSERT INTO notification_template_versions
              (notification_template_id, version_no, notification_key, subject_template, html_template, text_template, allowed_variables_json, changed_by_user_id, change_note, created_at)
            VALUES (?, 1, ?, ?, ?, ?, ?, NULL, 'Bootstrap: remote progress test auth email', UTC_TIMESTAMP())
        ")->execute([$templateId, $notificationKey, $subject, $html, $text, $allowedVars]);
    }

    $flowSt = $pdo->prepare("SELECT id FROM automation_flows WHERE event_key = 'remote_progress_test_requested' LIMIT 1");
    $flowSt->execute();
    $flowId = (int)$flowSt->fetchColumn();
    if ($flowId <= 0) {
        $pdo->prepare("
            INSERT INTO automation_flows (name, description, event_key, is_active, priority, created_at, updated_at)
            VALUES ('Theory — Remote progress test auth email', 'send_email → remote_progress_test_auth_request', 'remote_progress_test_requested', 1, 10, UTC_TIMESTAMP(), UTC_TIMESTAMP())
        ")->execute();
        $flowId = (int)$pdo->lastInsertId();
    } else {
        $pdo->prepare("UPDATE automation_flows SET is_active = 1, updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$flowId]);
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
                '{"notification_key":"remote_progress_test_auth_request","to_email":"{{student_email}}","to_name":"{{student_name}}"}',
            ]);
        }
    }
}

function ptr_automation_email_sent(?array $automationResult): bool
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

function ptr_automation_email_failure_reason(?array $automationResult): string
{
    if (!$automationResult) {
        return 'Automation dispatch returned no result.';
    }

    if ((int)($automationResult['matched_flows'] ?? 0) === 0) {
        return 'No active automation flow matched event remote_progress_test_requested.';
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

function ptr_hash(string $value): string
{
    return hash('sha256', $value);
}

function ptr_generate_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function ptr_generate_code(): string
{
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function ptr_user_agent_hash(): string
{
    $ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    return $ua === '' ? '' : ptr_hash($ua);
}

function ptr_photo_storage_dir(): string
{
    $dir = dirname(__DIR__) . '/storage/remote_progress_test_photos';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return $dir;
}

function ptr_store_auth_photo(int $authorizationId, string $binary, string $mime): array
{
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Photo must be JPEG, PNG, or WebP.');
    }
    if (strlen($binary) > 6 * 1024 * 1024) {
        throw new RuntimeException('Photo is too large (max 6 MB).');
    }
    $ext = $allowed[$mime];
    $rel = 'auth_' . $authorizationId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $abs = ptr_photo_storage_dir() . '/' . $rel;
    if (@file_put_contents($abs, $binary) === false) {
        throw new RuntimeException('Unable to store authentication photo.');
    }
    @chmod($abs, 0640);
    return [
        'path' => $rel,
        'hash' => ptr_hash($binary),
        'absolute' => $abs,
    ];
}

function ptr_photo_absolute_path(?string $storedPath): ?string
{
    $storedPath = trim((string)$storedPath);
    if ($storedPath === '' || str_contains($storedPath, '..') || str_contains($storedPath, '\\')) {
        return null;
    }
    $abs = ptr_photo_storage_dir() . '/' . basename($storedPath);
    return is_file($abs) ? $abs : null;
}

function cw_progress_test_is_trusted_school_network(PDO $pdo, int $userId, int $cohortId): bool
{
    $state = cw_progress_test_access_state($pdo, $userId, $cohortId);
    return !empty($state['ip_allowed']);
}

function ptr_status_label(string $status): string
{
    return match ($status) {
        'REQUESTED' => 'Requested',
        'EMAIL_SENT' => 'Email sent',
        'AUTHENTICATED' => 'Authenticated',
        'CODE_VERIFIED' => 'Code verified',
        'USED' => 'Used',
        'EXPIRED' => 'Expired',
        'REVOKED' => 'Revoked',
        'FAILED' => 'Failed',
        default => $status,
    };
}

function ptr_status_pill_class(string $status): string
{
    return match ($status) {
        'AUTHENTICATED', 'CODE_VERIFIED' => 'ok',
        'EMAIL_SENT', 'REQUESTED' => 'new',
        'USED' => 'ok',
        'FAILED', 'REVOKED' => 'bad',
        'EXPIRED' => 'warn',
        default => 'new',
    };
}

function ptr_app_base_url(): string
{
    $base = getenv('CW_APP_BASE_URL') ?: '';
    if ($base !== '') {
        return rtrim($base, '/');
    }
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host !== '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $host;
    }
    return 'https://ipca.training';
}

function ptr_support_email(): string
{
    $v = getenv('CW_SUPPORT_EMAIL') ?: getenv('SUPPORT_EMAIL') ?: 'support@ipca.training';
    return trim((string)$v);
}
