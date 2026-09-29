(function () {
  "use strict";

  function toggle(nav, button, open) {
    nav.classList.toggle("open", open);
    button.setAttribute("aria-expanded", String(open));
  }

  document.addEventListener("click", function (event) {
    var button = event.target.closest(".menu-toggle");
    if (button) {
      var nav = document.getElementById(button.getAttribute("aria-controls") || "mobile-nav");
      if (nav) toggle(nav, button, !nav.classList.contains("open"));
      return;
    }

    var opener = event.target.closest("[data-toggle]");
    if (opener) {
      var target = document.querySelector(opener.getAttribute("data-toggle"));
      if (target) {
        var willOpen = target.hasAttribute("hidden");
        if (willOpen) target.removeAttribute("hidden");
        else target.setAttribute("hidden", "");
        opener.setAttribute("aria-expanded", String(willOpen));
      }
      return;
    }

    var panelButton = event.target.closest(".panel-menu");
    if (panelButton) {
      document.body.classList.toggle("nav-open");
      return;
    }

    if (document.body.classList.contains("nav-open") && !event.target.closest(".sidebar")) {
      document.body.classList.remove("nav-open");
    }
  });

  document.addEventListener("keydown", function (event) {
    if (event.key !== "Escape") return;
    document.body.classList.remove("nav-open");
    var nav = document.getElementById("mobile-nav");
    var button = document.querySelector(".menu-toggle");
    if (nav && button && nav.classList.contains("open")) toggle(nav, button, false);
  });

  window.addEventListener("resize", function () {
    if (window.innerWidth > 900) {
      document.body.classList.remove("nav-open");
      var nav = document.getElementById("mobile-nav");
      var button = document.querySelector(".menu-toggle");
      if (nav && button) toggle(nav, button, false);
    }
  });
})();
