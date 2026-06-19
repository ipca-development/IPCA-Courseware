<?php
declare(strict_types=1);

final class UserFlightCredentialService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function schemaReady(): bool
    {
        return $this->tableExists('ipca_user_flight_credentials');
    }

    /**
     * @return array<string,mixed>
     */
    public function loadForUser(int $userId): array
    {
        if ($userId <= 0 || !$this->schemaReady()) {
            return array();
        }
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_user_flight_credentials WHERE user_id = :user_id LIMIT 1');
        $stmt->execute(array(':user_id' => $userId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : array();
    }

    /**
     * @param array<string,mixed> $data
     */
    public function saveForUser(int $userId, array $data, int $actorUserId): void
    {
        if (!$this->schemaReady()) {
            throw new RuntimeException('Flight credential table is not installed. Apply scripts/sql/2026_06_19_user_flight_credentials_signature.sql.');
        }
        if ($userId <= 0) {
            throw new RuntimeException('Invalid user.');
        }

        $existing = $this->loadForUser($userId);
        $signaturePath = (string)($existing['signature_image_path'] ?? '');
        $signatureHash = (string)($existing['signature_image_hash'] ?? '');
        $signatureCapturedAt = (string)($existing['signature_captured_at'] ?? '');

        $signatureData = trim((string)($data['signature_data_url'] ?? ''));
        if ($signatureData !== '') {
            $stored = $this->storeSignatureDataUrl($userId, $signatureData);
            $signaturePath = $stored['path'];
            $signatureHash = $stored['hash'];
            $signatureCapturedAt = gmdate('Y-m-d H:i:s');
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_user_flight_credentials
                (user_id, pilot_certificate_number, pilot_certificate_level, pilot_certificate_issuer,
                 pilot_certificate_expiration_date, medical_certificate_class, medical_certificate_issuer,
                 medical_certificate_expiration_date, medical_restrictions, instructor_certificate_number,
                 instructor_certificate_expiration_date, ground_instructor_certificate_number, license_country,
                 signature_image_path, signature_image_hash, signature_captured_at, metadata_json)
            VALUES
                (:user_id, :pilot_certificate_number, :pilot_certificate_level, :pilot_certificate_issuer,
                 :pilot_certificate_expiration_date, :medical_certificate_class, :medical_certificate_issuer,
                 :medical_certificate_expiration_date, :medical_restrictions, :instructor_certificate_number,
                 :instructor_certificate_expiration_date, :ground_instructor_certificate_number, :license_country,
                 :signature_image_path, :signature_image_hash, :signature_captured_at, :metadata_json)
            ON DUPLICATE KEY UPDATE
                pilot_certificate_number = VALUES(pilot_certificate_number),
                pilot_certificate_level = VALUES(pilot_certificate_level),
                pilot_certificate_issuer = VALUES(pilot_certificate_issuer),
                pilot_certificate_expiration_date = VALUES(pilot_certificate_expiration_date),
                medical_certificate_class = VALUES(medical_certificate_class),
                medical_certificate_issuer = VALUES(medical_certificate_issuer),
                medical_certificate_expiration_date = VALUES(medical_certificate_expiration_date),
                medical_restrictions = VALUES(medical_restrictions),
                instructor_certificate_number = VALUES(instructor_certificate_number),
                instructor_certificate_expiration_date = VALUES(instructor_certificate_expiration_date),
                ground_instructor_certificate_number = VALUES(ground_instructor_certificate_number),
                license_country = VALUES(license_country),
                signature_image_path = VALUES(signature_image_path),
                signature_image_hash = VALUES(signature_image_hash),
                signature_captured_at = VALUES(signature_captured_at),
                metadata_json = VALUES(metadata_json),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(array(
            ':user_id' => $userId,
            ':pilot_certificate_number' => $this->blankToNull($data['pilot_certificate_number'] ?? ''),
            ':pilot_certificate_level' => $this->blankToNull($data['pilot_certificate_level'] ?? ''),
            ':pilot_certificate_issuer' => $this->blankToNull($data['pilot_certificate_issuer'] ?? ''),
            ':pilot_certificate_expiration_date' => $this->dateOrNull($data['pilot_certificate_expiration_date'] ?? ''),
            ':medical_certificate_class' => $this->blankToNull($data['medical_certificate_class'] ?? ''),
            ':medical_certificate_issuer' => $this->blankToNull($data['medical_certificate_issuer'] ?? ''),
            ':medical_certificate_expiration_date' => $this->dateOrNull($data['medical_certificate_expiration_date'] ?? ''),
            ':medical_restrictions' => $this->blankToNull($data['medical_restrictions'] ?? ''),
            ':instructor_certificate_number' => $this->blankToNull($data['instructor_certificate_number'] ?? ''),
            ':instructor_certificate_expiration_date' => $this->dateOrNull($data['instructor_certificate_expiration_date'] ?? ''),
            ':ground_instructor_certificate_number' => $this->blankToNull($data['ground_instructor_certificate_number'] ?? ''),
            ':license_country' => $this->blankToNull($data['license_country'] ?? ''),
            ':signature_image_path' => $signaturePath !== '' ? $signaturePath : null,
            ':signature_image_hash' => $signatureHash !== '' ? $signatureHash : null,
            ':signature_captured_at' => $signatureCapturedAt !== '' ? $signatureCapturedAt : null,
            ':metadata_json' => json_encode(array('updated_by' => $actorUserId > 0 ? $actorUserId : null), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ));
    }

    /**
     * @return array<string,string>
     */
    public function variablesForUser(int $userId, string $prefix): array
    {
        $row = $this->loadForUser($userId);
        if ($row === array()) {
            return array();
        }
        $prefix = trim($prefix, '.');
        $vars = array();
        foreach (array(
            'pilot_certificate_number',
            'pilot_certificate_level',
            'pilot_certificate_issuer',
            'pilot_certificate_expiration_date',
            'medical_certificate_class',
            'medical_certificate_issuer',
            'medical_certificate_expiration_date',
            'medical_restrictions',
            'instructor_certificate_number',
            'instructor_certificate_expiration_date',
            'ground_instructor_certificate_number',
            'license_country',
            'signature_image_path',
            'signature_captured_at',
        ) as $key) {
            $vars[$prefix . '.' . $key] = (string)($row[$key] ?? '');
        }
        $signaturePath = trim((string)($row['signature_image_path'] ?? ''));
        $vars[$prefix . '.signature_url'] = $signaturePath !== '' ? '/' . ltrim($signaturePath, '/') : '';
        return $vars;
    }

    /**
     * @return array{path:string,hash:string}
     */
    private function storeSignatureDataUrl(int $userId, string $dataUrl): array
    {
        if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=\r\n]+)$#', $dataUrl, $matches)) {
            throw new RuntimeException('Signature must be submitted as a PNG image.');
        }
        $binary = base64_decode(preg_replace('/\s+/', '', $matches[1]) ?? '', true);
        if (!is_string($binary) || $binary === '') {
            throw new RuntimeException('Signature image is empty.');
        }
        if (strlen($binary) > 2 * 1024 * 1024) {
            throw new RuntimeException('Signature image is too large.');
        }
        $hash = hash('sha256', $binary);
        $root = dirname(__DIR__, 2);
        $relativeDir = 'uploads/user_signatures/' . $userId;
        $targetDir = $root . '/public/' . $relativeDir;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Could not create signature storage directory.');
        }
        $relativePath = $relativeDir . '/signature_' . substr($hash, 0, 16) . '.png';
        $targetPath = $root . '/public/' . $relativePath;
        if (file_put_contents($targetPath, $binary) === false) {
            throw new RuntimeException('Could not store signature image.');
        }
        @chmod($targetPath, 0664);
        return array('path' => $relativePath, 'hash' => $hash);
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = trim((string)$value);
        return $value !== '' ? $value : null;
    }

    private function dateOrNull(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    private function tableExists(string $table): bool
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :table
            ");
            $stmt->execute(array(':table' => $table));
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
