<?php
declare(strict_types=1);
?>

<section class="card ue-card">
    <h3 class="ue-card-title"><?php echo aue_svg('billing'); ?><span>Billing / Business Profile</span></h3>
    <p class="ue-card-subtitle">
        Billing data is stored in <code>user_billing_profiles</code> to keep financial identity separate from personal and authentication data.
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
        .ue-help{
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
            border:1px solid rgba(32,84,176,0.14);
            background:rgba(32,84,176,0.06);
            color:#214f9c;
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
        <input type="hidden" name="form_section" value="billing">
        <input type="hidden" name="tab" value="billing">
        <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">

        <div class="ue-form-grid">
            <div class="ue-field ue-field--full">
                <label for="business_name">Business Name</label>
                <input
                    class="app-input ue-input"
                    id="business_name"
                    type="text"
                    name="business_name"
                    value="<?php echo h((string)($user['business_name'] ?? '')); ?>"
                    placeholder="Company or entity name"
                >
            </div>

            <div class="ue-field ue-field--full">
                <label for="business_vat_tax_id">Business VAT / Tax ID</label>
                <input
                    class="app-input ue-input"
                    id="business_vat_tax_id"
                    type="text"
                    name="business_vat_tax_id"
                    value="<?php echo h((string)($user['business_vat_tax_id'] ?? '')); ?>"
                    placeholder="VAT, EIN, or tax identifier"
                >
                <div class="ue-help">
                    Used for invoicing and compliance reporting. Stored separately from personal profile data.
                </div>
            </div>
        </div>

        <div class="ue-actions-row">
            <button class="app-btn app-btn-primary ue-btn ue-btn--primary" type="submit">
                <?php echo aue_svg('save'); ?>
                <span>Save Billing Profile</span>
            </button>
        </div>
    </form>

    <div class="ue-info-panel">
        <div class="ue-info-grid">
            <div>
                <div class="ue-info-label">Business Name</div>
                <div class="ue-info-value"><?php echo h((string)($user['business_name'] ?? '—')); ?></div>
            </div>

            <div>
                <div class="ue-info-label">VAT / Tax ID</div>
                <div class="ue-info-value"><?php echo h((string)($user['business_vat_tax_id'] ?? '—')); ?></div>
            </div>

            <div>
                <div class="ue-info-label">Billing Mode</div>
                <div class="ue-info-value">
                    <?php echo empty($user['business_name']) ? 'Private individual' : 'Business account'; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="ue-callout">
        Aircraft rental and certain operational charges may be handled by a separate entity. This billing profile ensures correct invoicing context without mixing operational and contractual responsibilities.
    </div>
</section>