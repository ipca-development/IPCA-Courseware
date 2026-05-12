<?php
declare(strict_types=1);

require_once __DIR__ . '/ComplianceAutomationDispatch.php';

/**
 * Phase 6 — Monitoring framework.
 *
 * Wraps ipca_compliance_monitor_rules / _runs / _results / ipca_compliance_alerts.
 *
 * Built-in rule evaluators (rule_kind=BUILTIN):
 *   - CAP_OVERDUE       — corrective actions past due_date and not closed.
 *   - CAP_DUE_SOON      — CAPs due within N days (threshold.days, default 7).
 *   - FINDING_HIGH      — open findings with severity HIGH/CRITICAL.
 *   - AUDIT_OVERDUE     — audits with end_date past and status not CLOSED.
 *   - MOC_OPEN          — MoC cases still open (envelope check).
 *
 * Custom rules can store builtin_key in threshold_json["builtin_key"].
 * Other monitor kinds (FSTD/SAFETY/CYBER/LIVE) just use these same evaluators
 * but filtered by monitor_kind for tab grouping in the UI.
 */
final class ComplianceMonitorEngine
{
    /** @var list<string> */
    private const MONITOR_KINDS = array(
        'CAP', 'FSTD', 'SAFETY', 'CYBER', 'LIVE', 'REGULATORY', 'OTHER',
    );

    /** @var list<string> */
    private const SEVERITIES = array('LOW', 'MEDIUM', 'HIGH', 'CRITICAL');

    /** @var list<string> */
    private const CADENCES = array('EVENT', 'HOURLY', 'DAILY', 'WEEKLY', 'MONTHLY', 'CRON');

    /** @var list<string> */
    private const BUILTIN_KEYS = array(
        'CAP_OVERDUE', 'CAP_DUE_SOON', 'FINDING_HIGH', 'AUDIT_OVERDUE', 'MOC_OPEN',
    );

    public static function monitorKinds(): array
    {
        return self::MONITOR_KINDS;
    }

    public static function severities(): array
    {
        return self::SEVERITIES;
    }

    public static function cadences(): array
    {
        return self::CADENCES;
    }

    public static function builtinKeys(): array
    {
        return self::BUILTIN_KEYS;
    }

    public static function normalizeKind(string $v): string
    {
        $u = strtoupper(trim($v));

        return in_array($u, self::MONITOR_KINDS, true) ? $u : 'OTHER';
    }

    public static function normalizeSeverity(string $v): string
    {
        $u = strtoupper(trim($v));

        return in_array($u, self::SEVERITIES, true) ? $u : 'MEDIUM';
    }

    public static function normalizeCadence(string $v): ?string
    {
        $u = strtoupper(trim($v));
        if ($u === '') {
            return null;
        }

        return in_array($u, self::CADENCES, true) ? $u : null;
    }

    public static function normalizeBuiltin(string $v): ?string
    {
        $u = strtoupper(trim($v));

        return in_array($u, self::BUILTIN_KEYS, true) ? $u : null;
    }

    public static function generateRuleCode(PDO $pdo, string $kind): string
    {
        $kind = self::normalizeKind($kind);
        $prefix = strtoupper(substr($kind, 0, 4)) . '-';
        $st = $pdo->prepare('SELECT rule_code FROM ipca_compliance_monitor_rules WHERE rule_code LIKE ? ORDER BY id DESC LIMIT 80');
        $st->execute(array($prefix . '%'));
        $max = 0;
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $code = (string)($row['rule_code'] ?? '');
            if (!str_starts_with($code, $prefix)) {
                continue;
            }
            $n = (int)substr($code, strlen($prefix));
            if ($n > $max) {
                $max = $n;
            }
        }

        return $prefix . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listRules(PDO $pdo, ?string $kind = null, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        if ($kind !== null && $kind !== '') {
            $st = $pdo->prepare(
                'SELECT * FROM ipca_compliance_monitor_rules
                  WHERE monitor_kind = ?
                  ORDER BY is_active DESC, rule_code ASC
                  LIMIT ' . (int)$limit
            );
            $st->execute(array(self::normalizeKind($kind)));
        } else {
            $st = $pdo->query(
                'SELECT * FROM ipca_compliance_monitor_rules
                  ORDER BY is_active DESC, monitor_kind ASC, rule_code ASC
                  LIMIT ' . (int)$limit
            );
        }
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : array();
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function getRule(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare('SELECT * FROM ipca_compliance_monitor_rules WHERE id = ? LIMIT 1');
        $st->execute(array($id));
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public static function createRule(PDO $pdo, array $data, int $userId): int
    {
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Rule title is required.');
        }
        $kind = self::normalizeKind((string)($data['monitor_kind'] ?? 'OTHER'));
        $code = trim((string)($data['rule_code'] ?? ''));
        if ($code === '') {
            $code = self::generateRuleCode($pdo, $kind);
        }
        $sev = self::normalizeSeverity((string)($data['alert_severity'] ?? 'MEDIUM'));
        $cad = self::normalizeCadence((string)($data['cadence'] ?? ''));
        $builtin = self::normalizeBuiltin((string)($data['builtin_key'] ?? ''));
        $thresholdDays = isset($data['threshold_days']) ? (int)$data['threshold_days'] : null;

        $thresholdJson = null;
        if ($builtin !== null || $thresholdDays !== null) {
            $thresholdJson = json_encode(array_filter(array(
                'builtin_key' => $builtin,
                'days' => $thresholdDays,
            ), static fn($v) => $v !== null && $v !== ''));
        }

        $ins = $pdo->prepare(
            'INSERT INTO ipca_compliance_monitor_rules (
                rule_code, title, description, monitor_kind, is_active,
                cadence, cron_expression, event_key, threshold_json,
                alert_severity, notification_keys, created_by, updated_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute(array(
            substr($code, 0, 64),
            substr($title, 0, 255),
            self::nullableStr((string)($data['description'] ?? ''), null),
            $kind,
            (int)(($data['is_active'] ?? 1) ? 1 : 0),
            $cad,
            self::nullableStr((string)($data['cron_expression'] ?? ''), 64),
            self::nullableStr((string)($data['event_key'] ?? ''), 128),
            $thresholdJson,
            $sev,
            self::nullableStr((string)($data['notification_keys'] ?? ''), 255),
            $userId > 0 ? $userId : null,
            $userId > 0 ? $userId : null,
        ));

        return (int)$pdo->lastInsertId();
    }

    public static function updateRule(PDO $pdo, int $id, array $data, int $userId): void
    {
        $row = self::getRule($pdo, $id);
        if ($row === null) {
            throw new RuntimeException('Rule not found.');
        }
        $title = array_key_exists('title', $data) ? trim((string)$data['title']) : (string)$row['title'];
        if ($title === '') {
            throw new InvalidArgumentException('Rule title is required.');
        }
        $kind = array_key_exists('monitor_kind', $data) ? self::normalizeKind((string)$data['monitor_kind']) : (string)$row['monitor_kind'];
        $sev = array_key_exists('alert_severity', $data) ? self::normalizeSeverity((string)$data['alert_severity']) : (string)$row['alert_severity'];
        $isActive = (int)(($data['is_active'] ?? $row['is_active']) ? 1 : 0);
        $cad = array_key_exists('cadence', $data) ? self::normalizeCadence((string)$data['cadence']) : (string)($row['cadence'] ?? '');
        $builtin = array_key_exists('builtin_key', $data) ? self::normalizeBuiltin((string)$data['builtin_key']) : null;
        $thresholdDays = isset($data['threshold_days']) && $data['threshold_days'] !== '' ? (int)$data['threshold_days'] : null;

        $existingThreshold = is_string($row['threshold_json'] ?? null) ? json_decode((string)$row['threshold_json'], true) : null;
        if (!is_array($existingThreshold)) {
            $existingThreshold = array();
        }
        if ($builtin !== null) {
            $existingThreshold['builtin_key'] = $builtin;
        } elseif (array_key_exists('builtin_key', $data) && (string)$data['builtin_key'] === '') {
            unset($existingThreshold['builtin_key']);
        }
        if (array_key_exists('threshold_days', $data)) {
            if ($thresholdDays === null || $thresholdDays <= 0) {
                unset($existingThreshold['days']);
            } else {
                $existingThreshold['days'] = $thresholdDays;
            }
        }
        $thresholdJson = $existingThreshold === array() ? null : json_encode($existingThreshold);

        $pdo->prepare(
            'UPDATE ipca_compliance_monitor_rules SET
                title = ?, description = ?, monitor_kind = ?, is_active = ?,
                cadence = ?, cron_expression = ?, event_key = ?, threshold_json = ?,
                alert_severity = ?, notification_keys = ?,
                updated_by = ?
              WHERE id = ?'
        )->execute(array(
            substr($title, 0, 255),
            array_key_exists('description', $data) ? self::nullableStr((string)$data['description'], null) : ($row['description'] ?? null),
            $kind,
            $isActive,
            $cad,
            array_key_exists('cron_expression', $data) ? self::nullableStr((string)$data['cron_expression'], 64) : ($row['cron_expression'] ?? null),
            array_key_exists('event_key', $data) ? self::nullableStr((string)$data['event_key'], 128) : ($row['event_key'] ?? null),
            $thresholdJson,
            $sev,
            array_key_exists('notification_keys', $data) ? self::nullableStr((string)$data['notification_keys'], 255) : ($row['notification_keys'] ?? null),
            $userId > 0 ? $userId : null,
            $id,
        ));
    }

    public static function deleteRule(PDO $pdo, int $id): void
    {
        $pdo->prepare('DELETE FROM ipca_compliance_monitor_rules WHERE id = ?')->execute(array($id));
    }

    public static function toggleRule(PDO $pdo, int $id, bool $active, int $userId): void
    {
        $pdo->prepare(
            'UPDATE ipca_compliance_monitor_rules SET is_active = ?, updated_by = ? WHERE id = ?'
        )->execute(array($active ? 1 : 0, $userId > 0 ? $userId : null, $id));
    }

    /**
     * Execute a single rule. Returns ['hits' => N, 'results' => N, 'alerts' => N, 'run_id' => ID].
     *
     * @return array{hits:int,results:int,alerts:int,run_id:int}
     */
    public static function runRule(PDO $pdo, int $ruleId, string $triggerSource = 'MANUAL'): array
    {
        $rule = self::getRule($pdo, $ruleId);
        if ($rule === null) {
            throw new RuntimeException('Rule not found.');
        }

        $runIns = $pdo->prepare(
            'INSERT INTO ipca_compliance_monitor_runs (rule_id, run_status, trigger_source)
             VALUES (?, ?, ?)'
        );
        $runIns->execute(array($ruleId, 'RUNNING', $triggerSource));
        $runId = (int)$pdo->lastInsertId();

        $threshold = is_string($rule['threshold_json'] ?? null) ? json_decode((string)$rule['threshold_json'], true) : null;
        if (!is_array($threshold)) {
            $threshold = array();
        }
        $builtin = isset($threshold['builtin_key']) ? self::normalizeBuiltin((string)$threshold['builtin_key']) : null;
        $days = isset($threshold['days']) ? (int)$threshold['days'] : null;

        try {
            $hits = self::evaluateBuiltin($pdo, $builtin, $days, $runId, $ruleId);
            $alerts = self::raiseAlertsForRun($pdo, $rule, $runId);

            $pdo->prepare(
                'UPDATE ipca_compliance_monitor_runs
                    SET completed_at = ?, run_status = ?, result_count = ?, hit_count = ?
                  WHERE id = ?'
            )->execute(array(date('Y-m-d H:i:s'), 'SUCCESS', $hits, $hits, $runId));

            ComplianceAutomationDispatch::fire($pdo, 'compliance.monitor.run', array(
                'rule_id' => $ruleId,
                'rule_code' => (string)$rule['rule_code'],
                'monitor_kind' => (string)$rule['monitor_kind'],
                'run_id' => $runId,
                'hits' => $hits,
                'alerts_raised' => $alerts,
            ));

            return array(
                'hits' => $hits,
                'results' => $hits,
                'alerts' => $alerts,
                'run_id' => $runId,
            );
        } catch (Throwable $e) {
            $pdo->prepare(
                'UPDATE ipca_compliance_monitor_runs
                    SET completed_at = ?, run_status = ?, error_message = ?
                  WHERE id = ?'
            )->execute(array(date('Y-m-d H:i:s'), 'FAILED', substr($e->getMessage(), 0, 4000), $runId));
            throw $e;
        }
    }

    /**
     * @return array{run_id:int,hits:int,results:int,alerts:int}
     */
    public static function runAllActive(PDO $pdo, ?string $kind = null): array
    {
        $rules = self::listRules($pdo, $kind, 500);
        $totalHits = 0;
        $totalAlerts = 0;
        $lastRun = 0;
        foreach ($rules as $r) {
            if ((int)$r['is_active'] !== 1) {
                continue;
            }
            $res = self::runRule($pdo, (int)$r['id'], 'MANUAL');
            $totalHits += $res['hits'];
            $totalAlerts += $res['alerts'];
            $lastRun = $res['run_id'];
        }

        return array(
            'run_id' => $lastRun,
            'hits' => $totalHits,
            'results' => $totalHits,
            'alerts' => $totalAlerts,
        );
    }

    /**
     * Returns count of subjects that hit the threshold.
     */
    private static function evaluateBuiltin(PDO $pdo, ?string $builtinKey, ?int $days, int $runId, int $ruleId): int
    {
        if ($builtinKey === null) {
            return 0;
        }

        $rows = array();
        switch ($builtinKey) {
            case 'CAP_OVERDUE':
                $st = $pdo->query(
                    "SELECT id, action_code AS subject_code, title, due_date
                       FROM ipca_compliance_corrective_actions
                      WHERE due_date IS NOT NULL AND due_date < CURDATE()
                        AND COALESCE(status,'') NOT IN ('CLOSED','VERIFIED','CANCELLED')"
                );
                $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: array();
                $subjectType = 'corrective_action';
                break;

            case 'CAP_DUE_SOON':
                $d = ($days !== null && $days > 0) ? $days : 7;
                $st = $pdo->prepare(
                    "SELECT id, action_code AS subject_code, title, due_date
                       FROM ipca_compliance_corrective_actions
                      WHERE due_date IS NOT NULL
                        AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
                        AND COALESCE(status,'') NOT IN ('CLOSED','VERIFIED','CANCELLED')"
                );
                $st->execute(array($d));
                $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: array();
                $subjectType = 'corrective_action';
                break;

            case 'FINDING_HIGH':
                $st = $pdo->query(
                    "SELECT id, finding_code AS subject_code, title, severity
                       FROM ipca_compliance_findings
                      WHERE severity IN ('HIGH','CRITICAL')
                        AND COALESCE(status,'') NOT IN ('CLOSED','VOID','CANCELLED')"
                );
                $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: array();
                $subjectType = 'finding';
                break;

            case 'AUDIT_OVERDUE':
                $st = $pdo->query(
                    "SELECT id, audit_code AS subject_code, title, end_date
                       FROM ipca_compliance_audits
                      WHERE end_date IS NOT NULL AND end_date < CURDATE()
                        AND status NOT IN ('CLOSED','CANCELLED')"
                );
                $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: array();
                $subjectType = 'audit';
                break;

            case 'MOC_OPEN':
                $st = $pdo->query(
                    "SELECT id, case_code AS subject_code, title, due_at
                       FROM ipca_compliance_cases
                      WHERE case_type = 'MANAGEMENT_OF_CHANGE'
                        AND status NOT IN ('CLOSED','CANCELLED')"
                );
                $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: array();
                $subjectType = 'case';
                break;

            default:
                return 0;
        }

        if ($rows === array()) {
            return 0;
        }

        $ins = $pdo->prepare(
            'INSERT INTO ipca_compliance_monitor_results
                (run_id, rule_id, subject_type, subject_id, is_hit, message, data_json)
             VALUES (?, ?, ?, ?, 1, ?, ?)'
        );
        $hits = 0;
        foreach ($rows as $r) {
            $msg = trim(((string)($r['subject_code'] ?? '')) . ' — ' . ((string)($r['title'] ?? '')));
            $data = $r;
            $ins->execute(array(
                $runId,
                $ruleId,
                $subjectType,
                (int)($r['id'] ?? 0),
                substr($msg, 0, 4000),
                json_encode($data),
            ));
            $hits++;
        }

        return $hits;
    }

    /**
     * For each hit result of this run, raise (or keep open) an alert.
     */
    private static function raiseAlertsForRun(PDO $pdo, array $rule, int $runId): int
    {
        $st = $pdo->prepare(
            'SELECT id, subject_type, subject_id, message
               FROM ipca_compliance_monitor_results
              WHERE run_id = ? AND is_hit = 1'
        );
        $st->execute(array($runId));
        $hits = $st->fetchAll(PDO::FETCH_ASSOC) ?: array();
        if ($hits === array()) {
            return 0;
        }

        $find = $pdo->prepare(
            "SELECT id FROM ipca_compliance_alerts
              WHERE rule_id = ? AND subject_type = ? AND subject_id = ?
                AND status = 'OPEN' LIMIT 1"
        );
        $ins = $pdo->prepare(
            'INSERT INTO ipca_compliance_alerts (
                rule_id, result_id, subject_type, subject_id, alert_kind, severity, status, title, body
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $count = 0;
        foreach ($hits as $h) {
            $find->execute(array((int)$rule['id'], (string)$h['subject_type'], (int)$h['subject_id']));
            if ($find->fetchColumn() !== false) {
                continue; // already open — keep the existing one
            }
            $ins->execute(array(
                (int)$rule['id'],
                (int)$h['id'],
                (string)$h['subject_type'],
                (int)$h['subject_id'],
                'THRESHOLD',
                (string)$rule['alert_severity'],
                'OPEN',
                substr((string)$rule['title'], 0, 200),
                substr((string)$h['message'], 0, 4000),
            ));
            $count++;
        }

        if ($count > 0) {
            ComplianceAutomationDispatch::fire($pdo, 'compliance.alert.raised', array(
                'rule_id' => (int)$rule['id'],
                'rule_code' => (string)$rule['rule_code'],
                'monitor_kind' => (string)$rule['monitor_kind'],
                'severity' => (string)$rule['alert_severity'],
                'count' => $count,
            ));
        }

        return $count;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listAlerts(PDO $pdo, ?string $kind = null, ?string $status = 'OPEN', int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT a.*, r.monitor_kind AS rule_kind, r.rule_code, r.title AS rule_title
                  FROM ipca_compliance_alerts a
             LEFT JOIN ipca_compliance_monitor_rules r ON r.id = a.rule_id
                 WHERE 1=1';
        $args = array();
        if ($kind !== null && $kind !== '') {
            $sql .= ' AND r.monitor_kind = ?';
            $args[] = self::normalizeKind($kind);
        }
        if ($status !== null && $status !== '') {
            $sql .= ' AND a.status = ?';
            $args[] = strtoupper($status);
        }
        $sql .= ' ORDER BY a.raised_at DESC LIMIT ' . (int)$limit;
        $st = $pdo->prepare($sql);
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : array();
    }

    public static function acknowledgeAlert(PDO $pdo, int $alertId, int $userId): void
    {
        $pdo->prepare(
            "UPDATE ipca_compliance_alerts
                SET status = 'ACKNOWLEDGED',
                    acknowledged_by = ?, acknowledged_at = ?
              WHERE id = ? AND status = 'OPEN'"
        )->execute(array($userId > 0 ? $userId : null, date('Y-m-d H:i:s'), $alertId));
    }

    public static function resolveAlert(PDO $pdo, int $alertId, int $userId): void
    {
        $pdo->prepare(
            "UPDATE ipca_compliance_alerts
                SET status = 'RESOLVED',
                    resolved_by = ?, resolved_at = ?
              WHERE id = ?"
        )->execute(array($userId > 0 ? $userId : null, date('Y-m-d H:i:s'), $alertId));
    }

    public static function dismissAlert(PDO $pdo, int $alertId, int $userId): void
    {
        $pdo->prepare(
            "UPDATE ipca_compliance_alerts
                SET status = 'DISMISSED',
                    resolved_by = ?, resolved_at = ?
              WHERE id = ?"
        )->execute(array($userId > 0 ? $userId : null, date('Y-m-d H:i:s'), $alertId));
    }

    /**
     * @return array{open:int,critical:int,acknowledged:int,resolved:int}
     */
    public static function alertStats(PDO $pdo, ?string $kind = null): array
    {
        $sql = "SELECT
                  SUM(CASE WHEN a.status='OPEN' THEN 1 ELSE 0 END) AS open_cnt,
                  SUM(CASE WHEN a.status='OPEN' AND a.severity='CRITICAL' THEN 1 ELSE 0 END) AS crit_cnt,
                  SUM(CASE WHEN a.status='ACKNOWLEDGED' THEN 1 ELSE 0 END) AS ack_cnt,
                  SUM(CASE WHEN a.status='RESOLVED' THEN 1 ELSE 0 END) AS resolved_cnt
                FROM ipca_compliance_alerts a
           LEFT JOIN ipca_compliance_monitor_rules r ON r.id = a.rule_id
               WHERE 1=1";
        $args = array();
        if ($kind !== null && $kind !== '') {
            $sql .= ' AND r.monitor_kind = ?';
            $args[] = self::normalizeKind($kind);
        }
        try {
            $st = $pdo->prepare($sql);
            $st->execute($args);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: array();
        } catch (Throwable) {
            $row = array();
        }

        return array(
            'open' => (int)($row['open_cnt'] ?? 0),
            'critical' => (int)($row['crit_cnt'] ?? 0),
            'acknowledged' => (int)($row['ack_cnt'] ?? 0),
            'resolved' => (int)($row['resolved_cnt'] ?? 0),
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listRuns(PDO $pdo, ?int $ruleId = null, int $limit = 30): array
    {
        $limit = max(1, min(200, $limit));
        if ($ruleId !== null) {
            $st = $pdo->prepare(
                'SELECT * FROM ipca_compliance_monitor_runs
                  WHERE rule_id = ?
                  ORDER BY started_at DESC
                  LIMIT ' . (int)$limit
            );
            $st->execute(array($ruleId));
        } else {
            $st = $pdo->query(
                'SELECT * FROM ipca_compliance_monitor_runs
                  ORDER BY started_at DESC
                  LIMIT ' . (int)$limit
            );
        }
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : array();
    }

    private static function nullableStr(string $v, ?int $max): ?string
    {
        $t = trim($v);
        if ($t === '') {
            return null;
        }

        return $max !== null ? substr($t, 0, $max) : $t;
    }
}
