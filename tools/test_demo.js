const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");

const source = fs.readFileSync(__dirname + "/demo.js", "utf8");
const memory = new Map();
const session = new Map();
const storage = (map) => ({
  getItem: (key) => map.get(key) || null,
  setItem: (key, value) => map.set(key, String(value)),
  removeItem: (key) => map.delete(key),
});

function load() {
  const handlers = {};
  const location = { href: "" };
  const document = {
    querySelector: () => null,
    querySelectorAll: () => [],
    addEventListener: (event, handler) => { handlers[event] = handler; },
  };
  const context = {
    window: { NOVENTIX_BASE: "/Novetnix-SaaS/site", NOVENTIX_SEED: { courses: {}, projects: [], plans: [] } },
    localStorage: storage(memory), sessionStorage: storage(session),
    document, location, setTimeout: (callback) => callback(), Math, Date, String,
  };
  vm.runInNewContext(source, context);
  return { handlers, location, context };
}

function submit(app, action, values) {
  const fields = Object.fromEntries(Object.entries(values).map(([key, value]) => [key, { value }]));
  const form = {
    getAttribute: () => action,
    elements: { namedItem: (key) => fields[key] },
    parentNode: { querySelector: () => null },
    reset: () => {},
  };
  app.handlers.submit({ target: { closest: () => form }, preventDefault: () => {} });
}

let page = load();
submit(page, "login", { phone: "09123456789" });
assert.equal(page.location.href, "/Novetnix-SaaS/site/verify/");
const code = session.get("noventix.demo.code");
assert.match(code, /^\d{6}$/);
page = load();
submit(page, "verify", { code });
assert.equal(page.location.href, "/Novetnix-SaaS/site/panel/student/");
assert.equal(session.has("noventix.demo.code"), false);
submit(page, "profile", { name: "کاربر نمونه", email: "user@example.com" });
submit(page, "post", { title: "پرسش درباره پایتون", body: "چطور یک تابع آزمایش‌پذیر بنویسم؟" });
submit(page, "contact", { name: "نام آزمایشی", email: "user@example.com", message: "این پیام فقط در مرورگر می‌ماند." });
const state = JSON.parse(memory.get("noventix.demo.v1"));
assert.equal(state.user.name, "کاربر نمونه");
assert.equal(state.posts[0].title, "پرسش درباره پایتون");
assert.equal(state.messages.length, 1);
console.log("Demo login, cross-page verification, profile, community and contact passed");
