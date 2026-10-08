(function (window) {
  "use strict";

  var attached = new WeakMap();
  var eventTypes = ["play", "pause", "seeking", "seeked", "loadedmetadata", "timeupdate", "ended"];

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

  function detach(player) {
    var binding = attached.get(player);
    if (!binding) return;
    eventTypes.forEach(function (type) { player.removeEventListener(type, binding.handlers[type]); });
    attached.delete(player);
  }

  function attach(player, onEvent) {
    if (!player || typeof player.addEventListener !== "function" || typeof onEvent !== "function") {
      throw new TypeError("A Player EventTarget and event callback are required.");
    }
    detach(player);
    var handlers = {};
    eventTypes.forEach(function (type) {
      handlers[type] = function () { onEvent(normalize(type, player)); };
      player.addEventListener(type, handlers[type]);
    });
    attached.set(player, { handlers: handlers });
    return function () { detach(player); };
  }

  window.NoqtePlaybackEvents = Object.freeze({ attach: attach, detach: detach });
})(window);
