<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function json_out(array $x): void
{
    echo json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    // =========================
    // AUTH
    // =========================
    $u = cw_current_user($pdo);

    if (!$u || ($u['role'] ?? '') !== 'admin') {
        http_response_code(403);
        json_out(['ok' => false, 'error' => 'Forbidden']);
    }

    // =========================
    // INPUT
    // =========================
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        json_out(['ok' => false, 'error' => 'Invalid JSON']);
    }

    $action = (string)($data['action'] ?? '');

    // =========================
    // ROUTER
    // =========================
    switch ($action) {

        // =========================================
        // 1. SAVE REQUEST
        // =========================================
        case 'save_request':

            $prompt = trim((string)($data['prompt'] ?? ''));
            $title  = trim((string)($data['title'] ?? 'Untitled request'));
            $type   = trim((string)($data['type'] ?? 'investigation'));

            if ($prompt === '') {
                json_out(['ok' => false, 'error' => 'Empty prompt']);
            }

            $stmt = $pdo->prepare("
                INSERT INTO ai_jake_requests
                (
                    user_id,
                    request_title,
                    request_type,
                    prompt,
                    status,
                    created_at,
                    updated_at
                )
                VALUES
                (
                    ?, ?, ?, ?, 'new', NOW(), NOW()
                )
            ");
            $stmt->execute([
                (int)$u['id'],
                $title,
                $type,
                $prompt
            ]);

            json_out([
                'ok' => true,
                'request_id' => (int)$pdo->lastInsertId()
            ]);

        break;

        // =========================================
        // 2. READ FILE (SAFE)
        // =========================================
        case 'read_file':

            $path = trim((string)($data['path'] ?? ''));

            if ($path === '') {
                json_out(['ok' => false, 'error' => 'Missing path']);
            }

            if (str_contains($path, '..')) {
                json_out(['ok' => false, 'error' => 'Invalid path']);
            }

            $basePath = realpath(__DIR__ . '/../../../');
            if ($basePath === false) {
                json_out(['ok' => false, 'error' => 'Base path not found']);
            }

            $fullPath = realpath($basePath . '/' . ltrim($path, '/'));
            if (!$fullPath || !is_file($fullPath)) {
                json_out(['ok' => false, 'error' => 'File not found']);
            }

            if (strpos($fullPath, $basePath) !== 0) {
                json_out(['ok' => false, 'error' => 'Invalid path scope']);
            }

            $content = file_get_contents($fullPath);
            if ($content === false) {
                json_out(['ok' => false, 'error' => 'Failed to read file']);
            }

            json_out([
                'ok' => true,
                'path' => $path,
                'content' => $content
            ]);

        break;

        // =========================================
        // 3. LIST TABLES
        // =========================================
        case 'list_tables':

            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

            json_out([
                'ok' => true,
                'tables' => $tables
            ]);

        break;

        // =========================================
        // 4. DESCRIBE TABLE
        // =========================================
        case 'describe_table':

            $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($data['table'] ?? ''));

            if ($table === '') {
                json_out(['ok' => false, 'error' => 'Invalid table']);
            }

            $stmt = $pdo->query("DESCRIBE `$table`");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

            json_out([
                'ok' => true,
                'table' => $table,
                'columns' => $columns
            ]);

        break;

        // =========================================
        // 5. SAFE SQL (READ ONLY)
        // =========================================
        case 'run_sql_read':

            $query = trim((string)($data['query'] ?? ''));

            if ($query === '') {
                json_out(['ok' => false, 'error' => 'Empty query']);
            }

            if (!preg_match('/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', $query)) {
                json_out(['ok' => false, 'error' => 'Only SELECT, SHOW, DESCRIBE, EXPLAIN allowed']);
            }

            if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|CREATE|REPLACE|GRANT|REVOKE)\b/i', $query)) {
                json_out(['ok' => false, 'error' => 'Write operations are not allowed']);
            }

            $stmt = $pdo->query($query);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            json_out([
                'ok' => true,
                'rows' => $rows,
                'count' => count($rows)
            ]);

        break;

        // =========================================
        // 6. JAKE RESPONSE (V1 STUB)
        // =========================================
        case 'jake_think':

            $prompt = trim((string)($data['prompt'] ?? ''));

            json_out([
                'ok' => true,
                'response' => 'Jake received: ' . $prompt,
                'notes' => 'V1 stub — GPT + Steven integration next'
            ]);

        break;

        // =========================================
        // UNKNOWN
        // =========================================
        default:
            json_out(['ok' => false, 'error' => 'Unknown action']);
    }

} catch (Throwable $e) {
    http_response_code(400);
    json_out([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}