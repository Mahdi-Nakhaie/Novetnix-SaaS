"use strict";

/* Pyodide runs the student's Python. The worker owns one long-lived engine so
   the terminal and the editor share the same interpreter, filesystem and
   imported modules instead of booting a fresh runtime per command. */

const BASE = "https://cdn.jsdelivr.net/pyodide/v0.27.7/full/";
const DEFAULT_PACKAGES = ["numpy", "pandas", "matplotlib", "scipy"];

let enginePromise = null;

/* Pyodide failures arrive as Error, ErrnoError or plain objects; String() on
   them yields "[object Object]" and hides the only useful part. */
function describe(error) {
  if (error === null || error === undefined) return "خطای نامشخص";
  if (typeof error === "string") return error;
  const parts = [];
  if (error.name && error.name !== "Error") parts.push(String(error.name));
  if (error.message && String(error.message) !== String(error.name)) parts.push(String(error.message));
  if (!parts.length && error.errno !== undefined && error.errno !== null) parts.push("errno " + String(error.errno));
  if (!parts.length && error.type) parts.push(String(error.type));
  if (!parts.length) { try { parts.push(JSON.stringify(error)); } catch (_) { parts.push(String(error)); } }
  return parts.join(": ");
}

function publish(text) {
  self.postMessage({ type: "stream", text: String(text) });
}

function boot() {
  if (enginePromise) return enginePromise;
  enginePromise = (async () => {
    self.postMessage({ type: "status", text: "در حال دریافت موتور Python…" });
    importScripts(BASE + "pyodide.js");
    const engine = await loadPyodide({ indexURL: BASE });
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
    await engine.loadPackage(DEFAULT_PACKAGES);
    return engine;
  })();
  enginePromise.catch(() => { enginePromise = null; });
  return enginePromise;
}

function validVirtualPath(path) {
  return typeof path === "string" && /^(?!\.)(?:[A-Za-z0-9_./-])+$/.test(path) && path.indexOf("..") < 0 && path[0] !== "/";
}

function mountFiles(engine, files) {
  Object.keys(files || {}).forEach((name) => {
    if (!validVirtualPath(name) || name.length > 160) return;
    const parent = name.split("/").slice(0, -1).join("/");
    if (parent) engine.FS.mkdirTree(parent);
    engine.FS.writeFile(name, String(files[name] == null ? "" : files[name]));
  });
}

/* Reads back the files the student touched so the browser can persist them. */
function collect(engine, names) {
  const out = {};
  if (!engine) return out;
  (names || []).forEach((name) => {
    if (!validVirtualPath(name)) return;
    try { out[name] = engine.FS.readFile(name, { encoding: "utf8" }); } catch (_) { /* missing or binary */ }
  });
  return out;
}

/* Streams stdout/stderr into a sink and restores the previous writer, so
   repeated commands on the shared engine do not stack output handlers. */
async function withOutput(engine, sink, run) {
  const previous = engine.setStdout({ batched: sink });
  engine.setStderr({ batched: sink });
  engine.setStdin({ stdin: () => { throw new Error("input() در این میزکار پشتیبانی نمی‌شود؛ داده را داخل کد بسازید."); } });
  try {
    return await run();
  } finally {
    if (previous && previous.stdout) engine.setStdout(previous.stdout);
  }
}

async function runCode(engine, code, packages) {
  const names = Array.isArray(packages) ? packages.filter((n) => /^[a-z0-9][a-z0-9._-]*$/i.test(n)) : [];
  for (const name of names) {
    try { await engine.loadPackage(name); } catch (error) { publish("بارگیری بسته «" + name + "» ناموفق بود: " + describe(error)); }
  }
  self.postMessage({ type: "status", text: "در حال بررسی وابستگی‌های کد…" });
  try { await engine.loadPackagesFromImports(code); } catch (error) { publish("بخشی از وابستگی‌ها بارگیری نشد: " + describe(error)); }
  self.postMessage({ type: "running" });
  const result = await engine.runPythonAsync(code);
  if (result !== undefined && result !== null) publish(String(result));
  await engine.runPythonAsync("_noventix_show()");
}

/* Flags are not filenames: "rm -rf /" must be rejected, not delete a file
   literally named "-rf". */
function tokenize(raw) {
  const parts = String(raw || "").match(/(?:[^\s"']+|"[^"]*"|'[^']*')+/g) || [];
  const verb = parts[0] || "";
  const rest = parts.slice(1);
  const flags = rest.filter((token) => /^-/.test(token));
  const operands = rest.filter((token) => !/^-/.test(token));
  return {
    verb: verb,
    flags: flags,
    count: operands.length,
    has: (flag) => flags.indexOf(flag) >= 0,
    arg: (index) => String(operands[index - 1] || "").replace(/^["']|["']$/g, ""),
  };
}

async function runCommand(engine, raw) {
  const cmd = tokenize(raw);
  const { verb, arg } = cmd;
  if (!verb) return;
  if (/^-/.test(verb)) return publish("فرمان پشتیبانی نمی‌شود: " + raw + "\nبرای راهنما help را اجرا کنید.");

  if (verb === "pwd") return publish("/home/pyodide");
  if (verb === "cd") {
    try { engine.FS.chdir(arg(1) || "/home/pyodide"); } catch (error) { return publish(describe(error)); }
    return publish(engine.FS.cwd());
  }
  if (verb === "ls") {
    try { return publish(engine.FS.readdir(arg(1) || ".").filter((n) => n !== "." && n !== "..").sort().join("  ") || "(خالی)"); }
    catch (error) { return publish(describe(error)); }
  }
  if (verb === "cat") {
    if (!arg(1)) return publish("استفاده: cat <فایل>");
    try { return publish(engine.FS.readFile(arg(1), { encoding: "utf8" })); }
    catch (error) { return publish(describe(error)); }
  }
  if (verb === "mkdir") {
    if (!arg(1)) return publish("استفاده: mkdir <پوشه>");
    try { engine.FS.mkdirTree(arg(1)); return publish("پوشه ساخته شد: " + arg(1)); }
    catch (error) { return publish(describe(error)); }
  }
  if (verb === "rm") {
    if (cmd.flags.length) return publish("این ترمینال فقط «rm <فایل>» را می‌پذیرد و گزینه‌های خط فرمان را اجرا نمی‌کند.");
    if (!arg(1)) return publish("استفاده: rm <فایل>");
    if (!validVirtualPath(arg(1))) return publish("نام فایل معتبر نیست.");
    try { engine.FS.unlink(arg(1)); return publish("حذف شد: " + arg(1)); }
    catch (error) { return publish(describe(error)); }
  }
  if (verb === "pip" || (verb === "python" && cmd.has("-m"))) {
    const action = arg(1);
    if (action === "list") return publish((engine.loadedPackages || []).join("\n") || "فهرست بسته‌ها در دسترس نیست.");
    if (action !== "install") return publish("فقط «pip list» و «pip install <بسته>» پشتیبانی می‌شود.");
    const names = [];
    for (let index = 2; index <= cmd.count; index++) {
      const name = arg(index);
      if (/^[a-z0-9][a-z0-9._-]*$/i.test(name)) names.push(name);
    }
    if (!names.length) return publish("استفاده: pip install <بسته>");
    for (const name of names) {
      publish("نصب " + name + "…");
      try { await engine.loadPackage(name); publish("نصب شد: " + name); }
      catch (error) { publish("نصب «" + name + "» ناموفق بود: " + describe(error)); }
    }
    return;
  }
  if (verb === "python" && cmd.has("-c")) return runCode(engine, arg(1), []);
  if (verb === "python" || verb === "python3") {
    const target = arg(1);
    if (!target) return publish("استفاده: python <فایل.py>");
    if (!validVirtualPath(target)) return publish("نام فایل معتبر نیست.");
    if (!engine.FS.analyzePath(target).exists) return publish("فایل پیدا نشد: " + target);
    publish("— اجرای " + target + " —");
    await runCode(engine, engine.FS.readFile(target, { encoding: "utf8" }), []);
    return;
  }
  publish("فرمان پشتیبانی نمی‌شود: " + raw + "\nبرای راهنما help را اجرا کنید.");
}

async function execute(request) {
  const engine = await boot();
  await withOutput(engine, request.sink, () => runCode(engine, request.code, request.packages));
}

async function command(request) {
  const engine = await boot();
  await withOutput(engine, request.sink, () => runCommand(engine, request.command));
  await engine.runPythonAsync("_noventix_show()");
}

self.onmessage = async function (event) {
  const request = event.data || {};
  const names = Object.keys(request.files || {});
  let output = "";
  const append = (text) => { output = (output + text).slice(-40000); };
  request.sink = request.type === "command" ? (text) => { output = (output + text).slice(-40000); publish(text); } : append;
  try {
    if (request.type === "command") await command(request);
    else { const engine = await boot(); mountFiles(engine, request.files); await execute(request); }
    self.postMessage({ type: "done", ok: true, output, files: collect(await enginePromise, names) });
  } catch (error) {
    self.postMessage({ type: "done", ok: false, output: (output ? output + "\n" : "") + describe(error), files: {} });
  }
};
