<?php
declare(strict_types=1);

require_once __DIR__ . '/SafetySupport.php';
require_once __DIR__ . '/SafetyAccessService.php';
require_once __DIR__ . '/SafetyAuditEventService.php';
require_once dirname(__DIR__) . '/communication/CommunicationPushService.php';

abstract class SafetyDomainService
{
    public function __construct(
        protected PDO $pdo,
        protected SafetyAccessService $access,
        protected SafetyAuditEventService $events
    ) {
    }
}

final class SafetyReportabilityService extends SafetyDomainService
{
    /** @param array<string,mixed> $session */
    public function createOccurrence(
        array $session,
        int $reportId,
        ?string $type,
        ?string $occurredAtUtc
    ): array {
        $this->access->requirePermission($session, 'report.triage');
        $org = SafetySupport::organizationId($session);
        $report = $this->pdo->prepare(
            'SELECT id FROM ipca_safety_reports WHERE organization_id = ? AND id = ?'
        );
        $report->execute(array($org, $reportId));
        if (!$report->fetchColumn()) {
            throw new SafetyException('not_found', 'Safety report not found.', 404);
        }
        $uuid = SafetySupport::uuid();
        $this->pdo->prepare(
            'INSERT INTO ipca_safety_occurrences
             (organization_id, occurrence_uuid, report_id, occurrence_type, occurred_at_utc)
             VALUES (?, ?, ?, ?, ?)'
        )->execute(array(
            $org, $uuid, $reportId,
            $type === null || trim($type) === '' ? null : SafetySupport::cleanText($type, 96, 'occurrence_type'),
            SafetySupport::nullableUtc($occurredAtUtc)
        ));
        $id = (int)$this->pdo->lastInsertId();
        $this->events->append($org, 'occurrence', $id, 'occurrence.created',
            'user', (int)$session['user']['id'], null);
        return array('occurrence_id' => $id, 'occurrence_uuid' => $uuid);
    }

    /** @param array<string,mixed> $session */
    public function assess(
        array $session,
        int $occurrenceId,
        string $framework,
        string $decision,
        string $rationale,
        ?string $deadlineUtc = null
    ): int {
        $this->access->requirePermission($session, 'report.triage');
        if (!in_array($decision, array('reportable', 'not_reportable', 'pending_information'), true)) {
            throw new SafetyException('validation_error', 'Invalid reportability decision.', 400);
        }
        $org = SafetySupport::organizationId($session);
        $stmt = $this->pdo->prepare(
            'SELECT id FROM ipca_safety_occurrences WHERE organization_id = ? AND id = ?'
        );
        $stmt->execute(array($org, $occurrenceId));
        if (!$stmt->fetchColumn()) {
            throw new SafetyException('not_found', 'Occurrence not found.', 404);
        }
        $this->pdo->prepare(
            'INSERT INTO ipca_safety_reportability_assessments
             (organization_id, occurrence_id, framework_code, decision, rationale, deadline_at_utc, assessed_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute(array(
            $org, $occurrenceId, SafetySupport::cleanText($framework, 64, 'framework'),
            $decision, SafetySupport::cleanText($rationale, 12000, 'rationale'),
            $deadlineUtc, (int)$session['user']['id'],
        ));
        $id = (int)$this->pdo->lastInsertId();
        $this->events->append($org, 'occurrence', $occurrenceId, 'reportability.assessed',
            'user', (int)$session['user']['id'], null, array('decision' => $decision, 'framework' => $framework));
        return $id;
    }
}

final class SafetyRiskHazardService extends SafetyDomainService
{
    /** @param array<string,mixed> $session */
    public function createHazard(array $session, string $title, string $description, ?int $sourceReportId = null): array
    {
        $this->access->requirePermission($session, 'risk.manage');
        $org = SafetySupport::organizationId($session);
        $uuid = SafetySupport::uuid();
        $this->pdo->prepare(
            'INSERT INTO ipca_safety_hazards
             (organization_id, hazard_uuid, source_report_id, title, description, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute(array(
            $org, $uuid, $sourceReportId, SafetySupport::cleanText($title, 240, 'title'),
            SafetySupport::cleanText($description, 12000, 'description'), (int)$session['user']['id'],
        ));
        $id = (int)$this->pdo->lastInsertId();
        $this->events->append($org, 'hazard', $id, 'hazard.created', 'user', (int)$session['user']['id'], null);
        return array('hazard_id' => $id, 'hazard_uuid' => $uuid);
    }

    /** @param array<string,mixed> $session */
    public function snapshotRisk(
        array $session,
        int $hazardId,
        int $matrixVersionId,
        string $phase,
        string $likelihood,
        string $severity,
        string $rationale
    ): array {
        $this->access->requirePermission($session, 'risk.manage');
        $org = SafetySupport::organizationId($session);
        $stmt = $this->pdo->prepare(
            'SELECT c.score, c.band_code FROM ipca_safety_risk_matrix_cells c
             INNER JOIN ipca_safety_risk_matrix_versions v ON v.id = c.matrix_version_id
             WHERE c.organization_id = ? AND c.matrix_version_id = ? AND c.likelihood_code = ?
               AND c.severity_code = ? AND v.status = ? LIMIT 1'
        );
        $stmt->execute(array($org, $matrixVersionId, $likelihood, $severity, 'active'));
        $cell = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($cell)) {
            throw new SafetyException('risk_matrix_cell_not_found', 'No active risk matrix cell matches that assessment.', 409);
        }
        $this->pdo->prepare(
            'INSERT INTO ipca_safety_risk_snapshots
             (organization_id, hazard_id, matrix_version_id, phase, likelihood_code, severity_code,
              score, band_code, rationale, assessed_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(array(
            $org, $hazardId, $matrixVersionId, $phase, $likelihood, $severity,
            $cell['score'], $cell['band_code'], SafetySupport::cleanText($rationale, 12000, 'rationale'),
            (int)$session['user']['id'],
        ));
        $id = (int)$this->pdo->lastInsertId();
        $this->events->append($org, 'hazard', $hazardId, 'risk.assessed', 'user',
            (int)$session['user']['id'], null, array('phase' => $phase, 'band' => $cell['band_code']));
        return array('risk_snapshot_id' => $id, 'score' => (float)$cell['score'], 'band_code' => $cell['band_code']);
    }

    /** @param array<string,mixed> $session */
    public function acceptResidualRisk(array $session, int $snapshotId, string $rationale): void
    {
        $this->access->requirePermission($session, 'risk.accept');
        $org = SafetySupport::organizationId($session);
        $rationale = SafetySupport::cleanText($rationale, 12000, 'rationale');
        $stmt = $this->pdo->prepare(
            "UPDATE ipca_safety_risk_snapshots SET accepted_by_user_id = ?, accepted_at_utc = CURRENT_TIMESTAMP(3),
               rationale = CONCAT(rationale, '\n\nAcceptance: ', ?)
             WHERE organization_id = ? AND id = ? AND phase = 'residual' AND accepted_at_utc IS NULL"
        );
        $stmt->execute(array((int)$session['user']['id'], $rationale, $org, $snapshotId));
        if ($stmt->rowCount() !== 1) {
            throw new SafetyException('workflow_gate_failed', 'Only an unaccepted residual risk can be accepted.', 409);
        }
        $this->events->append($org, 'risk_snapshot', $snapshotId, 'risk.accepted',
            'user', (int)$session['user']['id'], null);
    }
}

final class SafetyInvestigationService extends SafetyDomainService
{
    /** @param array<string,mixed> $session */
    public function open(array $session, int $reportId, string $scope, ?int $leadUserId = null): array
    {
        $this->access->requirePermission($session, 'investigation.manage');
        $org = SafetySupport::organizationId($session);
        $uuid = SafetySupport::uuid();
        $this->pdo->prepare(
            'INSERT INTO ipca_safety_investigations
             (organization_id, investigation_uuid, report_id, lead_user_id, scope_text, status)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute(array(
            $org, $uuid, $reportId, $leadUserId,
            SafetySupport::cleanText($scope, 12000, 'scope'), 'planned',
        ));
        $id = (int)$this->pdo->lastInsertId();
        $this->events->append($org, 'investigation', $id, 'investigation.created',
            'user', (int)$session['user']['id'], null);
        return array('investigation_id' => $id, 'investigation_uuid' => $uuid);
    }

    /** @param array<string,mixed> $session */
    public function addFactor(
        array $session, int $investigationId, string $type, string $statement, string $causalRole
    ): int {
        $this->access->requirePermission($session, 'investigation.manage');
        $org = SafetySupport::organizationId($session);
        $this->pdo->prepare(
            'INSERT INTO ipca_safety_investigation_factors
             (organization_id, investigation_id, factor_type, statement_text, causal_role, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute(array(
            $org, $investigationId, SafetySupport::cleanText($type, 64, 'factor_type'),
            SafetySupport::cleanText($statement, 12000, 'statement'),
            SafetySupport::cleanText($causalRole, 32, 'causal_role'), (int)$session['user']['id'],
        ));
        return (int)$this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $session */
    public function complete(array $session, int $investigationId, string $conclusion): void
    {
        $this->access->requirePermission($session, 'investigation.manage');
        $org = SafetySupport::organizationId($session);
        $factor = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ipca_safety_investigation_factors
             WHERE organization_id = ? AND investigation_id = ?'
        );
        $factor->execute(array($org, $investigationId));
        if ((int)$factor->fetchColumn() === 0) {
            throw new SafetyException('workflow_gate_failed', 'Record at least one investigation factor first.', 409);
        }
        $conclusion = SafetySupport::cleanText($conclusion, 12000, 'conclusion');
        $stmt = $this->pdo->prepare(
            "UPDATE ipca_safety_investigations SET status = 'completed', completed_at_utc = CURRENT_TIMESTAMP(3),
               scope_text = CONCAT(scope_text, '\n\nConclusion: ', ?)
             WHERE organization_id = ? AND id = ? AND status <> 'completed'"
        );
        $stmt->execute(array($conclusion, $org, $investigationId));
        if ($stmt->rowCount() !== 1) {
            throw new SafetyException('not_found', 'Open investigation not found.', 404);
        }
        $this->events->append($org, 'investigation', $investigationId, 'investigation.completed',
            'user', (int)$session['user']['id'], null);
    }
}

final class SafetyActionService extends SafetyDomainService
{
    /** @param array<string,mixed> $session */
    public function create(
        array $session, string $sourceType, int $sourceId, string $title, string $description, int $ownerUserId, ?string $dueUtc
    ): array {
        $this->access->requirePermission($session, 'action.manage');
        $org = SafetySupport::organizationId($session);
        $uuid = SafetySupport::uuid();
        $this->pdo->prepare(
            'INSERT INTO ipca_safety_actions
             (organization_id, action_uuid, source_type, source_id, title, description,
              owner_user_id, due_at_utc, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(array(
            $org, $uuid, SafetySupport::cleanText($sourceType, 48, 'source_type'), $sourceId,
            SafetySupport::cleanText($title, 240, 'title'),
            SafetySupport::cleanText($description, 12000, 'description'),
            $ownerUserId, $dueUtc, (int)$session['user']['id'],
        ));
        $id = (int)$this->pdo->lastInsertId();
        $this->events->append($org, 'action', $id, 'action.created', 'user', (int)$session['user']['id'], null);
        return array('action_id' => $id, 'action_uuid' => $uuid);
    }

    /** @param array<string,mixed> $session */
    public function addEvidence(array $session, int $actionId, string $note, ?int $attachmentId = null): int
    {
        $this->access->requirePermission($session, 'action.manage');
        $org = SafetySupport::organizationId($session);
        $this->pdo->prepare(
            'INSERT INTO ipca_safety_action_evidence
             (organization_id, action_id, attachment_id, note_text, submitted_by_user_id)
             SELECT ?, a.id, ?, ?, ? FROM ipca_safety_actions a
             WHERE a.organization_id = ? AND a.id = ?'
        )->execute(array(
            $org, $attachmentId, SafetySupport::cleanText($note, 12000, 'note'),
            (int)$session['user']['id'], $org, $actionId,
        ));
        $id = (int)$this->pdo->lastInsertId();
        if ($id < 1) {
            throw new SafetyException('not_found', 'Safety action not found.', 404);
        }
        $this->events->append($org, 'action', $actionId, 'action.evidence_added',
            'user', (int)$session['user']['id'], null);
        return $id;
    }

    /** @param array<string,mixed> $session */
    public function reviewEffectiveness(
        array $session, int $actionId, string $outcome, string $method, string $result
    ): int {
        $this->access->requirePermission($session, 'action.manage');
        if (!in_array($outcome, array('effective', 'partially_effective', 'ineffective', 'inconclusive'), true)) {
            throw new SafetyException('validation_error', 'Invalid effectiveness outcome.', 400);
        }
        $org = SafetySupport::organizationId($session);
        $this->pdo->prepare(
            'INSERT INTO ipca_safety_action_effectiveness_reviews
             (organization_id, action_id, outcome, method_text, result_text, reviewed_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute(array(
            $org, $actionId, $outcome, SafetySupport::cleanText($method, 12000, 'method'),
            SafetySupport::cleanText($result, 12000, 'result'), (int)$session['user']['id'],
        ));
        return (int)$this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $session */
    public function close(array $session, int $actionId, int $reviewId, string $rationale): void
    {
        $this->access->requirePermission($session, 'action.manage');
        $org = SafetySupport::organizationId($session);
        $stmt = $this->pdo->prepare(
            "SELECT outcome FROM ipca_safety_action_effectiveness_reviews
             WHERE organization_id = ? AND id = ? AND action_id = ? AND outcome IN ('effective','partially_effective')"
        );
        $stmt->execute(array($org, $reviewId, $actionId));
        if (!$stmt->fetchColumn()) {
            throw new SafetyException('workflow_gate_failed', 'An acceptable effectiveness review is required before closure.', 409);
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO ipca_safety_action_closures
                 (organization_id, action_id, effectiveness_review_id, closure_rationale, closed_by_user_id)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute(array(
                $org, $actionId, $reviewId, SafetySupport::cleanText($rationale, 12000, 'rationale'),
                (int)$session['user']['id'],
            ));
            $this->pdo->prepare(
                "UPDATE ipca_safety_actions SET status = 'closed', completed_at_utc = CURRENT_TIMESTAMP(3)
                 WHERE organization_id = ? AND id = ?"
            )->execute(array($org, $actionId));
            $this->events->append($org, 'action', $actionId, 'action.closed',
                'user', (int)$session['user']['id'], null);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}

final class SafetyFeedbackService extends SafetyDomainService
{
    public function __construct(
        PDO $pdo,
        SafetyAccessService $access,
        SafetyAuditEventService $events,
        private ?CommunicationPushService $push = null
    ) {
        parent::__construct($pdo, $access, $events);
    }

    /** @param array<string,mixed> $session */
    public function send(array $session, int $reportId, string $body): string
    {
        $this->access->requirePermission($session, 'report.triage');
        $org = SafetySupport::organizationId($session);
        $reportStmt = $this->pdo->prepare(
            'SELECT report_uuid, reporter_user_id, confidentiality
             FROM ipca_safety_reports WHERE organization_id = ? AND id = ?'
        );
        $reportStmt->execute(array($org, $reportId));
        $report = $reportStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($report)) {
            throw new SafetyException('not_found', 'Safety report not found.', 404);
        }
        $uuid = SafetySupport::uuid();
        $this->pdo->prepare(
            "INSERT INTO ipca_safety_reporter_updates
             (organization_id, update_uuid, report_id, direction, author_user_id, body, visible_to_reporter)
             VALUES (?, ?, ?, 'to_reporter', ?, ?, 1)"
        )->execute(array(
            $org, $uuid, $reportId, (int)$session['user']['id'],
            SafetySupport::cleanText($body, 12000, 'body'),
        ));
        $this->events->append($org, 'report', $reportId, 'report.feedback_sent',
            'user', (int)$session['user']['id'], null);
        if ((string)$report['confidentiality'] === 'standard' && (int)$report['reporter_user_id'] > 0) {
            $this->push?->notifySafetyUpdate((int)$report['reporter_user_id'], (string)$report['report_uuid']);
        }
        return $uuid;
    }
}
