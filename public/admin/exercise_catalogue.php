<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/FlightExerciseCatalogService.php';

cw_require_admin();

$service = new FlightExerciseCatalogService($pdo);
$notice = '';
$error = '';
$editCode = trim((string)($_GET['code'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $saved = $service->upsertExercise($_POST);
        $notice = 'Exercise saved: ' . (string)$saved['display_name'];
        $editCode = (string)$saved['exercise_code'];
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $editCode = trim((string)($_POST['exercise_code'] ?? $editCode));
    }
}

$schemaReady = $service->schemaReady();
$missingTables = $service->missingTables();
$exercises = array();
if ($schemaReady) {
    try {
        $exercises = $service->listExercises();
    } catch (Throwable $e) {
        $error = $error !== '' ? $error : $e->getMessage();
    }
}

$editing = null;
if ($editCode !== '') {
    foreach ($exercises as $exercise) {
        if ((string)$exercise['exercise_code'] === $editCode) {
            $editing = $exercise;
            break;
        }
    }
}

$form = array(
    'exercise_code' => (string)($editing['exercise_code'] ?? ($_POST['exercise_code'] ?? '')),
    'display_name' => (string)($editing['display_name'] ?? ($_POST['display_name'] ?? '')),
    'description_text' => (string)($editing['description_text'] ?? ($_POST['description_text'] ?? '')),
    'transcript_aliases' => $editing !== null
        ? implode("\n", $editing['transcript_aliases'] ?? array())
        : (string)($_POST['transcript_aliases'] ?? ''),
    'detection_rules_json' => (string)($editing['detection_rules_json'] ?? ($_POST['detection_rules_json'] ?? "{\n  \"crew_event_types\": [\"exercise_marker\"],\n  \"transcript_window_sec\": 90,\n  \"marker_window_sec\": 90,\n  \"telemetry\": {}\n}")),
    'detector_version' => (string)($editing['detector_version'] ?? ($_POST['detector_version'] ?? 'v1')),
    'sort_order' => (string)($editing['sort_order'] ?? ($_POST['sort_order'] ?? '1000')),
    'is_active' => $editing !== null
        ? !empty($editing['is_active'])
        : (!isset($_POST['exercise_code']) || !empty($_POST['is_active'])),
);

cw_header('Exercise Catalogue');
?>
<style>
.exc-page{display:grid;gap:18px}
.exc-card{background:#fff;border:1px solid rgba(15,23,42,.12);border-radius:14px;padding:18px;box-shadow:0 10px 24px rgba(15,23,42,.06)}
.exc-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.exc-field{display:flex;flex-direction:column;gap:6px}
.exc-field--wide{grid-column:1/-1}
.exc-label{font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#64748b}
.exc-input,.exc-textarea{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:8px;padding:9px 10px;font:inherit;font-size:13px;color:#0f172a;background:#fff}
.exc-textarea{min-height:96px;resize:vertical;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}
.exc-textarea--rules{min-height:180px}
.exc-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:4px}
.exc-button{border:0;border-radius:8px;background:#1d4ed8;color:#fff;font-weight:700;padding:9px 12px;cursor:pointer;text-decoration:none;display:inline-flex}
.exc-button--secondary{background:#e2e8f0;color:#0f172a}
.exc-muted{color:#64748b;font-size:13px}
.exc-table{width:100%;border-collapse:collapse}
.exc-table th,.exc-table td{border-bottom:1px solid #e2e8f0;padding:10px 8px;text-align:left;vertical-align:top;font-size:13px}
.exc-code{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:12px;background:#f1f5f9;border-radius:999px;padding:3px 8px;color:#334155}
.exc-pill{display:inline-flex;align-items:center;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:800}
.exc-pill--on{background:#dcfce7;color:#166534}
.exc-pill--off{background:#fee2e2;color:#991b1b}
.exc-aliases{display:flex;flex-wrap:wrap;gap:4px;margin-top:4px}
.exc-alias{background:#eff6ff;color:#1e40af;border-radius:999px;padding:2px 8px;font-size:11px}
.exc-bindings{margin:6px 0 0;padding:0;list-style:none;display:grid;gap:4px}
.exc-bindings li{color:#475569;font-size:12px}
.exc-notice{background:#ecfdf5;border:1px solid #bbf7d0;color:#166534;border-radius:10px;padding:12px}
.exc-error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:12px}
.exc-warning{background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:10px;padding:12px}
@media(max-width:900px){.exc-grid{grid-template-columns:1fr}}
</style>
<div class="exc-page">
  <section class="exc-card">
    <h2 style="margin-top:0">Exercise Catalogue</h2>
    <p class="exc-muted">
      Define identifiable flight exercises used by CVR replay Events.
      Detection uses transcript aliases, crew markers, and telemetry rules.
      ACS and SOP bindings are shown as foresight only — evaluation stays off.
    </p>
  </section>

  <?php if ($notice !== ''): ?><div class="exc-notice"><?= h($notice) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="exc-error"><?= h($error) ?></div><?php endif; ?>
  <?php if (!$schemaReady): ?>
    <div class="exc-warning">
      Apply <code>scripts/sql/2026_08_07_flight_exercise_identification.sql</code>.
      Missing: <?= h(implode(', ', $missingTables)) ?>.
    </div>
  <?php endif; ?>

  <section class="exc-card">
    <h3 style="margin-top:0"><?= $editing ? 'Edit Exercise' : 'Add / Update Exercise' ?></h3>
    <form method="post" class="exc-grid">
      <label class="exc-field">
        <span class="exc-label">Exercise Code</span>
        <input class="exc-input" name="exercise_code" value="<?= h($form['exercise_code']) ?>" placeholder="power_off_stall" required<?= $editing ? ' readonly' : '' ?>>
      </label>
      <label class="exc-field">
        <span class="exc-label">Display Name</span>
        <input class="exc-input" name="display_name" value="<?= h($form['display_name']) ?>" placeholder="Power-Off Stall" required>
      </label>
      <label class="exc-field">
        <span class="exc-label">Sort Order</span>
        <input class="exc-input" name="sort_order" type="number" value="<?= h($form['sort_order']) ?>">
      </label>
      <label class="exc-field">
        <span class="exc-label">Detector Version</span>
        <input class="exc-input" name="detector_version" value="<?= h($form['detector_version']) ?>">
      </label>
      <label class="exc-field exc-field--wide">
        <span class="exc-label">Description</span>
        <textarea class="exc-textarea" name="description_text" style="font-family:inherit;min-height:72px"><?= h($form['description_text']) ?></textarea>
      </label>
      <label class="exc-field exc-field--wide">
        <span class="exc-label">Transcript Aliases (one per line)</span>
        <textarea class="exc-textarea" name="transcript_aliases" required><?= h($form['transcript_aliases']) ?></textarea>
      </label>
      <label class="exc-field exc-field--wide">
        <span class="exc-label">Detection Rules JSON</span>
        <textarea class="exc-textarea exc-textarea--rules" name="detection_rules_json" required><?= h($form['detection_rules_json']) ?></textarea>
      </label>
      <label class="exc-field">
        <span class="exc-label">Active</span>
        <span><input type="checkbox" name="is_active" value="1"<?= !empty($form['is_active']) ? ' checked' : '' ?>> Enabled for identification</span>
      </label>
      <div class="exc-field">
        <span class="exc-label">&nbsp;</span>
        <div class="exc-actions">
          <button class="exc-button" type="submit"<?= $schemaReady ? '' : ' disabled' ?>>Save Exercise</button>
          <?php if ($editing): ?>
            <a class="exc-button exc-button--secondary" href="/admin/exercise_catalogue.php">New Exercise</a>
          <?php endif; ?>
        </div>
      </div>
    </form>
  </section>

  <section class="exc-card">
    <table class="exc-table">
      <thead>
        <tr>
          <th>Exercise</th>
          <th>Aliases</th>
          <th>ACS / SOP</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($exercises as $exercise): ?>
        <tr>
          <td>
            <strong><?= h((string)$exercise['display_name']) ?></strong>
            <div><span class="exc-code"><?= h((string)$exercise['exercise_code']) ?></span></div>
            <?php if (trim((string)$exercise['description_text']) !== ''): ?>
              <div class="exc-muted"><?= h((string)$exercise['description_text']) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <div class="exc-aliases">
              <?php foreach (($exercise['transcript_aliases'] ?? array()) as $alias): ?>
                <span class="exc-alias"><?= h((string)$alias) ?></span>
              <?php endforeach; ?>
            </div>
          </td>
          <td>
            <ul class="exc-bindings">
              <?php foreach (($exercise['acs_bindings'] ?? array()) as $acs): ?>
                <li>ACS <?= h((string)$acs['acs_task_code']) ?> · <?= h((string)$acs['qualification_code']) ?></li>
              <?php endforeach; ?>
              <?php foreach (($exercise['sop_bindings'] ?? array()) as $sop): ?>
                <li>SOP <?= h((string)$sop['sop_code']) ?></li>
              <?php endforeach; ?>
              <?php if (($exercise['acs_bindings'] ?? array()) === array() && ($exercise['sop_bindings'] ?? array()) === array()): ?>
                <li class="exc-muted">No ACS/SOP bindings</li>
              <?php endif; ?>
            </ul>
          </td>
          <td>
            <span class="exc-pill <?= !empty($exercise['is_active']) ? 'exc-pill--on' : 'exc-pill--off' ?>">
              <?= !empty($exercise['is_active']) ? 'Active' : 'Inactive' ?>
            </span>
            <div class="exc-muted">sort <?= h((string)$exercise['sort_order']) ?></div>
          </td>
          <td>
            <a class="exc-button exc-button--secondary" href="/admin/exercise_catalogue.php?code=<?= h(urlencode((string)$exercise['exercise_code'])) ?>">Edit</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$exercises): ?>
        <tr><td colspan="5" class="exc-muted">No exercises available yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </section>
</div>
<?php cw_footer(); ?>
