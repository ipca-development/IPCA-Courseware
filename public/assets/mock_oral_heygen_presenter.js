/**
 * LiveAvatar presenter — LITE mode lip-sync via repeatAudio + separate TTS audio.
 * OpenAI (server) decides maya_text; we drive lip-sync with PCM audio chunks.
 */
(function (global) {
  'use strict';

  var session = null;
  var sdk = null;
  var ready = false;
  var videoEl = null;
  var initPromise = null;
  var ttsUrlFn = null;

  function loadSdk() {
    if (sdk) return Promise.resolve(sdk);
    if (global.LiveAvatarSdk) {
      var mod = global.LiveAvatarSdk;
      sdk = {
        LiveAvatarSession: mod.LiveAvatarSession,
        SessionEvent: mod.SessionEvent,
        AgentEventsEnum: mod.AgentEventsEnum,
        CommandEventsEnum: mod.CommandEventsEnum,
      };
      return Promise.resolve(sdk);
    }
    return import('https://esm.sh/@heygen/liveavatar-web-sdk@0.0.18').then(function (mod) {
      sdk = {
        LiveAvatarSession: mod.LiveAvatarSession,
        SessionEvent: mod.SessionEvent,
        AgentEventsEnum: mod.AgentEventsEnum,
        CommandEventsEnum: mod.CommandEventsEnum,
      };
      return sdk;
    });
  }

  function pcmDurationMs(pcm) {
    // 24 kHz mono 16-bit PCM: 960 bytes = 20 ms
    return Math.max(2500, Math.ceil(String(pcm || '').length / 48) + 800);
  }

  function attachVideoOnly() {
    if (!videoEl || !session) return;
    var track = session._remoteVideoTrack;
    if (track && typeof track.attach === 'function') {
      track.attach(videoEl);
    } else if (typeof session.attach === 'function') {
      session.attach(videoEl);
      videoEl.muted = true;
    }
    videoEl.hidden = false;
    videoEl.playsInline = true;
    var play = videoEl.play();
    if (play && typeof play.catch === 'function') {
      play.catch(function () {});
    }
  }

  function mp3BlobToPcm24k(blob) {
    var decodeCtx = new (window.AudioContext || window.webkitAudioContext)();
    return blob.arrayBuffer().then(function (buf) {
      return decodeCtx.decodeAudioData(buf.slice(0));
    }).then(function (buffer) {
      var frames = Math.max(1, Math.ceil(buffer.duration * 24000));
      var offline = new OfflineAudioContext(1, frames, 24000);
      var src = offline.createBufferSource();
      src.buffer = buffer;
      src.connect(offline.destination);
      src.start(0);
      return offline.startRendering();
    }).then(function (rendered) {
      var samples = rendered.getChannelData(0);
      var parts = [];
      for (var i = 0; i < samples.length; i++) {
        var s = Math.max(-1, Math.min(1, samples[i]));
        var v = s < 0 ? s * 0x8000 : s * 0x7fff;
        var int16 = v | 0;
        parts.push(String.fromCharCode(int16 & 0xff, (int16 >> 8) & 0xff));
      }
      return parts.join('');
    });
  }

  function fetchTtsPcm24k(text) {
    if (!ttsUrlFn) {
      return Promise.reject(new Error('TTS URL not configured.'));
    }
    return fetch(ttsUrlFn(text), { credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) throw new Error('TTS fetch failed');
        return r.blob();
      })
      .then(function (blob) {
        if (!blob || blob.size < 32) throw new Error('Empty TTS audio');
        return mp3BlobToPcm24k(blob);
      });
  }

  global.MoeHeyGenPresenter = {
    isReady: function () {
      return ready;
    },

    reset: function () {
      ready = false;
      initPromise = null;
      var stopper = session && session.stop ? session.stop() : Promise.resolve();
      session = null;
      if (videoEl) {
        try {
          videoEl.srcObject = null;
        } catch (e) {}
      }
      return Promise.resolve(stopper).catch(function () {});
    },

    unlockPlayback: function () {
      if (!videoEl) return;
      videoEl.muted = true;
      var play = videoEl.play();
      if (play && typeof play.catch === 'function') {
        play.catch(function () {});
      }
      try {
        var Ctx = window.AudioContext || window.webkitAudioContext;
        if (Ctx) {
          var ctx = new Ctx();
          if (ctx.state === 'suspended') ctx.resume().catch(function () {});
        }
      } catch (e) {}
    },

    init: function (opts) {
      opts = opts || {};
      if (initPromise) return initPromise;

      if (!opts.token) {
        return Promise.reject(new Error('LiveAvatar session token is required.'));
      }

      videoEl = opts.videoEl || null;
      ttsUrlFn = typeof opts.ttsUrl === 'function' ? opts.ttsUrl : null;

      initPromise = loadSdk().then(function (loaded) {
        var LiveAvatarSession = loaded.LiveAvatarSession;
        var SessionEvent = loaded.SessionEvent;

        session = new LiveAvatarSession(opts.token, {
          voiceChat: false,
        });

        var streamReady = new Promise(function (resolve, reject) {
          var timeout = setTimeout(function () {
            reject(new Error('LiveAvatar stream timed out.'));
          }, 45000);

          session.on(SessionEvent.SESSION_STREAM_READY, function () {
            clearTimeout(timeout);
            attachVideoOnly();
            resolve(true);
          });

          session.on(SessionEvent.SESSION_DISCONNECTED, function () {
            ready = false;
          });
        });

        return session.start().then(function () {
          return streamReady;
        }).then(function () {
          ready = true;
          return true;
        });
      }).catch(function (err) {
        initPromise = null;
        ready = false;
        throw err;
      });

      return initPromise;
    },

    /**
     * LITE mode: text repeat is blocked on WebSocket. Send PCM via repeatAudio for lip-sync.
     * Audio is played separately via OpenAI TTS in mock_oral_session.js.
     */
    speakLipSync: function (text) {
      text = String(text || '').trim();
      if (!text) return Promise.resolve(false);
      if (!ready || !session || !sdk) {
        return Promise.reject(new Error('LiveAvatar session not ready.'));
      }
      if (!session._sessionEventSocket) {
        return Promise.reject(new Error('LiveAvatar WebSocket required for LITE lip-sync.'));
      }

      return fetchTtsPcm24k(text).then(function (pcm) {
        if (!pcm || pcm.length < 500) {
          throw new Error('PCM audio too short.');
        }
        session.repeatAudio(pcm);
        return new Promise(function (resolve) {
          setTimeout(function () {
            resolve(true);
          }, pcmDurationMs(pcm));
        });
      });
    },

    stop: function () {
      return this.reset();
    },
  };
})(window);
