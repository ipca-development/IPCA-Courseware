<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

cw_require_admin();

if (empty($_SESSION['training_videos_csrf'])) {
    $_SESSION['training_videos_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['training_videos_csrf'];

cw_header('Training Videos');
?>
<link rel="stylesheet" href="/instructor/css/tcc_ia_shared.css">
<link rel="stylesheet" href="/admin/css/ipca_app_catalog.css">
<style>
.tv-muted { color: #728198; }
.tv-ok { color: #0f6d32; font-weight: 700; }
.tv-err { color: #b42318; font-weight: 700; }
.tv-catalog { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; }
.tv-card { background:#fff; border:1px solid rgba(15,23,42,.08); border-radius:18px; overflow:hidden; box-shadow:0 8px 20px rgba(15,23,42,.04); display:flex; flex-direction:column; }
.tv-card-thumb { position:relative; background:#071b35; aspect-ratio:16/9; }
.tv-card-thumb img { width:100%; height:100%; object-fit:cover; display:block; }
.tv-card-thumb .tv-duration { position:absolute; right:8px; bottom:8px; background:rgba(7,27,53,.8); color:#fff; font-size:11px; font-weight:800; padding:3px 7px; border-radius:999px; }
.tv-card-body { padding:12px 14px 14px; display:flex; flex-direction:column; gap:8px; flex:1; }
.tv-card-title { font-size:16px; font-weight:900; color:#102845; line-height:1.25; }
.tv-card-copy { font-size:13px; color:#64748b; line-height:1.45; display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; }
.tv-card-pills { display:flex; gap:6px; flex-wrap:wrap; }
.tv-badge { display:inline-flex; font-size:10px; font-weight:800; letter-spacing:.04em; text-transform:uppercase; padding:3px 8px; border-radius:999px; }
.tv-badge-live { background:#d8f3dc; color:#0f6d32; }
.tv-badge-draft { background:#eef2f6; color:#4b5d73; }
.tv-badge-warn { background:#fff3cd; color:#8a6d00; }
.tv-card-meta { font-size:12px; font-weight:700; color:#64748b; display:flex; gap:12px; }
.tv-card-actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:auto; }
.tv-field { margin: 0 0 12px; }
.tv-field label { display: block; font-size: 12px; font-weight: 700; margin: 0 0 4px; color: #728198; }
.tv-field input, .tv-field textarea, .tv-field select { width: 100%; }
.tv-grants { display: grid; gap: 10px; }
.tv-grant { display: grid; grid-template-columns: 140px 1fr 1fr 1fr auto; gap: 8px; align-items: end; }
.tv-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 16px; }
.tv-banner { padding: 10px 12px; border-radius: 8px; margin: 0 0 14px; font-weight: 700; }
.tv-banner-live { background: #d8f3dc; color: #0f6d32; }
.tv-banner-draft { background: #eef2f6; color: #4b5d73; }
.tv-banner-warn { background: #fff3cd; color: #8a6d00; }
.tv-progress { height: 8px; background: #e6edf5; border-radius: 999px; overflow: hidden; margin: 8px 0 0; }
.tv-progress[hidden] { display: none; }
.tv-progress-bar { height: 100%; width: 0; background: #1f6feb; transition: width .12s linear; }
.tv-thumb { width: 100%; max-width: 420px; height: auto; border-radius: 10px; background: #071b35; display: block; object-fit: contain; }
.tv-thumb-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
.tv-player { width:100%; max-height:58vh; background:#071b35; border-radius:14px; }
.tv-drop { border:2px dashed rgba(15,23,42,.16); border-radius:16px; padding:28px 18px; text-align:center; background:#fbfdff; }
.tv-drop.drag { border-color:#12355f; background:#eff6ff; }
.tv-bulk-list { display:flex; flex-direction:column; gap:8px; margin-top:14px; }
.tv-bulk-row { display:flex; justify-content:space-between; gap:10px; align-items:center; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,.08); background:#fff; }
.tv-picker { position: fixed; inset: 0; background: rgba(7,27,53,.45); display: none; align-items: center; justify-content: center; z-index: 40; padding: 24px; }
.tv-picker.open { display: flex; }
.tv-picker-card { background: #fff; border-radius: 12px; max-width: 860px; width: 100%; max-height: 80vh; overflow: auto; padding: 16px; }
.tv-picker-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 10px; }
.tv-picker-grid img { width: 100%; height: 110px; object-fit: cover; border-radius: 8px; cursor: pointer; }
#tv-edit-modal .tcc-modal-card, #tv-bulk-modal .tcc-modal-card { width:min(920px,96vw); }
@media (max-width: 900px) {
  .tv-grant { grid-template-columns: 1fr; }
}
</style>

<div class="ia-page">
  <section class="ia-hero-banner" aria-label="Training Videos">
    <div class="ia-hero-banner-head">
      <div class="ia-hero-banner-main">
        <div class="ia-hero-banner-kicker">IPCA App · Training Videos</div>
        <h1>Training Videos</h1>
        <p class="ia-hero-banner-sub">Private videos for the IPCA app. A thumbnail is generated automatically from the IPCA Media Library after the video is uploaded. Custom thumbnail upload is optional. A video is on the app only after the file is on the server and status is Published.</p>
      </div>
      <div class="ia-hero-banner-actions">
        <button type="button" class="ia-hero-back-btn" id="tv-bulk">Bulk upload</button>
        <button type="button" class="ia-hero-back-btn" id="tv-new">New video</button>
        <a class="ia-hero-back-btn" href="/admin/ipca_media_library.php">Media Library</a>
      </div>
    </div>
    <div class="ia-hero-banner-chips" id="tv-stats">
      <span class="ia-chip--hero">0 videos</span>
      <span class="ia-chip--hero">0 published</span>
      <span class="ia-chip--hero">0 drafts</span>
      <span class="ia-chip--hero">0 views</span>
    </div>
  </section>

  <div class="ia-chip-row" id="tv-cat-pills"></div>
  <div class="ia-chip-row" id="tv-sort-pills">
    <button type="button" class="ia-chip active" data-sort="newest">Newest</button>
    <button type="button" class="ia-chip" data-sort="views">Most viewed</button>
    <button type="button" class="ia-chip" data-sort="likes">Most liked</button>
  </div>
  <div class="tv-catalog" id="tv-catalog"></div>
  <p id="tv-empty" class="tv-muted" hidden>No videos yet.</p>
</div>

<div class="tcc-modal-overlay" id="tv-play-modal">
  <div class="tcc-modal-card">
    <div class="tcc-modal-head">
      <div>
        <div class="tcc-modal-kicker">Play</div>
        <div class="tcc-modal-title" id="tv-play-title">Training video</div>
      </div>
      <button type="button" class="tcc-modal-close" data-close="tv-play-modal" aria-label="Close">&times;</button>
    </div>
    <div class="tcc-modal-body">
      <video id="tv-player" class="tv-player" controls playsinline></video>
      <p class="tcc-modal-readable" id="tv-play-copy" style="margin-top:12px;"></p>
      <div class="tv-card-meta" id="tv-play-stats" style="margin-top:10px;"></div>
    </div>
  </div>
</div>

<div class="tcc-modal-overlay" id="tv-edit-modal">
  <div class="tcc-modal-card">
    <div class="tcc-modal-head">
      <div>
        <div class="tcc-modal-kicker">Edit</div>
        <div class="tcc-modal-title" id="tv-edit-heading">Video</div>
      </div>
      <button type="button" class="tcc-modal-close" data-close="tv-edit-modal" aria-label="Close">&times;</button>
    </div>
    <div class="tcc-modal-body">
      <form id="tv-form">
        <input type="hidden" id="video-uuid">
        <div id="tv-visibility" class="tv-banner tv-banner-draft">Draft — upload a video file, then publish.</div>
        <div class="tv-field"><label>Title</label><input id="title" required maxlength="255"></div>
        <div class="tv-field">
          <label>What you'll learn</label>
          <textarea id="description" rows="4" placeholder="Written automatically from the video after upload."></textarea>
          <p class="tv-muted" id="desc-source">A short, practical explanation is generated from the video. You can edit it.</p>
          <button type="button" class="tcc-btn" id="tv-rewrite" style="margin-top:8px;">Rewrite from video</button>
        </div>
        <div class="tv-field">
          <label>Category</label>
          <select id="category_id"></select>
        </div>
        <div class="tv-field"><label>Aircraft / program</label><input id="aircraft" maxlength="128" placeholder="Cessna 172, G1000, ..."></div>
        <div class="tv-field">
          <label>Status</label>
          <select id="status">
            <option value="draft">Draft</option>
            <option value="published">Published</option>
            <option value="archived">Archived</option>
          </select>
        </div>
        <div class="tv-field">
          <label>THUMBNAIL</label>
          <img id="thumb-preview" class="tv-thumb" alt="" hidden>
          <p class="tv-muted" id="poster-file-status">No thumbnail yet. Upload a video and one will be generated automatically.</p>
          <p class="tv-muted" id="thumb-source"></p>
          <div class="tv-thumb-actions">
            <button type="button" class="tcc-btn" id="thumb-regenerate">Regenerate</button>
            <button type="button" class="tcc-btn" id="thumb-choose">Choose Another Image</button>
            <label class="tcc-btn">Upload Custom
              <input id="poster-file" type="file" accept="image/jpeg,image/png,image/webp" hidden>
            </label>
          </div>
          <div class="tv-progress" id="poster-progress" hidden><div class="tv-progress-bar" id="poster-progress-bar"></div></div>
        </div>
        <div class="tv-field">
          <label>Video file (MP4)</label>
          <input id="video-file" type="file" accept="video/mp4,video/quicktime">
          <div class="tv-progress" id="video-progress" hidden><div class="tv-progress-bar" id="video-progress-bar"></div></div>
          <p class="tv-muted" id="video-file-status">No video uploaded yet.</p>
        </div>
        <h3>Who can watch</h3>
        <p class="tv-muted">A person needs at least one currently active grant. Leave until blank to keep access open-ended. Times are UTC. Time-limited category access is set in Enrollment.</p>
        <div id="tv-grants" class="tv-grants"></div>
        <button type="button" class="tcc-btn" id="tv-add-grant">Add access</button>
        <div class="tv-actions">
          <button type="submit" class="tcc-btn primary">Save</button>
          <button type="button" class="tcc-btn primary" id="tv-publish">Publish to app</button>
          <button type="button" class="tcc-btn" id="tv-archive">Archive</button>
          <button type="button" class="tcc-btn warn" id="tv-delete">Delete</button>
        </div>
        <p id="tv-message" class="tv-muted"></p>
      </form>
    </div>
  </div>
</div>

<div class="tcc-modal-overlay" id="tv-bulk-modal">
  <div class="tcc-modal-card">
    <div class="tcc-modal-head">
      <div>
        <div class="tcc-modal-kicker">Bulk upload</div>
        <div class="tcc-modal-title">Drop MP4 files</div>
      </div>
      <button type="button" class="tcc-modal-close" data-close="tv-bulk-modal" aria-label="Close">&times;</button>
    </div>
    <div class="tcc-modal-body">
      <p class="tv-muted">Each file is stored privately, then a thumbnail, title, category, and what-you’ll-learn copy are written. Edit any video afterward.</p>
      <div class="tv-field"><label>Default access</label>
        <select id="bulk-grant-type">
          <option value="all" selected>Everyone</option>
          <option value="roles">Role</option>
        </select>
      </div>
      <div class="tv-field"><label>Available from (UTC)</label><input id="bulk-from" type="datetime-local"></div>
      <div class="tv-field"><label>Available until (UTC)</label><input id="bulk-until" type="datetime-local"></div>
      <div class="tv-drop" id="tv-drop">
        <strong>Drop many MP4s here</strong>
        <p class="tv-muted">or choose files</p>
        <input id="bulk-files" type="file" accept="video/mp4,video/quicktime" multiple>
      </div>
      <div class="tv-bulk-list" id="tv-bulk-list"></div>
    </div>
  </div>
</div>

<div class="tv-picker" id="tv-picker">
  <div class="tv-picker-card">
    <h3 style="margin-top:0;">Choose Another Image</h3>
    <p class="tv-muted">Orientation-matched photographs from the IPCA Media Library.</p>
    <div class="tv-picker-grid" id="tv-picker-grid"></div>
    <button type="button" class="tcc-btn" id="tv-picker-close" style="margin-top:12px;">Close</button>
  </div>
</div>

<script>
(() => {
  const api = '/admin/api/training_videos_api.php';
  const csrf = <?= json_encode($csrf) ?>;
  const grantsEl = document.getElementById('tv-grants');
  const messageEl = document.getElementById('tv-message');
  const visibilityEl = document.getElementById('tv-visibility');
  let options = { users: [], cohorts: [], programs: [], roles: [] };
  let categories = [];
  let videos = [];
  let current = null;
  let filterCategory = 'all';
  let sortMode = 'newest';
  let uploadChain = Promise.resolve();
  let busy = false;

  const formatBytes = (n) => {
    const value = Number(n) || 0;
    if (value < 1024) return value + ' B';
    if (value < 1048576) return (value / 1024).toFixed(1) + ' KB';
    if (value < 1073741824) return (value / 1048576).toFixed(1) + ' MB';
    return (value / 1073741824).toFixed(2) + ' GB';
  };
  const formatDuration = (ms) => {
    const seconds = Math.max(0, Math.round((Number(ms) || 0) / 1000));
    return Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
  };
  const escapeHtml = (value) => String(value || '').replace(/[&<>"']/g, (ch) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[ch]));

  const visibilityLabel = (video) => {
    if (video && video.app_visible) return 'On the app';
    if (video && video.status === 'published' && !video.has_video) return 'Published, missing file';
    if (video && video.status === 'archived') return 'Archived';
    if (video && video.has_video) return 'Draft · file ready';
    return 'Draft · no file';
  };
  const badgeClass = (video) => video && video.app_visible ? 'tv-badge-live' : (video && video.has_video ? 'tv-badge-warn' : 'tv-badge-draft');

  const setVisibility = (video) => {
    visibilityEl.className = 'tv-banner';
    if (video && video.app_visible) {
      visibilityEl.classList.add('tv-banner-live');
      visibilityEl.textContent = 'Published on the app. Students who have access can watch it.';
      return;
    }
    if (video && video.status === 'published' && !video.has_video) {
      visibilityEl.classList.add('tv-banner-warn');
      visibilityEl.textContent = 'Marked published, but no video file is on the server yet.';
      return;
    }
    if (video && video.has_video && video.status !== 'published') {
      visibilityEl.classList.add('tv-banner-warn');
      visibilityEl.textContent = 'Video file is on the server. Publish to show it in the app.';
      return;
    }
    visibilityEl.classList.add('tv-banner-draft');
    visibilityEl.textContent = 'Draft — upload a video file, then publish.';
  };

  const fileStatus = (kind, video) => {
    if (kind === 'video') {
      if (video && video.has_video) {
        const size = video.byte_size ? ' · ' + formatBytes(video.byte_size) : '';
        return 'Video file is on the server' + size + '.';
      }
      return 'No video uploaded yet.';
    }
    return (video && video.has_poster) ? 'Generated thumbnail is ready.' : 'No thumbnail yet. Upload a video and one will be generated automatically.';
  };

  const setProgress = (kind, fraction, text) => {
    const bar = document.getElementById(kind + '-progress');
    const fill = document.getElementById(kind + '-progress-bar');
    const status = document.getElementById(kind + '-file-status');
    if (fraction == null) {
      bar.hidden = true;
      fill.style.width = '0%';
      return;
    }
    bar.hidden = false;
    fill.style.width = Math.max(0, Math.min(100, Math.round(fraction * 100))) + '%';
    if (text) status.textContent = text;
  };

  const sourceLabel = (video) => {
    const source = (video && video.poster_source) || '';
    if (source === 'media_library') return 'Source: IPCA Media Library';
    if (source === 'ai_generated') return 'Source: AI Generated';
    if (source === 'custom') return 'Source: Custom upload';
    if (source === 'branded_fallback') return 'Source: IPCA Media Library / AI Generated';
    if (video && video.has_poster) return 'Source: IPCA Media Library / AI Generated';
    return '';
  };

  const setThumb = (video) => {
    const img = document.getElementById('thumb-preview');
    const source = document.getElementById('thumb-source');
    const url = (video && (video.poster_preview_url || video.poster_url)) || '';
    if (url) {
      img.hidden = false;
      img.src = url;
    } else {
      img.hidden = true;
      img.removeAttribute('src');
    }
    source.textContent = sourceLabel(video);
  };

  const probeVideo = (file) => new Promise((resolve) => {
    const url = URL.createObjectURL(file);
    const video = document.createElement('video');
    video.preload = 'metadata';
    video.onloadedmetadata = () => {
      resolve({
        width: video.videoWidth || 0,
        height: video.videoHeight || 0,
        duration_ms: Number.isFinite(video.duration) ? Math.round(video.duration * 1000) : 0,
      });
      URL.revokeObjectURL(url);
    };
    video.onerror = () => {
      URL.revokeObjectURL(url);
      resolve({ width: 0, height: 0, duration_ms: 0 });
    };
    video.src = url;
  });

  const setMessage = (text, kind) => {
    messageEl.textContent = text || '';
    messageEl.className = kind === 'ok' ? 'tv-ok' : (kind === 'err' ? 'tv-err' : 'tv-muted');
  };

  const valueInput = (grant) => {
    if (grant.grant_type === 'all') return '<input type="hidden" class="grant-value" value="">';
    const items = grant.grant_type === 'users' ? options.users
      : grant.grant_type === 'cohorts' ? options.cohorts
      : grant.grant_type === 'programs' ? options.programs
      : options.roles;
    const opts = items.map((item) => {
      const selected = String(item.id) === String(grant.grant_value) ? ' selected' : '';
      const label = item.email ? `${item.name} (${item.email})` : item.name;
      return `<option value="${item.id}"${selected}>${escapeHtml(label)}</option>`;
    }).join('');
    return `<select class="grant-value">${opts}</select>`;
  };

  const toLocal = (value) => {
    if (!value) return '';
    const date = new Date(value.includes('T') ? value : value.replace(' ', 'T') + 'Z');
    if (Number.isNaN(date.getTime())) return '';
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())}T${pad(date.getUTCHours())}:${pad(date.getUTCMinutes())}`;
  };

  const renderGrants = (grants) => {
    grantsEl.innerHTML = '';
    (grants.length ? grants : [{ grant_type: 'all', grant_value: '', available_from_utc: '', available_until_utc: '' }]).forEach((grant) => {
      const row = document.createElement('div');
      row.className = 'tv-grant';
      row.innerHTML = `
        <div class="tv-field">
          <label>Type</label>
          <select class="grant-type">
            <option value="all"${grant.grant_type === 'all' ? ' selected' : ''}>Everyone</option>
            <option value="users"${grant.grant_type === 'users' ? ' selected' : ''}>Person</option>
            <option value="cohorts"${grant.grant_type === 'cohorts' ? ' selected' : ''}>Cohort</option>
            <option value="programs"${grant.grant_type === 'programs' ? ' selected' : ''}>Program</option>
            <option value="roles"${grant.grant_type === 'roles' ? ' selected' : ''}>Role</option>
          </select>
        </div>
        <div class="tv-field grant-value-wrap">
          <label>Who</label>
          ${valueInput(grant)}
        </div>
        <div class="tv-field"><label>Available from</label><input class="grant-from" type="datetime-local" value="${toLocal(grant.available_from_utc)}"></div>
        <div class="tv-field"><label>Available until</label><input class="grant-until" type="datetime-local" value="${toLocal(grant.available_until_utc)}"></div>
        <button type="button" class="tcc-btn grant-remove">Remove</button>
      `;
      row.querySelector('.grant-type').addEventListener('change', (event) => {
        grant.grant_type = event.target.value;
        grant.grant_value = '';
        row.querySelector('.grant-value-wrap').innerHTML = `<label>Who</label>${valueInput(grant)}`;
      });
      row.querySelector('.grant-remove').addEventListener('click', () => row.remove());
      grantsEl.appendChild(row);
    });
  };

  const collectGrants = () => Array.from(grantsEl.querySelectorAll('.tv-grant')).map((row) => ({
    grant_type: row.querySelector('.grant-type').value,
    grant_value: row.querySelector('.grant-value') ? row.querySelector('.grant-value').value : '',
    available_from_utc: row.querySelector('.grant-from').value ? new Date(row.querySelector('.grant-from').value + 'Z').toISOString() : '',
    available_until_utc: row.querySelector('.grant-until').value ? new Date(row.querySelector('.grant-until').value + 'Z').toISOString() : '',
  }));

  const post = async (body) => {
    const response = await fetch(api, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({ csrf_token: csrf }, body)),
    });
    return response.json();
  };

  const putWithProgress = (kind, uuid, file, mime, onProgress, extra) => new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    const query = new URLSearchParams(Object.assign({ kind, video_uuid: uuid }, extra || {}));
    xhr.open('POST', '/admin/api/training_videos_upload.php?' + query.toString());
    xhr.setRequestHeader('X-IPCA-CSRF', csrf);
    xhr.setRequestHeader('Content-Type', mime);
    xhr.upload.onprogress = (event) => {
      if (event.lengthComputable) onProgress(event.loaded, event.total);
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

  const fillCategories = (selected) => {
    const select = document.getElementById('category_id');
    select.innerHTML = categories.map((category) => {
      const isSelected = String(category.id) === String(selected || '') ? ' selected' : '';
      return `<option value="${category.id}"${isSelected}>${escapeHtml(category.name)}</option>`;
    }).join('');
  };

  const visibleVideos = () => {
    let list = videos.slice();
    if (filterCategory === 'uncategorized') {
      list = list.filter((video) => !video.category_slug || video.category_slug === 'uncategorized' || !video.category);
    } else if (filterCategory !== 'all') {
      list = list.filter((video) => String(video.category_id) === String(filterCategory) || video.category_slug === filterCategory);
    }
    if (sortMode === 'views') list.sort((a, b) => (b.view_count || 0) - (a.view_count || 0));
    else if (sortMode === 'likes') list.sort((a, b) => (b.like_count || 0) - (a.like_count || 0));
    else list.sort((a, b) => (b.id || 0) - (a.id || 0));
    return list;
  };

  const renderPills = () => {
    const counts = { all: videos.length, uncategorized: 0 };
    categories.forEach((category) => { counts[category.id] = 0; });
    videos.forEach((video) => {
      if (video.category_id && counts[video.category_id] !== undefined) counts[video.category_id] += 1;
      else counts.uncategorized += 1;
    });
    const pills = [`<button type="button" class="ia-chip${filterCategory === 'all' ? ' active' : ''}" data-cat="all">All (${counts.all})</button>`];
    categories.forEach((category) => {
      if (category.slug === 'uncategorized') return;
      pills.push(`<button type="button" class="ia-chip${String(filterCategory) === String(category.id) ? ' active' : ''}" data-cat="${category.id}">${escapeHtml(category.name)} (${counts[category.id] || 0})</button>`);
    });
    pills.push(`<button type="button" class="ia-chip${filterCategory === 'uncategorized' ? ' active' : ''}" data-cat="uncategorized">Uncategorized (${counts.uncategorized})</button>`);
    document.getElementById('tv-cat-pills').innerHTML = pills.join('');
    document.getElementById('tv-cat-pills').querySelectorAll('button[data-cat]').forEach((button) => {
      button.addEventListener('click', () => {
        filterCategory = button.dataset.cat;
        renderCatalog();
      });
    });
  };

  const renderCatalog = () => {
    renderPills();
    const list = visibleVideos();
    document.getElementById('tv-empty').hidden = list.length > 0;
    document.getElementById('tv-catalog').innerHTML = list.map((video) => `
      <article class="tv-card" data-uuid="${video.video_uuid}">
        <div class="tv-card-thumb">
          ${video.poster_preview_url ? `<img src="${escapeHtml(video.poster_preview_url)}" alt="">` : ''}
          <span class="tv-duration">${formatDuration(video.duration_ms)}</span>
        </div>
        <div class="tv-card-body">
          <div class="tv-card-title">${escapeHtml(video.title || 'Untitled')}</div>
          <div class="tv-card-copy">${escapeHtml(video.description || '')}</div>
          <div class="tv-card-pills">
            <span class="tv-badge">${escapeHtml(video.category || 'Uncategorized')}</span>
            <span class="tv-badge ${badgeClass(video)}">${visibilityLabel(video)}</span>
          </div>
          <div class="tv-card-meta">
            <span>${video.view_count || 0} views</span>
            <span>${video.like_count || 0} likes</span>
          </div>
          <div class="tv-card-actions">
            <button type="button" class="tcc-btn primary tv-play" ${video.has_video ? '' : 'disabled'}>Play</button>
            <button type="button" class="tcc-btn tv-edit">Edit</button>
          </div>
        </div>
      </article>
    `).join('');
    document.getElementById('tv-catalog').querySelectorAll('.tv-card').forEach((card) => {
      const uuid = card.dataset.uuid;
      card.querySelector('.tv-play').addEventListener('click', () => openPlay(uuid));
      card.querySelector('.tv-edit').addEventListener('click', () => loadVideo(uuid, true));
    });
  };

  const renderStats = (stats) => {
    document.getElementById('tv-stats').innerHTML = `
      <span class="ia-chip--hero">${stats.total || 0} videos</span>
      <span class="ia-chip--hero">${stats.published || 0} published</span>
      <span class="ia-chip--hero">${stats.drafts || 0} drafts</span>
      <span class="ia-chip--hero">${stats.views || 0} views</span>
    `;
  };

  const loadList = async () => {
    const data = await fetch(api + '?action=list').then((r) => r.json());
    options = data.options || options;
    categories = data.categories || categories;
    videos = data.videos || [];
    renderStats(data.stats || {});
    fillCategories(current && current.category_id);
    renderCatalog();
  };

  const fillForm = (video, grants) => {
    current = video || {};
    document.getElementById('video-uuid').value = current.video_uuid || '';
    document.getElementById('title').value = current.title || '';
    document.getElementById('description').value = current.description || '';
    document.getElementById('aircraft').value = current.aircraft || '';
    document.getElementById('status').value = current.status || 'draft';
    document.getElementById('tv-edit-heading').textContent = current.title || 'New video';
    fillCategories(current.category_id);
    const descSource = document.getElementById('desc-source');
    if (current.description_source === 'generated') {
      descSource.textContent = 'Written from the video. Edit freely, or rewrite.';
    } else if (current.description) {
      descSource.textContent = 'You can rewrite this from the video at any time.';
    } else {
      descSource.textContent = 'A short, practical explanation is generated from the video. You can edit it.';
    }
    document.getElementById('video-file-status').textContent = fileStatus('video', current);
    document.getElementById('poster-file-status').textContent = fileStatus('poster', current);
    setThumb(current);
    setVisibility(current);
    renderGrants(grants || []);
  };

  const openModal = (id) => document.getElementById(id).classList.add('open');
  const closeModal = (id) => {
    document.getElementById(id).classList.remove('open');
    if (id === 'tv-play-modal') {
      const player = document.getElementById('tv-player');
      player.pause();
      player.removeAttribute('src');
      player.load();
    }
  };

  const loadVideo = async (uuid, open) => {
    const data = await fetch(api + '?action=detail&video_uuid=' + encodeURIComponent(uuid)).then((r) => r.json());
    fillForm(data.video, data.grants);
    setMessage('');
    if (open) openModal('tv-edit-modal');
  };

  const openPlay = (uuid) => {
    const video = videos.find((item) => item.video_uuid === uuid);
    if (!video || !video.video_play_url) return;
    document.getElementById('tv-play-title').textContent = video.title || 'Training video';
    document.getElementById('tv-play-copy').textContent = video.description || '';
    document.getElementById('tv-play-stats').textContent = (video.view_count || 0) + ' views · ' + (video.like_count || 0) + ' likes';
    const player = document.getElementById('tv-player');
    player.src = video.video_play_url || ('/admin/api/training_videos_play.php?video_uuid=' + encodeURIComponent(uuid));
    openModal('tv-play-modal');
    player.play().catch(() => {});
  };

  const save = async (statusOverride) => {
    const payload = {
      action: 'save',
      video_uuid: document.getElementById('video-uuid').value,
      title: document.getElementById('title').value,
      description: document.getElementById('description').value,
      category_id: Number(document.getElementById('category_id').value || 0),
      aircraft: document.getElementById('aircraft').value,
      status: statusOverride || document.getElementById('status').value,
      grants: collectGrants(),
    };
    const saved = await post(payload);
    if (!saved.ok) {
      setMessage(saved.error || 'Could not save.', 'err');
      return saved;
    }
    fillForm(saved.video, saved.grants);
    await loadList();
    setMessage(saved.video && saved.video.app_visible ? 'Saved and published on the app.' : 'Saved.', 'ok');
    return saved;
  };

  const upload = async (kind, file) => {
    if (!file) return;
    busy = true;
    document.getElementById('video-file').disabled = true;
    document.getElementById('poster-file').disabled = true;
    try {
      let uuid = document.getElementById('video-uuid').value;
      if (!uuid) {
        const saved = await save();
        if (!saved.ok) return;
        uuid = saved.video.video_uuid;
      }
      const mime = file.type || (kind === 'video' ? 'video/mp4' : 'image/jpeg');
      let extra = {};
      if (kind === 'video') extra = await probeVideo(file);
      setProgress(kind, 0, 'Preparing ' + kind + ' upload…');
      setMessage('Uploading ' + kind + '…');
      const complete = await putWithProgress(kind, uuid, file, mime, (loaded, total) => {
        const pct = total ? Math.round((loaded / total) * 100) : 0;
        setProgress(kind, total ? loaded / total : 0, 'Uploading ' + kind + '… ' + pct + '% · ' + formatBytes(loaded) + ' of ' + formatBytes(total));
      }, extra);
      setProgress(kind, null);
      fillForm(complete.video, collectGrants());
      await loadList();
      if (kind === 'video') {
        setMessage('Writing copy…');
        const explained = await post({ action: 'generate_explanation', video_uuid: uuid, force: false });
        if (explained.ok) fillForm(explained.video, explained.grants || collectGrants());
        await loadList();
      }
      if (kind === 'video' && complete.video.app_visible) setMessage('Video uploaded and published on the app.', 'ok');
      else if (kind === 'video') setMessage('Video file is on the server. Publish to show it in the app.', 'ok');
      else setMessage('Custom thumbnail uploaded.', 'ok');
    } catch (error) {
      setProgress(kind, null);
      setMessage(error.message || 'Upload failed.', 'err');
    } finally {
      busy = false;
      document.getElementById('video-file').disabled = false;
      document.getElementById('poster-file').disabled = false;
      document.getElementById(kind + '-file').value = '';
    }
  };

  const queueUpload = (kind, file) => {
    if (!file) return;
    uploadChain = uploadChain.then(() => upload(kind, file));
  };

  const defaultBulkRange = () => {
    const from = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    document.getElementById('bulk-from').value = `${from.getUTCFullYear()}-${pad(from.getUTCMonth() + 1)}-${pad(from.getUTCDate())}T${pad(from.getUTCHours())}:${pad(from.getUTCMinutes())}`;
    document.getElementById('bulk-until').value = '';
  };

  const bulkGrants = () => [{
    grant_type: document.getElementById('bulk-grant-type').value || 'all',
    grant_value: '',
    available_from_utc: document.getElementById('bulk-from').value ? new Date(document.getElementById('bulk-from').value + 'Z').toISOString() : '',
    available_until_utc: document.getElementById('bulk-until').value ? new Date(document.getElementById('bulk-until').value + 'Z').toISOString() : '',
  }];

  const setBulkStatus = (row, text) => {
    row.querySelector('.tv-bulk-status').textContent = text;
  };

  const uploadBulkFile = async (file, row) => {
    setBulkStatus(row, 'Queued');
    const title = file.name.replace(/\.(mp4|mov|m4v)$/i, '').replace(/[_-]+/g, ' ').trim() || file.name;
    const saved = await post({
      action: 'save',
      title,
      title_source: 'filename',
      description: '',
      status: 'draft',
      grants: bulkGrants(),
    });
    if (!saved.ok) {
      setBulkStatus(row, 'Failed');
      throw new Error(saved.error || 'Could not create the draft.');
    }
    const uuid = saved.video.video_uuid;
    const extra = await probeVideo(file);
    setBulkStatus(row, 'Uploading 0%');
    await putWithProgress('video', uuid, file, file.type || 'video/mp4', (loaded, total) => {
      const pct = total ? Math.round((loaded / total) * 100) : 0;
      setBulkStatus(row, 'Uploading ' + pct + '%');
    }, extra);
    setBulkStatus(row, 'Thumbnail');
    setBulkStatus(row, 'Writing copy');
    const explained = await post({ action: 'generate_explanation', video_uuid: uuid, force: false });
    if (!explained.ok) {
      setBulkStatus(row, 'Failed');
      return;
    }
    setBulkStatus(row, 'Ready');
  };

  const queueBulkFiles = (fileList) => {
    const files = Array.from(fileList || []).filter((file) => file && file.type.startsWith('video/'));
    files.forEach((file) => {
      const row = document.createElement('div');
      row.className = 'tv-bulk-row';
      row.innerHTML = `<span>${escapeHtml(file.name)}</span><span class="tv-badge tv-badge-draft tv-bulk-status">Queued</span>`;
      document.getElementById('tv-bulk-list').appendChild(row);
      uploadChain = uploadChain.then(() => uploadBulkFile(file, row).catch((error) => {
        setBulkStatus(row, 'Failed');
        console.error(error);
      }).then(() => loadList()));
    });
  };

  document.getElementById('tv-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    await save();
  });
  document.getElementById('tv-new').addEventListener('click', () => {
    fillForm({ title: '', description: '', category: '', aircraft: '', status: 'draft', has_video: false, has_poster: false, app_visible: false }, []);
    setMessage('');
    openModal('tv-edit-modal');
  });
  document.getElementById('tv-bulk').addEventListener('click', () => {
    defaultBulkRange();
    openModal('tv-bulk-modal');
  });
  document.getElementById('tv-add-grant').addEventListener('click', () => {
    renderGrants(collectGrants().concat([{ grant_type: 'all', grant_value: '', available_from_utc: '', available_until_utc: '' }]));
  });
  document.getElementById('tv-publish').addEventListener('click', async () => {
    if (!current || !current.has_video) {
      setMessage('Upload the video file before publishing it to the app.', 'err');
      return;
    }
    document.getElementById('status').value = 'published';
    await save('published');
  });
  document.getElementById('tv-archive').addEventListener('click', async () => {
    const uuid = document.getElementById('video-uuid').value;
    if (!uuid) return;
    const result = await post({ action: 'archive', video_uuid: uuid });
    if (result.ok) {
      fillForm(result.video, result.grants);
      await loadList();
      setMessage('Archived. It is no longer on the app.', 'ok');
    } else setMessage(result.error || 'Could not archive.', 'err');
  });
  document.getElementById('tv-delete').addEventListener('click', async () => {
    const uuid = document.getElementById('video-uuid').value;
    if (!uuid) return;
    const result = await post({ action: 'delete', video_uuid: uuid });
    if (result.ok) {
      fillForm({ title: '', description: '', category: '', aircraft: '', status: 'draft', has_video: false, has_poster: false, app_visible: false }, []);
      await loadList();
      closeModal('tv-edit-modal');
      setMessage('Deleted.', 'ok');
    } else setMessage(result.error || 'Could not delete.', 'err');
  });
  document.getElementById('video-file').addEventListener('change', (event) => queueUpload('video', event.target.files[0]));
  document.getElementById('poster-file').addEventListener('change', (event) => queueUpload('poster', event.target.files[0]));
  document.getElementById('tv-rewrite').addEventListener('click', async () => {
    const uuid = document.getElementById('video-uuid').value;
    if (!uuid || !(current && current.has_video)) {
      setMessage('Upload the video first.', 'err');
      return;
    }
    setMessage('Writing what students will learn from the video…');
    const result = await post({ action: 'generate_explanation', video_uuid: uuid, force: true });
    if (result.ok) {
      fillForm(result.video, result.grants || collectGrants());
      await loadList();
      setMessage(result.used_video ? 'Explanation rewritten from the video.' : 'Explanation written from the video title and topic.', 'ok');
    } else setMessage(result.error || 'Could not write the explanation.', 'err');
  });
  document.getElementById('thumb-regenerate').addEventListener('click', async () => {
    const uuid = document.getElementById('video-uuid').value;
    if (!uuid) {
      setMessage('Save and upload a video first.', 'err');
      return;
    }
    const result = await post({ action: 'regenerate_thumbnail', video_uuid: uuid });
    if (result.ok) {
      fillForm(result.video, result.grants || collectGrants());
      setMessage('Thumbnail updated from the next matching photograph.', 'ok');
    } else setMessage(result.error || 'Could not regenerate.', 'err');
  });
  document.getElementById('thumb-choose').addEventListener('click', async () => {
    const uuid = document.getElementById('video-uuid').value;
    if (!uuid) {
      setMessage('Save and upload a video first.', 'err');
      return;
    }
    const orientation = (current && current.orientation) || 'landscape';
    const data = await fetch('/admin/api/media_library_api.php?action=list&orientation=' + encodeURIComponent(orientation)).then((r) => r.json());
    const picker = document.getElementById('tv-picker');
    const grid = document.getElementById('tv-picker-grid');
    const assets = data.assets || [];
    grid.innerHTML = assets.map((asset) => `
      <img src="${asset.preview_url || ''}" data-uuid="${asset.asset_uuid}" alt="${asset.filename || ''}">
    `).join('') || '<p class="tv-muted">No matching photographs yet. Upload some in Media Library.</p>';
    grid.querySelectorAll('img[data-uuid]').forEach((img) => {
      img.addEventListener('click', async () => {
        const result = await post({ action: 'choose_thumbnail', video_uuid: uuid, asset_uuid: img.dataset.uuid });
        if (result.ok) {
          fillForm(result.video, result.grants || collectGrants());
          picker.classList.remove('open');
          setMessage('Thumbnail updated from the selected photograph.', 'ok');
        } else setMessage(result.error || 'Could not use that photograph.', 'err');
      });
    });
    picker.classList.add('open');
  });
  document.getElementById('tv-picker-close').addEventListener('click', () => {
    document.getElementById('tv-picker').classList.remove('open');
  });
  document.querySelectorAll('[data-close]').forEach((button) => {
    button.addEventListener('click', () => closeModal(button.dataset.close));
  });
  document.querySelectorAll('.tcc-modal-overlay').forEach((overlay) => {
    overlay.addEventListener('click', (event) => {
      if (event.target === overlay) closeModal(overlay.id);
    });
  });
  document.getElementById('tv-sort-pills').querySelectorAll('button[data-sort]').forEach((button) => {
    button.addEventListener('click', () => {
      sortMode = button.dataset.sort;
      document.getElementById('tv-sort-pills').querySelectorAll('.ia-chip').forEach((chip) => chip.classList.toggle('active', chip === button));
      renderCatalog();
    });
  });
  const drop = document.getElementById('tv-drop');
  drop.addEventListener('dragover', (event) => { event.preventDefault(); drop.classList.add('drag'); });
  drop.addEventListener('dragleave', () => drop.classList.remove('drag'));
  drop.addEventListener('drop', (event) => {
    event.preventDefault();
    drop.classList.remove('drag');
    queueBulkFiles(event.dataTransfer.files);
  });
  document.getElementById('bulk-files').addEventListener('change', (event) => queueBulkFiles(event.target.files));

  fillForm({ title: '', description: '', category: '', aircraft: '', status: 'draft', has_video: false, has_poster: false, app_visible: false }, []);
  loadList();
})();
</script>
<?php
cw_footer();
