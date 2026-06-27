<?php
declare(strict_types=1);

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
     *   headers: list<string>,
     *   rows: list<array<string,string>>,
     *   row_count: int
     * }
     */
    public static function parseFile(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('G3X CSV file is missing.');
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not open G3X CSV file.');
        }

        try {
            $metaLine = fgets($handle);
            if ($metaLine === false) {
                throw new RuntimeException('G3X CSV file is empty.');
            }
            $meta = self::parseMetaLine($metaLine);

            $headerLine = fgets($handle);
            if ($headerLine === false) {
                throw new RuntimeException('G3X CSV header row is missing.');
            }
            $headers = self::parseCsvLine($headerLine);
            $headers = array_map(static function (string $header): string {
                return ltrim(trim($header), "#");
            }, $headers);
            if (!$headers) {
                throw new RuntimeException('G3X CSV header row is invalid.');
            }

            // Skip short-header alias row.
            fgets($handle);

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
            'headers' => $headers,
            'rows' => $rows,
            'row_count' => count($rows),
        );
    }

    /**
     * @param array<string,string> $row
     */
    public static function rowUtcTimestamp(array $row): ?DateTimeImmutable
    {
        $date = trim((string)($row['Date (yyyy-mm-dd)'] ?? ''));
        $utcTime = trim((string)($row['UTC Time (hh:mm:ss)'] ?? ''));
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
     * @return array{aircraft_ident: string, product: string}
     */
    private static function parseMetaLine(string $line): array
    {
        $aircraft = '';
        if (preg_match('/aircraft_ident="([^"]*)"/', $line, $matches)) {
            $aircraft = trim((string)$matches[1]);
        }
        $product = '';
        if (preg_match('/product="([^"]*)"/', $line, $matches)) {
            $product = trim((string)$matches[1]);
        }
        if ($aircraft === '' && stripos($line, '#airframe_info') === false) {
            throw new RuntimeException('Unrecognized G3X CSV format (missing #airframe_info).');
        }
        return array(
            'aircraft_ident' => $aircraft,
            'product' => $product,
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
