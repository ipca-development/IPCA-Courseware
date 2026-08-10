<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$path = __DIR__ . '/../ipca-cvr-unit/IPCACVRUnit/Resources/faa_ca_az_nv_airports.json';
if (!is_file($path)) {
    throw new RuntimeException('Bundled FAA regional airport catalog is missing.');
}
$catalog = json_decode((string)file_get_contents($path), true);
if (!is_array($catalog) || !is_array($catalog['airports'] ?? null)) {
    throw new RuntimeException('Bundled FAA regional airport catalog is invalid.');
}

$airportUpsert = $pdo->prepare(
    'INSERT INTO ipca_airports
     (icao_identifier, faa_identifier, full_name, city, region, state_code, country,
      latitude_deg, longitude_deg, elevation_ft, is_towered, facility_use,
      source, source_confidence, source_json, fetched_at)
     VALUES (?, ?, ?, ?, ?, ?, \'United States\', ?, ?, ?, ?, ?, \'faa_nasr\', 1.000, ?, ?)
     ON DUPLICATE KEY UPDATE
       faa_identifier = VALUES(faa_identifier),
       full_name = VALUES(full_name),
       city = VALUES(city),
       region = VALUES(region),
       state_code = VALUES(state_code),
       latitude_deg = VALUES(latitude_deg),
       longitude_deg = VALUES(longitude_deg),
       elevation_ft = VALUES(elevation_ft),
       is_towered = VALUES(is_towered),
       facility_use = VALUES(facility_use),
       source = VALUES(source),
       source_confidence = VALUES(source_confidence),
       source_json = VALUES(source_json),
       fetched_at = VALUES(fetched_at)'
);
$airportId = $pdo->prepare('SELECT id FROM ipca_airports WHERE icao_identifier = ? LIMIT 1');
$runwayUpsert = $pdo->prepare(
    'INSERT INTO ipca_airport_runways
     (airport_id, runway_identifier, length_ft, width_ft, surface, source_json)
     VALUES (?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
       length_ft = VALUES(length_ft),
       width_ft = VALUES(width_ft),
       surface = VALUES(surface),
       source_json = VALUES(source_json)'
);
$runwayId = $pdo->prepare(
    'SELECT id FROM ipca_airport_runways WHERE airport_id = ? AND runway_identifier = ? LIMIT 1'
);
$endUpsert = $pdo->prepare(
    'INSERT INTO ipca_airport_runway_ends
     (runway_id, runway_end_identifier, latitude_deg, longitude_deg, elevation_ft,
      true_heading_deg, source_json)
     VALUES (?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
       latitude_deg = VALUES(latitude_deg),
       longitude_deg = VALUES(longitude_deg),
       elevation_ft = VALUES(elevation_ft),
       true_heading_deg = VALUES(true_heading_deg),
       source_json = VALUES(source_json)'
);

$effectiveDate = trim((string)($catalog['effective_date'] ?? ''));
$fetchedAt = $effectiveDate !== '' ? $effectiveDate . ' 00:00:00' : gmdate('Y-m-d H:i:s');
$airportCount = 0;
$runwayCount = 0;
$endCount = 0;

$pdo->beginTransaction();
try {
    foreach ($catalog['airports'] as $airport) {
        if (!is_array($airport)) {
            continue;
        }
        $identifier = strtoupper(trim((string)($airport['identifier'] ?? '')));
        if ($identifier === '') {
            continue;
        }
        $airportUpsert->execute(array(
            $identifier,
            ($airport['faa_identifier'] ?? null) ?: null,
            (string)($airport['name'] ?? $identifier),
            ($airport['city'] ?? null) ?: null,
            ($airport['state'] ?? null) ?: null,
            ($airport['state'] ?? null) ?: null,
            (float)($airport['latitude_deg'] ?? 0),
            (float)($airport['longitude_deg'] ?? 0),
            isset($airport['elevation_ft']) ? (int)$airport['elevation_ft'] : null,
            !empty($airport['is_towered']) ? 1 : 0,
            ($airport['facility_use'] ?? null) ?: null,
            json_encode($airport, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $fetchedAt,
        ));
        $airportId->execute(array($identifier));
        $databaseAirportId = (int)$airportId->fetchColumn();
        if ($databaseAirportId <= 0) {
            throw new RuntimeException('Could not resolve seeded airport ' . $identifier . '.');
        }
        $airportCount++;

        foreach (($airport['runways'] ?? array()) as $runway) {
            if (!is_array($runway)) {
                continue;
            }
            $runwayIdentifier = trim((string)($runway['identifier'] ?? ''));
            if ($runwayIdentifier === '') {
                continue;
            }
            $runwayUpsert->execute(array(
                $databaseAirportId,
                $runwayIdentifier,
                isset($runway['length_ft']) ? (int)$runway['length_ft'] : null,
                isset($runway['width_ft']) ? (int)$runway['width_ft'] : null,
                ($runway['surface'] ?? null) ?: null,
                json_encode($runway, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ));
            $runwayId->execute(array($databaseAirportId, $runwayIdentifier));
            $databaseRunwayId = (int)$runwayId->fetchColumn();
            if ($databaseRunwayId <= 0) {
                throw new RuntimeException('Could not resolve seeded runway ' . $identifier . ' ' . $runwayIdentifier . '.');
            }
            $runwayCount++;

            foreach (($runway['ends'] ?? array()) as $end) {
                if (!is_array($end) || !isset($end['latitude_deg'], $end['longitude_deg'])) {
                    continue;
                }
                $endIdentifier = trim((string)($end['identifier'] ?? ''));
                if ($endIdentifier === '') {
                    continue;
                }
                $endUpsert->execute(array(
                    $databaseRunwayId,
                    $endIdentifier,
                    (float)$end['latitude_deg'],
                    (float)$end['longitude_deg'],
                    isset($end['elevation_ft']) ? (int)$end['elevation_ft'] : null,
                    isset($end['true_heading_deg']) ? (float)$end['true_heading_deg'] : null,
                    json_encode($end, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ));
                $endCount++;
            }
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

fwrite(STDOUT, sprintf(
    "Seeded %d FAA airports, %d runways, and %d runway ends (effective %s).\n",
    $airportCount,
    $runwayCount,
    $endCount,
    $effectiveDate
));
