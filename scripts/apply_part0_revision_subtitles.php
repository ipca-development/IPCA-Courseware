<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/RuntimeSecrets.php';
RuntimeSecrets::ensureCliEnvLoaded();
require_once __DIR__ . '/../src/db.php';

$apply = in_array('--apply', $argv, true);
$pdo = cw_db();
$rows = $pdo->query("
    SELECT b.id, b.payload_json
    FROM ipca_publishing_book_blocks b
    INNER JOIN ipca_publishing_book_sections s ON s.id = b.section_id
    WHERE s.section_key = 'highlights'
      AND b.block_type = 'paragraph'
      AND b.is_system_managed = 0
    ORDER BY b.id
")->fetchAll(PDO::FETCH_ASSOC) ?: array();

$updates = array();
foreach ($rows as $row) {
    $payload = json_decode((string)($row['payload_json'] ?? ''), true);
    if (!is_array($payload)) {
        continue;
    }
    $text = trim(html_entity_decode(strip_tags((string)($payload['html'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if (preg_match('/^Revision\s+(\d+)\s+Changes\s*:?\s*$/iu', $text, $match) !== 1) {
        continue;
    }
    $title = 'Revision ' . (int)$match[1] . ' Changes';
    if (($payload['paragraph_style'] ?? '') === 'subtitle_2' && $text === $title) {
        continue;
    }
    $payload['paragraph_style'] = 'subtitle_2';
    $payload['html'] = '<p>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    $updates[] = array(
        'id' => (int)$row['id'],
        'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'title' => $title,
    );
}

if ($apply && $updates !== array()) {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            UPDATE ipca_publishing_book_blocks
            SET payload_json = :payload_json,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        foreach ($updates as $update) {
            $stmt->execute(array(
                ':payload_json' => $update['payload_json'],
                ':id' => $update['id'],
            ));
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

echo ($apply ? 'Applied' : 'Would apply') . ' Subtitle 2 to ' . count($updates) . " revision headings.\n";
foreach ($updates as $update) {
    echo '  block ' . $update['id'] . ': ' . $update['title'] . "\n";
}
