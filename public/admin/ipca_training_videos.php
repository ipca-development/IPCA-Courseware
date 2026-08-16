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
<style>
.tv-page { max-width: 1100px; }
.tv-grid { display: grid; grid-template-columns: 300px 1fr; gap: 16px; align-items: start; }
.tv-list button { display: block; width: 100%; text-align: left; margin: 0 0 8px; }
.tv-list button.tv-selected { box-shadow: inset 0 0 0 2px #1f6feb; }
.tv-muted { color: #728198; }
.tv-kicker { font-size: 12px; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; color: #728198; }
.tv-field { margin: 0 0 12px; }
.tv-field label { display: block; font-size: 12px; font-weight: 700; margin: 0 0 4px; color: #728198; }
.tv-field input, .tv-field textarea, .tv-field select { width: 100%; }
.tv-grants { display: grid; gap: 10px; }
.tv-grant { display: grid; grid-template-columns: 140px 1fr 1fr 1fr auto; gap: 8px; align-items: end; }
.tv-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 16px; }
.tv-status { font-size: 12px; font-weight: 700; text-transform: uppercase; }
.tv-badge { display: inline-block; font-size: 11px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; padding: 2px 8px; border-radius: 999px; }
.tv-badge-live { background: #d8f3dc; color: #0f6d32; }
.tv-badge-draft { background: #eef2f6; color: #4b5d73; }
.tv-badge-warn { background: #fff3cd; color: #8a6d00; }
.tv-banner { padding: 10px 12px; border-radius: 8px; margin: 0 0 14px; font-weight: 700; }
.tv-banner-live { background: #d8f3dc; color: #0f6d32; }
.tv-banner-draft { background: #eef2f6; color: #4b5d73; }
.tv-banner-warn { background: #fff3cd; color: #8a6d00; }
.tv-progress { height: 8px; background: #e6edf5; border-radius: 999px; overflow: hidden; margin: 8px 0 0; }
.tv-progress[hidden] { display: none; }
.tv-progress-bar { height: 100%; width: 0; background: #1f6feb; transition: width .12s linear; }
.tv-ok { color: #0f6d32; font-weight: 700; }
.tv-err { color: #b42318; font-weight: 700; }
@media (max-width: 900px) {
  .tv-grid, .tv-grant { grid-template-columns: 1fr; }
}
</style>

<div class="tv-page">
  <div class="card">
    <div class="tv-kicker">IPCA App</div>
    <h2 style="margin:6px 0 8px;">Training Videos</h2>
    <p class="tv-muted">Private videos for the IPCA app. Upload the poster image and video one after the other; wait for each transfer to finish. A video is on the app only after the file is on the server and status is Published.</p>
  </div>
  <div class="tv-grid">
    <div class="card">
      <button type="button" class="btn" id="tv-new">New video</button>
      <div id="tv-list" style="margin-top:12px;"></div>
    </div>
    <div class="card">
      <form id="tv-form">
        <input type="hidden" id="video-uuid">
        <div id="tv-visibility" class="tv-banner tv-banner-draft">Draft — upload a video file, then publish.</div>
        <div class="tv-field"><label>Title</label><input id="title" required maxlength="255"></div>
        <div class="tv-field"><label>Description</label><textarea id="description" rows="4"></textarea></div>
        <div class="tv-field">
          <label>Status</label>
          <select id="status">
            <option value="draft">Draft</option>
            <option value="published">Published</option>
            <option value="archived">Archived</option>
          </select>
        </div>
        <div class="tv-field">
          <label>Poster image</label>
          <input id="poster-file" type="file" accept="image/jpeg,image/png,image/webp">
          <div class="tv-progress" id="poster-progress" hidden><div class="tv-progress-bar" id="poster-progress-bar"></div></div>
          <p class="tv-muted" id="poster-file-status">No poster uploaded yet.</p>
        </div>
        <div class="tv-field">
          <label>Video file (MP4)</label>
          <input id="video-file" type="file" accept="video/mp4,video/quicktime">
          <div class="tv-progress" id="video-progress" hidden><div class="tv-progress-bar" id="video-progress-bar"></div></div>
          <p class="tv-muted" id="video-file-status">No video uploaded yet.</p>
        </div>
        <h3>Who can watch</h3>
        <p class="tv-muted">A person needs at least one currently active grant. Both access times are required and interpreted as UTC.</p>
        <div id="tv-grants" class="tv-grants"></div>
        <button type="button" class="btn" id="tv-add-grant">Add access</button>
        <div class="tv-actions">
          <button type="submit" class="btn">Save</button>
          <button type="button" class="btn" id="tv-publish">Publish to app</button>
          <button type="button" class="btn" id="tv-archive">Archive</button>
          <button type="button" class="btn" id="tv-delete">Delete</button>
        </div>
        <p id="tv-message" class="tv-muted"></p>
      </form>
    </div>
  </div>
</div>

<script>
(() => {
  const api = '/admin/api/training_videos_api.php';
  const csrf = <?= json_encode($csrf) ?>;
  const listEl = document.getElementById('tv-list');
  const grantsEl = document.getElementById('tv-grants');
  const messageEl = document.getElementById('tv-message');
  const visibilityEl = document.getElementById('tv-visibility');
  let options = { users: [], cohorts: [], programs: [], roles: [] };
  let current = null;
  let uploadChain = Promise.resolve();
  let busy = false;

  const formatBytes = (n) => {
    const value = Number(n) || 0;
    if (value < 1024) return value + ' B';
    if (value < 1048576) return (value / 1024).toFixed(1) + ' KB';
    if (value < 1073741824) return (value / 1048576).toFixed(1) + ' MB';
    return (value / 1073741824).toFixed(2) + ' GB';
  };

  const visibilityLabel = (video) => {
    if (video && video.app_visible) return 'On the app';
    if (video && video.status === 'published' && !video.has_video) return 'Published, missing file';
    if (video && video.status === 'archived') return 'Archived';
    if (video && video.has_video) return 'Draft · file ready';
    return 'Draft · no file';
  };

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
    return (video && video.has_poster) ? 'Poster image is on the server.' : 'No poster uploaded yet.';
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
      return `<option value="${item.id}"${selected}>${label}</option>`;
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
        <div class="tv-field"><label>Available from</label><input class="grant-from" type="datetime-local" required value="${toLocal(grant.available_from_utc)}"></div>
        <div class="tv-field"><label>Available until</label><input class="grant-until" type="datetime-local" required value="${toLocal(grant.available_until_utc)}"></div>
        <button type="button" class="btn grant-remove">Remove</button>
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

  const putWithProgress = (url, headers, file, onProgress) => new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('PUT', url);
    Object.keys(headers || {}).forEach((key) => xhr.setRequestHeader(key, headers[key]));
    xhr.upload.onprogress = (event) => {
      if (event.lengthComputable) onProgress(event.loaded, event.total);
    };
    xhr.onload = () => {
      if (xhr.status >= 200 && xhr.status < 300) resolve();
      else reject(new Error('Upload failed (' + xhr.status + ').'));
    };
    xhr.onerror = () => reject(new Error('Upload failed. Check the connection and try again.'));
    xhr.send(file);
  });

  const loadList = async () => {
    const data = await fetch(api + '?action=list').then((r) => r.json());
    options = data.options || options;
    const selected = document.getElementById('video-uuid').value;
    listEl.innerHTML = (data.videos || []).map((video) => `
      <button type="button" data-uuid="${video.video_uuid}" class="${video.video_uuid === selected ? 'tv-selected' : ''}">
        <strong>${video.title || 'Untitled'}</strong><br>
        <span class="tv-badge ${video.app_visible ? 'tv-badge-live' : (video.has_video ? 'tv-badge-warn' : 'tv-badge-draft')}">${visibilityLabel(video)}</span>
        <span class="tv-muted"> · ${video.view_count || 0} views</span>
      </button>
    `).join('') || '<p class="tv-muted">No videos yet.</p>';
    listEl.querySelectorAll('button[data-uuid]').forEach((button) => {
      button.addEventListener('click', () => loadVideo(button.dataset.uuid));
    });
  };

  const fillForm = (video, grants) => {
    current = video || {};
    document.getElementById('video-uuid').value = current.video_uuid || '';
    document.getElementById('title').value = current.title || '';
    document.getElementById('description').value = current.description || '';
    document.getElementById('status').value = current.status || 'draft';
    document.getElementById('video-file-status').textContent = fileStatus('video', current);
    document.getElementById('poster-file-status').textContent = fileStatus('poster', current);
    setVisibility(current);
    renderGrants(grants || []);
  };

  const loadVideo = async (uuid) => {
    const data = await fetch(api + '?action=detail&video_uuid=' + encodeURIComponent(uuid)).then((r) => r.json());
    fillForm(data.video, data.grants);
    setMessage('');
    await loadList();
  };

  const save = async (statusOverride) => {
    const payload = {
      action: 'save',
      video_uuid: document.getElementById('video-uuid').value,
      title: document.getElementById('title').value,
      description: document.getElementById('description').value,
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
      setProgress(kind, 0, 'Preparing ' + kind + ' upload…');
      setMessage('Uploading ' + kind + '…');
      const presign = await post({
        action: kind === 'video' ? 'presign_video' : 'presign_poster',
        video_uuid: uuid,
        mime_type: mime,
        byte_size: file.size,
        filename: file.name,
      });
      if (!presign.ok) {
        setProgress(kind, null);
        setMessage(presign.error || 'Could not start upload.', 'err');
        return;
      }
      await putWithProgress(presign.put_url, presign.headers || { 'Content-Type': mime }, file, (loaded, total) => {
        const pct = total ? Math.round((loaded / total) * 100) : 0;
        setProgress(kind, total ? loaded / total : 0, 'Uploading ' + kind + '… ' + pct + '% · ' + formatBytes(loaded) + ' of ' + formatBytes(total));
      });
      setProgress(kind, 1, 'Finishing ' + kind + ' upload…');
      const complete = await post({
        action: kind === 'video' ? 'complete_video' : 'complete_poster',
        video_uuid: uuid,
        mime_type: mime,
        byte_size: file.size,
      });
      setProgress(kind, null);
      if (!complete.ok) {
        setMessage(complete.error || 'The file reached storage, but the platform could not mark it as uploaded. Save and try completing again.', 'err');
        return;
      }
      fillForm(complete.video, collectGrants());
      await loadList();
      if (kind === 'video' && complete.video.app_visible) {
        setMessage('Video uploaded and published on the app.', 'ok');
      } else if (kind === 'video') {
        setMessage('Video file is on the server. Publish to show it in the app.', 'ok');
      } else {
        setMessage('Poster uploaded. You can upload the video next.', 'ok');
      }
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

  document.getElementById('tv-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    await save();
  });
  document.getElementById('tv-new').addEventListener('click', () => {
    fillForm({ title: '', description: '', status: 'draft', has_video: false, has_poster: false, app_visible: false }, []);
    setMessage('');
    loadList();
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
      fillForm({ title: '', description: '', status: 'draft', has_video: false, has_poster: false, app_visible: false }, []);
      await loadList();
      setMessage('Deleted.', 'ok');
    } else setMessage(result.error || 'Could not delete.', 'err');
  });
  document.getElementById('video-file').addEventListener('change', (event) => queueUpload('video', event.target.files[0]));
  document.getElementById('poster-file').addEventListener('change', (event) => queueUpload('poster', event.target.files[0]));

  fillForm({ title: '', description: '', status: 'draft', has_video: false, has_poster: false, app_visible: false }, []);
  loadList();
})();
</script>
<?php
cw_footer();
