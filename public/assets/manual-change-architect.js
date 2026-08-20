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
    var panel = root.querySelector('[data-mcw-impact-feedback]');
    var areaSelect = root.querySelector('[data-mcw-feedback-area]');
    var feedbackText = root.querySelector('[data-mcw-feedback-text]');

    function showFeedback(impactId) {
      if (!panel) return;
      panel.hidden = false;
      if (areaSelect) areaSelect.value = impactId ? String(impactId) : '';
      panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
      if (feedbackText) window.setTimeout(function () { feedbackText.focus(); }, 350);
    }

    root.addEventListener('click', async function (event) {
      var flagButton = event.target.closest('[data-mcw-flag-impact]');
      if (flagButton) {
        showFeedback(Number(flagButton.dataset.mcwFlagImpact || 0));
        return;
      }

      var requestChanges = event.target.closest('[data-mcw-request-impact-changes]');
      if (requestChanges) {
        showFeedback(0);
        return;
      }

      var openResolution = event.target.closest('[data-mcw-open-review-resolution]');
      if (openResolution) {
        var resolutionPanel = root.querySelector('[data-mcw-review-resolution]');
        if (resolutionPanel) {
          resolutionPanel.hidden = false;
          resolutionPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        return;
      }

      var closeResolution = event.target.closest('[data-mcw-close-review-resolution]');
      if (closeResolution) {
        var openPanel = root.querySelector('[data-mcw-review-resolution]');
        if (openPanel) openPanel.hidden = true;
        return;
      }

      var architectResolve = event.target.closest('[data-mcw-architect-resolve]');
      if (architectResolve) {
        showFeedback(Number(architectResolve.dataset.mcwArchitectResolve || 0));
        return;
      }

      var saveDisposition = event.target.closest('[data-mcw-save-disposition]');
      if (saveDisposition) {
        var dispositionBlocker = saveDisposition.closest('[data-mcw-review-blocker]');
        var disposition = dispositionBlocker.querySelector('[data-mcw-resolution-disposition]');
        var dispositionRationale = dispositionBlocker.querySelector('[data-mcw-resolution-rationale]');
        var mergeTarget = dispositionBlocker.querySelector('[data-mcw-resolution-merge-target]');
        var rationaleText = String((dispositionRationale || {}).value || '').trim();
        if (rationaleText.length < 20) {
          toast('Record a specific rationale of at least 20 characters.', true);
          return;
        }
        busy(saveDisposition, true, 'Recording…');
        try {
          await request('resolve_review_blocker', {
            blocker_id: String(dispositionBlocker.dataset.mcwReviewBlocker || ''),
            resolution_type: 'HUMAN_DISPOSITION',
            disposition: String((disposition || {}).value || ''),
            rationale: rationaleText,
            merge_target_section: String((mergeTarget || {}).value || '')
          });
          toast('Governed disposition recorded. Re-running the quality gate…');
          window.setTimeout(function () { window.location.reload(); }, 350);
        } catch (error) {
          toast(error.message, true);
          busy(saveDisposition, false);
        }
        return;
      }

      var acceptException = event.target.closest('[data-mcw-accept-review-exception]');
      if (acceptException) {
        var exceptionBlocker = acceptException.closest('[data-mcw-review-blocker]');
        var exceptionRationale = exceptionBlocker.querySelector('[data-mcw-exception-rationale]');
        var exceptionRisk = exceptionBlocker.querySelector('[data-mcw-exception-risk]');
        var exceptionRationaleText = String((exceptionRationale || {}).value || '').trim();
        var exceptionRiskText = String((exceptionRisk || {}).value || '').trim();
        if (exceptionRationaleText.length < 20 || exceptionRiskText.length < 10) {
          toast('Record both a specific rationale and the residual risk.', true);
          return;
        }
        busy(acceptException, true, 'Recording Exception…');
        try {
          await request('resolve_review_blocker', {
            blocker_id: String(exceptionBlocker.dataset.mcwReviewBlocker || ''),
            resolution_type: 'REVIEW_EXCEPTION',
            rationale: exceptionRationaleText,
            residual_risk: exceptionRiskText
          });
          toast('Review exception recorded for independent reassessment.');
          window.setTimeout(function () { window.location.reload(); }, 350);
        } catch (error) {
          toast(error.message, true);
          busy(acceptException, false);
        }
        return;
      }

      var cancelFeedback = event.target.closest('[data-mcw-cancel-impact-feedback]');
      if (cancelFeedback) {
        if (panel) panel.hidden = true;
        return;
      }

      var reanalyze = event.target.closest('[data-mcw-reanalyze-impact]');
      if (reanalyze) {
        var correction = String((feedbackText || {}).value || '').trim();
        if (correction.length < 10) {
          toast('Describe what the Architect should reconsider.', true);
          return;
        }
        busy(reanalyze, true, 'Starting Re-analysis…');
        try {
          await request('request_impact_changes', {
            impact_id: Number((areaSelect || {}).value || 0),
            correction: correction
          });
          toast('Correction recorded. Re-analyzing the impact…');
          window.setTimeout(function () { window.location.reload(); }, 350);
        } catch (error) {
          toast(error.message, true);
          busy(reanalyze, false);
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
      ['[data-mcw-run-review]', 'run_independent_review', 'Accepting Independent Review…'],
      ['[data-mcw-continue-apply]', 'continue_to_apply', 'Continuing…'],
      ['[data-mcw-apply]', 'apply_accepted_wizard_changes', 'Applying Accepted Changes…']
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

  function setupReviewResolution() {
    root.addEventListener('click', async function (event) {
      var answerButton = event.target.closest('[data-mcw-answer-review-question]');
      if (answerButton) {
        var question = answerButton.closest('[data-mcw-review-question]');
        var selected = Array.prototype.slice.call(
          question.querySelectorAll('input[name="mcw-review-choice"]:checked')
        ).map(function (input) { return String(input.value || ''); });
        if (!selected.length) {
          toast('Select an answer before continuing.', true);
          return;
        }
        var explanationField = question.querySelector('[data-mcw-review-answer-explanation]');
        var explanation = String((explanationField || {}).value || '').trim();
        var requiresExplanation = Array.prototype.slice.call(
          question.querySelectorAll('input[name="mcw-review-choice"]:checked')
        ).some(function (input) {
          return input.getAttribute('data-requires-explanation') === '1';
        });
        if (requiresExplanation && !explanation) {
          toast('Provide the requested instruction before continuing.', true);
          if (explanationField) explanationField.focus();
          return;
        }
        busy(answerButton, true, 'Recording Decision…');
        try {
          await request('answer_review_question', {
            question_id: Number(question.dataset.mcwReviewQuestion || 0),
            selected_choice_ids: selected,
            explanation: explanation
          });
          window.location.reload();
        } catch (error) {
          toast(error.message, true);
          busy(answerButton, false);
        }
        return;
      }

      var generatePatch = event.target.closest('[data-mcw-generate-targeted-patch]');
      if (generatePatch) {
        busy(generatePatch, true, 'Generating Targeted Correction…');
        try {
          var findingIds = String(generatePatch.dataset.findingIds || '')
            .split(',')
            .map(function (value) { return Number(value || 0); })
            .filter(function (value) { return value > 0; });
          await request('generate_targeted_correction', {
            finding_ids: findingIds
          });
          window.location.reload();
        } catch (error) {
          toast(error.message, true);
          busy(generatePatch, false);
        }
        return;
      }

      var acceptPatch = event.target.closest('[data-mcw-accept-targeted-patch]');
      if (acceptPatch) {
        var patch = acceptPatch.closest('[data-mcw-targeted-patch]');
        busy(acceptPatch, true, 'Verifying Correction…');
        try {
          await request('accept_targeted_correction', {
            patch_id: Number(patch.dataset.mcwTargetedPatch || 0)
          });
          window.location.reload();
        } catch (error) {
          toast(error.message, true);
          busy(acceptPatch, false);
        }
        return;
      }

      var adjustPatch = event.target.closest('[data-mcw-request-patch-adjustment]');
      if (adjustPatch) {
        var adjustmentPatch = adjustPatch.closest('[data-mcw-targeted-patch]');
        var reason = window.prompt('What should be adjusted in this targeted correction?') || '';
        if (reason.trim().length < 10) {
          if (reason) toast('Provide a specific adjustment request.', true);
          return;
        }
        busy(adjustPatch, true, 'Recording Adjustment…');
        try {
          await request('request_targeted_correction_adjustment', {
            patch_id: Number(adjustmentPatch.dataset.mcwTargetedPatch || 0),
            reason: reason
          });
          window.location.reload();
        } catch (error) {
          toast(error.message, true);
          busy(adjustPatch, false);
        }
        return;
      }

      var reopen = event.target.closest('[data-mcw-explicit-reopen]');
      if (reopen) {
        var rationale = window.prompt('Why must this accepted baseline be reopened?') || '';
        if (rationale.trim().length < 20) {
          if (rationale) toast('Provide a specific reopening rationale.', true);
          return;
        }
        busy(reopen, true, 'Recording Explicit Reopen…');
        try {
          await request(String(reopen.dataset.mcwExplicitReopen || ''), {
            rationale: rationale
          });
          window.location.reload();
        } catch (error) {
          toast(error.message, true);
          busy(reopen, false);
        }
        return;
      }

      var followUp = event.target.closest('[data-mcw-scope-follow-up]');
      if (followUp) {
        var followUpReason = window.prompt('Why should this possible scope issue be handled separately?') || '';
        if (followUpReason.trim().length < 20) {
          if (followUpReason) toast('Provide a specific follow-up rationale.', true);
          return;
        }
        busy(followUp, true, 'Recording Follow-up…');
        try {
          await request('record_scope_follow_up', {
            finding_id: Number(followUp.dataset.mcwScopeFollowUp || 0),
            rationale: followUpReason
          });
          window.location.reload();
        } catch (error) {
          toast(error.message, true);
          busy(followUp, false);
        }
      }
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
    var displayedPercent = 2;
    var stageOrder = [
      'understanding_change',
      'reviewing_evidence',
      'reviewing_manual',
      'checking_references',
      'checking_related_sections',
      'mapping_coverage',
      'building_recommendation',
      'quality_check'
    ];

    function elapsedLabel(startedAt) {
      var started = Date.parse(String(startedAt || ''));
      if (!Number.isFinite(started)) return 'Analysis is running';
      var seconds = Math.max(0, Math.floor((Date.now() - started) / 1000));
      if (seconds < 60) return 'Running for ' + seconds + ' seconds';
      var minutes = Math.floor(seconds / 60);
      return 'Running for ' + minutes + ' minute' + (minutes === 1 ? '' : 's')
        + ' ' + (seconds % 60) + ' seconds';
    }

    function renderProgress(progress) {
      progress = progress || {};
      var percent = Math.max(displayedPercent, Math.min(100, Number(progress.percent || 2)));
      displayedPercent = percent;
      var fill = root.querySelector('[data-mcw-progress-fill]');
      var bar = root.querySelector('[data-mcw-progress-bar]');
      var percentNode = root.querySelector('[data-mcw-progress-percent]');
      var label = root.querySelector('[data-mcw-progress-label]');
      var elapsed = root.querySelector('[data-mcw-progress-elapsed]');
      if (fill) fill.style.width = percent + '%';
      if (bar) bar.setAttribute('aria-valuenow', String(percent));
      if (percentNode) percentNode.textContent = Math.round(percent) + '%';
      if (label && progress.label) label.textContent = String(progress.label);
      if (elapsed) {
        elapsed.textContent = elapsedLabel(progress.started_at)
          + ' · this page will continue automatically when ready.';
      }
      var currentStage = String(progress.stage_key || 'queued');
      var currentIndex = stageOrder.indexOf(currentStage);
      root.querySelectorAll('[data-mcw-progress-step]').forEach(function (item) {
        var index = stageOrder.indexOf(String(item.dataset.mcwProgressStep || ''));
        var text = item.textContent.replace(/^[✓●○]\s*/, '');
        item.classList.remove('is-complete', 'is-active');
        if (currentStage === 'complete' || (currentIndex >= 0 && index < currentIndex)) {
          item.classList.add('is-complete');
          item.textContent = '✓ ' + text;
        } else if (index === currentIndex) {
          item.classList.add('is-active');
          item.textContent = '● ' + text;
        } else {
          item.textContent = '○ ' + text;
        }
      });
    }

    async function check() {
      try {
        var result = await request('analysis_status');
        consecutiveErrors = 0;
        renderProgress(result.progress);
        if (result.complete) {
          renderProgress(Object.assign({}, result.progress || {}, {
            percent: 100,
            stage_key: 'complete',
            label: 'Amendment recommendation ready'
          }));
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

  function setupPendingDrafting() {
    if (root.dataset.draftingPending !== '1' || planId <= 0) return;
    var status = String(root.dataset.draftStatus || 'not_started');
    var polling = false;

    function render(progress, message) {
      progress = progress || {};
      var percent = Math.max(3, Math.min(100, Number(progress.percent || 3)));
      var fill = root.querySelector('[data-mcw-drafting-fill]');
      var bar = root.querySelector('[data-mcw-drafting-bar]');
      var percentNode = root.querySelector('[data-mcw-drafting-percent]');
      var label = root.querySelector('[data-mcw-drafting-label]');
      var messageNode = root.querySelector('[data-mcw-drafting-message]');
      if (fill) fill.style.width = percent + '%';
      if (bar) bar.setAttribute('aria-valuenow', String(percent));
      if (percentNode) percentNode.textContent = Math.round(percent) + '%';
      if (label && progress.label) label.textContent = String(progress.label);
      if (messageNode && message) messageNode.textContent = String(message);
    }

    async function poll() {
      if (polling) return;
      polling = true;
      try {
        var response = await request('draft_status');
        var result = response.result || response;
        status = String(result.draft_status || 'not_started');
        render(result.progress, result.message);
        if (status === 'generated') {
          render(Object.assign({}, result.progress || {}, {
            percent: 100,
            label: 'Proposed manual amendments are ready'
          }));
          window.location.reload();
          return;
        }
        if (status === 'abandoned') {
          var retry = root.querySelector('[data-mcw-retry-drafting]');
          if (retry) retry.hidden = false;
          render(result.progress, result.message || 'Draft generation could not be completed.');
          return;
        }
      } catch (error) {
        toast('Drafting is still running. This page will keep checking.', true);
      } finally {
        polling = false;
      }
      window.setTimeout(poll, 3000);
    }

    async function start(button) {
      if (button) busy(button, true, 'Applying Review Corrections…');
      try {
        var response = await request('generate_drafts');
        var result = response.result || response;
        status = 'generating';
        var retry = root.querySelector('[data-mcw-retry-drafting]');
        if (retry) retry.hidden = true;
        if (Number(result.controlled_review_correction_count || 0) > 0) {
          render({
            percent: 3,
            label: 'Regenerating with controlled-review corrections'
          }, 'The prior controlled-review failures are now explicit constraints for this drafting attempt.');
        }
        window.setTimeout(poll, 900);
      } catch (error) {
        toast(error.message, true);
        if (button) busy(button, false);
      }
    }

    root.addEventListener('click', function (event) {
      var retry = event.target.closest('[data-mcw-retry-drafting]');
      if (retry) start(retry);
    });
    if (status === 'not_started' || status === 'abandoned') {
      if (status === 'not_started') start(null);
    } else {
      window.setTimeout(poll, 900);
    }
  }

  setupFiles();
  setupIntake();
  setupImpactDecisions();
  setupDraftDecisions();
  setupReviewResolution();
  setupProgression();
  setupPendingAnalysis();
  setupPendingDrafting();
}());
