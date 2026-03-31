<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

cw_require_admin();

cw_header('Jake Console');

echo '<div class="card" style="padding:24px">PAGE LOAD OK</div>';

cw_footer();