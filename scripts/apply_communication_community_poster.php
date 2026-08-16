<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/RuntimeSecrets.php';
RuntimeSecrets::ensureCliEnvLoaded();
require_once __DIR__ . '/../src/db.php';

$pdo = cw_db();

$hasPoster = false;
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM ipca_community_post_media LIKE 'poster_storage_key'");
    $hasPoster = $stmt !== false && $stmt->fetch(PDO::FETCH_ASSOC) !== false;
} catch (Throwable) {
    $hasPoster = false;
}

if (!$hasPoster) {
    $pdo->exec("ALTER TABLE ipca_community_post_media ADD COLUMN poster_storage_key VARCHAR(512) NULL AFTER duration_ms");
    echo "Added ipca_community_post_media.poster_storage_key\n";
} else {
    echo "ipca_community_post_media.poster_storage_key already present\n";
}
