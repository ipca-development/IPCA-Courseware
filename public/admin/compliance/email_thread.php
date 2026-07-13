<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceCommsCenterEngine.php';

$user = compliance_require_access($pdo);
$uid = (int)($user['id'] ?? 0);

$threadId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$emailId = isset($_GET['email_id']) ? (int)$_GET['email_id'] : 0;

if ($threadId <= 0 && $emailId > 0) {
    $st = $pdo->prepare('SELECT thread_id FROM ipca_compliance_emails WHERE id = ? LIMIT 1');
    $st->execute(array($emailId));
    $threadId = (int)$st->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $postThreadId = isset($_POST['thread_id']) ? (int)$_POST['thread_id'] : $threadId;
    try {
        if ($action === 'link_object' && $postThreadId > 0) {
            ComplianceCommsCenterEngine::linkObject($pdo, array(
                'thread_id' => $postThreadId,
                'email_id' => isset($_POST['email_id']) && (int)$_POST['email_id'] > 0 ? (int)$_POST['email_id'] : null,
                'linked_object_type' => (string)($_POST['linked_object_type'] ?? ''),
                'linked_object_id' => (string)($_POST['linked_object_id'] ?? ''),
                'link_type' => (string)($_POST['link_type'] ?? 'context'),
                'created_by' => $uid > 0 ? $uid : null,
            ));
        } elseif ($action === 'unlink_object') {
            $linkId = (int)($_POST['link_id'] ?? 0);
            if ($linkId > 0) {
                ComplianceCommsCenterEngine::unlinkObject($pdo, $linkId);
            }
        } elseif ($action === 'set_status' && $postThreadId > 0) {
            ComplianceCommsCenterEngine::bulkUpdateThreadStatus($pdo, array($postThreadId), (string)($_POST['status'] ?? ''));
        } elseif ($action === 'set_priority' && $postThreadId > 0) {
            ComplianceCommsCenterEngine::bulkUpdateThreadPriority($pdo, array($postThreadId), (string)($_POST['priority'] ?? ''));
        }
    } catch (Throwable $e) {
        $_SESSION['_ipca_compliance_flash_inbox'] = array('type' => 'error', 'message' => $e->getMessage());
    }
    $threadId = $postThreadId > 0 ? $postThreadId : $threadId;
}

$href = '/admin/compliance/inbox.php';
if ($threadId > 0) {
    $href .= '?thread_id=' . $threadId;
}
redirect($href);
