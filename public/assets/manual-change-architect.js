(function () {
  'use strict';

  var root = document.querySelector('[data-mcw]');
  if (!root) return;

  var apiUrl = root.dataset.apiUrl || '';
  var csrfToken = root.dataset.csrfToken || '';
  var planId = Number(root.dataset.planId || 0);

  function toast(message, error) {
    var node = root.querySelector('[data-mcw-toast]');
    if (!node) return;
    node.textContent = message;
    node.className = 'mcw-toast' + (error ? ' is-error' : '');
    node.hidden = false;
    window.clearTimeout(Number(node.dataset.timer || 0));
    node.dataset.timer = String(window.setTimeout(function () {
      node.hidden = true;
    }, 4500));
  }

  async function request(action, data, formData) {
    var response;
    if (formData) {
      data.set('action', action);
      data.set('csrf_token', csrfToken);
      response = await fetch(apiUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: data
      });
    } else {
      response = await fetch(apiUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(Object.assign({
          action: action,
          csrf_token: csrfToken,
          plan_id: planId
        }, data || {}))
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
      throw new Error(String(result.error || result.message || 'The request could not be completed.'));
    }
    return result;
  }

  function busy(button, active, label) {
    if (!button) return;
    if (active) {
      button.dataset.label = button.textContent;
      button.textContent = label || 'Working…';
      button.disabled = true;
    } else {
      button.textContent = button.dataset.label || button.textContent;
      button.disabled = false;
    }
  }

  function setupFiles() {
    var input = root.querySelector('.mcw-upload input[type="file"]');
    var output = root.querySelector('[data-mcw-files]');
    if (!input || !output) return;
    var selected = [];
    function renderFiles() {
      output.innerHTML = '';
      selected.forEach(function (file, index) {
        var item = document.createElement('span');
        var name = document.createElement('span');
        name.textContent = file.name;
        var remove = document.createElement('button');
        remove.type = 'button';
        remove.textContent = 'Remove';
        remove.addEventListener('click', function () {
          selected.splice(index, 1);
          var transfer = new DataTransfer();
          selected.forEach(function (remaining) { transfer.items.add(remaining); });
          input.files = transfer.files;
          renderFiles();
        });
        item.appendChild(name);
        item.appendChild(remove);
        output.appendChild(item);
      });
      output.hidden = selected.length === 0;
    }
    input.addEventListener('change', function () {
      selected = Array.prototype.slice.call(input.files || []);
      renderFiles();
    });
  }

  function animateProgress(node) {
    var items = node.querySelectorAll('li');
    var index = 0;
    return window.setInterval(function () {
      if (index > 0 && items[index - 1]) {
        items[index - 1].classList.add('is-complete');
        items[index - 1].textContent = '✓ ' + items[index - 1].textContent.slice(2);
      }
      if (items[index]) {
        items[index].textContent = '● ' + items[index].textContent.slice(2);
      }
      if (index < items.length - 1) index += 1;
    }, 1300);
  }

  function setupIntake() {
    var form = root.querySelector('[data-mcw-intake]');
    if (!form) return;
    form.addEventListener('submit', async function (event) {
      event.preventDefault();
      var button = form.querySelector('[type="submit"]');
      var requestText = String(form.elements.change_request.value || '').trim();
      if (requestText.length < 20) {
        toast('Describe the requested change in at least 20 characters.', true);
        return;
      }
      var progress = root.querySelector('[data-mcw-analysis-progress]');
      progress.hidden = false;
      busy(button, true, 'Analyzing Change…');
      var timer = animateProgress(progress);
      try {
        var result = await request('analyze_change', new FormData(form), true);
        window.clearInterval(timer);
        toast('Analysis complete. Opening the amendment recommendation…');
        window.location.href = '/admin/books_manuals/change_architect.php?plan_id='
          + encodeURIComponent(result.plan_id);
      } catch (error) {
        window.clearInterval(timer);
        toast(error.message, true);
        busy(button, false);
      }
    });
  }

  function setupImpactDecisions() {
    root.addEventListener('click', async function (event) {
      var decisionButton = event.target.closest('[data-mcw-impact-decision]');
      if (decisionButton) {
        var impactId = Number(decisionButton.dataset.impactId || 0);
        var decision = String(decisionButton.dataset.mcwImpactDecision || '');
        var note = String((root.querySelector('[data-mcw-impact-note="' + impactId + '"]') || {}).value || '').trim();
        if (note.length < 5) {
          toast('Record a short rationale before modifying or rejecting this area.', true);
          return;
        }
        busy(decisionButton, true, 'Saving…');
        try {
          var result = await request('impact_decision', {
            impact_id: impactId,
            decision: decision,
            note: note
          });
          var status = root.querySelector('[data-mcw-impact-status="' + impactId + '"]');
          if (status) {
            status.textContent = result.result.status === 'dismissed' ? 'Rejected' : 'Modify';
            status.className = 'mcw-impact-status is-' + result.result.status;
          }
          toast(decision === 'REJECT' ? 'Amendment area rejected.' : 'Modification request recorded.');
        } catch (error) {
          toast(error.message, true);
        } finally {
          busy(decisionButton, false);
        }
        return;
      }

      var requestChanges = event.target.closest('[data-mcw-request-impact-changes]');
      if (requestChanges) {
        var firstDetails = root.querySelector('.mcw-impact-details');
        if (firstDetails) {
          firstDetails.open = true;
          firstDetails.scrollIntoView({ behavior: 'smooth', block: 'center' });
          var note = firstDetails.querySelector('textarea');
          if (note) window.setTimeout(function () { note.focus(); }, 350);
        }
        return;
      }

      var acceptAll = event.target.closest('[data-mcw-accept-impacts]');
      if (!acceptAll) return;
      busy(acceptAll, true, 'Accepting…');
      try {
        await request('accept_impact_analysis');
        toast('Impact analysis accepted.');
        window.setTimeout(function () { window.location.reload(); }, 450);
      } catch (error) {
        toast(error.message, true);
        busy(acceptAll, false);
      }
    });
  }

  function setupProgression() {
    var actions = [
      ['[data-mcw-accept-structure]', 'accept_structure', 'Accepting Structure…'],
      ['[data-mcw-accept-drafts]', 'accept_drafts', 'Accepting Amendments…'],
      ['[data-mcw-run-review]', 'run_independent_review', 'Reviewing Resulting Manual…'],
      ['[data-mcw-continue-apply]', 'continue_to_apply', 'Continuing…'],
      ['[data-mcw-apply]', 'apply_working_revision', 'Creating Working Revision…']
    ];
    actions.forEach(function (entry) {
      var button = root.querySelector(entry[0]);
      if (!button) return;
      button.addEventListener('click', async function () {
        busy(button, true, entry[2]);
        try {
          var result = await request(entry[1]);
          if (result.redirect) {
            window.location.href = result.redirect;
          } else {
            window.location.reload();
          }
        } catch (error) {
          toast(error.message, true);
          busy(button, false);
        }
      });
    });
  }

  function setupDraftDecisions() {
    root.addEventListener('click', async function (event) {
      var button = event.target.closest('[data-mcw-draft-decision]');
      if (!button) return;
      var section = button.closest('[data-mcw-draft-section]');
      if (!section) return;
      var decision = String(button.dataset.mcwDraftDecision || '');
      var sectionNumber = String(section.dataset.mcwDraftSection || '');
      busy(button, true, 'Saving…');
      try {
        await request('draft_decision', {
          section_number: sectionNumber,
          decision: decision
        });
        var status = section.querySelector('[data-mcw-draft-status]');
        if (status) {
          status.textContent = decision === 'accepted'
            ? 'Accepted'
            : decision.replace(/_/g, ' ');
          status.dataset.decision = decision;
        }
        if (decision === 'accepted') {
          section.open = false;
          var next = section.nextElementSibling;
          if (next && next.matches('[data-mcw-draft-section]')) next.open = true;
        }
        var statuses = root.querySelectorAll('[data-mcw-draft-status]');
        var allAccepted = Array.prototype.every.call(statuses, function (node) {
          return node.dataset.decision === 'accepted' || /· Accepted$/.test(node.textContent);
        });
        var continueButton = root.querySelector('[data-mcw-accept-drafts]');
        if (continueButton) continueButton.disabled = !allAccepted;
        toast(decision === 'accepted' ? 'Amendment accepted.' : 'Draft decision recorded.');
      } catch (error) {
        toast(error.message, true);
      } finally {
        busy(button, false);
      }
    });
  }

  function setupPendingAnalysis() {
    if (root.dataset.analysisPending !== '1' || planId <= 0) return;
    var consecutiveErrors = 0;
    async function check() {
      try {
        var result = await request('analysis_status');
        consecutiveErrors = 0;
        if (result.complete) {
          window.location.reload();
          return;
        }
        if (result.failed) {
          toast(result.message || 'Analysis could not be completed.', true);
          return;
        }
      } catch (error) {
        consecutiveErrors += 1;
        if (consecutiveErrors >= 4) {
          toast('Analysis is still running. This page will keep checking.', true);
        }
      }
      window.setTimeout(check, 3000);
    }
    window.setTimeout(check, 1200);
  }

  setupFiles();
  setupIntake();
  setupImpactDecisions();
  setupDraftDecisions();
  setupProgression();
  setupPendingAnalysis();
}());
