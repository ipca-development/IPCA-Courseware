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

function normalize_rel_path(string $path): string
{
    $path = trim($path);
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#/+#', '/', $path);
    $path = ltrim($path, '/');

    if ($path === '' || strpos($path, '..') !== false) {
        throw new RuntimeException('Invalid path');
    }

    return $path;
}

function project_root_path(): string
{
    $root = realpath(__DIR__ . '/../../../');
    return $root !== false ? $root : (__DIR__ . '/../../../');
}

function safe_project_file_read(string $relativePath): array
{
    $relativePath = normalize_rel_path($relativePath);

    $root = rtrim(project_root_path(), '/');
    $fullPath = realpath($root . '/' . $relativePath);

    if ($fullPath === false || !is_file($fullPath)) {
        throw new RuntimeException('File not found');
    }

    if (strpos($fullPath, $root) !== 0) {
        throw new RuntimeException('Invalid path');
    }

    $content = file_get_contents($fullPath);
    if ($content === false) {
        throw new RuntimeException('Unable to read file');
    }

    return [
        'path' => $relativePath,
        'full_path' => $fullPath,
        'content' => $content,
        'basename' => basename($relativePath),
        'size_bytes' => filesize($fullPath) ?: 0,
    ];
}

function table_exists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = ?
    ");
    $stmt->execute([$tableName]);
    return (int)$stmt->fetchColumn() > 0;
}

function load_latest_ssot_snapshot(PDO $pdo): ?array
{
    if (!table_exists($pdo, 'ai_ssot_snapshots')) {
        return null;
    }

    $stmt = $pdo->query("
        SELECT *
        FROM ai_ssot_snapshots
        ORDER BY id DESC
        LIMIT 1
    ");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function load_request_row(PDO $pdo, int $requestId): ?array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM ai_jake_requests
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$requestId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function extract_file_candidates_from_text(string $text): array
{
    $matches = [];

    if (preg_match_all('#([A-Za-z0-9_\-\/]+\.(php|js|css|sql|json|md|txt))#i', $text, $m)) {
        foreach ($m[1] as $path) {
            $path = trim((string)$path);
            if ($path !== '') {
                $matches[] = ltrim(str_replace('\\', '/', $path), '/');
            }
        }
    }

    return array_values(array_unique($matches));
}

function read_files_for_context(array $paths, int $limit = 3): array
{
    $out = [];
    $count = 0;

    foreach ($paths as $path) {
        if ($count >= $limit) {
            break;
        }

        try {
            $file = safe_project_file_read((string)$path);
            $out[] = [
                'path' => $file['path'],
                'basename' => $file['basename'],
                'size_bytes' => $file['size_bytes'],
                'content_excerpt' => mb_substr((string)$file['content'], 0, 8000),
            ];
            $count++;
        } catch (Throwable $e) {
            $out[] = [
                'path' => (string)$path,
                'error' => $e->getMessage(),
            ];
        }
    }

    return $out;
}

function build_jake_summary(array $requestRow, ?array $ssot, array $contextFiles): string
{
    $title = trim((string)($requestRow['request_title'] ?? 'Untitled request'));
    $type = trim((string)($requestRow['request_type'] ?? 'investigation'));
    $prompt = trim((string)($requestRow['prompt'] ?? ''));

    $ssotVersion = $ssot ? trim((string)($ssot['ssot_version'] ?? 'unknown')) : 'none';
    $ssotTitle = $ssot ? trim((string)($ssot['title'] ?? '')) : '';

    $lines = [];
    $lines[] = 'Jake analysis prepared.';
    $lines[] = '';
    $lines[] = 'Request Title: ' . $title;
    $lines[] = 'Request Type: ' . $type;
    $lines[] = 'SSOT Version: ' . $ssotVersion . ($ssotTitle !== '' ? ' (' . $ssotTitle . ')' : '');
    $lines[] = '';

    if ($prompt !== '') {
        $lines[] = 'Initial Request:';
        $lines[] = $prompt;
        $lines[] = '';
    }

    if ($contextFiles) {
        $lines[] = 'Context Files Loaded:';
        foreach ($contextFiles as $f) {
            if (!empty($f['error'])) {
                $lines[] = '- ' . $f['path'] . ' [read failed: ' . $f['error'] . ']';
            } else {
                $lines[] = '- ' . $f['path'];
            }
        }
        $lines[] = '';
    } else {
        $lines[] = 'Context Files Loaded: none';
        $lines[] = '';
    }

    $lines[] = 'Next internal action: Jake prepared a Steven implementation brief and artifact candidate.';
    return implode("\n", $lines);
}

function build_steven_brief(array $requestRow, ?array $ssot, array $contextFiles): string
{
    $title = trim((string)($requestRow['request_title'] ?? 'Untitled request'));
    $type = trim((string)($requestRow['request_type'] ?? 'investigation'));
    $prompt = trim((string)($requestRow['prompt'] ?? ''));

    $brief = [];
    $brief[] = 'STEVEN IMPLEMENTATION BRIEF';
    $brief[] = '';
    $brief[] = 'Request Title: ' . $title;
    $brief[] = 'Request Type: ' . $type;
    $brief[] = '';
    $brief[] = 'Rules:';
    $brief[] = '- Follow existing project patterns.';
    $brief[] = '- Do not invent schema or functions unless explicitly required.';
    $brief[] = '- Prefer full drop-in replacements or exact surgical patches.';
    $brief[] = '- Keep Jake as final authority.';
    $brief[] = '- Preserve existing features unless explicitly changed.';
    $brief[] = '';
    $brief[] = 'User Request:';
    $brief[] = $prompt !== '' ? $prompt : '(empty)';
    $brief[] = '';

    if ($ssot) {
        $brief[] = 'Latest SSOT:';
        $brief[] = 'Version: ' . (string)($ssot['ssot_version'] ?? '');
        $brief[] = 'Title: ' . (string)($ssot['title'] ?? '');
        $brief[] = 'Summary: ' . (string)($ssot['summary_text'] ?? '');
        $brief[] = '';
    }

    if ($contextFiles) {
        $brief[] = 'Loaded Context Files:';
        foreach ($contextFiles as $f) {
            if (empty($f['error'])) {
                $brief[] = '- ' . $f['path'];
            }
        }
        $brief[] = '';
    }

    $brief[] = 'Expected Output:';
    $brief[] = '- artifact_type: code';
    $brief[] = '- output_mode: full_drop_in';
    $brief[] = '- include exact target path when known';

    return implode("\n", $brief);
}

function build_steven_artifact_content(array $requestRow, array $contextFiles): array
{
    $targetPath = '';
    foreach ($contextFiles as $f) {
        if (empty($f['error']) && !empty($f['path'])) {
            $targetPath = (string)$f['path'];
            break;
        }
    }

    $title = trim((string)($requestRow['request_title'] ?? 'Untitled request'));
    $prompt = trim((string)($requestRow['prompt'] ?? ''));

    // =========================
    // BUILD STEVEN PROMPT
    // =========================
    $system = <<<SYS
You are Steven, a senior PHP/MySQL engineer.

Rules:
- DO NOT invent database schema unless explicitly required
- DO NOT break existing functionality
- Follow existing project patterns
- Output ONLY code (no explanations unless asked)
- Prefer full drop-in OR clearly marked patch

If unclear → choose safest minimal change
SYS;

    $user = "REQUEST:\n" . $prompt . "\n\n";

    if (!empty($contextFiles)) {
        $user .= "CONTEXT FILES:\n";
        foreach ($contextFiles as $f) {
            if (empty($f['error'])) {
                $user .= "FILE: " . $f['path'] . "\n";
                $user .= $f['content_excerpt'] . "\n\n";
            }
        }
    }

    // =========================
    // CALL OPENAI
    // =========================
    try {
        $ai = openai_chat([
            [
                'role' => 'system',
                'content' => $system
            ],
            [
                'role' => 'user',
                'content' => $user
            ]
        ], [
            'temperature' => 0.2,
            'max_tokens' => 4000
        ]);

        $output = trim((string)($ai['content'] ?? ''));

    } catch (Throwable $e) {
        $output = "AI ERROR:\n" . $e->getMessage();
    }

    // =========================
    // RETURN ARTIFACT
    // =========================
    return [
        'title' => $targetPath !== '' ? ('Steven Output - ' . $targetPath) : 'Steven Output',
        'target_path' => $targetPath !== '' ? $targetPath : null,
        'artifact_type' => 'code',
        'output_mode' => 'full_drop_in',
        'content' => $output,
        'notes' => 'Generated by Steven (AI)',
    ];
}

function create_jake_run(PDO $pdo, int $requestId, int $userId, string $runType, string $status): int
{
    $stmt = $pdo->prepare("
        INSERT INTO ai_jake_runs
        (request_id, run_type, status, created_by_user_id, created_at, updated_at)
        VALUES (?, ?, ?, ?, NOW(), NOW())
    ");
    $stmt->execute([$requestId, $runType, $status, $userId]);
    return (int)$pdo->lastInsertId();
}

function update_jake_run(PDO $pdo, int $runId, array $fields): void
{
    if (!$fields) {
        return;
    }

    $allowed = [
        'status',
        'jake_summary',
        'steven_brief',
        'risk_notes',
        'output_mode',
    ];

    $set = [];
    $params = [];

    foreach ($allowed as $key) {
        if (array_key_exists($key, $fields)) {
            $set[] = "{$key} = ?";
            $params[] = $fields[$key];
        }
    }

    if (!$set) {
        return;
    }

    $set[] = "updated_at = NOW()";
    $params[] = $runId;

    $sql = "UPDATE ai_jake_runs SET " . implode(', ', $set) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function create_artifact(PDO $pdo, array $artifact): int
{
    $stmt = $pdo->prepare("
        INSERT INTO ai_jake_artifacts
        (
            run_id,
            request_id,
            artifact_type,
            target_path,
            title,
            content,
            output_mode,
            notes,
            created_by_agent,
            approved_by_agent,
            created_at,
            updated_at
        )
        VALUES
        (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
        )
    ");

    $stmt->execute([
        (int)$artifact['run_id'],
        (int)$artifact['request_id'],
        (string)($artifact['artifact_type'] ?? 'code'),
        $artifact['target_path'] ?? null,
        (string)$artifact['title'],
        (string)$artifact['content'],
        (string)($artifact['output_mode'] ?? 'full_drop_in'),
        $artifact['notes'] ?? null,
        (string)($artifact['created_by_agent'] ?? 'steven'),
        (string)($artifact['approved_by_agent'] ?? 'jake'),
    ]);

    return (int)$pdo->lastInsertId();
}

try {
    $u = cw_current_user($pdo);

    if (!$u || ($u['role'] ?? '') !== 'admin') {
        http_response_code(403);
        json_out(['ok' => false, 'error' => 'Forbidden']);
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        json_out(['ok' => false, 'error' => 'Invalid JSON']);
    }

    $action = (string)($data['action'] ?? '');

    switch ($action) {

        case 'save_request':

            $prompt = trim((string)($data['prompt'] ?? ''));
            $title = trim((string)($data['title'] ?? ''));
            $type = trim((string)($data['type'] ?? ''));

            if ($prompt === '') {
                json_out(['ok' => false, 'error' => 'Empty prompt']);
            }

            if ($title === '') {
                $title = 'Untitled request';
            }

            if ($type === '') {
                $type = 'investigation';
            }

            $stmt = $pdo->prepare("
                INSERT INTO ai_jake_requests
                (user_id, request_title, request_type, prompt, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'new', NOW(), NOW())
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

        case 'read_file':

            $path = trim((string)($data['path'] ?? ''));

            if ($path === '') {
                json_out(['ok' => false, 'error' => 'Missing path']);
            }

            $file = safe_project_file_read($path);

            json_out([
                'ok' => true,
                'path' => $file['path'],
                'content' => $file['content']
            ]);

        case 'list_tables':

            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

            json_out([
                'ok' => true,
                'tables' => $tables
            ]);

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

        case 'run_sql_read':

            $query = trim((string)($data['query'] ?? ''));

            if ($query === '') {
                json_out(['ok' => false, 'error' => 'Empty query']);
            }

            if (!preg_match('/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', $query)) {
                json_out(['ok' => false, 'error' => 'Only SELECT, SHOW, DESCRIBE, or EXPLAIN allowed']);
            }

            $stmt = $pdo->query($query);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            json_out([
                'ok' => true,
                'rows' => $rows,
                'count' => count($rows)
            ]);

        case 'get_request_context':

            $requestId = (int)($data['request_id'] ?? 0);
            if ($requestId <= 0) {
                json_out(['ok' => false, 'error' => 'Missing request_id']);
            }

            $requestRow = load_request_row($pdo, $requestId);
            if (!$requestRow) {
                json_out(['ok' => false, 'error' => 'Request not found']);
            }

            $ssot = load_latest_ssot_snapshot($pdo);
            $candidates = extract_file_candidates_from_text((string)($requestRow['prompt'] ?? ''));
            $contextFiles = read_files_for_context($candidates, 3);

            json_out([
                'ok' => true,
                'request' => $requestRow,
                'ssot' => $ssot,
                'context_files' => $contextFiles,
            ]);

        case 'list_request_artifacts':

            $requestId = (int)($data['request_id'] ?? 0);
            if ($requestId <= 0) {
                json_out(['ok' => false, 'error' => 'Missing request_id']);
            }

            $stmt = $pdo->prepare("
                SELECT
                    id,
                    run_id,
                    request_id,
                    artifact_type,
                    target_path,
                    title,
                    output_mode,
                    created_by_agent,
                    approved_by_agent,
                    created_at,
                    updated_at
                FROM ai_jake_artifacts
                WHERE request_id = ?
                ORDER BY id DESC
            ");
            $stmt->execute([$requestId]);
            $artifacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            json_out([
                'ok' => true,
                'artifacts' => $artifacts,
                'count' => count($artifacts)
            ]);

        case 'read_artifact':

            $artifactId = (int)($data['artifact_id'] ?? 0);
            if ($artifactId <= 0) {
                json_out(['ok' => false, 'error' => 'Missing artifact_id']);
            }

            $stmt = $pdo->prepare("
                SELECT *
                FROM ai_jake_artifacts
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([$artifactId]);
            $artifact = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$artifact) {
                json_out(['ok' => false, 'error' => 'Artifact not found']);
            }

            json_out([
                'ok' => true,
                'artifact' => $artifact
            ]);

        case 'jake_think':

            $requestId = (int)($data['request_id'] ?? 0);
            $prompt = trim((string)($data['prompt'] ?? ''));
            $title = trim((string)($data['title'] ?? ''));
            $type = trim((string)($data['type'] ?? ''));

            if ($requestId <= 0) {
                if ($prompt === '') {
                    json_out(['ok' => false, 'error' => 'Missing request_id or prompt']);
                }

                if ($title === '') {
                    $title = 'Untitled request';
                }

                if ($type === '') {
                    $type = 'investigation';
                }

                $stmt = $pdo->prepare("
                    INSERT INTO ai_jake_requests
                    (user_id, request_title, request_type, prompt, status, created_at, updated_at)
                    VALUES (?, ?, ?, ?, 'new', NOW(), NOW())
                ");
                $stmt->execute([
                    (int)$u['id'],
                    $title,
                    $type,
                    $prompt
                ]);

                $requestId = (int)$pdo->lastInsertId();
            }

            $requestRow = load_request_row($pdo, $requestId);
            if (!$requestRow) {
                json_out(['ok' => false, 'error' => 'Request not found']);
            }

            $ssot = load_latest_ssot_snapshot($pdo);
            $candidatePaths = extract_file_candidates_from_text((string)($requestRow['prompt'] ?? ''));
            $contextFiles = read_files_for_context($candidatePaths, 3);

            $runId = create_jake_run(
                $pdo,
                $requestId,
                (int)$u['id'],
                'jake_think',
                'running'
            );

            $jakeSummary = build_jake_summary($requestRow, $ssot, $contextFiles);
            $stevenBrief = build_steven_brief($requestRow, $ssot, $contextFiles);
            $riskNotes = 'V1 caution: Steven output is placeholder-grade until real model generation is wired.';
            $outputMode = 'full_drop_in';

            update_jake_run($pdo, $runId, [
                'status' => 'completed',
                'jake_summary' => $jakeSummary,
                'steven_brief' => $stevenBrief,
                'risk_notes' => $riskNotes,
                'output_mode' => $outputMode,
            ]);

            $artifactSeed = build_steven_artifact_content($requestRow, $contextFiles);

            $artifactId = create_artifact($pdo, [
                'run_id' => $runId,
                'request_id' => $requestId,
                'artifact_type' => $artifactSeed['artifact_type'],
                'target_path' => $artifactSeed['target_path'],
                'title' => $artifactSeed['title'],
                'content' => $artifactSeed['content'],
                'output_mode' => $artifactSeed['output_mode'],
                'notes' => $artifactSeed['notes'],
                'created_by_agent' => 'steven',
                'approved_by_agent' => 'jake',
            ]);

            $summaryLines = [];
            $summaryLines[] = 'JAKE COMPLETE';
            $summaryLines[] = '';
            $summaryLines[] = 'Request ID: ' . $requestId;
            $summaryLines[] = 'Run ID: ' . $runId;
            $summaryLines[] = 'Artifact ID: ' . $artifactId;
            $summaryLines[] = 'Output Mode: ' . $outputMode;
            $summaryLines[] = 'Target Path: ' . ($artifactSeed['target_path'] !== null ? $artifactSeed['target_path'] : '[not determined]');
            $summaryLines[] = '';
            $summaryLines[] = $jakeSummary;

            json_out([
                'ok' => true,
                'request_id' => $requestId,
                'run_id' => $runId,
                'artifact_id' => $artifactId,
                'output_mode' => $outputMode,
                'target_path' => $artifactSeed['target_path'],
                'response' => implode("\n", $summaryLines),
                'jake_summary' => $jakeSummary,
                'steven_brief' => $stevenBrief,
                'risk_notes' => $riskNotes,
            ]);

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