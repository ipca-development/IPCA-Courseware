<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/openai.php';
require_once __DIR__ . '/../../../src/resource_library_ai.php';

cw_require_admin();

header('Content-Type: application/json; charset=utf-8');

function rl_search_test_json_out(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function rl_search_test_extract_output_text(array $resp): string
{
    if (!empty($resp['output_text']) && is_string($resp['output_text'])) {
        return trim($resp['output_text']);
    }
    $out = $resp['output'] ?? [];
    if (!is_array($out)) {
        return '';
    }
    $text = '';
    foreach ($out as $item) {
        if (!is_array($item)) {
            continue;
        }
        $content = $item['content'] ?? [];
        if (!is_array($content)) {
            continue;
        }
        foreach ($content as $c) {
            if (is_array($c) && ($c['type'] ?? '') === 'output_text') {
                $text .= (string)($c['text'] ?? '');
            }
        }
    }

    return trim($text);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    rl_search_test_json_out(405, ['ok' => false, 'error' => 'POST required']);
}

$raw = file_get_contents('php://input');
$data = json_decode((string)$raw, true);
if (!is_array($data)) {
    rl_search_test_json_out(400, ['ok' => false, 'error' => 'Invalid JSON']);
}

$editionId = (int)($data['edition_id'] ?? 0);
$query = trim((string)($data['query'] ?? ''));
$useAi = !empty($data['use_ai']);

if ($editionId <= 0) {
    rl_search_test_json_out(400, ['ok' => false, 'error' => 'edition_id required']);
}
if ($query === '') {
    rl_search_test_json_out(400, ['ok' => false, 'error' => 'query required']);
}

$stmt = $pdo->prepare('SELECT id FROM resource_library_editions WHERE id = ? LIMIT 1');
$stmt->execute([$editionId]);
if (!$stmt->fetchColumn()) {
    rl_search_test_json_out(404, ['ok' => false, 'error' => 'Edition not found']);
}

$hits = rl_ai_search_resource_blocks($pdo, $editionId, $query, 10);
$outHits = [];
$contextParts = [];
foreach ($hits as $h) {
    if (!is_array($h)) {
        continue;
    }
    $body = (string)($h['body_text'] ?? '');
    $snippet = $body;
    if (strlen($snippet) > 520) {
        $snippet = substr($snippet, 0, 520) . '…';
    }
    $outHits[] = [
        'chapter' => (string)($h['chapter'] ?? ''),
        'block_local_id' => (string)($h['block_local_id'] ?? ''),
        'sort_index' => (int)($h['sort_index'] ?? 0),
        'snippet' => $snippet,
    ];
    $contextParts[] = '[chapter=' . ($h['chapter'] ?? '') . ' id=' . ($h['block_local_id'] ?? '') . "]\n" . $body;
}

$payload = [
    'ok' => true,
    'hits' => $outHits,
    'hit_count' => count($outHits),
];

if (!$useAi) {
    rl_search_test_json_out(200, $payload);
}

if ($outHits === []) {
    $payload['ai_answer'] = '';
    $payload['ai_note'] = 'No database hits; widen your search or run Sync JSON → database.';
    rl_search_test_json_out(200, $payload);
}

$bundle = implode("\n\n---\n\n", $contextParts);
if (strlen($bundle) > 14000) {
    $bundle = substr($bundle, 0, 14000) . "\n\n[…truncated…]";
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
                        'text' => 'You are an FAA ground-school tutor. Answer the user using ONLY the reference excerpts provided below (from the indexed handbook). If the excerpts do not contain enough information, say what is missing and which topic to look up. Be concise and accurate. Cite chapter identifiers when helpful.',
                    ],
                ],
            ],
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => 'Question: ' . $query . "\n\nReference excerpts:\n" . $bundle,
                    ],
                ],
            ],
        ],
    ], 90);
    $payload['ai_answer'] = rl_search_test_extract_output_text($resp);
} catch (Throwable $e) {
    $payload['ai_error'] = $e->getMessage();
}

rl_search_test_json_out(200, $payload);
