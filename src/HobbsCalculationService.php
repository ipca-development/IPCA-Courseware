<?php
declare(strict_types=1);

require_once __DIR__ . '/G3XFlightStreamParser.php';

final class HobbsCalculationService
{
    public const VERSION = 'hobbs_rpm_threshold_v1';

    /**
     * @param list<array<string,string>> $rows
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public function calculate(array $rows, array $config): array
    {
        $threshold = (float)($config['hobbs_engine_on_rpm_threshold'] ?? 1000.0);
        $startConfirmMs = (int)($config['hobbs_start_confirm_ms'] ?? 1000);
        $stopConfirmMs = (int)($config['hobbs_stop_confirm_ms'] ?? 5000);
        return $this->engineInterval($rows, $threshold, $startConfirmMs, $stopConfirmMs, 'hobbs');
    }

    /**
     * @param list<array<string,string>> $rows
     * @return array<string,mixed>
     */
    private function engineInterval(array $rows, float $thresholdRpm, int $startConfirmMs, int $stopConfirmMs, string $type): array
    {
        $samples = $this->rpmSamples($rows);
        if (!$samples) {
            return $this->result($type, 'review_required', null, null, null, 'rpm_unavailable', $thresholdRpm, 0.0, array('RPM samples are unavailable; manual verification is required.'));
        }

        $start = null;
        $aboveSince = null;
        foreach ($samples as $sample) {
            if ($sample['rpm'] > $thresholdRpm) {
                $aboveSince ??= $sample;
                if ($this->diffMs($aboveSince['time'], $sample['time']) >= $startConfirmMs) {
                    $start = $aboveSince;
                    break;
                }
            } else {
                $aboveSince = null;
            }
        }
        if ($start === null) {
            return $this->result($type, 'not_running', null, null, 0, 'rpm_threshold', $thresholdRpm, 0.60, array('No sustained RPM interval exceeded the Hobbs threshold.'));
        }

        $end = end($samples);
        $belowSince = null;
        foreach ($samples as $sample) {
            if ($sample['time'] < $start['time']) {
                continue;
            }
            if ($sample['rpm'] <= $thresholdRpm) {
                $belowSince ??= $sample;
                if ($this->diffMs($belowSince['time'], $sample['time']) >= $stopConfirmMs) {
                    $end = $belowSince;
                    break;
                }
            } else {
                $belowSince = null;
                $end = $sample;
            }
        }

        $durationMs = max(0, $this->diffMs($start['time'], $end['time']));
        $result = $this->result($type, 'ok', $start['time'], $end['time'], $durationMs, 'rpm_threshold', $thresholdRpm, 0.85, array());
        $result['start_confirm_ms'] = $startConfirmMs;
        $result['stop_confirm_ms'] = $stopConfirmMs;
        $result['uncertainty_ms'] = $this->medianSampleGapMs($samples);
        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    private function result(string $type, string $status, ?DateTimeImmutable $start, ?DateTimeImmutable $end, ?int $durationMs, string $method, float $thresholdRpm, float $confidence, array $exceptions): array
    {
        $hours = $durationMs !== null ? $durationMs / 3600000 : null;
        return array(
            'type' => $type,
            'status' => $status,
            'verification_status' => $status === 'ok' ? 'system_verified' : 'needs_review',
            'start_utc' => $start?->format('Y-m-d H:i:s.v'),
            'end_utc' => $end?->format('Y-m-d H:i:s.v'),
            'duration_ms' => $durationMs,
            'duration_hours_exact' => $hours,
            'duration_hours_display' => $hours !== null ? round($hours, 1) : null,
            'method' => $method,
            'calculation_version' => self::VERSION,
            'threshold_rpm' => $thresholdRpm,
            'source' => array('type' => 'garmin_csv', 'fields' => array('RPM', 'E1 RPM', 'Engine RPM')),
            'confidence' => $confidence,
            'exceptions' => $exceptions,
        );
    }

    /**
     * @param list<array<string,string>> $rows
     * @return list<array{time:DateTimeImmutable,rpm:float}>
     */
    private function rpmSamples(array $rows): array
    {
        $samples = array();
        foreach ($rows as $row) {
            $time = G3XFlightStreamParser::rowUtcTimestamp($row);
            $rpm = G3XFlightStreamParser::numericValue($row, 'RPM', 'E1 RPM', 'Engine RPM');
            if ($time !== null && $rpm !== null) {
                $samples[] = array('time' => $time, 'rpm' => $rpm);
            }
        }
        usort($samples, static fn($a, $b): int => $a['time'] <=> $b['time']);
        return $samples;
    }

    private function diffMs(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        return (int)round(((float)$end->format('U.u') - (float)$start->format('U.u')) * 1000);
    }

    /**
     * @param list<array{time:DateTimeImmutable,rpm:float}> $samples
     */
    private function medianSampleGapMs(array $samples): int
    {
        $gaps = array();
        for ($i = 1; $i < count($samples); $i++) {
            $gaps[] = $this->diffMs($samples[$i - 1]['time'], $samples[$i]['time']);
        }
        sort($gaps);
        return $gaps ? (int)$gaps[(int)floor(count($gaps) / 2)] : 0;
    }
}
