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
.tv-grid { display: grid; grid-template-columns: 280px 1fr; gap: 16px; align-items: start; }
.tv-list button { display: block; width: 100%; text-align: left; margin: 0 0 8px; }
.tv-muted { color: #728198; }
.tv-kicker { font-size: 12px; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; color: #728198; }
.tv-field { margin: 0 0 12px; }
.tv-field label { display: block; font-size: 12px; font-weight: 700; margin: 0 0 4px; color: #728198; }
.tv-field input, .tv-field textarea, .tv-field select { width: 100%; }
.tv-grants { display: grid; gap: 10px; }
.tv-grant { display: grid; grid-template-columns: 140px 1fr 1fr 1fr auto; gap: 8px; align-items: end; }
.tv-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 16px; }
.tv-status { font-size: 12px; font-weight: 700; text-transform: uppercase; }
@media (max-width: 900px) {
  .tv-grid, .tv-grant { grid-template-columns: 1fr; }
}
</style>

<div class="tv-page">
  <div class="card">
    <div class="tv-kicker">IPCA App</div>
    <h2 style="margin:6px 0 8px;">Training Videos</h2>
    <p class="tv-muted">Private videos for the IPCA app. Access is time-based. Playback uses short-lived signed URLs, not the Community CDN.</p>
  </div>
  <div class="tv-grid">
    <div class="card">
      <button type="button" class="btn" id="tv-new">New video</button>
      <div id="tv-list" style="margin-top:12px;"></div>
    </div>
    <div class="card">
      <form id="tv-form">
        <input type="hidden" id="video-uuid">
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
          <label>Video file (MP4)</label>
          <input id="video-file" type="file" accept="video/mp4,video/quicktime">
          <p class="tv-muted" id="video-file-status">No video uploaded yet.</p>
        </div>
        <div class="tv-field">
          <label>Poster image</label>
          <input id="poster-file" type="file" accept="image/jpeg,image/png,image/webp">
          <p class="tv-muted" id="poster-file-status">No poster uploaded yet.</p>
        </div>
        <h3>Who can watch</h3>
        <p class="tv-muted">A person needs at least one currently active grant. Both access times are required and interpreted as UTC.</p>
        <div id="tv-grants" class="tv-grants"></div>
        <button type="button" class="btn" id="tv-add-grant">Add access</button>
        <div class="tv-actions">
          <button type="submit" class="btn">Save</button>
          <button type="button" class="btn" id="tv-archive">Archive</button>
          <button type="button" class="btn" id="tv-delete">Delete</button>
        </div>
        <p class="tv-muted" id="tv-message"></p>
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
  let options = { users: [], cohorts: [], programs: [], roles: [] };
  let current = null;

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

  const setMessage = (text) => { messageEl.textContent = text || ''; };

  const post = async (body) => {
    const response = await fetch(api, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({ csrf_token: csrf }, body)),
    });
    return response.json();
  };

  const loadList = async () => {
    const data = await fetch(api + '?action=list').then((r) => r.json());
    options = data.options || options;
    listEl.innerHTML = (data.videos || []).map((video) => `
      <button type="button" data-uuid="${video.video_uuid}">
        <strong>${video.title}</strong><br>
        <span class="tv-status">${video.status}</span>
        <span class="tv-muted"> · ${video.view_count} views</span>
      </button>
    `).join('') || '<p class="tv-muted">No videos yet.</p>';
    listEl.querySelectorAll('button[data-uuid]').forEach((button) => {
      button.addEventListener('click', () => loadVideo(button.dataset.uuid));
    });
  };

  const fillForm = (video, grants) => {
    current = video;
    document.getElementById('video-uuid').value = video.video_uuid || '';
    document.getElementById('title').value = video.title || '';
    document.getElementById('description').value = video.description || '';
    document.getElementById('status').value = video.status || 'draft';
    document.getElementById('video-file-status').textContent = video.has_video ? 'Video uploaded.' : 'No video uploaded yet.';
    document.getElementById('poster-file-status').textContent = video.has_poster ? 'Poster uploaded.' : 'No poster uploaded yet.';
    renderGrants(grants || []);
  };

  const loadVideo = async (uuid) => {
    const data = await fetch(api + '?action=detail&video_uuid=' + encodeURIComponent(uuid)).then((r) => r.json());
    fillForm(data.video, data.grants);
    setMessage('');
  };

  const save = async () => {
    const payload = {
      action: 'save',
      video_uuid: document.getElementById('video-uuid').value,
      title: document.getElementById('title').value,
      description: document.getElementById('description').value,
      status: document.getElementById('status').value,
      grants: collectGrants(),
    };
    const saved = await post(payload);
    if (!saved.ok) {
      setMessage(saved.error || 'Could not save.');
      return saved;
    }
    fillForm(saved.video, saved.grants);
    await loadList();
    setMessage('Saved.');
    return saved;
  };

  const upload = async (kind, file) => {
    if (!file) return;
    let uuid = document.getElementById('video-uuid').value;
    if (!uuid) {
      const saved = await save();
      if (!saved.ok) return;
      uuid = saved.video.video_uuid;
    }
    const presign = await post({
      action: kind === 'video' ? 'presign_video' : 'presign_poster',
      video_uuid: uuid,
      mime_type: file.type || (kind === 'video' ? 'video/mp4' : 'image/jpeg'),
      byte_size: file.size,
      filename: file.name,
    });
    if (!presign.ok) {
      setMessage(presign.error || 'Could not start upload.');
      return;
    }
    const headers = presign.headers || { 'Content-Type': file.type };
    const put = await fetch(presign.put_url, { method: 'PUT', headers, body: file });
    if (!put.ok) {
      setMessage('Upload failed.');
      return;
    }
    const complete = await post({
      action: kind === 'video' ? 'complete_video' : 'complete_poster',
      video_uuid: uuid,
    });
    if (!complete.ok) {
      setMessage(complete.error || 'Could not finish upload.');
      return;
    }
    fillForm(complete.video, collectGrants());
    await loadList();
    setMessage(kind === 'video' ? 'Video uploaded.' : 'Poster uploaded.');
  };

  document.getElementById('tv-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    await save();
  });
  document.getElementById('tv-new').addEventListener('click', () => {
    fillForm({ title: '', description: '', status: 'draft', has_video: false, has_poster: false }, []);
    setMessage('');
  });
  document.getElementById('tv-add-grant').addEventListener('click', () => {
    renderGrants(collectGrants().concat([{ grant_type: 'all', grant_value: '', available_from_utc: '', available_until_utc: '' }]));
  });
  document.getElementById('tv-archive').addEventListener('click', async () => {
    const uuid = document.getElementById('video-uuid').value;
    if (!uuid) return;
    const result = await post({ action: 'archive', video_uuid: uuid });
    if (result.ok) {
      fillForm(result.video, result.grants);
      await loadList();
      setMessage('Archived.');
    } else setMessage(result.error || 'Could not archive.');
  });
  document.getElementById('tv-delete').addEventListener('click', async () => {
    const uuid = document.getElementById('video-uuid').value;
    if (!uuid) return;
    const result = await post({ action: 'delete', video_uuid: uuid });
    if (result.ok) {
      fillForm({ title: '', description: '', status: 'draft', has_video: false, has_poster: false }, []);
      await loadList();
      setMessage('Deleted.');
    } else setMessage(result.error || 'Could not delete.');
  });
  document.getElementById('video-file').addEventListener('change', (event) => upload('video', event.target.files[0]));
  document.getElementById('poster-file').addEventListener('change', (event) => upload('poster', event.target.files[0]));

  fillForm({ title: '', description: '', status: 'draft', has_video: false, has_poster: false }, []);
  loadList();
})();
</script>
<?php
cw_footer();
