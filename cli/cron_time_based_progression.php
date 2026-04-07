<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/time_based_progression_cron.php';

try {
    $cron = new TimeBasedProgressionCron($pdo);
    $result = $cron->run();

    echo json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    ) . PHP_EOL;

    exit(!empty($result['ok']) ? 0 : 1);
} catch (Throwable $e) {
    $error = array(
        'ok' => false,
        'started_at_utc' => gmdate('Y-m-d H:i:s'),
        'finished_at_utc' => gmdate('Y-m-d H:i:s'),
        'message' => $e->getMessage(),
    );

    echo json_encode(
        $error,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    ) . PHP_EOL;

    exit(1);
}