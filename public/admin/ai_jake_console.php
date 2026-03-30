<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

cw_require_login();

$u = cw_current_user($pdo);

// TODO: replace 1 with your own user ID if needed
if ((int)($u['id'] ?? 0) !== 1) {
    http_response_code(403);
    exit('Forbidden');
}

$ssotStmt = $pdo->prepare("
    SELECT version, title, content, created_at
    FROM ssot_versions
    ORDER BY created_at DESC, id DESC
    LIMIT 1
");
$ssotStmt->execute();
$ssot = $ssotStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$ssotVersion = (string)($ssot['version'] ?? 'unknown');
$ssotTitle = (string)($ssot['title'] ?? 'SSOT');
$ssotContent = (string)($ssot['content'] ?? '');
$ssotCreatedAt = (string)($ssot['created_at'] ?? '');

function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>IPCA Jake Console</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    * { box-sizing: border-box; }
    body {
        margin: 0;
        font-family: Arial, Helvetica, sans-serif;
        background: #0f172a;
        color: #e2e8f0;
    }
    .layout {
        display: grid;
        grid-template-columns: 58% 42%;
        min-height: 100vh;
    }
    .left, .right {
        padding: 18px;
    }
    .left {
        border-right: 1px solid #1e293b;
    }
    .panel {
        background: #111827;
        border: 1px solid #334155;
        border-radius: 12px;
        padding: 14px;
        margin-bottom: 14px;
        box-shadow: 0 10px 24px rgba(0,0,0,0.18);
    }
    h1, h2, h3 {
        margin: 0 0 10px 0;
    }
    h1 {
        font-size: 22px;
    }
    h2 {
        font-size: 18px;
    }
    h3 {
        font-size: 15px;
    }
    .muted {
        color: #94a3b8;
        font-size: 13px;
    }
    textarea, input[type="text"] {
        width: 100%;
        border: 1px solid #475569;
        background: #020617;
        color: #e2e8f0;
        border-radius: 10px;
        padding: 10px;
        font-size: 14px;
    }
    textarea {
        min-height: 130px;
        resize: vertical;
    }
    button {
        border: 0;
        border-radius: 10px;
        background: #1d4ed8;
        color: #fff;
        padding: 10px 14px;
        font-size: 14px;
        cursor: pointer;
    }
    button.secondary {
        background: #334155;
    }
    .toolbar {
        display: flex;
        gap: 10px;
        margin-top: 10px;
        flex-wrap: wrap;
    }
    pre {
        margin: 0;
        white-space: pre-wrap;
        word-break: break-word;
        background: #020617;
        border: 1px solid #334155;
        border-radius: 10px;
        padding: 12px;
        max-height: 420px;
        overflow: auto;
        font-size: 13px;
        line-height: 1.45;
    }
    .grid2 {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }
    .status {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 999px;
        background: #1e293b;
        color: #cbd5e1;
        font-size: 12px;
    }
    .result-block {
        margin-top: 12px;
    }
    .small {
        font-size: 12px;
    }
</style>
</head>
<body>
<div class="layout">
    <div class="left">
        <div class="panel">
            <h1>🧠 Jake Console</h1>
            <div class="muted">Architect mode · read-only diagnostics · SSOT-aware</div>
        </div>

        <div class="panel">
            <h2>Ask Jake</h2>
            <div class="muted">Use this for bug tracing, architecture checks, file review plans, and next-step guidance.</div>
            <textarea id="jake_prompt" placeholder="Example: Review test_finalize_v2 flow and tell me exactly which file owns remediation creation."></textarea>
            <div class="toolbar">
                <button type="button" onclick="askJake()">Ask Jake</button>
                <button type="button" class="secondary" onclick="setPrompt('Review current file ownership for progress test finalization and identify drift.')">Ownership review</button>
                <button type="button" class="secondary" onclick="setPrompt('Give me a surgical patch plan for one file only, with zero architecture drift.')">Surgical patch plan</button>
            </div>
        </div>

        <div class="grid2">
            <div class="panel">
                <h2>Read File</h2>
                <input type="text" id="file_path" placeholder="src/courseware_progression_v2.php">
                <div class="toolbar">
                    <button type="button" onclick="readFile()">Read file</button>
                </div>
            </div>

            <div class="panel">
                <h2>Describe Table</h2>
                <input type="text" id="table_name" placeholder="progress_tests_v2">
                <div class="toolbar">
                    <button type="button" onclick="describeTable()">Describe</button>
                </div>
            </div>
        </div>

        <div class="grid2">
            <div class="panel">
                <h2>List Project Files</h2>
                <div class="muted">Scans a focused set of directories.</div>
                <div class="toolbar">
                    <button type="button" onclick="listFiles()">List files</button>
                </div>
            </div>

            <div class="panel">
                <h2>List DB Tables</h2>
                <div class="toolbar">
                    <button type="button" onclick="listTables()">List tables</button>
                </div>
            </div>
        </div>

        <div class="panel">
            <h2>Result</h2>
            <div class="muted">Structured output only. No hidden writes.</div>
            <div class="result-block">
                <pre id="result_box">Ready.</pre>
            </div>
        </div>
    </div>

    <div class="right">
        <div class="panel">
            <h2>📘 SSOT Snapshot</h2>
            <div class="small">
                <span class="status">Version <?php echo h($ssotVersion); ?></span>
                <?php if ($ssotCreatedAt !== ''): ?>
                    <span class="muted">· <?php echo h($ssotCreatedAt); ?></span>
                <?php endif; ?>
            </div>
            <div class="muted" style="margin-top:8px;"><?php echo h($ssotTitle); ?></div>
            <div style="margin-top:12px;">
                <pre><?php echo h($ssotContent); ?></pre>
            </div>
        </div>
    </div>
</div>

<script>
function setResult(data) {
    var box = document.getElementById('result_box');
    if (typeof data === 'string') {
        box.textContent = data;
        return;
    }
    box.textContent = JSON.stringify(data, null, 2);
}

function postAction(payload) {
    setResult('Working...');
    fetch('/admin/api/ai_jake_console_action.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
    })
    .then(function(r){ return r.json(); })
    .then(function(data){ setResult(data); })
    .catch(function(err){
        setResult({
            ok: false,
            error: String(err)
        });
    });
}

function askJake() {
    var prompt = document.getElementById('jake_prompt').value;
    postAction({
        action: 'ask_jake',
        prompt: prompt
    });
}

function readFile() {
    var path = document.getElementById('file_path').value;
    postAction({
        action: 'read_file',
        path: path
    });
}

function listFiles() {
    postAction({
        action: 'list_files'
    });
}

function listTables() {
    postAction({
        action: 'list_tables'
    });
}

function describeTable() {
    var table = document.getElementById('table_name').value;
    postAction({
        action: 'describe_table',
        table: table
    });
}
</script>
</body>
</html>