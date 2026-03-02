<?php
declare(strict_types=1);

function cw_header(string $title): void {
    $u = cw_current_user();
    $role = (string)($u['role'] ?? 'admin');

    // map supervisor => instructor portal
    $isAdmin = ($role === 'admin');
    $isInstructor = ($role === 'supervisor');
    $isStudent = ($role === 'student');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title) ?></title>
  <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
  <div class="topbar">
    <div class="brand">
      <?php if ($isStudent): ?>
        IPCA Student Portal
      <?php elseif ($isInstructor): ?>
        IPCA Instructor Portal
      <?php else: ?>
        IPCA Courseware Admin
      <?php endif; ?>
    </div>
    <div class="topbar-right">
      <span class="muted"><?= h($u['name'] ?: $u['email']) ?></span>
      <a class="btn btn-sm" href="/logout.php">Logout</a>
    </div>
  </div>

  <div class="shell">
    <nav class="sidebar">

      <?php if ($isAdmin): ?>
        <a href="/admin/dashboard.php">Dashboard</a>
        <a href="/admin/courses.php">Courses</a>
        <a href="/admin/lessons.php">Lessons</a>
        <a href="/admin/slides.php">Slides</a>
        <a href="/admin/import_lab.php">Import Lab</a>
        <a href="/admin/backgrounds.php">Backgrounds</a>
        <a href="/admin/templates.php">Templates</a>
        <hr>
        <a href="/instructor/cohorts.php">Instructor Portal</a>
        <a href="/student/dashboard.php">Student Portal</a>

      <?php elseif ($isInstructor): ?>
        <a href="/instructor/cohorts.php">Cohorts</a>

      <?php elseif ($isStudent): ?>
        <a href="/student/dashboard.php">My Dashboard</a>

      <?php endif; ?>

    </nav>
    <main class="main">
      <h1><?= h($title) ?></h1>
<?php
}

function cw_footer(): void {
?>
    </main>
  </div>
</body>
</html>
<?php
}