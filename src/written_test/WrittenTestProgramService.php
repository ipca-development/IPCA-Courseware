<?php
declare(strict_types=1);

require_once __DIR__ . '/WrittenTestSupport.php';

final class WrittenTestProgramService
{
    public function __construct(private PDO $pdo)
    {
        WrittenTestSupport::ensureSchema($this->pdo);
    }

    /** @return list<array<string,mixed>> */
    public function listPrograms(bool $includeRetired = false): array
    {
        $where = $includeRetired ? '1=1' : "program_status <> 'retired'";
        $rows = $this->pdo->query("
            SELECT p.*, c.title AS related_course_title
            FROM written_test_programs p
            LEFT JOIN courses c ON c.id = p.related_course_id
            WHERE $where
            ORDER BY FIELD(p.program_status, 'active','draft','suspended','retired'), p.display_name ASC, p.id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        return $rows ?: [];
    }

    /** @return array<string,mixed>|null */
    public function getProgram(int $programId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM written_test_programs WHERE id = ? LIMIT 1');
        $st->execute([$programId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function createProgram(array $data, int $actorUserId): int
    {
        $key = trim((string)($data['program_key'] ?? ''));
        $name = trim((string)($data['display_name'] ?? ''));
        if ($key === '' || $name === '') {
            throw new InvalidArgumentException('Program key and display name are required.');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_\\-]{2,95}$/', $key)) {
            throw new InvalidArgumentException('Program key must be lowercase letters, numbers, underscores, or hyphens.');
        }

        $st = $this->pdo->prepare("
            INSERT INTO written_test_programs
              (program_key, display_name, description, authority, certificate_type, related_course_id,
               program_status, feature_availability_state, created_by_user_id, updated_by_user_id)
            VALUES (?, ?, ?, ?, ?, ?, 'draft', 'disabled', ?, ?)
        ");
        $st->execute([
            $key,
            $name,
            trim((string)($data['description'] ?? '')) ?: null,
            trim((string)($data['authority'] ?? 'internal')) ?: 'internal',
            trim((string)($data['certificate_type'] ?? '')) ?: null,
            ((int)($data['related_course_id'] ?? 0)) > 0 ? (int)$data['related_course_id'] : null,
            $actorUserId > 0 ? $actorUserId : null,
            $actorUserId > 0 ? $actorUserId : null,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function updateProgram(int $programId, array $data, int $actorUserId): void
    {
        $name = trim((string)($data['display_name'] ?? ''));
        if ($programId <= 0 || $name === '') {
            throw new InvalidArgumentException('Program and display name are required.');
        }

        $status = trim((string)($data['program_status'] ?? 'draft'));
        if (!in_array($status, ['draft', 'active', 'suspended', 'retired'], true)) {
            $status = 'draft';
        }
        $availability = trim((string)($data['feature_availability_state'] ?? 'disabled'));
        if (!in_array($availability, ['disabled', 'preview', 'available'], true)) {
            $availability = 'disabled';
        }

        $st = $this->pdo->prepare("
            UPDATE written_test_programs
            SET display_name = ?,
                description = ?,
                authority = ?,
                certificate_type = ?,
                related_course_id = ?,
                program_status = ?,
                feature_availability_state = ?,
                updated_by_user_id = ?,
                retired_at = CASE WHEN ? = 'retired' AND retired_at IS NULL THEN UTC_TIMESTAMP() ELSE retired_at END
            WHERE id = ?
        ");
        $st->execute([
            $name,
            trim((string)($data['description'] ?? '')) ?: null,
            trim((string)($data['authority'] ?? 'internal')) ?: 'internal',
            trim((string)($data['certificate_type'] ?? '')) ?: null,
            ((int)($data['related_course_id'] ?? 0)) > 0 ? (int)$data['related_course_id'] : null,
            $status,
            $availability,
            $actorUserId > 0 ? $actorUserId : null,
            $status,
            $programId,
        ]);
    }
}
