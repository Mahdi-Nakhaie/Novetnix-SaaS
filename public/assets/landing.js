(function () {
  "use strict";

  var root = document.querySelector(".lp");
  if (!root) return;

  var motion = window.matchMedia("(prefers-reduced-motion: reduce)");

  // ---------------------------------------------------------------------
  // Copy-to-clipboard for the hero code block. Falls back silently when the
  // Clipboard API is unavailable (insecure origin or older browser).
  // ---------------------------------------------------------------------
  function copyCode(button) {
    var block = button.closest(".lp-code");
    var code = block && block.querySelector("code");
    if (!code) return;
    var text = code.textContent || "";
    var done = function () {
      var original = button.textContent;
      button.textContent = "کپی شد";
      window.setTimeout(function () { button.textContent = original; }, 1600);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done, function () {});
      return;
    }
    var area = document.createElement("textarea");
    area.value = text;
    area.setAttribute("readonly", "");
    area.style.position = "fixed";
    area.style.opacity = "0";
    document.body.appendChild(area);
    area.select();
    try { document.execCommand("copy"); done(); } catch (error) {}
    document.body.removeChild(area);
  }

  root.querySelectorAll("[data-copy-code]").forEach(function (button) {
    button.addEventListener("click", function () { copyCode(button); });
  });

  // ---------------------------------------------------------------------
  // Hero: type the code out over time, once, when the block is on screen.
  // The full code is in the DOM from the start, so no-JS and reduced-motion
  // users simply see finished code.
  // ---------------------------------------------------------------------
  function typeCode() {
    var lines = root.querySelectorAll(".lp-code__line");
    if (!lines.length || motion.matches) return;

    var full = [];
    lines.forEach(function (line) {
      full.push(line.innerHTML);
      line.innerHTML = "";
    });

    root.querySelector(".lp-code").classList.add("is-typing");

    var lineIndex = 0;
    var charIndex = 0;
    var textOf = full.map(function (html) {
      var probe = document.createElement("div");
      probe.innerHTML = html;
      return probe.textContent || "";
    });

    var caret = document.createElement("span");
    caret.className = "lp-code__caret";
    caret.setAttribute("aria-hidden", "true");

    function step() {
      if (lineIndex >= lines.length) {
        caret.remove();
        root.querySelector(".lp-code").classList.remove("is-typing");
        return;
      }
      var line = lines[lineIndex];
      var target = textOf[lineIndex];
      charIndex += 1;
      line.textContent = target.slice(0, charIndex);
      if (!caret.parentNode) line.appendChild(caret);
      if (charIndex >= target.length) {
        // Restore the highlighted markup once the line is complete.
        line.innerHTML = full[lineIndex];
        if (!caret.parentNode) line.appendChild(caret);
        lineIndex += 1;
        charIndex = 0;
        window.setTimeout(step, 120);
        return;
      }
      window.setTimeout(step, 18);
    }

    window.setTimeout(step, 420);
  }

  var codeBlock = root.querySelector(".lp-code");
  if (codeBlock && "IntersectionObserver" in window) {
    var codeObserver = new IntersectionObserver(function (entries) {
      if (!entries[0].isIntersecting) return;
      codeObserver.disconnect();
      typeCode();
    }, { threshold: 0.35 });
    codeObserver.observe(codeBlock);
  }

  // ---------------------------------------------------------------------
  // Feature rail: arrow buttons scroll the track by one card.
  // ---------------------------------------------------------------------
  root.querySelectorAll("[data-rail]").forEach(function (rail) {
    var viewport = rail.querySelector(".lp-rail__viewport");
    if (!viewport) return;
    var step = function () {
      var card = viewport.querySelector(".lp-feature");
      return card ? card.getBoundingClientRect().width + 18 : viewport.clientWidth * 0.8;
    };
    var prev = rail.querySelector("[data-rail-prev]");
    var next = rail.querySelector("[data-rail-next]");
    // In RTL, scrollLeft is negative, so the visual directions are mirrored.
    if (prev) prev.addEventListener("click", function () { viewport.scrollBy({ left: step(), behavior: "smooth" }); });
    if (next) next.addEventListener("click", function () { viewport.scrollBy({ left: -step(), behavior: "smooth" }); });

    var sync = function () {
      var max = viewport.scrollWidth - viewport.clientWidth;
      var pos = Math.abs(viewport.scrollLeft);
      if (prev) prev.disabled = pos < 4;
      if (next) next.disabled = pos > max - 4;
    };
    viewport.addEventListener("scroll", sync, { passive: true });
    window.addEventListener("resize", sync, { passive: true });
    sync();
  });

  // ---------------------------------------------------------------------
  // Scroll reveal. Content is visible by default (no JS = fully readable);
  // we only hide-then-reveal when the feature is actually supported.
  // ---------------------------------------------------------------------
  if (motion.matches || !("IntersectionObserver" in window)) return;

  var targets = root.querySelectorAll(
    ".lp-feature, .lp-step, .lp-plan, .lp-faq__item, .lp-diff__row, .lp-trust__item, .lp-read, .lp-project, .lp__head"
  );
  if (!targets.length) return;

  root.classList.add("lp--motion");

  var observer = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      if (!entry.isIntersecting) return;
      entry.target.classList.add("is-visible");
      observer.unobserve(entry.target);
    });
  }, { rootMargin: "0px 0px -8% 0px", threshold: 0.12 });

  targets.forEach(function (target, index) {
    target.style.setProperty("--lp-delay", Math.min(index % 6, 5) * 60 + "ms");
    observer.observe(target);
  });
})();
