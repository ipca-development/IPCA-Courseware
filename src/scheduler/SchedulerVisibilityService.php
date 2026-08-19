<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/SchedulerApiException.php';

final class SchedulerVisibilityService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @param array<string,mixed> $user */
    public function participantScope(array $user): ?int
    {
        return cw_user_can_edit_flight_schedule($user) ? null : (int)$user['id'];
    }

    /** @param array<string,mixed> $user */
    public function requireVisibleReservationDate(
        array $user,
        int $organizationId,
        string $reservationUuid
    ): string {
        $sql = 'SELECT scheduled_date FROM ipca_flight_schedule_slots s'
            . ' WHERE s.scheduler_record_id = ? AND s.organization_id = ?';
        $params = array($reservationUuid, $organizationId);
        if (!cw_user_can_edit_flight_schedule($user)) {
            $sql .= ' AND EXISTS (
                SELECT 1 FROM ipca_flight_schedule_crew c
                 WHERE c.schedule_slot_id = s.id AND c.user_id = ?
            )';
            $params[] = (int)$user['id'];
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $date = (string)$stmt->fetchColumn();
        if ($date === '') {
            throw new SchedulerApiException('not_found', 'The reservation was not found.', 404, false, false);
        }
        return $date;
    }
}
