(function () {
  'use strict';

  function syncAuthorityField(scope) {
    var route = scope.querySelector('[data-bm-approval-route]');
    var field = scope.querySelector('[data-bm-authority-field]');
    if (!route || !field) return;
    field.hidden = route.value !== 'authority';
    var input = field.querySelector('input');
    if (input) input.required = route.value === 'authority';
  }

  function addReviewer(select) {
    var option = select.options[select.selectedIndex];
    var id = option ? option.value : '';
    var editor = select.closest('.bm-reviewer-editor');
    var list = editor ? editor.querySelector('[data-bm-reviewer-list]') : null;
    if (!id || !list || list.querySelector('[data-reviewer-id="' + CSS.escape(id) + '"]')) {
      select.value = '';
      return;
    }

    var row = document.createElement('div');
    row.className = 'bm-reviewer-row';
    row.setAttribute('data-reviewer-id', id);

    var hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'reviewer_user_ids[]';
    hidden.value = id;

    var copy = document.createElement('span');
    var name = document.createElement('strong');
    name.textContent = option.getAttribute('data-name') || option.textContent || '';
    var email = document.createElement('small');
    email.textContent = option.getAttribute('data-email') || '';
    copy.appendChild(name);
    copy.appendChild(email);

    var remove = document.createElement('button');
    remove.type = 'button';
    remove.setAttribute('data-bm-reviewer-remove', '');
    remove.setAttribute('aria-label', 'Remove reviewer');
    remove.textContent = '×';

    row.appendChild(hidden);
    row.appendChild(copy);
    row.appendChild(remove);
    list.appendChild(row);
    select.value = '';
  }

  document.addEventListener('change', function (event) {
    if (event.target.matches('[data-bm-reviewer-add]')) {
      addReviewer(event.target);
      return;
    }
    if (event.target.matches('[data-bm-approval-route]')) {
      syncAuthorityField(event.target.closest('[data-bm-governance]') || document);
    }
  });

  document.addEventListener('click', function (event) {
    var toggle = event.target.closest('[data-bm-settings-toggle]');
    if (toggle) {
      var dialog = toggle.closest('dialog');
      var lifecycle = dialog ? dialog.querySelector('[data-bm-lifecycle]') : null;
      var settings = dialog ? dialog.querySelector('[data-bm-governance]') : null;
      if (lifecycle && settings) {
        var opening = settings.hidden;
        settings.hidden = !opening;
        lifecycle.hidden = opening;
        toggle.textContent = opening ? 'Back to Lifecycle' : 'Settings';
        if (opening) syncAuthorityField(settings);
      }
      return;
    }

    var remove = event.target.closest('[data-bm-reviewer-remove]');
    if (remove) {
      var row = remove.closest('.bm-reviewer-row');
      if (row) row.remove();
      return;
    }

    var card = event.target.closest('[data-bm-reader-url]');
    if (card && !event.target.closest('a, button, summary, details, form, input, select, label')) {
      window.location.href = card.getAttribute('data-bm-reader-url');
      return;
    }

    if (!event.target.closest('.bm-overflow-menu')) {
      document.querySelectorAll('.bm-overflow-menu[open]').forEach(function (menu) {
        menu.removeAttribute('open');
      });
    }
  });

  document.querySelectorAll('[data-bm-governance]').forEach(syncAuthorityField);

  document.querySelectorAll('.bm-cover--thumbnail img').forEach(function (image) {
    image.addEventListener('error', function () {
      var cover = image.closest('.bm-cover--thumbnail');
      if (cover) cover.classList.add('is-fallback');
    });
  });

  var openVersion = new URLSearchParams(window.location.search).get('open');
  if (openVersion) {
    var modal = document.getElementById('bm-manual-settings-' + openVersion);
    if (modal && typeof modal.showModal === 'function') {
      modal.showModal();
    }
  }
}());
