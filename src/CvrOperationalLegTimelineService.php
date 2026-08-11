<?php
declare(strict_types=1);

/**
 * Derives continuous Operational Session leg times from the pilot-entered Hobbs chain.
 *
 * Engine shutdown ends the Operational Session, so intermediate legs in one session
 * are contiguous: each next Off Block equals the previous calculated On Block.
 */
final class CvrOperationalLegTimelineService
{
    public const MODEL = 'hobbs_continuous_v1';

    public static function roundUpToTenth(float $hours): float
    {
        $hours = max(0.0, $hours);
        $scaled = $hours * 10;
        $nearestInteger = round($scaled);
        if (abs($scaled - $nearestInteger) < 0.0000001) {
            $scaled = $nearestInteger;
        }
        return ceil($scaled) / 10;
    }

    /**
     * @param list<array<string,mixed>> $legs
     * @return list<array<string,mixed>>
     */
    public static function apply(array $legs): array
    {
        if ($legs === []) {
            return [];
        }
        $firstOffBlock = trim((string)($legs[0]['off_block_utc'] ?? ''));
        $timestamp = strtotime($firstOffBlock);
        if ($timestamp === false) {
            throw new RuntimeException('The first leg requires a valid Off Block time.');
        }
        $cursor = (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'));

        foreach ($legs as $index => $leg) {
            $startingHobbs = self::roundUpToTenth((float)($leg['starting_hobbs'] ?? 0));
            $endingHobbs = self::roundUpToTenth((float)($leg['ending_hobbs'] ?? 0));
            if ($endingHobbs < $startingHobbs) {
                throw new RuntimeException('Leg Hobbs End cannot be lower than Hobbs Start.');
            }
            $hobbsDelta = self::roundUpToTenth($endingHobbs - $startingHobbs);
            $durationMinutes = (int)round($hobbsDelta * 60);
            $onBlock = $cursor->modify('+' . $durationMinutes . ' minutes');

            $leg['evidence_off_block_utc'] = $leg['evidence_off_block_utc']
                ?? ($leg['off_block_utc'] ?? null);
            $leg['evidence_on_block_utc'] = $leg['evidence_on_block_utc']
                ?? ($leg['on_block_utc'] ?? null);
            $leg['sequence_number'] = $index + 1;
            $leg['starting_hobbs'] = $startingHobbs;
            $leg['ending_hobbs'] = $endingHobbs;
            $leg['hobbs_delta'] = $hobbsDelta;
            $leg['off_block_utc'] = $cursor->format('Y-m-d H:i:s');
            $leg['on_block_utc'] = $onBlock->format('Y-m-d H:i:s');
            $legs[$index] = $leg;
            $cursor = $onBlock;
        }
        return $legs;
    }
}
