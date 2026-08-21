(function () {
  function csrf() {
    var el = document.querySelector('meta[name="theory-studio-csrf"]');
    return el ? el.getAttribute('content') : '';
  }

  function api(action, payload) {
    payload = payload || {};
    payload.action = action;
    payload.csrf_token = csrf();
    return fetch('/admin/theory_studio/api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
      credentials: 'same-origin',
      body: JSON.stringify(payload)
    }).then(function (res) {
      return res.json().then(function (body) {
        if (!res.ok || !body || body.ok === false) {
          var err = new Error((body && (body.message || body.error)) || 'Request failed');
          err.code = body && body.error_code;
          throw err;
        }
        return body;
      });
    });
  }

  document.addEventListener('click', function (ev) {
    var opener = ev.target.closest('[data-ts-modal-open]');
    if (opener) {
      var modal = document.getElementById(opener.getAttribute('data-ts-modal-open'));
      if (modal && typeof modal.showModal === 'function') modal.showModal();
      ev.preventDefault();
      return;
    }
    if (ev.target.closest('[data-ts-modal-close]')) {
      var dialog = ev.target.closest('dialog');
      if (dialog && typeof dialog.close === 'function') dialog.close();
      ev.preventDefault();
    }
  });

  document.addEventListener('submit', function (ev) {
    var form = ev.target.closest('[data-ts-form]');
    if (!form) return;
    ev.preventDefault();
    var action = form.getAttribute('data-ts-form');
    var fd = new FormData(form);
    fd.append('action', action);
    fd.append('csrf_token', csrf());
    var submit = form.querySelector('[type="submit"]');
    if (submit) submit.disabled = true;
    fetch('/admin/theory_studio/api.php', {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': csrf() },
      credentials: 'same-origin',
      body: fd
    }).then(function (res) {
      return res.json().then(function (body) {
        if (!res.ok || !body || body.ok === false) {
          var err = new Error((body && (body.message || body.error)) || 'Request failed');
          throw err;
        }
        return body;
      });
    }).then(function (body) {
      if (body.redirect) {
        window.location.href = body.redirect;
        return;
      }
      window.location.reload();
    }).catch(function (err) {
      window.alert(err.message || 'Could not save.');
      if (submit) submit.disabled = false;
    });
  });

  var dragRoot = document.querySelector('[data-ts-reorder]');
  if (!dragRoot) return;
  var dragged = null;
  dragRoot.addEventListener('dragstart', function (ev) {
    var row = ev.target.closest('[data-id]');
    if (!row) return;
    dragged = row;
    ev.dataTransfer.effectAllowed = 'move';
  });
  dragRoot.addEventListener('dragover', function (ev) {
    ev.preventDefault();
    var row = ev.target.closest('[data-id]');
    if (!row || !dragged || row === dragged) return;
    var rect = row.getBoundingClientRect();
    var before = (ev.clientY - rect.top) < rect.height / 2;
    dragRoot.insertBefore(dragged, before ? row : row.nextSibling);
  });
  dragRoot.addEventListener('drop', function (ev) {
    ev.preventDefault();
    var ids = Array.prototype.map.call(dragRoot.querySelectorAll('[data-id]'), function (row) {
      return parseInt(row.getAttribute('data-id'), 10);
    });
    var action = dragRoot.getAttribute('data-ts-reorder');
    var parentId = parseInt(dragRoot.getAttribute('data-parent-id'), 10);
    api(action, { parent_id: parentId, ordered_ids: ids }).catch(function (err) {
      window.alert(err.message || 'Could not reorder.');
      window.location.reload();
    });
  });
})();
