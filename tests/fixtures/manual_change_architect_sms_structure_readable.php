<?php
declare(strict_types=1);

$base = require __DIR__ . '/manual_change_architect_sms_structure.php';
$base['title'] = 'SMM occurrence-workflow readable structure proposal';
$base['rationale'] = 'Consolidate target-state controls into operational headings and paragraphs while preserving complete lifecycle coverage.';
$base['source_fingerprint'] = hash('sha256', 'isolated-sms-readable-structure-source-v2');

foreach ($base['areas'] as &$area) {
    if ($area['section_number'] !== '5.6') {
        continue;
    }
    $area['reasoning'] = 'Use nine operational headings. Target-State components remain validation evidence and are not mirrored one-to-one as numbered subsections.';
    $area['future'] = array(
        array(
            'number' => '5.6',
            'title' => 'Occurrence Reporting and Internal Safety Investigation',
            'action' => 'PRESERVE',
            'purpose' => 'Single readable lifecycle from initial report through controlled closure.',
            'children' => array(
                array('number' => '5.6.1', 'title' => 'Purpose, Scope and Reporting Principles', 'action' => 'MERGE',
                    'source_references' => array('current 5.6.1', 'current 5.6.2', 'current 5.6.4')),
                array('number' => '5.6.2', 'title' => 'Initial Occurrence Reporting', 'action' => 'MERGE',
                    'source_references' => array('current 5.6.1', 'current 5.6.2', 'current 5.6.5')),
                array('number' => '5.6.3', 'title' => 'Triage, Reportability and Reporting Deadlines', 'action' => 'MERGE',
                    'source_references' => array('current 5.6.1', 'current 5.6.2', 'current 5.6.4')),
                array('number' => '5.6.4', 'title' => 'Initial ECCAIRS Notification', 'action' => 'MERGE',
                    'source_references' => array('current 5.6.3', 'current 5.6.5')),
                array('number' => '5.6.5', 'title' => 'Internal Safety Investigation', 'action' => 'RENAME',
                    'source_references' => array('current 5.6.6')),
                array('number' => '5.6.6', 'title' => 'Corrective and Mitigating Actions', 'action' => 'ADD'),
                array('number' => '5.6.7', 'title' => 'Intermediate and Final ECCAIRS Follow-up', 'action' => 'ADD'),
                array('number' => '5.6.8', 'title' => 'Monitoring and Escalation', 'action' => 'ADD'),
                array('number' => '5.6.9', 'title' => 'Controlled Closure', 'action' => 'ADD'),
            ),
        ),
    );
}
unset($area);

return $base;
