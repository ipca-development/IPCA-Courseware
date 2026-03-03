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
<?php if ($u): ?>
  <div class="topbar">
    <div class="brand">IPCA Courseware Admin</div>
    <div class="topbar-right">
      <span class="muted"><?= h(($u['name'] ?? '') !== '' ? (string)$u['name'] : (string)($u['email'] ?? '')) ?></span>
      <a class="btn btn-sm" href="/logout.php">Logout</a>
    </div>
  </div>

  <div class="shell">
    <nav class="sidebar">
      <a href="/admin/dashboard.php">Dashboard</a>
      <a href="/admin/courses.php">Courses</a>
      <a href="/admin/lessons.php">Lessons</a>
      <a href="/admin/slides.php">Slides</a>
      <a href="/admin/import_lab.php">Import Lab</a>
      <a href="/admin/backgrounds.php">Backgrounds</a>
      <a href="/admin/templates.php">Templates</a>

      <div style="margin-top:14px; border-top:1px solid rgba(255,255,255,0.10); padding-top:12px;">
        <a href="/instructor/cohorts.php">Instructor Portal</a>
        <a href="/student/dashboard.php">Student Portal</a>
      </div>
    </nav>

    <main class="main">
      <h1><?= h($title) ?></h1>

<?php else: ?>
  <div style="padding:18px;">
    <h1 style="margin:0 0 14px 0; font-family: system-ui; font-size: 22px; color:#1e3c72;">IPCA Courseware</h1>
    <div style="max-width:980px;">
<?php endif; ?>
<?php
}

function cw_footer(): void {
    $u = cw_current_user();
    if ($u) {
        ?>
    </main>
  </div>
</body>
</html>
<?php
        return;
    }
    ?>
    </div>
  </div>
</body>
</html>
<?php
}