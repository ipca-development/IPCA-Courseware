<?php
declare(strict_types=1);

/**
 * Theory Automation Panel
 *
 * Safe admin editor for theory automation flows only.
 * Intended to be included inside admin/theory_control_center.php
 *
 * Assumptions:
 * - $pdo exists
 * - $u exists (current user)
 * - current admin access already enforced by parent page
 */

if (!isset($pdo) || !($pdo instanceof PDO)) {
    throw new RuntimeException('theory_automation_panel.php requires $pdo.');
}

if (!function_exists('tap_h')) {
    function tap_h(mixed $v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('tap_post_bool')) {
    function tap_post_bool(string $key): bool
    {
        return !empty($_POST[$key]);
    }
}

if (!function_exists('tap_normalize_json')) {
    function tap_normalize_json(string $json): string
    {
        $json = trim($json);
        if ($json === '') {
            return '{}';
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Config JSON must decode to an object/array.');
        }

        $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Unable to normalize config JSON.');
        }

        return $encoded;
    }
}

if (!function_exists('tap_theory_event_catalog')) {
    function tap_theory_event_catalog(): array
    {
        return array(
            'summary_reviewed' => 'Summary reviewed',
            'progress_test_failed' => 'Progress test failed',
            'progress_test_passed' => 'Progress test passed',
            'lesson_deadline_missed' => 'Lesson deadline missed',
            'deadline_extension_missed' => 'Deadline extension missed',
            'deadline_reason_submitted' => 'Deadline reason submitted',
            'deadline_reason_approved' => 'Deadline reason approved',
            'deadline_reason_rejected' => 'Deadline reason rejected',
            'multiple_unsat_threshold_reached' => 'Multiple unsat threshold reached',
            'instructor_decision_recorded' => 'Instructor decision recorded',
            'one_on_one_completed' => 'One-on-one completed',
            'remediation_acknowledged' => 'Remediation acknowledged',
        );
    }
}

if (!function_exists('tap_allowed_condition_operators')) {
    function tap_allowed_condition_operators(): array
    {
        return array(
            '=' => '=',
            '!=' => '!=',
            '>' => '>',
            '>=' => '>=',
            '<' => '<',
            '<=' => '<=',
            'contains' => 'contains',
            'not_contains' => 'not_contains',
            'in' => 'in',
            'not_in' => 'not_in',
            'is_empty' => 'is_empty',
            'is_not_empty' => 'is_not_empty',
        );
    }
}

if (!function_exists('tap_allowed_action_keys')) {
    function tap_allowed_action_keys(): array
    {
        return array(
            'send_email' => 'Send email',
            'log_event' => 'Log event',
            'notify_all_admins' => 'Notify all admins',
            'notify_all_instructors' => 'Notify all instructors',
            'notify_all_students' => 'Notify all students',
            'notify_specific_admin' => 'Notify specific admin',
            'notify_specific_instructor' => 'Notify specific instructor',
        );
    }
}

if (!function_exists('tap_is_theory_event_key')) {
    function tap_is_theory_event_key(string $eventKey): bool
    {
        $catalog = tap_theory_event_catalog();
        if (isset($catalog[$eventKey])) {
            return true;
        }

        return str_starts_with($eventKey, 'theory_');
    }
}

if (!function_exists('tap_validate_flow_core')) {
    function tap_validate_flow_core(string $name, string $eventKey, int $priority): void
    {
        if ($name === '') {
            throw new RuntimeException('Flow name is required.');
        }

        if ($eventKey === '') {
            throw new RuntimeException('Event key is required.');
        }

        if (!tap_is_theory_event_key($eventKey)) {
            throw new RuntimeException('Only theory event keys may be edited in this panel.');
        }

        if ($priority < 0) {
            throw new RuntimeException('Priority must be zero or higher.');
        }
    }
}

if (!function_exists('tap_validate_condition_row')) {
    function tap_validate_condition_row(string $fieldKey, string $operator, ?string $valueText, ?string $valueNumber): void
    {
        $allowedOperators = tap_allowed_condition_operators();

        if ($fieldKey === '') {
            throw new RuntimeException('Condition field_key is required.');
        }

        if (!isset($allowedOperators[$operator])) {
            throw new RuntimeException('Unsupported condition operator.');
        }

        if (in_array($operator, array('is_empty', 'is_not_empty'), true)) {
            return;
        }

        if (in_array($operator, array('>', '>=', '<', '<='), true)) {
            $candidate = $valueNumber !== null && trim($valueNumber) !== '' ? trim($valueNumber) : trim((string)$valueText);
            if ($candidate === '' || !is_numeric(str_replace(',', '.', $candidate))) {
                throw new RuntimeException('Numeric comparison operators require a numeric value.');
            }
        }
    }
}

if (!function_exists('tap_validate_action_row')) {
    function tap_validate_action_row(string $actionKey, string $configJson): array
    {
        $allowedActionKeys = tap_allowed_action_keys();

        if (!isset($allowedActionKeys[$actionKey])) {
            throw new RuntimeException('Unsupported action key.');
        }

        $normalizedJson = tap_normalize_json($configJson);
        $config = json_decode($normalizedJson, true);
        if (!is_array($config)) {
            throw new RuntimeException('Action config must decode to an object.');
        }

        if ($actionKey === 'send_email') {
            $notificationKey = trim((string)($config['notification_key'] ?? ''));
            if ($notificationKey === '') {
                throw new RuntimeException('send_email action requires config.notification_key');
            }
        }

        if ($actionKey === 'notify_all_admins' || $actionKey === 'notify_all_instructors' || $actionKey === 'notify_all_students') {
            $notificationKey = trim((string)($config['notification_key'] ?? ''));
            if ($notificationKey === '') {
                throw new RuntimeException($actionKey . ' requires config.notification_key');
            }
        }

        if ($actionKey === 'notify_specific_admin' || $actionKey === 'notify_specific_instructor') {
            $notificationKey = trim((string)($config['notification_key'] ?? ''));
            $recipientUserId = (int)($config['recipient_user_id'] ?? 0);

            if ($notificationKey === '') {
                throw new RuntimeException($actionKey . ' requires config.notification_key');
            }
            if ($recipientUserId <= 0) {
                throw new RuntimeException($actionKey . ' requires config.recipient_user_id');
            }
        }

        return array(
            'json' => $normalizedJson,
            'config' => $config,
        );
    }
}

if (!function_exists('tap_validate_flow_structure')) {
    function tap_validate_flow_structure(PDO $pdo, int $flowId): array
    {
        $errors = array();

        $flowStmt = $pdo->prepare("
            SELECT id, name, event_key, priority, is_active
            FROM automation_flows
            WHERE id = ?
            LIMIT 1
        ");
        $flowStmt->execute(array($flowId));
        $flow = $flowStmt->fetch(PDO::FETCH_ASSOC);

        if (!$flow) {
            $errors[] = 'Flow not found.';
            return $errors;
        }

        $name = trim((string)($flow['name'] ?? ''));
        $eventKey = trim((string)($flow['event_key'] ?? ''));
        $priority = (int)($flow['priority'] ?? 0);

        try {
            tap_validate_flow_core($name, $eventKey, $priority);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        $condStmt = $pdo->prepare("
            SELECT id, field_key, operator, value_text, value_number
            FROM automation_flow_conditions
            WHERE flow_id = ?
            ORDER BY sort_order ASC, id ASC
        ");
        $condStmt->execute(array($flowId));
        $conditions = $condStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($conditions as $condition) {
            try {
                tap_validate_condition_row(
                    trim((string)($condition['field_key'] ?? '')),
                    trim((string)($condition['operator'] ?? '')),
                    isset($condition['value_text']) ? (string)$condition['value_text'] : null,
                    isset($condition['value_number']) ? (string)$condition['value_number'] : null
                );
            } catch (Throwable $e) {
                $errors[] = 'Condition #' . (int)($condition['id'] ?? 0) . ': ' . $e->getMessage();
            }
        }

        $actStmt = $pdo->prepare("
            SELECT id, action_key, config_json
            FROM automation_flow_actions
            WHERE flow_id = ?
            ORDER BY sort_order ASC, id ASC
        ");
        $actStmt->execute(array($flowId));
        $actions = $actStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($actions as $action) {
            try {
                tap_validate_action_row(
                    trim((string)($action['action_key'] ?? '')),
                    (string)($action['config_json'] ?? '{}')
                );
            } catch (Throwable $e) {
                $errors[] = 'Action #' . (int)($action['id'] ?? 0) . ': ' . $e->getMessage();
            }
        }

        if (!$actions) {
            $errors[] = 'Flow has no actions.';
        }

        return $errors;
    }
}

$tapFlashType = '';
$tapFlashMessage = '';
$tapValidationErrors = array();

$tapSelectedFlowId = (int)($_GET['flow_id'] ?? $_POST['flow_id'] ?? 0);
$tapEventCatalog = tap_theory_event_catalog();
$tapAllowedOperators = tap_allowed_condition_operators();
$tapAllowedActionKeys = tap_allowed_action_keys();
$tapActorUserId = (int)($u['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['tcc_panel'] ?? '') === 'automation') {
    $tapAction = trim((string)($_POST['automation_action'] ?? ''));

    try {
        if ($tapAction === 'create_flow') {
            $name = trim((string)($_POST['name'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $eventKey = trim((string)($_POST['event_key'] ?? ''));
            $priority = (int)($_POST['priority'] ?? 100);
            $isActive = tap_post_bool('is_active') ? 1 : 0;

            tap_validate_flow_core($name, $eventKey, $priority);

            $stmt = $pdo->prepare("
                INSERT INTO automation_flows
                (name, description, event_key, is_active, priority)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute(array(
                $name,
                $description !== '' ? $description : null,
                $eventKey,
                $isActive,
                $priority
            ));

            $tapSelectedFlowId = (int)$pdo->lastInsertId();
            $tapFlashType = 'success';
            $tapFlashMessage = 'Theory automation flow created successfully.';
        } elseif ($tapAction === 'save_flow_core') {
            $flowId = (int)($_POST['flow_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $eventKey = trim((string)($_POST['event_key'] ?? ''));
            $priority = (int)($_POST['priority'] ?? 100);
            $isActive = tap_post_bool('is_active') ? 1 : 0;

            if ($flowId <= 0) {
                throw new RuntimeException('Missing flow id.');
            }

            tap_validate_flow_core($name, $eventKey, $priority);

            $stmt = $pdo->prepare("
                UPDATE automation_flows
                SET
                    name = ?,
                    description = ?,
                    event_key = ?,
                    is_active = ?,
                    priority = ?
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute(array(
                $name,
                $description !== '' ? $description : null,
                $eventKey,
                $isActive,
                $priority,
                $flowId
            ));

            $tapSelectedFlowId = $flowId;
            $tapFlashType = 'success';
            $tapFlashMessage = 'Flow updated successfully.';
        } elseif ($tapAction === 'delete_flow') {
            $flowId = (int)($_POST['flow_id'] ?? 0);

            if ($flowId <= 0) {
                throw new RuntimeException('Missing flow id.');
            }

            $stmt = $pdo->prepare("SELECT event_key FROM automation_flows WHERE id = ? LIMIT 1");
            $stmt->execute(array($flowId));
            $eventKey = (string)$stmt->fetchColumn();

            if (!tap_is_theory_event_key($eventKey)) {
                throw new RuntimeException('Only theory flows may be deleted from this panel.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("DELETE FROM automation_flow_conditions WHERE flow_id = ?");
            $stmt->execute(array($flowId));

            $stmt = $pdo->prepare("DELETE FROM automation_flow_actions WHERE flow_id = ?");
            $stmt->execute(array($flowId));

            $stmt = $pdo->prepare("DELETE FROM automation_flows WHERE id = ? LIMIT 1");
            $stmt->execute(array($flowId));

            $pdo->commit();

            $tapSelectedFlowId = 0;
            $tapFlashType = 'success';
            $tapFlashMessage = 'Flow deleted successfully.';
        } elseif ($tapAction === 'add_condition') {
            $flowId = (int)($_POST['flow_id'] ?? 0);

            if ($flowId <= 0) {
                throw new RuntimeException('Missing flow id.');
            }

            $stmt = $pdo->prepare("
                SELECT COALESCE(MAX(sort_order), 0) + 10
                FROM automation_flow_conditions
                WHERE flow_id = ?
            ");
            $stmt->execute(array($flowId));
            $nextSort = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("
                INSERT INTO automation_flow_conditions
                (flow_id, field_key, operator, value_text, value_number, sort_order)
                VALUES (?, '', '=', NULL, NULL, ?)
            ");
            $stmt->execute(array($flowId, $nextSort));

            $tapSelectedFlowId = $flowId;
            $tapFlashType = 'success';
            $tapFlashMessage = 'Condition row added.';
        } elseif ($tapAction === 'save_condition') {
            $flowId = (int)($_POST['flow_id'] ?? 0);
            $conditionId = (int)($_POST['condition_id'] ?? 0);
            $fieldKey = trim((string)($_POST['field_key'] ?? ''));
            $operator = trim((string)($_POST['operator'] ?? ''));
            $valueText = trim((string)($_POST['value_text'] ?? ''));
            $valueNumber = trim((string)($_POST['value_number'] ?? ''));
            $sortOrder = (int)($_POST['sort_order'] ?? 10);

            if ($flowId <= 0 || $conditionId <= 0) {
                throw new RuntimeException('Missing condition id.');
            }

            tap_validate_condition_row(
                $fieldKey,
                $operator,
                $valueText !== '' ? $valueText : null,
                $valueNumber !== '' ? $valueNumber : null
            );

            $stmt = $pdo->prepare("
                UPDATE automation_flow_conditions
                SET
                    field_key = ?,
                    operator = ?,
                    value_text = ?,
                    value_number = ?,
                    sort_order = ?
                WHERE id = ?
                  AND flow_id = ?
                LIMIT 1
            ");
            $stmt->execute(array(
                $fieldKey,
                $operator,
                $valueText !== '' ? $valueText : null,
                $valueNumber !== '' ? $valueNumber : null,
                $sortOrder,
                $conditionId,
                $flowId
            ));

            $tapSelectedFlowId = $flowId;
            $tapFlashType = 'success';
            $tapFlashMessage = 'Condition updated.';
        } elseif ($tapAction === 'delete_condition') {
            $flowId = (int)($_POST['flow_id'] ?? 0);
            $conditionId = (int)($_POST['condition_id'] ?? 0);

            if ($flowId <= 0 || $conditionId <= 0) {
                throw new RuntimeException('Missing condition id.');
            }

            $stmt = $pdo->prepare("
                DELETE FROM automation_flow_conditions
                WHERE id = ?
                  AND flow_id = ?
                LIMIT 1
            ");
            $stmt->execute(array($conditionId, $flowId));

            $tapSelectedFlowId = $flowId;
            $tapFlashType = 'success';
            $tapFlashMessage = 'Condition deleted.';
        } elseif ($tapAction === 'add_action') {
            $flowId = (int)($_POST['flow_id'] ?? 0);

            if ($flowId <= 0) {
                throw new RuntimeException('Missing flow id.');
            }

            $stmt = $pdo->prepare("
                SELECT COALESCE(MAX(sort_order), 0) + 10
                FROM automation_flow_actions
                WHERE flow_id = ?
            ");
            $stmt->execute(array($flowId));
            $nextSort = (int)$stmt->fetchColumn();

            $defaultConfig = json_encode(array(
                'notification_key' => ''
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $stmt = $pdo->prepare("
                INSERT INTO automation_flow_actions
                (flow_id, action_key, config_json, sort_order)
                VALUES (?, 'send_email', ?, ?)
            ");
            $stmt->execute(array($flowId, $defaultConfig, $nextSort));

            $tapSelectedFlowId = $flowId;
            $tapFlashType = 'success';
            $tapFlashMessage = 'Action row added.';
        } elseif ($tapAction === 'save_action') {
            $flowId = (int)($_POST['flow_id'] ?? 0);
            $actionId = (int)($_POST['action_id'] ?? 0);
            $actionKey = trim((string)($_POST['action_key'] ?? ''));
            $configJson = (string)($_POST['config_json'] ?? '{}');
            $sortOrder = (int)($_POST['sort_order'] ?? 10);

            if ($flowId <= 0 || $actionId <= 0) {
                throw new RuntimeException('Missing action id.');
            }

            $validated = tap_validate_action_row($actionKey, $configJson);

            $stmt = $pdo->prepare("
                UPDATE automation_flow_actions
                SET
                    action_key = ?,
                    config_json = ?,
                    sort_order = ?
                WHERE id = ?
                  AND flow_id = ?
                LIMIT 1
            ");
            $stmt->execute(array(
                $actionKey,
                $validated['json'],
                $sortOrder,
                $actionId,
                $flowId
            ));

            $tapSelectedFlowId = $flowId;
            $tapFlashType = 'success';
            $tapFlashMessage = 'Action updated.';
        } elseif ($tapAction === 'delete_action') {
            $flowId = (int)($_POST['flow_id'] ?? 0);
            $actionId = (int)($_POST['action_id'] ?? 0);

            if ($flowId <= 0 || $actionId <= 0) {
                throw new RuntimeException('Missing action id.');
            }

            $stmt = $pdo->prepare("
                DELETE FROM automation_flow_actions
                WHERE id = ?
                  AND flow_id = ?
                LIMIT 1
            ");
            $stmt->execute(array($actionId, $flowId));

            $tapSelectedFlowId = $flowId;
            $tapFlashType = 'success';
            $tapFlashMessage = 'Action deleted.';
        } elseif ($tapAction === 'validate_flow') {
            $flowId = (int)($_POST['flow_id'] ?? 0);

            if ($flowId <= 0) {
                throw new RuntimeException('Missing flow id.');
            }

            $tapValidationErrors = tap_validate_flow_structure($pdo, $flowId);
            $tapSelectedFlowId = $flowId;

            if ($tapValidationErrors) {
                $tapFlashType = 'error';
                $tapFlashMessage = 'Validation found ' . count($tapValidationErrors) . ' issue(s).';
            } else {
                $tapFlashType = 'success';
                $tapFlashMessage = 'Flow structure validated successfully. No issues found.';
            }
        } else {
            throw new RuntimeException('Unknown automation action.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $tapFlashType = 'error';
        $tapFlashMessage = $e->getMessage();
    }
}

$tapFlowsStmt = $pdo->query("
    SELECT
        id,
        name,
        description,
        event_key,
        is_active,
        priority
    FROM automation_flows
    ORDER BY priority ASC, id ASC
");
$tapAllFlows = $tapFlowsStmt ? $tapFlowsStmt->fetchAll(PDO::FETCH_ASSOC) : array();

$tapFlows = array();
foreach ($tapAllFlows as $tapFlow) {
    $eventKey = trim((string)($tapFlow['event_key'] ?? ''));
    if (tap_is_theory_event_key($eventKey)) {
        $tapFlows[] = $tapFlow;
    }
}

if ($tapSelectedFlowId <= 0 && !empty($tapFlows)) {
    $tapSelectedFlowId = (int)($tapFlows[0]['id'] ?? 0);
}

$tapSelectedFlow = null;
foreach ($tapFlows as $tapFlow) {
    if ((int)($tapFlow['id'] ?? 0) === $tapSelectedFlowId) {
        $tapSelectedFlow = $tapFlow;
        break;
    }
}

$tapSelectedConditions = array();
$tapSelectedActions = array();

if ($tapSelectedFlowId > 0) {
    $stmt = $pdo->prepare("
        SELECT
            id,
            flow_id,
            field_key,
            operator,
            value_text,
            value_number,
            sort_order
        FROM automation_flow_conditions
        WHERE flow_id = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $stmt->execute(array($tapSelectedFlowId));
    $tapSelectedConditions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT
            id,
            flow_id,
            action_key,
            config_json,
            sort_order
        FROM automation_flow_actions
        WHERE flow_id = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $stmt->execute(array($tapSelectedFlowId));
    $tapSelectedActions = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

?>
<style>
.tap-shell{display:flex;flex-direction:column;gap:16px}
.tap-flash{padding:14px 16px;border-radius:14px;font-size:14px;font-weight:700}
.tap-flash.success{background:rgba(22,101,52,.09);color:#166534;border:1px solid rgba(22,101,52,.18)}
.tap-flash.error{background:rgba(153,27,27,.08);color:#991b1b;border:1px solid rgba(153,27,27,.16)}
.tap-grid{display:grid;grid-template-columns:400px minmax(0,1fr);gap:16px;align-items:start}
.tap-card{padding:18px 20px}
.tap-title{margin:0 0 6px 0;font-size:18px;font-weight:800;color:#102845}
.tap-sub{font-size:13px;line-height:1.55;color:#64748b}
.tap-list{display:flex;flex-direction:column;gap:10px;margin-top:14px}
.tap-flow-link{
    display:block;padding:14px 14px;border-radius:14px;text-decoration:none;
    border:1px solid rgba(15,23,42,.08);background:#fff;color:#102845;
}
.tap-flow-link:hover{border-color:rgba(15,23,42,.16);background:#f8fbff}
.tap-flow-link.active{border-color:#12355f;background:#eff6ff;box-shadow:0 0 0 2px rgba(18,53,95,.08)}
.tap-flow-top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
.tap-flow-name{font-size:15px;font-weight:800;color:#102845}
.tap-flow-key{margin-top:5px;font-size:12px;color:#64748b;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
.tap-pill{
    display:inline-flex;align-items:center;justify-content:center;padding:4px 8px;border-radius:999px;
    font-size:11px;font-weight:800;white-space:nowrap
}
.tap-pill.ok{background:#dcfce7;color:#166534}
.tap-pill.off{background:#fee2e2;color:#991b1b}
.tap-pill.meta{background:#e2e8f0;color:#334155}
.tap-mini-meta{margin-top:8px;display:flex;gap:8px;flex-wrap:wrap}
.tap-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.tap-field{display:flex;flex-direction:column;gap:7px}
.tap-field.full{grid-column:1 / -1}
.tap-label{font-size:13px;font-weight:800;color:#102845}
.tap-help{font-size:12px;line-height:1.5;color:#64748b}
.tap-input,.tap-textarea,.tap-select{
    width:100%;box-sizing:border-box;border:1px solid rgba(15,23,42,.12);
    border-radius:12px;padding:10px 12px;background:#fff;color:#102845;font:inherit;
}
.tap-textarea{min-height:90px;resize:vertical}
.tap-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
.tap-btn{
    display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:0 14px;
    border-radius:10px;border:1px solid #12355f;background:#12355f;color:#fff;
    font-size:13px;font-weight:800;cursor:pointer;text-decoration:none;
}
.tap-btn.secondary{background:#fff;color:#12355f}
.tap-btn.danger{background:#991b1b;border-color:#991b1b}
.tap-btn:hover{opacity:.95}
.tap-section{margin-top:18px;padding-top:18px;border-top:1px solid rgba(15,23,42,.08)}
.tap-section:first-of-type{margin-top:0;padding-top:0;border-top:0}
.tap-section-title{margin:0 0 10px 0;font-size:16px;font-weight:800;color:#102845}
.tap-table-wrap{overflow:auto}
.tap-table{width:100%;border-collapse:collapse}
.tap-table th,.tap-table td{padding:10px 8px;border-bottom:1px solid rgba(15,23,42,.06);vertical-align:top}
.tap-table th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.12em;color:#64748b}
.tap-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;line-height:1.45}
.tap-errors{margin:0;padding-left:20px}
.tap-errors li{margin:6px 0;color:#991b1b;font-size:13px;line-height:1.5}
.tap-create-card{padding:18px 20px}
@media (max-width: 1180px){
    .tap-grid{grid-template-columns:1fr}
}
@media (max-width: 760px){
    .tap-form-grid{grid-template-columns:1fr}
}
</style>

<div class="tap-shell">

    <?php if ($tapFlashMessage !== ''): ?>
        <div class="tap-flash <?php echo $tapFlashType === 'success' ? 'success' : 'error'; ?>">
            <?php echo tap_h($tapFlashMessage); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($tapValidationErrors)): ?>
        <div class="tap-flash error">
            <div style="font-weight:800;margin-bottom:8px;">Validation details</div>
            <ul class="tap-errors">
                <?php foreach ($tapValidationErrors as $tapErr): ?>
                    <li><?php echo tap_h((string)$tapErr); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <section class="card tap-create-card">
        <h2 class="tap-title">Create Theory Flow</h2>
        <div class="tap-sub">
            Create a new theory-only automation flow. This does not execute anything by itself. It only creates a configurable flow record.
        </div>

        <form method="post" style="margin-top:14px;">
            <input type="hidden" name="tcc_panel" value="automation">
            <input type="hidden" name="tab" value="automation">
            <input type="hidden" name="automation_action" value="create_flow">

            <div class="tap-form-grid">
                <div class="tap-field">
                    <label class="tap-label">Flow Name</label>
                    <input class="tap-input" type="text" name="name" value="" placeholder="Example: Third Fail Remediation">
                </div>

                <div class="tap-field">
                    <label class="tap-label">Event Key</label>
                    <select class="tap-select" name="event_key">
                        <?php foreach ($tapEventCatalog as $tapEventKey => $tapEventLabel): ?>
                            <option value="<?php echo tap_h($tapEventKey); ?>"><?php echo tap_h($tapEventLabel . ' (' . $tapEventKey . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="tap-field">
                    <label class="tap-label">Priority</label>
                    <input class="tap-input" type="number" name="priority" value="100" min="0" step="1">
                </div>

                <div class="tap-field">
                    <label class="tap-label">Active</label>
                    <label class="tap-help" style="display:flex;align-items:center;gap:8px;min-height:40px;">
                        <input type="checkbox" name="is_active" value="1" checked>
                        Enable this flow immediately
                    </label>
                </div>

                <div class="tap-field full">
                    <label class="tap-label">Description</label>
                    <textarea class="tap-textarea" name="description" placeholder="Optional admin description"></textarea>
                </div>
            </div>

            <div class="tap-actions">
                <button class="tap-btn" type="submit">Create Theory Flow</button>
            </div>
        </form>
    </section>

    <div class="tap-grid">

        <section class="card tap-card">
            <h2 class="tap-title">Theory Flows</h2>
            <div class="tap-sub">
                Read-only overview first. Select a flow to edit its core fields, conditions, and actions.
            </div>

            <div class="tap-list">
                <?php if (!$tapFlows): ?>
                    <div class="tap-help">No theory automation flows found yet.</div>
                <?php else: ?>
                    <?php foreach ($tapFlows as $tapFlow): ?>
                        <?php
                        $tapFlowId = (int)($tapFlow['id'] ?? 0);
                        $tapIsActive = (int)($tapFlow['is_active'] ?? 0) === 1;
                        ?>
                        <a
                            class="tap-flow-link <?php echo $tapFlowId === $tapSelectedFlowId ? 'active' : ''; ?>"
                            href="/admin/theory_control_center.php?tab=automation&flow_id=<?php echo $tapFlowId; ?>"
                        >
                            <div class="tap-flow-top">
                                <div>
                                    <div class="tap-flow-name"><?php echo tap_h((string)($tapFlow['name'] ?? 'Untitled Flow')); ?></div>
                                    <div class="tap-flow-key"><?php echo tap_h((string)($tapFlow['event_key'] ?? '')); ?></div>
                                </div>
                                <span class="tap-pill <?php echo $tapIsActive ? 'ok' : 'off'; ?>">
                                    <?php echo $tapIsActive ? 'Active' : 'Disabled'; ?>
                                </span>
                            </div>

                            <div class="tap-mini-meta">
                                <span class="tap-pill meta">Priority <?php echo (int)($tapFlow['priority'] ?? 0); ?></span>
                                <span class="tap-pill meta">Flow #<?php echo $tapFlowId; ?></span>
                            </div>

                            <?php if (trim((string)($tapFlow['description'] ?? '')) !== ''): ?>
                                <div class="tap-help" style="margin-top:9px;">
                                    <?php echo tap_h((string)$tapFlow['description']); ?>
                                </div>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="card tap-card">
            <?php if (!$tapSelectedFlow): ?>
                <h2 class="tap-title">Flow Editor</h2>
                <div class="tap-sub">Select a theory flow from the left to edit it.</div>
            <?php else: ?>
                <h2 class="tap-title">Edit Flow: <?php echo tap_h((string)($tapSelectedFlow['name'] ?? '')); ?></h2>
                <div class="tap-sub">
                    Safe editor for one theory flow. Saving here only updates DB records. It does not dispatch events or send emails.
                </div>

                <div class="tap-section">
                    <h3 class="tap-section-title">Flow Core</h3>

                    <form method="post">
                        <input type="hidden" name="tcc_panel" value="automation">
                        <input type="hidden" name="tab" value="automation">
                        <input type="hidden" name="automation_action" value="save_flow_core">
                        <input type="hidden" name="flow_id" value="<?php echo (int)$tapSelectedFlowId; ?>">

                        <div class="tap-form-grid">
                            <div class="tap-field">
                                <label class="tap-label">Flow Name</label>
                                <input
                                    class="tap-input"
                                    type="text"
                                    name="name"
                                    value="<?php echo tap_h((string)($tapSelectedFlow['name'] ?? '')); ?>"
                                >
                            </div>

                            <div class="tap-field">
                                <label class="tap-label">Event Key</label>
                                <select class="tap-select" name="event_key">
                                    <?php foreach ($tapEventCatalog as $tapEventKey => $tapEventLabel): ?>
                                        <option
                                            value="<?php echo tap_h($tapEventKey); ?>"
                                            <?php echo (string)($tapSelectedFlow['event_key'] ?? '') === $tapEventKey ? 'selected' : ''; ?>
                                        >
                                            <?php echo tap_h($tapEventLabel . ' (' . $tapEventKey . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>

                                    <?php
                                    $tapSelectedEventKey = (string)($tapSelectedFlow['event_key'] ?? '');
                                    if ($tapSelectedEventKey !== '' && !isset($tapEventCatalog[$tapSelectedEventKey])):
                                    ?>
                                        <option value="<?php echo tap_h($tapSelectedEventKey); ?>" selected>
                                            <?php echo tap_h($tapSelectedEventKey); ?>
                                        </option>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div class="tap-field">
                                <label class="tap-label">Priority</label>
                                <input
                                    class="tap-input"
                                    type="number"
                                    name="priority"
                                    min="0"
                                    step="1"
                                    value="<?php echo (int)($tapSelectedFlow['priority'] ?? 100); ?>"
                                >
                            </div>

                            <div class="tap-field">
                                <label class="tap-label">Active</label>
                                <label class="tap-help" style="display:flex;align-items:center;gap:8px;min-height:40px;">
                                    <input
                                        type="checkbox"
                                        name="is_active"
                                        value="1"
                                        <?php echo !empty($tapSelectedFlow['is_active']) ? 'checked' : ''; ?>
                                    >
                                    Enable this flow
                                </label>
                            </div>

                            <div class="tap-field full">
                                <label class="tap-label">Description</label>
                                <textarea class="tap-textarea" name="description"><?php echo tap_h((string)($tapSelectedFlow['description'] ?? '')); ?></textarea>
                            </div>
                        </div>

                        <div class="tap-actions">
                            <button class="tap-btn" type="submit">Save Flow Core</button>
                        </div>
                    </form>
                </div>

                <div class="tap-section">
                    <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;">
                        <div>
                            <h3 class="tap-section-title" style="margin-bottom:4px;">Conditions</h3>
                            <div class="tap-sub">All conditions must match for the flow to run.</div>
                        </div>

                        <form method="post" style="margin:0;">
                            <input type="hidden" name="tcc_panel" value="automation">
                            <input type="hidden" name="tab" value="automation">
                            <input type="hidden" name="automation_action" value="add_condition">
                            <input type="hidden" name="flow_id" value="<?php echo (int)$tapSelectedFlowId; ?>">
                            <button class="tap-btn secondary" type="submit">Add Condition Row</button>
                        </form>
                    </div>

                    <div class="tap-table-wrap" style="margin-top:12px;">
                        <table class="tap-table">
                            <thead>
                                <tr>
                                    <th style="width:22%;">Field Key</th>
                                    <th style="width:16%;">Operator</th>
                                    <th style="width:22%;">Text Value</th>
                                    <th style="width:16%;">Number Value</th>
                                    <th style="width:10%;">Sort</th>
                                    <th style="width:14%;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$tapSelectedConditions): ?>
                                    <tr>
                                        <td colspan="6" class="tap-help">No conditions yet. An empty condition set means the flow matches the event directly.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($tapSelectedConditions as $tapCondition): ?>
                                        <tr>
                                            <td colspan="6">
                                                <form method="post">
                                                    <input type="hidden" name="tcc_panel" value="automation">
                                                    <input type="hidden" name="tab" value="automation">
                                                    <input type="hidden" name="automation_action" value="save_condition">
                                                    <input type="hidden" name="flow_id" value="<?php echo (int)$tapSelectedFlowId; ?>">
                                                    <input type="hidden" name="condition_id" value="<?php echo (int)($tapCondition['id'] ?? 0); ?>">

                                                    <div class="tap-form-grid" style="grid-template-columns:1.3fr .9fr 1.1fr .9fr .5fr auto;align-items:end;">
                                                        <div class="tap-field">
                                                            <label class="tap-label">Field Key</label>
                                                            <input class="tap-input" type="text" name="field_key" value="<?php echo tap_h((string)($tapCondition['field_key'] ?? '')); ?>" placeholder="review_status">
                                                        </div>

                                                        <div class="tap-field">
                                                            <label class="tap-label">Operator</label>
                                                            <select class="tap-select" name="operator">
                                                                <?php foreach ($tapAllowedOperators as $tapOperator => $tapOperatorLabel): ?>
                                                                    <option
                                                                        value="<?php echo tap_h($tapOperator); ?>"
                                                                        <?php echo (string)($tapCondition['operator'] ?? '') === $tapOperator ? 'selected' : ''; ?>
                                                                    >
                                                                        <?php echo tap_h($tapOperatorLabel); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>

                                                        <div class="tap-field">
                                                            <label class="tap-label">Text Value</label>
                                                            <input class="tap-input" type="text" name="value_text" value="<?php echo tap_h((string)($tapCondition['value_text'] ?? '')); ?>" placeholder="needs_revision">
                                                        </div>

                                                        <div class="tap-field">
                                                            <label class="tap-label">Number Value</label>
                                                            <input class="tap-input" type="text" name="value_number" value="<?php echo tap_h((string)($tapCondition['value_number'] ?? '')); ?>" placeholder="3">
                                                        </div>

                                                        <div class="tap-field">
                                                            <label class="tap-label">Sort</label>
                                                            <input class="tap-input" type="number" name="sort_order" min="0" step="1" value="<?php echo (int)($tapCondition['sort_order'] ?? 0); ?>">
                                                        </div>

                                                        <div class="tap-actions" style="margin-top:0;">
                                                            <button class="tap-btn" type="submit">Save</button>
                                                </form>

                                                <form method="post" onsubmit="return confirm('Delete this condition?');">
                                                    <input type="hidden" name="tcc_panel" value="automation">
                                                    <input type="hidden" name="tab" value="automation">
                                                    <input type="hidden" name="automation_action" value="delete_condition">
                                                    <input type="hidden" name="flow_id" value="<?php echo (int)$tapSelectedFlowId; ?>">
                                                    <input type="hidden" name="condition_id" value="<?php echo (int)($tapCondition['id'] ?? 0); ?>">
                                                    <button class="tap-btn danger" type="submit">Delete</button>
                                                </form>
                                                        </div>
                                                    </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="tap-section">
                    <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;">
                        <div>
                            <h3 class="tap-section-title" style="margin-bottom:4px;">Actions</h3>
                            <div class="tap-sub">Only runtime-supported actions are allowed here.</div>
                        </div>

                        <form method="post" style="margin:0;">
                            <input type="hidden" name="tcc_panel" value="automation">
                            <input type="hidden" name="tab" value="automation">
                            <input type="hidden" name="automation_action" value="add_action">
                            <input type="hidden" name="flow_id" value="<?php echo (int)$tapSelectedFlowId; ?>">
                            <button class="tap-btn secondary" type="submit">Add Action Row</button>
                        </form>
                    </div>

                    <div class="tap-table-wrap" style="margin-top:12px;">
                        <table class="tap-table">
                            <thead>
                                <tr>
                                    <th style="width:18%;">Action Key</th>
                                    <th style="width:56%;">Config JSON</th>
                                    <th style="width:10%;">Sort</th>
                                    <th style="width:16%;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$tapSelectedActions): ?>
                                    <tr>
                                        <td colspan="4" class="tap-help">No actions yet. Add at least one action before enabling this flow live.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($tapSelectedActions as $tapActionRow): ?>
                                        <tr>
                                            <td colspan="4">
                                                <form method="post">
                                                    <input type="hidden" name="tcc_panel" value="automation">
                                                    <input type="hidden" name="tab" value="automation">
                                                    <input type="hidden" name="automation_action" value="save_action">
                                                    <input type="hidden" name="flow_id" value="<?php echo (int)$tapSelectedFlowId; ?>">
                                                    <input type="hidden" name="action_id" value="<?php echo (int)($tapActionRow['id'] ?? 0); ?>">

                                                    <div class="tap-form-grid" style="grid-template-columns:1fr 2.4fr .6fr auto;align-items:end;">
                                                        <div class="tap-field">
                                                            <label class="tap-label">Action Key</label>
                                                            <select class="tap-select" name="action_key">
                                                                <?php foreach ($tapAllowedActionKeys as $tapActionKey => $tapActionLabel): ?>
                                                                    <option
                                                                        value="<?php echo tap_h($tapActionKey); ?>"
                                                                        <?php echo (string)($tapActionRow['action_key'] ?? '') === $tapActionKey ? 'selected' : ''; ?>
                                                                    >
                                                                        <?php echo tap_h($tapActionLabel . ' (' . $tapActionKey . ')'); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>

                                                        <div class="tap-field">
                                                            <label class="tap-label">Config JSON</label>
                                                            <textarea class="tap-textarea tap-code" name="config_json" placeholder='{"notification_key":"third_fail_remediation"}'><?php echo tap_h((string)($tapActionRow['config_json'] ?? '{}')); ?></textarea>
                                                            <div class="tap-help">
                                                                Examples:
                                                                <span class="tap-code">{"notification_key":"third_fail_remediation"}</span>,
                                                                <span class="tap-code">{"notification_key":"x","recipient_user_id":2}</span>
                                                            </div>
                                                        </div>

                                                        <div class="tap-field">
                                                            <label class="tap-label">Sort</label>
                                                            <input class="tap-input" type="number" name="sort_order" min="0" step="1" value="<?php echo (int)($tapActionRow['sort_order'] ?? 0); ?>">
                                                        </div>

                                                        <div class="tap-actions" style="margin-top:0;">
                                                            <button class="tap-btn" type="submit">Save</button>
                                                </form>

                                                <form method="post" onsubmit="return confirm('Delete this action?');">
                                                    <input type="hidden" name="tcc_panel" value="automation">
                                                    <input type="hidden" name="tab" value="automation">
                                                    <input type="hidden" name="automation_action" value="delete_action">
                                                    <input type="hidden" name="flow_id" value="<?php echo (int)$tapSelectedFlowId; ?>">
                                                    <input type="hidden" name="action_id" value="<?php echo (int)($tapActionRow['id'] ?? 0); ?>">
                                                    <button class="tap-btn danger" type="submit">Delete</button>
                                                </form>
                                                        </div>
                                                    </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="tap-section">
                    <h3 class="tap-section-title">Validate Only</h3>
                    <div class="tap-sub">
                        Safe structural validation only. No event dispatch. No email send. No progression state changes.
                    </div>

                    <div class="tap-actions">
                        <form method="post" style="margin:0;">
                            <input type="hidden" name="tcc_panel" value="automation">
                            <input type="hidden" name="tab" value="automation">
                            <input type="hidden" name="automation_action" value="validate_flow">
                            <input type="hidden" name="flow_id" value="<?php echo (int)$tapSelectedFlowId; ?>">
                            <button class="tap-btn secondary" type="submit">Validate This Flow</button>
                        </form>

                        <form method="post" style="margin:0;" onsubmit="return confirm('Delete this entire flow, including all conditions and actions?');">
                            <input type="hidden" name="tcc_panel" value="automation">
                            <input type="hidden" name="tab" value="automation">
                            <input type="hidden" name="automation_action" value="delete_flow">
                            <input type="hidden" name="flow_id" value="<?php echo (int)$tapSelectedFlowId; ?>">
                            <button class="tap-btn danger" type="submit">Delete Flow</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </section>

    </div>
</div>