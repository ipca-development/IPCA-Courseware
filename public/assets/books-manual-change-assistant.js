(function () {
  'use strict';

  var root = document.querySelector('[data-bmca-app]');
  if (!root) return;

  var apiUrl = root.dataset.apiUrl;
  var csrfToken = root.dataset.csrfToken || '';
  var projectId = Number(root.dataset.projectId || 0);
  var pollTimer = null;

  function messageFrom(payload, fallback) {
    return payload && (payload.error || payload.message) ? String(payload.error || payload.message) : fallback;
  }

  async function request(action, data, options) {
    options = options || {};
    var response;
    if (options.formData) {
      data.set('action', action);
      if (csrfToken) data.set('csrf_token', csrfToken);
      response = await fetch(apiUrl, { method: 'POST', body: data, credentials: 'same-origin' });
    } else {
      var payload = Object.assign({ action: action }, data || {});
      if (options.mutation !== false && csrfToken) payload.csrf_token = csrfToken;
      response = await fetch(apiUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(payload)
      });
    }
    var result;
    try {
      result = await response.json();
    } catch (error) {
      throw new Error('The server returned an unreadable response.');
    }
    if (result.csrf_token) {
      csrfToken = String(result.csrf_token);
      root.dataset.csrfToken = csrfToken;
    }
    if (!response.ok || result.ok === false) {
      var apiError = new Error(messageFrom(result, 'The request could not be completed.'));
      apiError.payload = result;
      apiError.status = response.status;
      throw apiError;
    }
    return result;
  }

  function setBusy(button, busy, busyLabel) {
    if (!button) return;
    if (busy) {
      button.dataset.originalLabel = button.textContent;
      button.textContent = busyLabel || 'Working…';
      button.disabled = true;
    } else {
      button.textContent = button.dataset.originalLabel || button.textContent;
      button.disabled = false;
    }
  }

  function toast(text, type) {
    var node = root.querySelector('[data-bmca-toast]');
    if (!node) return;
    node.textContent = text;
    node.className = 'bmca-toast' + (type === 'error' ? ' is-error' : ' is-ok');
    node.hidden = false;
    window.clearTimeout(Number(node.dataset.timer || 0));
    node.dataset.timer = String(window.setTimeout(function () { node.hidden = true; }, 5000));
  }

  function selectedValues(selector) {
    return Array.prototype.map.call(root.querySelectorAll(selector + ':checked'), function (input) {
      return Number(input.value);
    }).filter(Boolean);
  }

  function setupFileLabels() {
    root.addEventListener('change', function (event) {
      var input = event.target.closest('input[type="file"]');
      if (!input) return;
      var label = input.closest('label');
      var name = label && label.querySelector('[data-bmca-file-name]');
      if (name) name.textContent = input.files && input.files[0] ? input.files[0].name : 'Choose a supported document';
    });
  }

  function setupProjectList() {
    var search = root.querySelector('[data-bmca-project-search]');
    if (search) {
      search.addEventListener('input', function () {
        var term = search.value.trim().toLowerCase();
        root.querySelectorAll('[data-project-search]').forEach(function (card) {
          card.hidden = term !== '' && card.dataset.projectSearch.indexOf(term) === -1;
        });
      });
    }

    var form = root.querySelector('[data-bmca-create-form]');
    if (!form) return;
    form.addEventListener('change', function (event) {
      var version = event.target.closest('input[name="version_ids[]"]');
      if (version) {
        version.closest('.bmca-version-option').classList.toggle('is-selected', version.checked);
      }
    });
    form.addEventListener('submit', async function (event) {
      event.preventDefault();
      var button = form.querySelector('[data-bmca-submit]');
      var errorNode = form.querySelector('[data-bmca-form-error]');
      var requestText = String(form.elements.request_text.value || '').trim();
      var title = String(form.elements.title.value || '').trim();
      if (!title) title = requestText.slice(0, 96).replace(/\s+\S*$/, '') || 'Manual change project';
      errorNode.hidden = true;
      setBusy(button, true, 'Creating project…');
      try {
        var created = await request('create_project', { name: title, description: requestText });
        var id = Number(created.project && created.project.id);
        if (!id) throw new Error('The project was created without an identifier.');

        var sourceFile = form.querySelector('[data-bmca-source-file]');
        var upload = new FormData();
        upload.set('project_id', String(id));
        upload.set('title', sourceFile.files && sourceFile.files[0] ? sourceFile.files[0].name : 'Typed change request');
        if (sourceFile.files && sourceFile.files[0]) upload.set('source', sourceFile.files[0]);
        else upload.set('source_text', requestText);
        await request('upload_source', upload, { formData: true });

        var selected = Array.prototype.map.call(form.querySelectorAll('[name="version_ids[]"]:checked'), function (option) {
          return Number(option.value);
        }).filter(Boolean);
        if (selected.length) {
          await request('set_scope', { project_id: id, version_ids: selected });
        }
        window.location.href = '/admin/books_manuals/change_project.php?id=' + encodeURIComponent(id);
      } catch (error) {
        errorNode.textContent = error.message;
        errorNode.hidden = false;
        setBusy(button, false);
      }
    });
  }

  function activateStage(stage) {
    root.querySelectorAll('[data-bmca-stage-target]').forEach(function (button) {
      button.classList.toggle('is-active', button.dataset.bmcaStageTarget === stage);
    });
    root.querySelectorAll('[data-bmca-stage]').forEach(function (panel) {
      var active = panel.dataset.bmcaStage === stage;
      panel.classList.toggle('is-active', active);
      panel.hidden = !active;
    });
    try { window.sessionStorage.setItem('bmca-stage-' + projectId, stage); } catch (error) {}
  }

  function setupStages() {
    root.addEventListener('click', function (event) {
      var target = event.target.closest('[data-bmca-stage-target]');
      if (target) activateStage(target.dataset.bmcaStageTarget);
    });
    var saved = '';
    try { saved = window.sessionStorage.getItem('bmca-stage-' + projectId) || ''; } catch (error) {}
    if (saved && root.querySelector('[data-bmca-stage="' + saved + '"]')) activateStage(saved);
  }

  function setupScope() {
    root.addEventListener('change', function (event) {
      var checkbox = event.target.closest('[data-bmca-scope-version]');
      if (checkbox) checkbox.closest('.bmca-scope-card').classList.toggle('is-selected', checkbox.checked);
    });
    var save = root.querySelector('[data-bmca-save-scope]');
    if (!save) return;
    save.addEventListener('click', async function () {
      setBusy(save, true, 'Saving scope…');
      try {
        await request('set_scope', { project_id: projectId, version_ids: selectedValues('[data-bmca-scope-version]') });
        toast('Manual scope saved.');
      } catch (error) {
        if (error.payload && error.payload.requires_revision) {
          toast('A selected released manual requires a new draft revision before analysis.', 'error');
        } else toast(error.message, 'error');
      } finally {
        setBusy(save, false);
      }
    });
  }

  function setupSource() {
    var save = root.querySelector('[data-bmca-save-source]');
    if (save) {
      save.addEventListener('click', async function () {
        var text = String(root.querySelector('[data-bmca-request-text]').value || '').trim();
        if (text.length < 10) return toast('Enter at least 10 characters for the source request.', 'error');
        setBusy(save, true, 'Saving…');
        try {
          await request('upload_source', { project_id: projectId, source_text: text, title: 'Typed change request' });
          toast('Typed request added as project evidence.');
        } catch (error) { toast(error.message, 'error'); }
        finally { setBusy(save, false); }
      });
    }
    var uploadButton = root.querySelector('[data-bmca-upload-source]');
    if (uploadButton) {
      uploadButton.addEventListener('click', async function () {
        var input = root.querySelector('[data-bmca-workspace-upload]');
        if (!input.files || !input.files[0]) return toast('Choose a source file first.', 'error');
        var data = new FormData();
        data.set('project_id', String(projectId));
        data.set('title', input.files[0].name);
        data.set('source', input.files[0]);
        setBusy(uploadButton, true, 'Uploading…');
        try {
          await request('upload_source', data, { formData: true });
          toast('Source uploaded. Reloading workspace…');
          window.setTimeout(function () { window.location.reload(); }, 700);
        } catch (error) { toast(error.message, 'error'); setBusy(uploadButton, false); }
      });
    }
  }

  function updateProgress(job, project) {
    job = job || {};
    project = project || {};
    var percent = Math.max(0, Math.min(100, Number(job.progress_percent || project.progress_percent || 0)));
    var status = String(job.status || project.status || 'draft').toLowerCase();
    var bar = root.querySelector('[data-bmca-progress-bar]');
    var value = root.querySelector('[data-bmca-progress-value]');
    var badge = root.querySelector('[data-bmca-status]');
    var detail = root.querySelector('[data-bmca-progress-detail]');
    if (bar) bar.style.width = percent + '%';
    if (value) value.textContent = percent + '%';
    if (badge) {
      badge.textContent = status.replace(/_/g, ' ').replace(/\b\w/g, function (letter) { return letter.toUpperCase(); });
      badge.className = 'bmca-status bmca-status--' + status.replace(/[^a-z0-9_-]/g, '-');
    }
    var isComposer = String(job.job_type || '') === 'compose';
    if (detail) detail.textContent = job.error_message || (status === 'completed'
      ? (isComposer ? 'Amendments composed. Loading controlled redlines…' : 'Analysis complete. Loading cited findings…')
      : (isComposer ? 'The composer is building coherent amendments from approved impact areas.' : 'The worker is analyzing scoped controlled content.'));
    return status;
  }

  async function pollProject() {
    try {
      var result = await request('project', { project_id: projectId }, { mutation: false });
      var status = updateProgress(result.job, result.project);
      if (status === 'completed') {
        window.clearInterval(pollTimer);
        window.location.reload();
      } else if (status === 'failed') {
        window.clearInterval(pollTimer);
        var start = root.querySelector('[data-bmca-start-analysis]');
        if (start) { start.disabled = false; start.textContent = 'Run analysis again'; }
        toast(messageFrom(result.job, 'Analysis failed.'), 'error');
      }
    } catch (error) {
      window.clearInterval(pollTimer);
      toast(error.message, 'error');
    }
  }

  function startPolling() {
    window.clearInterval(pollTimer);
    pollProject();
    pollTimer = window.setInterval(pollProject, 2500);
  }

  function setupAnalysis() {
    var start = root.querySelector('[data-bmca-start-analysis]');
    if (start) {
      start.addEventListener('click', async function () {
        setBusy(start, true, 'Starting analysis…');
        try {
          var result = await request('start_analysis', { project_id: projectId });
          updateProgress(result.job, { status: 'queued' });
          start.textContent = 'Analyzing…';
          startPolling();
        } catch (error) { toast(error.message, 'error'); setBusy(start, false); }
      });
    }
    var compose = root.querySelector('[data-bmca-start-compose]');
    if (compose) {
      compose.addEventListener('click', async function () {
        var ids = String(compose.dataset.impactAreaIds || '').split(',').map(Number).filter(function (id) { return id > 0; });
        if (!ids.length) return toast('Approve at least one impact area before composing amendments.', 'error');
        setBusy(compose, true, 'Starting composer…');
        try {
          var result = await request('start_compose', { project_id: projectId, impact_area_ids: ids });
          updateProgress(result.job, { status: 'queued' });
          compose.textContent = 'Composing amendments…';
          startPolling();
        } catch (error) { toast(error.message, 'error'); setBusy(compose, false); }
      });
    }
    if (root.dataset.polling === 'true') startPolling();
  }

  function applyFilters() {
    root.querySelectorAll('.bmca-stage').forEach(function (stage) {
      var filters = {};
      stage.querySelectorAll('[data-bmca-filter]').forEach(function (select) {
        if (select.value) filters[select.dataset.bmcaFilter] = select.value;
      });
      stage.querySelectorAll('[data-bmca-filter-item]').forEach(function (item) {
        var visible = Object.keys(filters).every(function (key) { return item.dataset[key] === filters[key]; });
        item.hidden = !visible;
      });
    });
  }

  function setupFiltersAndReview() {
    root.addEventListener('change', function (event) {
      if (event.target.matches('[data-bmca-filter]')) applyFilters();
    });
    root.addEventListener('click', function (event) {
      var queue = event.target.closest('[data-bmca-proposal-select]');
      if (queue) {
        var id = queue.dataset.bmcaProposalSelect;
        root.querySelectorAll('[data-bmca-proposal-select]').forEach(function (item) { item.classList.toggle('is-active', item === queue); });
        root.querySelectorAll('[data-bmca-proposal]').forEach(function (item) {
          var active = item.dataset.bmcaProposal === id;
          item.hidden = !active;
          item.classList.toggle('is-active', active);
        });
      }
      var edit = event.target.closest('[data-bmca-edit-toggle]');
      if (edit) {
        var proposal = edit.closest('[data-bmca-proposal]');
        proposal.querySelector('[data-bmca-edit-field]').hidden = false;
        proposal.querySelector('[data-bmca-decision="edit"]').hidden = false;
        edit.hidden = true;
      }
    });
  }

  function setupDecisions() {
    root.addEventListener('change', async function (event) {
      var reviewer = event.target.closest('[data-bmca-reviewer]');
      if (!reviewer) return;
      var proposal = reviewer.closest('[data-bmca-proposal]');
      reviewer.disabled = true;
      try {
        await request('assign_reviewer', {
          project_id: projectId,
          finding_id: Number(proposal.dataset.bmcaProposal),
          reviewer_user_id: Number(reviewer.value || 0)
        });
        toast('Reviewer assignment saved.');
      } catch (error) {
        toast(error.message, 'error');
      } finally {
        reviewer.disabled = false;
      }
    });

    root.addEventListener('click', async function (event) {
      var impactButton = event.target.closest('[data-bmca-impact-decision]');
      if (impactButton) {
        var impact = impactButton.closest('[data-bmca-impact-finding]');
        var impactRationale = String(impact.querySelector('[data-bmca-impact-rationale]').value || '').trim();
        var impactDecision = impactButton.dataset.bmcaImpactDecision;
        if (impactDecision === 'rejected' && impactRationale.length < 5) {
          return toast('Record a rationale before dismissing an impact.', 'error');
        }
        setBusy(impactButton, true, 'Saving…');
        try {
          await request('decision', {
            project_id: projectId,
            target_type: 'finding',
            target_id: Number(impact.dataset.bmcaImpactFinding),
            decision: impactDecision,
            note: impactRationale
          });
          var impactBadge = impact.querySelector('[data-bmca-impact-status]');
          impactBadge.textContent = impactDecision.charAt(0).toUpperCase() + impactDecision.slice(1);
          impactBadge.className = 'bmca-status bmca-status--' + impactDecision;
          toast('Impact decision recorded.');
          window.setTimeout(function () { window.location.reload(); }, 350);
        } catch (error) {
          toast(error.message, 'error');
        } finally {
          setBusy(impactButton, false);
        }
        return;
      }
      var button = event.target.closest('[data-bmca-decision]');
      if (!button) return;
      var proposal = button.closest('[data-bmca-proposal]');
      var choice = button.dataset.bmcaDecision;
      var rationale = String(proposal.querySelector('[data-bmca-decision-rationale]').value || '').trim();
      var editedText = proposal.querySelector('[data-bmca-edited-text]');
      if ((choice === 'edit' || choice === 'dismiss') && rationale.length < 5) {
        return toast('Record a rationale before editing or dismissing.', 'error');
      }
      var apiDecision = choice === 'dismiss' ? 'rejected' : 'approved';
      var note = rationale;
      if (choice === 'edit') note = rationale + '\n\nHuman-edited proposed text:\n' + String(editedText.value || '').trim();
      setBusy(button, true, 'Saving…');
      try {
        await request('decision', {
          project_id: projectId,
          target_type: 'finding',
          target_id: Number(proposal.dataset.bmcaProposal),
          decision: apiDecision,
          note: note,
          proposed_text: choice === 'edit' ? String(editedText.value || '').trim() : null
        });
        var visualStatus = choice === 'dismiss' ? 'dismissed' : (choice === 'edit' ? 'edited' : 'approved');
        var badge = proposal.querySelector('[data-bmca-decision-status]');
        badge.textContent = visualStatus.charAt(0).toUpperCase() + visualStatus.slice(1);
        badge.className = 'bmca-status bmca-status--' + visualStatus;
        var queue = root.querySelector('[data-bmca-proposal-select="' + proposal.dataset.bmcaProposal + '"]');
        if (queue) queue.dataset.status = visualStatus;
        toast('Decision recorded.');
        setBusy(button, false);
      } catch (error) { toast(error.message, 'error'); setBusy(button, false); }
    });
  }

  function downloadJson(value, filename) {
    var blob = new Blob([JSON.stringify(value, null, 2)], { type: 'application/json' });
    var url = URL.createObjectURL(blob);
    var link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  }

  function setupExportAndApply() {
    root.addEventListener('click', async function (event) {
      var createRevision = event.target.closest('[data-bmca-create-revision]');
      if (!createRevision) return;
      if (!window.confirm('Create a governed draft revision and replace this released version in the project scope? The project must then be analyzed again.')) return;
      setBusy(createRevision, true, 'Creating draft…');
      try {
        await request('create_revision', {
          project_id: projectId,
          version_id: Number(createRevision.dataset.bmcaCreateRevision)
        });
        toast('Draft revision created. Reloading the project for re-analysis.');
        window.setTimeout(function () { window.location.reload(); }, 800);
      } catch (error) {
        toast(error.message, 'error');
        setBusy(createRevision, false);
      }
    });

    var exportButton = root.querySelector('[data-bmca-export-manifest]');
    if (exportButton) exportButton.addEventListener('click', async function () {
      setBusy(exportButton, true, 'Exporting…');
      try {
        var result = await request('export_report', { project_id: projectId }, { mutation: false });
        downloadJson(result.report, 'manual-change-project-' + projectId + '-impact-report.json');
      } catch (error) { toast(error.message, 'error'); }
      finally { setBusy(exportButton, false); }
    });

    var apply = root.querySelector('[data-bmca-apply]');
    var modal = document.getElementById('bmca-apply-confirm');
    if (apply && modal) apply.addEventListener('click', function () {
      if (apply.disabled) return;
      if (typeof modal.showModal === 'function') modal.showModal(); else modal.setAttribute('open', 'open');
    });
    if (!modal) return;
    var acknowledgement = modal.querySelector('[data-bmca-apply-ack]');
    var rationale = modal.querySelector('[data-bmca-apply-rationale]');
    var confirm = modal.querySelector('[data-bmca-confirm-apply]');
    function validate() { confirm.disabled = !acknowledgement.checked || rationale.value.trim().length < 10; }
    acknowledgement.addEventListener('change', validate);
    rationale.addEventListener('input', validate);
    confirm.addEventListener('click', async function () {
      var findingIds = [];
      root.querySelectorAll('[data-bmca-proposal]').forEach(function (proposal) {
        var badge = proposal.querySelector('[data-bmca-decision-status]');
        if (badge && badge.textContent.trim().toLowerCase() === 'approved') findingIds.push(Number(proposal.dataset.bmcaProposal));
      });
      setBusy(confirm, true, 'Applying controlled changes…');
      try {
        var result = await request('apply', { project_id: projectId, finding_ids: findingIds, rationale: rationale.value.trim() });
        if (result.requires_revision) throw Object.assign(new Error('A scoped released manual requires a new draft revision before changes can be applied.'), { payload: result });
        toast('Approved change set applied. Reloading…');
        if (typeof modal.close === 'function') modal.close();
        window.setTimeout(function () { window.location.reload(); }, 900);
      } catch (error) { toast(error.message, 'error'); setBusy(confirm, false); }
    });
  }

  setupFileLabels();
  if (root.dataset.bmcaApp === 'projects') {
    setupProjectList();
  } else {
    setupStages();
    setupScope();
    setupSource();
    setupAnalysis();
    setupFiltersAndReview();
    setupDecisions();
    setupExportAndApply();
  }
}());
