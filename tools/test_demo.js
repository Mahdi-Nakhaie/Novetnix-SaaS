const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");

const source = fs.readFileSync(__dirname + "/demo.js", "utf8");
const runnerSource = fs.readFileSync(__dirname + "/../public/assets/python-runner.js", "utf8");
let activeWorker;
let completed;
const receivedPlots = [];
const deadlines = [];
const runnerContext = {
  window: { NOVENTIX_BASE: "/Novetnix-SaaS/site" },
  Worker: class {
    constructor(path) { assert.equal(path, "/Novetnix-SaaS/site/assets/python-worker.js"); activeWorker = this; }
    postMessage(message) { assert.equal(message.code, "print(1)"); assert.equal(message.files && Object.keys(message.files).length, 0); }
    terminate() { this.terminated = true; }
  },
  setTimeout: (_callback, delay) => { deadlines.push(delay); return 1; },
  clearTimeout: () => {},
};
vm.runInNewContext(runnerSource, runnerContext);
runnerContext.window.NOVENTIX_RUN_PYTHON("print(1)", () => {}, (result) => { completed = result; }, (png) => receivedPlots.push(png));
activeWorker.onmessage({ data: { type: "plot", png: "aGVsbG8=" } });
activeWorker.onmessage({ data: { type: "done", ok: true, output: "1" } });
activeWorker.onmessage({ data: { type: "plot", png: "late" } });
assert.deepEqual(receivedPlots, ["aGVsbG8="]);
assert.equal(completed.output, "1");
assert.equal(activeWorker.terminated, true);
assert.deepEqual(deadlines, [120000]);
runnerContext.window.NOVENTIX_RUN_PYTHON("print(1)", () => {}, () => {});
activeWorker.onmessage({ data: { type: "running" } });
assert.equal(deadlines.at(-1), 10000);
activeWorker.onmessage({ data: { type: "done", ok: true, output: "1" } });

const novaPage = fs.readFileSync(__dirname + "/../site/panel/student/nova/index.html", "utf8");
assert.match(novaPage, /این نسخهٔ ایستا امکان اتصال امن/);
assert.doesNotMatch(novaPage, /data-form="nova"/);

const memory = new Map();
const session = new Map();
const storage = (map) => ({
  getItem: (key) => map.get(key) || null,
  setItem: (key, value) => map.set(key, String(value)),
  removeItem: (key) => map.delete(key),
});

const SEED = {
  courses: {
    "python-foundations": { title: "پایتون", topic: "Python" },
    "ml-basics": { title: "یادگیری ماشین", topic: "ML" },
  },
  projects: [
    { title: "پروژه رایگان", topic: "Data", description: "…", plan: "free" },
    { title: "پروژه برنزی", topic: "ML", description: "…", plan: "bronze" },
  ],
  paid_courses: ["ml-basics"],
  challenges: ["چالش نمونه"],
  plans: [{ id: "free", name: "رایگان", price: 0 }, { id: "bronze", name: "برنزی", price: 190000 }],
};

function load(protectedPath) {
  const handlers = {};
  const location = { href: "" };
  const swaps = [];
  const timers = [];
  const flush = () => { while (timers.length) timers.shift()(); };
  const terminalLines = [];
  const terminalHost = {
    innerHTML: "",
    scrollTop: 0,
    scrollHeight: 0,
    querySelector: () => null,
    appendChild(node) { terminalLines.push(node.textContent); },
  };
  const nodes = { "[data-terminal-log]": terminalHost };
  const document = {
    body: protectedPath === undefined ? null : { getAttribute: () => protectedPath },
    querySelector: (selector) => (swaps.length && selector === "main" ? swaps[0] : (nodes[selector] || null)),
    querySelectorAll: () => [],
    addEventListener: (event, handler) => { handlers[event] = handler; },
    createElement: () => ({
      style: {}, className: "", textContent: "", insertAdjacentElement: () => {},
      remove: () => {},
    }),
  };
  const context = {
    window: {
      NOVENTIX_BASE: "/Novetnix-SaaS/site", NOVENTIX_SEED: SEED,
      addEventListener: () => {}, innerHeight: 800, pageYOffset: 0,
    },
    localStorage: storage(memory), sessionStorage: storage(session),
    document, location, setTimeout: (callback) => { timers.push(callback); }, Math, Date, String,
  };
  vm.runInNewContext(source, context);
  return { handlers, location, context, swaps, terminalLines, nodes, flush };
}

function submit(app, action, values) {
  const fields = Object.fromEntries(
    Object.entries(values).map(([key, value]) => {
      // a plain value becomes a text field; an object is passed through so
      // checkboxes can carry their own `checked` property
      if (value && typeof value === "object") return [key, { focus: () => {}, ...value }];
      return [key, { value, focus: () => {} }];
    })
  );
  const form = {
    getAttribute: (key) => key === "data-form" ? action : null,
    elements: { namedItem: (key) => fields[key] },
    parentNode: { querySelector: () => null },
    reset: () => {},
  };
  app.handlers.submit({ target: { closest: () => form }, preventDefault: () => {} });
}

let page = load();
submit(page, "login", { first_name: "کاربر", last_name: "نمونه", phone: "09123456789", password: "StrongPass123" });
assert.equal(page.location.href, "/Novetnix-SaaS/site/verify/");
const code = session.get("noventix.demo.code");
assert.match(code, /^\d{6}$/);
page = load();
submit(page, "verify", { code });
page.flush();
assert.equal(page.location.href, "/Novetnix-SaaS/site/panel/student/");
assert.equal(session.has("noventix.demo.code"), false);
submit(page, "profile", { first_name: "کاربر", last_name: "نمونه", email: "user@example.com" });
submit(page, "post", { title: "پرسش درباره پایتون", body: "چطور یک تابع آزمایش‌پذیر بنویسم؟" });
submit(page, "contact", { name: "نام آزمایشی", email: "user@example.com", message: "این پیام فقط در مرورگر می‌ماند." });
let state = JSON.parse(memory.get("noventix.demo.v1"));
assert.equal(state.user.name, "کاربر نمونه");
assert.equal(state.posts[0].title, "پرسش درباره پایتون");
assert.equal(state.messages.length, 1);
state.user = null;
memory.set("noventix.demo.v1", JSON.stringify(state));
const signInPage = load();
session.set("noventix.demo.captcha", "HX94V");
submit(signInPage, "signin", { phone: "09123456789", password: "StrongPass123", captcha: "HX94V" });
assert.equal(signInPage.location.href, "/Novetnix-SaaS/site/panel/student/");
assert.equal(load("panel/student/workspace").location.href, "");
const forum = load("community");
forum.nodes["[data-community-list]"] = { innerHTML: "" };
const postId = JSON.parse(memory.get("noventix.demo.v1")).posts[0].id;
const commentForm = {
  getAttribute: () => String(postId),
  elements: { namedItem: () => ({ value: "نظر تازه درباره پایتون" }) },
};
forum.handlers.submit({ target: { closest: (selector) => selector === "[data-comment-form]" ? commentForm : null }, preventDefault() {} });
assert.match(forum.nodes["[data-community-list]"].innerHTML, /نظر تازه درباره پایتون/);
assert.match(forum.nodes["[data-community-list]"].innerHTML, /data-member-profile/);
assert.equal(JSON.parse(memory.get("noventix.demo.v1")).posts[0].replies.length, 1);
const support = load("panel/student/support");
support.nodes["[data-tickets]"] = { innerHTML: "" };
submit(support, "ticket", { subject: "پرسش پشتیبانی", body: "جزئیات مشکل در حساب من", priority: "متوسط" });
const ticketId = JSON.parse(memory.get("noventix.demo.v1")).tickets[0].id;
const replyForm = { getAttribute: () => String(ticketId), elements: { namedItem: () => ({ value: "پاسخ من به تیکت" }) } };
support.handlers.submit({ target: { closest: (selector) => selector === "[data-ticket-reply]" ? replyForm : null }, preventDefault() {} });
assert.match(support.nodes["[data-tickets]"].innerHTML, /پاسخ من به تیکت/);
assert.equal(JSON.parse(memory.get("noventix.demo.v1")).tickets[0].replies.length, 1);
const followButton = { getAttribute: () => "عضو دیگر", textContent: "" };
forum.handlers.click({ target: { closest: (selector) => selector === "[data-follow-user]" ? followButton : null } });
assert.equal(followButton.textContent, "دنبال می‌کنید");
assert.ok(JSON.parse(memory.get("noventix.demo.v1")).follows.includes("عضو دیگر"));
state = JSON.parse(memory.get("noventix.demo.v1"));

// a signed-in account without a full name must still be sent to the gate
state = JSON.parse(memory.get("noventix.demo.v1"));
state.user.first_name = "";
state.user.last_name = "";
memory.set("noventix.demo.v1", JSON.stringify(state));
const anonymous = load("projects");
assert.equal(anonymous.location.href, "/Novetnix-SaaS/site/login/");
assert.equal(session.get("noventix.demo.return"), "projects");

// free plan: free project allowed, bronze project blocked
state = JSON.parse(memory.get("noventix.demo.v1"));
state.user.first_name = "کاربر";
state.user.last_name = "نمونه";
memory.set("noventix.demo.v1", JSON.stringify(state));
const freeProject = load("project/0");
assert.equal(freeProject.location.href, "");
const lockedProject = load("project/1");
assert.equal(lockedProject.location.href, "/Novetnix-SaaS/site/panel/student/subscription/");
const lockedCourse = load("course/ml-basics");
assert.equal(lockedCourse.location.href, "/Novetnix-SaaS/site/panel/student/subscription/");
const openCourse = load("course/python-foundations");
assert.equal(openCourse.location.href, "");

// checkout submissions cannot activate plans without a payment backend
const checkout = load("panel/student/subscription");
submit(checkout, "checkout", {
  plan: "bronze", card: "6037997712345678", holder: "کاربر نمونه",
  month: "05", year: "07", cvv: "123", agree: { checked: true },
});
state = JSON.parse(memory.get("noventix.demo.v1"));
assert.equal(state.user.plan, undefined);
assert.equal(state.payments.length, 0);
assert.equal(load("project/1").location.href, "/Novetnix-SaaS/site/panel/student/subscription/");
// code workspace: static run report, snippet saved, run counter advances
const workspace = load("panel/student/workspace");
const codeField = { value: "def solve(data):\n    return sum(data)" };
const outputField = { textContent: "" };
workspace.context.document.querySelector = (selector) => {
  if (selector === "[data-code-input]") return codeField;
  if (selector === "[data-code-output]") return outputField;
  return workspace.nodes[selector] || null;
};
const codeStatus = { textContent: "" };
const figures = [];
const plotGallery = {
  get childElementCount() { return figures.length; },
  replaceChildren() { figures.length = 0; },
  appendChild(node) { figures.push({ src: node.src, alt: node.alt }); },
};
const codeRun = { disabled: false };
const codeStop = { disabled: true };
const codeClear = { disabled: false };
const inputColumn = { querySelector: (selector) => selector === "[data-code-input]" ? codeField : null };
const codeLayout = {
  querySelector: (selector) => ({
    "[data-code-input]": codeField,
    "[data-code-output]": outputField,
    "[data-code-plots]": plotGallery,
    "[data-code-status]": codeStatus,
    "[data-code-run]": codeRun,
    "[data-code-stop]": codeStop,
    "[data-code-reset]": codeClear,
  })[selector] || null,
};
const codeControls = {
  "[data-code-run]": codeRun,
  "[data-code-stop]": codeStop,
  "[data-code-reset]": codeClear,
};
function workspaceClick(action) {
  const button = codeControls[action] || { disabled: false };
  button.closest = (parent) => (parent === ".code-layout" ? codeLayout : inputColumn);
  button.getAttribute = () => null;
  workspace.handlers.click({ target: { closest: (selector) => selector === action ? button : null } });
  return button;
}

// the runner is injected; the click path must render its result and count the run
workspace.context.window.NOVENTIX_RUN_PYTHON = (code, status, done, plot) => {
  status("در حال اجرای کد…");
  plot("aGVsbG8=");
  done({ ok: true, output: "۱۵\n" });
  return () => {};
};
workspaceClick("[data-code-run]");
assert.match(outputField.textContent, /۱۵/);
assert.equal(figures[0].src, "data:image/png;base64,aGVsbG8=");
assert.equal(figures[0].alt, "نمودار خروجی پایتون");
assert.equal(codeStatus.textContent, "اجرا پایان یافت");
assert.equal(codeRun.disabled, false);
assert.equal(codeStop.disabled, true);
state = JSON.parse(memory.get("noventix.demo.v1"));
assert.equal(state.runs, 1);

// a failing run keeps the log, counts the attempt and reports the failure
let result;
workspace.context.window.NOVENTIX_RUN_PYTHON = (_code, _status, done) => {
  result = done;
  return () => { done({ ok: false, output: "اجرا متوقف شد" }); };
};
workspaceClick("[data-code-run]");
assert.equal(figures.length, 0);
assert.equal(codeRun.disabled, true);
assert.equal(codeStop.disabled, false);
result({ ok: false, output: "ValueError: bad input" });
assert.equal(codeRun.disabled, false);
assert.equal(codeStop.disabled, true);
assert.match(outputField.textContent, /ValueError/);
assert.equal(JSON.parse(memory.get("noventix.demo.v1")).runs, 2);

// without a runner the page reports the failure instead of failing silently
delete workspace.context.window.NOVENTIX_RUN_PYTHON;
workspaceClick("[data-code-run]");
assert.equal(codeRun.disabled, true);
workspace.flush();
assert.match(outputField.textContent, /بارگیری نشد/);
assert.equal(codeRun.disabled, false);
assert.equal(codeStop.disabled, true);
assert.equal(JSON.parse(memory.get("noventix.demo.v1")).runs, 3);
workspace.context.window.NOVENTIX_RUN_PYTHON = (code, status, done) => { done({ ok: true, output: "۱۵\n" }); return () => {}; };

submit(workspace, "solution", { code: "def solve(data):\n    return sum(data)", challenge: 0 });
state = JSON.parse(memory.get("noventix.demo.v1"));
assert.deepEqual(state.solutions, [0]);
assert.equal(state.snippets[0].challenge, 0);

// The static preview does not manufacture AI responses.
submit(workspace, "nova", { question: "چطور ورودی خالی را مدیریت کنم؟" });
state = JSON.parse(memory.get("noventix.demo.v1"));
assert.equal(state.nova.length, 0);

// terminal forwards python main.py to the editor's run control
workspace.terminalLines.length = 0;
let terminalRuns = 0;
workspace.nodes["[data-code-run]"] = { disabled: false, click() { terminalRuns++; } };
submit(workspace, "terminal", { command: "python main.py" });
assert.equal(terminalRuns, 1);
assert.ok(workspace.terminalLines.some((line) => line.includes("بخش خروجی میزکار")));
delete workspace.nodes["[data-code-run]"];
submit(workspace, "terminal", { command: "rm -rf /" });
assert.ok(workspace.terminalLines.some((line) => line.includes("پشتیبانی نمی‌شود")));
state = JSON.parse(memory.get("noventix.demo.v1"));
assert.equal(state.terminal.length, 2);

// Nova code review flags missing return and silent except
submit(workspace, "review", { code: "def solve(data):\n    try:\n        print(data)\n    except:\n        pass" });
state = JSON.parse(memory.get("noventix.demo.v1"));
assert.equal(state.reviews.length, 1);
const levels = state.reviews[0].findings.map((f) => f.level);
assert.ok(levels.includes("bad"));
assert.ok(state.reviews[0].findings.some((f) => f.text.includes("return")));

// live chat keeps the message and attributes it to the signed-in account
const community = load("community");
submit(community, "chat", { message: "کسی روی پروژه تحلیل فروش کار می‌کند؟" });
state = JSON.parse(memory.get("noventix.demo.v1"));
assert.equal(state.chat.length, 1);
assert.equal(state.chat[0].author, "کاربر نمونه");
assert.equal(state.chat[0].mine, true);

workspaceClick("[data-code-reset]");
assert.equal(codeField.value, "");
assert.match(outputField.textContent, /هنوز/);
workspaceClick("[data-code-run]");
assert.match(outputField.textContent, /ابتدا کدی/);
assert.equal(JSON.parse(memory.get("noventix.demo.v1")).runs, 3);

codeField.value = "import matplotlib.pyplot as plt\nplt.plot([1, 2])";
workspace.context.window.NOVENTIX_RUN_PYTHON = (_code, _status, done, plot) => {
  plot("aGVsbG8=");
  done({ ok: true, output: "" });
  return () => {};
};
workspaceClick("[data-code-run]");
assert.match(outputField.textContent, /نمودارها/);
assert.equal(figures.length, 1);

const legacy = {
  user: state.user, users: [state.user], enrollments: [], projects: [], challenges: [],
  posts: state.posts, tickets: [], notifications: [], content: [], settings: {},
  subscriptions: [], messages: [],
};
memory.set("noventix.demo.v1", JSON.stringify(legacy));
const returning = load("panel/student/workspace");
function click(app, selector, value) {
  const button = { getAttribute: () => String(value), outerHTML: "" };
  app.handlers.click({ target: { closest: (query) => query === selector ? button : null } });
  return button;
}
for (const [selector, value, field] of [
  ["[data-enroll]", "python-foundations", "enrollments"],
  ["[data-project]", 0, "projects"],
  ["[data-challenge]", 0, "challenges"],
]) {
  assert.match(click(returning, selector, value).outerHTML, /is-done/);
  click(returning, selector, value);
  assert.deepEqual(JSON.parse(memory.get("noventix.demo.v1"))[field], [value]);
}
submit(returning, "terminal", { command: "help" });
assert.ok(returning.terminalLines.some((line) => line.includes("فرمان‌های")));
returning.nodes["#term-input"] = { value: "ls", focus() {} };
click(returning, "[data-terminal-run]");
assert.ok(returning.terminalLines.some((line) => line.includes("main.py")));
assert.equal(returning.nodes["#term-input"].value, "");
codeField.value = "def solve(data):\n    return data";
returning.context.window.NOVENTIX_RUN_PYTHON = (code, status, done) => { done({ ok: true, output: "۱۵\n" }); return () => {}; };
returning.handlers.click({
  target: { closest: (selector) => selector === "[data-code-run]" ? {
    closest: () => codeLayout,
  } : null },
});
assert.match(outputField.textContent, /۱۵/);
submit(returning, "content", { title: "مقاله آزمایشی تازه", kind: "مقاله" });
const articles = load("panel/admin/course-new");
const articleList = { dataset: {}, innerHTML: "" };
articles.nodes["[data-content-list]"] = articleList;
submit(articles, "content", { title: "مقاله تازه من", topic: "Python", kind: "مقدماتی" });
assert.match(articleList.innerHTML, /مقاله تازه من/);
assert.match(articleList.innerHTML, /پیش‌نویس/);
const reloaded = load("panel/student/workspace").context.window.NOVENTIX.state;
assert.equal(reloaded.user.phone, legacy.user.phone);
assert.equal(reloaded.posts[0].title, legacy.posts[0].title);
assert.equal(reloaded.content[0].title, "مقاله تازه من");
assert.equal(reloaded.content[1].title, "مقاله آزمایشی تازه");
assert.equal(reloaded.terminal.length, 2);
assert.equal(reloaded.runs, 1);
assert.equal(reloaded.reviews.length, 0);

async function testPythonWorker() {
  const workerSource = fs.readFileSync(__dirname + "/../public/assets/python-worker.js", "utf8");
  const messages = [];
  const files = {};
  const imports = [];
  const engine = {
    FS: { mkdirTree() {}, writeFile(name, contents) { files[name] = contents; } },
    registerJsModule(name) { assert.equal(name, "noventixPlot"); },
    async loadPackagesFromImports(source) { imports.push(source); },
    setStdout({ batched }) { this.stdout = batched; },
    setStderr() {}, setStdin() {},
    runPython(source) { assert.match(source, /matplotlib/); },
    async runPythonAsync(source) { if (source !== "_noventix_show()") { assert.equal(source, "print(2+3)"); this.stdout("5"); } },
  };
  const context = {
    self: { postMessage(message) { messages.push(message); } },
    importScripts(url) { assert.match(url, /pyodide\.js$/); },
    loadPyodide: async () => engine,
  };
  vm.runInNewContext(workerSource, context);
  await context.self.onmessage({ data: { code: "print(2+3)", files: { "main.py": "print(2+3)", "3.PNG": "image data" } } });
  assert.deepEqual(imports, ["import numpy, pandas, matplotlib, scipy", "print(2+3)"]);
  assert.equal(files["main.py"], "print(2+3)");
  assert.equal(messages.at(-1).ok, true);
  assert.match(messages.at(-1).output, /5/);
}

testPythonWorker().then(() => console.log("Demo interactions and Python worker passed"), (error) => { console.error(error); process.exitCode = 1; });
