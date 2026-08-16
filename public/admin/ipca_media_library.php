<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

cw_require_admin();

if (empty($_SESSION['training_videos_csrf'])) {
    $_SESSION['training_videos_csrf'] = bin2hex(random_bytes(32));
}
if (empty($_SESSION['media_library_csrf'])) {
    $_SESSION['media_library_csrf'] = (string)$_SESSION['training_videos_csrf'];
}
$csrf = (string)$_SESSION['media_library_csrf'];

cw_header('Media Library');
?>
<link rel="stylesheet" href="/instructor/css/tcc_ia_shared.css">
<link rel="stylesheet" href="/admin/css/ipca_app_catalog.css">
<style>
.ml-tags { font-size: 12px; color: #4b5d73; margin: 0; max-height: 3.6em; overflow: hidden; }
.ml-card-actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:auto; }
</style>

<div class="ia-page">
  <section class="ia-hero-banner" aria-label="Media Library">
    <div class="ia-hero-banner-head">
      <div class="ia-hero-banner-main">
        <div class="ia-hero-banner-kicker">IPCA App · Media Library</div>
        <h1>Media Library</h1>
        <p class="ia-hero-banner-sub">Photograph archive for IPCA. Bulk-upload stills here. Training video thumbnails use these images automatically, and the same tagged archive is the source for future social media content. Photographs stay private until a later publishing step.</p>
      </div>
      <div class="ia-hero-banner-actions">
        <label class="ia-hero-back-btn">Upload
          <input id="ml-files" type="file" accept="image/jpeg,image/png,image/webp" multiple hidden>
        </label>
        <a class="ia-hero-back-btn" href="/admin/ipca_training_videos.php">Training Videos</a>
      </div>
    </div>
    <div class="ia-hero-banner-chips">
      <span class="ia-chip--hero" id="ml-count">0 photographs</span>
      <span class="ia-chip--hero">JPEG, PNG, or WebP</span>
    </div>
  </section>

  <div class="ia-chip-row" id="ml-orientation">
    <button type="button" class="ia-chip active" data-orientation="">All</button>
    <button type="button" class="ia-chip" data-orientation="landscape">Landscape</button>
    <button type="button" class="ia-chip" data-orientation="portrait">Portrait</button>
  </div>
  <div class="ia-progress" id="ml-progress" hidden><div class="ia-progress-bar" id="ml-progress-bar"></div></div>
  <p id="ml-message" class="ia-muted">JPEG, PNG, or WebP. AI tags aircraft, cockpit/exterior, maneuvers, avionics, and environment after each upload.</p>
  <div id="ml-grid" class="ia-card-grid"></div>
</div>

<script>
(() => {
  const csrf = <?= json_encode($csrf) ?>;
  const api = '/admin/api/media_library_api.php';
  const grid = document.getElementById('ml-grid');
  const message = document.getElementById('ml-message');
  const progress = document.getElementById('ml-progress');
  const bar = document.getElementById('ml-progress-bar');
  let orientation = '';

  const setMessage = (text, kind) => {
    message.textContent = text || '';
    message.className = kind === 'ok' ? 'ia-ok' : (kind === 'err' ? 'ia-err' : 'ia-muted');
  };

  const tags = (asset) => {
    const analysis = asset.analysis || {};
    const parts = []
      .concat(analysis.aircraft || [])
      .concat(analysis.setting || [])
      .concat(analysis.activity || [])
      .concat(analysis.concepts || []);
    return parts.filter(Boolean).slice(0, 8).join(' · ') || (asset.analysis_text || asset.filename || '');
  };

  const render = (assets) => {
    document.getElementById('ml-count').textContent = (assets || []).length + ' photograph' + ((assets || []).length === 1 ? '' : 's');
    grid.innerHTML = (assets || []).map((asset) => `
      <div class="ia-card">
        <img src="${asset.preview_url || ''}" alt="">
        <div class="ia-card-body">
          <span class="ia-badge">${asset.orientation || 'photo'}</span>
          <div class="ia-card-title">${asset.filename || 'Photograph'}</div>
          <div class="ml-tags">${tags(asset)}</div>
          <div class="ml-card-actions">
            <button type="button" class="tcc-btn ml-delete" data-uuid="${asset.asset_uuid}">Remove</button>
          </div>
        </div>
      </div>
    `).join('') || '<p class="ia-muted">No photographs yet. Upload a batch to start the archive.</p>';
    grid.querySelectorAll('.ml-delete').forEach((button) => {
      button.addEventListener('click', async () => {
        const result = await fetch(api, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ csrf_token: csrf, action: 'delete', asset_uuid: button.dataset.uuid }),
        }).then((r) => r.json());
        if (!result.ok) {
          setMessage(result.error || 'Could not remove that photograph.', 'err');
          return;
        }
        await load();
      });
    });
  };

  const load = async () => {
    const data = await fetch(api + '?action=list&orientation=' + encodeURIComponent(orientation)).then((r) => r.json());
    render(data.assets || []);
  };

  const uploadOne = (file, index, total) => new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', '/admin/api/media_library_upload.php?filename=' + encodeURIComponent(file.name));
    xhr.setRequestHeader('X-IPCA-CSRF', csrf);
    xhr.setRequestHeader('Content-Type', file.type || 'image/jpeg');
    xhr.upload.onprogress = (event) => {
      if (!event.lengthComputable) return;
      const base = index / total;
      const frac = base + ((event.loaded / event.total) / total);
      progress.hidden = false;
      bar.style.width = Math.round(frac * 100) + '%';
    };
    xhr.onload = () => {
      let payload = null;
      try { payload = JSON.parse(xhr.responseText); } catch (e) {}
      if (xhr.status >= 200 && xhr.status < 300 && payload && payload.ok) {
        resolve(payload);
        return;
      }
      reject(new Error((payload && payload.error) || ('Upload failed (' + xhr.status + ').')));
    };
    xhr.onerror = () => reject(new Error('Upload failed. Check the connection and try again.'));
    xhr.send(file);
  });

  document.getElementById('ml-files').addEventListener('change', async (event) => {
    const files = Array.from(event.target.files || []);
    if (!files.length) return;
    setMessage('Uploading ' + files.length + ' photograph' + (files.length === 1 ? '' : 's') + '…');
    try {
      for (let i = 0; i < files.length; i++) {
        await uploadOne(files[i], i, files.length);
        setMessage('Uploaded ' + (i + 1) + ' of ' + files.length + '. Tagging with AI when available…');
      }
      progress.hidden = true;
      bar.style.width = '0%';
      setMessage('Archive updated. These photographs can be used for training thumbnails and later social media.', 'ok');
      await load();
    } catch (error) {
      progress.hidden = true;
      setMessage(error.message || 'Upload failed.', 'err');
    }
    event.target.value = '';
  });
  document.querySelectorAll('#ml-orientation .ia-chip').forEach((chip) => {
    chip.addEventListener('click', () => {
      document.querySelectorAll('#ml-orientation .ia-chip').forEach((item) => item.classList.remove('active'));
      chip.classList.add('active');
      orientation = chip.dataset.orientation || '';
      load();
    });
  });
  load();
})();
</script>
<?php
cw_footer();
