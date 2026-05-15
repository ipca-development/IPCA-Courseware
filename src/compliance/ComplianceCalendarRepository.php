<?php
declare(strict_types=1);

/**
 * Read-only Compliance Schedule projection.
 *
 * This repository intentionally does not own source data. It normalizes dates
 * from existing Compliance OS tables into a single calendar event model for
 * the UI confirmation phase.
 */
final class ComplianceCalendarRepository
{
    /** @return list<array<string,mixed>> */
    public static function listEvents(PDO $pdo): array
    {
        $events = array();

        foreach (self::manualCalendarEvents($pdo) as $event) { $events[] = $event; }
        foreach (self::auditEvents($pdo) as $event) { $events[] = $event; }
        foreach (self::meetingEvents($pdo) as $event) { $events[] = $event; }
        foreach (self::findingTargetEvents($pdo) as $event) { $events[] = $event; }
        foreach (self::rcaCapSubmissionEvents($pdo) as $event) { $events[] = $event; }
        foreach (self::correctiveActionEvents($pdo) as $event) { $events[] = $event; }
        foreach (self::deadlineExtensionEvents($pdo) as $event) { $events[] = $event; }
        foreach (self::effectivenessReviewEvents($pdo) as $event) { $events[] = $event; }
        foreach (self::manualEvents($pdo) as $event) { $events[] = $event; }
        foreach (self::partIsEvents($pdo) as $event) { $events[] = $event; }
        foreach (self::monitoringEvents($pdo) as $event) { $events[] = $event; }
        foreach (self::pendingChangeRequestEvents($pdo) as $event) { $events[] = $event; }

        usort($events, static function (array $a, array $b): int {
            return strcmp((string)$a['starts_at'], (string)$b['starts_at']);
        });

        return $events;
    }

    /** @param list<array<string,mixed>> $events @return array<string,int> */
    public static function stats(array $events): array
    {
        $monthStart = new DateTimeImmutable(date('Y-m-01 00:00:00'));
        $monthEnd = $monthStart->modify('+1 month');
        $today = new DateTimeImmutable(date('Y-m-d 00:00:00'));
        $stats = array(
            'month_events' => 0,
            'upcoming_audits' => 0,
            'open_deadlines' => 0,
            'meetings_planned' => 0,
            'overdue_items' => 0,
        );

        foreach ($events as $event) {
            $start = self::safeDate((string)($event['starts_at'] ?? ''));
            if ($start === null) {
                continue;
            }
            if ($start >= $monthStart && $start < $monthEnd) {
                $stats['month_events']++;
            }
            $type = (string)($event['event_type'] ?? '');
            $status = strtoupper((string)($event['status'] ?? ''));
            if (in_array($type, array('internal_audit', 'authority_audit', 'audit_window'), true) && $start >= $today) {
                $stats['upcoming_audits']++;
            }
            if (str_contains($type, 'deadline') && !in_array($status, array('CLOSED', 'COMPLETED', 'VERIFIED', 'CANCELLED', 'RELEASED'), true)) {
                $stats['open_deadlines']++;
            }
            if ($type === 'meeting' && in_array($status, array('SCHEDULED', 'LIVE', 'PLANNED'), true)) {
                $stats['meetings_planned']++;
            }
            if (!empty($event['is_overdue'])) {
                $stats['overdue_items']++;
            }
        }

        return $stats;
    }

    /** @return array<string,bool> */
    public static function connectedSources(PDO $pdo): array
    {
        $sources = array(
            'Internal Audit' => self::tablePresent($pdo, 'ipca_compliance_audits'),
            'Authority Audit' => self::tablePresent($pdo, 'ipca_compliance_audits'),
            'Audit Window' => false,
            'RCA/CAP Deadline' => self::tablePresent($pdo, 'ipca_compliance_rca_cap_submissions') || self::tablePresent($pdo, 'ipca_compliance_findings'),
            'Corrective Action Deadline' => self::tablePresent($pdo, 'ipca_compliance_corrective_actions'),
            'Effectiveness Review' => self::tablePresent($pdo, 'ipca_compliance_effectiveness_reviews'),
            'Compliance Meeting' => self::tablePresent($pdo, 'ipca_compliance_meetings'),
            'Regulatory Review' => self::tablePresent($pdo, 'ipca_compliance_monitor_rules'),
            'Manual Change' => self::tablePresent($pdo, 'ipca_compliance_manual_release_packages') || self::tablePresent($pdo, 'ipca_compliance_manual_change_requests'),
            'Cyber / Part-IS' => self::tablePresent($pdo, 'ipca_compliance_is_risks'),
            'Other Event' => self::tablePresent($pdo, 'ipca_compliance_alerts') || self::tablePresent($pdo, 'ipca_compliance_calendar_events'),
        );

        return $sources;
    }

    /** @return list<array<string,mixed>> */
    private static function manualCalendarEvents(PDO $pdo): array
    {
        if (!self::tablePresent($pdo, 'ipca_compliance_calendar_events')) {
            return array();
        }
        $rows = self::rows($pdo, "SELECT * FROM ipca_compliance_calendar_events ORDER BY starts_at ASC LIMIT 800");
        $events = array();
        foreach ($rows as $row) {
            $type = (string)($row['event_type'] ?? 'other');
            $locked = !empty($row['is_locked']) || !empty($row['locked_at']);
            $events[] = self::event(array(
                'id' => 'manual:' . (int)$row['id'],
                'source_type' => 'calendar_event',
                'source_table' => 'ipca_compliance_calendar_events',
                'source_id' => (int)$row['id'],
                'title' => (string)$row['title'],
                'event_type' => $type !== '' ? $type : 'other',
                'status' => (string)($row['status'] ?? 'SCHEDULED'),
                'governance_state' => $locked ? 'locked' : (string)($row['governance_state'] ?? 'approved'),
                'starts_at' => self::dateTime((string)$row['starts_at']),
                'ends_at' => self::dateTime((string)($row['ends_at'] ?: $row['starts_at'])),
                'is_all_day' => (int)($row['is_all_day'] ?? 0) === 1,
                'timezone' => (string)($row['timezone'] ?? 'UTC'),
                'linked_object_type' => (string)($row['linked_object_type'] ?? ''),
                'linked_object_id' => isset($row['linked_object_id']) ? (int)$row['linked_object_id'] : 0,
                'color_key' => (string)($row['color_key'] ?? $type ?: 'other'),
                'icon_key' => (string)($row['icon_key'] ?? 'calendar'),
                'is_locked' => $locked,
                'requires_approval_to_move' => !empty($row['requires_approval_to_move']),
                'description' => (string)($row['description'] ?? ''),
                'created_by' => $row['created_by'] ?? null,
                'updated_by' => $row['updated_by'] ?? null,
                'can_edit_directly' => !$locked,
                'can_delete' => !$locked,
                'metadata' => array(
                    'code' => 'CAL-' . (int)$row['id'],
                    'edit_mode' => 'manual',
                ),
            ));
        }
        return $events;
    }

    /** @return list<array<string,mixed>> */
    private static function auditEvents(PDO $pdo): array
    {
        $rows = self::rows($pdo, "SELECT id, audit_code, title, authority, audit_type, status, start_date, end_date, external_ref, subject, created_by, updated_by, locked_at, updated_at FROM ipca_compliance_audits WHERE start_date IS NOT NULL ORDER BY start_date ASC LIMIT 500");
        $events = array();
        foreach ($rows as $row) {
            $authority = strtoupper((string)($row['authority'] ?? 'INTERNAL'));
            $type = $authority === 'INTERNAL' ? 'internal_audit' : 'authority_audit';
            $locked = !empty($row['locked_at']);
            $events[] = self::event(array(
                'id' => 'audit:' . (int)$row['id'],
                'source_type' => 'audit',
                'source_table' => 'ipca_compliance_audits',
                'source_id' => (int)$row['id'],
                'title' => ($authority === 'INTERNAL' ? 'Internal audit: ' : 'Authority audit: ') . (string)$row['title'],
                'event_type' => $type,
                'status' => (string)($row['status'] ?? 'PLANNED'),
                'governance_state' => $locked ? 'locked' : self::stateFromStatus((string)($row['status'] ?? '')),
                'starts_at' => self::dateStart((string)$row['start_date']),
                'ends_at' => self::dateEnd((string)($row['end_date'] ?: $row['start_date'])),
                'is_all_day' => true,
                'linked_object_type' => 'audit',
                'linked_object_id' => (int)$row['id'],
                'color_key' => $type,
                'icon_key' => $authority === 'INTERNAL' ? 'clipboard' : 'shield',
                'is_locked' => $locked,
                'requires_approval_to_move' => $authority !== 'INTERNAL' || $locked,
                'description' => (string)($row['subject'] ?? ''),
                'created_by' => $row['created_by'] ?? null,
                'updated_by' => $row['updated_by'] ?? null,
                'metadata' => array(
                    'code' => (string)($row['audit_code'] ?? ''),
                    'authority' => $authority,
                    'external_ref' => (string)($row['external_ref'] ?? ''),
                    'linked_url' => '/admin/compliance/audits.php?id=' . (int)$row['id'],
                ),
            ));
        }
        return $events;
    }

    /** @return list<array<string,mixed>> */
    private static function meetingEvents(PDO $pdo): array
    {
        $rows = self::rows($pdo, "SELECT id, case_id, audit_id, meeting_code, title, meeting_type, status, scheduled_start, scheduled_end, location, agenda, created_by, updated_by, locked_at FROM ipca_compliance_meetings WHERE scheduled_start IS NOT NULL ORDER BY scheduled_start ASC LIMIT 500");
        $events = array();
        foreach ($rows as $row) {
            $locked = !empty($row['locked_at']);
            $events[] = self::event(array(
                'id' => 'meeting:' . (int)$row['id'],
                'source_type' => 'meeting',
                'source_table' => 'ipca_compliance_meetings',
                'source_id' => (int)$row['id'],
                'title' => 'Meeting: ' . (string)$row['title'],
                'event_type' => 'meeting',
                'status' => (string)($row['status'] ?? 'SCHEDULED'),
                'governance_state' => $locked ? 'locked' : self::stateFromStatus((string)($row['status'] ?? '')),
                'starts_at' => self::dateTime((string)$row['scheduled_start']),
                'ends_at' => self::dateTime((string)($row['scheduled_end'] ?: $row['scheduled_start'])),
                'is_all_day' => false,
                'linked_object_type' => 'meeting',
                'linked_object_id' => (int)$row['id'],
                'color_key' => 'meeting',
                'icon_key' => 'users',
                'is_locked' => $locked,
                'requires_approval_to_move' => $locked,
                'can_edit_directly' => !$locked,
                'description' => (string)($row['agenda'] ?? ''),
                'created_by' => $row['created_by'] ?? null,
                'updated_by' => $row['updated_by'] ?? null,
                'metadata' => array(
                    'code' => (string)($row['meeting_code'] ?? ''),
                    'meeting_type' => (string)($row['meeting_type'] ?? ''),
                    'location' => (string)($row['location'] ?? ''),
                    'edit_mode' => $locked ? 'request' : 'direct',
                    'linked_url' => '/admin/compliance/meetings.php?id=' . (int)$row['id'],
                ),
            ));
        }
        return $events;
    }

    /** @return list<array<string,mixed>> */
    private static function findingTargetEvents(PDO $pdo): array
    {
        $rows = self::rows($pdo, "SELECT f.id, f.finding_code, f.reference, f.title, f.status, f.target_date, f.audit_id, f.locked_at, f.created_by, f.updated_by, a.authority FROM ipca_compliance_findings f LEFT JOIN ipca_compliance_audits a ON a.id = f.audit_id WHERE f.target_date IS NOT NULL ORDER BY f.target_date ASC LIMIT 500");
        $events = array();
        foreach ($rows as $row) {
            $locked = !empty($row['locked_at']);
            $events[] = self::deadlineEvent($row, array(
                'id' => 'finding-target:' . (int)$row['id'],
                'source_type' => 'finding',
                'source_table' => 'ipca_compliance_findings',
                'title' => 'Finding target: ' . (string)$row['title'],
                'event_type' => 'rca_cap_deadline',
                'date' => (string)$row['target_date'],
                'linked_object_type' => 'finding',
                'color_key' => 'rca_cap_deadline',
                'icon_key' => 'clock',
                'locked' => $locked,
                'metadata' => array(
                    'code' => (string)($row['finding_code'] ?? ''),
                    'authority' => (string)($row['authority'] ?? ''),
                    'linked_url' => '/admin/compliance/findings.php?id=' . (int)$row['id'],
                ),
            ));
        }
        return $events;
    }

    /** @return list<array<string,mixed>> */
    private static function pendingChangeRequestEvents(PDO $pdo): array
    {
        if (!self::tablePresent($pdo, 'ipca_compliance_calendar_change_requests')) {
            return array();
        }
        $rows = self::rows($pdo, "SELECT * FROM ipca_compliance_calendar_change_requests WHERE status = 'pending' ORDER BY proposed_starts_at ASC LIMIT 500");
        $events = array();
        foreach ($rows as $row) {
            $type = (string)($row['event_type'] ?? 'other');
            $events[] = self::event(array(
                'id' => 'change-request:' . (int)$row['id'],
                'source_type' => 'calendar_change_request',
                'source_table' => 'ipca_compliance_calendar_change_requests',
                'source_id' => (int)$row['id'],
                'title' => 'Awaiting approval: ' . (string)$row['title'],
                'event_type' => $type !== '' ? $type : 'other',
                'status' => 'PENDING_APPROVAL',
                'governance_state' => 'pending_approval',
                'starts_at' => self::dateTime((string)$row['proposed_starts_at']),
                'ends_at' => self::dateTime((string)($row['proposed_ends_at'] ?: $row['proposed_starts_at'])),
                'is_all_day' => self::looksAllDay((string)$row['proposed_starts_at'], (string)($row['proposed_ends_at'] ?: '')),
                'timezone' => (string)($row['timezone'] ?? 'UTC'),
                'linked_object_type' => (string)($row['linked_object_type'] ?? ''),
                'linked_object_id' => isset($row['linked_object_id']) ? (int)$row['linked_object_id'] : 0,
                'color_key' => $type !== '' ? $type : 'other',
                'icon_key' => 'hourglass',
                'is_locked' => false,
                'requires_approval_to_move' => true,
                'is_pending_approval' => true,
                'description' => (string)($row['reason'] ?? ''),
                'created_by' => $row['requested_by'] ?? null,
                'updated_by' => $row['reviewed_by'] ?? null,
                'metadata' => array(
                    'code' => 'CCR-' . (int)$row['id'],
                    'edit_mode' => 'approval_queue',
                    'source_event_id' => (string)($row['source_event_id'] ?? ''),
                    'current_starts_at' => self::dateTime((string)($row['current_starts_at'] ?? '')),
                    'current_ends_at' => self::dateTime((string)($row['current_ends_at'] ?? '')),
                ),
            ));
        }
        return $events;
    }

    /** @return list<array<string,mixed>> */
    private static function rcaCapSubmissionEvents(PDO $pdo): array
    {
        $rows = self::rows($pdo, "SELECT s.id, s.finding_id, s.submission_no, s.submission_type, s.status, s.proposed_rca_deadline, s.proposed_cap_deadline, s.approved_rca_deadline, s.approved_cap_deadline, s.submitted_at, s.reviewed_at, s.locked_at, f.finding_code, f.title AS finding_title, f.audit_id, a.authority FROM ipca_compliance_rca_cap_submissions s INNER JOIN ipca_compliance_findings f ON f.id = s.finding_id LEFT JOIN ipca_compliance_audits a ON a.id = f.audit_id ORDER BY COALESCE(s.approved_cap_deadline, s.proposed_cap_deadline, s.approved_rca_deadline, s.proposed_rca_deadline) ASC LIMIT 500");
        $events = array();
        foreach ($rows as $row) {
            foreach (array('rca' => 'RCA submission deadline', 'cap' => 'CAP submission deadline') as $kind => $label) {
                $approved = (string)($row['approved_' . $kind . '_deadline'] ?? '');
                $proposed = (string)($row['proposed_' . $kind . '_deadline'] ?? '');
                $date = $approved !== '' ? $approved : $proposed;
                if ($date === '') {
                    continue;
                }
                $locked = !empty($row['locked_at']) || $approved !== '';
                $events[] = self::deadlineEvent($row, array(
                    'id' => 'rca-cap-submission:' . (int)$row['id'] . ':' . $kind,
                    'source_type' => 'rca_cap_submission',
                    'source_table' => 'ipca_compliance_rca_cap_submissions',
                    'title' => $label . ': ' . (string)$row['finding_title'],
                    'event_type' => 'rca_cap_deadline',
                    'date' => $date,
                    'linked_object_type' => 'finding',
                    'linked_object_id' => (int)$row['finding_id'],
                    'color_key' => 'rca_cap_deadline',
                    'icon_key' => 'clock',
                    'locked' => $locked,
                    'pending' => $approved === '',
                    'metadata' => array(
                        'code' => (string)($row['finding_code'] ?? ''),
                        'deadline_type' => $kind,
                        'submission_type' => (string)($row['submission_type'] ?? ''),
                        'authority' => (string)($row['authority'] ?? ''),
                        'linked_url' => '/admin/compliance/findings.php?id=' . (int)$row['finding_id'],
                    ),
                ));
            }
        }
        return $events;
    }

    /** @return list<array<string,mixed>> */
    private static function correctiveActionEvents(PDO $pdo): array
    {
        $rows = self::rows($pdo, "SELECT c.id, c.finding_id, c.action_code, c.title, c.description, c.status, c.due_date, c.created_by, c.updated_by, c.locked_at, f.finding_code, f.title AS finding_title FROM ipca_compliance_corrective_actions c INNER JOIN ipca_compliance_findings f ON f.id = c.finding_id WHERE c.due_date IS NOT NULL ORDER BY c.due_date ASC LIMIT 500");
        $events = array();
        foreach ($rows as $row) {
            $locked = !empty($row['locked_at']);
            $events[] = self::deadlineEvent($row, array(
                'id' => 'cap:' . (int)$row['id'],
                'source_type' => 'corrective_action',
                'source_table' => 'ipca_compliance_corrective_actions',
                'title' => 'CAP deadline: ' . (string)$row['title'],
                'event_type' => 'corrective_action_deadline',
                'date' => (string)$row['due_date'],
                'linked_object_type' => 'corrective_action',
                'color_key' => 'corrective_action_deadline',
                'icon_key' => 'wrench',
                'locked' => $locked,
                'description' => (string)($row['description'] ?? ''),
                'metadata' => array(
                    'code' => (string)($row['action_code'] ?? ''),
                    'finding' => (string)($row['finding_code'] ?? ''),
                    'linked_url' => '/admin/compliance/corrective_actions.php?id=' . (int)$row['id'],
                ),
            ));
        }
        return $events;
    }

    /** @return list<array<string,mixed>> */
    private static function deadlineExtensionEvents(PDO $pdo): array
    {
        $events = array();
        $rows = self::rows($pdo, "SELECT e.id, e.submission_id, e.deadline_type, e.extension_no, e.previous_deadline, e.requested_deadline, e.approved_deadline, e.reason, e.status, e.submitted_at, s.finding_id, f.finding_code, f.title AS finding_title FROM ipca_compliance_rca_cap_deadline_extensions e INNER JOIN ipca_compliance_rca_cap_submissions s ON s.id = e.submission_id INNER JOIN ipca_compliance_findings f ON f.id = s.finding_id ORDER BY e.requested_deadline ASC LIMIT 300");
        foreach ($rows as $row) {
            $date = (string)($row['approved_deadline'] ?: $row['requested_deadline']);
            if ($date === '') {
                continue;
            }
            $events[] = self::deadlineEvent($row, array(
                'id' => 'rca-cap-extension:' . (int)$row['id'],
                'source_type' => 'deadline_extension',
                'source_table' => 'ipca_compliance_rca_cap_deadline_extensions',
                'title' => 'Extension request: ' . strtoupper((string)$row['deadline_type']) . ' ' . (string)$row['finding_title'],
                'event_type' => 'rca_cap_deadline',
                'date' => $date,
                'linked_object_type' => 'finding',
                'linked_object_id' => (int)$row['finding_id'],
                'color_key' => 'rca_cap_deadline',
                'icon_key' => 'hourglass',
                'pending' => !in_array((string)$row['status'], array('approved', 'rejected', 'withdrawn'), true),
                'metadata' => array(
                    'code' => (string)($row['finding_code'] ?? ''),
                    'extension_no' => (int)$row['extension_no'],
                    'linked_url' => '/admin/compliance/findings.php?id=' . (int)$row['finding_id'],
                ),
            ));
        }

        $rows = self::rows($pdo, "SELECT e.id, e.corrective_action_id, e.extension_no, e.previous_deadline, e.requested_deadline, e.approved_deadline, e.reason, e.status, e.submitted_at, c.action_code, c.title FROM ipca_compliance_corrective_action_deadline_extensions e INNER JOIN ipca_compliance_corrective_actions c ON c.id = e.corrective_action_id ORDER BY e.requested_deadline ASC LIMIT 300");
        foreach ($rows as $row) {
            $date = (string)($row['approved_deadline'] ?: $row['requested_deadline']);
            if ($date === '') {
                continue;
            }
            $events[] = self::deadlineEvent($row, array(
                'id' => 'cap-extension:' . (int)$row['id'],
                'source_type' => 'deadline_extension',
                'source_table' => 'ipca_compliance_corrective_action_deadline_extensions',
                'title' => 'CAP extension request: ' . (string)$row['title'],
                'event_type' => 'corrective_action_deadline',
                'date' => $date,
                'linked_object_type' => 'corrective_action',
                'linked_object_id' => (int)$row['corrective_action_id'],
                'color_key' => 'corrective_action_deadline',
                'icon_key' => 'hourglass',
                'pending' => !in_array((string)$row['status'], array('approved', 'rejected', 'withdrawn'), true),
                'metadata' => array(
                    'code' => (string)($row['action_code'] ?? ''),
                    'extension_no' => (int)$row['extension_no'],
                    'linked_url' => '/admin/compliance/corrective_actions.php?id=' . (int)$row['corrective_action_id'],
                ),
            ));
        }

        return $events;
    }

    /** @return list<array<string,mixed>> */
    private static function effectivenessReviewEvents(PDO $pdo): array
    {
        $rows = self::rows($pdo, "SELECT r.id, r.corrective_action_id, r.effectiveness, r.next_review_due, r.locked_at, c.action_code, c.title FROM ipca_compliance_effectiveness_reviews r INNER JOIN ipca_compliance_corrective_actions c ON c.id = r.corrective_action_id WHERE r.next_review_due IS NOT NULL ORDER BY r.next_review_due ASC LIMIT 300");
        $events = array();
        foreach ($rows as $row) {
            $events[] = self::deadlineEvent($row, array(
                'id' => 'effectiveness:' . (int)$row['id'],
                'source_type' => 'effectiveness_review',
                'source_table' => 'ipca_compliance_effectiveness_reviews',
                'title' => 'Effectiveness review: ' . (string)$row['title'],
                'event_type' => 'effectiveness_review',
                'date' => (string)$row['next_review_due'],
                'linked_object_type' => 'corrective_action',
                'linked_object_id' => (int)$row['corrective_action_id'],
                'color_key' => 'effectiveness_review',
                'icon_key' => 'clipboard-check',
                'locked' => !empty($row['locked_at']),
                'metadata' => array(
                    'code' => (string)($row['action_code'] ?? ''),
                    'effectiveness' => (string)($row['effectiveness'] ?? ''),
                    'linked_url' => '/admin/compliance/corrective_actions.php?id=' . (int)$row['corrective_action_id'],
                ),
            ));
        }
        return $events;
    }

    /** @return list<array<string,mixed>> */
    private static function manualEvents(PDO $pdo): array
    {
        $events = array();
        $rows = self::rows($pdo, "SELECT id, request_code, title, status, priority, raised_at, approved_at, released_at, locked_at, created_by, updated_by FROM ipca_compliance_manual_change_requests WHERE status IN ('SUBMITTED','UNDER_REVIEW','APPROVED') ORDER BY COALESCE(approved_at, raised_at) ASC LIMIT 300");
        foreach ($rows as $row) {
            $dt = (string)($row['approved_at'] ?: $row['raised_at']);
            $events[] = self::event(array(
                'id' => 'manual-change:' . (int)$row['id'],
                'source_type' => 'manual_change_request',
                'source_table' => 'ipca_compliance_manual_change_requests',
                'source_id' => (int)$row['id'],
                'title' => 'Manual change: ' . (string)$row['title'],
                'event_type' => 'manual_change',
                'status' => (string)($row['status'] ?? 'UNDER_REVIEW'),
                'governance_state' => self::stateFromStatus((string)($row['status'] ?? '')),
                'starts_at' => self::dateTime($dt),
                'ends_at' => self::dateTime($dt),
                'is_all_day' => false,
                'linked_object_type' => 'manual_change_request',
                'linked_object_id' => (int)$row['id'],
                'color_key' => 'manual_change',
                'icon_key' => 'book',
                'is_locked' => !empty($row['locked_at']),
                'requires_approval_to_move' => true,
                'created_by' => $row['created_by'] ?? null,
                'updated_by' => $row['updated_by'] ?? null,
                'metadata' => array(
                    'code' => (string)($row['request_code'] ?? ''),
                    'priority' => (string)($row['priority'] ?? ''),
                    'linked_url' => '/admin/compliance/change_requests.php?id=' . (int)$row['id'],
                ),
            ));
        }

        $rows = self::rows($pdo, "SELECT id, package_code, title, manual_code, target_revision, effective_date, status, released_at, locked_at, created_by, updated_by FROM ipca_compliance_manual_release_packages WHERE effective_date IS NOT NULL ORDER BY effective_date ASC LIMIT 300");
        foreach ($rows as $row) {
            $locked = !empty($row['locked_at']) || strtoupper((string)$row['status']) === 'RELEASED';
            $events[] = self::deadlineEvent($row, array(
                'id' => 'manual-release:' . (int)$row['id'],
                'source_type' => 'manual_release_package',
                'source_table' => 'ipca_compliance_manual_release_packages',
                'title' => 'Manual release effective: ' . (string)$row['title'],
                'event_type' => 'manual_change',
                'date' => (string)$row['effective_date'],
                'linked_object_type' => 'manual_release_package',
                'color_key' => 'manual_change',
                'icon_key' => 'book',
                'locked' => $locked,
                'metadata' => array(
                    'code' => (string)($row['package_code'] ?? ''),
                    'manual_code' => (string)($row['manual_code'] ?? ''),
                    'target_revision' => (string)($row['target_revision'] ?? ''),
                    'linked_url' => '/admin/compliance/manual_approved.php?id=' . (int)$row['id'],
                ),
            ));
        }

        return $events;
    }

    /** @return list<array<string,mixed>> */
    private static function partIsEvents(PDO $pdo): array
    {
        $rows = self::rows($pdo, "SELECT r.id, r.risk_code, r.title, r.status, r.next_review_due, r.approved_at, r.asset_id, a.asset_code, a.name AS asset_name FROM ipca_compliance_is_risks r LEFT JOIN ipca_compliance_is_assets a ON a.id = r.asset_id WHERE r.next_review_due IS NOT NULL ORDER BY r.next_review_due ASC LIMIT 300");
        $events = array();
        foreach ($rows as $row) {
            $events[] = self::deadlineEvent($row, array(
                'id' => 'part-is-risk:' . (int)$row['id'],
                'source_type' => 'part_is_risk',
                'source_table' => 'ipca_compliance_is_risks',
                'title' => 'Cyber / Part-IS review: ' . (string)$row['title'],
                'event_type' => 'cyber_part_is',
                'date' => (string)$row['next_review_due'],
                'linked_object_type' => 'part_is_risk',
                'color_key' => 'cyber_part_is',
                'icon_key' => 'shield',
                'locked' => false,
                'metadata' => array(
                    'code' => (string)($row['risk_code'] ?? ''),
                    'asset' => (string)($row['asset_code'] ?? $row['asset_name'] ?? ''),
                    'linked_url' => '/admin/compliance/part_is.php',
                ),
            ));
        }
        return $events;
    }

    /** @return list<array<string,mixed>> */
    private static function monitoringEvents(PDO $pdo): array
    {
        $events = array();
        $rows = self::rows($pdo, "SELECT id, rule_code, title, monitor_kind, is_active, cadence, alert_severity, updated_at FROM ipca_compliance_monitor_rules WHERE monitor_kind = 'REGULATORY' AND is_active = 1 ORDER BY updated_at DESC LIMIT 100");
        foreach ($rows as $row) {
            $events[] = self::event(array(
                'id' => 'reg-monitor:' . (int)$row['id'],
                'source_type' => 'monitor_rule',
                'source_table' => 'ipca_compliance_monitor_rules',
                'source_id' => (int)$row['id'],
                'title' => 'Regulatory review: ' . (string)$row['title'],
                'event_type' => 'regulatory_review',
                'status' => 'ACTIVE',
                'governance_state' => 'approved',
                'starts_at' => self::dateTime((string)$row['updated_at']),
                'ends_at' => self::dateTime((string)$row['updated_at']),
                'is_all_day' => false,
                'linked_object_type' => 'monitor_rule',
                'linked_object_id' => (int)$row['id'],
                'color_key' => 'regulatory_review',
                'icon_key' => 'scale',
                'is_locked' => false,
                'requires_approval_to_move' => false,
                'metadata' => array(
                    'code' => (string)($row['rule_code'] ?? ''),
                    'cadence' => (string)($row['cadence'] ?? ''),
                    'linked_url' => '/admin/compliance/monitoring_rules.php?id=' . (int)$row['id'],
                ),
            ));
        }

        $rows = self::rows($pdo, "SELECT id, subject_type, subject_id, alert_kind, severity, status, title, body, raised_at FROM ipca_compliance_alerts WHERE status IN ('OPEN','ACKNOWLEDGED') ORDER BY raised_at DESC LIMIT 200");
        foreach ($rows as $row) {
            $events[] = self::event(array(
                'id' => 'alert:' . (int)$row['id'],
                'source_type' => 'monitor_alert',
                'source_table' => 'ipca_compliance_alerts',
                'source_id' => (int)$row['id'],
                'title' => 'Authority/internal waiting: ' . (string)$row['title'],
                'event_type' => 'other',
                'status' => (string)($row['status'] ?? 'OPEN'),
                'governance_state' => 'awaiting_response',
                'starts_at' => self::dateTime((string)$row['raised_at']),
                'ends_at' => self::dateTime((string)$row['raised_at']),
                'is_all_day' => false,
                'linked_object_type' => (string)($row['subject_type'] ?? 'alert'),
                'linked_object_id' => isset($row['subject_id']) ? (int)$row['subject_id'] : (int)$row['id'],
                'color_key' => 'other',
                'icon_key' => 'hourglass',
                'is_locked' => false,
                'requires_approval_to_move' => false,
                'description' => (string)($row['body'] ?? ''),
                'metadata' => array(
                    'severity' => (string)($row['severity'] ?? ''),
                    'alert_kind' => (string)($row['alert_kind'] ?? ''),
                    'linked_url' => '/admin/compliance/live_monitoring.php',
                ),
            ));
        }

        return $events;
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $opts @return array<string,mixed> */
    private static function deadlineEvent(array $row, array $opts): array
    {
        $status = (string)($row['status'] ?? 'OPEN');
        $date = (string)$opts['date'];
        $pending = !empty($opts['pending']) || self::stateFromStatus($status) === 'pending';
        $locked = !empty($opts['locked']);

        return self::event(array(
            'id' => (string)$opts['id'],
            'source_type' => (string)$opts['source_type'],
            'source_table' => (string)$opts['source_table'],
            'source_id' => (int)($row['id'] ?? 0),
            'title' => (string)$opts['title'],
            'event_type' => (string)$opts['event_type'],
            'status' => $status,
            'governance_state' => $locked ? 'locked' : ($pending ? 'pending_approval' : self::stateFromStatus($status)),
            'starts_at' => self::dateStart($date),
            'ends_at' => self::dateEnd($date),
            'is_all_day' => true,
            'linked_object_type' => (string)$opts['linked_object_type'],
            'linked_object_id' => (int)($opts['linked_object_id'] ?? $row['id'] ?? 0),
            'color_key' => (string)$opts['color_key'],
            'icon_key' => (string)$opts['icon_key'],
            'is_locked' => $locked,
            'requires_approval_to_move' => true,
            'description' => (string)($opts['description'] ?? $row['reason'] ?? ''),
            'created_by' => $row['created_by'] ?? null,
            'updated_by' => $row['updated_by'] ?? null,
            'is_pending_approval' => $pending,
            'metadata' => isset($opts['metadata']) && is_array($opts['metadata']) ? $opts['metadata'] : array(),
        ));
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private static function event(array $data): array
    {
        $startsAt = (string)($data['starts_at'] ?? '');
        $isOverdue = self::isOverdue($startsAt, (string)($data['status'] ?? ''));
        $pending = !empty($data['is_pending_approval']) || self::stateFromStatus((string)($data['status'] ?? '')) === 'pending';

        return array(
            'id' => (string)($data['id'] ?? ''),
            'source_type' => (string)($data['source_type'] ?? ''),
            'source_table' => (string)($data['source_table'] ?? ''),
            'source_id' => (int)($data['source_id'] ?? 0),
            'title' => (string)($data['title'] ?? ''),
            'event_type' => (string)($data['event_type'] ?? 'other'),
            'status' => (string)($data['status'] ?? ''),
            'governance_state' => (string)($data['governance_state'] ?? 'proposed'),
            'starts_at' => $startsAt,
            'ends_at' => (string)($data['ends_at'] ?? $startsAt),
            'is_all_day' => !empty($data['is_all_day']),
            'timezone' => (string)($data['timezone'] ?? 'UTC'),
            'linked_object_type' => (string)($data['linked_object_type'] ?? ''),
            'linked_object_id' => (int)($data['linked_object_id'] ?? 0),
            'color_key' => (string)($data['color_key'] ?? 'other'),
            'icon_key' => (string)($data['icon_key'] ?? 'circle'),
            'is_locked' => !empty($data['is_locked']),
            'requires_approval_to_move' => !empty($data['requires_approval_to_move']),
            'can_edit_directly' => !empty($data['can_edit_directly']),
            'can_delete' => !empty($data['can_delete']),
            'is_overdue' => $isOverdue,
            'is_pending_approval' => $pending,
            'description' => (string)($data['description'] ?? ''),
            'created_by' => $data['created_by'] ?? null,
            'updated_by' => $data['updated_by'] ?? null,
            'metadata' => isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : array(),
        );
    }

    /** @return list<array<string,mixed>> */
    private static function rows(PDO $pdo, string $sql): array
    {
        try {
            $st = $pdo->query($sql);
            $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
            return is_array($rows) ? $rows : array();
        } catch (Throwable) {
            return array();
        }
    }

    private static function tablePresent(PDO $pdo, string $table): bool
    {
        try {
            $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 0');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private static function dateStart(string $date): string
    {
        return substr(trim($date), 0, 10) . 'T00:00:00';
    }

    private static function dateEnd(string $date): string
    {
        return substr(trim($date), 0, 10) . 'T23:59:59';
    }

    private static function dateTime(string $dateTime): string
    {
        $v = trim($dateTime);
        if ($v === '') {
            return date('Y-m-d\TH:i:s');
        }
        return str_replace(' ', 'T', substr($v, 0, 19));
    }

    private static function looksAllDay(string $start, string $end): bool
    {
        $start = trim($start);
        $end = trim($end);
        return substr($start, 11, 8) === '00:00:00'
            && ($end === '' || substr($end, 11, 8) === '23:59:59');
    }

    private static function safeDate(string $value): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    private static function stateFromStatus(string $status): string
    {
        $s = strtoupper(trim($status));
        if (in_array($s, array('APPROVED', 'SCHEDULED', 'IN_PROGRESS', 'LIVE', 'RELEASED', 'COMPLETED', 'VERIFIED', 'CLOSED'), true)) {
            return 'approved';
        }
        if (in_array($s, array('WAITING_AUTHORITY', 'WAITING_INTERNAL', 'UNDER_REVIEW', 'SUBMITTED', 'ACKNOWLEDGED'), true)) {
            return 'awaiting_response';
        }
        if (in_array($s, array('DRAFT', 'PROPOSED', 'PLANNED', 'PENDING', 'PENDING_APPROVAL', 'AWAITING_APPROVAL'), true)) {
            return 'pending';
        }
        return 'proposed';
    }

    private static function isOverdue(string $startsAt, string $status): bool
    {
        $s = strtoupper(trim($status));
        if (in_array($s, array('CLOSED', 'COMPLETED', 'VERIFIED', 'CANCELLED', 'RELEASED', 'REJECTED', 'WITHDRAWN'), true)) {
            return false;
        }
        $dt = self::safeDate($startsAt);
        if ($dt === null) {
            return false;
        }
        return $dt < new DateTimeImmutable(date('Y-m-d 00:00:00'));
    }
}
