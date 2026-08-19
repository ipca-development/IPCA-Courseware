<?php
declare(strict_types=1);

require_once __DIR__ . '/SafetySupport.php';
require_once __DIR__ . '/SafetyAuditEventService.php';
require_once __DIR__ . '/SafetyFeatureConfigService.php';

final class SafetyAnonymousService
{
    public function __construct(
        private PDO $pdo,
        private SafetyAuditEventService $events,
        private SafetyFeatureConfigService $config,
        private SafetyRateLimitService $rateLimits,
        private SafetyOccurrenceIntakeContextService $occurrenceContext
    ) {
    }

    /** @param array<string,mixed> $input */
    public function submit(
        int $organizationId,
        array $input,
        string $fingerprintHmac,
        string $idempotencyKey
    ): array
    {
        $this->config->requireEnabled($organizationId);
        $this->config->requireEnabled($organizationId, 'anonymous_reporting_enabled');
        $this->rateLimits->consume($organizationId, 'anonymous_submit', $fingerprintHmac, 8, 3600);
        $occurrenceType = $this->occurrenceContext->requireOccurrenceType($organizationId, $input);
        $titleInput = trim((string)($input['title'] ?? ''));
        $title = SafetySupport::cleanText(
            $titleInput !== '' ? $titleInput : (string)$occurrenceType['label'],
            240,
            'title'
        );
        $narrative = SafetySupport::cleanText(
            (string)($input['narrative'] ?? $input['description'] ?? ''),
            50000,
            'narrative'
        );
        $eventAt = SafetySupport::nullableUtc($input['event_at_utc'] ?? $input['occurred_at_utc'] ?? null);
        $location = $input['location_text'] ?? $input['location'] ?? null;
        $injuryState = $this->triState($input['injury_state'] ?? 'unknown', 'injury_state');
        $damageState = $this->triState($input['damage_state'] ?? 'unknown', 'damage_state');
        $weatherRelevance = $this->weatherState($input['weather_relevance'] ?? 'unknown');
        $requestHash = SafetySupport::digest(SafetySupport::json(array(
            $occurrenceType['id'], $title, $narrative, $eventAt, $location,
            $injuryState, $damageState, $weatherRelevance,
        )));
        $cached = $this->idempotentResponse($organizationId, $fingerprintHmac, $idempotencyKey, $requestHash);
        if ($cached !== null) {
            return $cached;
        }
        $receipt = strtoupper(bin2hex(random_bytes(8)));
        $secret = SafetySupport::token(32);

        $this->pdo->beginTransaction();
        try {
            $mailboxUuid = SafetySupport::uuid();
            $this->pdo->prepare(
                'INSERT INTO ipca_safety_anonymous_mailboxes
                 (organization_id, mailbox_uuid, receipt_code_hash, secret_hash, expires_at_utc)
                 VALUES (?, ?, ?, ?, DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL 730 DAY))'
            )->execute(array(
                $organizationId,
                $mailboxUuid,
                SafetySupport::digest($receipt),
                SafetySupport::secretHash($secret),
            ));
            $mailboxId = (int)$this->pdo->lastInsertId();
            $reportUuid = SafetySupport::uuid();
            $this->pdo->prepare(
                "INSERT INTO ipca_safety_reports
                 (organization_id, report_uuid, channel, anonymous_mailbox_id,
                  category_code, occurrence_type_node_id, title, narrative,
                  event_at_utc, location_text, aircraft_registration, immediate_action,
                  phase_of_flight, injury_state, injury_details, damage_state, damage_details,
                  weather_relevance, weather_details, intake_context_json,
                  status, confidentiality, submitted_at_utc)
                 VALUES (?, ?, 'anonymous', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                   'submitted', 'restricted', CURRENT_TIMESTAMP(3))"
            )->execute(array(
                $organizationId,
                $reportUuid,
                $mailboxId,
                (string)$occurrenceType['taxonomy_code'],
                (int)$occurrenceType['id'],
                $title,
                $narrative,
                $eventAt,
                $this->nullable($location, 255),
                $this->nullable($input['aircraft_registration'] ?? null, 32),
                $this->nullable($input['immediate_action'] ?? null, 12000),
                $this->nullable($input['phase_of_flight'] ?? null, 64),
                $injuryState,
                $this->nullable($input['injury_details'] ?? null, 12000),
                $damageState,
                $this->nullable($input['damage_details'] ?? null, 12000),
                $weatherRelevance,
                $this->nullable($input['weather_details'] ?? null, 12000),
                SafetySupport::json(array(
                    'event_time_source' => (string)($input['event_time_source'] ?? 'device'),
                    'location_source' => (string)($input['location_source'] ?? 'reporter'),
                    'occurrence_type_code' => (string)$occurrenceType['taxonomy_code'],
                )),
            ));
            $reportId = (int)$this->pdo->lastInsertId();
            $this->pdo->prepare(
                'INSERT IGNORE INTO ipca_safety_report_taxonomy
                 (organization_id, report_id, taxonomy_node_id, assigned_by_user_id)
                 VALUES (?, ?, ?, NULL)'
            )->execute(array($organizationId, $reportId, (int)$occurrenceType['id']));
            $number = 'SMS-' . gmdate('Y') . '-' . str_pad((string)$reportId, 7, '0', STR_PAD_LEFT);
            $this->pdo->prepare('UPDATE ipca_safety_reports SET report_number = ? WHERE id = ?')
                ->execute(array($number, $reportId));
            $this->events->append(
                $organizationId,
                'report',
                $reportId,
                'report.anonymously_submitted',
                'anonymous',
                null,
                SafetySupport::digest((string)$mailboxId),
                array('channel' => 'anonymous')
            );
            $this->events->append(
                $organizationId, 'report', $reportId, 'report.acknowledged',
                'system', null, null, array('channel' => 'anonymous')
            );
            $result = array(
                'receipt_id' => $receipt,
                'receipt_secret' => $secret,
                'secret' => $secret,
                'reference' => $number,
                'status' => 'submitted',
                'report_uuid' => $reportUuid,
                'report_number' => $number,
                'receipt_code' => $receipt,
                'mailbox_secret' => $secret,
                'mailbox_uuid' => $mailboxUuid,
                'privacy_notice' => 'No account or device metadata is requested. Network infrastructure may still process connection data; perfect anonymity cannot be guaranteed.',
            );
            $this->pdo->prepare(
                "INSERT INTO ipca_safety_idempotency_keys
                 (organization_id, actor_type, actor_key_hash, operation_code, idempotency_key_hash,
                  request_hash, response_code, response_json, completed_at_utc, expires_at_utc)
                 VALUES (?, 'anonymous', ?, 'anonymous.submit', ?, ?, 201, ?, CURRENT_TIMESTAMP(3),
                   DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL 7 DAY))"
            )->execute(array(
                $organizationId,
                SafetySupport::digest($fingerprintHmac),
                SafetySupport::digest($idempotencyKey),
                $requestHash,
                SafetySupport::json($result),
            ));
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function status(int $organizationId, string $receipt, string $secret, string $fingerprintHmac): array
    {
        $this->rateLimits->consume($organizationId, 'anonymous_mailbox', $fingerprintHmac, 30, 3600);
        [$mailbox, $report] = $this->authenticateMailbox($organizationId, $receipt, $secret);
        return array(
            'receipt_id' => $receipt,
            'reference' => (string)$report['report_number'],
            'report_uuid' => (string)$report['report_uuid'],
            'report_number' => (string)$report['report_number'],
            'status' => (string)$report['status'],
            'updated_at_utc' => (string)$report['updated_at_utc'],
            'submitted_at_utc' => $report['submitted_at_utc'],
            'mailbox_uuid' => (string)$mailbox['mailbox_uuid'],
        );
    }

    public function mailbox(int $organizationId, string $receipt, string $secret, string $fingerprintHmac): array
    {
        $this->rateLimits->consume($organizationId, 'anonymous_mailbox', $fingerprintHmac, 30, 3600);
        [$mailbox, $report] = $this->authenticateMailbox($organizationId, $receipt, $secret);
        $stmt = $this->pdo->prepare(
            'SELECT update_uuid, direction, body, created_at_utc FROM ipca_safety_reporter_updates
             WHERE organization_id = ? AND report_id = ? AND visible_to_reporter = 1 ORDER BY id'
        );
        $stmt->execute(array($organizationId, (int)$report['id']));
        return array(
            'report_uuid' => (string)$report['report_uuid'],
            'status' => (string)$report['status'],
            'messages' => array_map(static fn(array $row): array => array(
                'id' => (string)$row['update_uuid'],
                'message_uuid' => (string)$row['update_uuid'],
                'direction' => (string)$row['direction'],
                'sender_label' => $row['direction'] === 'to_reporter' ? 'Safety Team' : 'You',
                'body' => (string)$row['body'],
                'created_at_utc' => (string)$row['created_at_utc'],
            ), $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'mailbox_uuid' => (string)$mailbox['mailbox_uuid'],
        );
    }

    public function postUpdate(
        int $organizationId,
        string $receipt,
        string $secret,
        string $body,
        string $fingerprintHmac
    ): array {
        $this->rateLimits->consume($organizationId, 'anonymous_mailbox_post', $fingerprintHmac, 20, 3600);
        [$mailbox, $report] = $this->authenticateMailbox($organizationId, $receipt, $secret);
        $body = SafetySupport::cleanText($body, 12000, 'body');
        $uuid = SafetySupport::uuid();
        $this->pdo->prepare(
            "INSERT INTO ipca_safety_reporter_updates
             (organization_id, update_uuid, report_id, direction, author_anonymous_mailbox_id, body, visible_to_reporter)
             VALUES (?, ?, ?, 'from_reporter', ?, ?, 1)"
        )->execute(array($organizationId, $uuid, (int)$report['id'], (int)$mailbox['id'], $body));
        $this->events->append(
            $organizationId, 'report', (int)$report['id'], 'report.anonymous_update_added',
            'anonymous', null, SafetySupport::digest((string)$mailbox['id'])
        );
        return array('update_uuid' => $uuid, 'direction' => 'from_reporter', 'body' => $body);
    }

    /** @return array{organization_id:int,mailbox_id:int,report_id:int,report_uuid:string} */
    public function attachmentContext(
        int $organizationId,
        string $receipt,
        string $secret,
        string $fingerprintHmac
    ): array {
        $this->rateLimits->consume($organizationId, 'anonymous_attachment', $fingerprintHmac, 30, 3600);
        [$mailbox, $report] = $this->authenticateMailbox($organizationId, $receipt, $secret);
        return array(
            'organization_id' => $organizationId,
            'mailbox_id' => (int)$mailbox['id'],
            'report_id' => (int)$report['id'],
            'report_uuid' => (string)$report['report_uuid'],
        );
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function authenticateMailbox(int $organizationId, string $receipt, string $secret): array
    {
        $receipt = strtoupper(trim($receipt));
        $secret = trim($secret);
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ipca_safety_anonymous_mailboxes
             WHERE organization_id = ? AND receipt_code_hash = ? LIMIT 1'
        );
        $stmt->execute(array($organizationId, SafetySupport::digest($receipt)));
        $mailbox = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($mailbox)) {
            throw new SafetyException('mailbox_credentials_invalid', 'Receipt code or mailbox secret is invalid.', 401);
        }
        if ($mailbox['locked_until_utc'] !== null
            && strtotime((string)$mailbox['locked_until_utc'] . ' UTC') > time()) {
            throw new SafetyException('mailbox_credentials_invalid', 'Receipt code or mailbox secret is invalid.', 401);
        }
        if (!password_verify($secret, (string)$mailbox['secret_hash'])) {
            $this->pdo->prepare(
                'UPDATE ipca_safety_anonymous_mailboxes
                 SET failed_attempts = failed_attempts + 1,
                   locked_until_utc = CASE WHEN failed_attempts >= 4
                     THEN DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL 30 MINUTE) ELSE NULL END
                 WHERE id = ?'
            )->execute(array((int)$mailbox['id']));
            throw new SafetyException('mailbox_credentials_invalid', 'Receipt code or mailbox secret is invalid.', 401);
        }
        if ($mailbox['expires_at_utc'] !== null && strtotime((string)$mailbox['expires_at_utc'] . ' UTC') < time()) {
            throw new SafetyException('mailbox_expired', 'This anonymous mailbox has expired.', 410);
        }
        $this->pdo->prepare(
            'UPDATE ipca_safety_anonymous_mailboxes SET failed_attempts = 0, last_accessed_at_utc = CURRENT_TIMESTAMP(3)
             WHERE id = ?'
        )->execute(array((int)$mailbox['id']));
        $reportStmt = $this->pdo->prepare(
            'SELECT * FROM ipca_safety_reports WHERE organization_id = ? AND anonymous_mailbox_id = ? LIMIT 1'
        );
        $reportStmt->execute(array($organizationId, (int)$mailbox['id']));
        $report = $reportStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($report)) {
            throw new SafetyException('not_found', 'Safety report not found.', 404);
        }
        return array($mailbox, $report);
    }

    /** @return array<string,mixed>|null */
    private function idempotentResponse(
        int $organizationId,
        string $fingerprintHmac,
        string $idempotencyKey,
        string $requestHash
    ): ?array {
        $stmt = $this->pdo->prepare(
            "SELECT request_hash, response_json, completed_at_utc FROM ipca_safety_idempotency_keys
             WHERE organization_id = ? AND actor_type = 'anonymous' AND actor_key_hash = ?
               AND operation_code = 'anonymous.submit' AND idempotency_key_hash = ? LIMIT 1"
        );
        $stmt->execute(array(
            $organizationId,
            SafetySupport::digest($fingerprintHmac),
            SafetySupport::digest($idempotencyKey),
        ));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        if (!hash_equals((string)$row['request_hash'], $requestHash)) {
            throw new SafetyException('idempotency_conflict', 'That Idempotency-Key was used for a different request.', 409);
        }
        $response = json_decode((string)$row['response_json'], true);
        return is_array($response) ? $response : null;
    }

    private function nullable(mixed $value, int $max): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : SafetySupport::cleanText($value, $max, 'value');
    }

    private function triState(mixed $value, string $field): string
    {
        $value = strtolower(trim((string)$value));
        if (!in_array($value, array('no', 'yes', 'unknown'), true)) {
            throw new SafetyException('validation_error', $field . ' must be no, yes, or unknown.', 400);
        }
        return $value;
    }

    private function weatherState(mixed $value): string
    {
        $value = strtolower(trim((string)$value));
        if (!in_array($value, array('no', 'yes', 'unsure', 'unknown'), true)) {
            throw new SafetyException(
                'validation_error',
                'weather_relevance must be no, yes, unsure, or unknown.',
                400
            );
        }
        return $value;
    }
}
