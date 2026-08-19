<?php
declare(strict_types=1);

$structureFixture = require __DIR__ . '/manual_change_architect_sms_structure.php';
$acceptedNodes = array();
$walk = static function (array $nodes) use (&$walk, &$acceptedNodes): void {
    foreach ($nodes as $node) {
        $acceptedNodes[] = array('number' => (string)$node['number'], 'title' => (string)$node['title']);
        $walk((array)($node['children'] ?? array()));
    }
};
foreach ($structureFixture['areas'] as $area) {
    $walk($area['future']);
}

$preserve = '[PRESERVE EXISTING APPROVED CONTENT — no wording amendment proposed.]';

return array(
    'authorization' => array(
        'schema' => 'ipca.manual-change-author-brief.v1',
        'authorization' => array(
            'human_approved_impacts_only' => true,
            'accepted_structure_nodes_only' => true,
        ),
        'approved_impacts' => array_map(
            static fn(array $area): array => array(
                'section_number' => $area['section_number'],
                'treatment' => $area['treatment'],
                'status' => 'approved',
            ),
            $structureFixture['areas']
        ),
        'accepted_structure_nodes' => $acceptedNodes,
    ),
    'section_drafts' => array(
        '3.3' => array(
            'title' => 'Safety Manager',
            'treatment' => 'AMEND',
            'implementation_summary' => 'Responsibilities and competence aligned with the governed Section 5.6 lifecycle.',
            'nodes' => array(
                '3.3' => $preserve,
                '3.3.1' => $preserve,
                '3.3.2' => implode("\n\n", array(
                    'In relation to the occurrence-reporting and investigation lifecycle in Section 5.6, the Safety Manager is accountable for triage, the reportability decision, authority-deadline control, completeness review and approval of the applicable ECCAIRS occurrence dataset, investigation oversight, action follow-up, residual-risk acceptance, effectiveness acceptance, authority follow-up and controlled closure.',
                    'The Safety Manager shall ensure that required decisions and evidence are recorded as the lifecycle progresses; overdue, incomplete or conflicting items are escalated; and a report is not closed while a required reportability decision, authority action, investigation, corrective or mitigating action, implementation evidence, residual-risk acceptance, effectiveness review or follow-up remains incomplete.',
                )),
                '3.3.3' => 'Initial and recurrent Safety Manager training shall include the governed lifecycle in Section 5.6, use of the approved Safety Management System Tool, reportability and authority-deadline control, ECCAIRS preparation and approval, investigation governance, action and evidence review, residual-risk acceptance, effectiveness review, monitoring, escalation and closure authorization. Training shall include the transitional process for intermediate and final ECCAIRS updates and the retention of follow-up evidence.',
            ),
        ),
        '4.2' => array(
            'title' => 'Control of Records',
            'treatment' => 'AMEND',
            'implementation_summary' => 'Controlled records aligned with the complete occurrence lifecycle.',
            'nodes' => array(
                '4.2' => implode("\n\n", array(
                    'Records generated under Section 5.6 shall be controlled as SMS records. They include the initial report and acknowledgements; triage and reportability decisions; authority deadlines; ECCAIRS preparation, approval, transmission and authority acknowledgements; investigation plans, evidence, analysis, findings and recommendations; action assignments, due dates and implementation evidence; residual-risk acceptances; effectiveness reviews; monitoring and escalation records; authority follow-up; and closure authorization.',
                    'Records shall identify the accountable person and date, remain traceable to the relevant report or occurrence, be protected against unauthorized access or alteration, and be retained in accordance with the approved retention requirements. While automated intermediate and final ECCAIRS updates are not operational, direct updates made by the Safety Manager and the controlled follow-up record shall form part of the retained occurrence file.',
                )),
            ),
        ),
        '5.6' => array(
            'title' => 'Occurrence Reporting and Internal Safety Investigation',
            'treatment' => 'RESTRUCTURE',
            'implementation_summary' => 'Complete lifecycle reconstructed in accepted operational order.',
            'nodes' => array(
                '5.6' => 'EuroPilot Center maintains a controlled occurrence-reporting and internal safety-investigation lifecycle from initial report through controlled closure. The lifecycle assigns accountable roles, records the evidence supporting each decision and prevents progression or closure where a required control remains incomplete.',
                '5.6.1' => implode("\n\n", array(
                    'The occurrence-reporting scheme supports early reporting, organizational learning and prevention. Reports may be mandatory or voluntary. Personnel are encouraged to report safety concerns without delay and are protected in accordance with the Safety Policy and applicable just-culture principles.',
                    'The scheme shall preserve confidentiality, restrict access to those with an authorized safety function and use reported information for safety purposes. Reporting does not replace immediate operational action required to protect persons, aircraft or operations.',
                )),
                '5.6.2' => 'The initial-reporting stage captures the information reasonably available to the Reporter, the circumstances of the event or concern, immediate safety action and any available supporting evidence. Submission starts the controlled lifecycle but does not itself determine authority reportability.',
                '5.6.2.1' => 'The Reporter shall submit the report through the approved reporting arrangement as soon as practicable and provide the facts reasonably available at that time. The Reporter shall identify any immediate action taken and may request the applicable confidentiality protection. The Reporter is not responsible for completing the authority dataset or making the reportability decision. Where an approved anonymous-reporting arrangement is available, it shall preserve a controlled means for safety follow-up without exposing the Reporter’s identity.',
                '5.6.2.2' => 'Any immediate action needed to contain an urgent safety risk shall be recorded with the initial report or added at the earliest opportunity. Available photographs, documents, statements or other relevant material shall be linked to the controlled occurrence record. Missing initial evidence shall be identified for Safety Manager follow-up and shall not be represented as complete.',
                '5.6.3' => 'The Safety Manager shall review each submitted report, establish the controlled occurrence record, determine the required next actions and ensure that safety-critical matters are escalated without delay.',
                '5.6.3.1' => 'The Safety Manager shall acknowledge intake, review the initial facts, identify immediate safety concerns, request necessary clarification, determine whether an internal investigation is required and assign the report an appropriate controlled status. Triage decisions and requests for further information shall be recorded.',
                '5.6.3.2' => 'The Safety Manager shall make and record the reportability decision against the applicable authority-reporting requirements. The decision shall identify the governing basis, rationale and any information still required. A pending-information status may be used only while the decision cannot reasonably be completed and shall be actively followed up.',
                '5.6.3.3' => 'For every potentially reportable occurrence, the Safety Manager shall establish the applicable authority deadline at the earliest opportunity and monitor it to completion. The deadline, responsible person, submission status and any escalation shall be retained. Missing information does not suspend an applicable authority deadline; it shall be handled under the applicable reporting requirements.',
                '5.6.4' => 'Authority reporting shall be managed as a controlled sequence of dataset preparation, Safety Manager validation and approval, transmission, acknowledgement and subsequent authority follow-up.',
                '5.6.4.1' => implode("\n\n", array(
                    'The Safety Manager is responsible for the completeness and validation of the applicable ECCAIRS occurrence dataset before submission. Applicable mandatory authority-reporting information shall be complete before transmission.',
                    'Information not reasonably expected from the initial Reporter shall be completed or classified during Safety Manager review. Unavailable information shall be identified and handled in accordance with the applicable reporting requirements. Applicable conditional information shall be included when relevant to the occurrence.',
                    'The Safety Manager shall resolve or explicitly govern validation issues and shall ensure that the report is reviewed and approved before authority transmission. Exact fields, taxonomy, validation rules and technical mappings are controlled in the applicable operational or system specification and are not reproduced in this manual.',
                )),
                '5.6.4.2' => 'Following Safety Manager approval, the approved initial ECCAIRS report shall be transmitted through the approved authority-reporting connection. The occurrence record shall retain the approved submission version, transmission status, authority acknowledgement or reference, transmission time and any failed or repeated attempt. The Safety Manager remains accountable for confirming that the initial authority-reporting obligation has been completed.',
                '5.6.4.3' => 'Automated intermediate and final ECCAIRS updates are not operational. Until that capability is formally established, the Safety Manager shall make required intermediate and final updates directly through the approved authority arrangement. Each direct update, due date, submission reference, acknowledgement and outstanding authority action shall be retained in a controlled follow-up record linked to the occurrence.',
                '5.6.5' => 'An internal safety investigation shall establish what occurred, why it occurred, the associated hazards and risks, and the actions needed to prevent recurrence. The investigation shall remain proportionate to the actual or potential safety significance.',
                '5.6.5.1' => 'The Safety Manager shall define and record the investigation scope, responsible investigator, required evidence, interfaces, planned activities and target completion date. The plan shall be revised when material new information changes the required scope.',
                '5.6.5.2' => 'Investigation evidence shall be retained and assessed for reliability and relevance. The analysis shall identify material facts, contributing factors, causal relationships, hazards and risk implications. Conclusions shall be supported by the retained evidence and distinguish established facts from assumptions or unresolved matters.',
                '5.6.5.3' => 'Investigation recommendations shall address the identified safety issues without prescribing unsupported technical solutions. Each accepted recommendation requiring action shall be transferred into the controlled action process with traceability to the supporting finding.',
                '5.6.6' => 'Corrective and mitigating actions shall be controlled from assignment through implementation evidence and residual-risk acceptance. Actions shall be proportionate to the safety significance and traceable to the relevant finding, hazard or risk.',
                '5.6.6.1' => 'Each action shall have a defined outcome, accountable Action Owner, due date and status. The Safety Manager shall approve the assignment and monitor due dates. Delay, rejection, reassignment or material change shall be recorded with its rationale and escalated where safety or authority commitments may be affected.',
                '5.6.6.2' => 'The Action Owner shall provide objective evidence that the action was implemented. The evidence shall identify what was completed, by whom and when, and shall be sufficient for the Safety Manager to assess implementation and proceed to effectiveness review.',
                '5.6.6.3' => 'Where the occurrence or action leaves residual safety risk, the Safety Manager shall ensure that the residual risk is assessed using the approved risk methodology and formally accepted at the authorized level before closure. The assessment, acceptance rationale, accepting person and date shall be retained.',
                '5.6.7' => 'Implemented actions shall be subject to an effectiveness review before final acceptance. Completion of an activity alone does not demonstrate that the intended safety outcome has been achieved.',
                '5.6.7.1' => 'The Safety Manager shall define the effectiveness criteria, review method, required evidence and review date. The result shall state whether the action is effective, partially effective or ineffective and identify any further action required.',
                '5.6.7.2' => 'The Safety Manager shall accept the effectiveness result only when the retained evidence demonstrates the intended outcome. An ineffective or incomplete result shall reopen or create the necessary action, assign accountability and establish a new due date.',
                '5.6.8' => 'The Safety Manager shall monitor open occurrences, authority commitments, investigations, actions, evidence requests, effectiveness reviews and closure conditions. Exceptions shall be escalated according to safety significance and overdue status.',
                '5.6.8.1' => 'Open and overdue items shall be reviewed at a defined frequency. The controlled record shall identify the item, accountable role, due date, current status, escalation action and next review date. Safety-critical or authority-deadline exceptions shall be escalated immediately.',
                '5.6.8.2' => 'Occurrence, hazard, action, effectiveness and closure information shall provide controlled inputs to the safety-performance monitoring process in Section 5.7. Section 5.7 governs aggregate objectives, indicators and trends; this section governs the operational follow-up and reconciliation of individual occurrences.',
                '5.6.9' => 'An occurrence may be closed only after all applicable lifecycle requirements are complete and the Safety Manager has authorized closure. Closure shall preserve a traceable record of the decisions and evidence on which it is based.',
                '5.6.9.1' => 'Before closure, the Safety Manager shall verify completion of triage; a final reportability decision; applicable authority deadlines, submissions and follow-up; the required investigation; corrective or mitigating actions and implementation evidence; residual-risk acceptance where applicable; effectiveness review; required monitoring or escalation; and outcome feedback where required. An unmet condition is a closure gate and prevents closure.',
                '5.6.9.2' => 'The Safety Manager shall record the closure decision, rationale and date and confirm that no required action, evidence, authority response or review remains open. Closure authorization shall not be delegated to the system.',
                '5.6.9.3' => 'The retained audit record shall link the initial report, material decisions, responsible roles, deadlines, authority records, investigation evidence, actions, risk acceptance, effectiveness review, monitoring and closure authorization. The record shall support subsequent internal review, compliance oversight and authority inspection.',
            ),
        ),
        '5.7' => array(
            'title' => 'Safety Performance Monitoring and Measurement',
            'treatment' => 'AMEND',
            'implementation_summary' => 'Existing methodology preserved; operational inputs aligned with Section 5.6.',
            'nodes' => array(
                '5.7' => $preserve,
                '5.7.1' => $preserve,
                '5.7.2' => $preserve,
                '5.7.3' => 'The safety-performance monitoring process shall use controlled information from Section 5.6, including occurrence categories, reportability outcomes, authority deadlines and completion, investigation findings, open and overdue actions, residual-risk acceptances, effectiveness outcomes, escalation status and closure performance. Aggregate monitoring shall identify trends and systemic issues without replacing the accountable follow-up of individual occurrences required by Section 5.6.',
            ),
        ),
        '8.1' => array(
            'title' => 'Training',
            'treatment' => 'AMEND',
            'implementation_summary' => 'Role-based lifecycle competence established without changing hierarchy.',
            'nodes' => array(
                '8.1' => 'Personnel shall receive training appropriate to their role in the Section 5.6 lifecycle. Reporters shall understand reporting channels, timely submission, immediate-action information, confidentiality and reporter protection. Safety Managers shall be competent in triage, reportability, deadline control, ECCAIRS preparation and approval, investigation governance, action and evidence review, residual-risk acceptance, effectiveness review, monitoring, escalation, authority follow-up and closure. Action Owners shall understand assignment acceptance, due dates, implementation evidence and follow-up. Relevant Safety Managers shall also be trained in the controlled direct-update process used while automated intermediate and final ECCAIRS updates are not operational.',
            ),
        ),
    ),
    'lifecycle' => array(
        array('state' => 'INITIAL_REPORT', 'accountable_role' => 'Reporter', 'required_evidence' => 'Submitted report, available facts, immediate action and attachments.', 'deadline_control' => 'Submit as soon as practicable; urgent safety action is immediate.', 'closure_gate' => 'A controlled report record exists.'),
        array('state' => 'TRIAGE', 'accountable_role' => 'Safety Manager', 'required_evidence' => 'Acknowledgement, triage record, clarification requests and initial status.', 'deadline_control' => 'Safety-critical matters escalated immediately.', 'closure_gate' => 'Triage and required immediate actions are complete.'),
        array('state' => 'REPORTABILITY_DECISION', 'accountable_role' => 'Safety Manager', 'required_evidence' => 'Decision, governing basis, rationale and information status.', 'deadline_control' => 'Decision controlled against the authority deadline.', 'closure_gate' => 'A final decision exists for every occurrence.'),
        array('state' => 'AUTHORITY_DEADLINE_CONTROL', 'accountable_role' => 'Safety Manager', 'required_evidence' => 'Applicable deadline, owner, status and escalation record.', 'deadline_control' => 'Deadline established at the earliest opportunity and monitored.', 'closure_gate' => 'Every applicable deadline is satisfied or governed.'),
        array('state' => 'ECCAIRS_PREPARATION_APPROVAL', 'accountable_role' => 'Safety Manager', 'required_evidence' => 'Validated applicable dataset, resolved validation issues and approval.', 'deadline_control' => 'Preparation and approval completed before transmission deadline.', 'closure_gate' => 'Applicable information is complete, classified or governed and approved.'),
        array('state' => 'TRANSMISSION', 'accountable_role' => 'Safety Manager', 'required_evidence' => 'Approved version, transmission status, timestamp and authority acknowledgement.', 'deadline_control' => 'Initial transmission completed within the applicable deadline.', 'closure_gate' => 'Transmission obligation and failed-attempt follow-up are complete.'),
        array('state' => 'INVESTIGATION', 'accountable_role' => 'Safety Manager', 'required_evidence' => 'Plan, evidence, analysis, findings, conclusions and recommendations.', 'deadline_control' => 'Investigation target date is recorded and monitored.', 'closure_gate' => 'Required investigation is completed.'),
        array('state' => 'ACTIONS', 'accountable_role' => 'Action Owner', 'required_evidence' => 'Assignment, due date, status and objective implementation evidence.', 'deadline_control' => 'Due dates monitored by the Safety Manager.', 'closure_gate' => 'All required actions have implementation evidence.'),
        array('state' => 'RESIDUAL_RISK_ACCEPTANCE', 'accountable_role' => 'Safety Manager', 'required_evidence' => 'Residual-risk assessment, rationale, acceptance identity and date.', 'deadline_control' => 'Completed after implementation and before closure.', 'closure_gate' => 'Applicable residual risk is formally accepted.'),
        array('state' => 'EFFECTIVENESS_REVIEW', 'accountable_role' => 'Safety Manager', 'required_evidence' => 'Criteria, method, evidence, result and acceptance.', 'deadline_control' => 'Review date set when the action is accepted for monitoring.', 'closure_gate' => 'Every applicable action has an accepted effectiveness result.'),
        array('state' => 'MONITORING_ESCALATION', 'accountable_role' => 'Safety Manager', 'required_evidence' => 'Open/overdue register, escalation action and next review date.', 'deadline_control' => 'Defined review frequency; immediate escalation for critical exceptions.', 'closure_gate' => 'No unresolved safety-critical or overdue closure condition remains.'),
        array('state' => 'AUTHORITY_FOLLOW_UP', 'accountable_role' => 'Safety Manager', 'required_evidence' => 'Intermediate/final direct updates, references, acknowledgements and follow-up record.', 'deadline_control' => 'Each authority due date is recorded and monitored.', 'closure_gate' => 'All applicable authority follow-up is complete.'),
        array('state' => 'CONTROLLED_CLOSURE', 'accountable_role' => 'Safety Manager', 'required_evidence' => 'Completed gate checklist, closure rationale, authorization and retained audit record.', 'deadline_control' => 'Closure only after all preceding controls are complete.', 'closure_gate' => 'Safety Manager authorization; the system cannot authorize closure.'),
    ),
);
