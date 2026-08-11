<?php
declare(strict_types=1);

/**
 * Read-only access to the historical training analytics SQLite warehouse.
 * Does not touch production E-gle or official competency state.
 */

if (!function_exists('ta_analytics_root')) {
    function ta_analytics_root(): string
    {
        return dirname(__DIR__, 2);
    }
}

if (!function_exists('ta_analytics_db_path')) {
    function ta_analytics_db_path(): string
    {
        return ta_analytics_root() . '/storage/analytics/egle_training_analytics.sqlite';
    }
}

if (!function_exists('ta_analytics_pdo')) {
    function ta_analytics_pdo(): ?PDO
    {
        static $pdo = null;
        static $tried = false;
        if ($tried) {
            return $pdo;
        }
        $tried = true;

        $path = ta_analytics_db_path();
        if (!is_file($path)) {
            return null;
        }

        try {
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $pdo->exec('PRAGMA query_only = ON');
            $pdo->exec('PRAGMA busy_timeout = 3000');
        } catch (Throwable $e) {
            $pdo = null;
        }

        return $pdo;
    }
}

if (!function_exists('ta_table_exists')) {
    function ta_table_exists(PDO $db, string $table): bool
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }
        $st = $db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ? LIMIT 1");
        $st->execute([$table]);
        $cache[$table] = (bool)$st->fetchColumn();

        return $cache[$table];
    }
}

if (!function_exists('ta_query_all')) {
    /**
     * @param array<int,mixed> $args
     * @return list<array<string,mixed>>
     */
    function ta_query_all(PDO $db, string $sql, array $args = []): array
    {
        try {
            $st = $db->prepare($sql);
            $st->execute($args);
            $rows = $st->fetchAll();

            return is_array($rows) ? $rows : [];
        } catch (Throwable) {
            return [];
        }
    }
}

if (!function_exists('ta_query_one')) {
    /**
     * @param array<int,mixed> $args
     * @return array<string,mixed>|null
     */
    function ta_query_one(PDO $db, string $sql, array $args = []): ?array
    {
        $rows = ta_query_all($db, $sql, $args);

        return $rows[0] ?? null;
    }
}

if (!function_exists('ta_query_value')) {
    /**
     * @param array<int,mixed> $args
     */
    function ta_query_value(PDO $db, string $sql, array $args = [], mixed $default = null): mixed
    {
        try {
            $st = $db->prepare($sql);
            $st->execute($args);
            $v = $st->fetchColumn();

            return $v === false ? $default : $v;
        } catch (Throwable) {
            return $default;
        }
    }
}

if (!function_exists('ta_analysis_freshness')) {
    /**
     * @return array{historical:string,data_through:string,narrative_model:string,phase4:string,phase5b:string,phase6:string,generated_at:string}
     */
    function ta_analysis_freshness(PDO $db): array
    {
        $phase4 = (string)ta_query_value($db, 'SELECT analysis_version FROM analysis_meta LIMIT 1', [], 'n/a');
        $phase5b = (string)ta_query_value($db, 'SELECT analysis_version FROM analysis_phase5b_meta LIMIT 1', [], 'n/a');
        $phase6 = (string)ta_query_value($db, 'SELECT analysis_version FROM phase6_meta LIMIT 1', [], 'n/a');
        $dataThrough = (string)ta_query_value(
            $db,
            "SELECT MAX(session_date) FROM fact_training_session WHERE session_date_valid = 1 OR session_date_valid IS NULL",
            [],
            ''
        );
        $generated = (string)ta_query_value($db, 'SELECT generated_at FROM phase6_meta LIMIT 1', [], '');
        if ($generated === '') {
            $generated = (string)ta_query_value($db, 'SELECT generated_at FROM analysis_meta LIMIT 1', [], '');
        }

        return [
            'historical' => $phase6 !== 'n/a' ? $phase6 : $phase4,
            'data_through' => $dataThrough,
            'narrative_model' => $phase5b,
            'phase4' => $phase4,
            'phase5b' => $phase5b,
            'phase6' => $phase6,
            'generated_at' => $generated,
        ];
    }
}

if (!function_exists('ta_dataset_scope')) {
    /**
     * Live counts from warehouse facts — not hard-coded marketing numbers.
     *
     * @return array{
     *   year_min:string,year_max:string,students:int,students_dim:int,
     *   sessions:int,attempts:int,narratives:int,programs:int
     * }
     */
    function ta_dataset_scope(PDO $db): array
    {
        $min = (string)ta_query_value($db, 'SELECT MIN(session_date) FROM fact_training_session', [], '');
        $max = (string)ta_query_value($db, 'SELECT MAX(session_date) FROM fact_training_session', [], '');
        $yearMin = $min !== '' ? substr($min, 0, 4) : '—';
        $yearMax = $max !== '' ? substr($max, 0, 4) : '—';

        return [
            'year_min' => $yearMin,
            'year_max' => $yearMax,
            'students' => (int)ta_query_value($db, 'SELECT COUNT(DISTINCT student_id) FROM fact_training_session WHERE student_id IS NOT NULL', [], 0),
            'students_dim' => (int)ta_query_value($db, 'SELECT COUNT(*) FROM dim_student', [], 0),
            'sessions' => (int)ta_query_value($db, 'SELECT COUNT(*) FROM fact_training_session', [], 0),
            'attempts' => (int)ta_query_value($db, 'SELECT COUNT(*) FROM fact_exercise_attempt', [], 0),
            'narratives' => (int)ta_query_value($db, 'SELECT COUNT(*) FROM fact_narrative', [], 0),
            'programs' => (int)ta_query_value($db, 'SELECT COUNT(*) FROM dim_program', [], 0),
        ];
    }
}

if (!function_exists('ta_filter_options')) {
    /**
     * Filter dimensions supported reliably by the analytics warehouse.
     *
     * @return array{
     *   programs:list<array{id:string,label:string}>,
     *   versions:list<array{id:string,label:string,family:string}>,
     *   session_types:list<array{id:string,label:string}>,
     *   instructors:list<array{id:string,label:string}>
     * }
     */
    function ta_filter_options(PDO $db): array
    {
        $programs = [];
        foreach (ta_query_all($db, 'SELECT program_id, COALESCE(program_name, CAST(program_id AS TEXT)) AS label FROM dim_program ORDER BY label') as $r) {
            $programs[] = ['id' => (string)$r['program_id'], 'label' => (string)$r['label']];
        }

        $versions = [];
        foreach (ta_query_all(
            $db,
            "SELECT v.curriculum_version_id AS id, v.version_code AS label, COALESCE(f.family_code,'') AS family
             FROM dim_curriculum_version v
             LEFT JOIN dim_curriculum_family f ON f.curriculum_family_id = v.curriculum_family_id
             ORDER BY f.family_code, v.version_code"
        ) as $r) {
            $versions[] = [
                'id' => (string)$r['id'],
                'label' => (string)$r['label'],
                'family' => (string)$r['family'],
            ];
        }

        $sessionTypes = [];
        foreach (ta_query_all(
            $db,
            'SELECT session_type_normalized AS id, COUNT(*) AS c
             FROM fact_training_session
             WHERE session_type_normalized IS NOT NULL AND TRIM(session_type_normalized) != ""
             GROUP BY 1 ORDER BY c DESC'
        ) as $r) {
            $sessionTypes[] = ['id' => (string)$r['id'], 'label' => (string)$r['id']];
        }

        $instructors = [];
        foreach (ta_query_all(
            $db,
            "SELECT instructor_id AS id,
                    TRIM(COALESCE(NULLIF(TRIM(COALESCE(first_name,'') || ' ' || COALESCE(last_name,'')), ''), 'Instructor #' || instructor_id)) AS label
             FROM dim_instructor
             WHERE instructor_id IS NOT NULL
             ORDER BY label
             LIMIT 200"
        ) as $r) {
            $instructors[] = ['id' => (string)$r['id'], 'label' => (string)$r['label']];
        }

        return [
            'programs' => $programs,
            'versions' => $versions,
            'session_types' => $sessionTypes,
            'instructors' => $instructors,
        ];
    }
}

if (!function_exists('ta_parse_filters')) {
    /**
     * @return array{
     *   date_from:?string,date_to:?string,program_id:?string,version_id:?string,
     *   instructor_id:?string,session_type:?string,mission_role:?string,
     *   active:bool,qs:string
     * }
     */
    function ta_parse_filters(array $get): array
    {
        $dateFrom = trim((string)($get['date_from'] ?? ''));
        $dateTo = trim((string)($get['date_to'] ?? ''));
        $programId = trim((string)($get['program_id'] ?? ''));
        $versionId = trim((string)($get['version_id'] ?? ''));
        $instructorId = trim((string)($get['instructor_id'] ?? ''));
        $sessionType = trim((string)($get['session_type'] ?? ''));
        $missionRole = trim((string)($get['mission_role'] ?? ''));

        $normDate = static function (string $d): ?string {
            if ($d === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                return null;
            }

            return $d;
        };

        $filters = [
            'date_from' => $normDate($dateFrom),
            'date_to' => $normDate($dateTo),
            'program_id' => $programId !== '' ? $programId : null,
            'version_id' => $versionId !== '' ? $versionId : null,
            'instructor_id' => $instructorId !== '' ? $instructorId : null,
            'session_type' => $sessionType !== '' ? $sessionType : null,
            'mission_role' => $missionRole !== '' ? $missionRole : null,
        ];

        $active = false;
        foreach ($filters as $v) {
            if ($v !== null && $v !== '') {
                $active = true;
                break;
            }
        }

        $qsParts = [];
        foreach ([
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'program_id' => $filters['program_id'],
            'version_id' => $filters['version_id'],
            'instructor_id' => $filters['instructor_id'],
            'session_type' => $filters['session_type'],
            'mission_role' => $filters['mission_role'],
        ] as $k => $v) {
            if ($v !== null && $v !== '') {
                $qsParts[] = rawurlencode($k) . '=' . rawurlencode((string)$v);
            }
        }

        $filters['active'] = $active;
        $filters['qs'] = implode('&', $qsParts);

        return $filters;
    }
}

if (!function_exists('ta_confidence_label')) {
    function ta_confidence_label(?string $raw): string
    {
        $c = strtoupper(trim((string)$raw));
        return match (true) {
            in_array($c, ['HIGH', 'HIGH CONFIDENCE'], true) => 'HIGH CONFIDENCE',
            in_array($c, ['MEDIUM', 'MODERATE', 'MODERATE CONFIDENCE'], true) => 'MODERATE CONFIDENCE',
            in_array($c, ['LOW', 'EXPLORATORY', 'EXPLORATORY CONFIDENCE'], true) => 'EXPLORATORY',
            $c === '' => 'EXPLORATORY',
            default => $c,
        };
    }
}

if (!function_exists('ta_confidence_class')) {
    function ta_confidence_class(?string $raw): string
    {
        $label = ta_confidence_label($raw);
        return match ($label) {
            'HIGH CONFIDENCE' => 'ta-conf ta-conf--high',
            'MODERATE CONFIDENCE' => 'ta-conf ta-conf--moderate',
            default => 'ta-conf ta-conf--exploratory',
        };
    }
}

if (!function_exists('ta_fmt_int')) {
    function ta_fmt_int(int|float|string|null $n): string
    {
        if ($n === null || $n === '') {
            return '—';
        }

        return number_format((float)$n, 0, '.', ',');
    }
}

if (!function_exists('ta_fmt_pct')) {
    function ta_fmt_pct(int|float|string|null $rate, int $digits = 1): string
    {
        if ($rate === null || $rate === '') {
            return '—';
        }
        $v = (float)$rate;
        if ($v <= 1.0 && $v >= -1.0) {
            $v *= 100.0;
        }

        return number_format($v, $digits) . '%';
    }
}

if (!function_exists('ta_fmt_num')) {
    function ta_fmt_num(int|float|string|null $n, int $digits = 2): string
    {
        if ($n === null || $n === '') {
            return '—';
        }

        return number_format((float)$n, $digits);
    }
}

if (!function_exists('ta_h')) {
    function ta_h(?string $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}
