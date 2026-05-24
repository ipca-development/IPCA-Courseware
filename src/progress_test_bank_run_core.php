<?php
declare(strict_types=1);

require_once __DIR__ . '/progress_test_bank.php';

/**
 * Build or refresh a lesson progress test question bank.
 *
 * @param callable(string,array):void $emit
 */
function pt_bank_run_build(PDO $pdo, int $lessonId, bool $forceRebuild, callable $emit): array
{
    pt_bank_ensure_tables($pdo);
    $bank = pt_bank_get_or_create_bank($pdo, $lessonId);
    $bankId = (int)$bank['id'];
    $recommended = (int)$bank['recommended_pool_size'];
    $stats = $bank['stats'] ?? pt_bank_lesson_content_stats($pdo, $lessonId);
    $truth = (string)$stats['truth_text'];

    $emit('lesson_start', [
        'lesson_id' => $lessonId,
        'recommended_pool_size' => $recommended,
        'word_count' => (int)$stats['word_count'],
        'slide_count' => (int)$stats['slide_count'],
    ]);

    if ($forceRebuild) {
        $pdo->prepare("UPDATE progress_test_bank_questions SET status = 'retired', retired_at = NOW(), retired_reason = 'force_rebuild', updated_at = NOW() WHERE bank_id = ? AND status = 'active'")
            ->execute([$bankId]);
        $emit('step', ['message' => 'Retired existing active questions (force rebuild).']);
    }

    $active = pt_bank_count_active($pdo, $bankId);
    $need = max(0, min(PT_BANK_MAX_POOL, $recommended) - $active);

    if ($need <= 0) {
        $emit('step', ['message' => "Bank already has {$active} active questions (target {$recommended})."]);
        $pdo->prepare("UPDATE progress_test_lesson_banks SET status = 'ready', updated_at = NOW() WHERE id = ?")
            ->execute([$bankId]);
        $emit('lesson_done', ['lesson_id' => $lessonId, 'added' => 0, 'active_count' => $active]);
        return ['added' => 0, 'active_count' => $active];
    }

    $pdo->prepare("UPDATE progress_test_lesson_banks SET status = 'building', updated_at = NOW() WHERE id = ?")
        ->execute([$bankId]);

    $emit('step', ['message' => "Generating {$need} questions via AI…"]);
    $generated = ptq_generate_oral_questions($pdo, $truth, '(Admin bank build — no student summary.)', $need);

    if (count($generated) >= 3) {
        $emit('step', ['message' => 'Validating question quality…']);
        $validated = ptq_validate_and_rewrite_questions($pdo, $truth, $generated, $need);
        if (count($validated) >= count($generated) * 0.5) {
            $generated = $validated;
        }
    }

    $existingHashes = [];
    $hashSt = $pdo->prepare('SELECT prompt_hash FROM progress_test_bank_questions WHERE bank_id = ?');
    $hashSt->execute([$bankId]);
    foreach ($hashSt->fetchAll(PDO::FETCH_COLUMN) as $h) {
        $existingHashes[(string)$h] = true;
    }

    $added = 0;
    foreach ($generated as $q) {
        if ($added >= $need) break;
        if ($active + $added >= PT_BANK_MAX_POOL) break;

        $hash = pt_bank_prompt_hash((string)$q['kind'], (string)$q['prompt']);
        if (isset($existingHashes[$hash])) continue;

        $validation = ptq_score_validation($q);
        if ((int)$validation['validation_score'] < PT_BANK_BAD_VALIDATION) {
            $emit('step', ['message' => 'Skipped low-quality candidate (score ' . (int)$validation['validation_score'] . ').']);
            continue;
        }

        $qid = pt_bank_insert_question($pdo, $bankId, $lessonId, $q);
        $existingHashes[$hash] = true;
        $emit('step', ['message' => 'Generating audio for question #' . $qid . '…']);

        try {
            pt_bank_generate_audio_for_question($pdo, $lessonId, $qid, $q);
        } catch (Throwable $e) {
            $emit('step', ['message' => 'Audio failed for #' . $qid . ': ' . $e->getMessage()]);
        }

        $added++;
        $emit('question_added', ['question_id' => $qid, 'kind' => $q['kind']]);
    }

    $activeAfter = pt_bank_count_active($pdo, $bankId);
    $status = ($activeAfter >= $recommended && $activeAfter >= PT_BANK_MIN_POOL) ? 'ready' : 'stale';
    $pdo->prepare("UPDATE progress_test_lesson_banks SET status = ?, content_fingerprint = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$status, pt_bank_content_fingerprint($truth), $bankId]);

    try {
        pt_bank_ensure_generic_intro($pdo);
    } catch (Throwable $e) {
        $emit('step', ['message' => 'Generic intro cache: ' . $e->getMessage()]);
    }

    $emit('lesson_done', [
        'lesson_id' => $lessonId,
        'added' => $added,
        'active_count' => $activeAfter,
        'status' => $status,
    ]);

    return ['added' => $added, 'active_count' => $activeAfter, 'status' => $status];
}

function pt_bank_resolve_lesson_batch(PDO $pdo, array $post): array
{
    $programId = (int)($post['program_id'] ?? 0);
    $courseId = (int)($post['course_id'] ?? 0);
    $lessonId = (int)($post['lesson_id'] ?? 0);
    $offset = max(0, (int)($post['batch_offset'] ?? 0));
    $size = max(1, min(20, (int)($post['batch_size'] ?? 5)));

    $all = pt_bank_lessons_coverage($pdo, $programId, $courseId, $lessonId);
    $total = count($all);
    $batch = array_slice($all, $offset, $size);

    return [$batch, null, $total];
}
