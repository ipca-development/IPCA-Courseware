<?php
declare(strict_types=1);

final class GarminCsvImportProfile
{
    public const G3X = 'garmin_g3x';
    public const G1000_NXI = 'garmin_g1000nxi';

    /**
     * @return array<string,string>
     */
    public static function options(): array
    {
        return array(
            self::G3X => 'Garmin G3X / GDU 460',
            self::G1000_NXI => 'Garmin G1000 NXi data log',
        );
    }

    public static function normalize(?string $profile): string
    {
        $profile = strtolower(trim((string)$profile));
        $profile = str_replace(array('-', ' '), '_', $profile);
        if (in_array($profile, array('g1000', 'g1000nxi', 'garmin_g1000', 'garmin_g1000_nxi', self::G1000_NXI), true)) {
            return self::G1000_NXI;
        }
        return self::G3X;
    }

    public static function label(string $profile): string
    {
        $profile = self::normalize($profile);
        $options = self::options();
        return $options[$profile] ?? $profile;
    }

    public static function forAircraft(?string $registration, ?string $displayName, ?string $aircraftType): string
    {
        $haystack = strtoupper(trim((string)$registration . ' ' . (string)$displayName . ' ' . (string)$aircraftType));
        if (str_contains($haystack, 'AL172') || str_contains($haystack, 'ALSIM') || str_contains($haystack, 'G1000')) {
            return self::G1000_NXI;
        }
        return self::G3X;
    }

    /**
     * @param array<int,string> $headers
     */
    public static function detectFromHeaders(array $headers, array $aliasHeaders): string
    {
        $headerText = implode('|', array_map(static fn($value): string => trim((string)$value), $headers));
        $aliasText = implode('|', array_map(static fn($value): string => trim((string)$value), $aliasHeaders));
        if (str_contains($headerText, 'Wind Speed (kt)') || str_contains($headerText, 'GPS Time of Week (sec)')) {
            return self::G3X;
        }
        if (str_contains($aliasText, 'AltB') || str_contains($aliasText, 'HSIS') || str_contains($aliasText, 'AfcsOn')) {
            return self::G1000_NXI;
        }
        return self::G3X;
    }

    public static function assertMatches(string $expectedProfile, string $actualProfile): void
    {
        $expectedProfile = self::normalize($expectedProfile);
        $actualProfile = self::normalize($actualProfile);
        if ($expectedProfile !== $actualProfile) {
            throw new RuntimeException(
                'Selected import profile is ' . self::label($expectedProfile)
                . ', but the uploaded CSV looks like ' . self::label($actualProfile) . '.'
            );
        }
    }
}
