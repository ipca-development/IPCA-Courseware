<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

/**
 * Basic page chrome for admin + student pages.
 * MUST be null-safe (login page has no user).
 */

function cw_header(string $title): void
{
    $u = cw_current_user(); // may be null on login page
    $name = is_array($u) ? (string)($u['name'] ?? '') : '';
    $role = is_array($u) ? (string)($u['role'] ?? '') : '';

    // Detect if we are in admin area (simple heuristic)
    $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

    echo "<!doctype html>\n";
    echo "<html><head>\n";
    echo "  <meta charset='utf-8'>\n";
    echo "  <meta name='viewport' content='width=device-width, initial-scale=1'>\n";
    echo "  <title>" . h($title) . "</title>\n";
    echo "  <link rel='stylesheet' href='/assets/app.css'>\n";
    echo "</head><body>\n";

    echo "<div class='topbar'>\n";
    echo "  <div class='brand'>IPCA Courseware Admin</div>\n";
    echo "  <div class='topbar-right'>\n";

    if ($u) {
        echo "    <span class='muted'>" . h($name !== '' ? $name : 'User') . "</span>\n";
        echo "    <span class='muted' style='margin-left:8px;'>" . h($role) . "</span>\n";
        echo "    <a class='btn btn-sm' href='/logout.php' style='margin-left:10px;'>Logout</a>\n";
    } else {
        // Not logged in (login page)
        echo "    <span class='muted'>Not logged in</span>\n";
    }

    echo "  </div>\n";
    echo "</div>\n";

    // Only show sidebar if logged in AND we are not on login page
    if ($u && $path !== '/login.php') {
        echo "<div class='shell'>\n";
        echo "  <nav class='sidebar'>\n";
        echo "    <a href='/admin/dashboard.php'>Dashboard</a>\n";
        echo "    <a href='/admin/courses.php'>Courses</a>\n";
        echo "    <a href='/admin/lessons.php'>Lessons</a>\n";
        echo "    <a href='/admin/slides.php'>Slides</a>\n";
        echo "    <a href='/admin/import_lab.php'>Import Lab</a>\n";
        echo "    <a href='/admin/backgrounds.php'>Backgrounds</a>\n";
        echo "    <a href='/admin/templates.php'>Templates</a>\n";
        echo "  </nav>\n";
        echo "  <main class='main'>\n";
        echo "    <h1>" . h($title) . "</h1>\n";
    } else {
        // No sidebar layout (login, public pages, etc.)
        echo "<div class='shell'>\n";
        echo "  <main class='main'>\n";
        echo "    <h1>" . h($title) . "</h1>\n";
    }
}

function cw_footer(): void
{
    echo "  </main>\n";
    echo "</div>\n";
    echo "</body></html>\n";
}