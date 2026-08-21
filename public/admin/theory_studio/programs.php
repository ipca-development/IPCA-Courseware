<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';

$svc = new TheoryContentStudioService($pdo);
$programs = $svc->listPrograms();
$csrf = theory_studio_csrf_token();

cw_header('Theory Training Programs');
theory_studio_emit_assets();
echo '<meta name="theory-studio-csrf" content="' . h($csrf) . '">';

compliance_page_open(array(
    'overline' => 'Theory Training',
    'title' => 'Theory Training Programs',
    'description' => 'Inspect Live production programs and author isolated Studio Draft programs. Existing Live content cannot be modified here.',
    'actions' => array(
        array(
            'label' => 'Add New Program',
            'modal' => 'ts-add-program',
            'variant' => 'primary',
        ),
    ),
));
?>
<div class="ts-page">
  <div class="ts-program-grid">
    <?php foreach ($programs as $program): ?>
      <?php $cover = theory_studio_cover_url((string)$program['cover_image_path']); ?>
      <a class="ts-program-card" href="/admin/theory_studio/courses.php?program_id=<?= (int)$program['id'] ?>">
        <div class="ts-cover">
          <?php if ($cover !== ''): ?>
            <img src="<?= h($cover) ?>" alt="">
          <?php else: ?>
            <?= h((string)$program['program_key']) ?>
          <?php endif; ?>
        </div>
        <div>
          <h2><?= h((string)$program['name']) ?></h2>
          <?= TheoryStudioUi::statusPills($program) ?>
          <p class="ts-meta">
            Revision <?= h((string)$program['revision_number']) ?>
            <?php if ((string)$program['revision_date'] !== ''): ?>
              · <?= h((string)$program['revision_date']) ?>
            <?php endif; ?>
            · <?= (int)$program['course_count'] ?> course<?= (int)$program['course_count'] === 1 ? '' : 's' ?>
          </p>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
  <?php if (!$programs): ?>
    <div class="ts-empty">No programs are available yet.</div>
  <?php endif; ?>
</div>

<dialog class="compliance-modal ts-modal" id="ts-add-program">
  <div class="compliance-modal__panel">
    <div class="compliance-modal__header">
      <h2 class="compliance-modal__title">Add New Program</h2>
      <button type="button" class="app-btn app-btn-secondary" data-ts-modal-close>&times;</button>
    </div>
    <form class="ts-form" data-ts-form="create_program">
      <label for="ts-program-name">Name</label>
      <input id="ts-program-name" name="name" required>
      <label for="ts-program-key">Stable Program Key</label>
      <input id="ts-program-key" name="program_key" required pattern="[a-z][a-z0-9_]{1,63}" placeholder="commercial_pilot">
      <label for="ts-revision-number">Revision Number</label>
      <input id="ts-revision-number" name="revision_number" value="1.0" required>
      <label for="ts-revision-date">Revision Date</label>
      <input id="ts-revision-date" name="revision_date" type="date">
      <label for="ts-program-cover">Image</label>
      <input id="ts-program-cover" name="cover" type="file" accept="image/jpeg,image/png,image/webp">
      <p class="ts-meta">Status is Draft. Studio Draft programs are authoring-only and cannot be assigned to cohorts.</p>
      <div class="ts-form-actions">
        <button type="button" class="app-btn app-btn-secondary" data-ts-modal-close>Cancel</button>
        <button type="submit" class="app-btn app-btn-primary">Create Draft Program</button>
      </div>
    </form>
  </div>
</dialog>
<?php
compliance_page_close();
cw_footer();
