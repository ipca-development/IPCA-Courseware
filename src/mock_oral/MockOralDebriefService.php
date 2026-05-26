<?php
declare(strict_types=1);

require_once __DIR__ . '/../openai.php';
require_once __DIR__ . '/mock_oral_bootstrap.php';
require_once __DIR__ . '/ConversationalOrchestrator.php';

final class MockOralDebriefService
{
    public function __construct(private readonly PDO $pdo)
    {
        mo_ensure_tables($this->pdo);
    }

    public function generate(int $sessionId): array
    {
        $session = $this->loadSession($sessionId);
        if (!$session) {
            throw new RuntimeException('Session not found.');
        }

        $blueprint = mo_json_decode((string)($session['blueprint_json'] ?? ''));
        $orchestrator = new ConversationalOrchestrator($this->pdo);
        $transcript = $orchestrator->loadTranscript($sessionId);
        $evaluations = $this->loadEvaluations($sessionId);
        $remediationContext = $this->buildRemediationContext((int)$session['area_id']);

        $systemPrompt = $this->prompt('mock_oral_debrief_system', $this->defaultDebriefPrompt());
        $userPayload = [
            'session' => [
                'area_code' => $blueprint['area_code'] ?? '',
                'area_title' => $blueprint['area_title'] ?? '',
                'score_pct' => $session['score_pct'],
            ],
            'blueprint' => $blueprint,
            'transcript' => $transcript,
            'evaluations' => $evaluations,
            'remediation_context' => $remediationContext,
        ];

        $refItemSchema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'ref_type' => ['type' => 'string'],
                'ref_code' => ['type' => 'string'],
                'ref_title' => ['type' => 'string'],
            ],
            'required' => ['ref_type', 'ref_code', 'ref_title'],
        ];

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'written_debrief_text' => ['type' => 'string'],
                'weak_areas' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'concept' => ['type' => 'string'],
                            'acs_task_code' => ['type' => 'string'],
                            'confidence_gap' => ['type' => 'string'],
                        ],
                        'required' => ['concept', 'acs_task_code', 'confidence_gap'],
                    ],
                ],
                'remediation' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'far_aim_refs' => ['type' => 'array', 'items' => $refItemSchema],
                        'phak_afh_refs' => ['type' => 'array', 'items' => $refItemSchema],
                        'ipca_lessons' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'lesson_id' => ['type' => 'integer'],
                                    'slide_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                                    'note' => ['type' => 'string'],
                                ],
                                'required' => ['lesson_id', 'slide_ids', 'note'],
                            ],
                        ],
                        'theory_summary_additions' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'lesson_id' => ['type' => 'integer'],
                                    'addition' => ['type' => 'string'],
                                ],
                                'required' => ['lesson_id', 'addition'],
                            ],
                        ],
                        'recommended_actions' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['far_aim_refs', 'phak_afh_refs', 'ipca_lessons', 'theory_summary_additions', 'recommended_actions'],
                ],
            ],
            'required' => ['written_debrief_text', 'weak_areas', 'remediation'],
        ];

        $payload = [
            'model' => cw_openai_model(),
            'input' => [
                ['role' => 'system', 'content' => [['type' => 'input_text', 'text' => $systemPrompt]]],
                ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => json_encode($userPayload, JSON_UNESCAPED_UNICODE)]]],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'mock_oral_debrief',
                    'schema' => $schema,
                ],
            ],
        ];

        $resp = cw_openai_responses($payload);
        $decoded = cw_openai_extract_json_text($resp);
        if ($decoded === []) {
            throw new RuntimeException('Debrief generation returned invalid JSON.');
        }

        $decoded['remediation'] = $this->sanitizeRemediation(
            (array)($decoded['remediation'] ?? []),
            $remediationContext,
            (int)$session['area_id']
        );
        $decoded['weak_areas'] = $this->sanitizeWeakAreas(
            (array)($decoded['weak_areas'] ?? []),
            (int)$session['area_id']
        );

        $writtenText = trim((string)($decoded['written_debrief_text'] ?? ''));
        $writtenHtml = nl2br(htmlspecialchars($writtenText, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $weakJson = mo_json_encode((array)($decoded['weak_areas'] ?? []));
        $remediationJson = mo_json_encode((array)($decoded['remediation'] ?? []));

        $this->pdo->prepare('
            INSERT INTO mock_oral_debriefs
              (session_id, written_debrief_html, written_debrief_text, weak_areas_json, remediation_json, debrief_model, generated_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE
              written_debrief_html = VALUES(written_debrief_html),
              written_debrief_text = VALUES(written_debrief_text),
              weak_areas_json = VALUES(weak_areas_json),
              remediation_json = VALUES(remediation_json),
              debrief_model = VALUES(debrief_model),
              generated_at = UTC_TIMESTAMP()
        ')->execute([
            $sessionId,
            $writtenHtml,
            $writtenText,
            $weakJson,
            $remediationJson,
            cw_openai_model(),
        ]);

        $this->persistSessionDeficiencies($sessionId, (int)$session['area_id'], (array)($decoded['weak_areas'] ?? []));

        return [
            'written_debrief_text' => $writtenText,
            'written_debrief_html' => $writtenHtml,
            'weak_areas' => (array)($decoded['weak_areas'] ?? []),
            'remediation' => (array)($decoded['remediation'] ?? []),
        ];
    }

    private function loadSession(int $sessionId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM mock_oral_sessions WHERE id = ? LIMIT 1');
        $st->execute([$sessionId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return list<array<string,mixed>> */
    private function loadEvaluations(int $sessionId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM mock_oral_turn_evaluations WHERE session_id = ? ORDER BY turn_index ASC');
        $st->execute([$sessionId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function buildRemediationContext(int $areaId): array
    {
        $lessons = [];
        $st = $this->pdo->prepare('
            SELECT m.lesson_id, m.weight, l.title AS lesson_title, c.title AS course_title
            FROM mock_oral_acs_area_lesson_map m
            INNER JOIN lessons l ON l.id = m.lesson_id
            INNER JOIN courses c ON c.id = l.course_id
            WHERE m.area_id = ?
            ORDER BY m.weight DESC, l.id ASC
            LIMIT 20
        ');
        $st->execute([$areaId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $lessonId = (int)$row['lesson_id'];
            $slides = [];
            try {
                $sst = $this->pdo->prepare('
                    SELECT s.id, s.title, sr.ref_type, sr.ref_code, sr.ref_title
                    FROM slides s
                    LEFT JOIN slide_references sr ON sr.slide_id = s.id
                    WHERE s.lesson_id = ?
                    ORDER BY s.sort_order ASC, s.id ASC
                    LIMIT 12
                ');
                $sst->execute([$lessonId]);
                $slides = $sst->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
            }
            $lessons[] = [
                'lesson_id' => $lessonId,
                'lesson_title' => (string)$row['lesson_title'],
                'course_title' => (string)$row['course_title'],
                'slides' => $slides,
            ];
        }

        $acsTasks = [];
        try {
            $tst = $this->pdo->prepare('SELECT task_code, title FROM mock_oral_acs_tasks WHERE area_id = ? ORDER BY id ASC');
            $tst->execute([$areaId]);
            $acsTasks = $tst->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
        }

        return ['mapped_lessons' => $lessons, 'acs_tasks' => $acsTasks];
    }

    private function sanitizeRemediation(array $remediation, array $context, int $areaId): array
    {
        $allowedLessons = [];
        $allowedRefKeys = [];
        foreach ((array)($context['mapped_lessons'] ?? []) as $lesson) {
            $lessonId = (int)($lesson['lesson_id'] ?? 0);
            if ($lessonId > 0) {
                $allowedLessons[$lessonId] = true;
            }
            foreach ((array)($lesson['slides'] ?? []) as $slide) {
                $refType = strtoupper(trim((string)($slide['ref_type'] ?? '')));
                $refCode = strtoupper(trim((string)($slide['ref_code'] ?? '')));
                if ($refType !== '' && $refCode !== '') {
                    $allowedRefKeys[$refType . '|' . $refCode] = true;
                }
            }
        }

        $allowedAcs = [];
        foreach ((array)($context['acs_tasks'] ?? []) as $task) {
            $code = strtoupper(trim((string)($task['task_code'] ?? '')));
            if ($code !== '') {
                $allowedAcs[$code] = true;
            }
        }

        $filterRefs = static function (array $refs, array $allowedRefKeys): array {
            $out = [];
            foreach ($refs as $ref) {
                if (!is_array($ref)) {
                    continue;
                }
                $type = strtoupper(trim((string)($ref['ref_type'] ?? $ref['type'] ?? '')));
                $code = strtoupper(trim((string)($ref['ref_code'] ?? $ref['ref'] ?? $ref['code'] ?? '')));
                if ($type !== '' && $code !== '' && isset($allowedRefKeys[$type . '|' . $code])) {
                    $out[] = $ref;
                }
            }
            return $out;
        };

        $ipcaLessons = [];
        foreach ((array)($remediation['ipca_lessons'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $lessonId = (int)($row['lesson_id'] ?? 0);
            if ($lessonId > 0 && isset($allowedLessons[$lessonId])) {
                $slideIds = [];
                foreach ((array)($row['slide_ids'] ?? []) as $sid) {
                    $sid = (int)$sid;
                    if ($sid > 0) {
                        $slideIds[] = $sid;
                    }
                }
                $row['slide_ids'] = $slideIds;
                $ipcaLessons[] = $row;
            }
        }

        $summaryAdds = [];
        foreach ((array)($remediation['theory_summary_additions'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $lessonId = (int)($row['lesson_id'] ?? 0);
            if ($lessonId > 0 && isset($allowedLessons[$lessonId])) {
                $summaryAdds[] = $row;
            }
        }

        return [
            'far_aim_refs' => $filterRefs((array)($remediation['far_aim_refs'] ?? []), $allowedRefKeys),
            'phak_afh_refs' => $filterRefs((array)($remediation['phak_afh_refs'] ?? []), $allowedRefKeys),
            'ipca_lessons' => $ipcaLessons,
            'theory_summary_additions' => $summaryAdds,
            'recommended_actions' => array_values(array_filter(
                array_map('strval', (array)($remediation['recommended_actions'] ?? [])),
                static fn(string $v): bool => trim($v) !== ''
            )),
        ];
    }

    /** @param list<array<string,mixed>> $weakAreas */
    private function sanitizeWeakAreas(array $weakAreas, int $areaId): array
    {
        $allowedAcs = [];
        try {
            $st = $this->pdo->prepare('SELECT task_code FROM mock_oral_acs_tasks WHERE area_id = ?');
            $st->execute([$areaId]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $code) {
                $code = strtoupper(trim((string)$code));
                if ($code !== '') {
                    $allowedAcs[$code] = true;
                }
            }
        } catch (Throwable $e) {
        }

        $out = [];
        foreach ($weakAreas as $wa) {
            if (!is_array($wa)) {
                continue;
            }
            $concept = trim((string)($wa['concept'] ?? ''));
            if ($concept === '') {
                continue;
            }
            $acs = strtoupper(trim((string)($wa['acs_task_code'] ?? '')));
            if ($acs !== '' && $allowedAcs && !isset($allowedAcs[$acs])) {
                $wa['acs_task_code'] = '';
            }
            $out[] = $wa;
        }
        return $out;
    }

    private function persistSessionDeficiencies(int $sessionId, int $areaId, array $weakAreas): void
    {
        $this->pdo->prepare('DELETE FROM mock_oral_session_deficiencies WHERE session_id = ?')->execute([$sessionId]);
        $ins = $this->pdo->prepare('
            INSERT INTO mock_oral_session_deficiencies (session_id, area_id, acs_task_code, concept, weight, source, created_at)
            VALUES (?, ?, ?, ?, ?, \'session\', UTC_TIMESTAMP())
        ');
        foreach ($weakAreas as $wa) {
            if (!is_array($wa)) {
                continue;
            }
            $concept = trim((string)($wa['concept'] ?? ''));
            if ($concept === '') {
                continue;
            }
            $ins->execute([
                $sessionId,
                $areaId,
                trim((string)($wa['acs_task_code'] ?? '')) ?: null,
                $concept,
                1.0,
            ]);
        }
    }

    private function prompt(string $key, string $fallback): string
    {
        try {
            $st = $this->pdo->prepare('SELECT prompt_text FROM ai_prompts WHERE prompt_key = ? LIMIT 1');
            $st->execute([$key]);
            $txt = (string)$st->fetchColumn();
            return trim($txt) !== '' ? $txt : $fallback;
        } catch (Throwable $e) {
            return $fallback;
        }
    }

    private function defaultDebriefPrompt(): string
    {
        return 'You are an IPCA Head of Training writing a mock oral debrief for a student preparing for a real DPE exam. '
            . 'Return JSON only. '
            . 'Use ONLY references present in remediation_context (slide_references, mapped_lessons, acs_tasks). '
            . 'Do NOT invent FAR/AIM, PHAK, AFH, ACS codes, lesson IDs, or slide IDs. '
            . 'If a reference is not in remediation_context, omit it and describe the study action without a citation.';
    }
}
