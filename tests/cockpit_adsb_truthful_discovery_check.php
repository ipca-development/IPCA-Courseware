<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = array(
    'service' => $root . '/src/CockpitAdsbEnrichmentService.php',
    'adapter' => $root . '/src/AdsbHistoricalTrafficDiscoveryProvider.php',
    'archive' => $root . '/src/AdsbTrafficArchiveService.php',
    'corridor' => $root . '/src/AdsbHistoricalCorridorService.php',
    'replay' => $root . '/public/admin/cockpit_recorder_replay.php',
    'migration' => $root . '/scripts/sql/2026_07_18_cockpit_recorder_adsb_truthful_discovery.sql',
);

foreach ($files as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing {$name}: {$path}\n");
        exit(1);
    }
    $files[$name] = (string)file_get_contents($path);
}

$checks = array(
    'speculative corridor endpoint removed from service/archive/corridor' =>
        !str_contains($files['service'], '/historical/corridor')
        && !str_contains($files['archive'], '/historical/corridor')
        && !str_contains($files['corridor'], '/historical/corridor'),
    'ADSB provider requests are not suppressed in enrichment service' =>
        !str_contains($files['service'], '@file_get_contents'),
    'fleet supplement is explicitly configuration gated' =>
        str_contains($files['service'], 'CW_ADSB_ENABLE_LOCAL_FLEET_SUPPLEMENT')
        && str_contains($files['service'], 'local_fleet_supplement'),
    'fleet is not labeled as ADS-B source_type' =>
        !str_contains($files['service'], "'source_type' => 'fleet'")
        && !str_contains($files['service'], '"source_type" => "fleet"'),
    'provider capability reports unsupported by default' =>
        str_contains($files['adapter'], 'historical_geographical_discovery_supported')
        && str_contains($files['adapter'], 'unsupported_capability'),
    'candidate provenance migration exists' =>
        str_contains($files['migration'], 'discovery_source')
        && str_contains($files['migration'], 'ipca_cockpit_adsb_discovery_requests'),
    'metadata registry migration exists' =>
        str_contains($files['migration'], 'ipca_adsb_aircraft_metadata_registry'),
    'trace request diagnostics migration exists' =>
        str_contains($files['migration'], 'ipca_cockpit_adsb_trace_requests'),
    'ground traffic uses tolerant gap in backend and frontend' =>
        str_contains($files['service'], 'TRAFFIC_GROUND_HARD_SAMPLE_GAP_S')
        && str_contains($files['replay'], 'const maxGap = groundPair ? 300 : 30'),
    'stale ground positions are not automatically rejected' =>
        str_contains($files['service'], "if (!empty(\$sample['stale']) && !\$isGround)")
        && str_contains($files['replay'], 'if (!groundPair && (before.stale || after.stale)) return null;'),
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
