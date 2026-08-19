<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/publishing/BooksManualsChangeArchitectService.php';

$options = getopt('', array('version::', 'actor:'));
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
if ($existingId > 0) {
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

echo 'plan_id=' . (int)($report['id'] ?? 0) . "\n";
echo 'status=' . (string)($report['status'] ?? 'unknown') . "\n";
echo 'impacts=' . count((array)($report['impacts'] ?? array())) . "\n";
