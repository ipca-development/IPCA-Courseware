(function () {
  'use strict';

  var ROW_COLS = 24;
  var ROW_COUNT = 4;
  var TILE_CHARS = ' ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.-/:+&()';

  var DEFAULT_MESSAGES = [
    {
      id: 'fallback-standard',
      message_type: 'standard',
      title: 'IPCA OPERATIONS',
      body: 'TRAINING CENTER OPEN CHECK DISPATCH BOARD MONITOR INSTRUCTOR CALLS',
      priority: 10,
      display_duration_seconds: 12,
      announce_audio_enabled: false,
      voice_text: '',
      voice: 'marin',
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

  function wrapWordsToRows(text, cols, maxRows) {
    var words = normalizeText(text).split(' ').filter(Boolean);
    var rows = [];
    var current = '';

    words.forEach(function (word) {
      var candidate = current === '' ? word : current + ' ' + word;
      if (candidate.length <= cols) {
        current = candidate;
        return;
      }
      if (current !== '') {
        rows.push(current);
        current = '';
      }
      if (word.length <= cols) {
        current = word;
        return;
      }
      var offset = 0;
      while (offset < word.length) {
        rows.push(word.slice(offset, offset + cols));
        offset += cols;
      }
    });

    if (current !== '') rows.push(current);
    if (rows.length === 0) rows.push('');

    while (rows.length < maxRows) rows.push('');
    return rows.slice(0, maxRows).map(function (row) {
      return fitText(row, cols);
    });
  }

  function hashMessages(messages) {
    return JSON.stringify((messages || []).map(function (m) {
      return [
        m.id, m.message_type, m.title, m.body, m.priority, m.status,
        m.audio_url, m.voice_text, m.voice, m.announce_audio_enabled
      ];
    }));
  }

  function randomBetween(min, max) {
    return min + Math.random() * (max - min);
  }

  function BoardAudio() {
    this.ctx = null;
    this.master = null;
    this.armed = false;
    this.lastClickAt = 0;
    this.windowStart = 0;
    this.clicksInWindow = 0;
    this.flapSamples = [];
    this.chimeSamples = [];
    this.pendingSettle = null;
  }

  BoardAudio.prototype.arm = function () {
    var self = this;
    var AudioContextCtor = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextCtor) {
      return Promise.resolve(false);
    }

    try {
      if (!this.ctx) {
        this.ctx = new AudioContextCtor();
        this.master = this.ctx.createGain();
        this.master.gain.value = 0.55;
        this.master.connect(this.ctx.destination);
        this.loadOptionalSamples();
      }
    } catch (e) {
      this.armed = false;
      return Promise.resolve(false);
    }

    function markRunning() {
      self.armed = !!(self.ctx && self.ctx.state === 'running');
      if (!self.armed) return false;
      try {
        var buffer = self.ctx.createBuffer(1, 1, self.ctx.sampleRate);
        var source = self.ctx.createBufferSource();
        source.buffer = buffer;
        source.connect(self.ctx.destination);
        source.start(0);
      } catch (e) {}
      return true;
    }

    if (this.ctx.state === 'running') {
      this.armed = true;
      return Promise.resolve(true);
    }

    if (this.ctx.state === 'suspended') {
      return this.ctx.resume().then(function () {
        return markRunning();
      }).catch(function () {
        self.armed = false;
        return false;
      });
    }

    this.armed = false;
    return Promise.resolve(false);
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
        if (!res.ok) throw new Error('missing');
        return res.arrayBuffer();
      })
      .then(function (buf) { return self.ctx.decodeAudioData(buf); })
      .then(function (decoded) { bucket.push(decoded); })
      .catch(function () {});
  };

  BoardAudio.prototype.canClick = function () {
    var now = performance.now();
    if (now - this.lastClickAt < randomBetween(90, 180)) return false;
    if (now - this.windowStart > 1000) {
      this.windowStart = now;
      this.clicksInWindow = 0;
    }
    if (this.clicksInWindow >= 5) return false;
    return true;
  };

  BoardAudio.prototype.syntheticClick = function (weight) {
    if (!this.armed || !this.ctx || !this.master) return;
    var now = this.ctx.currentTime;
    var noise = this.ctx.createBufferSource();
    var noiseBuffer = this.ctx.createBuffer(1, Math.floor(this.ctx.sampleRate * 0.022), this.ctx.sampleRate);
    var data = noiseBuffer.getChannelData(0);
    var i;
    for (i = 0; i < data.length; i += 1) {
      data[i] = (Math.random() * 2 - 1) * Math.pow(1 - i / data.length, 2.4);
    }
    var thump = this.ctx.createOscillator();
    var clickGain = this.ctx.createGain();
    var noiseGain = this.ctx.createGain();
    var filter = this.ctx.createBiquadFilter();
    var pitch = randomBetween(180, 320) * (weight || 1);

    thump.type = 'triangle';
    thump.frequency.setValueAtTime(pitch, now);
    thump.frequency.exponentialRampToValueAtTime(Math.max(80, pitch * 0.35), now + 0.04);
    clickGain.gain.setValueAtTime(randomBetween(0.035, 0.075) * (weight || 1), now);
    clickGain.gain.exponentialRampToValueAtTime(0.0001, now + 0.06);

    noise.buffer = noiseBuffer;
    filter.type = 'bandpass';
    filter.frequency.value = randomBetween(700, 1400);
    filter.Q.value = randomBetween(0.8, 2.2);
    noiseGain.gain.setValueAtTime(randomBetween(0.04, 0.09) * (weight || 1), now);
    noiseGain.gain.exponentialRampToValueAtTime(0.0001, now + 0.045);

    thump.connect(clickGain);
    clickGain.connect(this.master);
    noise.connect(filter);
    filter.connect(noiseGain);
    noiseGain.connect(this.master);
    thump.start(now);
    noise.start(now);
    thump.stop(now + 0.07);
    noise.stop(now + 0.05);
  };

  BoardAudio.prototype.flap = function (intensity) {
    if (!this.armed || !this.canClick()) return;
    this.lastClickAt = performance.now();
    this.clicksInWindow += 1;
    if (this.flapSamples.length > 0 && Math.random() > 0.5) {
      var buffer = this.flapSamples[Math.floor(Math.random() * this.flapSamples.length)];
      var source = this.ctx.createBufferSource();
      var gain = this.ctx.createGain();
      source.buffer = buffer;
      source.playbackRate.value = randomBetween(0.85, 1.05);
      gain.gain.value = randomBetween(0.08, 0.16) * (intensity || 1);
      source.connect(gain);
      gain.connect(this.master);
      source.start();
    } else {
      this.syntheticClick(intensity || 1);
    }
  };

  BoardAudio.prototype.scheduleSettle = function () {
    var self = this;
    if (this.pendingSettle) window.clearTimeout(this.pendingSettle);
    this.pendingSettle = window.setTimeout(function () {
      self.pendingSettle = null;
      if (!self.armed) return;
      self.syntheticClick(1.25);
      window.setTimeout(function () {
        if (self.armed) self.syntheticClick(1.4);
      }, randomBetween(120, 220));
    }, randomBetween(420, 780));
  };

  BoardAudio.prototype.chime = function () {
    var self = this;
    if (!this.armed || !this.ctx || !this.master) return Promise.resolve();
    if (this.chimeSamples.length > 0) {
      var source = this.ctx.createBufferSource();
      var gain = this.ctx.createGain();
      source.buffer = this.chimeSamples[0];
      gain.gain.value = 0.55;
      source.connect(gain);
      gain.connect(this.master);
      source.start();
      return new Promise(function (resolve) { window.setTimeout(resolve, 2100); });
    }
    var now = this.ctx.currentTime;
    [523.25, 659.25, 783.99].forEach(function (freq, idx) {
      var osc = self.ctx.createOscillator();
      var gain = self.ctx.createGain();
      osc.type = 'sine';
      osc.frequency.value = freq;
      gain.gain.setValueAtTime(0.0001, now + idx * 0.34);
      gain.gain.exponentialRampToValueAtTime(0.1, now + idx * 0.34 + 0.04);
      gain.gain.exponentialRampToValueAtTime(0.0001, now + idx * 0.34 + 1.1);
      osc.connect(gain);
      gain.connect(self.master);
      osc.start(now + idx * 0.34);
      osc.stop(now + idx * 0.34 + 1.15);
    });
    return new Promise(function (resolve) { window.setTimeout(resolve, 1700); });
  };

  BoardAudio.prototype.playOpenAiMp3 = function (url) {
    var self = this;
    if (!this.armed || !this.ctx || !this.master || !url) {
      return Promise.resolve(false);
    }

    return fetch(url, { credentials: 'same-origin', cache: 'force-cache' })
      .then(function (res) {
        if (!res.ok) throw new Error('missing audio');
        return res.arrayBuffer();
      })
      .then(function (buf) {
        return self.ctx.decodeAudioData(buf);
      })
      .then(function (decoded) {
        return new Promise(function (resolve) {
          var source = self.ctx.createBufferSource();
          var gain = self.ctx.createGain();
          source.buffer = decoded;
          gain.gain.value = 0.92;
          source.connect(gain);
          gain.connect(self.master);
          source.onended = function () { resolve(true); };
          source.start();
        });
      })
      .catch(function () {
        return false;
      });
  };

  BoardAudio.prototype.playMp3 = function (url) {
    var self = this;
    if (!url) return Promise.resolve(false);
    if (url.indexOf('/tv/api/announcement.php') === 0) {
      return this.playOpenAiMp3(url);
    }

    if (!this.armed || !this.ctx || !this.master) {
      return Promise.resolve(false);
    }

    return fetch(url, { credentials: 'same-origin', cache: 'force-cache' })
      .then(function (res) {
        if (!res.ok) throw new Error('missing audio');
        return res.arrayBuffer();
      })
      .then(function (buf) {
        return self.ctx.decodeAudioData(buf);
      })
      .then(function (decoded) {
        return new Promise(function (resolve) {
          var source = self.ctx.createBufferSource();
          var gain = self.ctx.createGain();
          source.buffer = decoded;
          gain.gain.value = 0.92;
          source.connect(gain);
          gain.connect(self.master);
          source.onended = function () { resolve(true); };
          source.start();
        });
      })
      .catch(function () {
        return false;
      });
  };

  BoardAudio.prototype.announcementUrl = function (message) {
    var direct = String(message.audio_url || '').trim();
    if (direct) return direct;

    var messageId = parseInt(message.id, 10);
    if (!messageId) return '';

    var hasScript = String(message.voice_text || '').trim() !== ''
      || String(message.title || '').trim() !== ''
      || String(message.body || '').trim() !== '';
    if (!hasScript) return '';

    var version = encodeURIComponent(String(message.updated_at || messageId));
    return '/tv/api/announcement.php?message_id=' + messageId + '&v=' + version;
  };

  BoardAudio.prototype.playAnnouncement = function (message) {
    var self = this;
    if (!this.armed) return Promise.resolve();
    return this.chime()
      .then(function () {
        return new Promise(function (resolve) { window.setTimeout(resolve, 650); });
      })
      .then(function () {
        var url = self.announcementUrl(message);
        if (!url) return;
        return self.playMp3(url);
      });
  };

  function FlipTile(char) {
    this.char = char || ' ';
    this.el = document.createElement('div');
    this.el.className = 'fb-tile' + (this.char === ' ' ? ' is-space' : '');
    this.el.innerHTML =
      '<div class="fb-clip fb-clip-top fb-display-top"><span class="fb-char"></span></div>' +
      '<div class="fb-clip fb-clip-bottom fb-display-bottom"><span class="fb-char"></span></div>' +
      '<div class="fb-flap-stack fb-flap-upper">' +
        '<div class="fb-flap-face fb-flap-front"><div class="fb-clip fb-clip-top"><span class="fb-char"></span></div></div>' +
        '<div class="fb-flap-face fb-flap-back"><div class="fb-clip fb-clip-top"><span class="fb-char"></span></div></div>' +
      '</div>' +
      '<div class="fb-flap-stack fb-flap-lower">' +
        '<div class="fb-flap-face fb-flap-front"><div class="fb-clip fb-clip-bottom"><span class="fb-char"></span></div></div>' +
        '<div class="fb-flap-face fb-flap-back"><div class="fb-clip fb-clip-bottom"><span class="fb-char"></span></div></div>' +
      '</div>';
    this.displayTop = this.el.querySelector('.fb-display-top .fb-char');
    this.displayBottom = this.el.querySelector('.fb-display-bottom .fb-char');
    this.upperFront = this.el.querySelector('.fb-flap-upper .fb-flap-front .fb-char');
    this.upperBack = this.el.querySelector('.fb-flap-upper .fb-flap-back .fb-char');
    this.lowerFront = this.el.querySelector('.fb-flap-lower .fb-flap-front .fb-char');
    this.lowerBack = this.el.querySelector('.fb-flap-lower .fb-flap-back .fb-char');
    this.flapUpper = this.el.querySelector('.fb-flap-upper');
    this.flapLower = this.el.querySelector('.fb-flap-lower');
    this.setChar(this.char);
  }

  FlipTile.prototype.setChar = function (char) {
    this.char = char || ' ';
    this.displayTop.textContent = this.char;
    this.displayBottom.textContent = this.char;
    this.upperFront.textContent = this.char;
    this.upperBack.textContent = this.char;
    this.lowerFront.textContent = this.char;
    this.lowerBack.textContent = this.char;
    this.el.classList.toggle('is-space', this.char === ' ');
  };

  FlipTile.prototype.resetFlapMotion = function () {
    this.flapUpper.style.animation = 'none';
    this.flapLower.style.animation = 'none';
    this.flapUpper.style.transform = '';
    this.flapLower.style.transform = '';
    void this.flapUpper.offsetWidth;
    this.flapUpper.style.animation = '';
    this.flapLower.style.animation = '';
  };

  FlipTile.prototype.flipOnce = function (nextChar, duration, options, audio) {
    var self = this;
    return new Promise(function (resolve) {
      if (nextChar === self.char) {
        resolve();
        return;
      }

      var fromChar = self.char;
      self.upperFront.textContent = fromChar;
      self.lowerFront.textContent = fromChar;
      self.upperBack.textContent = nextChar;
      self.lowerBack.textContent = nextChar;
      self.el.style.setProperty('--flip-duration', duration + 'ms');
      self.el.style.setProperty('--tile-h', self.el.offsetHeight + 'px');
      self.resetFlapMotion();

      var done = false;
      function finish() {
        if (done) return;
        done = true;
        self.el.classList.remove('is-flipping');
        self.resetFlapMotion();
        self.setChar(nextChar);
        self.el.classList.add('is-settling');
        window.setTimeout(function () {
          self.el.classList.remove('is-settling');
        }, 220);
        resolve();
      }

      self.el.classList.remove('is-settling');
      self.el.classList.add('is-flipping');

      if (audio) {
        audio.flap(options.urgent ? 1.3 : 1);
        window.setTimeout(function () {
          if (Math.random() > 0.5) audio.flap(options.urgent ? 1.15 : 0.9);
        }, duration * randomBetween(0.4, 0.55));
      }

      window.setTimeout(function () {
        self.displayBottom.textContent = nextChar;
      }, duration * 0.48);

      self.flapUpper.addEventListener('animationend', finish, { once: true });
      window.setTimeout(finish, duration + 120);
    });
  };

  FlipTile.prototype.flipTo = function (target, options, audio) {
    var self = this;
    options = options || {};
    target = target || ' ';
    if (target === this.char && !options.force) return Promise.resolve();

    var duration = Math.floor(randomBetween(480, 980) * (options.urgent ? 0.72 : 1));
    var extraFlaps = options.urgent
      ? Math.floor(randomBetween(1, 4))
      : (Math.random() > 0.86 ? Math.floor(randomBetween(1, 3)) : 0);
    var steps = [];
    var currentIndex = TILE_CHARS.indexOf(this.char);
    if (currentIndex < 0) currentIndex = 0;
    var i;
    for (i = 0; i < extraFlaps; i += 1) {
      steps.push(TILE_CHARS[(currentIndex + i + 1 + Math.floor(Math.random() * 7)) % TILE_CHARS.length]);
    }
    steps.push(target);

    return steps.reduce(function (chain, nextChar) {
      return chain.then(function () {
        var localDuration = Math.floor(duration * randomBetween(0.85, 1.08));
        return self.flipOnce(nextChar, localDuration, options, audio);
      });
    }, Promise.resolve());
  };

  function FlipLine(length) {
    this.length = length;
    this.el = document.createElement('div');
    this.el.className = 'fb-line is-body';
    this.tiles = [];
    for (var i = 0; i < length; i += 1) {
      var tile = new FlipTile(' ');
      this.tiles.push(tile);
      this.el.appendChild(tile.el);
    }
  }

  FlipLine.prototype.setText = function (text, options, audio, rowDelayBase) {
    var fitted = fitText(text, this.length);
    var jobs = [];
    this.tiles.forEach(function (tile, idx) {
      var char = fitted.charAt(idx);
      if (char === tile.char && !options.force) return;
      var delay = rowDelayBase + ((options && options.urgent)
        ? randomBetween(0, 150)
        : idx * randomBetween(7, 22) + randomBetween(0, 260));
      jobs.push(new Promise(function (resolve) {
        window.setTimeout(function () {
          tile.flipTo(char, options, audio).then(resolve);
        }, delay);
      }));
    });
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
    this.audio = new BoardAudio();
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

  FlipBoard.prototype.updateAudioStatus = function () {
    if (!this.audioState) return;
    if (!window.AudioContext && !window.webkitAudioContext) {
      this.audioState.textContent = 'UNSUPPORTED';
      return;
    }
    if (this.audio.armed) {
      this.audioState.textContent = 'LIVE';
      return;
    }
    if (this.audio.ctx && this.audio.ctx.state === 'suspended') {
      this.audioState.textContent = 'TAP TO ENABLE';
      return;
    }
    if (this.audio.ctx) {
      this.audioState.textContent = this.audio.ctx.state.toUpperCase();
      return;
    }
    this.audioState.textContent = 'STARTING';
  };

  FlipBoard.prototype.bindAudio = function () {
    var self = this;
    var tryArm = function () {
      return self.audio.arm().then(function (armed) {
        self.updateAudioStatus();
        return armed;
      });
    };

    this.updateAudioStatus();

    if (this.autoAudio) {
      tryArm();
      var tries = 0;
      var retry = window.setInterval(function () {
        tryArm().then(function (armed) {
          tries += 1;
          if (armed || tries >= 40) window.clearInterval(retry);
        });
      }, 500);
    }

    var unlock = function () { tryArm(); };
    document.addEventListener('pointerdown', unlock, { passive: true });
    document.addEventListener('keydown', unlock, { once: true });
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) tryArm();
    });
    this.root.addEventListener('click', unlock, { passive: true });
  };

  FlipBoard.prototype.tickClock = function () {
    this.clock.textContent = new Date().toLocaleTimeString([], { hour12: false });
  };

  FlipBoard.prototype.buildMessageBoard = function () {
    var self = this;
    for (var i = 0; i < ROW_COUNT; i += 1) {
      var line = new FlipLine(ROW_COLS);
      self.lines.push(line);
      self.messageBoard.appendChild(line.el);
    }
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
      var title = new FlipLine(24);
      var status = new FlipLine(10);
      row.appendChild(label);
      row.appendChild(title.el);
      row.appendChild(status.el);
      self.scheduleRows.push({ title: title, status: status });
      self.scheduleBoard.appendChild(row);
    }
  };

  FlipBoard.prototype.prefetchAnnouncements = function (messages) {
    var self = this;
    (messages || []).forEach(function (message) {
      if (!message || !message.announce_audio_enabled) return;
      var url = self.audio.announcementUrl(message);
      if (!url || url.indexOf('/tv/api/announcement.php') !== 0) return;
      fetch(url + '&prefetch=1', { credentials: 'same-origin' }).catch(function () {});
    });
  };

  FlipBoard.prototype.poll = function () {
    var self = this;
    fetch(this.apiUrl + '?screen_key=' + encodeURIComponent(this.screenKey), {
      headers: { Accept: 'application/json' },
      cache: 'no-store'
    })
      .then(function (res) {
        if (!res.ok) throw new Error('API unavailable');
        return res.json();
      })
      .then(function (payload) {
        var incoming = Array.isArray(payload.messages) && payload.messages.length
          ? payload.messages
          : DEFAULT_MESSAGES;
        var nextHash = hashMessages(incoming);
        if (nextHash === self.messageHash) return;
        self.messageHash = nextHash;
        self.messages = incoming;
        self.prefetchAnnouncements(incoming);
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
    var key = [message.id, message.message_type, message.title, message.body, message.priority].join('|');
    if (!force && key === this.lastRenderedKey) return;

    this.rendering = true;
    this.lastRenderedKey = key;
    window.clearTimeout(this.rotateTimer);

    var urgent = String(message.message_type || '').toLowerCase() === 'urgent'
      || parseInt(message.priority || 0, 10) >= 90;
    var schedule = String(message.message_type || '').toLowerCase() === 'schedule'
      || this.mode === 'schedule';

    this.root.classList.toggle('is-urgent', urgent);
    this.statusLight.classList.toggle('is-urgent', urgent);
    this.statusLabel.textContent = urgent ? 'URGENT OVERRIDE' : (schedule ? 'SCHEDULE MODE' : 'STANDARD OPS');

    var announcePromise = Promise.resolve();
    if (message.announce_audio_enabled && (message.audio_url || message.voice_text || urgent)) {
      announcePromise = new Promise(function (resolve) {
        window.setTimeout(resolve, urgent ? 200 : 500);
      }).then(function () {
        return self.audio.playAnnouncement(message);
      });
    }

    announcePromise
      .then(function () {
        if (schedule) return self.renderSchedule(message, urgent);
        return self.renderMessage(message, urgent);
      })
      .then(function () {
        self.audio.scheduleSettle();
        self.rendering = false;
        if (self.pendingRender) {
          self.pendingRender = false;
          self.renderCurrent(true);
          return;
        }
        var duration = clamp(parseInt(message.display_duration_seconds || 12, 10), 5, 120) * 1000;
        self.rotateTimer = window.setTimeout(function () {
          self.activeIndex = (self.activeIndex + 1) % Math.max(1, self.messages.length);
          self.renderCurrent(false);
        }, duration);
      });
  };

  FlipBoard.prototype.renderMessage = function (message, urgent) {
    this.messageBoard.hidden = false;
    this.scheduleBoard.hidden = true;

    var combined = normalizeText((message.title || '') + ' ' + (message.body || '').replace(/\r?\n/g, ' '));
    var rows = wrapWordsToRows(combined, ROW_COLS, ROW_COUNT);
    var options = { urgent: urgent, force: urgent };
    var self = this;

    return Promise.all(this.lines.map(function (line, idx) {
      var rowDelay = idx * randomBetween(80, 140);
      return line.setText(rows[idx] || '', options, self.audio, rowDelay);
    }));
  };

  FlipBoard.prototype.renderSchedule = function (message, urgent) {
    this.ensureScheduleBoard();
    this.messageBoard.hidden = true;
    this.scheduleBoard.hidden = false;
    var rows = this.parseScheduleRows(message);
    var options = { urgent: urgent, force: urgent };
    var self = this;

    return Promise.all(this.scheduleRows.map(function (row, idx) {
      var item = rows[idx] || { title: '', status: '' };
      var rowDelay = idx * randomBetween(80, 140);
      return Promise.all([
        row.title.setText(item.title, options, self.audio, rowDelay),
        row.status.setText(item.status, options, self.audio, rowDelay + 40)
      ]);
    }));
  };

  FlipBoard.prototype.parseScheduleRows = function (message) {
    var body = String(message.body || '');
    var rows = [];
    body.split(/\r?\n/).forEach(function (line) {
      var clean = normalizeText(line);
      if (!clean) return;
      var parts = clean.split('|');
      if (parts.length >= 2) {
        rows.push({ title: parts[0].trim(), status: parts.slice(1).join(' ').trim() });
      } else {
        var wrapped = wrapWordsToRows(clean, 24, 1)[0].trim();
        rows.push({ title: wrapped, status: '' });
      }
    });
    if (rows.length === 0) {
      rows.push({ title: normalizeText(message.title || 'TRAINING OPS'), status: 'ON TIME' });
    }
    return rows.slice(0, 8);
  };

  document.addEventListener('DOMContentLoaded', function () {
    var root = document.getElementById('ipcaFlipBoardApp');
    if (!root) return;
    new FlipBoard(root).init();
  });
}());
