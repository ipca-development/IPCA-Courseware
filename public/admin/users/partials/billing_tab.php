<?php
declare(strict_types=1);

$billingCountryOptions = array(
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

$useProfileAddress = (int)($user['use_profile_address'] ?? 1) === 1;
?>

<section class="card ue-card">
    <h3 class="ue-card-title"><?php echo aue_svg('billing'); ?><span>Billing / Business Profile</span></h3>

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
        .ue-checkbox{
            display:inline-flex;
            align-items:center;
            gap:10px;
            font-size:14px;
            font-weight:600;
            color:var(--text-strong);
        }
        .ue-checkbox input{
            width:16px;
            height:16px;
            margin:0;
        }
        .ue-billing-address-block{
            padding:16px 18px 18px 18px;
            border:1px solid rgba(15,23,42,0.06);
            border-radius:16px;
            background:#fbfcfe;
        }
        .ue-billing-address-title{
            font-size:15px;
            font-weight:730;
            letter-spacing:-0.02em;
            color:var(--text-strong);
            margin:0 0 14px 0;
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
            </div>

            <div class="ue-field ue-field--full">
                <label class="ue-checkbox">
                    <input
                        type="checkbox"
                        id="use_profile_address"
                        name="use_profile_address"
                        value="1"<?php echo $useProfileAddress ? ' checked' : ''; ?>
                    >
                    <span>Use profile address as billing address</span>
                </label>
            </div>

            <div class="ue-field ue-field--full" id="billing-address-wrap"<?php echo $useProfileAddress ? ' style="display:none;"' : ''; ?>>
                <div class="ue-billing-address-block">
                    <h4 class="ue-billing-address-title">Billing Address</h4>

                    <div class="ue-form-grid">
                        <div class="ue-field ue-field--full">
                            <label for="billing_street_address">Billing Street Address</label>
                            <input
                                class="app-input ue-input"
                                id="billing_street_address"
                                type="text"
                                name="billing_street_address"
                                value="<?php echo h((string)($user['billing_street_address'] ?? '')); ?>"
                            >
                        </div>

                        <div class="ue-field">
                            <label for="billing_street_number">Billing Street Number</label>
                            <input
                                class="app-input ue-input"
                                id="billing_street_number"
                                type="text"
                                name="billing_street_number"
                                value="<?php echo h((string)($user['billing_street_number'] ?? '')); ?>"
                            >
                        </div>

                        <div class="ue-field">
                            <label for="billing_zip_code">Billing Zip Code</label>
                            <input
                                class="app-input ue-input"
                                id="billing_zip_code"
                                type="text"
                                name="billing_zip_code"
                                value="<?php echo h((string)($user['billing_zip_code'] ?? '')); ?>"
                            >
                        </div>

                        <div class="ue-field">
                            <label for="billing_city">Billing City</label>
                            <input
                                class="app-input ue-input"
                                id="billing_city"
                                type="text"
                                name="billing_city"
                                value="<?php echo h((string)($user['billing_city'] ?? '')); ?>"
                            >
                        </div>

                        <div class="ue-field">
                            <label for="billing_state_region">Billing State / Region</label>
                            <input
                                class="app-input ue-input"
                                id="billing_state_region"
                                type="text"
                                name="billing_state_region"
                                value="<?php echo h((string)($user['billing_state_region'] ?? '')); ?>"
                            >
                        </div>

                        <div class="ue-field">
                            <label for="billing_country_code">Billing Country</label>
                            <select class="app-select ue-select" id="billing_country_code" name="billing_country_code">
                                <?php foreach ($billingCountryOptions as $countryCode => $countryLabel): ?>
                                    <option value="<?php echo h($countryCode); ?>"<?php echo strtoupper((string)($user['billing_country_code'] ?? '')) === $countryCode ? ' selected' : ''; ?>>
                                        <?php echo h($countryLabel); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
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

            <div>
                <div class="ue-info-label">Billing Address Source</div>
                <div class="ue-info-value">
                    <?php echo $useProfileAddress ? 'Uses profile address' : 'Uses dedicated billing address'; ?>
                </div>
            </div>

            <div>
                <div class="ue-info-label">Billing City</div>
                <div class="ue-info-value">
                    <?php
                    if ($useProfileAddress) {
                        echo h((string)($user['city'] ?? '—'));
                    } else {
                        echo h((string)($user['billing_city'] ?? '—'));
                    }
                    ?>
                </div>
            </div>

            <div>
                <div class="ue-info-label">Billing Country</div>
                <div class="ue-info-value">
                    <?php
                    $billingCountryCode = $useProfileAddress
                        ? strtoupper((string)($user['country_code'] ?? ''))
                        : strtoupper((string)($user['billing_country_code'] ?? ''));

                    echo h($billingCountryOptions[$billingCountryCode] ?? ($billingCountryCode !== '' ? $billingCountryCode : '—'));
                    ?>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var checkbox = document.getElementById('use_profile_address');
        var wrap = document.getElementById('billing-address-wrap');

        function syncBillingAddressVisibility() {
            if (!checkbox || !wrap) {
                return;
            }

            wrap.style.display = checkbox.checked ? 'none' : '';
        }

        if (checkbox) {
            checkbox.addEventListener('change', syncBillingAddressVisibility);
            syncBillingAddressVisibility();
        }
    })();
    </script>
</section>