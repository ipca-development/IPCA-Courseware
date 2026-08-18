<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__, 2) . '/src/db.php';
require_once dirname(__DIR__, 2) . '/src/safety/SafetySupport.php';
require_once dirname(__DIR__, 2) . '/src/safety/SafetyAccessService.php';
require_once dirname(__DIR__, 2) . '/src/safety/SafetyAuditEventService.php';
require_once dirname(__DIR__, 2) . '/src/safety/SafetyEccairsService.php';

@set_time_limit(0);

$options = getopt('', array(
    'environment::',
    'max-jobs::',
    'reconcile',
    'reconcile-limit::',
    'export-uuid::',
    'output::',
));
$environment = trim((string)($options['environment'] ?? ''));
$maxJobs = max(1, min(100, (int)($options['max-jobs'] ?? 10)));
$reconcile = array_key_exists('reconcile', $options);
$reconcileLimit = max(1, min(200, (int)($options['reconcile-limit'] ?? 50)));
$exportUuid = strtolower(trim((string)($options['export-uuid'] ?? '')));
$output = trim((string)($options['output'] ?? ''));

$pdo = cw_db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$access = new SafetyAccessService($pdo);
$events = new SafetyAuditEventService($pdo);
$config = new SafetyEccairsConfig($pdo);
$mapper = new SafetyEccairsMapper($pdo);
$restSerializer = new SafetyEccairsRestSerializer();
$client = new SafetyEccairsApiClient($config);
$service = new SafetyEccairsService(
    $pdo,
    $access,
    $events,
    $config,
    $mapper,
    $restSerializer,
    $client
);

try {
    if ($exportUuid !== '') {
        if ($output === '' || strtolower(pathinfo($output, PATHINFO_EXTENSION)) !== 'e5x') {
            throw new InvalidArgumentException('--output must name an .e5x file.');
        }
        $stmt = $pdo->prepare(
            "SELECT * FROM ipca_safety_eccairs_submissions
             WHERE submission_uuid = ? AND approved_at_utc IS NOT NULL
               AND status IN ('queued','sending','accepted','retry_pending','rejected')
             LIMIT 1"
        );
        $stmt->execute(array($exportUuid));
        $submission = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($submission)) {
            throw new RuntimeException('Approved ECCAIRS submission not found.');
        }
        $result = (new SafetyEccairsE5xExporter())->export($submission, $output);
        $versionStmt = $pdo->prepare(
            "SELECT COALESCE(MAX(artifact_version), 0) + 1
             FROM ipca_safety_eccairs_artifacts
             WHERE submission_id = ? AND transport = 'e5x'"
        );
        $versionStmt->execute(array((int)$submission['id']));
        $artifactVersion = (int)$versionStmt->fetchColumn();
        $pdo->prepare(
            "INSERT INTO ipca_safety_eccairs_artifacts
             (organization_id, submission_id, artifact_uuid, transport, artifact_version,
              content_type, artifact_json, storage_reference, artifact_sha256, schema_version)
             VALUES (?, ?, ?, 'e5x', ?, ?, ?, ?, ?, ?)"
        )->execute(array(
            (int)$submission['organization_id'],
            (int)$submission['id'],
            SafetySupport::uuid(),
            $artifactVersion,
            'application/octet-stream',
            SafetySupport::json(array(
                'packaging_profile' => $result['packaging_profile'],
                'xml_sha256' => $result['xml_sha256'],
            )),
            basename($output),
            $result['sha256'],
            (string)$result['schema_version'],
        ));
        $events->append(
            (int)$submission['organization_id'],
            'occurrence',
            (int)$submission['occurrence_id'],
            'eccairs.e5x_generated',
            'system',
            null,
            null,
            array(
                'submission_uuid' => $exportUuid,
                'artifact_sha256' => $result['sha256'],
                'canonical_sha256' => $result['canonical_sha256'],
                'xml_sha256' => $result['xml_sha256'],
                'packaging_profile' => $result['packaging_profile'],
            )
        );
        fwrite(STDOUT, SafetySupport::json(array(
            'ok' => true,
            'mode' => 'e5x_export',
            'submission_uuid' => $exportUuid,
            'archive_sha256' => $result['sha256'],
        )) . "\n");
        exit(0);
    }

    $recovered = $service->recoverStaleSending();
    $processed = 0;
    $outcomes = array();
    while ($processed < $maxJobs) {
        $result = $service->processNext($environment !== '' ? $environment : null);
        if ($result === null) {
            break;
        }
        $processed++;
        $status = (string)($result['status'] ?? 'unknown');
        $outcomes[$status] = ($outcomes[$status] ?? 0) + 1;
    }
    $reconciled = $reconcile ? $service->reconcileAccepted($reconcileLimit) : 0;
    fwrite(STDOUT, SafetySupport::json(array(
        'ok' => true,
        'mode' => 'queue_worker',
        'environment' => $environment !== '' ? $environment : 'all',
        'processed' => $processed,
        'outcomes' => $outcomes,
        'stale_sending_recovered' => $recovered,
        'reconciled' => $reconciled,
    )) . "\n");
} catch (Throwable $e) {
    fwrite(STDERR, SafetySupport::json(array(
        'ok' => false,
        'error' => $e instanceof SafetyException ? $e->errorCode : get_class($e),
        'message' => $e->getMessage(),
    )) . "\n");
    exit(1);
}
