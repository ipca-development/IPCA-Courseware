<?php
declare(strict_types=1);

require_once __DIR__ . '/SafetySupport.php';
require_once __DIR__ . '/SafetyAccessService.php';
require_once __DIR__ . '/SafetyAuditEventService.php';

/**
 * Organization-scoped read model and bulletin commands for the staff workspace.
 *
 * Reporter-vault, mailbox credentials, and reporter identifiers are deliberately
 * absent from every projection in this service.
 */
final class SafetyStaffService
{
    public function __construct(
        private PDO $pdo,
        private SafetyAccessService $access,
        private SafetyAuditEventService $events,
        private SafetyOccurrenceIntakeContextService $occurrenceContext
    ) {
    }

    /** @param array<string,mixed> $session */
    public function dashboard(array $session): array
    {
        $this->access->requirePermission($session, 'report.read_all');
        $org = SafetySupport::organizationId($session);
        return array(
            'reports_by_status' => $this->rows(
                'SELECT status, COUNT(*) AS total FROM ipca_safety_reports
                 WHERE organization_id = ? GROUP BY status ORDER BY total DESC',
                array($org)
            ),
            'open_hazards' => $this->count(
                "SELECT COUNT(*) FROM ipca_safety_hazards WHERE organization_id = ? AND hazard_status <> 'closed'",
                array($org)
            ),
            'open_actions' => $this->count(
                "SELECT COUNT(*) FROM ipca_safety_actions WHERE organization_id = ? AND status <> 'closed'",
                array($org)
            ),
            'overdue_actions' => $this->count(
                "SELECT COUNT(*) FROM ipca_safety_actions
                 WHERE organization_id = ? AND status <> 'closed' AND due_at_utc < CURRENT_TIMESTAMP(3)",
                array($org)
            ),
            'active_investigations' => $this->count(
                "SELECT COUNT(*) FROM ipca_safety_investigations
                 WHERE organization_id = ? AND status <> 'completed'",
                array($org)
            ),
            'pending_reportability' => $this->count(
                "SELECT COUNT(*) FROM ipca_safety_occurrences o
                 WHERE o.organization_id = ? AND NOT EXISTS (
                   SELECT 1 FROM ipca_safety_reportability_assessments a
                   WHERE a.organization_id = o.organization_id AND a.occurrence_id = o.id
                     AND a.decision IN ('reportable','not_reportable')
                 )",
                array($org)
            ),
            'recent_reports' => $this->listReports($session, array('limit' => 8)),
        );
    }

    /** @param array<string,mixed> $session @param array<string,mixed> $filters */
    public function listReports(array $session, array $filters = array()): array
    {
        $this->access->requirePermission($session, 'report.read_all');
        $org = SafetySupport::organizationId($session);
        $where = array('organization_id = ?');
        $args = array($org);
        $status = strtolower(trim((string)($filters['status'] ?? '')));
        if ($status !== '') {
            $where[] = 'status = ?';
            $args[] = $status;
        }
        $query = trim((string)($filters['q'] ?? ''));
        if ($query !== '') {
            $where[] = '(report_number LIKE ? OR title LIKE ? OR category_code LIKE ?)';
            $like = '%' . $query . '%';
            array_push($args, $like, $like, $like);
        }
        $limit = max(1, min(200, (int)($filters['limit'] ?? 100)));
        $sql = 'SELECT id, report_uuid, report_number, channel, category_code, title, status,
                       confidentiality, event_at_utc, submitted_at_utc, updated_at_utc
                FROM ipca_safety_reports WHERE ' . implode(' AND ', $where)
            . ' ORDER BY COALESCE(submitted_at_utc, created_at_utc) DESC LIMIT ' . $limit;
        return $this->rows($sql, $args);
    }

    /** @param array<string,mixed> $session */
    public function reportDetail(array $session, string $reportUuid): array
    {
        $this->access->requirePermission($session, 'report.read_all');
        $org = SafetySupport::organizationId($session);
        $rows = $this->rows(
            'SELECT id, report_uuid, report_number, channel, category_code, occurrence_type_node_id,
                    title, narrative, event_at_utc, location_text, aircraft_registration,
                    immediate_action, phase_of_flight, injury_state, injury_details,
                    damage_state, damage_details, weather_relevance, weather_details, status,
                    confidentiality, owner_user_id, submitted_at_utc, triaged_at_utc,
                    closed_at_utc, created_at_utc, updated_at_utc
             FROM ipca_safety_reports WHERE organization_id = ? AND report_uuid = ? LIMIT 1',
            array($org, strtolower(trim($reportUuid)))
        );
        if ($rows === array()) {
            throw new SafetyException('not_found', 'Safety report not found.', 404);
        }
        $report = $rows[0];
        $id = (int)$report['id'];
        $report['flight_link'] = $this->occurrenceContext->flightLinkForReport($org, $id);
        $report['occurrences'] = $this->rows(
            'SELECT o.id, o.occurrence_uuid, o.occurrence_type, o.occurred_at_utc, o.state,
                    a.id AS assessment_id, a.framework_code, a.decision, a.rationale,
                    a.deadline_at_utc, a.assessed_at_utc
             FROM ipca_safety_occurrences o
             LEFT JOIN ipca_safety_reportability_assessments a ON a.id = (
               SELECT a2.id FROM ipca_safety_reportability_assessments a2
               WHERE a2.organization_id = o.organization_id AND a2.occurrence_id = o.id
               ORDER BY a2.assessed_at_utc DESC, a2.id DESC LIMIT 1
             )
             WHERE o.organization_id = ? AND o.report_id = ? ORDER BY o.id',
            array($org, $id)
        );
        foreach ($report['occurrences'] as &$occurrence) {
            $occurrence['eccairs_submissions'] = $this->rows(
                'SELECT submission_uuid, environment, payload_version, mapping_version,
                        taxonomy_version, canonical_sha256, envelope_sha256,
                        validation_json, status,
                        approved_by_user_id, approved_at_utc, queued_at_utc, remote_e2_id,
                        remote_version, remote_status, accepted_at_utc, last_error_code,
                        last_error_summary, created_at_utc, updated_at_utc,
                        (SELECT COUNT(*) FROM ipca_safety_eccairs_attempts ea
                         WHERE ea.organization_id = s.organization_id
                           AND ea.submission_id = s.id) AS attempt_count
                 FROM ipca_safety_eccairs_submissions s
                 WHERE s.organization_id = ? AND s.occurrence_id = ?
                 ORDER BY s.payload_version DESC',
                array($org, (int)$occurrence['id'])
            );
            foreach ($occurrence['eccairs_submissions'] as &$submission) {
                $submission['validation'] = json_decode(
                    (string)$submission['validation_json'],
                    true
                ) ?: array();
                unset($submission['validation_json']);
            }
            unset($submission);
        }
        unset($occurrence);
        $report['hazards'] = $this->rows(
            'SELECT h.id, h.hazard_uuid, h.title, h.description, h.hazard_status,
                    r.id AS risk_snapshot_id, r.phase, r.likelihood_code, r.severity_code,
                    r.score, r.band_code, r.accepted_at_utc
             FROM ipca_safety_hazards h
             LEFT JOIN ipca_safety_risk_snapshots r ON r.id = (
               SELECT r2.id FROM ipca_safety_risk_snapshots r2
               WHERE r2.organization_id = h.organization_id AND r2.hazard_id = h.id
               ORDER BY r2.assessed_at_utc DESC, r2.id DESC LIMIT 1
             )
             WHERE h.organization_id = ? AND h.source_report_id = ? ORDER BY h.id',
            array($org, $id)
        );
        $report['investigations'] = $this->rows(
            'SELECT i.id, i.investigation_uuid, i.scope_text, i.methodology, i.status,
                    i.started_at_utc, i.completed_at_utc,
                    (SELECT COUNT(*) FROM ipca_safety_investigation_factors f
                     WHERE f.organization_id = i.organization_id AND f.investigation_id = i.id) AS factor_count
             FROM ipca_safety_investigations i
             WHERE i.organization_id = ? AND i.report_id = ? ORDER BY i.id',
            array($org, $id)
        );
        $report['actions'] = $this->rows(
            "SELECT a.id, a.action_uuid, a.title, a.description, a.owner_user_id, a.due_at_utc,
                    a.status, a.priority,
                    (SELECT COUNT(*) FROM ipca_safety_action_evidence e
                     WHERE e.organization_id = a.organization_id AND e.action_id = a.id) AS evidence_count,
                    (SELECT er.id FROM ipca_safety_action_effectiveness_reviews er
                     WHERE er.organization_id = a.organization_id AND er.action_id = a.id
                     ORDER BY er.reviewed_at_utc DESC, er.id DESC LIMIT 1) AS latest_review_id
             FROM ipca_safety_actions a
             WHERE a.organization_id = ? AND a.source_type = 'report' AND a.source_id = ? ORDER BY a.id",
            array($org, $id)
        );
        $report['updates'] = $this->rows(
            'SELECT update_uuid, direction, body, visible_to_reporter, created_at_utc
             FROM ipca_safety_reporter_updates
             WHERE organization_id = ? AND report_id = ? ORDER BY id',
            array($org, $id)
        );
        $report['events'] = $this->rows(
            "SELECT event_type, actor_type, occurred_at_utc, payload_json
             FROM ipca_safety_events
             WHERE organization_id = ? AND aggregate_type = 'report' AND aggregate_id = ?
             ORDER BY id DESC LIMIT 100",
            array($org, $id)
        );
        return $report;
    }

    /** @param array<string,mixed> $session */
    public function registers(array $session): array
    {
        $this->access->requirePermission($session, 'report.read_all');
        $org = SafetySupport::organizationId($session);
        return array(
            'hazards' => $this->rows(
                'SELECT h.id, h.hazard_uuid, h.title, h.hazard_status, h.owner_user_id,
                        h.created_at_utc, r.phase, r.score, r.band_code, r.accepted_at_utc
                 FROM ipca_safety_hazards h
                 LEFT JOIN ipca_safety_risk_snapshots r ON r.id = (
                   SELECT r2.id FROM ipca_safety_risk_snapshots r2
                   WHERE r2.organization_id = h.organization_id AND r2.hazard_id = h.id
                   ORDER BY r2.assessed_at_utc DESC, r2.id DESC LIMIT 1
                 )
                 WHERE h.organization_id = ? ORDER BY h.created_at_utc DESC LIMIT 200',
                array($org)
            ),
            'occurrences' => $this->rows(
                'SELECT o.id, o.occurrence_uuid, o.occurrence_type, o.state, o.occurred_at_utc,
                        r.report_number, r.title AS report_title, a.decision, a.deadline_at_utc
                 FROM ipca_safety_occurrences o
                 INNER JOIN ipca_safety_reports r ON r.organization_id = o.organization_id AND r.id = o.report_id
                 LEFT JOIN ipca_safety_reportability_assessments a ON a.id = (
                   SELECT a2.id FROM ipca_safety_reportability_assessments a2
                   WHERE a2.organization_id = o.organization_id AND a2.occurrence_id = o.id
                   ORDER BY a2.assessed_at_utc DESC, a2.id DESC LIMIT 1
                 )
                 WHERE o.organization_id = ? ORDER BY o.created_at_utc DESC LIMIT 200',
                array($org)
            ),
            'investigations' => $this->rows(
                'SELECT i.id, i.investigation_uuid, i.status, i.lead_user_id, i.created_at_utc,
                        i.completed_at_utc, r.report_number, r.title AS report_title
                 FROM ipca_safety_investigations i
                 INNER JOIN ipca_safety_reports r ON r.organization_id = i.organization_id AND r.id = i.report_id
                 WHERE i.organization_id = ? ORDER BY i.created_at_utc DESC LIMIT 200',
                array($org)
            ),
            'actions' => $this->rows(
                'SELECT id, action_uuid, source_type, source_id, title, owner_user_id, due_at_utc,
                        status, priority, created_at_utc, completed_at_utc
                 FROM ipca_safety_actions WHERE organization_id = ?
                 ORDER BY (status = \'closed\'), due_at_utc IS NULL, due_at_utc LIMIT 200',
                array($org)
            ),
        );
    }

    /** @param array<string,mixed> $session */
    public function riskMatrix(array $session): array
    {
        $this->access->requirePermission($session, 'risk.manage');
        $org = SafetySupport::organizationId($session);
        return $this->rows(
            "SELECT v.id AS matrix_version_id, v.version_number, c.likelihood_code, c.severity_code,
                    c.score, c.band_code
             FROM ipca_safety_risk_matrix_versions v
             INNER JOIN ipca_safety_risk_matrix_cells c
               ON c.organization_id = v.organization_id AND c.matrix_version_id = v.id
             WHERE v.organization_id = ? AND v.status = 'active'
             ORDER BY c.severity_code, c.likelihood_code",
            array($org)
        );
    }

    /** @param array<string,mixed> $session */
    public function eccairsConfiguration(array $session): array
    {
        $this->access->requirePermission($session, 'eccairs.prepare');
        $org = SafetySupport::organizationId($session);
        return array(
            'connections' => $this->rows(
                'SELECT environment, base_url, token_path, create_path, get_path_template,
                        reporting_entity_id, responsible_entity_id,
                        taxonomy_version, general_version, enabled, production_transmission_enabled,
                        updated_at_utc
                 FROM ipca_safety_eccairs_connections
                 WHERE organization_id = ? ORDER BY FIELD(environment, \'sandbox\', \'uat\', \'production\')',
                array($org)
            ),
            'mapping_versions' => $this->rows(
                'SELECT mapping_version, taxonomy_version, COUNT(*) AS mapping_count,
                        SUM(required_state = \'required\') AS required_count
                 FROM ipca_safety_eccairs_mappings
                 WHERE organization_id = ? AND active = 1
                 GROUP BY mapping_version, taxonomy_version
                 ORDER BY mapping_version DESC',
                array($org)
            ),
            'taxonomy_packages' => $this->rows(
                'SELECT package_uuid, taxonomy_name, taxonomy_version, schema_version,
                        source_sha256, source_byte_size, manifest_json, status,
                        imported_at_utc, activated_at_utc
                 FROM ipca_safety_eccairs_taxonomy_packages
                 WHERE organization_id = ?
                 ORDER BY FIELD(status, \'active\', \'imported\', \'retired\'), imported_at_utc DESC',
                array($org)
            ),
        );
    }

    /** @param array<string,mixed> $session */
    public function listBulletins(array $session): array
    {
        $this->access->requirePermission($session, 'bulletin.manage');
        return $this->rows(
            'SELECT b.bulletin_uuid, b.title, b.body, b.audience_json, b.status, b.requires_acknowledgement,
                    b.published_at_utc, b.expires_at_utc, b.created_at_utc,
                    (SELECT COUNT(*) FROM ipca_safety_bulletin_acknowledgements a
                     WHERE a.organization_id = b.organization_id AND a.bulletin_id = b.id) AS acknowledgement_count
             FROM ipca_safety_bulletins b WHERE b.organization_id = ? ORDER BY b.created_at_utc DESC LIMIT 200',
            array(SafetySupport::organizationId($session))
        );
    }

    /** @param array<string,mixed> $session @param array<string,mixed> $input */
    public function createBulletin(array $session, array $input): array
    {
        $this->access->requirePermission($session, 'bulletin.manage');
        $org = SafetySupport::organizationId($session);
        $uuid = SafetySupport::uuid();
        $audience = $input['audience'] ?? array('roles' => array('student', 'instructor'));
        if (!is_array($audience)) {
            throw new SafetyException('validation_error', 'audience must be an object.', 400);
        }
        $this->pdo->prepare(
            'INSERT INTO ipca_safety_bulletins
             (organization_id, bulletin_uuid, title, body, audience_json, requires_acknowledgement, expires_at_utc)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute(array(
            $org,
            $uuid,
            SafetySupport::cleanText((string)($input['title'] ?? ''), 240, 'title'),
            SafetySupport::cleanText((string)($input['body'] ?? ''), 50000, 'body'),
            SafetySupport::json($audience),
            !empty($input['requires_acknowledgement']) ? 1 : 0,
            $this->nullable((string)($input['expires_at_utc'] ?? '')),
        ));
        $id = (int)$this->pdo->lastInsertId();
        $this->events->append($org, 'bulletin', $id, 'bulletin.created', 'user', (int)$session['user']['id'], null);
        return array('bulletin_uuid' => $uuid, 'status' => 'draft');
    }

    /** @param array<string,mixed> $session */
    public function publishBulletin(array $session, string $uuid): void
    {
        $this->access->requirePermission($session, 'bulletin.manage');
        $org = SafetySupport::organizationId($session);
        $stmt = $this->pdo->prepare(
            "UPDATE ipca_safety_bulletins SET status = 'published', published_by_user_id = ?,
                    published_at_utc = CURRENT_TIMESTAMP(3)
             WHERE organization_id = ? AND bulletin_uuid = ? AND status = 'draft'"
        );
        $stmt->execute(array((int)$session['user']['id'], $org, strtolower(trim($uuid))));
        if ($stmt->rowCount() !== 1) {
            throw new SafetyException('workflow_gate_failed', 'Only a draft bulletin can be published.', 409);
        }
    }

    /** @param array<int,mixed> $args */
    private function rows(string $sql, array $args): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<int,mixed> $args */
    private function count(string $sql, array $args): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        return (int)$stmt->fetchColumn();
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : $value;
    }
}
