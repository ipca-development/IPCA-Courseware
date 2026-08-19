<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/db.php';
require_once dirname(__DIR__, 2) . '/src/safety/SafetyFeatureConfigService.php';
require_once dirname(__DIR__, 2) . '/src/safety/SafetyOccurrenceIntakeContextService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$pdo = cw_db();
$service = new SafetyOccurrenceIntakeContextService(
    $pdo,
    new SafetyFeatureConfigService($pdo)
);
$limit = max(1, min(500, (int)($argv[1] ?? 100)));
$stmt = $pdo->prepare(
    "SELECT organization_id, report_id
     FROM ipca_safety_report_flight_links
     WHERE link_choice = 'scheduled_flight' AND resolution_state IN ('pending','review_required')
     ORDER BY updated_at_utc, id
     LIMIT {$limit}"
);
$stmt->execute();
$processed = 0;
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $service->reconcileReport((int)$row['organization_id'], (int)$row['report_id']);
    $processed++;
}
echo json_encode(array('ok' => true, 'processed' => $processed), JSON_UNESCAPED_SLASHES) . PHP_EOL;
