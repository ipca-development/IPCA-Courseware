<?php
declare(strict_types=1);

function load_relevant_file_intelligence(PDO $pdo, string $text, int $limit = 10): array
{
    $text = strtolower($text);

    $keywords = [];

    // ? Extract file-like tokens
    if (preg_match_all('/[a-zA-Z0-9_\-\/]+\.php/', $text, $m)) {
        foreach ($m[0] as $f) {
            $keywords[] = $f;
        }
    }

    // ? Extract keywords
    $words = preg_split('/[^a-z0-9_]+/', $text);
    foreach ($words as $w) {
        if (strlen($w) >= 4) {
            $keywords[] = $w;
        }
    }

    $keywords = array_values(array_unique($keywords));

    if (empty($keywords)) {
        return [];
    }

    $sql = "
        SELECT file_path, module, purpose, tables_json, helpers_json, functions_json, includes_json
        FROM ai_architecture_file_index
        WHERE
    ";

    $conditions = [];
    $params = [];

    foreach ($keywords as $k) {
        $conditions[] = "file_path LIKE ?";
        $params[] = '%' . $k . '%';

        $conditions[] = "purpose LIKE ?";
        $params[] = '%' . $k . '%';

        $conditions[] = "tables_json LIKE ?";
        $params[] = '%' . $k . '%';

        $conditions[] = "helpers_json LIKE ?";
        $params[] = '%' . $k . '%';
    }

    $sql .= implode(' OR ', $conditions);
    $sql .= " ORDER BY file_path ASC LIMIT " . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function build_targeted_context(PDO $pdo, string $text): string
{
    $rows = load_relevant_file_intelligence($pdo, $text);

    if (!$rows) {
        return '';
    }

    $lines = [];
    $lines[] = 'RELEVANT FILE CONTEXT';
    $lines[] = '';

    foreach ($rows as $r) {

    $lines[] = $r['file_path'];

    if (!empty($r['module'])) {
        $lines[] = '- module: ' . $r['module'];
    }

    if (!empty($r['purpose'])) {
        $lines[] = '- purpose: ' . $r['purpose'];
    }

    $tables = json_decode($r['tables_json'] ?? '[]', true);
    if (!empty($tables)) {
        $lines[] = '- tables: [' . implode(', ', $tables) . ']';
    }

    $helpers = json_decode($r['helpers_json'] ?? '[]', true);
    if (!empty($helpers)) {
        $lines[] = '- helpers: [' . implode(', ', $helpers) . ']';
    }

    $functions = json_decode($r['functions_json'] ?? '[]', true);
    if (!empty($functions)) {
        $lines[] = '- functions: [' . implode(', ', $functions) . ']';
    }

    $includes = json_decode($r['includes_json'] ?? '[]', true);
    if (!empty($includes)) {
        $lines[] = '- includes: [' . implode(', ', $includes) . ']';
    }

    $lines[] = '';
}

    return implode("\n", $lines);
}