<?php
declare(strict_types=1);

require_once __DIR__ . '/SafetySupport.php';
require_once __DIR__ . '/SafetyAccessService.php';
require_once __DIR__ . '/SafetyAuditEventService.php';
require_once __DIR__ . '/SafetyFeatureConfigService.php';

final class SafetyIntakeService
{
    public function __construct(
        private PDO $pdo,
        private SafetyAccessService $access,
        private SafetyAuditEventService $events,
        private SafetyFeatureConfigService $config
    ) {
    }

    /** @param array<string,mixed> $session @param array<string,mixed> $input */
    public function create(array $session, array $input, string $idempotencyKey): array
    {
        $organizationId = SafetySupport::organizationId($session);
        $this->config->requireEnabled($organizationId);
        $userId = (int)$session['user']['id'];
        $subjectHash = SafetySupport::reporterSubjectHash($organizationId, $userId);
        $title = SafetySupport::cleanText((string)($input['title'] ?? ''), 240, 'title');
        $narrative = SafetySupport::cleanText(
            (string)($input['narrative'] ?? $input['description'] ?? ''),
            50000,
            'narrative'
        );
        $eventAt = SafetySupport::nullableUtc($input['event_at_utc'] ?? $input['occurred_at_utc'] ?? null);
        $location = $input['location_text'] ?? $input['location'] ?? null;
        $confidentiality = in_array(($input['confidentiality'] ?? ''), array('standard', 'restricted'), true)
            ? (string)$input['confidentiality'] : 'standard';
        $requestHash = SafetySupport::digest(SafetySupport::json(array(
            $title, $narrative, $eventAt, $location, $confidentiality,
        )));
        $cached = $this->idempotency(
            $organizationId, 'user', $subjectHash, 'report.create', $idempotencyKey, $requestHash
        );
        if ($cached !== null) {
            return $cached;
        }
        $vaultIdentity = $confidentiality === 'restricted'
            ? SafetySupport::encryptReporterIdentity($organizationId, $userId) : null;

        $this->pdo->beginTransaction();
        try {
            $uuid = SafetySupport::uuid();
            $this->pdo->prepare(
                'INSERT INTO ipca_safety_reports
                 (organization_id, report_uuid, channel, reporter_user_id, reporter_subject_hash,
                  category_code, title, narrative,
                  event_at_utc, location_text, aircraft_registration, immediate_action, status, confidentiality)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute(array(
                $organizationId,
                $uuid,
                'authenticated',
                $confidentiality === 'restricted' ? null : $userId,
                $subjectHash,
                $this->nullableText($input['category'] ?? null, 64),
                $title,
                $narrative,
                $eventAt,
                $this->nullableText($location, 255),
                $this->nullableText($input['aircraft_registration'] ?? null, 32),
                $this->nullableText($input['immediate_action'] ?? null, 12000),
                'draft',
                $confidentiality,
            ));
            $id = (int)$this->pdo->lastInsertId();
            if ($vaultIdentity !== null) {
                $this->pdo->prepare(
                    'INSERT INTO ipca_safety_reporter_vault
                     (organization_id, report_id, identity_ciphertext, key_reference, identity_digest)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute(array(
                    $organizationId, $id, $vaultIdentity['ciphertext'],
                    $vaultIdentity['key_reference'], $vaultIdentity['identity_digest'],
                ));
            }
            $this->events->append(
                $organizationId, 'report', $id, 'report.created',
                $confidentiality === 'restricted' ? 'confidential_reporter' : 'user',
                $confidentiality === 'restricted' ? null : $userId,
                $confidentiality === 'restricted' ? $subjectHash : null
            );
            $result = $this->publicReport($this->reportById($organizationId, $id));
            $this->completeIdempotency(
                $organizationId, 'user', $subjectHash, 'report.create', $idempotencyKey, $requestHash, $result
            );
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string,mixed> $session */
    public function listOwn(array $session): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ipca_safety_reports
             WHERE organization_id = ? AND (reporter_user_id = ? OR reporter_subject_hash = ?)
             ORDER BY updated_at_utc DESC LIMIT 200'
        );
        $org = SafetySupport::organizationId($session);
        $userId = (int)$session['user']['id'];
        $stmt->execute(array($org, $userId, SafetySupport::reporterSubjectHash($org, $userId)));
        return array_map(fn(array $row): array => $this->publicReport($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string,mixed> $session */
    public function detailOwn(array $session, string $reportUuid): array
    {
        $report = $this->access->requireOwnReport($session, $reportUuid);
        $out = $this->publicReport($report);
        $out['updates'] = $this->visibleUpdates((int)$report['organization_id'], (int)$report['id']);
        return $out;
    }

    /** @param array<string,mixed> $session @param array<string,mixed> $input */
    public function updateOwn(array $session, string $reportUuid, array $input): array
    {
        $row = $this->access->requireOwnReport($session, $reportUuid, true);
        $title = SafetySupport::cleanText((string)($input['title'] ?? $row['title']), 240, 'title');
        $narrative = SafetySupport::cleanText(
            (string)($input['narrative'] ?? $input['description'] ?? $row['narrative']),
            50000,
            'narrative'
        );
        $this->pdo->prepare(
            'UPDATE ipca_safety_reports SET category_code = ?, title = ?, narrative = ?, event_at_utc = ?,
             location_text = ?, aircraft_registration = ?, immediate_action = ?
             WHERE id = ? AND organization_id = ?'
        )->execute(array(
            array_key_exists('category', $input)
                ? $this->nullableText($input['category'], 64) : $row['category_code'],
            $title,
            $narrative,
            array_key_exists('event_at_utc', $input) || array_key_exists('occurred_at_utc', $input)
                ? SafetySupport::nullableUtc($input['event_at_utc'] ?? $input['occurred_at_utc']) : $row['event_at_utc'],
            array_key_exists('location_text', $input) || array_key_exists('location', $input)
                ? $this->nullableText($input['location_text'] ?? $input['location'], 255) : $row['location_text'],
            array_key_exists('aircraft_registration', $input)
                ? $this->nullableText($input['aircraft_registration'], 32) : $row['aircraft_registration'],
            array_key_exists('immediate_action', $input)
                ? $this->nullableText($input['immediate_action'], 12000) : $row['immediate_action'],
            (int)$row['id'],
            (int)$row['organization_id'],
        ));
        $this->appendReporterEvent($session, $row, 'report.updated');
        return $this->publicReport($this->reportById((int)$row['organization_id'], (int)$row['id']));
    }

    /** @param array<string,mixed> $session */
    public function submitOwn(array $session, string $reportUuid): array
    {
        $row = $this->access->requireOwnReport($session, $reportUuid, true);
        $this->pdo->beginTransaction();
        try {
            $number = 'SMS-' . gmdate('Y') . '-' . str_pad((string)$row['id'], 7, '0', STR_PAD_LEFT);
            $now = SafetySupport::nowUtc();
            $this->pdo->prepare(
                "UPDATE ipca_safety_reports SET status = 'submitted', report_number = ?, submitted_at_utc = ?
                 WHERE id = ? AND organization_id = ? AND status IN ('draft','returned')"
            )->execute(array($number, $now, (int)$row['id'], (int)$row['organization_id']));
            $this->appendReporterEvent($session, $row, 'report.submitted');
            $this->events->append((int)$row['organization_id'], 'report', (int)$row['id'], 'report.acknowledged',
                'system', null, null, array('channel' => 'authenticated'));
            $this->pdo->commit();
            return $this->publicReport($this->reportById((int)$row['organization_id'], (int)$row['id']));
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string,mixed> $session */
    public function postReporterUpdate(array $session, string $reportUuid, string $body): array
    {
        $report = $this->access->requireOwnReport($session, $reportUuid);
        $body = SafetySupport::cleanText($body, 12000, 'body');
        $uuid = SafetySupport::uuid();
        $restricted = (string)$report['confidentiality'] === 'restricted';
        $this->pdo->prepare(
            "INSERT INTO ipca_safety_reporter_updates
             (organization_id, update_uuid, report_id, direction, author_user_id,
              author_reference_hash, body, visible_to_reporter)
             VALUES (?, ?, ?, 'from_reporter', ?, ?, ?, 1)"
        )->execute(array(
            (int)$report['organization_id'],
            $uuid,
            (int)$report['id'],
            $restricted ? null : (int)$session['user']['id'],
            $restricted ? (string)$report['reporter_subject_hash'] : null,
            $body,
        ));
        $this->appendReporterEvent($session, $report, 'report.reporter_update_added');
        return array('update_uuid' => $uuid, 'body' => $body, 'direction' => 'from_reporter');
    }

    /** @return array<string,mixed>|null */
    private function idempotency(int $org, string $actorType, string $actorKey, string $operation, string $key, string $requestHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT request_hash, response_json, completed_at_utc FROM ipca_safety_idempotency_keys
             WHERE organization_id = ? AND actor_type = ? AND actor_key_hash = ?
               AND operation_code = ? AND idempotency_key_hash = ? LIMIT 1'
        );
        $stmt->execute(array($org, $actorType, SafetySupport::digest($actorKey), $operation, SafetySupport::digest($key)));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        if (!hash_equals((string)$row['request_hash'], $requestHash)) {
            throw new SafetyException('idempotency_conflict', 'That Idempotency-Key was used for a different request.', 409);
        }
        if ($row['completed_at_utc'] === null) {
            throw new SafetyException('request_in_progress', 'That request is still being processed.', 409);
        }
        $decoded = json_decode((string)$row['response_json'], true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string,mixed> $response */
    private function completeIdempotency(
        int $org, string $actorType, string $actorKey, string $operation, string $key, string $requestHash, array $response
    ): void {
        $this->pdo->prepare(
            'INSERT INTO ipca_safety_idempotency_keys
             (organization_id, actor_type, actor_key_hash, operation_code, idempotency_key_hash,
              request_hash, response_code, response_json, completed_at_utc, expires_at_utc)
             VALUES (?, ?, ?, ?, ?, ?, 201, ?, CURRENT_TIMESTAMP(3), DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL 7 DAY))'
        )->execute(array(
            $org, $actorType, SafetySupport::digest($actorKey), $operation,
            SafetySupport::digest($key), $requestHash, SafetySupport::json($response),
        ));
    }

    /** @return array<string,mixed> */
    private function reportById(int $organizationId, int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_safety_reports WHERE organization_id = ? AND id = ?');
        $stmt->execute(array($organizationId, $id));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new SafetyException('not_found', 'Safety report not found.', 404);
        }
        return $row;
    }

    /** @return array<int,array<string,mixed>> */
    private function visibleUpdates(int $organizationId, int $reportId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT update_uuid, direction, body, created_at_utc FROM ipca_safety_reporter_updates
             WHERE organization_id = ? AND report_id = ? AND visible_to_reporter = 1 ORDER BY id'
        );
        $stmt->execute(array($organizationId, $reportId));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function publicReport(array $row): array
    {
        return array(
            'id' => (string)$row['report_uuid'],
            'report_uuid' => (string)$row['report_uuid'],
            'reference' => $row['report_number'] ?? (string)$row['report_uuid'],
            'report_number' => $row['report_number'],
            'category' => $row['category_code'],
            'title' => (string)$row['title'],
            'description' => (string)$row['narrative'],
            'narrative' => (string)$row['narrative'],
            'occurred_at_utc' => $row['event_at_utc'],
            'event_at_utc' => $row['event_at_utc'],
            'location' => $row['location_text'],
            'location_text' => $row['location_text'],
            'aircraft_registration' => $row['aircraft_registration'],
            'immediate_action' => $row['immediate_action'],
            'status' => (string)$row['status'],
            'confidentiality' => (string)$row['confidentiality'],
            'submitted_at_utc' => $row['submitted_at_utc'],
            'created_at_utc' => (string)$row['created_at_utc'],
            'updated_at_utc' => (string)$row['updated_at_utc'],
        );
    }

    private function nullableText(mixed $value, int $max = 64): ?string
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        return SafetySupport::cleanText((string)$value, $max, 'value');
    }

    /** @param array<string,mixed> $session @param array<string,mixed> $report */
    private function appendReporterEvent(array $session, array $report, string $eventType): void
    {
        $restricted = (string)$report['confidentiality'] === 'restricted';
        $this->events->append(
            (int)$report['organization_id'],
            'report',
            (int)$report['id'],
            $eventType,
            $restricted ? 'confidential_reporter' : 'user',
            $restricted ? null : (int)$session['user']['id'],
            $restricted ? (string)$report['reporter_subject_hash'] : null
        );
    }
}
