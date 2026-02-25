<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
cw_require_admin();

header('Content-Type: application/json; charset=utf-8');

$rows = $pdo->query("SELECT id, axis, pos, color, sort_order FROM designer_guides ORDER BY sort_order, axis, pos")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['ok'=>true,'guides'=>$rows]);