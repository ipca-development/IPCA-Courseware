<?php
declare(strict_types=1);

/**
 * Runtime secret loader (PHP). Mirrors analytics/lib/runtime_secrets.py semantics.
 *
 * Never decrypts EV[...]. Never logs secret values.
 * Inject plaintext via App Platform / FPM / /etc/ipca/ipca-courseware-cli.env.
 */
final class RuntimeSecrets
{
    /** @var array<string, list<string>> */
    private static array $aliases = [
        'OPENAI_API_KEY' => ['CW_OPENAI_API_KEY', 'OPENAI_API_KEY'],
        'CW_DB_PASS' => ['CW_DB_PASS'],
    ];

    /** @var list<string> */
    private static array $cliEnvCandidates = [
        '/etc/ipca/ipca-courseware-cli.env',
        '/etc/ipca/secrets.env',
    ];

    private static bool $cliLoaded = false;

    public static function ensureCliEnvLoaded(): void
    {
        if (self::$cliLoaded) {
            return;
        }
        self::$cliLoaded = true;
        foreach (self::$cliEnvCandidates as $path) {
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }
            $lines = file($path, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
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
                $key = trim($key);
                $val = trim($raw, " \t\"'");
                if ($key === '' || getenv($key) !== false) {
                    continue;
                }
                if (str_starts_with($val, 'EV[')) {
                    continue;
                }
                putenv($key . '=' . $val);
                $_ENV[$key] = $val;
            }
        }
    }

    public static function secretShape(?string $value): string
    {
        if ($value === null || $value === '') {
            return 'missing';
        }
        if (str_starts_with($value, 'EV[')) {
            return 'ev_ciphertext';
        }
        if (str_starts_with($value, 'sk-')) {
            return 'openai_sk';
        }
        return 'present_len_' . strlen($value);
    }

    /**
     * @return array{logical_name:string,usable:bool,aliases:list<array{alias:string,shape:string}>}
     */
    public static function peekStatus(string $logicalName): array
    {
        self::ensureCliEnvLoaded();
        $aliases = self::$aliases[$logicalName] ?? [$logicalName];
        $found = [];
        $usable = false;
        foreach ($aliases as $alias) {
            $raw = (string)(getenv($alias) ?: '');
            $found[] = ['alias' => $alias, 'shape' => self::secretShape($raw !== '' ? $raw : null)];
            if ($logicalName === 'OPENAI_API_KEY') {
                if (str_starts_with($raw, 'sk-') && !str_starts_with($raw, 'EV[')) {
                    $usable = true;
                }
            } elseif ($raw !== '' && !str_starts_with($raw, 'EV[')) {
                $usable = true;
            }
        }
        return ['logical_name' => $logicalName, 'usable' => $usable, 'aliases' => $found];
    }

    public static function get(string $logicalName, bool $required = true): ?string
    {
        self::ensureCliEnvLoaded();
        $aliases = self::$aliases[$logicalName] ?? [$logicalName];
        foreach ($aliases as $alias) {
            $value = (string)(getenv($alias) ?: '');
            if ($value === '') {
                continue;
            }
            if (str_starts_with($value, 'EV[')) {
                if ($required) {
                    throw new RuntimeException(
                        $logicalName . ': EV[...] ciphertext in ' . $alias .
                        '; inject plaintext via App Platform/FPM/cli env (repo cannot decrypt).'
                    );
                }
                return null;
            }
            if ($logicalName === 'OPENAI_API_KEY' && !str_starts_with($value, 'sk-')) {
                continue;
            }
            return $value;
        }
        if ($required) {
            throw new RuntimeException(
                $logicalName . ': runtime secret unavailable. Set ' .
                implode('|', $aliases) .
                ' via App Platform, PHP-FPM, or /etc/ipca/ipca-courseware-cli.env. Do not commit plaintext.'
            );
        }
        return null;
    }
}
