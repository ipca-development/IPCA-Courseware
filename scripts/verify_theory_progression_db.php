<?php
declare(strict_types=1);

/**
 * Read-only checks: notification templates, policy definitions/values, automation flows.
 * Usage (from project root, with DB env set): php scripts/verify_theory_progression_db.php
 */

$root = dirname(__DIR__);

/**
 * Load project root .env into getenv/$_ENV when vars are not already set (CLI helper).
 */
$loadDotenv = static function (string $path): void {
    if (!is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
            continue;
        }
        $key = $m[1];
        $val = $m[2];
        if ($val !== '' && (($val[0] === '"' && str_ends_with($val, '"')) || ($val[0] === "'" && str_ends_with($val, "'")))) {
            $val = substr($val, 1, -1);
        }
        if (getenv($key) !== false) {
            continue;
        }
        putenv($key . '=' . $val);
        $_ENV[$key] = $val;
    }
};

if (!getenv('CW_DB_HOST')) {
    $loadDotenv($root . '/.env');
}

require_once $root . '/src/db.php';

$out = static function (string $label, mixed $data): void {
    echo "\n=== {$label} ===\n";
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
};

try {
    $pdo = cw_db();
} catch (Throwable $e) {
    fwrite(STDERR, "Cannot connect: " . $e->getMessage() . "\n"
        . "Export CW_DB_HOST, CW_DB_PORT (optional), CW_DB_NAME, CW_DB_USER, CW_DB_PASS first.\n");
    exit(1);
}

$notificationKeys = [
    'third_fail_remediation',
    'instructor_approval_required',
    'instructor_approval_required_chief',
];

$placeholders = implode(',', array_fill(0, count($notificationKeys), '?'));
$sql = "
    SELECT
        nt.id,
        nt.notification_key,
        nt.is_enabled,
        (
            SELECT MAX(ntv.version_no)
            FROM notification_template_versions ntv
            WHERE ntv.notification_template_id = nt.id
        ) AS live_version_no
    FROM notification_templates nt
    WHERE nt.notification_key IN ($placeholders)
    ORDER BY nt.notification_key
";
$st = $pdo->prepare($sql);
$st->execute($notificationKeys);
$templates = $st->fetchAll(PDO::FETCH_ASSOC);

$policyKeys = [
    'threshold_attempt_for_remediation_email',
    'max_total_attempts_without_admin_override',
    'send_email_after_third_fail',
    'extra_attempts_after_threshold_fail',
    'auto_extra_attempts_after_remediation',
];

$ph2 = implode(',', array_fill(0, count($policyKeys), '?'));
$st2 = $pdo->prepare("
    SELECT policy_key, value_type, default_value_text
    FROM system_policy_definitions
    WHERE policy_key IN ($ph2)
    ORDER BY policy_key
");
$st2->execute($policyKeys);
$policyDefs = $st2->fetchAll(PDO::FETCH_ASSOC);

$st3 = $pdo->prepare("
    SELECT policy_key, scope_type, scope_id, value_text, effective_from, effective_to
    FROM system_policy_values
    WHERE policy_key IN ($ph2)
      AND is_active = 1
      AND (effective_to IS NULL OR effective_to > UTC_TIMESTAMP())
    ORDER BY policy_key, scope_type, scope_id, effective_from DESC
");
$st3->execute($policyKeys);
$policyVals = $st3->fetchAll(PDO::FETCH_ASSOC);

$st4 = $pdo->query("
    SELECT id, name, event_key, is_active, priority
    FROM automation_flows
    WHERE event_key IN ('progress_test_failed', 'progress_test_passed')
    ORDER BY event_key ASC, is_active DESC, priority ASC, id ASC
");
$flows = $st4 ? $st4->fetchAll(PDO::FETCH_ASSOC) : [];

$sendEmailActions = [];
foreach ($flows as $flow) {
    if ((string)($flow['event_key'] ?? '') !== 'progress_test_failed') {
        continue;
    }
    $fid = (int)$flow['id'];
    $sa = $pdo->prepare("
        SELECT id, action_key, sort_order, config_json
        FROM automation_flow_actions
        WHERE flow_id = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $sa->execute([$fid]);
    $actions = $sa->fetchAll(PDO::FETCH_ASSOC);
    foreach ($actions as $a) {
        if (trim((string)($a['action_key'] ?? '')) !== 'send_email') {
            continue;
        }
        $cfg = json_decode((string)($a['config_json'] ?? '{}'), true);
        $nk = is_array($cfg) ? trim((string)($cfg['notification_key'] ?? '')) : '';
        $sendEmailActions[] = [
            'flow_id' => $fid,
            'flow_name' => (string)($flow['name'] ?? ''),
            'flow_is_active' => (int)($flow['is_active'] ?? 0),
            'action_id' => (int)($a['id'] ?? 0),
            'notification_key' => $nk,
            'to_email' => is_array($cfg) ? ($cfg['to_email'] ?? '') : '',
        ];
    }
}

$templateSummary = [];
foreach ($notificationKeys as $k) {
    $row = null;
    foreach ($templates as $t) {
        if (($t['notification_key'] ?? '') === $k) {
            $row = $t;
            break;
        }
    }
    $templateSummary[$k] = $row
        ? [
            'found' => true,
            'is_enabled' => (int)($row['is_enabled'] ?? 0),
            'live_version_no' => $row['live_version_no'],
        ]
        : ['found' => false];
}

$duplicateRiskKeys = ['third_fail_remediation', 'instructor_approval_required', 'instructor_approval_required_chief'];
$riskyAutomation = array_values(array_filter($sendEmailActions, static function (array $x) use ($duplicateRiskKeys): bool {
    return $x['flow_is_active'] === 1 && in_array($x['notification_key'], $duplicateRiskKeys, true);
}));

$out('Notification templates (theory escalation)', $templateSummary);
$out('system_policy_definitions (requested keys)', $policyDefs);
$out('system_policy_values (active rows for those keys)', $policyVals);
$out('automation_flows (progress_test_failed / passed)', $flows);
$out('automation send_email actions on progress_test_failed flows', $sendEmailActions);
$out('DUPLICATE EMAIL RISK: active send_email with same keys as finalize()', $riskyAutomation ?: '(none)');

exit(0);
