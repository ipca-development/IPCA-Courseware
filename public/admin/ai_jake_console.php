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

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function table_exists(PDO $pdo, string $tableName): bool
{
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
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Jake Console</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root{
            --bg:#eef1f5;
            --card:#ffffff;
            --line:#d7dde7;
            --text:#182033;
            --muted:#5c667a;
            --blue1:#233b8f;
            --blue2:#3d66e0;
            --green:#256b4f;
            --shadow:0 10px 30px rgba(16,24,40,.08);
            --radius:18px;
        }
        *{box-sizing:border-box}
        body{
            margin:0;
            font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
            background:var(--bg);
            color:var(--text);
        }
        .page{
            padding:24px;
        }
        .shell{
            background:#f3f5f8;
            border:1px solid #cfd6df;
            border-radius:22px;
            overflow:hidden;
            box-shadow:var(--shadow);
        }
        .hero{
            padding:28px 30px;
            background:linear-gradient(135deg,var(--blue1),var(--blue2));
            color:#fff;
        }
        .hero h1{
            margin:0 0 10px 0;
            font-size:48px;
            line-height:1.05;
            font-weight:800;
            letter-spacing:-.02em;
        }
        .hero p{
            margin:0;
            font-size:18px;
            opacity:.95;
        }
        .content{
            padding:26px;
            display:grid;
            grid-template-columns:1.35fr .95fr;
            gap:22px;
        }
        .stack{
            display:grid;
            gap:22px;
        }
        .card{
            background:var(--card);
            border:1px solid var(--line);
            border-radius:var(--radius);
            padding:24px 26px;
            box-shadow:var(--shadow);
        }
        .card h2{
            margin:0 0 18px 0;
            font-size:28px;
            line-height:1.1;
            letter-spacing:-.02em;
        }
        .muted{
            color:var(--muted);
        }
        .ok{
            color:var(--green);
            font-weight:700;
        }
        .meta{
            display:grid;
            gap:12px;
        }
        .meta-row{
            display:grid;
            grid-template-columns:160px 1fr;
            gap:14px;
            align-items:start;
        }
        .meta-label{
            color:var(--muted);
            font-weight:600;
        }
        textarea,input,select{
            width:100%;
            border:1px solid #c9d2df;
            border-radius:12px;
            padding:12px 14px;
            font:inherit;
            color:var(--text);
            background:#fff;
        }
        textarea{
            min-height:180px;
            resize:vertical;
        }
        .form-grid{
            display:grid;
            gap:14px;
        }
        .form-row-2{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:14px;
        }
        .actions{
            display:flex;
            gap:12px;
            flex-wrap:wrap;
            margin-top:8px;
        }
        .btn{
            appearance:none;
            border:0;
            border-radius:12px;
            padding:12px 16px;
            font:inherit;
            font-weight:700;
            cursor:pointer;
        }
        .btn-primary{
            background:linear-gradient(135deg,var(--blue1),var(--blue2));
            color:#fff;
        }
        .btn-secondary{
            background:#e9eef8;
            color:#213051;
        }
        .panel-note{
            margin-top:12px;
            font-size:14px;
            color:var(--muted);
        }
        .list{
            display:grid;
            gap:12px;
        }
        .list-item{
            border:1px solid var(--line);
            border-radius:14px;
            padding:14px 16px;
            background:#fafbfd;
        }
        .list-title{
            font-weight:700;
            margin:0 0 6px 0;
        }
        .pill{
            display:inline-block;
            padding:5px 10px;
            border-radius:999px;
            font-size:12px;
            font-weight:700;
            background:#edf2ff;
            color:#2949a8;
            margin-right:8px;
        }
        .codebox{
            background:#0f172a;
            color:#d9e3f0;
            border-radius:14px;
            padding:14px 16px;
            font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
            font-size:13px;
            line-height:1.5;
            overflow:auto;
            white-space:pre-wrap;
        }
        .grid-3{
            display:grid;
            grid-template-columns:1fr 1fr 1fr;
            gap:12px;
        }
        .mini{
            border:1px solid var(--line);
            border-radius:14px;
            padding:14px;
            background:#fafbfd;
        }
        .mini-label{
            color:var(--muted);
            font-size:13px;
            margin-bottom:6px;
        }
        .mini-value{
            font-size:18px;
            font-weight:800;
        }
        @media (max-width: 1100px){
            .content{grid-template-columns:1fr}
        }
        @media (max-width: 720px){
            .form-row-2,.grid-3,.meta-row{grid-template-columns:1fr}
            .hero h1{font-size:36px}
        }
    </style>
</head>
<body>
<div class="page">
    <div class="shell">
        <div class="hero">
            <h1>Jake Console</h1>
            <p>Internal AI architect console</p>
        </div>

        <div class="content">
            <div class="stack">
                <section class="card">
                    <h2>Ask Jake</h2>

                    <div class="form-grid">
                        <div class="form-row-2">
                            <div>
                                <label for="request_title"><strong>Request Title</strong></label>
                                <input id="request_title" type="text" placeholder="Example: Thin test_finalize_v2 controller">
                            </div>
                            <div>
                                <label for="request_type"><strong>Request Type</strong></label>
                                <select id="request_type">
                                    <option value="bugfix">Bugfix</option>
                                    <option value="feature">Feature</option>
                                    <option value="review">Review</option>
                                    <option value="investigation">Investigation</option>
                                    <option value="cleanup">Cleanup</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label for="request_body"><strong>Request</strong></label>
                            <textarea id="request_body" placeholder="Describe the issue, target files, constraints, and what Jake should inspect first."></textarea>
                        </div>
                    </div>

                    <div class="actions">
                        <button class="btn btn-primary" type="button" id="btn_save_request">Save Request</button>
                        <button class="btn btn-secondary" type="button" id="btn_stub_chat">Run Jake Analysis</button>
                    </div>

                    <div class="panel-note">
                        V1 is read-only and orchestration-first. You keep full manual control of editor changes, SQL writes, and deployment.
                    </div>
                </section>

                <section class="card">
                    <h2>Access Check</h2>
                    <p class="ok">OK — authenticated as admin.</p>

                    <div class="meta">
                        <div class="meta-row">
                            <div class="meta-label">User ID</div>
                            <div><?= h((string)($user['id'] ?? '')) ?></div>
                        </div>
                        <div class="meta-row">
                            <div class="meta-label">Name</div>
                            <div><?= h((string)($user['name'] ?? '')) ?></div>
                        </div>
                        <div class="meta-row">
                            <div class="meta-label">Role</div>
                            <div><?= h($role) ?></div>
                        </div>
                    </div>
                </section>

                <section class="card">
                    <h2>Recent Jake Requests</h2>

                    <?php if (!$hasRequestsTable): ?>
                        <p class="muted">Table <code>ai_jake_requests</code> not found yet.</p>
                    <?php elseif (!$recentRequests): ?>
                        <p class="muted">No Jake requests found yet.</p>
                    <?php else: ?>
                        <div class="list">
                            <?php foreach ($recentRequests as $row): ?>
                                <div class="list-item">
                                    <div class="list-title">#<?= (int)$row['id'] ?> — <?= h((string)$row['request_title']) ?></div>
                                    <div>
                                        <span class="pill"><?= h((string)$row['request_type']) ?></span>
                                        <span class="pill"><?= h((string)$row['status']) ?></span>
                                    </div>
                                    <div class="panel-note">
                                        Created: <?= h((string)$row['created_at']) ?><br>
                                        Updated: <?= h((string)$row['updated_at']) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <div class="stack">
                <section class="card">
                    <h2>SSOT Snapshot</h2>

                    <?php if (!$hasSsotTable): ?>
                        <p class="muted">Table <code>ai_ssot_snapshots</code> not found yet.</p>
                    <?php elseif (!$latestSsot): ?>
                        <p class="muted">No SSOT snapshot found yet.</p>
                    <?php else: ?>
                        <div class="grid-3">
                            <div class="mini">
                                <div class="mini-label">Version</div>
                                <div class="mini-value"><?= h((string)($latestSsot['ssot_version'] ?? '')) ?></div>
                            </div>
                            <div class="mini">
                                <div class="mini-label">Status</div>
                                <div class="mini-value"><?= h((string)($latestSsot['status'] ?? '')) ?></div>
                            </div>
                            <div class="mini">
                                <div class="mini-label">Created</div>
                                <div class="mini-value" style="font-size:15px"><?= h((string)($latestSsot['created_at'] ?? '')) ?></div>
                            </div>
                        </div>

                        <div style="height:14px"></div>

                        <div class="meta">
                            <div class="meta-row">
                                <div class="meta-label">Title</div>
                                <div><?= h((string)($latestSsot['title'] ?? '')) ?></div>
                            </div>
                            <div class="meta-row">
                                <div class="meta-label">Summary</div>
                                <div><?= nl2br(h((string)($latestSsot['summary_text'] ?? ''))) ?></div>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="card">
                    <h2>File Tools</h2>
                    <div class="form-grid">
                        <div>
                            <label for="file_path"><strong>Project File Path</strong></label>
                            <input id="file_path" type="text" placeholder="src/courseware_progression_v2.php">
                        </div>
                        <div class="actions">
                            <button class="btn btn-secondary" type="button" id="btn_read_file">Read File</button>
                            <button class="btn btn-secondary" type="button" id="btn_list_unused_stub">Unused Scan</button>
                        </div>
                    </div>
                    <div class="panel-note">
                        Intended for read-only inspection and fast copy/paste workflow.
                    </div>
                </section>

                <section class="card">
                    <h2>DB Tools</h2>
                    <div class="form-grid">
                        <div>
                            <label for="db_query"><strong>Safe Read-Only SQL</strong></label>
                            <textarea id="db_query" style="min-height:140px" placeholder="SELECT * FROM ai_ssot_snapshots ORDER BY id DESC LIMIT 5"></textarea>
                        </div>
                        <div class="actions">
                            <button class="btn btn-secondary" type="button" id="btn_run_sql">Run Read Query</button>
                        </div>
                    </div>
                    <div class="panel-note">
                        Read-only diagnostics only. No write queries.
                    </div>
                </section>

                <section class="card">
                    <h2>Response Panel</h2>
                    <div id="response_panel" class="codebox">Jake Console V1 shell ready.</div>
                </section>
            </div>
        </div>
    </div>
</div>

<script>
(function () {

    const API = '/admin/api/ai_jake_console_action.php';
    const responsePanel = document.getElementById('response_panel');

    function setResponse(text) {
        responsePanel.textContent = text;
    }

    async function callAPI(payload) {
        setResponse('Loading...');

        try {
            const res = await fetch(API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const data = await res.json();

            if (!data.ok) {
                setResponse('ERROR:\n' + data.error);
                return null;
            }

            return data;

        } catch (e) {
            setResponse('FETCH ERROR:\n' + e.message);
            return null;
        }
    }

    // =========================
    // SAVE REQUEST
    // =========================
    document.getElementById('btn_save_request').addEventListener('click', async function () {

        const prompt =
            'TITLE: ' + document.getElementById('request_title').value + '\n\n' +
            'TYPE: ' + document.getElementById('request_type').value + '\n\n' +
            document.getElementById('request_body').value;

        const data = await callAPI({
            action: 'save_request',
            prompt: prompt
        });

        if (!data) return;

        setResponse(
            'Saved.\n\nRequest ID: ' + data.request_id
        );
    });

    // =========================
    // JAKE THINK (stub)
    // =========================
    document.getElementById('btn_stub_chat').addEventListener('click', async function () {

        const prompt = document.getElementById('request_body').value;

        const data = await callAPI({
            action: 'jake_think',
            prompt: prompt
        });

        if (!data) return;

        setResponse(
            'JAKE RESPONSE:\n\n' + data.response
        );
    });

    // =========================
    // READ FILE
    // =========================
    document.getElementById('btn_read_file').addEventListener('click', async function () {

        const path = document.getElementById('file_path').value;

        const data = await callAPI({
            action: 'read_file',
            path: path
        });

        if (!data) return;

        setResponse(
            'FILE: ' + data.path + '\n\n' + data.content
        );
    });

    // =========================
    // RUN SQL
    // =========================
    document.getElementById('btn_run_sql').addEventListener('click', async function () {

        const query = document.getElementById('db_query').value;

        const data = await callAPI({
            action: 'run_sql_read',
            query: query
        });

        if (!data) return;

        setResponse(
            'ROWS: ' + data.count + '\n\n' +
            JSON.stringify(data.rows, null, 2)
        );
    });

})();
</script>
</body>
</html>