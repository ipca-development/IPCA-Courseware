<?php
declare(strict_types=1);

require_once __DIR__ . '/HobbsCalculationService.php';

final class TachoCalculationService
{
    public const VERSION = 'tacho_rpm_threshold_cumulative_v2';

    /**
     * @param list<array<string,string>> $rows
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public function calculate(array $rows, array $config): array
    {
        $threshold = isset($config['tacho_rpm_threshold']) && $config['tacho_rpm_threshold'] !== null
            ? (float)$config['tacho_rpm_threshold']
            : null;
        if ($threshold === null) {
            return array(
                'type' => 'tacho',
                'status' => 'not_configured',
                'verification_status' => 'needs_review',
                'start_utc' => null,
                'end_utc' => null,
                'duration_ms' => null,
                'duration_hours_exact' => null,
                'duration_hours_display' => null,
                'method' => 'not_configured',
                'calculation_version' => self::VERSION,
                'threshold_rpm' => null,
                'source' => array('type' => 'aircraft_config'),
                'confidence' => 0.0,
                'exceptions' => array('Tacho RPM threshold is not configured for this aircraft.'),
            );
        }

        $samples = $this->rpmSamples($rows);
        if (!$samples) {
            return array(
                'type' => 'tacho',
                'status' => 'review_required',
                'verification_status' => 'needs_review',
                'start_utc' => null,
                'end_utc' => null,
                'duration_ms' => null,
                'duration_hours_exact' => null,
                'duration_hours_display' => null,
                'method' => 'rpm_unavailable',
                'calculation_version' => self::VERSION,
                'threshold_rpm' => $threshold,
                'source' => array('type' => 'garmin_csv', 'fields' => array('RPM', 'E1 RPM', 'Engine RPM'), 'aircraft_config_field' => 'tacho_rpm_threshold'),
                'confidence' => 0.0,
                'exceptions' => array('RPM samples are unavailable; manual verification is required.'),
            );
        }

        $durationMs = 0;
        $start = null;
        $end = null;
        for ($i = 0; $i < count($samples) - 1; $i++) {
            if ($samples[$i]['rpm'] <= $threshold) {
                continue;
            }
            $gapMs = $this->diffMs($samples[$i]['time'], $samples[$i + 1]['time']);
            if ($gapMs < 0 || $gapMs > 30000) {
                continue;
            }
            $start ??= $samples[$i]['time'];
            $end = $samples[$i + 1]['time'];
            $durationMs += $gapMs;
        }

        if ($durationMs <= 0 || $start === null || $end === null) {
            return array(
                'type' => 'tacho',
                'status' => 'not_running',
                'verification_status' => 'needs_review',
                'start_utc' => null,
                'end_utc' => null,
                'duration_ms' => 0,
                'duration_hours_exact' => 0.0,
                'duration_hours_display' => 0.0,
                'method' => 'rpm_threshold_cumulative',
                'calculation_version' => self::VERSION,
                'threshold_rpm' => $threshold,
                'source' => array('type' => 'garmin_csv', 'fields' => array('RPM', 'E1 RPM', 'Engine RPM'), 'aircraft_config_field' => 'tacho_rpm_threshold'),
                'confidence' => 0.60,
                'exceptions' => array('No RPM samples exceeded the Tacho threshold.'),
            );
        }

        $hours = $durationMs / 3600000;
        return array(
            'type' => 'tacho',
            'status' => 'ok',
            'verification_status' => 'system_verified',
            'start_utc' => $start->format('Y-m-d H:i:s.v'),
            'end_utc' => $end->format('Y-m-d H:i:s.v'),
            'duration_ms' => $durationMs,
            'duration_hours_exact' => $hours,
            'duration_hours_display' => round($hours, 1),
            'method' => 'rpm_threshold_cumulative',
            'calculation_version' => self::VERSION,
            'threshold_rpm' => $threshold,
            'source' => array('type' => 'garmin_csv', 'fields' => array('RPM', 'E1 RPM', 'Engine RPM'), 'aircraft_config_field' => 'tacho_rpm_threshold'),
            'confidence' => 0.85,
            'exceptions' => array(),
            'uncertainty_ms' => $this->medianSampleGapMs($samples),
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
            $gap = $this->diffMs($samples[$i - 1]['time'], $samples[$i]['time']);
            if ($gap >= 0 && $gap <= 30000) {
                $gaps[] = $gap;
            }
        }
        sort($gaps);
        return $gaps ? (int)$gaps[(int)floor(count($gaps) / 2)] : 0;
    }
}
