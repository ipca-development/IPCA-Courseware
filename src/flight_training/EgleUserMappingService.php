<?php
declare(strict_types=1);

require_once __DIR__ . '/EgleConnectionService.php';

final class EgleUserMappingService
{
    public function __construct(private PDO $pdo, private EgleConnectionService $connection)
    {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function mappingForIpcaUser(int $ipcaUserId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_egle_user_mappings WHERE ipca_user_id = :id LIMIT 1');
        $stmt->execute(array(':id' => $ipcaUserId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function allMappings(): array
    {
        $stmt = $this->pdo->query("
            SELECT m.*, u.name AS ipca_name, u.email AS ipca_email
            FROM ipca_egle_user_mappings m
            LEFT JOIN users u ON u.id = m.ipca_user_id
            ORDER BY m.updated_at DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function searchEgleUsers(PDO $eglePdo, string $query, ?int $ipcaUserId = null): array
    {
        $query = trim($query);
        if ($query === '' && $ipcaUserId !== null && $ipcaUserId > 0) {
            $stmt = $this->pdo->prepare('SELECT name, email FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(array(':id' => $ipcaUserId));
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($user)) {
                $query = trim((string)($user['email'] ?? '')) ?: trim((string)($user['name'] ?? ''));
            }
        }
        if ($query === '') {
            throw new RuntimeException('Search query or IPCA student is required.');
        }

        $columns = $this->connection->tableColumns($eglePdo, 'users');
        $useridCol = $this->firstExisting($columns, array('userid', 'user_id', 'id'));
        $emailCol = $this->firstExisting($columns, array('email', 'user_email', 'u_email'));
        $firstCol = $this->firstExisting($columns, array('firstname', 'first_name', 'fname', 'name_first'));
        $lastCol = $this->firstExisting($columns, array('lastname', 'last_name', 'lname', 'name_last'));
        $nameCol = $this->firstExisting($columns, array('name', 'fullname', 'full_name', 'display_name'));
        if ($useridCol === '') {
            throw new RuntimeException('E-GLE users table does not expose a recognizable userid column.');
        }

        $selects = array(
            $this->q($useridCol) . ' AS egle_userid',
            $emailCol !== '' ? $this->q($emailCol) . ' AS egle_email' : "'' AS egle_email",
            $nameCol !== ''
                ? $this->q($nameCol) . ' AS egle_full_name'
                : "TRIM(CONCAT(COALESCE(" . ($firstCol !== '' ? $this->q($firstCol) : "''") . ", ''), ' ', COALESCE(" . ($lastCol !== '' ? $this->q($lastCol) : "''") . ", ''))) AS egle_full_name",
        );
        $where = array();
        foreach (array($emailCol, $firstCol, $lastCol, $nameCol) as $column) {
            if ($column !== '') {
                $where[] = $this->q($column) . ' LIKE :q';
            }
        }
        if ($where === array()) {
            $where[] = $this->q($useridCol) . ' LIKE :q';
        }

        $rows = $this->connection->selectRows($eglePdo, "
            SELECT " . implode(', ', $selects) . "
            FROM users
            WHERE " . implode(' OR ', $where) . "
            ORDER BY egle_full_name ASC
            LIMIT 25
        ", array(':q' => '%' . $query . '%'));

        return array_map(function (array $row) use ($query): array {
            $confidence = $this->confidence($row, $query);
            return array(
                'egle_userid' => (string)($row['egle_userid'] ?? ''),
                'egle_email' => (string)($row['egle_email'] ?? ''),
                'egle_full_name' => trim((string)($row['egle_full_name'] ?? '')),
                'confidence_score' => $confidence,
            );
        }, $rows);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function saveMapping(array $data, int $actorUserId): array
    {
        $ipcaUserId = (int)($data['ipca_user_id'] ?? 0);
        $egleUserid = trim((string)($data['egle_userid'] ?? ''));
        if ($ipcaUserId <= 0 || $egleUserid === '') {
            throw new RuntimeException('IPCA student and E-GLE userid are required.');
        }
        $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare('DELETE FROM ipca_egle_user_mappings WHERE ipca_user_id = :ipca_user_id OR egle_userid = :egle_userid');
            $delete->execute(array(':ipca_user_id' => $ipcaUserId, ':egle_userid' => $egleUserid));
            $stmt = $this->pdo->prepare("
                INSERT INTO ipca_egle_user_mappings
                  (ipca_user_id, egle_userid, egle_email, egle_full_name, mapping_type, confidence_score, confirmed_by_user_id, confirmed_at)
                VALUES
                  (:ipca_user_id, :egle_userid, :egle_email, :egle_full_name, :mapping_type, :confidence_score, :confirmed_by_user_id, CURRENT_TIMESTAMP)
            ");
            $stmt->execute(array(
                ':ipca_user_id' => $ipcaUserId,
                ':egle_userid' => $egleUserid,
                ':egle_email' => trim((string)($data['egle_email'] ?? '')) ?: null,
                ':egle_full_name' => trim((string)($data['egle_full_name'] ?? '')) ?: null,
                ':mapping_type' => trim((string)($data['mapping_type'] ?? 'confirmed')) ?: 'confirmed',
                ':confidence_score' => isset($data['confidence_score']) ? (float)$data['confidence_score'] : null,
                ':confirmed_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
            ));
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return $this->mappingForIpcaUser($ipcaUserId) ?? array();
    }

    public function deleteMapping(int $ipcaUserId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ipca_egle_user_mappings WHERE ipca_user_id = :id');
        $stmt->execute(array(':id' => $ipcaUserId));
    }

    /**
     * @param list<string> $columns
     * @param list<string> $candidates
     */
    private function firstExisting(array $columns, array $candidates): string
    {
        $lookup = array_change_key_case(array_flip($columns), CASE_LOWER);
        foreach ($candidates as $candidate) {
            if (array_key_exists(strtolower($candidate), $lookup)) {
                return $columns[(int)$lookup[strtolower($candidate)]];
            }
        }
        return '';
    }

    private function q(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function confidence(array $row, string $query): float
    {
        $query = strtolower(trim($query));
        $email = strtolower((string)($row['egle_email'] ?? ''));
        $name = strtolower((string)($row['egle_full_name'] ?? ''));
        if ($query !== '' && $email !== '' && $query === $email) {
            return 1.0;
        }
        if ($query !== '' && ($email !== '' && str_contains($email, $query))) {
            return 0.85;
        }
        if ($query !== '' && ($name !== '' && str_contains($name, $query))) {
            return 0.75;
        }
        return 0.5;
    }
}
