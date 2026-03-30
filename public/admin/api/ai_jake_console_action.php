<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

cw_require_login();

header('Content-Type: application/json; charset=utf-8');

function jake_json_out(array $x): void
{
    echo json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jake_h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function jake_normalize_path(string $path): string
{
    $path = trim($path);
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('~/+~', '/', $path);
    $path = ltrim($path, '/');
    return $path;
}

function jake_is_allowed_path(string $path): bool
{
    $allowedPrefixes = [
        'src/',
        'public/',
        'admin/',
        'assets/',
    ];

    foreach ($allowedPrefixes as $prefix) {
        if (strpos($path, $prefix) === 0) {
            return true;
        }
    }

    return false;
}

function jake_get_project_root(): string
{
    return dirname(__DIR__, 2);
}

function jake_load_latest_ssot(PDO $pdo): array
{
    $stmt = $pdo->prepare("
        SELECT id, version, title, content, created_at
        FROM ssot_versions
        ORDER BY created_at DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: [];
}

try {
    $u = cw_current_user($pdo);

    // TODO: replace 1 with your own user ID if needed
    if ((int)($u['id'] ?? 0) !== 1) {
        http_response_code(403);
        jake_json_out([
            'ok' => false,
            'error' => 'Forbidden'
        ]);
    }

    $raw = file_get_contents('php://input');
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        jake_json_out([
            'ok' => false,
            'error' => 'Invalid JSON'
        ]);
    }

    $action = (string)($data['action'] ?? '');

    if ($action === 'list_tables') {
        $rows = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_NUM);
        $tables = [];
        foreach ($rows as $r) {
            if (isset($r[0])) {
                $tables[] = (string)$r[0];
            }
        }

        jake_json_out([
            'ok' => true,
            'action' => 'list_tables',
            'count' => count($tables),
            'tables' => $tables
        ]);
    }

    if ($action === 'describe_table') {
        $table = trim((string)($data['table'] ?? ''));
        if ($table === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            jake_json_out([
                'ok' => false,
                'error' => 'Invalid table name'
            ]);
        }

        $stmt = $pdo->query("DESCRIBE `" . $table . "`");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jake_json_out([
            'ok' => true,
            'action' => 'describe_table',
            'table' => $table,
            'columns' => $columns
        ]);
    }

    if ($action === 'list_files') {
        $root = jake_get_project_root();

        $targets = [
            $root . '/src',
            $root . '/public',
            $root . '/admin',
        ];

        $result = [];

        foreach ($targets as $base) {
            if (!is_dir($base)) {
                continue;
            }

            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($it as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $full = str_replace('\\', '/', $file->getPathname());
                $rel = ltrim(str_replace(str_replace('\\', '/', $root), '', $full), '/');

                if (preg_match('/\.(php|sql|js|css|json|md)$/i', $rel)) {
                    $result[] = $rel;
                }
            }
        }

        sort($result);

        jake_json_out([
            'ok' => true,
            'action' => 'list_files',
            'count' => count($result),
            'files' => $result
        ]);
    }

    if ($action === 'read_file') {
        $path = jake_normalize_path((string)($data['path'] ?? ''));

        if ($path === '' || !jake_is_allowed_path($path)) {
            jake_json_out([
                'ok' => false,
                'error' => 'Path not allowed'
            ]);
        }

        $full = jake_get_project_root() . '/' . $path;
        $real = realpath($full);
        $root = realpath(jake_get_project_root());

        if ($real === false || $root === false || strpos($real, $root) !== 0 || !is_file($real)) {
            jake_json_out([
                'ok' => false,
                'error' => 'File not found'
            ]);
        }

        $content = file_get_contents($real);
        if ($content === false) {
            jake_json_out([
                'ok' => false,
                'error' => 'Could not read file'
            ]);
        }

        jake_json_out([
            'ok' => true,
            'action' => 'read_file',
            'path' => $path,
            'bytes' => strlen($content),
            'content' => $content
        ]);
    }

    if ($action === 'ask_jake') {
        $prompt = trim((string)($data['prompt'] ?? ''));
        if ($prompt === '') {
            jake_json_out([
                'ok' => false,
                'error' => 'Prompt is required'
            ]);
        }

        $ssot = jake_load_latest_ssot($pdo);
        $ssotVersion = (string)($ssot['version'] ?? 'unknown');
        $ssotContent = (string)($ssot['content'] ?? '');

        $lower = strtolower($prompt);
        $files = [];
        $checks = [];
        $notes = [];
        $nextStep = 'Inspect relevant file and verify DB truth before coding.';

        if (strpos($lower, 'progress test') !== false || strpos($lower, 'test_finalize') !== false) {
            $files[] = 'public/student/api/test_finalize_v2.php';
            $files[] = 'src/courseware_progression_v2.php';
            $checks[] = 'Confirm controller only owns assessment pipeline';
            $checks[] = 'Confirm engine owns progression consequences';
            $checks[] = 'Confirm no duplicate writes to lesson_activity or student_required_actions';
            $notes[] = 'This area has already shown drift risk in this chat.';
            $nextStep = 'Read both files and compare ownership block-by-block.';
        }

        if (strpos($lower, 'summary') !== false) {
            $files[] = 'public/instructor/summary_review.php';
            $files[] = 'src/courseware_progression_v2.php';
            $checks[] = 'Confirm decision values align with engine expectations';
        }

        if (strpos($lower, 'instructor approval') !== false) {
            $files[] = 'public/instructor/instructor_approval.php';
            $files[] = 'src/courseware_progression_v2.php';
            $checks[] = 'Confirm page is thin and role-protected';
        }

        if (empty($files)) {
            $notes[] = 'No direct file match inferred yet.';
            $checks[] = 'Clarify file scope or inspect manually';
        }

        $ssotHints = [];
        if ($ssotContent !== '') {
            if (stripos($ssotContent, 'single source of truth') !== false) {
                $ssotHints[] = 'SSOT emphasizes central ownership and anti-drift behavior.';
            }
            if (stripos($ssotContent, 'lesson_activity') !== false) {
                $ssotHints[] = 'lesson_activity is projection, not canonical workflow authority.';
            }
            if (stripos($ssotContent, 'student_required_actions') !== false) {
                $ssotHints[] = 'student_required_actions is workflow/intervention authority.';
            }
            if (stripos($ssotContent, 'training_progression_emails') !== false) {
                $ssotHints[] = 'emails are outputs, never state.';
            }
        }

        $files = array_values(array_unique($files));
        $checks = array_values(array_unique($checks));
        $notes = array_values(array_unique($notes));
        $ssotHints = array_values(array_unique($ssotHints));

        jake_json_out([
            'ok' => true,
            'action' => 'ask_jake',
            'jake' => [
                'ssot_version_used' => $ssotVersion,
                'root_cause_hypothesis' => 'Likely ownership drift or duplicated progression logic unless proven otherwise.',
                'affected_files' => $files,
                'ssot_hints' => $ssotHints,
                'verification_checks' => $checks,
                'notes' => $notes,
                'next_step' => $nextStep
            ]
        ]);
    }

    jake_json_out([
        'ok' => false,
        'error' => 'Unknown action'
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    jake_json_out([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}