<?php

ini_set('display_errors', '1');
error_reporting(E_ALL);

declare(strict_types=1);

require_once __DIR__ . '/../../../../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function json_out(array $x): void {
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
        // 1. SAVE REQUEST (SSOT logging)
        // =========================================
        case 'save_request':

            $prompt = trim((string)($data['prompt'] ?? ''));

            if ($prompt === '') {
                json_out(['ok' => false, 'error' => 'Empty prompt']);
            }

            $stmt = $pdo->prepare("
                INSERT INTO ai_jake_requests
                (user_id, prompt, created_at)
                VALUES (?, ?, NOW())
            ");
            $stmt->execute([
                (int)$u['id'],
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

            // prevent directory traversal
            if (str_contains($path, '..')) {
                json_out(['ok' => false, 'error' => 'Invalid path']);
            }

            $fullPath = realpath(__DIR__ . '/../../../' . $path);

            if (!$fullPath || !is_file($fullPath)) {
                json_out(['ok' => false, 'error' => 'File not found']);
            }

            $content = file_get_contents($fullPath);

            json_out([
                'ok' => true,
                'path' => $path,
                'content' => $content
            ]);

        break;

        // =========================================
        // 3. LIST TABLES (READ-ONLY)
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
            $columns = $stmt->fetchAll();

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

            // HARD SAFETY: only allow SELECT
            if (!preg_match('/^\s*SELECT/i', $query)) {
                json_out(['ok' => false, 'error' => 'Only SELECT allowed']);
            }

            $stmt = $pdo->query($query);
            $rows = $stmt->fetchAll();

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

            // V1: simple echo (next step = GPT integration + Steven loop)
            json_out([
                'ok' => true,
                'response' => "Jake received: " . $prompt,
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