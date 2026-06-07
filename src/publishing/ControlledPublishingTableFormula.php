<?php
declare(strict_types=1);

/**
 * Lightweight spreadsheet-style formulas for governed table cells.
 * References use Excel-style notation (A1 = column A, body row 1).
 */
final class ControlledPublishingTableFormula
{
    /**
     * @param list<list<string>> $bodyRows
     */
    public static function displayValue(string $raw, array $bodyRows): string
    {
        $raw = trim($raw);
        if ($raw === '' || !str_starts_with($raw, '=')) {
            return $raw;
        }
        try {
            $value = self::evaluate($raw, $bodyRows);
            if (is_float($value) && floor($value) === $value) {
                return (string)(int)$value;
            }
            if (is_numeric($value)) {
                return rtrim(rtrim(sprintf('%.4f', (float)$value), '0'), '.');
            }
            return (string)$value;
        } catch (Throwable) {
            return '#ERR';
        }
    }

    /**
     * @param list<list<string>> $bodyRows
     */
    public static function evaluate(string $formula, array $bodyRows): float|string
    {
        $expr = trim(substr(trim($formula), 1));
        if ($expr === '') {
            throw new RuntimeException('Empty formula');
        }

        if (preg_match('/^(SUM|AVG|AVERAGE|MIN|MAX|COUNT)\((.+)\)$/i', $expr, $m) === 1) {
            $fn = strtoupper($m[1]);
            $args = self::parseArgs($m[2], $bodyRows);
            return match ($fn) {
                'SUM' => array_sum($args),
                'AVG', 'AVERAGE' => $args === array() ? 0.0 : array_sum($args) / count($args),
                'MIN' => $args === array() ? 0.0 : min($args),
                'MAX' => $args === array() ? 0.0 : max($args),
                'COUNT' => (float)count($args),
                default => throw new RuntimeException('Unsupported function'),
            };
        }

        return self::evaluateExpression($expr, $bodyRows);
    }

    /**
     * @param list<list<string>> $bodyRows
     * @return list<float>
     */
    private static function parseArgs(string $raw, array $bodyRows): array
    {
        $parts = preg_split('/\s*,\s*/', trim($raw)) ?: array();
        $values = array();
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (str_contains($part, ':')) {
                foreach (self::expandRange($part, $bodyRows) as $v) {
                    $values[] = $v;
                }
                continue;
            }
            $values[] = self::resolveValue($part, $bodyRows);
        }
        return $values;
    }

    /**
     * @param list<list<string>> $bodyRows
     * @return list<float>
     */
    private static function expandRange(string $range, array $bodyRows): array
    {
        $bits = explode(':', $range, 2);
        if (count($bits) !== 2) {
            throw new RuntimeException('Invalid range');
        }
        $start = self::parseRef($bits[0]);
        $end = self::parseRef($bits[1]);
        $values = array();
        $r0 = min($start['row'], $end['row']);
        $r1 = max($start['row'], $end['row']);
        $c0 = min($start['col'], $end['col']);
        $c1 = max($start['col'], $end['col']);
        for ($r = $r0; $r <= $r1; $r++) {
            for ($c = $c0; $c <= $c1; $c++) {
                $values[] = self::cellNumber($bodyRows, $r, $c);
            }
        }
        return $values;
    }

    /**
     * @param list<list<string>> $bodyRows
     */
    private static function evaluateExpression(string $expr, array $bodyRows): float
    {
        $expr = preg_replace_callback(
            '/([A-Z]+[0-9]+)/i',
            static function (array $m) use ($bodyRows): string {
                $ref = self::parseRef($m[1]);
                return (string)self::cellNumber($bodyRows, $ref['row'], $ref['col']);
            },
            strtoupper($expr)
        ) ?? $expr;

        if (!preg_match('/^[0-9+\-*/().\s]+$/', $expr)) {
            throw new RuntimeException('Invalid expression');
        }

        try {
            return (float)self::safeMathEval($expr);
        } catch (Throwable $e) {
            throw new RuntimeException('Math error', 0, $e);
        }
    }

    private static function safeMathEval(string $expr): float
    {
        $tokens = array();
        preg_match_all('/([0-9.]+)|([+\-*/()])/', $expr, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if ($match[1] !== '') {
                $tokens[] = array('num', (float)$match[1]);
            } elseif ($match[2] !== '') {
                $tokens[] = array('op', $match[2]);
            }
        }
        if ($tokens === array()) {
            throw new RuntimeException('Empty math');
        }
        $pos = 0;
        $value = self::parseAddSub($tokens, $pos);
        if ($pos !== count($tokens)) {
            throw new RuntimeException('Trailing tokens');
        }
        return $value;
    }

    /**
     * @param list<array{0:string,1:float|string}> $tokens
     */
    private static function parseAddSub(array $tokens, int &$pos): float
    {
        $value = self::parseMulDiv($tokens, $pos);
        while ($pos < count($tokens) && $tokens[$pos][0] === 'op' && in_array($tokens[$pos][1], array('+', '-'), true)) {
            $op = $tokens[$pos][1];
            $pos++;
            $rhs = self::parseMulDiv($tokens, $pos);
            $value = $op === '+' ? $value + $rhs : $value - $rhs;
        }
        return $value;
    }

    /**
     * @param list<array{0:string,1:float|string}> $tokens
     */
    private static function parseMulDiv(array $tokens, int &$pos): float
    {
        $value = self::parseUnary($tokens, $pos);
        while ($pos < count($tokens) && $tokens[$pos][0] === 'op' && in_array($tokens[$pos][1], array('*', '/'), true)) {
            $op = $tokens[$pos][1];
            $pos++;
            $rhs = self::parseUnary($tokens, $pos);
            if ($op === '*') {
                $value = $value * $rhs;
            } elseif ($rhs == 0.0) {
                throw new RuntimeException('Divide by zero');
            } else {
                $value = $value / $rhs;
            }
        }
        return $value;
    }

    /**
     * @param list<array{0:string,1:float|string}> $tokens
     */
    private static function parseUnary(array $tokens, int &$pos): float
    {
        if ($pos < count($tokens) && $tokens[$pos][0] === 'op' && $tokens[$pos][1] === '-') {
            $pos++;
            return -self::parseUnary($tokens, $pos);
        }
        if ($pos < count($tokens) && $tokens[$pos][0] === 'op' && $tokens[$pos][1] === '(') {
            $pos++;
            $value = self::parseAddSub($tokens, $pos);
            if ($pos >= count($tokens) || $tokens[$pos][0] !== 'op' || $tokens[$pos][1] !== ')') {
                throw new RuntimeException('Missing )');
            }
            $pos++;
            return $value;
        }
        if ($pos < count($tokens) && $tokens[$pos][0] === 'num') {
            $value = (float)$tokens[$pos][1];
            $pos++;
            return $value;
        }
        throw new RuntimeException('Bad expression');
    }

    /**
     * @param list<list<string>> $bodyRows
     */
    private static function resolveValue(string $token, array $bodyRows): float
    {
        if (preg_match('/^[A-Z]+[0-9]+$/i', $token) === 1) {
            $ref = self::parseRef($token);
            return self::cellNumber($bodyRows, $ref['row'], $ref['col']);
        }
        if (is_numeric($token)) {
            return (float)$token;
        }
        throw new RuntimeException('Bad arg');
    }

    /**
     * @return array{col:int,row:int}
     */
    private static function parseRef(string $ref): array
    {
        if (preg_match('/^([A-Z]+)([0-9]+)$/i', strtoupper(trim($ref)), $m) !== 1) {
            throw new RuntimeException('Bad ref');
        }
        $col = 0;
        foreach (str_split(strtoupper($m[1])) as $ch) {
            $col = $col * 26 + (ord($ch) - 64);
        }
        return array('col' => $col - 1, 'row' => (int)$m[2] - 1);
    }

    /**
     * @param list<list<string>> $bodyRows
     */
    private static function cellNumber(array $bodyRows, int $row, int $col): float
    {
        $raw = (string)($bodyRows[$row][$col] ?? '');
        if ($raw === '') {
            return 0.0;
        }
        if (str_starts_with($raw, '=')) {
            $v = self::evaluate($raw, $bodyRows);
            return is_numeric($v) ? (float)$v : 0.0;
        }
        return is_numeric($raw) ? (float)$raw : 0.0;
    }
}
