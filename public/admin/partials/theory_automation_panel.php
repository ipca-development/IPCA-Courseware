<?php
declare(strict_types=1);

/**
 * THEORY AUTOMATION PANEL
 * - No iframe
 * - Read-only safe view
 * - Filters to theory-related flows
 */

require_once __DIR__ . '/../../src/bootstrap.php';

function tap_h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * LOAD THEORY FLOWS ONLY
 * (Convention: event_key LIKE 'theory_%')
 */
$flowsStmt = $pdo->query("
    SELECT *
    FROM automation_flows
    WHERE event_key LIKE 'theory_%'
    ORDER BY priority ASC, id ASC
");

$flows = $flowsStmt ? $flowsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

/**
 * LOAD CONDITIONS
 */
function tap_load_conditions(PDO $pdo, int $flowId): array
{
    $st = $pdo->prepare("
        SELECT *
        FROM automation_flow_conditions
        WHERE flow_id = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $st->execute([$flowId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * LOAD ACTIONS
 */
function tap_load_actions(PDO $pdo, int $flowId): array
{
    $st = $pdo->prepare("
        SELECT *
        FROM automation_flow_actions
        WHERE flow_id = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $st->execute([$flowId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

?>

<style>
.tap-wrapper{display:flex;flex-direction:column;gap:16px}

.tap-card{
    padding:18px 20px;
    border-radius:18px;
    border:1px solid rgba(15,23,42,.06);
    background:#fff;
}

.tap-title{
    font-size:20px;
    font-weight:800;
    color:#102845;
    margin:0 0 6px 0;
}

.tap-sub{
    font-size:13px;
    color:#64748b;
    margin-bottom:12px;
}

.tap-flow{
    border:1px solid rgba(15,23,42,.06);
    border-radius:16px;
    padding:14px 16px;
    margin-bottom:10px;
    background:#fbfdff;
}

.tap-flow-head{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:10px;
}

.tap-flow-name{
    font-weight:800;
    color:#102845;
    font-size:15px;
}

.tap-pill{
    padding:4px 8px;
    border-radius:999px;
    font-size:11px;
    font-weight:800;
}

.tap-pill.active{
    background:#dcfce7;
    color:#166534;
}

.tap-pill.inactive{
    background:#fee2e2;
    color:#991b1b;
}

.tap-section{
    margin-top:10px;
}

.tap-label{
    font-size:11px;
    font-weight:800;
    text-transform:uppercase;
    color:#64748b;
    margin-bottom:6px;
}

.tap-row{
    display:flex;
    justify-content:space-between;
    gap:10px;
    font-size:13px;
    padding:4px 0;
}

.tap-key{
    font-weight:700;
    color:#334155;
}

.tap-value{
    font-weight:800;
    color:#102845;
}

.tap-empty{
    font-size:13px;
    color:#94a3b8;
}
</style>

<div class="tap-wrapper">

    <div class="tap-card">
        <div class="tap-title">Theory Automation Flows</div>
        <div class="tap-sub">
            This is a live view of automation flows affecting theory training.<br>
            Only flows with <strong>event_key starting with "theory_"</strong> are shown.
        </div>

        <?php if (!$flows): ?>
            <div class="tap-empty">No theory automation flows found.</div>
        <?php else: ?>

            <?php foreach ($flows as $flow): ?>
                <?php
                    $flowId = (int)$flow['id'];
                    $conditions = tap_load_conditions($pdo, $flowId);
                    $actions = tap_load_actions($pdo, $flowId);
                ?>

                <div class="tap-flow">

                    <div class="tap-flow-head">
                        <div class="tap-flow-name">
                            <?= tap_h($flow['name']) ?>
                        </div>

                        <div class="tap-pill <?= !empty($flow['is_active']) ? 'active' : 'inactive' ?>">
                            <?= !empty($flow['is_active']) ? 'Active' : 'Inactive' ?>
                        </div>
                    </div>

                    <div class="tap-row">
                        <div class="tap-key">Event</div>
                        <div class="tap-value"><?= tap_h($flow['event_key']) ?></div>
                    </div>

                    <div class="tap-row">
                        <div class="tap-key">Priority</div>
                        <div class="tap-value"><?= (int)$flow['priority'] ?></div>
                    </div>

                    <?php if (!empty($flow['description'])): ?>
                        <div class="tap-row">
                            <div class="tap-key">Description</div>
                            <div class="tap-value"><?= tap_h($flow['description']) ?></div>
                        </div>
                    <?php endif; ?>

                    <!-- CONDITIONS -->
                    <div class="tap-section">
                        <div class="tap-label">Conditions</div>

                        <?php if (!$conditions): ?>
                            <div class="tap-empty">No conditions (always runs)</div>
                        <?php else: ?>
                            <?php foreach ($conditions as $c): ?>
                                <div class="tap-row">
                                    <div class="tap-key">
                                        <?= tap_h($c['field_key']) ?> <?= tap_h($c['operator']) ?>
                                    </div>
                                    <div class="tap-value">
                                        <?= tap_h($c['value_text'] ?? $c['value_number']) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- ACTIONS -->
                    <div class="tap-section">
                        <div class="tap-label">Actions</div>

                        <?php if (!$actions): ?>
                            <div class="tap-empty">No actions</div>
                        <?php else: ?>
                            <?php foreach ($actions as $a): ?>
                                <div class="tap-row">
                                    <div class="tap-key"><?= tap_h($a['action_key']) ?></div>
                                    <div class="tap-value">Configured</div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                </div>

            <?php endforeach; ?>

        <?php endif; ?>

    </div>

</div>