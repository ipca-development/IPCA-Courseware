<?php
require_once __DIR__ . '/../../src/bootstrap.php';

echo 'BOOTSTRAP OK<br>';

cw_require_login();

echo 'LOGIN OK<br>';

$u = cw_current_user($pdo);

echo '<pre>';
print_r($u);
echo '</pre>';
exit;