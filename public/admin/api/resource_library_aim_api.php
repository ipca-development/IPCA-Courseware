<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/resource_library_aim.php';

cw_require_admin();

header('Content-Type: application/json; charset=utf-8');

$slot = strtolower(trim((string) ($_GET['slot'] ?? 'aim')));
if (!in_array($slot, ['aim', 'reserved2', 'reserved3'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid slot'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$dash = rl_aim_slot_dashboard($pdo, $slot);
echo json_encode($dash, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
