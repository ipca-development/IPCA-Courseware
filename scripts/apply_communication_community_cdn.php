<?php
declare(strict_types=1);

/**
 * Make existing Community objects public-read so the Spaces CDN can serve them.
 * New uploads set ACL at PUT time. Re-run safe.
 */

require_once __DIR__ . '/../src/RuntimeSecrets.php';
RuntimeSecrets::ensureCliEnvLoaded();

if (trim((string)getenv('CW_SPACES_KEY')) === '') {
    $pool = '/etc/php/8.3/fpm/pool.d/www.conf';
    if (is_readable($pool)) {
        $lines = file($pool, FILE_IGNORE_NEW_LINES);
        if (is_array($lines)) {
            foreach ($lines as $line) {
                if (!preg_match('/^env\[(CW_SPACES_[A-Z0-9_]+)\]\s*=\s*(.*)$/', trim($line), $m)) {
                    continue;
                }
                $key = $m[1];
                $val = trim($m[2], " \t\"'");
                if ($val === '' || getenv($key)) {
                    continue;
                }
                putenv($key . '=' . $val);
                $_ENV[$key] = $val;
            }
        }
    }
}

require_once __DIR__ . '/../src/spaces.php';

$prefix = 'community/';
$ok = 0;
$fail = 0;

foreach (cw_spaces_list_all_keys_under_prefix($prefix) as $key) {
    if (cw_spaces_put_acl((string)$key, 'public-read')) {
        $ok++;
        echo "public-read {$key}\n";
    } else {
        $fail++;
        echo "FAILED {$key}\n";
    }
}

echo "community_cdn_acl_ok={$ok}\n";
echo "community_cdn_acl_failed={$fail}\n";
echo "ok\n";
exit($fail > 0 ? 1 : 0);
