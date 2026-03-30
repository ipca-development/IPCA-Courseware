<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

cw_require_login();

$user = cw_current_user($pdo);
$role = (string)($user['role'] ?? '');

if ($role !== 'admin') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

if (!function_exists('h')) {
    function h(?string $value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

function table_exists(PDO $pdo, string $tableName): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = ?
    ");
    $stmt->execute([$tableName]);
    return (int)$stmt->fetchColumn() > 0;
}

$hasSsotTable = table_exists($pdo, 'ai_ssot_snapshots');
$hasRequestsTable = table_exists($pdo, 'ai_jake_requests');

$latestSsot = null;
$recentRequests = [];

if ($hasSsotTable) {
    $stmt = $pdo->query("
        SELECT *
        FROM ai_ssot_snapshots
        ORDER BY id DESC
        LIMIT 1
    ");
    $latestSsot = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($hasRequestsTable) {
    $stmt = $pdo->query("
        SELECT id, request_title, request_type, status, created_at, updated_at
        FROM ai_jake_requests
        ORDER BY id DESC
        LIMIT 10
    ");
    $recentRequests = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Jake Console</title>

<style>
body { font-family: Arial; background:#eef1f5; margin:0; padding:20px; }
.card { background:#fff; padding:20px; border-radius:12px; margin-bottom:20px; }
textarea,input { width:100%; padding:10px; margin-top:5px; }
button { padding:10px 15px; margin-top:10px; cursor:pointer; }
.codebox { background:#111; color:#0f0; padding:15px; white-space:pre-wrap; }
.table-link { cursor:pointer; color:#3d66e0; }
</style>
</head>

<body>

<div class="card">
<h2>Ask Jake</h2>

<input id="request_title" placeholder="Title">
<input id="request_type" placeholder="Type">
<textarea id="request_body" placeholder="Your request"></textarea>

<button id="btn_save_request">Save</button>
<button id="btn_jake">Run Jake</button>
</div>

<div class="card">
<h2>DB Tools</h2>

<input id="table_name" placeholder="Table name">

<button id="btn_list_tables">List Tables</button>
<button id="btn_describe_table">Describe Table</button>

<textarea id="db_query" placeholder="SELECT * FROM table"></textarea>
<button id="btn_run_sql">Run SQL</button>
</div>

<div class="card">
<h2>Response</h2>
<div id="response_panel" class="codebox">Ready</div>
</div>

<script>
const API = '/admin/api/ai_jake_console_action.php';
const panel = document.getElementById('response_panel');

function setResponse(t) {
    panel.textContent = t;
}

async function callAPI(payload) {
    setResponse('Loading...');
    try {
        const res = await fetch(API, {
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify(payload)
        });
        const data = await res.json();
        if (!data.ok) {
            setResponse("ERROR:\n" + data.error);
            return null;
        }
        return data;
    } catch(e) {
        setResponse("FETCH ERROR:\n" + e.message);
        return null;
    }
}

// SAVE
document.getElementById('btn_save_request').onclick = async () => {
    const data = await callAPI({
        action:'save_request',
        prompt: document.getElementById('request_body').value
    });
    if (!data) return;
    setResponse("Saved ID: " + data.request_id);
};

// JAKE
document.getElementById('btn_jake').onclick = async () => {
    const data = await callAPI({
        action:'jake_think',
        prompt: document.getElementById('request_body').value
    });
    if (!data) return;
    setResponse(data.response);
};

// LIST TABLES
document.getElementById('btn_list_tables').onclick = async () => {
    const data = await callAPI({action:'list_tables'});
    if (!data) return;

    let out = "TABLES:\n\n";

    data.tables.forEach(t => {
        out += "• " + t + "\n";
    });

    setResponse(out);

    // make clickable
    setTimeout(() => {
        const lines = panel.innerHTML.split('\n');
        panel.innerHTML = lines.map(line => {
            if (line.startsWith('• ')) {
                const table = line.replace('• ', '');
                return '<span class="table-link" onclick="selectTable(\''+table+'\')">'+line+'</span>';
            }
            return line;
        }).join('\n');
    }, 10);
};

// CLICK TABLE
function selectTable(name){
    document.getElementById('table_name').value = name;
}

// DESCRIBE
document.getElementById('btn_describe_table').onclick = async () => {
    const table = document.getElementById('table_name').value;
    if (!table) return setResponse("Enter table");

    const data = await callAPI({
        action:'describe_table',
        table:table
    });
    if (!data) return;

    setResponse(JSON.stringify(data.columns, null, 2));
};

// SQL
document.getElementById('btn_run_sql').onclick = async () => {
    const data = await callAPI({
        action:'run_sql_read',
        query: document.getElementById('db_query').value
    });
    if (!data) return;

    setResponse(JSON.stringify(data.rows, null, 2));
};
</script>

</body>
</html>