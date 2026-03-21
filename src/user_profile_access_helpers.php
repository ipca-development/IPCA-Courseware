<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| User Profile Access Helpers
|--------------------------------------------------------------------------
| Neutral helper layer for self-service and instructor-facing user data.
| This file MUST NOT depend on admin-only helper architecture.
|--------------------------------------------------------------------------
*/

if (!function_exists('ups_policy_raw')) {
    function ups_policy_raw(PDO $pdo, string $policyKey, string $scopeType = 'global', ?int $scopeId = null): ?string
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

if (!function_exists('ups_policy_bool')) {
    function ups_policy_bool(PDO $pdo, string $policyKey, bool $default = false, string $scopeType = 'global', ?int $scopeId = null): bool
    {
        $raw = ups_policy_raw($pdo, $policyKey, $scopeType, $scopeId);
        if ($raw === null) {
            return $default;
        }

        $normalized = strtolower(trim($raw));
        return in_array($normalized, array('1', 'true', 'yes', 'on'), true);
    }
}

if (!function_exists('ups_policy_int')) {
    function ups_policy_int(PDO $pdo, string $policyKey, int $default = 0, string $scopeType = 'global', ?int $scopeId = null): int
    {
        $raw = ups_policy_raw($pdo, $policyKey, $scopeType, $scopeId);
        if ($raw === null || trim($raw) === '' || !is_numeric($raw)) {
            return $default;
        }

        return (int)$raw;
    }
}

if (!function_exists('ups_normalize_date')) {
    function ups_normalize_date(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        $ts = strtotime($value);
        return $ts ? date('Y-m-d', $ts) : null;
    }
}

if (!function_exists('ups_normalize_decimal')) {
    function ups_normalize_decimal(?string $value): ?string
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

if (!function_exists('ups_has_value')) {
    function ups_has_value($value): bool
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

if (!function_exists('ups_profile_field_map')) {
    function ups_profile_field_map(): array
    {
        static $map = null;

        if (is_array($map)) {
            return $map;
        }

        $map = array(
            'first_name' => array(
                'key' => 'first_name',
                'label' => 'First Name',
                'tab' => 'personal',
                'anchor' => 'first_name',
                'admin_edit' => true,
                'self_edit' => true,
                'self_edit_policy_key' => null,
                'instructor_view_policy_key' => null,
                'instructor_edit' => false,
            ),
            'last_name' => array(
                'key' => 'last_name',
                'label' => 'Last Name',
                'tab' => 'personal',
                'anchor' => 'last_name',
                'admin_edit' => true,
                'self_edit' => true,
                'self_edit_policy_key' => null,
                'instructor_view_policy_key' => null,
                'instructor_edit' => false,
            ),
            'email' => array(
                'key' => 'email',
                'label' => 'Primary Email',
                'tab' => 'personal',
                'anchor' => 'email',
                'admin_edit' => true,
                'self_edit' => false,
                'self_edit_policy_key' => null,
                'instructor_view_policy_key' => 'instructor_can_view_student_email',
                'instructor_edit' => false,
            ),
            'photo_path' => array(
                'key' => 'photo_path',
                'label' => 'Photo',
                'tab' => 'personal',
                'anchor' => 'photo',
                'admin_edit' => true,
                'self_edit' => true,
                'self_edit_policy_key' => null,
                'instructor_view_policy_key' => null,
                'instructor_edit' => false,
            ),
            'street_address' => array(
                'key' => 'street_address',
                'label' => 'Street Address',
                'tab' => 'personal',
                'anchor' => 'street_address',
                'admin_edit' => true,
                'self_edit' => true,
                'self_edit_policy_key' => null,
                'instructor_view_policy_key' => null,
                'instructor_edit' => false,
            ),
            'street_number' => array(
                'key' => 'street_number',
                'label' => 'Street Number',
                'tab' => 'personal',
                'anchor' => 'street_number',
                'admin_edit' => true,
                'self_edit' => true,
                'self_edit_policy_key' => null,
                'instructor_view_policy_key' => null,
                'instructor_edit' => false,
            ),
            'zip_code' => array(
                'key' => 'zip_code',
                'label' => 'Zip Code',
                'tab' => 'personal',
                'anchor' => 'zip_code',
                'admin_edit' => true,
                'self_edit' => true,
                'self_edit_policy_key' => null,
                'instructor_view_policy_key' => null,
                'instructor_edit' => false,
            ),
            'city' => array(
                'key' => 'city',
                'label' => 'City',
                'tab' => 'personal',
                'anchor' => 'city',
                'admin_edit' => true,
                'self_edit' => true,
                'self_edit_policy_key' => null,
                'instructor_view_policy_key' => null,
                'instructor_edit' => false,
            ),
            'state_region' => array(
                'key' => 'state_region',
                'label' => 'State / Region',
                'tab' => 'personal',
                'anchor' => 'state_region',
                'admin_edit' => true,
                'self_edit' => true,
                'self_edit_policy_key' => null,
                'instructor_view_policy_key' => null,
                'instructor_edit' => false,
            ),
            'country_code' => array(
                'key' => 'country_code',
                'label' => 'Country',
                'tab' => 'personal',
                'anchor' => 'country_code',
                'admin_edit' => true,
                'self_edit' => true,
                'self_edit_policy_key' => null,
                'instructor_view_policy_key' => null,
                'instructor_edit' => false,
            ),
            'cellphone' => array(
                'key' => 'cellphone',
                'label' => 'Cellphone',
                'tab' => 'personal',
                'anchor' => 'cellphone',
                'admin_edit' => true,
                'self_edit' => true,
                'self_edit_policy_key' => null,
                'instructor_view_policy_key' => 'instructor_can_view_student_cellphone',
                'instructor_edit' => false,
            ),
            'secondary_email' => array(
                'key' => 'secondary_email',
                'label' => 'Secondary Email',
                'tab' => 'personal',
                'anchor' => 'secondary_email',
                'admin_edit' => true,
                'self_edit' => true,
                'self_edit_policy_key' => null,
                'instructor_view_policy_key' => null,
                'instructor_edit' => false,
            ),
            'date_of_birth' => array(
                'key' => 'date_of_birth',
                'label' => 'Date of Birth',
                'tab' => 'personal',
                'anchor' => 'date_of_birth',
                'admin_edit' => true,
                'self_edit' => true,
                'self_edit_policy_key' => null,
                'instructor_view_policy_key' => 'instructor_can_view_student_date_of_birth',
                'instructor_edit' => false,
            ),
            'place_of_birth' => array(
                'key' => 'place_of_birth',
                'label' => 'Place of Birth',
                'tab' => 'personal',
                'anchor' => 'place_of_birth',
                'admin_edit' => true,
                'self_edit' => true,
                'self_edit_policy_key' => null,
                'instructor_view_policy_key' => null,
                'instructor_edit' => false,
            ),
            'nationality' => array(
                'key' => 'nationality',
                'label' => 'Nationality',
                'tab' => 'personal',
                'anchor' => 'nationality',
                'admin_edit' => true,
                'self_edit' => true,
                'self_edit_policy_key' => null,
                'instructor_view_policy_key' => 'instructor_can_view_student_nationality',
                'instructor_edit' => false,
            ),
            'id_passport_number' => array(
                'key' => 'id_passport_number',
                'label' => 'ID / Passport Number',
                'tab' => 'personal',
                'anchor' => 'id_passport_number',
                'admin_edit' => true,
                'self_edit' => true,
                'self_edit_policy_key' => null,
                'instructor_view_policy_key' => null,
                'instructor_edit' => false,
            ),
            'gender' => array(
                'key' => 'gender',
                'label' => 'Gender',
                'tab' => 'personal',
                'anchor' => 'gender',
                'admin_edit' => true,
                'self_edit' => true,
                'self_edit_policy_key' => null,
                'instructor_view_policy_key' => null,
                'instructor_edit' => false,
            ),
            'weight_kg' => array(
                'key' => 'weight_kg',
                'label' => 'Weight',
                'tab' => 'personal',
                'anchor' => 'weight_kg',
                'admin_edit' => true,
                'self_edit' => true,
                'self_edit_policy_key' => null,
                'instructor_view_policy_key' => null,
                'instructor_edit' => false,
                /* Transitional compatibility bridge only.
                   Canonical architecture is weight_kg, but current DB/storage column is still weight.
                   Do not propagate this mismatch into new code or future schema work. */
                'storage_column' => 'weight',
            ),
            'height_cm' => array(
                'key' => 'height_cm',
                'label' => 'Height',
                'tab' => 'personal',
                'anchor' => 'height_cm',
                'admin_edit' => true,
                'self_edit' => true,
                'self_edit_policy_key' => null,
                'instructor_view_policy_key' => null,
                'instructor_edit' => false,
            ),
            'hair_color' => array(
                'key' => 'hair_color',
                'label' => 'Hair Color',
                'tab' => 'personal',
                'anchor' => 'hair_color',
                'admin_edit' => true,
                'self_edit' => true,
                'self_edit_policy_key' => null,
                'instructor_view_policy_key' => null,
                'instructor_edit' => false,
            ),
            'eye_color' => array(
                'key' => 'eye_color',
                'label' => 'Eye Color',
                'tab' => 'personal',
                'anchor' => 'eye_color',
                'admin_edit' => true,
                'self_edit' => true,
                'self_edit_policy_key' => null,
                'instructor_view_policy_key' => null,
                'instructor_edit' => false,
            ),
            'marital_status' => array(
                'key' => 'marital_status',
                'label' => 'Marital Status',
                'tab' => 'personal',
                'anchor' => 'marital_status',
                'admin_edit' => true,
                'self_edit' => true,
                'self_edit_policy_key' => null,
                'instructor_view_policy_key' => null,
                'instructor_edit' => false,
            ),
            'emergency_contact_1_name' => array(
                'key' => 'emergency_contact_1_name',
                'label' => 'Emergency Contact 1 Name',
                'tab' => 'emergency',
                'anchor' => 'contact_name_1',
                'admin_edit' => true,
                'self_edit' => true,
                'self_edit_policy_key' => null,
                'instructor_view_policy_key' => 'instructor_can_view_student_emergency_contacts',
                'instructor_edit' => false,
            ),
            'emergency_contact_1_relationship' => array(
                'key' => 'emergency_contact_1_relationship',
                'label' => 'Emergency Contact 1 Relationship',
                'tab' => 'emergency',
                'anchor' => 'relationship_1',
                'admin_edit' => true,
                'self_edit' => true,
                'self_edit_policy_key' => null,
                'instructor_view_policy_key' => 'instructor_can_view_student_emergency_contacts',
                'instructor_edit' => false,
            ),
            'emergency_contact_1_phone' => array(
                'key' => 'emergency_contact_1_phone',
                'label' => 'Emergency Contact 1 Phone',
                'tab' => 'emergency',
                'anchor' => 'phone_1',
                'admin_edit' => true,
                'self_edit' => true,
                'self_edit_policy_key' => null,
                'instructor_view_policy_key' => 'instructor_can_view_student_emergency_contacts',
                'instructor_edit' => false,
            ),
            'emergency_contact_2_name' => array(
                'key' => 'emergency_contact_2_name',
                'label' => 'Emergency Contact 2 Name',
                'tab' => 'emergency',
                'anchor' => 'contact_name_2',
                'admin_edit' => true,
                'self_edit' => true,
                'self_edit_policy_key' => null,
                'instructor_view_policy_key' => 'instructor_can_view_student_emergency_contacts',
                'instructor_edit' => false,
            ),
            'emergency_contact_2_relationship' => array(
                'key' => 'emergency_contact_2_relationship',
                'label' => 'Emergency Contact 2 Relationship',
                'tab' => 'emergency',
                'anchor' => 'relationship_2',
                'admin_edit' => true,
                'self_edit' => true,
                'self_edit_policy_key' => null,
                'instructor_view_policy_key' => 'instructor_can_view_student_emergency_contacts',
                'instructor_edit' => false,
            ),
            'emergency_contact_2_phone' => array(
                'key' => 'emergency_contact_2_phone',
                'label' => 'Emergency Contact 2 Phone',
                'tab' => 'emergency',
                'anchor' => 'phone_2',
                'admin_edit' => true,
                'self_edit' => true,
                'self_edit_policy_key' => null,
                'instructor_view_policy_key' => 'instructor_can_view_student_emergency_contacts',
                'instructor_edit' => false,
            ),
            'business_name' => array(
                'key' => 'business_name',
                'label' => 'Business Name',
                'tab' => 'billing',
                'anchor' => 'business_name',
                'admin_edit' => true,
                'self_edit' => false,
                'self_edit_policy_key' => 'user_can_edit_own_billing',
                'instructor_view_policy_key' => null,
                'instructor_edit' => false,
            ),
            'business_vat_tax_id' => array(
                'key' => 'business_vat_tax_id',
                'label' => 'Business VAT / Tax ID',
                'tab' => 'billing',
                'anchor' => 'business_vat_tax_id',
                'admin_edit' => true,
                'self_edit' => false,
                'self_edit_policy_key' => 'user_can_edit_own_billing',
                'instructor_view_policy_key' => null,
                'instructor_edit' => false,
            ),
            'billing_street_address' => array(
                'key' => 'billing_street_address',
                'label' => 'Billing Street Address',
                'tab' => 'billing',
                'anchor' => 'billing_street_address',
                'admin_edit' => true,
                'self_edit' => false,
                'self_edit_policy_key' => 'user_can_edit_own_billing',
                'instructor_view_policy_key' => null,
                'instructor_edit' => false,
            ),
            'billing_street_number' => array(
                'key' => 'billing_street_number',
                'label' => 'Billing Street Number',
                'tab' => 'billing',
                'anchor' => 'billing_street_number',
                'admin_edit' => true,
                'self_edit' => false,
                'self_edit_policy_key' => 'user_can_edit_own_billing',
                'instructor_view_policy_key' => null,
                'instructor_edit' => false,
            ),
            'billing_zip_code' => array(
                'key' => 'billing_zip_code',
                'label' => 'Billing Zip Code',
                'tab' => 'billing',
                'anchor' => 'billing_zip_code',
                'admin_edit' => true,
                'self_edit' => false,
                'self_edit_policy_key' => 'user_can_edit_own_billing',
                'instructor_view_policy_key' => null,
                'instructor_edit' => false,
            ),
            'billing_city' => array(
                'key' => 'billing_city',
                'label' => 'Billing City',
                'tab' => 'billing',
                'anchor' => 'billing_city',
                'admin_edit' => true,
                'self_edit' => false,
                'self_edit_policy_key' => 'user_can_edit_own_billing',
                'instructor_view_policy_key' => null,
                'instructor_edit' => false,
            ),
            'billing_state_region' => array(
                'key' => 'billing_state_region',
                'label' => 'Billing State / Region',
                'tab' => 'billing',
                'anchor' => 'billing_state_region',
                'admin_edit' => true,
                'self_edit' => false,
                'self_edit_policy_key' => 'user_can_edit_own_billing',
                'instructor_view_policy_key' => null,
                'instructor_edit' => false,
            ),
            'billing_country_code' => array(
                'key' => 'billing_country_code',
                'label' => 'Billing Country',
                'tab' => 'billing',
                'anchor' => 'billing_country_code',
                'admin_edit' => true,
                'self_edit' => false,
                'self_edit_policy_key' => 'user_can_edit_own_billing',
                'instructor_view_policy_key' => null,
                'instructor_edit' => false,
            ),
        );

        return $map;
    }
}

if (!function_exists('ups_profile_field_meta')) {
    function ups_profile_field_meta(string $key): ?array
    {
        $map = ups_profile_field_map();
        return isset($map[$key]) ? $map[$key] : null;
    }
}

if (!function_exists('ups_profile_missing_item')) {
    function ups_profile_missing_item(string $key): ?array
    {
        $meta = ups_profile_field_meta($key);
        if (!is_array($meta)) {
            return null;
        }

        return array(
            'key' => (string)$meta['key'],
            'tab' => (string)$meta['tab'],
            'anchor' => (string)$meta['anchor'],
        );
    }
}

if (!function_exists('ups_profile_missing_items_json')) {
    function ups_profile_missing_items_json(array $keys): string
    {
        $items = array();

        foreach ($keys as $key) {
            $item = ups_profile_missing_item((string)$key);
            if (is_array($item)) {
                $items[] = $item;
            }
        }

        $json = json_encode(array_values($items), JSON_UNESCAPED_UNICODE);
        return is_string($json) ? $json : '[]';
    }
}

if (!function_exists('ups_self_can_edit_field')) {
    function ups_self_can_edit_field(PDO $pdo, string $key): bool
    {
        $meta = ups_profile_field_meta($key);
        if (!is_array($meta)) {
            return false;
        }

        $policyKey = trim((string)($meta['self_edit_policy_key'] ?? ''));
        if ($policyKey !== '') {
            return ups_policy_bool($pdo, $policyKey, (bool)($meta['self_edit'] ?? false));
        }

        return (bool)($meta['self_edit'] ?? false);
    }
}

if (!function_exists('ups_instructor_can_view_field')) {
    function ups_instructor_can_view_field(PDO $pdo, string $key): bool
    {
        $meta = ups_profile_field_meta($key);
        if (!is_array($meta)) {
            return false;
        }

        $policyKey = trim((string)($meta['instructor_view_policy_key'] ?? ''));
        if ($policyKey === '') {
            return false;
        }

        return ups_policy_bool($pdo, $policyKey, false);
    }
}

if (!function_exists('ups_self_service_tabs')) {
    function ups_self_service_tabs(): array
    {
        return array(
            'personal' => 'Personal Details',
            'emergency' => 'Emergency Contacts',
            'password' => 'Password',
        );
    }
}

if (!function_exists('ups_self_service_editable_keys')) {
    function ups_self_service_editable_keys(PDO $pdo): array
    {
        $keys = array();

        foreach (ups_profile_field_map() as $key => $meta) {
            if ((string)($meta['tab'] ?? '') === 'billing') {
                continue;
            }

            if (ups_self_can_edit_field($pdo, (string)$key)) {
                $keys[] = (string)$key;
            }
        }

        return $keys;
    }
}

if (!function_exists('ups_instructor_visible_keys')) {
    function ups_instructor_visible_keys(PDO $pdo): array
    {
        /* Baseline instructor-visible identity fields are always visible.
           These are intentionally not policy-driven. */
        $keys = array(
            'first_name',
            'last_name',
            'photo_path',
        );

        foreach (ups_profile_field_map() as $key => $meta) {
            if (ups_instructor_can_view_field($pdo, (string)$key)) {
                $keys[] = (string)$key;
            }
        }

        return array_values(array_unique($keys));
    }
}

if (!function_exists('ups_missing_field_url')) {
    function ups_missing_field_url(array $missingItem, string $basePath): string
    {
        $tab = trim((string)($missingItem['tab'] ?? 'personal'));
        $anchor = trim((string)($missingItem['anchor'] ?? ''));

        $url = $basePath . '?' . http_build_query(array(
            'tab' => $tab,
        ));

        if ($anchor !== '') {
            $url .= '#' . rawurlencode($anchor);
        }

        return $url;
    }
}

if (!function_exists('ups_decode_missing_fields')) {
    function ups_decode_missing_fields(?string $json): array
    {
        $json = trim((string)$json);
        if ($json === '') {
            return array();
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return array();
        }

        $items = array();

        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }

            $key = trim((string)($item['key'] ?? ''));
            $tab = trim((string)($item['tab'] ?? ''));
            $anchor = trim((string)($item['anchor'] ?? ''));

            if ($key === '' || $tab === '' || $anchor === '') {
                continue;
            }

            $meta = ups_profile_field_meta($key);
            if (!is_array($meta)) {
                continue;
            }

            $items[] = array(
                'key' => $key,
                'label' => (string)$meta['label'],
                'tab' => $tab,
                'anchor' => $anchor,
            );
        }

        return $items;
    }
}

if (!function_exists('ups_empty_emergency_contact')) {
    function ups_empty_emergency_contact(int $sortOrder): array
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

if (!function_exists('ups_load_emergency_contacts')) {
    function ups_load_emergency_contacts(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare("
            SELECT id, user_id, contact_name, relationship, phone, sort_order, created_at, updated_at
            FROM user_emergency_contacts
            WHERE user_id = :user_id
            ORDER BY sort_order ASC, id ASC
        ");
        $stmt->execute(array(
            ':user_id' => $userId,
        ));

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = array();
        }

        $primary = ups_empty_emergency_contact(1);
        $secondary = ups_empty_emergency_contact(2);

        foreach ($rows as $row) {
            $sortOrder = (int)($row['sort_order'] ?? 0);
            if ($sortOrder === 1 && $primary['id'] === null) {
                $primary = $row;
            } elseif ($sortOrder === 2 && $secondary['id'] === null) {
                $secondary = $row;
            }
        }

        return array(
            'all' => $rows,
            'primary' => $primary,
            'secondary' => $secondary,
        );
    }
}

if (!function_exists('ups_load_self_service_workspace')) {
    function ups_load_self_service_workspace(PDO $pdo, int $userId): ?array
    {
        $stmt = $pdo->prepare("
            SELECT
                u.id,
                u.uuid,
                u.name,
                u.first_name,
                u.last_name,
                u.email,
                u.photo_path,

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

                req.missing_fields_json,
                COALESCE(req.missing_count, 0) AS missing_count,
                req.is_profile_complete,
                req.last_evaluated_at
            FROM users u
            LEFT JOIN user_profiles p
                ON p.user_id = u.id
            LEFT JOIN user_profile_requirements_status req
                ON req.user_id = u.id
            WHERE u.id = :id
            LIMIT 1
        ");
        $stmt->execute(array(
            ':id' => $userId,
        ));

        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($user)) {
            return null;
        }

        if (array_key_exists('weight', $user) && !array_key_exists('weight_kg', $user)) {
            $user['weight_kg'] = $user['weight'];
        }

        $contacts = ups_load_emergency_contacts($pdo, $userId);
        $missingFields = ups_decode_missing_fields((string)($user['missing_fields_json'] ?? ''));

        $displayName = trim((string)($user['name'] ?? ''));
        if ($displayName === '') {
            $displayName = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
        }
        if ($displayName === '') {
            $displayName = 'User #' . $userId;
        }

        return array(
            'user' => $user,
            'display_name' => $displayName,
            'missing_fields' => $missingFields,
            'editable_keys' => ups_self_service_editable_keys($pdo),
            'emergency_contacts' => $contacts['all'],
            'emergency_primary' => $contacts['primary'],
            'emergency_secondary' => $contacts['secondary'],
        );
    }
}

if (!function_exists('ups_load_instructor_student_workspace')) {
    function ups_load_instructor_student_workspace(PDO $pdo, int $studentId, int $instructorId): ?array
    {
        /* PHASE 1 SECURITY GUARD:
           This validates the caller is an active instructor-role user.
           Full instructor-to-student assignment authorization is still TODO
           and MUST be added before this is treated as final production access control. */
        $instructorStmt = $pdo->prepare("
            SELECT id
            FROM users
            WHERE id = :id
              AND status = 'active'
              AND role IN ('instructor', 'supervisor', 'chief_instructor')
            LIMIT 1
        ");
        $instructorStmt->execute(array(
            ':id' => $instructorId,
        ));

        if (!$instructorStmt->fetchColumn()) {
            return null;
        }

        $stmt = $pdo->prepare("
            SELECT
                u.id,
                u.uuid,
                u.name,
                u.first_name,
                u.last_name,
                u.email,
                u.photo_path,

                p.cellphone,
                p.date_of_birth,
                p.nationality
            FROM users u
            LEFT JOIN user_profiles p
                ON p.user_id = u.id
            WHERE u.id = :id
              AND u.role = 'student'
            LIMIT 1
        ");
        $stmt->execute(array(
            ':id' => $studentId,
        ));

        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($student)) {
            return null;
        }

        $visibleKeys = ups_instructor_visible_keys($pdo);

        if (!in_array('email', $visibleKeys, true)) {
            unset($student['email']);
        }
        if (!in_array('cellphone', $visibleKeys, true)) {
            unset($student['cellphone']);
        }
        if (!in_array('date_of_birth', $visibleKeys, true)) {
            unset($student['date_of_birth']);
        }
        if (!in_array('nationality', $visibleKeys, true)) {
            unset($student['nationality']);
        }

        $contacts = array(
            'all' => array(),
            'primary' => ups_empty_emergency_contact(1),
            'secondary' => ups_empty_emergency_contact(2),
        );

        if (in_array('emergency_contact_1_name', $visibleKeys, true)
            || in_array('emergency_contact_1_relationship', $visibleKeys, true)
            || in_array('emergency_contact_1_phone', $visibleKeys, true)
            || in_array('emergency_contact_2_name', $visibleKeys, true)
            || in_array('emergency_contact_2_relationship', $visibleKeys, true)
            || in_array('emergency_contact_2_phone', $visibleKeys, true)
        ) {
            $contacts = ups_load_emergency_contacts($pdo, $studentId);
        }

        $displayName = trim((string)($student['name'] ?? ''));
        if ($displayName === '') {
            $displayName = trim((string)($student['first_name'] ?? '') . ' ' . (string)($student['last_name'] ?? ''));
        }
        if ($displayName === '') {
            $displayName = 'Student #' . $studentId;
        }

        return array(
            'student' => $student,
            'display_name' => $displayName,
            'visible_keys' => $visibleKeys,
            'emergency_contacts' => $contacts['all'],
            'emergency_primary' => $contacts['primary'],
            'emergency_secondary' => $contacts['secondary'],
        );
    }
}

if (!function_exists('ups_verify_current_password')) {
    function ups_verify_current_password(PDO $pdo, int $userId, string $currentPassword): bool
    {
        $stmt = $pdo->prepare("
            SELECT password_hash
            FROM users
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(array(
            ':id' => $userId,
        ));

        $hash = $stmt->fetchColumn();
        if (!is_string($hash) || $hash === '') {
            return false;
        }

        return password_verify($currentPassword, $hash);
    }
}

if (!function_exists('ups_update_password')) {
    function ups_update_password(PDO $pdo, int $userId, string $newPassword): void
    {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('Unable to generate password hash.');
        }

        $stmt = $pdo->prepare("
            UPDATE users
            SET
                password_hash = :password_hash,
                must_change_password = 0,
                password_changed_at = NOW(),
                updated_at = NOW()
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(array(
            ':password_hash' => $hash,
            ':id' => $userId,
        ));
    }
}