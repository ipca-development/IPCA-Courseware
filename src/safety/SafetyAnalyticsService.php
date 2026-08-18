<?php
declare(strict_types=1);

require_once __DIR__ . '/SafetySupport.php';
require_once __DIR__ . '/SafetyAccessService.php';
require_once __DIR__ . '/SafetyAuditEventService.php';

/**
 * Computes assurance measures from immutable exposure and cross-domain snapshots.
 * Raw operational tables are never joined speculatively: a human-approved,
 * organization-scoped link is required before a cross-domain pair is analyzed.
 */
final class SafetyAnalyticsService
{
    private const NUMERATORS = array(
        'reports' => array('table' => 'ipca_safety_reports', 'date' => 'submitted_at_utc'),
        'occurrences' => array('table' => 'ipca_safety_occurrences', 'date' => 'created_at_utc'),
        'hazards' => array('table' => 'ipca_safety_hazards', 'date' => 'created_at_utc'),
        'actions' => array('table' => 'ipca_safety_actions', 'date' => 'created_at_utc'),
    );

    public function __construct(
        private PDO $pdo,
        private SafetyAccessService $access,
        private SafetyAuditEventService $events
    ) {
    }

    /** @param array<string,mixed> $session */
    public function summary(array $session, string $fromUtc, string $toUtc): array
    {
        $this->access->requirePermission($session, 'analytics.read');
        $this->assertPeriod($fromUtc, $toUtc);
        $org = SafetySupport::organizationId($session);
        $status = $this->pdo->prepare(
            'SELECT status, COUNT(*) AS total FROM ipca_safety_reports
             WHERE organization_id = ? AND submitted_at_utc >= ? AND submitted_at_utc < ?
             GROUP BY status ORDER BY status'
        );
        $status->execute(array($org, $fromUtc, $toUtc));
        $byStatus = $status->fetchAll(PDO::FETCH_ASSOC);
        $numerator = array_sum(array_map(
            static fn(array $row): int => (int)$row['total'],
            $byStatus
        ));
        $exposure = $this->pdo->prepare(
            'SELECT exposure_unit, SUM(exposure_value) AS denominator
             FROM ipca_safety_exposure_snapshots
             WHERE organization_id = ? AND period_start_utc >= ? AND period_end_utc <= ?
             GROUP BY exposure_unit ORDER BY exposure_unit'
        );
        $exposure->execute(array($org, $fromUtc, $toUtc));
        $rates = array();
        foreach ($exposure->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $denominator = (float)$row['denominator'];
            if ($denominator <= 0.0) {
                continue;
            }
            $rates[] = array(
                'exposure_unit' => (string)$row['exposure_unit'],
                'numerator' => $numerator,
                'denominator' => $denominator,
                'rate_per_1000' => round(($numerator / $denominator) * 1000, 6),
            );
        }
        return array(
            'by_status' => $byStatus,
            'exposure_normalized_rates' => $rates,
            'from_utc' => $fromUtc,
            'to_utc' => $toUtc,
            'interpretation' => 'Rates describe reporting frequency per exposure; they do not measure blame or individual performance.',
        );
    }

    /**
     * @param array<string,mixed> $session
     * @param array<string,mixed> $dimensions
     * @param array<string,mixed> $provenance
     */
    public function recordExposure(
        array $session,
        string $fromUtc,
        string $toUtc,
        string $unit,
        float $value,
        string $sourceSystem,
        string $sourceReference,
        array $dimensions,
        array $provenance
    ): string {
        $this->access->requirePermission($session, 'analytics.manage');
        $this->assertPeriod($fromUtc, $toUtc);
        if (!is_finite($value) || $value <= 0.0) {
            throw new SafetyException('validation_error', 'Exposure value must be greater than zero.', 400);
        }
        $unit = SafetySupport::cleanText(strtolower($unit), 48, 'exposure_unit');
        if (!in_array($unit, array('flight_hours', 'flight_cycles', 'sectors', 'training_hours', 'operations'), true)) {
            throw new SafetyException('validation_error', 'Unsupported exposure unit.', 400);
        }
        $this->assertDeidentifiedMetadata($dimensions, 'dimensions');
        $this->assertDeidentifiedMetadata($provenance, 'provenance');
        $org = SafetySupport::organizationId($session);
        $uuid = SafetySupport::uuid();
        $sourceReference = SafetySupport::cleanText($sourceReference, 255, 'source_reference');
        $this->pdo->prepare(
            'INSERT INTO ipca_safety_exposure_snapshots
             (organization_id, snapshot_uuid, period_start_utc, period_end_utc, exposure_unit,
              exposure_value, source_system, source_reference, source_digest, dimensions_json,
              provenance_json, recorded_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(array(
            $org, $uuid, $fromUtc, $toUtc, $unit, $value,
            SafetySupport::cleanText($sourceSystem, 96, 'source_system'),
            $sourceReference,
            SafetySupport::digest(SafetySupport::json(array($sourceSystem, $sourceReference, $fromUtc, $toUtc, $unit, $value))),
            SafetySupport::json($dimensions), SafetySupport::json($provenance), (int)$session['user']['id'],
        ));
        $id = (int)$this->pdo->lastInsertId();
        $this->events->append($org, 'exposure_snapshot', $id, 'analytics.exposure_recorded',
            'user', (int)$session['user']['id'], null, array('unit' => $unit, 'source_digest_only' => true));
        return $uuid;
    }

    /**
     * @param array<string,mixed> $session
     * @param array<string,mixed> $definition
     */
    public function createSpi(
        array $session,
        string $code,
        string $name,
        array $definition,
        ?float $target = null,
        ?float $warning = null
    ): string {
        $this->access->requirePermission($session, 'analytics.manage');
        $numerator = strtolower((string)($definition['numerator'] ?? ''));
        $unit = strtolower((string)($definition['denominator_exposure_unit'] ?? ''));
        $scale = (float)($definition['scale'] ?? 1000);
        if (!isset(self::NUMERATORS[$numerator])
            || !in_array($unit, array('flight_hours', 'flight_cycles', 'sectors', 'training_hours', 'operations'), true)
            || !is_finite($scale) || $scale <= 0.0) {
            throw new SafetyException('validation_error', 'SPI requires an approved numerator, exposure unit, and positive scale.', 400);
        }
        $canonical = array(
            'numerator' => $numerator,
            'denominator' => 'exposure',
            'denominator_exposure_unit' => $unit,
            'scale' => $scale,
            'method' => 'count_per_exposure',
        );
        $org = SafetySupport::organizationId($session);
        $uuid = SafetySupport::uuid();
        $this->pdo->prepare(
            'INSERT INTO ipca_safety_spis
             (organization_id, spi_uuid, code, name, definition_json, target_value, warning_value)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute(array(
            $org, $uuid, SafetySupport::cleanText(strtoupper($code), 64, 'code'),
            SafetySupport::cleanText($name, 180, 'name'), SafetySupport::json($canonical), $target, $warning,
        ));
        return $uuid;
    }

    /** @param array<string,mixed> $session */
    public function computeSpi(array $session, string $spiUuid, string $fromUtc, string $toUtc): array
    {
        $this->access->requirePermission($session, 'analytics.manage');
        $this->assertPeriod($fromUtc, $toUtc);
        $org = SafetySupport::organizationId($session);
        $stmt = $this->pdo->prepare(
            'SELECT id, code, name, definition_json, target_value, warning_value
             FROM ipca_safety_spis WHERE organization_id = ? AND spi_uuid = ? AND active = 1'
        );
        $stmt->execute(array($org, strtolower(trim($spiUuid))));
        $spi = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($spi)) {
            throw new SafetyException('not_found', 'Active safety performance indicator not found.', 404);
        }
        $definition = json_decode((string)$spi['definition_json'], true);
        $source = self::NUMERATORS[(string)($definition['numerator'] ?? '')] ?? null;
        if ($source === null) {
            throw new SafetyException('invalid_spi_definition', 'SPI definition is not supported.', 409);
        }
        $count = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ' . $source['table'] .
            ' WHERE organization_id = ? AND ' . $source['date'] . ' >= ? AND ' . $source['date'] . ' < ?'
        );
        $count->execute(array($org, $fromUtc, $toUtc));
        $numerator = (float)$count->fetchColumn();
        $denom = $this->pdo->prepare(
            'SELECT COALESCE(SUM(exposure_value), 0) FROM ipca_safety_exposure_snapshots
             WHERE organization_id = ? AND exposure_unit = ?
               AND period_start_utc >= ? AND period_end_utc <= ?'
        );
        $denom->execute(array($org, (string)$definition['denominator_exposure_unit'], $fromUtc, $toUtc));
        $denominator = (float)$denom->fetchColumn();
        if ($denominator <= 0.0) {
            throw new SafetyException('exposure_required', 'No positive exposure denominator covers this SPI period.', 409);
        }
        $value = ($numerator / $denominator) * (float)$definition['scale'];
        $this->pdo->prepare(
            'INSERT INTO ipca_safety_spi_values
             (organization_id, spi_id, period_start_utc, period_end_utc, value_decimal, numerator, denominator)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE value_decimal = VALUES(value_decimal), numerator = VALUES(numerator),
               denominator = VALUES(denominator), computed_at_utc = CURRENT_TIMESTAMP(3)'
        )->execute(array($org, $spi['id'], $fromUtc, $toUtc, $value, $numerator, $denominator));
        return array(
            'spi_uuid' => $spiUuid,
            'code' => $spi['code'],
            'name' => $spi['name'],
            'value' => round($value, 6),
            'numerator' => $numerator,
            'denominator' => $denominator,
            'exposure_unit' => $definition['denominator_exposure_unit'],
            'scale' => $definition['scale'],
            'target_value' => $spi['target_value'] === null ? null : (float)$spi['target_value'],
            'warning_value' => $spi['warning_value'] === null ? null : (float)$spi['warning_value'],
        );
    }

    /** @param array<string,mixed> $session */
    public function trendCandidates(array $session, string $spiUuid, int $periods = 6): array
    {
        $this->access->requirePermission($session, 'analytics.read');
        $periods = max(3, min(24, $periods));
        $stmt = $this->pdo->prepare(
            'SELECT v.value_decimal, v.period_start_utc, v.period_end_utc
             FROM ipca_safety_spi_values v
             INNER JOIN ipca_safety_spis s ON s.id = v.spi_id
             WHERE v.organization_id = ? AND s.spi_uuid = ?
             ORDER BY v.period_end_utc DESC LIMIT ' . $periods
        );
        $stmt->execute(array(SafetySupport::organizationId($session), strtolower(trim($spiUuid))));
        $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
        if (count($rows) < 3) {
            return array('candidate' => false, 'reason' => 'At least three computed periods are required.', 'points' => $rows);
        }
        $values = array_map(static fn(array $row): float => (float)$row['value_decimal'], $rows);
        $slope = $this->linearSlope($values);
        $mean = array_sum($values) / count($values);
        $relative = abs($mean) > 0.000001 ? $slope / abs($mean) : 0.0;
        return array(
            'candidate' => abs($relative) >= 0.05,
            'direction' => $slope > 0 ? 'increasing' : ($slope < 0 ? 'decreasing' : 'stable'),
            'slope_per_period' => round($slope, 6),
            'relative_slope' => round($relative, 6),
            'points' => $rows,
            'notice' => 'This is a statistical screening candidate, not a causal finding or risk-acceptance decision.',
        );
    }

    /**
     * @param array<string,mixed> $session
     * @param array<string,float|int> $metrics
     * @param array<string,mixed> $provenance
     */
    public function recordCrossDomainSnapshot(
        array $session,
        string $domain,
        string $subjectType,
        string $subjectReference,
        string $capturedAtUtc,
        array $metrics,
        array $provenance
    ): string {
        $this->access->requirePermission($session, 'correlation.manage');
        if ($metrics === array()) {
            throw new SafetyException('validation_error', 'At least one numeric metric is required.', 400);
        }
        foreach ($metrics as $key => $value) {
            if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', (string)$key) || !is_numeric($value) || !is_finite((float)$value)) {
                throw new SafetyException('validation_error', 'Cross-domain metrics must use safe codes and finite numeric values.', 400);
            }
        }
        $this->assertDeidentifiedMetadata($provenance, 'provenance');
        $org = SafetySupport::organizationId($session);
        $uuid = SafetySupport::uuid();
        $referenceDigest = SafetySupport::analyticsReferenceHash(
            $org,
            SafetySupport::cleanText($subjectReference, 512, 'subject_reference')
        );
        $this->pdo->prepare(
            'INSERT INTO ipca_safety_cross_domain_snapshots
             (organization_id, snapshot_uuid, domain_code, subject_type, subject_reference_digest,
              captured_at_utc, metrics_json, metrics_digest, provenance_json, recorded_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(array(
            $org, $uuid, SafetySupport::cleanText(strtolower($domain), 48, 'domain'),
            SafetySupport::cleanText(strtolower($subjectType), 48, 'subject_type'), $referenceDigest,
            $capturedAtUtc, SafetySupport::json($metrics), SafetySupport::digest(SafetySupport::json($metrics)),
            SafetySupport::json($provenance), (int)$session['user']['id'],
        ));
        return $uuid;
    }

    /** @param array<string,mixed> $session */
    public function linkCrossDomainSnapshot(
        array $session,
        string $safetySubjectType,
        int $safetySubjectId,
        string $snapshotUuid,
        string $relationship,
        string $rationale
    ): string {
        $this->access->requirePermission($session, 'correlation.manage');
        $tables = array(
            'report' => 'ipca_safety_reports',
            'hazard' => 'ipca_safety_hazards',
            'occurrence' => 'ipca_safety_occurrences',
            'action' => 'ipca_safety_actions',
        );
        $table = $tables[$safetySubjectType] ?? null;
        if ($table === null || $safetySubjectId < 1) {
            throw new SafetyException('validation_error', 'Unsupported safety subject.', 400);
        }
        $org = SafetySupport::organizationId($session);
        $subject = $this->pdo->prepare('SELECT id FROM ' . $table . ' WHERE organization_id = ? AND id = ?');
        $subject->execute(array($org, $safetySubjectId));
        $snapshot = $this->pdo->prepare(
            'SELECT id FROM ipca_safety_cross_domain_snapshots WHERE organization_id = ? AND snapshot_uuid = ?'
        );
        $snapshot->execute(array($org, strtolower(trim($snapshotUuid))));
        $snapshotId = (int)$snapshot->fetchColumn();
        if (!$subject->fetchColumn() || $snapshotId < 1) {
            throw new SafetyException('not_found', 'Safety subject or cross-domain snapshot not found.', 404);
        }
        $uuid = SafetySupport::uuid();
        $this->pdo->prepare(
            'INSERT INTO ipca_safety_cross_domain_links
             (organization_id, link_uuid, safety_subject_type, safety_subject_id, snapshot_id,
              relationship_type, link_rationale, approved_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(array(
            $org, $uuid, $safetySubjectType, $safetySubjectId, $snapshotId,
            SafetySupport::cleanText(strtolower($relationship), 48, 'relationship'),
            SafetySupport::cleanText($rationale, 4000, 'rationale'), (int)$session['user']['id'],
        ));
        return $uuid;
    }

    /** @param array<string,mixed> $session */
    public function correlationCandidate(array $session, string $metricCode): array
    {
        $this->access->requirePermission($session, 'analytics.read');
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $metricCode)) {
            throw new SafetyException('validation_error', 'Invalid metric code.', 400);
        }
        $stmt = $this->pdo->prepare(
            "SELECT s.metrics_json, r.score
             FROM ipca_safety_cross_domain_links l
             INNER JOIN ipca_safety_cross_domain_snapshots s
               ON s.organization_id = l.organization_id AND s.id = l.snapshot_id
             INNER JOIN ipca_safety_risk_snapshots r
               ON r.organization_id = l.organization_id AND r.hazard_id = l.safety_subject_id
             WHERE l.organization_id = ? AND l.safety_subject_type = 'hazard'
               AND r.id = (
                 SELECT r2.id FROM ipca_safety_risk_snapshots r2
                 WHERE r2.organization_id = r.organization_id AND r2.hazard_id = r.hazard_id
                 ORDER BY r2.assessed_at_utc DESC, r2.id DESC LIMIT 1
               )"
        );
        $stmt->execute(array(SafetySupport::organizationId($session)));
        $x = array();
        $y = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $metrics = json_decode((string)$row['metrics_json'], true);
            if (is_array($metrics) && isset($metrics[$metricCode]) && is_numeric($metrics[$metricCode])) {
                $x[] = (float)$metrics[$metricCode];
                $y[] = (float)$row['score'];
            }
        }
        if (count($x) < 5) {
            return array('candidate' => false, 'pair_count' => count($x), 'reason' => 'At least five explicitly linked pairs are required.');
        }
        $coefficient = $this->pearson($x, $y);
        return array(
            'candidate' => abs($coefficient) >= 0.5,
            'metric_code' => $metricCode,
            'safety_measure' => 'latest_hazard_risk_score',
            'pair_count' => count($x),
            'pearson_r' => round($coefficient, 6),
            'notice' => 'Correlation is exploratory, uses only approved links and snapshots, and does not establish causation.',
        );
    }

    private function assertPeriod(string $fromUtc, string $toUtc): void
    {
        try {
            $from = new DateTimeImmutable($fromUtc);
            $to = new DateTimeImmutable($toUtc);
        } catch (Throwable) {
            throw new SafetyException('validation_error', 'Invalid UTC period.', 400);
        }
        if ($from >= $to) {
            throw new SafetyException('validation_error', 'Period end must be after period start.', 400);
        }
    }

    /** @param array<string,mixed> $metadata */
    private function assertDeidentifiedMetadata(array $metadata, string $field): void
    {
        $encoded = strtolower(SafetySupport::json($metadata));
        if (preg_match('/"(name|email|phone|reporter|user_id|device_id|ip_address)"\s*:/', $encoded)) {
            throw new SafetyException('data_classification_blocked', $field . ' must be de-identified.', 403);
        }
    }

    /** @param array<int,float> $values */
    private function linearSlope(array $values): float
    {
        $n = count($values);
        $sumX = (($n - 1) * $n) / 2;
        $sumY = array_sum($values);
        $sumXY = 0.0;
        $sumXX = 0.0;
        foreach ($values as $index => $value) {
            $sumXY += $index * $value;
            $sumXX += $index * $index;
        }
        $denominator = ($n * $sumXX) - ($sumX * $sumX);
        return $denominator === 0.0 ? 0.0 : (($n * $sumXY) - ($sumX * $sumY)) / $denominator;
    }

    /** @param array<int,float> $x @param array<int,float> $y */
    private function pearson(array $x, array $y): float
    {
        $n = count($x);
        $meanX = array_sum($x) / $n;
        $meanY = array_sum($y) / $n;
        $sum = $sumX = $sumY = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dx = $x[$i] - $meanX;
            $dy = $y[$i] - $meanY;
            $sum += $dx * $dy;
            $sumX += $dx * $dx;
            $sumY += $dy * $dy;
        }
        $denominator = sqrt($sumX * $sumY);
        return $denominator <= 0.0 ? 0.0 : $sum / $denominator;
    }
}
