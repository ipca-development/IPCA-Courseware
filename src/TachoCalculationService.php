<?php
declare(strict_types=1);

require_once __DIR__ . '/HobbsCalculationService.php';

final class TachoCalculationService
{
    public const VERSION = 'tacho_rpm_threshold_v1';

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

        $hobbsStyle = new HobbsCalculationService();
        $result = $hobbsStyle->calculate($rows, array(
            'hobbs_engine_on_rpm_threshold' => $threshold,
            'hobbs_start_confirm_ms' => 1000,
            'hobbs_stop_confirm_ms' => 5000,
        ));
        $result['type'] = 'tacho';
        $result['calculation_version'] = self::VERSION;
        $result['source'] = array('type' => 'garmin_csv', 'fields' => array('RPM', 'E1 RPM', 'Engine RPM'), 'aircraft_config_field' => 'tacho_rpm_threshold');
        return $result;
    }
}
