<?php
declare(strict_types=1);

/**
 * Production must apply SQL migrations explicitly:
 *   scripts/sql/2026_05_28_mock_oral_schema.sql
 *   scripts/sql/2026_05_28_mock_oral_acs_seed.sql
 *   scripts/sql/2026_05_28_mock_oral_ai_prompts.sql
 *
 * Optional dev bootstrap: set MO_ALLOW_SCHEMA_BOOTSTRAP=1
 */
function mo_ensure_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (trim((string)getenv('MO_ALLOW_SCHEMA_BOOTSTRAP')) !== '1') {
        return;
    }

    $migrations = [
        dirname(__DIR__, 2) . '/scripts/sql/2026_05_28_mock_oral_schema.sql',
        dirname(__DIR__, 2) . '/scripts/sql/2026_05_28_mock_oral_acs_seed.sql',
        dirname(__DIR__, 2) . '/scripts/sql/2026_05_28_mock_oral_ai_prompts.sql',
    ];

    foreach ($migrations as $migration) {
        if (!is_readable($migration)) {
            continue;
        }
        $sql = (string)file_get_contents($migration);
        foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql) ?: [])) as $stmt) {
            if ($stmt === '' || stripos($stmt, 'SET @') === 0) {
                continue;
            }
            try {
                $pdo->exec($stmt);
            } catch (Throwable $e) {
                // idempotent dev bootstrap
            }
        }
    }
}

function mo_default_catalog_id(PDO $pdo): int
{
    mo_ensure_tables($pdo);
    $st = $pdo->query("SELECT id FROM mock_oral_acs_catalogs WHERE catalog_key = 'acs_private_pilot' AND is_active = 1 LIMIT 1");
    return (int)$st->fetchColumn();
}

function mo_catalog_by_key(PDO $pdo, string $catalogKey): ?array
{
    mo_ensure_tables($pdo);
    $st = $pdo->prepare('SELECT * FROM mock_oral_acs_catalogs WHERE catalog_key = ? LIMIT 1');
    $st->execute([trim($catalogKey)]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function mo_area_by_id(PDO $pdo, int $areaId): ?array
{
    mo_ensure_tables($pdo);
    $st = $pdo->prepare('
        SELECT a.*, c.catalog_key, c.label AS catalog_label
        FROM mock_oral_acs_areas a
        INNER JOIN mock_oral_acs_catalogs c ON c.id = a.catalog_id
        WHERE a.id = ? LIMIT 1
    ');
    $st->execute([$areaId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

require_once __DIR__ . '/../openai.php';

function mo_json_decode(mixed $json): array
{
    if (is_array($json)) {
        return $json;
    }
    if ($json === null || !is_string($json) || trim($json) === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function mo_synthesize_speech_mp3(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    $model = getenv('CW_OPENAI_TTS_MODEL') ?: 'gpt-4o-mini-tts';
    $voice = getenv('CW_OPENAI_TTS_VOICE') ?: (getenv('CW_OPENAI_REALTIME_VOICE') ?: 'marin');
    $payload = json_encode([
        'model' => $model,
        'voice' => $voice,
        'format' => 'mp3',
        'input' => $text,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.openai.com/v1/audio/speech');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . cw_openai_key(),
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 120,
    ]);
    $audio = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($audio === false || $code < 200 || $code >= 300) {
        return '';
    }

    return (string)$audio;
}

function mo_json_encode(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
}

function mo_storage_dir(string $subdir): string
{
    $dir = dirname(__DIR__, 2) . '/storage/' . trim($subdir, '/');
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return $dir;
}

function mo_faa_report_storage_dir(): string
{
    return mo_storage_dir('faa_knowledge_test_reports');
}

function mo_support_email(): string
{
    $v = getenv('CW_SUPPORT_EMAIL') ?: getenv('SUPPORT_EMAIL') ?: 'support@ipca.training';
    return trim((string)$v);
}

function mo_app_base_url(): string
{
    $base = getenv('CW_APP_BASE_URL') ?: '';
    if ($base !== '') {
        return rtrim($base, '/');
    }
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host !== '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $host;
    }
    return 'https://ipca.training';
}
