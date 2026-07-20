<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = array(
    'archive' => $root . '/src/AdsbTrafficArchiveService.php',
    'client' => $root . '/src/OpenSkyTrinoClient.php',
    'backfill' => $root . '/src/OpenSkyTrafficArchiveBackfillService.php',
    'cli' => $root . '/scripts/cockpit_adsb_opensky_backfill.php',
    'reconstruction' => $root . '/src/CockpitReconstructionService.php',
);

foreach ($files as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing {$name}: {$path}\n");
        exit(1);
    }
    $files[$name] = (string)file_get_contents($path);
}

$checks = array(
    'live ADS-B archive writer populates canonical phase 1 fields when present' =>
        str_contains($files['archive'], "private const SOURCE_MODE_LIVE = 'LIVE'")
        && str_contains($files['archive'], "'source_mode' => self::SOURCE_MODE_LIVE")
        && str_contains($files['archive'], "'baro_altitude_ft' => \$baroAltitudeFt")
        && str_contains($files['archive'], "'geo_altitude_ft' => \$geoAltitudeFt")
        && str_contains($files['archive'], "'observation_fingerprint' => \$sampleHash"),
    'archive writer remains schema-compatible and preserves legacy sample_hash uniqueness' =>
        str_contains($files['archive'], "INSERT IGNORE INTO ipca_adsb_traffic_samples")
        && str_contains($files['archive'], "columnsForTable('ipca_adsb_traffic_samples')")
        && str_contains($files['archive'], "'sample_hash'")
        && !preg_match('/ALTER\s+TABLE|DROP\s+TABLE|TRUNCATE|DELETE\s+FROM/i', $files['archive']),
    'OpenSky Trino client uses configured env vars and Trino statement protocol' =>
        str_contains($files['client'], 'CW_OPENSKY_TRINO_USERNAME')
        && str_contains($files['client'], 'CW_OPENSKY_TRINO_PASSWORD')
        && str_contains($files['client'], '/v1/statement')
        && str_contains($files['client'], 'nextUri')
        && str_contains($files['client'], 'X-Trino-Catalog'),
    'OpenSky backfill writes into existing archive tables only' =>
        str_contains($files['backfill'], 'ipca_adsb_traffic_samples')
        && str_contains($files['backfill'], 'ipca_adsb_raw_payloads')
        && str_contains($files['backfill'], 'ipca_adsb_coverage_jobs')
        && str_contains($files['backfill'], 'ipca_adsb_coverage_tiles')
        && !preg_match('/CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE|TRUNCATE/i', $files['backfill']),
    'OpenSky rows are stored as historical with conservative quality flags' =>
        str_contains($files['backfill'], "private const SOURCE_MODE = 'HISTORICAL'")
        && str_contains($files['backfill'], "'position_source' => 'opensky_state_vectors_data4'")
        && str_contains($files['backfill'], "'provider_record_identity' => 'derived_from_state_vector'")
        && str_contains($files['backfill'], "'normalization_version' => 'opensky_state_vectors_data4_v1'"),
    'OpenSky query uses state vector time and hour partition filters' =>
        str_contains($files['backfill'], 'time >= ')
        && str_contains($files['backfill'], 'time < ')
        && str_contains($files['backfill'], 'hour >= ')
        && str_contains($files['backfill'], 'hour <= ')
        && str_contains($files['backfill'], 'state_vectors_data4'),
    'operator CLI supports status, dry-run plan, and recording backfill' =>
        str_contains($files['cli'], "status")
        && str_contains($files['cli'], "plan")
        && str_contains($files['cli'], "run-recording")
        && str_contains($files['cli'], "backfillRecording"),
    'ordinary replay remains local-only after phase 2 ingestion changes' =>
        !str_contains($files['reconstruction'], 'OpenSky')
        && !str_contains($files['reconstruction'], 'tv_adsb_request(')
        && !str_contains($files['reconstruction'], 'CockpitAdsbEnrichmentService')
        && str_contains($files['reconstruction'], 'new LocalTrafficArchiveRepository'),
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
