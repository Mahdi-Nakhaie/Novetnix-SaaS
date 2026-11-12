(function () {
  "use strict";

  var root = document.querySelector(".lp");
  if (!root) return;

  var motion = window.matchMedia("(prefers-reduced-motion: reduce)");

  // Copy-to-clipboard for the hero code block. Falls back silently when the
  // Clipboard API is unavailable (insecure origin or older browser).
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

  // Scroll reveal. Content is visible by default (no JS = fully readable);
  // we only hide-then-reveal when the feature is actually supported.
  if (motion.matches || !("IntersectionObserver" in window)) return;

  var targets = root.querySelectorAll(
    ".lp-feature, .lp-step, .lp-plan, .lp-faq__item, .lp-diff__row, .lp-trust__chip, .lp__head"
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
