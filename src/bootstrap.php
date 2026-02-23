<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/render.php';

// --- cookie settings (important behind App Platform proxy HTTPS)
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);

// --- store sessions in MySQL so multiple instances don't log you out
ini_set('session.use_strict_mode', '1');
ini_set('session.gc_maxlifetime', '28800'); // 8 hours
ini_set('session.cookie_lifetime', '0');

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
        $stmt = $this->pdo->prepare("INSERT INTO cw_sessions (id, data) VALUES (?, ?)
          ON DUPLICATE KEY UPDATE data=VALUES(data), updated_at=CURRENT_TIMESTAMP");
        return $stmt->execute([$id, $data]);
    }

    public function destroy($id): bool {
        $stmt = $this->pdo->prepare("DELETE FROM cw_sessions WHERE id=?");
        $stmt->execute([$id]);
        return true;
    }

    public function gc($maxlifetime): int|false {
        // delete sessions older than maxlifetime seconds
        $stmt = $this->pdo->prepare("DELETE FROM cw_sessions WHERE updated_at < (NOW() - INTERVAL ? SECOND)");
        $stmt->execute([(int)$maxlifetime]);
        return $stmt->rowCount();
    }
}

// Important: create DB connection first, then set handler, then session_start
$pdo = cw_db();
session_set_save_handler(new DbSessionHandler($pdo), true);
session_start();

session_start();

$CDN_BASE = rtrim(getenv('CW_CDN_BASE') ?: '', '/');
if ($CDN_BASE === '') {
    $CDN_BASE = 'https://ipca-media.nyc3.cdn.digitaloceanspaces.com';
}

$pdo = cw_db();