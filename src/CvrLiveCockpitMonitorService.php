<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/CockpitRecorderService.php';

/**
 * Ephemeral live-listening control plane.
 *
 * This service can request/drop the monitor branch only. It has no method that
 * can start, stop, rotate, pause, or reconfigure canonical evidence recording.
 */
final class CvrLiveCockpitMonitorService
{
    private const LEASE_SECONDS = 15;
    private const RECONNECT_GAP_SECONDS = 8;
    private const CHUNK_TTL_SECONDS = 120;
    private const MAX_CHUNK_BYTES = 768000;
    private const MAX_MANIFEST_CHUNKS = 40;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function statusForAircraft(int $aircraftId, string $claimedDispatchUuid, int $staffUserId): array
    {
        $this->assertSchema();
        $this->expireStaleControlState();
        $dispatch = $this->activeDispatch($aircraftId, $claimedDispatchUuid, false);
        $broadcast = $this->activeBroadcast((int)$dispatch['id'], false);
        return array(
            'ok' => true,
            'available' => true,
            'aircraft_id' => (int)$dispatch['aircraft_id'],
            'dispatch_uuid' => strtolower((string)$dispatch['dispatch_uuid']),
            'operational_session_uuid' => strtolower((string)$dispatch['operational_session_uuid']),
            'broadcast_active' => is_array($broadcast),
            'active_listener_count' => is_array($broadcast)
                ? $this->activeListenerCount((int)$broadcast['id'])
                : 0,
            'device_enabled' => $this->monitorEnabledForDevice(array(
                'id' => (int)$dispatch['device_id'],
                'device_uuid' => (string)($dispatch['device_uuid'] ?? ''),
            )),
            'staff_user_id' => $staffUserId,
        );
    }

    /** @return array<string,mixed> */
    public function startListener(
        int $aircraftId,
        string $claimedDispatchUuid,
        string $clientUuid,
        int $staffUserId
    ): array {
        $this->assertSchema();
        $clientUuid = $this->uuid($clientUuid, 'client_uuid');
        if ($staffUserId <= 0) {
            throw new InvalidArgumentException('Authenticated staff identity is required.');
        }

        $this->pdo->beginTransaction();
        try {
            $this->expireStaleControlState();
            $dispatch = $this->activeDispatch($aircraftId, $claimedDispatchUuid, true);
            $broadcast = $this->activeBroadcast((int)$dispatch['id'], true);
            if (!is_array($broadcast)) {
                $broadcastUuid = AuditEventService::uuid();
                $this->pdo->prepare(
                    "INSERT INTO ipca_cvr_monitor_broadcasts
                       (broadcast_uuid, dispatch_id, dispatch_uuid, workflow_flight_record_uuid,
                        operational_session_uuid, device_id, status)
                     VALUES (?, ?, ?, ?, ?, ?, 'active')"
                )->execute(array(
                    $broadcastUuid,
                    (int)$dispatch['id'],
                    strtolower((string)$dispatch['dispatch_uuid']),
                    strtolower((string)$dispatch['workflow_flight_record_uuid']),
                    strtolower((string)$dispatch['operational_session_uuid']),
                    (int)$dispatch['device_id'],
                ));
                $broadcast = $this->broadcastById((int)$this->pdo->lastInsertId(), true);
            }
            if (!is_array($broadcast)) {
                throw new RuntimeException('Could not create live monitor broadcast.');
            }

            $existing = $this->pdo->prepare(
                "SELECT * FROM ipca_cvr_monitor_listener_leases
                 WHERE broadcast_id = ? AND staff_user_id = ? AND client_uuid = ?
                   AND status = 'active' AND expires_at_utc > CURRENT_TIMESTAMP(3)
                 ORDER BY id DESC LIMIT 1 FOR UPDATE"
            );
            $existing->execute(array((int)$broadcast['id'], $staffUserId, $clientUuid));
            $lease = $existing->fetch(PDO::FETCH_ASSOC);
            if (is_array($lease)) {
                $this->renewLease((int)$lease['id'], false);
                $lease = $this->leaseById((int)$lease['id']);
            } else {
                $leaseUuid = AuditEventService::uuid();
                $this->pdo->prepare(
                    "INSERT INTO ipca_cvr_monitor_listener_leases
                       (lease_uuid, client_uuid, broadcast_id, staff_user_id, expires_at_utc,
                        audit_metadata_json)
                     VALUES (?, ?, ?, ?, DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL "
                        . self::LEASE_SECONDS . " SECOND), ?)"
                )->execute(array(
                    $leaseUuid,
                    $clientUuid,
                    (int)$broadcast['id'],
                    $staffUserId,
                    AuditEventService::jsonEncode(array(
                        'aircraft_id' => $aircraftId,
                        'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                    )),
                ));
                $lease = $this->leaseById((int)$this->pdo->lastInsertId());
                $this->audit(
                    'cvr.live_monitor.listener_started',
                    $leaseUuid,
                    $staffUserId,
                    (int)$dispatch['organization_id'],
                    array(
                        'broadcast_uuid' => (string)$broadcast['broadcast_uuid'],
                        'dispatch_uuid' => (string)$dispatch['dispatch_uuid'],
                        'operational_session_uuid' => (string)$dispatch['operational_session_uuid'],
                    )
                );
            }
            $this->pdo->prepare(
                'UPDATE ipca_cvr_monitor_broadcasts
                 SET last_listener_at_utc = CURRENT_TIMESTAMP(3) WHERE id = ?'
            )->execute(array((int)$broadcast['id']));
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->listenerPayload($broadcast, $lease, $dispatch);
    }

    /** @return array<string,mixed> */
    public function heartbeat(string $leaseUuid, int $staffUserId): array
    {
        $this->assertSchema();
        $leaseUuid = $this->uuid($leaseUuid, 'lease_uuid');
        $this->pdo->beginTransaction();
        try {
            $lease = $this->ownedLease($leaseUuid, $staffUserId, true);
            if ((string)$lease['status'] !== 'active') {
                throw new RuntimeException('Live monitor listener has ended.');
            }
            $lastHeartbeat = strtotime((string)$lease['heartbeat_at_utc']) ?: time();
            $reconnected = (time() - $lastHeartbeat) >= self::RECONNECT_GAP_SECONDS;
            $this->renewLease((int)$lease['id'], $reconnected);
            $broadcast = $this->broadcastById((int)$lease['broadcast_id'], true);
            if (!is_array($broadcast) || (string)$broadcast['status'] !== 'active') {
                throw new RuntimeException('Live monitor broadcast has ended.');
            }
            $dispatch = $this->dispatchById((int)$broadcast['dispatch_id']);
            if ($reconnected) {
                $this->audit(
                    'cvr.live_monitor.listener_reconnected',
                    $leaseUuid,
                    $staffUserId,
                    (int)($dispatch['organization_id'] ?? 1),
                    array('broadcast_uuid' => (string)$broadcast['broadcast_uuid'])
                );
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return $this->listenerPayload($broadcast, $this->leaseById((int)$lease['id']), $dispatch);
    }

    /** @return array<string,mixed> */
    public function stopListener(string $leaseUuid, int $staffUserId, string $reason = 'staff_stop'): array
    {
        $this->assertSchema();
        $leaseUuid = $this->uuid($leaseUuid, 'lease_uuid');
        $this->pdo->beginTransaction();
        try {
            $lease = $this->ownedLease($leaseUuid, $staffUserId, true);
            $broadcast = $this->broadcastById((int)$lease['broadcast_id'], true);
            if ((string)$lease['status'] === 'active') {
                $this->pdo->prepare(
                    "UPDATE ipca_cvr_monitor_listener_leases
                     SET status = 'stopped', stopped_at_utc = CURRENT_TIMESTAMP(3),
                         stop_reason = ?
                     WHERE id = ?"
                )->execute(array(substr($reason, 0, 64), (int)$lease['id']));
            }
            if (is_array($broadcast) && $this->activeListenerCount((int)$broadcast['id']) === 0) {
                $this->endBroadcast((int)$broadcast['id'], 'no_active_listeners');
            }
            $dispatch = is_array($broadcast) ? $this->dispatchById((int)$broadcast['dispatch_id']) : array();
            $this->audit(
                'cvr.live_monitor.listener_stopped',
                $leaseUuid,
                $staffUserId,
                (int)($dispatch['organization_id'] ?? 1),
                array('reason' => $reason)
            );
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return array('ok' => true, 'lease_uuid' => $leaseUuid, 'status' => 'stopped');
    }

    /** @return array<string,mixed> */
    public function manifest(string $leaseUuid, int $staffUserId, int $afterSequence = 0): array
    {
        $this->assertSchema();
        $this->expireStaleControlState();
        $lease = $this->ownedLease($this->uuid($leaseUuid, 'lease_uuid'), $staffUserId, false);
        $broadcast = $this->broadcastById((int)$lease['broadcast_id'], false);
        if (!is_array($broadcast)) {
            throw new RuntimeException('Live monitor broadcast was not found.');
        }
        $statement = $this->pdo->prepare(
            'SELECT chunk_uuid, sequence_number, started_at_utc, duration_seconds,
                    file_size_bytes, received_at_utc
             FROM ipca_cvr_monitor_chunks
             WHERE broadcast_id = ? AND sequence_number > ? AND purged_at_utc IS NULL
               AND expires_at_utc > CURRENT_TIMESTAMP(3)
             ORDER BY sequence_number ASC LIMIT ' . self::MAX_MANIFEST_CHUNKS
        );
        $statement->execute(array((int)$broadcast['id'], max(0, $afterSequence)));
        $chunks = array_map(static function (array $row) use ($leaseUuid): array {
            return array(
                'chunk_uuid' => (string)$row['chunk_uuid'],
                'sequence_number' => (int)$row['sequence_number'],
                'started_at_utc' => (string)$row['started_at_utc'],
                'duration_seconds' => (float)$row['duration_seconds'],
                'file_size_bytes' => (int)$row['file_size_bytes'],
                'received_at_utc' => (string)$row['received_at_utc'],
                'audio_url' => '/admin/api/live_cockpit_monitor_audio.php?lease_uuid='
                    . rawurlencode($leaseUuid) . '&chunk_uuid=' . rawurlencode((string)$row['chunk_uuid']),
            );
        }, $statement->fetchAll(PDO::FETCH_ASSOC) ?: array());
        return array(
            'ok' => true,
            'lease_status' => (string)$lease['status'],
            'broadcast_status' => (string)$broadcast['status'],
            'server_time_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'expires_at_utc' => (string)$lease['expires_at_utc'],
            'chunks' => $chunks,
        );
    }

    /** @return array<string,mixed> */
    public function deviceLease(array $device): array
    {
        $this->assertSchema();
        $this->expireStaleControlState();
        $deviceId = (int)($device['id'] ?? 0);
        $captureBackendEnabled = $deviceId > 0 && $this->monitorEnabledForDevice($device);
        if (!$captureBackendEnabled) {
            return array(
                'ok' => true,
                'capture_requested' => false,
                'capture_backend_enabled' => false,
                'reason' => 'device_not_enabled',
            );
        }
        $statement = $this->pdo->prepare(
            "SELECT b.*
             FROM ipca_cvr_monitor_broadcasts b
             INNER JOIN ipca_cvr_dispatches d ON d.id = b.dispatch_id
             WHERE b.device_id = ? AND b.status = 'active'
               AND d.operational_session_uuid = b.operational_session_uuid
               AND LOWER(TRIM(COALESCE(d.status, ''))) <> 'released'
               AND EXISTS (
                 SELECT 1 FROM ipca_cvr_monitor_listener_leases l
                 WHERE l.broadcast_id = b.id AND l.status = 'active'
                   AND l.expires_at_utc > CURRENT_TIMESTAMP(3)
               )
             ORDER BY b.id DESC LIMIT 1"
        );
        $statement->execute(array($deviceId));
        $broadcast = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($broadcast)) {
            return array(
                'ok' => true,
                'capture_requested' => false,
                'capture_backend_enabled' => true,
                'reason' => 'no_active_listener',
            );
        }
        return array(
            'ok' => true,
            'capture_requested' => true,
            'capture_backend_enabled' => true,
            'broadcast_uuid' => (string)$broadcast['broadcast_uuid'],
            'dispatch_uuid' => (string)$broadcast['dispatch_uuid'],
            'workflow_flight_record_uuid' => (string)$broadcast['workflow_flight_record_uuid'],
            'operational_session_uuid' => (string)$broadcast['operational_session_uuid'],
            'lease_expires_in_seconds' => self::LEASE_SECONDS,
            'chunk_duration_seconds' => 4,
            'max_chunk_bytes' => self::MAX_CHUNK_BYTES,
        );
    }

    /** @return array<string,mixed> */
    public function receiveChunk(array $metadata, string $audioBytes, array $device): array
    {
        $this->assertSchema();
        $this->expireStaleControlState();
        if (!$this->monitorEnabledForDevice($device)) {
            throw new RuntimeException('Live cockpit monitoring is not enabled for this device.');
        }
        $broadcastUuid = $this->uuid((string)($metadata['broadcast_uuid'] ?? ''), 'broadcast_uuid');
        $chunkUuid = $this->uuid((string)($metadata['chunk_uuid'] ?? ''), 'chunk_uuid');
        $sessionUuid = $this->uuid((string)($metadata['operational_session_uuid'] ?? ''), 'operational_session_uuid');
        $sequence = (int)($metadata['sequence_number'] ?? 0);
        $duration = (float)($metadata['duration_seconds'] ?? 0);
        $startedAt = $this->dateTime((string)($metadata['started_at_utc'] ?? ''));
        $expectedSha = strtolower(trim((string)($metadata['sha256'] ?? '')));
        if ($sequence <= 0 || $duration <= 0 || $duration > 8.0 || $audioBytes === '') {
            throw new InvalidArgumentException('Complete monitor chunk metadata and audio are required.');
        }
        if (strlen($audioBytes) > self::MAX_CHUNK_BYTES) {
            throw new InvalidArgumentException('Monitor audio chunk exceeds the low-bandwidth limit.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $expectedSha)
            || !hash_equals($expectedSha, hash('sha256', $audioBytes))) {
            throw new InvalidArgumentException('Monitor chunk SHA-256 is invalid.');
        }

        $this->pdo->beginTransaction();
        $absolutePath = null;
        try {
            $broadcastStatement = $this->pdo->prepare(
                "SELECT * FROM ipca_cvr_monitor_broadcasts
                 WHERE broadcast_uuid = ? AND status = 'active' LIMIT 1 FOR UPDATE"
            );
            $broadcastStatement->execute(array($broadcastUuid));
            $broadcast = $broadcastStatement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($broadcast)
                || (int)$broadcast['device_id'] !== (int)($device['id'] ?? 0)
                || strtolower((string)$broadcast['operational_session_uuid']) !== $sessionUuid
                || $this->activeListenerCount((int)$broadcast['id']) === 0) {
                throw new RuntimeException('Monitor broadcast lease is no longer active for this device session.');
            }
            $existing = $this->pdo->prepare(
                'SELECT chunk_uuid, sha256 FROM ipca_cvr_monitor_chunks
                 WHERE broadcast_id = ? AND sequence_number = ? LIMIT 1 FOR UPDATE'
            );
            $existing->execute(array((int)$broadcast['id'], $sequence));
            $row = $existing->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                if (!hash_equals((string)$row['sha256'], $expectedSha)) {
                    throw new RuntimeException('Monitor chunk sequence already contains different audio.');
                }
                $this->pdo->commit();
                return array('ok' => true, 'already_present' => true, 'chunk_uuid' => (string)$row['chunk_uuid']);
            }

            $relativeDirectory = 'storage/cvr_monitor_ephemeral/' . $broadcastUuid;
            $directory = CockpitRecorderService::projectRoot() . '/' . $relativeDirectory;
            if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
                throw new RuntimeException('Could not create ephemeral monitor storage.');
            }
            $filename = str_pad((string)$sequence, 8, '0', STR_PAD_LEFT) . '-' . $expectedSha . '.m4a';
            $absolutePath = $directory . '/' . $filename;
            $temporary = $absolutePath . '.tmp-' . bin2hex(random_bytes(4));
            if (file_put_contents($temporary, $audioBytes, LOCK_EX) !== strlen($audioBytes)
                || !rename($temporary, $absolutePath)) {
                @unlink($temporary);
                throw new RuntimeException('Could not persist ephemeral monitor chunk.');
            }
            $this->pdo->prepare(
                'INSERT INTO ipca_cvr_monitor_chunks
                   (chunk_uuid, broadcast_id, sequence_number, started_at_utc, duration_seconds,
                    storage_path, sha256, file_size_bytes, uploaded_by_device_id, expires_at_utc)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?,
                         DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL ' . self::CHUNK_TTL_SECONDS . ' SECOND))'
            )->execute(array(
                $chunkUuid,
                (int)$broadcast['id'],
                $sequence,
                $startedAt,
                round($duration, 3),
                $relativeDirectory . '/' . $filename,
                $expectedSha,
                strlen($audioBytes),
                (int)$device['id'],
            ));
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($absolutePath !== null) {
                @unlink($absolutePath);
            }
            throw $e;
        }
        $this->cleanupExpiredChunks();
        return array('ok' => true, 'already_present' => false, 'chunk_uuid' => $chunkUuid);
    }

    public function audioPath(string $leaseUuid, string $chunkUuid, int $staffUserId): string
    {
        $this->assertSchema();
        $lease = $this->ownedLease($this->uuid($leaseUuid, 'lease_uuid'), $staffUserId, false);
        $chunkStatement = $this->pdo->prepare(
            'SELECT * FROM ipca_cvr_monitor_chunks
             WHERE chunk_uuid = ? AND broadcast_id = ? AND purged_at_utc IS NULL
               AND expires_at_utc > CURRENT_TIMESTAMP(3)
             LIMIT 1'
        );
        $chunkStatement->execute(array($this->uuid($chunkUuid, 'chunk_uuid'), (int)$lease['broadcast_id']));
        $chunk = $chunkStatement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($chunk)) {
            throw new RuntimeException('Monitor audio chunk is no longer available.');
        }
        $root = realpath(CockpitRecorderService::projectRoot() . '/storage/cvr_monitor_ephemeral');
        $path = realpath(CockpitRecorderService::projectRoot() . '/' . ltrim((string)$chunk['storage_path'], '/'));
        if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
            throw new RuntimeException('Monitor audio path is unavailable.');
        }
        return $path;
    }

    public function cleanupExpiredChunks(): int
    {
        if (!$this->tableExists('ipca_cvr_monitor_chunks')) {
            return 0;
        }
        $statement = $this->pdo->query(
            'SELECT id, storage_path FROM ipca_cvr_monitor_chunks
             WHERE purged_at_utc IS NULL AND expires_at_utc <= CURRENT_TIMESTAMP(3)
             ORDER BY id ASC LIMIT 200'
        );
        $rows = $statement ? ($statement->fetchAll(PDO::FETCH_ASSOC) ?: array()) : array();
        foreach ($rows as $row) {
            $path = CockpitRecorderService::projectRoot() . '/' . ltrim((string)$row['storage_path'], '/');
            if (is_file($path)) {
                @unlink($path);
            }
            $this->pdo->prepare(
                'UPDATE ipca_cvr_monitor_chunks SET purged_at_utc = CURRENT_TIMESTAMP(3) WHERE id = ?'
            )->execute(array((int)$row['id']));
        }
        return count($rows);
    }

    /** @return array<string,mixed> */
    private function activeDispatch(int $aircraftId, string $dispatchUuid, bool $forUpdate): array
    {
        $dispatchUuid = $this->uuid($dispatchUuid, 'claimed_dispatch_uuid');
        $statement = $this->pdo->prepare(
            "SELECT d.*, dev.device_uuid
             FROM ipca_cvr_dispatches d
             INNER JOIN ipca_cvr_devices dev ON dev.id = d.device_id
             INNER JOIN ipca_flight_schedule_slots s
               ON s.claimed_dispatch_uuid = d.dispatch_uuid
              AND s.scheduler_record_id = d.scheduler_record_id
             WHERE d.aircraft_id = ? AND d.dispatch_uuid = ?
               AND s.status = 'claimed'
               AND d.operational_session_uuid IS NOT NULL
               AND LOWER(TRIM(COALESCE(d.status, ''))) <> 'released'
             ORDER BY d.id DESC LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute(array($aircraftId, $dispatchUuid));
        $dispatch = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($dispatch)) {
            throw new RuntimeException('An active claimed Operational Session is required for live listening.');
        }
        return $dispatch;
    }

    /** @return array<string,mixed> */
    private function dispatchById(int $dispatchId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM ipca_cvr_dispatches WHERE id = ? LIMIT 1');
        $statement->execute(array($dispatchId));
        return $statement->fetch(PDO::FETCH_ASSOC) ?: array();
    }

    /** @return array<string,mixed>|null */
    private function activeBroadcast(int $dispatchId, bool $forUpdate): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM ipca_cvr_monitor_broadcasts
             WHERE dispatch_id = ? AND status = 'active'
             ORDER BY id DESC LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute(array($dispatchId));
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function broadcastById(int $id, bool $forUpdate): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM ipca_cvr_monitor_broadcasts WHERE id = ? LIMIT 1'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute(array($id));
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> */
    private function ownedLease(string $leaseUuid, int $staffUserId, bool $forUpdate): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM ipca_cvr_monitor_listener_leases
             WHERE lease_uuid = ? AND staff_user_id = ? LIMIT 1'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute(array($leaseUuid, $staffUserId));
        $lease = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($lease)) {
            throw new RuntimeException('Live monitor listener lease was not found.');
        }
        return $lease;
    }

    /** @return array<string,mixed> */
    private function leaseById(int $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM ipca_cvr_monitor_listener_leases WHERE id = ? LIMIT 1');
        $statement->execute(array($id));
        return $statement->fetch(PDO::FETCH_ASSOC) ?: array();
    }

    private function renewLease(int $leaseId, bool $reconnected): void
    {
        $this->pdo->prepare(
            'UPDATE ipca_cvr_monitor_listener_leases
             SET heartbeat_at_utc = CURRENT_TIMESTAMP(3),
                 expires_at_utc = DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL ' . self::LEASE_SECONDS . ' SECOND),
                 reconnect_count = reconnect_count + ?
             WHERE id = ?'
        )->execute(array($reconnected ? 1 : 0, $leaseId));
    }

    private function activeListenerCount(int $broadcastId): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM ipca_cvr_monitor_listener_leases
             WHERE broadcast_id = ? AND status = 'active'
               AND expires_at_utc > CURRENT_TIMESTAMP(3)"
        );
        $statement->execute(array($broadcastId));
        return (int)$statement->fetchColumn();
    }

    private function expireStaleControlState(): void
    {
        if (!$this->tableExists('ipca_cvr_monitor_listener_leases')) {
            return;
        }
        $this->pdo->exec(
            "UPDATE ipca_cvr_monitor_listener_leases
             SET status = 'expired', stopped_at_utc = COALESCE(stopped_at_utc, CURRENT_TIMESTAMP(3)),
                 stop_reason = COALESCE(stop_reason, 'heartbeat_expired')
             WHERE status = 'active' AND expires_at_utc <= CURRENT_TIMESTAMP(3)"
        );
        $this->pdo->exec(
            "UPDATE ipca_cvr_monitor_broadcasts b
             SET b.status = 'ended', b.ended_at_utc = COALESCE(b.ended_at_utc, CURRENT_TIMESTAMP(3)),
                 b.end_reason = COALESCE(b.end_reason, 'no_active_listeners')
             WHERE b.status = 'active'
               AND NOT EXISTS (
                 SELECT 1 FROM ipca_cvr_monitor_listener_leases l
                 WHERE l.broadcast_id = b.id AND l.status = 'active'
                   AND l.expires_at_utc > CURRENT_TIMESTAMP(3)
               )"
        );
        $this->cleanupExpiredChunks();
    }

    private function endBroadcast(int $broadcastId, string $reason): void
    {
        $this->pdo->prepare(
            "UPDATE ipca_cvr_monitor_broadcasts
             SET status = 'ended', ended_at_utc = CURRENT_TIMESTAMP(3), end_reason = ?
             WHERE id = ? AND status = 'active'"
        )->execute(array(substr($reason, 0, 64), $broadcastId));
    }

    /** @return array<string,mixed> */
    private function listenerPayload(array $broadcast, array $lease, array $dispatch): array
    {
        return array(
            'ok' => true,
            'lease_uuid' => (string)($lease['lease_uuid'] ?? ''),
            'broadcast_uuid' => (string)($broadcast['broadcast_uuid'] ?? ''),
            'status' => (string)($lease['status'] ?? 'active'),
            'expires_at_utc' => (string)($lease['expires_at_utc'] ?? ''),
            'heartbeat_interval_seconds' => 5,
            'lease_timeout_seconds' => self::LEASE_SECONDS,
            'active_listener_count' => $this->activeListenerCount((int)$broadcast['id']),
            'device_enabled' => $this->monitorEnabledForDevice(array(
                'id' => (int)($dispatch['device_id'] ?? 0),
                'device_uuid' => (string)($dispatch['device_uuid'] ?? ''),
            )),
        );
    }

    /** @param array<string,mixed> $device */
    private function monitorEnabledForDevice(array $device): bool
    {
        $enabled = strtolower(trim((string)(getenv('CW_CVR_LIVE_MONITOR_ENABLED') ?: '0')));
        if (!in_array($enabled, array('1', 'true', 'yes', 'on'), true)) {
            return false;
        }
        $allowlist = array_filter(array_map(
            static fn(string $value): string => strtolower(trim($value)),
            explode(',', (string)(getenv('CW_CVR_LIVE_MONITOR_DEVICE_ALLOWLIST') ?: ''))
        ));
        if ($allowlist === array()) {
            return false;
        }
        return in_array((string)(int)($device['id'] ?? 0), $allowlist, true)
            || in_array(strtolower(trim((string)($device['device_uuid'] ?? ''))), $allowlist, true);
    }

    /** @param array<string,mixed> $after */
    private function audit(
        string $action,
        string $entityId,
        int $staffUserId,
        int $organizationId,
        array $after
    ): void {
        (new AuditEventService($this->pdo))->record(
            $action,
            'ipca_cvr_monitor_listener_leases',
            $entityId,
            null,
            $after,
            'Authorized live cockpit audio listening.',
            'admin',
            $staffUserId,
            null,
            null,
            max(1, $organizationId),
            'online_scheduler'
        );
    }

    private function assertSchema(): void
    {
        foreach (array(
            'ipca_cvr_monitor_broadcasts',
            'ipca_cvr_monitor_listener_leases',
            'ipca_cvr_monitor_chunks',
        ) as $table) {
            if (!$this->tableExists($table)) {
                throw new RuntimeException('Live cockpit monitoring migration is not available.');
            }
        }
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $statement->execute(array($table));
        return (int)$statement->fetchColumn() > 0;
    }

    private function uuid(string $value, string $field): string
    {
        $value = strtolower(trim($value));
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $value)) {
            throw new InvalidArgumentException($field . ' must be a valid UUID.');
        }
        return $value;
    }

    private function dateTime(string $value): string
    {
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
        } catch (Throwable) {
            throw new InvalidArgumentException('A valid monitor chunk start time is required.');
        }
    }
}
