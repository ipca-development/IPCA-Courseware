(function () {
  'use strict';

  var root = document.getElementById('tseEditorRoot');
  if (!root) return;

  var mode = root.dataset.mode || 'slide';
  var readonly = root.dataset.readonly === '1';
  var stage = document.getElementById('tseStage');
  var stageWrap = document.getElementById('tseStageWrap');
  var canvasWorkspace = document.getElementById('tseCanvasWorkspace');
  var canvasScroll = document.getElementById('tseCanvasScroll');
  var inspector = document.getElementById('tseInspector');
  var contextToolbar = document.getElementById('tseContextToolbar');
  var smartGuides = document.getElementById('tseSmartGuides');
  var rulerX = document.getElementById('tseRulerX');
  var rulerY = document.getElementById('tseRulerY');
  var gridLayer = document.getElementById('tseGridLayer');
  var statusEl = document.getElementById('tseStatus');
  var lastSavedEl = document.getElementById('tseLastSaved');
  var revisionEl = document.getElementById('tseRevision');
  var fileInput = document.getElementById('tseMediaInput');
  var stateNode = document.getElementById('tseInitialState');
  var csrfNode = document.querySelector('meta[name="theory-studio-csrf"]');
  var state = stateNode ? JSON.parse(stateNode.textContent || '{}') : {};

  var selectedKey = '';
  var selectedGuideId = '';
  var activeInspectorTab = 'layout';
  var guidesVisible = true;
  var snapGuides = true;
  var showGrid = false;
  var snapGrid = false;
  var gridSpacing = 20;
  var scale = .6;
  var zoomMode = 'fit';
  var drag = null;
  var undoStack = [];
  var redoStack = [];
  var clipboard = null;
  var saveTimer = null;
  var saveInFlight = false;
  var savePending = false;

  state.placeholders = Array.isArray(state.placeholders) ? state.placeholders : [];
  state.guides = Array.isArray(state.guides) ? state.guides : [];
  state.values = state.values && typeof state.values === 'object' ? state.values : {};

  function csrf() {
    return csrfNode ? csrfNode.getAttribute('content') || '' : '';
  }

  function request(action, payload) {
    payload = payload || {};
    payload.action = action;
    payload.csrf_token = csrf();
    return fetch('/admin/theory_studio/api.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
      body: JSON.stringify(payload)
    }).then(function (response) {
      return response.json().then(function (body) {
        if (!response.ok || !body || body.ok === false) {
          var error = new Error((body && (body.message || body.error)) || 'Request failed.');
          error.code = body && body.error_code;
          throw error;
        }
        return body;
      });
    });
  }

  function status(message, tone) {
    if (!statusEl) return;
    statusEl.classList.toggle('is-unsaved', tone === 'unsaved');
    statusEl.classList.toggle('is-saving', tone === 'saving');
    statusEl.classList.toggle('is-error', tone === 'error');
    var text = statusEl.querySelector('span');
    if (text) text.textContent = message;
  }

  function clone(value) {
    return JSON.parse(JSON.stringify(value));
  }

  function snapshot() {
    return clone({
      placeholders: state.placeholders,
      guides: state.guides,
      values: state.values,
      selectedKey: selectedKey,
      selectedGuideId: selectedGuideId
    });
  }

  function remember() {
    undoStack.push(snapshot());
    if (undoStack.length > 60) undoStack.shift();
    redoStack = [];
  }

  function restore(value) {
    state.placeholders = value.placeholders || [];
    state.guides = value.guides || [];
    state.values = value.values || {};
    selectedKey = value.selectedKey || '';
    selectedGuideId = value.selectedGuideId || '';
    render();
    markChanged();
  }

  function undo() {
    if (!undoStack.length) return;
    redoStack.push(snapshot());
    restore(undoStack.pop());
  }

  function redo() {
    if (!redoStack.length) return;
    undoStack.push(snapshot());
    restore(redoStack.pop());
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function selectedPlaceholder() {
    return state.placeholders.find(function (item) {
      return String(item.placeholder_key) === String(selectedKey);
    }) || null;
  }

  function selectedGuide() {
    return state.guides.find(function (item) {
      return String(item.client_id || item.id) === String(selectedGuideId);
    }) || null;
  }

  function valueFor(placeholder) {
    var key = String(placeholder.placeholder_key);
    if (!state.values[key]) {
      state.values[key] = placeholder.type === 'text'
        ? { type: 'text', en_html: '', es_html: '' }
        : { type: placeholder.type, media_asset_id: null, cdn_url: '', fit: placeholder.media_fit || 'contain' };
    }
    return state.values[key];
  }

  function canMove(placeholder) {
    return !readonly && !placeholder.locked
      && (mode === 'template' || !!placeholder.author_can_reposition);
  }

  function canResize(placeholder) {
    return !readonly && !placeholder.locked
      && (mode === 'template' || !!placeholder.author_can_resize);
  }

  function applyGeometry(element, placeholder) {
    element.style.left = Number(placeholder.x) + 'px';
    element.style.top = Number(placeholder.y) + 'px';
    element.style.width = Number(placeholder.width) + 'px';
    element.style.height = Number(placeholder.height) + 'px';
    element.style.setProperty('--tse-layer', String(2 + (Number(placeholder.z_index) || 1)));
  }

  function renderPlaceholder(placeholder) {
    var element = document.createElement('div');
    var key = String(placeholder.placeholder_key);
    var value = valueFor(placeholder);
    element.className = 'tse-placeholder'
      + (key === selectedKey ? ' is-selected' : '')
      + (placeholder.locked ? ' is-locked' : '');
    element.dataset.key = key;
    element.dataset.type = placeholder.type;
    applyGeometry(element, placeholder);
    element.style.setProperty('--tse-media-fit', value.fit || placeholder.media_fit || 'contain');
    element.style.backgroundColor = placeholder.background_color || 'transparent';
    element.style.borderWidth = (Number(placeholder.border_width) || 0) + 'px';
    element.style.borderColor = placeholder.border_color || 'transparent';

    if (placeholder.type === 'text') {
      var content = document.createElement('div');
      content.className = 'tse-placeholder-content';
      content.contentEditable = mode === 'slide' && !readonly ? 'true' : 'false';
      content.dataset.placeholderContent = key;
      content.innerHTML = mode === 'slide'
        ? (value.en_html || '')
        : '<span>Aa · ' + escapeHtml(placeholder.semantic_role || key) + '</span>';
      content.style.padding = (Number(placeholder.padding) || 0) + 'px';
      content.style.fontFamily = placeholder.font_family || 'Arial, Helvetica, sans-serif';
      content.style.fontSize = (Number(placeholder.font_size) || 18) + 'px';
      content.style.fontWeight = String(Number(placeholder.font_weight) || 400);
      content.style.color = placeholder.text_color || '#0f172a';
      content.style.lineHeight = String(Number(placeholder.line_height) || 1.35);
      content.style.textAlign = placeholder.alignment || 'left';
      content.style.display = 'flex';
      content.style.flexDirection = 'column';
      content.style.justifyContent = {
        middle: 'center',
        bottom: 'flex-end'
      }[placeholder.vertical_alignment] || 'flex-start';
      content.style.overflow = placeholder.overflow_behavior || 'auto';
      content.addEventListener('input', function () {
        value.en_html = content.innerHTML;
        markChanged();
      });
      content.addEventListener('focus', function () {
        selectPlaceholder(key, false);
      });
      element.appendChild(content);
    } else if (value.cdn_url) {
      var media = document.createElement(placeholder.type === 'video' ? 'video' : 'img');
      media.src = value.cdn_url;
      media.alt = placeholder.semantic_role || key;
      if (placeholder.type === 'video') media.controls = true;
      element.appendChild(media);
    } else {
      var empty = document.createElement('div');
      empty.className = 'tse-empty-media';
      empty.textContent = mode === 'template'
        ? placeholder.type.toUpperCase() + ' · ' + (placeholder.semantic_role || key)
        : 'Choose ' + placeholder.type;
      element.appendChild(empty);
    }

    if (key === selectedKey && canResize(placeholder)) {
      ['nw', 'ne', 'sw', 'se'].forEach(function (handleName) {
        var handle = document.createElement('span');
        handle.className = 'tse-resize-handle';
        handle.dataset.handle = handleName;
        element.appendChild(handle);
      });
    }

    element.addEventListener('pointerdown', function (event) {
      selectPlaceholder(key, false);
      var handle = event.target.closest('.tse-resize-handle');
      var editingText = mode === 'slide' && event.target.closest('[contenteditable="true"]');
      if (editingText && !handle) return;
      if ((!handle && !canMove(placeholder)) || (handle && !canResize(placeholder))) return;
      remember();
      drag = {
        kind: 'placeholder',
        key: key,
        handle: handle ? handle.dataset.handle : '',
        startX: event.clientX,
        startY: event.clientY,
        x: Number(placeholder.x),
        y: Number(placeholder.y),
        width: Number(placeholder.width),
        height: Number(placeholder.height),
        changed: false
      };
      element.setPointerCapture(event.pointerId);
      event.preventDefault();
    });
    return element;
  }

  function renderGuide(guide) {
    var id = String(guide.client_id || guide.id);
    var element = document.createElement('button');
    element.type = 'button';
    element.className = 'tse-guide tse-guide--' + guide.orientation
      + (id === selectedGuideId ? ' is-selected' : '');
    element.dataset.guideId = id;
    element.setAttribute('aria-label', guide.orientation + ' guide at ' + guide.position);
    if (guide.orientation === 'vertical') element.style.left = Number(guide.position) + 'px';
    else element.style.top = Number(guide.position) + 'px';
    element.addEventListener('pointerdown', function (event) {
      selectGuide(id, false);
      if (readonly || guide.is_locked) return;
      remember();
      drag = { kind: 'guide', guideId: id, orientation: guide.orientation, outside: false, changed: false };
      element.setPointerCapture(event.pointerId);
      event.preventDefault();
      event.stopPropagation();
    });
    return element;
  }

  function render() {
    stage.innerHTML = '';
    state.guides.forEach(function (guide) {
      stage.appendChild(renderGuide(guide));
    });
    state.placeholders.slice().sort(function (a, b) {
      return (Number(a.z_index) || 1) - (Number(b.z_index) || 1);
    }).forEach(function (placeholder) {
      stage.appendChild(renderPlaceholder(placeholder));
    });
    renderInspector();
    renderContextToolbar();
  }

  function selectPlaceholder(key, rerender) {
    selectedKey = String(key || '');
    selectedGuideId = '';
    if (rerender !== false) render();
    else {
      stage.querySelectorAll('.tse-placeholder').forEach(function (element) {
        element.classList.toggle('is-selected', element.dataset.key === selectedKey);
      });
      renderInspector();
      renderContextToolbar();
    }
  }

  function selectGuide(id, rerender) {
    selectedGuideId = String(id || '');
    selectedKey = '';
    if (rerender !== false) {
      render();
      return;
    }
    stage.querySelectorAll('.tse-guide').forEach(function (element) {
      element.classList.toggle('is-selected', element.dataset.guideId === selectedGuideId);
    });
    stage.querySelectorAll('.tse-placeholder').forEach(function (element) {
      element.classList.remove('is-selected');
    });
    renderInspector();
    renderContextToolbar();
  }

  function deselect() {
    selectedKey = '';
    selectedGuideId = '';
    render();
  }

  function field(label, key, value, type, disabled) {
    return '<label class="tse-field">' + escapeHtml(label)
      + '<input data-property="' + escapeHtml(key) + '" type="' + (type || 'text')
      + (type === 'number' ? '" step="any' : '') + '" value="' + escapeHtml(value) + '"'
      + (disabled ? ' disabled' : '') + '></label>';
  }

  function selectField(label, key, value, options, disabled) {
    return '<label class="tse-field">' + escapeHtml(label)
      + '<select data-property="' + escapeHtml(key) + '"' + (disabled ? ' disabled' : '') + '>'
      + options.map(function (option) {
        return '<option value="' + escapeHtml(option[0]) + '"'
          + (String(option[0]) === String(value) ? ' selected' : '') + '>'
          + escapeHtml(option[1]) + '</option>';
      }).join('') + '</select></label>';
  }

  function section(title, html) {
    return '<section class="tse-inspector-section"><div class="tse-inspector-section-title">'
      + escapeHtml(title) + '</div>' + html + '</section>';
  }

  function renderInspector() {
    var placeholder = selectedPlaceholder();
    var guide = selectedGuide();
    if (guide) {
      var coordinate = guide.orientation === 'vertical' ? 'X' : 'Y';
      inspector.innerHTML = section('Guide', '<h3>'
        + (guide.orientation === 'vertical' ? 'Vertical Guide' : 'Horizontal Guide') + '</h3>'
        + field(coordinate + ' coordinate', 'guide_position', guide.position, 'number', readonly || guide.is_locked)
        + '<label class="tse-check"><input type="checkbox" data-guide-property="is_locked"'
        + (guide.is_locked ? ' checked' : '') + (readonly ? ' disabled' : '') + '> Lock guide</label>')
        + section('Actions', '<button type="button" class="tse-inspector-action is-danger" data-remove-guide'
        + (readonly || guide.is_locked ? ' disabled' : '') + '>Delete Guide</button>');
      return;
    }
    if (!placeholder) {
      inspector.innerHTML = section(mode === 'template' ? 'Template' : 'Slide',
        '<h3>' + escapeHtml(state.template_name || state.name || 'Structured Slide') + '</h3>'
        + '<p class="ts-meta">Select an object to edit it directly on the canvas.</p>')
        + section('Guides & Grid',
          '<button type="button" class="tse-inspector-action" data-add-guide="horizontal">Add Horizontal Guide</button> '
          + '<button type="button" class="tse-inspector-action" data-add-guide="vertical">Add Vertical Guide</button>'
          + field('Grid spacing', 'grid_spacing', gridSpacing, 'number', false));
      return;
    }

    var geometryLocked = mode !== 'template' && !placeholder.author_can_reposition && !placeholder.author_can_resize;
    var html = '<h3>' + escapeHtml(placeholder.semantic_role || placeholder.placeholder_key) + '</h3>';
    if (activeInspectorTab === 'layout') {
      html = section('Position & Size', html + '<div class="tse-field-grid">'
        + field('X', 'x', placeholder.x, 'number', geometryLocked || placeholder.locked)
        + field('Y', 'y', placeholder.y, 'number', geometryLocked || placeholder.locked)
        + field('W', 'width', placeholder.width, 'number', (mode !== 'template' && !placeholder.author_can_resize) || placeholder.locked)
        + field('H', 'height', placeholder.height, 'number', (mode !== 'template' && !placeholder.author_can_resize) || placeholder.locked)
        + '</div>')
        + section('Layer', '<div class="tse-actions">'
          + '<button type="button" class="tse-inspector-action" data-layer-action="forward">Bring Forward</button>'
          + '<button type="button" class="tse-inspector-action" data-layer-action="backward">Send Backward</button>'
          + '</div><label class="tse-check"><input type="checkbox" data-property="locked"'
          + (placeholder.locked ? ' checked' : '') + (mode !== 'template' ? ' disabled' : '') + '> Lock object</label>');
    } else if (activeInspectorTab === 'content') {
      html = section('Placeholder', html
        + field('Semantic role', 'semantic_role', placeholder.semantic_role || '', 'text', mode !== 'template')
        + '<label class="tse-check"><input type="checkbox" data-property="required"'
        + (placeholder.required ? ' checked' : '') + (mode !== 'template' ? ' disabled' : '') + '> Required</label>');
      if (mode === 'slide' && placeholder.type === 'text') {
        html += section('Translation', field('Spanish content', 'es_html', valueFor(placeholder).es_html || '', 'text', false));
      }
      if (mode === 'slide' && placeholder.type !== 'text') {
        html += section('Asset', '<button type="button" class="tse-inspector-action" data-pick-media>'
          + (valueFor(placeholder).media_asset_id ? 'Replace ' : 'Choose ') + escapeHtml(placeholder.type) + '</button>');
      }
    } else if (activeInspectorTab === 'style') {
      if (placeholder.type === 'text') {
        html = section('Typography', html
          + selectField('Font', 'font_family', placeholder.font_family, [
            ['Arial, Helvetica, sans-serif', 'Arial'],
            ['Helvetica, Arial, sans-serif', 'Helvetica'],
            ['Georgia, Times New Roman, serif', 'Georgia'],
            ['Times New Roman, Times, serif', 'Times New Roman'],
            ['system-ui, sans-serif', 'System Sans']
          ], mode !== 'template')
          + '<div class="tse-field-grid">'
          + field('Size', 'font_size', placeholder.font_size || 18, 'number', mode !== 'template')
          + selectField('Weight', 'font_weight', placeholder.font_weight || 400, [
            ['300', 'Light'], ['400', 'Regular'], ['500', 'Medium'],
            ['600', 'Semibold'], ['700', 'Bold'], ['800', 'Extra bold']
          ], mode !== 'template')
          + field('Color', 'text_color', placeholder.text_color || '#0f172a', 'color', mode !== 'template')
          + field('Line height', 'line_height', placeholder.line_height || 1.35, 'number', mode !== 'template')
          + '</div>'
          + selectField('Horizontal', 'alignment', placeholder.alignment || 'left', [
            ['left', 'Left'], ['center', 'Center'], ['right', 'Right'], ['justify', 'Justify']
          ], mode !== 'template')
          + selectField('Vertical', 'vertical_alignment', placeholder.vertical_alignment || 'top', [
            ['top', 'Top'], ['middle', 'Middle'], ['bottom', 'Bottom']
          ], mode !== 'template'));
      } else {
        html = section('Media', html + selectField('Fit', 'media_fit', placeholder.media_fit || 'contain', [
          ['contain', 'Fit'], ['cover', 'Fill']
        ], mode !== 'template'));
      }
      html += section('Appearance', '<div class="tse-field-grid">'
        + field('Background', 'background_color', placeholder.background_color || '#ffffff', 'color', mode !== 'template')
        + field('Border', 'border_color', placeholder.border_color || '#ffffff', 'color', mode !== 'template')
        + field('Border width', 'border_width', placeholder.border_width || 0, 'number', mode !== 'template')
        + field('Padding', 'padding', placeholder.padding || 0, 'number', mode !== 'template')
        + '</div>');
    } else {
      html = section('Structure', html
        + field('Placeholder key', 'placeholder_key', placeholder.placeholder_key, 'text', mode !== 'template')
        + selectField('Type', 'type', placeholder.type, [
          ['text', 'Text'], ['image', 'Image'], ['video', 'Video']
        ], mode !== 'template')
        + field('Reading order', 'reading_order', placeholder.reading_order || 1, 'number', mode !== 'template'))
        + section('Author permissions',
          '<label class="tse-check"><input type="checkbox" data-property="author_can_reposition"'
          + (placeholder.author_can_reposition ? ' checked' : '') + (mode !== 'template' ? ' disabled' : '')
          + '> Author may reposition</label>'
          + '<label class="tse-check"><input type="checkbox" data-property="author_can_resize"'
          + (placeholder.author_can_resize ? ' checked' : '') + (mode !== 'template' ? ' disabled' : '')
          + '> Author may resize</label>');
      if (mode === 'template') {
        html += section('Object', '<button type="button" class="tse-inspector-action is-danger" data-remove-placeholder>Delete Object</button>');
      }
    }
    inspector.innerHTML = html;
  }

  function renderContextToolbar() {
    var placeholder = selectedPlaceholder();
    if (!placeholder || root.classList.contains('is-preview')) {
      contextToolbar.hidden = true;
      return;
    }
    var buttons = '';
    if (placeholder.type === 'text') {
      buttons = '<button type="button" data-cmd="bold"><strong>B</strong></button>'
        + '<button type="button" data-cmd="italic"><em>I</em></button>'
        + '<button type="button" data-align="left">Left</button>'
        + '<button type="button" data-align="center">Center</button>'
        + '<button type="button" data-cmd="insertUnorderedList">List</button>'
        + '<button type="button" data-tse-link>Link</button>';
    } else {
      buttons = '<button type="button" data-pick-media>Replace</button>'
        + '<button type="button" data-media-fit="contain">Fit</button>'
        + '<button type="button" data-media-fit="cover">Fill</button>';
    }
    contextToolbar.innerHTML = buttons + '<button type="button" data-tse-more>•••</button>';
    contextToolbar.hidden = false;
    var left = (Number(placeholder.x) + Number(placeholder.width) / 2) * scale;
    var top = Math.max(4, Number(placeholder.y) * scale - 40);
    contextToolbar.style.left = Math.max(4, left - contextToolbar.offsetWidth / 2) + 'px';
    contextToolbar.style.top = top + 'px';
  }

  function stagePoint(event) {
    var rect = stage.getBoundingClientRect();
    return {
      x: (event.clientX - rect.left) * 1600 / rect.width,
      y: (event.clientY - rect.top) * 900 / rect.height
    };
  }

  function snapTargets(axis, movingKey) {
    var limit = axis === 'x' ? 1600 : 900;
    var targets = [{ value: limit / 2, kind: 'center' }];
    if (snapGuides) {
      state.guides.forEach(function (guide) {
        if ((axis === 'x' && guide.orientation === 'vertical')
          || (axis === 'y' && guide.orientation === 'horizontal')) {
          targets.push({ value: Number(guide.position), kind: 'guide' });
        }
      });
    }
    state.placeholders.forEach(function (placeholder) {
      if (String(placeholder.placeholder_key) === String(movingKey)) return;
      var start = Number(axis === 'x' ? placeholder.x : placeholder.y);
      var size = Number(axis === 'x' ? placeholder.width : placeholder.height);
      targets.push({ value: start, kind: 'object' });
      targets.push({ value: start + size / 2, kind: 'object' });
      targets.push({ value: start + size, kind: 'object' });
    });
    return targets;
  }

  function bestSnap(candidates, targets) {
    var tolerance = Math.max(5, 8 / scale);
    var best = { distance: tolerance + 1, delta: 0, target: null };
    candidates.forEach(function (candidate) {
      targets.forEach(function (target) {
        var delta = target.value - candidate;
        if (Math.abs(delta) < best.distance) {
          best = { distance: Math.abs(delta), delta: delta, target: target.value };
        }
      });
    });
    return best;
  }

  function gridSnap(value) {
    return snapGrid ? Math.round(value / gridSpacing) * gridSpacing : value;
  }

  function showSmartLines(xTarget, yTarget) {
    smartGuides.innerHTML = '';
    if (xTarget != null) {
      var vertical = document.createElement('i');
      vertical.className = 'tse-smart-line is-vertical';
      vertical.style.left = (xTarget * scale) + 'px';
      smartGuides.appendChild(vertical);
    }
    if (yTarget != null) {
      var horizontal = document.createElement('i');
      horizontal.className = 'tse-smart-line is-horizontal';
      horizontal.style.top = (yTarget * scale) + 'px';
      smartGuides.appendChild(horizontal);
    }
  }

  function movePlaceholder(event) {
    var placeholder = selectedPlaceholder();
    if (!placeholder || !drag) return;
    var dx = (event.clientX - drag.startX) / scale;
    var dy = (event.clientY - drag.startY) / scale;
    var x = drag.x;
    var y = drag.y;
    var width = drag.width;
    var height = drag.height;
    var handle = drag.handle;

    if (!handle) {
      x = gridSnap(drag.x + dx);
      y = gridSnap(drag.y + dy);
      var sx = bestSnap([x, x + width / 2, x + width], snapTargets('x', drag.key));
      var sy = bestSnap([y, y + height / 2, y + height], snapTargets('y', drag.key));
      x += sx.delta;
      y += sy.delta;
      showSmartLines(sx.target, sy.target);
    } else {
      if (handle.indexOf('e') >= 0) width = gridSnap(drag.width + dx);
      if (handle.indexOf('s') >= 0) height = gridSnap(drag.height + dy);
      if (handle.indexOf('w') >= 0) {
        x = gridSnap(drag.x + dx);
        width = drag.width - (x - drag.x);
      }
      if (handle.indexOf('n') >= 0) {
        y = gridSnap(drag.y + dy);
        height = drag.height - (y - drag.y);
      }
      var resizeX = bestSnap(
        [handle.indexOf('w') >= 0 ? x : x + width],
        snapTargets('x', drag.key)
      );
      var resizeY = bestSnap(
        [handle.indexOf('n') >= 0 ? y : y + height],
        snapTargets('y', drag.key)
      );
      if (handle.indexOf('w') >= 0) {
        x += resizeX.delta;
        width -= resizeX.delta;
      } else width += resizeX.delta;
      if (handle.indexOf('n') >= 0) {
        y += resizeY.delta;
        height -= resizeY.delta;
      } else height += resizeY.delta;
      showSmartLines(resizeX.target, resizeY.target);
    }
    width = Math.max(40, Math.min(1600, width));
    height = Math.max(40, Math.min(900, height));
    x = Math.max(0, Math.min(1600 - width, x));
    y = Math.max(0, Math.min(900 - height, y));
    placeholder.x = Math.round(x);
    placeholder.y = Math.round(y);
    placeholder.width = Math.round(width);
    placeholder.height = Math.round(height);
    render();
  }

  document.addEventListener('pointermove', function (event) {
    if (!drag) return;
    drag.changed = true;
    if (drag.kind === 'placeholder') {
      movePlaceholder(event);
      return;
    }
    var point = stagePoint(event);
    var orientation = drag.orientation;
    var limit = orientation === 'vertical' ? 1600 : 900;
    var position = orientation === 'vertical' ? point.x : point.y;
    drag.outside = position < 0 || position > limit;
    position = Math.max(0, Math.min(limit, position));
    if (Math.abs(position - limit / 2) <= Math.max(5, 8 / scale)) position = limit / 2;
    if (drag.kind === 'ruler-guide') {
      if (!drag.guide) {
        drag.guide = {
          client_id: 'guide-' + Date.now(),
          orientation: orientation,
          position: Math.round(position),
          is_locked: false
        };
        state.guides.push(drag.guide);
        selectedGuideId = drag.guide.client_id;
      }
      drag.guide.position = Math.round(position);
    } else {
      var guide = selectedGuide();
      if (guide) guide.position = Math.round(position);
    }
    render();
  });

  document.addEventListener('pointerup', function () {
    if (!drag) return;
    var changed = !!drag.changed;
    if ((drag.kind === 'guide' || drag.kind === 'ruler-guide') && drag.outside) {
      var removeId = drag.kind === 'ruler-guide'
        ? String(drag.guide && drag.guide.client_id)
        : String(drag.guideId);
      state.guides = state.guides.filter(function (guide) {
        return String(guide.client_id || guide.id) !== removeId;
      });
      selectedGuideId = '';
    }
    drag = null;
    smartGuides.innerHTML = '';
    render();
    if (changed) markChanged();
  });

  function addGuide(orientation, position) {
    if (readonly || mode !== 'template') return;
    remember();
    var limit = orientation === 'vertical' ? 1600 : 900;
    var guide = {
      client_id: 'guide-' + Date.now() + '-' + Math.random().toString(16).slice(2),
      orientation: orientation,
      position: position == null ? limit / 2 : Math.max(0, Math.min(limit, position)),
      is_locked: false
    };
    state.guides.push(guide);
    guidesVisible = true;
    root.classList.remove('is-guides-hidden');
    selectGuide(guide.client_id);
    markChanged();
  }

  function addPlaceholder(type) {
    if (mode !== 'template' || readonly) return;
    remember();
    var index = state.placeholders.length + 1;
    var key = type + '_' + index;
    while (state.placeholders.some(function (item) { return item.placeholder_key === key; })) {
      index += 1;
      key = type + '_' + index;
    }
    state.placeholders.push({
      placeholder_key: key,
      type: type,
      semantic_role: type === 'text' ? 'body' : (type === 'image' ? 'primary_image' : 'video'),
      x: 180, y: 180,
      width: type === 'text' ? 560 : 520,
      height: type === 'text' ? 220 : 320,
      reading_order: index,
      required: false,
      font_family: 'Arial, Helvetica, sans-serif',
      font_size: 28,
      font_weight: 400,
      text_color: '#162235',
      line_height: 1.35,
      alignment: 'left',
      vertical_alignment: 'top',
      background_color: 'transparent',
      border_color: 'transparent',
      border_width: 0,
      padding: 8,
      overflow_behavior: 'auto',
      media_fit: 'contain',
      author_can_resize: false,
      author_can_reposition: false,
      locked: false,
      z_index: index
    });
    selectPlaceholder(key);
    markChanged();
  }

  function removeSelection() {
    var placeholder = selectedPlaceholder();
    var guide = selectedGuide();
    if (guide && !guide.is_locked && !readonly) {
      remember();
      state.guides = state.guides.filter(function (item) {
        return String(item.client_id || item.id) !== selectedGuideId;
      });
      selectedGuideId = '';
      render();
      markChanged();
      return;
    }
    if (placeholder && mode === 'template' && !placeholder.locked && !readonly) {
      remember();
      state.placeholders = state.placeholders.filter(function (item) {
        return item.placeholder_key !== selectedKey;
      });
      delete state.values[selectedKey];
      selectedKey = '';
      render();
      markChanged();
    }
  }

  function changeLayer(direction) {
    var placeholder = selectedPlaceholder();
    if (!placeholder || readonly || mode !== 'template') return;
    remember();
    placeholder.z_index = Math.max(1, (Number(placeholder.z_index) || 1) + (direction === 'forward' ? 1 : -1));
    render();
    markChanged();
  }

  function toggleLock() {
    var placeholder = selectedPlaceholder();
    if (!placeholder || readonly || mode !== 'template') return;
    remember();
    placeholder.locked = !placeholder.locked;
    render();
    markChanged();
  }

  function activeEditable() {
    var active = document.activeElement;
    return active && active.matches('.tse-placeholder-content[contenteditable="true"]') ? active : null;
  }

  function executeCommand(command, value) {
    var editable = activeEditable();
    if (!editable) return;
    remember();
    editable.focus();
    document.execCommand(command, false, value || null);
    editable.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function insertHtml(html) {
    executeCommand('insertHTML', html);
  }

  function pickMedia() {
    var placeholder = selectedPlaceholder();
    if (!placeholder || placeholder.type === 'text' || !fileInput) return;
    fileInput.accept = placeholder.type === 'video'
      ? 'video/mp4,video/quicktime'
      : 'image/jpeg,image/png,image/webp';
    fileInput.click();
  }

  root.addEventListener('mousedown', function (event) {
    var command = event.target.closest('[data-cmd]');
    if (command) {
      event.preventDefault();
      executeCommand(command.dataset.cmd);
    }
    if (event.target.closest('[data-tse-link]')) {
      event.preventDefault();
      var url = window.prompt('Link URL');
      if (url) executeCommand('createLink', url);
    }
    var alignment = event.target.closest('[data-align]');
    if (alignment) {
      event.preventDefault();
      executeCommand('justify' + alignment.dataset.align);
    }
    if (event.target.closest('[data-tse-table]')) {
      event.preventDefault();
      insertHtml('<table><tbody><tr><td>Cell</td><td>Cell</td></tr><tr><td>Cell</td><td>Cell</td></tr></tbody></table>');
    }
    if (event.target.closest('[data-tse-callout]')) {
      event.preventDefault();
      insertHtml('<aside><strong>Key point</strong><p>Callout text</p></aside>');
    }
  });

  root.addEventListener('click', function (event) {
    var add = event.target.closest('[data-tse-add]');
    if (add) addPlaceholder(add.dataset.tseAdd);
    var addGuideButton = event.target.closest('[data-add-guide]');
    if (addGuideButton) addGuide(addGuideButton.dataset.addGuide);
    var layer = event.target.closest('[data-tse-layer],[data-layer-action]');
    if (layer) changeLayer(layer.dataset.tseLayer || layer.dataset.layerAction);
    if (event.target.closest('[data-tse-lock]')) toggleLock();
    if (event.target.closest('[data-remove-placeholder],[data-remove-guide]')) removeSelection();
    if (event.target.closest('[data-pick-media]')) pickMedia();
    var fit = event.target.closest('[data-media-fit]');
    if (fit) {
      var placeholder = selectedPlaceholder();
      if (placeholder) {
        remember();
        placeholder.media_fit = fit.dataset.mediaFit;
        valueFor(placeholder).fit = fit.dataset.mediaFit;
        render();
        markChanged();
      }
    }
    if (event.target.closest('[data-tse-align-menu]')) {
      var selected = selectedPlaceholder();
      if (selected && canMove(selected)) {
        remember();
        selected.x = Math.round((1600 - Number(selected.width)) / 2);
        render();
        markChanged();
      }
    }
    if (event.target.closest('[data-tse-template-chooser]')) {
      var chooser = document.getElementById('tseTemplateChooser');
      if (chooser && typeof chooser.showModal === 'function') chooser.showModal();
    }
    var slideMenuButton = event.target.closest('[data-tse-slide-menu]');
    if (slideMenuButton) {
      event.stopPropagation();
      root.querySelectorAll('.tse-slide-popover').forEach(function (item) { item.remove(); });
      var row = slideMenuButton.closest('.tse-nav-row');
      var popover = document.createElement('div');
      popover.className = 'tse-slide-popover';
      popover.innerHTML = '<button type="button" data-slide-properties>Slide Properties</button>'
        + '<button type="button" data-slide-delete>Delete Slide</button>';
      row.appendChild(popover);
    }
    if (event.target.closest('[data-slide-properties]')) {
      activeInspectorTab = 'advanced';
      document.querySelectorAll('[data-tse-inspector-tab]').forEach(function (item) {
        item.classList.toggle('is-active', item.dataset.tseInspectorTab === 'advanced');
      });
      renderInspector();
      event.target.closest('.tse-slide-popover')?.remove();
    }
    if (event.target.closest('[data-slide-delete]')) {
      var deleteRow = event.target.closest('.tse-nav-row');
      var deleteId = Number(deleteRow && deleteRow.dataset.id);
      if (deleteId !== Number(state.slide_id || 0)) {
        window.location.href = deleteRow.querySelector('.tse-nav-thumb').href;
        return;
      }
      if (window.confirm('Delete this Draft Slide?')) {
        request('delete_structured_slide', {
          slide_id: deleteId,
          content_revision: Number(state.content_revision || 0)
        }).then(function () {
          window.location.href = '/admin/theory_studio/lesson_editor.php?lesson_id=' + Number(state.lesson_id || 0);
        }).catch(function (error) {
          status(error.message, 'error');
        });
      }
    }
    if (event.target.closest('[data-tse-dialog-close]')) {
      var dialog = event.target.closest('dialog');
      if (dialog) dialog.close();
    }
  });

  inspector.addEventListener('change', function (event) {
    if (readonly) return;
    var placeholder = selectedPlaceholder();
    var guide = selectedGuide();
    var property = event.target.dataset.property;
    var guideProperty = event.target.dataset.guideProperty;
    if (guide && (property === 'guide_position' || guideProperty)) {
      remember();
      if (property === 'guide_position') {
        var limit = guide.orientation === 'vertical' ? 1600 : 900;
        guide.position = Math.round(Math.max(0, Math.min(limit, Number(event.target.value) || 0)));
      } else {
        guide[guideProperty] = event.target.checked;
      }
      render();
      markChanged();
      return;
    }
    if (property === 'grid_spacing') {
      gridSpacing = Math.max(5, Math.min(200, Number(event.target.value) || 20));
      updateGrid();
      return;
    }
    if (!placeholder || !property) return;
    remember();
    var value = event.target.type === 'checkbox' ? event.target.checked : event.target.value;
    if ([
      'x', 'y', 'width', 'height', 'reading_order', 'font_size', 'font_weight',
      'line_height', 'border_width', 'padding'
    ].indexOf(property) >= 0) value = Number(value);
    if (property === 'es_html') valueFor(placeholder).es_html = String(value);
    else placeholder[property] = value;
    render();
    markChanged();
  });

  document.querySelectorAll('[data-tse-inspector-tab]').forEach(function (button) {
    button.addEventListener('click', function () {
      activeInspectorTab = button.dataset.tseInspectorTab;
      document.querySelectorAll('[data-tse-inspector-tab]').forEach(function (item) {
        item.classList.toggle('is-active', item === button);
      });
      renderInspector();
    });
  });

  if (fileInput) fileInput.addEventListener('change', function () {
    var placeholder = selectedPlaceholder();
    var file = fileInput.files && fileInput.files[0];
    if (!placeholder || !file) return;
    var form = new FormData();
    form.append('action', 'upload_structured_media');
    form.append('csrf_token', csrf());
    form.append('slide_id', String(state.slide_id || 0));
    form.append('placeholder_key', placeholder.placeholder_key);
    form.append('media', file);
    status('Saving…', 'saving');
    fetch('/admin/theory_studio/api.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-CSRF-TOKEN': csrf() },
      body: form
    }).then(function (response) {
      return response.json().then(function (body) {
        if (!response.ok || !body.ok) throw new Error(body.message || 'Upload failed.');
        return body;
      });
    }).then(function (body) {
      remember();
      state.values[placeholder.placeholder_key] = {
        type: placeholder.type,
        media_asset_id: body.asset.id,
        cdn_url: body.asset.cdn_url,
        fit: placeholder.media_fit || 'contain'
      };
      render();
      markChanged();
    }).catch(function (error) {
      status(error.message, 'error');
    });
    fileInput.value = '';
  });

  function save() {
    if (readonly) return;
    if (saveInFlight) {
      savePending = true;
      return;
    }
    saveInFlight = true;
    savePending = false;
    status('Saving…', 'saving');
    function complete() {
      saveInFlight = false;
      if (savePending) save();
    }
    if (mode === 'template') {
      request('save_template_version', {
        template_id: Number(state.template_id || 0),
        program_id: Number(state.program_id || 0),
        placeholders: state.placeholders,
        guides: state.guides.map(function (guide) {
          return {
            orientation: guide.orientation,
            position: Number(guide.position),
            is_locked: !!guide.is_locked
          };
        })
      }).then(function (body) {
        state.version_id = body.version.id;
        state.version_number = body.version.version_number;
        saved();
        complete();
      }).catch(function (error) {
        status(error.message, 'error');
        complete();
      });
      return;
    }
    request('save_structured_slide', {
      slide_id: Number(state.slide_id || 0),
      content_revision: Number(state.content_revision || 0),
      values: state.values,
      outline_node_id: state.outline_node_id || null
    }).then(function (body) {
      state.content_revision = body.slide.content_revision;
      if (revisionEl) revisionEl.textContent = String(state.content_revision);
      saved();
      complete();
    }).catch(function (error) {
      status(error.message, 'error');
      complete();
    });
  }

  function saved() {
    status('All changes saved', 'saved');
    if (lastSavedEl) {
      lastSavedEl.textContent = 'Last saved: ' + new Date().toLocaleTimeString([], {
        hour: '2-digit', minute: '2-digit'
      });
    }
  }

  function markChanged() {
    window.clearTimeout(saveTimer);
    status('Unsaved changes', 'unsaved');
    if (mode === 'slide') saveTimer = window.setTimeout(save, 1000);
  }

  document.querySelectorAll('[data-tse-save]').forEach(function (button) {
    button.addEventListener('click', save);
  });
  document.getElementById('tseUndo')?.addEventListener('click', undo);
  document.getElementById('tseRedo')?.addEventListener('click', redo);

  function updateGrid() {
    root.classList.toggle('is-grid-visible', showGrid);
    gridLayer.style.setProperty('--tse-grid-size', gridSpacing + 'px');
  }

  document.getElementById('tseGuidesVisible')?.addEventListener('change', function (event) {
    guidesVisible = event.target.checked;
    root.classList.toggle('is-guides-hidden', !guidesVisible);
  });
  document.getElementById('tseSnapGuides')?.addEventListener('change', function (event) {
    snapGuides = event.target.checked;
  });
  document.getElementById('tseShowGrid')?.addEventListener('change', function (event) {
    showGrid = event.target.checked;
    updateGrid();
  });
  document.getElementById('tseSnapGrid')?.addEventListener('change', function (event) {
    snapGrid = event.target.checked;
  });

  function renderRuler(element, limit, horizontal) {
    element.innerHTML = '';
    element.style[horizontal ? 'width' : 'height'] = (limit * scale) + 'px';
    for (var value = 0; value <= limit; value += 50) {
      var tick = document.createElement('i');
      tick.className = 'tse-ruler-tick' + (value % 200 === 0 ? ' is-major' : '');
      tick.style[horizontal ? 'left' : 'top'] = (value * scale) + 'px';
      element.appendChild(tick);
      if (value % 200 === 0) {
        var label = document.createElement('span');
        label.className = 'tse-ruler-label';
        label.textContent = String(value);
        label.style[horizontal ? 'left' : 'top'] = (value * scale) + 'px';
        element.appendChild(label);
      }
    }
  }

  function setScale(nextScale) {
    scale = Math.max(.2, Math.min(1.5, nextScale));
    root.style.setProperty('--tse-scale', String(scale));
    stageWrap.style.width = (1600 * scale) + 'px';
    stageWrap.style.height = (900 * scale) + 'px';
    renderRuler(rulerX, 1600, true);
    renderRuler(rulerY, 900, false);
    renderContextToolbar();
  }

  function fitCanvas() {
    if (zoomMode !== 'fit') return;
    var width = Math.max(320, canvasWorkspace.clientWidth - 86);
    var height = Math.max(240, canvasWorkspace.clientHeight - 86);
    setScale(Math.min(width / 1600, height / 900, 1));
  }

  document.getElementById('tseZoom')?.addEventListener('change', function (event) {
    zoomMode = event.target.value;
    if (zoomMode === 'fit') fitCanvas();
    else setScale(Number(zoomMode) / 100);
  });

  new ResizeObserver(fitCanvas).observe(canvasWorkspace);

  function beginRulerGuide(event, orientation) {
    if (readonly || mode !== 'template') return;
    remember();
    drag = { kind: 'ruler-guide', orientation: orientation, guide: null, outside: false, changed: false };
    event.preventDefault();
  }
  rulerX.addEventListener('pointerdown', function (event) {
    beginRulerGuide(event, 'horizontal');
  });
  rulerY.addEventListener('pointerdown', function (event) {
    beginRulerGuide(event, 'vertical');
  });

  document.getElementById('tseFullscreen')?.addEventListener('click', function () {
    if (document.fullscreenElement === root) document.exitFullscreen();
    else root.requestFullscreen();
  });
  document.getElementById('tsePreview')?.addEventListener('click', function () {
    root.classList.toggle('is-preview');
    if (root.classList.contains('is-preview')) {
      zoomMode = 'fit';
      window.setTimeout(fitCanvas, 0);
    } else {
      window.setTimeout(fitCanvas, 0);
    }
  });

  document.addEventListener('keydown', function (event) {
    var command = event.metaKey || event.ctrlKey;
    var editable = event.target && event.target.closest
      ? event.target.closest('[contenteditable="true"],input,textarea,select')
      : null;
    if (command && event.key.toLowerCase() === 's') {
      event.preventDefault();
      save();
      return;
    }
    if (command && event.key.toLowerCase() === 'z') {
      event.preventDefault();
      event.shiftKey ? redo() : undo();
      return;
    }
    if (editable) return;
    if (event.key === 'Escape') {
      if (root.classList.contains('is-preview')) root.classList.remove('is-preview');
      else deselect();
      return;
    }
    if (event.key === 'Delete' || event.key === 'Backspace') {
      event.preventDefault();
      removeSelection();
      return;
    }
    var placeholder = selectedPlaceholder();
    if (command && event.key.toLowerCase() === 'c' && placeholder) {
      clipboard = clone(placeholder);
      event.preventDefault();
      return;
    }
    if (command && (event.key.toLowerCase() === 'v' || event.key.toLowerCase() === 'd')
      && mode === 'template' && !readonly && (clipboard || placeholder)) {
      event.preventDefault();
      remember();
      var copy = clone(event.key.toLowerCase() === 'd' ? placeholder : clipboard);
      copy.placeholder_key += '_copy_' + Date.now().toString().slice(-4);
      copy.x = Math.min(1600 - copy.width, Number(copy.x) + 20);
      copy.y = Math.min(900 - copy.height, Number(copy.y) + 20);
      copy.reading_order = Math.max.apply(null, state.placeholders.map(function (item) {
        return Number(item.reading_order) || 0;
      }).concat([0])) + 1;
      state.placeholders.push(copy);
      selectPlaceholder(copy.placeholder_key);
      markChanged();
      return;
    }
    if (placeholder && /^Arrow/.test(event.key) && canMove(placeholder)) {
      event.preventDefault();
      remember();
      var delta = event.shiftKey ? 10 : 1;
      if (event.key === 'ArrowLeft') placeholder.x = Math.max(0, placeholder.x - delta);
      if (event.key === 'ArrowRight') placeholder.x = Math.min(1600 - placeholder.width, placeholder.x + delta);
      if (event.key === 'ArrowUp') placeholder.y = Math.max(0, placeholder.y - delta);
      if (event.key === 'ArrowDown') placeholder.y = Math.min(900 - placeholder.height, placeholder.y + delta);
      render();
      markChanged();
    }
  });

  var navigator = document.getElementById('tseNavigatorList');
  if (navigator && navigator.dataset.tseSlideReorder) {
    var draggedRow = null;
    navigator.addEventListener('dragstart', function (event) {
      draggedRow = event.target.closest('.tse-nav-row');
      if (!draggedRow) return;
      event.dataTransfer.effectAllowed = 'move';
    });
    navigator.addEventListener('dragover', function (event) {
      event.preventDefault();
      var row = event.target.closest('.tse-nav-row');
      navigator.querySelectorAll('.is-drag-over').forEach(function (item) {
        item.classList.remove('is-drag-over');
      });
      if (row && row !== draggedRow) row.classList.add('is-drag-over');
    });
    navigator.addEventListener('drop', function (event) {
      event.preventDefault();
      var row = event.target.closest('.tse-nav-row');
      if (!row || !draggedRow || row === draggedRow) return;
      navigator.insertBefore(draggedRow, row);
      navigator.querySelectorAll('.is-drag-over').forEach(function (item) {
        item.classList.remove('is-drag-over');
      });
      var ids = Array.from(navigator.querySelectorAll('.tse-nav-row')).map(function (item) {
        return Number(item.dataset.id);
      });
      request('reorder_structured_slides', {
        lesson_id: Number(state.lesson_id || 0),
        ordered_ids: ids
      }).then(saved).catch(function (error) {
        status(error.message, 'error');
        window.location.reload();
      });
    });
  }

  stage.addEventListener('pointerdown', function (event) {
    if (event.target === stage) deselect();
  });

  render();
  updateGrid();
  fitCanvas();
  status('All changes saved', 'saved');
})();
