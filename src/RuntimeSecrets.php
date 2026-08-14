<?php
declare(strict_types=1);

/**
 * Runtime secret loader (PHP). Mirrors analytics/lib/runtime_secrets.py semantics.
 *
 * Resolution order:
 *  1. Process / FPM environment (getenv)
 *  2. Approved server-side EnvironmentFile(s)
 *  3. Repository .env for non-secret defaults only
 *
 * Never decrypts EV[...]. Never logs secret values.
 */
final class RuntimeSecrets
{
    /** @var array<string, list<string>> */
    private static array $aliases = [
        'OPENAI_API_KEY' => ['CW_OPENAI_API_KEY', 'OPENAI_API_KEY'],
        'CW_DB_PASS' => ['CW_DB_PASS'],
        'ASR_CREDENTIALS' => ['CW_OPENAI_API_KEY', 'OPENAI_API_KEY'],
    ];

    /** @var list<string> */
    private static array $approvedEnvFiles = [
        '/etc/ipca/analytics.env',
        '/etc/ipca/ipca-courseware-cli.env',
        '/etc/ipca/secrets.env',
    ];

    /** @var list<string> */
    private static array $repoForbidden = [
        'CW_OPENAI_API_KEY',
        'OPENAI_API_KEY',
        'CW_DB_PASS',
        'CW_SPACES_SECRET',
        'CW_SPACES_KEY',
        'CW_HEYGEN_API_KEY',
        'MAIL_SMTP_PASSWORD',
        'POSTMARK_SERVER_TOKEN',
        'POSTMARK_INBOUND_WEBHOOK_SECRET',
        'POSTMARK_TRACKING_WEBHOOK_SECRET',
        'GARMIN_WORKER_TOKEN',
        'CW_ADSBEXCHANGE_API_KEY',
        'CW_CESIUM_ION_TOKEN',
        'CW_OPENSKY_TRINO_PASSWORD',
        'IPCA_APNS_KEY_P8',
        'CW_APNS_KEY_P8',
    ];

    /** @var list<string> */
    private static array $repoAllowedDefaults = [
        'CW_DB_HOST',
        'CW_DB_PORT',
        'CW_DB_NAME',
        'CW_DB_USER',
        'CW_OPENAI_MODEL',
        'CW_OPENAI_ASR_MODEL',
        'CW_PUBLIC_BASE_URL',
        'CW_CDN_BASE',
    ];

    private static bool $bootstrapped = false;

    /** @var list<string> */
    private static array $loadedFiles = [];

    private static function stripValue(string $raw): string
    {
        return trim($raw, " \t\"'");
    }

    private static function applyLine(string $key, string $raw, bool $allowSecrets): void
    {
        if ($key === '') {
            return;
        }
        $existing = getenv($key);
        if ($existing !== false && $existing !== '') {
            return;
        }
        $val = self::stripValue($raw);
        if ($val === '' || str_starts_with($val, 'EV[')) {
            return;
        }
        if (!$allowSecrets && in_array($key, self::$repoForbidden, true)) {
            return;
        }
        if (!$allowSecrets && !in_array($key, self::$repoAllowedDefaults, true)) {
            return;
        }
        putenv($key . '=' . $val);
        $_ENV[$key] = $val;
    }

    private static function loadFile(string $path, bool $allowSecrets): bool
    {
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return false;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $raw] = explode('=', $line, 2);
            self::applyLine(trim($key), $raw, $allowSecrets);
        }
        return true;
    }

    public static function ensureCliEnvLoaded(): void
    {
        if (self::$bootstrapped) {
            return;
        }
        self::$bootstrapped = true;
        self::$loadedFiles = [];

        $explicit = trim((string)(getenv('IPCA_ANALYTICS_ENV_FILE') ?: ''));
        $candidates = [];
        if ($explicit !== '') {
            $candidates[] = $explicit;
        }
        foreach (self::$approvedEnvFiles as $path) {
            $candidates[] = $path;
        }
        $seen = [];
        foreach ($candidates as $path) {
            if (isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;
            if (self::loadFile($path, true)) {
                self::$loadedFiles[] = $path;
            }
        }

        $root = dirname(__DIR__);
        $repoEnv = $root . '/.env';
        if (self::loadFile($repoEnv, false)) {
            self::$loadedFiles[] = $repoEnv . ' (non-secret defaults only)';
        }
    }

    public static function availabilityLabel(string $logicalName): string
    {
        self::ensureCliEnvLoaded();
        $aliases = self::$aliases[$logicalName] ?? [$logicalName];
        foreach ($aliases as $alias) {
            $raw = self::stripValue((string)(getenv($alias) ?: ''));
            if ($raw === '' || str_starts_with($raw, 'EV[')) {
                continue;
            }
            if ($logicalName === 'OPENAI_API_KEY' || $logicalName === 'ASR_CREDENTIALS') {
                if (str_starts_with($raw, 'sk-')) {
                    return 'AVAILABLE';
                }
                continue;
            }
            return 'AVAILABLE';
        }
        return 'MISSING';
    }

    public static function secretShape(?string $value): string
    {
        if ($value === null || $value === '') {
            return 'missing';
        }
        $v = self::stripValue($value);
        if (str_starts_with($v, 'EV[')) {
            return 'ev_ciphertext';
        }
        if (str_starts_with($v, 'sk-')) {
            return 'openai_sk';
        }
        return 'present';
    }

    /**
     * @return array{logical_name:string,usable:bool,availability:string,aliases:list<array{alias:string,shape:string}>}
     */
    public static function peekStatus(string $logicalName): array
    {
        self::ensureCliEnvLoaded();
        $aliases = self::$aliases[$logicalName] ?? [$logicalName];
        $label = self::availabilityLabel($logicalName);
        $found = [];
        foreach ($aliases as $alias) {
            $raw = (string)(getenv($alias) ?: '');
            $found[] = ['alias' => $alias, 'shape' => self::secretShape($raw !== '' ? $raw : null)];
        }
        return [
            'logical_name' => $logicalName,
            'usable' => $label === 'AVAILABLE',
            'availability' => $label,
            'aliases' => $found,
            'loaded_env_files' => self::$loadedFiles,
        ];
    }

    public static function get(string $logicalName, bool $required = true): ?string
    {
        self::ensureCliEnvLoaded();
        $aliases = self::$aliases[$logicalName] ?? [$logicalName];
        foreach ($aliases as $alias) {
            $value = self::stripValue((string)(getenv($alias) ?: ''));
            if ($value === '') {
                continue;
            }
            if (str_starts_with($value, 'EV[')) {
                if ($required) {
                    throw new RuntimeException(
                        $logicalName . ': EV[...] ciphertext in ' . $alias .
                        '; inject plaintext via FPM → /etc/ipca/analytics.env (repo cannot decrypt).'
                    );
                }
                return null;
            }
            if (($logicalName === 'OPENAI_API_KEY' || $logicalName === 'ASR_CREDENTIALS') && !str_starts_with($value, 'sk-')) {
                continue;
            }
            return $value;
        }
        if ($required) {
            throw new RuntimeException(
                $logicalName . ': runtime secret unavailable. PHP-FPM env is not inherited by CLI. ' .
                'Configure /etc/ipca/analytics.env (or systemd EnvironmentFile) with ' .
                implode('|', $aliases) . '. Do not commit plaintext; do not use repo .env for secrets.'
            );
        }
        return null;
    }
}
