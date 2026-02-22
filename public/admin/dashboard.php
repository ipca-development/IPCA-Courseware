<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
cw_require_admin();

$stats = [
  'courses' => (int)$pdo->query("SELECT COUNT(*) FROM courses")->fetchColumn(),
  'lessons' => (int)$pdo->query("SELECT COUNT(*) FROM lessons")->fetchColumn(),
  'slides'  => (int)$pdo->query("SELECT COUNT(*) FROM slides")->fetchColumn(),
];

cw_header('Dashboard');
?>
<div class="grid">
  <div class="stat">Courses<br><strong><?= $stats['courses'] ?></strong></div>
  <div class="stat">Lessons<br><strong><?= $stats['lessons'] ?></strong></div>
  <div class="stat">Slides<br><strong><?= $stats['slides'] ?></strong></div>
</div>
<p class="muted">Next: create a course, add lessons, then “Generate Slides”.</p>
<?php cw_footer(); ?>