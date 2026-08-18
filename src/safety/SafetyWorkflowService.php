<?php
declare(strict_types=1);

require_once __DIR__ . '/SafetySupport.php';
require_once __DIR__ . '/SafetyAccessService.php';
require_once __DIR__ . '/SafetyAuditEventService.php';

final class SafetyWorkflowService
{
    private const TRANSITIONS = array(
        'submitted' => array('triaged', 'returned', 'screened_out'),
        'returned' => array('submitted'),
        'triaged' => array('under_investigation', 'actioning', 'monitoring', 'closed'),
        'under_investigation' => array('actioning', 'monitoring', 'closed'),
        'actioning' => array('monitoring', 'closed'),
        'monitoring' => array('actioning', 'closed'),
        'closed' => array('reopened'),
        'reopened' => array('triaged', 'under_investigation', 'actioning'),
    );

    public function __construct(
        private PDO $pdo,
        private SafetyAccessService $access,
        private SafetyAuditEventService $events
    ) {
    }

    /** @param array<string,mixed> $session */
    public function transition(array $session, string $reportUuid, string $target, string $rationale): array
    {
        $this->access->requirePermission($session, 'workflow.transition');
        $organizationId = SafetySupport::organizationId($session);
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM ipca_safety_reports WHERE organization_id = ? AND report_uuid = ? LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(array($organizationId, strtolower(trim($reportUuid))));
            $report = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($report)) {
                throw new SafetyException('not_found', 'Safety report not found.', 404);
            }
            $from = (string)$report['status'];
            $target = strtolower(trim($target));
            if (!in_array($target, self::TRANSITIONS[$from] ?? array(), true)) {
                throw new SafetyException('invalid_transition', 'That safety workflow transition is not allowed.', 409);
            }
            if ($target === 'closed') {
                $this->access->requirePermission($session, 'report.close');
            }
            $rationale = SafetySupport::cleanText($rationale, 12000, 'rationale');
            $this->assertGates($organizationId, (int)$report['id'], $target);
            $closed = $target === 'closed' ? SafetySupport::nowUtc() : null;
            $triaged = $target === 'triaged' ? SafetySupport::nowUtc() : $report['triaged_at_utc'];
            $this->pdo->prepare(
                'UPDATE ipca_safety_reports SET status = ?, triaged_at_utc = ?, closed_at_utc = ? WHERE id = ?'
            )->execute(array($target, $triaged, $closed, (int)$report['id']));
            if ($target === 'closed') {
                $this->pdo->prepare(
                    'INSERT INTO ipca_safety_report_closures
                     (organization_id, report_id, closure_rationale, closed_by_user_id)
                     VALUES (?, ?, ?, ?)'
                )->execute(array(
                    $organizationId, (int)$report['id'], $rationale, (int)$session['user']['id'],
                ));
            }
            $this->events->append(
                $organizationId, 'report', (int)$report['id'], 'report.transitioned',
                'user', (int)$session['user']['id'], null,
                array('from' => $from, 'to' => $target, 'rationale' => $rationale)
            );
            $this->pdo->commit();
            return array('report_uuid' => $reportUuid, 'from_status' => $from, 'status' => $target);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function assertGates(int $organizationId, int $reportId, string $target): void
    {
        if ($target === 'under_investigation') {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM ipca_safety_investigations WHERE organization_id = ? AND report_id = ?'
            );
            $stmt->execute(array($organizationId, $reportId));
            if ((int)$stmt->fetchColumn() === 0) {
                throw new SafetyException('workflow_gate_failed', 'Create an investigation before starting it.', 409);
            }
        }
        if ($target === 'closed') {
            $acknowledgement = $this->pdo->prepare(
                "SELECT COUNT(*) FROM ipca_safety_events
                 WHERE organization_id = ? AND aggregate_type = 'report' AND aggregate_id = ?
                   AND event_type = 'report.acknowledged'"
            );
            $acknowledgement->execute(array($organizationId, $reportId));
            if ((int)$acknowledgement->fetchColumn() === 0) {
                throw new SafetyException('workflow_gate_failed', 'Reporter acknowledgement is required before closure.', 409);
            }

            $occurrences = $this->pdo->prepare(
                'SELECT id FROM ipca_safety_occurrences WHERE organization_id = ? AND report_id = ?'
            );
            $occurrences->execute(array($organizationId, $reportId));
            $occurrenceIds = array_map('intval', $occurrences->fetchAll(PDO::FETCH_COLUMN));
            if ($occurrenceIds === array()) {
                throw new SafetyException('workflow_gate_failed', 'Occurrence classification is required before closure.', 409);
            }
            foreach ($occurrenceIds as $occurrenceId) {
                $assessment = $this->pdo->prepare(
                    'SELECT decision FROM ipca_safety_reportability_assessments
                     WHERE organization_id = ? AND occurrence_id = ? ORDER BY assessed_at_utc DESC, id DESC LIMIT 1'
                );
                $assessment->execute(array($organizationId, $occurrenceId));
                $decision = $assessment->fetchColumn();
                if (!is_string($decision) || $decision === 'pending_information') {
                    throw new SafetyException(
                        'workflow_gate_failed',
                        'A final reportability decision is required for every occurrence before closure.',
                        409
                    );
                }
            }

            $hazards = $this->pdo->prepare(
                'SELECT id FROM ipca_safety_hazards WHERE organization_id = ? AND source_report_id = ?'
            );
            $hazards->execute(array($organizationId, $reportId));
            $hazardIds = array_map('intval', $hazards->fetchAll(PDO::FETCH_COLUMN));
            if ($hazardIds === array()) {
                throw new SafetyException('workflow_gate_failed', 'At least one hazard assessment is required before closure.', 409);
            }
            foreach ($hazardIds as $hazardId) {
                $risk = $this->pdo->prepare(
                    "SELECT accepted_at_utc FROM ipca_safety_risk_snapshots
                     WHERE organization_id = ? AND hazard_id = ? AND phase = 'residual'
                     ORDER BY assessed_at_utc DESC, id DESC LIMIT 1"
                );
                $risk->execute(array($organizationId, $hazardId));
                $acceptedAt = $risk->fetchColumn();
                if ($acceptedAt === false || $acceptedAt === null) {
                    throw new SafetyException(
                        'workflow_gate_failed',
                        'Accepted residual risk is required for every linked hazard before closure.',
                        409
                    );
                }
            }

            $investigations = $this->pdo->prepare(
                'SELECT status FROM ipca_safety_investigations WHERE organization_id = ? AND report_id = ?'
            );
            $investigations->execute(array($organizationId, $reportId));
            $statuses = array_map('strval', $investigations->fetchAll(PDO::FETCH_COLUMN));
            if ($statuses === array() || count(array_filter($statuses, static fn(string $status): bool => $status !== 'completed')) > 0) {
                throw new SafetyException('workflow_gate_failed', 'A completed investigation is required before closure.', 409);
            }

            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM ipca_safety_actions
                 WHERE organization_id = ? AND source_type = 'report' AND source_id = ? AND status <> 'closed'"
            );
            $stmt->execute(array($organizationId, $reportId));
            if ((int)$stmt->fetchColumn() > 0) {
                throw new SafetyException('workflow_gate_failed', 'Open safety actions must be closed first.', 409);
            }
            $incompleteActions = $this->pdo->prepare(
                "SELECT COUNT(*) FROM ipca_safety_actions a
                 WHERE a.organization_id = ? AND a.source_type = 'report' AND a.source_id = ?
                   AND (
                     NOT EXISTS (SELECT 1 FROM ipca_safety_action_evidence e
                       WHERE e.organization_id = a.organization_id AND e.action_id = a.id)
                     OR NOT EXISTS (SELECT 1 FROM ipca_safety_action_closures c
                       WHERE c.organization_id = a.organization_id AND c.action_id = a.id)
                   )"
            );
            $incompleteActions->execute(array($organizationId, $reportId));
            if ((int)$incompleteActions->fetchColumn() > 0) {
                throw new SafetyException(
                    'workflow_gate_failed',
                    'Every safety action requires evidence and an approved effectiveness closure.',
                    409
                );
            }

            $feedback = $this->pdo->prepare(
                "SELECT COUNT(*) FROM ipca_safety_reporter_updates
                 WHERE organization_id = ? AND report_id = ? AND direction = 'to_reporter'
                   AND visible_to_reporter = 1"
            );
            $feedback->execute(array($organizationId, $reportId));
            if ((int)$feedback->fetchColumn() === 0) {
                throw new SafetyException(
                    'workflow_gate_failed',
                    'Outcome feedback must be sent to the reporter before closure.',
                    409
                );
            }
        }
    }
}
