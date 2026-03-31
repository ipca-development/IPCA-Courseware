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
    $maxCharsPerFile = 7000;

    foreach ($paths as $path) {
        if ($count >= $limit) {
            break;
        }

        try {
            $file = safe_project_file_read((string)$path);

            $content = (string)$file['content'];
            if (mb_strlen($content) > $maxCharsPerFile) {
                $content = mb_substr($content, 0, $maxCharsPerFile) . "\n\n/* [truncated for AI context] */";
            }

            $out[] = [
                'path' => $file['path'],
                'basename' => $file['basename'],
                'size_bytes' => $file['size_bytes'],
                'content' => $content,
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

function jake_review_artifact(array $requestRow, ?array $ssot, array $contextFiles, string $artifactContent): array
{
        if (strpos(ltrim($artifactContent), 'AI ERROR:') === 0) {
        return [
            'verdict' => 'analysis_only',
            'reason' => 'Steven did not complete the code generation successfully because the AI call failed before a real artifact could be produced. In other words: this is not approved code — it is a generation failure, so we should retry with less context or after the rate limit clears.',
            'revision' => 'Retry generation with reduced context size or after the OpenAI rate limit window resets.'
        ];
    }
	
	$system = implode("\n", [
        'You are Jake, the IPCA architect and SSOT guardian.',
        '',
        'Your job is to review Steven outputs strictly, but explain your verdict in clear human language.',
        '',
        'Core review rules:',
        '- Stay grounded in the actual user request.',
        '- Stay grounded in the loaded files.',
        '- Stay grounded in the SSOT snapshot when provided.',
        '- Do not give generic code-review advice unless it is directly relevant to the actual artifact.',
        '- Do not fall back to generic security, logging, validation, or architecture checklists unless the artifact truly has that specific problem.',
        '- Focus on whether Steven actually solved the requested task correctly and safely.',
        '',
        'Critical rejection rules:',
        '- Reject invented methods, invented schema, invented APIs, or invented architecture.',
        '- Reject changes that alter behavior without explicit permission.',
        '- Reject output that is not actually grounded in the loaded file context.',
        '- Reject output that claims a safe full drop-in when that is not justified.',
        '- If the correct answer is "not safely possible yet", prefer analysis_only.',
        '',
        'Communication rules:',
        '- Be clear, calm, and conversational.',
        '- Explain the issue in normal human language.',
        '- After the technical explanation, add a short plain-English clarification starting with: "In other words:"',
        '- Keep the explanation helpful, not robotic.',
        '',
        'Return format exactly:',
        'VERDICT: approved|needs_revision|analysis_only',
        'REASON: <clear explanation with some context, plus "In other words: ...">',
        'REVISION: <exact revision instruction if needed, otherwise "None">',
    ]);

    $user = "USER REQUEST:\n" . trim((string)($requestRow['prompt'] ?? '')) . "\n\n";

    if ($ssot) {
        $user .= "SSOT SNAPSHOT:\n";
        $user .= "Version: " . (string)($ssot['ssot_version'] ?? '') . "\n";
        $user .= "Title: " . (string)($ssot['title'] ?? '') . "\n";
        $user .= "Summary: " . (string)($ssot['summary_text'] ?? '') . "\n\n";
    }

    if ($contextFiles) {
        $user .= "LOADED FILES:\n";
        foreach ($contextFiles as $f) {
            if (!empty($f['error'])) {
                $user .= "- " . (string)$f['path'] . " [read failed: " . (string)$f['error'] . "]\n";
                continue;
            }

            $user .= "- " . (string)$f['path'] . "\n";
        }
        $user .= "\n";
    }

    $user .= "STEVEN ARTIFACT TO REVIEW:\n";
    $user .= $artifactContent;

    $resp = cw_openai_responses([
        'model' => cw_openai_model(),
        'input' => [
            [
                'role' => 'system',
                'content' => [
                    ['type' => 'input_text', 'text' => $system]
                ]
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => $user]
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
        throw new RuntimeException('Jake review returned empty content');
    }

    $verdict = 'analysis_only';
    $reason = $text;
    $revision = 'None';

    $lines = preg_split("/\r\n|\n|\r/", $text);
    if (is_array($lines)) {
        foreach ($lines as $line) {
            $trimmed = trim((string)$line);

            if (stripos($trimmed, 'VERDICT:') === 0) {
                $candidate = trim(substr($trimmed, strlen('VERDICT:')));
                if ($candidate !== '') {
                    $verdict = $candidate;
                }
                continue;
            }

            if (stripos($trimmed, 'REASON:') === 0) {
                $candidate = trim(substr($trimmed, strlen('REASON:')));
                if ($candidate !== '') {
                    $reason = $candidate;
                }
                continue;
            }

            if (stripos($trimmed, 'REVISION:') === 0) {
                $candidate = trim(substr($trimmed, strlen('REVISION:')));
                if ($candidate !== '') {
                    $revision = $candidate;
                }
                continue;
            }
        }
    }

    return [
        'verdict' => $verdict,
        'reason' => $reason,
        'revision' => $revision
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


//HELPERS FOR COOPERATION BETWEEN JAKE AND STEVEN

function update_conversation_state(
    PDO $pdo,
    int $conversationId,
    ?string $activeMode,
    ?string $activeRequestSummary,
    ?string $activeTargetFiles,
    ?string $activeNextStep,
    ?int $activeRunId,
    ?int $activeArtifactId
): void {
    $stmt = $pdo->prepare("
        UPDATE ai_jake_conversations
        SET
            active_mode = ?,
            active_request_summary = ?,
            active_target_files = ?,
            active_next_step = ?,
            active_run_id = ?,
            active_artifact_id = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $activeMode,
        $activeRequestSummary,
        $activeTargetFiles,
        $activeNextStep,
        $activeRunId,
        $activeArtifactId,
        $conversationId
    ]);
}

function extract_active_target_files_from_message(string $messageText): array
{
    return extract_file_candidates_from_text($messageText);
}

function build_active_request_summary(string $messageText, ?string $requestType = null): string
{
    $messageText = trim(preg_replace('/\s+/', ' ', $messageText));
    $summary = $messageText;

    if ($summary === '') {
        $summary = 'Untitled engineering request';
    }

    if (mb_strlen($summary) > 240) {
        $summary = trim(mb_substr($summary, 0, 237)) . '...';
    }

    if ($requestType !== null && trim($requestType) !== '') {
        return '[' . trim($requestType) . '] ' . $summary;
    }

    return $summary;
}

function is_continuation_trigger(string $messageText): bool
{
    $text = strtolower(trim($messageText));

    if ($text === '') {
        return false;
    }

    // Normalize apostrophes, punctuation, spacing
    $normalized = str_replace(array("’", "`", "‘"), "'", $text);
    $normalized = preg_replace('/[^a-z0-9\'\s]/', ' ', $normalized);
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    $normalized = trim((string)$normalized);

    if ($normalized === '') {
        return false;
    }

    $exact = array(
        'yes',
        'yes please',
        'go ahead',
        'do it',
        'implement it',
        'implement this',
        'code it',
        'code this',
        'try it',
        'try this',
        'proceed',
        'continue',
        'go for it',
        'sounds good',
        'looks good',
        'okay do it',
        'ok do it'
    );

    if (in_array($normalized, $exact, true)) {
        return true;
    }

    $patterns = array(
        '/\blet\s*\'?\s*s?\s+implement\s+(it|this)\b/',
        '/\blet\s*\'?\s*s?\s+code\s+(it|this)\b/',
        '/\blet\s*\'?\s*s?\s+try\s+(it|this)\b/',
        '/\blet\s*\'?\s*s?\s+build\s+(it|this)\b/',
        '/\bplease\s+implement\s+(it|this)\b/',
        '/\bplease\s+code\s+(it|this)\b/',
        '/\bgo\s+ahead\b/',
        '/\bgo\s+for\s+it\b/',
        '/\byes\b.*\bimplement\b/',
        '/\byes\b.*\bcode\b/',
        '/\byes\b.*\bdo\s+it\b/',
        '/\bimplement\s+(it|this)\b/',
        '/\bcode\s+(it|this)\b/',
        '/\bbuild\s+(it|this)\b/',
        '/\bcontinue\s+with\s+(the\s+)?implementation\b/',
        '/\bproceed\s+with\s+(the\s+)?implementation\b/'
    );

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $normalized)) {
            return true;
        }
    }

    return false;
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
    'You are Jake, the IPCA system architect and SSOT guardian.',
    '',
    'You think like a senior software architect, but you speak like a clear, calm, helpful human.',
    '',
    'Tone rules:',
    '- Natural, conversational, like a senior engineer explaining things to a colleague',
    '- No robotic or overly strict phrasing',
    '- No unnecessary warnings unless something is actually risky',
    '- Be concise but not abrupt',
    '- It should feel like a real conversation, not a system message',
    '',
    'Behavior rules:',
    '- Internally be strict about architecture, SSOT, and correctness',
    '- Externally explain things simply and clearly',
    '- You can guide, suggest, and explain — not just block',
    '- If something is not possible, explain *why* and suggest the next best step',
    '- Avoid saying things like "we must stay focused" or similar rigid phrasing',
    '',
    'Engineering rules:',
    '- Do not invent system behavior',
    '- Stay grounded in SSOT and loaded files',
    '- Highlight risks when relevant, but do not overdo it',
    '',
    'Interaction style:',
    '- Think like a partner, not a gatekeeper',
    '- You are assisting the user, not policing them',
	'- Prefer short paragraphs over bullet lists unless structure is needed',	
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


function run_jake_engineering_cycle(
    PDO $pdo,
    int $userId,
    string $messageText,
    ?string $requestType = null
): array {
    $title = auto_subject_from_message($messageText);
    $type = ($requestType !== null && trim($requestType) !== '') ? trim($requestType) : 'investigation';
    $prompt = trim($messageText);

    $stmt = $pdo->prepare("
        INSERT INTO ai_jake_requests
        (user_id, request_title, request_type, prompt, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, 'new', NOW(), NOW())
    ");
    $stmt->execute([
        $userId,
        $title,
        $type,
        $prompt
    ]);

    $requestId = (int)$pdo->lastInsertId();
    $requestRow = load_request_row($pdo, $requestId);

    if (!$requestRow) {
        throw new RuntimeException('Unable to reload created request');
    }

    $ssot = load_latest_ssot_snapshot($pdo);
    $candidatePaths = extract_file_candidates_from_text((string)$requestRow['prompt']);
    $contextFiles = read_files_for_context($candidatePaths, 2);

    $runId = create_jake_run(
        $pdo,
        $requestId,
        $userId,
        'jake_think',
        'running'
    );

    $jakeSummary = build_jake_summary($requestRow, $ssot, $contextFiles);
    $stevenBrief = build_steven_brief($requestRow, $ssot, $contextFiles);
    $riskNotes = 'Steven output generated via OpenAI. Manual review still required before any code is used.';
    $outputMode = 'full_drop_in';

    try {
        $maxRounds = 2;
        $currentRound = 1;
        $finalArtifactId = null;
        $finalArtifact = null;
        $finalReviewStatus = 'analysis_only';
        $finalReviewSummary = '';

        while ($currentRound <= $maxRounds) {

            $artifactSeed = build_steven_artifact_content($requestRow, $contextFiles);
            $outputMode = (string)($artifactSeed['output_mode'] ?? 'full_drop_in');

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

            $review = jake_review_artifact(
                $requestRow,
                $ssot,
                $contextFiles,
                (string)$artifactSeed['content']
            );

            $reviewStatus = (string)($review['verdict'] ?? 'analysis_only');
            $reviewSummary = (string)($review['reason'] ?? '');
            $isFinal = ($reviewStatus === 'approved' || $reviewStatus === 'analysis_only') ? 1 : 0;

            $stmt2 = $pdo->prepare("
                UPDATE ai_jake_artifacts
                SET review_status = ?, review_summary = ?, revision_round = ?, is_final = ?
                WHERE id = ?
            ");
            $stmt2->execute([
                $reviewStatus,
                $reviewSummary,
                $currentRound,
                $isFinal,
                $artifactId
            ]);

            $finalArtifactId = $artifactId;
            $finalArtifact = $artifactSeed;
            $finalReviewStatus = $reviewStatus;
            $finalReviewSummary = $reviewSummary;

            if ($isFinal) {
                break;
            }

            $requestRow['prompt'] .= "\n\nREVISION REQUIRED:\n" . $reviewSummary;
            $currentRound++;
        }

        update_jake_run($pdo, $runId, [
            'status' => 'completed',
            'jake_summary' => $jakeSummary,
            'steven_brief' => $stevenBrief,
            'risk_notes' => $riskNotes . ' Final review status: ' . $finalReviewStatus,
            'output_mode' => (string)($finalArtifact['output_mode'] ?? $outputMode),
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

$replyLines = [];

// Title
$replyLines[] = '**Summary**';
$replyLines[] = 'I took your request, generated a solution, and reviewed it against the system.';
$replyLines[] = '';

// Result block
$replyLines[] = '**Result**';
$replyLines[] = '- Request ID: ' . $requestId;
$replyLines[] = '- Run ID: ' . $runId;
$replyLines[] = '- Artifact ID: ' . $finalArtifactId;
$replyLines[] = '- Output Mode: ' . (string)($finalArtifact['output_mode'] ?? $outputMode);
$replyLines[] = '- Review Status: ' . $finalReviewStatus;
$replyLines[] = '- Target Path: ' . (($finalArtifact !== null && $finalArtifact['target_path'] !== null) ? $finalArtifact['target_path'] : '[not determined]');
$replyLines[] = '';

// Explanation
$replyLines[] = '**What this means**';
$replyLines[] = $finalReviewSummary !== '' ? $finalReviewSummary : 'No detailed review feedback was generated.';
$replyLines[] = '';

// Human explanation
$replyLines[] = '**In other words**';

if ($finalReviewStatus === 'approved') {
    $replyLines[] = 'This is solid and safe. You can use the artifact directly.';
} elseif ($finalReviewStatus === 'needs_revision') {
    $replyLines[] = 'The direction is good, but there are issues that make it unsafe to use as-is.';
} else {
    $replyLines[] = 'This cannot be safely implemented yet without adjusting the approach.';
}

$replyLines[] = '';

// Guidance
$replyLines[] = '**My suggestion**';
$replyLines[] = 'Open the artifact on the right and review the proposed code or patch.';

if ($finalReviewStatus !== 'approved') {
    $replyLines[] = 'If you want, I can refine this further and push it to an approved version.';
}

return array(
    'request_id' => $requestId,
    'run_id' => $runId,
    'artifact_id' => $finalArtifactId,
    'review_status' => $finalReviewStatus,
    'output_mode' => (string)($finalArtifact['output_mode'] ?? $outputMode),
    'target_path' => $finalArtifact !== null ? $finalArtifact['target_path'] : null,
    'reply' => implode("\n", $replyLines)
);
	
	
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

            $activeMode = (string)($conversation['active_mode'] ?? '');
            $activeRequestSummary = (string)($conversation['active_request_summary'] ?? '');
            $activeTargetFiles = (string)($conversation['active_target_files'] ?? '');
            $activeNextStep = (string)($conversation['active_next_step'] ?? '');
            $activeRunId = isset($conversation['active_run_id']) ? (int)$conversation['active_run_id'] : null;
            $activeArtifactId = isset($conversation['active_artifact_id']) ? (int)$conversation['active_artifact_id'] : null;

            $reply = '';
            $linkedRunId = null;

            if (
    (
        is_continuation_trigger($messageText) ||
        (
            $activeRequestSummary !== '' &&
            in_array(strtolower(trim($messageText)), array('yes', 'yes.', 'ok', 'okay', 'sure'), true) &&
            (string)$activeMode === 'analysis'
        )
    ) &&
    $activeRequestSummary !== ''
) {
                $engineeringPrompt = $activeRequestSummary;

                if ($activeTargetFiles !== '') {
                    $engineeringPrompt .= "\n\nRelevant files:\n" . $activeTargetFiles;
                }

                if ($activeNextStep !== '') {
                    $engineeringPrompt .= "\n\nRequested next step:\n" . $activeNextStep;
                }

                $result = run_jake_engineering_cycle(
                    $pdo,
                    (int)$u['id'],
                    $engineeringPrompt,
                    $requestType !== '' ? $requestType : 'investigation'
                );

                $reply = (string)$result['reply'];
                $linkedRunId = (int)$result['run_id'];

                update_conversation_state(
                    $pdo,
                    $conversationId,
                    'implementation',
                    $activeRequestSummary,
                    $activeTargetFiles,
                    'Implementation draft created and reviewed. Awaiting user inspection.',
                    $result['run_id'],
                    $result['artifact_id']
                );

            } else {
                $reply = jake_chat_reply($pdo, [
                    'message_text' => $messageText
                ], $requestType !== '' ? $requestType : null);

                $targetFiles = extract_active_target_files_from_message($messageText);
                $targetFilesText = $targetFiles ? implode("\n", $targetFiles) : '';

                update_conversation_state(
                    $pdo,
                    $conversationId,
                    'analysis',
                    build_active_request_summary($messageText, $requestType !== '' ? $requestType : null),
                    $targetFilesText !== '' ? $targetFilesText : null,
                    'If user approves, move into implementation mode.',
                    $activeRunId,
                    $activeArtifactId
                );
            }

            $jakeMessageId = add_conversation_message(
                $pdo,
                $conversationId,
                'jake',
                $reply,
                $requestType !== '' ? $requestType : null,
                $linkedRunId
            );

            json_out([
                'ok' => true,
                'conversation_id' => $conversationId,
                'user_message_id' => $userMessageId,
                'jake_message_id' => $jakeMessageId,
                'reply' => $reply
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
                $maxRounds = 2;
                $currentRound = 1;
                $finalArtifactId = null;
                $finalArtifact = null;
                $finalReviewStatus = 'analysis_only';
                $finalReviewSummary = '';

                while ($currentRound <= $maxRounds) {

                    $artifactSeed = build_steven_artifact_content($requestRow, $contextFiles);
                    $outputMode = (string)($artifactSeed['output_mode'] ?? 'full_drop_in');

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

                    $review = jake_review_artifact(
                        $requestRow,
                        $ssot,
                        $contextFiles,
                        (string)$artifactSeed['content']
                    );

                    $reviewStatus = (string)($review['verdict'] ?? 'analysis_only');
                    $reviewSummary = (string)($review['reason'] ?? '');
                    $isFinal = ($reviewStatus === 'approved' || $reviewStatus === 'analysis_only') ? 1 : 0;

                    $stmt = $pdo->prepare("
                        UPDATE ai_jake_artifacts
                        SET review_status = ?, review_summary = ?, revision_round = ?, is_final = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $reviewStatus,
                        $reviewSummary,
                        $currentRound,
                        $isFinal,
                        $artifactId
                    ]);

                    $finalArtifactId = $artifactId;
                    $finalArtifact = $artifactSeed;
                    $finalReviewStatus = $reviewStatus;
                    $finalReviewSummary = $reviewSummary;

                    if ($isFinal) {
                        break;
                    }

                    $requestRow['prompt'] .= "\n\nREVISION REQUIRED:\n" . $reviewSummary;
                    $currentRound++;
                }

                update_jake_run($pdo, $runId, [
                    'status' => 'completed',
                    'jake_summary' => $jakeSummary,
                    'steven_brief' => $stevenBrief,
                    'risk_notes' => $riskNotes . ' Final review status: ' . $finalReviewStatus,
                    'output_mode' => (string)($finalArtifact['output_mode'] ?? $outputMode),
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
            $summaryLines[] = 'Artifact ID: ' . $finalArtifactId;
            $summaryLines[] = 'Output Mode: ' . (string)($finalArtifact['output_mode'] ?? $outputMode);
            $summaryLines[] = 'Review Status: ' . $finalReviewStatus;
            $summaryLines[] = 'Target Path: ' . (($finalArtifact !== null && $finalArtifact['target_path'] !== null) ? $finalArtifact['target_path'] : '[not determined]');
            $summaryLines[] = '';
            $summaryLines[] = $jakeSummary;
            $summaryLines[] = '';
            $summaryLines[] = 'Jake Review Summary:';
            $summaryLines[] = $finalReviewSummary !== '' ? $finalReviewSummary : 'No review summary returned.';

            json_out([
                'ok' => true,
                'request_id' => $requestId,
                'run_id' => $runId,
                'artifact_id' => $finalArtifactId,
                'output_mode' => (string)($finalArtifact['output_mode'] ?? $outputMode),
                'target_path' => $finalArtifact !== null ? $finalArtifact['target_path'] : null,
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