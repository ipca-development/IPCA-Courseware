<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$screenKey = trim((string)($_GET['screen_key'] ?? 'main'));
$screenKey = preg_replace('/[^a-zA-Z0-9_-]/', '', $screenKey) ?: 'main';

try {
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'tv_screen_messages'");
    if ($tableCheck === false || $tableCheck->fetchColumn() === false) {
        echo json_encode(array(
            'ok' => true,
            'screen_key' => $screenKey,
            'urgent_override' => false,
            'server_time' => gmdate('c'),
            'messages' => array(),
            'setup_required' => true,
        ), JSON_UNESCAPED_SLASHES);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT
            id,
            screen_key,
            message_type,
            title,
            body,
            priority,
            starts_at,
            ends_at,
            display_duration_seconds,
            announce_audio_enabled,
            voice_text,
            voice,
            audio_url,
            status,
            updated_at
        FROM tv_screen_messages
        WHERE screen_key = ?
          AND status = 'active'
          AND (starts_at IS NULL OR starts_at <= UTC_TIMESTAMP())
          AND (ends_at IS NULL OR ends_at >= UTC_TIMESTAMP())
        ORDER BY
          CASE WHEN message_type = 'urgent' OR priority >= 90 THEN 0 ELSE 1 END ASC,
          priority DESC,
          COALESCE(starts_at, created_at) ASC,
          id ASC
        LIMIT 24
    ");
    $stmt->execute([$screenKey]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $urgent = array_values(array_filter($rows, static function (array $row): bool {
        return strtolower((string)($row['message_type'] ?? '')) === 'urgent' || (int)($row['priority'] ?? 0) >= 90;
    }));

    if (count($urgent) > 0) {
        $rows = array_slice($urgent, 0, 3);
    }

    $messages = array_map(static function (array $row): array {
        return array(
            'id' => (int)$row['id'],
            'screen_key' => (string)$row['screen_key'],
            'message_type' => (string)$row['message_type'],
            'title' => (string)$row['title'],
            'body' => (string)$row['body'],
            'priority' => (int)$row['priority'],
            'starts_at' => $row['starts_at'],
            'ends_at' => $row['ends_at'],
            'display_duration_seconds' => max(5, (int)$row['display_duration_seconds']),
            'announce_audio_enabled' => (bool)$row['announce_audio_enabled'],
            'voice_text' => (string)($row['voice_text'] ?? ''),
            'voice' => (string)($row['voice'] ?? ''),
            'audio_url' => (string)($row['audio_url'] ?? ''),
            'status' => (string)$row['status'],
            'updated_at' => (string)$row['updated_at'],
        );
    }, $rows);

    echo json_encode(array(
        'ok' => true,
        'screen_key' => $screenKey,
        'urgent_override' => count($urgent) > 0,
        'server_time' => gmdate('c'),
        'messages' => $messages,
    ), JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(array(
        'ok' => false,
        'error' => 'Unable to load TV screen messages.',
        'messages' => array(),
    ), JSON_UNESCAPED_SLASHES);
}
