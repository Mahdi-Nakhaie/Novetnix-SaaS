"use strict";

const BASE = "https://cdn.jsdelivr.net/pyodide/v0.27.7/full/";
let loading = null;

function boot() {
  if (loading) return loading;
  loading = (async () => {
    importScripts(BASE + "pyodide.js");
    const engine = await loadPyodide({ indexURL: BASE });
    await engine.loadPackagesFromImports("import numpy, pandas, matplotlib, scipy");
    engine.registerJsModule("noventixPlot", { emit: (png) => self.postMessage({ type: "plot", png }) });
    engine.runPython(`
import base64 as _base64
import io as _io
import matplotlib
matplotlib.use("Agg")
import matplotlib.pyplot as plt
import noventixPlot

def _noventix_show(*_args, **_kwargs):
    for number in plt.get_fignums():
        figure = plt.figure(number)
        buffer = _io.BytesIO()
        figure.savefig(buffer, format="png", dpi=110, bbox_inches="tight", facecolor="white")
        noventixPlot.emit(_base64.b64encode(buffer.getvalue()).decode("ascii"))
        plt.close(figure)

plt.show = _noventix_show
`);
    return engine;
  })();
  return loading;
}

function mountFiles(engine, files) {
  Object.keys(files || {}).forEach((name) => {
    if (!/^(?!\\.)(?:[A-Za-z0-9_./-])+$/.test(name) || name.indexOf("..") >= 0 || name[0] === "/") return;
    const parent = name.split("/").slice(0, -1).join("/");
    if (parent) engine.FS.mkdirTree(parent);
    engine.FS.writeFile(name, String(files[name] == null ? "" : files[name]));
  });
}

self.onmessage = async function (event) {
  const code = event.data.code || "";
  let output = "";
  const append = (text) => { output = (output + text + "\n").slice(-40000); };
  try {
    self.postMessage({ type: "status", text: "در حال آماده‌سازی موتور Python…" });
    const engine = await boot();
    mountFiles(engine, event.data.files);
    const packages = Array.isArray(event.data.packages) ? event.data.packages.filter((name) => /^[a-z0-9][a-z0-9._-]*$/i.test(name)) : [];
    if (packages.length) {
      self.postMessage({ type: "status", text: "در حال آماده‌سازی بسته‌های میزکار…" });
      try { await engine.loadPackage(packages); } catch (error) { append("بارگیری برخی بسته‌ها ناموفق بود: " + String(error)); }
    }
    engine.setStdout({ batched: append });
    engine.setStderr({ batched: append });
    engine.setStdin({ stdin: () => { throw new Error("input() در این محیط پشتیبانی نمی‌شود؛ داده را داخل کد بسازید."); } });
    self.postMessage({ type: "status", text: "در حال بارگذاری وابستگی‌های کد…" });
    try { await engine.loadPackagesFromImports(code); } catch (error) { append(String(error)); }
    self.postMessage({ type: "running" });
    const result = await engine.runPythonAsync(code);
    if (result !== undefined && result !== null) append(String(result));
    await engine.runPythonAsync("_noventix_show()");
    self.postMessage({ type: "done", ok: true, output });
  } catch (error) {
    self.postMessage({ type: "done", ok: false, output: output + String(error) });
  }
};
