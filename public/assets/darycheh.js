(function () {
  "use strict";
  var page = document.querySelector(".dr-page");
  if (!page) return;
  var status = document.getElementById("dr-save-status");
  var error = document.getElementById("dr-form-error");
  var edits = document.getElementById("dr-edit-status");
  var pending = false;
  var buttons = [];

  function reset() {
    pending = false;
    page.querySelectorAll("form.dr-form").forEach(function (form) { form.removeAttribute("aria-busy"); });
    status.hidden = true;
    buttons.forEach(function (item) { item.button.disabled = item.disabled; });
    buttons = [];
  }
  function invalidField(form) {
    var groups = form.querySelectorAll("[data-dr-kind]");
    for (var i = 0; i < groups.length; i++) {
      var group = groups[i];
      var kind = group.dataset.drKind;
      if (kind === "single") continue;
      var controls = Array.from(group.querySelectorAll(kind === "order" ? "select" : "input:checked"));
      var values = controls.map(function (control) { return control.value; }).filter(Boolean);
      var min = Number(group.dataset.drMin);
      var max = Number(group.dataset.drMax);
      var message = "";
      if (values.length < min || values.length > max) {
        message = min === max ? "تعداد گزینه‌های انتخاب‌شده باید " + min.toLocaleString("fa-IR") + " باشد." : "تعداد گام‌ها باید بین " + min.toLocaleString("fa-IR") + " و " + max.toLocaleString("fa-IR") + " باشد.";
      } else if (kind === "order" && new Set(values).size !== values.length) {
        message = "هر کارت را فقط یک‌بار در ترتیب گام‌ها انتخاب کن.";
      }
      if (message) {
        group.setAttribute("aria-invalid", "true");
        return { control: group.querySelector("input, select"), message: message };
      }
      group.removeAttribute("aria-invalid");
    }
    return null;
  }
  page.addEventListener("change", function (event) {
    if (!event.target.closest("form.dr-form")) return;
    var group = event.target.closest("[data-dr-kind]");
    if (group) group.removeAttribute("aria-invalid");
    error.hidden = true;
    if (edits) edits.textContent = "انتخاب‌ها تغییر کرده‌اند؛ برای ذخیره، دکمهٔ ثبت این مرحله را بزن.";
  });
  page.addEventListener("submit", function (event) {
    var form = event.target;
    if (!form.matches("form.dr-form") || event.defaultPrevented) return;
    if (pending) { event.preventDefault(); return; }
    var invalid = invalidField(form);
    if (invalid) {
      event.preventDefault();
      error.textContent = invalid.message;
      error.hidden = false;
      invalid.control.focus();
      return;
    }
    pending = true;
    error.hidden = true;
    status.hidden = false;
    form.setAttribute("aria-busy", "true");
    page.querySelectorAll('form.dr-form button[type="submit"]').forEach(function (button) {
      buttons.push({ button: button, disabled: button.disabled });
      button.disabled = true;
    });
  });
  window.addEventListener("pageshow", reset);
  window.addEventListener("pagehide", reset);
}());
