(function (window) {
  "use strict";

  var attached = new WeakMap();
  var eventTypes = ["play", "pause", "seeking", "seeked", "loadedmetadata", "timeupdate", "ended"];
  var stateTypes = {
    loadstart: "loading",
    waiting: "loading",
    stalled: "loading",
    loadedmetadata: "ready",
    canplay: "ready",
    playing: "ready",
    error: "error"
  };

  function mediaValue(value) {
    return typeof value === "number" && isFinite(value) ? value : null;
  }

  function normalize(type, player) {
    return {
      type: type,
      currentTime: mediaValue(player.currentTime),
      duration: mediaValue(player.duration)
    };
  }

  function normalizeState(state, player) {
    return {
      state: state,
      currentTime: mediaValue(player.currentTime),
      duration: mediaValue(player.duration)
    };
  }

  function readState(player) {
    if (!player) return null;
    var currentTime = mediaValue(player.currentTime);
    var duration = mediaValue(player.duration);
    if (currentTime === null || duration === null || duration <= 0 || currentTime < 0 || currentTime > duration) return null;
    return { currentTime: currentTime, duration: duration };
  }

  function calculatePercentage(currentTime, duration) {
    if (typeof currentTime !== "number" || !isFinite(currentTime) || currentTime < 0 ||
        typeof duration !== "number" || !isFinite(duration) || duration <= 0) return null;
    return Math.round(Math.min(currentTime, duration) / duration * 10000) / 100;
  }

  function detach(player) {
    var binding = attached.get(player);
    if (!binding) return;
    eventTypes.forEach(function (type) { player.removeEventListener(type, binding.handlers[type]); });
    Object.keys(binding.stateHandlers).forEach(function (type) { player.removeEventListener(type, binding.stateHandlers[type]); });
    attached.delete(player);
  }

  function attach(player, onEvent, onState) {
    if (!player || typeof player.addEventListener !== "function" || typeof onEvent !== "function") {
      throw new TypeError("A Player EventTarget and event callback are required.");
    }
    detach(player);
    var handlers = {};
    eventTypes.forEach(function (type) {
      handlers[type] = function () { onEvent(normalize(type, player)); };
      player.addEventListener(type, handlers[type]);
    });
    var stateHandlers = {};
    if (typeof onState === "function") Object.keys(stateTypes).forEach(function (type) {
      stateHandlers[type] = function () { onState(normalizeState(stateTypes[type], player)); };
      player.addEventListener(type, stateHandlers[type]);
    });
    attached.set(player, { handlers: handlers, stateHandlers: stateHandlers });
    return function () { detach(player); };
  }

  function retry(player) {
    if (!player || typeof player.load !== "function") return false;
    player.load();
    return true;
  }

  function connectProgress(player, options) {
    options = options || {};
    if (!options.lessonId || !options.csrf || typeof window.fetch !== "function") return function () {};
    var endpoint = options.endpoint || "/progress/save";
    var interval = options.interval || 15000;
    var dirty = false;
    var completed = false;
    var inFlight = false;
    var pending = false;
    var timer;

    function mark(done) {
      dirty = true;
      if (done) completed = true;
    }

    function flush(done) {
      if (done) completed = true;
      if (!dirty) return;
      if (inFlight) { pending = true; return; }
      var state = readState(player);
      if (!state) return;
      var percentage = calculatePercentage(state.currentTime, state.duration);
      if (percentage === null) return;
      dirty = false;
      inFlight = true;
      var payload = new URLSearchParams({
        csrf: options.csrf,
        lesson_id: String(options.lessonId),
        watched_seconds: String(state.currentTime),
        last_position: String(state.currentTime),
        percentage: String(percentage),
        completed: completed ? "1" : "0"
      });
      window.fetch(endpoint, { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" }, body: payload.toString() })
        .then(function (response) { if (!response.ok) throw new Error("save failed"); })
        .catch(function () { dirty = true; })
        .then(function () {
          inFlight = false;
          if (pending) { pending = false; dirty = true; flush(false); }
        });
    }

    var detachPlayer = attach(player, function (event) {
      if (event.type === "timeupdate") mark(false);
      if (event.type === "pause" || event.type === "ended") { mark(event.type === "ended"); flush(event.type === "ended"); }
      if (event.type === "seeked") mark(false);
    });
    timer = window.setInterval(function () { flush(false); }, interval);
    return function () { window.clearInterval(timer); detachPlayer(); };
  }

  window.NoqtePlaybackEvents = Object.freeze({ attach: attach, detach: detach, retry: retry, readState: readState, connectProgress: connectProgress });
})(window);
