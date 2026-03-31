<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/openai.php';

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
                'content' => (string)$file['content'],
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

    $lines[] = 'Next internal action: Jake prepared a Steven implementation brief and requested a coded artifact.';
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
    $brief[] = '- Do not invent schema, tables, or functions unless explicitly required by the request.';
    $brief[] = '- Preserve existing features unless explicitly changed.';
    $brief[] = '- Prefer full drop-in replacements when modifying a known file.';
    $brief[] = '- If full replacement is too risky, provide an exact surgical patch with exact insertion points.';
    $brief[] = '- Be concrete and copy-paste ready.';
    $brief[] = '';
    $brief[] = 'User Request:';
    $brief[] = $prompt !== '' ? $prompt : '(empty)';
    $brief[] = '';

    if ($ssot) {
        $brief[] = 'Latest SSOT Snapshot:';
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

    $brief[] = 'Required Output Format:';
    $brief[] = 'OUTPUT_MODE: full_drop_in|surgical_patch|analysis_only';
    $brief[] = 'TARGET_PATH: <path or blank>';
    $brief[] = 'TITLE: <short title>';
    $brief[] = 'NOTES: <short note>';
    $brief[] = '';
    $brief[] = '<blank line>';
    $brief[] = '<actual output starts here>';
    $brief[] = '';
    $brief[] = 'Do not return JSON.';
    $brief[] = 'Do not wrap the response in markdown fences.';
    $brief[] = 'Do not put introductions before OUTPUT_MODE.';
    $brief[] = 'After the blank line, provide normal human-readable code or patch content.';

    return implode("\n", $brief);
}

function parse_plain_text_artifact(string $text, ?string $fallbackTargetPath = null): array
{
    $text = trim($text);

    if ($text === '') {
        throw new RuntimeException('Empty model text');
    }

    $outputMode = 'analysis_only';
    $targetPath = $fallbackTargetPath;
    $title = 'Steven Output';
    $notes = '';

    $lines = preg_split("/\r\n|\n|\r/", $text);
    if (!is_array($lines)) {
        throw new RuntimeException('Unable to parse model text');
    }

    $bodyStartIndex = 0;

    for ($i = 0; $i < count($lines); $i++) {
        $line = trim((string)$lines[$i]);

        if ($line === '') {
            $bodyStartIndex = $i + 1;
            break;
        }

        if (stripos($line, 'OUTPUT_MODE:') === 0) {
            $value = trim(substr($line, strlen('OUTPUT_MODE:')));
            if ($value !== '') {
                $outputMode = $value;
            }
            continue;
        }

        if (stripos($line, 'TARGET_PATH:') === 0) {
            $value = trim(substr($line, strlen('TARGET_PATH:')));
            if ($value !== '') {
                $targetPath = $value;
            }
            continue;
        }

        if (stripos($line, 'TITLE:') === 0) {
            $value = trim(substr($line, strlen('TITLE:')));
            if ($value !== '') {
                $title = $value;
            }
            continue;
        }

        if (stripos($line, 'NOTES:') === 0) {
            $value = trim(substr($line, strlen('NOTES:')));
            if ($value !== '') {
                $notes = $value;
            }
            continue;
        }

        $bodyStartIndex = $i;
        break;
    }

    $body = implode("\n", array_slice($lines, $bodyStartIndex));
    $body = trim($body);

    if ($body === '') {
        throw new RuntimeException('Steven returned empty content');
    }

    return [
        'output_mode' => $outputMode,
        'target_path' => $targetPath,
        'title' => $title,
        'notes' => $notes,
        'content' => $body,
    ];
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

    $systemPrompt = implode("\n", [
        'You are Steven, a hidden senior PHP/MySQL implementation agent inside the IPCA engineering console.',
        'You write implementation-ready engineering output.',
        'You do not make architecture decisions independently.',
        'You must preserve existing behavior unless explicitly changed.',
        'You must not invent nonexistent schema, APIs, helper functions, or engine methods unless the user explicitly requests new structure.',
        'When a safe full replacement is possible, provide a full drop-in.',
        'When a full replacement is unsafe, provide a surgical patch.',
        'When neither is safe, provide analysis_only.',
        'Return plain text in exactly this format:',
        'OUTPUT_MODE: full_drop_in|surgical_patch|analysis_only',
        'TARGET_PATH: <path or blank>',
        'TITLE: <short title>',
        'NOTES: <short note>',
        '',
        '<blank line>',
        '<actual output starts here>',
        '',
        'Do not return JSON.',
        'Do not wrap the response in markdown fences.',
        'Do not put introductions before OUTPUT_MODE.',
        'After the blank line, provide normal human-readable code or patch content.',
    ]);

    $userPrompt = "REQUEST TITLE:\n" . $title . "\n\n";
    $userPrompt .= "REQUEST:\n" . $prompt . "\n\n";

    if (!empty($contextFiles)) {
        $userPrompt .= "CONTEXT FILES:\n";
        foreach ($contextFiles as $f) {
            if (!empty($f['error'])) {
                continue;
            }

            $fileContent = '';
            if (isset($f['content']) && is_string($f['content'])) {
                $fileContent = $f['content'];
            }

            $userPrompt .= "FILE: " . (string)$f['path'] . "\n";
            $userPrompt .= $fileContent . "\n\n";
        }
    }

    try {
        $resp = cw_openai_responses([
            'model' => cw_openai_model(),
            'input' => [
                [
                    'role' => 'system',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $systemPrompt
                        ]
                    ]
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $userPrompt
                        ]
                    ]
                ]
            ]
        ]);

        $text = '';

        if (!empty($resp['output_text']) && is_string($resp['output_text'])) {
            $text = trim($resp['output_text']);
        }

        if ($text === '') {
            $out = $resp['output'] ?? [];

            if (is_array($out)) {
                foreach ($out as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $content = $item['content'] ?? [];
                    if (!is_array($content)) {
                        continue;
                    }

                    foreach ($content as $c) {
                        if (!is_array($c)) {
                            continue;
                        }

                        if (($c['type'] ?? '') === 'output_text' && !empty($c['text']) && is_string($c['text'])) {
                            $text .= (string)$c['text'];
                        } elseif (($c['type'] ?? '') === 'text' && !empty($c['text']) && is_string($c['text'])) {
                            $text .= (string)$c['text'];
                        }
                    }
                }
            }

            $text = trim($text);
        }

        $parsed = parse_plain_text_artifact($text, $targetPath !== '' ? $targetPath : null);

        return [
            'title' => trim((string)$parsed['title']) !== '' ? (string)$parsed['title'] : ($targetPath !== '' ? ('Steven Output - ' . $targetPath) : 'Steven Output'),
            'target_path' => !empty($parsed['target_path']) ? (string)$parsed['target_path'] : ($targetPath !== '' ? $targetPath : null),
            'artifact_type' => 'code',
            'output_mode' => !empty($parsed['output_mode']) ? (string)$parsed['output_mode'] : 'analysis_only',
            'content' => (string)$parsed['content'],
            'notes' => trim((string)$parsed['notes']) !== '' ? (string)$parsed['notes'] : 'Generated by Steven (AI)',
        ];

    } catch (Throwable $e) {
        return [
            'title' => $targetPath !== '' ? ('Steven Output - ' . $targetPath) : 'Steven Output',
            'target_path' => $targetPath !== '' ? $targetPath : null,
            'artifact_type' => 'code',
            'output_mode' => 'analysis_only',
            'content' => "AI ERROR:\n" . $e->getMessage(),
            'notes' => 'Steven generation failed',
        ];
    }
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

function create_conversation(PDO $pdo, int $userId, string $subject): int
{
    $stmt = $pdo->prepare("
        INSERT INTO ai_jake_conversations
        (subject, status, created_by_user_id, created_at, updated_at)
        VALUES (?, 'active', ?, NOW(), NOW())
    ");
    $stmt->execute([$subject, $userId]);
    return (int)$pdo->lastInsertId();
}

function add_conversation_message(PDO $pdo, int $conversationId, string $role, string $messageText, ?string $requestType = null, ?int $linkedRunId = null): int
{
    $stmt = $pdo->prepare("
        INSERT INTO ai_jake_messages
        (conversation_id, role, message_text, request_type, linked_run_id, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $conversationId,
        $role,
        $messageText,
        $requestType,
        $linkedRunId
    ]);

    $stmt2 = $pdo->prepare("
        UPDATE ai_jake_conversations
        SET updated_at = NOW()
        WHERE id = ?
    ");
    $stmt2->execute([$conversationId]);

    return (int)$pdo->lastInsertId();
}

function load_conversation(PDO $pdo, int $conversationId): ?array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM ai_jake_conversations
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$conversationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function list_conversations(PDO $pdo, int $limit = 50): array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM ai_jake_conversations
        ORDER BY updated_at DESC, id DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function load_conversation_messages(PDO $pdo, int $conversationId): array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM ai_jake_messages
        WHERE conversation_id = ?
        ORDER BY id ASC
    ");
    $stmt->execute([$conversationId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function auto_subject_from_message(string $message): string
{
    $message = trim(preg_replace('/\s+/', ' ', $message));
    if ($message === '') {
        return 'Untitled conversation';
    }

    if (mb_strlen($message) <= 80) {
        return $message;
    }

    return trim(mb_substr($message, 0, 77)) . '...';
}

function jake_chat_reply(PDO $pdo, array $userMessage, ?string $requestType = null): string
{
    $message = trim((string)($userMessage['message_text'] ?? ''));
    $ssot = load_latest_ssot_snapshot($pdo);

    $fileCandidates = extract_file_candidates_from_text($message);
    $contextFiles = read_files_for_context($fileCandidates, 3);

    $systemPrompt = implode("\n", [
        'You are Jake, the IPCA architect and SSOT guardian.',
        'You speak naturally and clearly, like a senior engineering partner.',
        'You are not the final authority; the user is.',
        'You should be architecturally strict, practical, and concise.',
        'When useful, mention what files you inspected.',
        'Do not dump code unless the user explicitly asks for it in chat.',
        'Do not invent system state.',
        'Stay grounded in the provided SSOT snapshot and loaded files.',
    ]);

    $userPrompt = "USER MESSAGE:\n" . $message . "\n\n";

    if ($requestType !== null && trim($requestType) !== '') {
        $userPrompt .= "REQUEST TYPE:\n" . trim($requestType) . "\n\n";
    }

    if ($ssot) {
        $userPrompt .= "LATEST SSOT SNAPSHOT:\n";
        $userPrompt .= "Version: " . (string)($ssot['ssot_version'] ?? '') . "\n";
        $userPrompt .= "Title: " . (string)($ssot['title'] ?? '') . "\n";
        $userPrompt .= "Summary: " . (string)($ssot['summary_text'] ?? '') . "\n\n";
    }

    if ($contextFiles) {
        $userPrompt .= "LOADED FILES:\n";
        foreach ($contextFiles as $f) {
            if (!empty($f['error'])) {
                $userPrompt .= "- " . $f['path'] . " [read failed: " . $f['error'] . "]\n";
            } else {
                $userPrompt .= "- " . $f['path'] . "\n";
            }
        }
        $userPrompt .= "\n";
    }

    $resp = cw_openai_responses([
        'model' => cw_openai_model(),
        'input' => [
            [
                'role' => 'system',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => $systemPrompt
                    ]
                ]
            ],
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => $userPrompt
                    ]
                ]
            ]
        ]
    ]);

    $text = '';

    if (!empty($resp['output_text']) && is_string($resp['output_text'])) {
        $text = trim($resp['output_text']);
    }

    if ($text === '') {
        $out = $resp['output'] ?? [];

        if (is_array($out)) {
            foreach ($out as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $content = $item['content'] ?? [];
                if (!is_array($content)) {
                    continue;
                }

                foreach ($content as $c) {
                    if (!is_array($c)) {
                        continue;
                    }

                    if (($c['type'] ?? '') === 'output_text' && !empty($c['text']) && is_string($c['text'])) {
                        $text .= (string)$c['text'];
                    } elseif (($c['type'] ?? '') === 'text' && !empty($c['text']) && is_string($c['text'])) {
                        $text .= (string)$c['text'];
                    }
                }
            }
        }

        $text = trim($text);
    }

    if ($text === '') {
        throw new RuntimeException('Jake returned empty content');
    }

    return $text;
}

try {
    $u = cw_current_user($pdo);

    if (!$u || ($u['role'] ?? '') !== 'admin') {
        http_response_code(403);
        json_out(['ok' => false, 'error' => 'Forbidden']);
    }

    $raw = file_get_contents('php://input');
    $data = json_decode((string)$raw, true);

    if (!is_array($data)) {
        json_out(['ok' => false, 'error' => 'Invalid JSON']);
    }

    $action = (string)($data['action'] ?? '');

    switch ($action) {

        case 'create_conversation':

            $subject = trim((string)($data['subject'] ?? ''));
            if ($subject === '') {
                $subject = 'Untitled conversation';
            }

            $conversationId = create_conversation($pdo, (int)$u['id'], $subject);

            json_out([
                'ok' => true,
                'conversation_id' => $conversationId
            ]);

        case 'list_conversations':

            $items = list_conversations($pdo, 50);

            json_out([
                'ok' => true,
                'conversations' => $items,
                'count' => count($items)
            ]);

        case 'get_conversation_messages':

            $conversationId = (int)($data['conversation_id'] ?? 0);
            if ($conversationId <= 0) {
                json_out(['ok' => false, 'error' => 'Missing conversation_id']);
            }

            $conversation = load_conversation($pdo, $conversationId);
            if (!$conversation) {
                json_out(['ok' => false, 'error' => 'Conversation not found']);
            }

            $messages = load_conversation_messages($pdo, $conversationId);

            json_out([
                'ok' => true,
                'conversation' => $conversation,
                'messages' => $messages
            ]);

        case 'send_message':

            $conversationId = (int)($data['conversation_id'] ?? 0);
            $messageText = trim((string)($data['message_text'] ?? ''));
            $requestType = trim((string)($data['request_type'] ?? ''));

            if ($messageText === '') {
                json_out(['ok' => false, 'error' => 'Empty message_text']);
            }

            if ($conversationId <= 0) {
                $subject = auto_subject_from_message($messageText);
                $conversationId = create_conversation($pdo, (int)$u['id'], $subject);
            }

            $conversation = load_conversation($pdo, $conversationId);
            if (!$conversation) {
                json_out(['ok' => false, 'error' => 'Conversation not found']);
            }

            $userMessageId = add_conversation_message(
                $pdo,
                $conversationId,
                'user',
                $messageText,
                $requestType !== '' ? $requestType : null,
                null
            );

            $jakeReply = jake_chat_reply($pdo, [
                'message_text' => $messageText
            ], $requestType !== '' ? $requestType : null);

            $jakeMessageId = add_conversation_message(
                $pdo,
                $conversationId,
                'jake',
                $jakeReply,
                $requestType !== '' ? $requestType : null,
                null
            );

            json_out([
                'ok' => true,
                'conversation_id' => $conversationId,
                'user_message_id' => $userMessageId,
                'jake_message_id' => $jakeMessageId,
                'reply' => $jakeReply
            ]);

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
            $riskNotes = 'Steven output generated via OpenAI. Manual review still required before any code is used.';
            $outputMode = 'full_drop_in';

            try {
                $artifactSeed = build_steven_artifact_content($requestRow, $contextFiles);
                $outputMode = (string)($artifactSeed['output_mode'] ?? 'full_drop_in');

                update_jake_run($pdo, $runId, [
                    'status' => 'completed',
                    'jake_summary' => $jakeSummary,
                    'steven_brief' => $stevenBrief,
                    'risk_notes' => $riskNotes,
                    'output_mode' => $outputMode,
                ]);

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
            } catch (Throwable $inner) {
                update_jake_run($pdo, $runId, [
                    'status' => 'failed',
                    'jake_summary' => $jakeSummary,
                    'steven_brief' => $stevenBrief,
                    'risk_notes' => 'Steven generation failed: ' . $inner->getMessage(),
                    'output_mode' => $outputMode,
                ]);

                throw $inner;
            }

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