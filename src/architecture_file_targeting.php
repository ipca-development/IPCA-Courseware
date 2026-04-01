<?php
declare(strict_types=1);

function load_relevant_file_intelligence(PDO $pdo, string $text, int $limit = 10): array
{
    $text = strtolower($text);

    $keywords = [];

    // 🔹 Extract file-like tokens
    if (preg_match_all('/[a-zA-Z0-9_\-\/]+\.php/', $text, $m)) {
        foreach ($m[0] as $f) {
            $keywords[] = $f;
        }
    }

    // 🔹 Extract keywords
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

		$exactFile = null;
	if (preg_match('/([a-zA-Z0-9_\-\/]+\.php)/', $text, $m)) {
		$exactFile = strtolower(trim((string)$m[1]));
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

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($exactFile !== null) {
    usort($rows, function ($a, $b) use ($exactFile) {
        $aPath = strtolower((string)($a['file_path'] ?? ''));
        $bPath = strtolower((string)($b['file_path'] ?? ''));

        $aScore = (strpos($aPath, $exactFile) !== false) ? 0 : 1;
        $bScore = (strpos($bPath, $exactFile) !== false) ? 0 : 1;

        if ($aScore !== $bScore) {
            return $aScore <=> $bScore;
        }

        return strcmp($aPath, $bPath);
    });
}

return $rows;
}

function build_targeted_context(PDO $pdo, string $text): array
{
    $rows = load_relevant_file_intelligence($pdo, $text);

    if (!$rows) {
        return ['summary' => '', 'files' => []];
    }

    $summaryLines = [];
    $summaryLines[] = 'RELEVANT FILE CONTEXT';
    $summaryLines[] = '';

    $filePaths = [];

    foreach ($rows as $r) {

        $filePaths[] = $r['file_path'];

        $summaryLines[] = $r['file_path'];

        if (!empty($r['module'])) {
            $summaryLines[] = '- module: ' . $r['module'];
        }

        if (!empty($r['purpose'])) {
            $summaryLines[] = '- purpose: ' . $r['purpose'];
        }

        $tables = json_decode($r['tables_json'] ?? '[]', true);
        if (!empty($tables)) {
            $summaryLines[] = '- tables: [' . implode(', ', $tables) . ']';
        }

        $helpers = json_decode($r['helpers_json'] ?? '[]', true);
        if (!empty($helpers)) {
            $summaryLines[] = '- helpers: [' . implode(', ', $helpers) . ']';
        }

        $summaryLines[] = '';
    }

    $uniqueFiles = array_values(array_unique($filePaths));

    $primaryFile = null;

    if (!empty($rows) && isset($rows[0]['file_path'])) {
        $primaryFile = (string)$rows[0]['file_path'];
    }

    // Ensure primary file is first (no duplicates)
    if ($primaryFile !== null) {
        $uniqueFiles = array_values(array_filter($uniqueFiles, function ($f) use ($primaryFile) {
            return $f !== $primaryFile;
        }));

        array_unshift($uniqueFiles, $primaryFile);
    }

    return [
        'summary' => implode("\n", $summaryLines),
        'files' => array_slice($uniqueFiles, 0, 1),
        'primary_file' => $primaryFile,
    ];
}