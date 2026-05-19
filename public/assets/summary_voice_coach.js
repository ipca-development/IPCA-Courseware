/*
 * Maya Summary Coach V3 voice layer.
 * WebRTC/OpenAI Realtime prototype that attaches to the existing V3 coach UI.
 */
(function (global) {
  'use strict';

  if (global.IPCASummaryVoiceCoach) return;

  function $(sel, root) { return (root || document).querySelector(sel); }

  function plainTextFromEditor(editor) {
    if (!editor) return '';
    if (editor.value !== undefined && editor.tagName === 'TEXTAREA') return String(editor.value || '');
    return String(editor.textContent || editor.innerText || '');
  }

  function htmlFromEditor(editor) {
    if (!editor || editor.tagName === 'TEXTAREA') return '';
    return String(editor.innerHTML || '');
  }

  function truncate(s, n) {
    s = String(s || '');
    return s.length > n ? s.slice(s.length - n) : s;
  }

  function createEl(tag, cls, text) {
    var el = document.createElement(tag);
    if (cls) el.className = cls;
    if (text != null) el.textContent = text;
    return el;
  }

  function setIconButton(button, label, svgPath) {
    button.setAttribute('aria-label', label);
    button.setAttribute('title', label);
    button.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="' + svgPath + '"></path></svg>';
  }

  function debug() {
    try {
      if (!global.localStorage || global.localStorage.getItem('mayaVoiceDebug') !== '1') return;
      var args = Array.prototype.slice.call(arguments);
      args.unshift('[maya-voice]');
      global.console.debug.apply(global.console, args);
    } catch (e) {}
  }

  function eventKey(msg) {
    return String(msg && (msg.event_id || msg.item_id || msg.response_id || msg.call_id) || '');
  }

  function VoiceCoach(root, config, textCoach) {
    this.root = root;
    this.config = config || {};
    this.textCoach = textCoach || null;
    this.editor = null;
    this.pc = null;
    this.dc = null;
    this.localStream = null;
    this.remoteAudio = null;
    this.voiceSessionId = 0;
    this.blueprintVersionId = 0;
    this.realtimeModel = '';
    this.realtimeEndpoint = 'https://api.openai.com/v1/realtime/calls';
    this.connected = false;
    this.muted = false;
    this.summaryTimer = null;
    this.lastSummarySentAt = 0;
    this.realtimeResponseInProgress = false;
    this.pendingResponseInstructions = '';
    this.processedRealtimeEvents = {};
    this.processedToolCalls = {};
    this.processedTranscripts = {};
    this.finalReviewInProgress = false;
    this.status = 'Ready';
    this.lastLiveMessage = '';
    this._buildUi();
    this._bindEditor();
    this._wire();
  }

  VoiceCoach.prototype._buildUi = function () {
    if ($('[data-maya-voice]', this.root)) {
      this.el = $('[data-maya-voice]', this.root);
      return;
    }
    var box = createEl('div', 'maya-voice-card');
    box.setAttribute('data-maya-voice', '');

    var head = createEl('div', 'maya-voice-head');
    head.appendChild(createEl('div', 'maya-voice-title', 'Voice coaching'));
    this.statusEl = createEl('div', 'maya-voice-status', 'Ready');
    this.statusEl.setAttribute('data-maya-voice-status', '');
    head.appendChild(this.statusEl);
    box.appendChild(head);

    var note = createEl('div', 'maya-voice-privacy', 'Voice coaching may be transcribed and stored to help evaluate your understanding and improve your summary.');
    box.appendChild(note);

    var controls = createEl('div', 'maya-voice-controls');
    this.btnStart = createEl('button', 'maya-voice-btn primary');
    this.btnStart.type = 'button';
    setIconButton(this.btnStart, 'Start call', 'M6.6 10.8c1.5 3 3.6 5.1 6.6 6.6l2.2-2.2c.3-.3.8-.4 1.2-.3 1.3.4 2.6.6 4 .6.7 0 1.2.5 1.2 1.2v3.5c0 .7-.5 1.2-1.2 1.2C10.4 22 2 13.6 2 3.4 2 2.7 2.5 2.2 3.2 2.2h3.5c.7 0 1.2.5 1.2 1.2 0 1.4.2 2.7.6 4 .1.4 0 .9-.3 1.2l-1.6 2.2z');
    this.btnMute = createEl('button', 'maya-voice-btn');
    this.btnMute.type = 'button';
    setIconButton(this.btnMute, 'Mute microphone', 'M12 14c1.7 0 3-1.3 3-3V5c0-1.7-1.3-3-3-3S9 3.3 9 5v6c0 1.7 1.3 3 3 3zm5.3-3c0 3-2.5 5.1-5.3 5.1S6.7 14 6.7 11H5c0 3.4 2.7 6.2 6 6.7V21h2v-3.3c3.3-.5 6-3.3 6-6.7h-1.7z');
    this.btnMute.disabled = true;
    this.btnEnd = createEl('button', 'maya-voice-btn danger');
    this.btnEnd.type = 'button';
    setIconButton(this.btnEnd, 'End call', 'M12 9c-2.9 0-5.6.9-7.8 2.4-.7.5-1.1 1.2-1.1 2.1v2.2c0 .7.5 1.2 1.2 1.2h3.5c.7 0 1.2-.5 1.2-1.2v-1.8c1.9-.6 4.1-.6 6 0v1.8c0 .7.5 1.2 1.2 1.2h3.5c.7 0 1.2-.5 1.2-1.2v-2.2c0-.8-.4-1.6-1.1-2.1C17.6 9.9 14.9 9 12 9z');
    this.btnEnd.disabled = true;
    controls.appendChild(this.btnStart);
    controls.appendChild(this.btnMute);
    controls.appendChild(this.btnEnd);
    box.appendChild(controls);

    this.liveEl = createEl('div', 'maya-voice-live', 'Idle');
    this.liveEl.setAttribute('data-maya-voice-live', '');
    box.appendChild(this.liveEl);

    var cockpit = $('.maya-cockpit', this.root) || this.root;
    var compose = $('.maya-compose-area', this.root);
    cockpit.insertBefore(box, compose || null);
    this.el = box;
  };

  VoiceCoach.prototype._wire = function () {
    var self = this;
    this.btnStart.addEventListener('click', function () { self.start(); });
    this.btnMute.addEventListener('click', function () { self.toggleMute(); });
    this.btnEnd.addEventListener('click', function () { self.end(); });
  };

  VoiceCoach.prototype._bindEditor = function () {
    var sel = this.config.editorSelector;
    this.editor = typeof sel === 'string' ? document.querySelector(sel) : (sel || null);
    if (!this.editor) return;
    var self = this;
    this.editor.addEventListener('input', function () {
      if (!self.connected || !self.dc || self.dc.readyState !== 'open') return;
      if (self.summaryTimer) clearTimeout(self.summaryTimer);
      self.summaryTimer = setTimeout(function () { self.sendSummaryObservation(); }, 2500);
    });
  };

  VoiceCoach.prototype._setStatus = function (status) {
    this.status = status;
    if (this.statusEl) this.statusEl.textContent = status;
    if (this.liveEl) this.liveEl.textContent = status === 'Ready' ? 'Idle' : status + '...';
    if (this.el) this.el.setAttribute('data-voice-status', status.toLowerCase().replace(/\s+/g, '_'));
  };

  VoiceCoach.prototype._setVoiceModeActive = function (active) {
    if (this.root) this.root.setAttribute('data-voice-active', active ? '1' : '0');
    if (this.textCoach && this.textCoach.elReply) {
      this.textCoach.elReply.disabled = !!active;
      this.textCoach.elReply.setAttribute('aria-disabled', active ? 'true' : 'false');
      if (active) this.textCoach.elReply.blur();
    }
    if (this.textCoach && this.textCoach.btnContinue) {
      this.textCoach.btnContinue.disabled = !!active;
      this.textCoach.btnContinue.setAttribute('aria-disabled', active ? 'true' : 'false');
    }
  };

  VoiceCoach.prototype.start = function () {
    if (this.connected) return;
    var self = this;
    this._setStatus('Connecting');
    this._setVoiceModeActive(true);
    this.btnStart.disabled = true;

    this._postJson(this.config.voiceTokenUrl || '/student/api/summary_voice_token.php', {
      lesson_id: this.config.lessonId,
      cohort_id: this.config.cohortId || 0,
      summary_id: this.config.summaryId || 0,
      context: this.config.context || 'player'
    }).then(function (token) {
      if (!token || token.ok === false) throw new Error(token && token.error ? token.error : 'Voice token failed');
      self.voiceSessionId = parseInt(token.voice_session_id, 10) || 0;
      self.blueprintVersionId = parseInt(token.blueprint_version_id, 10) || 0;
      self.realtimeModel = token.realtime_model || 'gpt-realtime';
      self.realtimeEndpoint = token.realtime_endpoint || 'https://api.openai.com/v1/realtime/calls';
      debug('token received', {
        model: self.realtimeModel,
        endpoint: self.realtimeEndpoint,
        session_id: token.session_id || '',
        voice_session_id: self.voiceSessionId
      });
      return self._connectRealtime(token.client_secret);
    }).then(function () {
      self.connected = true;
      self.btnMute.disabled = false;
      self.btnEnd.disabled = false;
      self._setStatus('Listening');
      self._sendResponseCreate('Start the voice coaching call by calling get_current_coaching_state first. Then follow the returned coach_state exactly. If STATE_WAITING_FOR_SUMMARY_WRITE, give one brief instruction and let the student write.');
    }).catch(function (err) {
      self._setStatus('Ended');
      self._setVoiceModeActive(false);
      self.btnStart.disabled = false;
      debug('voice start failed', err && err.message ? err.message : String(err));
    });
  };

  VoiceCoach.prototype._connectRealtime = function (clientSecret) {
    var self = this;
    this.pc = new RTCPeerConnection();
    this.pc.onconnectionstatechange = function () {
      debug('peer connection state', self.pc.connectionState);
      if (self.pc.connectionState === 'failed' || self.pc.connectionState === 'disconnected') self._setStatus('Connection issue');
    };
    this.pc.oniceconnectionstatechange = function () { debug('ice connection state', self.pc.iceConnectionState); };
    this.remoteAudio = document.createElement('audio');
    this.remoteAudio.autoplay = true;
    this.remoteAudio.setAttribute('playsinline', 'playsinline');
    this.pc.ontrack = function (event) {
      self.remoteAudio.srcObject = event.streams[0];
      self._setStatus('Maya speaking');
    };
    this.dc = this.pc.createDataChannel('oai-events');
    this.dc.onopen = function () {
      debug('data channel open');
      self._setStatus('Listening');
    };
    this.dc.onclose = function () { debug('data channel close'); };
    this.dc.onerror = function (event) { debug('data channel error', event); };
    this.dc.onmessage = function (event) { self._handleRealtimeEvent(event); };

    return navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
      self.localStream = stream;
      stream.getTracks().forEach(function (track) { self.pc.addTrack(track, stream); });
      return self.pc.createOffer();
    }).then(function (offer) {
      return self.pc.setLocalDescription(offer).then(function () { return offer; });
    }).then(function (offer) {
      var endpoint = self.realtimeEndpoint || 'https://api.openai.com/v1/realtime/calls';
      debug('posting sdp offer', { endpoint: endpoint, model: self.realtimeModel });
      return fetch(endpoint, {
        method: 'POST',
        headers: {
          Authorization: 'Bearer ' + clientSecret,
          'Content-Type': 'application/sdp'
        },
        body: offer.sdp
      });
    }).then(function (res) {
      if (!res.ok) {
        return res.text().then(function (t) {
          var detail = String(t || '').trim();
          if (detail.length > 500) detail = detail.slice(0, 500);
          throw new Error('Realtime SDP exchange failed (HTTP ' + res.status + ')' + (detail ? ': ' + detail : ''));
        });
      }
      return res.text();
    }).then(function (answerSdp) {
      debug('sdp answer received', { length: String(answerSdp || '').length });
      return self.pc.setRemoteDescription({ type: 'answer', sdp: answerSdp });
    });
  };

  VoiceCoach.prototype._handleRealtimeEvent = function (event) {
    var msg = null;
    try { msg = JSON.parse(event.data); } catch (e) { return; }
    var type = String(msg.type || '');
    if (type === 'error') {
      debug('realtime error', msg.error || msg);
      if (msg.error && String(msg.error.message || '').indexOf('active response in progress') !== -1) {
        this.realtimeResponseInProgress = true;
        this.pendingResponseInstructions = '';
      }
      return;
    }
    debug('event', type);
    if (type === 'response.created') {
      this.realtimeResponseInProgress = true;
      debug('response lock set from response.created');
    }
    if (type === 'response.output_audio_transcript.done' && msg.transcript) {
      if (this._isDuplicateTranscriptEvent(msg, 'maya')) return;
      var mayaTranscript = this._sanitizeNoncanonicalAcceptanceText(msg.transcript);
      this._appendMainConversation('maya', mayaTranscript);
      this._saveTranscript('maya', mayaTranscript, 'audio_transcript');
    }
    if (type === 'conversation.item.input_audio_transcription.completed' && msg.transcript) {
      if (this._isDuplicateTranscriptEvent(msg, 'student')) return;
      this._appendMainConversation('student', msg.transcript);
      this._saveTranscript('student', msg.transcript, 'audio_transcript');
      if (this._isOfficialFinalReviewRequest(msg.transcript)) {
        this._runOfficialFinalReviewFromVoice(msg.transcript);
      }
    }
    if (type === 'response.function_call_arguments.done') {
      this._runTool(msg.name, msg.arguments || '{}', msg.call_id || '');
    }
    if (type === 'response.done' || type === 'response.failed' || type === 'response.cancelled' || type === 'response.canceled') {
      this._releaseResponseLock(false);
      var createdFollowup = type === 'response.done' ? this._handleResponseDone(msg) : false;
      if (!createdFollowup) this._drainPendingResponse();
      this._setStatus('Listening');
    }
  };

  VoiceCoach.prototype._handleResponseDone = function (msg) {
    var outputs = msg && msg.response && Array.isArray(msg.response.output) ? msg.response.output : [];
    var ranTool = false;
    for (var i = 0; i < outputs.length; i += 1) {
      if (outputs[i] && outputs[i].type === 'function_call') {
        ranTool = this._runTool(outputs[i].name, outputs[i].arguments || '{}', outputs[i].call_id || '') || ranTool;
      }
    }
    return ranTool;
  };

  VoiceCoach.prototype._isDuplicateTranscriptEvent = function (msg, role) {
    var transcript = String(msg.transcript || '').trim();
    if (!transcript) return true;
    var key = eventKey(msg) || (role + ':' + transcript.toLowerCase().replace(/\s+/g, ' ').slice(0, 220));
    if (this.processedTranscripts[key]) {
      debug('duplicate transcript ignored', { role: role, key: key });
      return true;
    }
    this.processedTranscripts[key] = Date.now();
    return false;
  };

  VoiceCoach.prototype._runTool = function (name, argString, callId) {
    var toolKey = String(callId || name + ':' + argString);
    if (toolKey && this.processedToolCalls[toolKey]) {
      debug('duplicate tool call ignored', { name: name, call_id: callId || '' });
      return false;
    }
    if (toolKey) this.processedToolCalls[toolKey] = Date.now();
    var args = {};
    try { args = JSON.parse(argString || '{}'); } catch (e) { args = {}; }
    if (name === 'get_current_coaching_state' || name === 'analyze_summary_draft' || name === 'request_final_review') {
      args.summary_excerpt = args.summary_excerpt || truncate(plainTextFromEditor(this.editor), 1800);
      args.summary_html = args.summary_html || htmlFromEditor(this.editor);
    }
    var self = this;
    this._postJson(this.config.apiUrl || '/student/api/summary_coach.php', {
      action: 'voice_tool',
      tool_name: name,
      arguments: args,
      voice_session_id: this.voiceSessionId,
      lesson_id: this.config.lessonId,
      cohort_id: this.config.cohortId || 0,
      summary_id: this.config.summaryId || 0,
      context: this.config.context || 'player',
      summary_excerpt: truncate(plainTextFromEditor(this.editor), 1800)
    }).then(function (out) {
      if (out && out.structure_insertion) self._insertStructureFromVoice(out.structure_insertion);
      if (out && out.active_assignment) self._applyActiveAssignment(out.active_assignment);
      if (out && out.current_task) self._applyCurrentTask(out.current_task);
      if (out && out.coach_state) self._applyCoachState(out.coach_state);
      if (out && (out.readiness || out.review_status || out.canonical_check)) self._applyReviewResult(out);
      self._sendToolOutput(
        callId,
        out || { ok: true },
        name === 'request_final_review'
          ? 'Report the official final review result exactly. If canonical_accepted=true, say the summary is accepted and the student can continue. If canonical_check_ran=true but canonical_accepted=false, say the official check did not accept it yet and point to the feedback/blockers. If canonical_check_ran=false, do not use accepted, validated, complete, progress-test, or move-forward wording.'
          : 'Continue the voice coaching naturally using the tool result. Do not write the student summary for them.'
      );
    }).catch(function (err) {
      self._sendToolOutput(callId, { ok: false, error: err && err.message ? err.message : String(err) });
    });
    return true;
  };

  VoiceCoach.prototype._sendToolOutput = function (callId, output, followupInstructions) {
    if (!this.dc || this.dc.readyState !== 'open' || !callId) return;
    this.dc.send(JSON.stringify({
      type: 'conversation.item.create',
      item: {
        type: 'function_call_output',
        call_id: callId,
        output: JSON.stringify(output)
      }
    }));
    this._sendResponseCreate(followupInstructions || 'Continue the voice coaching naturally using the tool result. Do not write the student summary for them.');
  };

  VoiceCoach.prototype._isOfficialFinalReviewRequest = function (text) {
    text = String(text || '').toLowerCase();
    return /validate my summary|finalize my summary|review my summary|final review|progress test|move to progress|move forward|still shows? draft|summary still says draft|why does it still show draft|why is it draft|are you going to take care of this|do i have to end the call|ready for you to review/.test(text);
  };

  VoiceCoach.prototype._sanitizeNoncanonicalAcceptanceText = function (text) {
    text = String(text || '');
    var accepted = !!(this.textCoach && this.textCoach.summaryState && this.textCoach.summaryState.reviewStatus === 'acceptable');
    if (accepted) return text;
    if (!/\b(validated|finalized|accepted|ready for (?:the )?progress test|you can move forward|you(?:'re| are) all set|all set to move forward|summary is complete|summary(?:'s| is) complete|move on to the next part|next part of your training)\b/i.test(text)) {
      return text;
    }
    return 'Your summary appears ready. I can run the official final review now.';
  };

  VoiceCoach.prototype._runOfficialFinalReviewFromVoice = function (studentText) {
    if (this.finalReviewInProgress) return;
    this.finalReviewInProgress = true;
    this._setStatus('Running final review');
    var self = this;
    this._postJson(this.config.apiUrl || '/student/api/summary_coach.php', {
      action: 'final_review',
      lesson_id: this.config.lessonId,
      cohort_id: this.config.cohortId || 0,
      summary_id: this.config.summaryId || 0,
      context: this.config.context || 'player',
      summary_excerpt: truncate(plainTextFromEditor(this.editor), 2200),
      summary_html: htmlFromEditor(this.editor),
      coach_history: this.textCoach && Array.isArray(this.textCoach.history) ? this.textCoach.history.slice(-12) : []
    }).then(function (out) {
      self.finalReviewInProgress = false;
      self._setStatus('Listening');
      if (!out || out.ok === false) return;
      self._applyReviewResult(out);
      if (self.textCoach && typeof self.textCoach._absorbResponse === 'function') {
        self.textCoach._absorbResponse(out, { isFinalReview: true, studentReply: '' });
      } else if (out.maya_message) {
        self._appendMainConversation('maya', out.maya_message);
      }
      if (self.dc && self.dc.readyState === 'open') {
        var instruction = out.canonical_accepted
          ? 'The official final review accepted the summary. Tell the student clearly that it is accepted and they can continue. Do not continue section coaching.'
          : 'The official final review did not accept the summary yet or the deterministic precheck blocked it. Tell the student the exact result and do not continue section coaching.';
        self._sendResponseCreate(instruction);
      }
    }).catch(function () {
      self.finalReviewInProgress = false;
      self._setStatus('Listening');
    });
  };

  VoiceCoach.prototype._applyReviewResult = function (out) {
    if (!out || !this.textCoach) return;
    if (out.readiness) {
      this.textCoach.readiness = {
        ready_for_final_review: !!out.readiness.ready_for_final_review,
        missing: Array.isArray(out.readiness.missing) ? out.readiness.missing.slice(0) : [],
        minimum_interactions_met: !!out.readiness.minimum_interactions_met,
        unresolved_required_question: !!out.readiness.unresolved_required_question
      };
    }
    if (typeof out.review_status === 'string' && out.review_status && typeof this.textCoach.setSummaryState === 'function') {
      this.textCoach.setSummaryState({
        reviewStatus: out.review_status,
        locked: !!out.student_soft_locked,
        hasText: this.textCoach.summaryState ? this.textCoach.summaryState.hasText : true,
        wordCount: this.textCoach.summaryState ? this.textCoach.summaryState.wordCount : 0,
        summaryId: this.textCoach.summaryState ? this.textCoach.summaryState.summaryId : (this.config.summaryId || 0)
      });
    }
    if ((out.canonical_check_ran || out.canonical_accepted) && typeof this.config.onCanonicalAccept === 'function') {
      try { this.config.onCanonicalAccept(out.canonical_check || null, out); } catch (e) {}
    }
    if (typeof this.textCoach._renderState === 'function') this.textCoach._renderState();
  };

  VoiceCoach.prototype._sendResponseCreate = function (instructions) {
    if (!this.dc || this.dc.readyState !== 'open') return;
    instructions = instructions || 'Continue coaching the student using the active blueprint.';
    if (this.realtimeResponseInProgress) {
      this.pendingResponseInstructions = instructions;
      debug('response.create queued because response is active');
      return;
    }
    this.realtimeResponseInProgress = true;
    debug('response.create sent');
    this.dc.send(JSON.stringify({
      type: 'response.create',
      response: {
        output_modalities: ['audio'],
        instructions: instructions
      }
    }));
  };

  VoiceCoach.prototype._releaseResponseLock = function () {
    if (this.realtimeResponseInProgress) debug('response lock released');
    this.realtimeResponseInProgress = false;
  };

  VoiceCoach.prototype._drainPendingResponse = function () {
    if (!this.pendingResponseInstructions) return;
    var instructions = this.pendingResponseInstructions;
    this.pendingResponseInstructions = '';
    this._sendResponseCreate(instructions);
  };

  VoiceCoach.prototype.sendSummaryObservation = function () {
    return;
  };

  VoiceCoach.prototype._saveTranscript = function (role, text, eventType) {
    var self = this;
    this._postJson(this.config.apiUrl || '/student/api/summary_coach.php', {
      action: 'voice_tool',
      tool_name: 'save_voice_transcript_event',
      voice_session_id: this.voiceSessionId,
      lesson_id: this.config.lessonId,
      cohort_id: this.config.cohortId || 0,
      summary_id: this.config.summaryId || 0,
      context: this.config.context || 'player',
      summary_excerpt: truncate(plainTextFromEditor(this.editor), 2200),
      arguments: { role: role, transcript_text: text, event_type: eventType || 'transcript' }
    }).then(function (out) {
      if (out && out.readiness && self.textCoach) {
        self.textCoach.readiness = {
          ready_for_final_review: !!out.readiness.ready_for_final_review,
          missing: Array.isArray(out.readiness.missing) ? out.readiness.missing.slice(0) : [],
          minimum_interactions_met: !!out.readiness.minimum_interactions_met,
          unresolved_required_question: !!out.readiness.unresolved_required_question
        };
        if (typeof self.textCoach._renderState === 'function') self.textCoach._renderState();
      }
    }).catch(function () {});
  };

  VoiceCoach.prototype._applyCurrentTask = function (task) {
    if (this.textCoach && this.textCoach.coachingState) {
      var assignment = this.textCoach.coachingState.active_assignment || null;
      this.textCoach.coachingState.current_writing_task = assignment && !assignment.completed
        ? String(assignment.short_task_label || assignment.instruction_text || '')
        : String(task.task_text || '');
      this.textCoach.coachingState.awaiting_chat_reply = String(task.mode || '') === 'answer_chat';
      this.textCoach.coachingState.coach_state = String(task.coach_state || this.textCoach.coachingState.coach_state || '');
      this.textCoach.coachingState.current_section = String(task.section_title || task.section_id || '');
      if (typeof this.textCoach._renderWritingTask === 'function') this.textCoach._renderWritingTask();
    }
  };

  VoiceCoach.prototype._applyActiveAssignment = function (assignment) {
    if (this.textCoach && this.textCoach.coachingState) {
      this.textCoach.coachingState.active_assignment = assignment || null;
      this.textCoach.coachingState.current_writing_task = assignment && !assignment.completed
        ? String(assignment.short_task_label || assignment.instruction_text || '')
        : '';
      if (typeof this.textCoach._renderWritingTask === 'function') this.textCoach._renderWritingTask();
    }
  };

  VoiceCoach.prototype._insertStructureFromVoice = function (insertion) {
    if (!insertion || !insertion.html) return;
    if (this.textCoach && typeof this.textCoach._insertIntoSummary === 'function') {
      this.textCoach._insertIntoSummary(insertion, null, 0);
      this._postJson(this.config.apiUrl || '/student/api/summary_coach.php', {
        action: 'voice_tool',
        tool_name: 'mark_structure_inserted',
        voice_session_id: this.voiceSessionId,
        lesson_id: this.config.lessonId,
        cohort_id: this.config.cohortId || 0,
        summary_id: this.config.summaryId || 0,
        context: this.config.context || 'player',
        arguments: { summary_excerpt: truncate(plainTextFromEditor(this.editor), 1800) }
      }).catch(function () {});
    }
  };

  VoiceCoach.prototype._applyCoachState = function (coachState) {
    if (this.textCoach && this.textCoach.coachingState) {
      this.textCoach.coachingState.coach_state = String(coachState || '');
      if (typeof this.textCoach._renderWritingTask === 'function') this.textCoach._renderWritingTask();
    }
  };

  VoiceCoach.prototype._appendMainConversation = function (role, text) {
    text = String(text || '').trim();
    if (!text) return;
    if (this.textCoach && typeof this.textCoach._appendBubble === 'function') {
      this.textCoach._appendBubble({
        role: role,
        message_type: role === 'system' ? 'voice_system' : 'voice_transcript',
        message_body: text,
        message: text
      });
    }
  };

  VoiceCoach.prototype.toggleMute = function () {
    this.muted = !this.muted;
    if (this.localStream) {
      this.localStream.getAudioTracks().forEach(function (track) { track.enabled = !this.muted; }, this);
    }
    setIconButton(this.btnMute, this.muted ? 'Unmute microphone' : 'Mute microphone', this.muted ? 'M4.3 3 3 4.3 8.7 10H8c0 2.2 1.8 4 4 4 .7 0 1.3-.2 1.9-.5l1.5 1.5c-1 .7-2.2 1.1-3.4 1.1-2.8 0-5.3-2.1-5.3-5.1H5c0 3.4 2.7 6.2 6 6.7V21h2v-3.3c1.5-.2 2.9-.9 4-1.9l2.7 2.7 1.3-1.3L5.6 1.7 4.3 3zM15 11.2V5c0-1.7-1.3-3-3-3-1.1 0-2 .6-2.6 1.5L15 9.1v2.1zm4-.2h-1.7c0 .8-.2 1.5-.5 2.2l1.2 1.2c.6-1 .9-2.1 1-3.4z' : 'M12 14c1.7 0 3-1.3 3-3V5c0-1.7-1.3-3-3-3S9 3.3 9 5v6c0 1.7 1.3 3 3 3zm5.3-3c0 3-2.5 5.1-5.3 5.1S6.7 14 6.7 11H5c0 3.4 2.7 6.2 6 6.7V21h2v-3.3c3.3-.5 6-3.3 6-6.7h-1.7z');
    this._setStatus(this.muted ? 'Paused' : 'Listening');
  };

  VoiceCoach.prototype.end = function () {
    this.connected = false;
    this.realtimeResponseInProgress = false;
    this.pendingResponseInstructions = '';
    if (this.dc) { try { this.dc.close(); } catch (e) {} }
    if (this.pc) { try { this.pc.close(); } catch (e2) {} }
    if (this.localStream) this.localStream.getTracks().forEach(function (t) { t.stop(); });
    this.btnStart.disabled = false;
    this.btnMute.disabled = true;
    this.btnEnd.disabled = true;
    this._setVoiceModeActive(false);
    this._setStatus('Ended');
    this._saveTranscript('system', 'Voice coaching ended.', 'ended');
  };

  VoiceCoach.prototype._postJson = function (url, payload) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(payload || {})
    }).then(function (res) {
      return res.text().then(function (text) {
        var json = null;
        try { json = JSON.parse(text); } catch (e) { json = null; }
        if (!res.ok) throw new Error((json && json.error) || ('HTTP ' + res.status));
        if (!json) throw new Error('Invalid JSON response');
        return json;
      });
    });
  };

  global.IPCASummaryVoiceCoach = {
    attach: function (root, config, textCoach) {
      return new VoiceCoach(root, config, textCoach);
    }
  };
})(window);
