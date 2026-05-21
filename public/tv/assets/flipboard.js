(function () {
  'use strict';

  var TILE_CHARS = ' ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.-/:+&()';
  var DEFAULT_MESSAGES = [
    {
      id: 'fallback-standard',
      message_type: 'standard',
      title: 'IPCA OPERATIONS',
      body: 'TRAINING CENTER OPEN  CHECK DISPATCH BOARD',
      priority: 10,
      display_duration_seconds: 10,
      announce_audio_enabled: false,
      voice_text: '',
      audio_url: ''
    }
  ];

  function clamp(n, min, max) {
    return Math.max(min, Math.min(max, n));
  }

  function normalizeText(value) {
    return String(value || '')
      .toUpperCase()
      .replace(/[^\x20-\x7E]/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function fitText(value, length) {
    var text = normalizeText(value);
    if (text.length > length) return text.slice(0, length);
    return text.padEnd(length, ' ');
  }

  function hashMessages(messages) {
    return JSON.stringify((messages || []).map(function (m) {
      return [
        m.id,
        m.message_type,
        m.title,
        m.body,
        m.priority,
        m.status,
        m.audio_url,
        m.voice_text,
        m.announce_audio_enabled
      ];
    }));
  }

  function randomBetween(min, max) {
    return min + Math.random() * (max - min);
  }

  function BoardAudio(root) {
    this.root = root;
    this.ctx = null;
    this.master = null;
    this.armed = false;
    this.lastClickAt = 0;
    this.flapSamples = [];
    this.chimeSamples = [];
  }

  BoardAudio.prototype.arm = function () {
    var AudioContextCtor = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextCtor) return false;
    if (!this.ctx) {
      this.ctx = new AudioContextCtor();
      this.master = this.ctx.createGain();
      this.master.gain.value = 0.72;
      this.master.connect(this.ctx.destination);
      this.loadOptionalSamples();
    }
    if (this.ctx.state === 'suspended') {
      this.ctx.resume();
    }
    this.armed = this.ctx.state === 'running';
    return this.armed;
  };

  BoardAudio.prototype.loadOptionalSamples = function () {
    var self = this;
    ['click-01.mp3', 'click-02.mp3', 'rattle-01.mp3', 'settle-01.mp3'].forEach(function (file) {
      self.fetchSample('/tv/assets/audio/flaps/' + file, self.flapSamples);
    });
    ['airport-chime.mp3'].forEach(function (file) {
      self.fetchSample('/tv/assets/audio/chimes/' + file, self.chimeSamples);
    });
  };

  BoardAudio.prototype.fetchSample = function (url, bucket) {
    var self = this;
    if (!this.ctx) return;
    fetch(url, { cache: 'force-cache' })
      .then(function (res) {
        if (!res.ok) throw new Error('sample unavailable');
        return res.arrayBuffer();
      })
      .then(function (buf) { return self.ctx.decodeAudioData(buf); })
      .then(function (decoded) { bucket.push(decoded); })
      .catch(function () {});
  };

  BoardAudio.prototype.playBuffer = function (buffer, gainValue, rate) {
    if (!this.armed || !this.ctx || !this.master || !buffer) return;
    var source = this.ctx.createBufferSource();
    var gain = this.ctx.createGain();
    source.buffer = buffer;
    source.playbackRate.value = rate || 1;
    gain.gain.value = gainValue;
    source.connect(gain);
    gain.connect(this.master);
    source.start();
  };

  BoardAudio.prototype.syntheticClick = function (weight) {
    if (!this.armed || !this.ctx || !this.master) return;
    var now = this.ctx.currentTime;
    var osc = this.ctx.createOscillator();
    var noise = this.ctx.createBufferSource();
    var noiseBuffer = this.ctx.createBuffer(1, Math.floor(this.ctx.sampleRate * 0.028), this.ctx.sampleRate);
    var data = noiseBuffer.getChannelData(0);
    var i;
    for (i = 0; i < data.length; i += 1) {
      data[i] = (Math.random() * 2 - 1) * Math.pow(1 - i / data.length, 2.2);
    }
    var clickGain = this.ctx.createGain();
    var noiseGain = this.ctx.createGain();
    var filter = this.ctx.createBiquadFilter();
    var pitch = randomBetween(280, 520) * (weight || 1);

    osc.type = 'square';
    osc.frequency.setValueAtTime(pitch, now);
    osc.frequency.exponentialRampToValueAtTime(Math.max(90, pitch * 0.25), now + 0.028);
    clickGain.gain.setValueAtTime(randomBetween(0.06, 0.14) * (weight || 1), now);
    clickGain.gain.exponentialRampToValueAtTime(0.0001, now + 0.05);

    noise.buffer = noiseBuffer;
    filter.type = 'highpass';
    filter.frequency.value = randomBetween(1200, 3200);
    noiseGain.gain.setValueAtTime(randomBetween(0.08, 0.18) * (weight || 1), now);
    noiseGain.gain.exponentialRampToValueAtTime(0.0001, now + 0.035);

    osc.connect(clickGain);
    clickGain.connect(this.master);
    noise.connect(filter);
    filter.connect(noiseGain);
    noiseGain.connect(this.master);
    osc.start(now);
    noise.start(now);
    osc.stop(now + 0.06);
    noise.stop(now + 0.04);
  };

  BoardAudio.prototype.flap = function (intensity) {
    if (!this.armed) return;
    var nowMs = performance.now();
    if (nowMs - this.lastClickAt < 10) return;
    this.lastClickAt = nowMs;
    if (this.flapSamples.length > 0 && Math.random() > 0.4) {
      this.playBuffer(
        this.flapSamples[Math.floor(Math.random() * this.flapSamples.length)],
        randomBetween(0.12, 0.28) * (intensity || 1),
        randomBetween(0.9, 1.18)
      );
    } else {
      this.syntheticClick(intensity || 1);
    }
  };

  BoardAudio.prototype.settle = function () {
    if (!this.armed) return;
    var self = this;
    [0, 28, 58].forEach(function (delay, idx) {
      window.setTimeout(function () {
        self.syntheticClick(idx === 2 ? 1.35 : 1.1);
      }, delay);
    });
  };

  BoardAudio.prototype.chime = function () {
    var self = this;
    if (!this.armed || !this.ctx || !this.master) return Promise.resolve();
    if (this.chimeSamples.length > 0) {
      this.playBuffer(this.chimeSamples[0], 0.72, 1);
      return new Promise(function (resolve) { window.setTimeout(resolve, 2100); });
    }
    var now = this.ctx.currentTime;
    [523.25, 659.25, 783.99].forEach(function (freq, idx) {
      var osc = self.ctx.createOscillator();
      var gain = self.ctx.createGain();
      osc.type = 'sine';
      osc.frequency.value = freq;
      gain.gain.setValueAtTime(0.0001, now + idx * 0.34);
      gain.gain.exponentialRampToValueAtTime(0.13, now + idx * 0.34 + 0.04);
      gain.gain.exponentialRampToValueAtTime(0.0001, now + idx * 0.34 + 1.15);
      osc.connect(gain);
      gain.connect(self.master);
      osc.start(now + idx * 0.34);
      osc.stop(now + idx * 0.34 + 1.2);
    });
    return new Promise(function (resolve) { window.setTimeout(resolve, 1700); });
  };

  BoardAudio.prototype.playAnnouncement = function (message) {
    var self = this;
    if (!this.armed) return Promise.resolve();
    return this.chime()
      .then(function () {
        return new Promise(function (resolve) { window.setTimeout(resolve, 650); });
      })
      .then(function () {
        var url = String(message.audio_url || '').trim();
        if (!url) return;
        return new Promise(function (resolve) {
          var audio = new Audio(url);
          audio.preload = 'auto';
          audio.onended = resolve;
          audio.onerror = resolve;
          audio.volume = 0.92;
          audio.play().catch(resolve);
        });
      });
  };

  function FlipTile(char, lineClass) {
    this.char = char || ' ';
    this.el = document.createElement('div');
    this.el.className = 'fb-tile' + (this.char === ' ' ? ' is-space' : '');
    this.el.innerHTML =
      '<div class="fb-half fb-half-top"><span class="fb-char"></span></div>' +
      '<div class="fb-half fb-half-bottom"><span class="fb-char"></span></div>' +
      '<div class="fb-flip-top">' +
        '<div class="fb-flip-face fb-flip-front"><span class="fb-char"></span></div>' +
        '<div class="fb-flip-face fb-flip-back"><span class="fb-char"></span></div>' +
      '</div>';
    this.halfTop = this.el.querySelector('.fb-half-top .fb-char');
    this.halfBottom = this.el.querySelector('.fb-half-bottom .fb-char');
    this.flipFront = this.el.querySelector('.fb-flip-front .fb-char');
    this.flipBack = this.el.querySelector('.fb-flip-back .fb-char');
    this.flipTop = this.el.querySelector('.fb-flip-top');
    this.setChar(this.char);
    if (lineClass) this.el.classList.add(lineClass);
  }

  FlipTile.prototype.setChar = function (char) {
    this.char = char || ' ';
    this.halfTop.textContent = this.char;
    this.halfBottom.textContent = this.char;
    this.flipFront.textContent = this.char;
    this.flipBack.textContent = this.char;
    this.el.classList.toggle('is-space', this.char === ' ');
  };

  FlipTile.prototype.flipOnce = function (nextChar, duration, options, audio) {
    var self = this;
    return new Promise(function (resolve) {
      self.flipFront.textContent = self.char;
      self.flipBack.textContent = nextChar;
      self.el.style.setProperty('--flip-ms', duration + 'ms');
      self.el.style.setProperty('--tile-h', self.el.offsetHeight + 'px');

      var done = false;
      function finish() {
        if (done) return;
        done = true;
        self.el.classList.remove('is-flipping');
        self.flipTop.style.animation = 'none';
        void self.flipTop.offsetWidth;
        self.flipTop.style.animation = '';
        self.setChar(nextChar);
        resolve();
      }

      self.el.classList.remove('is-settling');
      self.el.classList.add('is-flipping');
      if (audio) {
        audio.flap(options.urgent ? 1.45 : 1.15);
        window.setTimeout(function () {
          if (Math.random() > 0.35) audio.flap(options.urgent ? 1.2 : 0.95);
        }, duration * 0.45);
      }

      self.flipTop.addEventListener('animationend', finish, { once: true });
      window.setTimeout(finish, duration + 50);
    });
  };

  FlipTile.prototype.flipTo = function (target, options, audio) {
    var self = this;
    options = options || {};
    target = target || ' ';
    if (target === this.char && !options.force) return Promise.resolve();

    var baseDuration = Math.floor(randomBetween(105, 185) * (options.urgent ? 0.82 : 1));
    var extraFlaps = options.urgent
      ? Math.floor(randomBetween(1, 3))
      : (Math.random() > 0.88 ? Math.floor(randomBetween(1, 2)) : 0);
    var steps = [];
    var currentIndex = TILE_CHARS.indexOf(this.char);
    if (currentIndex < 0) currentIndex = 0;

    for (var i = 0; i < extraFlaps; i += 1) {
      steps.push(TILE_CHARS[(currentIndex + i + 1 + Math.floor(Math.random() * 5)) % TILE_CHARS.length]);
    }
    steps.push(target);

    return steps.reduce(function (chain, nextChar, idx) {
      return chain.then(function () {
        var dur = Math.floor(baseDuration * randomBetween(0.88, 1.05));
        return self.flipOnce(nextChar, dur, options, audio).then(function () {
          if (audio && idx === steps.length - 1) audio.settle();
        });
      });
    }, Promise.resolve());
  };

  function FlipLine(length, className) {
    this.length = length;
    this.el = document.createElement('div');
    this.el.className = 'fb-line ' + (className || '');
    this.tiles = [];
    for (var i = 0; i < length; i += 1) {
      var tile = new FlipTile(' ');
      this.tiles.push(tile);
      this.el.appendChild(tile.el);
    }
  }

  FlipLine.prototype.setText = function (text, options, audio) {
    var fitted = fitText(text, this.length);
    var jobs = [];
    for (var i = 0; i < this.tiles.length; i += 1) {
      (function (tile, char, idx) {
        var delay = (options && options.urgent)
          ? randomBetween(0, 120)
          : idx * randomBetween(6, 16) + randomBetween(0, 220);
        jobs.push(new Promise(function (resolve) {
          window.setTimeout(function () {
            tile.flipTo(char, options, audio).then(resolve);
          }, delay);
        }));
      }(this.tiles[i], fitted.charAt(i), i));
    }
    return Promise.all(jobs);
  };

  function FlipBoard(root) {
    this.root = root;
    this.messageBoard = root.querySelector('#fbMessageBoard');
    this.scheduleBoard = root.querySelector('#fbScheduleBoard');
    this.statusLabel = root.querySelector('#fbStatusLabel');
    this.statusLight = root.querySelector('#fbStatusLight');
    this.clock = root.querySelector('#fbClock');
    this.audioState = root.querySelector('#fbAudioState');
    this.screenKey = root.getAttribute('data-screen-key') || 'main';
    this.mode = root.getAttribute('data-initial-mode') || 'standard';
    this.apiUrl = root.getAttribute('data-api-url') || '/tv/api/messages.php';
    this.pollMs = clamp(parseInt(root.getAttribute('data-poll-ms') || '7000', 10), 5000, 10000);
    this.autoAudio = root.getAttribute('data-auto-audio') === '1';
    this.audio = new BoardAudio(root);
    this.lines = [];
    this.scheduleRows = [];
    this.scheduleBuilt = false;
    this.messages = DEFAULT_MESSAGES.slice();
    this.messageHash = '';
    this.activeIndex = 0;
    this.rendering = false;
    this.pendingRender = false;
    this.lastRenderedKey = '';
    this.rotateTimer = null;
  }

  FlipBoard.prototype.init = function () {
    this.buildMessageBoard();
    this.bindAudio();
    this.tickClock();
    this.renderCurrent(true);
    this.poll();
    window.setInterval(this.poll.bind(this), this.pollMs);
    window.setInterval(this.tickClock.bind(this), 1000);
  };

  FlipBoard.prototype.bindAudio = function () {
    var self = this;
    var markLive = function () {
      if (self.audio.arm()) {
        self.audioState.textContent = 'LIVE';
      }
    };

    if (this.autoAudio) {
      markLive();
      var tries = 0;
      var retry = window.setInterval(function () {
        markLive();
        tries += 1;
        if (self.audio.armed || tries > 24) window.clearInterval(retry);
      }, 400);
    }

    document.addEventListener('pointerdown', markLive, { once: true });
    document.addEventListener('keydown', markLive, { once: true });
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) markLive();
    });
  };

  FlipBoard.prototype.tickClock = function () {
    var now = new Date();
    this.clock.textContent = now.toLocaleTimeString([], { hour12: false });
  };

  FlipBoard.prototype.buildMessageBoard = function () {
    var specs = [
      { len: 24, cls: 'is-body' },
      { len: 24, cls: 'is-body' },
      { len: 24, cls: 'is-body' },
      { len: 24, cls: 'is-body' }
    ];
    var self = this;
    specs.forEach(function (spec) {
      var line = new FlipLine(spec.len, spec.cls);
      self.lines.push(line);
      self.messageBoard.appendChild(line.el);
    });
  };

  FlipBoard.prototype.ensureScheduleBoard = function () {
    if (this.scheduleBuilt) return;
    this.scheduleBuilt = true;
    var self = this;
    for (var i = 0; i < 8; i += 1) {
      var row = document.createElement('div');
      row.className = 'fb-schedule-row';
      var label = document.createElement('div');
      label.className = 'fb-schedule-label';
      label.textContent = String(i + 1).padStart(2, '0');
      var title = new FlipLine(24, 'is-schedule-title');
      var status = new FlipLine(10, 'is-schedule-status');
      row.appendChild(label);
      row.appendChild(title.el);
      row.appendChild(status.el);
      self.scheduleRows.push({ title: title, status: status });
      self.scheduleBoard.appendChild(row);
    }
  };

  FlipBoard.prototype.poll = function () {
    var self = this;
    var url = this.apiUrl + '?screen_key=' + encodeURIComponent(this.screenKey);
    fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' })
      .then(function (res) {
        if (!res.ok) throw new Error('API unavailable');
        return res.json();
      })
      .then(function (payload) {
        var incoming = Array.isArray(payload.messages) && payload.messages.length ? payload.messages : DEFAULT_MESSAGES;
        var nextHash = hashMessages(incoming);
        if (nextHash === self.messageHash) return;
        self.messageHash = nextHash;
        self.messages = incoming;
        self.activeIndex = 0;
        self.renderCurrent(false);
      })
      .catch(function () {
        if (!self.messageHash) {
          self.messages = DEFAULT_MESSAGES.slice();
          self.renderCurrent(false);
        }
      });
  };

  FlipBoard.prototype.renderCurrent = function (force) {
    var self = this;
    if (this.rendering) {
      this.pendingRender = true;
      return;
    }
    var message = this.messages[this.activeIndex] || DEFAULT_MESSAGES[0];
    var key = [message.id, message.message_type, message.title, message.body, message.priority, message.audio_url].join('|');
    if (!force && key === this.lastRenderedKey) return;
    this.rendering = true;
    this.lastRenderedKey = key;
    window.clearTimeout(this.rotateTimer);

    var urgent = String(message.message_type || '').toLowerCase() === 'urgent' || parseInt(message.priority || 0, 10) >= 90;
    var schedule = String(message.message_type || '').toLowerCase() === 'schedule' || this.mode === 'schedule';
    this.root.classList.toggle('is-urgent', urgent);
    this.statusLight.classList.toggle('is-urgent', urgent);
    this.statusLabel.textContent = urgent ? 'URGENT OVERRIDE' : (schedule ? 'SCHEDULE MODE' : 'STANDARD OPS');

    var announcePromise = Promise.resolve();
    if (message.announce_audio_enabled && (message.audio_url || message.voice_text || urgent)) {
      announcePromise = new Promise(function (resolve) { window.setTimeout(resolve, urgent ? 120 : 400); })
        .then(function () { return self.audio.playAnnouncement(message); });
    }

    announcePromise
      .then(function () {
        if (schedule) return self.renderSchedule(message, urgent);
        return self.renderMessage(message, urgent);
      })
      .then(function () {
        self.rendering = false;
        if (self.pendingRender) {
          self.pendingRender = false;
          self.renderCurrent(true);
          return;
        }
        var duration = clamp(parseInt(message.display_duration_seconds || 10, 10), 5, 120) * 1000;
        self.rotateTimer = window.setTimeout(function () {
          self.activeIndex = (self.activeIndex + 1) % Math.max(1, self.messages.length);
          self.renderCurrent(false);
        }, duration);
      });
  };

  FlipBoard.prototype.renderMessage = function (message, urgent) {
    this.messageBoard.hidden = false;
    this.scheduleBoard.hidden = true;
    var combined = normalizeText((message.title || '') + '  ' + (message.body || '').replace(/\r?\n/g, '  '));
    var segments = [
      combined.slice(0, 24),
      combined.slice(24, 48),
      combined.slice(48, 72),
      combined.slice(72, 96)
    ];
    return Promise.all(this.lines.map(function (line, idx) {
      return line.setText(segments[idx] || '', { urgent: urgent, force: urgent }, this.audio);
    }, this));
  };

  FlipBoard.prototype.renderSchedule = function (message, urgent) {
    this.ensureScheduleBoard();
    this.messageBoard.hidden = true;
    this.scheduleBoard.hidden = false;
    var rows = this.parseScheduleRows(message);
    return Promise.all(this.scheduleRows.map(function (row, idx) {
      var item = rows[idx] || { title: '', status: '' };
      return Promise.all([
        row.title.setText(item.title, { urgent: urgent }, this.audio),
        row.status.setText(item.status, { urgent: urgent }, this.audio)
      ]);
    }, this));
  };

  FlipBoard.prototype.parseScheduleRows = function (message) {
    var body = String(message.body || '');
    var rows = [];
    body.split(/\r?\n/).forEach(function (line) {
      var clean = normalizeText(line);
      if (!clean) return;
      var parts = clean.split('|');
      if (parts.length >= 2) {
        rows.push({ title: parts[0], status: parts.slice(1).join(' ') });
      } else {
        rows.push({ title: clean.slice(0, 24), status: clean.slice(24, 34) });
      }
    });
    if (rows.length === 0) {
      rows.push({ title: message.title || 'TRAINING OPS', status: 'ON TIME' });
    }
    return rows.slice(0, 8);
  };

  document.addEventListener('DOMContentLoaded', function () {
    var root = document.getElementById('ipcaFlipBoardApp');
    if (!root) return;
    new FlipBoard(root).init();
  });
}());
