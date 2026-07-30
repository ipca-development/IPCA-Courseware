<?php
declare(strict_types=1);

require_once __DIR__ . '/EvidenceSchema.php';
require_once __DIR__ . '/ProviderRunRepository.php';
require_once __DIR__ . '/SpeechSegmentRepository.php';
require_once __DIR__ . '/InterpretationRevisionRepository.php';
require_once __DIR__ . '/SuppressionRepository.php';
require_once __DIR__ . '/ProcessingRunRepository.php';
require_once __DIR__ . '/Pass4aSpeechQualityService.php';
require_once __DIR__ . '/Pass4bRepetitionDetectorService.php';

final class EvidencePass4Runner
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ProcessingRunRepository $processingRuns,
        private readonly ProviderRunRepository $providerRuns,
        private readonly SpeechSegmentRepository $speechSegments,
        private readonly InterpretationRevisionRepository $interpretations,
        private readonly SuppressionRepository $suppressions,
        private readonly Pass4aSpeechQualityService $pass4a,
        private readonly Pass4bRepetitionDetectorService $pass4b,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function runForProcessingRun(int $processingRunId, bool $force = false): array
    {
        if (!EvidenceSchema::pass4Ready($this->pdo)) {
            throw new RuntimeException('Pass 4 tables not ready. Apply Phase 1 migration first.');
        }

        $processingRun = $this->processingRuns->findById($processingRunId);
        if ($processingRun === null) {
            throw new RuntimeException('Processing run not found: ' . $processingRunId);
        }

        if (!$force && $this->interpretations->hasRevisionForRun($processingRunId, EvidenceSchema::LAYER_PASS4B)) {
            return array(
                'ok' => true,
                'skipped' => true,
                'reason' => 'Pass 4B already executed for this processing run',
                'processing_run_id' => $processingRunId,
            );
        }

        $recordingId = (int)$processingRun['recording_id'];
        $whisperRuns = $this->providerRuns->listCanonicalWhisperRunsForProcessingRun($processingRunId);
        if ($whisperRuns === array()) {
            throw new RuntimeException('No canonical whisper provider run for processing run ' . $processingRunId);
        }

        $secondaryText = $this->providerRuns->mergedProductionTextForProcessingRun($processingRunId);
        $language = null;
        foreach ($this->providerRuns->listProductionJsonRunsForProcessingRun($processingRunId) as $productionRun) {
            if (isset($productionRun['language_code']) && is_string($productionRun['language_code']) && $productionRun['language_code'] !== '') {
                $language = $productionRun['language_code'];
                break;
            }
        }

        if (count($whisperRuns) === 1) {
            $providerSegments = $this->providerRuns->listSegments((int)$whisperRuns[0]['id']);
            if ($providerSegments === array()) {
                throw new RuntimeException('Canonical provider run has no segments.');
            }
        }

        $speechSegmentRows = $this->speechSegments->materializeFromWhisperRuns(
            $recordingId,
            $processingRunId,
            $whisperRuns,
            is_string($language) ? $language : null
        );
        if ($speechSegmentRows === array()) {
            throw new RuntimeException('Whisper provider runs have no materialized speech segments.');
        }
        $canonicalRunId = (int)($whisperRuns[0]['id'] ?? 0);

        $pass4a = $this->pass4a->analyze($speechSegmentRows);
        $primaryText = trim(implode(' ', array_map(
            static fn(array $s): string => trim((string)($s['provider_segment_text'] ?? '')),
            $speechSegmentRows
        )));
        $pass4b = $this->pass4b->analyze($primaryText, $secondaryText !== null && $secondaryText !== '' ? $secondaryText : null, $speechSegmentRows);

        $interpretationIds = array();
        $suppressionIds = array();

        foreach ($pass4a['findings'] as $finding) {
            $speechSegmentId = (int)($finding['speech_segment_id'] ?? 0);
            if ($speechSegmentId <= 0) {
                continue;
            }
            $rev = $this->interpretations->createRevision(
                $speechSegmentId,
                EvidenceSchema::LAYER_PASS4A,
                (string)($finding['text_preview'] ?? ''),
                array(
                    'pass' => '4A',
                    'version' => EvidenceSchema::PASS4A_VERSION,
                    'signals' => $finding['signals'] ?? array(),
                    'metrics' => $finding['metrics'] ?? array(),
                ),
                (float)($finding['confidence'] ?? 0.5),
                EvidenceSchema::PASS4A_VERSION,
                array(array(
                    'factor_type' => 'support',
                    'source_type' => 'pass_4a_speech_quality',
                    'source_id' => $speechSegmentId,
                    'weight' => (float)($finding['confidence'] ?? 0.5),
                    'description' => implode(', ', $finding['signals'] ?? array()),
                ))
            );
            $interpretationIds[] = (int)($rev['id'] ?? 0);

            if (($finding['confidence'] ?? 0) >= 0.65) {
                $sup = $this->suppressions->create(
                    $processingRunId,
                    'low_speech_quality',
                    'Pass 4A: ' . implode(', ', $finding['signals'] ?? array()),
                    $speechSegmentId,
                    (int)($rev['id'] ?? null),
                    (string)($finding['text_preview'] ?? null)
                );
                $suppressionIds[] = (int)($sup['id'] ?? 0);
            }
        }

        $firstSpeechSegmentId = (int)($speechSegmentRows[0]['id'] ?? 0);
        if ($firstSpeechSegmentId > 0) {
            $pass4bRev = $this->interpretations->createRevision(
                $firstSpeechSegmentId,
                EvidenceSchema::LAYER_PASS4B,
                json_encode($pass4b['findings'], JSON_UNESCAPED_SLASHES) ?: '[]',
                array(
                    'pass' => '4B',
                    'version' => EvidenceSchema::PASS4B_VERSION,
                    'chunk_summary' => $pass4b['chunk_summary'] ?? array(),
                    'finding_count' => count($pass4b['findings']),
                ),
                count($pass4b['findings']) > 0 ? 0.75 : 0.2,
                EvidenceSchema::PASS4B_VERSION
            );
            $interpretationIds[] = (int)($pass4bRev['id'] ?? 0);
        }

        $suppressedSegmentIds = array();
        foreach ($pass4b['findings'] as $finding) {
            if (($finding['confidence'] ?? 0) < 0.55) {
                continue;
            }
            if (($finding['detection_type'] ?? '') === 'secondary_hypothesis_repetition') {
                continue;
            }
            foreach ($finding['speech_segment_ids'] ?? array() as $speechSegmentId) {
                $speechSegmentId = (int)$speechSegmentId;
                if ($speechSegmentId <= 0 || !in_array($speechSegmentId, $pass4b['suppress_segment_ids'] ?? array(), true)) {
                    continue;
                }
                if (isset($suppressedSegmentIds[$speechSegmentId])) {
                    continue;
                }
                $suppressedSegmentIds[$speechSegmentId] = true;
                $segmentText = null;
                foreach ($speechSegmentRows as $row) {
                    if ((int)($row['id'] ?? 0) === $speechSegmentId) {
                        $segmentText = (string)($row['provider_segment_text'] ?? '');
                        break;
                    }
                }
                $sup = $this->suppressions->create(
                    $processingRunId,
                    'repetition_loop',
                    'Pass 4B: ' . (string)($finding['detection_type'] ?? 'repetition'),
                    $speechSegmentId,
                    isset($pass4bRev) ? (int)($pass4bRev['id'] ?? null) : null,
                    $segmentText
                );
                $suppressionIds[] = (int)($sup['id'] ?? 0);
            }
        }

        if ($firstSpeechSegmentId > 0) {
            $readableRev = $this->interpretations->createRevision(
                $firstSpeechSegmentId,
                EvidenceSchema::LAYER_READABLE,
                (string)($pass4b['readable_text'] ?? $primaryText),
                array(
                    'pass' => 'readable',
                    'suppressed_segment_ids' => $pass4b['suppress_segment_ids'] ?? array(),
                    'source' => 'whisper_canonical_timeline',
                    'secondary_hypothesis_available' => $secondaryText !== null && $secondaryText !== '',
                ),
                0.85,
                EvidenceSchema::PASS4B_VERSION
            );
            $interpretationIds[] = (int)($readableRev['id'] ?? 0);
        }

        $this->updateProcessingRunVersions($processingRunId);

        return array(
            'ok' => true,
            'skipped' => false,
            'processing_run_id' => $processingRunId,
            'recording_id' => $recordingId,
            'canonical_provider_run_id' => $canonicalRunId,
            'whisper_provider_run_count' => count($whisperRuns),
            'secondary_text_length' => $secondaryText !== null ? strlen($secondaryText) : 0,
            'speech_segment_count' => count($speechSegmentRows),
            'pass_4a' => $pass4a,
            'pass_4b' => array(
                'finding_count' => count($pass4b['findings']),
                'chunk_summary' => $pass4b['chunk_summary'],
                'findings' => $pass4b['findings'],
                'readable_text_preview' => substr((string)($pass4b['readable_text'] ?? ''), 0, 400),
            ),
            'interpretation_revision_ids' => array_values(array_filter($interpretationIds)),
            'suppression_ids' => array_values(array_filter($suppressionIds)),
            'suppression_count' => count(array_filter($suppressionIds)),
        );
    }

    private function updateProcessingRunVersions(int $processingRunId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . EvidenceSchema::TABLE_PROCESSING_RUNS
            . ' SET speech_quality_version = ?, semantic_validation_version = ? WHERE id = ?'
        );
        $stmt->execute(array(EvidenceSchema::PASS4A_VERSION, EvidenceSchema::PASS4B_VERSION, $processingRunId));
    }

    public static function fromPdo(PDO $pdo): self
    {
        return new self(
            $pdo,
            new ProcessingRunRepository($pdo),
            new ProviderRunRepository($pdo),
            new SpeechSegmentRepository($pdo),
            new InterpretationRevisionRepository($pdo),
            new SuppressionRepository($pdo),
            new Pass4aSpeechQualityService(),
            new Pass4bRepetitionDetectorService(),
        );
    }
}
