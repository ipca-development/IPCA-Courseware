(function () {
  'use strict';

  var config = window.IPCAFlightSchedule || {};
  var scheduler = document.getElementById('flightResourceScheduler');
  if (!scheduler || !Array.isArray(config.reservations)) return;

  var dayStart = Number(config.dayStartMinutes || 300);
  var dayEnd = Number(config.dayEndMinutes || 1320);
  var snap = Number(config.snapMinutes || 15);
  var totalMinutes = dayEnd - dayStart;
  var suppressClick = false;

  function pad(value) {
    return String(value).padStart(2, '0');
  }

  function parseLocal(value) {
    var match = String(value || '').replace('T', ' ').match(/^(\d{4})-(\d{2})-(\d{2})[ ](\d{2}):(\d{2})(?::(\d{2}))?/);
    if (!match) return null;
    return new Date(
      Number(match[1]),
      Number(match[2]) - 1,
      Number(match[3]),
      Number(match[4]),
      Number(match[5]),
      Number(match[6] || 0)
    );
  }

  function postDateTime(date) {
    return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate())
      + ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes()) + ':00';
  }

  function minutes(date) {
    return date.getHours() * 60 + date.getMinutes();
  }

  function withMinutes(base, value) {
    var result = new Date(base.getTime());
    result.setHours(0, 0, 0, 0);
    result.setMinutes(value);
    return result;
  }

  function snapMinutes(value) {
    return Math.round(value / snap) * snap;
  }

  function clamp(value, minimum, maximum) {
    return Math.max(minimum, Math.min(maximum, value));
  }

  function formatTime(date) {
    return date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
  }

  function formatDateTime(date) {
    return date.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' })
      + ', ' + formatTime(date);
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (character) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[character];
    });
  }

  function showDialog(id) {
    var dialog = document.getElementById(id);
    if (!dialog) return;
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', 'open');
  }

  function renderAxis() {
    var axis = document.getElementById('flightScheduleTimeAxis');
    if (!axis) return;
    axis.innerHTML = '';
    for (var minute = dayStart; minute <= dayEnd; minute += 60) {
      var label = document.createElement('span');
      label.className = 'fltsch-hour-label';
      label.style.left = ((minute - dayStart) / totalMinutes * 100) + '%';
      label.textContent = pad(Math.floor(minute / 60)) + ':00';
      axis.appendChild(label);
    }
  }

  function eventTitle(reservation) {
    var mission = reservation.mission && reservation.mission.code ? reservation.mission.code : 'Reservation';
    var aircraft = reservation.aircraft && reservation.aircraft.registration ? reservation.aircraft.registration : '';
    return mission + (aircraft ? ' · ' + aircraft : '');
  }

  function eventDetail(reservation, start, end) {
    var crew = Array.isArray(reservation.crew)
      ? reservation.crew.map(function (member) { return member.person_name; }).filter(Boolean).join(', ')
      : '';
    return formatTime(start) + '–' + formatTime(end) + (crew ? ' · ' + crew : '');
  }

  function evidenceChip(shortLabel, fullLabel, evidence) {
    var present = !!(evidence && evidence.present);
    return '<span class="fltsch-evidence-chip' + (present ? ' is-present' : '') + '" title="'
      + escapeHtml(fullLabel + (present ? ' available' : ' not available')) + '">'
      + escapeHtml(shortLabel) + '</span>';
  }

  function bounds(start, end) {
    var startMinute = minutes(start);
    var endMinute = minutes(end);
    if (end.toDateString() !== start.toDateString()) endMinute = dayEnd;
    var visibleStart = clamp(startMinute, dayStart, dayEnd);
    var visibleEnd = clamp(endMinute, dayStart, dayEnd);
    return {
      left: (visibleStart - dayStart) / totalMinutes * 100,
      width: Math.max(.35, (visibleEnd - visibleStart) / totalMinutes * 100)
    };
  }

  function openEdit(reservation) {
    if (!reservation.editable) return;
    window.location.href = String(config.editBaseUrl || '') + encodeURIComponent(reservation.scheduler_record_id);
  }

  function openChangeConfirmation(reservation, proposedStart, proposedEnd) {
    var currentStart = parseLocal(reservation.scheduled_start_time);
    var currentEnd = parseLocal(reservation.scheduled_end_time);
    document.getElementById('flightChangeRecordId').value = reservation.scheduler_record_id;
    document.getElementById('flightChangeStart').value = postDateTime(proposedStart);
    document.getElementById('flightChangeEnd').value = postDateTime(proposedEnd);
    document.getElementById('flightChangeExpectedUpdatedAt').value = reservation.updated_at || '';
    document.getElementById('flightChangeDetails').innerHTML =
      '<dt>Reservation</dt><dd>' + escapeHtml(eventTitle(reservation)) + '</dd>'
      + '<dt>Current</dt><dd>' + escapeHtml(formatDateTime(currentStart) + ' – ' + formatTime(currentEnd)) + '</dd>'
      + '<dt>Proposed</dt><dd>' + escapeHtml(formatDateTime(proposedStart) + ' – ' + formatTime(proposedEnd)) + '</dd>';
    showDialog('flightScheduleChangeModal');
  }

  function startMove(pointerEvent, reservation, timeline, eventElement, start, end) {
    if (!reservation.editable || pointerEvent.button !== 0) return;
    if (pointerEvent.target.closest('.fltsch-resize-handle')) return;

    var originX = pointerEvent.clientX;
    var originalStart = minutes(start);
    var originalEnd = minutes(end);
    var duration = Math.max(snap, originalEnd - originalStart);
    var nextStart = originalStart;
    var moved = false;
    var rect = timeline.getBoundingClientRect();
    if (typeof eventElement.setPointerCapture === 'function') {
      try { eventElement.setPointerCapture(pointerEvent.pointerId); } catch (error) {}
    }

    function move(event) {
      var delta = snapMinutes((event.clientX - originX) / rect.width * totalMinutes);
      if (!moved && Math.abs(event.clientX - originX) < 5) return;
      event.preventDefault();
      moved = true;
      suppressClick = true;
      nextStart = clamp(originalStart + delta, dayStart, dayEnd - duration);
      var proposedStart = withMinutes(start, nextStart);
      var proposedEnd = withMinutes(start, nextStart + duration);
      var position = bounds(proposedStart, proposedEnd);
      eventElement.classList.add('is-resizing');
      eventElement.style.left = position.left + '%';
      eventElement.style.width = position.width + '%';
      eventElement.querySelector('.fltsch-event-meta').textContent = eventDetail(reservation, proposedStart, proposedEnd);
      timeline.classList.add('is-drop-target');
    }

    function up() {
      document.removeEventListener('pointermove', move);
      document.removeEventListener('pointerup', up);
      document.removeEventListener('pointercancel', up);
      if (typeof eventElement.releasePointerCapture === 'function') {
        try { eventElement.releasePointerCapture(pointerEvent.pointerId); } catch (error) {}
      }
      timeline.classList.remove('is-drop-target');
      eventElement.classList.remove('is-resizing');
      if (moved && nextStart !== originalStart) {
        openChangeConfirmation(
          reservation,
          withMinutes(start, nextStart),
          withMinutes(start, nextStart + duration)
        );
      } else if (moved) {
        renderReservations();
      }
      window.setTimeout(function () { suppressClick = false; }, 250);
    }

    document.addEventListener('pointermove', move);
    document.addEventListener('pointerup', up);
    document.addEventListener('pointercancel', up);
  }

  function startResize(pointerEvent, reservation, timeline, eventElement, start, end, edge) {
    if (!reservation.editable || pointerEvent.button !== 0) return;
    pointerEvent.stopPropagation();

    var originX = pointerEvent.clientX;
    var moved = false;
    var rect = timeline.getBoundingClientRect();
    var originalStart = minutes(start);
    var originalEnd = minutes(end);
    var nextStart = originalStart;
    var nextEnd = originalEnd;
    if (typeof eventElement.setPointerCapture === 'function') {
      try { eventElement.setPointerCapture(pointerEvent.pointerId); } catch (error) {}
    }

    function move(event) {
      if (!moved && Math.abs(event.clientX - originX) < 5) return;
      event.preventDefault();
      moved = true;
      var pointerMinute = snapMinutes(dayStart + ((event.clientX - rect.left) / rect.width * totalMinutes));
      if (edge === 'start') nextStart = clamp(pointerMinute, dayStart, nextEnd - snap);
      else nextEnd = clamp(pointerMinute, nextStart + snap, dayEnd);
      var proposedStart = withMinutes(start, nextStart);
      var proposedEnd = withMinutes(start, nextEnd);
      var position = bounds(proposedStart, proposedEnd);
      eventElement.classList.add('is-resizing');
      eventElement.style.left = position.left + '%';
      eventElement.style.width = position.width + '%';
      eventElement.querySelector('.fltsch-event-meta').textContent = eventDetail(reservation, proposedStart, proposedEnd);
    }

    function up() {
      document.removeEventListener('pointermove', move);
      document.removeEventListener('pointerup', up);
      document.removeEventListener('pointercancel', up);
      if (typeof eventElement.releasePointerCapture === 'function') {
        try { eventElement.releasePointerCapture(pointerEvent.pointerId); } catch (error) {}
      }
      eventElement.classList.remove('is-resizing');
      if (moved && (nextStart !== originalStart || nextEnd !== originalEnd)) {
        suppressClick = true;
        openChangeConfirmation(
          reservation,
          withMinutes(start, nextStart),
          withMinutes(start, nextEnd)
        );
      } else if (moved) {
        renderReservations();
      }
      window.setTimeout(function () { suppressClick = false; }, 250);
    }

    document.addEventListener('pointermove', move);
    document.addEventListener('pointerup', up);
    document.addEventListener('pointercancel', up);
  }

  function createEvent(reservation, timeline) {
    var start = parseLocal(reservation.scheduled_start_time);
    var end = parseLocal(reservation.scheduled_end_time);
    if (!start || !end || minutes(end) <= dayStart || minutes(start) >= dayEnd) return;

    var position = bounds(start, end);
    var element = document.createElement('div');
    element.className = 'fltsch-event'
      + (reservation.editable ? '' : ' is-locked')
      + (reservation.status === 'completed' ? ' is-completed' : '')
      + (reservation.status === 'claimed' ? ' is-dispatched' : '');
    element.dataset.type = reservation.reservation_type || 'flight_training';
    element.style.left = position.left + '%';
    element.style.width = position.width + '%';
    element.title = reservation.editable
      ? 'Click to edit. Drag to move; drag either edge to resize.'
      : (reservation.status === 'completed'
        ? 'Completed flight. Schedule and evidence are locked.'
        : (reservation.can_undispatch
          ? 'Dispatched · click to Undispatch if this was accidental.'
          : 'Locked after Dispatch activation.'));
    var evidence = reservation.evidence || {};
    var evidenceHtml = reservation.editable ? '' : '<span class="fltsch-evidence">'
      + evidenceChip('D', 'Dispatch Data', evidence.dispatch)
      + evidenceChip('F', 'Flight Data', evidence.flight)
      + evidenceChip('A', 'Audio', evidence.audio)
      + evidenceChip('B', 'Briefing', evidence.briefing)
      + '</span>';
    element.innerHTML =
      (reservation.editable ? '<span class="fltsch-resize-handle start"></span>' : '')
      + (reservation.editable ? '<button type="button" class="fltsch-event-edit" aria-label="Edit reservation">Edit</button>' : '')
      + (!reservation.editable && reservation.can_undispatch
        ? '<button type="button" class="fltsch-event-edit" aria-label="Undispatch reservation">Undispatch</button>'
        : '')
      + '<span class="fltsch-event-title">' + escapeHtml(eventTitle(reservation)) + '</span>'
      + '<span class="fltsch-event-meta">' + escapeHtml(eventDetail(reservation, start, end)) + '</span>'
      + evidenceHtml
      + (reservation.editable ? '<span class="fltsch-resize-handle end"></span>' : '');

    element.addEventListener('click', function (event) {
      event.stopPropagation();
      if (!suppressClick) openEdit(reservation);
    });
    element.addEventListener('pointerdown', function (event) {
      startMove(event, reservation, timeline, element, start, end);
    });
    var editButton = element.querySelector('.fltsch-event-edit');
    if (editButton) {
      editButton.addEventListener('pointerdown', function (event) {
        event.stopPropagation();
      });
      editButton.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();
        openEdit(reservation);
      });
    }
    element.querySelectorAll('.fltsch-resize-handle').forEach(function (handle) {
      handle.addEventListener('pointerdown', function (event) {
        startResize(
          event,
          reservation,
          timeline,
          element,
          start,
          end,
          handle.classList.contains('start') ? 'start' : 'end'
        );
      });
    });
    timeline.appendChild(element);
  }

  function renderNowLines() {
    if (config.date !== postDateTime(new Date()).slice(0, 10)) return;
    var currentMinute = minutes(new Date());
    if (currentMinute < dayStart || currentMinute > dayEnd) return;
    document.querySelectorAll('.fltsch-resource-timeline').forEach(function (timeline) {
      var line = document.createElement('span');
      line.className = 'fltsch-now-line';
      line.style.left = ((currentMinute - dayStart) / totalMinutes * 100) + '%';
      timeline.appendChild(line);
    });
  }

  function renderReservations() {
    document.querySelectorAll('.fltsch-resource-timeline').forEach(function (timeline) {
      timeline.innerHTML = '';
      var key = timeline.dataset.resourceKey;
      config.reservations.forEach(function (reservation) {
        if (Array.isArray(reservation.resource_keys) && reservation.resource_keys.indexOf(key) !== -1) {
          createEvent(reservation, timeline);
        }
      });
    });
    renderNowLines();
  }

  function prepareNewReservation(timeline, event) {
    if (event.target !== timeline) return;
    var rect = timeline.getBoundingClientRect();
    var startMinute = snapMinutes(dayStart + ((event.clientX - rect.left) / rect.width * totalMinutes));
    startMinute = clamp(startMinute, dayStart, dayEnd - 60);
    var date = parseLocal(config.date + ' 00:00:00');
    var start = withMinutes(date, startMinute);
    var end = withMinutes(date, Math.min(dayEnd, startMinute + 60));
    var form = document.getElementById('flightReservationForm');
    form.querySelector('[name="scheduled_start_date"]').value = config.date;
    form.querySelector('[name="scheduled_start_clock"]').value = pad(start.getHours()) + ':' + pad(start.getMinutes());
    form.querySelector('[name="scheduled_end_date"]').value = config.date;
    form.querySelector('[name="scheduled_end_clock"]').value = pad(end.getHours()) + ':' + pad(end.getMinutes());

    var parts = String(timeline.dataset.resourceKey || '').split(':');
    if (parts[0] === 'device') {
      form.querySelector('[name="aircraft_id"]').value = parts[1];
    } else if (parts[0] === 'staff') {
      var crew = form.querySelector('[data-crew-user]');
      crew.value = parts[1];
      crew.dispatchEvent(new Event('change', { bubbles: true }));
    } else if (parts[0] === 'cohort') {
      document.getElementById('flightReservationCohort').value = parts[1];
    }
    showDialog('flightReservationModal');
  }

  document.querySelectorAll('.fltsch-resource-timeline').forEach(function (timeline) {
    timeline.addEventListener('click', function (event) {
      prepareNewReservation(timeline, event);
    });
  });

  renderAxis();
  renderReservations();
  var changeModal = document.getElementById('flightScheduleChangeModal');
  if (changeModal) {
    changeModal.querySelectorAll('[data-compliance-modal-close]').forEach(function (button) {
      button.addEventListener('click', renderReservations);
    });
  }

  var scroll = document.querySelector('.fltsch-scheduler-scroll');
  if (scroll) {
    var preferredMinute = config.date === postDateTime(new Date()).slice(0, 10)
      ? clamp(minutes(new Date()) - 120, dayStart, dayEnd)
      : 7 * 60;
    var timeline = document.querySelector('.fltsch-resource-timeline');
    if (timeline) {
      scroll.scrollLeft = Math.max(0, ((preferredMinute - dayStart) / totalMinutes * timeline.clientWidth) - 260);
    }
  }
})();
