<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingFoundationService.php';

$user = compliance_require_access($pdo);
$versionId = isset($_GET['version_id']) ? (int)$_GET['version_id'] : 0;

$foundation = new ControlledPublishingFoundationService($pdo);

if ($versionId <= 0) {
    cw_header('Compliance · DOCX Import');
    compliance_page_open(array(
        'overline' => 'Compliance · Controlled publishing',
        'title' => 'Manual DOCX import',
        'back' => array('href' => '/admin/compliance/controlled_books.php', 'label' => 'All books'),
    ));
    echo '<section class="cmp-card"><p style="margin:0;">Provide ?version_id=…</p></section>';
    compliance_page_close();
    cw_footer();
    return;
}

$version = $foundation->getVersion($versionId);
if ($version === null) {
    cw_header('Compliance · DOCX Import');
    compliance_page_open(array(
        'overline' => 'Compliance · Controlled publishing',
        'title' => 'Version not found',
        'back' => array('href' => '/admin/compliance/controlled_books.php', 'label' => 'All books'),
    ));
    echo '<section class="cmp-card"><p style="margin:0;">Unknown version id.</p></section>';
    compliance_page_close();
    cw_footer();
    return;
}

$isReleased = (string)($version['lifecycle_status'] ?? '') === 'released';
$bookLabel = (string)$version['book_key'] . ' ' . (string)$version['version_label'];

cw_header('Compliance · DOCX Import · ' . $bookLabel);

compliance_page_open(array(
    'overline' => 'Compliance · Controlled publishing',
    'title' => 'Import manual from Word (DOCX)',
    'description' => 'Upload one DOCX per manual Part (Part 0–4). Content is written as normal editor blocks with your book styles, tables, lists, and images.',
    'back' => array(
        'href' => '/admin/compliance/controlled_book_version.php?id=' . $versionId,
        'label' => $bookLabel,
    ),
    'actions' => array(
        array(
            'label' => 'Open editor',
            'href' => '/admin/compliance/controlled_book_editor.php?version_id=' . $versionId,
            'variant' => 'secondary',
        ),
    ),
));

?>
<section class="cmp-card" id="cp-docx-import">
  <h2 style="margin:0 0 8px;">Upload Parts</h2>
  <p style="margin:0 0 16px;font-size:13px;color:#64748b;max-width:720px;">
    Export each Part from Apple Pages as <strong>Word (.docx)</strong>. Embedded per-Part TOCs are skipped automatically.
    Tables use your book table styles; images are uploaded and inserted as normal image blocks.
  </p>

  <?php if ($isReleased): ?>
    <p style="margin:0;color:#b45309;">This version is released and cannot be imported. Create a new draft first.</p>
  <?php else: ?>
    <form id="cp-docx-import-form" enctype="multipart/form-data" style="display:grid;gap:12px;max-width:720px;">
      <input type="hidden" name="version_id" value="<?= (int)$versionId ?>">
      <?php for ($part = 0; $part <= 4; $part++): ?>
        <label style="display:grid;gap:6px;padding:12px;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc;">
          <span style="font-size:13px;font-weight:700;color:#0f2744;">Part <?= $part ?><?= $part === 0 ? ' — Manual Administration' : '' ?></span>
          <input type="file" name="part_<?= $part ?>" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
        </label>
      <?php endfor; ?>

      <label style="display:flex;gap:8px;align-items:center;font-size:13px;color:#334155;">
        <input type="checkbox" name="force" value="1" checked>
        Don't Import Duplicate Content
      </label>

      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:4px;">
        <button type="button" id="cp-docx-preview-btn">Preview import</button>
        <button type="button" id="cp-docx-apply-btn" style="background:#0f2744;color:#fff;border:none;padding:10px 16px;border-radius:8px;cursor:pointer;">
          Import into book
        </button>
      </div>
    </form>

    <div id="cp-docx-import-status" style="margin-top:16px;font-size:13px;color:#334155;"></div>
    <div id="cp-docx-import-progress" role="progressbar" aria-label="DOCX import progress"
         aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"
         style="display:none;margin-top:14px;max-width:720px;">
      <div style="display:flex;justify-content:space-between;gap:16px;margin-bottom:6px;font-size:12px;color:#475569;">
        <strong id="cp-docx-import-phase">Preparing import…</strong>
        <span id="cp-docx-import-percent">0%</span>
      </div>
      <div style="height:12px;overflow:hidden;border-radius:999px;background:#e2e8f0;border:1px solid #cbd5e1;">
        <div id="cp-docx-import-progress-fill"
             style="width:0;height:100%;border-radius:inherit;background:#0f2744;transition:width .25s ease;"></div>
      </div>
      <p id="cp-docx-import-detail" style="margin:7px 0 0;font-size:12px;color:#64748b;"></p>
    </div>
    <pre id="cp-docx-import-report" style="margin-top:12px;padding:12px;background:#0f172a;color:#e2e8f0;border-radius:8px;font-size:12px;overflow:auto;max-height:360px;display:none;"></pre>
  <?php endif; ?>
</section>

<script>
(function () {
  var form = document.getElementById('cp-docx-import-form');
  if (!form) return;

  var statusEl = document.getElementById('cp-docx-import-status');
  var reportEl = document.getElementById('cp-docx-import-report');
  var progressEl = document.getElementById('cp-docx-import-progress');
  var progressFillEl = document.getElementById('cp-docx-import-progress-fill');
  var progressPhaseEl = document.getElementById('cp-docx-import-phase');
  var progressPercentEl = document.getElementById('cp-docx-import-percent');
  var progressDetailEl = document.getElementById('cp-docx-import-detail');
  var previewBtn = document.getElementById('cp-docx-preview-btn');
  var applyBtn = document.getElementById('cp-docx-apply-btn');
  var apiUrl = '/admin/api/controlled_book_docx_import_api.php';
  var activeToken = '';
  var pollTimer = null;
  var pollAttempts = 0;

  function hasAnyFile() {
    var inputs = form.querySelectorAll('input[type="file"]');
    for (var i = 0; i < inputs.length; i++) {
      if (inputs[i].files && inputs[i].files.length > 0) return true;
    }
    return false;
  }

  function setStatus(msg, isError) {
    statusEl.textContent = msg || '';
    statusEl.style.color = isError ? '#b45309' : '#334155';
  }

  function setBusy(busy) {
    previewBtn.disabled = !!busy;
    applyBtn.disabled = !!busy;
  }

  function setProgress(percent, phase, detail, state) {
    percent = Math.max(0, Math.min(100, Number(percent) || 0));
    progressEl.style.display = 'block';
    progressEl.setAttribute('aria-valuenow', String(percent));
    progressFillEl.style.width = percent + '%';
    progressFillEl.style.background = state === 'failed'
      ? '#b91c1c'
      : (state === 'completed' ? '#15803d' : '#0f2744');
    progressPhaseEl.textContent = phase || 'Importing document…';
    progressPercentEl.textContent = Math.round(percent) + '%';
    progressDetailEl.textContent = detail || '';
  }

  function showReport(obj) {
    reportEl.style.display = 'block';
    reportEl.textContent = JSON.stringify(obj, null, 2);
  }

  function buildFormData(action, jobToken) {
    var fd = new FormData(form);
    fd.append('action', action);
    if (jobToken) fd.append('job_token', jobToken);
    if (!fd.has('force')) {
      fd.append('force', '0');
    }
    return fd;
  }

  function createJobToken() {
    var bytes = new Uint8Array(24);
    window.crypto.getRandomValues(bytes);
    return Array.prototype.map.call(bytes, function (value) {
      return value.toString(16).padStart(2, '0');
    }).join('');
  }

  function stopPolling() {
    if (pollTimer) window.clearTimeout(pollTimer);
    pollTimer = null;
  }

  function finishImport(token, result) {
    if (token !== activeToken) return;
    stopPolling();
    activeToken = '';
    setBusy(false);
    setProgress(100, 'Import complete', 'The manual is ready in the editor.', 'completed');
    setStatus('Import complete. Open the editor to review content.');
    showReport(result || {});
  }

  function failImport(token, message, detail) {
    if (token !== activeToken) return;
    stopPolling();
    activeToken = '';
    setBusy(false);
    var fullMessage = message || 'The server could not complete the import.';
    setProgress(100, 'Import failed', fullMessage, 'failed');
    setStatus(fullMessage, true);
    showReport(detail || { error: fullMessage });
  }

  function scheduleStatusPoll(token) {
    if (token !== activeToken) return;
    pollTimer = window.setTimeout(function () { pollImportStatus(token); }, 1000);
  }

  function pollImportStatus(token) {
    if (token !== activeToken) return;
    pollAttempts++;
    fetch(apiUrl + '?action=import_status&token=' + encodeURIComponent(token), {
      credentials: 'same-origin',
      cache: 'no-store'
    })
      .then(function (res) {
        return res.text().then(function (text) {
          var data = null;
          try {
            data = text ? JSON.parse(text) : null;
          } catch (error) {
            throw new Error('Progress endpoint returned HTTP ' + res.status + ' with an invalid response.');
          }
          return { response: res, data: data };
        });
      })
      .then(function (packet) {
        if (token !== activeToken) return;
        if (packet.response.status === 404 && packet.data && packet.data.pending) {
          if (pollAttempts > 900) {
            failImport(token, 'The server did not start the import within 15 minutes.', packet.data);
            return;
          }
          scheduleStatusPoll(token);
          return;
        }
        if (!packet.response.ok || !packet.data || !packet.data.ok) {
          throw new Error(
            (packet.data && packet.data.error)
              || ('Progress request failed with HTTP ' + packet.response.status + '.')
          );
        }
        var status = packet.data.status || {};
        setProgress(
          status.percent || 0,
          status.phase ? status.phase.replaceAll('_', ' ') : 'Importing document…',
          status.message || '',
          status.status || 'running'
        );
        if (status.status === 'completed') {
          finishImport(token, status.result || {});
          return;
        }
        if (status.status === 'failed') {
          failImport(
            token,
            status.error || status.message || 'The server could not complete the import.',
            status
          );
          return;
        }
        scheduleStatusPoll(token);
      })
      .catch(function (error) {
        if (token !== activeToken) return;
        if (pollAttempts > 900) {
          failImport(
            token,
            'Import status could not be confirmed: ' + (error.message || String(error)),
            { error: error.message || String(error) }
          );
          return;
        }
        scheduleStatusPoll(token);
      });
  }

  function previewImport() {
    if (!hasAnyFile()) {
      setStatus('Select at least one Part DOCX file.', true);
      return;
    }
    setBusy(true);
    setStatus('Analyzing DOCX files…');
    progressEl.style.display = 'none';
    reportEl.style.display = 'none';
    fetch(apiUrl, {
      method: 'POST',
      body: buildFormData('preview_docx_import', ''),
      credentials: 'same-origin'
    })
      .then(function (res) {
        return res.text().then(function (text) {
          var data = null;
          try {
            data = text ? JSON.parse(text) : null;
          } catch (error) {
            throw new Error('Preview returned HTTP ' + res.status + ' with an invalid response.');
          }
          if (!res.ok || !data || !data.ok) {
            throw new Error(
              (data && data.error) || ('Preview failed with HTTP ' + res.status + '.')
            );
          }
          return data;
        });
      })
      .then(function (data) {
        setStatus('Preview ready. Review counts below, then click Import into book.');
        showReport(data.preview || data);
      })
      .catch(function (error) {
        setStatus('Preview failed: ' + (error.message || String(error)), true);
        showReport({ error: error.message || String(error) });
      })
      .finally(function () {
        setBusy(false);
      });
  }

  function applyImport() {
    if (!hasAnyFile()) {
      setStatus('Select at least one Part DOCX file.', true);
      return;
    }
    var token = createJobToken();
    activeToken = token;
    pollAttempts = 0;
    stopPolling();
    setBusy(true);
    reportEl.style.display = 'none';
    setStatus('Uploading DOCX files…');
    setProgress(0, 'Uploading documents', 'Sending the selected Parts to the server…', 'running');
    scheduleStatusPoll(token);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', apiUrl, true);
    xhr.withCredentials = true;
    xhr.upload.addEventListener('progress', function (event) {
      if (token !== activeToken || !event.lengthComputable) return;
      var uploadPercent = Math.min(10, Math.round((event.loaded / event.total) * 10));
      setProgress(
        uploadPercent,
        'Uploading documents',
        'Uploaded ' + Math.round(event.loaded / 1024) + ' of '
          + Math.round(event.total / 1024) + ' KB.',
        'running'
      );
    });
    xhr.addEventListener('load', function () {
      if (token !== activeToken) return;
      var data = null;
      try {
        data = xhr.responseText ? JSON.parse(xhr.responseText) : null;
      } catch (error) {
        failImport(
          token,
          'The server returned HTTP ' + xhr.status + ' with an invalid response.',
          { http_status: xhr.status, response: xhr.responseText || '' }
        );
        return;
      }
      if (xhr.status >= 200 && xhr.status < 300 && data && data.ok) {
        finishImport(token, data.result || {});
        return;
      }
      failImport(
        token,
        (data && data.error)
          ? 'Import failed: ' + data.error
          : 'Import failed with HTTP ' + xhr.status + '.',
        data || { http_status: xhr.status }
      );
    });
    xhr.addEventListener('error', function () {
      if (token !== activeToken) return;
      setStatus('The connection was interrupted. The server is still being checked…', false);
      setProgress(
        Number(progressEl.getAttribute('aria-valuenow')) || 10,
        'Connection interrupted',
        'Waiting for the server to confirm whether the import completed.',
        'running'
      );
    });
    xhr.addEventListener('abort', function () {
      if (token !== activeToken) return;
      setStatus('The request closed. Checking the server import status…', false);
    });
    xhr.send(buildFormData('apply_docx_import', token));
  }

  previewBtn.addEventListener('click', function () {
    previewImport();
  });

  applyBtn.addEventListener('click', function () {
    if (!window.confirm('Import uploaded Parts into this book version? Existing author blocks in affected sections will be replaced.')) {
      return;
    }
    applyImport();
  });
})();
</script>
<?php

compliance_page_close();
cw_footer();
