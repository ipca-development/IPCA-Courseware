<?php
declare(strict_types=1);

if (!function_exists('ot_client_ip')) {
    function ot_client_ip(): string
    {
        $keys = array(
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR',
        );

        foreach ($keys as $key) {
            $value = trim((string)($_SERVER[$key] ?? ''));
            if ($value === '') {
                continue;
            }

            if ($key === 'HTTP_X_FORWARDED_FOR') {
                $parts = explode(',', $value);
                $value = trim((string)($parts[0] ?? ''));
            }

            if ($value !== '') {
                return substr($value, 0, 64);
            }
        }

        return '';
    }
}

if (!function_exists('ot_user_agent')) {
    function ot_user_agent(): string
    {
        return substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);
    }
}

if (!function_exists('ot_base_url')) {
    function ot_base_url(): string
    {
        $scheme = 'https';
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));

        if ($host === '') {
            $appUrl = trim((string)($_ENV['APP_URL'] ?? ''));
            if ($appUrl !== '') {
                return rtrim($appUrl, '/');
            }
            return '';
        }

        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            $scheme = 'https';
        } elseif ((string)($_SERVER['SERVER_PORT'] ?? '') === '80') {
            $scheme = 'http';
        }

        return $scheme . '://' . $host;
    }
}

if (!function_exists('ot_generate_raw_token')) {
    function ot_generate_raw_token(): string
    {
        return bin2hex(random_bytes(32));
    }
}

if (!function_exists('ot_hash_token')) {
    function ot_hash_token(string $rawToken): string
    {
        return hash('sha256', trim($rawToken));
    }
}

if (!function_exists('ot_build_set_password_link')) {
    function ot_build_set_password_link(string $rawToken): string
    {
        return rtrim(ot_base_url(), '/') . '/set_password.php?token=' . urlencode($rawToken);
    }
}

if (!function_exists('ot_create_token')) {
    function ot_create_token(PDO $pdo, int $userId, string $tokenType, ?int $createdByUserId = null, int $ttlMinutes = 60): array
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Invalid user id for onboarding token.');
        }

        $tokenType = trim($tokenType);
        if ($tokenType === '') {
            throw new InvalidArgumentException('Invalid onboarding token type.');
        }

        if ($ttlMinutes < 1) {
            $ttlMinutes = 60;
        }

        $rawToken = ot_generate_raw_token();
        $tokenHash = ot_hash_token($rawToken);
        $expiresAtTs = time() + ($ttlMinutes * 60);
        $expiresAtDb = gmdate('Y-m-d H:i:s', $expiresAtTs);

        $stmt = $pdo->prepare("
            INSERT INTO user_onboarding_tokens (
                user_id,
                token_hash,
                token_type,
                expires_at,
                used_at,
                created_by_user_id,
                requested_ip,
                requested_user_agent,
                created_at
            ) VALUES (
                :user_id,
                :token_hash,
                :token_type,
                :expires_at,
                NULL,
                :created_by_user_id,
                :requested_ip,
                :requested_user_agent,
                NOW()
            )
        ");

        $ip = ot_client_ip();
        $userAgent = ot_user_agent();

        $stmt->execute(array(
            ':user_id' => $userId,
            ':token_hash' => $tokenHash,
            ':token_type' => $tokenType,
            ':expires_at' => $expiresAtDb,
            ':created_by_user_id' => $createdByUserId,
            ':requested_ip' => $ip !== '' ? $ip : null,
            ':requested_user_agent' => $userAgent !== '' ? $userAgent : null,
        ));

        return array(
            'id' => (int)$pdo->lastInsertId(),
            'raw_token' => $rawToken,
            'token_hash' => $tokenHash,
            'token_type' => $tokenType,
            'expires_at' => $expiresAtDb,
            'expires_ts' => $expiresAtTs,
            'ttl_minutes' => $ttlMinutes,
            'set_password_link' => $tokenType === 'set_password' ? ot_build_set_password_link($rawToken) : '',
        );
    }
}

if (!function_exists('ot_find_valid_token')) {
    function ot_find_valid_token(PDO $pdo, string $rawToken, string $tokenType = 'set_password'): ?array
    {
        $rawToken = trim($rawToken);
        $tokenType = trim($tokenType);

        if ($rawToken === '' || $tokenType === '') {
            return null;
        }

        $tokenHash = ot_hash_token($rawToken);

        $stmt = $pdo->prepare("
            SELECT
                uot.*,
                u.email,
                u.name,
                u.first_name,
                u.last_name,
                u.role,
                u.status,
                u.must_change_password
            FROM user_onboarding_tokens uot
            INNER JOIN users u
                ON u.id = uot.user_id
            WHERE uot.token_hash = :token_hash
              AND uot.token_type = :token_type
              AND uot.used_at IS NULL
              AND uot.expires_at >= UTC_TIMESTAMP()
            ORDER BY uot.id DESC
            LIMIT 1
        ");
        $stmt->execute(array(
            ':token_hash' => $tokenHash,
            ':token_type' => $tokenType,
        ));

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('ot_mark_token_used')) {
    function ot_mark_token_used(PDO $pdo, int $tokenId): void
    {
        if ($tokenId <= 0) {
            throw new InvalidArgumentException('Invalid onboarding token id.');
        }

        $stmt = $pdo->prepare("
            UPDATE user_onboarding_tokens
            SET used_at = UTC_TIMESTAMP()
            WHERE id = :id
              AND used_at IS NULL
            LIMIT 1
        ");
        $stmt->execute(array(
            ':id' => $tokenId,
        ));
    }
}

if (!function_exists('ot_invalidate_other_tokens')) {
    function ot_invalidate_other_tokens(PDO $pdo, int $userId, string $tokenType, ?int $excludeTokenId = null): void
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Invalid user id.');
        }

        $tokenType = trim($tokenType);
        if ($tokenType === '') {
            throw new InvalidArgumentException('Invalid token type.');
        }

        $sql = "
            UPDATE user_onboarding_tokens
            SET used_at = UTC_TIMESTAMP()
            WHERE user_id = :user_id
              AND token_type = :token_type
              AND used_at IS NULL
        ";

        $params = array(
            ':user_id' => $userId,
            ':token_type' => $tokenType,
        );

        if ($excludeTokenId !== null && $excludeTokenId > 0) {
            $sql .= " AND id <> :exclude_id";
            $params[':exclude_id'] = $excludeTokenId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }
}

if (!function_exists('ot_user_display_name')) {
    function ot_user_display_name(array $row): string
    {
        $displayName = trim((string)($row['name'] ?? ''));
        if ($displayName === '') {
            $displayName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
        }
        if ($displayName === '') {
            $displayName = trim((string)($row['email'] ?? ''));
        }
        return $displayName !== '' ? $displayName : 'User';
    }
}


if (!function_exists('ot_support_email')) {
    function ot_support_email(): string
    {
        $candidates = array(
            trim((string)($_ENV['SUPPORT_EMAIL'] ?? '')),
            trim((string)($_ENV['MAIL_FROM_ADDRESS'] ?? '')),
        );

        foreach ($candidates as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return 'support@ipca.aero';
    }
}

if (!function_exists('ot_send_set_password_notification')) {
    function ot_send_set_password_notification(PDO $pdo, array $userRow, array $tokenRow): void
    {
        if (!class_exists('NotificationService')) {
            require_once __DIR__ . '/notification_service.php';
        }

        $email = trim((string)($userRow['email'] ?? ''));
        if ($email === '') {
            throw new RuntimeException('Cannot send onboarding email without user email.');
        }

        $displayName = ot_user_display_name($userRow);
        $ttlMinutes = (int)($tokenRow['ttl_minutes'] ?? 60);
        $expiresTs = (int)($tokenRow['expires_ts'] ?? 0);
        $expiryDisplay = $expiresTs > 0 ? date('D, M j, Y g:i A', $expiresTs) : '';

        $service = new NotificationService($pdo);
        $service->sendSystemNotification(
    		'set_password_onboarding',
            $email,
            $displayName,
            array(
                'user_name' => $displayName,
                'login_email' => $email,
                'set_password_link' => (string)($tokenRow['set_password_link'] ?? ''),
                'expiry_minutes' => (string)$ttlMinutes,
                'expiry_datetime' => $expiryDisplay,
                'support_email' => ot_support_email(),
            ),
            null
        );
    }
}