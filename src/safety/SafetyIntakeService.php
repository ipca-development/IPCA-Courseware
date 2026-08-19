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
        private SafetyFeatureConfigService $config,
        private SafetyOccurrenceIntakeContextService $occurrenceContext
    ) {
    }

    /** @param array<string,mixed> $session @param array<string,mixed> $input */
    public function create(array $session, array $input, string $idempotencyKey): array
    {
        $organizationId = SafetySupport::organizationId($session);
        $this->config->requireEnabled($organizationId);
        $userId = (int)$session['user']['id'];
        $subjectHash = SafetySupport::reporterSubjectHash($organizationId, $userId);
        $selectedFlight = $this->occurrenceContext->selectedFlight($organizationId, $userId, $input);
        $titleInput = trim((string)($input['title'] ?? ''));
        $title = SafetySupport::cleanText(
            $titleInput !== ''
                ? $titleInput
                : $this->generatedTitle($selectedFlight, $input),
            240,
            'title'
        );
        $narrative = SafetySupport::cleanText(
            (string)($input['narrative'] ?? $input['description'] ?? ''),
            50000,
            'narrative'
        );
        $eventAt = SafetySupport::nullableUtc($input['event_at_utc'] ?? $input['occurred_at_utc'] ?? null);
        $location = $input['location_text'] ?? $input['location']
            ?? $selectedFlight['location_text'] ?? null;
        $aircraftRegistration = $input['aircraft_registration']
            ?? $selectedFlight['aircraft_registration'] ?? null;
        $injuryState = $this->triState($input['injury_state'] ?? 'unknown', 'injury_state');
        $damageState = $this->triState($input['damage_state'] ?? 'unknown', 'damage_state');
        $weatherRelevance = $this->weatherState($input['weather_relevance'] ?? 'unknown');
        $confidentiality = in_array(($input['confidentiality'] ?? ''), array('standard', 'restricted'), true)
            ? (string)$input['confidentiality'] : 'standard';
        $requestHash = SafetySupport::digest(SafetySupport::json(array(
            $title, $narrative, $eventAt, $location, $aircraftRegistration,
            $injuryState, $damageState, $weatherRelevance, $selectedFlight, $confidentiality,
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
                  category_code, occurrence_type_node_id, title, narrative,
                  event_at_utc, location_text, aircraft_registration, immediate_action,
                  phase_of_flight, injury_state, injury_details, damage_state, damage_details,
                  weather_relevance, weather_details, intake_context_json, status, confidentiality)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute(array(
                $organizationId,
                $uuid,
                'authenticated',
                $confidentiality === 'restricted' ? null : $userId,
                $subjectHash,
                null,
                null,
                $title,
                $narrative,
                $eventAt,
                $this->nullableText($location, 255),
                $this->nullableText($aircraftRegistration, 32),
                $this->nullableText($input['immediate_action'] ?? null, 12000),
                $this->nullableText($input['phase_of_flight'] ?? null, 64),
                $injuryState,
                $this->nullableText($input['injury_details'] ?? null, 12000),
                $damageState,
                $this->nullableText($input['damage_details'] ?? null, 12000),
                $weatherRelevance,
                $this->nullableText($input['weather_details'] ?? null, 12000),
                SafetySupport::json(array(
                    'event_time_source' => (string)($input['event_time_source'] ?? 'device'),
                    'location_source' => trim((string)($input['location_source'] ?? '')) !== ''
                        ? (string)$input['location_source']
                        : ($selectedFlight !== null ? 'selected_reservation' : 'reporter'),
                )),
                'draft',
                $confidentiality,
            ));
            $id = (int)$this->pdo->lastInsertId();
            if ($selectedFlight !== null) {
                $this->occurrenceContext->persistFlightLink(
                    $organizationId,
                    $id,
                    $userId,
                    $selectedFlight
                );
            }
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
            'UPDATE ipca_safety_reports SET category_code = ?, occurrence_type_node_id = ?,
             title = ?, narrative = ?, event_at_utc = ?,
             location_text = ?, aircraft_registration = ?, immediate_action = ?, phase_of_flight = ?,
             injury_state = ?, injury_details = ?, damage_state = ?, damage_details = ?,
             weather_relevance = ?, weather_details = ?
             WHERE id = ? AND organization_id = ?'
        )->execute(array(
            $row['category_code'],
            $row['occurrence_type_node_id'],
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
            array_key_exists('phase_of_flight', $input)
                ? $this->nullableText($input['phase_of_flight'], 64) : $row['phase_of_flight'],
            array_key_exists('injury_state', $input)
                ? $this->triState($input['injury_state'], 'injury_state') : $row['injury_state'],
            array_key_exists('injury_details', $input)
                ? $this->nullableText($input['injury_details'], 12000) : $row['injury_details'],
            array_key_exists('damage_state', $input)
                ? $this->triState($input['damage_state'], 'damage_state') : $row['damage_state'],
            array_key_exists('damage_details', $input)
                ? $this->nullableText($input['damage_details'], 12000) : $row['damage_details'],
            array_key_exists('weather_relevance', $input)
                ? $this->weatherState($input['weather_relevance']) : $row['weather_relevance'],
            array_key_exists('weather_details', $input)
                ? $this->nullableText($input['weather_details'], 12000) : $row['weather_details'],
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
            'occurrence_type_id' => $row['occurrence_type_node_id'] === null
                ? null : (int)$row['occurrence_type_node_id'],
            'occurrence_type_code' => $row['category_code'],
            'title' => (string)$row['title'],
            'description' => (string)$row['narrative'],
            'narrative' => (string)$row['narrative'],
            'occurred_at_utc' => $row['event_at_utc'],
            'event_at_utc' => $row['event_at_utc'],
            'location' => $row['location_text'],
            'location_text' => $row['location_text'],
            'aircraft_registration' => $row['aircraft_registration'],
            'immediate_action' => $row['immediate_action'],
            'phase_of_flight' => $row['phase_of_flight'] ?? null,
            'injury_state' => (string)($row['injury_state'] ?? 'unknown'),
            'injury_details' => $row['injury_details'] ?? null,
            'damage_state' => (string)($row['damage_state'] ?? 'unknown'),
            'damage_details' => $row['damage_details'] ?? null,
            'weather_relevance' => (string)($row['weather_relevance'] ?? 'unknown'),
            'weather_details' => $row['weather_details'] ?? null,
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

    /** @param array<string,mixed>|null $flight @param array<string,mixed> $input */
    private function generatedTitle(?array $flight, array $input): string
    {
        $parts = array('Aircraft safety occurrence');
        $registration = trim((string)(
            $input['aircraft_registration'] ?? $flight['aircraft_registration'] ?? ''
        ));
        if ($registration !== '') {
            $parts[] = strtoupper($registration);
        }
        $event = SafetySupport::nullableUtc($input['event_at_utc'] ?? $input['occurred_at_utc'] ?? null);
        if ($event !== null) {
            $parts[] = substr($event, 0, 10);
        }
        return implode(' · ', $parts);
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
