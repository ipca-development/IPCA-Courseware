<?php
declare(strict_types=1);

require_once __DIR__ . '/G3XFlightStreamParser.php';

final class FlightOperationalCalculationService
{
    /**
     * @param list<array<string,string>> $rows
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public function calculateSessionCounters(array $rows, array $config): array
    {
        $hobbs = $this->engineInterval(
            $rows,
            (float)($config['hobbs_engine_on_rpm_threshold'] ?? 1000.0),
            (int)($config['hobbs_start_confirm_ms'] ?? 1000),
            (int)($config['hobbs_stop_confirm_ms'] ?? 5000)
        );
        $tachoThreshold = isset($config['tacho_rpm_threshold']) && $config['tacho_rpm_threshold'] !== null
            ? (float)$config['tacho_rpm_threshold']
            : null;
        $tacho = $tachoThreshold !== null
            ? $this->engineInterval($rows, $tachoThreshold, 1000, 5000)
            : array('status' => 'not_configured', 'start_utc' => null, 'end_utc' => null, 'duration_ms' => null);

        return array(
            'hobbs' => $hobbs,
            'tacho' => $tacho,
            'config_version_uuid' => $config['config_version_uuid'] ?? 'phase1-default',
            'calculation_version' => 'phase3-v1',
        );
    }

    /**
     * @param list<array<string,string>> $rows
     * @return array<string,mixed>
     */
    private function engineInterval(array $rows, float $thresholdRpm, int $startConfirmMs, int $stopConfirmMs): array
    {
        $samples = $this->rpmSamples($rows);
        if (!$samples) {
            return array(
                'status' => 'review_required',
                'start_utc' => null,
                'end_utc' => null,
                'duration_ms' => null,
                'method' => 'rpm_unavailable',
                'threshold_rpm' => $thresholdRpm,
            );
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
            return array(
                'status' => 'not_running',
                'start_utc' => null,
                'end_utc' => null,
                'duration_ms' => 0,
                'method' => 'rpm_threshold',
                'threshold_rpm' => $thresholdRpm,
            );
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

        $durationMs = $this->diffMs($start['time'], $end['time']);
        return array(
            'status' => 'ok',
            'start_utc' => $start['time']->format('Y-m-d H:i:s.v'),
            'end_utc' => $end['time']->format('Y-m-d H:i:s.v'),
            'duration_ms' => max(0, $durationMs),
            'method' => 'rpm_threshold',
            'threshold_rpm' => $thresholdRpm,
            'start_confirm_ms' => $startConfirmMs,
            'stop_confirm_ms' => $stopConfirmMs,
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
            $gaps[] = $this->diffMs($samples[$i - 1]['time'], $samples[$i]['time']);
        }
        sort($gaps);
        return $gaps ? (int)$gaps[(int)floor(count($gaps) / 2)] : 0;
    }
}
