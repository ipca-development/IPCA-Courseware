<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/render.php';

$CDN_BASE = rtrim(getenv('CW_CDN_BASE') ?: '', '/');
if ($CDN_BASE === '') {
    $CDN_BASE = 'https://ipca-media.nyc3.cdn.digitaloceanspaces.com';
}

$pdo = cw_db();

/**
 * Detect HTTPS behind App Platform / reverse proxy.
 */
function cw_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') return true;
    return false;
}

/**
 * DB-backed session handler (prevents logout when App Platform routes requests across instances).
 */
class DbSessionHandler implements SessionHandlerInterface {
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }
    public function open($savePath, $sessionName): bool { return true; }
    public function close(): bool { return true; }

    public function read($id): string {
        $stmt = $this->pdo->prepare("SELECT data FROM cw_sessions WHERE id=?");
        $stmt->execute([$id]);
        $row = $stmt->fetchColumn();
        return $row ? (string)$row : '';
    }

    public function write($id, $data): bool {
        $stmt = $this->pdo->prepare("
          INSERT INTO cw_sessions (id, data) VALUES (?, ?)
          ON DUPLICATE KEY UPDATE data=VALUES(data), updated_at=CURRENT_TIMESTAMP
        ");
        return $stmt->execute([$id, $data]);
    }

    public function destroy($id): bool {
        $stmt = $this->pdo->prepare("DELETE FROM cw_sessions WHERE id=?");
        $stmt->execute([$id]);
        return true;
    }

    public function gc($maxlifetime): int|false {
        $stmt = $this->pdo->prepare("
          DELETE FROM cw_sessions
          WHERE updated_at < (NOW() - INTERVAL ? SECOND)
        ");
        $stmt->execute([(int)$maxlifetime]);
        return $stmt->rowCount();
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_name('CWSESSID');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => cw_is_https(),     // important behind proxy
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', '28800'); // 8 hours

    session_set_save_handler(new DbSessionHandler($pdo), true);
    session_start();
}