(function () {
  'use strict';

  var root = document.querySelector('[data-mca-workspace]');
  if (!root) return;

  var planId = Number(root.dataset.planId || 0);
  var apiUrl = root.dataset.apiUrl || '';
  var csrfToken = root.dataset.csrfToken || '';

  function toast(message, error) {
    var node = root.querySelector('[data-mca-toast]');
    if (!node) return;
    node.textContent = message;
    node.className = 'mca-toast' + (error ? ' is-error' : '');
    node.hidden = false;
    window.clearTimeout(Number(node.dataset.timer || 0));
    node.dataset.timer = String(window.setTimeout(function () {
      node.hidden = true;
    }, 4500));
  }

  function selectImpact(id) {
    id = String(id || '');
    root.querySelectorAll('[data-mca-impact-card]').forEach(function (card) {
      card.classList.toggle('is-selected', card.dataset.impactId === id);
    });
    root.querySelectorAll('[data-mca-inspector]').forEach(function (panel) {
      panel.hidden = panel.dataset.mcaInspector !== id;
    });
  }

  function setupSelection() {
    root.addEventListener('click', function (event) {
      var trigger = event.target.closest('[data-mca-open-inspector]');
      var card = event.target.closest('[data-mca-impact-card]');
      if (!trigger && !card) return;
      selectImpact(trigger ? trigger.dataset.mcaOpenInspector : card.dataset.impactId);
    });
    root.addEventListener('keydown', function (event) {
      var card = event.target.closest('[data-mca-impact-card]');
      if (!card || (event.key !== 'Enter' && event.key !== ' ')) return;
      event.preventDefault();
      selectImpact(card.dataset.impactId);
    });
  }

  async function api(action, payload) {
    var response = await fetch(apiUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(Object.assign({
        action: action,
        csrf_token: csrfToken
      }, payload || {}))
    });
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
      throw new Error(String(result.error || result.message || 'The decision could not be recorded.'));
    }
    return result;
  }

  function setBusy(group, busy) {
    group.querySelectorAll('[data-mca-impact-decision]').forEach(function (button) {
      button.disabled = busy;
    });
  }

  function updateCardStatus(impactId, decision) {
    var card = root.querySelector('[data-mca-impact-card][data-impact-id="' + impactId + '"]');
    if (!card) return;
    var status = card.querySelector('[data-mca-card-status]');
    if (!status) return;
    var projection = {
      ACCEPT: ['Approved', 'approved'],
      MODIFY: ['Modification requested', 'validated'],
      REJECT: ['Dismissed', 'dismissed']
    }[decision];
    status.textContent = projection[0];
    status.className = 'mca-decision-status is-' + projection[1];
  }

  function setupDecisions() {
    root.addEventListener('click', async function (event) {
      var button = event.target.closest('[data-mca-impact-decision]');
      if (!button) return;
      var panel = button.closest('[data-mca-inspector]');
      var decision = String(button.dataset.mcaImpactDecision || '');
      var impactId = Number(button.dataset.impactId || 0);
      var note = String(panel.querySelector('[data-mca-decision-note]').value || '').trim();
      if ((decision === 'MODIFY' || decision === 'REJECT') && note.length < 5) {
        toast('Record a short rationale before modifying or rejecting this amendment area.', true);
        return;
      }
      setBusy(panel, true);
      var original = button.textContent;
      button.textContent = 'Saving…';
      try {
        await api('impact_decision', {
          plan_id: planId,
          impact_id: impactId,
          decision: decision,
          note: note
        });
        updateCardStatus(impactId, decision);
        toast(decision === 'ACCEPT'
          ? 'Amendment area accepted.'
          : (decision === 'MODIFY' ? 'Modification request recorded.' : 'Amendment area rejected.'));
      } catch (error) {
        toast(error.message, true);
      } finally {
        setBusy(panel, false);
        button.textContent = original;
      }
    });
  }

  setupSelection();
  setupDecisions();
}());
