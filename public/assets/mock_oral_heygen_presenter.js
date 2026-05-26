/**
 * HeyGen Live Avatar presenter — text-repeat mode only.
 * OpenAI (server) decides maya_text; HeyGen lip-syncs and speaks it.
 * Does NOT use HeyGen voice chat / knowledge base.
 */
(function (global) {
  'use strict';

  var avatar = null;
  var sdk = null;
  var ready = false;
  var videoEl = null;
  var initPromise = null;

  function loadSdk() {
    if (sdk) return Promise.resolve(sdk);
    if (global.HeyGenStreamingAvatar) {
      sdk = global.HeyGenStreamingAvatar;
      return Promise.resolve(sdk);
    }
    return import('https://esm.sh/@heygen/streaming-avatar@2.1.0').then(function (mod) {
      sdk = {
        StreamingAvatar: mod.default,
        AvatarQuality: mod.AvatarQuality,
        StreamingEvents: mod.StreamingEvents,
        TaskType: mod.TaskType,
        TaskMode: mod.TaskMode,
      };
      return sdk;
    });
  }

  function attachStream(stream) {
    if (!videoEl || !stream) return;
    videoEl.srcObject = stream;
    videoEl.muted = false;
    videoEl.hidden = false;
    var play = videoEl.play();
    if (play && typeof play.catch === 'function') {
      play.catch(function () {});
    }
  }

  global.MoeHeyGenPresenter = {
    isReady: function () {
      return ready;
    },

    init: function (opts) {
      opts = opts || {};
      if (initPromise) return initPromise;

      if (!opts.token || !opts.avatarId) {
        return Promise.reject(new Error('HeyGen token and avatar ID are required.'));
      }

      videoEl = opts.videoEl || null;
      var activityIdle = parseInt(opts.activityIdleTimeoutSec, 10) || 600;
      activityIdle = Math.max(120, Math.min(3600, activityIdle));

      initPromise = loadSdk().then(function (loaded) {
        var StreamingAvatar = loaded.StreamingAvatar;
        var AvatarQuality = loaded.AvatarQuality;
        var StreamingEvents = loaded.StreamingEvents;

        avatar = new StreamingAvatar({ token: opts.token });

        var streamReady = new Promise(function (resolve, reject) {
          var timeout = setTimeout(function () {
            reject(new Error('HeyGen stream timed out.'));
          }, 45000);

          avatar.on(StreamingEvents.STREAM_READY, function (ev) {
            clearTimeout(timeout);
            attachStream(ev.detail || ev);
            resolve(true);
          });

          avatar.on(StreamingEvents.ERROR, function (ev) {
            clearTimeout(timeout);
            reject(new Error((ev && ev.message) || 'HeyGen stream error.'));
          });
        });

        var voice = { rate: 1.05 };
        if (opts.voiceId) voice.voiceId = opts.voiceId;

        var startOpts = {
          quality: AvatarQuality.High,
          avatarName: opts.avatarId,
          voice: voice,
          activityIdleTimeout: activityIdle,
          language: 'en',
        };

        return avatar.createStartAvatar(startOpts).then(function (sessionInfo) {
          if (sessionInfo && sessionInfo.stream) {
            attachStream(sessionInfo.stream);
          }
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
      if (!ready || !avatar || !sdk) {
        return Promise.reject(new Error('HeyGen avatar not ready.'));
      }

      var TaskType = sdk.TaskType;
      var TaskMode = sdk.TaskMode;
      var speakOpts = { text: text };
      if (TaskType && TaskType.REPEAT) speakOpts.taskType = TaskType.REPEAT;
      if (TaskMode && TaskMode.SYNC) speakOpts.taskMode = TaskMode.SYNC;

      return avatar.speak(speakOpts).then(function () {
        return true;
      });
    },

    stop: function () {
      ready = false;
      initPromise = null;
      var stopper = avatar && avatar.stopAvatar ? avatar.stopAvatar() : Promise.resolve();
      avatar = null;
      if (videoEl) {
        try {
          videoEl.srcObject = null;
        } catch (e) {}
      }
      return Promise.resolve(stopper).catch(function () {});
    },
  };
})(window);
