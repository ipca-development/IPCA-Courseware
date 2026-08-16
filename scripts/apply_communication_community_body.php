<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/RuntimeSecrets.php';
RuntimeSecrets::ensureCliEnvLoaded();
require_once __DIR__ . '/../src/db.php';

$pdo = cw_db();

$hasBody = false;
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM ipca_community_posts LIKE 'body'");
    $hasBody = $stmt !== false && $stmt->fetch(PDO::FETCH_ASSOC) !== false;
} catch (Throwable) {
    $hasBody = false;
}

if (!$hasBody) {
    $pdo->exec("ALTER TABLE ipca_community_posts ADD COLUMN body TEXT NULL AFTER caption");
    echo "Added ipca_community_posts.body\n";
} else {
    echo "ipca_community_posts.body already present\n";
}
