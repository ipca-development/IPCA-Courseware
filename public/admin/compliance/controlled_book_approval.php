<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';

$versionId = isset($_GET['version_id']) ? (int)$_GET['version_id'] : 0;
$token = trim((string)($_GET['token'] ?? ''));

$cssPath = __DIR__ . '/../../../public/assets/controlled_book_editor.css';
$cssVer = is_file($cssPath) ? (string)filemtime($cssPath) : '1';

cw_header('Manual approval · List of Effective Parts');
?>
<link rel="stylesheet" href="/assets/controlled_book_editor.css?v=<?= h($cssVer) ?>">

<style>
  .cpa-root {
    max-width: 920px;
    margin: 24px auto 48px;
    padding: 0 16px;
    font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
  }
  .cpa-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 16px;
  }
  .cpa-card h1 {
    margin: 0 0 6px;
    font-size: 20px;
    color: #0f2744;
  }
  .cpa-meta {
    margin: 0 0 16px;
    color: #64748b;
    font-size: 13px;
  }
  .cpa-preview {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 16px;
    overflow: auto;
  }
  .cpa-preview .cpb-sheet {
    margin: 0 auto;
    box-shadow: none;
  }
  .cpa-form-grid {
    display: grid;
    gap: 12px;
    margin-bottom: 14px;
  }
  .cpa-form-grid label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: #334155;
  }
  .cpa-form-grid input {
    display: block;
    width: 100%;
    margin-top: 4px;
    padding: 8px 10px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 14px;
  }
  .cpa-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }
  .cpa-btn {
    appearance: none;
    border: 1px solid #cbd5e1;
    background: #fff;
    color: #0f172a;
    border-radius: 6px;
    padding: 8px 14px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
  }
  .cpa-btn-primary {
    background: #0f2744;
    border-color: #0f2744;
    color: #fff;
  }
  .cpa-status {
    margin-top: 12px;
    font-size: 13px;
    color: #64748b;
  }
  .cpa-status.is-error { color: #b91c1c; }
  .cpa-status.is-success { color: #15803d; }
  .cpa-signed-banner {
    background: #ecfdf5;
    border: 1px solid #86efac;
    color: #166534;
    border-radius: 8px;
    padding: 12px 14px;
    margin-bottom: 14px;
    font-size: 13px;
  }
</style>

<div class="cpa-root" id="cpaRoot"
     data-version-id="<?= (int)$versionId ?>"
     data-token="<?= h($token) ?>">
  <div class="cpa-card">
    <h1>Authority approval</h1>
    <p class="cpa-meta" id="cpaMeta">Loading manual details…</p>
    <div id="cpaSignedBanner" class="cpa-signed-banner" hidden>
      Authority signature recorded. This approval link can no longer be used to sign again.
    </div>
    <div class="cpa-preview" id="cpaPreview">
      <p style="margin:0;color:#64748b;">Loading List of Effective Parts…</p>
    </div>
  </div>

  <div class="cpa-card" id="cpaSignCard">
    <h2 style="margin:0 0 12px;font-size:16px;color:#0f2744;">Competent authority e-signature</h2>
    <p style="margin:0 0 14px;font-size:13px;color:#64748b;">
      Enter your name and function, then draw your signature to approve the effective parts list.
    </p>
    <div class="cpa-form-grid">
      <label>Name
        <input type="text" id="cpaName" autocomplete="name" placeholder="Full name">
      </label>
      <label>Function / title
        <input type="text" id="cpaTitle" autocomplete="organization-title" value="Competent Authority">
      </label>
    </div>
    <div class="cpb-lep-sign-canvas-wrap">
      <canvas id="cpaSignCanvas" width="640" height="160"></canvas>
    </div>
    <div class="cpa-actions">
      <button type="button" class="cpa-btn" id="cpaClear">Clear signature</button>
      <button type="button" class="cpa-btn cpa-btn-primary" id="cpaSubmit">Submit approval</button>
    </div>
    <p class="cpa-status" id="cpaStatus"></p>
  </div>
</div>

<script>
(function () {
  'use strict';
  var root = document.getElementById('cpaRoot');
  if (!root) return;
  var versionId = parseInt(root.getAttribute('data-version-id') || '0', 10);
  var token = root.getAttribute('data-token') || '';
  var apiBase = '/admin/api/controlled_book_approval_api.php';
  var previewEl = document.getElementById('cpaPreview');
  var metaEl = document.getElementById('cpaMeta');
  var statusEl = document.getElementById('cpaStatus');
  var signCard = document.getElementById('cpaSignCard');
  var signedBanner = document.getElementById('cpaSignedBanner');
  var canvas = document.getElementById('cpaSignCanvas');
  var drawing = false;

  function setStatus(msg, kind) {
    statusEl.textContent = msg || '';
    statusEl.className = 'cpa-status' + (kind ? ' is-' + kind : '');
  }

  function pos(e) {
    var rect = canvas.getBoundingClientRect();
    var clientX = e.touches ? e.touches[0].clientX : e.clientX;
    var clientY = e.touches ? e.touches[0].clientY : e.clientY;
    return {
      x: (clientX - rect.left) * (canvas.width / rect.width),
      y: (clientY - rect.top) * (canvas.height / rect.height)
    };
  }

  var ctx = canvas.getContext('2d');
  ctx.lineWidth = 2;
  ctx.lineCap = 'round';
  ctx.strokeStyle = '#0f172a';

  function clearCanvas() {
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
  }
  clearCanvas();

  canvas.addEventListener('mousedown', function (e) {
    e.preventDefault();
    drawing = true;
    var p = pos(e);
    ctx.beginPath();
    ctx.moveTo(p.x, p.y);
  });
  canvas.addEventListener('mousemove', function (e) {
    if (!drawing) return;
    e.preventDefault();
    var p = pos(e);
    ctx.lineTo(p.x, p.y);
    ctx.stroke();
  });
  canvas.addEventListener('mouseup', function () { drawing = false; });
  canvas.addEventListener('mouseleave', function () { drawing = false; });
  canvas.addEventListener('touchstart', function (e) {
    e.preventDefault();
    drawing = true;
    var p = pos(e);
    ctx.beginPath();
    ctx.moveTo(p.x, p.y);
  }, { passive: false });
  canvas.addEventListener('touchmove', function (e) {
    if (!drawing) return;
    e.preventDefault();
    var p = pos(e);
    ctx.lineTo(p.x, p.y);
    ctx.stroke();
  }, { passive: false });
  canvas.addEventListener('touchend', function () { drawing = false; });

  document.getElementById('cpaClear').addEventListener('click', clearCanvas);

  function loadPage() {
    if (versionId <= 0 || !token) {
      metaEl.textContent = 'Invalid approval link.';
      previewEl.innerHTML = '<p style="margin:0;color:#b91c1c;">Provide a valid version_id and token.</p>';
      signCard.hidden = true;
      return;
    }
    fetch(apiBase + '?action=load&version_id=' + encodeURIComponent(String(versionId)) + '&token=' + encodeURIComponent(token), {
      credentials: 'same-origin'
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.ok) throw new Error(res.error || 'Load failed');
        var v = res.version || {};
        metaEl.textContent = (v.book_key || 'Manual') + ' ' + (v.version_label || '') + ' · ' + (v.lifecycle_status || '');
        previewEl.innerHTML = res.page_html || '';
        if (res.authority_signed) {
          signedBanner.hidden = false;
          signCard.hidden = true;
        }
      })
      .catch(function (err) {
        metaEl.textContent = 'Approval link invalid or expired.';
        previewEl.innerHTML = '<p style="margin:0;color:#b91c1c;">' + (err.message || 'Could not load') + '</p>';
        signCard.hidden = true;
      });
  }

  document.getElementById('cpaSubmit').addEventListener('click', function () {
    var name = (document.getElementById('cpaName').value || '').trim();
    var title = (document.getElementById('cpaTitle').value || '').trim();
    if (!name) {
      setStatus('Please enter your name.', 'error');
      return;
    }
    setStatus('Submitting…', '');
    fetch(apiBase, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({
        action: 'submit',
        version_id: versionId,
        token: token,
        name: name,
        title: title,
        signature_data_url: canvas.toDataURL('image/png')
      })
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.ok) throw new Error(res.error || 'Submit failed');
        previewEl.innerHTML = res.page_html || previewEl.innerHTML;
        signedBanner.hidden = false;
        signCard.hidden = true;
        setStatus('Approval recorded successfully.', 'success');
      })
      .catch(function (err) {
        setStatus(err.message || 'Submit failed', 'error');
      });
  });

  loadPage();
})();
</script>
<?php
cw_footer();
