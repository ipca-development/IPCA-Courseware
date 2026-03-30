<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

echo "STEP 1<br>";

require_once __DIR__ . '/../../../src/bootstrap.php';

echo "STEP 2<br>";

header('Content-Type: application/json; charset=utf-8');

echo "STEP 3<br>";

function json_out(array $x): void {
    echo json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

echo "STEP 4<br>";

try {
    echo "STEP 5<br>";

    $u = cw_current_user($pdo);

    echo "STEP 6<br>";
    echo '<pre>';
    print_r($u);
    echo '</pre>';

    json_out([
        'ok' => true,
        'step' => 'reached',
        'user' => $u
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo "<pre>";
    echo "ERROR:\n";
    echo $e->getMessage() . "\n\n";
    echo $e->getFile() . "\n";
    echo $e->getLine() . "\n\n";
    echo $e->getTraceAsString();
    echo "</pre>";
    exit;
}