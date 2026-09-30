(function () {
  "use strict";
  window.NOVENTIX_RUN_PYTHON = function (code, status, done, plot, files, packages) {
    var worker;
    var timer;
    var finished = false;
    function finish(result) {
      if (finished) return;
      finished = true;
      clearTimeout(timer);
      if (worker) worker.terminate();
      done(result);
    }
    function deadline(ms, message) {
      clearTimeout(timer);
      timer = setTimeout(function () { finish({ ok: false, output: message }); }, ms);
    }
    try {
      worker = new Worker((window.NOVENTIX_BASE || "") + "/assets/python-worker.js");
      status("در حال دریافت Python؛ بار اول به اینترنت نیاز دارد…");
      deadline(120000, "بارگیری Python یا کتابخانه‌ها طول کشید؛ اتصال اینترنت را بررسی و دوباره اجرا کنید.");
      worker.onmessage = function (event) {
        var data = event.data;
        if (finished) return;
        if (data.type === "status") status(data.text);
        if (data.type === "plot" && plot && /^[A-Za-z0-9+/=]+$/.test(data.png)) plot(data.png);
        if (data.type === "running") {
          status("در حال اجرای کد…");
          deadline(10000, "اجرا پس از ۱۰ ثانیه متوقف شد؛ حلقه‌ها و حجم محاسبات را بررسی کنید.");
        }
        if (data.type === "done") finish(data);
      };
      worker.onerror = function () {
        finish({ ok: false, output: "موتور Python بارگیری نشد؛ اتصال اینترنت و دسترسی مرورگر به Web Worker را بررسی کنید." });
      };
      worker.postMessage({ code: code, files: files || {}, packages: packages || [] });
    } catch (error) {
      finish({ ok: false, output: "اجرای Python در این مرورگر در دسترس نیست: " + error.message });
    }
    return function () { finish({ ok: false, output: "اجرا به درخواست شما متوقف شد." }); };
  };
})();
