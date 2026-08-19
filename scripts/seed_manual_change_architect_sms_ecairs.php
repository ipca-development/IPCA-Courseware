<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/publishing/BooksManualsChangeArchitectService.php';

$options = getopt('', array('version::', 'actor:', 'refresh'));
$versionId = max(1, (int)($options['version'] ?? 9));
$actorUserId = max(0, (int)($options['actor'] ?? 0));
if ($actorUserId <= 0) {
    throw new InvalidArgumentException('Provide --actor with the accountable Change Plan owner.');
}

$pdo = cw_db();
$title = 'SMS / ECCAIRS Occurrence Lifecycle';
$existing = $pdo->prepare(
    'SELECT id FROM ipca_manual_ai_architect_plans
     WHERE primary_book_version_id=? AND title=?
     ORDER BY id DESC LIMIT 1'
);
$existing->execute(array($versionId, $title));
$existingId = (int)$existing->fetchColumn();
if ($existingId > 0 && !array_key_exists('refresh', $options)) {
    echo "plan_id={$existingId}\nstatus=existing\n";
    exit(0);
}

$request = implode("\n\n", array(
    'Replace the obsolete Pipedrive-based safety occurrence workflow with the IPCA.training Safety Management System Tool.',
    'The target process must cover initial reporting, human reportability assessment, authority deadlines, ECCAIRS preparation and approval, investigation, corrective or mitigating actions, effectiveness review, evidence, monitoring and controlled closure.',
    'The Reporter submits the initial report. The Safety Manager makes the reportability decision, approves ECCAIRS submissions, conducts the investigation, accepts action effectiveness and authorizes closure. Action Owners implement assigned actions and provide evidence.',
    'The system records an auditable workflow and prevents closure while required decisions, investigation, actions, evidence, effectiveness review or residual-risk acceptance remain incomplete.',
    'Automated initial ECCAIRS preparation and transmission is operational. Automated intermediate and final ECCAIRS amendments are not operational. Until they are available, the Safety Manager must update ECCAIRS directly and maintain a controlled follow-up log.',
    'Existing valid hazard identification, safety-risk assessment, ALARP methodology, safety policy, open-reporting principles and reporter protection must be preserved.',
    'Sections 5.2.5.2 and 5.9 require separate review because replacement hazard-database capability has not been established. Section 6.4 supplier/commercial Pipedrive use remains preserved with justification and outside the occurrence-workflow amendment scope.',
));

if ($existingId > 0) {
    $report = (new BooksManualsChangeArchitectService($pdo))
        ->getCompleteCheckpointReport($existingId);
} else {
    $report = (new BooksManualsChangeArchitectService($pdo))->createAndRunCheckpoint(
        $versionId,
        array(array(
            'title' => 'Approved SMS / ECCAIRS operational change request',
            'reference_code' => 'SMS-ECCAIRS-CHANGE-PLAN',
            'text' => $request,
        )),
        array(
            'title' => $title,
            'objective' => 'Establish the smallest coherent complete amendment architecture for the governed occurrence-reporting lifecycle.',
            'change_request' => $request,
            'use_openai' => false,
            'legacy_terms' => array(
                'Pipedrive',
                'Online Safety Management System',
                'sms.europilotcenter.be',
                'safety.europilotcenter.be',
                'E-OR',
                'E-Occurrence Reporting System',
                'aviationreporting.eu',
            ),
            'replacement_terms' => array(
                'IPCA.training Safety Management System Tool',
                'ECCAIRS',
            ),
            'affected_roles' => array('Reporter', 'Safety Manager', 'Action Owner', 'Accountable Manager'),
            'constraints' => array(
                'Automated intermediate and final ECCAIRS amendments are not operational.',
                'Do not introduce capabilities that are not operationally established.',
            ),
            'preserved_concepts' => array(
                'hazard identification',
                'severity and likelihood risk assessment',
                'ALARP',
                'safety policy',
                'open reporting',
                'reporter protection and just culture',
                'mandatory and voluntary reporting distinction',
                'investigation principles',
            ),
            'authoritative_facts' => array(
                'system_name' => 'IPCA.training Safety Management System Tool',
                'known_limitations' => array(
                    'Automated intermediate and final ECCAIRS amendments are not operational.',
                ),
            ),
        ),
        $actorUserId
    );
}

$planId = (int)($report['id'] ?? $existingId);
$uuid = static function (): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20)
    );
};
$json = static fn(array $value): string =>
    json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$canonicalExcerpt = static function (int $sectionId, int $from, int $to) use ($pdo): array {
    $stmt = $pdo->prepare(
        'SELECT id,stable_anchor,payload_json,content_hash
         FROM ipca_publishing_book_blocks
         WHERE book_version_id=9 AND section_id=? AND sort_order BETWEEN ? AND ?
         ORDER BY sort_order,id'
    );
    $stmt->execute(array($sectionId, $from, $to));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    $text = array();
    foreach ($rows as $row) {
        $payload = json_decode((string)$row['payload_json'], true);
        if (is_array($payload)) {
            $text[] = trim(html_entity_decode(
                strip_tags((string)($payload['html'] ?? implode(' ', (array)($payload['items'] ?? array())))),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            ));
        }
    }
    return array(
        'block_count' => count($rows),
        'first_block_id' => (int)($rows[0]['id'] ?? 0),
        'first_stable_anchor' => (string)($rows[0]['stable_anchor'] ?? ''),
        'excerpt' => mb_strimwidth(trim(implode(' ', array_filter($text))), 0, 2200, '…'),
    );
};

$areas = array(
    array(
        'section_number' => '3.3',
        'section_id' => 58640,
        'section_title' => 'Safety Manager Responsibilities',
        'range' => array(110, 250),
        'treatment' => 'AMEND',
        'rationale' => 'Safety Manager responsibilities must govern reportability, ECCAIRS approval, investigation, action follow-up, effectiveness acceptance and controlled closure without replacing valid accountability logic.',
        'current' => 'The current responsibility and competence provisions remain valid but refer to the superseded occurrence-workflow arrangements.',
        'concepts' => array('Accountability', 'Reportability', 'ECCAIRS approval', 'Closure authority'),
        'preserved' => array('Existing management accountability', 'Existing Safety Manager qualification requirements'),
        'dependencies' => array('5.6', '8.1'),
    ),
    array(
        'section_number' => '4.2',
        'section_id' => 58643,
        'section_title' => 'Control of Records',
        'range' => array(40, 90),
        'treatment' => 'AMEND',
        'rationale' => 'The existing records framework must explicitly retain occurrence decisions, authority submissions, investigation evidence, actions, effectiveness reviews and closure authorization.',
        'current' => 'No existing record-control content or records-table entry is removed.',
        'concepts' => array('Controlled evidence', 'Authority records', 'Decision traceability'),
        'preserved' => array('Existing record-control procedure', 'Existing records table'),
        'dependencies' => array('5.6'),
    ),
    array(
        'section_number' => '5.6',
        'section_id' => 58646,
        'section_title' => 'Occurrence Reporting and Internal Safety Investigation',
        'range' => array(1220, 1460),
        'treatment' => 'RESTRUCTURE',
        'rationale' => 'Primary lifecycle section. Existing reporting, just-culture and investigation principles remain valid, but the section contains the superseded reporting workflow and does not govern intermediate/final ECCAIRS follow-up, action effectiveness or controlled closure.',
        'current' => 'Valid reporting principles, reporter protection, mandatory and voluntary distinctions, authority deadlines and investigation principles are preserved within a coherent lifecycle.',
        'concepts' => array('Reportability', 'ECCAIRS', 'Investigation', 'Actions', 'Effectiveness', 'Closure'),
        'preserved' => array('Open reporting and reporter protection', '72-hour initial notification', 'Investigation principles'),
        'dependencies' => array('3.3', '4.2', '5.7', '8.1'),
    ),
    array(
        'section_number' => '5.7',
        'section_id' => 58646,
        'section_title' => 'Safety Performance Monitoring and Measurement',
        'range' => array(1470, 1720),
        'treatment' => 'AMEND',
        'rationale' => 'Aggregate safety-performance monitoring must receive controlled inputs from the occurrence lifecycle without duplicating case-level monitoring and escalation in Section 5.6.',
        'current' => 'The complete hierarchy, existing studies, reviews, audits, surveys and valid monitoring logic remain unchanged.',
        'concepts' => array('Aggregate trends', 'Assurance', 'Overdue action performance'),
        'preserved' => array('Existing safety performance objectives', 'Existing assurance methods'),
        'dependencies' => array('5.6'),
    ),
    array(
        'section_number' => '8.1',
        'section_id' => 59377,
        'section_title' => 'Safety Training and Communication',
        'range' => array(30, 50),
        'treatment' => 'AMEND',
        'rationale' => 'Role-appropriate competence must support reporting, investigation, corrective-action governance and the transitional direct-ECCAIRS follow-up responsibility.',
        'current' => 'Existing organizational training logic and unrelated training-table content remain valid.',
        'concepts' => array('Role-based competence', 'Corrective actions', 'Direct ECCAIRS follow-up'),
        'preserved' => array('Existing induction framework', 'Existing recurrent training governance'),
        'dependencies' => array('3.3', '5.6'),
    ),
);

$pdo->beginTransaction();
try {
    $pdo->prepare('DELETE FROM ipca_manual_ai_architect_impacts WHERE plan_id=?')->execute(array($planId));
    $insertImpact = $pdo->prepare(
        'INSERT INTO ipca_manual_ai_architect_impacts
          (plan_id,impact_uuid,impact_key,section_id,section_number,section_title,
           impact_type,title,description,treatment,boundary_classification,
           substantive_rationale,current_state_summary,target_concepts_json,
           preserved_logic_json,canonical_evidence_json,dependencies_json,
           minimality_test,completeness_test,confidence,severity,status,impact_fingerprint)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    foreach ($areas as $area) {
        $evidence = $canonicalExcerpt($area['section_id'], $area['range'][0], $area['range'][1]);
        $key = 'approved-sms-ecairs-' . str_replace('.', '-', $area['section_number']);
        $fingerprint = hash('sha256', $json(array($key, $area, $evidence)));
        $insertImpact->execute(array(
            $planId,
            $uuid(),
            $key,
            $area['section_id'],
            $area['section_number'],
            $area['section_title'],
            'section_amendment',
            $area['section_number'] . ' ' . $area['section_title'],
            $area['rationale'],
            $area['treatment'],
            'MUST_CHANGE',
            $area['rationale'],
            $area['current'],
            $json($area['concepts']),
            $json($area['preserved']),
            $json(array($evidence)),
            $json($area['dependencies']),
            'This is the smallest safe canonical treatment for the accepted area.',
            'Together with the other four areas, this covers the complete governed occurrence lifecycle.',
            1,
            $area['section_number'] === '5.6' ? 'high' : 'normal',
            'proposed',
            $fingerprint,
        ));
    }

    $pdo->prepare('DELETE FROM ipca_manual_ai_architect_scope_boundaries WHERE plan_id=?')
        ->execute(array($planId));
    $insertBoundary = $pdo->prepare(
        'INSERT INTO ipca_manual_ai_architect_scope_boundaries
          (plan_id,boundary_uuid,boundary_key,classification,subject_type,
           subject_reference,book_version_id,section_id,rationale,
           boundary_fingerprint,status,created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $boundaryRows = array();
    foreach ($areas as $area) {
        $boundaryRows[] = array(
            'MUST_CHANGE',
            'section',
            $area['section_number'] . ' ' . $area['section_title'],
            $area['section_id'],
            $area['rationale'],
        );
    }
    $boundaryRows = array_merge($boundaryRows, array(
        array('MUST_PRESERVE', 'concept', 'Existing SMS risk-management and ALARP logic', null, 'Valid hazard identification, risk assessment and ALARP principles remain unchanged.'),
        array('MUST_PRESERVE', 'concept', 'Reporter protection and just-culture principles', null, 'Existing valid open-reporting and reporter-protection safeguards remain unchanged.'),
        array('MUST_PRESERVE', 'section', '6.4 Supplier/commercial relationship follow-up', null, 'Pipedrive use here is outside the SMS occurrence workflow and is preserved with justification.'),
        array('OUT_OF_SCOPE', 'section', 'Aircraft Description', null, 'No procedural relationship to the accepted occurrence-workflow change.'),
        array('OUT_OF_SCOPE', 'section', 'Instruction Staff', null, 'No procedural relationship to the accepted occurrence-workflow change.'),
        array('OUT_OF_SCOPE', 'section', 'FSTD procedures', null, 'No procedural relationship to the accepted occurrence-workflow change.'),
        array('OUT_OF_SCOPE', 'section', 'Compliance Audit closure', null, 'Compliance-finding closure is not occurrence-workflow closure.'),
        array('REVIEW_SEPARATELY', 'section', '5.2.5.2 Legacy SMS hazard database', 58646, 'Replacement hazard-database capability is not established by the accepted evidence.'),
        array('REVIEW_SEPARATELY', 'section', '5.9 Legacy aviation safety databases', 58646, 'Legacy database references require a separate governed decision.'),
    ));
    foreach ($boundaryRows as $index => $row) {
        $key = strtolower($row[0]) . '-' . ($index + 1);
        $insertBoundary->execute(array(
            $planId,
            $uuid(),
            $key,
            $row[0],
            $row[1],
            $row[2],
            $versionId,
            $row[3],
            $row[4],
            hash('sha256', $json(array($key, $row))),
            'active',
            $actorUserId,
        ));
    }
    $pdo->prepare(
        "UPDATE ipca_manual_ai_architect_legacy_hits
         SET disposition=CASE
           WHEN section_id=58656 THEN 'PRESERVE_WITH_JUSTIFICATION'
           WHEN block_id IN (34188,34302) THEN 'REVIEW_SEPARATELY'
           ELSE 'REMOVE_OR_REPLACE'
         END,
         disposition_justification=CASE
           WHEN section_id=58656 THEN 'Supplier/commercial relationship follow-up is outside the SMS occurrence-workflow replacement.'
           WHEN block_id IN (34188,34302) THEN 'Replacement database capability is not established; review separately.'
           ELSE 'The reference occurs within the accepted occurrence-workflow amendment scope.'
         END,
         status='decided'
         WHERE plan_id=?"
    )->execute(array($planId));
    $pdo->prepare(
        "UPDATE ipca_manual_ai_architect_plans
         SET status='ready_for_review',stage='impact',updated_by=? WHERE id=?"
    )->execute(array($actorUserId, $planId));
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

echo 'plan_id=' . $planId . "\n";
echo "status=ready_for_review\n";
echo 'impacts=' . count($areas) . "\n";
