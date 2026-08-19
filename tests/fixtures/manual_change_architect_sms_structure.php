<?php
declare(strict_types=1);

/** @return array<string,mixed> */
return array(
    'title' => 'SMM occurrence-workflow structure proposal',
    'rationale' => 'Keep four cross-cutting areas structurally stable and reorganize Section 5.6 as one complete operational lifecycle.',
    'source_fingerprint' => hash('sha256', 'isolated-sms-structure-source-v1'),
    'areas' => array(
        array(
            'section_number' => '3.3',
            'section_title' => 'Safety Manager',
            'treatment' => 'AMEND',
            'human_accepted' => true,
            'source_section_id' => 103,
            'source_anchor' => 'fixture-3.3',
            'dependencies' => array('5.6'),
            'reasoning' => 'Amend responsibilities and competence in place; no hierarchy change is necessary.',
            'current' => array(
                array('number' => '3.3', 'title' => 'Safety Manager', 'children' => array(
                    array('number' => '3.3.1', 'title' => 'Requirements'),
                    array('number' => '3.3.2', 'title' => 'Duties and Responsibilities'),
                    array('number' => '3.3.3', 'title' => 'Safety Manager Training (Initial & Recurrent)'),
                )),
            ),
            'future' => array(
                array('number' => '3.3', 'title' => 'Safety Manager', 'action' => 'PRESERVE', 'children' => array(
                    array('number' => '3.3.1', 'title' => 'Requirements', 'action' => 'PRESERVE'),
                    array('number' => '3.3.2', 'title' => 'Duties and Responsibilities', 'action' => 'PRESERVE',
                        'purpose' => 'Amend content for reportability, ECCAIRS approval, investigation, effectiveness acceptance, and closure authorization.'),
                    array('number' => '3.3.3', 'title' => 'Safety Manager Training (Initial & Recurrent)', 'action' => 'PRESERVE',
                        'purpose' => 'Amend competence content for the governed target workflow and transitional ECCAIRS duties.'),
                )),
            ),
        ),
        array(
            'section_number' => '4.2',
            'section_title' => 'Control of Records',
            'treatment' => 'AMEND',
            'human_accepted' => true,
            'source_section_id' => 104,
            'source_anchor' => 'fixture-4.2',
            'dependencies' => array('5.6'),
            'reasoning' => 'Preserve the hierarchy and amend the record classes, repository, retention, and controlled follow-up evidence.',
            'current' => array(array('number' => '4.2', 'title' => 'Control of Records')),
            'future' => array(array(
                'number' => '4.2',
                'title' => 'Control of Records',
                'action' => 'PRESERVE',
                'purpose' => 'Amend content for occurrence, investigation, action, effectiveness, closure, and authority follow-up records.',
            )),
        ),
        array(
            'section_number' => '5.6',
            'section_title' => 'Occurrence Reporting and Internal Safety Investigations',
            'treatment' => 'RESTRUCTURE',
            'human_accepted' => true,
            'source_section_id' => 106,
            'source_anchor' => 'fixture-5.6',
            'dependencies' => array(),
            'reasoning' => 'Reconstruct the complete lifecycle in operational order while preserving valid reporting and just-culture principles.',
            'current' => array(
                array('number' => '5.6', 'title' => 'Occurrence Reporting and Internal Safety Investigations', 'children' => array(
                    array('number' => '5.6.1', 'title' => 'Mandatory Occurrence Reporting'),
                    array('number' => '5.6.2', 'title' => 'Voluntary Occurrence Reporting'),
                    array('number' => '5.6.3', 'title' => 'Exchange of Information'),
                    array('number' => '5.6.4', 'title' => 'Occurrence Reporting Scheme'),
                    array('number' => '5.6.5', 'title' => 'E-Occurence Report (E-OR)'),
                    array('number' => '5.6.6', 'title' => 'Internal Safety Investigation'),
                )),
            ),
            'future' => array(
                array('number' => '5.6', 'title' => 'Occurrence Reporting and Internal Safety Investigation', 'action' => 'PRESERVE',
                    'purpose' => 'Single governed lifecycle from initial report through controlled closure.', 'children' => array(
                    array('number' => '5.6.1', 'title' => 'Purpose, Scope and Reporting Principles', 'action' => 'SPLIT',
                        'source_references' => array('current 5.6.1', 'current 5.6.2', 'current 5.6.4'),
                        'purpose' => 'Preserve open reporting, reporter protection, learning, confidentiality, and mandatory/voluntary scope.'),
                    array('number' => '5.6.2', 'title' => 'Initial Occurrence Reporting', 'action' => 'MERGE',
                        'source_references' => array('current 5.6.1', 'current 5.6.2', 'current 5.6.5'), 'children' => array(
                        array('number' => '5.6.2.1', 'title' => 'Reporter Submission and Confidentiality', 'action' => 'ADD'),
                        array('number' => '5.6.2.2', 'title' => 'Immediate Actions and Initial Evidence', 'action' => 'ADD'),
                    )),
                    array('number' => '5.6.3', 'title' => 'Intake and Reportability Decision', 'action' => 'SPLIT',
                        'source_references' => array('current 5.6.1', 'current 5.6.2', 'current 5.6.4'), 'children' => array(
                        array('number' => '5.6.3.1', 'title' => 'Safety Manager Triage', 'action' => 'ADD'),
                        array('number' => '5.6.3.2', 'title' => 'Mandatory and Voluntary Reportability', 'action' => 'ADD'),
                        array('number' => '5.6.3.3', 'title' => 'Authority Deadlines', 'action' => 'ADD'),
                    )),
                    array('number' => '5.6.4', 'title' => 'ECCAIRS Reporting and Authority Follow-Up', 'action' => 'MERGE',
                        'source_references' => array('current 5.6.3', 'current 5.6.5'), 'children' => array(
                        array('number' => '5.6.4.1', 'title' => 'Initial ECCAIRS Preparation and Approval', 'action' => 'ADD'),
                        array('number' => '5.6.4.2', 'title' => 'Initial Transmission and Evidence', 'action' => 'ADD'),
                        array('number' => '5.6.4.3', 'title' => 'Intermediate and Final Updates — Transitional Process', 'action' => 'ADD',
                            'purpose' => 'State the current automation limitation and controlled direct-update follow-up log.'),
                    )),
                    array('number' => '5.6.5', 'title' => 'Internal Safety Investigation', 'action' => 'RENAME',
                        'source_references' => array('current 5.6.6'), 'children' => array(
                        array('number' => '5.6.5.1', 'title' => 'Investigation Scope and Plan', 'action' => 'ADD'),
                        array('number' => '5.6.5.2', 'title' => 'Evidence, Analysis and Findings', 'action' => 'ADD'),
                        array('number' => '5.6.5.3', 'title' => 'Recommendations', 'action' => 'ADD'),
                    )),
                    array('number' => '5.6.6', 'title' => 'Corrective and Mitigating Actions', 'action' => 'ADD', 'children' => array(
                        array('number' => '5.6.6.1', 'title' => 'Assignment, Ownership and Due Dates', 'action' => 'ADD'),
                        array('number' => '5.6.6.2', 'title' => 'Implementation Evidence', 'action' => 'ADD'),
                        array('number' => '5.6.6.3', 'title' => 'Residual-Risk Acceptance', 'action' => 'ADD'),
                    )),
                    array('number' => '5.6.7', 'title' => 'Effectiveness Review', 'action' => 'ADD', 'children' => array(
                        array('number' => '5.6.7.1', 'title' => 'Effectiveness Criteria and Results', 'action' => 'ADD'),
                        array('number' => '5.6.7.2', 'title' => 'Safety Manager Acceptance', 'action' => 'ADD'),
                    )),
                    array('number' => '5.6.8', 'title' => 'Monitoring, Escalation and Reconciliation', 'action' => 'ADD', 'children' => array(
                        array('number' => '5.6.8.1', 'title' => 'Open and Overdue Items', 'action' => 'ADD'),
                        array('number' => '5.6.8.2', 'title' => 'Performance Monitoring Cross-Reference', 'action' => 'ADD',
                            'purpose' => 'Coordinate operational monitoring with Section 5.7 without duplicating safety-performance methodology.'),
                    )),
                    array('number' => '5.6.9', 'title' => 'Controlled Closure', 'action' => 'ADD', 'children' => array(
                        array('number' => '5.6.9.1', 'title' => 'Completion Gates', 'action' => 'ADD'),
                        array('number' => '5.6.9.2', 'title' => 'Safety Manager Closure Authorization', 'action' => 'ADD'),
                        array('number' => '5.6.9.3', 'title' => 'Retained Audit Record', 'action' => 'ADD'),
                    )),
                )),
            ),
        ),
        array(
            'section_number' => '5.7',
            'section_title' => 'Safety Performance Monitoring and Measurement',
            'treatment' => 'AMEND',
            'human_accepted' => true,
            'source_section_id' => 107,
            'source_anchor' => 'fixture-5.7',
            'dependencies' => array('5.6'),
            'reasoning' => 'Preserve the hierarchy and align monitoring inputs and overdue-action evidence with Section 5.6.',
            'current' => array(array('number' => '5.7', 'title' => 'Safety Performance Monitoring and Measurement', 'children' => array(
                array('number' => '5.7.1', 'title' => 'Stepwise Approach to Safety Performance Measurement'),
                array('number' => '5.7.2', 'title' => 'Fixing Safety Performance Objectives'),
                array('number' => '5.7.3', 'title' => 'Process'),
            ))),
            'future' => array(array('number' => '5.7', 'title' => 'Safety Performance Monitoring and Measurement', 'action' => 'PRESERVE', 'children' => array(
                array('number' => '5.7.1', 'title' => 'Stepwise Approach to Safety Performance Measurement', 'action' => 'PRESERVE'),
                array('number' => '5.7.2', 'title' => 'Fixing Safety Performance Objectives', 'action' => 'PRESERVE'),
                array('number' => '5.7.3', 'title' => 'Process', 'action' => 'PRESERVE',
                    'purpose' => 'Amend inputs and cross-reference Section 5.6 monitoring, escalation, and closure data.'),
            ))),
        ),
        array(
            'section_number' => '8.1',
            'section_title' => 'Training',
            'treatment' => 'AMEND',
            'human_accepted' => true,
            'source_section_id' => 108,
            'source_anchor' => 'fixture-8.1',
            'dependencies' => array('5.6'),
            'reasoning' => 'Preserve the hierarchy and amend role-based competence requirements.',
            'current' => array(array('number' => '8.1', 'title' => 'Training')),
            'future' => array(array('number' => '8.1', 'title' => 'Training', 'action' => 'PRESERVE',
                'purpose' => 'Amend training for reporters, Safety Managers, Action Owners, target-tool workflow, and transitional ECCAIRS duties.')),
        ),
    ),
);
