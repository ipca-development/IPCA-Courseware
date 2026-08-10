<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';

final class CvrCrewMessageService
{
    private const TERMINAL_STATUSES = "'completed','cancelled','released'";

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,mixed> $sender @return array<string,mixed> */
    public function sendForAircraft(
        int $aircraftId,
        string $claimedDispatchUuid,
        string $body,
        array $sender
    ): array {
        $organizationId = $this->organizationForAircraftClaim($aircraftId, $claimedDispatchUuid);
        return $this->send($organizationId, $claimedDispatchUuid, $body, $sender);
    }

    /** @param array<string,mixed> $sender @return array<string,mixed> */
    public function send(
        int $organizationId,
        string $claimedDispatchUuid,
        string $body,
        array $sender
    ): array {
        if ($organizationId <= 0) {
            throw new InvalidArgumentException('organization_id is required.');
        }
        $claimedDispatchUuid = $this->uuid($claimedDispatchUuid, 'claimed_dispatch_uuid');
        $body = trim($body);
        if ($body === '') {
            throw new InvalidArgumentException('Message body is required.');
        }
        if (mb_strlen($body, 'UTF-8') > 512) {
            throw new InvalidArgumentException('Message body cannot exceed 512 characters.');
        }
        $senderUserId = (int)($sender['id'] ?? 0);
        if ($senderUserId <= 0) {
            throw new InvalidArgumentException('An authenticated sender is required.');
        }

        $this->pdo->beginTransaction();
        try {
            $scope = $this->activeScope($organizationId, $claimedDispatchUuid, true);
            $messageUuid = AuditEventService::uuid();
            $statement = $this->pdo->prepare(
                'INSERT INTO ipca_cvr_crew_messages
                 (message_uuid, organization_id, aircraft_id, device_id,
                  operational_session_uuid, workflow_flight_record_uuid, dispatch_uuid,
                  sender_user_id, sender_name, sender_role, body)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->execute(array(
                $messageUuid,
                $organizationId,
                (int)$scope['aircraft_id'],
                (int)$scope['device_id'],
                (string)$scope['operational_session_uuid'],
                (string)$scope['workflow_flight_record_uuid'],
                (string)$scope['dispatch_uuid'],
                $senderUserId,
                mb_substr(trim((string)($sender['name'] ?? '')), 0, 255),
                mb_substr(trim((string)($sender['role'] ?? '')), 0, 64),
                $body,
            ));
            $message = $this->messageById((int)$this->pdo->lastInsertId());
            $this->pdo->commit();
            return array('ok' => true, 'message' => $message);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public function activeStatus(int $organizationId, string $claimedDispatchUuid): array
    {
        $scope = $this->activeScope(
            $organizationId,
            $this->uuid($claimedDispatchUuid, 'claimed_dispatch_uuid')
        );
        return array(
            'ok' => true,
            'active_session' => true,
            'active' => true,
            'scope' => $scope,
            'messages' => $this->historyByOperationalSession(
                $organizationId,
                (string)$scope['operational_session_uuid']
            ),
        );
    }

    /** @return array<string,mixed> */
    public function activeStatusForAircraft(int $aircraftId, string $claimedDispatchUuid): array
    {
        $organizationId = $this->organizationForAircraftClaim($aircraftId, $claimedDispatchUuid);
        return $this->activeStatus($organizationId, $claimedDispatchUuid);
    }

    /** @param array<string,mixed> $device @return array<string,mixed> */
    public function pendingForDevice(array $device, string $operationalSessionUuid): array
    {
        $serverResolved = trim($operationalSessionUuid) === '';
        try {
            $scope = $this->activeDeviceSession($device, $operationalSessionUuid);
        } catch (RuntimeException $e) {
            if (!$serverResolved) {
                throw $e;
            }
            return array(
                'ok' => true,
                'active_session' => false,
                'operational_session_uuid' => null,
                'messages' => array(),
            );
        }
        $statement = $this->pdo->prepare(
            'SELECT m.message_uuid, m.operational_session_uuid, m.workflow_flight_record_uuid,
                    m.dispatch_uuid, m.sender_name, m.sender_role, m.body, m.sent_at_utc
             FROM ipca_cvr_crew_messages m
             LEFT JOIN ipca_cvr_crew_message_acknowledgements a
               ON a.message_id = m.id AND a.device_id = ?
             WHERE m.organization_id = ?
               AND m.aircraft_id = ?
               AND m.device_id = ?
               AND m.operational_session_uuid = ?
               AND m.workflow_flight_record_uuid = ?
               AND a.id IS NULL
             ORDER BY m.sent_at_utc ASC, m.id ASC'
        );
        $statement->execute(array(
            (int)$scope['device_id'],
            (int)$scope['organization_id'],
            (int)$scope['aircraft_id'],
            (int)$scope['device_id'],
            (string)$scope['operational_session_uuid'],
            (string)$scope['workflow_flight_record_uuid'],
        ));
        $messages = $statement->fetchAll(PDO::FETCH_ASSOC) ?: array();
        foreach ($messages as &$message) {
            $message['sent_at_utc'] = $this->isoTimestamp((string)$message['sent_at_utc']);
        }
        unset($message);
        return array(
            'ok' => true,
            'active_session' => true,
            'operational_session_uuid' => (string)$scope['operational_session_uuid'],
            'messages' => $messages,
        );
    }

    /** @param array<string,mixed> $device @param array<string,mixed> $payload @return array<string,mixed> */
    public function acknowledge(array $device, array $payload): array
    {
        $messageUuid = $this->uuid((string)($payload['message_uuid'] ?? ''), 'message_uuid');
        $ackUuid = $this->uuid(
            (string)($payload['acknowledgement_uuid'] ?? ''),
            'acknowledgement_uuid'
        );
        $sessionUuid = $this->uuid(
            (string)($payload['operational_session_uuid'] ?? ''),
            'operational_session_uuid'
        );
        $deviceEventAt = $this->utcTimestamp(
            (string)($payload['device_event_at_utc'] ?? ''),
            'device_event_at_utc'
        );
        $message = $this->messageForAcknowledgement($device, $messageUuid, $sessionUuid);
        if ($message === null) {
            throw new RuntimeException('Message does not belong to this device and Operational Session.');
        }

        $deviceId = (int)($device['id'] ?? 0);
        $existing = $this->acknowledgement($messageUuid, $deviceId);
        if ($existing !== null) {
            return $this->acknowledgementResponse($existing, true);
        }
        $eventEpoch = (new DateTimeImmutable($deviceEventAt, new DateTimeZone('UTC')))->getTimestamp();
        $sentEpoch = (new DateTimeImmutable((string)$message['sent_at_utc'], new DateTimeZone('UTC')))->getTimestamp();
        if ($eventEpoch < $sentEpoch) {
            throw new InvalidArgumentException('Acknowledgement time cannot precede the message.');
        }
        if ($eventEpoch > time() + 300) {
            throw new InvalidArgumentException('Acknowledgement time is too far in the future.');
        }
        $closedAt = trim((string)($message['avionics_off_utc'] ?? ''));
        if ($closedAt !== '') {
            $closedEpoch = (new DateTimeImmutable($closedAt, new DateTimeZone('UTC')))->getTimestamp();
            if ($eventEpoch > $closedEpoch + 5) {
                throw new RuntimeException(
                    'Message acknowledgement occurred after the Operational Session closed.'
                );
            }
        }

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO ipca_cvr_crew_message_acknowledgements
                 (acknowledgement_uuid, message_id, message_uuid, organization_id,
                  aircraft_id, device_id, operational_session_uuid,
                  workflow_flight_record_uuid, device_event_at_utc)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->execute(array(
                $ackUuid,
                (int)$message['id'],
                $messageUuid,
                (int)$message['organization_id'],
                (int)$message['aircraft_id'],
                $deviceId,
                (string)$message['operational_session_uuid'],
                (string)$message['workflow_flight_record_uuid'],
                $deviceEventAt,
            ));
        } catch (PDOException $e) {
            $existing = $this->acknowledgement($messageUuid, $deviceId);
            if ($existing === null) {
                throw $e;
            }
            return $this->acknowledgementResponse($existing, true);
        }

        $acknowledgement = $this->acknowledgement($messageUuid, $deviceId);
        if ($acknowledgement === null) {
            throw new RuntimeException('Acknowledgement receipt could not be loaded.');
        }
        return $this->acknowledgementResponse($acknowledgement, false);
    }

    /** @return list<array<string,mixed>> */
    public function historyByOperationalSession(int $organizationId, string $operationalSessionUuid): array
    {
        if ($organizationId <= 0) {
            throw new InvalidArgumentException('organization_id is required.');
        }
        $operationalSessionUuid = $this->uuid($operationalSessionUuid, 'operational_session_uuid');
        $statement = $this->pdo->prepare(
            'SELECT m.message_uuid, m.organization_id, m.aircraft_id, m.device_id,
                    m.operational_session_uuid, m.workflow_flight_record_uuid, m.dispatch_uuid,
                    m.sender_user_id, m.sender_name, m.sender_role, m.body, m.sent_at_utc,
                    a.acknowledgement_uuid, a.device_event_at_utc, a.server_received_at_utc
             FROM ipca_cvr_crew_messages m
             LEFT JOIN ipca_cvr_crew_message_acknowledgements a
               ON a.message_id = m.id AND a.device_id = m.device_id
             WHERE m.organization_id = ? AND m.operational_session_uuid = ?
             ORDER BY m.sent_at_utc ASC, m.id ASC'
        );
        $statement->execute(array($organizationId, $operationalSessionUuid));
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: array();
        foreach ($rows as &$row) {
            $row['body_text'] = (string)$row['body'];
            $row['sent_at_utc'] = $this->isoTimestamp((string)$row['sent_at_utc']);
            if (trim((string)($row['device_event_at_utc'] ?? '')) !== '') {
                $row['acknowledged_at_utc'] = $this->isoTimestamp((string)$row['device_event_at_utc']);
                $row['device_event_at_utc'] = $row['acknowledged_at_utc'];
            }
            if (trim((string)($row['server_received_at_utc'] ?? '')) !== '') {
                $row['server_received_at_utc'] = $this->isoTimestamp(
                    (string)$row['server_received_at_utc']
                );
            }
        }
        unset($row);
        return $rows;
    }

    private function organizationForAircraftClaim(int $aircraftId, string $dispatchUuid): int
    {
        if ($aircraftId <= 0) {
            throw new InvalidArgumentException('aircraft_id is required.');
        }
        $dispatchUuid = $this->uuid($dispatchUuid, 'claimed_dispatch_uuid');
        $statement = $this->pdo->prepare(
            'SELECT d.organization_id
             FROM ipca_flight_schedule_slots slot
             INNER JOIN ipca_cvr_dispatches d ON d.dispatch_uuid = slot.claimed_dispatch_uuid
             WHERE slot.claimed_dispatch_uuid = ?
               AND d.aircraft_id = ?
               AND slot.organization_id = d.organization_id
             LIMIT 1'
        );
        $statement->execute(array($dispatchUuid, $aircraftId));
        $organizationId = (int)($statement->fetchColumn() ?: 0);
        if ($organizationId <= 0) {
            throw new RuntimeException('The claimed Dispatch does not belong to the selected aircraft.');
        }
        return $organizationId;
    }

    /** @return array<string,mixed> */
    private function activeScope(int $organizationId, string $dispatchUuid, bool $lock = false): array
    {
        if ($organizationId <= 0) {
            throw new InvalidArgumentException('organization_id is required.');
        }
        $sql =
            'SELECT d.organization_id, d.aircraft_id, d.device_id,
                    d.dispatch_uuid, d.workflow_flight_record_uuid,
                    d.operational_session_uuid, d.status AS dispatch_status,
                    s.status AS session_status
             FROM ipca_flight_schedule_slots slot
             INNER JOIN ipca_cvr_dispatches d
               ON d.dispatch_uuid = slot.claimed_dispatch_uuid
             INNER JOIN ipca_flight_sessions s
               ON s.session_uuid = d.operational_session_uuid
             INNER JOIN ipca_cvr_devices device
               ON device.id = d.device_id
             WHERE slot.claimed_dispatch_uuid = ?
               AND slot.organization_id = ?
               AND d.organization_id = ?
               AND s.organization_id = ?
               AND slot.status NOT IN (' . self::TERMINAL_STATUSES . ')
               AND d.status NOT IN (' . self::TERMINAL_STATUSES . ')
               AND s.status NOT IN (' . self::TERMINAL_STATUSES . ')
               AND d.operational_session_uuid IS NOT NULL
               AND d.workflow_flight_record_uuid IS NOT NULL
               AND d.aircraft_id IS NOT NULL
               AND s.device_id = d.device_id
               AND s.aircraft_id = d.aircraft_id
               AND device.organization_id = d.organization_id
               AND device.aircraft_id = d.aircraft_id
               AND device.active = 1
               AND device.revoked_at IS NULL
             LIMIT 1';
        if ($lock && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute(array($dispatchUuid, $organizationId, $organizationId, $organizationId));
        $scope = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($scope)) {
            throw new RuntimeException(
                'The claimed Dispatch does not resolve to an active Operational Session and assigned device.'
            );
        }
        return $scope;
    }

    /** @param array<string,mixed> $device @return array<string,mixed> */
    private function activeDeviceSession(array $device, string $operationalSessionUuid): array
    {
        $operationalSessionUuid = strtolower(trim($operationalSessionUuid));
        if ($operationalSessionUuid !== '') {
            $operationalSessionUuid = $this->uuid(
                $operationalSessionUuid,
                'operational_session_uuid'
            );
        }
        $deviceId = (int)($device['id'] ?? 0);
        $organizationId = (int)($device['organization_id'] ?? 0);
        $aircraftId = (int)($device['aircraft_id'] ?? 0);
        if ($deviceId <= 0 || $organizationId <= 0 || $aircraftId <= 0) {
            throw new RuntimeException('Authenticated device assignment is incomplete.');
        }
        $sessionFilter = $operationalSessionUuid !== ''
            ? ' AND d.operational_session_uuid = ?'
            : '';
        $statement = $this->pdo->prepare(
            'SELECT d.organization_id, d.aircraft_id, d.device_id, d.dispatch_uuid,
                    d.workflow_flight_record_uuid, d.operational_session_uuid
             FROM ipca_cvr_dispatches d
             INNER JOIN ipca_flight_sessions s ON s.session_uuid = d.operational_session_uuid
             INNER JOIN ipca_flight_schedule_slots slot
               ON slot.claimed_dispatch_uuid = d.dispatch_uuid
             WHERE d.organization_id = ?
               AND d.aircraft_id = ?
               AND d.device_id = ?
               ' . $sessionFilter . '
               AND s.organization_id = d.organization_id
               AND s.aircraft_id = d.aircraft_id
               AND s.device_id = d.device_id
               AND slot.organization_id = d.organization_id
               AND slot.status NOT IN (' . self::TERMINAL_STATUSES . ')
               AND d.status NOT IN (' . self::TERMINAL_STATUSES . ')
               AND s.status NOT IN (' . self::TERMINAL_STATUSES . ')
             ORDER BY d.last_received_at DESC, d.id DESC
             LIMIT 1'
        );
        $parameters = array($organizationId, $aircraftId, $deviceId);
        if ($operationalSessionUuid !== '') {
            $parameters[] = $operationalSessionUuid;
        }
        $statement->execute($parameters);
        $scope = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($scope)) {
            throw new RuntimeException('No matching active Operational Session is assigned to this device.');
        }
        return $scope;
    }

    /** @param array<string,mixed> $scope @return array<string,mixed>|null */
    private function messageForDevice(string $messageUuid, array $scope): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM ipca_cvr_crew_messages
             WHERE message_uuid = ? AND organization_id = ? AND aircraft_id = ?
               AND device_id = ? AND operational_session_uuid = ?
               AND workflow_flight_record_uuid = ?
             LIMIT 1'
        );
        $statement->execute(array(
            $messageUuid,
            (int)$scope['organization_id'],
            (int)$scope['aircraft_id'],
            (int)$scope['device_id'],
            (string)$scope['operational_session_uuid'],
            (string)$scope['workflow_flight_record_uuid'],
        ));
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * A locally persisted acknowledgement may arrive after closure. Its immutable
     * device event timestamp determines whether it occurred during the session.
     *
     * @param array<string,mixed> $device
     * @return array<string,mixed>|null
     */
    private function messageForAcknowledgement(
        array $device,
        string $messageUuid,
        string $operationalSessionUuid
    ): ?array {
        $deviceId = (int)($device['id'] ?? 0);
        $organizationId = (int)($device['organization_id'] ?? 0);
        $aircraftId = (int)($device['aircraft_id'] ?? 0);
        if ($deviceId <= 0 || $organizationId <= 0 || $aircraftId <= 0) {
            throw new RuntimeException('Authenticated device assignment is incomplete.');
        }
        $statement = $this->pdo->prepare(
            'SELECT m.*, s.status AS session_status, s.avionics_off_utc
             FROM ipca_cvr_crew_messages m
             INNER JOIN ipca_flight_sessions s
               ON s.session_uuid = m.operational_session_uuid
              AND s.organization_id = m.organization_id
              AND s.device_id = m.device_id
              AND s.aircraft_id = m.aircraft_id
             WHERE m.message_uuid = ?
               AND m.operational_session_uuid = ?
               AND m.organization_id = ?
               AND m.aircraft_id = ?
               AND m.device_id = ?
             LIMIT 1'
        );
        $statement->execute(array(
            $messageUuid,
            $operationalSessionUuid,
            $organizationId,
            $aircraftId,
            $deviceId,
        ));
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function acknowledgement(string $messageUuid, int $deviceId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT acknowledgement_uuid, message_uuid, device_event_at_utc, server_received_at_utc
             FROM ipca_cvr_crew_message_acknowledgements
             WHERE message_uuid = ? AND device_id = ? LIMIT 1'
        );
        $statement->execute(array($messageUuid, $deviceId));
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $row['device_event_at_utc'] = $this->isoTimestamp((string)$row['device_event_at_utc']);
            $row['server_received_at_utc'] = $this->isoTimestamp(
                (string)$row['server_received_at_utc']
            );
        }
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $acknowledgement @return array<string,mixed> */
    private function acknowledgementResponse(array $acknowledgement, bool $alreadyAcknowledged): array
    {
        return array(
            'ok' => true,
            'acknowledged' => true,
            'already_acknowledged' => $alreadyAcknowledged,
            'message_uuid' => (string)$acknowledgement['message_uuid'],
            'acknowledgement_uuid' => (string)$acknowledgement['acknowledgement_uuid'],
            'acknowledgement' => $acknowledgement,
        );
    }

    /** @return array<string,mixed> */
    private function messageById(int $id): array
    {
        $statement = $this->pdo->prepare(
            'SELECT message_uuid, organization_id, aircraft_id, device_id,
                    operational_session_uuid, workflow_flight_record_uuid, dispatch_uuid,
                    sender_user_id, sender_name, sender_role, body, sent_at_utc
             FROM ipca_cvr_crew_messages WHERE id = ? LIMIT 1'
        );
        $statement->execute(array($id));
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: array();
        if (isset($row['sent_at_utc'])) {
            $row['sent_at_utc'] = $this->isoTimestamp((string)$row['sent_at_utc']);
        }
        return $row;
    }

    private function uuid(string $value, string $field): string
    {
        $value = strtolower(trim($value));
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $value)) {
            throw new InvalidArgumentException($field . ' must be a valid UUID.');
        }
        return $value;
    }

    private function utcTimestamp(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException($field . ' is required.');
        }
        try {
            return (new DateTimeImmutable($value))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s.v');
        } catch (Throwable) {
            throw new InvalidArgumentException($field . ' must be a valid timestamp.');
        }
    }

    private function isoTimestamp(string $value): string
    {
        if (trim($value) === '') {
            return '';
        }
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.v\Z');
        } catch (Throwable) {
            return $value;
        }
    }
}
