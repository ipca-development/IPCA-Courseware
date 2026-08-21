<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/publishing/ControlledPublishingTableFormula.php';

function formula_safety_assert(string $label, bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
    echo "PASS: {$label}\n";
}

formula_safety_assert(
    'ordinary formulas still evaluate',
    ControlledPublishingTableFormula::displayValue(
        '=SUM(A1:B1)',
        array(array('2', '3'))
    ) === '5'
);

formula_safety_assert(
    'direct self-reference is rejected',
    ControlledPublishingTableFormula::displayValue(
        '=A1',
        array(array('=A1'))
    ) === '#CYCLE'
);

formula_safety_assert(
    'mutual reference cycle is rejected',
    ControlledPublishingTableFormula::displayValue(
        '=A1',
        array(array('=B1', '=A1'))
    ) === '#CYCLE'
);

formula_safety_assert(
    'range self-reference is rejected',
    ControlledPublishingTableFormula::displayValue(
        '=SUM(A1:A1)',
        array(array('=SUM(A1:A1)'))
    ) === '#CYCLE'
);

$chain = array(array());
for ($column = 0; $column < 70; $column++) {
    $next = $column + 2;
    $letters = '';
    for ($number = $next; $number > 0; $number = intdiv($number - 1, 26)) {
        $letters = chr(65 + (($number - 1) % 26)) . $letters;
    }
    $chain[0][$column] = '=' . $letters . '1';
}
$chain[0][70] = '1';
formula_safety_assert(
    'dependency depth is bounded',
    ControlledPublishingTableFormula::displayValue('=A1', $chain) === '#ERR'
);

formula_safety_assert(
    'oversized ranges are bounded before expansion',
    ControlledPublishingTableFormula::displayValue(
        '=SUM(A1:Z1000000)',
        array(array('1'))
    ) === '#ERR'
);

$before = memory_get_usage(true);
for ($attempt = 0; $attempt < 1000; $attempt++) {
    ControlledPublishingTableFormula::displayValue('=A1', array(array('=A1')));
}
$growth = memory_get_usage(true) - $before;
formula_safety_assert(
    'repeated cycle failures do not grow memory without bound',
    $growth < 8 * 1024 * 1024
);

echo "Controlled publishing table formula safety: PASS\n";
