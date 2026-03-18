<?php
declare(strict_types=1);

$relationshipOptions = array(
    '' => 'Select relationship',
    'Mother' => 'Mother',
    'Father' => 'Father',
    'Spouse' => 'Spouse',
    'Partner' => 'Partner',
    'Wife' => 'Wife',
    'Husband' => 'Husband',
    'Son' => 'Son',
    'Daughter' => 'Daughter',
    'Brother' => 'Brother',
    'Sister' => 'Sister',
    'Grandmother' => 'Grandmother',
    'Grandfather' => 'Grandfather',
    'Aunt' => 'Aunt',
    'Uncle' => 'Uncle',
    'Guardian' => 'Guardian',
    'Friend' => 'Friend',
    'Relative' => 'Relative',
    'Other' => 'Other',
);
?>

<section class="card ue-card">
    <h3 class="ue-card-title"><?php echo aue_svg('warning'); ?><span>Emergency Contacts</span></h3>
    <p class="ue-card-subtitle">
        Emergency contact data is stored separately in <code>user_emergency_contacts</code> and is part of the instructor-limited operational view.
    </p>

    <style>
        .ue-form-grid{
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:14px;
        }
        .ue-field{
            display:flex;
            flex-direction:column;
            gap:7px;
            min-width:0;
        }
        .ue-field label{
            font-size:12px;
            font-weight:670;
            letter-spacing:.02em;
            color:var(--text-muted);
        }
        .ue-field--full{
            grid-column:1 / -1;
        }
        .ue-input,
        .ue-select{
            width:100%;
            height:44px;
            border-radius:14px;
            box-sizing:border-box;
            padding:0 14px;
        }
        .ue-actions-row{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            margin-top:18px;
        }
        .ue-btn{
            min-height:42px;
            padding:0 16px;
            border-radius:12px;
        }
        .ue-btn svg{
            width:15px;
            height:15px;
            flex:0 0 15px;
        }
        .ue-note{
            color:var(--text-muted);
            font-size:13px;
            line-height:1.6;
        }
        .ue-contact-block{
            padding:16px 18px 18px 18px;
            border:1px solid rgba(15,23,42,0.06);
            border-radius:16px;
            background:#fbfcfe;
        }
        .ue-contact-head{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            margin-bottom:14px;
        }
        .ue-contact-title{
            font-size:15px;
            font-weight:730;
            letter-spacing:-0.02em;
            color:var(--text-strong);
        }
        .ue-contact-chip{
            display:inline-flex;
            align-items:center;
            padding:0 12px;
            height:30px;
            border-radius:999px;
            background:rgba(32,84,176,0.08);
            border:1px solid rgba(32,84,176,0.12);
            color:#2557b3;
            font-size:12px;
            font-weight:700;
        }
        .ue-info-panel{
            margin-top:18px;
            padding:16px 18px;
            border:1px solid rgba(15,23,42,0.06);
            border-radius:16px;
            background:#fbfcfe;
        }
        .ue-info-grid{
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:14px;
        }
        .ue-info-label{
            font-size:11px;
            line-height:1.15;
            text-transform:uppercase;
            letter-spacing:.12em;
            color:#8a97ab;
            font-weight:700;
        }
        .ue-info-value{
            margin-top:6px;
            color:var(--text-strong);
            font-size:14px;
            font-weight:630;
            word-break:break-word;
        }
        .ue-callout{
            margin-top:18px;
            padding:16px 18px;
            border-radius:16px;
            border:1px solid rgba(196,118,11,0.14);
            background:rgba(196,118,11,0.06);
            color:#8b5b06;
            font-size:13px;
            line-height:1.65;
            font-weight:560;
        }
        @media (max-width:900px){
            .ue-form-grid,
            .ue-info-grid{
                grid-template-columns:1fr;
            }
            .ue-contact-head{
                flex-direction:column;
                align-items:flex-start;
            }
        }
    </style>

    <form method="post">
        <input type="hidden" name="form_section" value="emergency">
        <input type="hidden" name="tab" value="emergency">
        <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">

        <div class="ue-form-grid">
            <div class="ue-field ue-field--full">
                <div class="ue-contact-block">
                    <div class="ue-contact-head">
                        <div class="ue-contact-title">Emergency Contact 1</div>
                        <div class="ue-contact-chip">Primary</div>
                    </div>

                    <div class="ue-form-grid">
                        <div class="ue-field ue-field--full">
                            <label for="contact_name_1">Emergency Contact Name 1</label>
                            <input
                                class="app-input ue-input"
                                id="contact_name_1"
                                type="text"
                                name="contact_name_1"
                                value="<?php echo h((string)($emergencyPrimary['contact_name'] ?? '')); ?>"
                                placeholder="Full name"
                            >
                        </div>

                        <div class="ue-field">
                            <label for="relationship_1">Emergency Contact 1 Relationship</label>
                            <select class="app-select ue-select" id="relationship_1" name="relationship_1">
                                <?php foreach ($relationshipOptions as $optionValue => $optionLabel): ?>
                                    <option value="<?php echo h($optionValue); ?>"<?php echo (string)($emergencyPrimary['relationship'] ?? '') === $optionValue ? ' selected' : ''; ?>>
                                        <?php echo h($optionLabel); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="ue-field">
                            <label for="phone_1">Emergency Contact 1 Phone</label>
                            <input
                                class="app-input ue-input"
                                id="phone_1"
                                type="text"
                                name="phone_1"
                                value="<?php echo h((string)($emergencyPrimary['phone'] ?? '')); ?>"
                                placeholder="+1 ..."
                            >
                        </div>
                    </div>
                </div>
            </div>

            <div class="ue-field ue-field--full">
                <div class="ue-contact-block">
                    <div class="ue-contact-head">
                        <div class="ue-contact-title">Emergency Contact 2</div>
                        <div class="ue-contact-chip">Secondary</div>
                    </div>

                    <div class="ue-form-grid">
                        <div class="ue-field ue-field--full">
                            <label for="contact_name_2">Emergency Contact Name 2</label>
                            <input
                                class="app-input ue-input"
                                id="contact_name_2"
                                type="text"
                                name="contact_name_2"
                                value="<?php echo h((string)($emergencySecondary['contact_name'] ?? '')); ?>"
                                placeholder="Full name"
                            >
                        </div>

                        <div class="ue-field">
                            <label for="relationship_2">Emergency Contact 2 Relationship</label>
                            <select class="app-select ue-select" id="relationship_2" name="relationship_2">
                                <?php foreach ($relationshipOptions as $optionValue => $optionLabel): ?>
                                    <option value="<?php echo h($optionValue); ?>"<?php echo (string)($emergencySecondary['relationship'] ?? '') === $optionValue ? ' selected' : ''; ?>>
                                        <?php echo h($optionLabel); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="ue-field">
                            <label for="phone_2">Emergency Contact 2 Phone</label>
                            <input
                                class="app-input ue-input"
                                id="phone_2"
                                type="text"
                                name="phone_2"
                                value="<?php echo h((string)($emergencySecondary['phone'] ?? '')); ?>"
                                placeholder="+1 ..."
                            >
                        </div>
                    </div>
                </div>
            </div>

            <div class="ue-field ue-field--full">
                <div class="ue-note">
                    These phone numbers are among the very few personal fields exposed to instructors in operational scenarios.
                </div>
            </div>
        </div>

        <div class="ue-actions-row">
            <button class="app-btn app-btn-primary ue-btn ue-btn--primary" type="submit">
                <?php echo aue_svg('save'); ?>
                <span>Save Emergency Contacts</span>
            </button>
        </div>
    </form>

    <div class="ue-info-panel">
        <div class="ue-info-grid">
            <div>
                <div class="ue-info-label">Primary Contact</div>
                <div class="ue-info-value">
                    <?php
                    $primarySummary = trim(
                        (string)($emergencyPrimary['contact_name'] ?? '') .
                        (((string)($emergencyPrimary['contact_name'] ?? '') !== '' && (string)($emergencyPrimary['relationship'] ?? '') !== '') ? ' · ' : '') .
                        (string)($emergencyPrimary['relationship'] ?? '')
                    );
                    echo h($primarySummary !== '' ? $primarySummary : '—');
                    ?>
                </div>
            </div>

            <div>
                <div class="ue-info-label">Primary Phone</div>
                <div class="ue-info-value"><?php echo h((string)($emergencyPrimary['phone'] ?? '—')); ?></div>
            </div>

            <div>
                <div class="ue-info-label">Secondary Contact</div>
                <div class="ue-info-value">
                    <?php
                    $secondarySummary = trim(
                        (string)($emergencySecondary['contact_name'] ?? '') .
                        (((string)($emergencySecondary['contact_name'] ?? '') !== '' && (string)($emergencySecondary['relationship'] ?? '') !== '') ? ' · ' : '') .
                        (string)($emergencySecondary['relationship'] ?? '')
                    );
                    echo h($secondarySummary !== '' ? $secondarySummary : '—');
                    ?>
                </div>
            </div>

            <div>
                <div class="ue-info-label">Secondary Phone</div>
                <div class="ue-info-value"><?php echo h((string)($emergencySecondary['phone'] ?? '—')); ?></div>
            </div>
        </div>
    </div>

    <div class="ue-callout">
        Instructors should only see the operational minimum: photo, email, phone number, and emergency phone numbers. This section supports that restricted field-level visibility model.
    </div>
</section>