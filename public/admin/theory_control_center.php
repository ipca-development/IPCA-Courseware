<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/courseware_progression_v2.php';

cw_require_admin();

$u = cw_current_user($pdo);

$role = (string)($u['role'] ?? '');
if ($role !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

$flashType = '';
$flashMessage = '';

function tcc_h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function tcc_active_tab(string $tab): string
{
    $allowed = array('policies', 'logic_map', 'automation', 'notifications');
    $tab = trim(strtolower($tab));
    return in_array($tab, $allowed, true) ? $tab : 'policies';
}

function tcc_policy_categories(): array
{
    return array(
        'grading' => 'Grading',
        'summary' => 'Summary',
        'attempts' => 'Attempts',
        'deadlines' => 'Deadlines',
        'remediation' => 'Remediation',
        'notifications' => 'Notifications',
    );
}

function tcc_policy_category_sort(string $category): int
{
    $map = array(
        'grading' => 10,
        'summary' => 20,
        'attempts' => 30,
        'deadlines' => 40,
        'remediation' => 50,
        'notifications' => 60,
    );

    return $map[$category] ?? 999;
}

function tcc_bool_label(mixed $value): string
{
    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, array('1', 'true', 'yes', 'on'), true) ? 'Enabled' : 'Disabled';
}

function tcc_redirect(string $tab, string $type, string $message): void
{
    header('Location: /admin/theory_control_center.php?tab=' . rawurlencode($tab) . '&flash_type=' . rawurlencode($type) . '&flash_message=' . rawurlencode($message));
    exit;
}

$activeTab = tcc_active_tab((string)($_POST['tab'] ?? $_GET['tab'] ?? 'policies'));
$flashType = trim((string)($_GET['flash_type'] ?? ''));
$flashMessage = trim((string)($_GET['flash_message'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = trim((string)($_POST['action'] ?? ''));

    try {
        if ($postAction === 'save_global_policy') {
            $policyKey = trim((string)($_POST['policy_key'] ?? ''));
            $valueText = trim((string)($_POST['value_text'] ?? ''));
            $reasonText = trim((string)($_POST['change_reason_text'] ?? ''));
            $actorUserId = (int)($u['id'] ?? 0);

            if ($policyKey === '') {
                throw new RuntimeException('Missing policy key.');
            }

            $defStmt = $pdo->prepare("
                SELECT id, policy_key, value_type, default_value_text
                FROM system_policy_definitions
                WHERE policy_key = ?
                LIMIT 1
            ");
            $defStmt->execute(array($policyKey));
            $definition = $defStmt->fetch(PDO::FETCH_ASSOC);

            if (!$definition) {
                throw new RuntimeException('Unknown policy key.');
            }

            $valueType = trim((string)($definition['value_type'] ?? 'string'));

            if ($valueType === 'int') {
                if ($valueText === '' || !preg_match('/^-?\d+$/', $valueText)) {
                    throw new RuntimeException('This policy requires an integer value.');
                }
            } elseif ($valueType === 'decimal') {
                if ($valueText === '' || !is_numeric(str_replace(',', '.', $valueText))) {
                    throw new RuntimeException('This policy requires a decimal value.');
                }
                $valueText = str_replace(',', '.', $valueText);
            } elseif ($valueType === 'bool') {
                $normalized = strtolower($valueText);
                if (!in_array($normalized, array('0', '1', 'true', 'false', 'yes', 'no', 'on', 'off'), true)) {
                    throw new RuntimeException('This policy requires a boolean value. Use 1 or 0.');
                }
                $valueText = in_array($normalized, array('1', 'true', 'yes', 'on'), true) ? '1' : '0';
            } elseif ($valueType === 'json') {
                if ($valueText === '') {
                    throw new RuntimeException('This policy requires JSON.');
                }
                $decoded = json_decode($valueText, true);
                if (!is_array($decoded) && !is_object($decoded)) {
                    throw new RuntimeException('Invalid JSON value.');
                }
            }

            $oldStmt = $pdo->prepare("
                SELECT id, value_text
                FROM system_policy_values
                WHERE policy_key = ?
                  AND scope_type = 'global'
                  AND scope_id IS NULL
                  AND is_active = 1
                  AND (effective_to IS NULL OR effective_to >= NOW())
                ORDER BY effective_from DESC, id DESC
                LIMIT 1
            ");
            $oldStmt->execute(array($policyKey));
            $oldRow = $oldStmt->fetch(PDO::FETCH_ASSOC);

            $oldValueText = $oldRow ? (string)$oldRow['value_text'] : null;
            $oldId = $oldRow ? (int)$oldRow['id'] : 0;

            $pdo->beginTransaction();

            if ($oldId > 0) {
                $closeStmt = $pdo->prepare("
                    UPDATE system_policy_values
                    SET
                        is_active = 0,
                        effective_to = NOW()
                    WHERE id = ?
                    LIMIT 1
                ");
                $closeStmt->execute(array($oldId));
            }

            $insStmt = $pdo->prepare("
                INSERT INTO system_policy_values
                (
                    policy_key,
                    scope_type,
                    scope_id,
                    value_text,
                    is_active,
                    effective_from,
                    effective_to,
                    changed_by_user_id,
                    change_reason_text,
                    created_at
                )
                VALUES
                (
                    ?,
                    'global',
                    NULL,
                    ?,
                    1,
                    NOW(),
                    NULL,
                    ?,
                    ?,
                    NOW()
                )
            ");
            $insStmt->execute(array(
                $policyKey,
                $valueText,
                $actorUserId > 0 ? $actorUserId : null,
                $reasonText !== '' ? $reasonText : null,
            ));

            $auditStmt = $pdo->prepare("
                INSERT INTO system_policy_audit
                (
                    policy_key,
                    scope_type,
                    scope_id,
                    old_value_text,
                    new_value_text,
                    changed_by_user_id,
                    changed_at,
                    reason_text
                )
                VALUES
                (
                    ?,
                    'global',
                    NULL,
                    ?,
                    ?,
                    ?,
                    NOW(),
                    ?
                )
            ");
            $auditStmt->execute(array(
                $policyKey,
                $oldValueText,
                $valueText,
                $actorUserId > 0 ? $actorUserId : 0,
                $reasonText !== '' ? $reasonText : null,
            ));

            $pdo->commit();

            tcc_redirect('policies', 'success', 'Policy updated successfully.');
        }

        throw new RuntimeException('Unknown action.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        } else {
    throw new RuntimeException('Unknown action.');
		}
	}
}

$policyCategories = tcc_policy_categories();

$policyStmt = $pdo->query("
    SELECT
        d.id,
        d.policy_key,
        d.category,
        d.value_type,
        d.default_value_text,
        d.allowed_values_json,
        d.validation_rules_json,
        d.description_text,
        d.is_admin_editable,
        d.sort_order
    FROM system_policy_definitions d
    WHERE d.category IN ('grading','summary','attempts','deadlines','remediation','notifications')
    ORDER BY d.category ASC, d.sort_order ASC, d.policy_key ASC
");

$policyDefinitions = $policyStmt ? $policyStmt->fetchAll(PDO::FETCH_ASSOC) : array();

$currentGlobalValues = array();
$valueStmt = $pdo->query("
    SELECT v.policy_key, v.value_text, v.id, v.effective_from, v.changed_by_user_id
    FROM system_policy_values v
    INNER JOIN (
        SELECT policy_key, MAX(id) AS max_id
        FROM system_policy_values
        WHERE scope_type = 'global'
          AND scope_id IS NULL
          AND is_active = 1
          AND (effective_to IS NULL OR effective_to >= NOW())
        GROUP BY policy_key
    ) x ON x.max_id = v.id
");
if ($valueStmt) {
    foreach ($valueStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $currentGlobalValues[(string)$row['policy_key']] = $row;
    }
}

$groupedPolicies = array();
foreach ($policyDefinitions as $row) {
    $category = (string)($row['category'] ?? 'other');
    if (!isset($groupedPolicies[$category])) {
        $groupedPolicies[$category] = array();
    }

    $policyKey = (string)$row['policy_key'];
    $currentRow = $currentGlobalValues[$policyKey] ?? null;
    $row['current_value_text'] = $currentRow ? (string)$currentRow['value_text'] : (string)($row['default_value_text'] ?? '');
    $row['effective_from'] = $currentRow ? (string)$currentRow['effective_from'] : '';
    $groupedPolicies[$category][] = $row;
}

uksort($groupedPolicies, function ($a, $b) {
    return tcc_policy_category_sort((string)$a) <=> tcc_policy_category_sort((string)$b);
});

$engine = new CoursewareProgressionV2($pdo);
$policySnapshot = $engine->getAllPolicies(array());

$logicCards = array(
    array(
        'title' => 'Summary Gate Before Test Start',
        'items' => array(
            'Summary required before test start' => !empty($policySnapshot['summary_required_before_test_start']) ? 'Yes' : 'No',
            'Minimum summary characters' => (string)($policySnapshot['summary_min_characters'] ?? '150'),
            'Summary acceptance minimum score' => (string)($policySnapshot['summary_acceptance_min_score'] ?? '70'),
            'Copy / paste block' => !empty($policySnapshot['summary_block_copy_paste']) ? 'Enabled' : 'Disabled',
            'Similarity threshold %' => (string)($policySnapshot['summary_similarity_threshold_pct'] ?? '85'),
        ),
    ),
    array(
        'title' => 'Progress Test Passing',
        'items' => array(
            'Pass percentage' => (string)($policySnapshot['progress_test_pass_pct'] ?? '75') . '%',
            'Late pass counts if within effective deadline' => !empty($policySnapshot['late_pass_counts_as_valid_if_within_effective_deadline']) ? 'Yes' : 'No',
        ),
    ),
    array(
        'title' => 'Attempt Escalation',
        'items' => array(
            'Initial attempt limit' => (string)($policySnapshot['initial_attempt_limit'] ?? '3'),
            'Extra attempts after remediation' => (string)($policySnapshot['extra_attempts_after_threshold_fail'] ?? '2'),
            'Threshold attempt for remediation email' => (string)($policySnapshot['threshold_attempt_for_remediation_email'] ?? '3'),
            'Maximum attempts without manual override' => (string)($policySnapshot['max_total_attempts_without_admin_override'] ?? '5'),
        ),
    ),
    array(
        'title' => 'Deadline Handling',
        'items' => array(
            'Automatic first extension allowed' => !empty($policySnapshot['allow_first_deadline_extension_automatic']) ? 'Yes' : 'No',
            'Extension 1 hours' => (string)($policySnapshot['deadline_extension_1_hours'] ?? '48'),
            'Require reason after extension 1 missed' => !empty($policySnapshot['require_reason_after_extension_1_missed']) ? 'Yes' : 'No',
            'Extension 2 hours' => (string)($policySnapshot['deadline_extension_2_hours'] ?? '48'),
            'Final extension requires AI approval' => !empty($policySnapshot['final_extension_requires_ai_reason_approval']) ? 'Yes' : 'No',
        ),
    ),
    array(
        'title' => 'Remediation and Notifications',
        'items' => array(
            'Multiple unsat same lesson threshold' => (string)($policySnapshot['multiple_unsat_same_lesson_threshold'] ?? '3'),
            'Multiple unsat coursewide threshold' => (string)($policySnapshot['multiple_unsat_coursewide_threshold'] ?? '5'),
            'Multiple unsat window days' => (string)($policySnapshot['multiple_unsat_window_days'] ?? '30'),
            'Send email after third fail' => !empty($policySnapshot['send_email_after_third_fail']) ? 'Yes' : 'No',
            'Send email after deadline miss' => !empty($policySnapshot['send_email_after_deadline_miss']) ? 'Yes' : 'No',
            'Send email after multiple unsat' => !empty($policySnapshot['send_email_after_multiple_unsat']) ? 'Yes' : 'No',
            'Chief instructor user id' => (string)($policySnapshot['chief_instructor_user_id'] ?? '0'),
        ),
    ),
);

cw_header('Theory Control Center');
?>

<style>
.tcc-page{display:flex;flex-direction:column;gap:18px}
.tcc-flash{padding:14px 16px;border-radius:14px;font-size:14px;font-weight:700}
.tcc-flash.success{background:rgba(22,101,52,.09);color:#166534;border:1px solid rgba(22,101,52,.18)}
.tcc-flash.error{background:rgba(153,27,27,.08);color:#991b1b;border:1px solid rgba(153,27,27,.16)}
.tcc-hero{padding:22px 24px}
.tcc-eyebrow{font-size:11px;text-transform:uppercase;letter-spacing:.14em;color:#64748b;font-weight:800;margin-bottom:8px}
.tcc-title{margin:0;font-size:32px;line-height:1.05;letter-spacing:-.04em;color:#102845}
.tcc-sub{margin-top:10px;font-size:14px;line-height:1.6;color:#56677f;max-width:980px}
.tcc-tabs{display:flex;gap:10px;flex-wrap:wrap}
.tcc-tab{
    display:inline-flex;align-items:center;justify-content:center;
    min-height:42px;padding:0 14px;border-radius:12px;
    text-decoration:none;font-size:13px;font-weight:800;
    border:1px solid rgba(15,23,42,.10);background:#fff;color:#102845;
}
.tcc-tab.active{background:#12355f;color:#fff;border-color:#12355f}
.tcc-grid{display:grid;gap:16px}
.tcc-policy-group{padding:20px 22px}
.tcc-group-title{margin:0 0 14px 0;font-size:19px;font-weight:800;color:#102845}
.tcc-policy-table{width:100%;border-collapse:collapse}
.tcc-policy-table th,.tcc-policy-table td{padding:12px 10px;border-bottom:1px solid rgba(15,23,42,.06);vertical-align:top}
.tcc-policy-table th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.12em;color:#64748b}
.tcc-policy-key{font-weight:800;color:#102845}
.tcc-policy-desc{font-size:13px;line-height:1.55;color:#56677f;margin-top:5px}
.tcc-muted{font-size:12px;color:#64748b;line-height:1.5}
.tcc-form-inline{display:flex;flex-direction:column;gap:8px}
.tcc-input,.tcc-textarea{
    width:100%;box-sizing:border-box;border:1px solid rgba(15,23,42,.12);
    border-radius:12px;padding:10px 12px;background:#fff;color:#102845;font:inherit;
}
.tcc-textarea{min-height:76px;resize:vertical}
.tcc-btn{
    display:inline-flex;align-items:center;justify-content:center;
    min-height:38px;padding:0 14px;border-radius:10px;
    border:1px solid #12355f;background:#12355f;color:#fff;
    font-size:13px;font-weight:800;cursor:pointer;
}
.tcc-btn:hover{opacity:.95}
.tcc-logic-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.tcc-logic-card{padding:20px 22px}
.tcc-logic-title{margin:0 0 12px 0;font-size:18px;font-weight:800;color:#102845}
.tcc-logic-list{display:grid;gap:10px}
.tcc-logic-item{display:flex;justify-content:space-between;gap:16px;padding:10px 0;border-bottom:1px solid rgba(15,23,42,.06)}
.tcc-logic-item:last-child{border-bottom:0}
.tcc-logic-label{font-size:13px;font-weight:700;color:#334155}
.tcc-logic-value{font-size:13px;font-weight:800;color:#102845;text-align:right}
.tcc-embed-card{padding:0;overflow:hidden}
.tcc-embed-head{padding:18px 20px;border-bottom:1px solid rgba(15,23,42,.06)}
.tcc-embed-title{margin:0;font-size:18px;font-weight:800;color:#102845}
.tcc-embed-sub{margin-top:6px;font-size:13px;color:#64748b}
.tcc-frame{width:100%;height:calc(100vh - 260px);min-height:900px;border:0;background:#fff}
@media (max-width: 1100px){
    .tcc-logic-grid{grid-template-columns:1fr}
}
</style>

<div class="tcc-page">

    <?php if ($flashMessage !== ''): ?>
        <div class="tcc-flash <?php echo $flashType === 'success' ? 'success' : 'error'; ?>">
            <?php echo tcc_h($flashMessage); ?>
        </div>
    <?php endif; ?>

    <section class="card tcc-hero">
        <div class="tcc-eyebrow">Admin · Theory Training</div>
        <h1 class="tcc-title">Theory Control Center</h1>
        <div class="tcc-sub">
            Central control page for theory policies, read-only logic visibility, theory automations, and theory notifications.
            This page is designed to give you one place to inspect and adjust the theory system without digging through code.
        </div>
    </section>

    <section class="card" style="padding:14px 16px;">
        <div class="tcc-tabs">
            <a class="tcc-tab <?php echo $activeTab === 'policies' ? 'active' : ''; ?>" href="/admin/theory_control_center.php?tab=policies">A. Theory Policies</a>
            <a class="tcc-tab <?php echo $activeTab === 'logic_map' ? 'active' : ''; ?>" href="/admin/theory_control_center.php?tab=logic_map">B. Read-only Logic Map</a>
            <a class="tcc-tab <?php echo $activeTab === 'automation' ? 'active' : ''; ?>" href="/admin/theory_control_center.php?tab=automation">C. Theory Automations</a>
            <a class="tcc-tab <?php echo $activeTab === 'notifications' ? 'active' : ''; ?>" href="/admin/theory_control_center.php?tab=notifications">D. Theory Notifications</a>
        </div>
    </section>

    <?php if ($activeTab === 'policies'): ?>
    <section class="card" style="padding:14px 16px;">
        <div class="tcc-muted">
            You are currently editing <strong>global theory policies</strong>.
            Course-level and cohort-level overrides are not yet exposed in this control center.
        </div>
    </section>

    <div class="tcc-grid">
        <?php foreach ($groupedPolicies as $category => $rows): ?>
                <section class="card tcc-policy-group">
                    <h2 class="tcc-group-title"><?php echo tcc_h($policyCategories[$category] ?? ucfirst($category)); ?></h2>

                    <table class="tcc-policy-table">
                        <thead>
                            <tr>
                                <th style="width:28%;">Policy</th>
                                <th style="width:12%;">Type</th>
                                <th style="width:14%;">Current</th>
                                <th style="width:14%;">Default</th>
                                <th style="width:32%;">Update Global Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td>
                                        <div class="tcc-policy-key"><?php echo tcc_h($row['policy_key']); ?></div>
                                        <div class="tcc-policy-desc"><?php echo tcc_h((string)($row['description_text'] ?? '')); ?></div>
                                    </td>
                                    <td>
                                        <div class="tcc-muted"><?php echo tcc_h((string)$row['value_type']); ?></div>
                                    </td>
                                    <td>
                                        <div class="tcc-muted">
                                            <?php
                                            $valueType = (string)$row['value_type'];
                                            $currentValue = (string)$row['current_value_text'];

                                            if ($valueType === 'bool') {
                                                echo tcc_h(tcc_bool_label($currentValue) . ' (' . $currentValue . ')');
                                            } else {
                                                echo tcc_h($currentValue);
                                            }
                                            ?>
                                        </div>
                                        <?php if ((string)($row['effective_from'] ?? '') !== ''): ?>
                                            <div class="tcc-muted" style="margin-top:6px;">
                                                Active since <?php echo tcc_h((string)$row['effective_from']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="tcc-muted">
                                            <?php echo tcc_h((string)$row['default_value_text']); ?>
                                        </div>
                                    </td>
                                    <td>
    <?php if (!empty($row['is_admin_editable'])): ?>
        <form method="post" class="tcc-form-inline">
            <input type="hidden" name="action" value="save_global_policy">
            <input type="hidden" name="policy_key" value="<?php echo tcc_h((string)$row['policy_key']); ?>">
            <input type="hidden" name="tab" value="policies">

            <?php if ((string)$row['value_type'] === 'bool'): ?>
                <select class="tcc-input" name="value_text">
                    <?php $boolCurrent = strtolower(trim((string)$row['current_value_text'])); ?>
					<option value="1" <?php echo in_array($boolCurrent, array('1', 'true', 'yes', 'on'), true) ? 'selected' : ''; ?>>
						Enabled (1)
					</option>
					<option value="0" <?php echo in_array($boolCurrent, array('0', 'false', 'no', 'off', ''), true) ? 'selected' : ''; ?>>
						Disabled (0)
					</option>
                </select>
            <?php elseif ((string)$row['value_type'] === 'json'): ?>
                <textarea
                    class="tcc-textarea"
                    name="value_text"
                    placeholder="Enter valid JSON"
                ><?php echo tcc_h((string)$row['current_value_text']); ?></textarea>
            <?php else: ?>
                <input
                    class="tcc-input"
                    type="text"
                    name="value_text"
                    value="<?php echo tcc_h((string)$row['current_value_text']); ?>"
                    placeholder="New value"
                >
            <?php endif; ?>

            <textarea
                class="tcc-textarea"
                name="change_reason_text"
                placeholder="Optional reason for audit trail"
            ></textarea>

            <div>
                <button class="tcc-btn" type="submit">Save Global Policy</button>
            </div>
        </form>
    <?php else: ?>
        <div class="tcc-muted">Read-only</div>
    <?php endif; ?>
</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($activeTab === 'logic_map'): ?>
        <div class="tcc-logic-grid">
            <?php foreach ($logicCards as $card): ?>
                <section class="card tcc-logic-card">
                    <h2 class="tcc-logic-title"><?php echo tcc_h((string)$card['title']); ?></h2>
                    <div class="tcc-logic-list">
                        <?php foreach ((array)$card['items'] as $label => $value): ?>
                            <div class="tcc-logic-item">
                                <div class="tcc-logic-label"><?php echo tcc_h((string)$label); ?></div>
                                <div class="tcc-logic-value"><?php echo tcc_h((string)$value); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($activeTab === 'automation'): ?>
        <section class="card tcc-embed-card">
            <div class="tcc-embed-head">
                <h2 class="tcc-embed-title">Theory Automation Flows</h2>
                <div class="tcc-embed-sub">
                    Existing automation page loaded inside the Theory Control Center.
                    Next step is to wire `scope=theory_training` filtering inside the automation page itself.
                </div>
            </div>
            <iframe
                class="tcc-frame"
                src="/admin/automation_flows.php?scope=theory_training"
                title="Theory Automation Flows"
            ></iframe>
        </section>
    <?php endif; ?>

    <?php if ($activeTab === 'notifications'): ?>
        <section class="card tcc-embed-card">
            <div class="tcc-embed-head">
                <h2 class="tcc-embed-title">Theory Notifications</h2>
                <div class="tcc-embed-sub">
                    Existing notifications page loaded inside the Theory Control Center.
                    Next step is to wire `scope=theory_training` filtering inside the notifications page itself.
                </div>
            </div>
            <iframe
                class="tcc-frame"
                src="/admin/notifications.php?scope=theory_training"
                title="Theory Notifications"
            ></iframe>
        </section>
    <?php endif; ?>

</div>

<?php cw_footer(); ?>