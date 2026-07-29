<?php
declare(strict_types=1);

require_once __DIR__ . '/EvidenceSchema.php';

final class ProviderModelCapabilitiesRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(string $provider, string $model, string $responseFormat = 'json'): ?array
    {
        if (!EvidenceSchema::tablePresent($this->pdo, EvidenceSchema::TABLE_MODEL_CAPABILITIES)) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . EvidenceSchema::TABLE_MODEL_CAPABILITIES
            . ' WHERE provider = ? AND model = ? AND response_format = ? LIMIT 1'
        );
        $stmt->execute(array($provider, $model, $responseFormat));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function supportsSegmentTimestamps(string $provider, string $model, string $responseFormat = 'json'): bool
    {
        $row = $this->find($provider, $model, $responseFormat);
        return (bool)($row['supports_segment_timestamps'] ?? false);
    }
}
