<?php
declare(strict_types=1);

$root = dirname(__DIR__);
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

try {
    $pdo = cw_db();
} catch (Throwable $e) {
    fwrite(STDERR, 'Cannot connect: ' . $e->getMessage() . "\n");
    exit(1);
}

$keys = ['instructor_approval_decision_student', 'instructor_approval_decision_chief'];
$ph = implode(',', array_fill(0, count($keys), '?'));
$st = $pdo->prepare("
    SELECT id, notification_key, channel, is_enabled, name,
           CHAR_LENGTH(html_template) AS html_len,
           CHAR_LENGTH(allowed_variables_json) AS vars_len
    FROM notification_templates
    WHERE notification_key IN ($ph)
    ORDER BY notification_key
");
$st->execute($keys);
$templates = $st->fetchAll(PDO::FETCH_ASSOC);

echo "=== notification_templates ===\n";
echo json_encode($templates, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

if ($templates) {
    $ids = array_column($templates, 'id');
    $ph2 = implode(',', array_fill(0, count($ids), '?'));
    $st2 = $pdo->prepare("
        SELECT notification_template_id, version_no, notification_key,
               CHAR_LENGTH(html_template) AS html_len, change_note
        FROM notification_template_versions
        WHERE notification_template_id IN ($ph2)
        ORDER BY notification_template_id, version_no DESC
    ");
    $st2->execute($ids);
    echo "\n=== notification_template_versions ===\n";
    echo json_encode($st2->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

$st3 = $pdo->query("
    SELECT id, name, event_key, is_active, priority
    FROM automation_flows
    WHERE name LIKE 'Theory — Instructor decision%'
    ORDER BY priority, id
");
$flows = $st3 ? $st3->fetchAll(PDO::FETCH_ASSOC) : [];

echo "\n=== automation_flows (Theory — Instructor decision*) ===\n";
echo json_encode($flows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

foreach ($flows as $f) {
    $fid = (int)$f['id'];
    $ca = $pdo->prepare('SELECT id, field_key, operator, sort_order FROM automation_flow_conditions WHERE flow_id = ? ORDER BY sort_order');
    $ca->execute([$fid]);
    $aa = $pdo->prepare('SELECT id, action_key, config_json, sort_order FROM automation_flow_actions WHERE flow_id = ? ORDER BY sort_order');
    $aa->execute([$fid]);
    echo "\n--- flow id {$fid} {$f['name']} ---\n";
    echo 'conditions: ' . json_encode($ca->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    echo 'actions: ' . json_encode($aa->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

$tplOk = count($templates) === 2;
foreach ($templates as $t) {
    if ((int)($t['is_enabled'] ?? 0) !== 1) {
        $tplOk = false;
    }
}
$flowOk = count($flows) >= 2;
$studentFlow = false;
$chiefFlow = false;
foreach ($flows as $f) {
    if (str_contains((string)$f['name'], '(student email)')) {
        $studentFlow = true;
    }
    if (str_contains((string)$f['name'], '(chief email)')) {
        $chiefFlow = true;
    }
}

echo "\n=== SUMMARY ===\n";
echo 'Templates found & enabled (2): ' . ($tplOk ? 'yes' : 'NO') . "\n";
echo 'Automation flows (student + chief names): ' . (($studentFlow && $chiefFlow) ? 'yes' : 'NO') . "\n";
echo 'Overall: ' . (($tplOk && $studentFlow && $chiefFlow) ? 'OK — seed looks applied.' : 'INCOMPLETE — re-run SQL or fix errors.') . "\n";
