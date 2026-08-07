<?php
declare(strict_types=1);

require_once __DIR__ . '/CvrDispatchIntakeService.php';
require_once __DIR__ . '/CvrWorkflowEvidenceIntakeService.php';
require_once __DIR__ . '/CvrSyncException.php';

final class CvrWorkflowSyncReconciliationService
{
    private const EVIDENCE_TYPES = array(
        'flight_events',
        'recorder_verification',
        'flight_record_closure',
    );

    private CvrDispatchIntakeService $dispatchIntake;
    private CvrWorkflowEvidenceIntakeService $evidenceIntake;

    public function __construct(private PDO $pdo)
    {
        $this->dispatchIntake = new CvrDispatchIntakeService($pdo);
        $this->evidenceIntake = new CvrWorkflowEvidenceIntakeService($pdo);
    }

    /**
     * @param list<mixed> $items
     * @param array<string,mixed> $device
     * @return list<array<string,mixed>>
     */
    public function reconcile(array $items, array $device): array
    {
        $results = array();
        foreach ($items as $item) {
            $item = is_array($item) ? $item : array();
            try {
                $results[] = $this->reconcileItem($item, $device);
            } catch (CvrDependencyNotReady $e) {
                $results[] = $this->failureResult($item, 'DEPENDENCY_NOT_READY', $e->getMessage(), true);
            } catch (CvrUserCorrectionRequired $e) {
                $results[] = $this->failureResult(
                    $item,
                    'USER_CORRECTION_REQUIRED',
                    $e->getMessage(),
                    false,
                    true
                );
            } catch (CvrImmutableConflict|CvrTechnicalReviewRequired $e) {
                $results[] = $this->failureResult($item, 'IMMUTABLE_CONFLICT', $e->getMessage(), false);
            } catch (Throwable $e) {
                error_log('CVR reconciliation item failed: ' . $e->getMessage());
                $results[] = $this->failureResult(
                    $item,
                    'TEMPORARY_TECHNICAL_FAILURE',
                    'Synchronization reconciliation is temporarily unavailable.',
                    true
                );
            }
        }
        return $results;
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $device
     * @return array<string,mixed>
     */
    private function reconcileItem(array $item, array $device): array
    {
        $itemId = trim((string)($item['item_id'] ?? ''));
        $componentType = trim((string)($item['component_type'] ?? ''));
        if ($itemId === '' || strlen($itemId) > 128) {
            throw new CvrImmutableConflict('A valid reconciliation item_id is required.');
        }
        if (!is_array($item['payload'] ?? null)) {
            throw new CvrImmutableConflict('The complete normal intake payload is required.');
        }
        if ($componentType === 'dispatch_metadata') {
            return $this->reconcileDispatch($item, $device);
        }
        if (in_array($componentType, self::EVIDENCE_TYPES, true)) {
            return $this->reconcileEvidence($item, $device);
        }
        throw new CvrImmutableConflict('Unsupported reconciliation component type.');
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $device
     * @return array<string,mixed>
     */
    private function reconcileDispatch(array $item, array $device): array
    {
        $dispatchUuid = $this->uuid($item['dispatch_uuid'] ?? null, 'dispatch_uuid');
        $flightUuid = $this->uuid($item['flight_record_uuid'] ?? null, 'flight_record_uuid');
        $version = (int)($item['dispatch_version'] ?? 0);
        if ($version <= 0) {
            throw new CvrImmutableConflict('dispatch_version is required.');
        }

        $statement = $this->pdo->prepare(
            'SELECT d.id AS server_dispatch_id, d.dispatch_uuid, d.workflow_flight_record_uuid,
                    d.device_id AS dispatch_device_id, v.dispatch_version,
                    v.device_id AS version_device_id, v.receipt_uuid, v.payload_sha256,
                    v.payload_json, v.received_at
             FROM ipca_cvr_dispatches d
             INNER JOIN ipca_cvr_dispatch_versions v ON v.dispatch_id = d.id
             WHERE d.dispatch_uuid = ? AND v.dispatch_version = ?
             LIMIT 1'
        );
        $statement->execute(array($dispatchUuid, $version));
        $stored = $statement->fetch(PDO::FETCH_ASSOC);

        // Apply the same aircraft/device ownership and payload identity rules as
        // normal Dispatch intake before classifying absence or conflict.
        $canonical = $this->dispatchIntake->canonicalPayload($item['payload'], $device);
        $normalized = $canonical['normalized'];
        if ($normalized['dispatch_uuid'] !== $dispatchUuid
            || $normalized['dispatch_version'] !== $version
            || $normalized['flight_record_uuid'] !== $flightUuid) {
            throw new CvrImmutableConflict('Dispatch payload identity conflicts with the reconciliation identity.');
        }

        if (!is_array($stored)) {
            $dispatchStatement = $this->pdo->prepare(
                'SELECT device_id, workflow_flight_record_uuid
                 FROM ipca_cvr_dispatches WHERE dispatch_uuid = ? LIMIT 1'
            );
            $dispatchStatement->execute(array($dispatchUuid));
            $existingDispatch = $dispatchStatement->fetch(PDO::FETCH_ASSOC);
            if (is_array($existingDispatch)
                && ((int)$existingDispatch['device_id'] !== (int)($device['id'] ?? 0)
                    || strtolower((string)$existingDispatch['workflow_flight_record_uuid']) !== $flightUuid)) {
                throw new CvrImmutableConflict('Dispatch ownership or immutable linkage conflicts with stored data.');
            }
            return $this->statusResult($item, 'NOT_FOUND', true);
        }

        $deviceId = (int)($device['id'] ?? 0);
        if ((int)$stored['dispatch_device_id'] !== $deviceId
            || (int)$stored['version_device_id'] !== $deviceId
            || strtolower((string)$stored['dispatch_uuid']) !== $dispatchUuid
            || (int)$stored['dispatch_version'] !== $version
            || strtolower((string)$stored['workflow_flight_record_uuid']) !== $flightUuid) {
            throw new CvrImmutableConflict('Dispatch ownership or immutable linkage conflicts with stored data.');
        }

        $matches = hash_equals((string)$stored['payload_sha256'], $canonical['payload_sha256'])
            || $this->dispatchIntake->isRetryEquivalent(
                (string)$stored['payload_json'],
                $canonical['payload_json']
            );
        if (!$matches) {
            throw new CvrImmutableConflict('Dispatch payload conflicts with the immutable stored version.');
        }

        return $this->verifiedResult($item, $stored, array(
            'server_dispatch_id' => (string)$stored['server_dispatch_id'],
            'dispatch_uuid' => $dispatchUuid,
            'dispatch_version' => (string)$version,
            'flight_record_uuid' => $flightUuid,
        ));
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $device
     * @return array<string,mixed>
     */
    private function reconcileEvidence(array $item, array $device): array
    {
        $componentUuid = $this->uuid($item['component_uuid'] ?? null, 'component_uuid');
        $dispatchUuid = $this->uuid($item['dispatch_uuid'] ?? null, 'dispatch_uuid');
        $flightUuid = $this->uuid($item['flight_record_uuid'] ?? null, 'flight_record_uuid');
        $componentType = (string)$item['component_type'];
        $deviceId = (int)($device['id'] ?? 0);

        $statement = $this->pdo->prepare(
            'SELECT id, batch_uuid, component_uuid, workflow_flight_record_uuid, dispatch_uuid,
                    device_id, component_type, payload_sha256, payload_json, receipt_uuid, received_at
             FROM ipca_cvr_workflow_evidence_batches
             WHERE component_uuid = ? LIMIT 1'
        );
        $statement->execute(array($componentUuid));
        $stored = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($stored)) {
            // Restored/admin closures mint a different component_uuid than the device.
            // Accept an existing flight-scoped closure so the App can clear a stuck upload.
            if ($componentType === 'flight_record_closure') {
                $existingClosure = $this->existingFlightClosureBatch($dispatchUuid, $flightUuid, $deviceId);
                if (is_array($existingClosure)) {
                    $typedIdentifiers = $this->typedIdentifiers((int)$existingClosure['id'], $componentType);
                    return $this->verifiedResult($item, $existingClosure, array_merge(array(
                        'server_evidence_batch_id' => (string)$existingClosure['id'],
                        'server_batch_uuid' => (string)$existingClosure['batch_uuid'],
                        'component_uuid' => strtolower((string)$existingClosure['component_uuid']),
                        'component_type' => $componentType,
                        'dispatch_uuid' => $dispatchUuid,
                        'flight_record_uuid' => $flightUuid,
                        'flight_scoped_closure_match' => '1',
                    ), $typedIdentifiers, $this->closureMeterIdentifiers($existingClosure)));
                }
            }
            $this->assertMissingEvidenceDependency($dispatchUuid, $flightUuid, $deviceId);
            return $this->statusResult($item, 'NOT_FOUND', true);
        }
        if ((int)$stored['device_id'] !== $deviceId
            || strtolower((string)$stored['component_uuid']) !== $componentUuid
            || strtolower((string)$stored['dispatch_uuid']) !== $dispatchUuid
            || strtolower((string)$stored['workflow_flight_record_uuid']) !== $flightUuid
            || (string)$stored['component_type'] !== $componentType) {
            throw new CvrImmutableConflict('Evidence ownership, type, or immutable linkage conflicts with stored data.');
        }

        $canonical = $this->evidenceIntake->canonicalPayload($item['payload']);
        $normalized = $canonical['normalized'];
        if ($normalized['component_uuid'] !== $componentUuid
            || $normalized['component_type'] !== $componentType
            || $normalized['dispatch_uuid'] !== $dispatchUuid
            || $normalized['flight_record_uuid'] !== $flightUuid) {
            throw new CvrImmutableConflict('Evidence payload identity conflicts with the reconciliation identity.');
        }
        if (!hash_equals((string)$stored['payload_sha256'], $canonical['payload_sha256'])) {
            throw new CvrImmutableConflict('Evidence payload conflicts with the immutable stored component.');
        }

        $typedIdentifiers = $this->typedIdentifiers((int)$stored['id'], $componentType);
        return $this->verifiedResult($item, $stored, array_merge(array(
            'server_evidence_batch_id' => (string)$stored['id'],
            'server_batch_uuid' => (string)$stored['batch_uuid'],
            'component_uuid' => $componentUuid,
            'component_type' => $componentType,
            'dispatch_uuid' => $dispatchUuid,
            'flight_record_uuid' => $flightUuid,
        ), $typedIdentifiers));
    }

    private function assertMissingEvidenceDependency(string $dispatchUuid, string $flightUuid, int $deviceId): void
    {
        $statement = $this->pdo->prepare(
            'SELECT device_id, workflow_flight_record_uuid
             FROM ipca_cvr_dispatches WHERE dispatch_uuid = ? LIMIT 1'
        );
        $statement->execute(array($dispatchUuid));
        $dispatch = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($dispatch)) {
            throw new CvrDependencyNotReady('Required Dispatch linkage is not available yet.');
        }
        if ((int)$dispatch['device_id'] !== $deviceId
            || strtolower((string)$dispatch['workflow_flight_record_uuid']) !== $flightUuid) {
            throw new CvrImmutableConflict('Evidence Dispatch ownership or Flight Record linkage conflicts.');
        }
    }

    /**
     * @return array<string,mixed>|false
     */
    private function existingFlightClosureBatch(string $dispatchUuid, string $flightUuid, int $deviceId): array|false
    {
        $statement = $this->pdo->prepare(
            'SELECT id, batch_uuid, component_uuid, workflow_flight_record_uuid, dispatch_uuid,
                    device_id, component_type, payload_sha256, payload_json, receipt_uuid, received_at
             FROM ipca_cvr_workflow_evidence_batches
             WHERE component_type = ?
               AND LOWER(dispatch_uuid) = ?
               AND LOWER(workflow_flight_record_uuid) = ?
               AND device_id = ?
             ORDER BY id DESC
             LIMIT 1'
        );
        $statement->execute(array('flight_record_closure', $dispatchUuid, $flightUuid, $deviceId));
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : false;
    }

    /**
     * @param array<string,mixed> $batch
     * @return array<string,string>
     */
    private function closureMeterIdentifiers(array $batch): array
    {
        $payload = json_decode((string)($batch['payload_json'] ?? ''), true);
        $evidence = is_array($payload) && is_array($payload['evidence'] ?? null)
            ? $payload['evidence']
            : array();
        $identifiers = array();
        foreach (array('ending_hobbs', 'ending_tacho', 'fuel_remaining', 'verified_destination_airport') as $key) {
            if (!array_key_exists($key, $evidence) || $evidence[$key] === null || $evidence[$key] === '') {
                continue;
            }
            $identifiers[$key] = is_scalar($evidence[$key]) ? (string)$evidence[$key] : '';
            if ($identifiers[$key] === '') {
                unset($identifiers[$key]);
            }
        }
        return $identifiers;
    }

    /** @return array<string,string> */
    private function typedIdentifiers(int $batchId, string $componentType): array
    {
        [$table, $uuidField, $serverIdField] = match ($componentType) {
            'flight_events' => array('ipca_cvr_flight_events', 'event_uuid', 'server_event_id'),
            'recorder_verification' => array(
                'ipca_cvr_recorder_verifications',
                'verification_uuid',
                'server_verification_id'
            ),
            default => array('ipca_cvr_flight_closures', 'closure_uuid', 'server_closure_id'),
        };
        $statement = $this->pdo->prepare(
            "SELECT id, {$uuidField} FROM {$table} WHERE batch_id = ? LIMIT 1"
        );
        $statement->execute(array($batchId));
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new CvrTemporaryTechnicalFailure('Stored evidence identifiers are temporarily unavailable.');
        }
        $typedId = (string)$row['id'];
        $typedUuid = strtolower((string)$row[$uuidField]);
        return array(
            'server_typed_evidence_id' => $typedId,
            'typed_evidence_uuid' => $typedUuid,
            $serverIdField => $typedId,
            $uuidField => $typedUuid,
        );
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $stored
     * @param array<string,mixed> $identifiers
     * @return array<string,mixed>
     */
    private function verifiedResult(array $item, array $stored, array $identifiers): array
    {
        return array_merge($this->statusResult($item, 'VERIFIED_MATCH', false), array(
            'receipt_id' => (string)$stored['receipt_uuid'],
            'received_at' => (string)$stored['received_at'],
            'payload_sha256' => (string)$stored['payload_sha256'],
            'canonical_identifiers' => $identifiers,
        ));
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function statusResult(array $item, string $status, bool $retryable): array
    {
        return array(
            'item_id' => substr(trim((string)($item['item_id'] ?? '')), 0, 128),
            'component_type' => trim((string)($item['component_type'] ?? '')),
            'status' => $status,
            'retryable' => $retryable,
            'user_action_required' => false,
            'error' => null,
        );
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function failureResult(
        array $item,
        string $status,
        string $message,
        bool $retryable,
        bool $userActionRequired = false
    ): array {
        $result = $this->statusResult($item, $status, $retryable);
        $result['error'] = $message;
        $result['user_action_required'] = $userActionRequired;
        return $result;
    }

    private function uuid(mixed $value, string $field): string
    {
        $uuid = strtolower(trim((string)$value));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid) !== 1) {
            throw new CvrImmutableConflict($field . ' must be a valid UUID.');
        }
        return $uuid;
    }
}
