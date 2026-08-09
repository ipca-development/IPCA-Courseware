<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';

final class MissionCatalogService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $exercise
     * @return array<string,mixed>
     */
    public function upsertMission(string $code, string $name, string $description = '', array $exercise = array(), ?int $actorUserId = null): array
    {
        $code = strtoupper(trim($code));
        $name = trim($name);
        if ($code === '' || $name === '') {
            throw new RuntimeException('Mission code and name are required.');
        }
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM ipca_missions WHERE organization_id = 1 AND code = ? LIMIT 1 FOR UPDATE');
            $stmt->execute(array($code));
            $mission = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($mission)) {
                $this->pdo->prepare('INSERT INTO ipca_missions (mission_uuid, code, name) VALUES (?, ?, ?)')
                    ->execute(array(AuditEventService::uuid(), $code, $name));
                $missionId = (int)$this->pdo->lastInsertId();
                $versionNumber = 1;
            } else {
                $missionId = (int)$mission['id'];
                $this->pdo->prepare('UPDATE ipca_missions SET name = ?, updated_at = CURRENT_TIMESTAMP(3) WHERE id = ?')
                    ->execute(array($name, $missionId));
                $versionNumber = $this->nextVersionNumber($missionId);
            }
            $this->pdo->prepare("
                INSERT INTO ipca_mission_versions
                  (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
                VALUES
                  (?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute(array($missionId, AuditEventService::uuid(), $versionNumber, $code, $name, $description, AuditEventService::jsonEncode($exercise), $actorUserId));
            $versionId = (int)$this->pdo->lastInsertId();
            $this->pdo->prepare('UPDATE ipca_missions SET current_version_id = ?, updated_at = CURRENT_TIMESTAMP(3) WHERE id = ?')
                ->execute(array($versionId, $missionId));
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $this->missionById($missionId) ?? array();
    }

    public function addAlias(int $missionId, string $alias, string $source = 'manual'): void
    {
        $alias = trim($alias);
        if ($missionId <= 0 || $alias === '') {
            throw new RuntimeException('Mission and alias are required.');
        }
        $this->pdo->prepare("
            INSERT INTO ipca_mission_aliases (mission_id, alias, source)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source)
        ")->execute(array($missionId, $alias, substr($source, 0, 64)));
    }

    public function assignMission(int $flightRecordVersionId, ?int $legVersionId, int $missionVersionId, ?string $startUtc, ?string $endUtc, string $source, float $confidence, ?int $actorUserId = null): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_flight_mission_assignments
              (assignment_uuid, flight_record_version_id, leg_version_id, mission_version_id, start_utc, end_utc, source, confidence, created_by)
            VALUES
              (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute(array(AuditEventService::uuid(), $flightRecordVersionId, $legVersionId, $missionVersionId, $startUtc, $endUtc, substr($source, 0, 64), $confidence, $actorUserId));
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listMissions(): array
    {
        $stmt = $this->pdo->query("
            SELECT m.*, v.version_number, v.description, v.exercise_json
            FROM ipca_missions m
            LEFT JOIN ipca_mission_versions v ON v.id = m.current_version_id
            ORDER BY m.code ASC
        ");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
        return is_array($rows) ? $rows : array();
    }

    /**
     * Missions for the online scheduler reservation modal: natural code order + schedule category.
     *
     * @return list<array<string,mixed>>
     */
    public function listMissionsForSchedule(): array
    {
        $rows = $this->listMissions();
        usort(
            $rows,
            static fn(array $left, array $right): int => self::compareMissionCodes(
                (string)($left['code'] ?? ''),
                (string)($right['code'] ?? '')
            )
        );
        foreach ($rows as &$row) {
            $row['schedule_category'] = self::scheduleCategoryForMission(
                (string)($row['code'] ?? ''),
                (string)($row['name'] ?? '')
            );
        }
        unset($row);

        return $rows;
    }

    /**
     * Map catalogue hour tags to reservation types used by the online scheduler.
     * Flight Training = DUAL/PIC/SOLO/NIGHT/X-C; Briefing = LB / briefing scenarios;
     * Simulator = SE/FSTD (and related device tags).
     */
    public static function scheduleCategoryForMission(string $code, string $name): ?string
    {
        $haystack = strtoupper(trim($code . ' ' . $name));
        if ($haystack === '') {
            return null;
        }

        foreach (array('FSTD', 'SIMULATOR', 'AATD', 'FNPT') as $token) {
            if (str_contains($haystack, $token)) {
                return 'simulator_training';
            }
        }

        foreach (array('BRIEFING', 'THEORY', 'MEETING', 'GROUND SCHOOL', 'CLASSROOM') as $token) {
            if (str_contains($haystack, $token)) {
                return 'briefing';
            }
        }

        if (preg_match('/\bLB\b/', $haystack) === 1) {
            $hasFlight = str_contains($haystack, 'DUAL')
                || str_contains($haystack, 'PIC')
                || str_contains($haystack, 'SOLO');
            if (!$hasFlight) {
                return 'briefing';
            }
        }

        if (
            str_contains($haystack, 'DUAL')
            || str_contains($haystack, 'PIC')
            || str_contains($haystack, 'SOLO')
            || str_contains($haystack, 'NIGHT')
            || str_contains($haystack, 'X-C')
            || preg_match('/\bXC\b/', $haystack) === 1
        ) {
            return 'flight_training';
        }

        return null;
    }

    public static function compareMissionCodes(string $left, string $right): int
    {
        $leftParts = preg_split('/[^0-9A-Za-z]+/', strtoupper(trim($left))) ?: array();
        $rightParts = preg_split('/[^0-9A-Za-z]+/', strtoupper(trim($right))) ?: array();
        $count = max(count($leftParts), count($rightParts));
        for ($i = 0; $i < $count; $i++) {
            $a = (string)($leftParts[$i] ?? '');
            $b = (string)($rightParts[$i] ?? '');
            if ($a === '' && $b === '') {
                continue;
            }
            if ($a === '') {
                return -1;
            }
            if ($b === '') {
                return 1;
            }
            $aNumeric = ctype_digit($a);
            $bNumeric = ctype_digit($b);
            if ($aNumeric && $bNumeric) {
                $cmp = ((int)$a) <=> ((int)$b);
                if ($cmp !== 0) {
                    return $cmp;
                }
                continue;
            }
            $cmp = strcmp($a, $b);
            if ($cmp !== 0) {
                return $cmp;
            }
        }

        return 0;
    }

    public static function reservationTypeRequiresMission(string $reservationType): bool
    {
        return in_array(
            strtolower(trim($reservationType)),
            array('flight_training', 'briefing', 'simulator_training'),
            true
        );
    }

    private function nextVersionNumber(int $missionId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = ?');
        $stmt->execute(array($missionId));
        return (int)$stmt->fetchColumn();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function missionById(int $missionId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_missions WHERE id = ? LIMIT 1');
        $stmt->execute(array($missionId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
