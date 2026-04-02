<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/openai.php';
require_once __DIR__ . '/../../../src/architecture_file_targeting.php';

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

function path_looks_like_full_relative_path(string $path): bool
{
    $path = trim(str_replace('\\', '/', $path), '/');
    if ($path === '') {
        return false;
    }

    return strpos($path, '/') !== false;
}

function resolve_candidate_path_from_target_data(string $candidatePath, array $targetData): ?string
{
    $candidatePath = trim(str_replace('\\', '/', $candidatePath), '/');
    if ($candidatePath === '') {
        return null;
    }

    if (path_looks_like_full_relative_path($candidatePath)) {
        return $candidatePath;
    }

    $possibleFiles = [];

    if (!empty($targetData['primary_file']) && is_string($targetData['primary_file'])) {
        $possibleFiles[] = (string)$targetData['primary_file'];
    }

    if (!empty($targetData['files']) && is_array($targetData['files'])) {
        foreach ($targetData['files'] as $f) {
            if (is_string($f) && $f !== '') {
                $possibleFiles[] = $f;
            }
        }
    }

    $possibleFiles = array_values(array_unique($possibleFiles));

    foreach ($possibleFiles as $fullPath) {
        $fullPathNorm = trim(str_replace('\\', '/', $fullPath), '/');
        if ($fullPathNorm === '') {
            continue;
        }

        if (strcasecmp(basename($fullPathNorm), basename($candidatePath)) === 0) {
            return $fullPathNorm;
        }
    }

    return $candidatePath;
}

function resolve_explicit_file_candidates(PDO $pdo, string $prompt): array
{
    $explicitPaths = extract_file_candidates_from_text($prompt);
    if (empty($explicitPaths)) {
        return [];
    }

    $targetData = build_targeted_context($pdo, $prompt);
    $resolved = [];

    foreach ($explicitPaths as $path) {
        $resolvedPath = resolve_candidate_path_from_target_data((string)$path, $targetData);
        if ($resolvedPath !== null && $resolvedPath !== '') {
            $resolved[] = $resolvedPath;
        }
    }

    return array_values(array_unique($resolved));
}

function pick_primary_target_path(PDO $pdo, string $prompt, array $contextFiles = array()): ?string
{
    
	if (preg_match('/FORCED_TARGET_PATH:\s*([^\r\n]+)/i', $prompt, $m)) {
    $forcedPath = trim((string)$m[1]);
        if ($forcedPath !== '') {
            return $forcedPath;
        }
    }
	
	$explicitPaths = resolve_explicit_file_candidates($pdo, $prompt);
    if (!empty($explicitPaths)) {
        return (string)$explicitPaths[0];
    }

    $targetData = build_targeted_context($pdo, $prompt);
    if (!empty($targetData['primary_file']) && is_string($targetData['primary_file'])) {
        return (string)$targetData['primary_file'];
    }

    foreach ($contextFiles as $f) {
        if (empty($f['error']) && !empty($f['path'])) {
            return (string)$f['path'];
        }
    }

    return null;
}

function extract_method_like_tokens_from_text(string $text, int $limit = 8): array
{
    $tokens = array();

    if (preg_match_all('/\b([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $text, $m)) {
        foreach ($m[1] as $name) {
            $name = trim((string)$name);
            if ($name === '' || strlen($name) < 6) {
                continue;
            }

            $tokens[] = $name;
        }
    }

    $tokens = array_values(array_unique($tokens));

    if (count($tokens) > $limit) {
        $tokens = array_slice($tokens, 0, $limit);
    }

    return $tokens;
}

function extract_targeted_excerpt_from_file_content(string $content, array $methodNames, int $radius = 7000): ?string
{
    if ($content === '' || empty($methodNames)) {
        return null;
    }

    foreach ($methodNames as $methodName) {
        $methodName = trim((string)$methodName);
        if ($methodName === '') {
            continue;
        }

        $patterns = array(
            '/public\s+function\s+' . preg_quote($methodName, '/') . '\s*\(/i',
            '/protected\s+function\s+' . preg_quote($methodName, '/') . '\s*\(/i',
            '/private\s+function\s+' . preg_quote($methodName, '/') . '\s*\(/i',
            '/function\s+' . preg_quote($methodName, '/') . '\s*\(/i'
        );

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $match, PREG_OFFSET_CAPTURE)) {
                $offset = (int)$match[0][1];
                $start = max(0, $offset - 1400);
                $length = $radius;

                $excerpt = substr($content, $start, $length);
                if (!is_string($excerpt) || $excerpt === '') {
                    continue;
                }

                return "/* TARGETED METHOD EXCERPT: " . $methodName . " */\n\n" . $excerpt;
            }
        }
    }

    foreach ($methodNames as $methodName) {
        $methodName = trim((string)$methodName);
        if ($methodName === '') {
            continue;
        }

        $offset = stripos($content, $methodName);
        if ($offset !== false) {
            $start = max(0, (int)$offset - 1800);
            $length = $radius;

            $excerpt = substr($content, $start, $length);
            if (!is_string($excerpt) || $excerpt === '') {
                continue;
            }

            return "/* TARGETED SYMBOL EXCERPT: " . $methodName . " */\n\n" . $excerpt;
        }
    }

    return null;
}


//LARGE FILE UPGRADE

function extract_large_file_tail_excerpt(string $content, array $methodNames, int $tailChars = 28000): ?string
{
    if ($content === '') {
        return null;
    }

    $len = mb_strlen($content);
    if ($len <= $tailChars) {
        return null;
    }

    $tail = mb_substr($content, $len - $tailChars, $tailChars);
    if (!is_string($tail) || $tail === '') {
        return null;
    }

    $label = !empty($methodNames) ? implode(', ', $methodNames) : 'unknown_symbol';

    return "/* LARGE FILE TAIL FALLBACK FOR: " . $label . " */\n\n" . $tail;
}

function extract_method_inventory_from_file_content(string $content): array
{
    $methods = array();

    if ($content === '') {
        return $methods;
    }

    if (preg_match_all('/\b(public)\s+function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\((.*?)\)\s*(?::\s*([A-Za-z0-9_\\\\?]+))?/s', $content, $m, PREG_SET_ORDER)) {
        foreach ($m as $row) {
            $visibility = isset($row[1]) ? trim((string)$row[1]) : '';
            $name = isset($row[2]) ? trim((string)$row[2]) : '';
            $params = isset($row[3]) ? trim((string)$row[3]) : '';
            $returnType = isset($row[4]) ? trim((string)$row[4]) : '';

            if ($name === '') {
                continue;
            }

            $signature = $visibility . ' function ' . $name . '(' . $params . ')';
            if ($returnType !== '') {
                $signature .= ': ' . $returnType;
            }

            $methods[] = array(
                'visibility' => $visibility,
                'name' => $name,
                'signature' => $signature,
            );
        }
    }

    return $methods;
}

function build_method_inventory_text(string $content): ?string
{
    $methods = extract_method_inventory_from_file_content($content);
    if (empty($methods)) {
        return null;
    }

    $lines = array();
    $lines[] = '/* METHOD INVENTORY */';
    $lines[] = '';

    foreach ($methods as $method) {
        $lines[] = $method['signature'];
    }

    return implode("\n", $lines);
}

function find_matching_brace_position(string $content, int $openBracePos): ?int
{
    $len = strlen($content);
    if ($openBracePos < 0 || $openBracePos >= $len) {
        return null;
    }

    $depth = 0;
    $inSingle = false;
    $inDouble = false;
    $inLineComment = false;
    $inBlockComment = false;
    $escaped = false;

    for ($i = $openBracePos; $i < $len; $i++) {
        $ch = $content[$i];
        $next = ($i + 1 < $len) ? $content[$i + 1] : '';

        if ($inLineComment) {
            if ($ch === "\n") {
                $inLineComment = false;
            }
            continue;
        }

        if ($inBlockComment) {
            if ($ch === '*' && $next === '/') {
                $inBlockComment = false;
                $i++;
            }
            continue;
        }

        if ($inSingle) {
            if ($ch === '\\' && !$escaped) {
                $escaped = true;
                continue;
            }
            if ($ch === "'" && !$escaped) {
                $inSingle = false;
            }
            $escaped = false;
            continue;
        }

        if ($inDouble) {
            if ($ch === '\\' && !$escaped) {
                $escaped = true;
                continue;
            }
            if ($ch === '"' && !$escaped) {
                $inDouble = false;
            }
            $escaped = false;
            continue;
        }

        if ($ch === '/' && $next === '/') {
            $inLineComment = true;
            $i++;
            continue;
        }

        if ($ch === '/' && $next === '*') {
            $inBlockComment = true;
            $i++;
            continue;
        }

        if ($ch === "'") {
            $inSingle = true;
            continue;
        }

        if ($ch === '"') {
            $inDouble = true;
            continue;
        }

        if ($ch === '{') {
            $depth++;
            continue;
        }

        if ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                return $i;
            }
        }
    }

    return null;
}

function extract_exact_method_block_from_file_content(string $content, string $methodName): ?string
{
    $methodName = trim($methodName);
    if ($content === '' || $methodName === '') {
        return null;
    }

    $pattern = '/\b(public|protected|private)?\s*function\s+' . preg_quote($methodName, '/') . '\s*\(/i';
    if (!preg_match($pattern, $content, $match, PREG_OFFSET_CAPTURE)) {
        return null;
    }

    $methodOffset = (int)$match[0][1];
    $openBracePos = strpos($content, '{', $methodOffset);
    if ($openBracePos === false) {
        return null;
    }

    $closeBracePos = find_matching_brace_position($content, (int)$openBracePos);
    if ($closeBracePos === null) {
        return null;
    }

    $start = $methodOffset;
    $docPos = strrpos(substr($content, 0, $methodOffset), '/**');
    if ($docPos !== false) {
        $between = substr($content, $docPos, $methodOffset - $docPos);
        if (strpos($between, '*/') !== false) {
            $start = (int)$docPos;
        }
    }

    $block = substr($content, $start, $closeBracePos - $start + 1);
    if (!is_string($block) || trim($block) === '') {
        return null;
    }

    return $block;
}

function extract_exact_method_blocks_from_file_content(string $content, array $methodNames, int $maxBlocks = 4): ?string
{
    if ($content === '' || empty($methodNames)) {
        return null;
    }

    $blocks = array();
    $seen = array();

    foreach ($methodNames as $methodName) {
        $methodName = trim((string)$methodName);
        if ($methodName === '') {
            continue;
        }

        $block = extract_exact_method_block_from_file_content($content, $methodName);
        if ($block === null) {
            continue;
        }

        $hash = md5($block);
        if (isset($seen[$hash])) {
            continue;
        }

        $seen[$hash] = true;
        $blocks[] = "/* EXACT METHOD BLOCK: " . $methodName . " */\n\n" . $block;

        if (count($blocks) >= $maxBlocks) {
            break;
        }
    }

    if (empty($blocks)) {
        return null;
    }

    return implode("\n\n", $blocks);
}

function should_force_method_inventory_mode(string $prompt): bool
{
    $promptLower = strtolower($prompt);

    $signals = array(
        'list all public methods',
        'public methods',
        'visible public methods',
        'method inventory',
        'which methods are visible',
        'complete method list',
        'list methods'
    );

    foreach ($signals as $signal) {
        if (strpos($promptLower, $signal) !== false) {
            return true;
        }
    }

    return false;
}

function read_files_for_targeted_context(
    array $paths,
    array $methodNames,
    int $limit = 3,
    int $fallbackMaxCharsPerFile = 12000,
    bool $preferFullFileWhenSmall = false,
    ?string $prompt = null
): array
{
    $out = array();
    $count = 0;
    $forceMethodInventory = ($prompt !== null && should_force_method_inventory_mode($prompt));

    $paths = array_values(array_unique($paths));

    foreach ($paths as $path) {
        if ($count >= $limit) {
            break;
        }

        try {
            $file = safe_project_file_read((string)$path);
            $content = (string)$file['content'];
            $len = strlen($content);

            if ($forceMethodInventory) {
                $inventory = build_method_inventory_text($content);
                if ($inventory !== null) {
                    $out[] = array(
                        'path' => $file['path'],
                        'basename' => $file['basename'],
                        'size_bytes' => $file['size_bytes'],
                        'content' => $inventory,
                    );
                    $count++;
                    continue;
                }
            }

            if ($preferFullFileWhenSmall && $len <= 80000) {
                $out[] = array(
                    'path' => $file['path'],
                    'basename' => $file['basename'],
                    'size_bytes' => $file['size_bytes'],
                    'content' => $content,
                );
                $count++;
                continue;
            }

            $exactBlocks = extract_exact_method_blocks_from_file_content($content, $methodNames, 4);
            if ($exactBlocks !== null) {
                $out[] = array(
                    'path' => $file['path'],
                    'basename' => $file['basename'],
                    'size_bytes' => $file['size_bytes'],
                    'content' => $exactBlocks,
                );
                $count++;
                continue;
            }

            $targetedExcerpt = extract_targeted_excerpt_from_file_content($content, $methodNames);

            if ($targetedExcerpt === null && !empty($methodNames)) {
                $targetedExcerpt = extract_large_file_tail_excerpt($content, $methodNames, 28000);
            }

            if ($targetedExcerpt !== null) {
                $out[] = array(
                    'path' => $file['path'],
                    'basename' => $file['basename'],
                    'size_bytes' => $file['size_bytes'],
                    'content' => $targetedExcerpt,
                );
            } else {
                if ($len <= $fallbackMaxCharsPerFile) {
                    $out[] = array(
                        'path' => $file['path'],
                        'basename' => $file['basename'],
                        'size_bytes' => $file['size_bytes'],
                        'content' => $content,
                    );
                } else {
                    $out[] = array(
                        'path' => $file['path'],
                        'basename' => $file['basename'],
                        'size_bytes' => $file['size_bytes'],
                        'content' => "/* FILE CHUNK 1 / 1 */\n\n" . mb_substr($content, 0, $fallbackMaxCharsPerFile),
                    );
                }
            }

            $count++;
        } catch (Throwable $e) {
            $out[] = array(
                'path' => (string)$path,
                'error' => $e->getMessage(),
            );
        }
    }

    return $out;
}


function read_files_for_context(array $paths, int $limit = 5, int $maxCharsPerFile = 6000): array
{
    $out = [];
    $count = 0;

    $paths = array_values(array_unique($paths));

    foreach ($paths as $path) {
        if ($count >= $limit) {
            break;
        }

        try {
            $file = safe_project_file_read((string)$path);
            $content = (string)$file['content'];
            $len = strlen($content);

            if ($len <= 80000) {
                $out[] = [
                    'path' => $file['path'],
                    'basename' => $file['basename'],
                    'size_bytes' => $file['size_bytes'],
                    'content' => $content,
                ];
            } else {
                $out[] = [
                    'path' => $file['path'],
                    'basename' => $file['basename'],
                    'size_bytes' => $file['size_bytes'],
                    'content' => "/* FILE CHUNK 1 / 1 */\n\n" . mb_substr($content, 0, $maxCharsPerFile),
                ];
            }

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

function load_database_schema(PDO $pdo): array
{
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $tables = array_slice($tables, 0, 15);

    $schema = [];

    foreach ($tables as $table) {
        $stmt = $pdo->query("DESCRIBE `$table`");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $schema[$table] = array_slice($columns, 0, 10);
    }

    return $schema;
}

function load_project_file_index(string $root): array
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $path = str_replace($root . '/', '', $file->getPathname());

        if (
            strpos($path, 'vendor/') === 0 ||
            strpos($path, '.git/') === 0 ||
            strpos($path, 'node_modules/') === 0
        ) {
            continue;
        }

        if (preg_match('/\.(php|js|css|sql)$/i', $path)) {
            $files[] = $path;
        }
    }

    return array_slice($files, 0, 20);
}


//NEW HELPERS AI

function extract_relevant_tables_from_prompt(PDO $pdo, string $prompt, int $limit = 8): array
{
    $promptLower = strtolower($prompt);
    $tables = [];

    try {
        $allTables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return [];
    }

    if (!is_array($allTables) || empty($allTables)) {
        return [];
    }

    foreach ($allTables as $table) {
        $table = (string)$table;
        if ($table === '') {
            continue;
        }

        $tableLower = strtolower($table);

        if (strpos($promptLower, $tableLower) !== false) {
            $tables[] = $table;
            continue;
        }

        $parts = preg_split('/_+/', $tableLower);
        if (!is_array($parts)) {
            continue;
        }

        $matchedParts = 0;
        foreach ($parts as $part) {
            $part = trim((string)$part);
            if ($part === '' || strlen($part) < 4) {
                continue;
            }

            if (strpos($promptLower, $part) !== false) {
                $matchedParts++;
            }
        }

        if ($matchedParts >= 1) {
            $tables[] = $table;
        }

        if (count($tables) >= $limit) {
            break;
        }
    }

    return array_values(array_unique($tables));
}

function load_targeted_schema(PDO $pdo, string $prompt, int $maxTables = 6, int $maxColumns = 12): array
{
    $tables = extract_relevant_tables_from_prompt($pdo, $prompt, $maxTables);

    if (empty($tables)) {
        return [];
    }

    $schema = [];

    foreach ($tables as $table) {
        try {
            $stmt = $pdo->query("DESCRIBE `$table`");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $schema[$table] = array_slice($columns ?: [], 0, $maxColumns);
        } catch (Throwable $e) {
            $schema[$table] = [
                [
                    'Field' => '[describe_failed]',
                    'Type' => $e->getMessage(),
                    'Null' => '',
                    'Key' => '',
                    'Default' => '',
                    'Extra' => ''
                ]
            ];
        }
    }

    return $schema;
}

function load_targeted_project_index(string $root, array $targetFiles, string $prompt, int $limit = 12): array
{
    $allFiles = load_project_file_index($root);
    if (empty($allFiles)) {
        return [];
    }

    $promptLower = strtolower($prompt);
    $scored = [];

    foreach ($allFiles as $path) {
        $path = (string)$path;
        $pathLower = strtolower($path);
        $score = 0;

        if (in_array($path, $targetFiles, true)) {
            $score += 1000;
        }

        $base = strtolower(basename($path));
        if ($base !== '' && strpos($promptLower, $base) !== false) {
            $score += 500;
        }

        $parts = preg_split('/[\/_.-]+/', $pathLower);
        if (is_array($parts)) {
            foreach ($parts as $part) {
                $part = trim((string)$part);
                if ($part === '' || strlen($part) < 4) {
                    continue;
                }

                if (strpos($promptLower, $part) !== false) {
                    $score += 25;
                }
            }
        }

        if ($score > 0) {
            $scored[] = [
                'path' => $path,
                'score' => $score
            ];
        }
    }

    usort($scored, function ($a, $b) {
        $aScore = (int)$a['score'];
        $bScore = (int)$b['score'];

        if ($aScore !== $bScore) {
            return $bScore <=> $aScore;
        }

        return strcmp((string)$a['path'], (string)$b['path']);
    });

    $out = [];
    foreach ($scored as $row) {
        $out[] = (string)$row['path'];
        if (count($out) >= $limit) {
            break;
        }
    }

    return array_values(array_unique($out));
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

function detect_preferred_output_mode(string $prompt, ?string $primaryTargetPath = null): string
{
    $promptLower = strtolower($prompt);

    if (
        strpos($promptLower, 'surgical patch') !== false ||
        strpos($promptLower, 'surgical_patch') !== false ||
        strpos($promptLower, 'small patch') !== false ||
        strpos($promptLower, 'minimal patch') !== false
    ) {
        return 'surgical_patch';
    }

    if (
        strpos($promptLower, 'full drop-in') !== false ||
        strpos($promptLower, 'full drop in') !== false ||
        strpos($promptLower, 'full replacement') !== false ||
        strpos($promptLower, 'rewrite the file') !== false ||
        strpos($promptLower, 'replace the file') !== false
    ) {
        return 'full_drop_in';
    }

    if ($primaryTargetPath !== null && $primaryTargetPath !== '') {
        return 'surgical_patch';
    }

    return 'analysis_only';
}

function detect_task_type(string $prompt): string
{
    $promptLower = strtolower($prompt);

    if (
        strpos($promptLower, 'normalize') !== false ||
        strpos($promptLower, 'alias') !== false
    ) {
        return 'alias_normalization';
    }

    if (
        strpos($promptLower, 'fix') !== false ||
        strpos($promptLower, 'patch') !== false ||
        strpos($promptLower, 'repair') !== false
    ) {
        return 'bugfix';
    }

    if (
        strpos($promptLower, 'create') !== false ||
        strpos($promptLower, 'build') !== false ||
        strpos($promptLower, 'implement') !== false ||
        strpos($promptLower, 'add') !== false
    ) {
        return 'implementation';
    }

    if (
        strpos($promptLower, 'explain') !== false ||
        strpos($promptLower, 'trace') !== false ||
        strpos($promptLower, 'identify') !== false ||
        strpos($promptLower, 'safest place') !== false
    ) {
        return 'analysis_trace';
    }

    return 'investigation';
}

function build_scope_contract(PDO $pdo, array $requestRow, array $contextFiles): array
{
    $prompt = trim((string)($requestRow['prompt'] ?? ''));

    $targetData = build_targeted_context($pdo, $prompt);
    $primaryTargetPath = pick_primary_target_path($pdo, $prompt, $contextFiles);
    $supportingPaths = array();

    if (!empty($targetData['files']) && is_array($targetData['files'])) {
        foreach ($targetData['files'] as $path) {
            $path = trim((string)$path);
            if ($path !== '' && $path !== $primaryTargetPath) {
                $supportingPaths[] = $path;
            }
        }
    }

    foreach ($contextFiles as $f) {
        if (!empty($f['error']) || empty($f['path'])) {
            continue;
        }

        $path = trim((string)$f['path']);
        if ($path !== '' && $path !== $primaryTargetPath) {
            $supportingPaths[] = $path;
        }
    }

    $supportingPaths = array_values(array_unique($supportingPaths));
    if (count($supportingPaths) > 3) {
        $supportingPaths = array_slice($supportingPaths, 0, 3);
    }

    $methodNames = extract_method_like_tokens_from_text($prompt, 6);

    $preferredOutputMode = detect_preferred_output_mode($prompt, $primaryTargetPath);
    $taskType = detect_task_type($prompt);

    $noClassRewrite = 'yes';
    if (
        strpos(strtolower($prompt), 'rewrite the file') !== false ||
        strpos(strtolower($prompt), 'full replacement') !== false ||
        strpos(strtolower($prompt), 'full drop-in') !== false
    ) {
        $noClassRewrite = 'no';
    }

    $expectedLocalChangeOnly = ($preferredOutputMode === 'surgical_patch') ? 'yes' : 'no';

	$allowedEditPaths = array();

    if ($primaryTargetPath !== null && $primaryTargetPath !== '') {
        $allowedEditPaths[] = $primaryTargetPath;
    }

    $explicitPaths = resolve_explicit_file_candidates($pdo, $prompt);
    foreach ($explicitPaths as $path) {
        $path = trim((string)$path);
        if ($path !== '') {
            $allowedEditPaths[] = $path;
        }
    }

    $allowedEditPaths = array_values(array_unique($allowedEditPaths));

    return array(
        'primary_target_path' => $primaryTargetPath,
        'supporting_paths' => $supportingPaths,
        'allowed_edit_paths' => $allowedEditPaths,
        'preferred_output_mode' => $preferredOutputMode,
        'no_class_rewrite' => $noClassRewrite,
        'expected_local_change_only' => $expectedLocalChangeOnly,
        'task_type' => $taskType,
        'expected_symbols' => $methodNames,
    );
}

function render_scope_contract_text(array $contract): string
{
    $lines = array();
    $lines[] = 'SCOPE CONTRACT';

    $lines[] = 'PRIMARY_TARGET_PATH: ' . (string)($contract['primary_target_path'] ?? '');

    $supportingPaths = (!empty($contract['supporting_paths']) && is_array($contract['supporting_paths']))
        ? $contract['supporting_paths']
        : array();
    $lines[] = 'SUPPORTING_PATHS: ' . (!empty($supportingPaths) ? implode(', ', $supportingPaths) : '');

    $allowedEditPaths = (!empty($contract['allowed_edit_paths']) && is_array($contract['allowed_edit_paths']))
        ? $contract['allowed_edit_paths']
        : array();
    $lines[] = 'ALLOWED_EDIT_PATHS: ' . (!empty($allowedEditPaths) ? implode(', ', $allowedEditPaths) : '');

    $lines[] = 'PREFERRED_OUTPUT_MODE: ' . (string)($contract['preferred_output_mode'] ?? '');
    $lines[] = 'NO_CLASS_REWRITE: ' . (string)($contract['no_class_rewrite'] ?? 'yes');
    $lines[] = 'EXPECTED_LOCAL_CHANGE_ONLY: ' . (string)($contract['expected_local_change_only'] ?? 'yes');
    $lines[] = 'TASK_TYPE: ' . (string)($contract['task_type'] ?? 'investigation');

    $expectedSymbols = (!empty($contract['expected_symbols']) && is_array($contract['expected_symbols']))
        ? $contract['expected_symbols']
        : array();
    $lines[] = 'EXPECTED_SYMBOLS: ' . (!empty($expectedSymbols) ? implode(', ', $expectedSymbols) : '');

    return implode("\n", $lines);
}

function artifact_mentions_expected_symbols(string $artifactContent, array $expectedSymbols): bool
{
    $artifactContent = trim($artifactContent);
    if ($artifactContent === '' || empty($expectedSymbols)) {
        return false;
    }

    foreach ($expectedSymbols as $symbol) {
        $symbol = trim((string)$symbol);
        if ($symbol === '') {
            continue;
        }

        if (stripos($artifactContent, $symbol) !== false) {
            return true;
        }
    }

    return false;
}

function context_files_contain_expected_symbol_bodies(array $contextFiles, array $expectedSymbols): bool
{
    if (empty($expectedSymbols)) {
        return true;
    }

    foreach ($expectedSymbols as $symbol) {
        $symbol = trim((string)$symbol);
        if ($symbol === '') {
            continue;
        }

        $found = false;

        foreach ($contextFiles as $f) {
            if (!empty($f['error']) || empty($f['content'])) {
                continue;
            }

            $content = (string)$f['content'];

            if (
                preg_match('/public\s+function\s+' . preg_quote($symbol, '/') . '\s*\(/i', $content) ||
                preg_match('/protected\s+function\s+' . preg_quote($symbol, '/') . '\s*\(/i', $content) ||
                preg_match('/private\s+function\s+' . preg_quote($symbol, '/') . '\s*\(/i', $content) ||
                preg_match('/function\s+' . preg_quote($symbol, '/') . '\s*\(/i', $content)
            ) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            return false;
        }
    }

    return true;
}

function scope_contract_requires_method_scoped_patch(array $scopeContract): bool
{
    $taskType = trim((string)($scopeContract['task_type'] ?? ''));
    $expectedLocalChangeOnly = trim((string)($scopeContract['expected_local_change_only'] ?? ''));
    $expectedSymbols = (!empty($scopeContract['expected_symbols']) && is_array($scopeContract['expected_symbols']))
        ? $scopeContract['expected_symbols']
        : array();

    if ($expectedLocalChangeOnly !== 'yes') {
        return false;
    }

    if (empty($expectedSymbols)) {
        return false;
    }

    return in_array($taskType, array('bugfix', 'alias_normalization', 'implementation'), true);
}


function build_steven_brief(array $requestRow, ?array $ssot, array $contextFiles, array $scopeContract = array()): string
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

	if (!empty($scopeContract)) {
        $brief[] = render_scope_contract_text($scopeContract);
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

function jake_review_artifact(array $requestRow, ?array $ssot, array $contextFiles, array $artifactSeed, array $scopeContract = array()): array
{
    $artifactContent = (string)($artifactSeed['content'] ?? '');
    $targetPath = trim((string)($artifactSeed['target_path'] ?? ''));
    $outputMode = trim((string)($artifactSeed['output_mode'] ?? 'analysis_only'));
    if ($outputMode === '') {
        $outputMode = 'analysis_only';
    }
    $requestPrompt = trim((string)($requestRow['prompt'] ?? ''));

    if (strpos(ltrim($artifactContent), 'AI ERROR:') === 0) {
        return [
            'verdict' => 'analysis_only',
            'reason' => 'Steven did not complete the code generation successfully because the AI call failed before a real artifact could be produced. In other words: this is not approved code, it is a generation failure, so we should retry with less context or after the rate limit clears.',
            'revision' => 'Retry generation with reduced context size or after the OpenAI rate limit window resets.'
        ];
    }

    $containsClassRewrite =
        strpos($artifactContent, 'class CoursewareProgressionV2') !== false
        || strpos($artifactContent, 'final class CoursewareProgressionV2') !== false;

    $userExplicitlyAskedFullDropIn =
        stripos($requestPrompt, 'full drop-in') !== false
        || stripos($requestPrompt, 'full drop in') !== false
        || stripos($requestPrompt, 'full replacement') !== false
        || stripos($requestPrompt, 'rewrite the file') !== false
        || stripos($requestPrompt, 'replace the file') !== false;

    if (
        $targetPath === 'src/courseware_progression_v2.php'
        && $containsClassRewrite
        && !$userExplicitlyAskedFullDropIn
    ) {
        return [
            'verdict' => 'needs_revision',
            'reason' => 'Steven rewrote the CoursewareProgressionV2 class instead of proposing a clearly scoped addition or surgical patch. For this request, that is too broad and too risky. In other words: even if the methods used are visible, the delivery format is still wrong, this should be added as a precise patch, not as a class rewrite.',
            'revision' => 'Return a surgical_patch only. Do not redefine the class. Provide the exact insertion point for the new diagnostics method inside src/courseware_progression_v2.php.'
        ];
    }

    if (!empty($scopeContract)) {
        $allowedEditPaths = (!empty($scopeContract['allowed_edit_paths']) && is_array($scopeContract['allowed_edit_paths']))
            ? $scopeContract['allowed_edit_paths']
            : array();

        if ($outputMode !== 'analysis_only' && !empty($allowedEditPaths)) {
            if ($targetPath === '' || !in_array($targetPath, $allowedEditPaths, true)) {
                return [
                    'verdict' => 'needs_revision',
                    'reason' => 'Steven produced an artifact outside the allowed edit scope. The scope contract limited edits to specific file paths, but this artifact targets a different path. In other words: even if the code itself looks plausible, it is aimed at the wrong file and cannot be approved.',
                    'revision' => 'Return a new artifact that targets only a file listed in ALLOWED_EDIT_PATHS.'
                ];
            }
        }
    }

	
// STRICT PATCH QUALITY GATE (notification_service + similar)
if (
    strpos($targetPath, 'notification_service.php') !== false &&
    $outputMode === 'surgical_patch'
) {
    $hasReplaceKeyword = stripos($artifactContent, 'Replace this block') !== false;
    $hasWithKeyword = stripos($artifactContent, 'With this block') !== false;

    if (!$hasReplaceKeyword || !$hasWithKeyword) {
        return [
            'verdict' => 'needs_revision',
            'reason' => 'Steven did not provide a concrete before/after patch. The request requires an exact replace block, not generic instructions. In other words: this cannot be applied directly and is therefore unsafe.',
            'revision' => 'Return a surgical_patch that includes both the exact existing block and the exact replacement block.'
        ];
    }
}	
	
	
        if (
        !empty($scopeContract) &&
        $outputMode !== 'analysis_only' &&
        scope_contract_requires_method_scoped_patch($scopeContract)
    ) {
        $expectedSymbols = (!empty($scopeContract['expected_symbols']) && is_array($scopeContract['expected_symbols']))
            ? $scopeContract['expected_symbols']
            : array();

        if (!context_files_contain_expected_symbol_bodies($contextFiles, $expectedSymbols)) {
            return [
                'verdict' => 'needs_revision',
                'reason' => 'Steven produced a concrete patch for a method-scoped request, but the expected method body is not actually visible in the loaded file context. In other words: this may look precise, but it is still an invented patch against an unseen implementation.',
                'revision' => 'Return analysis_only unless the loaded context includes the actual body of the expected method/symbol.'
            ];
        }

        if (!artifact_mentions_expected_symbols($artifactContent, $expectedSymbols)) {
            return [
                'verdict' => 'needs_revision',
                'reason' => 'Steven did not patch the expected local method/symbol area required by the scope contract. The requested change was method-scoped, but the artifact does not visibly reference the expected symbol(s), so this is likely the wrong in-file patch location. In other words: the patch may be in the right file, but not in the right method.',
                'revision' => 'Return a surgical_patch that explicitly patches the expected local method/symbol area only, and reference that method or symbol directly in the patch.'
            ];
        }
    }

    // STRICT SQL PATCH QUALITY GUARD (notification_service specific)
    if (
        $targetPath === 'src/notification_service.php' &&
        $outputMode !== 'analysis_only'
    ) {
        $content = trim($artifactContent);

        $hasUpdateKeyword = stripos($content, 'UPDATE training_progression_emails') !== false;
        $hasBeforeBlock =
            strpos($content, '@@') !== false
            || stripos($content, 'Replace this block') !== false
            || stripos($content, 'Replace the failure-status UPDATE') !== false;

        $hasPlaceholderLanguage =
            stripos($content, 'find the failure path') !== false
            || stripos($content, 'locate the failure') !== false
            || stripos($content, 'if that same failure update') !== false
            || (
                stripos($content, '/*') !== false
                && stripos($content, 'keep any existing') !== false
            );

        if (!$hasUpdateKeyword || !$hasBeforeBlock || $hasPlaceholderLanguage) {
            return [
                'verdict' => 'needs_revision',
                'reason' => 'Steven did not provide a concrete, directly applicable SQL patch for sendProgressionEmailById(). The artifact must include the exact existing UPDATE block and a precise replacement. In other words: this is still guidance, not a real patch.',
                'revision' => 'Return a surgical_patch that shows the exact existing UPDATE statement and replaces it with a fully concrete revised SQL block. No instructions, no placeholders.'
            ];
        }

        if (
            stripos($content, 'sent_at') !== false &&
            stripos($content, 'sent_at = sent_at') !== false
        ) {
            return [
                'verdict' => 'needs_revision',
                'reason' => 'Steven used a no-op sent_at assignment instead of removing the overwrite. This is not the cleanest or safest fix. In other words: the patch gestures at preserving sent_at, but it does not implement the clean minimal correction we want.',
                'revision' => 'Return a surgical_patch that removes sent_at from the failure UPDATE entirely instead of assigning sent_at = sent_at.'
            ];
        }
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
        '- Review only against the current USER REQUEST, current SCOPE CONTRACT, and currently loaded file contents. Do not import semantic assumptions from prior runs, prior revisions, or earlier artifacts unless they are explicitly included in the current prompt.',
        '- If a SCOPE CONTRACT is provided, review Steven against that contract strictly.',
        '',
        'Critical rejection rules:',
        '- Reject invented methods, invented schema, invented APIs, or invented architecture.',
        '- Reject changes that alter behavior without explicit permission.',
        '- Reject output that is not actually grounded in the loaded file context.',
        '- Reject output that claims a safe full drop-in when that is not justified.',
        '- Reject any output that redefines an existing class unless that was explicitly requested.',
        '- Reject any output that includes a full class definition for a file that already exists, unless a true full-file replacement was explicitly requested and is clearly safe.',
        '- Reject methods ONLY if they clearly do not match established project conventions or naming patterns.',
        '- If a method follows known engine naming patterns (e.g. finalizeAssessedProgressTest, sendProgressionEmailById), assume it exists unless there is evidence it does not.',
        '- Reject any output that introduces new methods while relying on unverified existing methods.',
        '- Reject any output that mixes placeholders, example usage blocks, or speculative scaffolding into what is presented as production-ready code.',
        '- If there is uncertainty, prefer needs_revision over analysis_only when the target file still contains an obvious local bug that can be repaired without inventing new architecture.',
        '- Use analysis_only only when the requested fix truly cannot be grounded from the target file, loaded files, and established project conventions.',
        '- Reject output that modifies files outside ALLOWED_EDIT_PATHS when a SCOPE CONTRACT is present.',
        '- Reject output that rewrites a class when NO_CLASS_REWRITE is yes.',
        '- For notification_service SQL fixes, reject any artifact that gives generic instructions, placeholder comments, or indirect guidance instead of a directly applicable before/after patch.',
        '',
        'Approval rules:',
        '- Approve code if it follows established project patterns, even if full method visibility is not present in the context.',
        '- If method names match known engine conventions (e.g. finalizeAssessedProgressTest, sendProgressionEmailById), treat them as valid unless proven otherwise.',
        '- Only reject when there is clear evidence of invented or unsafe behavior.',
        '- Only approve full_drop_in if it safely replaces a known file without structural risk.',
        '- Only approve surgical_patch if the insertion point is clear and the patch is realistically applicable.',
        '- If unsure, do not approve.',
        '',
        'Formatting rules:',
        '- Do not use markdown headings like #, ##, or ###.',
        '- Do not number sections like "### 1."',
        '- Use plain section titles only when needed, for example: "Summary", "What this means", "My suggestion".',
        '- Use simple bullet lists when structure helps.',
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

    if (!empty($scopeContract)) {
        $user .= render_scope_contract_text($scopeContract) . "\n\n";
    }

    if ($ssot) {
        $user .= "SSOT SNAPSHOT:\n";
        $user .= "Version: " . (string)($ssot['ssot_version'] ?? '') . "\n";
        $user .= "Title: " . (string)($ssot['title'] ?? '') . "\n";
        $user .= "Summary: " . (string)($ssot['summary_text'] ?? '') . "\n\n";
    }

    if (!empty($contextFiles)) {
        $user .= "TARGETED FILE CONTENTS:\n";

        foreach ($contextFiles as $f) {
            if (!empty($f['error'])) {
                continue;
            }

            $user .= "FILE: " . $f['path'] . "\n";
            $user .= (string)$f['content'] . "\n\n";
        }
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

function build_steven_artifact_content(array $requestRow, array $contextFiles, array $scopeContract = array()): array
{
    $title = trim((string)($requestRow['request_title'] ?? 'Untitled request'));
    $prompt = trim((string)($requestRow['prompt'] ?? ''));

    $targetData = build_targeted_context($GLOBALS['pdo'], $prompt);
    $targetedSummary = $targetData['summary'];
    $targetFiles = isset($targetData['files']) && is_array($targetData['files']) ? $targetData['files'] : array();
    $primaryTargetFile = isset($targetData['primary_file']) ? (string)$targetData['primary_file'] : '';

    $targetPath = pick_primary_target_path($GLOBALS['pdo'], $prompt, $contextFiles);
    if ($targetPath === null) {
        $targetPath = '';
    }

    if ($targetPath === '') {
        foreach ($contextFiles as $f) {
            if (empty($f['error']) && !empty($f['path'])) {
                $targetPath = (string)$f['path'];
                break;
            }
        }
    }

    $targetedMaxChars = 20000;

            $methodNames = extract_method_like_tokens_from_text($prompt);

    if ($targetPath !== '') {
        $targetedFilesContent = read_files_for_targeted_context(array($targetPath), $methodNames, 1, 24000, true, $prompt);
    } elseif ($primaryTargetFile !== '' && count($targetFiles) >= 1) {
        $targetedFilesContent = read_files_for_targeted_context(array($primaryTargetFile), $methodNames, 1, 24000, true, $prompt);
    } else {
        $targetedFilesContent = read_files_for_targeted_context($targetFiles, $methodNames, 2, $targetedMaxChars, false, $prompt);
    }

      // Targeted DB + project context
    $dbSchema = load_targeted_schema($GLOBALS['pdo'], $prompt);
    $projectIndex = load_targeted_project_index(
        project_root_path(),
        array_values(array_unique(array_filter(array_merge(
            $targetPath !== '' ? [$targetPath] : [],
            $targetFiles
        )))),
        $prompt
    );

    $systemPrompt = implode("\n", [
        'You are Steven, a hidden senior PHP/MySQL implementation agent inside the IPCA engineering console.',
        'You write implementation-ready engineering output.',
        'You do not make architecture decisions independently.',
        'You must preserve existing behavior unless explicitly changed.',
        'You must not invent nonexistent schema, APIs, helper functions, or engine methods unless the user explicitly requests new structure.',
        '',
        'Context usage rules:',
        '- If CONTEXT FILES are provided, treat them as directly readable code, not as hints.',
        '- If DATABASE SCHEMA is provided, treat it as authoritative and extract real table and column names from it.',
        '- If PROJECT FILE INDEX is provided, treat it as the available live project structure for this run.',
        '- Do NOT say you cannot access files, schema, or project structure when they are present in the prompt.',
        '- When asked to list methods, tables, files, or structures, extract them explicitly from the provided context.',
        '- If the required information is NOT present in the provided context, return analysis_only and clearly state what is missing.',
	    '- If PRIMARY TARGET FILE CONTENTS are provided, treat them as the authoritative implementation source for the requested fix.',
        '',
        'Context priority rules:',
        '- When a question explicitly references DATABASE SCHEMA, ONLY use DATABASE SCHEMA to answer.',
        '- When a question explicitly references CONTEXT FILES, ONLY use the provided file contents to answer.',
        '- When a question explicitly references PROJECT FILE INDEX, ONLY use the file index list to answer.',
        '- Do NOT mix sources unless the request explicitly requires combining them.',
        '- Do NOT reuse a previous answer from another context source when the current request targets a different source.',
        '- Each answer must be grounded in the correct requested source.',
        '',
        'Implementation safety rules:',
		'- Before using any method or function, verify it exists in the provided file context.',
		'- If unsure whether a method exists, do NOT assume; instead return analysis_only.',
		'- Prefer using clearly visible existing methods from the loaded file.',
		'- If functionality is missing, explicitly state which method, file, schema element, or dependency is missing instead of guessing.',
		'- When the requested fix names a specific target file and that file content is provided, prefer patching that file directly instead of refusing due to broader uncertainty.',
		'- If a SCOPE CONTRACT is provided, obey it strictly.',
		'- Only modify files listed in ALLOWED_EDIT_PATHS.',
		'- If PRIMARY_TARGET_PATH is provided, treat it as the main file to patch.',
		'- If NO_CLASS_REWRITE is yes, do not output a full class definition or class rewrite.',
		'- If EXPECTED_LOCAL_CHANGE_ONLY is yes, prefer the smallest viable local patch.',
		'- For surgical_patch output, prefer exact before/after replacement blocks whenever the requested change is method-scoped or SQL-block-scoped.',
		'- Do not give vague instructions like "find this block", "locate the failure path", or "change the UPDATE".',
		'- Do not use placeholder comments inside a patch.',
		'- If you cannot quote the existing block from visible context, return analysis_only instead of inventing it.',
		'- A surgical_patch should be directly copy-paste applicable by the user.',
        '',
        'Output rules:',
        '- When a safe full replacement is possible, provide a full drop-in.',
        '- When a full replacement is unsafe, provide a surgical patch.',
        '- When neither is safe, provide analysis_only.',
        '- Return plain text in exactly this format:',
        '- When OUTPUT_MODE is full_drop_in, DO NOT include any diff markers like "-", "+", or "@@". Return clean copy-paste ready code only.',
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

	    if (!empty($scopeContract)) {
        $userPrompt .= render_scope_contract_text($scopeContract) . "\n\n";
    }
	
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

    if (!empty($dbSchema)) {
        $userPrompt .= "TARGETED DATABASE SCHEMA:\n";
        $userPrompt .= json_encode($dbSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $userPrompt .= "\n\n";
    }

    if (!empty($projectIndex)) {
        $userPrompt .= "TARGETED PROJECT FILE INDEX:\n";
        $userPrompt .= implode("\n", $projectIndex);
        $userPrompt .= "\n\n";
    }

        if ($targetPath !== '') {
        $userPrompt .= "PRIMARY TARGET FILE:\n";
        $userPrompt .= $targetPath . "\n\n";
    }

    if ($targetedSummary !== '') {
        $userPrompt .= "TARGETED FILE CONTEXT:\n";
        $userPrompt .= $targetedSummary . "\n\n";
    }

    if ($targetedFilesContent) {
        $userPrompt .= "PRIMARY TARGET FILE CONTENTS (AUTHORITATIVE):\n";

        foreach ($targetedFilesContent as $f) {
            if (!empty($f['error'])) {
                continue;
            }

            $userPrompt .= "FILE: " . $f['path'] . "\n";
            $userPrompt .= $f['content'] . "\n\n";
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
        trim((string)($artifact['output_mode'] ?? '')) !== '' ? (string)$artifact['output_mode'] : 'analysis_only',
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

function load_artifact_row(PDO $pdo, int $artifactId): ?array
{
    if ($artifactId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM ai_jake_artifacts
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$artifactId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function message_explicitly_names_different_target(PDO $pdo, string $messageText, ?string $currentTargetPath): bool
{
    $currentTargetPath = trim((string)$currentTargetPath);
    if ($currentTargetPath === '') {
        return false;
    }

    $explicitPaths = resolve_explicit_file_candidates($pdo, $messageText);
    if (empty($explicitPaths)) {
        return false;
    }

    foreach ($explicitPaths as $path) {
        $path = trim((string)$path);
        if ($path === '') {
            continue;
        }

        if ($path !== $currentTargetPath) {
            return true;
        }
    }

    return false;
}

function extract_explicit_full_target_path(PDO $pdo, string $messageText): string
{
    $resolvedPaths = resolve_explicit_file_candidates($pdo, $messageText);
    if (empty($resolvedPaths)) {
        return '';
    }

    foreach ($resolvedPaths as $path) {
        $path = trim((string)$path);
        if ($path === '') {
            continue;
        }

        if (path_looks_like_full_relative_path($path)) {
            return $path;
        }
    }

    return (string)$resolvedPaths[0];
}


function inject_forced_target_into_prompt(string $prompt, string $targetPath): string
{
    $prompt = trim($prompt);
    $targetPath = trim($targetPath);

    if ($targetPath === '') {
        return $prompt;
    }

    return "FORCED_TARGET_PATH:\n" . $targetPath . "\n\n" . $prompt;
}


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

function extract_active_target_files_from_message(PDO $pdo, string $messageText): array
{
    return resolve_explicit_file_candidates($pdo, $messageText);
}

function build_active_request_summary(string $messageText, ?string $requestType = null): string
{
    $messageText = trim((string)preg_replace('/\s+/', ' ', $messageText));
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

function message_has_explicit_target_or_direct_fix_intent(PDO $pdo, string $messageText): bool
{
    $messageText = trim($messageText);
    if ($messageText === '') {
        return false;
    }

    $resolvedPaths = resolve_explicit_file_candidates($pdo, $messageText);
    if (!empty($resolvedPaths)) {
        return true;
    }

    $lower = strtolower($messageText);

    $signals = array(
        'fix',
        'patch',
        'repair',
        'update',
        'change',
        'modify',
        'implement',
        'create',
        'build'
    );

    foreach ($signals as $signal) {
        if (strpos($lower, $signal) !== false) {
            return true;
        }
    }

    return false;
}


function is_continuation_trigger(string $messageText): bool
{
    $text = strtolower(trim($messageText));

    if ($text === '') {
        return false;
    }

    $normalized = str_replace(array("’", "‘", "â€™", "â€˜", "`"), "'", $text);
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

function is_revision_trigger(string $messageText): bool
{
    $text = strtolower(trim($messageText));

    if ($text === '') {
        return false;
    }

    $normalized = str_replace(array("’", "‘", "â€™", "â€˜", "`"), "'", $text);
    $normalized = preg_replace('/[^a-z0-9\'\s]/', ' ', $normalized);
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    $normalized = trim((string)$normalized);

    if ($normalized === '') {
        return false;
    }

    $patterns = array(
        '/\brefine\s+(this|it)\b/',
        '/\bimprove\s+(this|it)\b/',
        '/\brevise\s+(this|it)\b/',
        '/\btry\s+again\b/',
        '/\bpush\s+(it|this)\s+to\s+approved\b/',
        '/\bgenerate\s+a\s+revised\s+version\b/',
        '/\bmake\s+this\s+safer\b/',
        '/\bfix\s+this\b/',
        '/\brework\s+(this|it)\b/'
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
    $message = trim((string)preg_replace('/\s+/', ' ', $message));
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
    $targetData = build_targeted_context($pdo, $message);
    $targetedSummary = $targetData['summary'];
    $targetFiles = $targetData['files'];
    $primaryTargetFile = isset($targetData['primary_file']) ? (string)$targetData['primary_file'] : '';

    $targetedMaxChars = 20000;
    $methodNames = extract_method_like_tokens_from_text($message);

    if ($primaryTargetFile !== '' && count($targetFiles) === 1) {
        $targetedFilesContent = read_files_for_targeted_context(array($primaryTargetFile), $methodNames, 1, 24000, true, $message);
    } else {
        $targetedFilesContent = read_files_for_targeted_context($targetFiles, $methodNames, 2, $targetedMaxChars, false, $message);
    }

    $ssot = load_latest_ssot_snapshot($pdo);
    $fileCandidates = resolve_explicit_file_candidates($pdo, $message);
    $contextFiles = read_files_for_targeted_context($fileCandidates, array(), 3, 24000, true, $message);
    $dbSchema = load_targeted_schema($pdo, $message);
    $projectIndex = load_targeted_project_index(
        project_root_path(),
        array_values(array_unique(array_filter(array_merge($fileCandidates, $targetFiles)))),
        $message
    );

$systemPrompt = implode("\n", [
    'You are Jake, the IPCA architect and SSOT guardian.',
    '',
    'You are NOT a generic assistant.',
    'You are an execution-focused orchestrator working with Steven (implementation agent).',
    '',
    'CORE BEHAVIOR',
    '- You guide implementation, not just explain.',
    '- You prepare clean, actionable steps for Steven.',
    '- You do NOT re-analyze systems that are already defined.',
    '- You respect the existing architecture and SSOT strictly.',
    '',
    'SYSTEM REALITY',
    '- Files may be truncated.',
    '- Methods may be partially visible.',
    '- Context may be incomplete.',
    '- You must work within this constraint WITHOUT guessing.',
    '',
    'CRITICAL RULES',
    '- DO NOT invent methods, schema, or architecture.',
    '- DO NOT suggest rewrites of core systems unless explicitly asked.',
    '- DO NOT duplicate business logic from the progression engine.',
    '',
    'EXECUTION MINDSET',
    '- Always move the system forward.',
    '- Prefer safe progress over theoretical perfection.',
    '- If a safe step can be taken → take it.',
    '- If blocked → clearly state what is missing.',
    '',
    'COOPERATION MODEL',
    '- You define WHAT should be built.',
    '- Steven defines HOW it is implemented.',
    '- You validate that Steven stayed within scope.',
    '',
    'WHEN USER IS BUILDING SYSTEMS',
    '- Break work into ordered steps.',
    '- Keep steps small and deterministic.',
    '- Avoid mixing multiple concerns in one step.',
    '',
    'CONTEXT USAGE RULES',
    '- If LOADED FILES are present → treat as source of truth.',
    '- If DATABASE SCHEMA is present → treat as authoritative.',
    '- If context is incomplete → explicitly say so.',
    '',
    'INTERACTION STYLE',
    '- Clear, calm, senior engineer tone',
    '- No robotic phrasing',
    '- No over-warning',
    '- Short structured responses',
    '',
    'STRUCTURE OUTPUT LIKE THIS:',
    '**Summary**',
    '',
    '**What this means**',
    '',
    '**In other words**',
    '',
    '**My suggestion**',
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
        $userPrompt .= "LOADED FILE CONTENTS:\n";

        foreach ($contextFiles as $f) {
            if (!empty($f['error'])) {
                $userPrompt .= "FILE: " . $f['path'] . "\n";
                $userPrompt .= "[READ FAILED: " . $f['error'] . "]\n\n";
                continue;
            }

            $userPrompt .= "FILE: " . $f['path'] . "\n";
            $userPrompt .= (string)$f['content'] . "\n\n";
        }
    }

    if (!empty($dbSchema)) {
        $userPrompt .= "TARGETED DATABASE SCHEMA:\n";
        $userPrompt .= json_encode($dbSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $userPrompt .= "\n\n";
    }

    if (!empty($projectIndex)) {
        $userPrompt .= "TARGETED PROJECT FILE INDEX:\n";
        $userPrompt .= implode("\n", $projectIndex);
        $userPrompt .= "\n\n";
    }

    if ($targetedSummary !== '') {
        $userPrompt .= "TARGETED FILE CONTEXT:\n";
        $userPrompt .= $targetedSummary . "\n\n";
    }

    if (!empty($targetedFilesContent)) {
        $userPrompt .= "TARGETED FILE CONTENTS:\n";

        foreach ($targetedFilesContent as $f) {
            if (!empty($f['error'])) {
                continue;
            }

            $userPrompt .= "FILE: " . $f['path'] . "\n";
            $userPrompt .= $f['content'] . "\n\n";
        }
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

function should_include_core_engine_file(string $path, string $prompt, array $targetFiles, ?string $primaryFile): bool
{
    $path = trim($path);
    if ($path === '') {
        return false;
    }

    if ($primaryFile !== null && $primaryFile === $path) {
        return true;
    }

    if (in_array($path, $targetFiles, true)) {
        return true;
    }

    $explicitPaths = extract_file_candidates_from_text($prompt);
    if (in_array($path, $explicitPaths, true)) {
        return true;
    }

    $promptLower = strtolower($prompt);

    if ($path === 'src/courseware_progression_v2.php') {
        $signals = [
            'progression',
            'progress test',
            'progress_test',
            'lesson activity',
            'lesson_activity',
            'student_required_actions',
            'deadline',
            'remediation',
            'summary review',
            'instructor approval'
        ];

        foreach ($signals as $signal) {
            if (strpos($promptLower, $signal) !== false) {
                return true;
            }
        }
    }

    if ($path === 'src/notification_service.php') {
        $signals = [
            'notification',
            'email',
            'template',
            'training_progression_emails',
            'send',
            'queue',
            'postmark'
        ];

        foreach ($signals as $signal) {
            if (strpos($promptLower, $signal) !== false) {
                return true;
            }
        }
    }

    return false;
}

function build_engineering_context_files(PDO $pdo, string $prompt): array
{
    $paths = [];

    $primaryTargetPath = pick_primary_target_path($pdo, $prompt);

    if ($primaryTargetPath !== null && $primaryTargetPath !== '') {
        $paths[] = $primaryTargetPath;
    }

    $targetData = build_targeted_context($pdo, $prompt);
    $files = isset($targetData['files']) && is_array($targetData['files']) ? $targetData['files'] : [];

    foreach ($files as $f) {
        if (is_string($f) && $f !== '') {
            $paths[] = $f;
        }
    }

    if (should_include_core_engine_file('src/courseware_progression_v2.php', $prompt, $files, $primaryTargetPath)) {
        $paths[] = 'src/courseware_progression_v2.php';
    }

    if (should_include_core_engine_file('src/notification_service.php', $prompt, $files, $primaryTargetPath)) {
        $paths[] = 'src/notification_service.php';
    }

    $paths = array_values(array_unique(array_filter($paths)));

    $methodNames = extract_method_like_tokens_from_text($prompt);
    return read_files_for_targeted_context($paths, $methodNames, 3, 24000, true, $prompt);
}

function resolve_effective_output_mode(?array $artifact, string $fallback = 'analysis_only'): string
{
    if (is_array($artifact)) {
        $candidate = trim((string)($artifact['output_mode'] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }
    }

    $fallback = trim($fallback);
    return $fallback !== '' ? $fallback : 'analysis_only';
}


function artifact_or_analysis_requests_more_context(array $artifactSeed): bool
{
    $outputMode = trim((string)($artifactSeed['output_mode'] ?? 'analysis_only'));
    $content = trim((string)($artifactSeed['content'] ?? ''));

    if ($content === '') {
        return false;
    }

    if ($outputMode !== 'analysis_only') {
        return false;
    }

    $signals = array(
        'missing required file',
        'missing file',
        'missing method',
        'missing dependency',
        'not visible in the loaded context',
        'not present in the provided context',
        'actual file contents',
        'please provide the file contents',
        'the provided context is not sufficient',
        'the authoritative target file content provided is truncated',
        'the expected method body is not actually visible',
        'cannot safely produce',
        'cannot safely patch',
        'cannot safely output',
        'full current contents of',
        'what is missing',
        'required to produce',
        'required to proceed safely',
    );

    $lower = strtolower($content);

    foreach ($signals as $signal) {
        if (strpos($lower, $signal) !== false) {
            return true;
        }
    }

    return false;
}

function extract_missing_dependencies_from_analysis(string $text): array
{
    $text = trim($text);

    $out = array(
        'files' => array(),
        'symbols' => array(),
        'tables' => array(),
    );

    if ($text === '') {
        return $out;
    }

    if (preg_match_all('#([A-Za-z0-9_\-\/]+\.(php|js|css|sql|json|md|txt))#i', $text, $m)) {
        foreach ($m[1] as $path) {
            $path = trim((string)$path);
            if ($path !== '') {
                $out['files'][] = ltrim(str_replace('\\', '/', $path), '/');
            }
        }
    }

    if (preg_match_all('/\b([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $text, $m)) {
        foreach ($m[1] as $symbol) {
            $symbol = trim((string)$symbol);
            if ($symbol !== '' && strlen($symbol) >= 6) {
                $out['symbols'][] = $symbol;
            }
        }
    }

    if (preg_match_all('/\b([a-z][a-z0-9_]{3,})\b/', strtolower($text), $m)) {
        foreach ($m[1] as $token) {
            if (strpos($token, '_') !== false) {
                $out['tables'][] = $token;
            }
        }
    }

    $out['files'] = array_values(array_unique($out['files']));
    $out['symbols'] = array_values(array_unique($out['symbols']));
    $out['tables'] = array_values(array_unique($out['tables']));

    return $out;
}

function resolve_dependency_candidates(PDO $pdo, array $dependencyHints, string $prompt = ''): array
{
    $paths = array();

    if (!empty($dependencyHints['files']) && is_array($dependencyHints['files'])) {
        foreach ($dependencyHints['files'] as $path) {
            $path = trim((string)$path);
            if ($path !== '') {
                $paths[] = $path;
            }
        }
    }

    $symbols = (!empty($dependencyHints['symbols']) && is_array($dependencyHints['symbols']))
        ? $dependencyHints['symbols']
        : array();

    $root = project_root_path();
    $allFiles = load_project_file_index($root);

    if (!empty($symbols)) {
        foreach ($allFiles as $path) {
            try {
                $file = safe_project_file_read((string)$path);
                $content = (string)$file['content'];

                foreach ($symbols as $symbol) {
                    $symbol = trim((string)$symbol);
                    if ($symbol === '') {
                        continue;
                    }

                    if (
                        preg_match('/public\s+function\s+' . preg_quote($symbol, '/') . '\s*\(/i', $content) ||
                        preg_match('/protected\s+function\s+' . preg_quote($symbol, '/') . '\s*\(/i', $content) ||
                        preg_match('/private\s+function\s+' . preg_quote($symbol, '/') . '\s*\(/i', $content) ||
                        preg_match('/function\s+' . preg_quote($symbol, '/') . '\s*\(/i', $content) ||
                        preg_match('/class\s+' . preg_quote($symbol, '/') . '\b/i', $content)
                    ) {
                        $paths[] = (string)$path;
                        break;
                    }
                }
            } catch (Throwable $e) {
                continue;
            }
        }
    }

    if ($prompt !== '') {
        $targetData = build_targeted_context($pdo, $prompt);
        if (!empty($targetData['files']) && is_array($targetData['files'])) {
            foreach ($targetData['files'] as $path) {
                $path = trim((string)$path);
                if ($path !== '') {
                    $paths[] = $path;
                }
            }
        }
    }

    $paths = array_values(array_unique(array_filter($paths)));

    return $paths;
}

function merge_context_files(array $existingContextFiles, array $extraContextFiles, int $maxFiles = 8): array
{
    $merged = array();
    $seen = array();

    foreach (array_merge($existingContextFiles, $extraContextFiles) as $f) {
        $path = !empty($f['path']) ? (string)$f['path'] : '';
        $key = $path !== '' ? $path : md5(json_encode($f));

        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $merged[] = $f;

        if (count($merged) >= $maxFiles) {
            break;
        }
    }

    return $merged;
}

function expand_engineering_context_files(
    PDO $pdo,
    string $prompt,
    array $existingContextFiles,
    array $artifactSeed,
    int $maxExtraFiles = 3
): array {
    $analysisText = trim((string)($artifactSeed['content'] ?? ''));
    if ($analysisText === '') {
        return $existingContextFiles;
    }

    $dependencyHints = extract_missing_dependencies_from_analysis($analysisText);
    $candidatePaths = resolve_dependency_candidates($pdo, $dependencyHints, $prompt);

    $alreadyLoaded = array();
    foreach ($existingContextFiles as $f) {
        if (!empty($f['path'])) {
            $alreadyLoaded[] = (string)$f['path'];
        }
    }

    $newPaths = array();
    foreach ($candidatePaths as $path) {
        if (!in_array($path, $alreadyLoaded, true)) {
            $newPaths[] = $path;
        }
    }

    if (empty($newPaths)) {
        return $existingContextFiles;
    }

    $methodNames = extract_method_like_tokens_from_text($prompt, 10);
    if (!empty($dependencyHints['symbols'])) {
        $methodNames = array_values(array_unique(array_merge($methodNames, $dependencyHints['symbols'])));
    }

    $extraContextFiles = read_files_for_targeted_context($newPaths, $methodNames, $maxExtraFiles, 24000, true, $prompt);

    return merge_context_files($existingContextFiles, $extraContextFiles, 8);
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
$contextFiles = build_engineering_context_files($pdo, (string)$requestRow['prompt']);
$scopeContract = build_scope_contract($pdo, $requestRow, $contextFiles);

$runId = create_jake_run(
    $pdo,
    $requestId,
    $userId,
    'jake_think',
    'running'
);

$jakeSummary = build_jake_summary($requestRow, $ssot, $contextFiles);
$stevenBrief = build_steven_brief($requestRow, $ssot, $contextFiles, $scopeContract);
$riskNotes = 'Steven output generated via OpenAI. Manual review still required before any code is used.';
$outputMode = 'analysis_only';

    try {
        $maxRounds = 2;
        $currentRound = 1;
        $finalArtifactId = null;
        $finalArtifact = null;
        $finalReviewStatus = 'analysis_only';
        $finalReviewSummary = '';

                while ($currentRound <= $maxRounds) {
            $artifactSeed = build_steven_artifact_content($requestRow, $contextFiles, $scopeContract);
            $outputMode = (string)($artifactSeed['output_mode'] ?? 'full_drop_in');

            if (
                artifact_or_analysis_requests_more_context($artifactSeed)
                && $currentRound < $maxRounds
            ) {
                $expandedContextFiles = expand_engineering_context_files(
                    $pdo,
                    (string)$requestRow['prompt'],
                    $contextFiles,
                    $artifactSeed,
                    3
                );

                $contextActuallyExpanded = count($expandedContextFiles) > count($contextFiles);

                if ($contextActuallyExpanded) {
                    $contextFiles = $expandedContextFiles;
                    $scopeContract = build_scope_contract($pdo, $requestRow, $contextFiles);
                    $stevenBrief = build_steven_brief($requestRow, $ssot, $contextFiles, $scopeContract);
                    $currentRound++;
                    continue;
                }
            }

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
                $artifactSeed,
                $scopeContract
            );

            $reviewStatus = (string)($review['verdict'] ?? 'analysis_only');
            $reviewSummary = (string)($review['reason'] ?? '');

					if (
			resolve_effective_output_mode($artifactSeed, $outputMode) === 'analysis_only'
			&& $reviewStatus === 'approved'
		) {
			$reviewStatus = 'analysis_only';
				}
			
			
            $isFinal = ($reviewStatus === 'approved') ? 1 : 0;

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
            $contextFiles = build_engineering_context_files($pdo, (string)$requestRow['prompt']);
			$scopeContract = build_scope_contract($pdo, $requestRow, $contextFiles);
			$stevenBrief = build_steven_brief($requestRow, $ssot, $contextFiles, $scopeContract);
			$currentRound++;
        }

        update_jake_run($pdo, $runId, [
            'status' => 'completed',
            'jake_summary' => $jakeSummary,
            'steven_brief' => $stevenBrief,
            'risk_notes' => $riskNotes . ' Final review status: ' . $finalReviewStatus,
            'output_mode' => resolve_effective_output_mode($finalArtifact, $outputMode),
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

    $replyLines[] = '**Summary**';
    $replyLines[] = 'I took your request, generated a solution, and reviewed it against the system.';
    $replyLines[] = '';

    $replyLines[] = '**Result**';
    $replyLines[] = '- Request ID: ' . $requestId;
    $replyLines[] = '- Run ID: ' . $runId;
    $replyLines[] = '- Artifact ID: ' . $finalArtifactId;
    $replyLines[] = '- Output Mode: ' . resolve_effective_output_mode($finalArtifact, $outputMode);
    $replyLines[] = '- Review Status: ' . $finalReviewStatus;
    $replyLines[] = '- Target Path: ' . (($finalArtifact !== null && $finalArtifact['target_path'] !== null) ? $finalArtifact['target_path'] : '[not determined]');
    $replyLines[] = '';

    $replyLines[] = '**What this means**';
    $replyLines[] = $finalReviewSummary !== '' ? $finalReviewSummary : 'No detailed review feedback was generated.';
    $replyLines[] = '';

    $replyLines[] = '**In other words**';

    if ($finalReviewStatus === 'approved') {
        $replyLines[] = 'This is solid and safe. You can use the artifact directly.';
    } elseif ($finalReviewStatus === 'needs_revision') {
        $replyLines[] = 'The direction is good, but there are issues that make it unsafe to use as-is.';
    } else {
        $replyLines[] = 'This cannot be safely implemented yet without adjusting the approach.';
    }

    $replyLines[] = '';

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
        'output_mode' => resolve_effective_output_mode($finalArtifact, $outputMode),
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
            $activeArtifactId = isset($conversation['active_artifact_id']) ? (int)$conversation['active_artifact_id'] : null;

            $reply = '';
            $linkedRunId = null;
            $result = null;

			            $activeArtifact = null;
            $inheritedTargetPath = '';

            if ($activeArtifactId !== null && $activeArtifactId > 0) {
                $activeArtifact = load_artifact_row($pdo, $activeArtifactId);

                if ($activeArtifact && !empty($activeArtifact['target_path'])) {
                    $inheritedTargetPath = trim((string)$activeArtifact['target_path']);
                }
            }
			
            $lower = strtolower($messageText);

            $explicitImplementation =
                strpos($lower, 'create') !== false ||
                strpos($lower, 'build') !== false ||
                strpos($lower, 'generate') !== false ||
                strpos($lower, 'make') !== false ||
                strpos($lower, 'add') !== false ||
                strpos($lower, 'implement') !== false ||
                strpos($lower, 'fix') !== false ||
                strpos($lower, 'patch') !== false ||
                strpos($lower, 'repair') !== false;

            $shouldRunEngineering = (
                $explicitImplementation ||
                (
                    $activeRequestSummary !== '' &&
                    (
                        is_continuation_trigger($messageText)
                        || is_revision_trigger($messageText)
                        || $activeArtifactId !== null
                        || (
                            (string)$activeMode === 'analysis' &&
                            mb_strlen($messageText) < 40
                        )
                    )
                )
            );

            if ($shouldRunEngineering) {
                $engineeringPromptParts = array();

                                $isRevision = is_revision_trigger($messageText);
                $useFreshPromptIsolation = message_has_explicit_target_or_direct_fix_intent($pdo, $messageText);

                $explicitCurrentTargetPath = extract_explicit_full_target_path($pdo, $messageText);

                $forcedTargetPath = '';
                if ($explicitCurrentTargetPath !== '') {
                    $forcedTargetPath = $explicitCurrentTargetPath;
                } elseif (
                    $isRevision &&
                    $inheritedTargetPath !== '' &&
                    !message_explicitly_names_different_target($pdo, $messageText, $inheritedTargetPath)
                ) {
                    $forcedTargetPath = $inheritedTargetPath;
                }

                if ($useFreshPromptIsolation) {
                    $basePrompt = $messageText;

                                        if ($forcedTargetPath !== '') {
                        $basePrompt = inject_forced_target_into_prompt($basePrompt, $forcedTargetPath);
                    }

                    $engineeringPromptParts[] = $basePrompt;
                } else {
                    if ($activeRequestSummary !== '') {
                        $engineeringPromptParts[] = $activeRequestSummary;
                    }

                    if ($activeTargetFiles !== '') {
                        $engineeringPromptParts[] = "Relevant files:\n" . $activeTargetFiles;
                    }

                    if ($activeNextStep !== '') {
                        $engineeringPromptParts[] = "Requested next step:\n" . $activeNextStep;
                    }

                    $followUpPrompt = "USER FOLLOW-UP:\n" . $messageText;

                                        if ($forcedTargetPath !== '') {
                        $followUpPrompt = inject_forced_target_into_prompt($followUpPrompt, $forcedTargetPath);
                    }

                    $engineeringPromptParts[] = $followUpPrompt;
                }

                if ($activeArtifactId !== null && $isRevision) {
                    $stmt = $pdo->prepare("
                        SELECT content, review_summary
                        FROM ai_jake_artifacts
                        WHERE id = ?
                        LIMIT 1
                    ");
                    $stmt->execute([$activeArtifactId]);
                    $artifactRow = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($artifactRow) {
                        $engineeringPromptParts[] = "PREVIOUS ARTIFACT:\n" . $artifactRow['content'];
                        $engineeringPromptParts[] = "PREVIOUS REVIEW:\n" . $artifactRow['review_summary'];
                    }
                }

                $engineeringPrompt = implode("\n\n", $engineeringPromptParts); 

                $result = run_jake_engineering_cycle(
                    $pdo,
                    (int)$u['id'],
                    $engineeringPrompt,
                    $requestType !== '' ? $requestType : 'investigation'
                );

                $reply = (string)$result['reply'];
                $linkedRunId = (int)$result['run_id'];

                $newActiveTargetFiles = $activeTargetFiles;

				if (!empty($result['target_path'])) {
					$newActiveTargetFiles = (string)$result['target_path'];
				}

				update_conversation_state(
					$pdo,
					$conversationId,
					'implementation',
					$activeRequestSummary,
					$newActiveTargetFiles,
					'Refinement in progress',
					$result['run_id'],
					$result['artifact_id']
				);
            } else {
                $reply = jake_chat_reply($pdo, [
                    'message_text' => $messageText
                ], $requestType !== '' ? $requestType : null);

                $targetFiles = extract_active_target_files_from_message($pdo, $messageText);
                $targetFilesText = $targetFiles ? implode("\n", $targetFiles) : '';

                update_conversation_state(
                    $pdo,
                    $conversationId,
                    'analysis',
                    build_active_request_summary($messageText, $requestType !== '' ? $requestType : null),
                    $targetFilesText !== '' ? $targetFilesText : null,
                    'Awaiting approval',
                    null,
                    null
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
                'reply' => $reply,
                'artifact_id' => isset($result['artifact_id']) ? (int)$result['artifact_id'] : null
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
            $contextFiles = build_engineering_context_files($pdo, (string)($requestRow['prompt'] ?? ''));

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

        case 'list_recent_artifacts':
            $stmt = $pdo->query("
                SELECT
                    id,
                    request_id,
                    run_id,
                    title,
                    target_path,
                    output_mode,
                    review_status,
                    created_at,
                    updated_at
                FROM ai_jake_artifacts
                ORDER BY id DESC
                LIMIT 50
            ");

            $artifacts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();

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

            if ($requestId > 0) {
                $requestRow = load_request_row($pdo, $requestId);
                if (!$requestRow) {
                    json_out(['ok' => false, 'error' => 'Request not found']);
                }

                $result = run_jake_engineering_cycle(
                    $pdo,
                    (int)$u['id'],
                    (string)$requestRow['prompt'],
                    (string)($requestRow['request_type'] ?? 'investigation')
                );
            } else {
                if ($prompt === '') {
                    json_out(['ok' => false, 'error' => 'Missing request_id or prompt']);
                }

                if ($title !== '') {
                    $prompt = $title . "\n\n" . $prompt;
                }

                $result = run_jake_engineering_cycle(
                    $pdo,
                    (int)$u['id'],
                    $prompt,
                    $type !== '' ? $type : 'investigation'
                );
            }

            json_out([
                'ok' => true,
                'request_id' => (int)$result['request_id'],
                'run_id' => (int)$result['run_id'],
                'artifact_id' => (int)$result['artifact_id'],
                'output_mode' => (string)$result['output_mode'],
                'target_path' => $result['target_path'],
                'response' => (string)$result['reply']
            ]);

        default:
            json_out(['ok' => false, 'error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    error_log('ai_jake_console_action.php ERROR: ' . $e->getMessage());
    error_log($e->getTraceAsString());

    http_response_code(400);
    json_out([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}