<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = array(
    'reconstruction' => $root . '/src/CockpitReconstructionService.php',
    'repository' => $root . '/src/LocalTrafficArchiveRepository.php',
    'migration' => $root . '/scripts/sql/2026_07_19_adsb_archive_phase1_evolution.sql',
    'compare' => $root . '/scripts/cockpit_adsb_archive_phase1_compare.php',
);

foreach ($files as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing {$name}: {$path}\n");
        exit(1);
    }
    $files[$name] = (string)file_get_contents($path);
}

$archiveMethod = extractMethod($files['reconstruction'], 'archiveTrafficForRecording');

$checks = array(
    'phase 1 migration is additive and idempotent' =>
        str_contains($files['migration'], 'CREATE TABLE IF NOT EXISTS ipca_adsb_geographic_definitions')
        && str_contains($files['migration'], 'ipca_adsb_add_column_if_missing')
        && !preg_match('/\bDROP\s+TABLE\b/i', $files['migration'])
        && !preg_match('/\bTRUNCATE\b/i', $files['migration'])
        && !preg_match('/\bDELETE\s+FROM\b/i', $files['migration']),
    'existing archive rows keep sample hashes and conservative unknown source mode' =>
        str_contains($files['migration'], "source_mode', 'VARCHAR(32) NOT NULL DEFAULT ''UNKNOWN''")
        && str_contains($files['migration'], 'observation_fingerprint = sample_hash')
        && str_contains($files['migration'], "'source_mode', 'unknown_existing_row'")
        && !str_contains($files['migration'], "SET source_mode = 'LIVE'")
        && !str_contains($files['migration'], "SET source_mode = 'HISTORICAL'"),
    'repository reads existing ipca_adsb_traffic_samples only' =>
        str_contains($files['repository'], 'FROM ipca_adsb_traffic_samples')
        && str_contains($files['repository'], "COALESCE(NULLIF(icao24, ''), aircraft_hex)")
        && str_contains($files['repository'], "foreach (array('baro_altitude_ft', 'altitude_ft')")
        && !str_contains($files['repository'], 'tv_adsb_request(')
        && !str_contains($files['repository'], 'AdsbHistoricalCorridorService')
        && !str_contains($files['repository'], 'fetchHistoricalTrace'),
    'ordinary replay uses local repository and does not schedule or fetch providers' =>
        str_contains($archiveMethod, 'new LocalTrafficArchiveRepository')
        && !str_contains($archiveMethod, 'new AdsbTrafficArchiveService')
        && !str_contains($archiveMethod, 'schedulePathCoverage')
        && !str_contains($archiveMethod, 'scheduleKtrmCoverage')
        && !str_contains($archiveMethod, 'CockpitAdsbEnrichmentService')
        && !str_contains($archiveMethod, 'enrich('),
    'replay metadata no longer calls external enrichment fallback on empty traffic' =>
        !str_contains($files['reconstruction'], '$trafficRows = $this->ensureAdsbTrafficForReplay($recording);'),
    'replay payload shape is preserved' =>
        str_contains($files['reconstruction'], "'traffic' => \$trafficIsArchive ? \$trafficRows")
        && str_contains($files['reconstruction'], "'trafficAircraft' => \$trafficAircraft")
        && str_contains($files['repository'], "'hex' =>")
        && str_contains($files['repository'], "'utc' =>")
        && str_contains($files['repository'], "'lat' =>")
        && str_contains($files['repository'], "'lon' =>"),
    'before-after comparison command exists' =>
        str_contains($files['compare'], 'AdsbTrafficArchiveService')
        && str_contains($files['compare'], 'LocalTrafficArchiveRepository')
        && str_contains($files['compare'], 'legacy_samples_missing_from_repository'),
);

$failed = array();
foreach ($checks as $name => $pass) {
    echo ($pass ? 'PASS' : 'FAIL') . " {$name}\n";
    if (!$pass) {
        $failed[] = $name;
    }
}

if ($failed !== array()) {
    fwrite(STDERR, "\nFailed checks:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

function extractMethod(string $source, string $method): string
{
    $needle = 'function ' . $method . '(';
    $pos = strpos($source, $needle);
    if ($pos === false) {
        return '';
    }
    $brace = strpos($source, '{', $pos);
    if ($brace === false) {
        return '';
    }
    $depth = 0;
    $len = strlen($source);
    for ($i = $brace; $i < $len; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $pos, $i - $pos + 1);
            }
        }
    }
    return '';
}
