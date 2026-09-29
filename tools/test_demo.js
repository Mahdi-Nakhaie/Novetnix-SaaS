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
  plans: [{ id: "free", name: "رایگان", price: 0 }, { id: "bronze", name: "برنزی", price: 190000 }],
};

function load(protectedPath) {
  const handlers = {};
  const location = { href: "" };
  const swaps = [];
  const document = {
    body: protectedPath === undefined ? null : { getAttribute: () => protectedPath },
    querySelector: (selector) => (swaps.length && selector === "main" ? swaps[0] : null),
    querySelectorAll: () => [],
    addEventListener: (event, handler) => { handlers[event] = handler; },
    createElement: () => ({ style: {}, insertAdjacentElement: () => {} }),
  };
  const context = {
    window: {
      NOVENTIX_BASE: "/Novetnix-SaaS/site", NOVENTIX_SEED: SEED,
      addEventListener: () => {}, innerHeight: 800, pageYOffset: 0,
    },
    localStorage: storage(memory), sessionStorage: storage(session),
    document, location, setTimeout: (callback) => callback(), Math, Date, String,
  };
  vm.runInNewContext(source, context);
  return { handlers, location, context, swaps };
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
submit(page, "login", { first_name: "کاربر", last_name: "نمونه", phone: "09123456789" });
assert.equal(page.location.href, "/Novetnix-SaaS/site/verify/");
const code = session.get("noventix.demo.code");
assert.match(code, /^\d{6}$/);
page = load();
submit(page, "verify", { code });
assert.equal(page.location.href, "/Novetnix-SaaS/site/panel/student/");
assert.equal(session.has("noventix.demo.code"), false);
submit(page, "profile", { first_name: "کاربر", last_name: "نمونه", email: "user@example.com" });
submit(page, "post", { title: "پرسش درباره پایتون", body: "چطور یک تابع آزمایش‌پذیر بنویسم؟" });
submit(page, "contact", { name: "نام آزمایشی", email: "user@example.com", message: "این پیام فقط در مرورگر می‌ماند." });
let state = JSON.parse(memory.get("noventix.demo.v1"));
assert.equal(state.user.name, "کاربر نمونه");
assert.equal(state.posts[0].title, "پرسش درباره پایتون");
assert.equal(state.messages.length, 1);

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

// activating a demo plan unlocks both gates
lockedProject.handlers.click({
  target: { closest: (selector) => (selector === "[data-demo-plan]" ? { getAttribute: () => "bronze" } : null) },
});
state = JSON.parse(memory.get("noventix.demo.v1"));
assert.equal(state.user.plan, "bronze");
const upgraded = load("project/1");
assert.equal(upgraded.location.href, "");
assert.equal(load("course/ml-basics").location.href, "");
console.log("Demo gate, plan limits, demo activation, login, community and contact passed");
