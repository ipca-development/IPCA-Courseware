<?php
declare(strict_types=1);

function cw_header(string $title): void {
    $u = cw_current_user();
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
    <div class="brand">IPCA Courseware Admin</div>
    <div class="topbar-right">
      <span class="muted"><?= h($u['name'] ?: $u['email']) ?></span>
      <a class="btn btn-sm" href="/logout.php">Logout</a>
    </div>
  </div>
  <div class="shell">
    <nav class="sidebar">
      <a href="/admin/dashboard.php">Dashboard</a>
      <a href="/admin/courses.php">Courses</a>
      <a href="/admin/lessons.php">Lessons</a>
      <a href="/admin/slides.php">Slides</a>
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