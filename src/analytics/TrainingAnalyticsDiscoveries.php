<?php
declare(strict_types=1);

require_once __DIR__ . '/TrainingAnalyticsDb.php';
require_once __DIR__ . '/TrainingAnalyticsQueries.php';

/**
 * Ranked management discoveries built from analysis_* evidence only.
 */

if (!function_exists('ta_discoveries_catalog')) {
    /**
     * @return list<array<string,mixed>>
     */
    function ta_discoveries_catalog(PDO $db): array
    {
        $out = [];

        // --- CONTINUITY ---
        $b02 = ta_query_one($db, "SELECT coefficient, n FROM analysis_training_gap_effect WHERE model_name='descriptive_bucket' AND stratum='all_sessions' AND predictor='gap=0-2' AND outcome='pct_incomplete' LIMIT 1");
        $b21 = ta_query_one($db, "SELECT coefficient, n FROM analysis_training_gap_effect WHERE model_name='descriptive_bucket' AND stratum='all_sessions' AND predictor='gap=21+' AND outcome='pct_incomplete' LIMIT 1");
        $logit = ta_query_one($db, "SELECT odds_ratio, ci_low, ci_high, n, p_value, analysis_version FROM analysis_training_gap_effect WHERE model_name='logit_log_gap' AND stratum='all_sessions' AND outcome='incomplete' LIMIT 1");
        if ($b02 && $b21) {
            $out[] = [
                'id' => 'continuity-gap-dose',
                'category' => 'CONTINUITY',
                'finding' => 'Training continuity shows a graded incomplete-rate dose response',
                'why' => 'Scheduling and leave policies that create long gaps are associated with poorer subsequent session outcomes even after program controls.',
                'evidence' => 'Descriptive buckets in analysis_training_gap_effect; program-controlled logit on log1p(days_since_previous).',
                'magnitude' => 'Incomplete ' . ta_fmt_pct($b02['coefficient']) . ' (0–2d) → ' . ta_fmt_pct($b21['coefficient']) . ' (21+d)'
                    . ($logit ? '; logit OR=' . ta_fmt_num($logit['odds_ratio'], 3) : ''),
                'n' => (int)(($b02['n'] ?? 0) + ($b21['n'] ?? 0)),
                'n_label' => 'n₀₋₂=' . ta_fmt_int($b02['n']) . ', n₂₁₊=' . ta_fmt_int($b21['n'])
                    . ($logit ? ', logit n=' . ta_fmt_int($logit['n']) : ''),
                'confidence' => 'HIGH',
                'explanations' => 'Skill decay, disrupted sequencing, and selection (struggling students may also gap more) can all contribute — association is strong, causality not claimed.',
                'action' => 'Review scheduling for gaps >~2 weeks before progression missions; treat gap risk as an operational design input.',
                'version' => (string)($logit['analysis_version'] ?? 'phase4-v1'),
                'href' => '/admin/training_analytics/continuity.php',
                'program_ids' => [],
                'rank' => 100,
            ];
        }

        // --- COMPETENCY PE ---
        $pe = ta_query_one($db, "SELECT SUM(stable_pe_rate*n_reobserved)/SUM(n_reobserved) wavg, SUM(n_reobserved) n_re FROM analysis_competency_stability WHERE n_reobserved>=10 AND required_level='PE'");
        $soft = ta_query_all($db, "SELECT exercise_name, program_id, stable_pe_rate, n_reobserved FROM analysis_competency_stability WHERE required_level='PE' AND n_reobserved>=20 AND (lower(exercise_name) LIKE '%intercept%' OR lower(exercise_name) LIKE '%hold%' OR lower(exercise_name) LIKE '%go-around%' OR lower(exercise_name) LIKE '%go around%') ORDER BY stable_pe_rate ASC LIMIT 5");
        if ($pe && $pe['wavg'] !== null) {
            $softTxt = $soft ? ' Lowest IR-related reobs stability examples include: ' . implode('; ', array_map(static fn ($r) => (string)$r['exercise_name'] . ' @ ' . ta_fmt_pct($r['stable_pe_rate']) . ' (n=' . (int)$r['n_reobserved'] . ')', $soft)) : '';
            $out[] = [
                'id' => 'pe-durability',
                'category' => 'COMPETENCY',
                'finding' => 'PE is generally durable, but not uniformly across competencies',
                'why' => 'Management can trust PE as a durable signal overall, while still investigating specific softening clusters (notably some IR skills).',
                'evidence' => 'Weighted PE stability from analysis_competency_stability (min n_reobserved=10).',
                'magnitude' => 'Weighted PE stability ' . ta_fmt_pct($pe['wavg']) . ' across reobservations',
                'n' => (int)$pe['n_re'],
                'n_label' => 'n_reobs=' . ta_fmt_int($pe['n_re']),
                'confidence' => 'HIGH',
                'explanations' => 'Some PE softens with instructor change or long gaps; some IR interception/holding items show lower reobservation stability.' . $softTxt,
                'action' => 'Use Competency Explorer softening ranks; prioritize IR path/holding/interception reviews where sample allows.',
                'version' => 'phase4-v1',
                'href' => '/admin/training_analytics/competencies.php?view=softening',
                'program_ids' => array_values(array_unique(array_map(static fn ($r) => (string)$r['program_id'], $soft))),
                'rank' => 95,
            ];
        }

        // --- CURRICULUM MIXED ---
        $pairs = function_exists('ta_curriculum_pairs') ? ta_curriculum_pairs($db) : [];
        if ($pairs) {
            $improvePe = 0;
            $worseEff = 0;
            foreach ($pairs as $p) {
                $peV = strtoupper((string)($p['metrics']['pe_stability_rate']['verdict'] ?? ''));
                $sessV = strtoupper((string)($p['metrics']['sessions_per_student']['verdict'] ?? ''));
                if (str_contains($peV, 'IMPROVEMENT')) {
                    $improvePe++;
                }
                if (str_contains($sessV, 'DETERIORATION')) {
                    $worseEff++;
                }
            }
            $out[] = [
                'id' => 'curriculum-mixed',
                'category' => 'CURRICULUM',
                'finding' => 'Newer curricula show mixed efficiency with stronger durability in some families',
                'why' => 'Curriculum replacement decisions should not be judged by hours/sessions alone.',
                'evidence' => 'analysis_curriculum_comparison across verified family pairs (PPL, MEP, IR, CPL).',
                'magnitude' => count($pairs) . ' family pairs compared; PE-stability improvements in ' . $improvePe . ' pairs; session-burden deterioration signals in ' . $worseEff . ' pairs',
                'n' => count($pairs),
                'n_label' => count($pairs) . ' curriculum pairs',
                'confidence' => 'MODERATE',
                'explanations' => 'Program mix, era, and student selection differ between generations; some newer tracks are still small-n.',
                'action' => 'Review Curriculum Comparison for each family; weigh PE durability and repeats alongside hours.',
                'version' => 'phase4-v1',
                'href' => '/admin/training_analytics/curriculum.php',
                'program_ids' => [],
                'rank' => 88,
            ];
        }

        // --- APS MCC stretch ---
        $aps = ta_query_one($db, "SELECT * FROM analysis_unexpected_finding WHERE finding_id=12 OR title LIKE '%APS MCC%' LIMIT 1");
        if ($aps) {
            $out[] = [
                'id' => 'aps-mcc-stretch',
                'category' => 'CURRICULUM',
                'finding' => (string)$aps['title'],
                'why' => 'High not-met rates here look more like intentional stretch standards than simple curriculum failure.',
                'evidence' => (string)$aps['evidence'] . ' · ' . (string)($aps['notes'] ?? ''),
                'magnitude' => (string)$aps['magnitude'],
                'n' => (int)$aps['n'],
                'n_label' => 'n=' . ta_fmt_int($aps['n']),
                'confidence' => ta_confidence_label((string)$aps['confidence']),
                'explanations' => 'Advanced multi-crew challenge design can elevate PE not-met without implying the program is “failing.”',
                'action' => 'Interpret APS MCC difficulty in Program health with stretch-standard framing; avoid naïve benchmarking against PPL/IR not-met rates.',
                'version' => (string)($aps['analysis_version'] ?? 'phase4-v1'),
                'href' => '/admin/training_analytics/program.php?program_id=13',
                'program_ids' => ['13'],
                'rank' => 82,
            ];
        }

        // --- NARRATIVE MISMATCH ---
        $narr = ta_query_one($db, "SELECT metric_value, n, population, analysis_version FROM analysis_phase6_scale_findings WHERE finding_name='phase5b_llm_mismatch_hidden_signal' LIMIT 1");
        $enc = ta_query_one($db, "SELECT metric_value, n FROM analysis_phase6_scale_findings WHERE finding_name='phase5b_llm_encouraging_def' LIMIT 1");
        if ($narr) {
            $out[] = [
                'id' => 'narrative-mismatch',
                'category' => 'NARRATIVE',
                'finding' => 'Structured grades miss substantial narrative signal',
                'why' => 'Management reviews that look only at structured grades will under-detect deficiencies and context.',
                'evidence' => 'Phase 5B LLM-validated reference sample (analysis_phase6_scale_findings / analysis_phase5_mismatch_llm).',
                'magnitude' => 'Mismatch/hidden-signal rate ' . ta_fmt_pct($narr['metric_value'])
                    . ($enc ? '; encouraging tone + deficiency ≈ ' . ta_fmt_pct($enc['metric_value']) : ''),
                'n' => (int)$narr['n'],
                'n_label' => 'Phase 5B sample n=' . ta_fmt_int($narr['n']) . ' · population=' . (string)$narr['population'],
                'confidence' => 'HIGH',
                'explanations' => 'Instructors often write richer performance detail than the grade field captures; silence and encouragement are not the same as independence or mastery.',
                'action' => 'Use Narrative Insights in management reviews; design future independence capture explicitly (Cockpit Recorder).',
                'version' => (string)($narr['analysis_version'] ?? 'phase6-v1'),
                'href' => '/admin/training_analytics/narratives.php',
                'program_ids' => [],
                'rank' => 92,
            ];
        }

        // --- EARLY WARNING consistency ---
        $cons = ta_query_one($db, "SELECT * FROM analysis_phase6_early_warning_pattern WHERE pattern_code='CONSISTENCY_CONCERN' LIMIT 1");
        if ($cons) {
            $out[] = [
                'id' => 'ew-consistency',
                'category' => 'EARLY_WARNING',
                'finding' => 'VARIABLE consistency precedes later training problems',
                'why' => 'Consistency language is an early-warning signal for progression risk — not a student label.',
                'evidence' => 'analysis_phase6_early_warning_pattern · CONSISTENCY_CONCERN',
                'magnitude' => 'Later-problem rate ' . ta_fmt_pct($cons['later_problem_rate']) . ' vs baseline ' . ta_fmt_pct($cons['baseline_rate']),
                'n' => (int)$cons['n_episodes'],
                'n_label' => 'n_episodes=' . ta_fmt_int($cons['n_episodes']) . ', n_students=' . ta_fmt_int($cons['n_students']),
                'confidence' => 'MODERATE',
                'explanations' => 'May reflect true inconsistency, tougher missions, or narrative style differences across instructors.',
                'action' => 'Flag VARIABLE consistency windows for instructor briefing before checkpoint/progression missions.',
                'version' => (string)($cons['analysis_version'] ?? 'phase6-v1'),
                'href' => '/admin/training_analytics/narratives.php#early-warning',
                'program_ids' => [],
                'rank' => 90,
            ];
        }

        $repDef = ta_query_one($db, "SELECT * FROM analysis_phase6_early_warning_pattern WHERE pattern_code='REPEATED_DEFICIENCY_WINDOW' LIMIT 1");
        if ($repDef) {
            $out[] = [
                'id' => 'ew-repeated-def',
                'category' => 'EARLY_WARNING',
                'finding' => 'Repeated deficiency windows are a strong early-warning pattern',
                'why' => 'Clusters of recent deficiency signals associate with substantially elevated later-problem rates.',
                'evidence' => 'analysis_phase6_early_warning_pattern · REPEATED_DEFICIENCY_WINDOW (≥3 of last ≤5 enriched observations)',
                'magnitude' => 'Later-problem rate ' . ta_fmt_pct($repDef['later_problem_rate']) . ' vs baseline ' . ta_fmt_pct($repDef['baseline_rate']),
                'n' => (int)$repDef['n_episodes'],
                'n_label' => 'n_episodes=' . ta_fmt_int($repDef['n_episodes']) . ', n_students=' . ta_fmt_int($repDef['n_students']),
                'confidence' => 'MODERATE',
                'explanations' => 'Enrichment source mix includes heuristic-scaled narratives; interpret rates with extractor caveats on Narratives page.',
                'action' => 'Treat repeated deficiency windows as triage for curriculum pace / remediation — not as punitive labels.',
                'version' => (string)($repDef['analysis_version'] ?? 'phase6-v1'),
                'href' => '/admin/training_analytics/narratives.php#early-warning',
                'program_ids' => [],
                'rank' => 91,
            ];
        }

        $hg = ta_query_one($db, "SELECT * FROM analysis_phase6_early_warning_pattern WHERE pattern_code='HIGH_GRADE_NARRATIVE_DEFICIENCY' LIMIT 1");
        if ($hg) {
            $out[] = [
                'id' => 'ew-high-grade-def',
                'category' => 'NARRATIVE',
                'finding' => 'High structured grade + narrative deficiency predicts later problems',
                'why' => 'Optimistic grades that coexist with written deficiencies are not harmless noise.',
                'evidence' => 'analysis_phase6_early_warning_pattern · HIGH_GRADE_NARRATIVE_DEFICIENCY',
                'magnitude' => 'Later-problem rate ' . ta_fmt_pct($hg['later_problem_rate']) . ' vs baseline ' . ta_fmt_pct($hg['baseline_rate']),
                'n' => (int)$hg['n_episodes'],
                'n_label' => 'n_episodes=' . ta_fmt_int($hg['n_episodes']) . ', n_students=' . ta_fmt_int($hg['n_students']),
                'confidence' => 'MODERATE',
                'explanations' => 'May reflect grade inflation, deferred remediation, or narrative style — still operationally useful as a review trigger.',
                'action' => 'When grades are strong but narratives note deficiencies, require explicit independence/quality confirmation in future sessions.',
                'version' => (string)($hg['analysis_version'] ?? 'phase6-v1'),
                'href' => '/admin/training_analytics/narratives.php',
                'program_ids' => [],
                'rank' => 89,
            ];
        }

        // --- MISSION ROLE ---
        $role = ta_query_one($db, "SELECT * FROM analysis_unexpected_finding WHERE finding_id=11 OR title LIKE '%accumulation%' OR title LIKE '%mission role%' LIMIT 1");
        if ($role) {
            $out[] = [
                'id' => 'mission-role-filter',
                'category' => 'UNEXPECTED',
                'finding' => (string)$role['title'],
                'why' => 'Naive “most repeated missions” lists waste management time on intentional accumulation/proficiency events.',
                'evidence' => (string)$role['evidence'] . ' · ' . (string)($role['notes'] ?? ''),
                'magnitude' => (string)$role['magnitude'],
                'n' => (int)$role['n'],
                'n_label' => 'n_sessions≈' . ta_fmt_int($role['n']),
                'confidence' => ta_confidence_label((string)$role['confidence']),
                'explanations' => 'Mission roles (PROGRESSION / ACCUMULATION / PROFICIENCY / BRIEFING / CHECK / SOLO) must be applied before interpreting repeat burden.',
                'action' => 'Default bottleneck reviews to PROGRESSION_MISSION only (already implemented in Mission Bottlenecks).',
                'version' => (string)($role['analysis_version'] ?? 'phase4-v1'),
                'href' => '/admin/training_analytics/bottlenecks.php',
                'program_ids' => [],
                'rank' => 86,
            ];
        }

        // --- INDEPENDENCE ---
        $indep = ta_query_one($db, "SELECT * FROM analysis_phase5_final_architecture WHERE field_name='observed_independence' LIMIT 1");
        $assistPresent = ta_query_one($db, "SELECT llm_rate, heuristic_rate, n FROM analysis_phase5_extractor_summary WHERE metric_name='assistance_present' LIMIT 1");
        if ($indep || $assistPresent) {
            $mag = $assistPresent
                ? 'Assistance language present in ≈' . ta_fmt_pct($assistPresent['llm_rate']) . ' (LLM) vs ≈' . ta_fmt_pct($assistPresent['heuristic_rate']) . ' (heuristic) of Phase 5B sample'
                : 'Architecture retains observed_independence with explicit UNKNOWN state';
            $out[] = [
                'id' => 'independence-not-observed',
                'category' => 'NARRATIVE',
                'finding' => 'Historical independence cannot be reconstructed reliably from narrative silence',
                'why' => 'Absence of assistance language ≠ independent performance. Future systems must capture independence explicitly.',
                'evidence' => 'analysis_phase5_final_architecture.observed_independence + Phase 5B assistance_present rates',
                'magnitude' => $mag,
                'n' => (int)($assistPresent['n'] ?? 0),
                'n_label' => $assistPresent ? 'Phase 5B n=' . ta_fmt_int($assistPresent['n']) : 'architecture field retained',
                'confidence' => 'HIGH',
                'explanations' => 'Instructors often omit assistance details when busy; silence is NOT_OBSERVED / UNKNOWN, not proof of independence.',
                'action' => 'Keep NOT_OBSERVED framing in management communications; Cockpit Recorder independence is a design requirement, not optional.',
                'version' => 'phase5b-v1',
                'href' => '/admin/training_analytics/narratives.php#independence',
                'program_ids' => [],
                'rank' => 94,
            ];
        }

        // --- DATA QUALITY curriculum names ---
        $nameCollision = ta_query_all($db, "SELECT program_name, COUNT(*) c, GROUP_CONCAT(program_id) ids FROM dim_program GROUP BY program_name HAVING c>1");
        // Also MEP shares display name across versions
        $mep = ta_query_all($db, "SELECT program_id, program_name, curriculum_version_id FROM dim_program WHERE program_name LIKE '%MEP%'");
        if ($nameCollision || count($mep) >= 2) {
            $examples = [];
            foreach ($nameCollision as $nc) {
                $examples[] = (string)$nc['program_name'] . ' → ids ' . (string)$nc['ids'];
            }
            if (!$examples && $mep) {
                foreach ($mep as $m) {
                    $examples[] = 'program_id=' . $m['program_id'] . ' · ' . $m['program_name'] . ' · version_id=' . $m['curriculum_version_id'];
                }
            }
            $out[] = [
                'id' => 'curriculum-display-names',
                'category' => 'DATA_QUALITY',
                'finding' => 'Curriculum display names are insufficient analytical identifiers',
                'why' => 'Old/new generations can share or nearly share display names; wrong merges corrupt curriculum comparisons.',
                'evidence' => 'dim_program identity = program_id + curriculum_version + source tracking table',
                'magnitude' => implode(' | ', $examples),
                'n' => count($mep) + count($nameCollision),
                'n_label' => 'identity collisions/near-collisions inspected in dim_program',
                'confidence' => 'HIGH',
                'explanations' => 'Human-facing labels stay for readability; analytics must use canonical IDs.',
                'action' => 'Always show program ID / version code in Program health; never filter analytics by display name alone.',
                'version' => 'phase3-extraction',
                'href' => '/admin/training_analytics/programs.php',
                'program_ids' => array_map(static fn ($m) => (string)$m['program_id'], $mep),
                'rank' => 80,
            ];
        }

        // --- INSTRUCTOR identity ---
        $idDup = ta_query_one($db, "SELECT * FROM analysis_unexpected_finding WHERE finding_id=10 OR title LIKE '%identical names%' LIMIT 1");
        if ($idDup) {
            $out[] = [
                'id' => 'instructor-name-collision',
                'category' => 'INSTRUCTOR',
                'finding' => (string)$idDup['title'],
                'why' => 'Calibration deltas can be attributed to the wrong person if duplicate source IDs are silently merged.',
                'evidence' => (string)$idDup['evidence'] . ' · ' . (string)($idDup['notes'] ?? ''),
                'magnitude' => (string)$idDup['magnitude'],
                'n' => (int)$idDup['n'],
                'n_label' => 'n=' . ta_fmt_int($idDup['n']),
                'confidence' => ta_confidence_label((string)$idDup['confidence']),
                'explanations' => 'Willy Rozendaal appears as instructor_id 59 and 70 in dim_instructor — unresolved duplicate candidate.',
                'action' => 'Surface identity warnings on Instructor pages; do not merge until mapping is verified.',
                'version' => (string)($idDup['analysis_version'] ?? 'phase4-v1'),
                'href' => '/admin/training_analytics/instructors.php',
                'program_ids' => [],
                'rank' => 78,
            ];
        }

        // --- CO-DIFFICULTY ---
        $codiffN = (int)ta_query_value($db, 'SELECT COUNT(*) FROM analysis_codifficulty', [], 0);
        $prereqN = (int)ta_query_value($db, 'SELECT COUNT(*) FROM analysis_prerequisite_candidate', [], 0);
        if ($codiffN > 0 || $prereqN > 0) {
            $top = ta_query_one($db, 'SELECT exercise_a_name, exercise_b_name, lift, n_co_difficult, evidence_confidence FROM analysis_codifficulty ORDER BY lift DESC LIMIT 1');
            $out[] = [
                'id' => 'codiff-prereq',
                'category' => 'COMPETENCY',
                'finding' => 'Co-difficulty and prerequisite patterns suggest some weaknesses are not isolated maneuver problems',
                'why' => 'Remediation that only re-trains a single exercise may miss linked skill clusters.',
                'evidence' => 'analysis_codifficulty (' . $codiffN . ' pairs) · analysis_prerequisite_candidate (' . $prereqN . ' candidates)',
                'magnitude' => $top
                    ? 'Top lift example: ' . (string)$top['exercise_a_name'] . ' ↔ ' . (string)$top['exercise_b_name'] . ' (lift=' . ta_fmt_num($top['lift'], 2) . ', n=' . ta_fmt_int($top['n_co_difficult']) . ')'
                    : $prereqN . ' prerequisite candidates',
                'n' => $codiffN + $prereqN,
                'n_label' => 'codiff pairs=' . $codiffN . ', prereq candidates=' . $prereqN,
                'confidence' => 'EXPLORATORY',
                'explanations' => 'Associations can reflect curriculum sequencing, shared context, or true skill dependence — not proven causal prerequisites.',
                'action' => 'Inspect Competency drill-downs for co-difficult partners before redesigning a single exercise.',
                'version' => 'phase4-v1',
                'href' => '/admin/training_analytics/competencies.php',
                'program_ids' => [],
                'rank' => 76,
            ];
        }

        // Remaining unexpected findings not already covered
        foreach (ta_query_all($db, 'SELECT * FROM analysis_unexpected_finding ORDER BY finding_id') as $uf) {
            $title = (string)$uf['title'];
            $skip = false;
            foreach (['continuity', 'APS MCC', 'accumulation', 'identical names'] as $needle) {
                if (stripos($title, $needle) !== false) {
                    $skip = true;
                    break;
                }
            }
            // also skip if we already have instructor-change as separate - include instructor-change & ground modalities & returns
            if ($skip) {
                continue;
            }
            $id = 'unexpected-' . (int)$uf['finding_id'];
            $already = false;
            foreach ($out as $o) {
                if (($o['id'] ?? '') === $id) {
                    $already = true;
                    break;
                }
            }
            if ($already) {
                continue;
            }
            $cat = 'UNEXPECTED';
            if (stripos($title, 'instructor') !== false) {
                $cat = 'INSTRUCTOR';
            }
            if (stripos($title, 'Ground') !== false || stripos($title, 'modalit') !== false) {
                $cat = 'DATA_QUALITY';
            }
            $out[] = [
                'id' => $id,
                'category' => $cat,
                'finding' => $title,
                'why' => (string)($uf['notes'] ?? 'Unexpected pattern from Phase 4 deep analysis.'),
                'evidence' => (string)$uf['evidence'],
                'magnitude' => (string)$uf['magnitude'],
                'n' => (int)$uf['n'],
                'n_label' => 'n=' . ta_fmt_int($uf['n']),
                'confidence' => ta_confidence_label((string)$uf['confidence']),
                'explanations' => (string)($uf['notes'] ?? 'See evidence field; treat as research notebook entry.'),
                'action' => 'Review in context of related Continuity / Instructor / Data Quality views.',
                'version' => (string)($uf['analysis_version'] ?? 'phase4-v1'),
                'href' => '/admin/training_analytics/data_quality.php',
                'program_ids' => [],
                'rank' => 50 + min(20, (int)$uf['finding_id']),
            ];
        }

        usort($out, static function ($a, $b) {
            $c = ($b['rank'] ?? 0) <=> ($a['rank'] ?? 0);
            return $c !== 0 ? $c : strcmp((string)$a['id'], (string)$b['id']);
        });

        return $out;
    }
}

if (!function_exists('ta_filter_discoveries')) {
    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    function ta_filter_discoveries(array $items, ?string $category, ?string $confidence, ?string $programId): array
    {
        $out = [];
        foreach ($items as $d) {
            if ($category !== null && $category !== '' && (string)$d['category'] !== $category) {
                continue;
            }
            if ($confidence !== null && $confidence !== '') {
                $raw = strtoupper(trim((string)$d['confidence']));
                $bucket = match (true) {
                    in_array($raw, ['HIGH', 'HIGH CONFIDENCE'], true) => 'HIGH',
                    in_array($raw, ['MEDIUM', 'MODERATE', 'MODERATE CONFIDENCE'], true) => 'MODERATE',
                    default => 'EXPLORATORY',
                };
                if ($bucket !== $confidence) {
                    continue;
                }
            }
            if ($programId !== null && $programId !== '') {
                $pids = $d['program_ids'] ?? [];
                // Global findings (empty program_ids) remain visible; program-specific ones must match.
                if ($pids && !in_array($programId, array_map('strval', $pids), true)) {
                    continue;
                }
            }
            $out[] = $d;
        }

        return $out;
    }
}
