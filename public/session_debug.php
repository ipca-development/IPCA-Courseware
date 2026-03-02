<?php
require_once __DIR__ . '/../src/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

echo "Session ID: " . session_id() . "\n";
echo "User ID in session: " . ($_SESSION['user_id'] ?? '(none)') . "\n";
echo "Cookies received:\n";
print_r($_COOKIE);