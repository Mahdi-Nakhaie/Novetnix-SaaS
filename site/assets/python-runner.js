(function () {
  "use strict";

  /* One worker per page: the terminal, the editor and every challenge card
     share a single Pyodide engine, so files and imports survive between runs. */
  var worker = null;
  var pending = null;
  var LOAD_DEADLINE = 180000;
  var RUN_DEADLINE = 15000;

  function engine() {
    if (worker) return worker;
    worker = new Worker((window.NOVENTIX_BASE || "") + "/assets/python-worker.js");
    worker.onmessage = function (event) {
      var data = event.data || {};
      if (!pending) return;
      if (data.type === "status") { pending.status(data.text); return; }
      if (data.type === "stream") { pending.stream(data.text); return; }
      if (data.type === "plot" && /^[A-Za-z0-9+/=]+$/.test(data.png || "")) { pending.plot(data.png); return; }
      if (data.type === "running") { pending.arm(RUN_DEADLINE, "اجرا پس از ۱۵ ثانیه متوقف شد؛ حلقه‌ها و حجم محاسبات را بررسی کنید."); return; }
      if (data.type === "done") {
        var job = pending;
        pending = null;
        job.finish(data);
      }
    };
    worker.onerror = function () {
      failAndReset("موتور Python بارگیری نشد؛ اتصال اینترنت و دسترسی مرورگر به Web Worker را بررسی کنید.");
    };
    return worker;
  }

  function failAndReset(message) {
    if (worker) { worker.terminate(); worker = null; }
    if (pending) {
      var job = pending;
      pending = null;
      job.finish({ ok: false, output: message, files: {} });
    }
  }

  function dispatch(request, hooks) {
    if (pending) return function () {};
    var alive = true;
    var timer = null;
    var job = {
      status: hooks.status || function () {},
      stream: hooks.stream || function () {},
      plot: hooks.plot || function () {},
      arm: function (ms, message) {
        clearTimeout(timer);
        timer = setTimeout(function () {
          if (pending !== job) return;
          pending = null;
          if (worker) { worker.terminate(); worker = null; }
          hooks.done({ ok: false, output: message, files: {} });
        }, ms);
      },
      finish: function (result) {
        clearTimeout(timer);
        if (!alive) return;
        hooks.done(result);
      }
    };
    pending = job;
    job.arm(LOAD_DEADLINE, "بارگیری Python یا کتابخانه‌ها طول کشید؛ اتصال اینترنت را بررسی و دوباره اجرا کنید.");
    try {
      engine().postMessage(request);
    } catch (error) {
      failAndReset("اجرای Python در این مرورگر در دسترس نیست: " + error.message);
    }
    return function () {
      alive = false;
      if (pending === job) { pending = null; if (worker) { worker.terminate(); worker = null; } }
      hooks.done({ ok: false, output: "اجرا به درخواست شما متوقف شد.", files: {} });
    };
  }

  /* Editor runs: code plus the whole virtual project, so `import helper`
     resolves against the student's own files. */
  window.NOVENTIX_RUN_PYTHON = function (code, status, done, plot, files, packages) {
    return dispatch(
      { type: "execute", code: code, files: files || {}, packages: packages || [] },
      { status: status, done: done, plot: plot }
    );
  };

  /* Terminal runs: a shell-like command executed by the same engine so output,
     files and imported modules stay consistent with the editor. */
  window.NOVENTIX_RUN_COMMAND = function (command, files, onStream, done, status, plot) {
    return dispatch(
      { type: "command", command: command, files: files || {} },
      { status: status, stream: onStream, done: done, plot: plot }
    );
  };
})();
