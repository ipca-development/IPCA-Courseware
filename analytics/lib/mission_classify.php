<?php
declare(strict_types=1);

/**
 * Propose mission event classifications with confidence + evidence.
 * Ambiguous cases stay UNKNOWN / low confidence — never silently forced.
 */
function classify_mission(array $scenario): array
{
    $name = (string)($scenario['sc_name'] ?? '');
    $code = (string)($scenario['sc_code'] ?? '');
    $type = strtoupper(trim((string)($scenario['sc_type'] ?? '')));
    $solo = strtoupper(trim((string)($scenario['easa_solo'] ?? '')));
    $hay = strtoupper($name . ' ' . $code);

    $evidence = [];
    if ($solo !== '') {
        $evidence[] = "easa_solo={$solo}";
    }
    if ($type !== '') {
        $evidence[] = "sc_type={$type}";
    }
    $evidence[] = "sc_name={$name}";
    $evidence[] = "sc_code={$code}";

    // Code-backed solo forms
    if ($solo === 'FIRST') {
        return [
            'classification' => 'SOLO',
            'confidence' => 'HIGH',
            'classification_reason' => 'scenarios.easa_solo=FIRST linked to easa_first_solo.php authorization form',
            'source_evidence' => implode(' | ', $evidence),
        ];
    }
    if ($solo === 'XC') {
        return [
            'classification' => 'SOLO CROSS-COUNTRY',
            'confidence' => 'HIGH',
            'classification_reason' => 'scenarios.easa_solo=XC linked to easa_xc_solo.php authorization form',
            'source_evidence' => implode(' | ', $evidence),
        ];
    }

    $rules = [
        ['SKILL TEST / CHECKRIDE', 'HIGH', '/\b(SKILL\s*TEST|CHECK\s*RIDE|CHECKRIDE|PRACTICAL\s*TEST)\b/'],
        ['FINAL PROGRESS CHECK', 'MEDIUM', '/\b(FINAL\s+PROGRESS\s+CHECK|FINAL\s+CHECK)\b/'],
        ['STAGE CHECK', 'MEDIUM', '/\b(STAGE\s*CHECK|PHASE\s*CHECK)\b/'],
        ['PROGRESS CHECK', 'MEDIUM', '/\b(PROGRESS\s*CHECK|PROGRESS\s*TEST)\b/'],
        ['PRE-SOLO CHECK', 'MEDIUM', '/\b(PRE[-\s]?SOLO)\b/'],
        ['SOLO CROSS-COUNTRY', 'MEDIUM', '/\b(SOLO\s+CROSS[-\s]?COUNTRY|SOLO\s+XC|XC\s+SOLO)\b/'],
        ['SOLO', 'MEDIUM', '/\b(FIRST\s+SOLO|SOLO\s+FLIGHT|\bSOLO\b)/'],
        ['SIMULATOR CHECK', 'MEDIUM', '/\b(SIM(ULATOR)?\s+CHECK|FNPT\s+CHECK)\b/'],
    ];

    foreach ($rules as [$class, $conf, $rx]) {
        if (preg_match($rx, $hay)) {
            // Reduce confidence if name also looks like ordinary training
            if ($class === 'SOLO' && preg_match('/\b(BRIEF|INTRO|DEMO)\b/', $hay)) {
                return [
                    'classification' => 'UNKNOWN',
                    'confidence' => 'LOW',
                    'classification_reason' => 'Name contains solo-related token but also briefing/intro language; left unclassified',
                    'source_evidence' => implode(' | ', $evidence),
                ];
            }
            return [
                'classification' => $class,
                'confidence' => $conf,
                'classification_reason' => "Name/code matched pattern {$rx}",
                'source_evidence' => implode(' | ', $evidence),
            ];
        }
    }

    if (in_array($type, ['FLIGHT', 'FNPT', 'LB', 'SAB'], true)) {
        return [
            'classification' => 'NORMAL TRAINING',
            'confidence' => 'MEDIUM',
            'classification_reason' => 'No check/solo naming signals; standard sc_type training session',
            'source_evidence' => implode(' | ', $evidence),
        ];
    }

    return [
        'classification' => 'UNKNOWN',
        'confidence' => 'LOW',
        'classification_reason' => 'Insufficient evidence for automatic classification',
        'source_evidence' => implode(' | ', $evidence),
    ];
}
