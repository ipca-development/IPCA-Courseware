<?php
declare(strict_types=1);

/**
 * Documented grade mappings for E-gle historical evidence.
 *
 * Exercise grade UI (training_record_print.php):
 *   R -> DE column, Y -> EX, G -> PR, B -> PE, D/empty -> NO (deferred)
 *
 * Session grade:
 *   first char color R/Y/G/B, second char C=Complete / I=Incomplete
 *
 * SRM grade UI uses different column headers (EX/PR/MD/NO) while still storing R/Y/G/B.
 * Do NOT map SRM R/Y/G/B to DE/EX/PR/PE without separate documentation.
 */

function parse_required_level(string $nameRaw): array
{
    $rawMarker = null;
    $norm = null;
    $status = 'NONE';
    if (preg_match('/\((DE|EX|PR|PE)\)/i', $nameRaw, $m)) {
        $rawMarker = '(' . strtoupper($m[1]) . ')';
        $norm = strtoupper($m[1]);
        $status = 'OK';
    } elseif (preg_match('/\b(DE|EX|PR|PE)\b/i', $nameRaw, $m) && preg_match('/\(/', $nameRaw)) {
        // ambiguous parenthetical content
        $status = 'AMBIGUOUS';
    }
    // Keep markers in raw; normalized name strips only the trailing competency marker when unambiguous.
    $nameNorm = $nameRaw;
    if ($status === 'OK' && $rawMarker !== null) {
        $nameNorm = trim(preg_replace('/\s*\(' . preg_quote($norm, '/') . '\)\s*$/i', '', $nameRaw) ?? $nameRaw);
    }
    return [
        'required_level_raw' => $rawMarker,
        'required_level_normalized' => $norm,
        'parse_status' => $status,
        'name_normalized' => $nameNorm,
    ];
}

function exercise_grade_ordinal(?string $gradeRaw): ?int
{
    $g = strtoupper(trim((string)$gradeRaw));
    return match ($g) {
        'R' => 1, // DE
        'Y' => 2, // EX
        'G' => 3, // PR
        'B' => 4, // PE
        default => null, // D/blank/unknown = no ordinal
    };
}

function required_level_ordinal(?string $level): ?int
{
    $l = strtoupper(trim((string)$level));
    return match ($l) {
        'DE' => 1,
        'EX' => 2,
        'PR' => 3,
        'PE' => 4,
        default => null,
    };
}

function achieved_stage_from_exercise_grade(?string $gradeRaw): string
{
    $g = strtoupper(trim((string)$gradeRaw));
    return match ($g) {
        'R' => 'DE',
        'Y' => 'EX',
        'G' => 'PR',
        'B' => 'PE',
        'D', '' => 'DEFERRED',
        default => 'UNKNOWN',
    };
}

/**
 * Compare required DE/EX/PR/PE vs achieved R/Y/G/B using documented ordinals.
 */
function compare_required_achieved(?string $requiredNorm, ?string $achievedRaw): array
{
    $stage = achieved_stage_from_exercise_grade($achievedRaw);
    $reqOrd = required_level_ordinal($requiredNorm);
    $achOrd = exercise_grade_ordinal($achievedRaw);
    $deferred = ($stage === 'DEFERRED');
    if ($deferred || $reqOrd === null || $achOrd === null) {
        return [
            'achieved_stage' => $stage,
            'met' => null,
            'exceeded' => null,
            'not_met' => null,
            'deferred' => $deferred,
        ];
    }
    return [
        'achieved_stage' => $stage,
        'met' => $achOrd >= $reqOrd ? 1 : 0,
        'exceeded' => $achOrd > $reqOrd ? 1 : 0,
        'not_met' => $achOrd < $reqOrd ? 1 : 0,
        'deferred' => false,
    ];
}

function map_session_grading(string $raw): array
{
    $raw = strtoupper(trim($raw));
    if ($raw === '') {
        return ['raw' => '', 'color' => 'BLANK', 'completion' => 'BLANK', 'category' => 'BLANK'];
    }
    $color = $raw[0] ?? '';
    $completion = $raw[1] ?? '';
    $colorOk = in_array($color, ['R', 'Y', 'G', 'B'], true);
    $compOk = in_array($completion, ['C', 'I'], true);
    $category = ($colorOk && $compOk) ? ($color . $completion) : 'UNKNOWN';
    return [
        'raw' => $raw,
        'color' => $colorOk ? $color : 'UNKNOWN',
        'completion' => $compOk ? $completion : 'UNKNOWN',
        'category' => $category,
    ];
}

/**
 * Preserve source type exactly elsewhere; normalized mapping only where confirmed by UI.
 * scenarios_admin.php: SAB = "Simulator Briefing (SAB)"; LB = "Long Briefing (LB)"
 * User clarification: SAB may be treated operationally as full simulator scenario-based session.
 * We still keep source raw and only lightly normalize confirmed labels.
 */
function normalize_session_type(string $raw): string
{
    $t = strtoupper(trim($raw));
    return match ($t) {
        'FLIGHT' => 'FLIGHT',
        'FNPT' => 'SIMULATOR_FNPT',
        'SAB' => 'SIMULATOR_BRIEFING_SAB', // confirmed label from admin UI; operational nuance retained in docs
        'LB' => 'LONG_BRIEFING',
        '' => 'BLANK',
        default => 'UNKNOWN',
    };
}
