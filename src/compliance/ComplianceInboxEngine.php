<?php
declare(strict_types=1);

require_once __DIR__ . '/ComplianceAutomationDispatch.php';

/**
 * Phase 7 — Compliance Inbox.
 *
 * Manages ipca_compliance_inbound_emails and ipca_compliance_email_links.
 * Real inbound ingestion (Postmark/Mailgun/IMAP) is a separate worker job;
 * this engine focuses on what the UI needs:
 *   - manually capture an inbound email
 *   - triage state machine (NEW -> IN_REVIEW -> ACTIONED / ARCHIVED / IGNORED)
 *   - link an email to an audit / finding / corrective_action / case
 */
final class ComplianceInboxEngine
{
    /** @var list<string> */
    private const TRIAGE_STATES = array('NEW', 'IN_REVIEW', 'ACTIONED', 'ARCHIVED', 'IGNORED');

    /** @var list<string> */
    private const CLASSIFICATIONS = array('AUTHORITY', 'INTERNAL', 'SUPPLIER', 'UNKNOWN', 'SPAM');

    /** @var list<string> */
    private const LINK_ENTITY_TYPES = array('audit', 'finding', 'corrective_action', 'meeting', 'manual_change_request', 'case');

    public static function triageStates(): array
    {
        return self::TRIAGE_STATES;
    }

    public static function classifications(): array
    {
        return self::CLASSIFICATIONS;
    }

    public static function normalizeTriage(string $v): string
    {
        $u = strtoupper(trim($v));

        return in_array($u, self::TRIAGE_STATES, true) ? $u : 'NEW';
    }

    public static function normalizeClassification(string $v): ?string
    {
        $u = strtoupper(trim($v));
        if ($u === '') {
            return null;
        }

        return in_array($u, self::CLASSIFICATIONS, true) ? $u : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listRecent(PDO $pdo, ?string $triage = null, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        if ($triage !== null && $triage !== '') {
            $st = $pdo->prepare(
                'SELECT * FROM ipca_compliance_inbound_emails
                  WHERE triage_state = ?
                  ORDER BY received_at DESC
                  LIMIT ' . (int)$limit
            );
            $st->execute(array(self::normalizeTriage($triage)));
        } else {
            $st = $pdo->query(
                'SELECT * FROM ipca_compliance_inbound_emails
                  ORDER BY received_at DESC
                  LIMIT ' . (int)$limit
            );
        }
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : array();
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function getById(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare('SELECT * FROM ipca_compliance_inbound_emails WHERE id = ? LIMIT 1');
        $st->execute(array($id));
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function manualCreate(PDO $pdo, array $data, int $userId): int
    {
        $from = trim((string)($data['from_email'] ?? ''));
        if ($from === '') {
            throw new InvalidArgumentException('From email is required.');
        }
        $subject = trim((string)($data['subject'] ?? ''));
        if ($subject === '') {
            throw new InvalidArgumentException('Subject is required.');
        }
        $body = trim((string)($data['body_text'] ?? ''));
        $classification = self::normalizeClassification((string)($data['classification'] ?? ''));
        $receivedAt = trim((string)($data['received_at'] ?? ''));
        if ($receivedAt === '') {
            $receivedAt = date('Y-m-d H:i:s');
        } else {
            $receivedAt = str_replace('T', ' ', $receivedAt);
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $receivedAt) === 1) {
                $receivedAt .= ':00';
            }
        }

        $ins = $pdo->prepare(
            'INSERT INTO ipca_compliance_inbound_emails (
                provider, from_email, from_name, to_email, subject, body_text,
                received_at, classification, triage_state, assigned_to
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute(array(
            'MANUAL',
            substr($from, 0, 255),
            self::nullableStr((string)($data['from_name'] ?? ''), 255),
            self::nullableStr((string)($data['to_email'] ?? ''), 255),
            substr($subject, 0, 512),
            $body !== '' ? $body : null,
            $receivedAt,
            $classification,
            'NEW',
            $userId > 0 ? $userId : null,
        ));
        $newId = (int)$pdo->lastInsertId();

        ComplianceAutomationDispatch::fire($pdo, 'compliance.inbox.received', array(
            'inbound_email_id' => $newId,
            'from_email' => $from,
            'subject' => $subject,
            'classification' => $classification,
            'source' => 'MANUAL',
        ));

        return $newId;
    }

    public static function setTriage(PDO $pdo, int $id, string $state, int $userId): void
    {
        $s = self::normalizeTriage($state);
        $pdo->prepare(
            'UPDATE ipca_compliance_inbound_emails SET triage_state = ?, assigned_to = COALESCE(assigned_to, ?) WHERE id = ?'
        )->execute(array($s, $userId > 0 ? $userId : null, $id));
    }

    public static function setClassification(PDO $pdo, int $id, ?string $classification): void
    {
        $c = $classification !== null ? self::normalizeClassification($classification) : null;
        $pdo->prepare(
            'UPDATE ipca_compliance_inbound_emails SET classification = ? WHERE id = ?'
        )->execute(array($c, $id));
    }

    public static function attachToCase(PDO $pdo, int $emailId, ?int $caseId): void
    {
        $pdo->prepare(
            'UPDATE ipca_compliance_inbound_emails SET case_id = ? WHERE id = ?'
        )->execute(array(
            $caseId !== null && $caseId > 0 ? $caseId : null,
            $emailId,
        ));
    }

    public static function attachToAudit(PDO $pdo, int $emailId, ?int $auditId): void
    {
        $pdo->prepare(
            'UPDATE ipca_compliance_inbound_emails SET audit_id = ? WHERE id = ?'
        )->execute(array(
            $auditId !== null && $auditId > 0 ? $auditId : null,
            $emailId,
        ));
    }

    public static function attachToFinding(PDO $pdo, int $emailId, ?int $findingId): void
    {
        $pdo->prepare(
            'UPDATE ipca_compliance_inbound_emails SET finding_id = ? WHERE id = ?'
        )->execute(array(
            $findingId !== null && $findingId > 0 ? $findingId : null,
            $emailId,
        ));
    }

    public static function addLink(PDO $pdo, int $emailId, string $entityType, int $entityId, ?string $relation, int $userId): int
    {
        $type = strtolower(trim($entityType));
        if (!in_array($type, self::LINK_ENTITY_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported entity type for inbox link.');
        }
        if ($entityId <= 0) {
            throw new InvalidArgumentException('Entity id is required.');
        }
        $rel = $relation !== null ? strtoupper(trim($relation)) : null;
        if ($rel !== null && !in_array($rel, array('EVIDENCE', 'NOTIFICATION', 'RESPONSE', 'REFERENCE'), true)) {
            $rel = null;
        }

        $ins = $pdo->prepare(
            'INSERT IGNORE INTO ipca_compliance_email_links
                (email_id, entity_type, entity_id, relation, created_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $ins->execute(array($emailId, $type, $entityId, $rel, $userId > 0 ? $userId : null));

        return (int)$pdo->lastInsertId();
    }

    public static function removeLink(PDO $pdo, int $linkId): void
    {
        $pdo->prepare('DELETE FROM ipca_compliance_email_links WHERE id = ?')
            ->execute(array($linkId));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listLinks(PDO $pdo, int $emailId): array
    {
        $st = $pdo->prepare(
            'SELECT * FROM ipca_compliance_email_links WHERE email_id = ? ORDER BY id ASC'
        );
        $st->execute(array($emailId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : array();
    }

    /**
     * @return array{NEW:int,IN_REVIEW:int,ACTIONED:int,ARCHIVED:int,IGNORED:int}
     */
    public static function triageStats(PDO $pdo): array
    {
        $out = array('NEW' => 0, 'IN_REVIEW' => 0, 'ACTIONED' => 0, 'ARCHIVED' => 0, 'IGNORED' => 0);
        try {
            $st = $pdo->query(
                'SELECT triage_state, COUNT(*) AS n
                   FROM ipca_compliance_inbound_emails
                  GROUP BY triage_state'
            );
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $k = strtoupper((string)($row['triage_state'] ?? ''));
                if (array_key_exists($k, $out)) {
                    $out[$k] = (int)$row['n'];
                }
            }
        } catch (Throwable) {
            // table missing → leave zeroes
        }

        return $out;
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
