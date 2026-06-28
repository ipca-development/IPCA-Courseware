<?php
declare(strict_types=1);

/**
 * Aircraft PFD profile: V-speed bugs, airspeed arc colors, engine gauge zones.
 */
final class PfdProfileService
{
    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return array(
            'units' => array(
                'altimeter' => 'inHg',
                'temperature' => 'C',
            ),
            'v_speeds' => array(
                'Va' => 92,
                'Vy' => 74,
                'Vg' => 68,
                'Vx' => 58,
                'Vr' => 50,
            ),
            'airspeed_arc' => array(
                array('from' => 0, 'to' => 40, 'color' => 'white'),
                array('from' => 40, 'to' => 50, 'color' => 'green'),
                array('from' => 50, 'to' => 99, 'color' => 'white'),
                array('from' => 99, 'to' => 129, 'color' => 'green'),
                array('from' => 129, 'to' => 200, 'color' => 'red'),
            ),
            'airspeed_tape' => array('min' => 20, 'max' => 200, 'major' => 10, 'minor' => 2),
            'altitude_tape' => array('min' => -1000, 'max' => 20000, 'major' => 100, 'minor' => 20),
            'gauges' => array(
                'rpm' => array(
                    'label' => 'RPM',
                    'type' => 'arc',
                    'min' => 0,
                    'max' => 2700,
                    'zones' => array(
                        array('from' => 0, 'to' => 700, 'color' => 'white'),
                        array('from' => 700, 'to' => 2500, 'color' => 'green'),
                        array('from' => 2500, 'to' => 2600, 'color' => 'yellow'),
                        array('from' => 2600, 'to' => 2700, 'color' => 'red'),
                    ),
                ),
                'gph' => array(
                    'label' => 'GPH',
                    'type' => 'bar',
                    'min' => 0,
                    'max' => 20,
                    'zones' => array(
                        array('from' => 0, 'to' => 16, 'color' => 'green'),
                        array('from' => 16, 'to' => 20, 'color' => 'white'),
                    ),
                ),
                'oil_psi' => array(
                    'label' => 'OIL PSI',
                    'type' => 'bar',
                    'min' => 0,
                    'max' => 120,
                    'zones' => array(
                        array('from' => 0, 'to' => 20, 'color' => 'red'),
                        array('from' => 20, 'to' => 40, 'color' => 'white'),
                        array('from' => 40, 'to' => 100, 'color' => 'green'),
                        array('from' => 100, 'to' => 115, 'color' => 'yellow'),
                        array('from' => 115, 'to' => 120, 'color' => 'red'),
                    ),
                ),
                'oil_temp_f' => array(
                    'label' => 'OIL °F',
                    'type' => 'bar',
                    'min' => 0,
                    'max' => 250,
                    'zones' => array(
                        array('from' => 0, 'to' => 60, 'color' => 'white'),
                        array('from' => 60, 'to' => 220, 'color' => 'green'),
                        array('from' => 220, 'to' => 240, 'color' => 'yellow'),
                        array('from' => 240, 'to' => 250, 'color' => 'red'),
                    ),
                ),
                'egt1_f' => array(
                    'label' => 'EGT °F',
                    'type' => 'bar',
                    'min' => 0,
                    'max' => 1600,
                    'zones' => array(
                        array('from' => 0, 'to' => 200, 'color' => 'white'),
                        array('from' => 200, 'to' => 1400, 'color' => 'green'),
                        array('from' => 1400, 'to' => 1500, 'color' => 'yellow'),
                        array('from' => 1500, 'to' => 1600, 'color' => 'red'),
                    ),
                ),
                'egt2_f' => array(
                    'label' => 'EGT2 °F',
                    'type' => 'bar',
                    'min' => 0,
                    'max' => 1600,
                    'zones' => array(
                        array('from' => 0, 'to' => 200, 'color' => 'white'),
                        array('from' => 200, 'to' => 1400, 'color' => 'green'),
                        array('from' => 1400, 'to' => 1500, 'color' => 'yellow'),
                        array('from' => 1500, 'to' => 1600, 'color' => 'red'),
                    ),
                ),
                'fuel_gal' => array(
                    'label' => 'FUEL GAL',
                    'type' => 'bar',
                    'min' => 0,
                    'max' => 30,
                    'zones' => array(
                        array('from' => 0, 'to' => 4, 'color' => 'yellow'),
                        array('from' => 4, 'to' => 30, 'color' => 'green'),
                    ),
                ),
                'fuel_psi' => array(
                    'label' => 'FUEL PSI',
                    'type' => 'bar',
                    'min' => 0,
                    'max' => 30,
                    'zones' => array(
                        array('from' => 0, 'to' => 5, 'color' => 'white'),
                        array('from' => 5, 'to' => 30, 'color' => 'green'),
                    ),
                ),
                'coolant1_f' => array(
                    'label' => 'COOLANT °F',
                    'type' => 'bar',
                    'min' => 0,
                    'max' => 250,
                    'zones' => array(
                        array('from' => 0, 'to' => 160, 'color' => 'white'),
                        array('from' => 160, 'to' => 210, 'color' => 'yellow'),
                        array('from' => 210, 'to' => 250, 'color' => 'red'),
                    ),
                ),
                'coolant2_f' => array(
                    'label' => 'COOL2 °F',
                    'type' => 'bar',
                    'min' => 0,
                    'max' => 250,
                    'zones' => array(
                        array('from' => 0, 'to' => 160, 'color' => 'white'),
                        array('from' => 160, 'to' => 210, 'color' => 'yellow'),
                        array('from' => 210, 'to' => 250, 'color' => 'red'),
                    ),
                ),
                'volts' => array(
                    'label' => 'VOLTS',
                    'type' => 'bar',
                    'min' => 0,
                    'max' => 30,
                    'zones' => array(
                        array('from' => 0, 'to' => 11, 'color' => 'red'),
                        array('from' => 11, 'to' => 12.5, 'color' => 'yellow'),
                        array('from' => 12.5, 'to' => 14.5, 'color' => 'green'),
                        array('from' => 14.5, 'to' => 16, 'color' => 'yellow'),
                        array('from' => 16, 'to' => 30, 'color' => 'red'),
                    ),
                ),
                'amps' => array(
                    'label' => 'AMPS',
                    'type' => 'bar',
                    'min' => -60,
                    'max' => 60,
                    'zones' => array(
                        array('from' => -60, 'to' => 0, 'color' => 'white'),
                        array('from' => 0, 'to' => 50, 'color' => 'green'),
                        array('from' => 50, 'to' => 60, 'color' => 'yellow'),
                    ),
                ),
            ),
        );
    }

    /**
     * @param mixed $raw
     * @return array<string,mixed>
     */
    public static function normalize($raw): array
    {
        $defaults = self::defaults();
        if (!is_array($raw)) {
            return $defaults;
        }
        return self::mergeRecursive($defaults, $raw);
    }

    /**
     * @param array<string,mixed> $base
     * @param array<string,mixed> $override
     * @return array<string,mixed>
     */
    private static function mergeRecursive(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && self::isAssoc($value) && self::isAssoc($base[$key])) {
                $base[$key] = self::mergeRecursive($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    /**
     * @param array<string,mixed> $arr
     */
    private static function isAssoc(array $arr): bool
    {
        return $arr === array() || array_keys($arr) !== range(0, count($arr) - 1);
    }

    /**
     * @return array<string,mixed>
     */
    public static function fromStored(?string $json): array
    {
        $json = trim((string)$json);
        if ($json === '') {
            return self::defaults();
        }
        $decoded = json_decode($json, true);
        return self::normalize(is_array($decoded) ? $decoded : array());
    }

    /**
     * @param array<string,mixed> $profile
     */
    public static function encode(array $profile): string
    {
        return json_encode(self::normalize($profile), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
