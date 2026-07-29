<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';

final class CvrWorkflowEvidenceIntakeService
{
    private const TYPES = array('flight_events', 'recorder_verification', 'flight_record_closure');

    public function __construct(private PDO $pdo)
    {
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $device */
    public function receive(array $payload, array $device): array
    {
        $this->requireSchema();
        $normalized = $this->normalize($payload);
        $deviceId = (int)($device['id'] ?? 0);
        $this->assertDispatchOwnership($normalized['dispatch_uuid'], $normalized['flight_record_uuid'], $deviceId);
        $canonical = AuditEventService::jsonEncode($this->canonicalize($normalized));
        $hash = hash('sha256', $canonical);

        $this->pdo->beginTransaction();
        try {
            $existing = $this->batch($normalized['component_uuid']);
            $alreadyPresent = is_array($existing);
            if ($existing) {
                if ((int)$existing['device_id'] !== $deviceId || !hash_equals((string)$existing['payload_sha256'], $hash)) {
                    throw new RuntimeException('Workflow evidence component UUID conflict.');
                }
                $receipt = (string)$existing['receipt_uuid'];
                $batchId = (int)$existing['id'];
            } else {
                $receipt = AuditEventService::uuid();
                $statement = $this->pdo->prepare(
                    'INSERT INTO ipca_cvr_workflow_evidence_batches
                     (batch_uuid, component_uuid, workflow_flight_record_uuid, dispatch_uuid, device_id,
                      component_type, payload_sha256, payload_json, receipt_uuid)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $statement->execute(array(
                    AuditEventService::uuid(),
                    $normalized['component_uuid'],
                    $normalized['flight_record_uuid'],
                    $normalized['dispatch_uuid'],
                    $deviceId,
                    $normalized['component_type'],
                    $hash,
                    $canonical,
                    $receipt,
                ));
                $batchId = (int)$this->pdo->lastInsertId();
                $this->insertEvidence($batchId, $normalized, $hash, $canonical);
                (new AuditEventService($this->pdo))->record(
                    'cvr_workflow_evidence_received',
                    'ipca_cvr_workflow_evidence_batches',
                    $normalized['component_uuid'],
                    null,
                    array(
                        'component_type' => $normalized['component_type'],
                        'flight_record_uuid' => $normalized['flight_record_uuid'],
                        'receipt_uuid' => $receipt,
                        'payload_sha256' => $hash,
                    ),
                    'Authenticated immutable CVR workflow evidence received.',
                    'device',
                    null,
                    $deviceId,
                    null,
                    max(1, (int)($device['organization_id'] ?? 1)),
                    'cvr_app'
                );
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return array(
            'ok' => true,
            'already_present' => $alreadyPresent,
            'receipt' => array(
                'receipt_id' => $receipt,
                'component_type' => $normalized['component_type'],
                'payload_sha256' => $hash,
                'server_verified_at' => gmdate('c'),
            ),
        );
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function normalize(array $payload): array
    {
        $type = trim((string)($payload['component_type'] ?? ''));
        if (!in_array($type, self::TYPES, true)) {
            throw new RuntimeException('Unsupported workflow evidence component type.');
        }
        $normalized = array(
            'component_uuid' => $this->uuid($payload['component_uuid'] ?? null, 'component_uuid'),
            'flight_record_uuid' => $this->uuid($payload['flight_record_uuid'] ?? null, 'flight_record_uuid'),
            'dispatch_uuid' => $this->uuid($payload['dispatch_uuid'] ?? null, 'dispatch_uuid'),
            'component_type' => $type,
            'evidence' => is_array($payload['evidence'] ?? null) ? $payload['evidence'] : array(),
            'schema_version' => max(1, (int)($payload['schema_version'] ?? 1)),
        );
        if ($normalized['evidence'] === array()) {
            throw new RuntimeException('Workflow evidence payload is required.');
        }
        $idField = match ($type) {
            'flight_events' => 'event_uuid',
            'recorder_verification' => 'verification_uuid',
            default => 'closure_uuid',
        };
        $normalized['evidence'][$idField] = $this->uuid(
            $normalized['evidence'][$idField] ?? null,
            $idField
        );
        return $normalized;
    }

    /** @param array<string,mixed> $normalized */
    private function insertEvidence(int $batchId, array $normalized, string $hash, string $json): void
    {
        $e = $normalized['evidence'];
        if ($normalized['component_type'] === 'flight_events') {
            $statement = $this->pdo->prepare(
                'INSERT INTO ipca_cvr_flight_events
                 (event_uuid, batch_id, workflow_flight_record_uuid, recording_session_uuid, event_type,
                  timestamp_utc, timestamp_local, device_monotonic_time, audio_offset_seconds, latitude,
                  longitude, altitude, ground_speed, source, confidence, creation_method, user_identity,
                  payload_sha256, payload_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->execute(array(
                $e['event_uuid'], $batchId, $normalized['flight_record_uuid'],
                $this->nullableString($e['recording_session_id'] ?? null),
                $this->requiredString($e['event_type'] ?? null, 'event_type'),
                $this->date($e['timestamp_utc'] ?? null, 'timestamp_utc'),
                $this->date($e['timestamp_local'] ?? null, 'timestamp_local'),
                $e['device_monotonic_time'] ?? null, $e['audio_offset'] ?? null,
                $e['latitude'] ?? null, $e['longitude'] ?? null, $e['altitude'] ?? null,
                $e['ground_speed'] ?? null, (string)($e['source'] ?? ''),
                (float)($e['confidence'] ?? 1), (string)($e['creation_method'] ?? ''),
                $this->nullableString($e['user_identity'] ?? null), $hash, $json,
            ));
            return;
        }
        if ($normalized['component_type'] === 'recorder_verification') {
            $this->pdo->prepare(
                'INSERT INTO ipca_cvr_recorder_verifications
                 (verification_uuid, batch_id, workflow_flight_record_uuid, dispatch_uuid, verified_at,
                  payload_sha256, payload_json) VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute(array(
                $e['verification_uuid'], $batchId, $normalized['flight_record_uuid'],
                $normalized['dispatch_uuid'], $this->date($e['timestamp'] ?? null, 'timestamp'),
                $hash, $json,
            ));
            return;
        }
        $oil = isset($e['ending_oil_percentage']) ? max(0, min(100, (int)$e['ending_oil_percentage'])) : null;
        $this->pdo->prepare(
            'INSERT INTO ipca_cvr_flight_closures
             (closure_uuid, batch_id, workflow_flight_record_uuid, ending_hobbs, ending_tacho,
              fuel_remaining, oil_percentage, maintenance_remark, payload_sha256, payload_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(array(
            $e['closure_uuid'], $batchId, $normalized['flight_record_uuid'],
            $e['ending_hobbs'] ?? null, $e['ending_tacho'] ?? null,
            $this->nullableString($e['fuel_remaining'] ?? null), $oil,
            $this->nullableString($e['maintenance_remark'] ?? null), $hash, $json,
        ));
    }

    private function assertDispatchOwnership(string $dispatchUuid, string $flightUuid, int $deviceId): void
    {
        $statement = $this->pdo->prepare(
            'SELECT device_id, workflow_flight_record_uuid FROM ipca_cvr_dispatches WHERE dispatch_uuid = ? LIMIT 1'
        );
        $statement->execute(array($dispatchUuid));
        $dispatch = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$dispatch || (int)$dispatch['device_id'] !== $deviceId) {
            throw new RuntimeException('Dispatch is not owned by the authenticated CVR device.');
        }
        if (strtolower((string)$dispatch['workflow_flight_record_uuid']) !== $flightUuid) {
            throw new RuntimeException('Evidence Flight Record does not match the Dispatch.');
        }
    }

    /** @return array<string,mixed>|false */
    private function batch(string $componentUuid): array|false
    {
        $statement = $this->pdo->prepare(
            'SELECT id, device_id, payload_sha256, receipt_uuid
             FROM ipca_cvr_workflow_evidence_batches WHERE component_uuid = ? LIMIT 1 FOR UPDATE'
        );
        $statement->execute(array($componentUuid));
        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    private function requireSchema(): void
    {
        foreach (array(
            'ipca_cvr_dispatches',
            'ipca_cvr_workflow_evidence_batches',
            'ipca_cvr_flight_events',
            'ipca_cvr_recorder_verifications',
            'ipca_cvr_flight_closures'
        ) as $table) {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $statement->execute(array($table));
            if ((int)$statement->fetchColumn() !== 1) {
                throw new RuntimeException('CVR workflow evidence schema is not installed.');
            }
        }
    }

    private function uuid(mixed $value, string $field): string
    {
        $uuid = strtolower(trim((string)$value));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid)) {
            throw new RuntimeException($field . ' must be a valid UUID.');
        }
        return $uuid;
    }

    private function requiredString(mixed $value, string $field): string
    {
        $result = trim((string)$value);
        if ($result === '') {
            throw new RuntimeException($field . ' is required.');
        }
        return $result;
    }

    private function nullableString(mixed $value): ?string
    {
        $result = trim((string)$value);
        return $result === '' ? null : $result;
    }

    private function date(mixed $value, string $field): string
    {
        try {
            return (new DateTimeImmutable($this->requiredString($value, $field)))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s.v');
        } catch (Throwable) {
            throw new RuntimeException($field . ' must be a valid timestamp.');
        }
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = array_is_list($item)
                    ? array_map(fn($entry) => is_array($entry) ? $this->canonicalize($entry) : $entry, $item)
                    : $this->canonicalize($item);
            }
        }
        ksort($value);
        return $value;
    }
}
