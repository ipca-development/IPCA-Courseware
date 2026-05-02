<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/notification_service.php';

if (!function_exists('ttnp_h')) {
    function ttnp_h(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('ttnp_is_theory_notification')) {
    function ttnp_is_theory_notification(array $row): bool
    {
        $key = strtolower(trim((string)($row['notification_key'] ?? '')));
        $name = strtolower(trim((string)($row['name'] ?? '')));
        $description = strtolower(trim((string)($row['description'] ?? '')));

        if ($key === '') {
            return false;
        }

        $explicitKeys = array(
            'third_fail_remediation',
            'instructor_approval_required',
            'instructor_approval_required_chief',
            'instructor_approval_decision_student',
            'instructor_approval_decision_chief',
            'multiple_unsat_remedial_meeting',
            'deadline_missed_extension_1',
            'final_extension_granted_last_warning',
            'reason_rejected',
            'summary_needs_revision',
            'summary_approved',
        );

        if (in_array($key, $explicitKeys, true)) {
            return true;
        }

        if (
            strpos($key, 'progress_test') !== false ||
            strpos($key, 'summary') !== false ||
            strpos($key, 'remediation') !== false ||
            strpos($key, 'deadline') !== false ||
            strpos($key, 'unsat') !== false ||
            strpos($key, 'instructor_approval') !== false ||
            strpos($key, 'theory') !== false
        ) {
            return true;
        }

        if (
            strpos($name, 'theory') !== false ||
            strpos($name, 'progress test') !== false ||
            strpos($name, 'summary') !== false ||
            strpos($name, 'remediation') !== false ||
            strpos($name, 'deadline') !== false
        ) {
            return true;
        }

        if (
            strpos($description, 'progression') !== false ||
            strpos($description, 'progress test') !== false ||
            strpos($description, 'lesson summary') !== false ||
            strpos($description, 'summary') !== false ||
            strpos($description, 'deadline') !== false ||
            strpos($description, 'remediation') !== false ||
            strpos($description, 'unsatisfactory') !== false
        ) {
            return true;
        }

        return false;
    }
}

$ttnpService = new NotificationService($pdo);
$ttnpAllRows = $ttnpService->listTemplates();

$ttnpRows = array_values(array_filter($ttnpAllRows, 'ttnp_is_theory_notification'));

usort($ttnpRows, function (array $a, array $b): int {
    $aEnabled = (int)($a['is_enabled'] ?? 0);
    $bEnabled = (int)($b['is_enabled'] ?? 0);

    if ($aEnabled !== $bEnabled) {
        return $bEnabled <=> $aEnabled;
    }

    $aKey = strtolower(trim((string)($a['notification_key'] ?? '')));
    $bKey = strtolower(trim((string)($b['notification_key'] ?? '')));

    return $aKey <=> $bKey;
});

$ttnpEnabledCount = 0;
foreach ($ttnpRows as $ttnpRow) {
    if ((int)($ttnpRow['is_enabled'] ?? 0) === 1) {
        $ttnpEnabledCount++;
    }
}
?>

<style>
.ttnp-wrap{
    display:flex;
    flex-direction:column;
    gap:16px;
}
.ttnp-topbar{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:14px;
    flex-wrap:wrap;
}
.ttnp-title-block{
    display:flex;
    flex-direction:column;
    gap:6px;
}
.ttnp-title{
    margin:0;
    font-size:20px;
    font-weight:800;
    color:#102845;
}
.ttnp-sub{
    font-size:13px;
    line-height:1.6;
    color:#64748b;
    max-width:900px;
}
.ttnp-chips{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}
.ttnp-chip{
    display:inline-flex;
    align-items:center;
    min-height:34px;
    padding:0 12px;
    border-radius:999px;
    background:#f8fafc;
    border:1px solid rgba(15,23,42,.08);
    color:#334155;
    font-size:12px;
    font-weight:800;
    white-space:nowrap;
}
.ttnp-card{
    background:#fff;
    border:1px solid rgba(15,23,42,.08);
    border-radius:18px;
    overflow:hidden;
}
.ttnp-table-wrap{
    overflow-x:auto;
}
.ttnp-table{
    width:100%;
    border-collapse:collapse;
    table-layout:fixed;
}
.ttnp-table th,
.ttnp-table td{
    padding:14px 14px;
    border-bottom:1px solid rgba(15,23,42,.06);
    vertical-align:top;
}
.ttnp-table th{
    background:#f8fafc;
    text-align:left;
    font-size:11px;
    line-height:1.2;
    text-transform:uppercase;
    letter-spacing:.10em;
    color:#64748b;
    font-weight:800;
}
.ttnp-table tr:last-child td{
    border-bottom:0;
}
.ttnp-name{
    font-size:14px;
    font-weight:800;
    color:#102845;
    line-height:1.4;
    margin-bottom:6px;
}
.ttnp-key{
    display:inline-block;
    padding:4px 8px;
    border-radius:999px;
    background:#eff6ff;
    border:1px solid #bfdbfe;
    color:#1d4ed8;
    font-size:11px;
    font-weight:800;
    line-height:1.3;
    word-break:break-word;
}
.ttnp-desc{
    margin-top:8px;
    font-size:12px;
    line-height:1.55;
    color:#64748b;
}
.ttnp-pill{
    display:inline-flex;
    align-items:center;
    gap:7px;
    padding:6px 10px;
    border-radius:999px;
    font-size:11px;
    font-weight:800;
    white-space:nowrap;
    border:1px solid transparent;
}
.ttnp-pill-dot{
    width:8px;
    height:8px;
    border-radius:999px;
    display:inline-block;
    flex:0 0 8px;
}
.ttnp-pill.enabled{
    background:#ecfdf5;
    border-color:#bbf7d0;
    color:#166534;
}
.ttnp-pill.enabled .ttnp-pill-dot{
    background:#16a34a;
}
.ttnp-pill.disabled{
    background:#fff1f2;
    border-color:#fecdd3;
    color:#be123c;
}
.ttnp-pill.disabled .ttnp-pill-dot{
    background:#e11d48;
}
.ttnp-pill.mode{
    background:#eef2ff;
    border-color:#c7d2fe;
    color:#3730a3;
}
.ttnp-pill.mode .ttnp-pill-dot{
    background:#4f46e5;
}
.ttnp-meta{
    font-size:13px;
    line-height:1.55;
    color:#334155;
}
.ttnp-meta-muted{
    margin-top:4px;
    font-size:12px;
    line-height:1.5;
    color:#64748b;
}
.ttnp-actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}
.ttnp-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:38px;
    padding:0 12px;
    border-radius:10px;
    text-decoration:none;
    font-size:12px;
    font-weight:800;
    border:1px solid rgba(15,23,42,.10);
    background:#fff;
    color:#102845;
    white-space:nowrap;
}
.ttnp-btn:hover{
    background:#f8fbff;
}
.ttnp-btn.primary{
    background:#12355f;
    border-color:#12355f;
    color:#fff;
}
.ttnp-btn.primary:hover{
    opacity:.95;
}
.ttnp-empty{
    padding:30px 22px;
    font-size:14px;
    color:#64748b;
    text-align:center;
}
@media (max-width: 1200px){
    .ttnp-table{
        min-width:980px;
    }
}
</style>

<div class="ttnp-wrap">
    <div class="ttnp-topbar">
        <div class="ttnp-title-block">
            <h2 class="ttnp-title">Theory Notifications</h2>
            <div class="ttnp-sub">
                Manage the live theory-training notification templates used by the progression system.
                This panel is intentionally narrower and cleaner than the standalone page, and avoids the horizontal sprawl.
            </div>
        </div>

        <div class="ttnp-chips">
            <div class="ttnp-chip"><?php echo (int)count($ttnpRows); ?> theory template<?php echo count($ttnpRows) === 1 ? '' : 's'; ?></div>
            <div class="ttnp-chip"><?php echo (int)$ttnpEnabledCount; ?> enabled</div>
            <div class="ttnp-chip">Preview/test stays safe</div>
            <div class="ttnp-chip">Real sends use live saved versions</div>
        </div>
    </div>

    <div class="ttnp-card">
        <div class="ttnp-table-wrap">
            <table class="ttnp-table">
                <thead>
                    <tr>
                        <th style="width:34%;">Notification</th>
                        <th style="width:11%;">Status</th>
                        <th style="width:11%;">Delivery</th>
                        <th style="width:12%;">Duplicate</th>
                        <th style="width:10%;">Live Version</th>
                        <th style="width:12%;">Updated</th>
                        <th style="width:10%;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$ttnpRows): ?>
                        <tr>
                            <td colspan="7">
                                <div class="ttnp-empty">
                                    No theory notification templates were matched yet.
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($ttnpRows as $row): ?>
                            <?php
                            $templateId = (int)($row['id'] ?? 0);
                            $isEnabled = (int)($row['is_enabled'] ?? 0) === 1;
                            $name = trim((string)($row['name'] ?? ''));
                            $notificationKey = trim((string)($row['notification_key'] ?? ''));
                            $description = trim((string)($row['description'] ?? ''));
                            $deliveryMode = trim((string)($row['delivery_mode'] ?? ''));
                            $duplicateStrategy = trim((string)($row['duplicate_strategy'] ?? ''));
                            $liveVersionNo = (int)($row['live_version_no'] ?? 0);
                            $updatedAt = trim((string)($row['updated_at'] ?? ''));
                            $updatedByUserId = isset($row['updated_by_user_id']) && $row['updated_by_user_id'] !== null
                                ? (int)$row['updated_by_user_id']
                                : null;

                            $updatedAtDisplay = $updatedAt !== ''
                                ? date('M j, Y g:i A', strtotime($updatedAt))
                                : '—';
                            ?>
                            <tr>
                                <td>
                                    <div class="ttnp-name"><?php echo ttnp_h($name !== '' ? $name : $notificationKey); ?></div>
                                    <div class="ttnp-key"><?php echo ttnp_h($notificationKey); ?></div>
                                    <?php if ($description !== ''): ?>
                                        <div class="ttnp-desc"><?php echo nl2br(ttnp_h($description)); ?></div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="ttnp-pill <?php echo $isEnabled ? 'enabled' : 'disabled'; ?>">
                                        <span class="ttnp-pill-dot"></span>
                                        <?php echo $isEnabled ? 'Enabled' : 'Disabled'; ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="ttnp-pill mode">
                                        <span class="ttnp-pill-dot"></span>
                                        <?php echo ttnp_h($deliveryMode !== '' ? $deliveryMode : 'immediate'); ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="ttnp-meta"><?php echo ttnp_h($duplicateStrategy !== '' ? $duplicateStrategy : '—'); ?></div>
                                </td>

                                <td>
                                    <div class="ttnp-meta"><?php echo $liveVersionNo > 0 ? 'v' . $liveVersionNo : '—'; ?></div>
                                </td>

                                <td>
                                    <div class="ttnp-meta"><?php echo ttnp_h($updatedAtDisplay); ?></div>
                                    <div class="ttnp-meta-muted">
                                        Updated by:
                                        <?php echo $updatedByUserId !== null ? 'User #' . (int)$updatedByUserId : '—'; ?>
                                    </div>
                                </td>

                                <td>
                                    <div class="ttnp-actions">
                                        <a class="ttnp-btn primary" href="/admin/notification_edit.php?id=<?php echo $templateId; ?>">Edit</a>
                                        <a class="ttnp-btn" href="/admin/notification_versions.php?id=<?php echo $templateId; ?>">Versions</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>