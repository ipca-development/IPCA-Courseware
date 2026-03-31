<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

echo 'LAYOUT LOAD TEST<br>';
require_once __DIR__ . '/../../src/layout.php';
echo 'LAYOUT OK<br>';
exit;


cw_require_admin();

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

$recentArtifacts = array();
try {
    $stmt = $pdo->query("
        SELECT
            id,
            request_id,
            run_id,
            title,
            target_path,
            output_mode,
            created_at
        FROM ai_jake_artifacts
        ORDER BY id DESC
        LIMIT 20
    ");
    $recentArtifacts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
} catch (Throwable $e) {
    $recentArtifacts = array();
}

cw_header('Jake Console');
?>
<style>
  .jake-shell{
    display:flex;
    flex-direction:column;
    gap:22px;
  }

  .hero-card{padding:26px 28px}
  .hero-eyebrow{
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.14em;
    color:#7a8aa2;
    font-weight:700;
    margin-bottom:10px;
  }
  .hero-title{
    margin:0;
    font-size:32px;
    line-height:1.02;
    letter-spacing:-0.04em;
    color:#152235;
    font-weight:800;
  }
  .hero-sub{
    margin-top:12px;
    font-size:15px;
    color:#6f7f95;
    max-width:980px;
    line-height:1.55;
  }

  .console-grid{
    display:grid;
    grid-template-columns:minmax(0, 1.45fr) minmax(380px, .95fr);
    gap:18px;
    align-items:start;
  }

  .console-card{
    padding:0;
    overflow:hidden;
  }

  .console-head{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:14px;
    padding:20px 22px 16px;
    border-bottom:1px solid rgba(15,23,42,0.06);
  }

  .console-title{
    margin:0;
    font-size:22px;
    line-height:1.05;
    letter-spacing:-0.03em;
    color:#152235;
    font-weight:800;
  }

  .console-sub{
    margin-top:8px;
    font-size:14px;
    line-height:1.5;
    color:#728198;
  }

  .status-pill{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:34px;
    padding:0 12px;
    border-radius:999px;
    font-size:11px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.1em;
    white-space:nowrap;
    background:#edf2ff;
    color:#2949a8;
  }

  .chat-shell{
    display:flex;
    flex-direction:column;
    min-height:72vh;
    background:#f8fafd;
  }

  .chat-scroll{
    flex:1 1 auto;
    padding:20px 18px 12px;
    overflow:auto;
    display:flex;
    flex-direction:column;
    gap:12px;
    min-height:420px;
  }

  .chat-empty{
    padding:18px;
    border-radius:16px;
    border:1px dashed rgba(15,23,42,0.12);
    background:linear-gradient(180deg,#ffffff 0%,#fbfcfe 100%);
    color:#728198;
    font-size:14px;
  }

  .bubble-row{
    display:flex;
  }
  .bubble-row.user{
    justify-content:flex-end;
  }
  .bubble-row.jake{
    justify-content:flex-start;
  }

  .bubble{
    max-width:min(82%, 760px);
    padding:14px 16px;
    border-radius:20px;
    white-space:pre-wrap;
    line-height:1.48;
    font-size:14px;
    box-shadow:0 8px 18px rgba(15,23,42,0.05);
    border:1px solid rgba(15,23,42,0.05);
  }

  .bubble.user{
    background:linear-gradient(135deg,#233b8f,#3d66e0);
    color:#fff;
    border-bottom-right-radius:8px;
  }

  .bubble.jake{
    background:#eef2f7;
    color:#152235;
    border-bottom-left-radius:8px;
  }

  .bubble-meta{
    margin-top:8px;
    font-size:11px;
    opacity:.72;
    white-space:normal;
  }

  .chat-input-wrap{
    position:sticky;
    bottom:0;
    background:#fff;
    border-top:1px solid rgba(15,23,42,0.08);
    padding:14px 16px 16px;
  }

  .chat-input-grid{
    display:grid;
    grid-template-columns:180px minmax(0, 1fr) 120px;
    gap:12px;
    align-items:end;
  }

  .field-label{
    display:block;
    margin-bottom:7px;
    font-size:12px;
    font-weight:700;
    letter-spacing:.08em;
    text-transform:uppercase;
    color:#7b8ba3;
  }

  .ui-input,
  .ui-select,
  .ui-textarea,
  .ui-button{
    width:100%;
    font:inherit;
    border-radius:14px;
    border:1px solid rgba(15,23,42,0.10);
    background:#fff;
    color:#152235;
    box-sizing:border-box;
  }

  .ui-input,
  .ui-select{
    height:48px;
    padding:0 14px;
  }

  .ui-textarea{
    min-height:48px;
    max-height:180px;
    padding:12px 14px;
    resize:vertical;
    line-height:1.45;
  }

  .ui-button{
    height:48px;
    border:none;
    cursor:pointer;
    font-weight:700;
    color:#fff;
    background:linear-gradient(135deg,#233b8f,#3d66e0);
    box-shadow:0 10px 20px rgba(35,59,143,0.18);
  }

  .ui-button.secondary{
    color:#152235;
    background:#eef2f7;
    box-shadow:none;
    border:1px solid rgba(15,23,42,0.08);
  }

  .right-stack{
    display:flex;
    flex-direction:column;
    gap:18px;
    min-width:0;
  }

  .panel-body{
    padding:18px 20px 20px;
  }

  .conversation-list{
    display:flex;
    flex-direction:column;
    gap:10px;
    max-height:220px;
    overflow:auto;
  }

  .conversation-item{
    padding:12px 14px;
    border-radius:16px;
    background:#f8fafd;
    border:1px solid rgba(15,23,42,0.05);
    cursor:pointer;
  }

  .conversation-item:hover,
  .conversation-item.active{
    background:#edf2f8;
  }

  .conversation-subject{
    font-size:14px;
    font-weight:700;
    color:#152235;
    line-height:1.3;
  }

  .conversation-meta{
    margin-top:6px;
    font-size:12px;
    color:#728198;
    line-height:1.45;
  }

  .artifact-list{
    display:flex;
    flex-direction:column;
    gap:10px;
    max-height:260px;
    overflow:auto;
  }

  .artifact-item{
    padding:12px 14px;
    border-radius:16px;
    background:#f8fafd;
    border:1px solid rgba(15,23,42,0.05);
    cursor:pointer;
  }

  .artifact-item:hover,
  .artifact-item.active{
    background:#edf2f8;
  }

  .artifact-title{
    font-size:14px;
    font-weight:700;
    color:#152235;
    line-height:1.35;
  }

  .artifact-meta{
    margin-top:6px;
    font-size:12px;
    color:#728198;
    line-height:1.45;
  }

  .artifact-viewer{
    background:#0f172a;
    color:#d9e3f0;
    border-radius:16px;
    padding:16px 18px;
    min-height:240px;
    max-height:46vh;
    overflow:auto;
    white-space:pre-wrap;
    font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
    font-size:13px;
    line-height:1.55;
  }

  .mini-actions{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    margin-top:14px;
  }

  .mini-action{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:40px;
    padding:0 14px;
    border-radius:12px;
    text-decoration:none;
    font-size:14px;
    font-weight:700;
    color:#152235;
    background:#f4f7fb;
    border:1px solid rgba(15,23,42,0.08);
    cursor:pointer;
  }

  .mini-action:hover{
    background:#edf2f8;
  }

  .empty-premium{
    padding:18px;
    border-radius:16px;
    border:1px dashed rgba(15,23,42,0.12);
    background:linear-gradient(180deg,#ffffff 0%,#fbfcfe 100%);
    color:#728198;
    font-size:14px;
  }

  @media (max-width: 1200px){
    .console-grid{
      grid-template-columns:1fr;
    }
    .artifact-viewer{
      max-height:34vh;
    }
  }

  @media (max-width: 820px){
    .chat-shell{
      min-height:calc(100vh - 230px);
    }

    .chat-input-grid{
      grid-template-columns:1fr;
      align-items:stretch;
    }

    .ui-input,
    .ui-select,
    .ui-button{
      height:48px;
    }

    .chat-input-wrap{
      padding-bottom:calc(14px + env(safe-area-inset-bottom));
    }

    .chat-scroll{
      min-height:300px;
      padding-bottom:14px;
    }

    .bubble{
      max-width:92%;
    }
  }
</style>

<div class="jake-shell">

  <div class="card hero-card">
    <div class="hero-eyebrow">Admin Workspace</div>
    <h2 class="hero-title">Jake Console</h2>
    <div class="hero-sub">
      Natural-language architect console for IPCA. You chat with Jake, Jake orchestrates the reasoning, and artifacts stay available in a dedicated engineering panel for inspection and copy/paste into your editor.
    </div>
  </div>

  <div class="console-grid">

    <div class="card console-card">
      <div class="console-head">
        <div>
          <h3 class="console-title">Chat with Jake</h3>
          <div class="console-sub">
            Ask in normal language. Jake replies conversationally and keeps the engineering layer out of the chat stream unless you want to inspect it.
          </div>
        </div>
        <div class="status-pill">Live Conversation</div>
      </div>

      <div class="chat-shell">
        <div class="chat-scroll" id="messages">
          <div class="chat-empty">Start a conversation with Jake. Your message appears on the right, Jake replies on the left, and technical artifacts remain selectable in the panel next to the chat.</div>
        </div>

        <div class="chat-input-wrap">
          <div class="chat-input-grid">
            <div>
              <label class="field-label" for="request_type">Request Type</label>
              <select id="request_type" class="ui-select">
                <option value="">Auto Detect</option>
                <option value="bugfix">Bugfix</option>
                <option value="feature">Feature</option>
                <option value="review">Review</option>
                <option value="investigation">Investigation</option>
                <option value="cleanup">Cleanup</option>
              </select>
            </div>

            <div>
              <label class="field-label" for="msg_input">Message</label>
              <textarea id="msg_input" class="ui-textarea" rows="2" placeholder="Ask Jake in normal language..."></textarea>
            </div>

            <div>
              <label class="field-label">&nbsp;</label>
              <button id="send_btn" class="ui-button" type="button">Send</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="right-stack">

      <div class="card console-card">
        <div class="console-head">
          <div>
            <h3 class="console-title">Conversations</h3>
            <div class="console-sub">
              Recent Jake threads. Select one to reload the conversation history.
            </div>
          </div>
          <div class="status-pill">History</div>
        </div>
        <div class="panel-body">
          <div class="conversation-list" id="conv_list">
            <div class="empty-premium">No conversations loaded yet.</div>
          </div>
        </div>
      </div>

      <div class="card console-card">
        <div class="console-head">
          <div>
            <h3 class="console-title">Artifacts</h3>
            <div class="console-sub">
              Recent engineering outputs are selectable here, separate from the normal chat stream.
            </div>
          </div>
          <div class="status-pill">Selectable</div>
        </div>
        <div class="panel-body">
          <div class="artifact-list" id="artifact_list">
            <?php if (!$recentArtifacts): ?>
              <div class="empty-premium">No artifacts found yet.</div>
            <?php else: ?>
              <?php foreach ($recentArtifacts as $artifact): ?>
                <div
                  class="artifact-item"
                  data-artifact-id="<?= (int)$artifact['id'] ?>"
                >
                  <div class="artifact-title"><?= h((string)$artifact['title']) ?></div>
                  <div class="artifact-meta">
                    Artifact #<?= (int)$artifact['id'] ?>
                    <?php if (!empty($artifact['target_path'])): ?>
                      · <?= h((string)$artifact['target_path']) ?>
                    <?php endif; ?>
                    <br>
                    <?= h((string)$artifact['output_mode']) ?> · <?= h((string)$artifact['created_at']) ?>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <div class="mini-actions">
            <button type="button" class="mini-action" id="refresh_conversations_btn">Refresh Conversations</button>
          </div>

          <div style="height:14px"></div>

          <div class="artifact-viewer" id="artifact_viewer">Select an artifact to view its contents here.</div>
        </div>
      </div>

    </div>

  </div>

</div>

<script>
(function () {
    const API = window.location.origin + '/admin/api/ai_jake_console_action.php';

    let currentConversation = null;

    async function callAPI(payload) {
        const res = await fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const text = await res.text();

        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            throw new Error('Invalid JSON response: ' + text);
        }

        if (!data.ok) {
            throw new Error(data.error || 'Unknown API error');
        }

        return data;
    }

    function escapeHtml(str) {
        return String(str)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function renderMessages(messages) {
        const box = document.getElementById('messages');
        box.innerHTML = '';

        if (!messages || !messages.length) {
            box.innerHTML = '<div class="chat-empty">No messages in this conversation yet.</div>';
            return;
        }

        messages.forEach(function (m) {
            const role = m.role === 'user' ? 'user' : 'jake';
            const row = document.createElement('div');
            row.className = 'bubble-row ' + role;

            const bubble = document.createElement('div');
            bubble.className = 'bubble ' + role;

            const safeText = escapeHtml(m.message_text || '');
            const meta = m.created_at ? '<div class="bubble-meta">' + escapeHtml(m.created_at) + '</div>' : '';

            bubble.innerHTML = safeText + meta;
            row.appendChild(bubble);
            box.appendChild(row);
        });

        box.scrollTop = box.scrollHeight;
    }

    function setConversationList(conversations) {
        const list = document.getElementById('conv_list');
        list.innerHTML = '';

        if (!conversations || !conversations.length) {
            list.innerHTML = '<div class="empty-premium">No conversations found yet.</div>';
            return;
        }

        conversations.forEach(function (c) {
            const div = document.createElement('div');
            div.className = 'conversation-item' + ((currentConversation && Number(currentConversation) === Number(c.id)) ? ' active' : '');

            div.innerHTML =
                '<div class="conversation-subject">' + escapeHtml(c.subject || 'Untitled conversation') + '</div>' +
                '<div class="conversation-meta">' + escapeHtml(c.updated_at || '') + '</div>';

            div.addEventListener('click', function () {
                loadConversation(c.id);
            });

            list.appendChild(div);
        });
    }

    async function loadConversations() {
        const data = await callAPI({
            action: 'list_conversations'
        });

        setConversationList(data.conversations || []);
    }

    async function loadConversation(id) {
        currentConversation = id;

        const data = await callAPI({
            action: 'get_conversation_messages',
            conversation_id: id
        });

        renderMessages(data.messages || []);
        await loadConversations();
    }

    async function sendMessage() {
        const input = document.getElementById('msg_input');
        const requestType = document.getElementById('request_type').value;
        const messageText = input.value.trim();

        if (!messageText) return;

        const payload = {
            action: 'send_message',
            message_text: messageText
        };

        if (currentConversation) {
            payload.conversation_id = currentConversation;
        }

        if (requestType !== '') {
            payload.request_type = requestType;
        }

        input.value = '';

        const data = await callAPI(payload);

        if (!currentConversation) {
            currentConversation = data.conversation_id;
        }

        await loadConversation(currentConversation);
        await loadConversations();
    }

    async function readArtifact(artifactId) {
        const data = await callAPI({
            action: 'read_artifact',
            artifact_id: artifactId
        });

        const a = data.artifact || {};
        const viewer = document.getElementById('artifact_viewer');

        viewer.textContent =
            'ARTIFACT ID: ' + (a.id || '') + '\n' +
            'REQUEST ID: ' + (a.request_id || '') + '\n' +
            'RUN ID: ' + (a.run_id || '') + '\n' +
            'TITLE: ' + (a.title || '') + '\n' +
            'TARGET PATH: ' + (a.target_path || '') + '\n' +
            'OUTPUT MODE: ' + (a.output_mode || '') + '\n' +
            'CREATED BY: ' + (a.created_by_agent || '') + '\n' +
            'APPROVED BY: ' + (a.approved_by_agent || '') + '\n' +
            '\n' +
            (a.content || '');

        document.querySelectorAll('.artifact-item').forEach(function (el) {
            el.classList.remove('active');
        });

        const active = document.querySelector('.artifact-item[data-artifact-id="' + artifactId + '"]');
        if (active) {
            active.classList.add('active');
        }
    }

    document.getElementById('send_btn').addEventListener('click', function () {
        sendMessage().catch(function (err) {
            alert(err.message || 'Failed to send message');
        });
    });

    document.getElementById('msg_input').addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage().catch(function (err) {
                alert(err.message || 'Failed to send message');
            });
        }
    });

    document.getElementById('refresh_conversations_btn').addEventListener('click', function () {
        loadConversations().catch(function (err) {
            alert(err.message || 'Failed to refresh conversations');
        });
    });

    document.querySelectorAll('.artifact-item').forEach(function (el) {
        el.addEventListener('click', function () {
            const artifactId = this.getAttribute('data-artifact-id');
            if (!artifactId) return;

            readArtifact(parseInt(artifactId, 10)).catch(function (err) {
                alert(err.message || 'Failed to read artifact');
            });
        });
    });

    loadConversations().catch(function () {});
})();
</script>

<?php cw_footer(); ?>