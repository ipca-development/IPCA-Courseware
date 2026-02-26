<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

// Optional: require login
// cw_require_login();

try {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  if (!is_array($data)) throw new RuntimeException("Invalid JSON");

  $slideId = (int)($data['slide_id'] ?? 0);
  $event = (string)($data['event'] ?? 'view');
  $meta = $data['meta'] ?? null;

  if ($slideId <= 0) throw new RuntimeException("Missing slide_id");

  // If you have a user id in session, use it; else 0
  $userId = (int)($_SESSION['user_id'] ?? 0);

  // Save into slide_events if exists
  $stmt = $pdo->prepare("INSERT INTO slide_events (user_id, slide_id, event_type, seconds) VALUES (?,?,?,0)");
  $stmt->execute([$userId, $slideId, $event]);

  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}