"use strict";

const BASE = "https://cdn.jsdelivr.net/pyodide/v0.27.7/full/";
let loading = null;

function boot() {
  if (loading) return loading;
  loading = (async () => {
    importScripts(BASE + "pyodide.js");
    const engine = await loadPyodide({ indexURL: BASE });
    engine.registerJsModule("noventixPlot", { emit: (png) => self.postMessage({ type: "plot", png }) });
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

function localSources(code, files, filename) {
  const sources = [];
  const visited = new Set();
  const directory = filename.includes("/") ? filename.slice(0, filename.lastIndexOf("/") + 1) : "";
  function visit(source) {
    const imports = /^\s*(?:from\s+([A-Za-z_]\w*(?:\.[A-Za-z_]\w*)*)\s+import|import\s+([A-Za-z_]\w*(?:\.[A-Za-z_]\w*)*))/gm;
    let match;
    while ((match = imports.exec(source))) {
      const module = (match[1] || match[2]).replace(/\./g, "/");
      const paths = [directory + module + ".py", module + ".py"];
      for (const path of paths) {
        if (!Object.prototype.hasOwnProperty.call(files, path) || visited.has(path)) continue;
        visited.add(path);
        const dependency = String(files[path]);
        sources.push(dependency);
        visit(dependency);
        break;
      }
    }
  }
  visit(code);
  return sources;
}

self.onmessage = async function (event) {
  const code = event.data.code || "";
  let output = "";
  let stage = "آماده‌سازی Python";
  const append = (text) => { output = (output + text + "\n").slice(-40000); };
  try {
    self.postMessage({ type: "status", text: "در حال آماده‌سازی موتور Python…" });
    const engine = await boot();
    stage = "بارگذاری فایل‌های میزکار";
    mountFiles(engine, event.data.files);
    const packages = Array.isArray(event.data.packages) ? event.data.packages.filter((name) => /^[a-z0-9][a-z0-9._-]*$/i.test(name)) : [];
    if (packages.length) {
      self.postMessage({ type: "status", text: "در حال آماده‌سازی بسته‌های میزکار…" });
      stage = "بارگذاری بسته‌های میزکار";
      await engine.loadPackage(packages);
    }
    engine.setStdout({ batched: append });
    engine.setStderr({ batched: append });
    engine.setStdin({ stdin: () => { throw new Error("input() در این محیط پشتیبانی نمی‌شود؛ داده را داخل کد بسازید."); } });
    self.postMessage({ type: "status", text: "در حال بارگذاری وابستگی‌های کد…" });
    stage = "بارگذاری وابستگی‌های کد";
    for (const source of [code, ...localSources(code, event.data.files || {}, event.data.filename || "")]) {
      await engine.loadPackagesFromImports(source);
    }
    if (engine.loadedPackages.matplotlib) engine.runPython(`
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
    self.postMessage({ type: "running" });
    stage = "تنظیم مسیر فایل اجرایی";
    const filename = event.data.filename || "main.py";
    if (filename.includes("/")) engine.FS.chdir(filename.slice(0, filename.lastIndexOf("/")));
    engine.runPython("import sys; sys.path.insert(0, " + JSON.stringify(engine.FS.cwd()));
    stage = "اجرای کد";
    const result = await engine.runPythonAsync(code);
    if (result !== undefined && result !== null) append(String(result));
    if (engine.loadedPackages.matplotlib) await engine.runPythonAsync("_noventix_show()");
    self.postMessage({ type: "done", ok: true, output });
  } catch (error) {
    const detail = error && (error.message || error.stack || error.name || (error.errno != null ? "خطای فایل با کد " + error.errno : "")) || "خطای ناشناخته";
    self.postMessage({ type: "done", ok: false, output: output + stage + ": " + detail });
  }
};
