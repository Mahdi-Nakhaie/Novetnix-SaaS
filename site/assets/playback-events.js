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

  window.NoqtePlaybackEvents = Object.freeze({ attach: attach, detach: detach, retry: retry, readState: readState });
})(window);
