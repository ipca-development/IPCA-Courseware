<?php
declare(strict_types=1);

/**
 * Copy Courseware DB env from php-fpm, then optionally retarget a different database.
 *
 * @return array<string,string>
 */
function ipca_load_fpm_db_env(?string $databaseOverride = null): array
{
    $conf = '/etc/php/8.3/fpm/pool.d/www.conf';
    if (!is_readable($conf)) {
        throw new RuntimeException('Cannot read php-fpm pool config.');
    }
    $env = array();
    foreach (file($conf) ?: array() as $line) {
        $line = trim($line);
        if (preg_match('/^env\[(CW_DB_[A-Z]+)\]\s*=\s*(.+)$/', $line, $m)) {
            $env[$m[1]] = trim($m[2], " \t\"'");
        }
    }
    foreach (array('CW_DB_HOST', 'CW_DB_NAME', 'CW_DB_USER', 'CW_DB_PASS') as $required) {
        if (empty($env[$required])) {
            throw new RuntimeException('Missing ' . $required . ' in php-fpm pool.');
        }
    }
    if (empty($env['CW_DB_PORT'])) {
        $env['CW_DB_PORT'] = '25060';
    }
    if ($databaseOverride !== null && $databaseOverride !== '') {
        $env['CW_DB_NAME'] = $databaseOverride;
    }
    foreach ($env as $key => $value) {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }
    return $env;
}

function ipca_pdo_from_env(array $env, ?string $database = null): PDO
{
    $db = $database ?? (string)$env['CW_DB_NAME'];
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $env['CW_DB_HOST'],
        $env['CW_DB_PORT'],
        $db
    );
    return new PDO($dsn, $env['CW_DB_USER'], $env['CW_DB_PASS'], array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ));
}
