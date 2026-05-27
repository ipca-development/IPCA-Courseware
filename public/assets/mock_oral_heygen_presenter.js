/**
 * LiveAvatar presenter — text repeat mode only.
 * OpenAI (server) decides maya_text; LiveAvatar lip-syncs and speaks it.
 * Does NOT use voice chat or avatar knowledge base.
 */
(function (global) {
  'use strict';

  var session = null;
  var sdk = null;
  var ready = false;
  var videoEl = null;
  var initPromise = null;
  var ttsUrlFn = null;

  var LIVEKIT_COMMAND_TOPIC = 'agent-control';
  var SPEAK_TEXT_EVENT = 'avatar.speak_text';

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

  function generateEventId() {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) {
      return crypto.randomUUID();
    }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = (Math.random() * 16) | 0;
      var v = c === 'x' ? r : (r & 0x3) | 0x8;
      return v.toString(16);
    });
  }

  /**
   * LITE sessions expose a WebSocket, but the SDK only sends speak-TEXT over LiveKit.
   * session.repeat(text) is silently dropped when ws is open — route text via LiveKit.
   */
  function sendSpeakTextViaLivekit(text) {
    if (!session || !session.room || session.room.state !== 'connected') {
      return false;
    }
    var eventType = (sdk && sdk.CommandEventsEnum && sdk.CommandEventsEnum.AVATAR_SPEAK_TEXT)
      ? sdk.CommandEventsEnum.AVATAR_SPEAK_TEXT
      : SPEAK_TEXT_EVENT;
    var payload = {
      event_id: generateEventId(),
      event_type: eventType,
      text: String(text || '').trim(),
    };
    if (!payload.text) return false;
    var data = new TextEncoder().encode(JSON.stringify(payload));
    session.room.localParticipant.publishData(data, {
      reliable: true,
      topic: LIVEKIT_COMMAND_TOPIC,
    });
    return true;
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
      .then(mp3BlobToPcm24k);
  }

  function waitForAvatarSpeech(trigger) {
    var AgentEventsEnum = sdk.AgentEventsEnum;

    return new Promise(function (resolve, reject) {
      var started = false;
      var settled = false;
      var startDeadline = null;
      var hardTimeout = null;

      function cleanup() {
        clearTimeout(startDeadline);
        clearTimeout(hardTimeout);
        if (AgentEventsEnum) {
          session.off(AgentEventsEnum.AVATAR_SPEAK_ENDED, onEnded);
          session.off(AgentEventsEnum.AVATAR_SPEAK_STARTED, onStarted);
        }
      }

      function finish(ok) {
        if (settled) return;
        settled = true;
        cleanup();
        if (ok) resolve(true);
        else reject(new Error('Avatar did not speak.'));
      }

      function onStarted() {
        started = true;
        clearTimeout(startDeadline);
      }

      function onEnded() {
        finish(true);
      }

      session.on(AgentEventsEnum.AVATAR_SPEAK_STARTED, onStarted);
      session.on(AgentEventsEnum.AVATAR_SPEAK_ENDED, onEnded);

      startDeadline = setTimeout(function () {
        if (!started) finish(false);
      }, 15000);

      hardTimeout = setTimeout(function () {
        finish(started);
      }, 120000);

      try {
        var result = trigger();
        if (result && typeof result.then === 'function') {
          result.catch(function (err) {
            finish(false);
            reject(err);
          });
        }
      } catch (err) {
        cleanup();
        reject(err);
      }
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
            if (videoEl && session.attach) {
              session.attach(videoEl);
              videoEl.hidden = false;
              videoEl.muted = false;
              var play = videoEl.play();
              if (play && typeof play.catch === 'function') {
                play.catch(function () {});
              }
            }
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

    speak: function (text) {
      text = String(text || '').trim();
      if (!text) return Promise.resolve(false);
      if (!ready || !session || !sdk) {
        return Promise.reject(new Error('LiveAvatar session not ready.'));
      }

      function trySpeakText() {
        return waitForAvatarSpeech(function () {
          if (!sendSpeakTextViaLivekit(text)) {
            throw new Error('LiveKit not connected for speak.');
          }
        });
      }

      function trySpeakAudio() {
        return fetchTtsPcm24k(text).then(function (pcm) {
          if (!pcm || pcm.length < 500) {
            throw new Error('Empty PCM audio.');
          }
          return waitForAvatarSpeech(function () {
            session.repeatAudio(pcm);
          });
        });
      }

      return trySpeakText().catch(function () {
        return trySpeakAudio();
      });
    },

    stop: function () {
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
  };
})(window);
