<?php
declare(strict_types=1);

/**
 * Provider-free, isolated Manual Change Architect fixture.
 *
 * This is intentionally synthetic. It models the relevant shape of an OMM
 * without reading or mutating a production manual revision.
 *
 * @return array<string,mixed>
 */
return array(
    'manual' => array(
        'book_key' => 'OMM_FIXTURE',
        'title' => 'Organization Management Manual — Isolated SMS Fixture',
        'version_label' => 'fixture-1.0',
        'source_fingerprint' => hash('sha256', 'manual-change-architect-sms-ecairs-fixture-v1'),
        'sections' => array(
            array(
                'id' => 101,
                'number' => '1.1',
                'title' => 'Definitions and Abbreviations',
                'parent_number' => '1',
                'parent_title' => 'General',
                'text' => 'SMS means Safety Management System. ALARP means as low as reasonably practicable. ECCAIRS is the European reporting system.',
            ),
            array(
                'id' => 102,
                'number' => '2.1',
                'title' => 'Safety Policy and Objectives',
                'parent_number' => '2',
                'parent_title' => 'Safety Policy',
                'text' => 'The organization promotes open reporting, protects reporters, and uses safety information for learning and prevention.',
            ),
            array(
                'id' => 103,
                'number' => '3.3',
                'title' => 'Safety Manager',
                'parent_number' => '3',
                'parent_title' => 'Organization and Responsibilities',
                'children' => array(
                    array(
                        'title' => 'Requirements',
                        'text' => 'The Safety Manager requires suitable aviation knowledge, management-system experience, managerial skills, English-language ability and the qualifications appropriate to the role.',
                    ),
                    array(
                        'title' => 'Duties and Responsibilities',
                        'text' => 'The Safety Manager is the focal point responsible for development, administration and maintenance of the SMS. The Safety Manager administers Pipedrive for safety reports, reviews occurrences, assigns actions, and reports significant safety matters to the Accountable Manager.',
                    ),
                    array(
                        'title' => 'Safety Manager Training',
                        'text' => 'The Safety Manager completes initial and recurrent SMS training, including practical safety-management, evaluation and reporting courses.',
                    ),
                ),
            ),
            array(
                'id' => 104,
                'number' => '4.2',
                'title' => 'Control of Safety Records',
                'parent_number' => '4',
                'parent_title' => 'Document and Record Control',
                'text' => 'Safety reports, investigation evidence and action records are retained in Pipedrive. Access is restricted and retention follows the record-retention schedule.',
            ),
            array(
                'id' => 105,
                'number' => '5.1',
                'title' => 'Hazard Identification and Safety Risk Assessment',
                'parent_number' => '5',
                'parent_title' => 'Safety Risk Management',
                'text' => 'Hazards are identified from reactive, proactive and predictive sources. Risks are assessed for severity and likelihood, controlled to ALARP and periodically reviewed.',
            ),
            array(
                'id' => 106,
                'number' => '5.6',
                'title' => 'Occurrence Reporting and Internal Investigation',
                'parent_number' => '5',
                'parent_title' => 'Safety Risk Management',
                'text' => implode(' ', array(
                    'Reporters submit occurrences through the reporting form at https://sms.europilotcenter.be.',
                    'The Safety Manager creates a Pipedrive Project, records the investigation PLAN and stores follow-up in NOTES.',
                    'Reportability, authority deadlines and E-OR submission are tracked by the Safety Manager.',
                    'Corrective actions are assigned and the occurrence is closed after completion evidence is received.',
                )),
            ),
            array(
                'id' => 107,
                'number' => '5.7',
                'title' => 'Safety Performance Monitoring',
                'parent_number' => '5',
                'parent_title' => 'Safety Assurance',
                'text' => 'The Safety Manager monitors occurrence trends and overdue corrective actions. Results are reviewed during the safety review meeting.',
            ),
            array(
                'id' => 108,
                'number' => '8.1',
                'title' => 'Safety Training and Communication',
                'parent_number' => '8',
                'parent_title' => 'Training and Promotion',
                'text' => 'Personnel receive SMS induction. Safety Managers receive practical training in Pipedrive reporting and investigation records.',
            ),
            array(
                'id' => 109,
                'number' => 'A.1',
                'title' => 'Occurrence Report Form',
                'parent_number' => 'A',
                'parent_title' => 'Forms and Annexes',
                'text' => 'The occurrence form captures reporter details, event details, immediate action and confidentiality preference.',
            ),
            array(
                'id' => 110,
                'number' => '6.4',
                'title' => 'Supplier Relationship Follow-up',
                'parent_number' => '6',
                'parent_title' => 'Contracted Activities',
                'text' => 'Commercial supplier leads are tracked in Pipedrive. This process is not part of safety occurrence management.',
            ),
            array(
                'id' => 111,
                'number' => '7.1',
                'title' => 'Aircraft Description',
                'parent_number' => '7',
                'parent_title' => 'Operational Resources',
                'text' => 'The aircraft fleet, equipment and registration details are maintained in the approved aircraft list.',
            ),
            array(
                'id' => 112,
                'number' => '7.2',
                'title' => 'Instruction Staff',
                'parent_number' => '7',
                'parent_title' => 'Personnel',
                'text' => 'Instructor qualifications, standardization and recurrent checking requirements are defined in the Training Manual.',
            ),
            array(
                'id' => 113,
                'number' => '9.2',
                'title' => 'Compliance Audit Finding Closure',
                'parent_number' => '9',
                'parent_title' => 'Compliance Monitoring',
                'text' => 'Compliance audit findings are closed after root-cause review and acceptance of corrective-action evidence by the Compliance Monitoring Manager.',
            ),
            array(
                'id' => 114,
                'number' => '10.3',
                'title' => 'FSTD QTG Procedures',
                'parent_number' => '10',
                'parent_title' => 'FSTD Compliance',
                'text' => 'QTG test discrepancies are recorded in the FSTD report and reviewed by the FSTD Manager.',
            ),
        ),
    ),
    'request' => implode("\n\n", array(
        'Replace the obsolete Pipedrive-based safety occurrence workflow with the IPCA.training Safety Management System Tool.',
        'The target process must cover initial reporting, human reportability assessment, authority deadlines, ECCAIRS preparation and approval, investigation, corrective or mitigating actions, effectiveness review, evidence, monitoring and controlled closure.',
        'The Reporter submits the initial report. The Safety Manager makes the reportability decision, approves ECCAIRS submissions, conducts the investigation, accepts action effectiveness and authorizes closure. Action Owners implement assigned actions and provide evidence.',
        'The system records an auditable workflow and prevents closure while required decisions, investigation, actions, evidence, effectiveness review or residual-risk acceptance remain incomplete.',
        'Automated initial ECCAIRS preparation and transmission is operational. Automated intermediate and final ECCAIRS amendments are not yet operational. Until they are available, the Safety Manager must update ECCAIRS directly and maintain a controlled follow-up log.',
        'Existing valid hazard identification, safety-risk assessment, ALARP methodology, safety policy and open-reporting principles must be preserved.',
        'Low-level implementation details such as REST envelopes, canonical digests and internal hashes are evidence of software controls but should not be copied literally into the approved manual.',
    )),
    'authoritative_evidence' => array(
        'system_name' => 'IPCA.training Safety Management System Tool',
        'obsolete_identities' => array('Pipedrive', 'https://sms.europilotcenter.be'),
        'roles' => array('Reporter', 'Safety Manager', 'Action Owner'),
        'known_limitations' => array(
            'Automated intermediate and final ECCAIRS amendments are not operational.',
        ),
        'preserve' => array(
            'hazard identification',
            'severity and likelihood risk assessment',
            'ALARP',
            'safety policy',
            'open reporting and reporter protection',
        ),
    ),
    'expected' => array(
        'must_change_numbers' => array('3.3', '4.2', '5.6', '5.7', '8.1'),
        'primary_restructure_number' => '5.6',
        'must_preserve_numbers' => array('1.1', '2.1', '5.1'),
        'out_of_scope_numbers' => array('7.1', '7.2', '9.2', '10.3'),
        'review_separately_numbers' => array('6.4'),
        'forbidden_amendment_numbers' => array('7.1', '7.2', '9.2', '10.3'),
        'required_coverage_domains' => array(
            'responsibility',
            'reporting',
            'reportability',
            'investigation',
            'corrective_actions',
            'effectiveness',
            'records_evidence',
            'authority_follow_up',
            'closure',
            'monitoring',
            'training',
            'known_limitation',
        ),
    ),
);
