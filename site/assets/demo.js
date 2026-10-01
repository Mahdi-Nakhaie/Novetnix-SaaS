/* Static-build data layer.
 *
 * GitHub Pages cannot run PHP, so the demo stores state in the visitor's own
 * browser and seeds it with the same catalogue the server build ships with.
 * Every write goes through store(); nothing leaves the device.
 */
(function () {
  "use strict";

  var KEY = "noventix.demo.v1";
  var seed = window.NOVENTIX_SEED || { courses: {}, projects: [], plans: [] };
  var plans = seed.plans || [];
  var planOrder = ["free", "bronze", "silver", "gold", "titanium"];
  var planLimits = { free: [0, 0], bronze: [1, 5], silver: [3, 30], gold: [-1, 100], titanium: [-1, 300] };
  var lastCode = sessionStorage.getItem("noventix.demo.code");

  function planById(id) {
    var found = plans.filter(function (p) { return p.id === id; })[0];
    return found || { id: id, name: id, price: 0 };
  }

  function read() {
    try {
      var raw = localStorage.getItem(KEY);
      if (raw) return JSON.parse(raw);
    } catch (e) { /* corrupted or unavailable storage falls through to defaults */ }
    return null;
  }

  function blank() {
    return {
      user: null, enrollments: [], projects: [], challenges: [],
      posts: [], tickets: [], notifications: [], content: [],
      settings: {}, subscriptions: [], messages: [], users: [],
      nova: [], solutions: [], snippets: [], runs: 0,
      chat: [], reviews: [], terminal: [], payments: [], replies: [], follows: [],
      workspaceFiles: { "main.py": "", "README.md": "# Noventix workspace" },
      packages: []
    };
  }

  var state = Object.assign(blank(), read() || {});
  if (!state.workspaceFiles || typeof state.workspaceFiles !== "object") state.workspaceFiles = blank().workspaceFiles;
  if (!Array.isArray(state.packages)) state.packages = blank().packages.slice();
  var activeFile = "main.py";

  function store() {
    try { localStorage.setItem(KEY, JSON.stringify(state)); }
    catch (e) { /* private mode: the page still works, it just forgets */ }
    return state;
  }

  function fa(value) {
    return String(value).replace(/[0-9]/g, function (d) { return "۰۱۲۳۴۵۶۷۸۹"[d]; });
  }

  function el(sel, root) { return (root || document).querySelector(sel); }
  function els(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function esc(value) {
    return String(value).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function jalali(iso) {
    var d = new Date(iso);
    try {
      return new Intl.DateTimeFormat("fa-IR", { year: "numeric", month: "2-digit", day: "2-digit" }).format(d);
    } catch (e) { return d.toISOString().slice(0, 10); }
  }

  function note(form, text, kind) {
    var box = form.parentNode.querySelector(".form-note");
    if (!box) return;
    box.hidden = false;
    box.className = "form-note notice " + (kind || "success");
    box.textContent = text;
  }

  /* ------------------------------------------------------------ account */

  function signIn(phone) {
    var existing = state.users.filter(function (u) { return u.phone === phone; })[0];
    if (!existing) {
      existing = { id: state.users.length + 1, phone: phone, name: "", role: "student", created_at: new Date().toISOString() };
      state.users.push(existing);
    }
    state.user = existing;
    store();
    return existing;
  }

  function isAdmin() { return state.user && state.user.role === "admin"; }

  function accountReady() { return !!(state.user && state.user.phone && state.user.first_name && state.user.last_name); }
  function currentPlan() { return state.user && state.user.plan && planOrder.indexOf(state.user.plan) >= 0 ? state.user.plan : "free"; }
  function canProject(index) {
    var item = seed.projects[index];
    return accountReady() && item && planOrder.indexOf(currentPlan()) >= planOrder.indexOf(item.plan);
  }
  function canCourse(slug) {
    return accountReady() && (seed.paid_courses.indexOf(slug) < 0 || currentPlan() !== "free");
  }

  function guardPage() {
    var path = document.body && document.body.getAttribute("data-protected");
    if (path === null || path === undefined) return;
    if (path.indexOf("panel/admin") === 0) {
      location.href = join(accountReady() ? "panel/student/" : "login/");
      return;
    }
    if (!accountReady()) {
      sessionStorage.setItem("noventix.demo.return", path);
      location.href = join("login/");
      return;
    }
    var project = /^project\/(\d+)$/.exec(path);
    var course = /^course\/(.+)$/.exec(path);
    if ((project && !canProject(Number(project[1]))) || (course && !canCourse(course[1]))) {
      location.href = join("panel/student/subscription/");
    }
  }

  function paintPlan() {
    var id = currentPlan();
    var slot = el("[data-current-plan]");
    if (slot) slot.textContent = planById(id).name;
    slot = el("[data-plan-expiry]");
    if (slot) slot.textContent = id === "free" ? "اشتراک پولی فعالی ثبت نشده است." : "پایان دوره آزمایشی: " + jalali(state.user.plan_expires);
    slot = el("[data-plan-credits]");
    if (slot) slot.textContent = fa(planLimits[id][1]);
    slot = el("[data-plan-projects]");
    if (slot) slot.textContent = planLimits[id][0] < 0 ? "نامحدود" : fa(planLimits[id][0]);
  }

  /* Without a worker or network the browser runs nothing, so the click still reports progress. */
  function runnerMissing() {
    return { ok: false, output: "موتور Python بارگیری نشد؛ اتصال اینترنت را بررسی و صفحه را دوباره باز کنید." };
  }

  var TERMINAL_HELP = [
    "فرمان‌های پشتیبانی‌شده در میزکار مجازی:",
    "  ls [مسیر]                 فهرست فایل‌ها",
    "  pwd                       نمایش مسیر مجازی",
    "  tree                      نمایش درخت فایل‌ها",
    "  cat <فایل>                خواندن فایل",
    "  touch <فایل>              ساخت فایل خالی",
    "  write <فایل> <متن>        نوشتن متن در فایل",
    "  mkdir <پوشه>              ساخت پوشه مجازی",
    "  rm <فایل>                 حذف فایل مجازی",
    "  python <فایل.py>          اجرای فایل Python",
    "  pip list                  فهرست بسته‌های فعال",
    "  pip install <بسته>        افزودن بسته سازگار با Pyodide",
    "  clear · help              پاک‌کردن و راهنما"
  ].join("\\n");
  var PYODIDE_PACKAGES = ["numpy", "pandas", "matplotlib", "scipy", "sympy", "scikit-learn", "micropip", "pytest", "pyyaml"];

  function workspaceFiles() { return state.workspaceFiles || (state.workspaceFiles = {}); }
  function fileNames() { return Object.keys(workspaceFiles()).sort(); }
  function saveWorkspace() { store(); paintFiles(); }
  function saveCurrentFile(card) {
    var source = el("[data-code-input]", card || document);
    if (source) workspaceFiles()[activeFile] = source.value;
  }
  function paintFiles() {
    var tree = el("[data-file-tree]");
    if (!tree) return;
    tree.replaceChildren();
    fileNames().forEach(function (name) {
      var button = document.createElement("button");
      button.type = "button";
      button.className = "file-item" + (name === activeFile ? " is-active" : "");
      button.setAttribute("data-file-select", name);
      button.setAttribute("role", "option");
      button.textContent = name;
      tree.appendChild(button);
    });
    var nameSlot = el("[data-file-name]");
    if (nameSlot) nameSlot.textContent = activeFile;
    var packages = el("[data-package-status]");
    if (packages) packages.textContent = "بسته‌های فعال: " + state.packages.join(", ");
  }
  function validVirtualPath(path) {
    return !!path && /^(?!\.)(?:[A-Za-z0-9_./-])+$/.test(path) && path.indexOf("..") < 0 && path[0] !== "/";
  }
  function installPackage(name) {
    name = String(name || "").toLowerCase();
    if (!/^[a-z0-9][a-z0-9._-]*$/.test(name)) return "نام بسته معتبر نیست.";
    if (PYODIDE_PACKAGES.indexOf(name) < 0) return "این بسته در محیط Pyodide این میزکار پشتیبانی نمی‌شود. بسته‌های پیشنهادی: " + PYODIDE_PACKAGES.join(", ");
    if (state.packages.indexOf(name) < 0) state.packages.push(name);
    saveWorkspace();
    return "بسته «" + name + "» برای اجرای بعدی آماده شد.";
  }
  function runFile(name) {
    var source = el("[data-code-input]");
    var run = el("[data-code-run]");
    if (!validVirtualPath(name) || !/\.py$/i.test(name)) return "فقط فایل Python معتبر قابل اجراست.";
    if (source && run && name !== activeFile) {
      workspaceFiles()[activeFile] = source.value;
      activeFile = name;
      source.value = workspaceFiles()[name] || "";
      paintFiles();
    }
    if (run && !run.disabled) { appendTerminal("خروجی " + name + " در بخش خروجی میزکار نمایش داده می‌شود.", "term-out"); run.click(); return null; }
    return "اجرای کد در حال انجام است؛ پس از پایان دوباره تلاش کنید.";
  }

  function runCommand(raw) {
    var host = el("[data-terminal-log]");
    if (!host) return;
    var command = String(raw || "").trim();
    var body;
    if (!command) return;
    if (command === "clear") { host.replaceChildren(); return; }
    appendTerminal("$ " + command, "term-cmd");
    state.terminal = state.terminal.concat([{ command: command, at: new Date().toISOString() }]).slice(-40);
    store(); paintStats();
    var parts = command.match(/(?:[^\s"]+|"[^"]*")+/g) || [];
    var verb = parts[0] || "";
    if (verb === "help" || verb === "--help") body = TERMINAL_HELP;
    else if (verb === "pwd") body = "/workspace";
    else if (verb === "ls") body = fileNames().join("\\n") || "میزکار خالی است.";
    else if (verb === "tree") body = fileNames().map(function (name) { return "├── " + name; }).join("\\n") || "میزکار خالی است.";
    else if (verb === "cat") body = validVirtualPath(parts[1]) && Object.prototype.hasOwnProperty.call(workspaceFiles(), parts[1]) ? workspaceFiles()[parts[1]] : "فایل پیدا نشد.";
    else if (verb === "touch") {
      var touch = parts[1];
      body = validVirtualPath(touch) ? (workspaceFiles()[touch] = workspaceFiles()[touch] || "", saveWorkspace(), "فایل ساخته شد: " + touch) : "نام فایل مجازی معتبر نیست.";
    } else if (verb === "mkdir") {
      var folder = parts[1]; body = validVirtualPath(folder) ? "پوشه مجازی آماده شد: " + folder : "نام پوشه مجازی معتبر نیست.";
    } else if (verb === "rm" && /^rm\s+-/.test(command)) body = "این فرمان پشتیبانی نمی‌شود؛ فقط حذف فایل مجازی مجاز است.";
    else if (verb === "rm") {
      var remove = parts[1]; body = validVirtualPath(remove) && Object.prototype.hasOwnProperty.call(workspaceFiles(), remove) ? (delete workspaceFiles()[remove], activeFile === remove && (activeFile = "main.py"), saveWorkspace(), "فایل حذف شد: " + remove) : "فایل پیدا نشد.";
    } else if (verb === "write") {
      var target = parts[1]; var text = parts.slice(2).join(" ").replace(/^"|"$/g, "");
      body = validVirtualPath(target) ? (workspaceFiles()[target] = text, saveWorkspace(), "فایل ذخیره شد: " + target) : "نام فایل مجازی معتبر نیست.";
    } else if (verb === "python" && parts[1]) body = runFile(parts[1]);
    else if (verb === "pip" && parts[1] === "list") body = state.packages.join("\\n");
    else if (verb === "pip" && parts[1] === "install" && parts[2]) body = installPackage(parts[2]);
    else if (verb === "python" && parts[1] === "-m" && parts[2] === "pip" && parts[3] === "install" && parts[4]) body = installPackage(parts[4]);
    else body = "فرمان پشتیبانی نمی‌شود: " + command + "\\nبرای راهنما help را اجرا کنید.";
    if (body) appendTerminal(body, "term-out");
  }

  function appendTerminal(text, css) {
    var host = el("[data-terminal-log]");
    if (!host) return;
    var hint = el(".term-hint", host);
    if (hint) hint.remove();
    text.split("\n").forEach(function (line) {
      var span = document.createElement("span");
      span.className = "term-line " + css;
      span.textContent = line;
      host.appendChild(span);
    });
    host.scrollTop = host.scrollHeight;
  }

  /* Rule-based review: no model runs, the checks are deterministic. */
  function reviewCode(code) {
    var lines = code.split("\n");
    var findings = [];
    var add = function (level, text) { findings.push({ level: level, text: text }); };

    if (!/\bdef\s+\w+\s*\(/.test(code)) add("warn", "هیچ تابعی تعریف نشده است؛ منطق را در یک تابع با نام گویا بگذار.");
    else add("ok", "تعریف تابع پیدا شد.");

    if (/\bdef\s+\w+\s*\(/.test(code) && !/\breturn\b/.test(code)) add("bad", "تابع بدون return است؛ خروجی مشخصی برنمی‌گرداند.");
    if (/\bprint\s*\(/.test(code)) add("warn", "print برای دیباگ مناسب است، اما خروجی نهایی را با return برگردان.");
    if ((code.match(/\bif\b/g) || []).length && !/\b(else|elif)\b/.test(code)) add("info", "فقط یک شاخه شرطی دیده شد؛ حالت‌های دیگر را هم پوشش بده.");
    if (/\bexcept\s*:/.test(code)) add("bad", "except بدون نوع خطا، خطاهای واقعی را پنهان می‌کند.");
    if (/\bexcept\b/.test(code) && !/\braise\b/.test(code) && !/\blog|print/.test(code)) add("warn", "در بخش except خطا را بی‌صدا رد می‌کنی؛ حداقل آن را ثبت کن.");
    if (/\bTODO\b|\n\s*pass\s*\n|\n\s*pass$/.test(code)) add("bad", "بخش ناتمام (TODO یا pass) در کد باقی مانده است.");
    if (/\bfor\s+\w+\s+in\s+range\(len\(/.test(code)) add("info", "به‌جای range(len(...)) می‌توانی مستقیم روی عناصر پیمایش کنی.");
    if (lines.some(function (l) { return l.length > 100; })) add("info", "خط بلندتر از ۱۰۰ نویسه دیده شد؛ خوانایی را بهتر کن.");
    if (!/(==|!=|<=|>=|<|>)/.test(code)) add("info", "هیچ مقایسه‌ای در کد نیست؛ حالت‌های مرزی را بررسی کن.");
    add("info", "پیشنهاد آزمون: ورودی عادی، ورودی خالی و ورودی نامعتبر را جدا بسنج.");
    add("info", "در پایان یک آزمون کوچک بنویس تا رفتار تابع تثبیت شود.");
    return findings;
  }

  function paintReview(findings) {
    var host = el("[data-review-output]");
    if (!host) return;
    var list = findings || (state.reviews[0] && state.reviews[0].findings) || [];
    if (!list.length) { host.hidden = true; return; }
    var labels = { ok: "درست", warn: "هشدار", bad: "باید اصلاح شود", info: "پیشنهاد" };
    host.innerHTML = '<h3>نتیجه بررسی</h3><ul class="review-list">' + list.map(function (item) {
      return '<li class="review-' + item.level + '"><span class="review-tag">' + labels[item.level] + '</span>' + esc(item.text) + '</li>';
    }).join("") + '</ul>';
    host.hidden = false;
  }

  function paintChat() {
    var host = el("[data-chat-log]");
    if (!host) return;
    if (!state.chat.length) {
      host.innerHTML = '<div class="chat-empty" data-chat-empty>هنوز پیامی در گفت‌وگوی زنده نیست. اولین نفر باشید.</div>';
      return;
    }
    host.innerHTML = state.chat.map(function (m) {
      return '<div class="chat-msg' + (m.mine ? " is-mine" : "") + '"><div class="chat-meta"><strong>' + esc(m.author) +
        '</strong><small>' + jalali(m.created_at) + '</small></div><p>' + esc(m.body).replace(/\n/g, "<br>") + '</p></div>';
    }).join("");
    host.scrollTop = host.scrollHeight;
  }

  /* ------------------------------------------------------------ actions */

  var actions = {
    login: function (form) {
      var first = (form.elements.namedItem("first_name").value || "").trim();
      var last = (form.elements.namedItem("last_name").value || "").trim();
      if (first.length < 2 || last.length < 2 || first.length > 50 || last.length > 50) {
        note(form, "نام و نام خانوادگی معتبر وارد کنید.", "error"); return;
      }
      var phone = (form.elements.namedItem("phone").value || "").replace(/[۰-۹٠-٩]/g, function (d) { return "۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩".indexOf(d) % 10; })
        .replace(/[\s-]/g, "").replace(/^(\+98|98)/, "0");
      if (!/^09[0-9]{9}$/.test(phone)) { note(form, "شماره موبایل معتبر وارد کنید.", "error"); return; }
      var passwordField = form.elements.namedItem("password");
      var password = passwordField ? String(passwordField.value || "") : "";
      if (passwordField && (!/^[\s\S]{8,72}$/.test(password) || !/[A-Za-z]/.test(password) || !/[0-9]/.test(password))) {
        note(form, "رمز باید ۸ تا ۷۲ نویسه و شامل حرف انگلیسی و عدد باشد.", "error"); return;
      }
      lastCode = String(Math.floor(100000 + Math.random() * 900000));
      state.pending_phone = phone;
      state.pending_name = { first: first, last: last };
      state.pending_password = password;
      sessionStorage.setItem("noventix.demo.code", lastCode);
      store();
      location.href = join("verify/");
    },

    signin: function (form) {
      var phone = (form.elements.namedItem("phone").value || "").replace(/[۰-۹٠-٩]/g, function (d) { return "۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩".indexOf(d) % 10; }).replace(/[\s-]/g, "").replace(/^(\+98|98)/, "0");
      var password = form.elements.namedItem("password").value || "";
      var captcha = form.elements.namedItem("captcha").value || "";
      var expected = sessionStorage.getItem("noventix.demo.captcha") || "";
      var found = state.users.filter(function (u) { return u.phone === phone; })[0];
      if (!found || found.password !== password) { note(form, "شماره موبایل یا رمز عبور صحیح نیست.", "error"); return; }
      if (captcha.toUpperCase() !== expected) { note(form, "کد کپچا صحیح نیست.", "error"); return; }
      state.user = found; store(); sessionStorage.removeItem("noventix.demo.captcha");
      location.href = join(found.role === "admin" ? "panel/admin/" : "panel/student/");
    },

    verify: function (form) {
      var code = (form.elements.namedItem("code").value || "").replace(/[۰-۹]/g, function (d) { return "۰۱۲۳۴۵۶۷۸۹".indexOf(d); });
      if (!lastCode || code !== lastCode || !state.pending_phone) { note(form, "کد واردشده صحیح نیست.", "error"); return; }
      var user = signIn(state.pending_phone);
      user.first_name = state.pending_name.first;
      user.last_name = state.pending_name.last;
      user.name = user.first_name + " " + user.last_name;
      if (state.pending_password) user.password = state.pending_password;
      sessionStorage.removeItem("noventix.demo.code");
      lastCode = null;
      delete state.pending_phone;
      delete state.pending_name;
      delete state.pending_password;
      store();
      note(form, user.role === "admin" ? "خوش آمدید؛ ورود به پنل مدیریت…" : "خوش آمدید!", "success");
      var target = sessionStorage.getItem("noventix.demo.return") || (user.role === "admin" ? "panel/admin" : "panel/student");
      sessionStorage.removeItem("noventix.demo.return");
      setTimeout(function () { location.href = join(target.replace(/\/$/, "") + "/"); }, 700);
    },

    profile: function (form) {
      if (!state.user) return;
      var first = (form.elements.namedItem("first_name").value || "").trim();
      var last = (form.elements.namedItem("last_name").value || "").trim();
      if (first.length < 2 || last.length < 2 || first.length > 50 || last.length > 50) { note(form, "نام و نام خانوادگی معتبر وارد کنید.", "error"); return; }
      state.user.first_name = first;
      state.user.last_name = last;
      state.user.name = first + " " + last;
      state.user.email = (form.elements.namedItem("email").value || "").trim();
      store();
      paintProfile();
      note(form, "پروفایل ذخیره شد.", "success");
    },

    contact: function (form) {
      var name = (form.elements.namedItem("name").value || "").trim();
      var email = (form.elements.namedItem("email").value || "").trim();
      var message = (form.elements.namedItem("message").value || "").trim();
      if (name.length < 2 || email.indexOf("@") < 1 || message.length < 10) {
        note(form, "نام، ایمیل و پیام معتبر وارد کنید.", "error"); return;
      }
      state.messages.push({ name: name, email: email, message: message, created_at: new Date().toISOString() });
      store();
      form.reset();
      note(form, "پیام شما ثبت شد.", "success");
    },

    post: function (form) {
      var title = (form.elements.namedItem("title").value || "").trim();
      var body = (form.elements.namedItem("body").value || "").trim();
      if (title.length < 5 || title.length > 180) { note(form, "عنوان باید بین ۵ تا ۱۸۰ نویسه باشد.", "error"); return; }
      if (body.length < 10 || body.length > 5000) { note(form, "متن باید بین ۱۰ تا ۵۰۰۰ نویسه باشد.", "error"); return; }
      state.posts.unshift({
        id: Date.now(), title: title, body: body,
        author: (state.user && state.user.name) || "کاربر Noventix",
        created_at: new Date().toISOString(), likes: 0, saves: 0, replies: []
      });
      store();
      form.reset();
      note(form, "پیام شما در انجمن ثبت شد.", "success");
      paintPosts();
    },

    ticket: function (form) {
      var subject = (form.elements.namedItem("subject").value || "").trim();
      var body = (form.elements.namedItem("body").value || "").trim();
      if (subject.length < 5 || subject.length > 180 || body.length < 10) {
        note(form, "موضوع، متن و اولویت معتبر وارد کنید.", "error"); return;
      }
      state.tickets.unshift({ id: Date.now(), subject: subject, body: body, replies: [], priority: form.elements.namedItem("priority").value, status: "open", created_at: new Date().toISOString() });
      store();
      form.reset();
      note(form, "تیکت شما ثبت شد.", "success");
      paintTickets();
    },

    announcement: function (form) {
      var title = (form.elements.namedItem("title").value || "").trim();
      var body = (form.elements.namedItem("body").value || "").trim();
      if (title.length < 3 || body.length < 5) { note(form, "عنوان و متن اعلان معتبر وارد کنید.", "error"); return; }
      state.notifications.unshift({ title: title, body: body, created_at: new Date().toISOString() });
      store();
      form.reset();
      note(form, "اعلان برای کاربران ارسال شد.", "success");
      paintAnnouncements();
    },

    content: function (form) {
      var title = (form.elements.namedItem("title").value || "").trim();
      if (title.length < 3 || title.length > 180) { note(form, "عنوان باید بین ۳ تا ۱۸۰ نویسه باشد.", "error"); return; }
      var draft = !!form.elements.namedItem("topic");
      state.content.unshift({ title: title, kind: form.elements.namedItem("kind").value,
        topic: draft ? form.elements.namedItem("topic").value.trim() : "",
        status: draft ? "پیش‌نویس" : "منتشرشده", created_at: new Date().toISOString() });
      store();
      form.reset();
      note(form, draft ? "مقاله به فهرست مقاله‌های من اضافه شد." : "محتوا ذخیره و منتشر شد.", "success");
      paintContent();
    },

    nova: function (form) {
      note(form, "Nova فقط در نسخهٔ سروری فعال است؛ در این نسخه پاسخی تولید نمی‌شود.", "error");
    },

    solution: function (form) {
      var code = (form.elements.namedItem("code").value || "").trim();
      if (code.length < 20) { note(form, "راه‌حل باید دست‌کم ۲۰ نویسه باشد.", "error"); return; }
      var index = Number(form.elements.namedItem("challenge").value);
      if (state.solutions.indexOf(index) < 0) state.solutions.push(index);
      if (state.challenges.indexOf(index) < 0) state.challenges.push(index);
      state.snippets.unshift({ challenge: index, code: code, created_at: new Date().toISOString() });
      store();
      note(form, "راه‌حل ثبت شد؛ چالش به فهرست شما اضافه شد.", "success");
      paintStats(); paintChallenges(); paintSnippets();
    },

    terminal: function (form) {
      var input = form.elements.namedItem("command");
      runCommand(input ? input.value : "");
      if (input) input.value = "";
      if (input) input.focus();
    },

    subscription: function (form) {
      if (!accountReady()) { location.href = join("login/"); return; }
      var plan = form.elements.namedItem("plan").value;
      if (planOrder.indexOf(plan) < 1) { note(form, "پلن انتخاب‌شده معتبر نیست.", "error"); return; }
      state.user.plan = plan;
      state.user.plan_expires = new Date(Date.now() + 30 * 86400000).toISOString();
      store();
      note(form, "پلن " + planById(plan).name + " برای ۳۰ روز فعال شد.", "success");
      paintPlan(); paintStats();
    },

    review: function (form) {
      var code = (form.elements.namedItem("code").value || "").trim();
      if (code.length < 20) { note(form, "کد باید دست‌کم ۲۰ نویسه باشد.", "error"); return; }
      var findings = reviewCode(code);
      state.reviews.unshift({ code: code, findings: findings, created_at: new Date().toISOString() });
      store();
      note(form, "بررسی انجام شد.", "success");
      paintReview(findings);
      paintStats();
    },

    chat: function (form) {
      if (!accountReady()) { location.href = join("login/"); return; }
      var input = form.elements.namedItem("message");
      var message = (input.value || "").trim();
      if (!message) { note(form, "پیام خالی است.", "error"); return; }
      if (message.length > 500) { note(form, "پیام باید کمتر از ۵۰۰ نویسه باشد.", "error"); return; }
      state.chat.push({
        author: state.user.name, body: message,
        created_at: new Date().toISOString(), mine: true
      });
      store();
      form.reset();
      paintChat();
      paintStats();
    },

    setting: function (form) {
      state.settings[form.elements.namedItem("key").value] = (form.elements.namedItem("value").value || "").trim();
      store();
      note(form, "تنظیم ذخیره شد.", "success");
    }
  };

  /* ----------------------------------------------------------- painting */

  function paintProfile() {
    if (!state.user) return;
    els("[data-profile-name]").forEach(function (n) { n.textContent = state.user.name || "کاربر Noventix"; });
    els("[data-profile-phone]").forEach(function (n) { n.textContent = state.user.phone + " · Level ۱۲ · XP ۲٬۴۸۰"; });
    els("[data-profile-phone-input]").forEach(function (n) { n.value = state.user.phone; });
    var first = el('[data-form="profile"] [name="first_name"]');
    var last = el('[data-form="profile"] [name="last_name"]');
    if (first) first.value = state.user.first_name || "";
    if (last) last.value = state.user.last_name || "";
    els("[data-avatar]").forEach(function (n) { n.textContent = (state.user.name || state.user.phone).slice(0, 1); });
  }

  function paintStats() {
    var map = {
      enrollments: state.enrollments.length,
      projects: state.projects.length,
      posts: state.posts.length,
      users: state.users.length,
      subs: state.subscriptions.length,
      messages: state.messages.length,
      users30: state.users.length,
      posts30: state.posts.length,
      projects_all: state.projects.length,
      enroll_all: state.enrollments.length,
      xp: 2480 + state.challenges.length * 120,
      streak: 7 + state.projects.length,
      runs: state.runs,
      terminal_runs: (state.terminal || []).length,
      chat_messages: state.chat.length,
      reviews: state.reviews.length,
      solved: state.solutions.length,
      snippets: state.snippets.length,
      nova_today: state.nova.length,
      nova_open: Math.max(0, state.nova.length - state.solutions.length),
      plan_credits: planLimits[currentPlan()][1],
      week_now: state.runs + state.solutions.length + (state.terminal || []).length
    };
    els("[data-stat]").forEach(function (n) {
      var key = n.getAttribute("data-stat");
      if (key in map) n.textContent = fa(map[key]);
    });
    els("[data-open-tickets]").forEach(function (n) {
      n.textContent = fa(state.tickets.filter(function (t) { return t.status === "open"; }).length);
    });
    els("[data-plan-count]").forEach(function (n) {
      var plan = n.getAttribute("data-plan-count");
      n.textContent = fa(state.subscriptions.filter(function (s) { return planById(s.plan).name === plan; }).length);
    });
  }

  function paintEnrollments() {
    var host = el("[data-enrollments]");
    if (!host) return;
    if (!state.enrollments.length) {
      host.innerHTML = '<div class="empty-state">هنوز مقاله‌ای اضافه نکردی. <a href="' + join("courses/") + '">مقاله‌ها را ببین ←</a></div>';
      return;
    }
    host.innerHTML = state.enrollments.map(function (slug) {
      var c = seed.courses[slug];
      if (!c) return "";
      return '<div class="list-item"><div><span class="pill">' + esc(c.topic) + '</span><h3>' + esc(c.title) +
        '</h3></div><a href="' + join("course/" + slug + "/") + '">ادامه مطالعه ←</a></div>';
    }).join("");
  }

  function paintMyChallenges() {
    var host = el("[data-my-challenges]");
    if (!host) return;
    if (!state.challenges.length) {
      host.innerHTML = '<div class="empty-state">هنوز چالشی اضافه نکردی. <a href="' + join("panel/student/challenges/") + '">چالش‌ها را ببین ←</a></div>';
      return;
    }
    host.innerHTML = state.challenges.map(function (i) {
      var title = seed.challenges ? seed.challenges[i] : null;
      if (!title) return "";
      var solved = state.solutions.indexOf(i) >= 0;
      return '<div class="list-item"><div><span class="pill">' + (solved ? "حل‌شده" : "در انتظار") + '</span><h3>' +
        esc(title) + '</h3></div><a href="' + join("panel/student/challenge/" + i + "/") + '">' +
        (solved ? "مشاهده راه‌حل ←" : "ادامه چالش ←") + '</a></div>';
    }).join("");
  }

  function paintMyProjects() {
    var host = el("[data-my-projects]");
    if (!host) return;
    if (!state.projects.length) {
      host.innerHTML = '<div class="empty-state">هنوز پروژه‌ای شروع نکردی. <a href="' + join("projects/") + '">پروژه‌ها را ببین ←</a></div>';
      return;
    }
    host.innerHTML = state.projects.map(function (i) {
      var p = seed.projects[i];
      if (!p) return "";
      return '<div class="list-item"><div><span class="pill">' + esc(p.topic) + '</span><h3>' + esc(p.title) +
        '</h3><p>' + esc(p.description) + '</p></div><a href="' + join("project/" + i + "/") + '">جزئیات ←</a></div>';
    }).join("");
  }

  function postCard(p, withActions) {
    var replies = p.replies || [];
    var author = p.author || "کاربر";
    var out = '<article class="post-card"><div class="meta-row"><button type="button" class="profile-link" data-member-profile="' + esc(author) + '">' + esc(author) + '</button><span>' + jalali(p.created_at) + '</span></div><div class="member-preview" data-member-card="' + esc(author) + '" hidden><strong>' + esc(author) + '</strong><span>' + fa(state.posts.filter(function (item) { return item.author === author; }).length) + ' پست در انجمن</span>' + (state.user && state.user.name !== author ? '<button type="button" class="btn btn-small btn-outline" data-follow-user="' + esc(author) + '">' + (state.follows.indexOf(author) >= 0 ? 'دنبال می‌کنید' : 'دنبال کردن') + '</button>' : '') + '</div><h2>' + esc(p.title) + '</h2><p>' + esc(p.body).replace(/\n/g, "<br>") + '</p>';
    if (withActions) {
      out += '<div class="react-row"><button type="button" class="text-button" data-like="' + p.id + '">پسندیدن ' + fa(p.likes) + '</button>' +
        '<button type="button" class="text-button" data-save="' + p.id + '">ذخیره ' + fa(p.saves) + '</button>' +
        '<button type="button" class="text-button" data-comment-toggle="' + p.id + '">نظر ' + fa(replies.length) + '</button></div><form class="comment-form" data-comment-form="' + p.id + '" hidden><textarea name="body" minlength="2" maxlength="2000" required placeholder="نظر خود را بنویسید…"></textarea><button type="submit" class="btn btn-small btn-outline">ارسال نظر</button></form>';
      if (replies.length) out += '<div class="comment-list">' + replies.map(function (r) { return '<div class="comment"><strong>' + esc(r.author) + '</strong><p>' + esc(r.body) + '</p></div>'; }).join("") + '</div>';
    }
    return out + '</article>';
  }

  function paintPosts() {
    [el("[data-community-list]"), el("[data-panel-posts]")].forEach(function (host) {
      if (!host) return;
      if (!state.posts.length) {
        host.innerHTML = '<div class="empty-state">هنوز گفت‌وگویی ثبت نشده است. اولین نفر باشید.</div>';
        return;
      }
      host.innerHTML = state.posts.map(function (p) { return postCard(p, true); }).join("");
    });
  }

  function paintTickets() {
    var host = el("[data-tickets]");
    if (!host) return;
    if (!state.tickets.length) {
      host.innerHTML = '<div class="empty-state">هنوز تیکتی ثبت نکرده‌ای.</div>';
      return;
    }
    host.innerHTML = state.tickets.map(function (t) {
      return '<div class="ticket-card"><div class="meta-row"><span class="pill">' + esc(t.priority) + '</span><span>' +
        (t.status === "open" ? "باز" : "بسته") + '</span><span>' + jalali(t.created_at) + '</span></div><h3>' + esc(t.subject) + '</h3><div class="ticket-thread"><div class="ticket-message is-user"><strong>شما</strong><p>' + esc(t.body) + '</p></div>' + (t.replies || []).map(function (r) { return '<div class="ticket-message"><strong>' + esc(r.author) + '</strong><p>' + esc(r.body) + '</p></div>'; }).join("") + '</div><form class="ticket-reply-form" data-ticket-reply="' + t.id + '"><textarea name="body" required minlength="2" maxlength="5000" placeholder="پاسخ خود را بنویسید…"></textarea><button type="submit" class="btn btn-small btn-outline">ارسال پاسخ</button></form></div>';
    }).join("");
  }

  function paintAnnouncements() {
    var host = el("[data-announcements]");
    if (!host || !state.notifications.length) return;
    if (!host.dataset.initialMarkup) host.dataset.initialMarkup = host.innerHTML;
    host.innerHTML = state.notifications.map(function (a) {
      return '<div class="announce-card"><div><h2>' + esc(a.title) + '</h2><p>' + esc(a.body) + '</p></div><small>' + jalali(a.created_at) + '</small></div>';
    }).join("") + host.dataset.initialMarkup;
  }

  function paintContent() {
    var host = el("[data-content-list]");
    if (!host || !state.content.length) return;
    if (!host.dataset.initialMarkup) host.dataset.initialMarkup = host.innerHTML;
    host.innerHTML = state.content.map(function (c) {
      return '<div class="content-row"><div><h3>' + esc(c.title) + '</h3><span class="muted">' + esc(c.kind) + ' · ' + esc(c.status) + '</span></div><div class="content-actions"><span class="icon-btn" aria-hidden="true">✎</span></div></div>';
    }).join("") + host.dataset.initialMarkup;
  }

  function paintUsers() {
    var host = el("[data-users-table]");
    if (host) {
      host.innerHTML = state.users.length
        ? state.users.map(function (u) {
            return '<tr><td>' + esc(u.name || "—") + '</td><td dir="ltr">' + esc(u.phone) + '</td><td>' + esc(u.role) + '</td><td>' + jalali(u.created_at) + '</td></tr>';
          }).join("")
        : '<tr><td colspan="4" class="muted">هنوز کاربری ثبت نشده است.</td></tr>';
    }
    var fresh = el("[data-users-new]");
    if (fresh) {
      fresh.innerHTML = state.users.length
        ? state.users.map(function (u) {
            return '<tr><td>' + esc(u.name || "—") + '</td><td dir="ltr">' + esc(u.phone) + '</td><td>' + jalali(u.created_at) + '</td></tr>';
          }).join("")
        : '<tr><td colspan="3" class="muted">هنوز کاربری ثبت نشده است.</td></tr>';
    }
  }

  function paintSubscriptions() {
    var rows = state.subscriptions.map(function (s) {
      var p = planById(s.plan);
      return { phone: s.phone, plan: p.name, price: p.price, status: s.status, expires: s.expires_at };
    });
    var subsTable = el("[data-subs-table]");
    if (subsTable) {
      subsTable.innerHTML = rows.length
        ? rows.map(function (r) {
            return '<tr><td dir="ltr">' + esc(r.phone) + '</td><td>' + esc(r.plan) + '</td><td>' + esc(r.status) + '</td><td>' + jalali(r.expires) + '</td></tr>';
          }).join("")
        : '<tr><td colspan="4" class="muted">هنوز اشتراکی ثبت نشده است.</td></tr>';
    }
    var payTable = el("[data-payments-table]");
    if (payTable) {
      payTable.innerHTML = rows.length
        ? rows.map(function (r) {
            return '<tr><td dir="ltr">' + esc(r.phone) + '</td><td>' + esc(r.plan) + '</td><td>' + fa(r.price.toLocaleString("en-US")) + ' تومان</td><td>' + esc(r.status) + '</td><td>' + jalali(r.expires) + '</td></tr>';
          }).join("")
        : '<tr><td colspan="5" class="muted">هنوز پرداختی ثبت نشده است.</td></tr>';
    }

    var studentPay = el("[data-student-payments]");
    if (studentPay) {
      studentPay.innerHTML = rows.length
        ? rows.map(function (r) {
            return '<tr><td>' + esc(r.plan) + '</td><td>' + fa(r.price.toLocaleString("en-US")) + ' تومان</td><td>' + esc(r.status) + '</td><td>' + jalali(r.expires) + '</td></tr>';
          }).join("")
        : '<tr><td colspan="4" class="muted">هنوز پرداختی ثبت نشده است.</td></tr>';
    }

    var ownPay = el("[data-subscription-payments]");
    if (ownPay) {
      ownPay.innerHTML = state.payments.length
        ? state.payments.map(function (p) {
            return '<tr><td>' + esc(planById(p.plan).name) + '</td><td>' + fa(p.amount.toLocaleString("en-US")) + ' تومان</td><td>' + esc(p.status) + '</td><td>' + jalali(p.created_at) + '</td></tr>';
          }).join("")
        : '<tr><td colspan="4" class="muted">هنوز پرداختی ثبت نشده است.</td></tr>';
    }
  }

  function paintNova() {
    var host = el("[data-nova-thread]");
    if (!host) return;
    if (!state.nova.length) {
      host.innerHTML = '<div class="empty-state">هنوز گفت‌وگویی با Nova ثبت نشده است. اولین سؤال را بپرس.</div>';
      return;
    }
    host.innerHTML = state.nova.map(function (item) {
      return '<div class="chat-bubble is-user"><p>' + esc(item.question) + '</p><small>' + jalali(item.created_at) + '</small></div>' +
        '<div class="chat-bubble is-nova"><span class="chip">Nova</span><p>' + esc(item.answer) + '</p></div>';
    }).join("");
  }

  function paintSnippets() {
    var host = el("[data-snippets]");
    if (!host) return;
    if (!state.snippets.length) {
      host.innerHTML = '<div class="empty-state">هنوز قطعه‌کدی ذخیره نکرده‌ای.</div>';
      return;
    }
    host.innerHTML = state.snippets.map(function (item) {
      var ch = seed.challenges ? seed.challenges[item.challenge] : null;
      var label = ch ? ch : "چالش " + fa(item.challenge + 1);
      return '<div class="list-item"><div><span class="pill">' + esc(label) + '</span>' +
        '<pre class="code-block" dir="ltr"><code>' + esc(item.code) + '</code></pre></div><small>' + jalali(item.created_at) + '</small></div>';
    }).join("");
  }

  function paintChallenges() {
    els("[data-challenge]").forEach(function (btn) {
      var index = Number(btn.getAttribute("data-challenge"));
      if (state.challenges.indexOf(index) < 0) return;
      btn.outerHTML = '<span class="btn btn-outline full is-done">در فهرست شما ✓</span>';
    });
  }

  function paintChallengeNotes() {
    els("[data-challenge-note]").forEach(function (note) {
      var form = note.closest(".panel-card");
      var code = form ? el('[name="code"]', form) : null;
      if (code && code.value) return;
      var index = Number(note.getAttribute("data-challenge-note"));
      var done = state.solutions.indexOf(index) >= 0;
      note.hidden = false;
      note.className = "form-note notice " + (done ? "success" : "info");
      note.textContent = done
        ? "راه‌حل این چالش ثبت شده است؛ می‌توانی نسخه بهتری هم اضافه کنی."
        : "پس از نوشتن راه‌حل و پاس‌شدن آزمون‌های پذیرش، آن را ثبت کن.";
    });
  }

  function paintAll() {
    paintProfile(); paintStats(); paintEnrollments(); paintMyProjects(); paintMyChallenges();
    paintPosts(); paintTickets(); paintAnnouncements(); paintContent();
    paintUsers(); paintSubscriptions(); paintChallenges(); paintPlan();
    paintNova(); paintSnippets(); paintChat(); paintReview(); paintChallengeNotes();
  }

  /* ------------------------------------------------------------- wiring */

  function join(path) {
    var base = (window.NOVENTIX_BASE || "").replace(/\/$/, "");
    return base + "/" + path;
  }

  document.addEventListener("submit", function (event) {
    var ticketReply = event.target.closest("[data-ticket-reply]");
    if (ticketReply && ticketReply.getAttribute("data-ticket-reply") !== null) {
      event.preventDefault();
      var ticket = state.tickets.filter(function (item) { return String(item.id) === ticketReply.getAttribute("data-ticket-reply"); })[0];
      var answer = (ticketReply.elements.namedItem("body").value || "").trim();
      if (ticket && answer.length >= 2 && answer.length <= 5000) { (ticket.replies || (ticket.replies = [])).push({ author: state.user.name, body: answer }); store(); paintTickets(); }
      return;
    }
    var commentForm = event.target.closest("[data-comment-form]");
    if (commentForm && commentForm.getAttribute("data-comment-form") !== null) {
      event.preventDefault();
      var post = state.posts.filter(function (item) { return String(item.id) === commentForm.getAttribute("data-comment-form"); })[0];
      var body = (commentForm.elements.namedItem("body").value || "").trim();
      if (post && body.length >= 2 && body.length <= 2000) { (post.replies || (post.replies = [])).push({ author: state.user ? state.user.name : "کاربر", body: body, created_at: new Date().toISOString() }); store(); paintPosts(); }
      return;
    }
    var form = event.target.closest("[data-form]");
    if (!form) return;
    event.preventDefault();
    var handler = actions[form.getAttribute("data-form")];
    if (handler) handler(form);
  });

  document.addEventListener("change", function (event) {
    if (!event.target.matches || !event.target.matches("[data-file-upload]")) return;
    Array.prototype.slice.call(event.target.files || []).slice(0, 20).forEach(function (file) {
      var name = String(file.name || "").replace(/[^A-Za-z0-9._/-]/g, "_").replace(/^\/+/, "");
      if (!validVirtualPath(name) || name.length > 120 || file.size > 1024 * 1024) return;
      var reader = new FileReader();
      reader.onload = function () {
        var bytes = reader.result;
        var text;
        try { text = new TextDecoder("utf-8", { fatal: true }).decode(bytes); }
        catch (error) { text = new TextDecoder("windows-1256").decode(bytes); }
        workspaceFiles()[name] = text; activeFile = name; store(); paintFiles(); var editor = el("[data-code-input]"); if (editor) editor.value = text;
      };
      reader.readAsArrayBuffer(file);
    });
    event.target.value = "";
  });

  document.addEventListener("click", function (event) {
    var profile = event.target.closest("[data-member-profile]");
    if (profile) { var card = profile.closest(".post-card").querySelector("[data-member-card]"); card.hidden = !card.hidden; return; }
    var follow = event.target.closest("[data-follow-user]");
    if (follow) { var name = follow.getAttribute("data-follow-user"); if (state.follows.indexOf(name) < 0) state.follows.push(name); store(); follow.textContent = "دنبال می‌کنید"; return; }

    var commentToggle = event.target.closest("[data-comment-toggle]");
    if (commentToggle) { var commentForm = el('[data-comment-form="' + commentToggle.getAttribute("data-comment-toggle") + '"]'); if (commentForm) commentForm.hidden = !commentForm.hidden; return; }
    var fileSelect = event.target.closest("[data-file-select]");
    if (fileSelect) {
      saveCurrentFile(document);
      var selected = fileSelect.getAttribute("data-file-select");
      if (selected && Object.prototype.hasOwnProperty.call(workspaceFiles(), selected)) {
        activeFile = selected;
        var editor = el("[data-code-input]");
        if (editor) editor.value = workspaceFiles()[selected];
        paintFiles();
      }
      return;
    }
    var fileCreate = event.target.closest("[data-file-create]");
    if (fileCreate) {
      var nameInput = el("[data-file-name-input]");
      var name = nameInput ? nameInput.value.trim() : "";
      if (validVirtualPath(name) && !Object.prototype.hasOwnProperty.call(workspaceFiles(), name)) {
        workspaceFiles()[name] = "";
        activeFile = name;
        saveWorkspace();
        var editor = el("[data-code-input]");
        if (editor) editor.value = "";
        if (nameInput) nameInput.value = "";
      }
      return;
    }
    var termRun = event.target.closest("[data-terminal-run]");
    if (termRun) {
      var termInput = el("#term-input") || el('[data-form="terminal"] [name="command"]');
      runCommand(termInput ? termInput.value : "");
      if (termInput) { termInput.value = ""; termInput.focus(); }
      return;
    }

    var run = event.target.closest("[data-code-run]");
    if (run) {
      var card = run.closest(".code-layout") || document;
      var source = el("[data-code-input]", card);
      var output = el("[data-code-output]", card);
      var status = el("[data-code-status]", card);
      var code = source ? source.value.trim() : "";
      saveCurrentFile(card);
      if (!code) {
        if (output) output.textContent = "برای اجرا، ابتدا کدی بنویس.";
        return;
      }
      var plots = el("[data-code-plots]", card);
      if (plots) plots.replaceChildren();
      card.codeRunning = true;
      run.disabled = true;
      if (output) output.textContent = "";
      if (status) status.textContent = "در حال آماده‌سازی…";
      var stop = el("[data-code-stop]", card);
      var clear = el("[data-code-reset]", card);
      var stamp = (card.querySelector && card.querySelector("[data-code-status]")) || status;
      if (stop) stop.disabled = false;
      if (clear) clear.disabled = true;
      var runner = window.NOVENTIX_RUN_PYTHON || function (_code, _status, done) {
        setTimeout(function () { done(runnerMissing()); }, 0);
        return function () {};
      };
      card.cancelRun = runner(code, function (message) {
        if (stamp) stamp.textContent = message;
      }, function (result) {
        card.codeRunning = false;
        run.disabled = false;
        if (stop) stop.disabled = true;
        if (clear) clear.disabled = false;
        if (output) output.textContent = result.output || (plots && plots.childElementCount ? "نمودارها در پایین نمایش داده شدند." : "کد بدون خطا پایان یافت؛ برای نمایش نتیجه از print استفاده کنید.");
        if (stamp) stamp.textContent = result.ok ? "اجرا پایان یافت" : "اجرا متوقف شد یا خطا داشت";
        state.runs++;
        store();
        paintStats();
      }, function (png) {
        if (!plots) return;
        var image = document.createElement("img");
        image.src = "data:image/png;base64," + png;
        image.alt = "نمودار خروجی پایتون";
        image.className = "code-plot";
        plots.appendChild(image);
      }, workspaceFiles(), state.packages.filter(function (name) { return ["numpy", "pandas", "matplotlib", "scipy"].indexOf(name) < 0; }), activeFile);
      return;
    }

    var stopRun = event.target.closest("[data-code-stop]");
    if (stopRun) {
      var activeCard = stopRun.closest(".code-layout");
      if (activeCard && activeCard.codeRunning && activeCard.cancelRun) activeCard.cancelRun();
      return;
    }

    var reset = event.target.closest("[data-code-reset]");
    if (reset) {
      var rcard = reset.closest(".code-layout") || document;
      var rinput = el("[data-code-input]", rcard);
      var routput = el("[data-code-output]", rcard);
      var rplots = el("[data-code-plots]", rcard);
      if (rplots) rplots.replaceChildren();
      if (rinput) rinput.value = "";
      if (routput) routput.textContent = "هنوز کدی اجرا نشده است.";
      return;
    }

    var enroll = event.target.closest("[data-enroll]");
    if (enroll) {
      var slug = enroll.getAttribute("data-enroll");
      if (!canCourse(slug)) { location.href = join(accountReady() ? "panel/student/subscription/" : "login/"); return; }
      if (state.enrollments.indexOf(slug) < 0) state.enrollments.push(slug);
      store(); paintStats(); paintEnrollments();
      enroll.outerHTML = '<span class="btn btn-primary is-done">در مسیر یادگیری شما ✓</span>';
      location.href = join("panel/student/learning/");
      return;
    }

    var project = event.target.closest("[data-project]");
    if (project) {
      var index = Number(project.getAttribute("data-project"));
      if (!canProject(index)) { location.href = join(accountReady() ? "panel/student/subscription/" : "login/"); return; }
      if (state.projects.indexOf(index) < 0) state.projects.push(index);
      store(); paintStats();
      project.outerHTML = '<span class="btn btn-primary is-done">به پروژه‌های من اضافه شد ✓</span>';
      return;
    }

    var challenge = event.target.closest("[data-challenge]");
    if (challenge) {
      var ci = Number(challenge.getAttribute("data-challenge"));
      if (state.challenges.indexOf(ci) < 0) state.challenges.push(ci);
      store(); paintStats(); paintMyChallenges();
      challenge.outerHTML = '<span class="btn btn-outline full is-done">در چالش‌های من ✓</span>';
      location.href = join("panel/student/my-challenges/");
      return;
    }

    var like = event.target.closest("[data-like]");
    if (like) {
      var post = state.posts.filter(function (p) { return String(p.id) === like.getAttribute("data-like"); })[0];
      if (post) { post.likes++; store(); paintPosts(); }
      return;
    }

    var save = event.target.closest("[data-save]");
    if (save) {
      var sp = state.posts.filter(function (p) { return String(p.id) === save.getAttribute("data-save"); })[0];
      if (sp) { sp.saves++; store(); paintPosts(); }
      return;
    }

    if (event.target.closest("[data-logout]")) {
      state.user = null;
      store();
      location.href = join("");
    }
  });

  var progress = el("[data-reading-progress]");
  if (progress) {
    var update = function () {
      var height = document.documentElement.scrollHeight - window.innerHeight;
      var ratio = height > 0 ? Math.min(1, Math.max(0, window.pageYOffset / height)) : 0;
      progress.style.width = (ratio * 100).toFixed(1) + "%";
    };
    window.addEventListener("scroll", update, { passive: true });
    window.addEventListener("resize", update);
    update();
  }

  var phoneSlot = el("[data-demo-phone]");
  if (phoneSlot && state.pending_phone) {
    phoneSlot.textContent = state.pending_phone;
    if (lastCode) {
      var codeHint = document.createElement("p");
      codeHint.className = "notice success";
      codeHint.textContent = "کد تأیید: " + fa(lastCode);
      phoneSlot.insertAdjacentElement("afterend", codeHint);
    }
  }

  function paintCaptcha() {
    var slot = el("[data-login-captcha]");
    if (!slot) return;
    var known = state.users.length > 0 && !accountReady();
    var register = el("[data-register-form]");
    var signin = el("[data-signin-form]");
    if (register) register.hidden = known;
    if (signin) signin.hidden = !known;
    var code = sessionStorage.getItem("noventix.demo.captcha");
    if (!code) { code = Math.random().toString(36).slice(2, 7).toUpperCase(); sessionStorage.setItem("noventix.demo.captcha", code); }
    slot.textContent = code;
  }

  window.NOVENTIX = { state: state, save: store, fa: fa, join: join, repaint: paintAll };
  guardPage();
  paintAll();
  paintFiles();
  paintCaptcha();
})();
