<?php
declare(strict_types=1);

/**
 * Cross Reference Annex catalog for regulatory references under manual titles.
 *
 * @phpstan-type CrossRefEntry array{key:string,label:string}
 */
final class ControlledPublishingCrossRefAnnex
{
    /**
     * @return array<string,list<CrossRefEntry>>
     */
    public static function catalog(): array
    {
        return array(
            'PART NCO' => array(
                array('key' => 'NCO.GEN.103', 'label' => 'NCO.GEN.103 — General requirements'),
                array('key' => 'NCO.GEN.105', 'label' => 'NCO.GEN.105 — Crew requirements'),
                array('key' => 'NCO.GEN.110', 'label' => 'NCO.GEN.110 — Operator responsibilities'),
                array('key' => 'NCO.OP.100', 'label' => 'NCO.OP.100 — Operational procedures'),
                array('key' => 'NCO.OP.125', 'label' => 'NCO.OP.125 — Pre-flight duties'),
            ),
            'PART FCL' => array(
                array('key' => 'FCL.1010', 'label' => 'FCL.1010 — Training course requirements'),
                array('key' => 'FCL.1050', 'label' => 'FCL.1050 — Instructor privileges'),
            ),
            'PART CAMO' => array(
                array('key' => 'CAMO.A.300', 'label' => 'CAMO.A.300 — Continuing airworthiness'),
            ),
        );
    }

    public static function formatDisplay(string $document, string $key): string
    {
        $document = trim($document);
        $key = trim($key);
        if ($key === '') {
            return '';
        }
        if (preg_match('/^(NCO|FCL|CAMO|SPO|CAT|ORO)\./i', $key) === 1) {
            return 'Part ' . $key;
        }
        if ($document !== '') {
            return $document . ' ' . $key;
        }

        return $key;
    }

    /**
     * @return list<string>
     */
    public static function documentKeys(): array
    {
        return array_keys(self::catalog());
    }
}
