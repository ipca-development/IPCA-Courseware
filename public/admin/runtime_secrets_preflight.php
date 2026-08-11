<?php
/**
 * Safe runtime-secret preflight for approved-host ops (admin).
 * Prints AVAILABLE / MISSING only. Never renders secret material.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/src/RuntimeSecrets.php';

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');

$checks = [
    'OPENAI_API_KEY' => 'OPENAI_API_KEY',
    'CW_DB_PASS' => 'CW_DB_PASS',
    'ASR credentials' => 'ASR_CREDENTIALS',
];

foreach ($checks as $label => $logical) {
    echo $label . ': ' . RuntimeSecrets::availabilityLabel($logical) . "\n";
}

# Note: this page runs under PHP-FPM and sees FPM-injected env.
echo "# Python CLI does NOT inherit FPM env automatically.\n";
echo "# Use /etc/ipca/analytics.env or PHP_FPM_POOL allowlisted load via RuntimeSecrets.\n";
