<?php
declare(strict_types=1);

final class CvrIntakeDisplayService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function timezoneForRegistration(string $registration): string
    {
        return cw_aircraft_operational_timezone_by_registration($this->pdo, $registration);
    }

    public function localTime(?string $utc, string $registration): string
    {
        return cw_logbook_time($utc, $this->timezoneForRegistration($registration));
    }

    public function localDatetime(?string $utc, string $registration): string
    {
        return cw_logbook_datetime($utc, $this->timezoneForRegistration($registration));
    }

    public function blockRange(?string $offBlockUtc, ?string $onBlockUtc, string $registration): string
    {
        $off = trim((string)$offBlockUtc);
        $on = trim((string)$onBlockUtc);
        if ($off === '' && $on === '') {
            return '—';
        }
        $tz = $this->timezoneForRegistration($registration);
        $parts = array();
        if ($off !== '') {
            $parts[] = 'OFF ' . cw_logbook_time($off, $tz);
        }
        if ($on !== '') {
            $parts[] = 'ON ' . cw_logbook_time($on, $tz);
        }
        return implode(' · ', $parts) . ' LT';
    }

    /** @param array<string,mixed> $row */
    public function dispatchOptionLabel(array $row): string
    {
        $tail = trim((string)($row['aircraft_registration'] ?? '')) ?: '—';
        $mission = trim((string)($row['mission_code'] ?? '')) ?: 'No mission';
        $crew = $this->crewShort($row['crew_json'] ?? null);
        $blocks = $this->blockRange(
            isset($row['off_block_utc']) ? (string)$row['off_block_utc'] : null,
            isset($row['on_block_utc']) ? (string)$row['on_block_utc'] : null,
            $tail
        );
        $uuid = trim((string)($row['dispatch_uuid'] ?? ''));
        $uuidShort = $uuid !== '' ? substr($uuid, 0, 8) : '';
        $received = $this->localDatetime(isset($row['received_at']) ? (string)$row['received_at'] : null, $tail);
        return implode(' · ', array_filter(array(
            $tail,
            $mission,
            $crew !== '—' ? 'Crew: ' . $crew : '',
            $blocks !== '—' ? $blocks : '',
            $received !== '—' ? 'Rcvd ' . $received : '',
            $uuidShort !== '' ? '#' . $uuidShort : '',
        )));
    }

    /** @param array<string,mixed> $row */
    public function audioOptionLabel(array $row): string
    {
        $tail = trim((string)($row['aircraft_registration'] ?? '')) ?: '—';
        $start = $this->localDatetime(isset($row['started_at']) ? (string)$row['started_at'] : null, $tail);
        $duration = (float)($row['duration_seconds'] ?? 0);
        $durationLabel = $duration > 0 ? $this->formatDuration($duration) : '';
        $transcript = strtoupper(trim((string)($row['transcription_status'] ?? 'unknown')));
        $filename = trim((string)($row['original_filename'] ?? ''));
        $uid = trim((string)($row['recording_uid'] ?? ''));
        return implode(' · ', array_filter(array(
            $tail,
            $start !== '—' ? 'Start ' . $start : '',
            $durationLabel,
            'Transcript ' . $transcript,
            $filename !== '' ? $filename : ($uid !== '' ? substr($uid, 0, 12) : ''),
        )));
    }

    /** @param array<string,mixed> $row */
    public function garminOptionLabel(array $row): string
    {
        $tail = trim((string)($row['aircraft_registration'] ?? '')) ?: '—';
        $source = trim((string)($row['source_label'] ?? 'CVR APP'));
        $first = isset($row['first_valid_sample_utc']) ? (string)$row['first_valid_sample_utc'] : '';
        $last = isset($row['last_valid_sample_utc']) ? (string)$row['last_valid_sample_utc'] : '';
        $tz = $this->timezoneForRegistration($tail);
        $coverage = '—';
        if ($first !== '' || $last !== '') {
            $start = $first !== '' ? cw_logbook_time($first, $tz) : '?';
            $end = $last !== '' ? cw_logbook_time($last, $tz) : '?';
            $coverage = $start . '–' . $end . ' LT';
        }
        $filename = trim((string)($row['original_filename'] ?? ''));
        $rows = (int)($row['valid_row_count'] ?? 0);
        return implode(' · ', array_filter(array(
            $tail,
            $source,
            $coverage !== '—' ? $coverage : '',
            $filename,
            $rows > 0 ? number_format($rows) . ' rows' : '',
        )));
    }

    public function crewShort(mixed $value): string
    {
        if (is_array($value)) {
            $decoded = $value;
        } else {
            $decoded = json_decode((string)$value, true);
        }
        if (!is_array($decoded) || $decoded === array()) {
            return '—';
        }
        $names = array();
        foreach ($decoded as $member) {
            if (!is_array($member)) {
                continue;
            }
            $name = trim((string)($member['personName'] ?? $member['person_name'] ?? $member['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }
        return $names === array() ? '—' : implode(', ', $names);
    }

    private function formatDuration(float $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds >= 3600) {
            return number_format($seconds / 3600, 1) . ' h';
        }
        if ($seconds >= 60) {
            return number_format($seconds / 60, 0) . ' min';
        }
        return number_format($seconds, 0) . ' s';
    }
}
