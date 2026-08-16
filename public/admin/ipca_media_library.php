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
<style>
.ml-page { max-width: 1100px; }
.ml-kicker { font-size: 12px; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; color: #728198; }
.ml-muted { color: #728198; }
.ml-ok { color: #0f6d32; font-weight: 700; }
.ml-err { color: #b42318; font-weight: 700; }
.ml-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; }
.ml-card { border: 1px solid #e6edf5; border-radius: 10px; overflow: hidden; background: #fff; }
.ml-card img { display: block; width: 100%; height: 160px; object-fit: cover; background: #071b35; }
.ml-card-body { padding: 10px; }
.ml-badge { display: inline-block; font-size: 10px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; padding: 2px 7px; border-radius: 999px; background: #eef2f6; color: #4b5d73; }
.ml-tags { font-size: 12px; color: #4b5d73; margin: 6px 0 0; max-height: 3.6em; overflow: hidden; }
.ml-progress { height: 8px; background: #e6edf5; border-radius: 999px; overflow: hidden; margin: 8px 0 0; }
.ml-progress[hidden] { display: none; }
.ml-progress-bar { height: 100%; width: 0; background: #1f6feb; }
.ml-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin: 12px 0; }
</style>

<div class="ml-page">
  <div class="card">
    <div class="ml-kicker">IPCA App</div>
    <h2 style="margin:6px 0 8px;">Media Library</h2>
    <p class="ml-muted">Photograph archive for IPCA. Bulk-upload stills here. Training video thumbnails use these images automatically, and the same tagged archive is the source for future social media content. Photographs stay private until a later publishing step.</p>
    <div class="ml-actions">
      <label class="btn">Upload photographs
        <input id="ml-files" type="file" accept="image/jpeg,image/png,image/webp" multiple hidden>
      </label>
      <select id="ml-orientation">
        <option value="">All orientations</option>
        <option value="landscape">Landscape</option>
        <option value="portrait">Portrait</option>
        <option value="square">Square</option>
      </select>
    </div>
    <div class="ml-progress" id="ml-progress" hidden><div class="ml-progress-bar" id="ml-progress-bar"></div></div>
    <p id="ml-message" class="ml-muted">JPEG, PNG, or WebP. AI tags aircraft, cockpit/exterior, maneuvers, avionics, and environment after each upload.</p>
  </div>
  <div id="ml-grid" class="ml-grid" style="margin-top:16px;"></div>
</div>

<script>
(() => {
  const csrf = <?= json_encode($csrf) ?>;
  const api = '/admin/api/media_library_api.php';
  const grid = document.getElementById('ml-grid');
  const message = document.getElementById('ml-message');
  const progress = document.getElementById('ml-progress');
  const bar = document.getElementById('ml-progress-bar');

  const setMessage = (text, kind) => {
    message.textContent = text || '';
    message.className = kind === 'ok' ? 'ml-ok' : (kind === 'err' ? 'ml-err' : 'ml-muted');
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
    grid.innerHTML = (assets || []).map((asset) => `
      <div class="ml-card">
        <img src="${asset.preview_url || ''}" alt="">
        <div class="ml-card-body">
          <span class="ml-badge">${asset.orientation || 'photo'}</span>
          <div class="ml-tags">${tags(asset)}</div>
          <button type="button" class="btn ml-delete" data-uuid="${asset.asset_uuid}" style="margin-top:8px;">Remove</button>
        </div>
      </div>
    `).join('') || '<p class="ml-muted">No photographs yet. Upload a batch to start the archive.</p>';
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
    const orientation = document.getElementById('ml-orientation').value;
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
  document.getElementById('ml-orientation').addEventListener('change', load);
  load();
})();
</script>
<?php
cw_footer();
