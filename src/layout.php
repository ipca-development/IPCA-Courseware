<?php
declare(strict_types=1);

function cw_header(string $title): void {
    $u = cw_current_user(); // may be null on login
    $role = $u ? (string)($u['role'] ?? '') : '';
    $name = $u ? (string)($u['name'] ?? $u['email'] ?? 'User') : '';

    $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $isLogin = (strpos($path, '/login.php') !== false);

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
      <?php if ($u): ?>
        <span class="muted"><?= h($name) ?></span>
        <a class="btn btn-sm" href="/logout.php">Logout</a>
      <?php else: ?>
        <span class="muted">Not logged in</span>
      <?php endif; ?>
    </div>
  </div>

  <div class="shell">
    <?php if ($u && !$isLogin): ?>
      <nav class="sidebar">
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
      </nav>
    <?php endif; ?>

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