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
    phone: "شماره موبایل معتبر وارد کنید.",
    password: "رمز باید دست‌کم ۸ نویسه و شامل حرف انگلیسی و عدد باشد."
  };

  function normalizedPhone(value) {
    return value
      .replace(/[۰-۹٠-٩]/g, function (digit) {
        return "۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩".indexOf(digit) % 10;
      })
      .replace(/[\s-]+/g, "")
      .replace(/^(\+98|98)/, "0");
  }

  function fieldMessage(field) {
    if (field.dataset.validation === "phone" && !/^09[0-9]{9}$/.test(normalizedPhone(field.value))) return messages.phone;
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
  var loading = document.getElementById("noventix-loading");
  var loadingDelay;
  var loadingTimeout;
  function hideLoading() {
    window.clearTimeout(loadingDelay);
    window.clearTimeout(loadingTimeout);
    if (loading) loading.hidden = true;
  }
  document.addEventListener("submit", function (event) {
    if (!loading || event.defaultPrevented || event.target.method.toLowerCase() !== "post") return;
    hideLoading();
    loadingDelay = window.setTimeout(function () { loading.hidden = false; }, 180);
    loadingTimeout = window.setTimeout(hideLoading, 10000);
  });
  window.addEventListener("pageshow", hideLoading);
  window.addEventListener("pagehide", hideLoading);

  var backLink = document.querySelector("[data-safe-back]");
  if (backLink && document.referrer && window.history.length > 1) {
    var previous = new URL(document.referrer);
    if (previous.origin === window.location.origin) {
      backLink.hidden = false;
      backLink.addEventListener("click", function (event) {
        event.preventDefault();
        window.history.back();
      });
    }
  }
  var glossaryForm = document.querySelector("[data-glossary-search]");
  if (glossaryForm) {
    var glossaryInput = glossaryForm.querySelector("[data-glossary-input]");
    var glossaryClear = glossaryForm.querySelector("[data-glossary-clear]");
    var glossarySuggestions = glossaryForm.querySelector("[data-glossary-suggestions]");
    var glossaryTimer;
    var glossaryController;
    function showGlossaryMessage(message, retry) {
      glossarySuggestions.hidden = false;
      glossarySuggestions.innerHTML = "";
      var text = document.createElement("span");
      text.textContent = message;
      glossarySuggestions.appendChild(text);
      if (retry) {
        var button = document.createElement("button");
        button.type = "button";
        button.className = "glossary-retry";
        button.textContent = "تلاش دوباره";
        button.addEventListener("click", loadGlossarySuggestions);
        glossarySuggestions.appendChild(button);
      }
    }
    function loadGlossarySuggestions() {
      var query = glossaryInput.value.trim();
      glossaryClear.hidden = query === "";
      if (glossaryController) glossaryController.abort();
      if (query.length < 2) {
        glossarySuggestions.hidden = true;
        glossarySuggestions.innerHTML = "";
        return;
      }
      glossarySuggestions.hidden = false;
      glossarySuggestions.textContent = "در حال جست‌وجو…";
      glossaryController = new AbortController();
      var endpoint = new URL(glossaryForm.action, window.location.origin);
      endpoint.pathname = endpoint.pathname.replace(/\/$/, "") + "/suggest";
      endpoint.search = "q=" + encodeURIComponent(query);
      fetch(endpoint.toString(), { headers: { Accept: "application/json" }, signal: glossaryController.signal })
        .then(function (response) { if (!response.ok) throw new Error("search"); return response.json(); })
        .then(function (payload) {
          if (!payload.suggestions || !payload.suggestions.length) { showGlossaryMessage("پیشنهادی پیدا نشد.", false); return; }
          glossarySuggestions.innerHTML = "";
          payload.suggestions.forEach(function (suggestion) {
            var link = document.createElement("a");
            link.href = glossaryForm.action.replace(/\/$/, "") + "/" + encodeURIComponent(suggestion.key);
            link.innerHTML = "<strong></strong><span></span>";
            link.querySelector("strong").textContent = suggestion.title;
            link.querySelector("span").textContent = suggestion.term;
            glossarySuggestions.appendChild(link);
          });
        })
        .catch(function (error) { if (error.name !== "AbortError") showGlossaryMessage("جست‌وجو موقتاً در دسترس نیست.", true); });
    }
    glossaryInput.addEventListener("input", function () {
      window.clearTimeout(glossaryTimer);
      glossaryTimer = window.setTimeout(loadGlossarySuggestions, 280);
    });
    glossaryClear.addEventListener("click", function () {
      glossaryInput.value = "";
      glossaryInput.focus();
      glossarySuggestions.hidden = true;
      glossarySuggestions.innerHTML = "";
      glossaryClear.hidden = true;
      if (glossaryController) glossaryController.abort();
    });
    glossaryForm.addEventListener("submit", function () {
      if (glossaryController) glossaryController.abort();
      glossarySuggestions.hidden = true;
    });
  }

})();
