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

<meta name="theme-color" content="#1e3c72">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="IPCA Academy">

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">

<link rel="icon" type="image/svg+xml" href="/favicon.svg">

<link rel="apple-touch-icon" href="/favicon-180x180.png">

<link rel="manifest" href="/site.webmanifest">
	

<link rel="stylesheet" href="/assets/app.css">
<link rel="stylesheet" href="/assets/app-shell.css">
</head>
<body class="app-shell-body">
  <div class="app-shell" id="appShell">
    <div class="app-mobile-overlay" id="appMobileOverlay"></div>

<aside class="app-sidebar" id="appSidebar">
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
      <div class="app-topbar-left">
        <button
          class="app-mobile-menu-toggle"
          id="appMobileMenuToggle"
          type="button"
          aria-label="Open navigation menu"
          aria-controls="appSidebar"
          aria-expanded="false">
          <span class="app-mobile-menu-toggle-lines" aria-hidden="true">
            <span></span>
            <span></span>
            <span></span>
          </span>
        </button>

        <div class="app-topbar-title-block">
          <div class="app-topbar-overline"><?= h($roleLabel) ?></div>
          <h1 class="app-topbar-title"><?= h($pageTitle) ?></h1>
        </div>
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

      var shell = document.getElementById('appShell');
      var sidebar = document.getElementById('appSidebar');
      var overlay = document.getElementById('appMobileOverlay');
      var toggle = document.getElementById('appMobileMenuToggle');
      var mobileQuery = window.matchMedia('(max-width: 820px)');

      function isMobile() {
        return mobileQuery.matches;
      }

      function openMenu() {
        if (!shell || !toggle || !isMobile()) {
          return;
        }
        shell.classList.add('is-mobile-nav-open');
        document.body.classList.add('is-mobile-nav-open');
        toggle.setAttribute('aria-expanded', 'true');
      }

      function closeMenu() {
        if (!shell || !toggle) {
          return;
        }
        shell.classList.remove('is-mobile-nav-open');
        document.body.classList.remove('is-mobile-nav-open');
        toggle.setAttribute('aria-expanded', 'false');
      }

      function toggleMenu() {
        if (!shell || !isMobile()) {
          return;
        }
        if (shell.classList.contains('is-mobile-nav-open')) {
          closeMenu();
        } else {
          openMenu();
        }
      }

      if (toggle) {
        toggle.addEventListener('click', function () {
          toggleMenu();
        });
      }

      if (overlay) {
        overlay.addEventListener('click', function () {
          closeMenu();
        });
      }

      if (sidebar) {
        sidebar.querySelectorAll('a[href]').forEach(function (link) {
          link.addEventListener('click', function () {
            if (isMobile()) {
              closeMenu();
            }
          });
        });
      }

      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
          closeMenu();
        }
      });

      function handleViewportChange() {
        if (!isMobile()) {
          closeMenu();
        }
      }

      if (typeof mobileQuery.addEventListener === 'function') {
        mobileQuery.addEventListener('change', handleViewportChange);
      } else if (typeof mobileQuery.addListener === 'function') {
        mobileQuery.addListener(handleViewportChange);
      }

      handleViewportChange();
    });
  </script>
</body>
</html>
<?php
}
