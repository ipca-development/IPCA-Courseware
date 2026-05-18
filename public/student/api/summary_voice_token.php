<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/openai.php';

cw_require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function sv_fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

function sv_read_json(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}

function sv_table_exists(PDO $pdo, string $table): bool
{
    try {
        $st = $pdo->prepare('SHOW TABLES LIKE ?');
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function sv_assert_lesson_access(PDO $pdo, array $u, int $cohortId, int $lessonId): void
{
    $role = (string)($u['role'] ?? '');
    if ($lessonId <= 0) sv_fail(400, 'lesson_id required');
    if ($role === 'admin') return;
    if ($cohortId <= 0) sv_fail(400, 'cohort_id required');
    $st = $pdo->prepare("
        SELECT 1
        FROM cohort_students cs
        JOIN cohort_lesson_deadlines d ON d.cohort_id = cs.cohort_id
        WHERE cs.user_id = ? AND cs.cohort_id = ? AND d.lesson_id = ?
        LIMIT 1
    ");
    $st->execute([(int)$u['id'], $cohortId, $lessonId]);
    if (!$st->fetchColumn()) sv_fail(403, 'Lesson access denied');
}

function sv_load_active_blueprint(PDO $pdo, int $lessonId): ?array
{
    if (!sv_table_exists($pdo, 'lesson_summary_blueprints') || !sv_table_exists($pdo, 'lesson_summary_blueprint_versions')) return null;
    $st = $pdo->prepare("
        SELECT b.id AS blueprint_id, v.id AS version_id, v.blueprint_json
        FROM lesson_summary_blueprints b
        JOIN lesson_summary_blueprint_versions v
          ON v.id = b.active_version_id
         AND v.blueprint_id = b.id
        WHERE b.lesson_id = ?
          AND v.lesson_id = ?
          AND b.current_status = 'active'
          AND v.status = 'active'
        LIMIT 1
    ");
    $st->execute([$lessonId, $lessonId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $json = json_decode((string)($row['blueprint_json'] ?? ''), true);
    if (!is_array($json) || !is_array($json['summary_structure'] ?? null) || !is_array($json['coaching_sequence'] ?? null)) return null;
    return [
        'blueprint_id' => (int)$row['blueprint_id'],
        'version_id' => (int)$row['version_id'],
        'blueprint' => $json,
    ];
}

function sv_first_required_section(array $blueprint): array
{
    foreach (($blueprint['summary_structure'] ?? []) as $section) {
        if (is_array($section) && !empty($section['requires_student_section'])) return $section;
    }
    return [];
}

function sv_first_sequence_step(array $blueprint): array
{
    $steps = is_array($blueprint['coaching_sequence'] ?? null) ? $blueprint['coaching_sequence'] : [];
    usort($steps, static fn (array $a, array $b): int => ((int)($a['step'] ?? 0)) <=> ((int)($b['step'] ?? 0)));
    return is_array($steps[0] ?? null) ? $steps[0] : [];
}

function sv_compact_blueprint_context(array $bundle): string
{
    $bp = $bundle['blueprint'];
    $section = sv_first_required_section($bp);
    $step = sv_first_sequence_step($bp);
    return "Blueprint version: " . (int)$bundle['version_id'] . "\n"
        . "Current section id: " . (string)($step['section_id'] ?? $section['section_id'] ?? '') . "\n"
        . "Current section title: " . (string)($section['title'] ?? '') . "\n"
        . "Current slide group: " . implode(',', array_map('intval', $step['slide_group'] ?? $section['covered_by_slides'] ?? [])) . "\n"
        . "Required concepts: " . implode('; ', array_map('strval', $section['required_concepts'] ?? [])) . "\n"
        . "Must have: " . implode('; ', array_map('strval', $section['section_acceptance_criteria']['must_have'] ?? [])) . "\n"
        . "Do not ask: " . implode('; ', array_map('strval', array_merge($bp['global_do_not_ask'] ?? [], $section['do_not_ask'] ?? [])));
}

function sv_create_voice_session_row(PDO $pdo, int $userId, int $cohortId, int $lessonId, int $blueprintVersionId): int
{
    if (!sv_table_exists($pdo, 'student_summary_voice_sessions')) return 0;
    $st = $pdo->prepare("
        INSERT INTO student_summary_voice_sessions
          (user_id, cohort_id, lesson_id, blueprint_version_id, started_at, status)
        VALUES (?, ?, ?, ?, NOW(), 'starting')
    ");
    $st->execute([$userId, $cohortId > 0 ? $cohortId : null, $lessonId, $blueprintVersionId]);
    return (int)$pdo->lastInsertId();
}

function sv_update_realtime_id(PDO $pdo, int $voiceSessionId, string $realtimeId): void
{
    if ($voiceSessionId <= 0 || !sv_table_exists($pdo, 'student_summary_voice_sessions')) return;
    $st = $pdo->prepare("UPDATE student_summary_voice_sessions SET realtime_session_id=?, status='active' WHERE id=? LIMIT 1");
    $st->execute([$realtimeId, $voiceSessionId]);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') sv_fail(405, 'POST required');
    $u = cw_current_user($pdo);
    if (!$u) sv_fail(401, 'Not authenticated');
    $role = (string)($u['role'] ?? '');
    if ($role !== 'student' && $role !== 'admin') sv_fail(403, 'Forbidden');

    $payload = sv_read_json();
    $lessonId = (int)($payload['lesson_id'] ?? 0);
    $cohortId = (int)($payload['cohort_id'] ?? 0);
    sv_assert_lesson_access($pdo, $u, $cohortId, $lessonId);

    $bundle = sv_load_active_blueprint($pdo, $lessonId);
    if (!$bundle) sv_fail(409, 'No active lesson summary blueprint exists for this lesson.');

    $voiceSessionId = sv_create_voice_session_row($pdo, (int)$u['id'], $cohortId, $lessonId, (int)$bundle['version_id']);
    $safeUser = 'ipca_user_' . (int)$u['id'] . '_lesson_' . $lessonId . '_voice_' . $voiceSessionId;
    $instructions =
        "You are Maya, an IPCA AI flight instructor and summary coach on a short voice call.\n"
        . "The canonical lesson blueprint is authoritative. Do not invent sections, slide groups, concepts, references, or completion rules.\n"
        . "Help the student write their own lesson summary. Never write the full answer or ideal bullet text. Preserve student wording.\n"
        . "Ask one focused question at a time, stay inside the current section and slide group, and do not reopen completed sections.\n"
        . "Use supportive, natural spoken coaching. Good enough is good enough; do not over-refine.\n"
        . "If you need state, ask for get_current_coaching_state. If summary text is weak, request analyze_summary_draft.\n\n"
        . sv_compact_blueprint_context($bundle);

    $model = getenv('CW_OPENAI_REALTIME_MODEL') ?: 'gpt-realtime';
    $body = [
        'model' => $model,
        'voice' => getenv('CW_OPENAI_REALTIME_VOICE') ?: 'alloy',
        'instructions' => $instructions,
        'metadata' => [
            'safe_user' => $safeUser,
            'voice_session_id' => (string)$voiceSessionId,
            'lesson_id' => (string)$lessonId,
            'blueprint_version_id' => (string)$bundle['version_id'],
        ],
        'input_audio_transcription' => [
            'model' => 'whisper-1',
        ],
        'tools' => [
            ['type' => 'function', 'name' => 'get_current_coaching_state', 'description' => 'Fetch current blueprint-bound coaching state.', 'parameters' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false]],
            ['type' => 'function', 'name' => 'analyze_summary_draft', 'description' => 'Analyze the current summary draft against blueprint must-have criteria without rewriting it.', 'parameters' => ['type' => 'object', 'properties' => ['section_id' => ['type' => 'string'], 'summary_excerpt' => ['type' => 'string']], 'required' => ['section_id', 'summary_excerpt'], 'additionalProperties' => false]],
            ['type' => 'function', 'name' => 'save_voice_transcript_event', 'description' => 'Persist a transcript or coaching marker.', 'parameters' => ['type' => 'object', 'properties' => ['role' => ['type' => 'string'], 'transcript_text' => ['type' => 'string'], 'event_type' => ['type' => 'string'], 'section_id' => ['type' => 'string']], 'required' => ['role', 'transcript_text'], 'additionalProperties' => false]],
            ['type' => 'function', 'name' => 'set_current_task', 'description' => 'Update the current writing task box.', 'parameters' => ['type' => 'object', 'properties' => ['mode' => ['type' => 'string'], 'task_text' => ['type' => 'string'], 'section_id' => ['type' => 'string']], 'required' => ['mode', 'task_text'], 'additionalProperties' => false]],
        ],
        'tool_choice' => 'auto',
    ];

    $ch = curl_init('https://api.openai.com/v1/realtime/sessions');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . cw_openai_key(),
        'Content-Type: application/json',
        'OpenAI-Beta: realtime=v1',
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false) throw new RuntimeException('OpenAI Realtime request failed: ' . $err);
    $json = json_decode((string)$resp, true);
    if (!is_array($json) || $code < 200 || $code >= 300) {
        $msg = is_array($json) ? (string)($json['error']['message'] ?? ('HTTP ' . $code)) : substr((string)$resp, 0, 200);
        throw new RuntimeException('OpenAI Realtime error: ' . $msg);
    }
    $secret = (string)($json['client_secret']['value'] ?? $json['client_secret'] ?? '');
    if ($secret === '') throw new RuntimeException('OpenAI Realtime response did not include a client secret.');
    sv_update_realtime_id($pdo, $voiceSessionId, (string)($json['id'] ?? ''));

    echo json_encode([
        'ok' => true,
        'client_secret' => $secret,
        'session_id' => (string)($json['id'] ?? ''),
        'voice_session_id' => $voiceSessionId,
        'blueprint_version_id' => (int)$bundle['version_id'],
        'realtime_model' => $model,
    ]);
} catch (Throwable $e) {
    sv_fail(500, $e->getMessage());
}
