<?php
declare(strict_types=1);

require_once __DIR__ . '/theory_studio/_bootstrap.php';
require_once __DIR__ . '/../../src/compliance/ComplianceUi.php';

$svc = new TheoryContentStudioService($pdo);
$statusSvc = new TheoryLessonEnhancementStatusService($pdo);
$programId = (int)($_GET['program_id'] ?? 0);
$programs = $svc->listPrograms();
if ($programId <= 0 && $programs) {
    $programId = (int)$programs[0]['id'];
}

$rows = array();
if ($programId > 0) {
    $program = $svc->getProgram($programId);
    $pending = array();
    $lessonIds = array();
    foreach ($svc->listCourses($programId) as $course) {
        foreach ($svc->listLessons((int)$course['id']) as $lesson) {
            $pending[] = array($course, $lesson);
            $lessonIds[] = (int)$lesson['id'];
        }
    }
    $chips = array();
    try {
        $chips = $statusSvc->forLessons($lessonIds);
    } catch (Throwable $e) {
        error_log('Theory Studio question chips failed: ' . $e->getMessage());
    }
    foreach ($pending as $item) {
        [$course, $lesson] = $item;
        $status = $chips[(int)$lesson['id']] ?? array('chips' => array());
        $question = array('tone' => 'muted', 'label' => 'Missing');
        foreach (($status['chips'] ?? array()) as $chip) {
            if (($chip['key'] ?? '') === 'questions') {
                $question = $chip;
                break;
            }
        }
        $rows[] = array(
            'program' => $program,
            'course' => $course,
            'lesson' => $lesson,
            'question' => $question,
        );
    }
}

cw_header('Question Manager');
theory_studio_emit_assets();

compliance_page_open(array(
    'overline' => 'Theory Training',
    'title' => 'Question Manager',
    'description' => 'Read-only status of existing progress-test banks. Generating or replacing questions for Live lessons remains on Slide Bulk Enrich. Studio does not mutate Live banks.',
    'actions' => array(
        array(
            'label' => 'Open Slide Bulk Enrich',
            'href' => '/admin/bulk_enrich.php',
            'variant' => 'primary',
        ),
    ),
));
?>
<form method="get" class="ts-form" style="max-width:420px;margin-bottom:18px;">
  <label for="qm-program">Program</label>
  <select id="qm-program" name="program_id" onchange="this.form.submit()">
    <?php foreach ($programs as $program): ?>
      <option value="<?= (int)$program['id'] ?>" <?= (int)$program['id'] === $programId ? 'selected' : '' ?>>
        <?= h((string)$program['name']) ?>
      </option>
    <?php endforeach; ?>
  </select>
</form>
<table class="ts-question-table">
  <thead>
    <tr>
      <th>Course</th>
      <th>Lesson</th>
      <th>Bank status</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($rows as $row): ?>
      <tr>
        <td><?= h((string)$row['course']['title']) ?></td>
        <td><?= h((string)$row['lesson']['title']) ?></td>
        <td>
          <span class="<?= h(TheoryStudioUi::chipClass((string)$row['question']['tone'])) ?>">
            <?= h((string)$row['question']['label']) ?>
          </span>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php if (!$rows): ?>
  <div class="ts-empty">No lessons in this program.</div>
<?php endif; ?>
<?php
compliance_page_close();
cw_footer();
