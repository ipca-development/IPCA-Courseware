<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$options = getopt('', array(
    'repo-root::', 'fixture-dir:', 'source::', 'label:', 'actor:',
    'approve-package:', 'preflight',
));
$repoRoot = rtrim((string)($options['repo-root'] ?? dirname(__DIR__)), '/');
$fixtureDir = rtrim((string)($options['fixture-dir'] ?? ''), '/');
$sourceVersionId = (int)($options['source'] ?? 9);
$newVersionLabel = trim((string)($options['label'] ?? ''));
$actorUserId = (int)($options['actor'] ?? 0);
$approval = trim((string)($options['approve-package'] ?? ''));
$preflightOnly = array_key_exists('preflight', $options);

if (!$preflightOnly) {
    throw new RuntimeException(
        'This Manual Change Architect utility is permanently read-only. '
        . 'Controlled revisions can only be created through the authenticated Books & Manuals workflow.'
    );
}

const APPROVED_PACKAGE_FINGERPRINT = 'da4cef101b5dfb50c5559c05e5cad3f13394993104745854cd0cbeef37174058';
const EXPECTED_SOURCE_SNAPSHOT = '990d67ca08d08486ac6c0cf6aeca92d7b92d417de3cc0bfa074134fcf4dab664';
const EXPECTED_SOURCE_TREE = 'a0ed79cf460dc26918a7899ea863846a5c5ed0db3d17c1fa811e04feb2152740';

if ($fixtureDir === '' || !is_file($fixtureDir . '/manual_change_architect_sms_minimal_operations.php')
    || !is_file($fixtureDir . '/manual_change_architect_sms_amendment_readable.php')) {
    throw new RuntimeException('The reviewed package fixtures are required.');
}
if (!$preflightOnly && ($newVersionLabel === '' || $actorUserId <= 0
    || !hash_equals(APPROVED_PACKAGE_FINGERPRINT, $approval))) {
    throw new RuntimeException('Exact reviewed-package authorization, actor, and destination label are required.');
}

require_once $repoRoot . '/src/db.php';
require_once $repoRoot . '/src/publishing/ControlledPublishingFoundationService.php';
require_once $repoRoot . '/src/publishing/ControlledPublishingBlockService.php';
require_once $repoRoot . '/src/publishing/ControlledPublishingTocService.php';
require_once $repoRoot . '/src/publishing/ControlledPublishingRevisionService.php';
require_once $repoRoot . '/src/publishing/ControlledPublishingLivePageMapService.php';
require_once $repoRoot . '/src/publishing/BooksManualsWorkflowService.php';
require_once $repoRoot . '/src/publishing/BooksManualsAuditService.php';
if (!function_exists('h')) {
    function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

$minimalPlan = require $fixtureDir . '/manual_change_architect_sms_minimal_operations.php';
$amendment = require $fixtureDir . '/manual_change_architect_sms_amendment_readable.php';
$pdo = cw_db();
$foundation = new ControlledPublishingFoundationService($pdo);
$blocks = new ControlledPublishingBlockService($pdo);

$targetDefinitions = array(
    '3.3' => array('section_id' => 58640, 'from' => 110, 'to' => 250),
    '4.2' => array('section_id' => 58643, 'from' => 40, 'to' => 90),
    '5.6' => array('section_id' => 58646, 'from' => 1220, 'to' => 1460),
    '5.7' => array('section_id' => 58646, 'from' => 1470, 'to' => 1720),
    '8.1' => array('section_id' => 59377, 'from' => 30, 'to' => 50),
);

/** @return array<string,mixed> */
function versionIdentity(PDO $pdo, int $versionId): array
{
    $stmt = $pdo->prepare(
        'SELECT id,book_id,version_label,title,lifecycle_status,supersedes_version_id
         FROM ipca_publishing_book_versions WHERE id=? LIMIT 1'
    );
    $stmt->execute(array($versionId));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException("Version {$versionId} not found.");
    }
    return array(
        'book_id' => (int)$row['book_id'],
        'book_version_id' => (int)$row['id'],
        'version_label' => (string)$row['version_label'],
        'title' => (string)$row['title'],
        'lifecycle_status' => (string)$row['lifecycle_status'],
        'supersedes_version_id' => $row['supersedes_version_id'] === null
            ? null : (int)$row['supersedes_version_id'],
    );
}

/** @return array<string,mixed> */
function sourceSnapshot(PDO $pdo, int $versionId, array $targets): array
{
    $manual = versionIdentity($pdo, $versionId);
    unset($manual['supersedes_version_id']);
    $ranges = array();
    $details = array();
    foreach ($targets as $number => $target) {
        $stmt = $pdo->prepare(
            'SELECT id,sort_order,content_hash,stable_anchor,block_key
             FROM ipca_publishing_book_blocks
             WHERE book_version_id=? AND section_id=? AND sort_order BETWEEN ? AND ?
             ORDER BY sort_order,id'
        );
        $stmt->execute(array($versionId, $target['section_id'], $target['from'], $target['to']));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
        $fingerprint = hash('sha256', json_encode(
            array_map(
                static fn(array $row): array => array(
                    (int)$row['id'], (int)$row['sort_order'], (string)$row['content_hash'],
                ),
                $rows
            ),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
        $ranges[$number] = $fingerprint;
        $details[$number] = array('fingerprint' => $fingerprint, 'rows' => $rows);
    }
    $snapshot = hash('sha256', json_encode(
        array('manual' => $manual, 'ranges' => $ranges),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ));
    return array('fingerprint' => $snapshot, 'ranges' => $details, 'manual' => $manual);
}

function textFromPayload(array $payload): string
{
    return trim(preg_replace(
        '/\s+/u',
        ' ',
        html_entity_decode(strip_tags((string)($payload['html'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')
    ) ?? '');
}

/** @return array{fingerprint:string,headings:list<array<string,mixed>>} */
function sourceTreeFingerprint(PDO $pdo, int $versionId, int $sectionId, int $expectedHeadingCount = 7): array
{
    $stmt = $pdo->prepare(
        'SELECT id,sort_order,payload_json FROM ipca_publishing_book_blocks
         WHERE book_version_id=? AND section_id=? AND sort_order BETWEEN 1220 AND 1460
         ORDER BY sort_order,id'
    );
    $stmt->execute(array($versionId, $sectionId));
    $headings = array();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
        $payload = json_decode((string)$row['payload_json'], true);
        $payload = is_array($payload) ? $payload : array();
        $style = (string)($payload['paragraph_style'] ?? '');
        if (!in_array($style, array('subtitle_1', 'subtitle_2'), true)) {
            continue;
        }
        $headings[] = array(
            'id' => (int)$row['id'],
            'sort' => (int)$row['sort_order'],
            'style' => $style,
            'title' => textFromPayload($payload),
        );
    }
    if (count($headings) !== $expectedHeadingCount) {
        throw new RuntimeException('Canonical Section 5.6 does not contain the expected source hierarchy.');
    }
    $children = array();
    foreach (array_slice($headings, 1) as $index => $heading) {
        $children[] = array(
            'node_key' => 'current-5-6-' . ($index + 1),
            'number' => '5.6.' . ($index + 1),
            'title' => $heading['title'],
            'node_type' => 'section',
            'purpose' => '',
            'action' => 'PRESERVE',
            'source_references' => array(),
            'children' => array(),
            'sort_order' => $index,
        );
    }
    $tree = array(array(
        'node_key' => 'current-5-6',
        'number' => '5.6',
        'title' => $headings[0]['title'],
        'node_type' => 'section',
        'purpose' => '',
        'action' => 'PRESERVE',
        'source_references' => array(),
        'children' => $children,
        'sort_order' => 0,
    ));
    return array(
        'fingerprint' => hash('sha256', json_encode(
            $tree,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        )),
        'headings' => $headings,
    );
}

function assertSourcePreconditions(PDO $pdo, int $versionId, array $targets): array
{
    $snapshot = sourceSnapshot($pdo, $versionId, $targets);
    if (!hash_equals(EXPECTED_SOURCE_SNAPSHOT, $snapshot['fingerprint'])) {
        throw new RuntimeException(
            'Source snapshot mismatch: expected ' . EXPECTED_SOURCE_SNAPSHOT
            . ', got ' . $snapshot['fingerprint']
        );
    }
    $tree = sourceTreeFingerprint($pdo, $versionId, (int)$targets['5.6']['section_id']);
    if (!hash_equals(EXPECTED_SOURCE_TREE, $tree['fingerprint'])) {
        throw new RuntimeException(
            'Section 5.6 source-tree mismatch: expected ' . EXPECTED_SOURCE_TREE
            . ', got ' . $tree['fingerprint']
        );
    }
    return array('snapshot' => $snapshot, 'tree' => $tree);
}

/** @return array<int,int> */
function clonedSectionMap(PDO $pdo, int $sourceId, int $destinationId, array $sourceSectionIds): array
{
    $placeholders = implode(',', array_fill(0, count($sourceSectionIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT source.id source_id,destination.id destination_id
         FROM ipca_publishing_book_sections source
         JOIN ipca_publishing_book_sections destination
           ON destination.book_version_id=? AND destination.section_key=source.section_key
         WHERE source.book_version_id=? AND source.id IN ({$placeholders})"
    );
    $stmt->execute(array_merge(array($destinationId, $sourceId), $sourceSectionIds));
    $map = array();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
        $map[(int)$row['source_id']] = (int)$row['destination_id'];
    }
    if (count($map) !== count(array_unique($sourceSectionIds))) {
        throw new RuntimeException('The cloned section map is incomplete.');
    }
    return $map;
}

/** @return array<int,int> */
function clonedBlockMap(PDO $pdo, int $sourceId, int $destinationId, array $sourceBlockIds): array
{
    $placeholders = implode(',', array_fill(0, count($sourceBlockIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT source.id source_id,destination.id destination_id
         FROM ipca_publishing_book_blocks source
         JOIN ipca_publishing_book_blocks destination
           ON destination.book_version_id=? AND destination.block_key=source.block_key
         WHERE source.book_version_id=? AND source.id IN ({$placeholders})"
    );
    $stmt->execute(array_merge(array($destinationId, $sourceId), $sourceBlockIds));
    $map = array();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
        $map[(int)$row['source_id']] = (int)$row['destination_id'];
    }
    if (count($map) !== count($sourceBlockIds)) {
        throw new RuntimeException('The cloned block map is incomplete.');
    }
    return $map;
}

/** @return array<int,array<string,mixed>> */
function loadBlocks(PDO $pdo, array $ids): array
{
    if ($ids === array()) {
        return array();
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM ipca_publishing_book_blocks WHERE id IN ('
        . implode(',', array_fill(0, count($ids), '?')) . ')'
    );
    $stmt->execute(array_values($ids));
    $rows = array();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
        $rows[(int)$row['id']] = $row;
    }
    return $rows;
}

function htmlFromText(string $text): string
{
    $lines = preg_split('/\R/u', trim($text)) ?: array();
    $html = '';
    $paragraph = array();
    $list = array();
    $flushParagraph = static function () use (&$html, &$paragraph): void {
        if ($paragraph !== array()) {
            $html .= '<p>' . htmlspecialchars(implode(' ', $paragraph), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>';
            $paragraph = array();
        }
    };
    $flushList = static function () use (&$html, &$list): void {
        if ($list !== array()) {
            $html .= '<ul>';
            foreach ($list as $item) {
                $html .= '<li>' . htmlspecialchars($item, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</li>';
            }
            $html .= '</ul>';
            $list = array();
        }
    };
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            $flushParagraph();
            $flushList();
            continue;
        }
        if (str_starts_with($line, '• ')) {
            $flushParagraph();
            $list[] = mb_substr($line, 2);
            continue;
        }
        $flushList();
        $paragraph[] = $line;
    }
    $flushParagraph();
    $flushList();
    return $html;
}

/** @return array<string,mixed> */
function paragraphPayload(string $text, string $style = 'body'): array
{
    return array(
        'html' => htmlFromText($text),
        'text_align' => 'left',
        'indent_level' => 0,
        'paragraph_style' => $style,
    );
}

function updateNewSort(PDO $pdo, int $blockId, int $sortOrder, int $actorUserId): void
{
    $stmt = $pdo->prepare(
        'UPDATE ipca_publishing_book_blocks
         SET sort_order=?,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE id=?'
    );
    $stmt->execute(array($sortOrder, $actorUserId, $blockId));
}

/** @return list<int> */
function insertParagraphs(
    PDO $pdo,
    ControlledPublishingBlockService $blocks,
    int $versionId,
    int $sectionId,
    array $texts,
    int $firstSort,
    int $actorUserId
): array {
    $ids = array();
    foreach (array_values($texts) as $index => $text) {
        $id = $blocks->createBlock(
            $versionId,
            $sectionId,
            'paragraph',
            paragraphPayload((string)$text),
            $actorUserId
        );
        updateNewSort($pdo, $id, $firstSort + ($index * 5), $actorUserId);
        $ids[] = $id;
    }
    return $ids;
}

function updateBlockTypeAndPayload(
    PDO $pdo,
    int $blockId,
    string $blockType,
    array $payload,
    int $actorUserId
): void {
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $hash = hash('sha256', $blockType . '|' . $payloadJson);
    $stmt = $pdo->prepare(
        'UPDATE ipca_publishing_book_blocks
         SET block_type=?,payload_json=?,content_hash=?,updated_by=?,updated_at=CURRENT_TIMESTAMP
         WHERE id=?'
    );
    $stmt->execute(array($blockType, $payloadJson, $hash, $actorUserId, $blockId));
}

function verifyPreservedBlocks(array $baseline, array $after): array
{
    $fields = array(
        'id', 'block_key', 'stable_anchor', 'block_type', 'sort_order',
        'payload_json', 'content_hash', 'is_system_managed',
    );
    $failures = array();
    foreach ($baseline as $id => $before) {
        if (!isset($after[$id])) {
            $failures[] = "Preserved block {$id} was removed.";
            continue;
        }
        foreach ($fields as $field) {
            if ((string)($before[$field] ?? '') !== (string)($after[$id][$field] ?? '')) {
                $failures[] = "Preserved block {$id} changed {$field}.";
            }
        }
    }
    return array('verified' => $failures === array(), 'failures' => $failures, 'count' => count($baseline));
}

$preflight = assertSourcePreconditions($pdo, $sourceVersionId, $targetDefinitions);
if ($preflightOnly) {
    echo json_encode(array(
        'status' => 'PREFLIGHT_OK',
        'source_version_id' => $sourceVersionId,
        'source_snapshot_fingerprint' => $preflight['snapshot']['fingerprint'],
        'source_tree_fingerprint' => $preflight['tree']['fingerprint'],
        'reviewed_package_fingerprint' => APPROVED_PACKAGE_FINGERPRINT,
        'mutated' => false,
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

$existing = $pdo->prepare(
    'SELECT id FROM ipca_publishing_book_versions WHERE book_id=2 AND version_label=? LIMIT 1'
);
$existing->execute(array($newVersionLabel));
if ($existing->fetchColumn()) {
    throw new RuntimeException("Destination revision {$newVersionLabel} already exists.");
}

$clone = $foundation->createNextDraftVersion($sourceVersionId, $newVersionLabel, $actorUserId);
$destinationVersionId = (int)$clone['version_id'];
$operationResults = array();
$generated56 = array();
$transactionResult = 'not_started';

try {
    $sourceBlockIds = array();
    foreach ($preflight['snapshot']['ranges'] as $range) {
        foreach ($range['rows'] as $row) {
            $sourceBlockIds[] = (int)$row['id'];
        }
    }
    $sourceBlockIds = array_values(array_unique($sourceBlockIds));
    $sectionMap = clonedSectionMap(
        $pdo,
        $sourceVersionId,
        $destinationVersionId,
        array_values(array_unique(array_column($targetDefinitions, 'section_id')))
    );
    $blockMap = clonedBlockMap($pdo, $sourceVersionId, $destinationVersionId, $sourceBlockIds);

    $preservedSourceIds = array_merge(
        range(34015, 34027),
        range(34073, 34077),
        range(34224, 34249),
        array(34332, 34333)
    );
    $preservedCloneIds = array_map(
        static fn(int $sourceId): int => $blockMap[$sourceId],
        $preservedSourceIds
    );
    $preservedBaseline = loadBlocks($pdo, $preservedCloneIds);

    $pdo->beginTransaction();
    $lock = $pdo->prepare('SELECT id FROM ipca_publishing_book_versions WHERE id=? FOR UPDATE');
    $lock->execute(array($sourceVersionId));
    assertSourcePreconditions($pdo, $sourceVersionId, $targetDefinitions);

    $section33 = $sectionMap[58640];
    $section42 = $sectionMap[58643];
    $section56 = $sectionMap[58646];
    $section81 = $sectionMap[59377];

    $ids = insertParagraphs(
        $pdo,
        $blocks,
        $destinationVersionId,
        $section33,
        array($minimalPlan['operations'][0]['new_blocks'][0]['content']),
        195,
        $actorUserId
    );
    $operationResults[] = array('sequence' => 1, 'type' => 'INSERT_BLOCK', 'status' => 'success', 'destination_block_ids' => $ids);

    $listPayload = array(
        'items' => array_values($minimalPlan['operations'][1]['replacement']['items']),
        'ordered' => false,
        'text_align' => 'left',
        'indent_level' => 0,
        'start_number' => 1,
        'paragraph_style' => 'body',
        'continuation_html' => '',
        'continuation_after' => count($minimalPlan['operations'][1]['replacement']['items']),
        'item_indent_levels' => array_fill(0, count($minimalPlan['operations'][1]['replacement']['items']), 0),
    );
    updateBlockTypeAndPayload($pdo, $blockMap[34028], 'list', $listPayload, $actorUserId);
    $operationResults[] = array('sequence' => 2, 'type' => 'REPLACE_BLOCK', 'status' => 'success', 'destination_block_id' => $blockMap[34028]);

    $blocks->updateBlock(
        $blockMap[34029],
        paragraphPayload((string)$minimalPlan['operations'][2]['replacement']['content']),
        $actorUserId
    );
    $operationResults[] = array('sequence' => 3, 'type' => 'REPLACE_BLOCK', 'status' => 'success', 'destination_block_id' => $blockMap[34029]);

    $recordTexts = array_map(
        static fn(array $block): string => (string)$block['content'],
        $minimalPlan['operations'][3]['new_blocks']
    );
    $ids = insertParagraphs(
        $pdo,
        $blocks,
        $destinationVersionId,
        $section42,
        $recordTexts,
        100,
        $actorUserId
    );
    $operationResults[] = array('sequence' => 4, 'type' => 'INSERT_BLOCKS', 'status' => 'success', 'destination_block_ids' => $ids);

    foreach (range(34199, 34223) as $sourceBlockId) {
        $blocks->deleteBlock($blockMap[$sourceBlockId], $actorUserId);
    }
    $titles56 = array(
        '5.6' => 'Occurrence Reporting and Internal Safety Investigation',
        '5.6.1' => 'Purpose, Scope and Reporting Principles',
        '5.6.2' => 'Initial Occurrence Reporting',
        '5.6.3' => 'Triage, Reportability and Reporting Deadlines',
        '5.6.4' => 'Initial ECCAIRS Notification',
        '5.6.5' => 'Internal Safety Investigation',
        '5.6.6' => 'Corrective and Mitigating Actions',
        '5.6.7' => 'Intermediate and Final ECCAIRS Follow-up',
        '5.6.8' => 'Monitoring and Escalation',
        '5.6.9' => 'Controlled Closure',
    );
    $sort = 1220;
    foreach ($amendment['section_drafts']['5.6']['nodes'] as $number => $content) {
        $style = $number === '5.6' ? 'subtitle_1' : 'subtitle_2';
        $headingId = $blocks->createBlock(
            $destinationVersionId,
            $section56,
            'paragraph',
            paragraphPayload($titles56[$number], $style),
            $actorUserId
        );
        updateNewSort($pdo, $headingId, $sort, $actorUserId);
        $sort += 10;
        $bodyId = $blocks->createBlock(
            $destinationVersionId,
            $section56,
            'paragraph',
            paragraphPayload((string)$content),
            $actorUserId
        );
        updateNewSort($pdo, $bodyId, $sort, $actorUserId);
        $sort += 10;
        $heading = $blocks->getBlock($headingId);
        $body = $blocks->getBlock($bodyId);
        $generated56[$number] = array(
            'heading' => array('id' => $headingId, 'stable_anchor' => $heading['stable_anchor'] ?? ''),
            'body' => array('id' => $bodyId, 'stable_anchor' => $body['stable_anchor'] ?? ''),
        );
    }
    $operationResults[] = array(
        'sequence' => 5,
        'type' => 'RESTRUCTURE_SECTION_WITH_CONTENT',
        'status' => 'success',
        'atomic' => true,
        'removed_block_count' => 25,
        'generated_block_count' => 20,
        'destination_objects' => $generated56,
    );

    $ids = insertParagraphs(
        $pdo,
        $blocks,
        $destinationVersionId,
        $section56,
        array($minimalPlan['operations'][5]['new_blocks'][0]['content']),
        1675,
        $actorUserId
    );
    $operationResults[] = array('sequence' => 6, 'type' => 'INSERT_BLOCK', 'status' => 'success', 'destination_block_ids' => $ids);

    $ids = insertParagraphs(
        $pdo,
        $blocks,
        $destinationVersionId,
        $section81,
        array($minimalPlan['operations'][7]['new_blocks'][0]['content']),
        60,
        $actorUserId
    );
    $operationResults[] = array(
        'sequence' => 7,
        'type' => 'INSERT_BLOCK',
        'status' => 'success',
        'destination_block_ids' => $ids,
        'anchor_table_id_before_update' => $blockMap[34334],
    );

    $tableId = $blockMap[34334];
    $tableBefore = $blocks->getBlock($tableId);
    $tableAnchorBefore = (string)($tableBefore['stable_anchor'] ?? '');
    $tablePayload = json_decode((string)($tableBefore['payload_json'] ?? '{}'), true);
    $tablePayload = is_array($tablePayload) ? $tablePayload : array();
    $rows = array_values((array)($tablePayload['rows'] ?? array()));
    $replaceFound = false;
    $insertAt = null;
    foreach ($rows as $index => $row) {
        if ((string)($row[0] ?? '') === 'Occurence and hazards reporting') {
            if ((string)($row[1] ?? '') !== 'Know the means and procedures for reporting occurrences and hazards.') {
                throw new RuntimeException('The Section 8.1 occurrence-training row changed before UPDATE_TABLE.');
            }
            $rows[$index] = $minimalPlan['operations'][6]['table_patch']['replace_row']['new_row'];
            $replaceFound = true;
        }
        if ((string)($row[0] ?? '') === 'Safety Risk Management (SRM) process including roles and responsibilities') {
            $insertAt = $index + 1;
        }
    }
    if (!$replaceFound || $insertAt === null) {
        throw new RuntimeException('The Section 8.1 table patch anchors were not found.');
    }
    array_splice($rows, $insertAt, 0, array($minimalPlan['operations'][6]['table_patch']['new_row']));
    $tablePayload['rows'] = $rows;
    $blocks->updateBlock($tableId, $tablePayload, $actorUserId);
    $tableAfter = $blocks->getBlock($tableId);
    if ((int)$tableAfter['id'] !== $tableId
        || (string)$tableAfter['stable_anchor'] !== $tableAnchorBefore) {
        throw new RuntimeException('UPDATE_TABLE did not preserve the table identity and stable anchor.');
    }
    $operationResults[] = array(
        'sequence' => 8,
        'type' => 'UPDATE_TABLE',
        'status' => 'success',
        'destination_block_id' => $tableId,
        'identity_preserved' => true,
        'stable_anchor_preserved' => true,
    );

    $preservedAfter = loadBlocks($pdo, $preservedCloneIds);
    $preservedInvariant = verifyPreservedBlocks($preservedBaseline, $preservedAfter);
    if (!$preservedInvariant['verified']) {
        throw new RuntimeException(implode('; ', $preservedInvariant['failures']));
    }
    $pdo->commit();
    $transactionResult = 'committed';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $transactionResult = 'rolled_back';
    throw new RuntimeException(
        "Package application failed; mutation transaction rolled back for clone {$destinationVersionId}: "
        . $e->getMessage(),
        0,
        $e
    );
}

$refresh = array();
$refreshErrors = array();
try {
    $refresh['toc'] = (new ControlledPublishingTocService($pdo, $blocks))
        ->regenerateTocSection($destinationVersionId, $actorUserId);
} catch (Throwable $e) {
    $refreshErrors['toc'] = $e->getMessage();
}
try {
    $refresh['revision_highlights'] = (new ControlledPublishingRevisionService($pdo))
        ->regenerateHighlightsSection($destinationVersionId, $actorUserId);
} catch (Throwable $e) {
    $refreshErrors['revision_highlights'] = $e->getMessage();
}
try {
    $refresh['update_identity'] = (new BooksManualsWorkflowService($pdo))
        ->syncUpdateIdentity($destinationVersionId);
} catch (Throwable $e) {
    $refreshErrors['update_identity'] = $e->getMessage();
}
try {
    $refresh['mccf_integrity'] = (new BooksManualsAuditService($pdo, $foundation))
        ->startIntegrityRefresh($destinationVersionId, $actorUserId);
} catch (Throwable $e) {
    $refreshErrors['mccf_integrity'] = $e->getMessage();
}
try {
    $live = new ControlledPublishingLivePageMapService($pdo);
    $refresh['pagination_request'] = $live->ensure(
        $destinationVersionId,
        $actorUserId,
        null,
        array('mutation_kind' => 'manual_change_architect_apply', 'layout_impact' => 'global')
    );
    $refresh['pagination_work'] = $live->workOne($destinationVersionId, 'LETTER_READER_v1');
    $refresh['pagination_status'] = $live->status($destinationVersionId, 'LETTER_READER_v1');
} catch (Throwable $e) {
    $refreshErrors['pagination'] = $e->getMessage();
}

$sourceAfter = assertSourcePreconditions($pdo, $sourceVersionId, $targetDefinitions);
$destination = versionIdentity($pdo, $destinationVersionId);
$sectionMapAfter = clonedSectionMap(
    $pdo,
    $sourceVersionId,
    $destinationVersionId,
    array_values(array_unique(array_column($targetDefinitions, 'section_id')))
);
$section56Id = $sectionMapAfter[58646];
$destinationTree = sourceTreeFingerprint($pdo, $destinationVersionId, $section56Id, 10);

$scopeSectionIds = array_values($sectionMapAfter);
$stmt = $pdo->prepare(
    'SELECT id,section_id,block_key,stable_anchor,block_type,sort_order,payload_json,content_hash
     FROM ipca_publishing_book_blocks
     WHERE book_version_id=? AND section_id IN (' . implode(',', array_fill(0, count($scopeSectionIds), '?')) . ')
     ORDER BY section_id,sort_order,id'
);
$stmt->execute(array_merge(array($destinationVersionId), $scopeSectionIds));
$scopeBlocks = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
$amendmentScopeBlocks = array_values(array_filter(
    $scopeBlocks,
    static function (array $row) use ($sectionMapAfter): bool {
        $sectionId = (int)$row['section_id'];
        $sort = (int)$row['sort_order'];
        return ($sectionId === $sectionMapAfter[58640] && $sort >= 110 && $sort <= 250)
            || ($sectionId === $sectionMapAfter[58643] && $sort >= 40 && $sort <= 110)
            || ($sectionId === $sectionMapAfter[58646]
                && (($sort >= 1220 && $sort <= 1410) || ($sort >= 1470 && $sort <= 1720)))
            || ($sectionId === $sectionMapAfter[59377] && $sort >= 30 && $sort <= 60);
    }
));
$postApplyFingerprint = hash('sha256', json_encode(
    $amendmentScopeBlocks,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
));
$scopeText = '';
foreach ($amendmentScopeBlocks as $row) {
    $scopeText .= "\n" . (string)$row['payload_json'];
}
$legacyMatches = array();
preg_match_all(
    '/\b(?:Pipedrive|Online Safety Management System|E-Occurrence Reporting System|E-OR)\b|(?:sms|safety)\.europilotcenter\.be|aviationreporting\.eu/iu',
    $scopeText,
    $legacyMatches
);
$changePlanTerms = preg_match(
    '/\b(?:REVIEW_SEPARATELY|PRESERVE_WITH_JUSTIFICATION|accepted scope|legacy disposition|Change Plan)\b/iu',
    $scopeText
) === 1;
$controlChecks = array(
    'automation_limitation' => str_contains($scopeText, 'automated intermediate and final ECCAIRS amendment functionality is not operational')
        || str_contains($scopeText, 'Automated intermediate and final ECCAIRS updates are not operational'),
    'initial_72_hours' => str_contains($scopeText, 'not later than 72 hours'),
    'conditional_30_days' => str_contains($scopeText, 'actual or potential aviation safety risk')
        && str_contains($scopeText, 'within 30 days from the date of notification'),
    'final_three_months' => str_contains($scopeText, 'not later than three months from the date of notification'),
);

$tocTextStmt = $pdo->prepare(
    "SELECT payload_json
     FROM ipca_publishing_book_blocks b
     JOIN ipca_publishing_book_sections s ON s.id=b.section_id
     WHERE s.book_version_id=? AND s.section_key='toc'
     ORDER BY b.sort_order,b.id"
);
$tocTextStmt->execute(array($destinationVersionId));
$tocText = implode('', array_map(
    'strval',
    $tocTextStmt->fetchAll(PDO::FETCH_COLUMN) ?: array()
));
$tocChecks = array();
foreach (array_keys($titles56) as $number) {
    $tocChecks[$number] = str_contains($tocText, $titles56[$number]);
}

$allDestinationBlocks = $blocks->listSectionBlocks($section56Id);
$annotated = (new ControlledPublishingRevisionService($pdo))
    ->annotateChangeStatus($destinationVersionId, $allDestinationBlocks);
$changedAnchors = array();
foreach ($annotated as $row) {
    if ((string)($row['change_status'] ?? 'unchanged') !== 'unchanged') {
        $changedAnchors[] = (string)$row['stable_anchor'];
    }
}
$preservedChanged = array_values(array_intersect(
    $changedAnchors,
    array_map(static fn(array $row): string => (string)$row['stable_anchor'], $preservedBaseline)
));

$reviewIssues = array();
if ($transactionResult !== 'committed') {
    $reviewIssues[] = 'Mutation transaction was not committed.';
}
if (!$preservedInvariant['verified']) {
    $reviewIssues[] = 'Preserved-block invariant failed.';
}
if ($destination['supersedes_version_id'] !== $sourceVersionId) {
    $reviewIssues[] = 'Destination source-parent relationship is incorrect.';
}
if ($sourceAfter['snapshot']['fingerprint'] !== EXPECTED_SOURCE_SNAPSHOT) {
    $reviewIssues[] = 'Source version changed.';
}
if (array_values(array_filter($tocChecks, static fn(bool $ok): bool => !$ok)) !== array()) {
    $reviewIssues[] = 'TOC does not contain every accepted Section 5.6 heading.';
}
if ($legacyMatches[0] !== array()) {
    $reviewIssues[] = 'Legacy occurrence-workflow references remain in amendment scope.';
}
if ($changePlanTerms) {
    $reviewIssues[] = 'Change Plan terminology entered canonical content.';
}
if (in_array(false, $controlChecks, true)) {
    $reviewIssues[] = 'A required regulatory or transition control is missing.';
}
if ($preservedChanged !== array()) {
    $reviewIssues[] = 'Revision highlighting marked preserved target blocks as changed.';
}
if ($refreshErrors !== array()) {
    $reviewIssues[] = 'One or more derived-artifact refreshes failed.';
}

$report = array(
    'milestone' => 'The reviewed amendment package has been safely and exactly applied to a new controlled working revision, while the source revision remains untouched.',
    'reviewed_package_fingerprint' => APPROVED_PACKAGE_FINGERPRINT,
    'source_revision_id' => $sourceVersionId,
    'source_revision_label' => $preflight['snapshot']['manual']['version_label'],
    'working_revision_id' => $destinationVersionId,
    'working_revision_label' => $destination['version_label'],
    'working_revision_status' => $destination['lifecycle_status'],
    'supersedes_version_id' => $destination['supersedes_version_id'],
    'transaction_result' => $transactionResult,
    'operation_results' => $operationResults,
    'preserved_block_invariant' => $preservedInvariant,
    'source_revision_unchanged' => array(
        'verified' => $sourceAfter['snapshot']['fingerprint'] === EXPECTED_SOURCE_SNAPSHOT,
        'before_fingerprint' => $preflight['snapshot']['fingerprint'],
        'after_fingerprint' => $sourceAfter['snapshot']['fingerprint'],
    ),
    'section_5_6_tree' => $destinationTree['headings'],
    'section_5_6_generated_objects' => $generated56,
    'post_apply_content_fingerprint' => $postApplyFingerprint,
    'reviewed_virtual_destination_fingerprint' => '631d627a16464f41b3bcacf83582ff42389baa531d22e20dce19a0bd2e2a063d',
    'fingerprint_equivalence' => array(
        'exact_match_expected' => false,
        'reason' => 'The reviewed virtual fingerprint represents semantic operations and source references; the post-apply fingerprint includes database IDs, durable anchors, normalized payload JSON, generated TOC/highlight metadata, and clone-specific serialization.',
        'semantic_controls' => $controlChecks,
    ),
    'toc_cross_reference_result' => array(
        'toc' => $refresh['toc'] ?? null,
        'all_5_6_headings_present' => !in_array(false, $tocChecks, true),
        'heading_checks' => $tocChecks,
    ),
    'pagination_integrity_result' => array(
        'pagination' => $refresh['pagination_status'] ?? null,
        'mccf_integrity' => $refresh['mccf_integrity'] ?? null,
        'errors' => $refreshErrors,
    ),
    'revision_highlight_result' => array(
        'refresh' => $refresh['revision_highlights'] ?? null,
        'changed_anchor_count_in_5_6' => count($changedAnchors),
        'preserved_target_anchors_incorrectly_changed' => $preservedChanged,
    ),
    'legacy_references_in_amendment_scope' => array_values(array_unique($legacyMatches[0] ?? array())),
    'change_plan_terms_in_canonical_content' => $changePlanTerms,
    'independent_reviewer' => array(
        'status' => $reviewIssues === array() ? 'READY_FOR_HUMAN_REVIEW' : 'REQUIRES_REVIEW',
        'issues' => $reviewIssues,
        'actual_working_revision_reviewed' => true,
        'finalized' => false,
        'approved' => false,
        'published' => false,
    ),
);

echo json_encode(
    $report,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
), "\n";
