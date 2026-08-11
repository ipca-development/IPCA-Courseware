<?php
declare(strict_types=1);

require_once __DIR__ . '/CvrOperationalLegTimelineService.php';

/**
 * Phase 4A single authority for operational Off/On Block derivation.
 *
 * Off Block = Engine Start (engine_start_off_block), else Check-In closure payload.
 * On Block  = Off Block + (Ending Hobbs − Starting Hobbs).
 * Never use Transient Stop / Engine Shutdown button-press timestamps as the display On Block.
 */
final class CvrOperationalBlockTimeService
{
    /**
     * @param array<string,mixed> $row Keys: off_block_utc, starting_hobbs, ending_hobbs, closure_on_block_utc?
     */
    public function derivedOnBlockUtc(array $row): ?string
    {
        $offRaw = trim((string)($row['off_block_utc'] ?? ''));
        $startingHobbs = $row['starting_hobbs'] ?? null;
        $endingHobbs = $row['ending_hobbs'] ?? null;
        if ($offRaw !== '' && is_numeric($startingHobbs) && is_numeric($endingHobbs)) {
            $rawDeltaHours = (float)$endingHobbs - (float)$startingHobbs;
            if ($rawDeltaHours >= 0) {
                $deltaHours = CvrOperationalLegTimelineService::roundUpToTenth($rawDeltaHours);
                try {
                    $off = new DateTimeImmutable($offRaw, new DateTimeZone('UTC'));
                    $seconds = (int)round($deltaHours * 3600);
                    return $off->modify(sprintf('+%d seconds', $seconds))->format('Y-m-d H:i:s');
                } catch (Throwable) {
                    // Fall through to closure-carried Hobbs-derived ON Block.
                }
            }
        }

        $closureOn = trim((string)($row['closure_on_block_utc'] ?? ''));
        return $closureOn !== '' ? $closureOn : null;
    }

    public function engineTimeHours(mixed $startingHobbs, mixed $endingHobbs): ?float
    {
        if (!is_numeric($startingHobbs) || !is_numeric($endingHobbs)) {
            return null;
        }
        $delta = (float)$endingHobbs - (float)$startingHobbs;
        return $delta >= 0
            ? CvrOperationalLegTimelineService::roundUpToTenth($delta)
            : null;
    }

    /**
     * Preferred crew/instructor-facing synchronization vocabulary.
     *
     * @return array{
     *   sync_status:string,
     *   dispatch_status:string,
     *   audio_status:string,
     *   transcript_status:string
     * }
     */
    public function presentationStatuses(
        bool $hasDispatchReceipt,
        bool $hasClosure,
        bool $hasRecorderVerification,
        string $audioUploadStatus,
        string $transcriptStatus
    ): array {
        $audio = strtolower(trim($audioUploadStatus));
        $transcript = strtolower(trim($transcriptStatus));

        $audioLabel = match ($audio) {
            'uploaded', 'complete' => 'Audio Uploaded',
            'uploading' => 'Synchronizing',
            'failed' => 'Audio Failed',
            'missing', '' => 'Stored on Device',
            default => 'Synchronizing',
        };

        $transcriptLabel = match ($transcript) {
            'ready' => 'Transcript Ready',
            'failed' => 'Transcript Failed',
            'transcribing', 'queued' => 'Transcript Processing',
            default => 'Transcript Pending',
        };

        $dispatchLabel = !$hasDispatchReceipt
            ? 'Stored on Device'
            : ($hasClosure ? 'Server Verified' : 'Synchronizing');

        if ($audio === 'failed' || $transcript === 'failed') {
            $sync = 'Needs Attention';
        } elseif (
            $hasDispatchReceipt
            && $hasClosure
            && $hasRecorderVerification
            && in_array($audio, array('uploaded', 'complete'), true)
            && $transcript === 'ready'
        ) {
            $sync = 'Complete';
        } elseif (
            $hasDispatchReceipt
            && $hasClosure
            && in_array($audio, array('uploaded', 'complete'), true)
        ) {
            $sync = 'Audio Uploaded';
        } elseif ($hasDispatchReceipt && ($hasClosure || $hasRecorderVerification)) {
            $sync = 'Server Verified';
        } elseif ($hasDispatchReceipt) {
            $sync = 'Synchronizing';
        } else {
            $sync = 'Stored on Device';
        }

        return array(
            'sync_status' => $sync,
            'dispatch_status' => $dispatchLabel,
            'audio_status' => $audioLabel,
            'transcript_status' => $transcriptLabel,
        );
    }

    /**
     * @param mixed $crewJson
     * @return list<array{name:string,role:string,display:string}>
     */
    public function parseCrew(mixed $crewJson): array
    {
        if (is_array($crewJson)) {
            $decoded = $crewJson;
        } else {
            $decoded = json_decode((string)$crewJson, true);
        }
        if (!is_array($decoded)) {
            return array();
        }
        $crew = array();
        foreach ($decoded as $member) {
            if (is_string($member)) {
                $display = trim($member);
                if ($display === '') {
                    continue;
                }
                $role = '';
                $name = $display;
                if (preg_match('/^(.*)\(([^)]+)\)\s*$/', $display, $matches) === 1) {
                    $name = trim((string)$matches[1]);
                    $role = trim((string)$matches[2]);
                }
                $crew[] = array(
                    'name' => $name !== '' ? $name : $display,
                    'role' => $role,
                    'display' => $display,
                );
                continue;
            }
            if (!is_array($member)) {
                continue;
            }
            $name = trim((string)($member['personName'] ?? $member['person_name'] ?? $member['name'] ?? ''));
            $role = trim((string)($member['role'] ?? $member['crew_role'] ?? ''));
            if ($name === '') {
                continue;
            }
            $crew[] = array(
                'name' => $name,
                'role' => $role,
                'display' => $role !== '' ? $name . ' (' . $role . ')' : $name,
            );
        }
        return $crew;
    }
}
