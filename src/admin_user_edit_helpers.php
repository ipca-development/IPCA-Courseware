<?php
declare(strict_types=1);

require_once __DIR__ . '/onboarding_tokens.php';
require_once __DIR__ . '/automation_runtime.php';

if (!function_exists('aue_human_date')) {
    function aue_human_date(?string $date): string
    {
        return cw_date_only($date);
    }
}

if (!function_exists('aue_human_datetime')) {
    function aue_human_datetime(?string $dateTime, PDO $pdo, ?int $userId = null): string
    {
        return cw_dt_admin($dateTime, $pdo, $userId);
    }
}

if (!function_exists('aue_role_label')) {
    function aue_role_label(string $role): string
    {
        $role = strtolower(trim($role));

        return match ($role) {
            'admin' => 'Admin',
            'supervisor' => 'Instructor',
            'instructor' => 'Instructor',
            'chief_instructor' => 'Chief Instructor',
            'student' => 'Student',
            default => ucfirst($role),
        };
    }
}

if (!function_exists('aue_status_label')) {
    function aue_status_label(string $status): string
    {
        $status = strtolower(trim($status));

        return match ($status) {
            'pending_activation' => 'Pending Activation',
            'active' => 'Active',
            'locked' => 'Locked',
            'retired' => 'Retired',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }
}

if (!function_exists('aue_status_class')) {
    function aue_status_class(string $status): string
    {
        $status = strtolower(trim($status));

        return match ($status) {
            'active' => 'app-badge app-badge-success',
            'pending_activation' => 'app-badge app-badge-warn',
            'locked' => 'app-badge app-badge-danger',
            'retired' => 'app-badge app-badge-muted',
            default => 'app-badge app-badge-neutral',
        };
    }
}

if (!function_exists('aue_role_class')) {
    function aue_role_class(string $role): string
    {
        $role = strtolower(trim($role));

        return match ($role) {
            'admin' => 'app-badge app-badge-accent',
            'supervisor', 'instructor', 'chief_instructor' => 'app-badge app-badge-sky',
            'student' => 'app-badge app-badge-neutral',
            default => 'app-badge app-badge-neutral',
        };
    }
}

if (!function_exists('aue_completeness_class')) {
    function aue_completeness_class(int $missingCount): string
    {
        return $missingCount > 0
            ? 'app-badge app-badge-warn'
            : 'app-badge app-badge-success';
    }
}

if (!function_exists('aue_validity_class')) {
    function aue_validity_class(?string $validUntil): string
    {
        if (!$validUntil) {
            return 'app-badge app-badge-neutral';
        }

        $today = strtotime(date('Y-m-d'));
        $target = strtotime($validUntil);
        if (!$target) {
            return 'app-badge app-badge-neutral';
        }

        $days = (int)floor(($target - $today) / 86400);

        if ($days < 0) {
            return 'app-badge app-badge-danger';
        }
        if ($days <= 30) {
            return 'app-badge app-badge-warn';
        }

        return 'app-badge app-badge-success';
    }
}

if (!function_exists('aue_validity_label')) {
    function aue_validity_label(?string $validUntil): string
    {
        if (!$validUntil) {
            return 'No validity set';
        }

        $today = strtotime(date('Y-m-d'));
        $target = strtotime($validUntil);
        if (!$target) {
            return 'No validity set';
        }

        $days = (int)floor(($target - $today) / 86400);

        if ($days < 0) {
            return 'Expired';
        }
        if ($days === 0) {
            return 'Expires Today';
        }
        if ($days <= 30) {
            return 'Expires in ' . $days . ' day' . ($days === 1 ? '' : 's');
        }

        return 'Valid';
    }
}

if (!function_exists('aue_mask_value')) {
    function aue_mask_value(?string $value): string
    {
        $value = trim((string)$value);

        if ($value === '') {
            return 'Not stored';
        }

        $len = strlen($value);

        if ($len <= 4) {
            return str_repeat('•', $len);
        }

        return substr($value, 0, 2) . str_repeat('•', max(4, $len - 4)) . substr($value, -2);
    }
}

if (!function_exists('aue_normalize_date')) {
    function aue_normalize_date(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        $ts = strtotime($value);
        return $ts ? date('Y-m-d', $ts) : null;
    }
}

if (!function_exists('aue_normalize_decimal')) {
    function aue_normalize_decimal(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        $value = str_replace(',', '.', $value);
        if (!is_numeric($value)) {
            return null;
        }

        return number_format((float)$value, 2, '.', '');
    }
}

if (!function_exists('aue_has_value')) {
    function aue_has_value(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        return true;
    }
}

if (!function_exists('aue_missing_field_metadata_map')) {
    function aue_missing_field_metadata_map(): array
    {
        static $map = null;

        if ($map !== null) {
            return $map;
        }

        $map = array(
            'first_name' => array(
                'key' => 'first_name',
                'label' => 'First Name',
                'tab' => 'account',
                'anchor' => 'first_name',
            ),
            'last_name' => array(
                'key' => 'last_name',
                'label' => 'Last Name',
                'tab' => 'account',
                'anchor' => 'last_name',
            ),
            'primary_email' => array(
                'key' => 'primary_email',
                'label' => 'Primary Email',
                'tab' => 'account',
                'anchor' => 'email',
            ),
            'role' => array(
                'key' => 'role',
                'label' => 'Role',
                'tab' => 'account',
                'anchor' => 'role',
            ),
            'status' => array(
                'key' => 'status',
                'label' => 'Status',
                'tab' => 'account',
                'anchor' => 'status',
            ),
            'account_valid_until' => array(
                'key' => 'account_valid_until',
                'label' => 'Account Valid Until',
                'tab' => 'account',
                'anchor' => 'account_valid_until',
            ),
            'photo' => array(
                'key' => 'photo',
                'label' => 'Photo',
                'tab' => 'account',
                'anchor' => 'photo',
            ),

            'street_address' => array(
                'key' => 'street_address',
                'label' => 'Street Address',
                'tab' => 'profile',
                'anchor' => 'street_address',
            ),
            'street_number' => array(
                'key' => 'street_number',
                'label' => 'Street Number',
                'tab' => 'profile',
                'anchor' => 'street_number',
            ),
            'zip_code' => array(
                'key' => 'zip_code',
                'label' => 'Zip Code',
                'tab' => 'profile',
                'anchor' => 'zip_code',
            ),
            'city' => array(
                'key' => 'city',
                'label' => 'City',
                'tab' => 'profile',
                'anchor' => 'city',
            ),
            'state_region' => array(
                'key' => 'state_region',
                'label' => 'State / Region',
                'tab' => 'profile',
                'anchor' => 'state_region',
            ),
            'country_code' => array(
                'key' => 'country_code',
                'label' => 'Country',
                'tab' => 'profile',
                'anchor' => 'country_code',
            ),
            'cellphone' => array(
                'key' => 'cellphone',
                'label' => 'Cellphone',
                'tab' => 'profile',
                'anchor' => 'cellphone',
            ),
            'secondary_email' => array(
                'key' => 'secondary_email',
                'label' => 'Secondary Email',
                'tab' => 'profile',
                'anchor' => 'secondary_email',
            ),
            'date_of_birth' => array(
                'key' => 'date_of_birth',
                'label' => 'Date of Birth',
                'tab' => 'profile',
                'anchor' => 'date_of_birth',
            ),
            'place_of_birth' => array(
                'key' => 'place_of_birth',
                'label' => 'Place of Birth',
                'tab' => 'profile',
                'anchor' => 'place_of_birth',
            ),
            'nationality' => array(
                'key' => 'nationality',
                'label' => 'Nationality',
                'tab' => 'profile',
                'anchor' => 'nationality',
            ),
            'id_passport_number' => array(
                'key' => 'id_passport_number',
                'label' => 'ID / Passport Number',
                'tab' => 'profile',
                'anchor' => 'id_passport_number',
            ),
            'gender' => array(
                'key' => 'gender',
                'label' => 'Gender',
                'tab' => 'profile',
                'anchor' => 'gender',
            ),
            'weight_kg' => array(
                'key' => 'weight_kg',
                'label' => 'Weight',
                'tab' => 'profile',
                'anchor' => 'weight_kg',
            ),
            'height_cm' => array(
                'key' => 'height_cm',
                'label' => 'Height',
                'tab' => 'profile',
                'anchor' => 'height_cm',
            ),
            'hair_color' => array(
                'key' => 'hair_color',
                'label' => 'Hair Color',
                'tab' => 'profile',
                'anchor' => 'hair_color',
            ),
            'eye_color' => array(
                'key' => 'eye_color',
                'label' => 'Eye Color',
                'tab' => 'profile',
                'anchor' => 'eye_color',
            ),
            'marital_status' => array(
                'key' => 'marital_status',
                'label' => 'Marital Status',
                'tab' => 'profile',
                'anchor' => 'marital_status',
            ),

            'emergency_contact_1_name' => array(
                'key' => 'emergency_contact_1_name',
                'label' => 'Emergency Contact 1 Name',
                'tab' => 'emergency',
                'anchor' => 'contact_name_1',
            ),
            'emergency_contact_1_relationship' => array(
                'key' => 'emergency_contact_1_relationship',
                'label' => 'Emergency Contact 1 Relationship',
                'tab' => 'emergency',
                'anchor' => 'relationship_1',
            ),
            'emergency_contact_1_phone' => array(
                'key' => 'emergency_contact_1_phone',
                'label' => 'Emergency Contact 1 Phone',
                'tab' => 'emergency',
                'anchor' => 'phone_1',
            ),
            'emergency_contact_2_name' => array(
                'key' => 'emergency_contact_2_name',
                'label' => 'Emergency Contact 2 Name',
                'tab' => 'emergency',
                'anchor' => 'contact_name_2',
            ),
            'emergency_contact_2_relationship' => array(
                'key' => 'emergency_contact_2_relationship',
                'label' => 'Emergency Contact 2 Relationship',
                'tab' => 'emergency',
                'anchor' => 'relationship_2',
            ),
            'emergency_contact_2_phone' => array(
                'key' => 'emergency_contact_2_phone',
                'label' => 'Emergency Contact 2 Phone',
                'tab' => 'emergency',
                'anchor' => 'phone_2',
            ),

            'business_name' => array(
                'key' => 'business_name',
                'label' => 'Business Name',
                'tab' => 'billing',
                'anchor' => 'business_name',
            ),
            'business_vat_tax_id' => array(
                'key' => 'business_vat_tax_id',
                'label' => 'Business VAT / Tax ID',
                'tab' => 'billing',
                'anchor' => 'business_vat_tax_id',
            ),
            'billing_street_address' => array(
                'key' => 'billing_street_address',
                'label' => 'Billing Street Address',
                'tab' => 'billing',
                'anchor' => 'billing_street_address',
            ),
            'billing_street_number' => array(
                'key' => 'billing_street_number',
                'label' => 'Billing Street Number',
                'tab' => 'billing',
                'anchor' => 'billing_street_number',
            ),
            'billing_zip_code' => array(
                'key' => 'billing_zip_code',
                'label' => 'Billing Zip Code',
                'tab' => 'billing',
                'anchor' => 'billing_zip_code',
            ),
            'billing_city' => array(
                'key' => 'billing_city',
                'label' => 'Billing City',
                'tab' => 'billing',
                'anchor' => 'billing_city',
            ),
            'billing_state_region' => array(
                'key' => 'billing_state_region',
                'label' => 'Billing State / Region',
                'tab' => 'billing',
                'anchor' => 'billing_state_region',
            ),
            'billing_country_code' => array(
                'key' => 'billing_country_code',
                'label' => 'Billing Country',
                'tab' => 'billing',
                'anchor' => 'billing_country_code',
            ),
        );

        return $map;
    }
}

if (!function_exists('aue_missing_field_metadata')) {
    function aue_missing_field_metadata(string $key): ?array
    {
        $map = aue_missing_field_metadata_map();
        return $map[$key] ?? null;
    }
}

if (!function_exists('aue_missing_field_item')) {
    function aue_missing_field_item(string $key): ?array
    {
        $meta = aue_missing_field_metadata($key);
        if (!is_array($meta)) {
            return null;
        }

        return array(
            'key' => (string)$meta['key'],
            'label' => (string)$meta['label'],
            'tab' => (string)$meta['tab'],
            'anchor' => (string)$meta['anchor'],
        );
    }
}

if (!function_exists('aue_push_missing_field')) {
    function aue_push_missing_field(array &$missing, string $key): void
    {
        $item = aue_missing_field_item($key);
        if ($item !== null) {
            $missing[$key] = $item;
        }
    }
}

if (!function_exists('aue_missing_field_url')) {
    function aue_missing_field_url(int $userId, mixed $missingField): string
    {
        $item = null;

        if (is_array($missingField)) {
            if (isset($missingField['key'])) {
                $item = aue_missing_field_item((string)$missingField['key']);
            } elseif (isset($missingField['tab'], $missingField['anchor'])) {
                $item = array(
                    'key' => (string)($missingField['key'] ?? ''),
                    'label' => (string)($missingField['label'] ?? ''),
                    'tab' => (string)$missingField['tab'],
                    'anchor' => (string)$missingField['anchor'],
                );
            }
        } elseif (is_string($missingField) && $missingField !== '') {
            $item = aue_missing_field_item($missingField);
        }

        if (!is_array($item) || trim((string)($item['tab'] ?? '')) === '') {
            return aue_edit_url($userId, 'account');
        }

        $url = aue_edit_url($userId, (string)$item['tab']);
        $anchor = trim((string)($item['anchor'] ?? ''));

        if ($anchor !== '') {
            $url .= '#' . rawurlencode($anchor);
        }

        return $url;
    }
}

if (!function_exists('aue_svg')) {
    function aue_svg(string $name): string
    {
        switch ($name) {
            case 'users':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 19v-1.2a3.8 3.8 0 0 0-3.8-3.8H8.8A3.8 3.8 0 0 0 5 17.8V19M15.5 8.5a2.5 2.5 0 1 1 0-5a2.5 2.5 0 0 1 0 5Zm-8 0a2.5 2.5 0 1 1 0-5a2.5 2.5 0 0 1 0 5Zm12 10.5v-1a3 3 0 0 0-2.2-2.9" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'mail':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7.5A1.5 1.5 0 0 1 5.5 6h13A1.5 1.5 0 0 1 20 7.5v9a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 16.5v-9Zm0 .5l8 5.5l8-5.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'shield':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l7 3v5c0 4.4-2.7 8.4-7 10c-4.3-1.6-7-5.6-7-10V6l7-3Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'archive':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M6 7l1 11h10l1-11M10 11h4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M5 4h14v3H5z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>';
            case 'check':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.2 4.2L19 7.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'warning':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4l8 14H4L12 4Zm0 5.2v4.8m0 3h.01" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'clock':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 7v5l3 2M21 12a9 9 0 1 1-18 0a9 9 0 0 1 18 0Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'camera':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 6l1.2-2h5.6L16 6h2a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h2Zm4 10a3.5 3.5 0 1 0 0-7a3.5 3.5 0 0 0 0 7Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'key':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.5 6.5a4.5 4.5 0 1 1-2.5 8.2L9 17.7V20H7v-2H5v-2.1l4.2-4.2a4.5 4.5 0 0 1 5.3-5.2Zm1.7 3.3h.01" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'save':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h11l3 3v13H5V4Zm3 0v5h8V4M9 20v-6h6v6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'copy':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 9h10v11H9zM5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'lock':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 11V8a5 5 0 0 1 10 0v3M6 11h12a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-7a1 1 0 0 1 1-1Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'activity':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12h4l2-5l4 10l2-5h6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'profile':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a4 4 0 1 0-4-4a4 4 0 0 0 4 4Zm-7 8a7 7 0 0 1 14 0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'contact':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 20h12a2 2 0 0 0 2-2V8l-4-4H8a2 2 0 0 0-2 2Zm2-9h8M8 15h5M14 4v4h4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'billing':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M6 4h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Zm0 6h6m-6 4h12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            default:
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>';
        }
    }
}

if (!function_exists('aue_tabs')) {
    function aue_tabs(): array
    {
        return array(
            'account' => 'Account',
            'profile' => 'Profile',
            'emergency' => 'Emergency',
            'billing' => 'Billing',
            'integrations' => 'Integrations',
            'vault' => 'Credentials Vault',
            'security' => 'Security',
            'audit' => 'Audit',
        );
    }
}

if (!function_exists('aue_active_tab')) {
    function aue_active_tab(?string $tab): string
    {
        $tab = strtolower(trim((string)$tab));
        $tabs = aue_tabs();

        return isset($tabs[$tab]) ? $tab : 'account';
    }
}

if (!function_exists('aue_edit_url')) {
    function aue_edit_url(int $userId, string $tab, array $extra = array()): string
    {
        $params = array_merge(array(
            'id' => $userId,
            'tab' => $tab,
        ), $extra);

        return '/admin/users/edit.php?' . http_build_query($params);
    }
}

if (!function_exists('aue_flash_redirect')) {
    function aue_flash_redirect(int $userId, string $tab, string $type, string $message): void
    {
        header('Location: ' . aue_edit_url($userId, $tab, array(
            'flash_type' => $type,
            'flash_message' => $message,
        )));
        exit;
    }
}

if (!function_exists('aue_empty_emergency_contact')) {
    function aue_empty_emergency_contact(int $sortOrder): array
    {
        return array(
            'id' => null,
            'user_id' => null,
            'contact_name' => null,
            'relationship' => null,
            'phone' => null,
            'sort_order' => $sortOrder,
            'created_at' => null,
            'updated_at' => null,
        );
    }
}

if (!function_exists('aue_policy_raw')) {
    function aue_policy_raw(PDO $pdo, string $policyKey, string $scopeType = 'global', ?int $scopeId = null): ?string
    {
        $sql = "
            SELECT v.value_text
            FROM system_policy_values v
            WHERE v.policy_key = :policy_key
              AND v.scope_type = :scope_type
              AND (
                    (:scope_id IS NULL AND v.scope_id IS NULL)
                    OR v.scope_id = :scope_id
                  )
              AND v.is_active = 1
              AND v.effective_from <= NOW()
              AND (v.effective_to IS NULL OR v.effective_to >= NOW())
            ORDER BY v.effective_from DESC, v.id DESC
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array(
            ':policy_key' => $policyKey,
            ':scope_type' => $scopeType,
            ':scope_id' => $scopeId,
        ));

        $value = $stmt->fetchColumn();
        if ($value !== false && $value !== null) {
            return (string)$value;
        }

        $fallbackStmt = $pdo->prepare("
            SELECT default_value_text
            FROM system_policy_definitions
            WHERE policy_key = :policy_key
            LIMIT 1
        ");
        $fallbackStmt->execute(array(
            ':policy_key' => $policyKey,
        ));

        $fallback = $fallbackStmt->fetchColumn();
        if ($fallback !== false && $fallback !== null) {
            return (string)$fallback;
        }

        return null;
    }
}

if (!function_exists('aue_policy_bool')) {
    function aue_policy_bool(PDO $pdo, string $policyKey, bool $default = false, string $scopeType = 'global', ?int $scopeId = null): bool
    {
        $raw = aue_policy_raw($pdo, $policyKey, $scopeType, $scopeId);
        if ($raw === null) {
            return $default;
        }

        $normalized = strtolower(trim($raw));
        return in_array($normalized, array('1', 'true', 'yes', 'on'), true);
    }
}

if (!function_exists('aue_policy_int')) {
    function aue_policy_int(PDO $pdo, string $policyKey, int $default = 0, string $scopeType = 'global', ?int $scopeId = null): int
    {
        $raw = aue_policy_raw($pdo, $policyKey, $scopeType, $scopeId);
        if ($raw === null || trim($raw) === '' || !is_numeric($raw)) {
            return $default;
        }

        return (int)$raw;
    }
}

if (!function_exists('aue_load_user_workspace')) {
    function aue_load_user_workspace(PDO $pdo, int $userId): ?array
    {
        $sql = "
            SELECT
                u.id,
                u.uuid,
                u.name,
                u.first_name,
                u.last_name,
                u.email,
                u.username,
                u.role,
                u.status,
                u.account_valid_until,
                u.photo_path,
                u.must_change_password,
                u.last_login_at,
                u.password_changed_at,
                u.created_at,
                u.updated_at,
                u.retired_at,
                u.created_by_user_id,
                u.updated_by_user_id,
                u.retired_by_user_id,

                p.street_address,
                p.street_number,
                p.zip_code,
                p.city,
                p.state_region,
                p.country_code,
                p.cellphone,
                p.secondary_email,
                p.date_of_birth,
                p.place_of_birth,
                p.nationality,
                p.id_passport_number,
                p.gender,
                p.weight,
                p.height_cm,
                p.hair_color,
                p.eye_color,
                p.marital_status,

                b.business_name,
                b.business_vat_tax_id,
                b.use_profile_address,
                b.billing_street_address,
                b.billing_street_number,
                b.billing_zip_code,
                b.billing_city,
                b.billing_state_region,
                b.billing_country_code,

                req.missing_fields_json,
                COALESCE(req.missing_count, 0) AS missing_count,
                req.is_profile_complete,
                req.last_evaluated_at
            FROM users u
            LEFT JOIN user_profiles p
                ON p.user_id = u.id
            LEFT JOIN user_billing_profiles b
                ON b.user_id = u.id
            LEFT JOIN user_profile_requirements_status req
                ON req.user_id = u.id
            WHERE u.id = :id
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array(':id' => $userId));
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($user)) {
            return null;
        }

        $contactsStmt = $pdo->prepare("
            SELECT *
            FROM user_emergency_contacts
            WHERE user_id = :user_id
            ORDER BY sort_order ASC, id ASC
        ");
        $contactsStmt->execute(array(':user_id' => $userId));
        $contacts = $contactsStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($contacts)) {
            $contacts = array();
        }

        $primary = aue_empty_emergency_contact(1);
        $secondary = aue_empty_emergency_contact(2);

        foreach ($contacts as $contact) {
            $sortOrder = (int)($contact['sort_order'] ?? 0);
            if ($sortOrder === 1 && $primary['id'] === null) {
                $primary = $contact;
            } elseif ($sortOrder === 2 && $secondary['id'] === null) {
                $secondary = $contact;
            }
        }

        $missingFields = array();
        $missingFieldsJson = (string)($user['missing_fields_json'] ?? '');
        if ($missingFieldsJson !== '') {
            $decoded = json_decode($missingFieldsJson, true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    if (is_array($item) && isset($item['key'])) {
                        $normalized = aue_missing_field_item((string)$item['key']);
                        if ($normalized !== null) {
                            $missingFields[] = $normalized;
                        }
                    }
                }
            }
        }

        $displayName = trim((string)($user['name'] ?? ''));
        if ($displayName === '') {
            $displayName = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
        }
        if ($displayName === '') {
            $displayName = 'User #' . $userId;
        }

        return array(
            'user' => $user,
            'emergency' => $primary,
            'emergency_primary' => $primary,
            'emergency_secondary' => $secondary,
            'emergency_contacts' => $contacts,
            'missing_fields' => $missingFields,
            'display_name' => $displayName,
        );
    }
}

if (!function_exists('aue_handle_account_photo_upload')) {
    function aue_handle_account_photo_upload(int $userId): ?string
    {
        if (!isset($_FILES['photo']) || !is_array($_FILES['photo'])) {
            return null;
        }

        if ((int)($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ((int)($_FILES['photo']['error'] ?? 0) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Photo upload failed.');
        }

        $tmpPath = (string)($_FILES['photo']['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            throw new RuntimeException('Invalid upload payload.');
        }

        $mime = mime_content_type($tmpPath) ?: '';
        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => '',
        };

        if ($ext === '') {
            throw new RuntimeException('Only JPG, PNG, or WEBP photos are allowed.');
        }

        $targetDirFs = dirname(__DIR__) . '/public/uploads/user_photos';
        if (!is_dir($targetDirFs) && !mkdir($targetDirFs, 0775, true) && !is_dir($targetDirFs)) {
            throw new RuntimeException('Unable to create photo upload directory.');
        }

        $fileName = 'user_' . $userId . '_' . time() . '.' . $ext;
        $targetFs = $targetDirFs . '/' . $fileName;
        $targetWeb = '/uploads/user_photos/' . $fileName;

        if (!move_uploaded_file($tmpPath, $targetFs)) {
            throw new RuntimeException('Unable to store uploaded photo.');
        }

        return $targetWeb;
    }
}

if (!function_exists('aue_update_account_tab')) {
    function aue_update_account_tab(PDO $pdo, int $userId, int $actorId): void
    {
        $firstName = trim((string)($_POST['first_name'] ?? ''));
        $lastName = trim((string)($_POST['last_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $role = trim((string)($_POST['role'] ?? ''));
        $status = trim((string)($_POST['status'] ?? ''));
        $validUntil = aue_normalize_date((string)($_POST['account_valid_until'] ?? ''));
        $mustChange = isset($_POST['must_change_password']) ? 1 : 0;

        $allowedRoles = array('admin', 'student', 'supervisor', 'instructor', 'chief_instructor');
        $allowedStatuses = array('pending_activation', 'active', 'locked', 'retired');

        if ($firstName === '' || $lastName === '' || $email === '') {
            throw new RuntimeException('First name, last name, and email are required.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Email address is invalid.');
        }

        if (!in_array($role, $allowedRoles, true)) {
            throw new RuntimeException('Role is invalid.');
        }

        if (!in_array($status, $allowedStatuses, true)) {
            throw new RuntimeException('Status is invalid.');
        }

        $dupEmailStmt = $pdo->prepare("
            SELECT id
            FROM users
            WHERE email = :email
              AND id <> :id
            LIMIT 1
        ");
        $dupEmailStmt->execute(array(
            ':email' => $email,
            ':id' => $userId,
        ));
        if ($dupEmailStmt->fetchColumn()) {
            throw new RuntimeException('Another user already uses this email address.');
        }

        $photoPath = aue_handle_account_photo_upload($userId);
        $displayName = trim($firstName . ' ' . $lastName);

        $update = $pdo->prepare("
            UPDATE users
            SET
                first_name = :first_name,
                last_name = :last_name,
                name = :name,
                email = :email,
                username = NULL,
                role = :role,
                status = :status,
                account_valid_until = :account_valid_until,
                must_change_password = :must_change_password,
                photo_path = COALESCE(:photo_path, photo_path),
                updated_by_user_id = :updated_by_user_id,
                retired_at = CASE
                    WHEN :status = 'retired' AND retired_at IS NULL THEN NOW()
                    WHEN :status <> 'retired' THEN NULL
                    ELSE retired_at
                END,
                retired_by_user_id = CASE
                    WHEN :status = 'retired' THEN :retired_by_user_id
                    WHEN :status <> 'retired' THEN NULL
                    ELSE retired_by_user_id
                END,
                updated_at = NOW()
            WHERE id = :id
            LIMIT 1
        ");

        $update->execute(array(
            ':first_name' => $firstName,
            ':last_name' => $lastName,
            ':name' => $displayName,
            ':email' => $email,
            ':role' => $role,
            ':status' => $status,
            ':account_valid_until' => $validUntil,
            ':must_change_password' => $mustChange,
            ':photo_path' => $photoPath,
            ':updated_by_user_id' => $actorId > 0 ? $actorId : null,
            ':retired_by_user_id' => $status === 'retired' && $actorId > 0 ? $actorId : null,
            ':id' => $userId,
        ));

        aue_recalculate_profile_requirements_status($pdo, $userId);
    }
}

if (!function_exists('aue_update_profile_tab')) {
    function aue_update_profile_tab(PDO $pdo, int $userId): void
    {
        $secondaryEmail = trim((string)($_POST['secondary_email'] ?? ''));
        if ($secondaryEmail !== '' && !filter_var($secondaryEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Secondary email address is invalid.');
        }

        $upsert = $pdo->prepare("
            INSERT INTO user_profiles (
                user_id, street_address, street_number, zip_code, city, state_region, country_code,
                cellphone, secondary_email, date_of_birth, place_of_birth, nationality,
                id_passport_number, gender, weight, height_cm, hair_color, eye_color, marital_status,
                created_at, updated_at
            ) VALUES (
                :user_id, :street_address, :street_number, :zip_code, :city, :state_region, :country_code,
                :cellphone, :secondary_email, :date_of_birth, :place_of_birth, :nationality,
                :id_passport_number, :gender, :weight, :height_cm, :hair_color, :eye_color, :marital_status,
                NOW(), NOW()
            )
            ON DUPLICATE KEY UPDATE
                street_address = VALUES(street_address),
                street_number = VALUES(street_number),
                zip_code = VALUES(zip_code),
                city = VALUES(city),
                state_region = VALUES(state_region),
                country_code = VALUES(country_code),
                cellphone = VALUES(cellphone),
                secondary_email = VALUES(secondary_email),
                date_of_birth = VALUES(date_of_birth),
                place_of_birth = VALUES(place_of_birth),
                nationality = VALUES(nationality),
                id_passport_number = VALUES(id_passport_number),
                gender = VALUES(gender),
                weight = VALUES(weight),
                height_cm = VALUES(height_cm),
                hair_color = VALUES(hair_color),
                eye_color = VALUES(eye_color),
                marital_status = VALUES(marital_status),
                updated_at = NOW()
        ");

        $upsert->execute(array(
            ':user_id' => $userId,
            ':street_address' => trim((string)($_POST['street_address'] ?? '')) ?: null,
            ':street_number' => trim((string)($_POST['street_number'] ?? '')) ?: null,
            ':zip_code' => trim((string)($_POST['zip_code'] ?? '')) ?: null,
            ':city' => trim((string)($_POST['city'] ?? '')) ?: null,
            ':state_region' => trim((string)($_POST['state_region'] ?? '')) ?: null,
            ':country_code' => strtoupper(trim((string)($_POST['country_code'] ?? ''))) ?: null,
            ':cellphone' => trim((string)($_POST['cellphone'] ?? '')) ?: null,
            ':secondary_email' => $secondaryEmail !== '' ? $secondaryEmail : null,
            ':date_of_birth' => aue_normalize_date((string)($_POST['date_of_birth'] ?? '')),
            ':place_of_birth' => trim((string)($_POST['place_of_birth'] ?? '')) ?: null,
            ':nationality' => trim((string)($_POST['nationality'] ?? '')) ?: null,
            ':id_passport_number' => trim((string)($_POST['id_passport_number'] ?? '')) ?: null,
            ':gender' => trim((string)($_POST['gender'] ?? '')) ?: null,
            ':weight' => trim((string)($_POST['weight'] ?? '')) ?: null,
            ':height_cm' => aue_normalize_decimal((string)($_POST['height_cm'] ?? '')),
            ':hair_color' => trim((string)($_POST['hair_color'] ?? '')) ?: null,
            ':eye_color' => trim((string)($_POST['eye_color'] ?? '')) ?: null,
            ':marital_status' => trim((string)($_POST['marital_status'] ?? '')) ?: null,
        ));

        aue_recalculate_profile_requirements_status($pdo, $userId);
    }
}

if (!function_exists('aue_upsert_emergency_contact_row')) {
    function aue_upsert_emergency_contact_row(PDO $pdo, int $userId, int $sortOrder, ?string $contactName, ?string $relationship, ?string $phone): void
    {
        $contactName = trim((string)$contactName);
        $relationship = trim((string)$relationship);
        $phone = trim((string)$phone);

        $allEmpty = ($contactName === '' && $relationship === '' && $phone === '');

        $existingStmt = $pdo->prepare("
            SELECT id
            FROM user_emergency_contacts
            WHERE user_id = :user_id
              AND sort_order = :sort_order
            ORDER BY id ASC
            LIMIT 1
        ");
        $existingStmt->execute(array(
            ':user_id' => $userId,
            ':sort_order' => $sortOrder,
        ));
        $existingId = (int)$existingStmt->fetchColumn();

        if ($allEmpty) {
            if ($existingId > 0) {
                $deleteStmt = $pdo->prepare("
                    DELETE FROM user_emergency_contacts
                    WHERE id = :id
                    LIMIT 1
                ");
                $deleteStmt->execute(array(':id' => $existingId));
            }
            return;
        }

        if ($existingId > 0) {
            $updateStmt = $pdo->prepare("
                UPDATE user_emergency_contacts
                SET
                    contact_name = :contact_name,
                    relationship = :relationship,
                    phone = :phone,
                    sort_order = :sort_order,
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ");
            $updateStmt->execute(array(
                ':contact_name' => $contactName !== '' ? $contactName : null,
                ':relationship' => $relationship !== '' ? $relationship : null,
                ':phone' => $phone !== '' ? $phone : null,
                ':sort_order' => $sortOrder,
                ':id' => $existingId,
            ));
            return;
        }

        $insertStmt = $pdo->prepare("
            INSERT INTO user_emergency_contacts (
                user_id, contact_name, relationship, phone, sort_order, created_at, updated_at
            ) VALUES (
                :user_id, :contact_name, :relationship, :phone, :sort_order, NOW(), NOW()
            )
        ");
        $insertStmt->execute(array(
            ':user_id' => $userId,
            ':contact_name' => $contactName !== '' ? $contactName : null,
            ':relationship' => $relationship !== '' ? $relationship : null,
            ':phone' => $phone !== '' ? $phone : null,
            ':sort_order' => $sortOrder,
        ));
    }
}

if (!function_exists('aue_update_emergency_tab')) {
    function aue_update_emergency_tab(PDO $pdo, int $userId): void
    {
        aue_upsert_emergency_contact_row(
            $pdo,
            $userId,
            1,
            (string)($_POST['contact_name_1'] ?? ''),
            (string)($_POST['relationship_1'] ?? ''),
            (string)($_POST['phone_1'] ?? '')
        );

        aue_upsert_emergency_contact_row(
            $pdo,
            $userId,
            2,
            (string)($_POST['contact_name_2'] ?? ''),
            (string)($_POST['relationship_2'] ?? ''),
            (string)($_POST['phone_2'] ?? '')
        );

        aue_recalculate_profile_requirements_status($pdo, $userId);
    }
}

if (!function_exists('aue_update_billing_tab')) {
    function aue_update_billing_tab(PDO $pdo, int $userId): void
    {
        $useProfileAddress = isset($_POST['use_profile_address']) ? 1 : 0;

        $stmt = $pdo->prepare("
            INSERT INTO user_billing_profiles (
                user_id,
                business_name,
                business_vat_tax_id,
                use_profile_address,
                billing_street_address,
                billing_street_number,
                billing_zip_code,
                billing_city,
                billing_state_region,
                billing_country_code,
                created_at,
                updated_at
            ) VALUES (
                :user_id,
                :business_name,
                :business_vat_tax_id,
                :use_profile_address,
                :billing_street_address,
                :billing_street_number,
                :billing_zip_code,
                :billing_city,
                :billing_state_region,
                :billing_country_code,
                NOW(),
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                business_name = VALUES(business_name),
                business_vat_tax_id = VALUES(business_vat_tax_id),
                use_profile_address = VALUES(use_profile_address),
                billing_street_address = VALUES(billing_street_address),
                billing_street_number = VALUES(billing_street_number),
                billing_zip_code = VALUES(billing_zip_code),
                billing_city = VALUES(billing_city),
                billing_state_region = VALUES(billing_state_region),
                billing_country_code = VALUES(billing_country_code),
                updated_at = NOW()
        ");

        $stmt->execute(array(
            ':user_id' => $userId,
            ':business_name' => trim((string)($_POST['business_name'] ?? '')) ?: null,
            ':business_vat_tax_id' => trim((string)($_POST['business_vat_tax_id'] ?? '')) ?: null,
            ':use_profile_address' => $useProfileAddress,
            ':billing_street_address' => trim((string)($_POST['billing_street_address'] ?? '')) ?: null,
            ':billing_street_number' => trim((string)($_POST['billing_street_number'] ?? '')) ?: null,
            ':billing_zip_code' => trim((string)($_POST['billing_zip_code'] ?? '')) ?: null,
            ':billing_city' => trim((string)($_POST['billing_city'] ?? '')) ?: null,
            ':billing_state_region' => trim((string)($_POST['billing_state_region'] ?? '')) ?: null,
            ':billing_country_code' => strtoupper(trim((string)($_POST['billing_country_code'] ?? ''))) ?: null,
        ));

        aue_recalculate_profile_requirements_status($pdo, $userId);
    }
}

if (!function_exists('aue_recalculate_profile_requirements_status')) {
    function aue_recalculate_profile_requirements_status(PDO $pdo, int $userId): void
    {
        $workspace = aue_load_user_workspace($pdo, $userId);
        if (!$workspace) {
            return;
        }

        $user = is_array($workspace['user'] ?? null) ? $workspace['user'] : array();
        $contacts = is_array($workspace['emergency_contacts'] ?? null) ? $workspace['emergency_contacts'] : array();

        $missing = array();

        if (!aue_has_value($user['first_name'] ?? null)) {
            aue_push_missing_field($missing, 'first_name');
        }
        if (!aue_has_value($user['last_name'] ?? null)) {
            aue_push_missing_field($missing, 'last_name');
        }
        if (!aue_has_value($user['email'] ?? null)) {
            aue_push_missing_field($missing, 'primary_email');
        }
        if (!aue_has_value($user['role'] ?? null)) {
            aue_push_missing_field($missing, 'role');
        }
        if (!aue_has_value($user['status'] ?? null)) {
            aue_push_missing_field($missing, 'status');
        }
        if (aue_policy_bool($pdo, 'user_require_account_valid_until', false) && !aue_has_value($user['account_valid_until'] ?? null)) {
            aue_push_missing_field($missing, 'account_valid_until');
        }
        if (aue_policy_bool($pdo, 'user_require_photo', false) && !aue_has_value($user['photo_path'] ?? null)) {
            aue_push_missing_field($missing, 'photo');
        }

        if (aue_policy_bool($pdo, 'user_require_street_address', true) && !aue_has_value($user['street_address'] ?? null)) {
            aue_push_missing_field($missing, 'street_address');
        }
        if (aue_policy_bool($pdo, 'user_require_street_number', true) && !aue_has_value($user['street_number'] ?? null)) {
            aue_push_missing_field($missing, 'street_number');
        }
        if (aue_policy_bool($pdo, 'user_require_zip_code', true) && !aue_has_value($user['zip_code'] ?? null)) {
            aue_push_missing_field($missing, 'zip_code');
        }
        if (aue_policy_bool($pdo, 'user_require_city', true) && !aue_has_value($user['city'] ?? null)) {
            aue_push_missing_field($missing, 'city');
        }
        if (aue_policy_bool($pdo, 'user_require_state_region', true) && !aue_has_value($user['state_region'] ?? null)) {
            aue_push_missing_field($missing, 'state_region');
        }
        if (aue_policy_bool($pdo, 'user_require_country_code', true) && !aue_has_value($user['country_code'] ?? null)) {
            aue_push_missing_field($missing, 'country_code');
        }
        if (aue_policy_bool($pdo, 'user_require_cellphone', true) && !aue_has_value($user['cellphone'] ?? null)) {
            aue_push_missing_field($missing, 'cellphone');
        }
        if (aue_policy_bool($pdo, 'user_require_secondary_email', false) && !aue_has_value($user['secondary_email'] ?? null)) {
            aue_push_missing_field($missing, 'secondary_email');
        }
        if (aue_policy_bool($pdo, 'user_require_date_of_birth', true) && !aue_has_value($user['date_of_birth'] ?? null)) {
            aue_push_missing_field($missing, 'date_of_birth');
        }
        if (aue_policy_bool($pdo, 'user_require_place_of_birth', true) && !aue_has_value($user['place_of_birth'] ?? null)) {
            aue_push_missing_field($missing, 'place_of_birth');
        }
        if (aue_policy_bool($pdo, 'user_require_nationality', true) && !aue_has_value($user['nationality'] ?? null)) {
            aue_push_missing_field($missing, 'nationality');
        }
        if (aue_policy_bool($pdo, 'user_require_id_passport_number', true) && !aue_has_value($user['id_passport_number'] ?? null)) {
            aue_push_missing_field($missing, 'id_passport_number');
        }
        if (aue_policy_bool($pdo, 'user_require_gender', true) && !aue_has_value($user['gender'] ?? null)) {
            aue_push_missing_field($missing, 'gender');
        }
        if (aue_policy_bool($pdo, 'user_require_weight', true) && !aue_has_value($user['weight'] ?? null)) {
            aue_push_missing_field($missing, 'weight_kg');
        }
        if (aue_policy_bool($pdo, 'user_require_height_cm', true) && !aue_has_value($user['height_cm'] ?? null)) {
            aue_push_missing_field($missing, 'height_cm');
        }
        if (aue_policy_bool($pdo, 'user_require_hair_color', true) && !aue_has_value($user['hair_color'] ?? null)) {
            aue_push_missing_field($missing, 'hair_color');
        }
        if (aue_policy_bool($pdo, 'user_require_eye_color', true) && !aue_has_value($user['eye_color'] ?? null)) {
            aue_push_missing_field($missing, 'eye_color');
        }
        if (aue_policy_bool($pdo, 'user_require_marital_status', true) && !aue_has_value($user['marital_status'] ?? null)) {
            aue_push_missing_field($missing, 'marital_status');
        }

        $requiredContactCount = max(0, aue_policy_int($pdo, 'user_required_emergency_contact_count', 2));

        for ($i = 1; $i <= $requiredContactCount; $i++) {
            $contact = null;

            foreach ($contacts as $row) {
                if ((int)($row['sort_order'] ?? 0) === $i) {
                    $contact = $row;
                    break;
                }
            }

            $contact = is_array($contact) ? $contact : array();

            if ($i === 1) {
                if (aue_policy_bool($pdo, 'user_require_emergency_contact_name', true) && !aue_has_value($contact['contact_name'] ?? null)) {
                    aue_push_missing_field($missing, 'emergency_contact_1_name');
                }
                if (aue_policy_bool($pdo, 'user_require_emergency_contact_relationship', true) && !aue_has_value($contact['relationship'] ?? null)) {
                    aue_push_missing_field($missing, 'emergency_contact_1_relationship');
                }
                if (aue_policy_bool($pdo, 'user_require_emergency_contact_phone', true) && !aue_has_value($contact['phone'] ?? null)) {
                    aue_push_missing_field($missing, 'emergency_contact_1_phone');
                }
            }

            if ($i === 2) {
                if (aue_policy_bool($pdo, 'user_require_emergency_contact_name', true) && !aue_has_value($contact['contact_name'] ?? null)) {
                    aue_push_missing_field($missing, 'emergency_contact_2_name');
                }
                if (aue_policy_bool($pdo, 'user_require_emergency_contact_relationship', true) && !aue_has_value($contact['relationship'] ?? null)) {
                    aue_push_missing_field($missing, 'emergency_contact_2_relationship');
                }
                if (aue_policy_bool($pdo, 'user_require_emergency_contact_phone', true) && !aue_has_value($contact['phone'] ?? null)) {
                    aue_push_missing_field($missing, 'emergency_contact_2_phone');
                }
            }
        }

        $useProfileAddress = (int)($user['use_profile_address'] ?? 1) === 1;

        $billingTriggered =
            aue_has_value($user['business_name'] ?? null) ||
            aue_has_value($user['business_vat_tax_id'] ?? null) ||
            !$useProfileAddress ||
            aue_has_value($user['billing_street_address'] ?? null) ||
            aue_has_value($user['billing_street_number'] ?? null) ||
            aue_has_value($user['billing_zip_code'] ?? null) ||
            aue_has_value($user['billing_city'] ?? null) ||
            aue_has_value($user['billing_state_region'] ?? null) ||
            aue_has_value($user['billing_country_code'] ?? null);

        if ($billingTriggered && aue_policy_bool($pdo, 'user_billing_require_when_business_used', true)) {
            if (aue_policy_bool($pdo, 'user_billing_require_business_name', true) && !aue_has_value($user['business_name'] ?? null)) {
                aue_push_missing_field($missing, 'business_name');
            }

            if (aue_policy_bool($pdo, 'user_billing_require_business_vat_tax_id', true) && !aue_has_value($user['business_vat_tax_id'] ?? null)) {
                aue_push_missing_field($missing, 'business_vat_tax_id');
            }

            if (!$useProfileAddress && aue_policy_bool($pdo, 'user_billing_require_dedicated_address_if_not_using_profile', true)) {
                if (!aue_has_value($user['billing_street_address'] ?? null)) {
                    aue_push_missing_field($missing, 'billing_street_address');
                }
                if (!aue_has_value($user['billing_street_number'] ?? null)) {
                    aue_push_missing_field($missing, 'billing_street_number');
                }
                if (!aue_has_value($user['billing_zip_code'] ?? null)) {
                    aue_push_missing_field($missing, 'billing_zip_code');
                }
                if (!aue_has_value($user['billing_city'] ?? null)) {
                    aue_push_missing_field($missing, 'billing_city');
                }
                if (!aue_has_value($user['billing_state_region'] ?? null)) {
                    aue_push_missing_field($missing, 'billing_state_region');
                }
                if (!aue_has_value($user['billing_country_code'] ?? null)) {
                    aue_push_missing_field($missing, 'billing_country_code');
                }
            }
        }

        $missing = array_values($missing);
        $missingJson = json_encode($missing, JSON_UNESCAPED_UNICODE);
        if (!is_string($missingJson)) {
            $missingJson = '[]';
        }

        $missingCount = count($missing);
        $isComplete = $missingCount === 0 ? 1 : 0;

        $stmt = $pdo->prepare("
            INSERT INTO user_profile_requirements_status (
                user_id,
                missing_fields_json,
                missing_count,
                is_profile_complete,
                last_evaluated_at
            ) VALUES (
                :user_id,
                :missing_fields_json,
                :missing_count,
                :is_profile_complete,
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                missing_fields_json = VALUES(missing_fields_json),
                missing_count = VALUES(missing_count),
                is_profile_complete = VALUES(is_profile_complete),
                last_evaluated_at = NOW()
        ");

        $stmt->execute(array(
            ':user_id' => $userId,
            ':missing_fields_json' => $missingJson,
            ':missing_count' => $missingCount,
            ':is_profile_complete' => $isComplete,
        ));
    }
}

if (!function_exists('aue_recalculate_all_profile_requirements_status')) {
    function aue_recalculate_all_profile_requirements_status(PDO $pdo): void
    {
        $stmt = $pdo->query("SELECT id FROM users ORDER BY id ASC");
        $ids = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : array();

        if (!is_array($ids)) {
            return;
        }

        foreach ($ids as $id) {
            $userId = (int)$id;
            if ($userId > 0) {
                aue_recalculate_profile_requirements_status($pdo, $userId);
            }
        }
    }
}

if (!function_exists('aue_activate_pending_user')) {
    function aue_activate_pending_user(PDO $pdo, int $userId, int $actorId): void
    {
        if ($userId <= 0) {
            throw new RuntimeException('Invalid user id.');
        }

        $stmt = $pdo->prepare("
            SELECT id, name, first_name, last_name, email, status, must_change_password
            FROM users
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(array(
            ':id' => $userId,
        ));
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($user)) {
            throw new RuntimeException('User not found.');
        }

        $status = strtolower(trim((string)($user['status'] ?? '')));
        if ($status !== 'pending_activation') {
            throw new RuntimeException('Only pending-activation users can be activated.');
        }

        $email = trim((string)($user['email'] ?? ''));
        if ($email === '') {
            throw new RuntimeException('Cannot activate a user without an email address.');
        }

        $pdo->beginTransaction();

        try {
            $update = $pdo->prepare("
                UPDATE users
                SET
                    status = 'active',
                    must_change_password = 1,
                    updated_by_user_id = :updated_by_user_id,
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ");
            $update->execute(array(
                ':updated_by_user_id' => $actorId > 0 ? $actorId : null,
                ':id' => $userId,
            ));

                        $tokenRow = ot_create_token(
                $pdo,
                $userId,
                'set_password',
                $actorId > 0 ? $actorId : null,
                60
            );

            $user['status'] = 'active';
            $user['must_change_password'] = 1;

            $displayName = trim((string)($user['name'] ?? ''));
            if ($displayName === '') {
                $displayName = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
            }
            if ($displayName === '') {
                $displayName = 'User';
            }

            $baseUrl = '';
            $appUrl = trim((string)($_ENV['APP_URL'] ?? ''));
            if ($appUrl !== '') {
                $baseUrl = rtrim($appUrl, '/');
            } elseif (!empty($_SERVER['HTTP_HOST'])) {
                $scheme = 'https';
                if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
                    $scheme = 'https';
                } elseif ((string)($_SERVER['SERVER_PORT'] ?? '') === '80') {
                    $scheme = 'http';
                }
                $baseUrl = $scheme . '://' . trim((string)$_SERVER['HTTP_HOST']);
            }

            $rawToken = trim((string)($tokenRow['raw_token'] ?? ''));
            $setPasswordLink = $baseUrl !== '' && $rawToken !== ''
                ? ($baseUrl . '/set_password.php?token=' . urlencode($rawToken))
                : '';

            $expiryMinutes = '60';
            $expiryDisplay = '';
            if (!empty($tokenRow['expires_at'])) {
                $ts = strtotime((string)$tokenRow['expires_at']);
                if ($ts) {
                    $expiryDisplay = date('D, M j, Y g:i A', $ts);
                }
            }

            $automationRuntime = new AutomationRuntime();
            $automationRuntime->dispatchEvent(
                $pdo,
                'user_onboarding_created',
                array(
                    'user_id' => $userId,
                    'user_name' => $displayName,
                    'first_name' => (string)($user['first_name'] ?? ''),
                    'last_name' => (string)($user['last_name'] ?? ''),
                    'user_email' => $email,
                    'email' => $email,
                    'login_email' => $email,
                    'to_email' => $email,
                    'to_name' => $displayName,
                    'set_password_link' => $setPasswordLink,
                    'reset_link' => $setPasswordLink,
                    'expiry_minutes' => $expiryMinutes,
                    'expiry_datetime' => $expiryDisplay,
                    'support_email' => ot_support_email(),
                    'actor_user_id' => $actorId > 0 ? $actorId : null
                )
            );

            aue_recalculate_profile_requirements_status($pdo, $userId);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}