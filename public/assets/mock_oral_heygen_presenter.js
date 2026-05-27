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

      var AgentEventsEnum = sdk.AgentEventsEnum;

      function estimateSpeakMs(message) {
        var words = String(message || '').trim().split(/\s+/).filter(Boolean).length;
        return Math.max(5000, Math.min(120000, words * 420));
      }

      return new Promise(function (resolve, reject) {
        var settled = false;
        var started = false;
        var hardTimeout = null;
        var fallbackTimeout = null;

        function finish(ok) {
          if (settled) return;
          settled = true;
          cleanup();
          if (ok) resolve(true);
          else reject(new Error('LiveAvatar speak failed.'));
        }

        function cleanup() {
          clearTimeout(hardTimeout);
          clearTimeout(fallbackTimeout);
          if (AgentEventsEnum) {
            session.off(AgentEventsEnum.AVATAR_SPEAK_ENDED, onEnded);
            session.off(AgentEventsEnum.AVATAR_SPEAK_STARTED, onStarted);
          }
        }

        function onStarted() {
          started = true;
          clearTimeout(fallbackTimeout);
          fallbackTimeout = setTimeout(function () {
            finish(true);
          }, estimateSpeakMs(text) + 3000);
        }

        function onEnded() {
          finish(true);
        }

        hardTimeout = setTimeout(function () {
          finish(started);
        }, 120000);

        session.on(AgentEventsEnum.AVATAR_SPEAK_ENDED, onEnded);
        session.on(AgentEventsEnum.AVATAR_SPEAK_STARTED, onStarted);

        try {
          session.repeat(text);
          fallbackTimeout = setTimeout(function () {
            if (!started) finish(true);
          }, estimateSpeakMs(text));
        } catch (err) {
          finish(false);
        }
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
