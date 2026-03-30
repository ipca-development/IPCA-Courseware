<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

cw_require_login();

$u = cw_current_user($pdo);

// 🔒 Restrict to YOU ONLY
if ((int)$u['id'] !== 1) {
    die('Forbidden');
}

// --- Load SSOT ---
$ssotStmt = $pdo->prepare("
    SELECT version, content
    FROM ssot_versions
    ORDER BY created_at DESC
    LIMIT 1
");
$ssotStmt->execute();
$ssot = $ssotStmt->fetch(PDO::FETCH_ASSOC);

$ssotVersion = $ssot['version'] ?? 'unknown';
$ssotContent = $ssot['content'] ?? '';

?>
<!DOCTYPE html>
<html>
<head>
    <title>IPCA Jake Console</title>
    <style>
        body {
            font-family: Arial;
            background: #0f172a;
            color: #e2e8f0;
            margin: 0;
        }
        .container {
            display: flex;
            height: 100vh;
        }
        .left {
            width: 60%;
            padding: 20px;
            border-right: 1px solid #1e293b;
        }
        .right {
            width: 40%;
            padding: 20px;
        }
        textarea {
            width: 100%;
            height: 120px;
            background: #020617;
            color: #e2e8f0;
            border: 1px solid #334155;
            padding: 10px;
        }
        button {
            background: #1e40af;
            color: white;
            padding: 10px 15px;
            border: none;
            cursor: pointer;
            margin-top: 10px;
        }
        pre {
            background: #020617;
            padding: 10px;
            overflow: auto;
            border: 1px solid #334155;
        }
        .box {
            margin-bottom: 20px;
        }
        h2 {
            margin-top: 0;
        }
    </style>
</head>
<body>

<div class="container">

    <!-- LEFT: JAKE -->
    <div class="left">

        <h2>IPCA's AI Agent (Jake) � The Architect</h2>

        <form method="post">
            <textarea name="prompt" placeholder="Ask Jake..."><?php echo htmlspecialchars($_POST['prompt'] ?? ''); ?></textarea>
            <button type="submit">Ask Jake</button>
        </form>

        <?php if (!empty($_POST['prompt'])): ?>

            <div class="box">
                <h3>📥 Your Request</h3>
                <pre><?php echo htmlspecialchars($_POST['prompt']); ?></pre>
            </div>

            <div class="box">
                <h3>🧠 Jake Analysis</h3>
                <pre>
<?php
// VERY SIMPLE V1 — we just echo SSOT + request
echo "SSOT Version: " . $ssotVersion . "\n\n";

echo "Jake reasoning:\n";
echo "- Check SSOT\n";
echo "- Identify affected files\n";
echo "- Avoid controller logic\n";
echo "- Move logic to engine\n\n";

echo "Next step: implement or inspect requested change.";
?>
                </pre>
            </div>

        <?php endif; ?>

    </div>

    <!-- RIGHT: SSOT -->
    <div class="right">

        <h2>📘 SSOT (v<?php echo htmlspecialchars($ssotVersion); ?>)</h2>

        <pre style="height:80vh;"><?php echo htmlspecialchars($ssotContent); ?></pre>

    </div>

</div>

</body>
</html>