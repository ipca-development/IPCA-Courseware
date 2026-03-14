<?php
declare(strict_types=1);

/**
 * Premium app shell layout helpers.
 * Assumes bootstrap.php sets $pdo and session.
 */

require_once __DIR__ . '/navigation.php';

function cw_header(string $title = ''): void
{
    global $pdo;

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    $u = null;
    if (function_exists('cw_current_user')) {
        $u = cw_current_user($pdo);
    }

    $role = is_array($u) ? (string)($u['role'] ?? '') : '';
    $name = is_array($u) ? (string)($u['name'] ?? '') : '';

    $pageTitle = trim($title) !== '' ? trim($title) : 'Dashboard';

    $roleLabel = 'User';
    if ($role === 'admin') {
        $roleLabel = 'Admin';
    } elseif (in_array($role, ['supervisor', 'instructor', 'chief_instructor'], true)) {
        $roleLabel = 'Instructor';
    } elseif ($role === 'student') {
        $roleLabel = 'Student';
    }

    $displayName = $name !== '' ? $name : $roleLabel;
    $logoutHref = '/logout.php';

    $navHtml = cw_render_navigation($role, $path, $roleLabel);
    ?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($pageTitle) ?></title>
  <link rel="stylesheet" href="/assets/app.css">
  <link rel="stylesheet" href="/assets/app-shell.css">
</head>
<body class="app-shell-body">
  <div class="app-shell">
    <aside class="app-sidebar">
      <?= $navHtml ?>

      <div class="app-sidebar-bottom">
        <div class="sidebar-utility-card">
          <div class="sidebar-utility-meta">
            <div class="sidebar-utility-name"><?= h($displayName) ?></div>
            <div class="sidebar-utility-role"><?= h($roleLabel) ?></div>
          </div>

          <div class="sidebar-utility-actions">
            <?php if (function_exists('cw_is_logged_in') && cw_is_logged_in()): ?>
              <a class="utility-action utility-action-logout" href="<?= h($logoutHref) ?>">
                <span class="utility-action-icon"><?= cw_nav_icon_img('logout', 'Logout') ?></span>
                <span class="utility-action-label">Logout</span>
              </a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </aside>

    <div class="app-main-shell">
      <header class="app-topbar">
        <div class="app-topbar-inner">
          <div class="app-topbar-title-block">
            <div class="app-topbar-overline"><?= h($roleLabel) ?></div>
            <h1 class="app-topbar-title"><?= h($pageTitle) ?></h1>
          </div>
        </div>
      </header>

      <main class="app-main">
        <div class="app-content">
<?php
}

function cw_footer(): void
{
    ?>
        </div>
      </main>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      document.querySelectorAll('.nav-icon').forEach(function (img) {
        img.addEventListener('error', function () {
          var png = img.getAttribute('data-png-fallback') || '';
          if (png && img.getAttribute('src') !== png) {
            img.setAttribute('src', png);
            return;
          }
          img.style.visibility = 'hidden';
        });
      });
    });
  </script>
</body>
</html>
<?php
}
