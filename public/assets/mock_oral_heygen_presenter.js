/**
 * LiveAvatar presenter — LITE mode lip-sync via base64 PCM on WebSocket.
 */
(function (global) {
  'use strict';

  var session = null;
  var sdk = null;
  var ready = false;
  var videoEl = null;
  var initPromise = null;
  var ttsUrlFn = null;
  var streamAttached = false;
  var keepAliveTimer = null;

  var FIRST_CHUNK_BYTES = 19200;
  var SUB_CHUNK_BYTES = 48000;

  function loadSdk() {
    if (sdk) return Promise.resolve(sdk);
    if (global.LiveAvatarSdk) {
      var mod = global.LiveAvatarSdk;
      sdk = {
        LiveAvatarSession: mod.LiveAvatarSession,
        SessionEvent: mod.SessionEvent,
        AgentEventsEnum: mod.AgentEventsEnum,
      };
      return Promise.resolve(sdk);
    }
    return import('https://esm.sh/@heygen/liveavatar-web-sdk@0.0.18').then(function (mod) {
      sdk = {
        LiveAvatarSession: mod.LiveAvatarSession,
        SessionEvent: mod.SessionEvent,
        AgentEventsEnum: mod.AgentEventsEnum,
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

  function pcmDurationMs(pcmLen) {
    return Math.max(1500, Math.ceil(pcmLen / 48) + 300);
  }

  function pcmBinaryToBase64(pcm) {
    var len = pcm.length;
    var step = 0x8000;
    var bin = '';
    for (var i = 0; i < len; i += step) {
      var end = Math.min(i + step, len);
      for (var j = i; j < end; j++) {
        bin += String.fromCharCode(pcm.charCodeAt(j) & 0xff);
      }
    }
    return btoa(bin);
  }

  function splitPcmChunks(pcm) {
    var chunks = [];
    if (!pcm || !pcm.length) return chunks;
    chunks.push(pcm.slice(0, FIRST_CHUNK_BYTES));
    for (var i = FIRST_CHUNK_BYTES; i < pcm.length; i += SUB_CHUNK_BYTES) {
      chunks.push(pcm.slice(i, i + SUB_CHUNK_BYTES));
    }
    return chunks;
  }

  function wsSend(msg) {
    var ws = session && session._sessionEventSocket;
    if (!ws || ws.readyState !== WebSocket.OPEN) return false;
    ws.send(JSON.stringify(msg));
    return true;
  }

  function attachAvatarStream() {
    if (!videoEl || !session) return;
    if (!streamAttached) {
      if (typeof session.attach === 'function') {
        session.attach(videoEl);
      } else if (session._remoteVideoTrack && session._remoteVideoTrack.attach) {
        session._remoteVideoTrack.attach(videoEl);
      }
      streamAttached = true;
    }
    videoEl.hidden = false;
    videoEl.muted = false;
    videoEl.playsInline = true;
    var play = videoEl.play();
    if (play && typeof play.catch === 'function') {
      play.catch(function () {});
    }
  }

  function startKeepAlive() {
    if (keepAliveTimer) return;
    keepAliveTimer = setInterval(function () {
      if (!session) return;
      wsSend({ type: 'session.keep_alive', event_id: generateEventId() });
      if (typeof session.keepAlive === 'function') {
        session.keepAlive().catch(function () {});
      }
    }, 45000);
  }

  function stopKeepAlive() {
    if (keepAliveTimer) {
      clearInterval(keepAliveTimer);
      keepAliveTimer = null;
    }
  }

  function waitForSpeakEnded(maxMs) {
    var AgentEventsEnum = sdk && sdk.AgentEventsEnum;
    if (!session || !AgentEventsEnum) {
      return Promise.resolve(true);
    }
    return new Promise(function (resolve) {
      var done = false;
      function finish() {
        if (done) return;
        done = true;
        clearTimeout(timer);
        session.off(AgentEventsEnum.AVATAR_SPEAK_ENDED, onEnded);
        resolve(true);
      }
      function onEnded() {
        finish();
      }
      var timer = setTimeout(finish, maxMs);
      session.on(AgentEventsEnum.AVATAR_SPEAK_ENDED, onEnded);
    });
  }

  function sendPcmViaWebSocket(pcm) {
    var ws = session && session._sessionEventSocket;
    if (!ws || ws.readyState !== WebSocket.OPEN) {
      return Promise.reject(new Error('LiveAvatar WebSocket not open.'));
    }

    var chunks = splitPcmChunks(pcm);
    if (!chunks.length) {
      return Promise.reject(new Error('No PCM audio to send.'));
    }

    var eventId = generateEventId();
    var durationMs = pcmDurationMs(pcm.length);

    return new Promise(function (resolve, reject) {
      var idx = 0;

      function sendNext() {
        if (idx >= chunks.length) {
          try {
            ws.send(JSON.stringify({ type: 'agent.speak_end', event_id: eventId }));
            wsSend({ type: 'agent.start_listening', event_id: generateEventId() });
          } catch (e) {
            reject(e);
            return;
          }
          waitForSpeakEnded(durationMs + 4000).then(function () {
            attachAvatarStream();
            resolve(true);
          });
          return;
        }

        try {
          ws.send(JSON.stringify({
            type: 'agent.speak',
            event_id: eventId,
            audio: pcmBinaryToBase64(chunks[idx]),
          }));
        } catch (e) {
          reject(e);
          return;
        }

        idx += 1;
        setTimeout(sendNext, 20);
      }

      sendNext();
    });
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
      streamAttached = false;
      stopKeepAlive();
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
      attachAvatarStream();
    },

    init: function (opts) {
      opts = opts || {};
      if (initPromise) return initPromise;

      if (!opts.token) {
        return Promise.reject(new Error('LiveAvatar session token is required.'));
      }

      videoEl = opts.videoEl || null;
      ttsUrlFn = typeof opts.ttsUrl === 'function' ? opts.ttsUrl : null;
      streamAttached = false;

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
            streamAttached = false;
            attachAvatarStream();
            resolve(true);
          });

          session.on(SessionEvent.SESSION_DISCONNECTED, function () {
            ready = false;
            streamAttached = false;
          });
        });

        return session.start().then(function () {
          return streamReady;
        }).then(function () {
          ready = true;
          startKeepAlive();
          return true;
        });
      }).catch(function (err) {
        initPromise = null;
        ready = false;
        streamAttached = false;
        throw err;
      });

      return initPromise;
    },

    speakLipSync: function (text) {
      text = String(text || '').trim();
      if (!text) return Promise.resolve(false);
      if (!ready || !session) {
        return Promise.reject(new Error('LiveAvatar session not ready.'));
      }

      attachAvatarStream();

      return fetchTtsPcm24k(text).then(function (pcm) {
        if (!pcm || pcm.length < 500) {
          throw new Error('PCM audio too short.');
        }
        return sendPcmViaWebSocket(pcm);
      });
    },

    stop: function () {
      return this.reset();
    },
  };
})(window);
