<?php
declare(strict_types=1);

/**
 * Restricted legacy occurrence PDF extractor.
 *
 * Standard output is aggregate-only. Extracted content is written only to the
 * restricted database table when --stage is explicitly supplied.
 *
 * Examples:
 *   php scripts/safety/legacy_pdf_extract.php --source-dir=/restricted/files --pretty
 *   php scripts/safety/legacy_pdf_extract.php --source-dir=/restricted/files \
 *     --stage --batch-uuid=<uuid> --organization-id=1
 *   php scripts/safety/legacy_pdf_extract.php --review-id=12 --reviewer-id=1 \
 *     --decision=approved --notes-file=/restricted/review-note.txt
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__, 2) . '/src/safety/SafetySupport.php';

$options = getopt('', array(
    'source-dir::',
    'stage',
    'batch-uuid::',
    'organization-id::',
    'pretty',
    'review-id::',
    'reviewer-id::',
    'decision::',
    'notes-file::',
));

try {
    if (isset($options['review-id'])) {
        smsReviewExtraction($options);
        exit(0);
    }
    $sourceDir = rtrim(trim((string)($options['source-dir'] ?? '')), '/');
    if ($sourceDir === '' || !is_dir($sourceDir) || !is_readable($sourceDir)) {
        throw new InvalidArgumentException('--source-dir must name a readable restricted directory.');
    }
    $files = glob($sourceDir . '/OR_[0-9][0-9][0-9][0-9].pdf') ?: array();
    sort($files, SORT_NATURAL);
    if ($files === array()) {
        throw new RuntimeException('No OR_0000.pdf files were found.');
    }

    $stage = array_key_exists('stage', $options);
    $batchUuid = strtolower(trim((string)($options['batch-uuid'] ?? '')));
    $organizationId = max(1, (int)($options['organization-id'] ?? 1));
    $pdo = null;
    if ($stage) {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $batchUuid) !== 1) {
            throw new InvalidArgumentException('--batch-uuid must be a version-4 UUID when staging.');
        }
        require_once dirname(__DIR__, 2) . '/src/db.php';
        $pdo = cw_db();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    $summary = array(
        'schema_version' => 1,
        'mode' => $stage ? 'restricted_stage' : 'aggregate_preflight',
        'files' => count($files),
        'text_based' => 0,
        'ocr_used' => 0,
        'multi_page_bundle_candidates' => 0,
        'content_blank_after_extraction' => 0,
        'staged' => 0,
        'already_staged' => 0,
        'pii_indicator_documents' => 0,
        'field_presence' => array(),
        'content_emitted' => false,
        'source_paths_emitted' => false,
        'external_transmission' => false,
    );

    foreach ($files as $file) {
        $extracted = smsExtractPdf($file);
        $text = $extracted['text'];
        $method = $extracted['method'];
        $pages = smsPdfPages($file);
        $summary[$method === 'ocr' ? 'ocr_used' : 'text_based']++;
        if ($pages > 3) {
            $summary['multi_page_bundle_candidates']++;
        }
        if (trim($text) === '') {
            $summary['content_blank_after_extraction']++;
        }
        $payload = smsStructuredFields($text);
        $payload['_quality'] = array(
            'page_count' => $pages,
            'bundle_candidate' => $pages > 3,
            'mapping_confidence' => $method === 'ocr' ? 'low' : 'medium',
            'manual_source_match_required' => true,
        );
        if (smsContainsPiiIndicators($text)) {
            $summary['pii_indicator_documents']++;
        }
        foreach ($payload as $field => $value) {
            if (is_array($value)) {
                continue;
            }
            if ($value !== null && $value !== '') {
                $summary['field_presence'][$field] = ($summary['field_presence'][$field] ?? 0) + 1;
            }
        }
        if (!$pdo instanceof PDO) {
            continue;
        }
        $payload['legacy_report_reference'] = pathinfo($file, PATHINFO_FILENAME);
        $payloadJson = SafetySupport::json($payload);
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO ipca_safety_legacy_document_extractions
             (organization_id, import_batch_uuid, source_reference, source_sha256, source_byte_size,
              extraction_method, extraction_version, extracted_payload_json, extracted_payload_sha256)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(array(
            $organizationId,
            $batchUuid,
            basename($file),
            hash_file('sha256', $file),
            filesize($file),
            $method,
            'legacy-or-v1',
            $payloadJson,
            SafetySupport::digest($payloadJson),
        ));
        if ($stmt->rowCount() === 1) {
            $summary['staged']++;
        } else {
            $summary['already_staged']++;
        }
    }
    ksort($summary['field_presence']);
    $flags = JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
    if (array_key_exists('pretty', $options)) {
        $flags |= JSON_PRETTY_PRINT;
    }
    fwrite(STDOUT, json_encode($summary, $flags) . "\n");
} catch (Throwable $e) {
    fwrite(STDERR, SafetySupport::json(array(
        'ok' => false,
        'error' => get_class($e),
        'message' => $e->getMessage(),
        'content_emitted' => false,
    )) . "\n");
    exit(1);
}

/** @return array{text:string,method:string} */
function smsExtractPdf(string $file): array
{
    $textFile = tempnam(sys_get_temp_dir(), 'ipca_sms_text_');
    if (!is_string($textFile)) {
        throw new RuntimeException('Could not allocate temporary extraction storage.');
    }
    try {
        smsRun(array('pdftotext', '-layout', '-nopgbrk', $file, $textFile));
        $text = file_get_contents($textFile);
        $text = is_string($text) ? smsNormalizeExtractedText($text) : '';
        if (mb_strlen(preg_replace('/\s+/u', '', $text) ?? '') >= 80) {
            return array('text' => $text, 'method' => 'pdftotext');
        }
        $ocrText = smsOcrPdf($file);
        return array(
            'text' => mb_strlen(trim($ocrText)) > mb_strlen(trim($text)) ? $ocrText : $text,
            'method' => 'ocr',
        );
    } finally {
        @unlink($textFile);
    }
}

function smsOcrPdf(string $file): string
{
    $directory = sys_get_temp_dir() . '/ipca_sms_ocr_' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not allocate OCR workspace.');
    }
    try {
        $prefix = $directory . '/page';
        smsRun(array('pdftoppm', '-f', '1', '-l', '3', '-r', '200', '-png', $file, $prefix));
        $images = glob($prefix . '-*.png') ?: array();
        sort($images, SORT_NATURAL);
        $parts = array();
        foreach ($images as $image) {
            $result = smsRun(array('tesseract', $image, 'stdout', '-l', 'eng'), true);
            $parts[] = $result;
        }
        return smsNormalizeExtractedText(implode("\n", $parts));
    } finally {
        foreach (glob($directory . '/*') ?: array() as $temporary) {
            @unlink($temporary);
        }
        @rmdir($directory);
    }
}

/**
 * @param list<string> $command
 */
function smsRun(array $command, bool $capture = false): string
{
    $pipes = array();
    $process = proc_open(
        $command,
        array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes,
        null,
        array('PATH' => (string)getenv('PATH'))
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start a required local extraction tool.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        throw new RuntimeException('A local PDF extraction tool failed: ' . mb_substr(trim((string)$stderr), 0, 300));
    }
    return $capture && is_string($stdout) ? $stdout : '';
}

function smsNormalizeExtractedText(string $text): string
{
    $text = str_replace(array("\r\n", "\r", "\0"), array("\n", "\n", ''), $text);
    $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
    return trim($text);
}

function smsPdfPages(string $file): int
{
    $output = smsRun(array('pdfinfo', $file), true);
    return preg_match('/^Pages:\s+(\d+)$/mi', $output, $match) === 1
        ? max(1, (int)$match[1])
        : 1;
}

/** @return array<string,?string> */
function smsStructuredFields(string $text): array
{
    $fields = array(
        'classification' => smsCapture($text, '/Classification:\s*([^\n]+)/i'),
        'event_date' => smsEventDate($text),
        'event_time_utc' => smsCapture($text, '/Time\s*\(UTC\):\s*([^\n]+)/i'),
        'training_flight_reference' => smsCapture($text, '/Training Flight Reference:\s*([^\n]+)/i'),
        'route_or_station' => smsCapture($text, '/(?:Route|Route\/Station[^:]*):\s*([^\n]+)/i'),
        'transponder_code' => smsCapture($text, '/(?:XPDR|Transponder) Code[^:]*:\s*([^\n]+)/i'),
        'aircraft_type' => smsCapture($text, '/(?:Aircraft Type|A\/C Type):\s*([^\n]+)/i'),
        'aircraft_registration' => smsCapture($text, '/(?:Aircraft Registration|Registration):\s*([^\n]+)/i'),
        'altitude' => smsCapture($text, '/Altitude:\s*([^\n]+)/i'),
        'airspeed' => smsCapture($text, '/Airspeed:\s*([^\n]+)/i'),
        'aircraft_mass' => smsCapture($text, '/Aircraft Mass:\s*([^\n]+)/i'),
        'flight_phase' => smsCapture($text, '/Flight Phase:\s*([^\n]+)/i'),
        'weather_conditions' => smsCapture($text, '/Weather Conditions:\s*([^\n]+)/i'),
        'weather_details' => smsCapture($text, '/Weather Details:\s*([^\n]+)/i'),
        'runway' => smsCapture($text, '/Runway:\s*([^\n]+)/i'),
        'runway_condition' => smsCapture($text, '/Runway Condition:\s*([^\n]+)/i'),
        'configuration' => smsCapture($text, '/Configuration:\s*([^\n]+)/i'),
        'title' => smsCapture(
            $text,
            '/(?:Title of Event|Summary\s*\/\s*Subject\s*\/\s*Title of the event):\s*(.*?)(?=\n(?:Description|23\.|Detailed Description|OMM Annex)|\z)/is'
        ),
        'narrative' => smsCapture(
            $text,
            '/(?:Description|Detailed Description of the event[^:]*):\s*(.*?)(?=\n(?:Crew Actions|24\.|Actions of the crew)|\z)/is'
        ),
        'crew_actions' => smsCapture(
            $text,
            '/(?:Crew Actions|Actions of the crew to manage the event):\s*(.*?)(?=\n(?:Future Prevention|25\.|Proposal to prevent)|\z)/is'
        ),
        'proposed_prevention' => smsCapture(
            $text,
            '/(?:Future Prevention|Proposal to prevent the event from re-occuring):\s*(.*?)(?=\n(?:Anonymous|26\.|Signatures)|\z)/is'
        ),
        'anonymous_declared' => smsCapture($text, '/Anonymous:\s*(Yes|No)/i'),
    );
    foreach ($fields as $name => $value) {
        if ($value !== null) {
            $fields[$name] = mb_substr(trim(preg_replace('/\s+/u', ' ', $value) ?? $value), 0, 50000);
        }
    }
    return $fields;
}

function smsCapture(string $text, string $pattern): ?string
{
    return preg_match($pattern, $text, $match) === 1 ? trim((string)$match[1]) : null;
}

function smsEventDate(string $text): ?string
{
    if (preg_match_all('/(?:^|\n)Date:\s*([^\n]+)/i', $text, $matches) < 1 || empty($matches[1])) {
        return null;
    }
    foreach ($matches[1] as $candidate) {
        $candidate = trim((string)$candidate);
        if (
            preg_match('/^\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4}\b/', $candidate) === 1
            && !in_array($candidate, array('01/07/2013', '01-07-2013'), true)
        ) {
            return $candidate;
        }
    }
    return null;
}

function smsContainsPiiIndicators(string $text): bool
{
    return preg_match('/(?:Pilot In Command|PIC|Student|Reporters?|From|To):\s*\S+/i', $text) === 1
        || preg_match('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', $text) === 1;
}

/** @param array<string,mixed> $options */
function smsReviewExtraction(array $options): void
{
    $reviewId = (int)($options['review-id'] ?? 0);
    $reviewerId = (int)($options['reviewer-id'] ?? 0);
    $decision = strtolower(trim((string)($options['decision'] ?? '')));
    $notesFile = trim((string)($options['notes-file'] ?? ''));
    if ($reviewId < 1 || $reviewerId < 1 || !in_array($decision, array('approved', 'rejected'), true)) {
        throw new InvalidArgumentException('Review requires valid --review-id, --reviewer-id and --decision.');
    }
    if ($notesFile === '' || !is_file($notesFile) || !is_readable($notesFile)) {
        throw new InvalidArgumentException('--notes-file must name a readable restricted review note.');
    }
    $notes = file_get_contents($notesFile);
    if (!is_string($notes)) {
        throw new RuntimeException('Could not read the review note.');
    }
    $notes = SafetySupport::cleanText($notes, 12000, 'review_notes');
    require_once dirname(__DIR__, 2) . '/src/db.php';
    $pdo = cw_db();
    $stmt = $pdo->prepare(
        "UPDATE ipca_safety_legacy_document_extractions
         SET review_status = ?, reviewed_by_user_id = ?, reviewed_at_utc = CURRENT_TIMESTAMP(3),
             review_notes = ?
         WHERE id = ? AND review_status = 'pending'"
    );
    $stmt->execute(array($decision, $reviewerId, $notes, $reviewId));
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Pending extraction record not found.');
    }
    fwrite(STDOUT, SafetySupport::json(array(
        'ok' => true,
        'review_id' => $reviewId,
        'decision' => $decision,
        'content_emitted' => false,
    )) . "\n");
}
