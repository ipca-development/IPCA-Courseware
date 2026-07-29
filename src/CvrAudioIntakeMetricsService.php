<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/CockpitRecorderService.php';

final class CvrAudioIntakeMetricsService
{
    private const PROJECT_ROOT = __DIR__ . '/..';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public function enrichRows(array $rows): array
    {
        $enriched = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row['intake_source_label'] = self::sourceLabel($row);
            $row['intake_source_class'] = self::sourceClass($row);
            $row['intake_mission_code'] = self::missionCode($row);
            $row['intake_crew_lines'] = self::crewLines($row);
            $row['intake_input_mix'] = $this->inputMix($row);
            $enriched[] = $row;
        }
        return $enriched;
    }

    /** @return array{label:string,class:string} */
    public static function sourcePill(array $row): array
    {
        return array(
            'label' => self::sourceLabel($row),
            'class' => self::sourceClass($row),
        );
    }

    public static function sourceLabel(array $row): string
    {
        $stored = strtolower(trim((string)($row['intake_source'] ?? '')));
        if ($stored === 'manual') {
            return 'MANUAL';
        }
        if ($stored === 'ipca_cvr' || $stored === 'cvr_app') {
            return 'IPCA CVR';
        }

        $device = strtolower(trim((string)($row['input_device'] ?? '')));
        if ($device === 'admin_manual_upload' || str_contains($device, 'manual')) {
            return 'MANUAL';
        }

        $sessionUid = trim((string)($row['session_uuid'] ?? $row['flight_session_uid'] ?? ''));
        if ($sessionUid !== '') {
            return 'IPCA CVR';
        }

        if (trim((string)($row['dispatch_mission_code'] ?? '')) !== ''
            || trim((string)($row['dispatch_crew_json'] ?? '')) !== '') {
            return 'IPCA CVR';
        }

        return 'MANUAL';
    }

    public static function sourceClass(array $row): string
    {
        return self::sourceLabel($row) === 'MANUAL' ? 'intake-source-manual' : 'intake-source-app';
    }

    public static function missionCode(array $row): string
    {
        $manual = trim((string)($row['intake_mission_code'] ?? ''));
        if ($manual !== '') {
            return $manual;
        }
        $dispatch = trim((string)($row['dispatch_mission_code'] ?? ''));
        return $dispatch !== '' ? $dispatch : '';
    }

    /**
     * @return list<array{role:string,name:string}>
     */
    public static function crewLines(array $row): array
    {
        $manual = self::decodeCrew($row['intake_crew_json'] ?? null);
        $dispatch = self::decodeCrew($row['dispatch_crew_json'] ?? null);
        $crew = $manual !== array() ? $manual : $dispatch;
        if ($crew === array()) {
            return array();
        }

        $lines = array();
        $usedRoles = array();
        foreach (array(
            array('student', 'Student'),
            array('instructor', 'Instructor'),
            array('supervisor', 'Instructor'),
            array('pic', 'PIC'),
            array('observer', 'Observer'),
        ) as [$needle, $label]) {
            foreach ($crew as $member) {
                $role = strtolower(trim((string)($member['role'] ?? '')));
                $name = trim((string)($member['name'] ?? ''));
                if ($name === '' || !str_contains($role, $needle) || isset($usedRoles[$label . ':' . $name])) {
                    continue;
                }
                $lines[] = array('role' => $label, 'name' => $name);
                $usedRoles[$label . ':' . $name] = true;
                break;
            }
        }

        foreach ($crew as $member) {
            $name = trim((string)($member['name'] ?? ''));
            $role = trim((string)($member['role'] ?? ''));
            if ($name === '') {
                continue;
            }
            $normalizedRole = $role !== '' ? ucwords(str_replace('_', ' ', $role)) : 'Crew';
            $key = $normalizedRole . ':' . $name;
            if (isset($usedRoles[$key])) {
                continue;
            }
            $lines[] = array('role' => $normalizedRole, 'name' => $name);
            $usedRoles[$key] = true;
            if (count($lines) >= 4) {
                break;
            }
        }

        return $lines;
    }

    /**
     * @return array{usb_percent:int,iphone_percent:int,label:string,detail:string,dominant:string}
     */
    public function inputMix(array $row): array
    {
        $duration = max(0.0, (float)($row['duration_seconds'] ?? 0));
        $events = $this->loadEvents($row);
        if ($events !== array() && $duration > 0) {
            $mix = $this->inputMixFromEvents($events, $duration, (string)($row['input_device'] ?? ''));
            if ($mix !== null) {
                return $mix;
            }
        }

        return $this->inputMixFromDevice((string)($row['input_device'] ?? ''));
    }

    /**
     * @param list<array<string,mixed>> $events
     * @return array{usb_percent:int,iphone_percent:int,label:string,detail:string,dominant:string}|null
     */
    private function inputMixFromEvents(array $events, float $durationSeconds, string $inputDevice): ?array
    {
        if ($durationSeconds <= 0) {
            return null;
        }

        usort($events, static function (array $a, array $b): int {
            return strcmp((string)($a['timestamp'] ?? ''), (string)($b['timestamp'] ?? ''));
        });

        $start = strtotime((string)($events[0]['timestamp'] ?? ''));
        if ($start === false) {
            $start = time();
        }

        $isInternal = self::deviceIsInternal($inputDevice);
        $segments = array();
        $cursor = 0.0;
        $currentInternal = $isInternal;

        foreach ($events as $event) {
            $type = strtolower(trim((string)($event['type'] ?? '')));
            if (!in_array($type, array('audio_source_warning', 'audio_source_restored'), true)) {
                continue;
            }
            $eventTime = strtotime((string)($event['timestamp'] ?? ''));
            if ($eventTime === false) {
                continue;
            }
            $offset = max(0.0, min($durationSeconds, (float)($eventTime - $start)));
            if ($offset > $cursor) {
                $segments[] = array('internal' => $currentInternal, 'seconds' => $offset - $cursor);
                $cursor = $offset;
            }
            if ($type === 'audio_source_warning') {
                $currentInternal = true;
            } else {
                $message = strtolower((string)($event['message'] ?? ''));
                $currentInternal = !(str_contains($message, 'usb') || str_contains($message, 'external'));
            }
        }

        if ($cursor < $durationSeconds) {
            $segments[] = array('internal' => $currentInternal, 'seconds' => $durationSeconds - $cursor);
        }

        if ($segments === array()) {
            return null;
        }

        $iphoneSeconds = 0.0;
        $usbSeconds = 0.0;
        foreach ($segments as $segment) {
            if (!empty($segment['internal'])) {
                $iphoneSeconds += (float)$segment['seconds'];
            } else {
                $usbSeconds += (float)$segment['seconds'];
            }
        }

        $total = max(0.001, $iphoneSeconds + $usbSeconds);
        return self::formatInputMix(
            (int)round(($usbSeconds / $total) * 100),
            (int)round(($iphoneSeconds / $total) * 100)
        );
    }

    /** @return array{usb_percent:int,iphone_percent:int,label:string,detail:string,dominant:string} */
    private static function inputMixFromDevice(string $inputDevice): array
    {
        if (self::deviceIsInternal($inputDevice)) {
            return self::formatInputMix(0, 100);
        }
        if (self::deviceIsExternal($inputDevice)) {
            return self::formatInputMix(100, 0);
        }
        return self::formatInputMix(0, 100);
    }

    /** @return array{usb_percent:int,iphone_percent:int,label:string,detail:string,dominant:string} */
    private static function formatInputMix(int $usbPercent, int $iphonePercent): array
    {
        $usbPercent = max(0, min(100, $usbPercent));
        $iphonePercent = max(0, min(100, $iphonePercent));
        if ($usbPercent + $iphonePercent !== 100) {
            if ($usbPercent >= $iphonePercent) {
                $usbPercent = 100 - $iphonePercent;
            } else {
                $iphonePercent = 100 - $usbPercent;
            }
        }
        $dominant = $usbPercent >= $iphonePercent ? 'usb' : 'iphone';
        return array(
            'usb_percent' => $usbPercent,
            'iphone_percent' => $iphonePercent,
            'label' => $dominant === 'usb' ? 'USB-C Mic' : 'iPhone Mic',
            'detail' => 'USB ' . $usbPercent . '% · iPhone ' . $iphonePercent . '%',
            'dominant' => $dominant,
        );
    }

    /** @return list<array<string,mixed>> */
    private function loadEvents(array $row): array
    {
        $relativePath = trim((string)($row['recording_events_storage_path'] ?? ''));
        if ($relativePath === '') {
            return array();
        }
        $absolutePath = self::PROJECT_ROOT . '/' . ltrim($relativePath, '/');
        if (!is_file($absolutePath)) {
            return array();
        }
        $raw = file_get_contents($absolutePath);
        if ($raw === false || trim($raw) === '') {
            return array();
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : array();
    }

    /** @return list<array{role:string,name:string}> */
    private static function decodeCrew(mixed $value): array
    {
        $decoded = is_array($value) ? $value : json_decode((string)$value, true);
        if (!is_array($decoded)) {
            return array();
        }
        $crew = array();
        foreach ($decoded as $member) {
            if (!is_array($member)) {
                continue;
            }
            $name = trim((string)($member['personName'] ?? $member['person_name'] ?? $member['name'] ?? ''));
            $role = trim((string)($member['role'] ?? $member['crew_role'] ?? ''));
            if ($name === '') {
                continue;
            }
            $crew[] = array('role' => $role, 'name' => $name);
        }
        return $crew;
    }

    private static function deviceIsInternal(string $inputDevice): bool
    {
        $device = strtolower(trim($inputDevice));
        if ($device === '') {
            return true;
        }
        return str_contains($device, 'iphone')
            || str_contains($device, 'internal')
            || str_contains($device, 'microphone')
            || str_contains($device, 'admin_manual');
    }

    private static function deviceIsExternal(string $inputDevice): bool
    {
        $device = strtolower(trim($inputDevice));
        return $device !== ''
            && (str_contains($device, 'usb') || str_contains($device, 'external') || str_contains($device, 'earpod'));
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public function saveIntakeMetadata(int $recordingId, array $metadata): void
    {
        if ($recordingId <= 0) {
            return;
        }

        $sets = array();
        $values = array();
        if ($this->columnExists('intake_source') && isset($metadata['intake_source'])) {
            $sets[] = 'intake_source = ?';
            $values[] = substr(trim((string)$metadata['intake_source']), 0, 32);
        }
        if ($this->columnExists('intake_mission_code') && array_key_exists('intake_mission_code', $metadata)) {
            $sets[] = 'intake_mission_code = ?';
            $values[] = substr(trim((string)$metadata['intake_mission_code']), 0, 64) ?: null;
        }
        if ($this->columnExists('intake_crew_json') && array_key_exists('intake_crew_json', $metadata)) {
            $sets[] = 'intake_crew_json = ?';
            $values[] = $metadata['intake_crew_json'] === null
                ? null
                : AuditEventService::jsonEncode($metadata['intake_crew_json']);
        }
        if ($sets === array()) {
            return;
        }
        $values[] = $recordingId;
        $this->pdo->prepare(
            'UPDATE ipca_cockpit_recordings SET ' . implode(', ', $sets) . ', updated_at = CURRENT_TIMESTAMP(3) WHERE id = ?'
        )->execute($values);
    }

    /**
     * @return list<array{role:string,name:string}>
     */
    public static function crewFromManualForm(string $studentName, string $instructorName): array
    {
        $crew = array();
        $studentName = trim($studentName);
        $instructorName = trim($instructorName);
        if ($studentName !== '') {
            $crew[] = array('role' => 'student', 'name' => $studentName, 'personName' => $studentName);
        }
        if ($instructorName !== '') {
            $crew[] = array('role' => 'instructor', 'name' => $instructorName, 'personName' => $instructorName);
        }
        return $crew;
    }

    private function columnExists(string $column): bool
    {
        static $cache = array();
        if (isset($cache[$column])) {
            return $cache[$column];
        }
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute(array('ipca_cockpit_recordings', $column));
        return $cache[$column] = ((int)$stmt->fetchColumn() > 0);
    }
}
