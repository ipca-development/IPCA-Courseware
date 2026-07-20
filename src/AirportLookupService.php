<?php
declare(strict_types=1);

require_once __DIR__ . '/CockpitRecorderService.php';
require_once __DIR__ . '/tv_adsb_status.php';

final class AirportLookupService
{
    private const OURAIRPORTS_URL = 'https://davidmegginson.github.io/ourairports-data/airports.csv';
    private const CACHE_TTL_SECONDS = 604800;

    /**
     * @return list<array<string,mixed>>
     */
    public function search(string $query, int $limit = 20): array
    {
        $query = strtoupper(trim($query));
        $query = preg_replace('/[^A-Z0-9\s\-]/', '', $query) ?? '';
        if (strlen($query) < 2) {
            return array();
        }
        $limit = max(1, min(50, $limit));
        $rows = $this->airports();
        $matches = array();
        foreach ($rows as $airport) {
            $score = $this->scoreAirport($airport, $query);
            if ($score <= 0) {
                continue;
            }
            $airport['score'] = $score;
            $matches[] = $airport;
        }
        usort($matches, static fn(array $a, array $b): int => ((int)$b['score'] <=> (int)$a['score']) ?: strcmp((string)$a['ident'], (string)$b['ident']));
        return array_slice($matches, 0, $limit);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function airports(): array
    {
        $csv = $this->cachedOurAirportsCsv();
        if ($csv !== '') {
            $rows = $this->parseOurAirportsCsv($csv);
            if ($rows !== array()) {
                return $rows;
            }
        }
        return $this->fallbackAirports();
    }

    private function cachedOurAirportsCsv(): string
    {
        $path = $this->cachePath();
        if (is_file($path) && filemtime($path) !== false && (time() - (int)filemtime($path)) < self::CACHE_TTL_SECONDS) {
            $cached = file_get_contents($path);
            return is_string($cached) ? $cached : '';
        }
        $csv = $this->fetchOurAirportsCsv();
        if ($csv !== '') {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            @file_put_contents($path, $csv);
            return $csv;
        }
        if (is_file($path)) {
            $cached = file_get_contents($path);
            return is_string($cached) ? $cached : '';
        }
        return '';
    }

    private function fetchOurAirportsCsv(): string
    {
        if (!function_exists('curl_init')) {
            return '';
        }
        $ch = curl_init(self::OURAIRPORTS_URL);
        if ($ch === false) {
            return '';
        }
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_USERAGENT => 'IPCA-Courseware-AirportLookup/1.0',
        ));
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return is_string($body) && $status >= 200 && $status < 300 ? $body : '';
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function parseOurAirportsCsv(string $csv): array
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return array();
        }
        fwrite($handle, $csv);
        rewind($handle);
        $header = fgetcsv($handle);
        if (!is_array($header)) {
            fclose($handle);
            return array();
        }
        $map = array_flip($header);
        $rows = array();
        while (($row = fgetcsv($handle)) !== false) {
            if (!is_array($row)) {
                continue;
            }
            $ident = strtoupper(trim((string)($row[$map['ident'] ?? -1] ?? '')));
            $icao = strtoupper(trim((string)($row[$map['gps_code'] ?? -1] ?? '')));
            $iata = strtoupper(trim((string)($row[$map['iata_code'] ?? -1] ?? '')));
            $name = trim((string)($row[$map['name'] ?? -1] ?? ''));
            $type = trim((string)($row[$map['type'] ?? -1] ?? ''));
            $lat = $row[$map['latitude_deg'] ?? -1] ?? null;
            $lon = $row[$map['longitude_deg'] ?? -1] ?? null;
            if (!is_numeric($lat) || !is_numeric($lon) || $name === '') {
                continue;
            }
            if (in_array($type, array('closed', 'heliport', 'seaplane_base', 'balloonport'), true)) {
                continue;
            }
            $municipality = trim((string)($row[$map['municipality'] ?? -1] ?? ''));
            $country = strtoupper(trim((string)($row[$map['iso_country'] ?? -1] ?? '')));
            $displayIdent = $icao !== '' ? $icao : ($iata !== '' ? $iata : $ident);
            if ($displayIdent === '') {
                continue;
            }
            $rows[] = array(
                'ident' => $displayIdent,
                'icao' => $icao,
                'iata' => $iata,
                'name' => $name,
                'municipality' => $municipality,
                'country' => $country,
                'type' => $type,
                'lat' => (float)$lat,
                'lon' => (float)$lon,
                'source' => 'ourairports',
            );
        }
        fclose($handle);
        return $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fallbackAirports(): array
    {
        $rows = array();
        foreach (tv_adsb_airports() as $icao => $airport) {
            $rows[] = array(
                'ident' => (string)$icao,
                'icao' => (string)$icao,
                'iata' => '',
                'name' => (string)($airport['name'] ?? $icao),
                'municipality' => '',
                'country' => '',
                'type' => 'small_airport',
                'lat' => (float)($airport['lat'] ?? 0.0),
                'lon' => (float)($airport['lon'] ?? 0.0),
                'source' => 'local_fallback',
            );
        }
        return $rows;
    }

    private function scoreAirport(array $airport, string $query): int
    {
        $ident = strtoupper((string)($airport['ident'] ?? ''));
        $icao = strtoupper((string)($airport['icao'] ?? ''));
        $iata = strtoupper((string)($airport['iata'] ?? ''));
        $name = strtoupper((string)($airport['name'] ?? ''));
        $municipality = strtoupper((string)($airport['municipality'] ?? ''));
        if ($ident === $query || $icao === $query || $iata === $query) {
            return 1000;
        }
        if (str_starts_with($ident, $query) || str_starts_with($icao, $query) || str_starts_with($iata, $query)) {
            return 800;
        }
        if (str_contains($name, $query)) {
            return 500;
        }
        if (str_contains($municipality, $query)) {
            return 400;
        }
        return 0;
    }

    private function cachePath(): string
    {
        return CockpitRecorderService::projectRoot() . '/storage/adsb_archive/airport_lookup/ourairports_airports.csv';
    }
}
