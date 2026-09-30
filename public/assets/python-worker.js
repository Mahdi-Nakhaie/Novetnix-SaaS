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

self.onmessage = async function (event) {
  const code = event.data.code || "";
  let output = "";
  const append = (text) => { output = (output + text + "\n").slice(-40000); };
  try {
    self.postMessage({ type: "status", text: "در حال آماده‌سازی موتور Python…" });
    const engine = await boot();
    engine.setStdout({ batched: append });
    engine.setStderr({ batched: append });
    engine.setStdin({ stdin: () => { throw new Error("input() در این محیط پشتیبانی نمی‌شود؛ داده را داخل کد بسازید."); } });
    self.postMessage({ type: "status", text: "در حال بارگذاری کتابخانه‌ها…" });
    try { await engine.loadPackagesFromImports(code); } catch (error) { append(String(error)); }
    self.postMessage({ type: "running" });
    const result = await engine.runPythonAsync(code);
    if (result !== undefined && result !== null) append(String(result));
    /* a figure created without an explicit plt.show() still gets rendered */
    await engine.runPythonAsync("_noventix_show()");
    self.postMessage({ type: "done", ok: true, output });
  } catch (error) {
    self.postMessage({ type: "done", ok: false, output: output + String(error) });
  }
};
