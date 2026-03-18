<?php
declare(strict_types=1);
?>

<section class="card ue-card">
    <h3 class="ue-card-title"><?php echo aue_svg('warning'); ?><span>Emergency Contact</span></h3>
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
        .ue-input{
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
        .ue-info-panel{
            margin-top:18px;
            padding:16px 18px;
            border:1px solid rgba(15,23,42,0.06);
            border-radius:16px;
            background:#fbfcfe;
        }
        .ue-info-grid{
            display:grid;
            grid-template-columns:repeat(3,minmax(0,1fr));
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
        }
    </style>

    <form method="post">
        <input type="hidden" name="form_section" value="emergency">
        <input type="hidden" name="tab" value="emergency">
        <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">

        <div class="ue-form-grid">
            <div class="ue-field">
                <label for="relationship">Emergency Contact Relationship</label>
                <input
                    class="app-input ue-input"
                    id="relationship"
                    type="text"
                    name="relationship"
                    value="<?php echo h((string)($emergency['relationship'] ?? '')); ?>"
                    placeholder="e.g. Spouse, Parent, Partner"
                >
            </div>

            <div class="ue-field">
                <label for="phone">Emergency Contact Phone</label>
                <input
                    class="app-input ue-input"
                    id="phone"
                    type="text"
                    name="phone"
                    value="<?php echo h((string)($emergency['phone'] ?? '')); ?>"
                    placeholder="+1 ..."
                >
            </div>

            <div class="ue-field ue-field--full">
                <div class="ue-note">
                    This phone number is one of the very few personal fields exposed to instructors in operational scenarios.
                </div>
            </div>
        </div>

        <div class="ue-actions-row">
            <button class="app-btn app-btn-primary ue-btn ue-btn--primary" type="submit">
                <?php echo aue_svg('save'); ?>
                <span>Save Emergency Contact</span>
            </button>
        </div>
    </form>

    <div class="ue-info-panel">
        <div class="ue-info-grid">
            <div>
                <div class="ue-info-label">Relationship</div>
                <div class="ue-info-value"><?php echo h((string)($emergency['relationship'] ?? '—')); ?></div>
            </div>

            <div>
                <div class="ue-info-label">Emergency Phone</div>
                <div class="ue-info-value"><?php echo h((string)($emergency['phone'] ?? '—')); ?></div>
            </div>

            <div>
                <div class="ue-info-label">Instructor Visibility</div>
                <div class="ue-info-value">Visible in limited operational view</div>
            </div>
        </div>
    </div>

    <div class="ue-callout">
        Instructors should only see the operational minimum: photo, email, phone number, and emergency phone. This section supports that restricted field-level visibility model.
    </div>
</section>