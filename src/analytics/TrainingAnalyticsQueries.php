<?php
declare(strict_types=1);

require_once __DIR__ . '/TrainingAnalyticsDb.php';

/**
 * Query helpers for Training Analytics pages (read-only, analysis_* first).
 */

if (!function_exists('ta_gap_buckets')) {
    /**
     * @return list<array{bucket:string,incomplete:float,repeat:float,n:int}>
     */
    function ta_gap_buckets(PDO $db): array
    {
        $rows = ta_query_all(
            $db,
            "SELECT predictor, outcome, coefficient, n
             FROM analysis_training_gap_effect
             WHERE model_name = 'descriptive_bucket' AND stratum = 'all_sessions'
             ORDER BY predictor, outcome"
        );
        $out = [];
        foreach ($rows as $r) {
            $bucket = (string)$r['predictor'];
            if (!isset($out[$bucket])) {
                $out[$bucket] = ['bucket' => $bucket, 'incomplete' => null, 'repeat' => null, 'n' => (int)$r['n']];
            }
            if ((string)$r['outcome'] === 'pct_incomplete') {
                $out[$bucket]['incomplete'] = (float)$r['coefficient'];
                $out[$bucket]['n'] = (int)$r['n'];
            }
            if ((string)$r['outcome'] === 'pct_repeat') {
                $out[$bucket]['repeat'] = (float)$r['coefficient'];
            }
        }
        $order = ['gap=0-2', 'gap=3-5', 'gap=6-10', 'gap=11-20', 'gap=21+'];
        $sorted = [];
        foreach ($order as $k) {
            if (isset($out[$k])) {
                $sorted[] = $out[$k];
            }
        }

        return $sorted;
    }
}

if (!function_exists('ta_gap_models')) {
    /** @return list<array<string,mixed>> */
    function ta_gap_models(PDO $db, ?string $sessionType = null): array
    {
        if ($sessionType) {
            return ta_query_all(
                $db,
                "SELECT * FROM analysis_training_gap_effect
                 WHERE model_name LIKE 'corr_gap%' AND stratum = ?
                 ORDER BY outcome",
                ['type=' . $sessionType]
            );
        }

        return ta_query_all(
            $db,
            "SELECT * FROM analysis_training_gap_effect
             WHERE model_name = 'logit_log_gap'
             ORDER BY outcome, stratum"
        );
    }
}

if (!function_exists('ta_overview_insights')) {
    /**
     * Build high-value insight cards from materialized tables only.
     *
     * @return list<array<string,mixed>>
     */
    function ta_overview_insights(PDO $db): array
    {
        $cards = [];
        $qs = '';

        $buckets = ta_gap_buckets($db);
        $b02 = null;
        $b21 = null;
        foreach ($buckets as $b) {
            if ($b['bucket'] === 'gap=0-2') {
                $b02 = $b;
            }
            if ($b['bucket'] === 'gap=21+') {
                $b21 = $b;
            }
        }
        $logit = ta_query_one(
            $db,
            "SELECT * FROM analysis_training_gap_effect
             WHERE model_name='logit_log_gap' AND stratum='all_sessions' AND outcome='incomplete' LIMIT 1"
        );
        if ($b02 && $b21) {
            $cards[] = [
                'eyebrow' => 'Training continuity',
                'title' => 'Longer gaps → higher incomplete rate',
                'metrics' => [
                    ['label' => '0–2 day gap', 'value' => ta_fmt_pct($b02['incomplete']) . ' incomplete'],
                    ['label' => '21+ day gap', 'value' => ta_fmt_pct($b21['incomplete']) . ' incomplete'],
                ],
                'what' => 'Incomplete rates rise from '
                    . ta_fmt_pct($b02['incomplete']) . ' after short gaps (n=' . ta_fmt_int($b02['n']) . ') to '
                    . ta_fmt_pct($b21['incomplete']) . ' after 21+ day gaps (n=' . ta_fmt_int($b21['n']) . ').',
                'so_what' => 'Long training gaps are strongly associated with poorer subsequent training outcomes even after program controls.',
                'confidence' => 'HIGH',
                'evidence' => $logit
                    ? 'analysis_training_gap_effect · logit OR=' . ta_fmt_num($logit['odds_ratio'] ?? null, 3)
                      . ' (95% CI ' . ta_fmt_num($logit['ci_low'] ?? null, 3) . '–' . ta_fmt_num($logit['ci_high'] ?? null, 3)
                      . '), n=' . ta_fmt_int($logit['n'] ?? 0) . ', ' . (string)($logit['analysis_version'] ?? 'phase4-v1')
                    : 'analysis_training_gap_effect descriptive buckets',
                'href' => '/admin/training_analytics/continuity.php' . $qs,
            ];
        }

        $pe = ta_query_one(
            $db,
            "SELECT SUM(n_reached_pe) AS n_pe, SUM(n_reobserved) AS n_re,
                    SUM(stable_pe_rate * n_reobserved) * 1.0 / NULLIF(SUM(n_reobserved),0) AS wavg
             FROM analysis_competency_stability
             WHERE n_reobserved >= 10 AND required_level = 'PE'"
        );
        $soft = ta_query_all(
            $db,
            "SELECT exercise_name, program_id, n_reobserved, stable_pe_rate
             FROM analysis_competency_stability
             WHERE required_level='PE' AND n_reobserved >= 20
               AND (lower(exercise_name) LIKE '%intercept%' OR lower(exercise_name) LIKE '%hold%'
                    OR lower(exercise_name) LIKE '%go-around%' OR lower(exercise_name) LIKE '%go around%'
                    OR lower(exercise_name) LIKE '%path%')
             ORDER BY stable_pe_rate ASC
             LIMIT 3"
        );
        if ($pe && $pe['wavg'] !== null) {
            $softNote = $soft
                ? ' Several IR path/holding/interception items show materially lower reobservation stability (e.g. '
                  . (string)$soft[0]['exercise_name'] . ' @ ' . ta_fmt_pct($soft[0]['stable_pe_rate']) . ').'
                : '';
            $cards[] = [
                'eyebrow' => 'Competency stability',
                'title' => 'PE is generally durable',
                'metrics' => [
                    ['label' => 'Weighted PE stability', 'value' => ta_fmt_pct($pe['wavg'])],
                    ['label' => 'Reobservations', 'value' => ta_fmt_int($pe['n_re'])],
                ],
                'what' => 'Across PE competencies with ≥10 later reobservations, weighted stability is '
                    . ta_fmt_pct($pe['wavg']) . ' (n_reobs=' . ta_fmt_int($pe['n_re']) . ').',
                'so_what' => 'Competency marked at PE is generally durable, but a minority of items — especially some IR skills — show higher later softening.' . $softNote,
                'confidence' => 'HIGH',
                'evidence' => 'analysis_competency_stability · min n_reobserved=10 · phase4-v1',
                'href' => '/admin/training_analytics/competencies.php?view=stability',
            ];
        }

        $curr = ta_query_all(
            $db,
            "SELECT family_code, version_a, version_b, metric_name, verdict, confidence, n_a, n_b
             FROM analysis_curriculum_comparison
             WHERE metric_name IN ('sessions_per_student','flight_hours_per_student','pe_stability_rate')
             GROUP BY family_code, version_a, version_b, metric_name
             ORDER BY family_code"
        );
        if ($curr) {
            $improvePe = 0;
            $worseEff = 0;
            $pairs = [];
            foreach ($curr as $r) {
                $key = $r['family_code'] . '|' . $r['version_a'] . '|' . $r['version_b'];
                $pairs[$key] = true;
                if ($r['metric_name'] === 'pe_stability_rate' && str_contains((string)$r['verdict'], 'IMPROVEMENT')) {
                    $improvePe++;
                }
                if (in_array($r['metric_name'], ['sessions_per_student', 'flight_hours_per_student'], true)
                    && str_contains((string)$r['verdict'], 'DETERIORATION')) {
                    $worseEff++;
                }
            }
            $cards[] = [
                'eyebrow' => 'Curriculum',
                'title' => 'Newer curriculum: mixed efficiency',
                'metrics' => [
                    ['label' => 'Compared families', 'value' => (string)count($pairs)],
                    ['label' => 'Pattern', 'value' => 'MIXED'],
                ],
                'what' => 'Curriculum-generation comparisons (PPL→PPLA, MEP→MEPNEW, IR→IRNEW, CPL→CPLAUPRT) show mixed session/hour effects alongside several PE-stability improvements.',
                'so_what' => 'New versions do not universally reduce hours/sessions, but PE durability often appears stronger. Do not judge curriculum quality by hours alone.',
                'confidence' => 'MODERATE',
                'evidence' => 'analysis_curriculum_comparison · ' . count($curr) . ' metric rows · phase4-v1',
                'href' => '/admin/training_analytics/curriculum.php',
            ];
        }

        $narr = ta_query_one(
            $db,
            "SELECT metric_value, n, population, analysis_version
             FROM analysis_phase6_scale_findings
             WHERE finding_name = 'phase5b_llm_mismatch_hidden_signal' LIMIT 1"
        );
        $enc = ta_query_one(
            $db,
            "SELECT metric_value, n FROM analysis_phase6_scale_findings
             WHERE finding_name = 'phase5b_llm_encouraging_def' LIMIT 1"
        );
        if ($narr) {
            $cards[] = [
                'eyebrow' => 'Narrative signal',
                'title' => 'Structured grades miss meaningful information',
                'metrics' => [
                    ['label' => 'Grade↔narrative mismatch', 'value' => ta_fmt_pct($narr['metric_value'])],
                    ['label' => 'Phase 5B sample', 'value' => 'n=' . ta_fmt_int($narr['n'])],
                ],
                'what' => 'On the Phase 5B LLM-validated reference sample (n=' . ta_fmt_int($narr['n'])
                    . '), grade↔narrative mismatch / hidden-signal rate is ' . ta_fmt_pct($narr['metric_value'])
                    . ($enc ? '; encouraging tone with real deficiency ≈ ' . ta_fmt_pct($enc['metric_value']) . '.' : '.'),
                'so_what' => 'Narratives carry training-relevant information that structured grades alone do not surface — important for management review and future independence capture.',
                'confidence' => 'HIGH',
                'evidence' => 'analysis_phase6_scale_findings · population=' . (string)$narr['population']
                    . ' · ' . (string)($narr['analysis_version'] ?? 'phase6-v1') . ' (LLM-validated reference, not heuristic-only)',
                'href' => '/admin/training_analytics/narratives.php',
            ];
        }

        $cons = ta_query_one(
            $db,
            "SELECT * FROM analysis_phase6_early_warning_pattern WHERE pattern_code='CONSISTENCY_CONCERN' LIMIT 1"
        );
        if ($cons) {
            $cards[] = [
                'eyebrow' => 'Consistency',
                'title' => 'VARIABLE consistency precedes later problems',
                'metrics' => [
                    ['label' => 'Later-problem rate', 'value' => ta_fmt_pct($cons['later_problem_rate'])],
                    ['label' => 'Baseline', 'value' => ta_fmt_pct($cons['baseline_rate'])],
                ],
                'what' => 'When narrative/extracted consistency is VARIABLE, later-problem rate is '
                    . ta_fmt_pct($cons['later_problem_rate']) . ' vs baseline '
                    . ta_fmt_pct($cons['baseline_rate']) . ' (n_episodes=' . ta_fmt_int($cons['n_episodes'])
                    . ', n_students=' . ta_fmt_int($cons['n_students']) . ').',
                'so_what' => 'Consistency language is an early-warning signal — not a student label. Treat as exploratory for scheduling/remediation review.',
                'confidence' => 'MODERATE',
                'evidence' => 'analysis_phase6_early_warning_pattern · CONSISTENCY_CONCERN · ' . (string)($cons['analysis_version'] ?? 'phase6-v1'),
                'href' => '/admin/training_analytics/discoveries.php',
            ];
        }

        $bn = ta_query_one(
            $db,
            "SELECT program_name, item_label, metric_value, n, confidence
             FROM analysis_program_bottleneck
             WHERE item_type='PROGRESSION_MISSION' AND metric_name='extra_sessions_per_student'
             ORDER BY metric_value DESC LIMIT 1"
        );
        if ($bn) {
            $cards[] = [
                'eyebrow' => 'Mission bottlenecks',
                'title' => 'Where progression time accumulates',
                'metrics' => [
                    ['label' => 'Top mission', 'value' => (string)$bn['item_label']],
                    ['label' => 'Extra sessions / student', 'value' => ta_fmt_num($bn['metric_value'], 2)],
                ],
                'what' => 'Highest progression-mission repeat burden: ' . (string)$bn['item_label']
                    . ' in ' . (string)$bn['program_name'] . ' (+' . ta_fmt_num($bn['metric_value'], 2)
                    . ' extra sessions/student, n=' . ta_fmt_int($bn['n']) . ').',
                'so_what' => 'Use progression-only bottlenecks (exclude intentional accumulation/proficiency) to find where training time is lost.',
                'confidence' => (string)($bn['confidence'] ?? 'HIGH'),
                'evidence' => 'analysis_program_bottleneck · PROGRESSION_MISSION · phase4-v1',
                'href' => '/admin/training_analytics/bottlenecks.php',
            ];
        }

        $indep = ta_query_one(
            $db,
            "SELECT * FROM analysis_phase5_final_architecture WHERE field_name LIKE '%independence%' OR field_name LIKE '%assistance%' LIMIT 1"
        );
        // Always surface the NOT_OBSERVED principle if narrative findings exist
        if ($narr || ta_table_exists($db, 'analysis_phase5_final_architecture')) {
            $cards[] = [
                'eyebrow' => 'Independence',
                'title' => 'Silence ≠ independent performance',
                'metrics' => [
                    ['label' => 'Correct interpretation', 'value' => 'NOT_OBSERVED'],
                ],
                'what' => 'Historical absence of instructor-assistance language does not prove independent performance.',
                'so_what' => 'This is one reason the future system explicitly captures independence. Treat missing assistance language as NOT_OBSERVED, not success.',
                'confidence' => 'HIGH',
                'evidence' => 'Phase 5 architecture / narrative validation principle · analysis_phase5_final_architecture',
                'href' => '/admin/training_analytics/narratives.php',
            ];
        }

        return $cards;
    }
}

if (!function_exists('ta_curriculum_pairs')) {
    /**
     * Deduplicated curriculum comparison pairs with metrics + rollup verdict.
     *
     * @return list<array<string,mixed>>
     */
    function ta_curriculum_pairs(PDO $db): array
    {
        $rows = ta_query_all(
            $db,
            "SELECT family_code, version_a, version_b, metric_name,
                    MAX(value_a) AS value_a, MAX(value_b) AS value_b, MAX(delta) AS delta,
                    MAX(n_a) AS n_a, MAX(n_b) AS n_b,
                    MAX(verdict) AS verdict, MAX(confidence) AS confidence, MAX(notes) AS notes
             FROM analysis_curriculum_comparison
             GROUP BY family_code, version_a, version_b, metric_name
             ORDER BY family_code, version_a, version_b, metric_name"
        );
        $pairs = [];
        foreach ($rows as $r) {
            $key = $r['family_code'] . '|' . $r['version_a'] . '|' . $r['version_b'];
            if (!isset($pairs[$key])) {
                $pairs[$key] = [
                    'family_code' => $r['family_code'],
                    'version_a' => $r['version_a'],
                    'version_b' => $r['version_b'],
                    'metrics' => [],
                    'verdicts' => [],
                ];
            }
            $pairs[$key]['metrics'][$r['metric_name']] = $r;
            $pairs[$key]['verdicts'][] = (string)$r['verdict'];
        }

        foreach ($pairs as &$p) {
            $p['rollup'] = ta_curriculum_rollup($p['verdicts'], $p['metrics']);
        }
        unset($p);

        return array_values($pairs);
    }
}

if (!function_exists('ta_curriculum_rollup')) {
    /**
     * @param list<string> $verdicts
     * @param array<string,array<string,mixed>> $metrics
     */
    function ta_curriculum_rollup(array $verdicts, array $metrics): array
    {
        $improve = 0;
        $worse = 0;
        $none = 0;
        $insuff = 0;
        foreach ($verdicts as $v) {
            $u = strtoupper($v);
            if (str_contains($u, 'INSUFFICIENT')) {
                $insuff++;
            } elseif (str_contains($u, 'IMPROVEMENT')) {
                $improve++;
            } elseif (str_contains($u, 'DETERIORATION')) {
                $worse++;
            } elseif (str_contains($u, 'NO MEANINGFUL') || str_contains($u, 'NO MATERIAL')) {
                $none++;
            }
        }

        // Prefer PE stability + repeats over hours alone for narrative conclusion
        $pe = $metrics['pe_stability_rate']['verdict'] ?? null;
        $sess = $metrics['sessions_per_student']['verdict'] ?? null;
        $label = 'MIXED';
        $cls = 'ta-verdict--mixed';
        if ($improve > 0 && $worse === 0 && $insuff < count($verdicts)) {
            $label = 'CLEAR IMPROVEMENT';
            $cls = 'ta-verdict--improve';
        } elseif ($worse > 0 && $improve === 0) {
            $label = 'DETERIORATION';
            $cls = 'ta-verdict--worse';
        } elseif ($improve > 0 && $worse > 0) {
            $label = 'MIXED';
            $cls = 'ta-verdict--mixed';
        } elseif ($none > 0 && $improve === 0 && $worse === 0) {
            $label = 'NO MATERIAL DIFFERENCE';
            $cls = 'ta-verdict--none';
        }
        if ($insuff >= count($verdicts) - 1 && count($verdicts) > 0) {
            $label = 'INSUFFICIENT EVIDENCE';
            $cls = 'ta-verdict--insuff';
        }

        $note = 'Do not reduce curriculum quality to training hours.';
        if ($pe && str_contains(strtoupper((string)$pe), 'IMPROVEMENT') && $sess && str_contains(strtoupper((string)$sess), 'DETERIORATION')) {
            $note = 'PE durability improved while session burden did not fall — mixed efficiency, stronger retention signal.';
        }

        return ['label' => $label, 'class' => $cls, 'note' => $note];
    }
}

if (!function_exists('ta_bottlenecks')) {
    /**
     * @return list<array<string,mixed>>
     */
    function ta_bottlenecks(PDO $db, ?string $programId, string $roleMode, string $sort, string $dir): array
    {
        $where = ["item_type = 'PROGRESSION_MISSION'", "metric_name = 'extra_sessions_per_student'"];
        $args = [];
        if ($programId) {
            $where[] = 'CAST(program_id AS TEXT) = ?';
            $args[] = $programId;
        }
        // analysis_program_bottleneck already stores PROGRESSION_MISSION only for missions;
        // roleMode ALL would need other sources — keep progression default.
        $sql = 'SELECT * FROM analysis_program_bottleneck WHERE ' . implode(' AND ', $where);

        $sortMap = [
            'metric' => 'metric_value',
            'n' => 'n',
            'label' => 'item_label',
            'program' => 'program_name',
            'confidence' => 'confidence',
        ];
        $col = $sortMap[$sort] ?? 'metric_value';
        $dirSql = strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC';
        $sql .= " ORDER BY {$col} {$dirSql}";

        $rows = ta_query_all($db, $sql, $args);

        // Enrich with attempt stats for displayed set (lightweight IN query)
        if (!$rows) {
            return [];
        }
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (string)$r['item_id'];
        }
        $ids = array_values(array_unique($ids));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stats = [];
        foreach (ta_query_all(
            $db,
            "SELECT CAST(mission_id AS TEXT) AS mid,
                    COUNT(DISTINCT student_id) AS students,
                    COUNT(*) AS sessions,
                    AVG(mission_attempt_number) AS avg_attempts,
                    SUM(CASE WHEN COALESCE(mission_attempt_number,1) > 1 THEN 1 ELSE 0 END) * 1.0 / COUNT(*) AS repeat_rate
             FROM fact_training_session
             WHERE CAST(mission_id AS TEXT) IN ($placeholders)
             GROUP BY 1",
            $ids
        ) as $s) {
            $stats[(string)$s['mid']] = $s;
        }

        foreach ($rows as &$r) {
            $mid = (string)$r['item_id'];
            $r['students'] = $stats[$mid]['students'] ?? $r['n'];
            $r['avg_attempts'] = $stats[$mid]['avg_attempts'] ?? null;
            $r['repeat_rate'] = $stats[$mid]['repeat_rate'] ?? null;
            $r['mission_role'] = 'PROGRESSION_MISSION';
        }
        unset($r);

        if ($roleMode !== 'PROGRESSION_MISSION' && $roleMode !== '' && $roleMode !== 'ALL') {
            // Bottleneck table is progression-only; signal empty for other exclusive roles
            return [];
        }

        return $rows;
    }
}

if (!function_exists('ta_competency_list')) {
    /**
     * @return list<array<string,mixed>>
     */
    function ta_competency_list(PDO $db, string $q, ?string $programId, int $limit = 80): array
    {
        $where = ['1=1'];
        $args = [];
        if ($programId) {
            $where[] = 'CAST(s.program_id AS TEXT) = ?';
            $args[] = $programId;
        }
        if ($q !== '') {
            $where[] = '(lower(s.exercise_name) LIKE ? OR CAST(s.source_exercise_id AS TEXT) LIKE ?)';
            $like = '%' . strtolower($q) . '%';
            $args[] = $like;
            $args[] = $like;
        }
        $args[] = $limit;

        return ta_query_all(
            $db,
            'SELECT s.exercise_id, s.source_exercise_id, s.program_id, s.exercise_name, s.required_level,
                    s.n_reached_pe, s.n_reobserved, s.stable_pe_rate, s.one_time_regression_rate,
                    s.repeated_regression_rate,
                    p.program_name
             FROM analysis_competency_stability s
             LEFT JOIN dim_program p ON p.program_id = s.program_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY s.n_reobserved DESC, s.n_reached_pe DESC
             LIMIT ?',
            $args
        );
    }
}

if (!function_exists('ta_verdict_class')) {
    function ta_verdict_class(string $verdict): string
    {
        $u = strtoupper($verdict);
        if (str_contains($u, 'INSUFFICIENT')) {
            return 'ta-verdict--insuff';
        }
        if (str_contains($u, 'IMPROVEMENT')) {
            return 'ta-verdict--improve';
        }
        if (str_contains($u, 'DETERIORATION')) {
            return 'ta-verdict--worse';
        }
        if (str_contains($u, 'NO MEANINGFUL') || str_contains($u, 'NO MATERIAL')) {
            return 'ta-verdict--none';
        }

        return 'ta-verdict--mixed';
    }
}

if (!function_exists('ta_program_health_rows')) {
    /**
     * Program health summary — one lightweight fact aggregation + analysis joins.
     *
     * @return list<array<string,mixed>>
     */
    function ta_program_health_rows(PDO $db): array
    {
        $base = ta_query_all(
            $db,
            "SELECT p.program_id,
                    p.program_name,
                    p.source_tracking_table,
                    p.source_program_id,
                    p.curriculum_family_id,
                    p.curriculum_version_id,
                    f.family_code,
                    v.version_code,
                    COUNT(s.session_id) AS sessions,
                    COUNT(DISTINCT s.student_id) AS students,
                    MIN(s.session_date) AS date_min,
                    MAX(s.session_date) AS date_max,
                    AVG(CASE WHEN s.exercises_graded_count > 0
                        THEN s.exercises_below_required * 1.0 / s.exercises_graded_count END) AS below_required_rate,
                    AVG(CASE WHEN COALESCE(s.mission_repeated,0)=1 THEN 1.0 ELSE 0.0 END) AS repeat_session_share,
                    SUM(CASE WHEN s.narrative_public_present=1 OR s.narrative_private_present=1 THEN 1 ELSE 0 END) AS narrative_sessions,
                    SUM(CASE WHEN s.session_type_normalized='FLIGHT' THEN 1 ELSE 0 END) AS flight_sessions,
                    SUM(CASE WHEN s.session_type_normalized='FNPT' THEN 1 ELSE 0 END) AS fnpt_sessions,
                    SUM(CASE WHEN s.session_type_normalized IN ('LB','SAB') THEN 1 ELSE 0 END) AS ground_sessions
             FROM dim_program p
             LEFT JOIN dim_curriculum_family f ON f.curriculum_family_id = p.curriculum_family_id
             LEFT JOIN dim_curriculum_version v ON v.curriculum_version_id = p.curriculum_version_id
             LEFT JOIN fact_training_session s ON s.program_id = p.program_id
             GROUP BY p.program_id
             ORDER BY sessions DESC"
        );

        $pe = [];
        foreach (ta_query_all(
            $db,
            "SELECT program_id,
                    SUM(stable_pe_rate * n_reobserved) / SUM(n_reobserved) AS pe_stability,
                    SUM(n_reobserved) AS pe_n
             FROM analysis_competency_stability
             WHERE required_level='PE' AND n_reobserved >= 10
             GROUP BY program_id"
        ) as $r) {
            $pe[(string)$r['program_id']] = $r;
        }

        $bn = [];
        foreach (ta_query_all(
            $db,
            "SELECT program_id,
                    AVG(metric_value) AS avg_extra_sessions,
                    COUNT(*) AS bottleneck_missions,
                    MAX(metric_value) AS max_extra_sessions
             FROM analysis_program_bottleneck
             WHERE item_type='PROGRESSION_MISSION' AND metric_name='extra_sessions_per_student'
             GROUP BY program_id"
        ) as $r) {
            $bn[(string)$r['program_id']] = $r;
        }

        $traj = [];
        foreach (ta_query_all(
            $db,
            "SELECT program_id, trajectory_label, COUNT(*) AS c
             FROM analysis_student_trajectory
             GROUP BY program_id, trajectory_label"
        ) as $r) {
            $pid = (string)$r['program_id'];
            if (!isset($traj[$pid])) {
                $traj[$pid] = ['total' => 0, 'labels' => []];
            }
            $traj[$pid]['labels'][(string)$r['trajectory_label']] = (int)$r['c'];
            $traj[$pid]['total'] += (int)$r['c'];
        }

        $progMissions = [];
        foreach (ta_query_all(
            $db,
            "SELECT program_id, COUNT(*) AS c FROM analysis_mission_role WHERE mission_role='PROGRESSION_MISSION' GROUP BY program_id"
        ) as $r) {
            $progMissions[(string)$r['program_id']] = (int)$r['c'];
        }

        foreach ($base as &$row) {
            $pid = (string)$row['program_id'];
            $row['pe_stability'] = $pe[$pid]['pe_stability'] ?? null;
            $row['pe_n'] = $pe[$pid]['pe_n'] ?? 0;
            $row['avg_extra_sessions'] = $bn[$pid]['avg_extra_sessions'] ?? null;
            $row['bottleneck_missions'] = $bn[$pid]['bottleneck_missions'] ?? 0;
            $row['max_extra_sessions'] = $bn[$pid]['max_extra_sessions'] ?? null;
            $row['progression_mission_count'] = $progMissions[$pid] ?? 0;
            $row['trajectory'] = $traj[$pid] ?? ['total' => 0, 'labels' => []];
            $row['narrative_coverage'] = ((int)$row['sessions'] > 0)
                ? ((int)$row['narrative_sessions'] / (int)$row['sessions'])
                : null;
            $sessions = (int)$row['sessions'];
            $students = (int)$row['students'];
            if ($sessions >= 200 && $students >= 20) {
                $row['sufficiency'] = 'SUFFICIENT';
            } elseif ($sessions >= 50 && $students >= 8) {
                $row['sufficiency'] = 'LIMITED';
            } else {
                $row['sufficiency'] = 'INSUFFICIENT';
            }
            // Gap sensitivity: not materialized per program
            $row['gap_sensitivity'] = null;
        }
        unset($row);

        return $base;
    }
}

if (!function_exists('ta_instructor_duplicate_names')) {
    /** @return array<string,list<string>> name => instructor_ids */
    function ta_instructor_duplicate_names(PDO $db): array
    {
        $map = [];
        foreach (ta_query_all(
            $db,
            "SELECT TRIM(COALESCE(first_name,'') || ' ' || COALESCE(last_name,'')) AS name,
                    GROUP_CONCAT(instructor_id) AS ids,
                    COUNT(*) AS c
             FROM dim_instructor
             GROUP BY 1
             HAVING c > 1"
        ) as $r) {
            $name = trim((string)$r['name']);
            if ($name === '') {
                continue;
            }
            $map[$name] = array_map('strval', explode(',', (string)$r['ids']));
        }

        return $map;
    }
}

if (!function_exists('ta_instructor_calibration_rows')) {
    /**
     * @return list<array<string,mixed>>
     */
    function ta_instructor_calibration_rows(PDO $db, int $minSessions = 30): array
    {
        $rows = ta_query_all(
            $db,
            'SELECT * FROM analysis_instructor_calibration ORDER BY n_sessions DESC'
        );
        $dups = ta_instructor_duplicate_names($db);
        $dupIds = [];
        foreach ($dups as $ids) {
            foreach ($ids as $id) {
                $dupIds[$id] = true;
            }
        }

        // Population reference among SAMPLE_OK
        $ok = array_values(array_filter($rows, static fn ($r) => (string)$r['sample_sufficiency'] === 'SAMPLE_OK' && (int)$r['n_sessions'] >= $minSessions));
        $baseline = [
            'pe_rate' => ta_median(array_map(static fn ($r) => (float)$r['pe_rate'], $ok)),
            'required_met_rate' => ta_median(array_map(static fn ($r) => (float)$r['required_met_rate'], $ok)),
            'progression_repeat_rate' => ta_median(array_map(static fn ($r) => (float)$r['progression_repeat_rate'], $ok)),
            'downstream_problem_rate' => ta_median(array_map(static fn ($r) => $r['downstream_problem_rate'] !== null ? (float)$r['downstream_problem_rate'] : null, $ok)),
            'n_instructors' => count($ok),
        ];

        foreach ($rows as &$r) {
            $r['duplicate_identity'] = isset($dupIds[(string)$r['instructor_id']]);
            $r['baseline'] = $baseline;
            $r['signal_label'] = ta_instructor_signal_label((string)($r['pattern_signal'] ?? ''));
        }
        unset($r);

        return $rows;
    }
}

if (!function_exists('ta_median')) {
    /** @param list<?float> $vals */
    function ta_median(array $vals): ?float
    {
        $vals = array_values(array_filter($vals, static fn ($v) => $v !== null && !is_nan((float)$v)));
        if (!$vals) {
            return null;
        }
        sort($vals);
        $n = count($vals);
        $m = intdiv($n, 2);

        return $n % 2 ? (float)$vals[$m] : (((float)$vals[$m - 1] + (float)$vals[$m]) / 2.0);
    }
}

if (!function_exists('ta_instructor_signal_label')) {
    function ta_instructor_signal_label(string $signal): string
    {
        return match ($signal) {
            'FAST_ADVANCEMENT' => 'FAST_ADVANCEMENT_WITH_STABILITY',
            'STRICT_SIGNAL' => 'POSSIBLE_STRICT_PATTERN',
            'POSSIBLE_OVERTRAINING' => 'HIGH_REPEAT_WITHOUT_OBVIOUS_DOWNSTREAM_BENEFIT',
            'POSSIBLE_PREMATURE_ADVANCEMENT' => 'POSSIBLE_PREMATURE_ADVANCEMENT',
            'UNCLEAR' => 'UNCLEAR',
            default => $signal !== '' ? $signal : 'UNCLEAR',
        };
    }
}

if (!function_exists('ta_trajectory_distribution')) {
    /**
     * @return list<array{label:string,c:int,share:float}>
     */
    function ta_trajectory_distribution(PDO $db, ?string $programId = null, ?string $eraFrom = null, ?string $eraTo = null): array
    {
        // Era filter not on trajectory table — note when requested
        $where = ['1=1'];
        $args = [];
        if ($programId) {
            $where[] = 'CAST(program_id AS TEXT) = ?';
            $args[] = $programId;
        }
        $rows = ta_query_all(
            $db,
            'SELECT trajectory_label AS label, COUNT(*) AS c
             FROM analysis_student_trajectory
             WHERE ' . implode(' AND ', $where) . '
             GROUP BY trajectory_label
             ORDER BY c DESC',
            $args
        );
        $total = 0;
        foreach ($rows as $r) {
            $total += (int)$r['c'];
        }
        foreach ($rows as &$r) {
            $r['share'] = $total > 0 ? ((int)$r['c'] / $total) : 0.0;
        }
        unset($r);

        return $rows;
    }
}

if (!function_exists('ta_trajectory_group_profile')) {
    /**
     * Aggregate characteristics for a trajectory label (anonymized).
     *
     * @return array<string,mixed>|null
     */
    function ta_trajectory_group_profile(PDO $db, string $label, ?string $programId = null): ?array
    {
        $where = ['trajectory_label = ?'];
        $args = [$label];
        if ($programId) {
            $where[] = 'CAST(program_id AS TEXT) = ?';
            $args[] = $programId;
        }
        $row = ta_query_one(
            $db,
            'SELECT COUNT(*) AS n,
                    AVG(sessions) AS avg_sessions,
                    AVG(flight_hours) AS avg_flight_hours,
                    AVG(sim_hours) AS avg_sim_hours,
                    AVG(calendar_days) AS avg_calendar_days,
                    AVG(progression_mission_repeats) AS avg_repeats,
                    AVG(exercise_regression_count) AS avg_regressions,
                    AVG(median_gap_days) AS avg_median_gap,
                    AVG(instructor_switches) AS avg_instructor_switches,
                    AVG(pe_stability_rate) AS avg_pe_stability,
                    AVG(below_required_rate) AS avg_below_required
             FROM analysis_student_trajectory
             WHERE ' . implode(' AND ', $where),
            $args
        );
        if (!$row || (int)($row['n'] ?? 0) === 0) {
            return null;
        }

        return $row;
    }
}

if (!function_exists('ta_research_status')) {
    /** @return list<array{label:string,state:string,note:string}> */
    function ta_research_status(PDO $db): array
    {
        $llm = ta_query_one($db, 'SELECT status, hashes_done, hashes_remaining, notes FROM phase10_llm_status LIMIT 1');
        $p6 = ta_query_one($db, 'SELECT notes FROM phase6_meta LIMIT 1');
        $enrichState = 'IN PROGRESS';
        $enrichNote = 'See phase10_llm_status / phase6_meta';
        if ($llm) {
            $done = (int)($llm['hashes_done'] ?? 0);
            $rem = (int)($llm['hashes_remaining'] ?? 0);
            $st = strtoupper((string)$llm['status']);
            if ($st === 'COMPLETE' || ($rem === 0 && $done > 0)) {
                $enrichState = 'COMPLETE';
            } elseif (in_array($st, ['BLOCKED', 'IN_PROGRESS', 'RUNNING'], true)) {
                $enrichState = $st === 'BLOCKED' ? 'IN PROGRESS' : 'IN PROGRESS';
            }
            $enrichNote = 'LLM hashes done=' . ta_fmt_int($done) . ', remaining=' . ta_fmt_int($rem) . ' · ' . (string)($llm['notes'] ?? '');
        } elseif ($p6) {
            $enrichNote = (string)$p6['notes'];
        }

        return [
            ['label' => 'HISTORICAL STRUCTURED', 'state' => 'COMPLETE', 'note' => 'Phases 3–4 analysis_* materializations'],
            ['label' => 'NARRATIVE VALIDATION', 'state' => 'COMPLETE', 'note' => 'Phase 5/5B validation sample (n=405)'],
            ['label' => 'TARGETED LLM ENRICHMENT', 'state' => $enrichState, 'note' => $enrichNote],
            ['label' => 'LIVE COMPETENCY VALIDATION', 'state' => 'SEPARATE PHASE 10C', 'note' => 'Not mixed into historical management conclusions'],
        ];
    }
}

if (!function_exists('ta_data_quality_snapshot')) {
    /** @return array<string,mixed> */
    function ta_data_quality_snapshot(PDO $db): array
    {
        $scope = ta_dataset_scope($db);
        $agg = ta_query_one(
            $db,
            "SELECT
                SUM(CASE WHEN qa_class='HIGH_CONFIDENCE' THEN 1 ELSE 0 END) AS qa_high,
                SUM(CASE WHEN qa_class='USABLE_WITH_QUALIFICATION' THEN 1 ELSE 0 END) AS qa_usable,
                SUM(CASE WHEN qa_class='AMBIGUOUS' THEN 1 ELSE 0 END) AS qa_amb,
                SUM(CASE WHEN qa_class='EXCLUDE' THEN 1 ELSE 0 END) AS qa_excl,
                SUM(CASE WHEN ex_blob_parse_status='OK' THEN 1 ELSE 0 END) AS ex_ok,
                SUM(CASE WHEN ex_blob_parse_status='EMPTY' THEN 1 ELSE 0 END) AS ex_empty,
                SUM(CASE WHEN session_date_valid=0 THEN 1 ELSE 0 END) AS invalid_dates,
                SUM(CASE WHEN student_id IS NULL OR CAST(student_id AS TEXT)='' THEN 1 ELSE 0 END) AS missing_student,
                SUM(CASE WHEN mission_id IS NULL THEN 1 ELSE 0 END) AS missing_mission,
                SUM(CASE WHEN narrative_public_present=1 OR narrative_private_present=1 THEN 1 ELSE 0 END) AS narrative_sessions
             FROM fact_training_session"
        ) ?? [];

        $qa = array_filter([
            'HIGH_CONFIDENCE' => (int)($agg['qa_high'] ?? 0),
            'USABLE_WITH_QUALIFICATION' => (int)($agg['qa_usable'] ?? 0),
            'AMBIGUOUS' => (int)($agg['qa_amb'] ?? 0),
            'EXCLUDE' => (int)($agg['qa_excl'] ?? 0),
        ], static fn ($v) => $v > 0);
        $exBlob = array_filter([
            'OK' => (int)($agg['ex_ok'] ?? 0),
            'EMPTY' => (int)($agg['ex_empty'] ?? 0),
        ], static fn ($v) => $v > 0);

        $excl = ta_query_all($db, 'SELECT reason_code, qa_class, COUNT(*) AS c FROM qa_exclusion_log GROUP BY reason_code, qa_class ORDER BY c DESC');
        $issues = ta_query_all($db, 'SELECT issue_code, severity, COUNT(*) AS c FROM qa_data_issue GROUP BY issue_code, severity ORDER BY c DESC');
        $roleCov = ta_query_one($db, 'SELECT COUNT(*) AS classified FROM analysis_mission_role');
        $missions = (int)ta_query_value($db, 'SELECT COUNT(*) FROM dim_mission', [], 0);
        $extractors = ta_query_all($db, 'SELECT extractor, COUNT(*) AS c FROM analysis_phase6_narrative_extraction GROUP BY extractor');

        return [
            'scope' => $scope,
            'qa_class' => $qa,
            'ex_blob' => $exBlob,
            'invalid_dates' => (int)($agg['invalid_dates'] ?? 0),
            'missing_student' => (int)($agg['missing_student'] ?? 0),
            'missing_mission' => (int)($agg['missing_mission'] ?? 0),
            'exclusions' => $excl,
            'issues' => $issues,
            'mission_role_classified' => (int)($roleCov['classified'] ?? 0),
            'missions_total' => $missions,
            'narrative_sessions' => (int)($agg['narrative_sessions'] ?? 0),
            'extractors' => $extractors,
            'instructor_dups' => ta_instructor_duplicate_names($db),
            'freshness' => ta_analysis_freshness($db),
        ];
    }
}
