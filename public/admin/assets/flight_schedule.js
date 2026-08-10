(function () {
  'use strict';

  var config = window.IPCAFlightSchedule || {};

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function formatCoord(value, digits) {
    var n = Number(value);
    if (!Number.isFinite(n)) return '—';
    return n.toFixed(digits == null ? 5 : digits);
  }

  function renderAdsbHtml(data, options) {
    options = options || {};
    var includeMap = options.includeMap !== false;
    if (!data || data.ok === false) {
      return '<p class="fltsch-adsb-status is-ground">ADS-B unavailable</p>'
        + '<p class="fltsch-muted" style="margin:0">' + escapeHtml((data && data.error) || 'Unable to load ADS-B status.') + '</p>';
    }
    if (!data.in_flight) {
      var groundDetail = data.detail || data.error || data.fetch_error || '';
      return '<p class="fltsch-adsb-status is-ground">Aircraft not in flight</p>'
        + (groundDetail ? '<p class="fltsch-muted" style="margin:0">' + escapeHtml(groundDetail) + '</p>' : '');
    }
    var pos = data.position || {};
    var nearest = data.nearest_airport || (pos && pos.nearest_airport) || null;
    var mapEmbed = data.map_embed_url || pos.map_embed_url || '';
    var mapUrl = data.map_url || pos.map_url || '';
    var rows = [
      ['Latitude', formatCoord(pos.lat)],
      ['Longitude', formatCoord(pos.lon)],
      ['Altitude', pos.altitude_ft != null ? (Number(pos.altitude_ft).toLocaleString() + ' ft') : '—'],
      ['Groundspeed', pos.groundspeed_kt != null ? (Number(pos.groundspeed_kt) + ' kt') : '—'],
      ['Track', pos.track_deg != null ? (Number(pos.track_deg) + '°') : '—'],
      ['Callsign', pos.callsign ? String(pos.callsign) : '—']
    ];
    if (nearest && nearest.icao) {
      rows.push(['Near', String(nearest.icao) + (nearest.distance_nm != null ? (' · ' + nearest.distance_nm + ' NM') : '')]);
    }
    var meta = rows.map(function (row) {
      return '<div><dt>' + escapeHtml(row[0]) + '</dt><dd>' + escapeHtml(row[1]) + '</dd></div>';
    }).join('');
    var map = '';
    if (includeMap) {
      if (mapEmbed) {
        map = '<div class="fltsch-adsb-map"><iframe title="Aircraft position map" loading="lazy" referrerpolicy="no-referrer-when-downgrade" src="'
          + escapeHtml(mapEmbed) + '"></iframe></div>';
      }
      if (mapUrl) {
        map += '<a class="fltsch-adsb-map-link" href="' + escapeHtml(mapUrl) + '" target="_blank" rel="noopener noreferrer">Open full map</a>';
      }
    }
    return '<p class="fltsch-adsb-status is-airborne">Aircraft in flight</p>'
      + '<dl class="fltsch-adsb-meta">' + meta + '</dl>'
      + map;
  }

  function loadAircraftAdsb(aircraftId, bodyEl) {
    if (!bodyEl) return Promise.resolve();
    var id = Number(aircraftId || 0);
    if (!id) {
      bodyEl.innerHTML = '<p class="fltsch-muted" style="margin:0">Select an aircraft to check live position.</p>';
      return Promise.resolve();
    }
    bodyEl.innerHTML = '<p class="fltsch-muted" style="margin:0">Checking aircraft position…</p>';
    var url = String(config.adsbApiUrl || '/admin/api/schedule_aircraft_adsb.php')
      + '?aircraft_id=' + encodeURIComponent(String(id));
    return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (response) { return response.json().catch(function () { return null; }).then(function (data) {
        return { okHttp: response.ok, data: data };
      }); })
      .then(function (result) {
        if (!result.data) {
          bodyEl.innerHTML = renderAdsbHtml({ ok: false, error: 'Invalid ADS-B response.' });
          return;
        }
        bodyEl.innerHTML = renderAdsbHtml(result.data);
      })
      .catch(function () {
        bodyEl.innerHTML = renderAdsbHtml({ ok: false, error: 'ADS-B request failed.' });
      });
  }

  function wireReservationAdsbPanel() {
    var panel = document.getElementById('flightReservationAdsbPanel');
    var body = document.getElementById('flightReservationAdsbBody');
    var select = document.getElementById('flightReservationAircraft');
    var refresh = document.getElementById('flightReservationAdsbRefresh');
    if (!panel || !body) return;

    function currentId() {
      if (select && select.value) return Number(select.value) || 0;
      return Number(panel.getAttribute('data-aircraft-id') || 0) || 0;
    }

    function refreshPanel() {
      var id = currentId();
      panel.setAttribute('data-aircraft-id', String(id || ''));
      return loadAircraftAdsb(id, body);
    }

    if (select) {
      select.addEventListener('change', function () { refreshPanel(); });
    }
    if (refresh) {
      refresh.addEventListener('click', function () { refreshPanel(); });
    }
    if (currentId() > 0) {
      refreshPanel();
    }
  }

  window.IPCAScheduleAdsb = {
    load: loadAircraftAdsb,
    render: renderAdsbHtml,
    wireReservationPanel: wireReservationAdsbPanel
  };

  wireReservationAdsbPanel();

  var scheduler = document.getElementById('flightResourceScheduler');
  if (!scheduler || !Array.isArray(config.reservations)) return;

  var dayStart = Number(config.dayStartMinutes || 300);
  var dayEnd = Number(config.dayEndMinutes || 1320);
  var snap = Number(config.snapMinutes || 15);
  var totalMinutes = dayEnd - dayStart;
  var suppressClick = false;
  var scheduleInteractionActive = false;
  var hoverTip = null;
  var hoverHideTimer = null;

  var ROLE_LABELS = {
    student: 'Student',
    instructor: 'Instructor',
    pic: 'PIC',
    safetypilot: 'Safety Pilot',
    observer: 'Observer'
  };

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

  function formatWeekdayTime(date) {
    return date.toLocaleDateString([], { weekday: 'short' }) + ' ' + formatTime(date);
  }

  function formatReservationWindow(start, end) {
    if (!start || !end) return '—';
    return formatWeekdayTime(start) + ' – ' + formatWeekdayTime(end);
  }

  function formatDateTime(date) {
    return date.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' })
      + ', ' + formatTime(date);
  }

  function showDialog(id) {
    var dialog = document.getElementById(id);
    if (!dialog) return;
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', 'open');
  }

  function roleLabel(role) {
    var key = String(role || '').toLowerCase();
    return ROLE_LABELS[key] || (role ? String(role) : '');
  }

  function meterTriplet(hobbs, tacho, fuel) {
    var parts = [];
    if (hobbs != null && hobbs !== '') parts.push('H ' + Number(hobbs).toFixed(1));
    if (tacho != null && tacho !== '') parts.push('T ' + Number(tacho).toFixed(1));
    if (fuel != null && fuel !== '') parts.push('F ' + String(fuel));
    return parts.length ? '(' + parts.join(' / ') + ')' : '';
  }

  function reservationLegs(reservation) {
    if (Array.isArray(reservation.legs) && reservation.legs.length) return reservation.legs;
    var chain = Array.isArray(reservation.airport_chain) ? reservation.airport_chain.filter(Boolean) : [];
    if (chain.length >= 2) {
      var legs = [];
      for (var i = 0; i < chain.length - 1; i++) {
        legs.push({
          sequence_number: i + 1,
          origin_airport: chain[i],
          destination_airport: chain[i + 1]
        });
      }
      return legs;
    }
    var dep = reservation.planned_departure_airport || '';
    var arr = reservation.planned_destination_airport || '';
    if (dep || arr) {
      return [{ sequence_number: 1, origin_airport: dep, destination_airport: arr }];
    }
    return [];
  }

  function plannedLegLine(leg, index) {
    var seq = leg.sequence_number || (index + 1);
    var origin = leg.origin_airport || '—';
    var destination = leg.destination_airport || '—';
    return 'Leg ' + seq + ' — ' + origin + ' – ' + destination;
  }

  function completedLegLine(leg, index) {
    var seq = leg.sequence_number || (index + 1);
    var origin = leg.origin_airport || '—';
    var destination = leg.destination_airport || '—';
    var off = leg.off_block_local || '—';
    var on = leg.on_block_local || '—';
    var startMeters = meterTriplet(leg.starting_hobbs, leg.starting_tacho, leg.fuel_onboard);
    var endMeters = meterTriplet(leg.ending_hobbs, leg.ending_tacho, leg.fuel_remaining);
    var hours = leg.hobbs_hours != null ? Number(leg.hobbs_hours).toFixed(1) + 'h' : '—';
    return 'Leg ' + seq + ': '
      + origin + ' ' + off + (startMeters ? ' ' + startMeters : '')
      + ' – '
      + destination + ' ' + on + (endMeters ? ' ' + endMeters : '')
      + ' – ' + hours;
  }

  function detailRowsHtml(reservation, options) {
    options = options || {};
    var start = parseLocal(reservation.scheduled_start_time);
    var end = parseLocal(reservation.scheduled_end_time);
    var typeLabel = reservation.reservation_type_label
      || reservation.reservation_type
      || 'Reservation';
    var missionCode = reservation.mission && reservation.mission.code ? reservation.mission.code : '';
    var missionName = reservation.mission && reservation.mission.name ? reservation.mission.name : '';
    var mission = missionCode
      ? (missionName ? missionCode + ' — ' + missionName : missionCode)
      : (missionName || '—');
    var crew = Array.isArray(reservation.crew)
      ? reservation.crew.map(function (member) {
          var name = member.person_name || '';
          var role = roleLabel(member.role);
          if (!name) return '';
          return name + (role ? ' (' + role + ')' : '');
        }).filter(Boolean)
      : [];
    var legs = reservationLegs(reservation);
    var legLines = legs.map(function (leg, index) {
      return options.completed ? completedLegLine(leg, index) : plannedLegLine(leg, index);
    });
    var notes = String(reservation.notes || '').trim();

    var html = ''
      + '<div class="fltsch-detail-row"><dt>Type of Reservation</dt><dd>' + escapeHtml(typeLabel) + '</dd></div>'
      + '<div class="fltsch-detail-row"><dt>Time</dt><dd>' + escapeHtml(formatReservationWindow(start, end)) + '</dd></div>'
      + '<div class="fltsch-detail-row"><dt>Mission</dt><dd>' + escapeHtml(mission) + '</dd></div>'
      + '<div class="fltsch-detail-row"><dt>Crew</dt><dd>'
      + (crew.length
        ? '<ul class="fltsch-detail-list">' + crew.map(function (line) {
            return '<li>' + escapeHtml(line) + '</li>';
          }).join('') + '</ul>'
        : '—')
      + '</dd></div>'
      + '<div class="fltsch-detail-row"><dt>Legs</dt><dd>'
      + (legLines.length
        ? '<ul class="fltsch-detail-list">' + legLines.map(function (line) {
            return '<li>' + escapeHtml(line) + '</li>';
          }).join('') + '</ul>'
        : '—')
      + '</dd></div>'
      + '<div class="fltsch-detail-row"><dt>Public Notes</dt><dd>' + escapeHtml(notes || '—') + '</dd></div>';
    return html;
  }

  function ensureHoverTip() {
    if (hoverTip) return hoverTip;
    hoverTip = document.createElement('div');
    hoverTip.className = 'fltsch-hover-tip';
    hoverTip.hidden = true;
    document.body.appendChild(hoverTip);
    return hoverTip;
  }

  function hideHoverTip() {
    if (hoverHideTimer) {
      window.clearTimeout(hoverHideTimer);
      hoverHideTimer = null;
    }
    var tip = ensureHoverTip();
    tip.hidden = true;
    tip.innerHTML = '';
  }

  function showHoverTip(reservation, event) {
    if (suppressClick) return;
    var tip = ensureHoverTip();
    var aircraft = reservation.aircraft && reservation.aircraft.registration
      ? reservation.aircraft.registration
      : 'Reservation';
    tip.innerHTML = ''
      + '<div class="fltsch-hover-tip-head">' + escapeHtml(aircraft) + '</div>'
      + '<dl class="fltsch-hover-tip-body">' + detailRowsHtml(reservation, {
        completed: reservation.status === 'completed'
      }) + '</dl>';
    tip.hidden = false;
    positionHoverTip(event);
  }

  function positionHoverTip(event) {
    var tip = ensureHoverTip();
    if (tip.hidden) return;
    var padGap = 14;
    var x = event.clientX + padGap;
    var y = event.clientY + padGap;
    tip.style.left = '0px';
    tip.style.top = '0px';
    var width = tip.offsetWidth;
    var height = tip.offsetHeight;
    if (x + width > window.innerWidth - 8) x = Math.max(8, event.clientX - width - padGap);
    if (y + height > window.innerHeight - 8) y = Math.max(8, event.clientY - height - padGap);
    tip.style.left = x + 'px';
    tip.style.top = y + 'px';
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

  function openReservation(reservation) {
    // Live ADS-B is opened from the aircraft tail number, not the reservation block.
    // Dispatched / claimed blocks stay locked here; undispatch remains available from the ADS-B modal.
    if (reservation.status === 'claimed') {
      return;
    }
    if (reservation.editable) {
      window.location.href = String(config.editBaseUrl || '') + encodeURIComponent(reservation.scheduler_record_id);
      return;
    }
    if (reservation.status === 'completed') {
      openCompletedModal(reservation);
    }
  }

  function reservationForAircraft(aircraftId) {
    var id = Number(aircraftId || 0);
    if (!id) return null;
    var claimed = null;
    var fallback = null;
    (config.reservations || []).forEach(function (reservation) {
      var reservationAircraftId = reservation && reservation.aircraft
        ? Number(reservation.aircraft.id || 0)
        : 0;
      if (reservationAircraftId !== id) return;
      if (reservation.status === 'claimed') {
        claimed = reservation;
        return;
      }
      if (!fallback) fallback = reservation;
    });
    return claimed || fallback;
  }

  function syntheticAircraftReservation(aircraftId, registration) {
    return {
      aircraft: {
        id: Number(aircraftId || 0),
        registration: String(registration || '')
      },
      status: '',
      can_undispatch: false,
      scheduled_start_time: null,
      scheduled_end_time: null,
      claimed_at: null,
      mission: null,
      crew: []
    };
  }

  function openAircraftAdsbModal(aircraftId, registration) {
    var id = Number(aircraftId || 0);
    if (!id) return;
    var reservation = reservationForAircraft(id) || syntheticAircraftReservation(id, registration);
    if (!reservation.aircraft) {
      reservation.aircraft = { id: id, registration: String(registration || '') };
    } else {
      reservation.aircraft.id = id;
      if (!reservation.aircraft.registration && registration) {
        reservation.aircraft.registration = String(registration);
      }
    }
    openDispatchedModal(reservation);
  }

  function openCompletedModal(reservation) {
    var aircraft = reservation.aircraft && reservation.aircraft.registration
      ? reservation.aircraft.registration
      : 'Completed flight';
    var title = document.querySelector('#flightCompletedModal .compliance-modal__title, #flightCompletedModal [data-compliance-modal-title]');
    var body = document.getElementById('flightCompletedModalBody');
    if (!body) return;
    if (title) title.textContent = aircraft + ' · Completed flight';
    var aircraftId = reservation.aircraft && reservation.aircraft.id ? Number(reservation.aircraft.id) : 0;
    body.innerHTML = '<div class="fltsch-adsb-panel" style="margin-bottom:14px">'
      + '<div class="fltsch-adsb-head"><strong>Live ADS-B</strong></div>'
      + '<div class="fltsch-adsb-body" id="flightCompletedAdsbBody"><p class="fltsch-muted" style="margin:0">Checking aircraft position…</p></div>'
      + '</div>'
      + '<dl class="fltsch-completed-detail">' + detailRowsHtml(reservation, { completed: true }) + '</dl>'
      + '<p class="fltsch-muted">This flight is locked. Operational values are read-only — use Master Logbook to correct evidence.</p>';
    showDialog('flightCompletedModal');
    if (window.IPCAScheduleAdsb && typeof window.IPCAScheduleAdsb.load === 'function') {
      window.IPCAScheduleAdsb.load(aircraftId, document.getElementById('flightCompletedAdsbBody'));
    }
  }

  var dispatchedTrackChart = null;
  var dispatchedTrackReservation = null;

  function ensureDispatchedTrackChart() {
    var root = document.getElementById('flightDispatchedTrackRoot');
    if (!root) return null;
    if (!dispatchedTrackChart && window.IPCALegTrackChart && typeof window.IPCALegTrackChart.create === 'function') {
      dispatchedTrackChart = window.IPCALegTrackChart.create(root);
    }
    return dispatchedTrackChart;
  }

  function trackWindowForReservation(reservation) {
    var start = parseLocal(reservation.scheduled_start_time);
    var end = parseLocal(reservation.scheduled_end_time);
    var claimed = reservation.claimed_at ? parseLocal(reservation.claimed_at) : null;
    var from = start || claimed || new Date(Date.now() - 6 * 3600 * 1000);
    if (claimed && claimed < from) from = claimed;
    var to = end && end > new Date() ? end : new Date();
    if (end && reservation.status === 'completed') to = end;
    return { from: from, to: to };
  }

  function loadDispatchedTrack(reservation, options) {
    options = options || {};
    var silent = !!options.silent;
    var chart = ensureDispatchedTrackChart();
    var modalRoot = document.getElementById('flightDispatchedTrackRoot');
    var statusEl = (modalRoot && modalRoot.querySelector('#legs-track-status'))
      || document.getElementById('legs-track-status');
    var adsbBody = document.getElementById('flightDispatchedAdsbBody');
    var aircraftId = reservation.aircraft && reservation.aircraft.id ? Number(reservation.aircraft.id) : 0;
    if (!aircraftId) {
      if (adsbBody) adsbBody.innerHTML = renderAdsbHtml({ ok: false, error: 'Aircraft is missing on this reservation.' }, { includeMap: false });
      if (statusEl) {
        statusEl.textContent = 'Cannot load ADS-B track without an aircraft.';
        statusEl.dataset.tone = 'error';
      }
      return Promise.resolve(null);
    }

    var trackWindow = trackWindowForReservation(reservation);
    trackWindow.to = new Date();
    if (!(trackWindow.from instanceof Date) || !Number.isFinite(trackWindow.from.getTime())) {
      trackWindow.from = new Date(Date.now() - 6 * 3600 * 1000);
    }
    if (!(trackWindow.to instanceof Date) || !Number.isFinite(trackWindow.to.getTime())) {
      trackWindow.to = new Date();
    }
    if (!silent && statusEl) {
      statusEl.textContent = 'Loading live ADS-B track…';
      statusEl.dataset.tone = 'loading';
    }
    if (!silent && adsbBody) {
      adsbBody.innerHTML = '<p class="fltsch-muted" style="margin:0">Checking aircraft position…</p>';
    }

    var url = String(config.adsbTrackApiUrl || '/admin/api/schedule_aircraft_adsb_track.php')
      + '?aircraft_id=' + encodeURIComponent(String(aircraftId))
      + '&from=' + encodeURIComponent(trackWindow.from.toISOString())
      + '&to=' + encodeURIComponent(trackWindow.to.toISOString())
      // Live dispatch modal must stay fast: archive traffic over ~1.8M samples
      // previously timed out the whole request and showed "Aircraft not in flight".
      // Nearby aircraft still come from the live ADS-B area query.
      + '&include_traffic=0'
      // Background polls need only current ownship + nearby traffic. Full trace
      // and terrain reconstruction run once on open/manual refresh.
      + (silent ? '&live_only=1' : '');

    return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (response) {
        return response.json().catch(function () { return null; }).then(function (data) {
          return { okHttp: response.ok, data: data, status: response.status };
        });
      })
      .then(function (result) {
        if (dispatchedTrackReservation !== reservation) return null;
        var data = result && result.data;
        if (!data || data.ok === false) {
          if (!silent && adsbBody) {
            adsbBody.innerHTML = renderAdsbHtml({
              ok: false,
              error: (data && data.error) || ('Unable to load ADS-B' + (result && result.status ? (' (HTTP ' + result.status + ')') : '') + '.')
            }, { includeMap: false });
          }
          if (!silent && statusEl) {
            statusEl.textContent = (data && data.error) || 'Unable to load ADS-B track.';
            statusEl.dataset.tone = 'error';
          }
          return null;
        }

        // Status panel is independent of the map — never let chart failures wipe this.
        if (!silent && adsbBody) {
          adsbBody.innerHTML = renderAdsbHtml({
            ok: true,
            in_flight: !!data.in_flight,
            detail: data.detail,
            position: data.position || null,
            nearest_airport: data.position && data.position.nearest_airport ? data.position.nearest_airport : null,
            error: data.fetch_error
          }, { includeMap: false });
        }

        var label = (reservation.aircraft && reservation.aircraft.registration)
          || (data.aircraft && data.aircraft.registration)
          || 'Aircraft';
        var windowStartEpoch = trackWindow.from.getTime() / 1000;

        try {
          if (chart && typeof chart.startLiveFollow === 'function') {
            if (!chart._scheduleLiveStarted) {
              chart._trafficHistoryLoaded = !!(data.traffic && data.traffic.length);
              if (typeof chart.invalidate === 'function') chart.invalidate();
              chart.startLiveFollow({
                label: label,
                pollMs: 3500,
                windowStartEpoch: windowStartEpoch,
                follow: true,
                initialSnapshot: data,
                pollFn: function () {
                  if (dispatchedTrackReservation !== reservation) return Promise.resolve(null);
                  // Silent polls return the payload; pollLiveOnce ingests once.
                  return loadDispatchedTrack(reservation, { silent: true });
                }
              });
              chart._scheduleLiveStarted = true;
            } else if (!silent && typeof chart.ingestLiveSnapshot === 'function') {
              // Manual Refresh: ingest immediately. Interval polls ingest via pollFn return.
              chart.ingestLiveSnapshot(data);
              if (data.traffic && data.traffic.length) chart._trafficHistoryLoaded = true;
            }
          } else if (chart && typeof chart.loadOwnshipSamples === 'function') {
            chart.loadOwnshipSamples(data.track && Array.isArray(data.track.samples) ? data.track.samples : [], {
              style: 'adsb',
              label: label,
              nearby: Array.isArray(data.nearby) ? data.nearby : [],
              emptyMessage: data.in_flight
                ? 'Aircraft is airborne, but no historical track points were returned yet.'
                : 'No ADS-B track samples are available for this reservation window.'
            });
          } else if (statusEl && !silent) {
            statusEl.textContent = data.in_flight
              ? ('In flight · ' + label + ' (map unavailable)')
              : (data.detail || 'Aircraft not in flight');
            statusEl.dataset.tone = data.in_flight ? 'ok' : 'muted';
          }
        } catch (chartError) {
          if (statusEl && !silent) {
            statusEl.textContent = (chartError && chartError.message)
              ? ('Map error: ' + chartError.message)
              : 'Live map failed — ADS-B status above is still valid.';
            statusEl.dataset.tone = 'error';
          }
        }

        globalThis.setTimeout(function () {
          if (chart && typeof chart.invalidate === 'function') chart.invalidate();
        }, 180);
        return data;
      })
      .catch(function (err) {
        if (dispatchedTrackReservation !== reservation) return null;
        if (!silent) {
          var message = (err && err.message) ? String(err.message) : 'ADS-B request failed.';
          if (adsbBody) adsbBody.innerHTML = renderAdsbHtml({ ok: false, error: message }, { includeMap: false });
          if (statusEl) {
            statusEl.textContent = message;
            statusEl.dataset.tone = 'error';
          }
        }
        return null;
      });
  }

  function openUndispatchModal(reservation) {
    var modal = document.getElementById('flightUndispatchModal');
    var form = document.getElementById('flightUndispatchForm');
    if (!modal || !form) {
      window.location.href = String(config.editBaseUrl || '') + encodeURIComponent(reservation.scheduler_record_id);
      return;
    }
    var idInput = form.querySelector('[name="scheduler_record_id"]');
    if (idInput) idInput.value = reservation.scheduler_record_id || '';
    var aircraftEl = document.getElementById('flightUndispatchAircraft');
    var missionEl = document.getElementById('flightUndispatchMission');
    var dispatchEl = document.getElementById('flightUndispatchDispatch');
    if (aircraftEl) {
      aircraftEl.textContent = (reservation.aircraft && reservation.aircraft.registration)
        ? reservation.aircraft.registration
        : '—';
    }
    if (missionEl) {
      missionEl.textContent = (reservation.mission && reservation.mission.code)
        ? reservation.mission.code
        : '—';
    }
    if (dispatchEl) {
      dispatchEl.textContent = reservation.claimed_dispatch_uuid || '—';
    }
    // Close track modal first so undispatch is the focused dialog.
    var trackModal = document.getElementById('flightDispatchedModal');
    if (trackModal && typeof trackModal.close === 'function' && trackModal.open) {
      trackModal.close();
    }
    showDialog('flightUndispatchModal');
  }

  function openDispatchedModal(reservation) {
    dispatchedTrackReservation = reservation;
    var aircraft = reservation.aircraft && reservation.aircraft.registration
      ? reservation.aircraft.registration
      : 'Aircraft';
    var title = document.querySelector('#flightDispatchedModal .compliance-modal__title, #flightDispatchedModal [data-compliance-modal-title]');
    if (title) title.textContent = aircraft + ' · Live ADS-B';
    var summary = document.getElementById('flightDispatchedSummary');
    if (summary) {
      var hasScheduleDetail = !!(reservation.scheduler_record_id || reservation.status === 'claimed' || reservation.status === 'completed');
      summary.innerHTML = (hasScheduleDetail
        ? ('<dl class="fltsch-completed-detail">' + detailRowsHtml(reservation, { completed: reservation.status === 'completed' }) + '</dl>')
        : '')
        + '<p class="fltsch-muted">Live ADS-B follows this aircraft continuously. Full selected-aircraft track is shown; nearby traffic appears without trails.</p>';
    }
    showDialog('flightDispatchedModal');
    var chart = ensureDispatchedTrackChart();
    if (chart) {
      chart._scheduleLiveStarted = false;
      chart._trafficHistoryLoaded = false;
      if (typeof chart.stopLiveFollow === 'function') chart.stopLiveFollow();
      if (typeof chart.reset === 'function') chart.reset(false);
    }
    loadDispatchedTrack(reservation);
    var refresh = document.getElementById('flightDispatchedAdsbRefresh');
    if (refresh && !refresh.dataset.bound) {
      refresh.dataset.bound = '1';
      refresh.addEventListener('click', function () {
        if (dispatchedTrackReservation) loadDispatchedTrack(dispatchedTrackReservation);
      });
    }
    var modal = document.getElementById('flightDispatchedModal');
    if (modal && !modal.dataset.trackCloseBound) {
      modal.dataset.trackCloseBound = '1';
      modal.addEventListener('close', function () {
        dispatchedTrackReservation = null;
        if (dispatchedTrackChart) {
          dispatchedTrackChart._scheduleLiveStarted = false;
          if (typeof dispatchedTrackChart.stopLiveFollow === 'function') dispatchedTrackChart.stopLiveFollow();
          if (typeof dispatchedTrackChart.stopPlayback === 'function') dispatchedTrackChart.stopPlayback();
        }
      });
    }
    globalThis.setTimeout(function () {
      if (dispatchedTrackChart && typeof dispatchedTrackChart.invalidate === 'function') {
        dispatchedTrackChart.invalidate();
      }
    }, 220);
  }

  function openChangeConfirmation(reservation, proposedStart, proposedEnd, proposedAircraftId) {
    var currentStart = parseLocal(reservation.scheduled_start_time);
    var currentEnd = parseLocal(reservation.scheduled_end_time);
    var currentAircraftId = reservation.aircraft && reservation.aircraft.id ? Number(reservation.aircraft.id) : 0;
    var nextAircraftId = proposedAircraftId ? Number(proposedAircraftId) : currentAircraftId;
    var currentAircraft = reservation.aircraft && reservation.aircraft.registration
      ? reservation.aircraft.registration
      : ('#' + currentAircraftId);
    var nextAircraftLabel = currentAircraft;
    if (nextAircraftId && nextAircraftId !== currentAircraftId) {
      var targetTimeline = document.querySelector('.fltsch-resource-timeline[data-resource-key="device:' + nextAircraftId + '"]');
      var rowLabel = targetTimeline && targetTimeline.closest('.fltsch-resource-row');
      var title = rowLabel ? rowLabel.querySelector('.fltsch-resource-label') : null;
      nextAircraftLabel = (title && title.dataset.aircraftRegistration)
        || (title && title.querySelector('.fltsch-tail-reg') && title.querySelector('.fltsch-tail-reg').textContent.trim())
        || ('#' + nextAircraftId);
    }
    document.getElementById('flightChangeRecordId').value = reservation.scheduler_record_id;
    document.getElementById('flightChangeStart').value = postDateTime(proposedStart);
    document.getElementById('flightChangeEnd').value = postDateTime(proposedEnd);
    document.getElementById('flightChangeAircraftId').value = String(nextAircraftId || currentAircraftId || '');
    document.getElementById('flightChangeExpectedUpdatedAt').value = reservation.updated_at || '';
    document.getElementById('flightChangeDetails').innerHTML =
      '<dt>Reservation</dt><dd>' + escapeHtml(eventTitle(reservation)) + '</dd>'
      + '<dt>Current</dt><dd>' + escapeHtml(formatDateTime(currentStart) + ' – ' + formatTime(currentEnd) + ' · ' + currentAircraft) + '</dd>'
      + '<dt>Proposed</dt><dd>' + escapeHtml(formatDateTime(proposedStart) + ' – ' + formatTime(proposedEnd) + ' · ' + nextAircraftLabel) + '</dd>';
    showDialog('flightScheduleChangeModal');
  }

  function aircraftIdFromTimeline(timeline) {
    if (!timeline || !timeline.dataset) return 0;
    var parts = String(timeline.dataset.resourceKey || '').split(':');
    if (parts[0] !== 'device') return 0;
    return Number(parts[1] || 0) || 0;
  }

  function timelineAtPoint(clientX, clientY) {
    var nodes = document.querySelectorAll('.fltsch-resource-timeline');
    for (var i = 0; i < nodes.length; i++) {
      var rect = nodes[i].getBoundingClientRect();
      if (clientY >= rect.top && clientY <= rect.bottom && clientX >= rect.left && clientX <= rect.right) {
        return nodes[i];
      }
    }
    return null;
  }

  function startMove(pointerEvent, reservation, timeline, eventElement, start, end) {
    if (!reservation.editable || pointerEvent.button !== 0) return;
    if (pointerEvent.target.closest('.fltsch-resize-handle')) return;
    scheduleInteractionActive = true;

    var originX = pointerEvent.clientX;
    var originalStart = minutes(start);
    var originalEnd = minutes(end);
    var duration = Math.max(snap, originalEnd - originalStart);
    var nextStart = originalStart;
    var moved = false;
    var originAircraftId = aircraftIdFromTimeline(timeline);
    var proposedAircraftId = originAircraftId;
    var activeTimeline = timeline;
    var rect = timeline.getBoundingClientRect();
    if (typeof eventElement.setPointerCapture === 'function') {
      try { eventElement.setPointerCapture(pointerEvent.pointerId); } catch (error) {}
    }

    function move(event) {
      var delta = snapMinutes((event.clientX - originX) / rect.width * totalMinutes);
      if (!moved && Math.abs(event.clientX - originX) < 5 && Math.abs(event.clientY - pointerEvent.clientY) < 8) return;
      event.preventDefault();
      moved = true;
      suppressClick = true;
      hideHoverTip();
      nextStart = clamp(originalStart + delta, dayStart, dayEnd - duration);
      var proposedStart = withMinutes(start, nextStart);
      var proposedEnd = withMinutes(start, nextStart + duration);
      var position = bounds(proposedStart, proposedEnd);
      eventElement.classList.add('is-resizing');
      eventElement.style.left = position.left + '%';
      eventElement.style.width = position.width + '%';
      eventElement.querySelector('.fltsch-event-meta').textContent = eventDetail(reservation, proposedStart, proposedEnd);
      timeline.classList.add('is-drop-target');

      var over = timelineAtPoint(event.clientX, event.clientY);
      if (over && over !== activeTimeline) {
        var overAircraftId = aircraftIdFromTimeline(over);
        if (overAircraftId > 0) {
          if (activeTimeline) activeTimeline.classList.remove('is-drop-target');
          activeTimeline = over;
          proposedAircraftId = overAircraftId;
          over.appendChild(eventElement);
          over.classList.add('is-drop-target');
          rect = over.getBoundingClientRect();
        }
      } else if (activeTimeline) {
        activeTimeline.classList.add('is-drop-target');
      }
    }

    function up() {
      scheduleInteractionActive = false;
      document.removeEventListener('pointermove', move);
      document.removeEventListener('pointerup', up);
      document.removeEventListener('pointercancel', up);
      if (typeof eventElement.releasePointerCapture === 'function') {
        try { eventElement.releasePointerCapture(pointerEvent.pointerId); } catch (error) {}
      }
      if (activeTimeline) activeTimeline.classList.remove('is-drop-target');
      timeline.classList.remove('is-drop-target');
      eventElement.classList.remove('is-resizing');
      var aircraftChanged = proposedAircraftId > 0 && proposedAircraftId !== originAircraftId;
      if (moved && (nextStart !== originalStart || aircraftChanged)) {
        openChangeConfirmation(
          reservation,
          withMinutes(start, nextStart),
          withMinutes(start, nextStart + duration),
          proposedAircraftId || originAircraftId
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
    scheduleInteractionActive = true;

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
      hideHoverTip();
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
      scheduleInteractionActive = false;
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

  function createEvent(reservation, timeline, lane) {
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
    element.style.top = (8 + Math.max(0, Number(lane || 0)) * 60) + 'px';
    element.removeAttribute('title');
    var evidence = reservation.evidence || {};
    var evidenceHtml = reservation.editable ? '' : '<span class="fltsch-evidence">'
      + evidenceChip('D', 'Dispatch Data', evidence.dispatch)
      + evidenceChip('F', 'Flight Data', evidence.flight)
      + evidenceChip('A', 'Audio', evidence.audio)
      + evidenceChip('B', 'Debrief', evidence.briefing)
      + '</span>';
    element.innerHTML =
      (reservation.editable ? '<span class="fltsch-resize-handle start"></span>' : '')
      + '<span class="fltsch-event-title">' + escapeHtml(eventTitle(reservation)) + '</span>'
      + '<span class="fltsch-event-meta">' + escapeHtml(eventDetail(reservation, start, end)) + '</span>'
      + evidenceHtml
      + (reservation.editable ? '<span class="fltsch-resize-handle end"></span>' : '');

    element.addEventListener('click', function (event) {
      event.stopPropagation();
      if (!suppressClick) openReservation(reservation);
    });
    element.addEventListener('pointerdown', function (event) {
      startMove(event, reservation, timeline, element, start, end);
    });
    element.addEventListener('mouseenter', function (event) {
      if (hoverHideTimer) {
        window.clearTimeout(hoverHideTimer);
        hoverHideTimer = null;
      }
      showHoverTip(reservation, event);
    });
    element.addEventListener('mousemove', function (event) {
      positionHoverTip(event);
    });
    element.addEventListener('mouseleave', function () {
      hoverHideTimer = window.setTimeout(hideHoverTip, 80);
    });
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
    hideHoverTip();
    document.querySelectorAll('.fltsch-resource-timeline').forEach(function (timeline) {
      timeline.innerHTML = '';
      var key = timeline.dataset.resourceKey;
      var matching = config.reservations.filter(function (reservation) {
        return Array.isArray(reservation.resource_keys) && reservation.resource_keys.indexOf(key) !== -1;
      }).sort(function (left, right) {
        return parseLocal(left.scheduled_start_time) - parseLocal(right.scheduled_start_time);
      });
      var laneEnds = [];
      var placements = matching.map(function (reservation) {
        var start = parseLocal(reservation.scheduled_start_time);
        var end = parseLocal(reservation.scheduled_end_time);
        var lane = 0;
        while (lane < laneEnds.length && start < laneEnds[lane]) lane += 1;
        laneEnds[lane] = end;
        return { reservation: reservation, lane: lane };
      });
      var laneCount = Math.max(1, laneEnds.length);
      timeline.style.minHeight = Math.max(68, 8 + laneCount * 60) + 'px';
      placements.forEach(function (placement) {
        if (placement.reservation) {
          createEvent(placement.reservation, timeline, placement.lane);
        }
      });
    });
    renderNowLines();
  }

  function enrichLiveReservation(reservation, previousById) {
    var keys = [];
    var aircraftId = reservation && reservation.aircraft ? Number(reservation.aircraft.id || 0) : 0;
    if (aircraftId > 0) keys.push('device:' + aircraftId);
    (Array.isArray(reservation.crew) ? reservation.crew : []).forEach(function (member) {
      var personId = Number(member.person_id || 0);
      var key = 'staff:' + personId;
      if (personId > 0 && document.querySelector('.fltsch-resource-timeline[data-resource-key="' + key + '"]')) {
        keys.push(key);
      }
    });
    var cohortId = reservation && reservation.cohort ? Number(reservation.cohort.id || 0) : 0;
    if (cohortId > 0) keys.push('cohort:' + cohortId);

    // Preserve inferred cohort placement across a Duty supersession. The source
    // schedule endpoint remains authoritative for aircraft, staff and direct cohort.
    var prior = previousById[String(reservation.scheduler_record_id || '')]
      || previousById[String(reservation.supersedes_scheduler_record_id || '')];
    if (prior && Array.isArray(prior.resource_keys)) {
      prior.resource_keys.forEach(function (key) {
        if (String(key).indexOf('cohort:') === 0) keys.push(String(key));
      });
    }
    reservation.resource_keys = Array.from(new Set(keys));
    return reservation;
  }

  var liveRefreshInFlight = false;
  var liveReservationsFingerprint = JSON.stringify(config.reservations);
  var liveStatus = document.getElementById('flightScheduleLiveStatus');

  function setLiveStatus(state, text) {
    if (!liveStatus) return;
    liveStatus.dataset.state = state;
    liveStatus.textContent = text;
  }

  function updateHeroStats(reservations) {
    var counts = {
      Reservations: reservations.length,
      Today: config.date === postDateTime(new Date()).slice(0, 10) ? reservations.length : 0,
      Available: reservations.filter(function (item) { return item.status === 'scheduled'; }).length,
      'Dispatch Locked': reservations.filter(function (item) { return item.status === 'claimed'; }).length,
      Completed: reservations.filter(function (item) { return item.status === 'completed'; }).length
    };
    document.querySelectorAll('.cmp-stat-chip').forEach(function (chip) {
      var label = chip.querySelector('.cmp-stat-label');
      var value = chip.querySelector('.cmp-stat-value');
      if (!label || !value || !Object.prototype.hasOwnProperty.call(counts, label.textContent.trim())) return;
      value.textContent = String(counts[label.textContent.trim()]);
    });
  }

  function refreshLiveReservations() {
    if (liveRefreshInFlight || scheduleInteractionActive || document.hidden || document.querySelector('dialog[open]')) {
      return Promise.resolve(false);
    }
    var url = String(config.liveReservationsUrl || '/admin/api/schedule_reservations.php')
      + '?date=' + encodeURIComponent(String(config.date || ''))
      + '&_=' + Date.now();
    liveRefreshInFlight = true;
    setLiveStatus('updating', 'LIVE · updating…');
    return fetch(url, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' }
    })
      .then(function (response) {
        return response.json().catch(function () { return null; }).then(function (data) {
          return { okHttp: response.ok, data: data };
        });
      })
      .then(function (result) {
        if (!result.okHttp || !result.data || result.data.ok !== true
            || !Array.isArray(result.data.reservations)) {
          setLiveStatus('warning', 'LIVE · retrying');
          return false;
        }
        var previousById = {};
        config.reservations.forEach(function (reservation) {
          previousById[String(reservation.scheduler_record_id || '')] = reservation;
        });
        var next = result.data.reservations.map(function (reservation) {
          return enrichLiveReservation(reservation, previousById);
        });
        var fingerprint = JSON.stringify(next);
        var updatedAt = new Date();
        setLiveStatus(
          'live',
          'LIVE · updated ' + updatedAt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })
        );
        updateHeroStats(next);
        if (fingerprint === liveReservationsFingerprint) return false;
        config.reservations = next;
        liveReservationsFingerprint = fingerprint;
        renderReservations();
        return true;
      })
      .catch(function () {
        // Keep the last valid schedule visible; the next poll retries automatically.
        setLiveStatus('warning', 'LIVE · offline, retrying');
        return false;
      })
      .finally(function () {
        liveRefreshInFlight = false;
      });
  }

  var liveRefreshMilliseconds = Math.max(3000, Number(config.liveRefreshMilliseconds || 5000));
  window.setInterval(refreshLiveReservations, liveRefreshMilliseconds);
  refreshLiveReservations();
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) refreshLiveReservations();
  });

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

  function setAircraftInFlightState(label, inFlight) {
    if (!label) return;
    var pill = label.querySelector('.fltsch-inflight-pill');
    if (pill) pill.hidden = !inFlight;
    label.classList.toggle('is-in-flight', !!inFlight);
  }

  function refreshAircraftInFlightPills() {
    if (document.hidden) return Promise.resolve();
    var labels = Array.prototype.slice.call(
      document.querySelectorAll('.fltsch-resource-label[data-aircraft-id]')
    );
    if (!labels.length) return Promise.resolve();
    return Promise.all(labels.map(function (label) {
      var aircraftId = Number(label.dataset.aircraftId || 0);
      if (!aircraftId) return Promise.resolve();
      var url = String(config.adsbApiUrl || '/admin/api/schedule_aircraft_adsb.php')
        + '?aircraft_id=' + encodeURIComponent(String(aircraftId))
        + '&_=' + Date.now();
      return fetch(url, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' }
      })
        .then(function (response) {
          return response.json().catch(function () { return null; });
        })
        .then(function (data) {
          setAircraftInFlightState(label, !!(data && data.ok && data.in_flight));
        })
        .catch(function () {
          // A failed ADS-B lookup must not preserve a stale airborne claim.
          setAircraftInFlightState(label, false);
        });
    }));
  }

  function wireAircraftTailAdsb() {
    document.querySelectorAll('.fltsch-resource-label[data-aircraft-id]').forEach(function (label) {
      if (label.dataset.adsbBound === '1') return;
      label.dataset.adsbBound = '1';
      function openFromLabel(event) {
        if (event) event.preventDefault();
        openAircraftAdsbModal(
          Number(label.dataset.aircraftId || 0),
          String(label.dataset.aircraftRegistration || '')
        );
      }
      label.addEventListener('click', openFromLabel);
      label.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ') {
          openFromLabel(event);
        }
      });
    });
  }

  wireAircraftTailAdsb();
  refreshAircraftInFlightPills();
  window.setInterval(refreshAircraftInFlightPills, Math.max(15000, liveRefreshMilliseconds * 3));
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) refreshAircraftInFlightPills();
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
