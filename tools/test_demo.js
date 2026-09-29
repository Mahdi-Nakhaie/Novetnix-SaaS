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
  challenges: ["چالش نمونه"],
  plans: [{ id: "free", name: "رایگان", price: 0 }, { id: "bronze", name: "برنزی", price: 190000 }],
};

function load(protectedPath) {
  const handlers = {};
  const location = { href: "" };
  const swaps = [];
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
    document, location, setTimeout: (callback) => callback(), Math, Date, String,
  };
  vm.runInNewContext(source, context);
  return { handlers, location, context, swaps, terminalLines, nodes };
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

// buying a plan goes through the symbolic checkout, which validates the card first
const checkout = load("panel/student/subscription");
const receipt = { hidden: true, innerHTML: "" };
const planSlot = { textContent: "" };
checkout.nodes["#checkout"] = {
  hidden: true,
  scrollIntoView: () => {},
  querySelector: () => null,
};
checkout.context.document.querySelector = (selector) => checkout.nodes[selector] || null;
checkout.handlers.click({
  target: { closest: (selector) => (selector === "[data-demo-plan]" ? { getAttribute: () => "bronze" } : null) },
});
assert.equal(checkout.nodes["#checkout"].hidden, false);

// a card that fails the Luhn check must not activate anything
submit(checkout, "checkout", {
  plan: "bronze", card: "1234567890123456", holder: "کاربر نمونه",
  month: "05", year: "07", cvv: "123", agree: { checked: true },
});
state = JSON.parse(memory.get("noventix.demo.v1"));
assert.equal(state.user.plan, undefined);

// a structurally valid test card completes the symbolic payment
submit(checkout, "checkout", {
  plan: "bronze", card: "6037997712345678", holder: "کاربر نمونه",
  month: "05", year: "07", cvv: "123", agree: { checked: true },
});
state = JSON.parse(memory.get("noventix.demo.v1"));
assert.equal(state.user.plan, "bronze");
assert.equal(state.payments.length, 1);
assert.equal(state.payments[0].last4, "5678");
assert.equal(state.payments[0].status, "نمایشی");
assert.equal(state.payments[0].card, undefined);
const upgraded = load("project/1");
assert.equal(upgraded.location.href, "");
assert.equal(load("course/ml-basics").location.href, "");
// code workspace: static run report, snippet saved, run counter advances
const workspace = load("panel/student/workspace");
const codeField = { value: "def solve(data):\n    return sum(data)" };
const outputField = { textContent: "" };
workspace.context.document.querySelector = (selector) => {
  if (selector === "[data-code-input]") return codeField;
  if (selector === "[data-code-output]") return outputField;
  return workspace.nodes[selector] || null;
};
workspace.handlers.click({
  target: { closest: (selector) => (selector === "[data-code-run]" ? { closest: () => null } : null) },
});
assert.match(outputField.textContent, /تعریف تابع/);
assert.match(outputField.textContent, /اجرا نمی‌کند/);
state = JSON.parse(memory.get("noventix.demo.v1"));
assert.equal(state.runs, 1);

submit(workspace, "solution", { code: "def solve(data):\n    return sum(data)", challenge: 0 });
state = JSON.parse(memory.get("noventix.demo.v1"));
assert.deepEqual(state.solutions, [0]);
assert.equal(state.snippets[0].challenge, 0);

// Nova replies stay local and are stored with the question
submit(workspace, "nova", { question: "چطور ورودی خالی را مدیریت کنم؟" });
state = JSON.parse(memory.get("noventix.demo.v1"));
assert.equal(state.nova.length, 1);
assert.match(state.nova[0].answer, /ورودی خالی/);

// simulated terminal answers known commands and refuses the rest
workspace.terminalLines.length = 0;
submit(workspace, "terminal", { command: "python main.py" });
assert.ok(workspace.terminalLines.some((line) => line.includes("شبیه‌سازی")));
submit(workspace, "terminal", { command: "rm -rf /" });
assert.ok(workspace.terminalLines.some((line) => line.includes("شناخته نشد")));
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

console.log("Demo gate, plan limits, activation, workspace, terminal, review, chat, Nova and community passed");
