<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceCommsCenterEngine.php';

$user = compliance_require_access($pdo);
$uid = (int)($user['id'] ?? 0);

/**
 * Shared receiver for the "Attach existing thread" form rendered by
 * compliance_render_comms_panel(). Keeps each compliance-object detail page
 * free of comms-specific POST routing.
 *
 * Accepts:
 *   POST linked_object_type   string (must be in linkableObjectTypes())
 *        linked_object_id     string
 *        thread_id            int
 *        link_type            string (must be in linkTypes())
 *        return_to            string  (URL on this domain to return to)
 *
 * `return_to` is whitelisted to /admin/compliance/* paths so we cannot be
 * used as an open redirect.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/compliance/inbox.php');
}

$returnTo = (string)($_POST['return_to'] ?? '');
$safeReturn = '/admin/compliance/inbox.php';
if ($returnTo !== '' && preg_match('#^/admin/compliance/[A-Za-z0-9_\-/.?=&%]+$#', $returnTo) === 1) {
    $safeReturn = $returnTo;
}

$type = (string)($_POST['linked_object_type'] ?? '');
$id = trim((string)($_POST['linked_object_id'] ?? ''));
$threadId = isset($_POST['thread_id']) ? (int)$_POST['thread_id'] : 0;
$linkType = (string)($_POST['link_type'] ?? 'authority_communication');

if ($threadId <= 0 || $type === '' || $id === '') {
    $_SESSION['_ipca_compliance_flash_objlink'] = array(
        'type' => 'error',
        'message' => 'Missing required fields for thread link.',
    );
    redirect($safeReturn);
}

try {
    ComplianceCommsCenterEngine::linkObject($pdo, array(
        'thread_id' => $threadId,
        'linked_object_type' => $type,
        'linked_object_id' => $id,
        'link_type' => $linkType,
        'created_by' => $uid > 0 ? $uid : null,
    ));
    $_SESSION['_ipca_compliance_flash_objlink'] = array(
        'type' => 'success',
        'message' => 'Linked thread #' . $threadId . ' to ' . $type . ' ' . $id . '.',
    );
} catch (Throwable $e) {
    $_SESSION['_ipca_compliance_flash_objlink'] = array(
        'type' => 'error',
        'message' => 'Link failed: ' . $e->getMessage(),
    );
}

redirect($safeReturn);
