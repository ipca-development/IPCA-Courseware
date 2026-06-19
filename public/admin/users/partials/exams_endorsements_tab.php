<?php
declare(strict_types=1);

$examsCtx = aue_load_exams_endorsements_context($pdo, $userId);
$cohorts = $examsCtx['cohorts'];
$areas = $examsCtx['areas'];
$permissions = $examsCtx['permissions'];
$reports = $examsCtx['reports'];
$flightCredentials = is_array($examsCtx['flight_credentials'] ?? null) ? $examsCtx['flight_credentials'] : array();
$flightCredentialsReady = !empty($examsCtx['flight_credentials_schema_ready']);
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

<section class="card ue-card">
    <h3 class="ue-card-title"><span>Medical, Licenses &amp; Signature</span></h3>
    <div class="ue-info-panel" style="margin-bottom:16px;">
        Store reusable pilot/instructor certificate data and a manual electronic signature. These values can be used by Forms and printable logbooks for auto-fill and signature placement.
    </div>
    <?php if (!$flightCredentialsReady): ?>
        <div class="ue-info-panel" style="margin-bottom:16px;background:#fff7ed;border-color:#fed7aa;color:#9a3412;">
            Apply <code>scripts/sql/2026_06_19_user_flight_credentials_signature.sql</code> before saving medical/license/signature data.
        </div>
    <?php endif; ?>
    <form method="post" action="<?php echo h(aue_edit_url($userId, 'exams_endorsements')); ?>" id="ueFlightCredentialsForm">
        <input type="hidden" name="form_section" value="exams_endorsements">
        <input type="hidden" name="tab" value="exams_endorsements">
        <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">
        <input type="hidden" name="exams_action" value="save_flight_credentials">
        <input type="hidden" name="signature_data_url" id="ueSignatureDataUrl">
        <div class="ue-form-grid">
            <div>
                <label class="ue-field-label">Pilot Certificate Number</label>
                <input class="app-input ue-input" name="pilot_certificate_number" value="<?php echo h((string)($flightCredentials['pilot_certificate_number'] ?? '')); ?>">
            </div>
            <div>
                <label class="ue-field-label">Pilot Certificate Level / Ratings</label>
                <input class="app-input ue-input" name="pilot_certificate_level" placeholder="Private Pilot ASEL" value="<?php echo h((string)($flightCredentials['pilot_certificate_level'] ?? '')); ?>">
            </div>
            <div>
                <label class="ue-field-label">Pilot Certificate Issuer</label>
                <input class="app-input ue-input" name="pilot_certificate_issuer" placeholder="FAA" value="<?php echo h((string)($flightCredentials['pilot_certificate_issuer'] ?? '')); ?>">
            </div>
            <div>
                <label class="ue-field-label">Pilot Certificate Expiration</label>
                <input class="app-input ue-input" type="date" name="pilot_certificate_expiration_date" value="<?php echo h((string)($flightCredentials['pilot_certificate_expiration_date'] ?? '')); ?>">
            </div>
            <div>
                <label class="ue-field-label">Medical Certificate Class</label>
                <input class="app-input ue-input" name="medical_certificate_class" placeholder="Third Class" value="<?php echo h((string)($flightCredentials['medical_certificate_class'] ?? '')); ?>">
            </div>
            <div>
                <label class="ue-field-label">Medical Certificate Issuer</label>
                <input class="app-input ue-input" name="medical_certificate_issuer" placeholder="FAA AME" value="<?php echo h((string)($flightCredentials['medical_certificate_issuer'] ?? '')); ?>">
            </div>
            <div>
                <label class="ue-field-label">Medical Expiration</label>
                <input class="app-input ue-input" type="date" name="medical_certificate_expiration_date" value="<?php echo h((string)($flightCredentials['medical_certificate_expiration_date'] ?? '')); ?>">
            </div>
            <div>
                <label class="ue-field-label">License Country / Authority</label>
                <input class="app-input ue-input" name="license_country" placeholder="FAA / United States" value="<?php echo h((string)($flightCredentials['license_country'] ?? '')); ?>">
            </div>
            <div>
                <label class="ue-field-label">Instructor Certificate Number</label>
                <input class="app-input ue-input" name="instructor_certificate_number" value="<?php echo h((string)($flightCredentials['instructor_certificate_number'] ?? '')); ?>">
            </div>
            <div>
                <label class="ue-field-label">Instructor Certificate Expiration</label>
                <input class="app-input ue-input" type="date" name="instructor_certificate_expiration_date" value="<?php echo h((string)($flightCredentials['instructor_certificate_expiration_date'] ?? '')); ?>">
            </div>
            <div>
                <label class="ue-field-label">Ground Instructor Certificate Number</label>
                <input class="app-input ue-input" name="ground_instructor_certificate_number" value="<?php echo h((string)($flightCredentials['ground_instructor_certificate_number'] ?? '')); ?>">
            </div>
            <div>
                <label class="ue-field-label">Medical Restrictions / Notes</label>
                <textarea class="app-textarea ue-input" name="medical_restrictions" rows="3"><?php echo h((string)($flightCredentials['medical_restrictions'] ?? '')); ?></textarea>
            </div>
        </div>

        <div class="ue-signature-wrap">
            <div>
                <label class="ue-field-label">Reusable Electronic Signature</label>
                <div class="ue-signature-pad">
                    <canvas id="ueSignatureCanvas" width="860" height="220" aria-label="Signature pad"></canvas>
                    <div class="ue-signature-baseline"></div>
                    <div class="ue-signature-placeholder">Sign here with mouse, trackpad, or finger</div>
                </div>
                <div class="ue-signature-actions">
                    <button type="button" class="app-btn app-btn-secondary" id="ueClearSignature">Clear Signature</button>
                    <span class="ue-list-meta">A smoothed PNG will be stored for logbook/form auto-fill.</span>
                </div>
            </div>
            <div>
                <label class="ue-field-label">Current Saved Signature</label>
                <?php $signaturePath = trim((string)($flightCredentials['signature_image_path'] ?? '')); ?>
                <?php if ($signaturePath !== ''): ?>
                    <div class="ue-saved-signature">
                        <img src="/<?php echo h(ltrim($signaturePath, '/')); ?>" alt="Saved electronic signature">
                    </div>
                    <div class="ue-list-meta">Captured <?php echo h((string)($flightCredentials['signature_captured_at'] ?? '')); ?></div>
                <?php else: ?>
                    <div class="ue-info-panel">No saved signature yet.</div>
                <?php endif; ?>
            </div>
        </div>

        <div style="margin-top:16px;">
            <button type="submit" class="app-btn app-btn-primary"<?php echo $flightCredentialsReady ? '' : ' disabled'; ?>>Save Medical, License &amp; Signature Data</button>
        </div>
    </form>
</section>

<style>
.ue-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;}
.ue-field-label{display:block;margin-bottom:6px;font-size:12px;color:var(--text-muted);font-weight:650;}
.ue-input{width:100%;}
.ue-signature-wrap{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(260px,.7fr);gap:18px;margin-top:18px;align-items:start}
.ue-signature-pad{position:relative;border:1px solid rgba(15,23,42,.16);border-radius:18px;background:linear-gradient(180deg,#fff 0%,#fbfaf7 100%);height:220px;overflow:hidden;touch-action:none}
.ue-signature-pad canvas{position:absolute;inset:0;width:100%;height:100%;z-index:2;touch-action:none}
.ue-signature-baseline{position:absolute;left:30px;right:30px;bottom:54px;height:1px;background:rgba(15,23,42,.18);z-index:1}
.ue-signature-placeholder{position:absolute;left:32px;bottom:62px;color:#94a3b8;font-size:13px;font-style:italic;z-index:1;pointer-events:none}
.ue-signature-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px}
.ue-saved-signature{border:1px solid rgba(15,23,42,.12);border-radius:16px;background:#fff;padding:14px;min-height:120px;display:flex;align-items:center;justify-content:center}
.ue-saved-signature img{max-width:100%;max-height:120px;object-fit:contain}
@media (max-width:860px){.ue-form-grid{grid-template-columns:1fr;}}
@media (max-width:860px){.ue-signature-wrap{grid-template-columns:1fr;}}
</style>

<script>
(function(){
  const canvas = document.getElementById('ueSignatureCanvas');
  const input = document.getElementById('ueSignatureDataUrl');
  const form = document.getElementById('ueFlightCredentialsForm');
  const clearBtn = document.getElementById('ueClearSignature');
  if (!canvas || !input || !form) return;
  const ctx = canvas.getContext('2d');
  let drawing = false;
  let hasInk = false;
  let last = null;
  const ratio = Math.max(1, window.devicePixelRatio || 1);
  function resize(){
    const rect = canvas.getBoundingClientRect();
    canvas.width = Math.max(1, Math.round(rect.width * ratio));
    canvas.height = Math.max(1, Math.round(rect.height * ratio));
    ctx.setTransform(ratio,0,0,ratio,0,0);
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#0f172a';
    ctx.shadowColor = 'rgba(15,23,42,.18)';
    ctx.shadowBlur = 0.6;
  }
  function point(e){
    const rect = canvas.getBoundingClientRect();
    const touch = e.touches && e.touches[0] ? e.touches[0] : e;
    return {x: touch.clientX - rect.left, y: touch.clientY - rect.top, t: Date.now()};
  }
  function start(e){
    drawing = true;
    last = point(e);
    e.preventDefault();
  }
  function move(e){
    if (!drawing || !last) return;
    const p = point(e);
    const dx = p.x - last.x;
    const dy = p.y - last.y;
    const dist = Math.sqrt(dx*dx + dy*dy);
    const width = Math.max(1.45, Math.min(3.8, 4.2 - dist / 14));
    const mid = {x:(last.x+p.x)/2, y:(last.y+p.y)/2};
    ctx.lineWidth = width;
    ctx.beginPath();
    ctx.moveTo(last.x, last.y);
    ctx.quadraticCurveTo(last.x, last.y, mid.x, mid.y);
    ctx.stroke();
    last = p;
    hasInk = true;
    e.preventDefault();
  }
  function end(){
    drawing = false;
    last = null;
  }
  function clear(){
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    input.value = '';
    hasInk = false;
  }
  resize();
  window.addEventListener('resize', resize);
  canvas.addEventListener('mousedown', start);
  canvas.addEventListener('mousemove', move);
  window.addEventListener('mouseup', end);
  canvas.addEventListener('touchstart', start, {passive:false});
  canvas.addEventListener('touchmove', move, {passive:false});
  window.addEventListener('touchend', end);
  if (clearBtn) clearBtn.addEventListener('click', clear);
  form.addEventListener('submit', function(){
    if (hasInk) {
      input.value = canvas.toDataURL('image/png');
    }
  });
})();
</script>
