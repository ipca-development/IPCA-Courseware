<?php
declare(strict_types=1);

require_once __DIR__ . '/GarminCsvImportProfile.php';

/**
 * Parser for Garmin G3X Flight Stream CSV exports.
 */
final class G3XFlightStreamParser
{
    /** @var list<string> */
    private const LONG_HEADERS = array(
        'Date (yyyy-mm-dd)', 'Time (hh:mm:ss)', 'UTC Time (hh:mm:ss)', 'UTC Offset (hh:mm)',
        'Latitude (deg)', 'Longitude (deg)', 'GPS Altitude (ft)', 'GPS Fix Status',
        'GPS Time of Week (sec)', 'GPS Ground Speed (kt)', 'GPS Ground Track (deg)',
        'GPS Velocity E (m/sec)', 'GPS Velocity N (m/sec)', 'GPS Velocity U (m/sec)',
        'Magnetic Heading (deg)', 'GPS HFOM (ft)', 'GPS VFOM (ft)', 'GPS Sats',
        'Pressure Altitude (ft)', 'Baro Altitude (ft)', 'Vertical Speed (ft/min)',
        'Indicated Airspeed (kt)', 'True Airspeed (kt)', 'Pitch (deg)', 'Roll (deg)',
        'Lateral Acceleration (G)', 'Normal Acceleration (G)',
    );

    /**
     * @return array{
     *   aircraft_ident: string,
     *   product: string,
     *   metadata: array<string,string>,
     *   headers: list<string>,
     *   import_profile: string,
     *   rows: list<array<string,string>>,
     *   row_count: int
     * }
     */
    public static function parseFile(string $path, ?string $expectedImportProfile = null): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('G3X CSV file is missing.');
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not open G3X CSV file.');
        }

        try {
            $metaLine = null;
            while (($line = fgets($handle)) !== false) {
                if (stripos($line, '#airframe_info') !== false) {
                    $metaLine = $line;
                    break;
                }
            }
            if ($metaLine === null) {
                throw new RuntimeException('Unrecognized G3X CSV format (missing #airframe_info).');
            }
            $meta = self::parseMetaLine($metaLine);

            $headerLine = fgets($handle);
            if ($headerLine === false) {
                throw new RuntimeException('G3X CSV header row is missing.');
            }
            $firstHeaders = self::parseCsvLine($headerLine);
            $firstHeaders = array_map(static function (string $header): string {
                return ltrim(trim($header), "#");
            }, $firstHeaders);
            if (!$firstHeaders) {
                throw new RuntimeException('G3X CSV header row is invalid.');
            }

            $aliasLine = fgets($handle);
            if ($aliasLine === false) {
                throw new RuntimeException('Garmin CSV alias row is missing.');
            }
            $aliasHeaders = self::parseCsvLine($aliasLine);
            $aliasHeaders = array_map(static function (string $header): string {
                return ltrim(trim($header), "#");
            }, $aliasHeaders);
            $importProfile = GarminCsvImportProfile::detectFromHeaders($firstHeaders, $aliasHeaders);
            if ($expectedImportProfile !== null && trim($expectedImportProfile) !== '') {
                GarminCsvImportProfile::assertMatches($expectedImportProfile, $importProfile);
            }
            $headers = $importProfile === GarminCsvImportProfile::G1000_NXI ? $aliasHeaders : $firstHeaders;

            $rows = array();
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $values = self::parseCsvLine($line);
                if (!$values || self::isBlankRow($values)) {
                    continue;
                }
                $row = array();
                foreach ($headers as $index => $header) {
                    $header = trim((string)$header);
                    if ($header === '') {
                        continue;
                    }
                    $row[$header] = trim((string)($values[$index] ?? ''));
                }
                $rows[] = $row;
            }
        } finally {
            fclose($handle);
        }

        if (!$rows) {
            throw new RuntimeException('G3X CSV contains no data rows.');
        }

        return array(
            'aircraft_ident' => $meta['aircraft_ident'],
            'product' => $meta['product'],
            'metadata' => $meta['metadata'],
            'headers' => $headers,
            'import_profile' => $importProfile,
            'rows' => $rows,
            'row_count' => count($rows),
        );
    }

    /**
     * @param array<string,string> $row
     */
    public static function rowUtcTimestamp(array $row): ?DateTimeImmutable
    {
        $date = trim((string)($row['Date (yyyy-mm-dd)'] ?? ($row['Lcl Date'] ?? '')));
        $localTime = trim((string)($row['Time (hh:mm:ss)'] ?? ($row['Lcl Time'] ?? '')));
        $utcOffset = trim((string)($row['UTC Offset (hh:mm)'] ?? ($row['UTCOfst'] ?? '')));
        if ($date !== '' && $localTime !== '' && preg_match('/^[+-]\d{2}:\d{2}$/', $utcOffset) === 1) {
            try {
                return (new DateTimeImmutable($date . ' ' . $localTime . ' ' . $utcOffset))
                    ->setTimezone(new DateTimeZone('UTC'));
            } catch (Throwable) {
                // Fall through to the legacy UTC-time parser below.
            }
        }

        $utcTime = trim((string)($row['UTC Time (hh:mm:ss)'] ?? ($row['UTC Time'] ?? '')));
        if ($date === '' || $utcTime === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($date . ' ' . $utcTime, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param list<array<string,string>> $rows
     */
    public static function firstUtcTimestamp(array $rows): ?DateTimeImmutable
    {
        foreach ($rows as $row) {
            $dt = self::rowUtcTimestamp($row);
            if ($dt !== null) {
                return $dt;
            }
        }
        return null;
    }

    /**
     * @param list<array<string,string>> $rows
     */
    public static function lastUtcTimestamp(array $rows): ?DateTimeImmutable
    {
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            $dt = self::rowUtcTimestamp($rows[$i]);
            if ($dt !== null) {
                return $dt;
            }
        }
        return null;
    }

    /**
     * @param array<string,string> $row
     */
    public static function numericValue(array $row, string ...$keys): ?float
    {
        foreach ($keys as $key) {
            $value = trim((string)($row[$key] ?? ''));
            if ($value === '' || !is_numeric($value)) {
                continue;
            }
            return (float)$value;
        }
        return null;
    }

    /**
     * @return array{aircraft_ident: string, product: string, metadata: array<string,string>}
     */
    private static function parseMetaLine(string $line): array
    {
        $fields = self::parseCsvLine($line);
        $values = array();
        foreach ($fields as $field) {
            $field = trim((string)$field);
            if ($field === '' || $field === '#airframe_info' || !str_contains($field, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $field, 2);
            $key = trim($key);
            $value = trim($value);
            $value = trim($value, "\" \t\n\r\0\x0B");
            if ($key !== '') {
                $values[$key] = $value;
            }
        }

        $aircraft = '';
        if (isset($values['aircraft_ident'])) {
            $aircraft = trim((string)$values['aircraft_ident']);
        } elseif (isset($values['airframe_name'])) {
            $aircraft = trim((string)$values['airframe_name']);
        } elseif (preg_match('/aircraft_ident="{1,2}([^"]*)"{1,2}/', $line, $matches)) {
            $aircraft = trim((string)$matches[1]);
        } elseif (preg_match('/airframe_name="{1,2}([^"]*)"{1,2}/', $line, $matches)) {
            $aircraft = trim((string)$matches[1]);
        }
        $product = '';
        if (isset($values['product'])) {
            $product = trim((string)$values['product']);
        } elseif (preg_match('/product="{1,2}([^"]*)"{1,2}/', $line, $matches)) {
            $product = trim((string)$matches[1]);
        }
        return array(
            'aircraft_ident' => $aircraft,
            'product' => $product,
            'metadata' => $values,
        );
    }

    /**
     * @return list<string>
     */
    private static function parseCsvLine(string $line): array
    {
        $line = rtrim($line, "\r\n");
        $stream = fopen('php://memory', 'rb+');
        if ($stream === false) {
            return array();
        }
        fwrite($stream, $line);
        rewind($stream);
        $row = fgetcsv($stream, 0, ',', '"', '\\');
        fclose($stream);
        return is_array($row) ? array_map(static fn($value): string => (string)$value, $row) : array();
    }

    /**
     * @param list<string> $values
     */
    private static function isBlankRow(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string)$value) !== '') {
                return false;
            }
        }
        return true;
    }
}
