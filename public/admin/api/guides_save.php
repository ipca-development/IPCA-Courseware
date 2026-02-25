<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
cw_require_admin();

header('Content-Type: application/json; charset=utf-8');

try {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new RuntimeException("Invalid JSON");

    $action = (string)($data['action'] ?? '');

    if ($action === 'add') {
        $axis = (string)($data['axis'] ?? '');
        $pos  = (int)($data['pos'] ?? 0);
        $color = (string)($data['color'] ?? '#ABCDE0');
        if (!in_array($axis, ['v','h'], true)) throw new RuntimeException("Invalid axis");
        if ($pos < 0 || $pos > ($axis==='v' ? 1600 : 900)) throw new RuntimeException("pos out of range");
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) $color = '#ABCDE0';

        $stmt = $pdo->prepare("INSERT INTO designer_guides (axis,pos,color,sort_order) VALUES (?,?,?,0)");
        $stmt->execute([$axis,$pos,$color]);
        echo json_encode(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) throw new RuntimeException("Missing id");
        $pdo->prepare("DELETE FROM designer_guides WHERE id=?")->execute([$id]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($action === 'update') {
        $id = (int)($data['id'] ?? 0);
        $pos = (int)($data['pos'] ?? 0);
        $color = (string)($data['color'] ?? '#ABCDE0');
        if ($id <= 0) throw new RuntimeException("Missing id");
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) $color = '#ABCDE0';

        $pdo->prepare("UPDATE designer_guides SET pos=?, color=? WHERE id=?")->execute([$pos,$color,$id]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    throw new RuntimeException("Unknown action");
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}