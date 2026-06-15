<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingMccfIntegrityContentService.php';

/**
 * MCCF integrity scoring based on regulation-vs-manual content alignment.
 */
final class ControlledPublishingMccfIntegrityService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    /**
     * @param array<string,mixed> $requirement
     * @param list<array<string,mixed>> $linkedExcerpts
     * @param list<array<string,mixed>> $regulationLinks
     * @return array{score:int,label:string,tone:string,breakdown:array<string,int>,reasons:list<string>}
     */
    public function scoreRequirement(
        array $requirement,
        array $linkedExcerpts,
        array $regulationLinks,
        int $bookVersionId = 0,
        bool $resolveEasa = true,
        bool $lightweight = false
    ): array {
        if ($this->pdo instanceof PDO) {
            return (new ControlledPublishingMccfIntegrityContentService($this->pdo))
                ->scoreRequirement($requirement, $linkedExcerpts, $regulationLinks, $bookVersionId, $resolveEasa, $lightweight);
        }

        return array(
            'score' => 0,
            'label' => 'Unavailable',
            'tone' => 'muted',
            'breakdown' => array(),
            'reasons' => array('Integrity scoring requires a database connection.'),
        );
    }

    public static function barClass(string $tone): string
    {
        return match ($tone) {
            'ok' => 'mccf-bar-fill--ok',
            'warn' => 'mccf-bar-fill--warn',
            'bad' => 'mccf-bar-fill--bad',
            default => 'mccf-bar-fill--muted',
        };
    }
}
