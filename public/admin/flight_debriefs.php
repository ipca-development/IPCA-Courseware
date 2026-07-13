<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/FlightDebriefService.php';

cw_require_admin();

$user = cw_current_user($pdo) ?: array();
$service = new FlightDebriefService($pdo);
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $versionId = (int)($_POST['flight_record_version_id'] ?? 0);
        if ((string)($_POST['action'] ?? '') === 'add_note') {
            $service->addInstructorNote($versionId, (int)($user['id'] ?? 0), (string)($_POST['note_text'] ?? ''), array());
            $notice = 'Instructor note saved.';
        } elseif ((string)($_POST['action'] ?? '') === 'release') {
            $service->setReleaseControls($versionId, (int)($_POST['recipient_user_id'] ?? 0), array(
                'summary_released' => !empty($_POST['summary_released']),
                'replay_released' => !empty($_POST['replay_released']),
                'transcript_released' => !empty($_POST['transcript_released']),
                'debrief_released' => !empty($_POST['debrief_released']),
                'audio_released' => !empty($_POST['audio_released']),
            ), (int)($user['id'] ?? 0));
            $notice = 'Release controls updated.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

try {
    $stmt = $pdo->query("
        SELECT v.id, v.version_uuid, s.aircraft_registration, s.avionics_on_utc
        FROM ipca_operational_flight_record_versions v
        INNER JOIN ipca_operational_flight_records r ON r.id = v.flight_record_id
        INNER JOIN ipca_flight_sessions s ON s.id = r.session_id
        ORDER BY v.created_at DESC
        LIMIT 100
    ");
    $versions = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
    $versions = is_array($versions) ? $versions : array();
} catch (Throwable $e) {
    $versions = array();
    $error = $error !== '' ? $error : 'Debrief foundation tables are not available yet.';
}

cw_header('Flight Debriefs');
?>
<style>
.debrief-page{display:grid;gap:18px}.debrief-card{background:#fff;border:1px solid rgba(15,23,42,.12);border-radius:14px;padding:18px;box-shadow:0 10px 24px rgba(15,23,42,.06)}.debrief-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px}.debrief-input,.debrief-select{width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:8px}.debrief-button{border:0;border-radius:8px;background:#1d4ed8;color:#fff;font-weight:700;padding:9px 12px}.debrief-muted{color:#64748b;font-size:13px}.debrief-notice{background:#ecfdf5;border:1px solid #bbf7d0;color:#166534;border-radius:10px;padding:12px}.debrief-error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:12px}
</style>
<div class="debrief-page">
  <section class="debrief-card">
    <h2 style="margin-top:0">Flight Debrief Foundation</h2>
    <p class="debrief-muted">Create instructor notes and manage separate release switches for summary, replay, transcript, debrief, and audio. AI generation itself remains a later provider integration.</p>
  </section>
  <?php if ($notice !== ''): ?><div class="debrief-notice"><?= h($notice) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="debrief-error"><?= h($error) ?></div><?php endif; ?>
  <section class="debrief-grid">
    <form class="debrief-card" method="post">
      <h3 style="margin-top:0">Instructor Note</h3>
      <input type="hidden" name="action" value="add_note">
      <select class="debrief-select" name="flight_record_version_id">
        <?php foreach ($versions as $version): ?><option value="<?= h((string)$version['id']) ?>"><?= h((string)$version['aircraft_registration']) ?> · <?= h((string)$version['avionics_on_utc']) ?></option><?php endforeach; ?>
      </select>
      <p><textarea class="debrief-input" name="note_text" rows="5" placeholder="Instructor note"></textarea></p>
      <button class="debrief-button" type="submit">Save Note</button>
    </form>
    <form class="debrief-card" method="post">
      <h3 style="margin-top:0">Release Controls</h3>
      <input type="hidden" name="action" value="release">
      <select class="debrief-select" name="flight_record_version_id">
        <?php foreach ($versions as $version): ?><option value="<?= h((string)$version['id']) ?>"><?= h((string)$version['aircraft_registration']) ?> · <?= h((string)$version['avionics_on_utc']) ?></option><?php endforeach; ?>
      </select>
      <p><input class="debrief-input" name="recipient_user_id" placeholder="Recipient user id"></p>
      <label><input type="checkbox" name="summary_released"> Summary</label><br>
      <label><input type="checkbox" name="replay_released"> Replay</label><br>
      <label><input type="checkbox" name="transcript_released"> Transcript</label><br>
      <label><input type="checkbox" name="debrief_released"> Debrief</label><br>
      <label><input type="checkbox" name="audio_released"> Audio</label><br><br>
      <button class="debrief-button" type="submit">Update Release</button>
    </form>
  </section>
</div>
<?php cw_footer(); ?>
