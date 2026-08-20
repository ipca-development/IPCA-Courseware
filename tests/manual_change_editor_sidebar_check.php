<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/publishing/BooksManualsChangePlanService.php';
require_once dirname(__DIR__) . '/src/publishing/BooksManualsChangeApplyService.php';

/** @param mixed $condition */
function wizardEditorAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(
    'CREATE TABLE ipca_manual_ai_architect_plans (
        id INTEGER PRIMARY KEY,
        title TEXT NOT NULL,
        owner_id INTEGER NOT NULL
    )'
);
$db->exec(
    'CREATE TABLE ipca_manual_ai_architect_operations (
        id INTEGER PRIMARY KEY,
        plan_id INTEGER NOT NULL,
        operation_type TEXT NOT NULL,
        status TEXT NOT NULL,
        operation_payload_json TEXT,
        result_json TEXT,
        completed_at TEXT
    )'
);
$db->exec(
    'CREATE TABLE ipca_publishing_book_versions (
        id INTEGER PRIMARY KEY,
        lifecycle_status TEXT NOT NULL
    )'
);
$db->exec(
    'CREATE TABLE ipca_publishing_book_sections (
        id INTEGER PRIMARY KEY,
        book_version_id INTEGER NOT NULL,
        title TEXT NOT NULL
    )'
);
$db->exec(
    'CREATE TABLE ipca_publishing_book_blocks (
        id INTEGER PRIMARY KEY,
        book_version_id INTEGER NOT NULL,
        section_id INTEGER NOT NULL,
        block_key TEXT NOT NULL,
        stable_anchor TEXT NOT NULL,
        block_type TEXT NOT NULL,
        sort_order INTEGER NOT NULL,
        payload_json TEXT NOT NULL,
        content_hash TEXT NOT NULL,
        is_system_managed INTEGER NOT NULL DEFAULT 0
    )'
);

$row = array(
    'id' => 501,
    'book_version_id' => 7,
    'section_id' => 11,
    'block_key' => 'WIZ-20-8-1-BODY',
    'stable_anchor' => 'WIZ-20-8-1-BODY',
    'block_type' => 'paragraph',
    'sort_order' => 10,
    'payload_json' => '{"html":"<p>Accepted Wizard wording</p>"}',
    'content_hash' => hash('sha256', 'accepted'),
    'is_system_managed' => 0,
);
$fingerprint = hash('sha256', json_encode(array(array(
    501,
    'WIZ-20-8-1-BODY',
    'WIZ-20-8-1-BODY',
    'paragraph',
    10,
    '{"html":"<p>Accepted Wizard wording</p>"}',
    hash('sha256', 'accepted'),
    0,
)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
$beforeRow = $row;
$beforeRow['payload_json'] = '{"html":"<p>Original wording</p>"}';
$beforeRow['content_hash'] = hash('sha256', 'original');
$beforeFingerprint = hash('sha256', json_encode(array(array(
    501,
    'WIZ-20-8-1-BODY',
    'WIZ-20-8-1-BODY',
    'paragraph',
    10,
    '{"html":"<p>Original wording</p>"}',
    hash('sha256', 'original'),
    0,
)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

$db->exec(
    "INSERT INTO ipca_manual_ai_architect_plans (id,title,owner_id)
     VALUES (20,'Training update',25)"
);
$db->exec("INSERT INTO ipca_publishing_book_versions (id,lifecycle_status) VALUES (7,'draft')");
$db->exec(
    "INSERT INTO ipca_publishing_book_sections (id,book_version_id,title)
     VALUES (11,7,'Training')"
);
$insertBlock = $db->prepare(
    'INSERT INTO ipca_publishing_book_blocks
     (id,book_version_id,section_id,block_key,stable_anchor,block_type,sort_order,
      payload_json,content_hash,is_system_managed)
     VALUES (?,?,?,?,?,?,?,?,?,?)'
);
$insertBlock->execute(array_values($row));
$operationPayload = array(
    'target_contexts' => array(array(
        'section_number' => '8.1',
        'section_id' => 11,
        'context_key' => 'section-11',
    )),
);
$operationResult = array(
    'book_version_id' => 7,
    'applied_at' => '2026-08-20T12:00:00Z',
    'before_sections' => array(
        11 => array('section_id' => 11, 'rows' => array($beforeRow), 'fingerprint' => $beforeFingerprint),
    ),
    'after_sections' => array(
        11 => array('section_id' => 11, 'rows' => array($row), 'fingerprint' => $fingerprint),
    ),
    'review_guidance' => array(array(
        'title' => 'Corrective action competence',
        'advisory' => true,
        'editor_action_required' => false,
    )),
);
$insertOperation = $db->prepare(
    'INSERT INTO ipca_manual_ai_architect_operations
     (id,plan_id,operation_type,status,operation_payload_json,result_json,completed_at)
     VALUES (1,20,\'apply_accepted_wizard_changes\',\'succeeded\',?,?,?)'
);
$insertOperation->execute(array(
    json_encode($operationPayload, JSON_THROW_ON_ERROR),
    json_encode($operationResult, JSON_THROW_ON_ERROR),
    '2026-08-20 12:00:00',
));

$service = new BooksManualsChangeApplyService(
    $db,
    new BooksManualsChangePlanService($db)
);
$lockedSnapshots = new ReflectionMethod($service, 'snapshotsForSectionIds');
$sqliteLockedSnapshot = $lockedSnapshots->invoke($service, array(11), true);
wizardEditorAssert(
    (string)($sqliteLockedSnapshot[11]['fingerprint'] ?? '') === $fingerprint,
    'Locked section snapshot reads are not portable to the SQLite regression harness.'
);
$changes = $service->editorChanges(7);
$item = (array)($changes['items'][0] ?? array());
wizardEditorAssert(
    (string)($item['status'] ?? '') === 'APPLIED'
        && (array)($item['section_numbers'] ?? array()) === array('8.1')
        && (string)($item['title'] ?? '') === 'Training'
        && !empty($item['can_revert'])
        && str_contains((string)($item['original_preview'] ?? ''), 'Original wording')
        && str_contains((string)($item['applied_preview'] ?? ''), 'Accepted Wizard wording')
        && count((array)($changes['review_guidance'] ?? array())) === 1,
    'The Editor sidebar did not expose the applied section and governed review guidance.'
);
$ownerView = $service->editorChanges(7, 25);
$otherAdminView = $service->editorChanges(7, 99);
wizardEditorAssert(
    !empty($ownerView['items'][0]['can_revert'])
        && empty($otherAdminView['items'][0]['can_revert']),
    'The Editor exposed a Change Plan owner-only revert to another administrator.'
);

$db->exec(
    "UPDATE ipca_publishing_book_blocks
     SET payload_json='{\"html\":\"<p>Manually enhanced wording</p>\"}',
         content_hash='" . hash('sha256', 'manual') . "'
     WHERE id=501"
);
$modified = $service->editorChanges(7);
$modifiedItem = (array)($modified['items'][0] ?? array());
wizardEditorAssert(
    (string)($modifiedItem['status'] ?? '') === 'MANUALLY_EDITED'
        && !empty($modifiedItem['manual_edits_detected'])
        && !empty($modifiedItem['current_fingerprint']),
    'The Editor sidebar did not detect a later manual edit before revert.'
);

$manualRow = $row;
$manualRow['payload_json'] = '{"html":"<p>Manually enhanced wording</p>"}';
$manualRow['content_hash'] = hash('sha256', 'manual');
$manualFingerprint = hash('sha256', json_encode(array(array(
    501,
    'WIZ-20-8-1-BODY',
    'WIZ-20-8-1-BODY',
    'paragraph',
    10,
    $manualRow['payload_json'],
    $manualRow['content_hash'],
    0,
)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
$newerRow = $row;
$newerRow['payload_json'] = '{"html":"<p>Newer accepted Wizard wording</p>"}';
$newerRow['content_hash'] = hash('sha256', 'newer');
$newerFingerprint = hash('sha256', json_encode(array(array(
    501,
    'WIZ-20-8-1-BODY',
    'WIZ-20-8-1-BODY',
    'paragraph',
    10,
    $newerRow['payload_json'],
    $newerRow['content_hash'],
    0,
)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
$updateToNewer = $db->prepare(
    'UPDATE ipca_publishing_book_blocks
     SET payload_json=?,content_hash=? WHERE id=501'
);
$updateToNewer->execute(array($newerRow['payload_json'], $newerRow['content_hash']));
$newerResult = array(
    'book_version_id' => 7,
    'applied_at' => '2026-08-20T12:30:00Z',
    'before_sections' => array(
        11 => array('section_id' => 11, 'rows' => array($manualRow), 'fingerprint' => $manualFingerprint),
    ),
    'after_sections' => array(
        11 => array('section_id' => 11, 'rows' => array($newerRow), 'fingerprint' => $newerFingerprint),
    ),
    'review_guidance' => array(array(
        'title' => 'Corrective action competence',
        'advisory' => true,
        'editor_action_required' => false,
    )),
);
$insertNewerOperation = $db->prepare(
    'INSERT INTO ipca_manual_ai_architect_operations
     (id,plan_id,operation_type,status,operation_payload_json,result_json,completed_at)
     VALUES (2,20,\'apply_accepted_wizard_changes\',\'succeeded\',?,?,?)'
);
$insertNewerOperation->execute(array(
    json_encode($operationPayload, JSON_THROW_ON_ERROR),
    json_encode($newerResult, JSON_THROW_ON_ERROR),
    '2026-08-20 12:30:00',
));
$stacked = $service->editorChanges(7);
$stackedItems = (array)($stacked['items'] ?? array());
wizardEditorAssert(
    count($stackedItems) === 2
        && (int)($stackedItems[0]['operation_id'] ?? 0) === 2
        && (string)($stackedItems[0]['status'] ?? '') === 'APPLIED'
        && (int)($stackedItems[1]['operation_id'] ?? 0) === 1
        && (string)($stackedItems[1]['status'] ?? '') === 'SUPERSEDED'
        && count((array)($stacked['review_guidance'] ?? array())) === 1,
    'A later Wizard application erased or misclassified earlier section provenance.'
);

$db->prepare(
    'INSERT INTO ipca_manual_ai_architect_operations
     (id,plan_id,operation_type,status,operation_payload_json,result_json,completed_at)
     VALUES (3,20,\'revert_wizard_change_item\',\'succeeded\',?,\'{}\',?)'
)->execute(array(
    json_encode(array(
        'original_operation_id' => 2,
        'book_version_id' => 7,
        'section_id' => 11,
    ), JSON_THROW_ON_ERROR),
    '2026-08-20 12:35:00',
));
$updateToNewer->execute(array($manualRow['payload_json'], $manualRow['content_hash']));
$afterLatestRevert = $service->editorChanges(7);
$afterLatestRevertItems = (array)($afterLatestRevert['items'] ?? array());
wizardEditorAssert(
    count($afterLatestRevertItems) === 2
        && (int)($afterLatestRevertItems[0]['operation_id'] ?? 0) === 2
        && (string)($afterLatestRevertItems[0]['status'] ?? '') === 'REVERTED'
        && (int)($afterLatestRevertItems[1]['operation_id'] ?? 0) === 1
        && (string)($afterLatestRevertItems[1]['status'] ?? '') === 'MANUALLY_EDITED',
    'Reverting the newest item incorrectly left the older Wizard application superseded.'
);
$db->exec("UPDATE ipca_publishing_book_versions SET lifecycle_status='released' WHERE id=7");
$replayedRevert = $service->revertEditorChange(7, 2, 11, 25);
wizardEditorAssert(
    is_array($replayedRevert)
        && array_key_exists('derived_refresh_warnings', $replayedRevert),
    'A successful per-section revert was not replayable after the version lifecycle advanced.'
);

$editorJs = file_get_contents(dirname(__DIR__) . '/public/assets/controlled_book_editor.js') ?: '';
$editorApi = file_get_contents(
    dirname(__DIR__) . '/public/admin/api/controlled_book_editor_api.php'
) ?: '';
$editorPage = file_get_contents(
    dirname(__DIR__) . '/public/admin/compliance/controlled_book_editor.php'
) ?: '';
$applyService = file_get_contents(
    dirname(__DIR__) . '/src/publishing/BooksManualsChangeApplyService.php'
) ?: '';
wizardEditorAssert(
    str_contains($editorJs, 'requires_confirmation')
        && str_contains($editorJs, 'expected_current_fingerprint')
        && str_contains($editorJs, "if (firstSuccessfulLoad) setSidebarTab('changes')")
        && str_contains($editorApi, "'requires_confirmation'")
        && str_contains($editorApi, "'current_fingerprint'")
        && str_contains($applyService, 'Only the Change Plan owner can revert')
        && str_contains(
            $applyService,
            'This Wizard change was superseded by a later application to the same section.'
        )
        && str_contains($applyService, '$lockedPreflightPackage = $this->buildPreflightPackage($planId, true);')
        && strpos($applyService, 'A successful operation is the authoritative idempotent response.')
            < strpos($applyService, '$draft = $this->latestRow(')
        && strpos($applyService, '$existingStmt = $this->pdo->prepare(')
            < strpos($applyService, '$newerApplications = $this->pdo->prepare(')
        && str_contains($editorJs, 'function trackBlockSave(')
        && str_contains($editorJs, 'return flushAllPendingSaves().then(function () {')
        && str_contains($editorJs, 'function withEditorMutationLock(')
        && str_contains($editorJs, "root.setAttribute('inert', '')")
        && str_contains($editorJs, 'function hasPendingSaveWork(')
        && str_contains($editorJs, 'return flushAllPendingSaves();')
        && substr_count(
            $editorJs,
            'return trackBlockSave(blockId, function () {'
        ) >= 2
        && str_contains($editorJs, 'requestPayload.csrf_token = csrfToken')
        && str_contains($editorApi, 'cp_editor_require_csrf($_POST);')
        && str_contains($editorPage, 'data-csrf-token="<?= h($editorCsrfToken) ?>"'),
    'A Wizard section can be reverted without owner, recency, and fingerprint safeguards.'
);

echo "PASS: Wizard changes and review guidance are visible and independently revertible.\n";
