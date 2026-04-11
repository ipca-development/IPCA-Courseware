<?php
declare(strict_types=1);

require_once __DIR__ . '/courseware_progression_v2_core.php';
require_once __DIR__ . '/courseware_progression_v2_support.php';

final class CoursewareProgressionV2
{
    use CoursewareProgressionV2Core;
    use CoursewareProgressionV2Support;

    public const LOGIC_VERSION = 'v2.0';
    public const NOTIFICATION_CHANNEL_EMAIL = 'email';

    public function __construct(
        private readonly PDO $pdo
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }
}

/**
 * ============================================================
 * SAFE AUTOMATION PDO WRAPPER
 * ============================================================
 *
 * Prevents AutomationRuntime from modifying canonical tables.
 */
final class SafeAutomationPDO
{
    private PDO $inner;

    private array $blockedTables = [
        'progress_tests_v2',
        'student_required_actions',
        'lesson_activity',
        'training_progression_events',
        'student_lesson_deadline_overrides',
        'lesson_summaries',
    ];

    public function __construct(PDO $pdo)
    {
        $this->inner = $pdo;
    }

    public function prepare($query, $options = []): PDOStatement|false
    {
        $this->assertQueryAllowed((string)$query);
        return $this->inner->prepare($query, $options);
    }

    public function query(string $query, ?int $fetchMode = null, ...$fetchModeArgs): PDOStatement|false
    {
        $this->assertQueryAllowed($query);

        if ($fetchMode === null) {
            return $this->inner->query($query);
        }

        return $this->inner->query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec($statement): int|false
    {
        $this->assertQueryAllowed((string)$statement);
        return $this->inner->exec($statement);
    }

    private function assertQueryAllowed(string $sql): void
    {
        $normalized = preg_replace('/\s+/', ' ', strtolower($sql));
        if (!is_string($normalized)) {
            $normalized = strtolower($sql);
        }

        foreach ($this->blockedTables as $table) {
            if (
                str_contains($normalized, $table)
                && preg_match('/\b(insert|update|delete|replace)\b/', $normalized)
            ) {
                throw new RuntimeException(
                    "AutomationRuntime is not allowed to mutate table: {$table}"
                );
            }
        }
    }
}