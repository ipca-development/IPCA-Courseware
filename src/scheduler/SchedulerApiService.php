<?php
declare(strict_types=1);

require_once __DIR__ . '/../AuditEventService.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../FlightScheduleService.php';
require_once __DIR__ . '/../MissionCatalogService.php';
require_once __DIR__ . '/../communication/CommunicationSupport.php';
require_once __DIR__ . '/SchedulerApiException.php';
require_once __DIR__ . '/SchedulerOperationalContextService.php';
require_once __DIR__ . '/SchedulerVisibilityService.php';

final class SchedulerApiService
{
    public const OPERATIONAL_TIMEZONE = 'America/Los_Angeles';
    public const MAX_RANGE_DAYS = 31;

    private FlightScheduleService $schedule;
    private SchedulerVisibilityService $visibility;
    private SchedulerOperationalContextService $operationalContext;

    public function __construct(
        private PDO $pdo,
        ?SchedulerOperationalContextService $operationalContext = null
    ) {
        $this->schedule = new FlightScheduleService($pdo);
        $this->visibility = new SchedulerVisibilityService($pdo);
        $this->operationalContext = $operationalContext
            ?? new SchedulerOperationalContextService($pdo, self::OPERATIONAL_TIMEZONE);
    }

    /** @param array<string,mixed> $session @return array<string,mixed> */
    public function bootstrap(array $session): array
    {
        $organizationId = $this->organizationId($session);
        return array(
            'ok' => true,
            'user' => CommunicationSupport::publicUser($session['user']),
            'organization' => array('id' => $organizationId),
            'capabilities' => $this->capabilities($session['user']),
            'operational_timezone' => self::OPERATIONAL_TIMEZONE,
            'operational_home_base' => $this->operationalContext->homeBase($organizationId),
            'scheduler' => array(
                'max_range_days' => self::MAX_RANGE_DAYS,
                'snap_minutes' => 15,
                'overlap_policy' => 'warning',
                'schedule_time_semantics' => 'timezone_free_operational_local',
                'recurring_reservations_supported' => false,
                'comprehensive_availability_supported' => false,
                'reservation_types' => array_map(
                    static fn(string $value, string $label): array => array(
                        'value' => $value,
                        'label' => $label,
                        'requires_mission' => MissionCatalogService::reservationTypeRequiresMission($value),
                    ),
                    array_keys($this->schedule->reservationTypes()),
                    array_values($this->schedule->reservationTypes())
                ),
            ),
        );
    }

    /** @param array<string,mixed> $user @return array<string,bool> */
    public function capabilities(array $user): array
    {
        $editor = cw_user_can_edit_flight_schedule($user);
        return array(
            'schedule_read' => true,
            'reservation_create' => $editor,
            'reservation_edit' => $editor,
            'reservation_cancel' => $editor,
            'reservation_undispatch' => false,
            'manual_checkin' => false,
            'dispatch' => false,
            'view_training' => true,
            'resource_search' => $editor,
        );
    }

    /** @param array<string,mixed> $session @param array<string,mixed> $filters @return array<string,mixed> */
    public function scheduleRange(array $session, string $start, string $end, array $filters = array()): array
    {
        [$start, $end] = $this->normalizeRange($start, $end);
        $user = $session['user'];
        $editor = cw_user_can_edit_flight_schedule($user);
        $participantUserId = $this->visibility->participantScope($user);
        $aircraftId = $editor ? ((int)($filters['aircraft_id'] ?? 0) ?: null) : null;
        $slots = $this->schedule->listSlots(
            $start,
            $end,
            $aircraftId,
            true,
            $this->organizationId($session),
            $participantUserId
        );
        $slots = array_values(array_filter($slots, static function (array $slot) use ($filters): bool {
            if (strtolower((string)($slot['status'] ?? '')) === 'cancelled') {
                return false;
            }
            $participant = (int)($filters['participant_user_id'] ?? 0);
            if ($participant > 0) {
                foreach ((array)($slot['crew'] ?? array()) as $member) {
                    if ((int)($member['person_id'] ?? 0) === $participant) {
                        return true;
                    }
                }
                return false;
            }
            $cohortId = (int)($filters['cohort_id'] ?? 0);
            if ($cohortId > 0 && (int)($slot['cohort']['id'] ?? 0) !== $cohortId) {
                return false;
            }
            $type = strtolower(trim((string)($filters['reservation_type'] ?? '')));
            return $type === '' || strtolower((string)($slot['reservation_type'] ?? '')) === $type;
        }));
        $warningsByReservation = $this->schedule->assessResourceConflictsForReservations(array_map(
            static fn(array $slot): string => (string)($slot['scheduler_record_id'] ?? ''),
            $slots
        ));
        $organizationId = $this->organizationId($session);
        return array(
            'ok' => true,
            'range' => array('start' => $start, 'end' => $end),
            'operational_timezone' => self::OPERATIONAL_TIMEZONE,
            'operational_home_base' => $this->operationalContext->homeBase($organizationId),
            'astronomy_days' => $this->operationalContext->astronomyDays(
                $organizationId,
                $start,
                $end
            ),
            'reservations' => array_map(
                function (array $slot) use ($session, $warningsByReservation): array {
                    $reservation = $this->presentReservation($slot, $session, false);
                    $recordId = strtolower((string)($slot['scheduler_record_id'] ?? ''));
                    $reservation['validation'] = $this->validationEnvelope(
                        $warningsByReservation[$recordId] ?? array()
                    );
                    return $reservation;
                },
                $slots
            ),
            'refreshed_at' => gmdate('c'),
        );
    }

    /** @param array<string,mixed> $session @return array<string,mixed> */
    public function reservationDetail(array $session, string $reservationUuid): array
    {
        $slot = $this->visibleSlot($session, $reservationUuid);
        $warnings = $this->schedule->assessResourceConflicts(
            $reservationUuid,
            (int)$slot['aircraft']['id'],
            (int)($slot['cohort']['id'] ?? 0) ?: null,
            array_values(array_filter(array_map(
                static fn(array $member): int => (int)($member['person_id'] ?? 0),
                (array)$slot['crew']
            ))),
            (string)$slot['scheduled_start_time'],
            (string)$slot['scheduled_end_time']
        );
        return array(
            'ok' => true,
            'operational_timezone' => self::OPERATIONAL_TIMEZONE,
            'reservation' => $this->presentReservation($slot, $session, true),
            'validation' => $this->validationEnvelope($warnings),
        );
    }

    /**
     * @param array<string,mixed> $session
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createReservation(array $session, array $input, string $idempotencyKey): array
    {
        $this->requireCapability($session, 'reservation_create');
        $requestHash = hash('sha256', json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $claim = $this->claimIdempotency(
            (int)$session['user']['id'],
            $this->organizationId($session),
            $idempotencyKey,
            $requestHash
        );
        $recordId = (string)$claim['reservation_uuid'];
        if (!empty($claim['already_present'])) {
            return array(
                'ok' => true,
                'already_present' => true,
                'operational_timezone' => self::OPERATIONAL_TIMEZONE,
                'reservation' => $this->presentReservation(
                    $this->visibleSlot($session, $recordId),
                    $session,
                    true
                ),
            );
        }

        try {
            [$values, $crew] = $this->mutationValues($input, $session);
            $values['scheduler_record_id'] = $recordId;
            $result = $this->schedule->saveSlot($values, $crew, (int)$session['user']['id']);
            $this->completeIdempotency((int)$session['user']['id'], $idempotencyKey);
            return array(
                'ok' => true,
                'already_present' => false,
                'operational_timezone' => self::OPERATIONAL_TIMEZONE,
                'validation' => $this->validationEnvelope((array)($result['warning_details'] ?? array())),
                'reservation' => $this->presentReservation(
                    $this->visibleSlot($session, (string)$result['scheduler_record_id']),
                    $session,
                    true
                ),
            );
        } catch (Throwable $e) {
            $this->releaseFailedIdempotency((int)$session['user']['id'], $idempotencyKey, $recordId);
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $session
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function updateReservation(array $session, string $reservationUuid, array $input): array
    {
        $this->requireCapability($session, 'reservation_edit');
        $existing = $this->visibleSlot($session, $reservationUuid);
        if (empty($existing['editable'])) {
            throw new SchedulerApiException(
                'reservation_locked',
                'This reservation can no longer be changed.',
                409,
                false,
                true
            );
        }
        [$values, $crew] = $this->mutationValues($input, $session, $existing);
        $values['scheduler_record_id'] = $reservationUuid;
        $values['expected_updated_at'] = (string)($input['expected_updated_at'] ?? '');
        if ($values['expected_updated_at'] === '') {
            throw new SchedulerApiException(
                'invalid_request',
                'expected_updated_at is required when editing a reservation.',
                400,
                false,
                true
            );
        }
        $result = $this->schedule->saveSlot($values, $crew, (int)$session['user']['id']);
        return array(
            'ok' => true,
            'operational_timezone' => self::OPERATIONAL_TIMEZONE,
            'validation' => $this->validationEnvelope((array)($result['warning_details'] ?? array())),
            'reservation' => $this->presentReservation(
                $this->visibleSlot($session, $reservationUuid),
                $session,
                true
            ),
        );
    }

    /** @param array<string,mixed> $session @param array<string,mixed> $input @return array<string,mixed> */
    public function rescheduleReservation(array $session, string $reservationUuid, array $input): array
    {
        $this->requireCapability($session, 'reservation_edit');
        $this->visibleSlot($session, $reservationUuid);
        $expectedUpdatedAt = trim((string)($input['expected_updated_at'] ?? ''));
        if ($expectedUpdatedAt === '') {
            throw new SchedulerApiException(
                'invalid_request',
                'expected_updated_at is required when rescheduling a reservation.',
                400,
                false,
                true
            );
        }
        $result = $this->schedule->rescheduleSlot(
            $reservationUuid,
            $this->requireLocalTimestamp($input['start_local'] ?? null, 'start_local'),
            $this->requireLocalTimestamp($input['end_local'] ?? null, 'end_local'),
            (int)$session['user']['id'],
            $expectedUpdatedAt,
            (int)($input['aircraft_id'] ?? 0) ?: null,
            true
        );
        return array(
            'ok' => true,
            'operational_timezone' => self::OPERATIONAL_TIMEZONE,
            'validation' => $this->validationEnvelope((array)($result['warning_details'] ?? array())),
            'reservation' => $this->presentReservation(
                $this->visibleSlot($session, $reservationUuid),
                $session,
                true
            ),
        );
    }

    /** @param array<string,mixed> $session @return array<string,mixed> */
    public function cancelReservation(array $session, string $reservationUuid, ?string $expectedUpdatedAt): array
    {
        $this->requireCapability($session, 'reservation_cancel');
        $existing = $this->visibleSlot($session, $reservationUuid);
        if (empty($existing['editable'])) {
            throw new SchedulerApiException(
                'reservation_locked',
                'This reservation can no longer be cancelled.',
                409,
                false,
                true
            );
        }
        if (trim((string)$expectedUpdatedAt) === '') {
            throw new SchedulerApiException(
                'invalid_request',
                'expected_updated_at is required when cancelling a reservation.',
                400,
                false,
                true
            );
        }
        $this->schedule->cancelSlot(
            $reservationUuid,
            (int)$session['user']['id'],
            $expectedUpdatedAt
        );
        return array(
            'ok' => true,
            'operational_timezone' => self::OPERATIONAL_TIMEZONE,
            'reservation' => $this->presentReservation(
                $this->visibleSlot($session, $reservationUuid),
                $session,
                true
            ),
        );
    }

    /** @param array<string,mixed> $session @param array<string,mixed> $input @return array<string,mixed> */
    public function validateReservation(array $session, array $input): array
    {
        $this->requireCapability($session, 'reservation_create');
        [$values, $crew] = $this->mutationValues($input, $session);
        $warnings = $this->schedule->assessResourceConflicts(
            (string)($input['reservation_uuid'] ?? ''),
            (int)$values['aircraft_id'],
            (int)($values['cohort_id'] ?? 0) ?: null,
            array_map(static fn(array $member): int => (int)$member['user_id'], $crew),
            (string)$values['scheduled_start_time'],
            (string)$values['scheduled_end_time']
        );
        return array(
            'ok' => true,
            'operational_timezone' => self::OPERATIONAL_TIMEZONE,
            'assessment_scope' => array(
                'reservation_overlaps' => true,
                'maintenance' => false,
                'staff_shifts' => false,
                'student_self_booking' => false,
            ),
            'validation' => $this->validationEnvelope($warnings),
        );
    }

    /** @param array<string,mixed> $session @return array<string,mixed> */
    public function resources(array $session, string $type, string $query = '', int $limit = 30): array
    {
        $this->requireCapability($session, 'resource_search');
        $type = strtolower(trim($type));
        $query = trim($query);
        $limit = max(1, min(50, $limit));
        if ($type === 'aircraft') {
            $stmt = $this->pdo->prepare(
                "SELECT id, registration, display_name, aircraft_type, home_airport
                   FROM ipca_aircraft_devices
                  WHERE active = 1
                    AND (? = '' OR registration LIKE ? OR display_name LIKE ? OR aircraft_type LIKE ?)
                  ORDER BY registration ASC
                  LIMIT $limit"
            );
            $like = '%' . $query . '%';
            $stmt->execute(array($query, $like, $like, $like));
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
        } elseif ($type === 'person') {
            $stmt = $this->pdo->prepare(
                "SELECT id, COALESCE(NULLIF(TRIM(name), ''), email) AS display_name, role
                   FROM users
                  WHERE status = 'active'
                    AND (account_valid_until IS NULL OR account_valid_until >= ?)
                    AND (? = '' OR name LIKE ? OR email LIKE ?)
                  ORDER BY display_name ASC
                  LIMIT $limit"
            );
            $like = '%' . $query . '%';
            $stmt->execute(array(gmdate('Y-m-d H:i:s'), $query, $like, $like));
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
        } elseif ($type === 'mission') {
            $stmt = $this->pdo->prepare(
                "SELECT id, code, name
                   FROM ipca_missions
                  WHERE organization_id = ?
                    AND (? = '' OR code LIKE ? OR name LIKE ?)
                  ORDER BY code ASC
                  LIMIT $limit"
            );
            $like = '%' . $query . '%';
            $stmt->execute(array($this->organizationId($session), $query, $like, $like));
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
            foreach ($items as &$item) {
                $item['schedule_category'] = MissionCatalogService::scheduleCategoryForMission(
                    (string)($item['code'] ?? ''),
                    (string)($item['name'] ?? '')
                );
            }
            unset($item);
        } elseif ($type === 'cohort') {
            $stmt = $this->pdo->prepare(
                "SELECT id, name
                   FROM cohorts
                  WHERE (end_date IS NULL OR end_date >= CURRENT_DATE)
                    AND (? = '' OR name LIKE ?)
                  ORDER BY name ASC
                  LIMIT $limit"
            );
            $like = '%' . $query . '%';
            $stmt->execute(array($query, $like));
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
        } else {
            throw new SchedulerApiException(
                'invalid_request',
                'resource type must be aircraft, person, mission, or cohort.',
                400,
                false,
                true
            );
        }
        return array('ok' => true, 'resource_type' => $type, 'items' => $items);
    }

    /** @param array<string,mixed> $session @return array<string,mixed> */
    public function search(array $session, string $query, int $limit = 20): array
    {
        $this->requireCapability($session, 'resource_search');
        $limit = max(1, min(50, $limit));
        $results = array();
        foreach (array('aircraft', 'person', 'mission') as $type) {
            $results[$type] = $this->resources($session, $type, $query, $limit)['items'];
        }
        return array('ok' => true, 'query' => $query, 'results' => $results);
    }

    /** @return array{0:string,1:string} */
    public function normalizeRange(string $start, string $end): array
    {
        $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', trim($start));
        $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', trim($end));
        if (!$startDate || !$endDate
            || $startDate->format('Y-m-d') !== trim($start)
            || $endDate->format('Y-m-d') !== trim($end)) {
            throw new SchedulerApiException('invalid_request', 'start and end must be valid YYYY-MM-DD dates.', 400, false, true);
        }
        if ($endDate < $startDate) {
            throw new SchedulerApiException('invalid_request', 'end must not be before start.', 400, false, true);
        }
        if ((int)$startDate->diff($endDate)->days + 1 > self::MAX_RANGE_DAYS) {
            throw new SchedulerApiException(
                'invalid_request',
                'The requested schedule range exceeds ' . self::MAX_RANGE_DAYS . ' days.',
                400,
                false,
                true
            );
        }
        return array($startDate->format('Y-m-d'), $endDate->format('Y-m-d'));
    }

    /** @param list<array<string,mixed>> $warnings @return array<string,mixed> */
    private function validationEnvelope(array $warnings): array
    {
        return array(
            'result' => $warnings === array() ? 'allowed' : 'allowed_with_warning',
            'warnings' => array_values($warnings),
        );
    }

    /** @param array<string,mixed> $session */
    private function organizationId(array $session): int
    {
        $organizationId = (int)($session['device']['organization_id'] ?? $session['user']['organization_id'] ?? 0);
        if ($organizationId <= 0) {
            throw new SchedulerApiException('forbidden', 'Organization context is unavailable.', 403, false, false);
        }
        return $organizationId;
    }

    /** @param array<string,mixed> $session */
    private function requireCapability(array $session, string $capability): void
    {
        if (empty($this->capabilities($session['user'])[$capability])) {
            throw new SchedulerApiException('forbidden', 'This account is not authorized for that scheduler action.', 403, false, false);
        }
    }

    /** @param array<string,mixed> $session @return array<string,mixed> */
    private function visibleSlot(array $session, string $reservationUuid): array
    {
        $reservationUuid = strtolower(trim($reservationUuid));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $reservationUuid)) {
            throw new SchedulerApiException('invalid_request', 'reservation_uuid must be a UUID.', 400, false, true);
        }
        $editor = cw_user_can_edit_flight_schedule($session['user']);
        $date = $this->visibility->requireVisibleReservationDate(
            $session['user'],
            $this->organizationId($session),
            $reservationUuid
        );
        $slots = $this->schedule->listSlots(
            $date,
            $date,
            null,
            true,
            $this->organizationId($session),
            $editor ? null : (int)$session['user']['id']
        );
        foreach ($slots as $slot) {
            if (strtolower((string)($slot['scheduler_record_id'] ?? '')) === $reservationUuid) {
                return $slot;
            }
        }
        throw new SchedulerApiException('not_found', 'The reservation was not found.', 404, false, false);
    }

    /** @param array<string,mixed> $slot @param array<string,mixed> $session @return array<string,mixed> */
    private function presentReservation(array $slot, array $session, bool $detail): array
    {
        $recordId = (string)$slot['scheduler_record_id'];
        $editor = cw_user_can_edit_flight_schedule($session['user']);
        $actions = array(
            'edit' => $editor && !empty($slot['editable']),
            'reschedule' => $editor && !empty($slot['editable']),
            'cancel' => $editor && !empty($slot['editable']),
            'undispatch' => false,
            'manual_checkin' => false,
            'dispatch' => false,
        );
        $presented = array(
            'reservation_uuid' => (string)($slot['reservation_uuid'] ?? $recordId),
            'scheduler_record_id' => $recordId,
            'reservation_type' => (string)$slot['reservation_type'],
            'reservation_type_label' => (string)$slot['reservation_type_label'],
            'start_local' => $this->localWithMilliseconds((string)$slot['scheduled_start_time']),
            'end_local' => $this->localWithMilliseconds((string)$slot['scheduled_end_time']),
            'operational_timezone' => self::OPERATIONAL_TIMEZONE,
            'status' => (string)$slot['status'],
            'lock' => array(
                'locked' => empty($slot['editable']),
                'reason' => $slot['lock_reason'] ?? null,
            ),
            'aircraft' => $slot['aircraft'],
            'mission' => $slot['mission'],
            'cohort' => $slot['cohort'],
            'crew' => $slot['crew'],
            'route' => array(
                'airport_chain' => $slot['airport_chain'],
                'legs' => $slot['legs'],
            ),
            'notes' => (string)$slot['notes'],
            'evidence' => $slot['evidence'],
            'updated_at' => (string)$slot['updated_at'],
            'authorized_actions' => $actions,
        );
        if ($detail && $editor) {
            $presented['dispatch_context'] = $slot['dispatch_context'];
            $presented['claimed_dispatch_uuid'] = $slot['claimed_dispatch_uuid'];
        }
        return $presented;
    }

    private function localWithMilliseconds(string $value): string
    {
        $value = str_replace(' ', 'T', trim($value));
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}$/', $value)) {
            return $value;
        }
        return substr($value, 0, 19) . '.000';
    }

    private function requireLocalTimestamp(mixed $value, string $field): string
    {
        $value = trim((string)$value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/', $value)) {
            throw new SchedulerApiException(
                'invalid_request',
                $field . ' must be a timezone-free local timestamp.',
                400,
                false,
                true
            );
        }
        return str_replace('T', ' ', $value);
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $session
     * @param array<string,mixed>|null $existing
     * @return array{0:array<string,mixed>,1:list<array<string,mixed>>}
     */
    private function mutationValues(array $input, array $session, ?array $existing = null): array
    {
        $start = $this->requireLocalTimestamp(
            $input['start_local'] ?? $existing['scheduled_start_time'] ?? null,
            'start_local'
        );
        $end = $this->requireLocalTimestamp(
            $input['end_local'] ?? $existing['scheduled_end_time'] ?? null,
            'end_local'
        );
        $values = array(
            'organization_id' => $this->organizationId($session),
            'reservation_type' => (string)($input['reservation_type'] ?? $existing['reservation_type'] ?? 'flight_training'),
            'aircraft_id' => (int)($input['aircraft_id'] ?? $existing['aircraft']['id'] ?? 0),
            'mission_id' => (int)($input['mission_id'] ?? $existing['mission']['id'] ?? 0) ?: null,
            'cohort_id' => (int)($input['cohort_id'] ?? $existing['cohort']['id'] ?? 0) ?: null,
            'scheduled_date' => substr($start, 0, 10),
            'scheduled_start_time' => $start,
            'scheduled_end_time' => $end,
            'airport_chain' => $input['airport_chain'] ?? $existing['airport_chain'] ?? array(),
            'legs' => $input['legs'] ?? $existing['legs'] ?? array(),
            'notes' => (string)($input['notes'] ?? $existing['notes'] ?? ''),
            'status' => (string)($existing['status'] ?? 'scheduled'),
        );
        if (is_array($input['crew'] ?? null)) {
            $crewInput = $input['crew'];
        } else {
            $crewInput = array();
            foreach ((array)($existing['crew'] ?? array()) as $index => $member) {
                $crewInput[] = array(
                    'user_id' => (int)($member['person_id'] ?? 0),
                    'role' => (string)($member['role'] ?? ''),
                    'pilot_function' => (string)($member['pilot_function'] ?? 'NONE'),
                    'is_pic' => (bool)($member['is_pic'] ?? false),
                    'is_primary_customer' => (bool)($member['is_primary_customer'] ?? ($index === 0)),
                );
            }
        }
        $crew = $this->crewWithTrustedNames($crewInput);
        return array($values, $crew);
    }

    /** @param array<mixed> $crewInput @return list<array<string,mixed>> */
    private function crewWithTrustedNames(array $crewInput): array
    {
        $normalized = array_values(array_filter($crewInput, static fn($member): bool => is_array($member)));
        $ids = array_values(array_unique(array_filter(array_map(
            static fn(array $member): int => (int)($member['user_id'] ?? 0),
            $normalized
        ))));
        if ($ids === array()) {
            throw new SchedulerApiException('validation_failed', 'Reservation crew is required.', 422, false, true);
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, COALESCE(NULLIF(TRIM(name), ''), email) AS display_name
               FROM users WHERE id IN ($placeholders)"
        );
        $stmt->execute($ids);
        $names = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $names[(int)$row['id']] = (string)$row['display_name'];
        }
        $crew = array();
        foreach ($normalized as $index => $member) {
            $userId = (int)($member['user_id'] ?? 0);
            if ($userId <= 0 || !isset($names[$userId])) {
                throw new SchedulerApiException('validation_failed', 'Every crew position must use a valid user account.', 422, false, true);
            }
            $crew[] = array(
                'user_id' => $userId,
                'person_name' => $names[$userId],
                'role' => strtolower(trim((string)($member['role'] ?? ($index === 0 ? 'student' : '')))),
                'pilot_function' => (string)($member['pilot_function'] ?? ($index === 0 ? 'PF' : 'NONE')),
                'is_pic' => (bool)($member['is_pic'] ?? false),
                'is_primary_customer' => (bool)($member['is_primary_customer'] ?? ($index === 0)),
            );
        }
        return $crew;
    }

    /** @return array{reservation_uuid:string,already_present:bool} */
    private function claimIdempotency(
        int $actorUserId,
        int $organizationId,
        string $key,
        string $requestHash
    ): array {
        $reservationUuid = AuditEventService::uuid();
        $now = CommunicationSupport::nowUtc();
        try {
            $this->pdo->prepare(
                "INSERT INTO ipca_scheduler_api_mutations
                    (actor_user_id, organization_id, idempotency_key, request_sha256,
                     reservation_uuid, status, created_at_utc, updated_at_utc)
                 VALUES (?, ?, ?, ?, ?, 'processing', ?, ?)"
            )->execute(array($actorUserId, $organizationId, $key, $requestHash, $reservationUuid, $now, $now));
            return array('reservation_uuid' => $reservationUuid, 'already_present' => false);
        } catch (PDOException $e) {
            if ((string)$e->getCode() !== '23000') {
                throw $e;
            }
        }
        $stmt = $this->pdo->prepare(
            'SELECT request_sha256, reservation_uuid, status, updated_at_utc
               FROM ipca_scheduler_api_mutations
              WHERE actor_user_id = ? AND idempotency_key = ? LIMIT 1'
        );
        $stmt->execute(array($actorUserId, $key));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new SchedulerApiException('server_error', 'The idempotency receipt could not be loaded.', 500, true, false);
        }
        if (!hash_equals((string)$row['request_sha256'], $requestHash)) {
            throw new SchedulerApiException(
                'idempotency_conflict',
                'This Idempotency-Key was already used for a different request.',
                409,
                false,
                true
            );
        }
        $recordId = (string)$row['reservation_uuid'];
        $exists = $this->pdo->prepare(
            'SELECT 1 FROM ipca_flight_schedule_slots WHERE scheduler_record_id = ? LIMIT 1'
        );
        $exists->execute(array($recordId));
        if ($exists->fetchColumn() === false) {
            $updatedAt = strtotime((string)($row['updated_at_utc'] ?? '') . ' UTC');
            if ($updatedAt !== false && $updatedAt <= time() - 120) {
                $now = CommunicationSupport::nowUtc();
                $this->pdo->prepare(
                    "UPDATE ipca_scheduler_api_mutations
                        SET status = 'processing', updated_at_utc = ?
                      WHERE actor_user_id = ? AND idempotency_key = ?
                        AND request_sha256 = ? AND reservation_uuid = ?"
                )->execute(array($now, $actorUserId, $key, $requestHash, $recordId));
                return array('reservation_uuid' => $recordId, 'already_present' => false);
            }
            throw new SchedulerApiException(
                'request_in_progress',
                'The original reservation request is still being processed.',
                409,
                true,
                false
            );
        }
        $this->completeIdempotency($actorUserId, $key);
        return array('reservation_uuid' => $recordId, 'already_present' => true);
    }

    private function completeIdempotency(int $actorUserId, string $key): void
    {
        $now = CommunicationSupport::nowUtc();
        $this->pdo->prepare(
            "UPDATE ipca_scheduler_api_mutations
                SET status = 'completed', completed_at_utc = ?, updated_at_utc = ?
              WHERE actor_user_id = ? AND idempotency_key = ?"
        )->execute(array($now, $now, $actorUserId, $key));
    }

    private function releaseFailedIdempotency(int $actorUserId, string $key, string $reservationUuid): void
    {
        $exists = $this->pdo->prepare(
            'SELECT 1 FROM ipca_flight_schedule_slots WHERE scheduler_record_id = ? LIMIT 1'
        );
        $exists->execute(array($reservationUuid));
        if ($exists->fetchColumn() === false) {
            $this->pdo->prepare(
                'DELETE FROM ipca_scheduler_api_mutations
                  WHERE actor_user_id = ? AND idempotency_key = ? AND status = ?'
            )->execute(array($actorUserId, $key, 'processing'));
        }
    }
}
