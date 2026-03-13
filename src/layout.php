<?php
declare(strict_types=1);

/**
 * Layout helpers for consistent header/footer across Admin/Instructor/Student.
 * Assumes bootstrap.php sets $pdo and session.
 */

require_once __DIR__ . '/navigation.php';

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

    // Display role label (right)
    $roleLabel = 'User';
    if ($role === 'admin') $roleLabel = 'Admin';
    elseif ($role === 'supervisor' || $role === 'instructor' || $role === 'chief_instructor') $roleLabel = 'Instructor';
    elseif ($role === 'student') $roleLabel = 'Student';

    // Safe display name
    $displayName = $name !== '' ? $name : $roleLabel;

    $navHtml = cw_render_navigation($role, $path);

    // Output HTML
    ?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($centerTitle) ?></title>
  <link rel="stylesheet" href="/assets/app.css">
	<style>
    :root{
      --cw-topbar-h: 58px;
      --cw-sidebar-bg: #123b72;
    }

    html, body{
      margin:0;
      padding:0;
    }

    body{
      background:#f3f4f6;
    }

    .topbar{
      position: sticky;
      top: 0;
      z-index: 1000;
      min-height: var(--cw-topbar-h);
    }

    .shell{
      display:flex;
      align-items:flex-start;
      min-height: calc(100vh - var(--cw-topbar-h));
    }

    .sidebar{
      position: sticky;
      top: var(--cw-topbar-h);
      align-self:flex-start;
      height: calc(100vh - var(--cw-topbar-h));
      overflow-y: auto;
      background: var(--cw-sidebar-bg);
      flex: 0 0 240px;
    }

    .main{
      flex:1 1 auto;
      min-width:0;
    }

    /* Make centralized nav look like a real sidebar, not a floating card */
    .sidebar .cw-nav-shell{
      margin:0;
      padding:0;
      border:none;
      border-radius:0;
      box-shadow:none;
      background:transparent;
    }

    .sidebar .cw-nav-groups{
      display:block;
    }

    .sidebar .cw-nav-group{
      margin:0 0 14px 0;
    }

    .sidebar .cw-nav-group-title{
      padding:10px 20px 6px 20px;
      margin:0;
      font-size:11px;
      font-weight:800;
      text-transform:uppercase;
      letter-spacing:.04em;
      color:rgba(255,255,255,0.55);
    }

    .sidebar .cw-nav-list{
      display:flex;
      flex-direction:column;
      gap:2px;
    }

    .sidebar .cw-nav-link{
      display:block;
      padding:10px 20px;
      border:none;
      border-radius:0;
      background:transparent;
      color:rgba(255,255,255,0.90);
      text-decoration:none;
      font-size:15px;
      font-weight:700;
    }

    .sidebar .cw-nav-link:hover{
      background:rgba(255,255,255,0.08);
      color:#ffffff;
      border:none;
    }

    .sidebar .cw-nav-link.current{
      background:rgba(255,255,255,0.14);
      color:#ffffff;
      border:none;
    }

    .sidebar .cw-nav-link.disabled{
      background:transparent;
      color:rgba(255,255,255,0.35);
      border:none;
      pointer-events:none;
      cursor:default;
    }
  </style>
	
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
    <nav class="sidebar">
      <?php if ($navHtml !== ''): ?>
        <?= $navHtml ?>
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
