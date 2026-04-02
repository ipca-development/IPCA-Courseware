<?php
declare(strict_types=1);

function load_latest_architecture_snapshot_id(PDO $pdo): ?int
{
    $stmt = $pdo->query("
        SELECT id
        FROM ai_architecture_snapshots
        ORDER BY id DESC
        LIMIT 1
    ");

    $value = $stmt->fetchColumn();

    if ($value === false || $value === null) {
        return null;
    }

    return (int)$value;
}

function extract_targeting_keywords(string $text): array
{
    $text = strtolower($text);
    $keywords = [];

    if (preg_match_all('/[a-zA-Z0-9_\-\/]+\.php/', $text, $m)) {
        foreach ($m[0] as $fileToken) {
            $fileToken = trim((string)$fileToken);
            if ($fileToken !== '') {
                $keywords[] = $fileToken;
            }
        }
    }

    $words = preg_split('/[^a-z0-9_]+/', $text);
    if (is_array($words)) {
        foreach ($words as $word) {
            $word = trim((string)$word);
            if (strlen($word) >= 4) {
                $keywords[] = $word;
            }
        }
    }

    return array_values(array_unique($keywords));
}

function extract_exact_target_file(string $text): ?string
{
    if (preg_match('/([a-zA-Z0-9_\-\/]+\.php)/i', $text, $m)) {
        $file = strtolower(trim((string)$m[1]));
        if ($file !== '') {
            return $file;
        }
    }

    return null;
}

function compute_file_intelligence_score(array $row, array $keywords, ?string $exactFile): int
{
    $score = 0;

    $filePath = strtolower((string)($row['file_path'] ?? ''));
    $purpose = strtolower((string)($row['purpose'] ?? ''));
    $module = strtolower((string)($row['module'] ?? ''));
    $tablesJson = strtolower((string)($row['tables_json'] ?? ''));
    $helpersJson = strtolower((string)($row['helpers_json'] ?? ''));
    $functionsJson = strtolower((string)($row['functions_json'] ?? ''));
    $includesJson = strtolower((string)($row['includes_json'] ?? ''));

    if ($exactFile !== null && $exactFile !== '') {
        if ($filePath === $exactFile) {
            $score += 1000;
        } elseif (strpos($filePath, $exactFile) !== false) {
            $score += 700;
        } elseif (basename($filePath) === basename($exactFile)) {
            $score += 500;
        }
    }

    foreach ($keywords as $keyword) {
        $keyword = strtolower(trim((string)$keyword));
        if ($keyword === '') {
            continue;
        }

        if ($filePath === $keyword) {
            $score += 300;
        } elseif (strpos($filePath, $keyword) !== false) {
            $score += 120;
        }

        if (strpos($functionsJson, '"' . $keyword . '"') !== false) {
            $score += 90;
        } elseif (strpos($functionsJson, $keyword) !== false) {
            $score += 60;
        }

        if (strpos($helpersJson, '"' . $keyword . '"') !== false) {
            $score += 70;
        } elseif (strpos($helpersJson, $keyword) !== false) {
            $score += 45;
        }

        if (strpos($tablesJson, '"' . $keyword . '"') !== false) {
            $score += 55;
        } elseif (strpos($tablesJson, $keyword) !== false) {
            $score += 35;
        }

        if (strpos($includesJson, '"' . $keyword . '"') !== false) {
            $score += 45;
        } elseif (strpos($includesJson, $keyword) !== false) {
            $score += 25;
        }

        if (strpos($purpose, $keyword) !== false) {
            $score += 25;
        }

        if (strpos($module, $keyword) !== false) {
            $score += 15;
        }
    }

    if ($module === 'core') {
        $score += 5;
    }

    return $score;
}

function load_relevant_file_intelligence(PDO $pdo, string $text, int $limit = 10): array
{
    $keywords = extract_targeting_keywords($text);

    if (empty($keywords)) {
        return [];
    }

    $latestSnapshotId = load_latest_architecture_snapshot_id($pdo);
    if ($latestSnapshotId === null) {
        return [];
    }

    $exactFile = extract_exact_target_file($text);

    $sql = "
        SELECT
            id,
            snapshot_id,
            file_path,
            module,
            purpose,
            tables_json,
            helpers_json,
            functions_json,
            includes_json
        FROM ai_architecture_file_index
        WHERE snapshot_id = ?
          AND (
    ";

    $conditions = [];
    $params = [$latestSnapshotId];

    foreach ($keywords as $keyword) {
        $conditions[] = "file_path LIKE ?";
        $params[] = '%' . $keyword . '%';

        $conditions[] = "purpose LIKE ?";
        $params[] = '%' . $keyword . '%';

        $conditions[] = "tables_json LIKE ?";
        $params[] = '%' . $keyword . '%';

        $conditions[] = "helpers_json LIKE ?";
        $params[] = '%' . $keyword . '%';

        $conditions[] = "functions_json LIKE ?";
        $params[] = '%' . $keyword . '%';

        $conditions[] = "includes_json LIKE ?";
        $params[] = '%' . $keyword . '%';
    }

    $sql .= implode(' OR ', $conditions);
    $sql .= "
          )
        ORDER BY file_path ASC
        LIMIT " . (int)max($limit * 3, 12);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) {
        return [];
    }

    foreach ($rows as &$row) {
        $row['_score'] = compute_file_intelligence_score($row, $keywords, $exactFile);
    }
    unset($row);

    usort($rows, function ($a, $b) {
        $aScore = (int)($a['_score'] ?? 0);
        $bScore = (int)($b['_score'] ?? 0);

        if ($aScore !== $bScore) {
            return $bScore <=> $aScore;
        }

        $aPath = strtolower((string)($a['file_path'] ?? ''));
        $bPath = strtolower((string)($b['file_path'] ?? ''));

        return strcmp($aPath, $bPath);
    });

    $deduped = [];
    $seen = [];

    foreach ($rows as $row) {
        $path = (string)($row['file_path'] ?? '');
        if ($path === '' || isset($seen[$path])) {
            continue;
        }

        $seen[$path] = true;
        $deduped[] = $row;

        if (count($deduped) >= $limit) {
            break;
        }
    }

    return $deduped;
}

function build_targeted_context(PDO $pdo, string $text): array
{
    $rows = load_relevant_file_intelligence($pdo, $text, 5);

    if (!$rows) {
        return [
            'summary' => '',
            'files' => [],
            'primary_file' => null,
        ];
    }

    $summaryLines = [];
    $summaryLines[] = 'RELEVANT FILE CONTEXT';
    $summaryLines[] = '';

    $filePaths = [];

    foreach ($rows as $row) {
        $path = (string)($row['file_path'] ?? '');
        if ($path === '') {
            continue;
        }

        $filePaths[] = $path;

        $summaryLines[] = $path;

        if (!empty($row['module'])) {
            $summaryLines[] = '- module: ' . (string)$row['module'];
        }

        if (!empty($row['purpose'])) {
            $summaryLines[] = '- purpose: ' . (string)$row['purpose'];
        }

        $tables = json_decode((string)($row['tables_json'] ?? '[]'), true);
        if (is_array($tables) && !empty($tables)) {
            $summaryLines[] = '- tables: [' . implode(', ', $tables) . ']';
        }

        $helpers = json_decode((string)($row['helpers_json'] ?? '[]'), true);
        if (is_array($helpers) && !empty($helpers)) {
            $summaryLines[] = '- helpers: [' . implode(', ', $helpers) . ']';
        }

        $functions = json_decode((string)($row['functions_json'] ?? '[]'), true);
        if (is_array($functions) && !empty($functions)) {
            $summaryLines[] = '- functions: [' . implode(', ', array_slice($functions, 0, 8)) . ']';
        }

        $includes = json_decode((string)($row['includes_json'] ?? '[]'), true);
        if (is_array($includes) && !empty($includes)) {
            $summaryLines[] = '- includes: [' . implode(', ', array_slice($includes, 0, 5)) . ']';
        }

        $summaryLines[] = '';
    }

    $uniqueFiles = array_values(array_unique($filePaths));
    $primaryFile = !empty($uniqueFiles) ? (string)$uniqueFiles[0] : null;

    if ($primaryFile !== null) {
        $uniqueFiles = array_values(array_filter($uniqueFiles, function ($file) use ($primaryFile) {
            return $file !== $primaryFile;
        }));
        array_unshift($uniqueFiles, $primaryFile);
    }

    return [
        'summary' => implode("\n", $summaryLines),
        'files' => array_slice($uniqueFiles, 0, 3),
        'primary_file' => $primaryFile,
    ];
}