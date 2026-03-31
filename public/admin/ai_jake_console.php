<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

cw_require_admin();

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
        LIMIT 50
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
    padding-bottom:120px;
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
    position:relative;
  }

  .chat-scroll{
    flex:1 1 auto;
    padding:20px 18px 18px;
    overflow:auto;
    display:flex;
    flex-direction:column;
    gap:12px;
    min-height:420px;
    max-height:72vh;
    scroll-behavior:smooth;
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
    background:linear-gradient(135deg,#1e3c72,#2a5298);
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

  /* Jake message formatting */
  .msg-section-title{
    font-weight:800;
    color:#152235;
    margin:6px 0 0;
  }

  .msg-section-gap{
    height:10px;
  }

  .msg-list{
    margin:6px 0 14px 20px;
    padding-left:18px;
  }

  .msg-list li{
    margin:5px 0;
    line-height:1.5;
  }

  .msg-paragraph{
    line-height:1.55;
    margin:0 0 10px;
  }

  .msg-spacer{
    height:10px;
  }
  .inline-artifact-link{
  display:inline-block;
  margin-left:4px;
  padding:2px 8px;
  border-radius:999px;
  background:#dbe7ff;
  color:#1f4db8;
  font-weight:700;
  text-decoration:none;
  border:1px solid rgba(31,77,184,0.15);
}

.inline-artifact-link:hover{
  background:#cfe0ff;
}
	
  .typing-wrap{
    display:flex;
    justify-content:flex-start;
  }

  .typing-bubble{
    background:#eef2f7;
    color:#152235;
    border:1px solid rgba(15,23,42,0.05);
    border-radius:20px;
    border-bottom-left-radius:8px;
    padding:12px 16px;
    box-shadow:0 8px 18px rgba(15,23,42,0.05);
  }

  .typing-dots{
    display:inline-flex;
    align-items:center;
    gap:6px;
    min-width:38px;
  }

  .typing-dots span{
    width:7px;
    height:7px;
    border-radius:999px;
    background:#7c8aa3;
    display:inline-block;
    animation:typingBlink 1.2s infinite ease-in-out;
  }
  .typing-dots span:nth-child(2){animation-delay:.15s}
  .typing-dots span:nth-child(3){animation-delay:.3s}

  @keyframes typingBlink{
    0%, 80%, 100%{opacity:.35; transform:translateY(0)}
    40%{opacity:1; transform:translateY(-2px)}
  }

  .chat-composer-float{
    position:fixed;
    left:calc(280px + 24px);
    right:24px;
    bottom:18px;
    z-index:50;
    pointer-events:none;
  }

  .chat-composer{
    max-width:980px;
    margin:0 auto;
    pointer-events:auto;
    background:rgba(255,255,255,0.62);
    backdrop-filter:blur(16px) saturate(160%);
    -webkit-backdrop-filter:blur(16px) saturate(160%);
    border:1px solid rgba(255,255,255,0.35);
    box-shadow:0 20px 50px rgba(15,23,42,0.12);
    border-radius:24px;
    padding:12px;
  }

  .chat-input-grid{
    display:grid;
    grid-template-columns:minmax(0, 1fr) 56px;
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
    height:48px;
    min-height:48px;
    max-height:48px;
    padding:13px 14px 11px;
    resize:none;
    line-height:1.35;
    overflow:auto;
  }

  .ui-button{
    height:48px;
    border:none;
    cursor:pointer;
    font-weight:700;
    color:#fff;
    background:linear-gradient(135deg,#1e3c72,#2a5298);
    box-shadow:0 10px 20px rgba(30,60,114,0.24);
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:16px;
  }

  .ui-button svg{
    width:20px;
    height:20px;
    display:block;
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
    max-height:240px;
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
    max-height:420px;
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

  .artifact-badge-new{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  margin-left:8px;
  padding:2px 8px;
  font-size:10px;
  font-weight:800;
  letter-spacing:.08em;
  text-transform:uppercase;
  border-radius:999px;
  background:linear-gradient(135deg,#22c55e,#16a34a);
  color:#fff;
}	
	
  .artifact-meta{
    margin-top:6px;
    font-size:12px;
    color:#728198;
    line-height:1.45;
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

  .artifact-modal{
    position:fixed;
    inset:0;
    z-index:120;
    display:none;
    align-items:center;
    justify-content:center;
    padding:24px;
    background:rgba(15,23,42,0.46);
    backdrop-filter:blur(6px);
    -webkit-backdrop-filter:blur(6px);
  }

  .artifact-modal.is-open{
    display:flex;
  }

  .artifact-modal-panel{
    width:min(1180px, 96vw);
    height:min(86vh, 920px);
    background:#fff;
    border-radius:22px;
    box-shadow:0 28px 70px rgba(15,23,42,0.25);
    overflow:hidden;
    display:flex;
    flex-direction:column;
  }

  .artifact-modal-head{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:16px;
    padding:20px 22px 16px;
    border-bottom:1px solid rgba(15,23,42,0.08);
  }

  .artifact-modal-title{
    margin:0;
    font-size:20px;
    line-height:1.1;
    letter-spacing:-0.02em;
    color:#152235;
    font-weight:800;
  }

  .artifact-modal-sub{
    margin-top:8px;
    font-size:13px;
    line-height:1.45;
    color:#728198;
    white-space:pre-wrap;
  }

  .artifact-modal-close{
    width:40px;
    height:40px;
    border:none;
    border-radius:12px;
    background:#eef2f7;
    color:#152235;
    font-size:20px;
    line-height:1;
    cursor:pointer;
    flex:0 0 auto;
  }

  .artifact-modal-body{
    flex:1 1 auto;
    overflow:auto;
    background:#0f172a;
    color:#d9e3f0;
    padding:18px 20px;
    white-space:pre-wrap;
    font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
    font-size:13px;
    line-height:1.55;
  }

  @media (max-width: 1200px){
    .console-grid{
      grid-template-columns:1fr;
    }

    .chat-composer-float{
      left:24px;
    }
  }

  @media (max-width: 820px){
    .jake-shell{
      padding-bottom:122px;
    }

    .chat-shell{
      min-height:calc(100vh - 290px);
    }

    .chat-scroll{
      min-height:300px;
      max-height:none;
      padding-bottom:18px;
    }

    .bubble{
      max-width:92%;
    }

    .chat-composer-float{
      left:12px;
      right:12px;
      bottom:12px;
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

    .ui-textarea{
      height:48px;
      min-height:48px;
      max-height:48px;
    }

    .artifact-modal{
      padding:10px;
    }

    .artifact-modal-panel{
      width:100%;
      height:92vh;
      border-radius:18px;
    }
  }
</style>

<div class="jake-shell">

  <div class="card hero-card">
    <div class="hero-eyebrow">Admin Workspace</div>
    <h2 class="hero-title">Jake Console</h2>
  </div>

  <div class="console-grid">

    <div class="card console-card">
      <div class="console-head">
        <div>
          <h3 class="console-title">Chat with Jake</h3>
        </div>
        <div class="status-pill">Live Conversation</div>
      </div>

      <div class="chat-shell">
        <div class="chat-scroll" id="messages">
          <div class="chat-empty">Start a conversation with Jake.</div>
        </div>
      </div>
    </div>

    <div class="right-stack">

      <div class="card console-card">
        <div class="console-head">
          <div>
            <h3 class="console-title">My conversations</h3>
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
          </div>
          <div class="status-pill">Selectable</div>
        </div>
        <div class="panel-body">
          <div class="artifact-list" id="artifact_list">
            <?php if (!$recentArtifacts): ?>
              <div class="empty-premium">No artifacts found yet.</div>
            <?php else: ?>
              <?php foreach ($recentArtifacts as $artifact): ?>
                <div class="artifact-item" data-artifact-id="<?= (int)$artifact['id'] ?>">
                  <?php
$isNew = false;
if (!empty($artifact['created_at'])) {
    $createdTs = strtotime($artifact['created_at']);
    if ($createdTs !== false && (time() - $createdTs) <= 60) {
        $isNew = true;
    }
}
?>

<div class="artifact-title">
    <?= h((string)$artifact['title']) ?>
    <?php if ($isNew): ?>
        <span class="artifact-badge-new">New</span>
    <?php endif; ?>
</div>
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
            <button type="button" class="mini-action" id="refresh_artifacts_btn">Refresh Artifacts</button>
          </div>
        </div>
      </div>

    </div>

  </div>

  <div class="chat-composer-float">
    <div class="chat-composer">
      <div class="chat-input-grid">
        <div>
          <label class="field-label" for="msg_input">Message</label>
          <textarea id="msg_input" class="ui-textarea" rows="1" placeholder="Ask Jake..."></textarea>
        </div>

        <div>
          <label class="field-label">&nbsp;</label>
          <button id="send_btn" class="ui-button" type="button" aria-label="Send">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <path d="M4 12L20 4L13 20L11 13L4 12Z" fill="currentColor"></path>
            </svg>
          </button>
        </div>
      </div>
    </div>
  </div>

</div>

<div class="artifact-modal" id="artifact_modal" aria-hidden="true">
  <div class="artifact-modal-panel">
    <div class="artifact-modal-head">
      <div>
        <h3 class="artifact-modal-title" id="artifact_modal_title">Artifact</h3>
        <div class="artifact-modal-sub" id="artifact_modal_meta"></div>
      </div>
      <button type="button" class="artifact-modal-close" id="artifact_modal_close" aria-label="Close">×</button>
    </div>
    <div class="artifact-modal-body" id="artifact_modal_body">Select an artifact to inspect its content.</div>
  </div>
</div>

<script>
(function () {
    const API = window.location.origin + '/admin/api/ai_jake_console_action.php';

    let currentConversation = null;
    let typingNode = null;

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

	
function renderArtifactItem(a) {
    const createdAt = a.created_at || '';

    const lastSeenId = parseInt(localStorage.getItem('last_seen_artifact_id') || '0', 10);
    const isNew = a.id > lastSeenId;

    return `
        <div class="artifact-item" data-artifact-id="${a.id}">
            <div class="artifact-title">
                ${escapeHtml(a.title || 'Artifact')}
                ${isNew ? '<span class="artifact-badge-new">New</span>' : ''}
            </div>
            <div class="artifact-meta">
                Artifact #${a.id}
                ${a.target_path ? ' · ' + escapeHtml(a.target_path) : ''}
                <br>
                ${escapeHtml(a.output_mode || '')} · ${escapeHtml(createdAt)}
            </div>
        </div>
    `;
}
	
	
    function chatBox() {
        return document.getElementById('messages');
    }

    function scrollChatToBottom() {
        const box = chatBox();
        requestAnimationFrame(function () {
            box.scrollTop = box.scrollHeight;
        });
    }

function formatJakeMessage(text) {
    const safe = escapeHtml(text || '');
    const lines = safe.split(/\r?\n/);

    let html = '';
    let inList = false;

    function closeListIfOpen() {
        if (inList) {
            html += '</ul>';
            inList = false;
        }
    }

    function looksLikeTitle(line) {
        if (!line) return false;
        if (line.length > 70) return false;
        if (line.endsWith('.') || line.endsWith(':') || line.endsWith('?') || line.endsWith('!')) return false;
        if (line.startsWith('- ')) return false;
        if (/^#{1,6}\s+/.test(line)) return true;
        if (/^\*\*(.+?)\*\*$/.test(line)) return true;

        const words = line.split(/\s+/).filter(Boolean);
        if (words.length >= 2 && words.length <= 6) {
            const titleish = words.filter(function (w) {
                return /^[A-Z][A-Za-z0-9'`-]*$/.test(w);
            }).length;
            if (titleish >= Math.max(2, words.length - 1)) {
                return true;
            }
        }

        return false;
    }

    function injectArtifactLinks(line) {
        return line.replace(
            /Artifact ID:\s*([0-9]+)/g,
            'Artifact ID: <a href="#" class="inline-artifact-link" data-artifact-id="$1">#$1</a>'
        );
    }

    for (let i = 0; i < lines.length; i++) {
        const rawLine = lines[i];
        const line = rawLine.trim();

        if (line === '') {
            closeListIfOpen();
            html += '<div class="msg-spacer"></div>';
            continue;
        }

        const boldMatch = line.match(/^\*\*(.+?)\*\*$/);
        if (boldMatch) {
            closeListIfOpen();
            html += '<div class="msg-section-title">' + injectArtifactLinks(boldMatch[1]) + '</div>';
            html += '<div class="msg-section-gap"></div>';
            continue;
        }

        const mdHeadingMatch = line.match(/^#{1,6}\s+(.+)$/);
        if (mdHeadingMatch) {
            closeListIfOpen();
            html += '<div class="msg-section-title">' + injectArtifactLinks(mdHeadingMatch[1]) + '</div>';
            html += '<div class="msg-section-gap"></div>';
            continue;
        }

        if (looksLikeTitle(line)) {
            closeListIfOpen();
            html += '<div class="msg-section-title">' + injectArtifactLinks(line) + '</div>';
            html += '<div class="msg-section-gap"></div>';
            continue;
        }

        if (line.startsWith('- ')) {
            if (!inList) {
                html += '<ul class="msg-list">';
                inList = true;
            }
            html += '<li>' + injectArtifactLinks(line.substring(2)) + '</li>';
            continue;
        }

        closeListIfOpen();
        html += '<div class="msg-paragraph">' + injectArtifactLinks(line) + '</div>';
    }

    closeListIfOpen();
    return html;
}
	
	
    function renderMessages(messages) {
        const box = chatBox();
        box.innerHTML = '';

        if (!messages || !messages.length) {
            box.innerHTML = '<div class="chat-empty">Start a conversation with Jake.</div>';
            return;
        }

        messages.forEach(function (m) {
            appendBubble(m.role === 'user' ? 'user' : 'jake', m.message_text || '', m.created_at || '', false);
        });

        scrollChatToBottom();
    }

    function appendBubble(role, text, createdAt, shouldScroll = true) {
        const box = chatBox();

        const row = document.createElement('div');
        row.className = 'bubble-row ' + role;

        const bubble = document.createElement('div');
        bubble.className = 'bubble ' + role;

        const formattedText = formatJakeMessage(text || '');
		const meta = createdAt ? '<div class="bubble-meta">' + escapeHtml(createdAt) + '</div>' : '';

		bubble.innerHTML = formattedText + meta;
		
        row.appendChild(bubble);
        box.appendChild(row);

        if (shouldScroll) {
            scrollChatToBottom();
        }

        return row;
    }

    function showTyping() {
        hideTyping();

        const box = chatBox();
        const wrap = document.createElement('div');
        wrap.className = 'typing-wrap';
        wrap.id = 'typing_indicator';

        wrap.innerHTML =
            '<div class="typing-bubble">' +
                '<div class="typing-dots"><span></span><span></span><span></span></div>' +
            '</div>';

        box.appendChild(wrap);
        typingNode = wrap;
        scrollChatToBottom();
    }

    function hideTyping() {
        if (typingNode && typingNode.parentNode) {
            typingNode.parentNode.removeChild(typingNode);
        }
        typingNode = null;
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

	    function renderArtifactList(artifacts) {
        const list = document.getElementById('artifact_list');
        list.innerHTML = '';

        if (!artifacts || !artifacts.length) {
            list.innerHTML = '<div class="empty-premium">No artifacts found yet.</div>';
            return;
        }

        artifacts.forEach(function (artifact) {
            const div = document.createElement('div');
            div.className = 'artifact-item';
            div.setAttribute('data-artifact-id', String(artifact.id));

            div.innerHTML =
                '<div class="artifact-title">' + escapeHtml(artifact.title || 'Untitled artifact') + '</div>' +
                '<div class="artifact-meta">' +
                    'Artifact #' + escapeHtml(artifact.id || '') +
                    (artifact.target_path ? ' · ' + escapeHtml(artifact.target_path) : '') +
                    '<br>' +
                    escapeHtml(artifact.output_mode || '') +
                    (artifact.review_status ? ' · ' + escapeHtml(artifact.review_status) : '') +
                    ' · ' + escapeHtml(artifact.created_at || '') +
                '</div>';

            div.addEventListener('click', function () {
                const artifactId = this.getAttribute('data-artifact-id');
                if (!artifactId) return;

                openArtifactModal(parseInt(artifactId, 10)).catch(function (err) {
                    alert(err.message || 'Failed to read artifact');
                });
            });

            list.appendChild(div);
        });
    }

    async function loadRecentArtifacts() {
        const data = await callAPI({
            action: 'list_recent_artifacts'
        });

        renderArtifactList(data.artifacts || []);
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
        const messageText = input.value.trim();

        if (!messageText) return;

        const tempTime = new Date().toLocaleString();
        appendBubble('user', messageText, tempTime, true);
        input.value = '';
        showTyping();

        const payload = {
            action: 'send_message',
            message_text: messageText
        };

        if (currentConversation) {
            payload.conversation_id = currentConversation;
        }

        try {
            const data = await callAPI(payload);

            if (!currentConversation) {
                currentConversation = data.conversation_id;
            }

            hideTyping();
            await loadConversation(currentConversation);
await loadConversations();

// 🔥 NEW: refresh artifact list
try {
    const res = await fetch(API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'list_request_artifacts', request_id: data.request_id })
    });

    const json = await res.json();

    if (json.ok && json.artifacts) {
        const list = document.getElementById('artifact_list');
        list.innerHTML = '';

        json.artifacts.forEach(function (a) {
            const wrapper = document.createElement('div');
            wrapper.innerHTML = renderArtifactItem(a);

            const el = wrapper.firstElementChild;

            el.addEventListener('click', function () {
                openArtifactModal(a.id);
            });

            list.appendChild(el);
        });
    }
} catch (e) {
    console.warn('Artifact refresh failed', e);
}
            await loadRecentArtifacts();
        } catch (err) {
            hideTyping();
            appendBubble('jake', 'Error: ' + (err.message || 'Failed to send message'), new Date().toLocaleString(), true);
        }
    }

    async function openArtifactModal(artifactId) {
        const data = await callAPI({
            action: 'read_artifact',
            artifact_id: artifactId
        });

        const a = data.artifact || {};

		localStorage.setItem('last_seen_artifact_id', String(artifactId));
		
        document.getElementById('artifact_modal_title').textContent = a.title || 'Artifact';
        document.getElementById('artifact_modal_meta').textContent =
            'Artifact ID: ' + (a.id || '') +
            ' · Request ID: ' + (a.request_id || '') +
            ' · Run ID: ' + (a.run_id || '') +
            ' · Output Mode: ' + (a.output_mode || '') +
            (a.target_path ? ' · Target Path: ' + a.target_path : '');

        document.getElementById('artifact_modal_body').textContent = a.content || '';

        document.querySelectorAll('.artifact-item').forEach(function (el) {
            el.classList.remove('active');
        });

        const active = document.querySelector('.artifact-item[data-artifact-id="' + artifactId + '"]');
        if (active) {
            active.classList.add('active');
        }

        const modal = document.getElementById('artifact_modal');
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeArtifactModal() {
        const modal = document.getElementById('artifact_modal');
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
    }

    document.getElementById('send_btn').addEventListener('click', function () {
        sendMessage();
    });

    document.getElementById('msg_input').addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

	    document.getElementById('refresh_conversations_btn').addEventListener('click', function () {
        loadConversations().catch(function (err) {
            alert(err.message || 'Failed to refresh conversations');
        });
    });

    document.getElementById('refresh_artifacts_btn').addEventListener('click', function () {
        loadRecentArtifacts().catch(function (err) {
            alert(err.message || 'Failed to refresh artifacts');
        });
    });

    document.querySelectorAll('.artifact-item').forEach(function (el) {
        el.addEventListener('click', function () {
            const artifactId = this.getAttribute('data-artifact-id');
            if (!artifactId) return;

            openArtifactModal(parseInt(artifactId, 10)).catch(function (err) {
                alert(err.message || 'Failed to read artifact');
            });
        });
    });

    document.getElementById('artifact_modal_close').addEventListener('click', closeArtifactModal);

    document.getElementById('artifact_modal').addEventListener('click', function (e) {
        if (e.target === this) {
            closeArtifactModal();
        }
    });

	document.addEventListener('click', function (e) {
    const link = e.target.closest('.inline-artifact-link');
    if (!link) return;

    e.preventDefault();

    const artifactId = parseInt(link.getAttribute('data-artifact-id') || '', 10);
    if (!artifactId) return;

    openArtifactModal(artifactId).catch(function (err) {
        alert(err.message || 'Failed to open artifact');
    });
});
	
	
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeArtifactModal();
        }
    });

    loadConversations().catch(function () {});
    loadRecentArtifacts().catch(function () {});

    setInterval(function () {
        loadRecentArtifacts().catch(function () {});
    }, 5000);
})();
</script>

<?php cw_footer(); ?>