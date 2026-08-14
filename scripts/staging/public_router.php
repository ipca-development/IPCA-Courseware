<?php
declare(strict_types=1);

require_once __DIR__ . '/load_fpm_db_env.php';

ipca_load_fpm_db_env('ipca_courseware_staging');
putenv('CW_COMMUNICATION_STAGING=1');
$_ENV['CW_COMMUNICATION_STAGING'] = '1';

if (getenv('CW_DB_NAME') !== 'ipca_courseware_staging') {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(array('ok' => false, 'error_code' => 'staging_misconfigured'));
    exit;
}

$public = dirname(__DIR__, 2) . '/public';
$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$file = $public . $path;
if (is_string($path) && is_file($file) && str_ends_with($file, '.php')) {
    require $file;
    return true;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(array('ok' => false, 'error' => 'Not found', 'error_code' => 'not_found'));
return true;
