<?php
declare(strict_types=1);

/**
 * Bearer communication APIs must not start the Courseware web session (CWSESS).
 * They only need a PDO connection.
 */
require_once __DIR__ . '/../db.php';

$pdo = cw_db();

if (getenv('CW_COMMUNICATION_STAGING') === '1') {
    $name = (string)getenv('CW_DB_NAME');
    if ($name !== 'ipca_courseware_staging') {
        throw new RuntimeException('Staging communication server refused to start: CW_DB_NAME is not ipca_courseware_staging.');
    }
    $active = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($active !== 'ipca_courseware_staging') {
        throw new RuntimeException('Staging communication server refused to start: connected database is not ipca_courseware_staging.');
    }
}
