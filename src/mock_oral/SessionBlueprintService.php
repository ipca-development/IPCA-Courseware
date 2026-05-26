<?php
declare(strict_types=1);

require_once __DIR__ . '/../openai.php';
require_once __DIR__ . '/mock_oral_bootstrap.php';
require_once __DIR__ . '/WeakAreaAggregationService.php';

final class SessionBlueprintService
{
    public function __construct(private readonly PDO $pdo)
    {
        mo_ensure_tables($this->pdo);
    }

    public function generate(int $userId, int $cohortId, int $catalogId, int $areaId, array $weakProfile): array
    {
        $area = mo_area_by_id($this->pdo, $areaId);
        if (!$area) {
            throw new RuntimeException('ACS area not found.');
        }

        $tasks = $this->loadTasks($areaId);
        $scenarioTemplates = mo_json_decode((string)($area['scenario_templates_json'] ?? '[]'));
        $scenarioSeed = $scenarioTemplates[array_rand($scenarioTemplates)] ?? 'Standard VFR oral scenario';

        $systemPrompt = $this->prompt('mock_oral_blueprint_system', $this->defaultBlueprintSystemPrompt());
        $userPrompt = json_encode([
            'area' => [
                'code' => $area['area_code'],
                'title' => $area['title'],
            ],
            'acs_tasks' => $tasks,
            'weak_areas' => array_slice($weakProfile, 0, 12),
            'scenario_seed' => $scenarioSeed,
            'session_duration_minutes' => 5,
            'instructions' => 'Build a DPE-style conversational oral blueprint with cross-country thread, required ACS coverage, weakness priorities, and follow-up focus.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'opening_scenario' => ['type' => 'string'],
                'cross_country_context' => ['type' => 'string'],
                'required_acs_tasks' => ['type' => 'array', 'items' => ['type' => 'string']],
                'weakness_priorities' => ['type' => 'array', 'items' => ['type' => 'string']],
                'follow_up_focus' => ['type' => 'array', 'items' => ['type' => 'string']],
                'difficulty_curve' => ['type' => 'string'],
                'planned_turns' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'turn' => ['type' => 'integer'],
                            'maya_prompt' => ['type' => 'string'],
                            'acs_refs' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'targets_weakness' => ['type' => 'string'],
                        ],
                        'required' => ['turn', 'maya_prompt', 'acs_refs', 'targets_weakness'],
                    ],
                ],
            ],
            'required' => ['opening_scenario', 'cross_country_context', 'required_acs_tasks', 'weakness_priorities', 'follow_up_focus', 'difficulty_curve', 'planned_turns'],
        ];

        $result = $this->aiJson($systemPrompt, (string)$userPrompt, $schema, 'mock_oral_blueprint');
        $result['area_id'] = $areaId;
        $result['area_code'] = $area['area_code'];
        $result['area_title'] = $area['title'];
        $result['generated_at'] = gmdate('c');

        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function loadTasks(int $areaId): array
    {
        $st = $this->pdo->prepare('SELECT task_code, element_code, title, risk_level FROM mock_oral_acs_tasks WHERE area_id = ? ORDER BY id ASC LIMIT 40');
        $st->execute([$areaId]);
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

    private function defaultBlueprintSystemPrompt(): string
    {
        return 'You are an FAA DPE oral exam planner for IPCA aviation training. '
            . 'Return ONLY valid JSON matching the schema. '
            . 'Design scenario-driven conversational exams—not random disconnected questions. '
            . 'Weight weak areas heavily. Include natural follow-ups. Stay within Private Pilot ACS scope.';
    }

    private function aiJson(string $systemPrompt, string $userPrompt, array $schema, string $name): array
    {
        $payload = [
            'model' => cw_openai_model(),
            'input' => [
                ['role' => 'system', 'content' => [['type' => 'input_text', 'text' => $systemPrompt]]],
                ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => $userPrompt]]],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => $name,
                    'schema' => $schema,
                ],
            ],
        ];
        $resp = cw_openai_responses($payload);
        $decoded = cw_openai_extract_json_text($resp);
        if ($decoded === []) {
            throw new RuntimeException('Blueprint generation returned invalid JSON.');
        }
        return $decoded;
    }
}
