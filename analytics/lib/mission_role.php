<?php
declare(strict_types=1);

/**
 * Analytical mission role classification.
 * Intentional repetition must not be treated as curriculum failure.
 */
function classify_mission_role(array $m, array $ctx = []): array
{
    $name = (string)($m['mission_name'] ?? '');
    $code = (string)($m['mission_code'] ?? '');
    $type = strtoupper(trim((string)($m['source_session_type'] ?? '')));
    $event = (string)($m['event_class'] ?? '');
    $solo = strtoupper(trim((string)($m['easa_solo_raw'] ?? '')));
    $hay = strtoupper($name . ' ' . $code);

    $avgAttempts = (float)($ctx['avg_attempts'] ?? 0);
    $students = (int)($ctx['student_count'] ?? 0);
    $extraPerStudent = (float)($ctx['extra_per_student'] ?? 0);
    $shareIncomplete = (float)($ctx['share_incomplete'] ?? 0);

    // Explicit check / solo events from prior classification
    if (in_array($event, ['SKILL TEST / CHECKRIDE', 'FINAL PROGRESS CHECK', 'STAGE CHECK', 'PROGRESS CHECK', 'PRE-SOLO CHECK', 'SIMULATOR CHECK'], true)) {
        return [
            'mission_role' => 'CHECK_EVENT',
            'mission_role_confidence' => $event === 'SKILL TEST / CHECKRIDE' ? 'HIGH' : 'MEDIUM',
            'mission_role_reason' => "Prior event_class={$event}",
        ];
    }
    if ($event === 'SOLO' || $solo === 'FIRST') {
        return [
            'mission_role' => 'SOLO_EVENT',
            'mission_role_confidence' => $solo === 'FIRST' ? 'HIGH' : 'MEDIUM',
            'mission_role_reason' => 'Solo event classification / easa_solo=FIRST',
        ];
    }
    if ($event === 'SOLO CROSS-COUNTRY' || $solo === 'XC') {
        return [
            'mission_role' => 'SOLO_EVENT',
            'mission_role_confidence' => $solo === 'XC' ? 'HIGH' : 'MEDIUM',
            'mission_role_reason' => 'Solo XC classification / easa_solo=XC',
        ];
    }

    // Accumulation / intentional hour-building blocks
    if (preg_match('/\b(EXTRA\s+MISSION|AS REQUIRED TO OBTAIN|PIC\s*TIME|TT\s*OF|TIME\s+BUILD|HOUR\s+BUILD|EXPERIENCE\s+BUILD)\b/i', $hay)) {
        return [
            'mission_role' => 'ACCUMULATION_MISSION',
            'mission_role_confidence' => 'HIGH',
            'mission_role_reason' => 'Name indicates intentional hour/PIC accumulation rather than single-progression lesson',
        ];
    }

    // Briefing / ground
    if ($type === 'LB' || preg_match('/\b(LONG\s+BRIEF|BRIEFING\s+PRACTICUM|GROUND\s+BRIEF|THEORY)\b/i', $hay)) {
        return [
            'mission_role' => 'BRIEFING_OR_GROUND_EVENT',
            'mission_role_confidence' => 'HIGH',
            'mission_role_reason' => 'Session type LB or briefing/ground naming',
        ];
    }
    if ($type === 'SAB' && preg_match('/\b(BRIEF|FAMILIARISATION|FAMILIARIZATION|INTRO)\b/i', $hay)) {
        return [
            'mission_role' => 'BRIEFING_OR_GROUND_EVENT',
            'mission_role_confidence' => 'MEDIUM',
            'mission_role_reason' => 'SAB with briefing/familiarisation naming',
        ];
    }

    // Optional / remedial wording
    if (preg_match('/\b(OPTIONAL|REMEDIAL|AS\s+NEEDED|IF\s+REQUIRED|ADDITIONAL\s+TRAINING)\b/i', $hay)) {
        return [
            'mission_role' => 'OPTIONAL_OR_REMEDIAL',
            'mission_role_confidence' => 'MEDIUM',
            'mission_role_reason' => 'Optional/remedial naming',
        ];
    }

    // Proficiency missions: holdings, approaches, consolidations often intentionally repeated
    if (preg_match('/\b(HOLDING|HOLDINGS|APPROACH|APPROACHES|CIRCLE\s+TO\s+LAND|CONSOLIDATION|PROFICIENCY|RECURRENT|PRACTICE)\b/i', $hay)) {
        // If high incomplete share, still proficiency but note difficulty
        $conf = 'MEDIUM';
        $reason = 'Name indicates proficiency/practice skill block often repeated by design';
        if ($students >= 20 && $extraPerStudent >= 1.5 && $shareIncomplete < 0.25) {
            $conf = 'HIGH';
            $reason .= '; high intentional-looking repeats with moderate incompletes';
        }
        return [
            'mission_role' => 'PROFICIENCY_MISSION',
            'mission_role_confidence' => $conf,
            'mission_role_reason' => $reason,
        ];
    }

    // Default progression for FLIGHT/FNPT/SAB sequenced lessons
    if (in_array($type, ['FLIGHT', 'FNPT', 'SAB', ''], true) || $event === 'NORMAL TRAINING') {
        return [
            'mission_role' => 'PROGRESSION_MISSION',
            'mission_role_confidence' => 'MEDIUM',
            'mission_role_reason' => 'Standard sequenced training mission without accumulation/check/solo/proficiency signals',
        ];
    }

    return [
        'mission_role' => 'UNKNOWN',
        'mission_role_confidence' => 'LOW',
        'mission_role_reason' => 'Insufficient structure to assign analytical role',
    ];
}
