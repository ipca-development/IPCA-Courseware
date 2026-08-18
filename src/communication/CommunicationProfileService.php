<?php
declare(strict_types=1);

require_once __DIR__ . '/CommunicationSupport.php';
require_once dirname(__DIR__) . '/user_profile_access_helpers.php';

/**
 * Self-service Courseware profile for the IPCA app.
 * Writes the same users / user_profiles / photo paths as /student/profile.php.
 */
final class CommunicationProfileService
{
    public const PHOTO_MAX_BYTES = 5242880;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public function get(array $session): array
    {
        $userId = (int)$session['user']['id'];
        return $this->payload($userId);
    }

    /**
     * @param array<string,mixed> $session
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function savePersonal(array $session, array $input): array
    {
        $userId = (int)$session['user']['id'];
        $firstName = trim((string)($input['first_name'] ?? ''));
        $lastName = trim((string)($input['last_name'] ?? ''));
        if ($firstName === '' || $lastName === '') {
            throw new CommunicationException('validation_error', 'First name and last name are required.', 400);
        }
        $secondaryEmail = trim((string)($input['secondary_email'] ?? ''));
        if ($secondaryEmail !== '' && !filter_var($secondaryEmail, FILTER_VALIDATE_EMAIL)) {
            throw new CommunicationException('validation_error', 'Secondary email address is invalid.', 400);
        }
        $displayName = trim($firstName . ' ' . $lastName);
        $now = gmdate('Y-m-d H:i:s');

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE users SET first_name = ?, last_name = ?, name = ?, updated_at = ? WHERE id = ?'
            )->execute(array($firstName, $lastName, $displayName, $now, $userId));
            $this->upsertProfile($userId, $input, $secondaryEmail, $now);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        $this->recalculate($userId);
        return $this->payload($userId);
    }

    /**
     * @param array<string,mixed> $session
     * @param array<int,mixed> $contacts
     * @return array<string,mixed>
     */
    public function saveEmergency(array $session, array $contacts): array
    {
        $userId = (int)$session['user']['id'];
        $now = gmdate('Y-m-d H:i:s');
        $byOrder = array();
        foreach ($contacts as $row) {
            if (!is_array($row)) {
                continue;
            }
            $order = (int)($row['sort_order'] ?? 0);
            if ($order >= 1 && $order <= 2) {
                $byOrder[$order] = $row;
            }
        }
        if ($byOrder === array() && isset($contacts[0]) && is_array($contacts[0])) {
            $byOrder[1] = $contacts[0];
            $byOrder[2] = is_array($contacts[1] ?? null) ? $contacts[1] : array();
        }
        $this->pdo->beginTransaction();
        try {
            for ($i = 1; $i <= 2; $i++) {
                $this->upsertEmergency($userId, $i, $byOrder[$i] ?? array(), $now);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        $this->recalculate($userId);
        return $this->payload($userId);
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public function changePassword(array $session, string $current, string $new, string $confirm): array
    {
        $userId = (int)$session['user']['id'];
        if ($current === '' || $new === '' || $confirm === '') {
            throw new CommunicationException('validation_error', 'All password fields are required.', 400);
        }
        if (!ups_verify_current_password($this->pdo, $userId, $current)) {
            throw new CommunicationException('validation_error', 'Current password is incorrect.', 400);
        }
        if ($new !== $confirm) {
            throw new CommunicationException('validation_error', 'New password and confirmation do not match.', 400);
        }
        if (strlen($new) < 8) {
            throw new CommunicationException('validation_error', 'New password must be at least 8 characters.', 400);
        }
        if ($new === $current) {
            throw new CommunicationException('validation_error', 'New password must be different from your current password.', 400);
        }
        $this->updatePasswordHash($userId, $new);
        return array('ok' => true);
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public function putPhoto(array $session, string $bytes, string $mimeType): array
    {
        $userId = (int)$session['user']['id'];
        $mimeType = strtolower(trim(preg_replace('/;.*$/', '', $mimeType) ?? $mimeType));
        if (strlen($bytes) < 1 || strlen($bytes) > self::PHOTO_MAX_BYTES) {
            throw new CommunicationException('validation_error', 'Photo is too large. Maximum allowed size is 5 MB.', 400);
        }
        $info = @getimagesizefromstring($bytes);
        if (!is_array($info)) {
            throw new CommunicationException('validation_error', 'Uploaded file is not a valid image.', 400);
        }
        $detected = strtolower((string)($info['mime'] ?? $mimeType));
        $ext = match ($detected) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => '',
        };
        if ($ext === '') {
            throw new CommunicationException('validation_error', 'Only JPG, PNG, or WEBP photos are allowed.', 400);
        }
        $dir = dirname(__DIR__, 2) . '/public/uploads/user_photos';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new CommunicationException('server_error', 'Unable to store uploaded photo.', 500);
        }
        $fileName = 'user_' . $userId . '_' . time() . '.' . $ext;
        $path = $dir . '/' . $fileName;
        if (file_put_contents($path, $bytes) === false) {
            throw new CommunicationException('server_error', 'Unable to store uploaded photo.', 500);
        }
        $web = '/uploads/user_photos/' . $fileName;
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->prepare('UPDATE users SET photo_path = ?, updated_at = ? WHERE id = ?')
            ->execute(array($web, $now, $userId));
        $this->recalculate($userId);
        return $this->payload($userId);
    }

    /**
     * @return array<string,mixed>
     */
    public function requestPasswordReset(string $email): array
    {
        $email = trim($email);
        $message = 'If an account with that email exists, a password reset link has been sent.';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new CommunicationException('validation_error', 'Please enter a valid email address.', 400);
        }
        if (!$this->tableExists('password_reset_tokens')) {
            return array('ok' => true, 'message' => $message);
        }
        $stmt = $this->pdo->prepare('SELECT id, name, first_name, last_name, email, status FROM users WHERE email = ? LIMIT 1');
        $stmt->execute(array($email));
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($user) && strtolower(trim((string)($user['status'] ?? ''))) === 'active') {
            $rawToken = bin2hex(random_bytes(32));
            $expiresAt = gmdate('Y-m-d H:i:s', time() + 3600);
            $this->pdo->prepare(
                'INSERT INTO password_reset_tokens
                    (user_id, token_hash, expires_at, used_at, requested_ip, requested_user_agent, created_at)
                 VALUES (?, ?, ?, NULL, ?, ?, ?)'
            )->execute(array(
                (int)$user['id'],
                hash('sha256', $rawToken),
                $expiresAt,
                substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 255) ?: null,
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000) ?: null,
                gmdate('Y-m-d H:i:s'),
            ));
            $this->dispatchResetEmail($user, $rawToken);
        }
        return array('ok' => true, 'message' => $message);
    }

    /**
     * @return array<string,mixed>
     */
    public function validateResetToken(string $token): array
    {
        $row = $this->findValidResetToken($token);
        if ($row === null) {
            throw new CommunicationException('validation_error', 'This password reset link is invalid or has expired.', 400);
        }
        return array(
            'ok' => true,
            'valid' => true,
            'email' => (string)($row['email'] ?? ''),
            'name' => $this->displayName($row),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function completePasswordReset(string $token, string $password, string $confirm): array
    {
        $row = $this->findValidResetToken($token);
        if ($row === null) {
            throw new CommunicationException('validation_error', 'This password reset link is invalid or has expired.', 400);
        }
        if ($password === '') {
            throw new CommunicationException('validation_error', 'Please enter a new password.', 400);
        }
        if (strlen($password) < 8) {
            throw new CommunicationException('validation_error', 'Your new password must be at least 8 characters long.', 400);
        }
        if ($password !== $confirm) {
            throw new CommunicationException('validation_error', 'The password confirmation does not match.', 400);
        }
        $userId = (int)$row['user_id'];
        $this->pdo->beginTransaction();
        try {
            $this->updatePasswordHash($userId, $password);
            $this->pdo->prepare(
                'UPDATE password_reset_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL'
            )->execute(array(gmdate('Y-m-d H:i:s'), (int)$row['id']));
            $this->pdo->prepare(
                'UPDATE password_reset_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL AND id <> ?'
            )->execute(array(gmdate('Y-m-d H:i:s'), $userId, (int)$row['id']));
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return array(
            'ok' => true,
            'message' => 'Your password has been reset successfully. You can now sign in with your new password.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(int $userId): array
    {
        $userStmt = $this->pdo->prepare(
            'SELECT id, uuid, email, name, first_name, last_name, role, photo_path FROM users WHERE id = ? LIMIT 1'
        );
        $userStmt->execute(array($userId));
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($user)) {
            throw new CommunicationException('not_found', 'Profile not found.', 404);
        }
        $profile = array();
        if ($this->tableExists('user_profiles')) {
            $stmt = $this->pdo->prepare('SELECT * FROM user_profiles WHERE user_id = ? LIMIT 1');
            $stmt->execute(array($userId));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $profile = $row;
            }
        }
        $contacts = array();
        if ($this->tableExists('user_emergency_contacts')) {
            $stmt = $this->pdo->prepare(
                'SELECT sort_order, contact_name, relationship, phone
                 FROM user_emergency_contacts WHERE user_id = ? ORDER BY sort_order ASC, id ASC'
            );
            $stmt->execute(array($userId));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $contacts[] = array(
                    'sort_order' => (int)($row['sort_order'] ?? 0),
                    'contact_name' => (string)($row['contact_name'] ?? ''),
                    'relationship' => (string)($row['relationship'] ?? ''),
                    'phone' => (string)($row['phone'] ?? ''),
                );
            }
        }
        while (count($contacts) < 2) {
            $contacts[] = array(
                'sort_order' => count($contacts) + 1,
                'contact_name' => '',
                'relationship' => '',
                'phone' => '',
            );
        }
        $weight = (string)($profile['weight'] ?? $profile['weight_kg'] ?? '');
        return array(
            'ok' => true,
            'user' => CommunicationSupport::publicUser($user),
            'profile' => array(
                'first_name' => (string)($user['first_name'] ?? ''),
                'last_name' => (string)($user['last_name'] ?? ''),
                'email' => (string)($user['email'] ?? ''),
                'photo_path' => (string)($user['photo_path'] ?? ''),
                'street_address' => (string)($profile['street_address'] ?? ''),
                'street_number' => (string)($profile['street_number'] ?? ''),
                'zip_code' => (string)($profile['zip_code'] ?? ''),
                'city' => (string)($profile['city'] ?? ''),
                'state_region' => (string)($profile['state_region'] ?? ''),
                'country_code' => (string)($profile['country_code'] ?? ''),
                'cellphone' => (string)($profile['cellphone'] ?? ''),
                'secondary_email' => (string)($profile['secondary_email'] ?? ''),
                'date_of_birth' => (string)($profile['date_of_birth'] ?? ''),
                'place_of_birth' => (string)($profile['place_of_birth'] ?? ''),
                'nationality' => (string)($profile['nationality'] ?? ''),
                'id_passport_number' => (string)($profile['id_passport_number'] ?? ''),
                'gender' => (string)($profile['gender'] ?? ''),
                'marital_status' => (string)($profile['marital_status'] ?? ''),
                'hair_color' => (string)($profile['hair_color'] ?? ''),
                'eye_color' => (string)($profile['eye_color'] ?? ''),
                'weight_kg' => $weight,
                'height_cm' => (string)($profile['height_cm'] ?? ''),
            ),
            'emergency_contacts' => $contacts,
            'options' => self::options(),
        );
    }

    /**
     * @param array<string,mixed> $input
     */
    private function upsertProfile(int $userId, array $input, string $secondaryEmail, string $now): void
    {
        if (!$this->tableExists('user_profiles')) {
            return;
        }
        $fields = array(
            'street_address' => trim((string)($input['street_address'] ?? '')) ?: null,
            'street_number' => trim((string)($input['street_number'] ?? '')) ?: null,
            'zip_code' => trim((string)($input['zip_code'] ?? '')) ?: null,
            'city' => trim((string)($input['city'] ?? '')) ?: null,
            'state_region' => trim((string)($input['state_region'] ?? '')) ?: null,
            'country_code' => strtoupper(trim((string)($input['country_code'] ?? ''))) ?: null,
            'cellphone' => trim((string)($input['cellphone'] ?? '')) ?: null,
            'secondary_email' => $secondaryEmail !== '' ? $secondaryEmail : null,
            'date_of_birth' => ups_normalize_date((string)($input['date_of_birth'] ?? '')),
            'place_of_birth' => trim((string)($input['place_of_birth'] ?? '')) ?: null,
            'nationality' => trim((string)($input['nationality'] ?? '')) ?: null,
            'id_passport_number' => trim((string)($input['id_passport_number'] ?? '')) ?: null,
            'gender' => trim((string)($input['gender'] ?? '')) ?: null,
            'weight' => trim((string)($input['weight_kg'] ?? $input['weight'] ?? '')) ?: null,
            'height_cm' => ups_normalize_decimal((string)($input['height_cm'] ?? '')),
            'hair_color' => trim((string)($input['hair_color'] ?? '')) ?: null,
            'eye_color' => trim((string)($input['eye_color'] ?? '')) ?: null,
            'marital_status' => trim((string)($input['marital_status'] ?? '')) ?: null,
        );
        $existing = $this->pdo->prepare('SELECT id FROM user_profiles WHERE user_id = ? LIMIT 1');
        $existing->execute(array($userId));
        $id = (int)$existing->fetchColumn();
        if ($id > 0) {
            $this->pdo->prepare(
                'UPDATE user_profiles SET
                    street_address = ?, street_number = ?, zip_code = ?, city = ?, state_region = ?,
                    country_code = ?, cellphone = ?, secondary_email = ?, date_of_birth = ?, place_of_birth = ?,
                    nationality = ?, id_passport_number = ?, gender = ?, weight = ?, height_cm = ?,
                    hair_color = ?, eye_color = ?, marital_status = ?, updated_at = ?
                 WHERE id = ?'
            )->execute(array_merge(array_values($fields), array($now, $id)));
            return;
        }
        $this->pdo->prepare(
            'INSERT INTO user_profiles (
                user_id, street_address, street_number, zip_code, city, state_region, country_code,
                cellphone, secondary_email, date_of_birth, place_of_birth, nationality, id_passport_number,
                gender, weight, height_cm, hair_color, eye_color, marital_status, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(array_merge(array($userId), array_values($fields), array($now, $now)));
    }

    /**
     * @param array<string,mixed> $row
     */
    private function upsertEmergency(int $userId, int $sortOrder, array $row, string $now): void
    {
        if (!$this->tableExists('user_emergency_contacts')) {
            return;
        }
        $name = trim((string)($row['contact_name'] ?? ''));
        $relationship = trim((string)($row['relationship'] ?? ''));
        $phone = trim((string)($row['phone'] ?? ''));
        $existing = $this->pdo->prepare(
            'SELECT id FROM user_emergency_contacts WHERE user_id = ? AND sort_order = ? ORDER BY id ASC LIMIT 1'
        );
        $existing->execute(array($userId, $sortOrder));
        $id = (int)$existing->fetchColumn();
        if ($name === '' && $relationship === '' && $phone === '') {
            if ($id > 0) {
                $this->pdo->prepare('DELETE FROM user_emergency_contacts WHERE id = ?')->execute(array($id));
            }
            return;
        }
        if ($id > 0) {
            $this->pdo->prepare(
                'UPDATE user_emergency_contacts
                 SET contact_name = ?, relationship = ?, phone = ?, updated_at = ?
                 WHERE id = ?'
            )->execute(array($name !== '' ? $name : null, $relationship !== '' ? $relationship : null, $phone !== '' ? $phone : null, $now, $id));
            return;
        }
        $this->pdo->prepare(
            'INSERT INTO user_emergency_contacts
                (user_id, contact_name, relationship, phone, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute(array(
            $userId,
            $name !== '' ? $name : null,
            $relationship !== '' ? $relationship : null,
            $phone !== '' ? $phone : null,
            $sortOrder,
            $now,
            $now,
        ));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findValidResetToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || !$this->tableExists('password_reset_tokens')) {
            return null;
        }
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'SELECT prt.id, prt.user_id, prt.expires_at, u.email, u.name, u.first_name, u.last_name, u.status
             FROM password_reset_tokens prt
             INNER JOIN users u ON u.id = prt.user_id
             WHERE prt.token_hash = ? AND prt.used_at IS NULL AND prt.expires_at >= ?
             ORDER BY prt.id DESC LIMIT 1'
        );
        $stmt->execute(array(hash('sha256', $token), $now));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $user
     */
    private function dispatchResetEmail(array $user, string $rawToken): void
    {
        $runtimePath = dirname(__DIR__) . '/automation_runtime.php';
        if (!is_file($runtimePath)) {
            return;
        }
        require_once $runtimePath;
        $displayName = $this->displayName($user);
        $base = rtrim((string)(getenv('APP_URL') ?: 'https://ipca.training'), '/');
        $resetLink = $base . '/reset_password.php?token=' . rawurlencode($rawToken);
        try {
            $runtime = new AutomationRuntime();
            $runtime->dispatchEvent($this->pdo, 'password_reset_requested', array(
                'user_name' => $displayName,
                'reset_link' => $resetLink,
                'app_reset_link' => 'ipca://reset?token=' . rawurlencode($rawToken),
                'expiry_minutes' => '60',
                'expiry_datetime' => date('D, M j, Y g:i A', time() + 3600),
                'support_email' => 'support@ipca.aero',
                'to_email' => (string)$user['email'],
                'to_name' => $displayName,
            ));
        } catch (Throwable) {
        }
    }

    /**
     * @param array<string,mixed> $row
     */
    private function displayName(array $row): string
    {
        $name = trim((string)($row['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $name = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
        return $name !== '' ? $name : (string)($row['email'] ?? 'User');
    }

    private function updatePasswordHash(int $userId, string $password): void
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($hash) || $hash === '') {
            throw new CommunicationException('server_error', 'Unable to generate password hash.', 500);
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->prepare(
            'UPDATE users SET password_hash = ?, must_change_password = 0, password_changed_at = ?, updated_at = ? WHERE id = ?'
        )->execute(array($hash, $now, $now, $userId));
    }

    private function recalculate(int $userId): void
    {
        try {
            ups_recalculate_profile_requirements_status($this->pdo, $userId);
        } catch (Throwable) {
        }
    }

    private function tableExists(string $name): bool
    {
        try {
            $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $this->pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
                $stmt->execute(array($name));
                return $stmt->fetchColumn() !== false;
            }
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $stmt->execute(array($name));
            return $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string,list<array{value:string,label:string}>>
     */
    public static function options(): array
    {
        $pairs = static function (array $map): array {
            $out = array();
            foreach ($map as $value => $label) {
                $out[] = array('value' => (string)$value, 'label' => (string)$label);
            }
            return $out;
        };
        return array(
            'country' => $pairs(array(
                '' => 'Select country',
                'US' => 'United States', 'CA' => 'Canada', 'MX' => 'Mexico', 'BE' => 'Belgium',
                'NL' => 'Netherlands', 'DE' => 'Germany', 'FR' => 'France', 'ES' => 'Spain',
                'IT' => 'Italy', 'PT' => 'Portugal', 'GB' => 'United Kingdom', 'IE' => 'Ireland',
                'CH' => 'Switzerland', 'AT' => 'Austria', 'LU' => 'Luxembourg', 'SE' => 'Sweden',
                'NO' => 'Norway', 'DK' => 'Denmark', 'FI' => 'Finland', 'PL' => 'Poland',
                'CZ' => 'Czech Republic', 'HU' => 'Hungary', 'RO' => 'Romania', 'BG' => 'Bulgaria',
                'HR' => 'Croatia', 'GR' => 'Greece', 'TR' => 'Turkey', 'UA' => 'Ukraine',
                'IS' => 'Iceland', 'AU' => 'Australia', 'NZ' => 'New Zealand', 'ZA' => 'South Africa',
                'AE' => 'United Arab Emirates', 'SA' => 'Saudi Arabia', 'QA' => 'Qatar',
                'BR' => 'Brazil', 'IN' => 'India', 'JP' => 'Japan', 'CN' => 'China',
            )),
            'gender' => $pairs(array(
                '' => 'Select gender', 'Male' => 'Male', 'Female' => 'Female',
                'Non-Binary' => 'Non-Binary', 'Prefer Not to Say' => 'Prefer Not to Say', 'Other' => 'Other',
            )),
            'marital_status' => $pairs(array(
                '' => 'Select marital status', 'Single' => 'Single', 'Married' => 'Married',
                'Separated' => 'Separated', 'Divorced' => 'Divorced', 'Widowed' => 'Widowed',
                'Domestic Partnership' => 'Domestic Partnership', 'Civil Union' => 'Civil Union',
                'Prefer Not to Say' => 'Prefer Not to Say',
            )),
            'hair_color' => $pairs(array(
                '' => 'Select hair color', 'Black' => 'Black', 'Brown' => 'Brown', 'Blonde' => 'Blonde',
                'Red' => 'Red', 'Gray' => 'Gray', 'White' => 'White', 'Bald' => 'Bald / Shaved', 'Other' => 'Other',
            )),
            'eye_color' => $pairs(array(
                '' => 'Select eye color', 'Brown' => 'Brown', 'Blue' => 'Blue', 'Hazel' => 'Hazel',
                'Green' => 'Green', 'Gray' => 'Gray', 'Amber' => 'Amber', 'Other' => 'Other',
            )),
            'relationship' => $pairs(array(
                '' => 'Select relationship', 'Mother' => 'Mother', 'Father' => 'Father', 'Spouse' => 'Spouse',
                'Partner' => 'Partner', 'Wife' => 'Wife', 'Husband' => 'Husband', 'Son' => 'Son',
                'Daughter' => 'Daughter', 'Brother' => 'Brother', 'Sister' => 'Sister',
                'Grandmother' => 'Grandmother', 'Grandfather' => 'Grandfather', 'Aunt' => 'Aunt',
                'Uncle' => 'Uncle', 'Guardian' => 'Guardian', 'Friend' => 'Friend',
                'Relative' => 'Relative', 'Other' => 'Other',
            )),
        );
    }
}
