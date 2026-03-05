<?php
declare(strict_types=1);

/**
 * Layout helpers for consistent header/footer across Admin/Instructor/Student.
 * Assumes bootstrap.php sets $pdo and session.
 */

function cw_header(string $title = ''): void
{
    global $pdo;

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    // Current user (if logged in)
    $u = null;
    if (function_exists('cw_current_user')) {
        $u = cw_current_user($pdo);
    }
    $role = is_array($u) ? (string)($u['role'] ?? '') : '';
    $name = is_array($u) ? (string)($u['name'] ?? '') : '';

    // Determine "area"
    $area = 'app';
    if (strpos($path, '/admin/') === 0) $area = 'admin';
    if (strpos($path, '/instructor/') === 0) $area = 'instructor';
    if (strpos($path, '/student/') === 0) $area = 'student';

    // Center title logic:
    // If current page title is "Dashboard" (or empty), show the role-specific dashboard title.
    $centerTitle = $title !== '' ? $title : 'Dashboard';
    if (strcasecmp($centerTitle, 'Dashboard') === 0) {
        if ($area === 'admin') $centerTitle = 'Admin Dashboard';
        elseif ($area === 'instructor') $centerTitle = 'Instructor Dashboard';
        elseif ($area === 'student') $centerTitle = 'Student Dashboard';
        else $centerTitle = 'Dashboard';
    }

    // If someone calls cw_header("IPCA Courseware Admin") etc, normalize it too
    $lower = strtolower($centerTitle);
    if (strpos($lower, 'ipca courseware') !== false && strpos($lower, 'dashboard') === false) {
        if (strpos($lower, 'admin') !== false) $centerTitle = 'Admin Dashboard';
        elseif (strpos($lower, 'instructor') !== false || strpos($lower, 'supervisor') !== false) $centerTitle = 'Instructor Dashboard';
        elseif (strpos($lower, 'student') !== false) $centerTitle = 'Student Dashboard';
    }

    // Logout link
    $logoutHref = '/logout.php';
    if ($area === 'student') $logoutHref = '/logout.php';
    if ($area === 'instructor') $logoutHref = '/logout.php';
    if ($area === 'admin') $logoutHref = '/logout.php';

    // Display role label (right)
    $roleLabel = 'User';
    if ($role === 'admin') $roleLabel = 'Admin';
    elseif ($role === 'supervisor') $roleLabel = 'Instructor';
    elseif ($role === 'student') $roleLabel = 'Student';

    // Safe display name
    $displayName = $name !== '' ? $name : $roleLabel;

    // Output HTML
    ?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($centerTitle) ?></title>
  <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
  <div class="topbar">
    <div class="topbar-left">
      <img src="/assets/logo/ipca_logo_white.png" class="logo-ipca" alt="IPCA">
    </div>

    <div class="topbar-center">
      <?= h($centerTitle) ?>
    </div>

    <div class="topbar-right">
      <span class="muted" style="color:rgba(255,255,255,0.85);"><?= h($roleLabel) ?></span>
      <?php if (function_exists('cw_is_logged_in') && cw_is_logged_in()): ?>
        <a class="btn btn-sm" href="<?= h($logoutHref) ?>">Logout</a>
      <?php else: ?>
        <span class="muted" style="color:rgba(255,255,255,0.70);"><?= h($displayName) ?></span>
      <?php endif; ?>
    </div>
  </div>

  <div class="shell">
<?php
    // Sidebar visibility rules:
    // - Admin sees admin sidebar
    // - Instructor sees instructor sidebar
    // - Student sees student sidebar
    ?>
    <nav class="sidebar">
      <?php if ($area === 'admin'): ?>
        <a href="/admin/dashboard.php">Dashboard</a>
        <a href="/admin/courses.php">Courses</a>
        <a href="/admin/lessons.php">Lessons</a>
        <a href="/admin/slides.php">Slides</a>
        <a href="/admin/import_lab.php">Import Lab</a>
        <a href="/admin/backgrounds.php">Backgrounds</a>
        <a href="/admin/templates.php">Templates</a>
        <hr style="opacity:.25;">
        <a href="/instructor/dashboard.php">Instructor Portal</a>
        <a href="/student/dashboard.php">Student Portal</a>

      <?php elseif ($area === 'instructor'): ?>
        <a href="/instructor/dashboard.php">Dashboard</a>
        <a href="/instructor/cohorts.php">Theory Training</a>
        <hr style="opacity:.25;">
        <a href="/admin/dashboard.php">Admin</a>

      <?php elseif ($area === 'student'): ?>
        <a href="/student/dashboard.php">Dashboard</a>
        <a href="/student/dashboard.php">Theory Training</a>

      <?php else: ?>
        <a href="/admin/dashboard.php">Admin</a>
        <a href="/instructor/dashboard.php">Instructor</a>
        <a href="/student/dashboard.php">Student</a>
      <?php endif; ?>
    </nav>

    <main class="main">
<?php
}

function cw_footer(): void
{
    ?>
    </main>
  </div>
</body>
</html>
<?php
}