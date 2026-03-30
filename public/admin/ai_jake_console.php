<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

cw_require_login();

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');

if ($role !== 'admin') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jake Console</title>
    <style>
        body {
            font-family: Arial, Helvetica, sans-serif;
            background: #f3f4f6;
            margin: 0;
            padding: 0;
            color: #111827;
        }
        .wrap {
            max-width: 1200px;
            margin: 30px auto;
            background: #ffffff;
            border: 1px solid #d1d5db;
            border-radius: 12px;
            overflow: hidden;
        }
        .head {
            background: linear-gradient(135deg, #1e3a8a, #2563eb);
            color: #fff;
            padding: 24px;
        }
        .body {
            padding: 24px;
        }
        .card {
            border: 1px solid #d1d5db;
            border-radius: 10px;
            padding: 18px;
            background: #f9fafb;
            margin-bottom: 16px;
        }
        h1, h2, p {
            margin-top: 0;
        }
        .ok {
            color: #065f46;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="head">
            <h1>Jake Console</h1>
            <p>Internal AI architect console</p>
        </div>
        <div class="body">
            <div class="card">
                <h2>Access Check</h2>
                <p class="ok">OK — authenticated as admin.</p>
                <p>User ID: <?php echo (int)$u['id']; ?></p>
                <p>Name: <?php echo htmlspecialchars((string)$u['name'], ENT_QUOTES, 'UTF-8'); ?></p>
                <p>Role: <?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>

            <div class="card">
                <h2>Step Status</h2>
                <p>Bootstrap loaded.</p>
                <p>Session loaded.</p>
                <p>PDO available.</p>
                <p>HTML shell rendering correctly.</p>
            </div>
        </div>
    </div>
</body>
</html>