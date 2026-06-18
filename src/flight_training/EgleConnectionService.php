<?php
declare(strict_types=1);

final class EgleConnectionService
{
    private const SESSION_KEY = 'ipca_egle_connection';

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function credentialsFromInput(array $input): array
    {
        return array(
            'host' => trim((string)($input['host'] ?? '')),
            'port' => (int)($input['port'] ?? 3306),
            'database' => trim((string)($input['database'] ?? '')),
            'username' => trim((string)($input['username'] ?? '')),
            'password' => (string)($input['password'] ?? ''),
            'ssl' => !empty($input['ssl']),
            'notes' => trim((string)($input['notes'] ?? '')),
        );
    }

    /**
     * @param array<string,mixed> $credentials
     */
    public function storeTemporaryCredentials(array $credentials): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('Session is required for temporary E-GLE credentials.');
        }
        $this->validateCredentials($credentials);
        $_SESSION[self::SESSION_KEY] = $credentials;
    }

    public function clearTemporaryCredentials(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION[self::SESSION_KEY]);
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    public function temporaryCredentials(): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        $credentials = $_SESSION[self::SESSION_KEY] ?? null;
        return is_array($credentials) ? $credentials : null;
    }

    /**
     * @param array<string,mixed>|null $credentials
     * @return array<string,mixed>
     */
    public function status(?array $credentials = null): array
    {
        $credentials = $credentials ?? $this->temporaryCredentials();
        if (!is_array($credentials)) {
            return array('connected' => false, 'message' => 'Disconnected');
        }
        return array(
            'connected' => true,
            'message' => 'Temporary E-GLE credentials are set.',
            'host' => (string)($credentials['host'] ?? ''),
            'database' => (string)($credentials['database'] ?? ''),
            'username' => (string)($credentials['username'] ?? ''),
            'ssl' => !empty($credentials['ssl']),
            'notes' => (string)($credentials['notes'] ?? ''),
        );
    }

    /**
     * @param array<string,mixed>|null $credentials
     * @return array<string,mixed>
     */
    public function testConnection(?array $credentials = null): array
    {
        $pdo = $this->connect($credentials);
        $tables = $this->selectRows($pdo, "
            SELECT TABLE_NAME
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME IN ('logbook', 'users', 'devices')
            ORDER BY TABLE_NAME
        ");
        return array(
            'ok' => true,
            'tables' => array_map(static fn (array $row): string => (string)$row['TABLE_NAME'], $tables),
        );
    }

    /**
     * @param array<string,mixed>|null $credentials
     */
    public function connect(?array $credentials = null): PDO
    {
        $credentials = $credentials ?? $this->temporaryCredentials();
        if (!is_array($credentials)) {
            throw new RuntimeException('No temporary E-GLE connection is configured.');
        }
        $this->validateCredentials($credentials);
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            (string)$credentials['host'],
            (int)$credentials['port'],
            (string)$credentials['database']
        );
        $options = array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        );
        if (!empty($credentials['ssl'])) {
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }
        return new PDO($dsn, (string)$credentials['username'], (string)$credentials['password'], $options);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function selectRows(PDO $pdo, string $sql, array $params = array()): array
    {
        if (!preg_match('/^\s*SELECT\b/i', $sql)) {
            throw new RuntimeException('E-GLE connector is read-only. Only SELECT statements are allowed.');
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @return list<string>
     */
    public function tableColumns(PDO $pdo, string $table): array
    {
        $rows = $this->selectRows($pdo, "
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table
            ORDER BY ORDINAL_POSITION
        ", array(':table' => $table));
        return array_map(static fn (array $row): string => (string)$row['COLUMN_NAME'], $rows);
    }

    /**
     * @param array<string,mixed> $credentials
     */
    private function validateCredentials(array $credentials): void
    {
        foreach (array('host', 'database', 'username') as $key) {
            if (trim((string)($credentials[$key] ?? '')) === '') {
                throw new RuntimeException('E-GLE ' . $key . ' is required.');
            }
        }
        if ((int)($credentials['port'] ?? 0) <= 0) {
            throw new RuntimeException('E-GLE port is required.');
        }
    }
}
