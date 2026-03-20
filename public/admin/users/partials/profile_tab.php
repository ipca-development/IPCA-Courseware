<?php
declare(strict_types=1);

$countryOptions = array(
    '' => 'Select country',
    'US' => 'United States',
    'CA' => 'Canada',
    'MX' => 'Mexico',
    'BE' => 'Belgium',
    'NL' => 'Netherlands',
    'DE' => 'Germany',
    'FR' => 'France',
    'ES' => 'Spain',
    'IT' => 'Italy',
    'PT' => 'Portugal',
    'GB' => 'United Kingdom',
    'IE' => 'Ireland',
    'CH' => 'Switzerland',
    'AT' => 'Austria',
    'LU' => 'Luxembourg',
    'SE' => 'Sweden',
    'NO' => 'Norway',
    'DK' => 'Denmark',
    'FI' => 'Finland',
    'PL' => 'Poland',
    'CZ' => 'Czech Republic',
    'HU' => 'Hungary',
    'RO' => 'Romania',
    'BG' => 'Bulgaria',
    'HR' => 'Croatia',
    'GR' => 'Greece',
    'TR' => 'Turkey',
    'UA' => 'Ukraine',
    'IS' => 'Iceland',
    'AU' => 'Australia',
    'NZ' => 'New Zealand',
    'ZA' => 'South Africa',
    'AE' => 'United Arab Emirates',
    'SA' => 'Saudi Arabia',
    'QA' => 'Qatar',
    'KW' => 'Kuwait',
    'IL' => 'Israel',
    'EG' => 'Egypt',
    'MA' => 'Morocco',
    'IN' => 'India',
    'PK' => 'Pakistan',
    'BD' => 'Bangladesh',
    'LK' => 'Sri Lanka',
    'NP' => 'Nepal',
    'TH' => 'Thailand',
    'VN' => 'Vietnam',
    'MY' => 'Malaysia',
    'SG' => 'Singapore',
    'ID' => 'Indonesia',
    'PH' => 'Philippines',
    'CN' => 'China',
    'HK' => 'Hong Kong',
    'TW' => 'Taiwan',
    'JP' => 'Japan',
    'KR' => 'South Korea',
    'BR' => 'Brazil',
    'AR' => 'Argentina',
    'CL' => 'Chile',
    'CO' => 'Colombia',
    'PE' => 'Peru',
    'VE' => 'Venezuela',
);

$genderOptions = array(
    '' => 'Select gender',
    'Male' => 'Male',
    'Female' => 'Female',
    'Non-Binary' => 'Non-Binary',
    'Prefer Not to Say' => 'Prefer Not to Say',
    'Other' => 'Other',
);

$maritalStatusOptions = array(
    '' => 'Select marital status',
    'Single' => 'Single',
    'Married' => 'Married',
    'Separated' => 'Separated',
    'Divorced' => 'Divorced',
    'Widowed' => 'Widowed',
    'Domestic Partnership' => 'Domestic Partnership',
    'Civil Union' => 'Civil Union',
    'Prefer Not to Say' => 'Prefer Not to Say',
);

$hairColorOptions = array(
    '' => 'Select hair color',
    'Black' => 'Black',
    'Brown' => 'Brown',
    'Blonde' => 'Blonde',
    'Red' => 'Red',
    'Gray' => 'Gray',
    'White' => 'White',
    'Bald' => 'Bald / Shaved',
    'Other' => 'Other',
);

$eyeColorOptions = array(
    '' => 'Select eye color',
    'Brown' => 'Brown',
    'Blue' => 'Blue',
    'Hazel' => 'Hazel',
    'Green' => 'Green',
    'Gray' => 'Gray',
    'Amber' => 'Amber',
    'Other' => 'Other',
);

$weightKgValue = trim((string)($user['weight'] ?? ''));
$heightCmValue = trim((string)($user['height_cm'] ?? ''));
?>

<section class="card ue-card">
    <h3 class="ue-card-title"><?php echo aue_svg('profile'); ?><span>Profile Details</span></h3>

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
            max-width:100%;
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
        .ue-inline-grid{
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:12px;
        }
        .ue-readonly{
            background:#f7f9fc;
            color:#6b778d;
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
            .ue-info-grid,
            .ue-inline-grid{
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
                    class="app-input ue-input"
                    id="street_address"
                    type="text"
                    name="street_address"
                    value="<?php echo h((string)($user['street_address'] ?? '')); ?>"
                >
            </div>

            <div class="ue-field">
                <label for="street_number">Street Number</label>
                <input
                    class="app-input ue-input"
                    id="street_number"
                    type="text"
                    name="street_number"
                    value="<?php echo h((string)($user['street_number'] ?? '')); ?>"
                >
            </div>

            <div class="ue-field">
                <label for="zip_code">Zip Code</label>
                <input
                    class="app-input ue-input"
                    id="zip_code"
                    type="text"
                    name="zip_code"
                    value="<?php echo h((string)($user['zip_code'] ?? '')); ?>"
                >
            </div>

            <div class="ue-field">
                <label for="city">City</label>
                <input
                    class="app-input ue-input"
                    id="city"
                    type="text"
                    name="city"
                    value="<?php echo h((string)($user['city'] ?? '')); ?>"
                >
            </div>

            <div class="ue-field">
                <label for="state_region">State / Region</label>
                <input
                    class="app-input ue-input"
                    id="state_region"
                    type="text"
                    name="state_region"
                    value="<?php echo h((string)($user['state_region'] ?? '')); ?>"
                >
            </div>

            <div class="ue-field">
                <label for="country_code">Country</label>
                <select class="app-select ue-select" id="country_code" name="country_code">
                    <?php foreach ($countryOptions as $countryCode => $countryLabel): ?>
                        <option value="<?php echo h($countryCode); ?>"<?php echo strtoupper((string)($user['country_code'] ?? '')) === $countryCode ? ' selected' : ''; ?>>
                            <?php echo h($countryLabel); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="ue-field">
                <label for="cellphone">Cellphone</label>
                <input
                    class="app-input ue-input"
                    id="cellphone"
                    type="text"
                    name="cellphone"
                    value="<?php echo h((string)($user['cellphone'] ?? '')); ?>"
                >
            </div>

            <div class="ue-field ue-field--full">
                <label for="secondary_email">Secondary Email</label>
                <input
                    class="app-input ue-input"
                    id="secondary_email"
                    type="email"
                    name="secondary_email"
                    value="<?php echo h((string)($user['secondary_email'] ?? '')); ?>"
                >
            </div>

            <div class="ue-field">
                <label for="date_of_birth">Date of Birth</label>
                <input
                    class="app-input ue-input"
                    id="date_of_birth"
                    type="date"
                    name="date_of_birth"
                    value="<?php echo h((string)($user['date_of_birth'] ?? '')); ?>"
                >
            </div>

            <div class="ue-field">
                <label for="place_of_birth">Place of Birth</label>
                <input
                    class="app-input ue-input"
                    id="place_of_birth"
                    type="text"
                    name="place_of_birth"
                    value="<?php echo h((string)($user['place_of_birth'] ?? '')); ?>"
                >
            </div>

            <div class="ue-field">
                <label for="nationality">Nationality</label>
                <input
                    class="app-input ue-input"
                    id="nationality"
                    type="text"
                    name="nationality"
                    value="<?php echo h((string)($user['nationality'] ?? '')); ?>"
                >
            </div>

            <div class="ue-field">
                <label for="id_passport_number">ID / Passport Number</label>
                <input
                    class="app-input ue-input"
                    id="id_passport_number"
                    type="text"
                    name="id_passport_number"
                    value="<?php echo h((string)($user['id_passport_number'] ?? '')); ?>"
                >
            </div>

            <div class="ue-field">
                <label for="gender">Gender</label>
                <select class="app-select ue-select" id="gender" name="gender">
                    <?php foreach ($genderOptions as $optionValue => $optionLabel): ?>
                        <option value="<?php echo h($optionValue); ?>"<?php echo (string)($user['gender'] ?? '') === $optionValue ? ' selected' : ''; ?>>
                            <?php echo h($optionLabel); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="ue-field">
                <label for="marital_status">Marital Status</label>
                <select class="app-select ue-select" id="marital_status" name="marital_status">
                    <?php foreach ($maritalStatusOptions as $optionValue => $optionLabel): ?>
                        <option value="<?php echo h($optionValue); ?>"<?php echo (string)($user['marital_status'] ?? '') === $optionValue ? ' selected' : ''; ?>>
                            <?php echo h($optionLabel); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="ue-field">
                <label for="hair_color">Hair Color</label>
                <select class="app-select ue-select" id="hair_color" name="hair_color">
                    <?php foreach ($hairColorOptions as $optionValue => $optionLabel): ?>
                        <option value="<?php echo h($optionValue); ?>"<?php echo (string)($user['hair_color'] ?? '') === $optionValue ? ' selected' : ''; ?>>
                            <?php echo h($optionLabel); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="ue-field">
                <label for="eye_color">Eye Color</label>
                <select class="app-select ue-select" id="eye_color" name="eye_color">
                    <?php foreach ($eyeColorOptions as $optionValue => $optionLabel): ?>
                        <option value="<?php echo h($optionValue); ?>"<?php echo (string)($user['eye_color'] ?? '') === $optionValue ? ' selected' : ''; ?>>
                            <?php echo h($optionLabel); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="ue-field ue-field--full">
                <div class="ue-inline-grid">
                    <div class="ue-field">
                        <label for="weight_kg">Weight (kg)</label>
                        <input
                            class="app-input ue-input"
                            id="weight_kg"
                            type="number"
                            step="0.1"
                            min="0"
                            name="weight"
                            value="<?php echo h($weightKgValue); ?>"
                        >
                    </div>

                    <div class="ue-field">
                        <label for="weight_lb_display">Weight (lb)</label>
                        <input
                            class="app-input ue-input ue-readonly"
                            id="weight_lb_display"
                            type="text"
                            value=""
                            readonly
                        >
                    </div>
                </div>
            </div>

            <div class="ue-field ue-field--full">
                <div class="ue-inline-grid">
                    <div class="ue-field">
                        <label for="height_cm">Height (cm)</label>
                        <input
                            class="app-input ue-input"
                            id="height_cm"
                            type="number"
                            step="0.1"
                            min="0"
                            name="height_cm"
                            value="<?php echo h($heightCmValue); ?>"
                        >
                    </div>

                    <div class="ue-field">
                        <label for="height_in_display">Height (in)</label>
                        <input
                            class="app-input ue-input ue-readonly"
                            id="height_in_display"
                            type="text"
                            value=""
                            readonly
                        >
                    </div>
                </div>
            </div>
        </div>

        <div class="ue-actions-row">
            <button class="app-btn app-btn-primary ue-btn ue-btn--primary" type="submit">
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
                <div class="ue-info-value">
                    <?php
                    $countryCode = strtoupper((string)($user['country_code'] ?? ''));
                    echo h($countryOptions[$countryCode] ?? ($countryCode !== '' ? $countryCode : '—'));
                    ?>
                </div>
            </div>

            <div>
                <div class="ue-info-label">Date of Birth</div>
                <div class="ue-info-value"><?php echo h(cw_date_only((string)($user['date_of_birth'] ?? ''))); ?></div>
            </div>

            <div>
                <div class="ue-info-label">Nationality</div>
                <div class="ue-info-value"><?php echo h((string)($user['nationality'] ?? '—')); ?></div>
            </div>

            <div>
                <div class="ue-info-label">ID / Passport</div>
                <div class="ue-info-value"><?php echo h((string)($user['id_passport_number'] ?? '—')); ?></div>
            </div>

            <div>
                <div class="ue-info-label">Weight</div>
                <div class="ue-info-value"><?php echo h($weightKgValue !== '' ? $weightKgValue . ' kg' : '—'); ?></div>
            </div>

            <div>
                <div class="ue-info-label">Height</div>
                <div class="ue-info-value"><?php echo h($heightCmValue !== '' ? $heightCmValue . ' cm' : '—'); ?></div>
            </div>

            <div>
                <div class="ue-info-label">Hair / Eye Color</div>
                <div class="ue-info-value">
                    <?php
                    $hair = trim((string)($user['hair_color'] ?? ''));
                    $eyes = trim((string)($user['eye_color'] ?? ''));
                    $combo = trim($hair . ($hair !== '' && $eyes !== '' ? ' / ' : '') . $eyes);
                    echo h($combo !== '' ? $combo : '—');
                    ?>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var weightKg = document.getElementById('weight_kg');
        var weightLb = document.getElementById('weight_lb_display');
        var heightCm = document.getElementById('height_cm');
        var heightIn = document.getElementById('height_in_display');

        function formatOneDecimal(value) {
            return Math.round(value * 10) / 10;
        }

        function syncWeight() {
            if (!weightKg || !weightLb) {
                return;
            }

            var kg = parseFloat(weightKg.value);
            if (isNaN(kg) || kg <= 0) {
                weightLb.value = '';
                return;
            }

            weightLb.value = formatOneDecimal(kg * 2.20462262) + ' lb';
        }

        function syncHeight() {
            if (!heightCm || !heightIn) {
                return;
            }

            var cm = parseFloat(heightCm.value);
            if (isNaN(cm) || cm <= 0) {
                heightIn.value = '';
                return;
            }

            heightIn.value = formatOneDecimal(cm / 2.54) + ' in';
        }

        if (weightKg) {
            weightKg.addEventListener('input', syncWeight);
            syncWeight();
        }

        if (heightCm) {
            heightCm.addEventListener('input', syncHeight);
            syncHeight();
        }
    })();
    </script>
</section>