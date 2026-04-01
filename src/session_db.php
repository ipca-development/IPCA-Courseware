<?php
declare(strict_types=1);

class CwDbSessionHandler implements SessionHandlerInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function open($savePath, $sessionName): bool { return true; }
    public function close(): bool { return true; }

    public function read($id): string {
        $stmt = $this->pdo->prepare("SELECT data FROM php_sessions WHERE id=? LIMIT 1");
        $stmt->execute([(string)$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (string)$row['data'] : '';
    }

    public function write($id, $data): bool {
        $stmt = $this->pdo->prepare("
            INSERT INTO php_sessions (id, data)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE data=VALUES(data), updated_at=CURRENT_TIMESTAMP
        ");
        return (bool)$stmt->execute([(string)$id, $data]);
    }

    public function destroy($id): bool {
        $stmt = $this->pdo->prepare("DELETE FROM php_sessions WHERE id=?");
        $stmt->execute([(string)$id]);
        return true;
    }

    public function gc($maxlifetime): int|false {
        $stmt = $this->pdo->prepare("
            DELETE FROM php_sessions
            WHERE updated_at < (NOW() - INTERVAL ? SECOND)
        ");
        $stmt->execute([(int)$maxlifetime]);
        return $stmt->rowCount();
    }
}