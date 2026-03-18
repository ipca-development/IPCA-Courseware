<?php
declare(strict_types=1);
?>

<section class="card ue-card">
    <h3 class="ue-card-title"><?php echo aue_svg('profile'); ?><span>Profile Details</span></h3>
    <p class="ue-card-subtitle">
        Contact and personal data are stored in <code>user_profiles</code> so the canonical account root remains lean and backward-compatible.
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
            border:1px solid rgba(15,23,42,0.08);
            background:#fff;
            box-sizing:border-box;
            color:var(--text-strong);
            font-size:14px;
            font-weight:560;
            outline:none;
            transition:border-color .16s ease, box-shadow .16s ease;
            padding:0 14px;
        }
        .ue-input:focus,
        .ue-select:focus{
            border-color:rgba(82,133,212,0.45);
            box-shadow:0 0 0 4px rgba(110,174,252,0.12);
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
            border:1px solid rgba(15,23,42,0.08);
            display:inline-flex;
            align-items:center;
            gap:9px;
            background:#fff;
            color:var(--text-strong);
            text-decoration:none;
            font-size:13px;
            font-weight:680;
            cursor:pointer;
            transition:transform .16s ease,border-color .16s ease,background .16s ease;
        }
        .ue-btn:hover{
            transform:translateY(-1px);
            border-color:rgba(16,36,64,0.16);
            background:#f9fbfe;
        }
        .ue-btn--primary{
            background:linear-gradient(180deg,#17345d 0%,#102440 100%);
            color:#fff;
            border-color:transparent;
            box-shadow:0 10px 22px rgba(16,36,64,0.13);
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
        @media (max-width:900px){
            .ue-form-grid,
            .ue-info-grid{
                grid-template-columns:1fr;
            }
        }
    </style>

    <form method="post">
        <input type="hidden" name="form_section" value="profile">
        <input type="hidden" name="tab" value="profile">
        <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">

        <div class="ue-form-grid">
            <div class="ue-field ue-field--full">
                <label for="street_address">Street Address</label>
                <input
                    class="ue-input"
                    id="street_address"
                    type="text"
                    name="street_address"
                    value="<?php echo h((string)($user['street_address'] ?? '')); ?>"
                >
            </div>

            <div class="ue-field">
                <label for="street_number">Street Number</label>
                <input
                    class="ue-input"
                    id="street_number"
                    type="text"
                    name="street_number"
                    value="<?php echo h((string)($user['street_number'] ?? '')); ?>"
                >
            </div>

            <div class="ue-field">
                <label for="zip_code">Zip Code</label>
                <input
                    class="ue-input"
                    id="zip_code"
                    type="text"
                    name="zip_code"
                    value="<?php echo h((string)($user['zip_code'] ?? '')); ?>"
                >
            </div>

            <div class="ue-field">
                <label for="city">City</label>
                <input
                    class="ue-input"
                    id="city"
                    type="text"
                    name="city"
                    value="<?php echo h((string)($user['city'] ?? '')); ?>"
                >
            </div>

            <div class="ue-field">
                <label for="state_region">State / Region</label>
                <input
                    class="ue-input"
                    id="state_region"
                    type="text"
                    name="state_region"
                    value="<?php echo h((string)($user['state_region'] ?? '')); ?>"
                >
            </div>

            <div class="ue-field">
                <label for="country_code">Country Code</label>
                <input
                    class="ue-input"
                    id="country_code"
                    type="text"
                    name="country_code"
                    maxlength="2"
                    value="<?php echo h((string)($user['country_code'] ?? '')); ?>"
                >
            </div>

            <div class="ue-field">
                <label for="cellphone">Cellphone</label>
                <input
                    class="ue-input"
                    id="cellphone"
                    type="text"
                    name="cellphone"
                    value="<?php echo h((string)($user['cellphone'] ?? '')); ?>"
                >
            </div>

            <div class="ue-field ue-field--full">
                <label for="secondary_email">Secondary Email</label>
                <input
                    class="ue-input"
                    id="secondary_email"
                    type="email"
                    name="secondary_email"
                    value="<?php echo h((string)($user['secondary_email'] ?? '')); ?>"
                >
                <div class="ue-help">
                    Secondary contact email is stored in the profile layer, not in the canonical account root.
                </div>
            </div>

            <div class="ue-field">
                <label for="date_of_birth">Date of Birth</label>
                <input
                    class="ue-input"
                    id="date_of_birth"
                    type="date"
                    name="date_of_birth"
                    value="<?php echo h((string)($user['date_of_birth'] ?? '')); ?>"
                >
            </div>

            <div class="ue-field">
                <label for="place_of_birth">Place of Birth</label>
                <input
                    class="ue-input"
                    id="place_of_birth"
                    type="text"
                    name="place_of_birth"
                    value="<?php echo h((string)($user['place_of_birth'] ?? '')); ?>"
                >
            </div>

            <div class="ue-field">
                <label for="nationality">Nationality</label>
                <input
                    class="ue-input"
                    id="nationality"
                    type="text"
                    name="nationality"
                    value="<?php echo h((string)($user['nationality'] ?? '')); ?>"
                >
            </div>

            <div class="ue-field">
                <label for="id_passport_number">ID / Passport Number</label>
                <input
                    class="ue-input"
                    id="id_passport_number"
                    type="text"
                    name="id_passport_number"
                    value="<?php echo h((string)($user['id_passport_number'] ?? '')); ?>"
                >
            </div>

            <div class="ue-field">
                <label for="gender">Gender</label>
                <input
                    class="ue-input"
                    id="gender"
                    type="text"
                    name="gender"
                    value="<?php echo h((string)($user['gender'] ?? '')); ?>"
                >
            </div>

            <div class="ue-field">
                <label for="weight">Weight</label>
                <input
                    class="ue-input"
                    id="weight"
                    type="text"
                    name="weight"
                    value="<?php echo h((string)($user['weight'] ?? '')); ?>"
                >
            </div>

            <div class="ue-field">
                <label for="marital_status">Marital Status</label>
                <input
                    class="ue-input"
                    id="marital_status"
                    type="text"
                    name="marital_status"
                    value="<?php echo h((string)($user['marital_status'] ?? '')); ?>"
                >
            </div>
        </div>

        <div class="ue-actions-row">
            <button class="ue-btn ue-btn--primary" type="submit">
                <?php echo aue_svg('save'); ?>
                <span>Save Profile</span>
            </button>
        </div>
    </form>

    <div class="ue-info-panel">
        <div class="ue-info-grid">
            <div>
                <div class="ue-info-label">Cellphone</div>
                <div class="ue-info-value"><?php echo h((string)($user['cellphone'] ?? '—')); ?></div>
            </div>

            <div>
                <div class="ue-info-label">Secondary Email</div>
                <div class="ue-info-value"><?php echo h((string)($user['secondary_email'] ?? '—')); ?></div>
            </div>

            <div>
                <div class="ue-info-label">Country</div>
                <div class="ue-info-value"><?php echo h((string)($user['country_code'] ?? '—')); ?></div>
            </div>

            <div>
                <div class="ue-info-label">Date of Birth</div>
                <div class="ue-info-value"><?php echo h(aue_human_date((string)($user['date_of_birth'] ?? ''))); ?></div>
            </div>

            <div>
                <div class="ue-info-label">Nationality</div>
                <div class="ue-info-value"><?php echo h((string)($user['nationality'] ?? '—')); ?></div>
            </div>

            <div>
                <div class="ue-info-label">ID / Passport</div>
                <div class="ue-info-value"><?php echo h((string)($user['id_passport_number'] ?? '—')); ?></div>
            </div>
        </div>
    </div>
</section>