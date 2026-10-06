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

  var messages = {
    required: "این فیلد را کامل کنید.",
    minlength: "مقدار واردشده کوتاه است.",
    maxlength: "مقدار واردشده بیش از حد طولانی است.",
    pattern: "مقدار واردشده معتبر نیست.",
    password: "رمز باید دست‌کم ۸ نویسه و شامل حرف انگلیسی و عدد باشد."
  };

  function fieldMessage(field) {
    if (field.dataset.validation === "password" && (!/[A-Za-z]/.test(field.value) || !/[0-9]/.test(field.value))) return messages.password;
    if (field.required && !field.value.trim()) return messages.required;
    if (field.minLength > -1 && field.value.length < field.minLength) return messages.minlength;
    if (field.maxLength > -1 && field.value.length > field.maxLength) return messages.maxlength;
    if (field.pattern && field.value && !(new RegExp("^(?:" + field.pattern + ")$")).test(field.value)) return messages.pattern;
    return "";
  }

  document.addEventListener("submit", function (event) {
    var form = event.target.closest("form.custom-validation");
    if (!form) return;
    var firstInvalid = null;
    form.querySelectorAll(".field-error").forEach(function (error) { error.remove(); });
    form.querySelectorAll("input, textarea, select").forEach(function (field) {
      field.removeAttribute("aria-invalid");
      var message = fieldMessage(field);
      if (!message) return;
      if (!firstInvalid) firstInvalid = field;
      field.setAttribute("aria-invalid", "true");
      var error = document.createElement("span");
      error.className = "field-error";
      error.setAttribute("role", "alert");
      error.textContent = message;
      field.parentElement.appendChild(error);
    });
    if (firstInvalid) {
      event.preventDefault();
      firstInvalid.focus();
    }
  });

  document.addEventListener("input", function (event) {
    var field = event.target;
    if (!field.closest("form.custom-validation") || !field.hasAttribute("aria-invalid")) return;
    var error = field.parentElement.querySelector(".field-error");
    var message = fieldMessage(field);
    if (message) {
      if (error) error.textContent = message;
      return;
    }
    field.removeAttribute("aria-invalid");
    if (error) error.remove();
  });
})();
