<?php
declare(strict_types=1);

$examsCtx = aue_load_exams_endorsements_context($pdo, $userId);
$cohorts = $examsCtx['cohorts'];
$areas = $examsCtx['areas'];
$permissions = $examsCtx['permissions'];
$reports = $examsCtx['reports'];
$selectedCohortId = (int)$examsCtx['selected_cohort_id'];
$deficiencies = $examsCtx['deficiencies'];
$sessions = $examsCtx['sessions'];
$lessonMaps = $examsCtx['lesson_maps'];
$selectedReportId = (int)($_GET['report_id'] ?? 0);
$permRow = $selectedCohortId > 0 ? ($permissions[$selectedCohortId] ?? null) : null;
$mockOralEnabled = $permRow ? ((int)$permRow['mock_oral_enabled'] === 1) : false;
?>

<section class="card ue-card">
    <h3 class="ue-card-title"><?php echo aue_svg('shield'); ?><span>Mock Oral Exam Access</span></h3>
    <div class="ue-info-panel" style="margin-bottom:16px;">
        Enable Mock Oral Exam preparation after theory training is complete. Head of Training approval is required before students can request authenticated sessions.
    </div>
    <form method="post" action="<?php echo h(aue_edit_url($userId, 'exams_endorsements')); ?>">
        <input type="hidden" name="form_section" value="exams_endorsements">
        <input type="hidden" name="tab" value="exams_endorsements">
        <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">
        <input type="hidden" name="exams_action" value="set_mock_oral_permission">
        <div class="ue-form-grid">
            <div>
                <label class="ue-field-label">Cohort</label>
                <select class="app-select ue-input" name="cohort_id" onchange="window.location='<?php echo h(aue_edit_url($userId, 'exams_endorsements')); ?>&amp;cohort_id='+this.value;">
                    <?php foreach ($cohorts as $cohort): ?>
                        <option value="<?php echo (int)$cohort['cohort_id']; ?>"<?php echo $selectedCohortId === (int)$cohort['cohort_id'] ? ' selected' : ''; ?>>
                            <?php echo h((string)$cohort['cohort_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="ue-field-label">Mock Oral Enabled</label>
                <select class="app-select ue-input" name="mock_oral_enabled">
                    <option value="0"<?php echo !$mockOralEnabled ? ' selected' : ''; ?>>Disabled</option>
                    <option value="1"<?php echo $mockOralEnabled ? ' selected' : ''; ?>>Enabled</option>
                </select>
            </div>
        </div>
        <div style="margin-top:14px;">
            <label class="ue-field-label">Notes</label>
            <textarea class="app-textarea ue-input" name="mock_oral_notes" rows="2"><?php echo h((string)($permRow['notes'] ?? '')); ?></textarea>
        </div>
        <div style="margin-top:16px;">
            <button type="submit" class="app-btn app-btn-primary">Save Mock Oral Permission</button>
        </div>
    </form>
</section>

<section class="card ue-card">
    <h3 class="ue-card-title"><?php echo aue_svg('billing'); ?><span>FAA Knowledge Test Reports</span></h3>
    <div class="ue-info-panel" style="margin-bottom:16px;">
        Upload PSI/CATS airman knowledge test report PDFs. Parsed deficiencies require admin review before they feed the mock oral weak-area engine.
    </div>
    <form method="post" enctype="multipart/form-data" action="<?php echo h(aue_edit_url($userId, 'exams_endorsements')); ?>">
        <input type="hidden" name="form_section" value="exams_endorsements">
        <input type="hidden" name="tab" value="exams_endorsements">
        <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">
        <input type="hidden" name="exams_action" value="upload_faa_report">
        <input type="hidden" name="cohort_id" value="<?php echo $selectedCohortId; ?>">
        <div class="ue-form-grid">
            <div>
                <label class="ue-field-label">PSI/CATS PDF Report</label>
                <input class="app-input ue-input" type="file" name="faa_report_pdf" accept="application/pdf,.pdf" required>
            </div>
        </div>
        <div style="margin-top:16px;">
            <button type="submit" class="app-btn app-btn-primary">Upload &amp; Parse Report</button>
        </div>
    </form>

    <?php if ($reports): ?>
        <div class="ue-list" style="margin-top:20px;">
            <?php foreach ($reports as $report): ?>
                <?php
                $badge = match ((string)$report['parse_status']) {
                    'needs_review' => 'app-badge-warn',
                    'parsed' => 'app-badge-success',
                    'failed' => 'app-badge-danger',
                    default => 'app-badge-muted',
                };
                ?>
                <div class="ue-list-item">
                    <div class="ue-list-title"><?php echo h((string)$report['original_filename']); ?></div>
                    <div class="ue-list-meta">
                        <span class="app-badge <?php echo h($badge); ?>"><?php echo h((string)$report['parse_status']); ?></span>
                        <?php if ($report['overall_score_pct'] !== null): ?>
                            · Score <?php echo h((string)$report['overall_score_pct']); ?>%
                        <?php endif; ?>
                        · <?php echo h((string)$report['created_at']); ?>
                    </div>
                    <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;">
                        <a class="app-btn app-btn-secondary" href="<?php echo h(aue_edit_url($userId, 'exams_endorsements') . '&cohort_id=' . $selectedCohortId . '&report_id=' . (int)$report['id']); ?>">Review Deficiencies</a>
                        <?php if ((string)$report['parse_status'] === 'failed'): ?>
                            <form method="post" action="<?php echo h(aue_edit_url($userId, 'exams_endorsements')); ?>" style="display:inline;">
                                <input type="hidden" name="form_section" value="exams_endorsements">
                                <input type="hidden" name="tab" value="exams_endorsements">
                                <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">
                                <input type="hidden" name="exams_action" value="parse_faa_report">
                                <input type="hidden" name="report_id" value="<?php echo (int)$report['id']; ?>">
                                <button type="submit" class="app-btn app-btn-secondary">Retry Parse</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php if ($selectedReportId > 0 && $deficiencies): ?>
<section class="card ue-card">
    <h3 class="ue-card-title"><span>Deficiency Review</span></h3>
    <div class="ue-list">
        <?php foreach ($deficiencies as $def): ?>
            <div class="ue-list-item">
                <div class="ue-list-title"><?php echo h((string)$def['deficiency_label']); ?></div>
                <div class="ue-list-meta">
                    Status: <?php echo h((string)$def['review_status']); ?>
                    <?php if (!empty($def['area_title'])): ?> · ACS <?php echo h((string)$def['area_code'] . ' — ' . $def['area_title']); ?><?php endif; ?>
                </div>
                <form method="post" action="<?php echo h(aue_edit_url($userId, 'exams_endorsements')); ?>" style="margin-top:10px;display:grid;grid-template-columns:1fr 1fr auto auto;gap:8px;align-items:end;">
                    <input type="hidden" name="form_section" value="exams_endorsements">
                    <input type="hidden" name="tab" value="exams_endorsements">
                    <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">
                    <input type="hidden" name="exams_action" value="review_deficiency">
                    <input type="hidden" name="deficiency_id" value="<?php echo (int)$def['id']; ?>">
                    <div>
                        <label class="ue-field-label">ACS Area</label>
                        <select class="app-select ue-input" name="area_id">
                            <option value="">Unmapped</option>
                            <?php foreach ($areas as $area): ?>
                                <option value="<?php echo (int)$area['id']; ?>"<?php echo (int)($def['area_id'] ?? 0) === (int)$area['id'] ? ' selected' : ''; ?>>
                                    <?php echo h((string)$area['area_code'] . ' — ' . $area['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="ue-field-label">Review</label>
                        <select class="app-select ue-input" name="review_status">
                            <option value="confirmed">Confirm</option>
                            <option value="rejected">Reject</option>
                        </select>
                    </div>
                    <button type="submit" class="app-btn app-btn-primary">Save</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<section class="card ue-card">
    <h3 class="ue-card-title"><span>ACS Lesson Mapping</span></h3>
    <form method="post" action="<?php echo h(aue_edit_url($userId, 'exams_endorsements')); ?>">
        <input type="hidden" name="form_section" value="exams_endorsements">
        <input type="hidden" name="tab" value="exams_endorsements">
        <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">
        <input type="hidden" name="exams_action" value="save_area_lesson_map">
        <div class="ue-form-grid">
            <div>
                <label class="ue-field-label">ACS Area</label>
                <select class="app-select ue-input" name="map_area_id" required>
                    <?php foreach ($areas as $area): ?>
                        <option value="<?php echo (int)$area['id']; ?>"><?php echo h((string)$area['area_code'] . ' — ' . $area['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="ue-field-label">Lesson ID</label>
                <input class="app-input ue-input" type="number" name="map_lesson_id" min="1" required>
            </div>
            <div>
                <label class="ue-field-label">Weight</label>
                <input class="app-input ue-input" type="number" step="0.01" name="map_weight" value="1.00">
            </div>
        </div>
        <div style="margin-top:16px;">
            <button type="submit" class="app-btn app-btn-primary">Add / Update Mapping</button>
        </div>
    </form>
    <?php if ($lessonMaps): ?>
        <div class="ue-list" style="margin-top:18px;">
            <?php foreach ($lessonMaps as $map): ?>
                <div class="ue-list-item">
                    <div class="ue-list-title"><?php echo h((string)$map['area_code'] . ' — ' . $map['area_title']); ?></div>
                    <div class="ue-list-meta">Lesson #<?php echo (int)$map['lesson_id']; ?>: <?php echo h((string)$map['lesson_title']); ?> · weight <?php echo h((string)$map['weight']); ?></div>
                    <form method="post" action="<?php echo h(aue_edit_url($userId, 'exams_endorsements')); ?>" style="margin-top:8px;">
                        <input type="hidden" name="form_section" value="exams_endorsements">
                        <input type="hidden" name="tab" value="exams_endorsements">
                        <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">
                        <input type="hidden" name="exams_action" value="delete_area_lesson_map">
                        <input type="hidden" name="map_id" value="<?php echo (int)$map['id']; ?>">
                        <button type="submit" class="app-btn app-btn-secondary">Remove</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="card ue-card">
    <h3 class="ue-card-title"><span>Mock Oral Session History</span></h3>
    <?php if (!$sessions): ?>
        <div class="ue-info-panel">No mock oral sessions recorded yet.</div>
    <?php else: ?>
        <div class="ue-list">
            <?php foreach ($sessions as $session): ?>
                <div class="ue-list-item">
                    <div class="ue-list-title"><?php echo h((string)$session['area_code'] . ' — ' . $session['area_title']); ?></div>
                    <div class="ue-list-meta">
                        <?php echo h((string)$session['status']); ?>
                        <?php if ($session['score_pct'] !== null): ?> · <?php echo h((string)$session['score_pct']); ?>%<?php endif; ?>
                        · <?php echo h((string)($session['ended_at'] ?? $session['started_at'] ?? '')); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="card ue-card" style="opacity:.55;">
    <h3 class="ue-card-title"><span>Certificates &amp; Endorsements</span></h3>
    <div class="ue-info-panel">Coming soon: certificate uploads, endorsements, and checkride readiness evidence.</div>
</section>

<style>
.ue-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;}
.ue-field-label{display:block;margin-bottom:6px;font-size:12px;color:var(--text-muted);font-weight:650;}
.ue-input{width:100%;}
@media (max-width:860px){.ue-form-grid{grid-template-columns:1fr;}}
</style>
