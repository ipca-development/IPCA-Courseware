<?php
declare(strict_types=1);

/**
 * @deprecated Covers are stored per edition id; use resource_library_thumb.php?id=
 */
require_once __DIR__ . '/../../src/bootstrap.php';

cw_require_admin();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Bad id';
    exit;
}

$q = http_build_query(['id' => $id]);
header('Location: /admin/resource_library_thumb.php?' . $q, true, 301);
exit;
