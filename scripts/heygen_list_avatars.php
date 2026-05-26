<?php
declare(strict_types=1);

/**
 * List LiveAvatar avatars and mint a test session token.
 * Usage: CW_HEYGEN_API_KEY=... php scripts/heygen_list_avatars.php
 */

$apiKey = trim((string)(getenv('CW_HEYGEN_API_KEY') ?: getenv('HEYGEN_API_KEY') ?: ''));
if ($apiKey === '') {
    fwrite(STDERR, "Set CW_HEYGEN_API_KEY (or HEYGEN_API_KEY) in the environment.\n");
    exit(1);
}

function liveavatar_get(string $apiKey, string $path): array
{
    $ch = curl_init('https://api.liveavatar.com' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'X-API-KEY: ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = (string)curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $decoded = ['raw' => $raw];
    }

    return ['http' => $http, 'body' => $decoded];
}

function liveavatar_post(string $apiKey, string $path, array $payload = []): array
{
    $ch = curl_init('https://api.liveavatar.com' . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-API-KEY: ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = (string)curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $decoded = ['raw' => $raw];
    }

    return ['http' => $http, 'body' => $decoded];
}

$testAvatar = trim((string)(getenv('CW_HEYGEN_AVATAR_ID') ?: ''));
$testVoice = trim((string)(getenv('CW_HEYGEN_VOICE_ID') ?: ''));

echo "=== LiveAvatar session token (smoke test) ===\n";
if ($testAvatar === '') {
    echo "Set CW_HEYGEN_AVATAR_ID to smoke-test token minting.\n\n";
} else {
    $persona = ['language' => 'en'];
    if ($testVoice !== '') {
        $persona['voice_id'] = $testVoice;
    }
    $tokenRes = liveavatar_post($apiKey, '/v1/sessions/token', [
        'mode' => 'LITE',
        'avatar_id' => $testAvatar,
        'avatar_persona' => $persona,
    ]);
    if ($tokenRes['http'] >= 200 && $tokenRes['http'] < 300) {
        $token = (string)($tokenRes['body']['data']['session_token'] ?? '');
        $sid = (string)($tokenRes['body']['data']['session_id'] ?? '');
        echo $token !== '' ? "Token OK (" . strlen($token) . " chars), session {$sid}\n" : "Token response missing session_token.\n";
    } else {
        echo "Token request failed HTTP {$tokenRes['http']}\n";
        echo json_encode($tokenRes['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
    echo "\n";
}

echo "=== Public avatars (first page) ===\n";
$publicRes = liveavatar_get($apiKey, '/v1/avatars/public?page_size=15');
if ($publicRes['http'] < 200 || $publicRes['http'] >= 300) {
    echo "Public avatar list failed HTTP {$publicRes['http']}\n";
    echo json_encode($publicRes['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(1);
}

$public = $publicRes['body']['data']['results'] ?? [];
foreach ($public as $row) {
    if (!is_array($row)) {
        continue;
    }
    $id = (string)($row['id'] ?? '');
    $name = (string)($row['name'] ?? '');
    if ($id === '') {
        continue;
    }
    echo "- {$id}";
    if ($name !== '') {
        echo " ({$name})";
    }
    $voice = $row['default_voice']['id'] ?? '';
    if ($voice !== '') {
        echo " voice={$voice}";
    }
    echo "\n";
}

echo "\n=== Custom avatars ===\n";
$customRes = liveavatar_get($apiKey, '/v1/avatars/custom?page_size=15');
$custom = $customRes['body']['data']['results'] ?? [];
if ($custom === []) {
    echo "No custom avatars yet (your Maya avatar will appear here when ready).\n";
} else {
    foreach ($custom as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string)($row['id'] ?? '');
        $name = (string)($row['name'] ?? '');
        if ($id === '') {
            continue;
        }
        echo "- {$id}";
        if ($name !== '') {
            echo " ({$name})";
        }
        $voice = $row['default_voice']['id'] ?? '';
        if ($voice !== '') {
            echo " voice={$voice}";
        }
        echo " [custom]\n";
    }
}

echo "\nDefault Maya avatar + voice (used when env vars are unset):\n";
echo "CW_HEYGEN_AVATAR_ID=8de43fb8-8c57-4fba-9c30-295574b4749c\n";
echo "CW_HEYGEN_VOICE_ID=3607df3c-9de0-4274-b0be-7e035775ead5\n";
