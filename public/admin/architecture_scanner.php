<?php
declare(strict_types=1);

echo '<pre>';
echo '__DIR__ = ' . __DIR__ . "\n";
echo 'dirname(__DIR__) = ' . dirname(__DIR__) . "\n";
echo 'dirname(dirname(__DIR__)) = ' . dirname(dirname(__DIR__)) . "\n";
echo 'realpath(__DIR__) = ' . realpath(__DIR__) . "\n";
echo 'realpath(__DIR__ . "/..") = ' . realpath(__DIR__ . '/..') . "\n";
echo 'realpath(__DIR__ . "/../../") = ' . realpath(__DIR__ . '/../../') . "\n";
echo '</pre>';
exit;

$config = require __DIR__ . '/../../src/courseware_architecture_ssot.php';
require_once __DIR__ . '/../../src/Services/ArchitectureScanner.php';

/**
 * Repo root:
 * public/admin/architecture_scanner.php -> ../../ = repo root
 */
$repoRoot = realpath(__DIR__ . '/../../');
$scanner  = new ArchitectureScanner($repoRoot, $config);
$report   = $scanner->scan();

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$statusColor = '#16a34a';
if ($report['status'] === 'WARNING') {
    $statusColor = '#d97706';
} elseif ($report['status'] === 'CRITICAL') {
    $statusColor = '#dc2626';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>IPCA Courseware - Architecture Scanner</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            margin: 0;
            padding: 24px;
            background: #f3f4f6;
            font-family: Arial, Helvetica, sans-serif;
            color: #111827;
        }
        .wrap {
            max-width: 1200px;
            margin: 0 auto;
        }
        .card {
            background: #ffffff;
            border-radius: 14px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.08);
            padding: 20px 24px;
            margin-bottom: 20px;
        }
        .header {
            background: linear-gradient(135deg, #1e3c72, #2a5298);
            color: #ffffff;
        }
        h1, h2, h3 {
            margin-top: 0;
        }
        .pill {
            display: inline-block;
            padding: 8px 14px;
            border-radius: 999px;
            color: #ffffff;
            font-weight: bold;
            background: <?php echo h($statusColor); ?>;
        }
        .grid {
            display: grid;
            gap: 16px;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        }
        .metric {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 14px;
        }
        .metric .num {
            font-size: 28px;
            font-weight: bold;
            margin-top: 6px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            text-align: left;
            padding: 10px 12px;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: top;
        }
        th {
            background: #f9fafb;
        }
        .ok {
            color: #15803d;
            font-weight: bold;
        }
        .missing {
            color: #b91c1c;
            font-weight: bold;
        }
        ul {
            margin: 0;
            padding-left: 20px;
        }
        .mono {
            font-family: Menlo, Monaco, Consolas, monospace;
            font-size: 13px;
        }
        .small {
            color: #6b7280;
            font-size: 13px;
        }
        .json-box {
            white-space: pre-wrap;
            background: #0f172a;
            color: #e2e8f0;
            border-radius: 12px;
            padding: 16px;
            overflow-x: auto;
            font-family: Menlo, Monaco, Consolas, monospace;
            font-size: 12px;
        }
    </style>
</head>
<body>
<div class="wrap">

    <div class="card header">
        <h1>IPCA Courseware - Repository Architecture Scanner</h1>
        <p>
            SSOT-based architecture health check for the Courseware repository.
        </p>
        <p>
            <span class="pill"><?php echo h($report['status']); ?></span>
        </p>
        <p class="small" style="color:#dbeafe;">
            Scanned at UTC: <?php echo h($report['scanned_at_utc']); ?><br>
            Repo root: <span class="mono"><?php echo h($report['repo_root']); ?></span>
        </p>
    </div>

    <div class="card">
        <h2>Summary</h2>
        <div class="grid">
            <div class="metric">
                <div>Files</div>
                <div class="num"><?php echo (int)$report['summary']['file_count']; ?></div>
            </div>
            <div class="metric">
                <div>Directories</div>
                <div class="num"><?php echo (int)$report['summary']['directory_count']; ?></div>
            </div>
            <div class="metric">
                <div>Critical</div>
                <div class="num"><?php echo (int)$report['summary']['critical_count']; ?></div>
            </div>
            <div class="metric">
                <div>Warnings</div>
                <div class="num"><?php echo (int)$report['summary']['warning_count']; ?></div>
            </div>
            <div class="metric">
                <div>Info</div>
                <div class="num"><?php echo (int)$report['summary']['info_count']; ?></div>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>Environment SSOT</h2>
        <table>
            <tbody>
            <?php foreach ($report['environment'] as $key => $value): ?>
                <tr>
                    <th><?php echo h((string)$key); ?></th>
                    <td><?php echo h((string)$value); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Architecture Components</h2>
        <table>
            <thead>
            <tr>
                <th>Component</th>
                <th>Status</th>
                <th>Found At</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($report['components'] as $componentKey => $component): ?>
                <tr>
                    <td>
                        <strong><?php echo h($component['label']); ?></strong><br>
                        <span class="small mono"><?php echo h((string)$componentKey); ?></span>
                    </td>
                    <td class="<?php echo $component['status'] === 'ok' ? 'ok' : 'missing'; ?>">
                        <?php echo strtoupper(h($component['status'])); ?>
                    </td>
                    <td>
                        <?php if (!empty($component['found_at'])): ?>
                            <ul>
                                <?php foreach ($component['found_at'] as $path): ?>
                                    <li class="mono"><?php echo h((string)$path); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <span class="small">No marker found</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Critical Issues</h2>
        <?php if (empty($report['issues']['critical'])): ?>
            <p class="ok">No critical issues found.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($report['issues']['critical'] as $issue): ?>
                    <li><?php echo h((string)$issue); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Warnings</h2>
        <?php if (empty($report['issues']['warning'])): ?>
            <p class="ok">No warnings found.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($report['issues']['warning'] as $issue): ?>
                    <li><?php echo h((string)$issue); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Info</h2>
        <?php if (empty($report['issues']['info'])): ?>
            <p class="small">No info messages.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($report['issues']['info'] as $issue): ?>
                    <li><?php echo h((string)$issue); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Raw JSON</h2>
        <div class="json-box"><?php echo h(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></div>
    </div>

</div>
</body>
</html>