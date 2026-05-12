<?php
declare(strict_types=1);

require_once __DIR__ . '/../openai.php';
require_once __DIR__ . '/ComplianceAiRunLogger.php';
require_once __DIR__ . '/ComplianceCaseEvents.php';
require_once __DIR__ . '/ComplianceFindingEngine.php';

/**
 * Root-cause analysis (5-Whys). Corrective actions live in ComplianceCapEngine.
 */
final class ComplianceRcaCapEngine
{
    /**
     * @return array<string,mixed>|null
     */
    public static function getRcaForFinding(PDO $pdo, int $findingId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM ipca_compliance_finding_rca WHERE finding_id = ? LIMIT 1'
        );
        $stmt->execute(array($findingId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public static function assertRcaNotLocked(?array $rcaRow): void
    {
        if ($rcaRow !== null && !empty($rcaRow['locked_at'])) {
            throw new RuntimeException('RCA is locked and cannot be modified.');
        }
    }

    /**
     * @param list<array<string,mixed>> $steps Each: whyNumber, question, answer
     * @param array<string,mixed> $findingRow
     * @param bool $preserveRootCauseOnUpdate When true and an RCA row already exists,
     *        root_cause_text is left unchanged in the database (AI next-step uses this).
     */
    public static function saveRcaDraft(
        PDO $pdo,
        int $findingId,
        array $findingRow,
        array $steps,
        ?string $rootCause,
        int $userId,
        bool $aiAssisted = false,
        ?int $aiRunId = null,
        bool $preserveRootCauseOnUpdate = false
    ): void {
        ComplianceFindingEngine::assertNotLocked($findingRow);

        $existing = self::getRcaForFinding($pdo, $findingId);
        self::assertRcaNotLocked($existing);

        $cleanSteps = self::normaliseSteps($steps);
        $stepsJson = json_encode($cleanSteps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($stepsJson === false) {
            throw new RuntimeException('Failed to encode RCA steps.');
        }

        $rootTrim = $rootCause !== null ? trim($rootCause) : '';
        $finalRoot = $rootTrim !== '' ? $rootTrim : null;
        if ($existing !== null && $preserveRootCauseOnUpdate) {
            $prev = isset($existing['root_cause_text']) ? trim((string)$existing['root_cause_text']) : '';
            $finalRoot = $prev !== '' ? $prev : null;
        }

        $caseId = isset($findingRow['case_id']) ? (int)$findingRow['case_id'] : null;
        if ($caseId !== null && $caseId <= 0) {
            $caseId = null;
        }

        if ($existing === null) {
            $stmt = $pdo->prepare(
                'INSERT INTO ipca_compliance_finding_rca (
                    finding_id, method, steps_json, root_cause_text,
                    ai_assisted, ai_run_id, created_by, updated_by
                ) VALUES (
                    ?, \'FIVE_WHYS\', ?, ?,
                    ?, ?, ?, ?
                )'
            );
            $stmt->execute(array(
                $findingId,
                $stepsJson,
                $finalRoot,
                $aiAssisted ? 1 : 0,
                $aiRunId,
                $userId,
                $userId,
            ));

            compliance_log_case_event(
                $pdo,
                $caseId,
                'rca',
                $findingId,
                'rca_started',
                $userId,
                'RCA draft started',
                null,
                array('step_count' => count($cleanSteps)),
                null
            );
        } else {
            $stmt = $pdo->prepare(
                'UPDATE ipca_compliance_finding_rca SET
                    steps_json = ?,
                    root_cause_text = ?,                    ai_assisted = ?,
                    ai_run_id = COALESCE(?, ai_run_id),
                    updated_by = ?,
                    updated_at = NOW()
                 WHERE finding_id = ? LIMIT 1'
            );
            $stmt->execute(array(
                $stepsJson,
                $finalRoot,
                $aiAssisted ? 1 : 0,
                $aiRunId,
                $userId,
                $findingId,
            ));

            compliance_log_case_event(
                $pdo,
                $caseId,
                'rca',
                $findingId,
                'updated',
                $userId,
                'RCA draft saved',
                null,
                array('step_count' => count($cleanSteps)),
                null
            );
        }
    }

    /**
     * Lock RCA — human approval fields required for regulatory record.
     */
    public static function lockRca(
        PDO $pdo,
        int $findingId,
        array $findingRow,
        int $userId,
        string $approverDisplayName,
        ?string $lockReason = null
    ): void {
        ComplianceFindingEngine::assertNotLocked($findingRow);

        $rca = self::getRcaForFinding($pdo, $findingId);
        if ($rca === null) {
            throw new RuntimeException('Cannot lock RCA — no RCA row exists yet.');
        }
        self::assertRcaNotLocked($rca);

        $name = trim($approverDisplayName);
        if ($name === '') {
            throw new RuntimeException('Approver name is required to lock RCA.');
        }

        $caseId = isset($findingRow['case_id']) ? (int)$findingRow['case_id'] : null;
        if ($caseId !== null && $caseId <= 0) {
            $caseId = null;
        }

        $reason = $lockReason !== null ? trim($lockReason) : '';
        $reason = $reason !== '' ? $reason : null;

        $stmt = $pdo->prepare(
            'UPDATE ipca_compliance_finding_rca SET
                approved_by = ?,
                approved_by_name = ?,
                approved_at = NOW(),
                locked_at = NOW(),
                locked_by = ?,
                lock_reason = ?,
                updated_by = ?,
                updated_at = NOW()
             WHERE finding_id = ? AND locked_at IS NULL
             LIMIT 1'
        );
        $stmt->execute(array(
            $userId,
            $name,
            $userId,
            $reason,
            $userId,
            $findingId,
        ));

        if ($stmt->rowCount() < 1) {
            throw new RuntimeException('RCA lock failed (already locked?).');
        }

        compliance_log_case_event(
            $pdo,
            $caseId,
            'rca',
            $findingId,
            'rca_locked',
            $userId,
            'RCA locked by ' . $name,
            null,
            array('approved_by_name' => $name),
            null
        );
    }

    /**
     * AI: append the next 5-Whys step (legacy RcaAiService::generateNextStep).
     * Persists immediately to the RCA row so the user can edit on save.
     *
     * @return array{whyNumber:int,question:string,answer:string}
     */
    public static function suggestNextWhyStep(
        PDO $pdo,
        int $findingId,
        array $findingRow,
        int $userId
    ): array {
        ComplianceFindingEngine::assertNotLocked($findingRow);

        $rca = self::getRcaForFinding($pdo, $findingId);
        self::assertRcaNotLocked($rca);

        $steps = array();
        if ($rca !== null) {
            $raw = $rca['steps_json'] ?? '[]';
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                $steps = is_array($decoded) ? self::normaliseSteps($decoded) : array();
            }
        }

        $nextWhy = count($steps) + 1;
        if ($nextWhy > 5) {
            throw new RuntimeException('The 5-Whys chain is already complete (maximum five steps).');
        }

        $findingContext = ComplianceFindingEngine::buildFindingContextArray($findingRow);
        $findingText = self::buildFindingContext($findingContext);

        $prev = '';
        foreach ($steps as $s) {
            $n = (int)($s['whyNumber'] ?? 0);
            if ($n < 1 || $n > 5) {
                continue;
            }
            $q = trim((string)($s['question'] ?? ''));
            $a = trim((string)($s['answer'] ?? ''));
            $prev .= "WHY {$n} QUESTION: {$q}\nWHY {$n} ANSWER (final): {$a}\n\n";
        }

        $prompt = <<<TXT
You are an aviation compliance expert writing a regulatory-grade Root Cause Analysis using the 5 Whys method.
You must produce the NEXT why step only (Why {$nextWhy}).

Rules:
- Do NOT blame individuals; focus on system/process/training/documentation/oversight.
- Build logically on the previous final answers (these may have been edited by the Compliance Manager).
- Use concise professional language suitable for an authority (CAA/BCAA/FAA).
- Output MUST be valid JSON ONLY with keys: whyNumber, question, answer.
- question should start with "Why ...?"
- answer should start with "Because ..."

FINDING CONTEXT:
{$findingText}

PREVIOUS WHY STEPS (final approved answers):
{$prev}

Now produce Why {$nextWhy}.
TXT;

        $t0 = (int)round(microtime(true) * 1000);
        $model = cw_openai_model();

        try {
            $raw = cw_openai_prompt_plaintext($prompt, null, 120);
            $t1 = (int)round(microtime(true) * 1000);
            $latency = max(0, $t1 - $t0);

            $decoded = self::decodeJsonSafely($raw);
            if (!is_array($decoded)) {
                $step = array(
                    'whyNumber' => $nextWhy,
                    'question' => 'Why (auto) — please refine',
                    'answer' => $raw,
                );
            } else {
                $decoded['whyNumber'] = $nextWhy;
                $decoded['question'] = (string)($decoded['question'] ?? '');
                $decoded['answer'] = (string)($decoded['answer'] ?? '');
                $step = $decoded;
            }

            $steps[] = $step;

            $runId = ComplianceAiRunLogger::insert(
                $pdo,
                'finding',
                $findingId,
                'RCA_SUGGEST',
                'OK',
                $model,
                $prompt,
                array(
                    'finding_id' => $findingId,
                    'prior_step_count' => count($steps) - 1,
                    'next_why' => $nextWhy,
                ),
                $step,
                $raw,
                $latency,
                null,
                $userId
            );

            self::saveRcaDraft(
                $pdo,
                $findingId,
                $findingRow,
                $steps,
                null,
                $userId,
                true,
                $runId,
                true
            );

            return $step;
        } catch (Throwable $e) {
            $t1 = (int)round(microtime(true) * 1000);
            $latency = max(0, $t1 - $t0);

            ComplianceAiRunLogger::insert(
                $pdo,
                'finding',
                $findingId,
                'RCA_SUGGEST',
                'ERROR',
                $model,
                $prompt,
                array('finding_id' => $findingId),
                null,
                null,
                $latency,
                $e->getMessage(),
                $userId
            );

            throw $e;
        }
    }

    /**
     * @param list<array<string,mixed>> $steps
     * @return list<array{whyNumber:int,question:string,answer:string}>
     */
    public static function normaliseSteps(array $steps): array
    {
        $out = array();
        foreach ($steps as $s) {
            if (!is_array($s)) {
                continue;
            }
            $n = (int)($s['whyNumber'] ?? 0);
            if ($n < 1 || $n > 5) {
                continue;
            }
            $out[] = array(
                'whyNumber' => $n,
                'question' => trim((string)($s['question'] ?? '')),
                'answer' => trim((string)($s['answer'] ?? '')),
            );
        }
        usort($out, static function (array $a, array $b): int {
            return ($a['whyNumber'] <=> $b['whyNumber']);
        });
        return $out;
    }

    /**
     * @param array<string,mixed> $f
     */
    private static function buildFindingContext(array $f): string
    {
        $parts = array();
        $parts[] = 'Reference: ' . ($f['reference'] ?? '');
        $parts[] = 'Title: ' . ($f['title'] ?? '');
        $parts[] = 'Classification: ' . ($f['classification'] ?? '');
        $parts[] = 'Severity: ' . ($f['severity'] ?? '');
        $parts[] = 'Regulation ref: ' . ($f['regulation_ref'] ?? '');
        $parts[] = "Description:\n" . ($f['description'] ?? '');

        if (!empty($f['manual_refs']) && is_array($f['manual_refs'])) {
            $parts[] = "Linked manual references:\n" . implode("\n", $f['manual_refs']);
        }

        return implode("\n", $parts);
    }

    private static function decodeJsonSafely(string $raw): mixed
    {
        $text = trim($raw);

        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?/i', '', $text) ?? $text;
            $text = preg_replace('/```$/', '', $text) ?? $text;
            $text = trim($text);
        }

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $maybe = substr($text, $start, $end - $start + 1);
            $decoded2 = json_decode($maybe, true);
            if (is_array($decoded2)) {
                return $decoded2;
            }
        }

        return null;
    }
}
