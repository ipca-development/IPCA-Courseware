<?php
declare(strict_types=1);

require_once __DIR__ . '/../openai.php';
require_once __DIR__ . '/mock_oral_bootstrap.php';

final class ConversationalOrchestrator
{
    public function __construct(private readonly PDO $pdo)
    {
        mo_ensure_tables($this->pdo);
    }

    public function nextMayaTurn(array $session, array $blueprint, int $turnIndex, array $transcriptSoFar = []): array
    {
        $planned = (array)($blueprint['planned_turns'] ?? []);
        foreach ($planned as $turn) {
            if ((int)($turn['turn'] ?? 0) === $turnIndex) {
                return [
                    'turn_index' => $turnIndex,
                    'maya_text' => trim((string)($turn['maya_prompt'] ?? '')),
                    'acs_refs' => (array)($turn['acs_refs'] ?? []),
                    'source' => 'blueprint',
                ];
            }
        }

        if ($turnIndex === 0) {
            $opening = trim((string)($blueprint['opening_scenario'] ?? ''));
            if ($opening !== '') {
                return [
                    'turn_index' => 0,
                    'maya_text' => $opening,
                    'acs_refs' => (array)($blueprint['required_acs_tasks'] ?? []),
                    'source' => 'opening',
                ];
            }
        }

        return [
            'turn_index' => $turnIndex,
            'maya_text' => 'Let us continue with your cross-country planning scenario. What would you do next?',
            'acs_refs' => [],
            'source' => 'fallback',
        ];
    }

    public function evaluateStudentTurn(array $session, array $blueprint, int $turnIndex, string $studentText, array $transcriptSoFar = []): array
    {
        $systemPrompt = $this->prompt('mock_oral_turn_evaluator_system', $this->defaultEvaluatorPrompt());
        $userPayload = [
            'blueprint' => $blueprint,
            'turn_index' => $turnIndex,
            'student_answer' => $studentText,
            'transcript_so_far' => $transcriptSoFar,
        ];

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'score_pct' => ['type' => 'number'],
                'missing_concepts' => ['type' => 'array', 'items' => ['type' => 'string']],
                'acs_task_codes' => ['type' => 'array', 'items' => ['type' => 'string']],
                'feedback_for_student' => ['type' => 'string'],
                'follow_up' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'needed' => ['type' => 'boolean'],
                        'maya_text' => ['type' => 'string'],
                        'reason' => ['type' => 'string'],
                    ],
                    'required' => ['needed', 'maya_text', 'reason'],
                ],
                'advance_to_next_planned_turn' => ['type' => 'boolean'],
            ],
            'required' => ['score_pct', 'missing_concepts', 'acs_task_codes', 'feedback_for_student', 'follow_up', 'advance_to_next_planned_turn'],
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
                    'name' => 'mock_oral_turn_eval',
                    'schema' => $schema,
                ],
            ],
        ];

        $resp = cw_openai_responses($payload);
        $text = cw_openai_extract_json_text($resp);
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Turn evaluation returned invalid JSON.');
        }

        $this->pdo->prepare('
            INSERT INTO mock_oral_turn_evaluations
              (session_id, turn_index, acs_task_codes_json, score_pct, missing_concepts_json, follow_up_directive_json, evaluator_model, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE
              acs_task_codes_json = VALUES(acs_task_codes_json),
              score_pct = VALUES(score_pct),
              missing_concepts_json = VALUES(missing_concepts_json),
              follow_up_directive_json = VALUES(follow_up_directive_json),
              evaluator_model = VALUES(evaluator_model)
        ')->execute([
            (int)$session['id'],
            $turnIndex,
            mo_json_encode((array)($decoded['acs_task_codes'] ?? [])),
            (float)($decoded['score_pct'] ?? 0),
            mo_json_encode((array)($decoded['missing_concepts'] ?? [])),
            mo_json_encode((array)($decoded['follow_up'] ?? [])),
            cw_openai_model(),
        ]);

        return $decoded;
    }

    public function logTranscriptEvent(int $sessionId, string $role, int $turnIndex, string $text, string $eventType = 'utterance', array $meta = []): void
    {
        if (!in_array($role, ['maya', 'student', 'system'], true)) {
            $role = 'system';
        }
        $this->pdo->prepare('
            INSERT INTO mock_oral_transcript_events
              (session_id, role, turn_index, event_type, transcript_text, meta_json, created_at)
            VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
        ')->execute([
            $sessionId,
            $role,
            $turnIndex,
            $eventType,
            $text,
            $meta ? mo_json_encode($meta) : null,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function loadTranscript(int $sessionId): array
    {
        $st = $this->pdo->prepare('
            SELECT role, turn_index, transcript_text, event_type, created_at
            FROM mock_oral_transcript_events
            WHERE session_id = ?
            ORDER BY id ASC
        ');
        $st->execute([$sessionId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
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

    private function defaultEvaluatorPrompt(): string
    {
        return 'You are an FAA DPE evaluating a Private Pilot oral exam answer. '
            . 'Use the session blueprint as authoritative scope. '
            . 'Return JSON only. Score 0-100. Identify missing concepts. '
            . 'Suggest natural follow-up questions when answers are partial or weak.';
    }
}
