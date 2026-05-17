<?php
declare(strict_types=1);

final class ComplianceAuthorityDocumentService
{
    /** @return array<string,string> */
    public static function auditDocumentTypes(): array
    {
        return array(
            'AUDIT_REPORT' => 'Audit Report',
            'UPDATED_AUDIT_REPORT' => 'Updated Audit Report',
            'OTHER' => 'Other',
        );
    }

    /** @return array<string,string> */
    public static function findingDocumentTypes(): array
    {
        return array(
            'FINDING_REPORT' => 'Finding Report',
            'UPDATED_FINDING_REPORT' => 'Updated Finding Report',
            'OTHER' => 'Other',
        );
    }

    /** @return list<array<string,mixed>> */
    public static function listAuditDocuments(PDO $pdo, int $auditId): array
    {
        if ($auditId <= 0 || !self::tablePresent($pdo, 'ipca_compliance_audit_documents')) {
            return array();
        }
        $hasReceived = self::columnPresent($pdo, 'ipca_compliance_audit_documents', 'received_on');
        $hasDeleted = self::columnPresent($pdo, 'ipca_compliance_audit_documents', 'deleted_at');
        $receivedExpr = $hasReceived ? 'received_on' : 'NULL AS received_on';
        $orderExpr = $hasReceived ? 'COALESCE(received_on, DATE(uploaded_at)) DESC,' : '';
        $deletedWhere = $hasDeleted ? ' AND deleted_at IS NULL' : '';
        $st = $pdo->prepare(
            'SELECT id, audit_id, doc_kind, storage_relpath, original_name, mime_type, file_size, '
            . $receivedExpr . ', sha256, uploaded_by, uploaded_at, notes
               FROM ipca_compliance_audit_documents
              WHERE audit_id = ?' . $deletedWhere . '
              ORDER BY ' . $orderExpr . ' uploaded_at DESC, id DESC'
        );
        $st->execute(array($auditId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    /** @return list<array<string,mixed>> */
    public static function listFindingDocuments(PDO $pdo, int $findingId): array
    {
        if ($findingId <= 0 || !self::tablePresent($pdo, 'ipca_compliance_finding_documents')) {
            return array();
        }
        try {
            $hasReceived = self::columnPresent($pdo, 'ipca_compliance_finding_documents', 'received_on');
            $hasDeleted = self::columnPresent($pdo, 'ipca_compliance_finding_documents', 'deleted_at');
            $receivedExpr = $hasReceived ? 'received_on' : 'NULL AS received_on';
            $orderExpr = $hasReceived ? 'COALESCE(received_on, DATE(uploaded_at)) DESC,' : '';
            $deletedWhere = $hasDeleted ? ' AND deleted_at IS NULL' : '';
            $st = $pdo->prepare(
                'SELECT id, finding_id, doc_kind, storage_relpath, original_name, mime_type, file_size, '
                . $receivedExpr . ', sha256, uploaded_by, uploaded_at, notes
                   FROM ipca_compliance_finding_documents
                  WHERE finding_id = ?' . $deletedWhere . '
                  ORDER BY ' . $orderExpr . ' uploaded_at DESC, id DESC'
            );
            $st->execute(array($findingId));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : array();
        } catch (Throwable) {
            return array();
        }
    }

    public static function updateDocumentMetadata(PDO $pdo, string $scope, int $id, array $data): void
    {
        $table = self::tableForScope($scope);
        if ($table === null || $id <= 0 || !self::tablePresent($pdo, $table)) {
            throw new RuntimeException('Document not found.');
        }
        $types = $scope === 'finding' ? self::findingDocumentTypes() : self::auditDocumentTypes();
        $fallback = $scope === 'finding' ? 'FINDING_REPORT' : 'AUDIT_REPORT';
        $kind = self::normalizeKind((string)($data['doc_kind'] ?? $fallback), $types, $fallback);
        $received = self::normalizeDate((string)($data['received_on'] ?? ''));
        $notes = trim((string)($data['notes'] ?? ''));
        $sets = array('doc_kind = ?', 'notes = ?');
        $params = array($kind, $notes !== '' ? $notes : null);
        if (self::columnPresent($pdo, $table, 'received_on')) {
            $sets[] = 'received_on = ?';
            $params[] = $received;
        }
        $params[] = $id;
        $pdo->prepare('UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE id = ? LIMIT 1')
            ->execute($params);
    }

    public static function softDeleteDocument(PDO $pdo, string $scope, int $id, int $userId): void
    {
        $table = self::tableForScope($scope);
        if ($table === null || $id <= 0 || !self::tablePresent($pdo, $table)) {
            throw new RuntimeException('Document not found.');
        }
        if (self::columnPresent($pdo, $table, 'deleted_at')) {
            $sets = array('deleted_at = NOW()');
            $params = array();
            if (self::columnPresent($pdo, $table, 'deleted_by')) {
                $sets[] = 'deleted_by = ?';
                $params[] = $userId > 0 ? $userId : null;
            }
            $params[] = $id;
            $pdo->prepare('UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE id = ? LIMIT 1')
                ->execute($params);
            return;
        }
        throw new RuntimeException('Soft-delete migration is not installed.');
    }

    /** @param array{name?:string,tmp_name?:string,type?:string,size?:int,error?:int} $file */
    public static function uploadAuditDocument(PDO $pdo, int $auditId, array $file, array $data, int $userId): int
    {
        if (!self::tablePresent($pdo, 'ipca_compliance_audit_documents')) {
            throw new RuntimeException('Audit document table is not installed.');
        }
        self::assertAuditExists($pdo, $auditId);
        $stored = self::storeUploadedPdf($file, 'audit', $auditId);
        $kind = self::normalizeKind((string)($data['doc_kind'] ?? 'AUDIT_REPORT'), self::auditDocumentTypes(), 'AUDIT_REPORT');
        $received = self::normalizeDate((string)($data['received_on'] ?? ''));
        $notes = trim((string)($data['notes'] ?? ''));
        $hasReceived = self::columnPresent($pdo, 'ipca_compliance_audit_documents', 'received_on');
        $columns = 'audit_id, doc_kind, storage_relpath, original_name, mime_type, file_size, ';
        $values = '?, ?, ?, ?, ?, ?, ';
        $params = array(
            $auditId,
            $kind,
            $stored['relpath'],
            $stored['original_name'],
            $stored['mime_type'],
            $stored['file_size'],
        );
        if ($hasReceived) {
            $columns .= 'received_on, ';
            $values .= '?, ';
            $params[] = $received;
        }
        $columns .= 'sha256, uploaded_by, notes';
        $values .= '?, ?, ?';
        $params[] = $stored['sha256'];
        $params[] = $userId > 0 ? $userId : null;
        $params[] = $notes !== '' ? $notes : null;
        $st = $pdo->prepare(
            'INSERT INTO ipca_compliance_audit_documents
                (' . $columns . ')
             VALUES (' . $values . ')'
        );
        $st->execute($params);
        return (int)$pdo->lastInsertId();
    }

    /** @param array{name?:string,tmp_name?:string,type?:string,size?:int,error?:int} $file */
    public static function uploadFindingDocument(PDO $pdo, int $findingId, array $file, array $data, int $userId): int
    {
        if (!self::tablePresent($pdo, 'ipca_compliance_finding_documents')) {
            throw new RuntimeException('Finding document table is not installed.');
        }
        self::assertFindingExists($pdo, $findingId);
        $stored = self::storeUploadedPdf($file, 'finding', $findingId);
        $kind = self::normalizeKind((string)($data['doc_kind'] ?? 'FINDING_REPORT'), self::findingDocumentTypes(), 'FINDING_REPORT');
        $received = self::normalizeDate((string)($data['received_on'] ?? ''));
        $notes = trim((string)($data['notes'] ?? ''));
        $hasReceived = self::columnPresent($pdo, 'ipca_compliance_finding_documents', 'received_on');
        $columns = 'finding_id, doc_kind, storage_relpath, original_name, mime_type, file_size, ';
        $values = '?, ?, ?, ?, ?, ?, ';
        $params = array(
            $findingId,
            $kind,
            $stored['relpath'],
            $stored['original_name'],
            $stored['mime_type'],
            $stored['file_size'],
        );
        if ($hasReceived) {
            $columns .= 'received_on, ';
            $values .= '?, ';
            $params[] = $received;
        }
        $columns .= 'sha256, uploaded_by, notes';
        $values .= '?, ?, ?';
        $params[] = $stored['sha256'];
        $params[] = $userId > 0 ? $userId : null;
        $params[] = $notes !== '' ? $notes : null;
        $st = $pdo->prepare(
            'INSERT INTO ipca_compliance_finding_documents
                (' . $columns . ')
             VALUES (' . $values . ')'
        );
        $st->execute($params);
        return (int)$pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public static function getDocument(PDO $pdo, string $scope, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        if ($scope === 'audit') {
            $st = $pdo->prepare("SELECT *, 'audit' AS scope FROM ipca_compliance_audit_documents WHERE id = ? LIMIT 1");
        } elseif ($scope === 'finding') {
            $st = $pdo->prepare("SELECT *, 'finding' AS scope FROM ipca_compliance_finding_documents WHERE id = ? LIMIT 1");
        } else {
            return null;
        }
        $st->execute(array($id));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public static function absolutePath(array $doc): string
    {
        $rel = str_replace('\\', '/', trim((string)($doc['storage_relpath'] ?? '')));
        if ($rel === '' || str_contains($rel, '..')) {
            throw new RuntimeException('Invalid document storage path.');
        }
        return self::projectRoot() . '/' . $rel;
    }

    public static function friendlyKind(string $scope, string $kind): string
    {
        $map = $scope === 'finding' ? self::findingDocumentTypes() : self::auditDocumentTypes();
        return $map[$kind] ?? ucwords(strtolower(str_replace('_', ' ', $kind)));
    }

    /** @return array{relpath:string,original_name:string,mime_type:string,file_size:int,sha256:string} */
    private static function storeUploadedPdf(array $file, string $scope, int $ownerId): array
    {
        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed with error code ' . $err . '.');
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Invalid uploaded file.');
        }
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0) {
            throw new RuntimeException('Uploaded PDF is empty.');
        }
        if ($size > 50 * 1024 * 1024) {
            throw new RuntimeException('PDF exceeds maximum size of 50 MiB.');
        }
        $original = self::safeFilename((string)($file['name'] ?? 'authority-report.pdf'));
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if ($ext !== 'pdf') {
            throw new RuntimeException('Only PDF documents can be uploaded.');
        }
        $mime = self::detectMime($tmp);
        if ($mime !== 'application/pdf' && $mime !== 'application/octet-stream') {
            throw new RuntimeException('Uploaded file must be a PDF.');
        }
        $dirRel = 'storage/compliance/authority_documents/' . $scope . 's/' . $ownerId;
        $dirAbs = self::projectRoot() . '/' . $dirRel;
        if (!is_dir($dirAbs) && !mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
            throw new RuntimeException('Unable to create document storage directory.');
        }
        $sha = hash_file('sha256', $tmp) ?: bin2hex(random_bytes(16));
        $targetName = date('Ymd_His') . '_' . substr($sha, 0, 12) . '_' . $original;
        $targetAbs = $dirAbs . '/' . $targetName;
        if (!move_uploaded_file($tmp, $targetAbs)) {
            throw new RuntimeException('Unable to store uploaded PDF.');
        }
        return array(
            'relpath' => $dirRel . '/' . $targetName,
            'original_name' => $original,
            'mime_type' => 'application/pdf',
            'file_size' => $size,
            'sha256' => $sha,
        );
    }

    private static function normalizeKind(string $kind, array $allowed, string $fallback): string
    {
        $kind = strtoupper(str_replace(array(' ', '-'), '_', trim($kind)));
        return array_key_exists($kind, $allowed) ? $kind : $fallback;
    }

    private static function tableForScope(string $scope): ?string
    {
        if ($scope === 'audit') {
            return 'ipca_compliance_audit_documents';
        }
        if ($scope === 'finding') {
            return 'ipca_compliance_finding_documents';
        }
        return null;
    }

    private static function normalizeDate(string $date): ?string
    {
        $date = substr(trim($date), 0, 10);
        if ($date === '') {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $dt ? $date : null;
    }

    private static function safeFilename(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'authority-report.pdf';
        return substr($name, 0, 180);
    }

    private static function detectMime(string $path): string
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);
        return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
    }

    private static function assertAuditExists(PDO $pdo, int $auditId): void
    {
        $st = $pdo->prepare('SELECT id FROM ipca_compliance_audits WHERE id = ? LIMIT 1');
        $st->execute(array($auditId));
        if (!$st->fetchColumn()) {
            throw new RuntimeException('Audit not found.');
        }
    }

    private static function assertFindingExists(PDO $pdo, int $findingId): void
    {
        $st = $pdo->prepare('SELECT id FROM ipca_compliance_findings WHERE id = ? LIMIT 1');
        $st->execute(array($findingId));
        if (!$st->fetchColumn()) {
            throw new RuntimeException('Finding not found.');
        }
    }

    private static function tablePresent(PDO $pdo, string $table): bool
    {
        try {
            $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 0');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private static function columnPresent(PDO $pdo, string $table, string $column): bool
    {
        try {
            $st = $pdo->prepare(
                'SELECT COUNT(*)
                   FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND COLUMN_NAME = ?'
            );
            $st->execute(array($table, $column));
            return (int)$st->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private static function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
