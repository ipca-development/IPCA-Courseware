<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/AviationEvidence/Pass4aSpeechQualityService.php';

$text = str_repeat('A', 118) . '—' . 'end';
$result = (new Pass4aSpeechQualityService())->analyze(array(array(
    'id' => 1,
    'provider_segment_index' => 0,
    'provider_segment_text' => $text,
    'no_speech_probability' => 0.8,
    'avg_log_probability' => -0.2,
    'compression_ratio' => 1.0,
)));
$preview = (string)($result['findings'][0]['text_preview'] ?? '');

$sources = array(
    file_get_contents(__DIR__ . '/../src/AviationEvidence/Pass4aSpeechQualityService.php') ?: '',
    file_get_contents(__DIR__ . '/../src/AviationEvidence/Pass4bRepetitionDetectorService.php') ?: '',
    file_get_contents(__DIR__ . '/../src/AviationEvidence/EvidencePass4Runner.php') ?: '',
    file_get_contents(__DIR__ . '/../src/AviationEvidence/InterpretationRevisionRepository.php') ?: '',
    file_get_contents(__DIR__ . '/../src/AviationEvidence/SuppressionRepository.php') ?: '',
    file_get_contents(__DIR__ . '/../src/AviationEvidence/ChapterRepository.php') ?: '',
    file_get_contents(__DIR__ . '/../src/AviationEvidence/ProcessingRunRepository.php') ?: '',
);

$passed = mb_check_encoding($preview, 'UTF-8')
    && str_contains($preview, '—')
    && array_reduce(
        $sources,
        static fn(bool $ok, string $source): bool => $ok && str_contains($source, 'mb_substr('),
        true
    );

if (!$passed) {
    fwrite(STDERR, "cvr_evidence_utf8_contract_check FAILED\n");
    exit(1);
}

fwrite(STDOUT, "cvr_evidence_utf8_contract_check OK\n");
