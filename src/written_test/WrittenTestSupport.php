<?php
declare(strict_types=1);

final class WrittenTestSupport
{
    public const POLICY_KEYS = [
        'written_test.preparation_enabled',
        'written_test.require_complete_ground_school',
        'written_test.required_ground_school_completion_pct',
        'written_test.require_mandatory_summaries',
        'written_test.require_progress_tests_completed',
        'written_test.minimum_progress_test_score_pct',
        'written_test.require_remediation_resolved',
        'written_test.require_instructor_approval',
        'written_test.require_administrator_approval',
        'written_test.allow_manual_student_override',
        'written_test.allow_manual_cohort_override',
        'written_test.display_locked_module_to_allocated_students',
        'written_test.treat_overdue_required_lessons_as_lock_reason',
        'written_test.policy_effective_date_behavior',
    ];

    public static function h(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public static function jsonEncode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function tableExists(PDO $pdo, string $table): bool
    {
        try {
            $st = $pdo->prepare('SHOW TABLES LIKE ?');
            $st->execute([$table]);
            return (bool)$st->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    public static function ensureSchema(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        if (self::tableExists($pdo, 'written_test_programs')
            && self::tableExists($pdo, 'cohort_written_test_allocations')
            && self::tableExists($pdo, 'written_test_policy_versions')
            && self::tableExists($pdo, 'written_test_access_overrides')
        ) {
            return;
        }

        $migration = dirname(__DIR__, 2) . '/scripts/sql/2026_07_14_written_test_phase1_foundation.sql';
        if (!is_readable($migration)) {
            return;
        }

        $sql = (string)file_get_contents($migration);
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || str_starts_with($stmt, '--')) {
                continue;
            }
            try {
                $pdo->exec($stmt);
            } catch (Throwable $e) {
                error_log('WrittenTestSupport::ensureSchema statement failed: ' . $e->getMessage());
            }
        }
    }

    public static function utcNow(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function dateTimeOrNull(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value . ' 00:00:00';
        }
        return $value;
    }
}
