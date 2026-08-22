<?php
require_once __DIR__.'/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/theory_studio/TheoryStudioIsolation.php';
cw_require_admin();
header('Content-Type: application/json');

$data=json_decode(file_get_contents('php://input'),true);
$lessonId=(int)$data['lesson_id'];

try {
    theory_studio_require_operational_lesson($pdo, $lessonId);
} catch (TheoryStudioException $e) {
    http_response_code($e->httpStatus);
    echo json_encode(['ok' => false, 'error_code' => $e->errorCode, 'error' => $e->getMessage()]);
    exit;
}

$pdo->beginTransaction();
foreach($data['ordered'] as $row){
 $pdo->prepare("UPDATE slides SET page_number=? WHERE id=?")
     ->execute([(int)$row['page'],(int)$row['id']]);
}
$pdo->commit();

echo json_encode(['ok'=>true]);