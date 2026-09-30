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
      chat: [], reviews: [], terminal: [], payments: []
    };
  }

  var state = Object.assign(blank(), read() || {});

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
  function currentPlan() {
    if (!accountReady() || !state.user.plan || !state.user.plan_expires || new Date(state.user.plan_expires) <= new Date()) return "free";
    return state.user.plan;
  }
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

  /* Demo-only canned replies; no model call leaves the browser. */
  function sampleAnswer(question) {
    var text = question.toLowerCase();
    if (text.indexOf("خالی") >= 0 || text.indexOf("empty") >= 0) {
      return "برای ورودی خالی پیش از محاسبه شرط بگذار و خروجی مشخص برگردان؛ مثلاً اگر فهرست خالی است مقدار پیش‌فرض برگردان یا خطای روشن بده. سپس همین حالت را در آزمون بنویس.";
    }
    if (text.indexOf("خطا") >= 0 || text.indexOf("error") >= 0 || text.indexOf("debug") >= 0) {
      return "پیام خطا را کامل بخوان، خط مربوطه را پیدا کن و مقدار متغیرها را در همان نقطه چاپ کن. کوچک‌ترین ورودی‌ای که خطا را بازتولید می‌کند بساز و سپس اصلاح کن.";
    }
    if (text.indexOf("pandas") >= 0 || text.indexOf("داده") >= 0) {
      return "پیش از تحلیل نوع ستون‌ها را بررسی کن، مقادیر گمشده و ردیف‌های تکراری را بشمار و تصمیم پاک‌سازی را در گزارش ثبت کن. نمونه کوچک بساز تا نتیجه قابل بازتولید بماند.";
    }
    return "مسئله را به گام‌های کوچک بشکن: ورودی، خروجی و معیار موفقیت را بنویس، ساده‌ترین راه‌حل را پیاده کن و سپس آن را با یک حالت مرزی آزمون کن.";
  }

  /* Without a worker or network the browser runs nothing, so the click still reports progress. */
  function runnerMissing() {
    return { ok: false, output: "موتور Python بارگیری نشد؛ اتصال اینترنت را بررسی و صفحه را دوباره باز کنید." };
  }

  var TERMINAL_HELP = [
    "فرمان‌های پشتیبانی‌شده:",
    "  python main.py   اجرای کد و نمودارهای ویرایشگر",
    "  ls               فهرست فایل‌های میزکار",
    "  clear            پاک‌کردن ترمینال",
    "  help             راهنمای فرمان‌ها",
  ].join("\n");

  function runCommand(raw) {
    var host = el("[data-terminal-log]");
    if (!host) return;
    var command = String(raw || "").trim();
    var body;
    if (!command) return;
    if (command === "clear") { host.replaceChildren(); return; }
    appendTerminal("$ " + command, "term-cmd");
    state.terminal = state.terminal.concat([{ command: command, at: new Date().toISOString() }]).slice(-40);
    store();
    paintStats();
    if (command === "python main.py") {
      var run = el("[data-code-run]");
      if (run && !run.disabled) {
        appendTerminal("خروجی کد و نمودارها در بخش خروجی میزکار نمایش داده می‌شوند.", "term-out");
        run.click();
      } else appendTerminal("اجرای کد در حال انجام است؛ پس از پایان دوباره تلاش کنید.", "term-out");
      return;
    }
    if (command === "help" || command === "--help") body = TERMINAL_HELP;
    else if (command === "ls") body = "main.py";
    else body = "فرمان پشتیبانی نمی‌شود: " + command + "\nبرای راهنما help را اجرا کنید.";
    appendTerminal(body, "term-out");
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

  /* Symbolic checkout: the card data is validated, then discarded — never stored. */
  function openCheckout(planId) {
    var box = el("#checkout");
    if (!box) return;
    box.hidden = false;
    var slot = el("[data-checkout-plan]", box);
    if (slot) slot.textContent = planById(planId).name + " · " + fa(planById(planId).price.toLocaleString("en-US")) + " تومان";
    var field = el('[data-form="checkout"] [name="plan"]', box);
    if (field) field.value = planId;
    var receipt = el("[data-receipt]", box);
    if (receipt) { receipt.hidden = true; receipt.innerHTML = ""; }
    var message = el(".form-note", box);
    if (message) message.hidden = true;
    box.scrollIntoView({ behavior: "smooth", block: "start" });
  }

  function digits(value) {
    return String(value || "").replace(/[۰-۹٠-٩]/g, function (d) {
      return "۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩".indexOf(d) % 10;
    });
  }

  function luhn(number) {
    var sum = 0;
    var flip = false;
    for (var i = number.length - 1; i >= 0; i--) {
      var n = Number(number.charAt(i));
      if (flip) { n *= 2; if (n > 9) n -= 9; }
      sum += n;
      flip = !flip;
    }
    return sum % 10 === 0;
  }

  function receiptHtml(planId) {
    var plan = planById(planId);
    var ref = "NVX-" + String(Date.now()).slice(-8);
    return '<h3>نتیجه فعال‌سازی آزمایشی</h3>' +
      '<ul class="receipt-list">' +
      '<li><span>پلن</span><strong>' + esc(plan.name) + '</strong></li>' +
      '<li><span>مبلغ</span><strong>' + fa(plan.price.toLocaleString("en-US")) + ' تومان</strong></li>' +
      '<li><span>شماره پیگیری</span><strong dir="ltr">' + esc(ref) + '</strong></li>' +
      '<li><span>وضعیت</span><strong>بدون تراکنش واقعی</strong></li>' +
      '</ul><p class="muted">هیچ مبلغی از حساب شما کم نشد و اطلاعات کارت ذخیره نشد.</p>';
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
      lastCode = String(Math.floor(100000 + Math.random() * 900000));
      state.pending_phone = phone;
      state.pending_name = { first: first, last: last };
      sessionStorage.setItem("noventix.demo.code", lastCode);
      store();
      location.href = join("verify/");
    },

    verify: function (form) {
      var code = (form.elements.namedItem("code").value || "").replace(/[۰-۹]/g, function (d) { return "۰۱۲۳۴۵۶۷۸۹".indexOf(d); });
      if (!lastCode || code !== lastCode || !state.pending_phone) { note(form, "کد واردشده صحیح نیست.", "error"); return; }
      var user = signIn(state.pending_phone);
      user.first_name = state.pending_name.first;
      user.last_name = state.pending_name.last;
      user.name = user.first_name + " " + user.last_name;
      sessionStorage.removeItem("noventix.demo.code");
      lastCode = null;
      delete state.pending_phone;
      delete state.pending_name;
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
      state.tickets.unshift({ subject: subject, body: body, priority: form.elements.namedItem("priority").value, status: "open", created_at: new Date().toISOString() });
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
      var question = (form.elements.namedItem("question").value || "").trim();
      if (question.length < 10) { note(form, "سؤال باید دست‌کم ۱۰ نویسه باشد.", "error"); return; }
      var guess = sampleAnswer(question);
      state.nova.push({ question: question, answer: guess, created_at: new Date().toISOString() });
      store();
      form.reset();
      note(form, "پاسخ Nova ثبت شد.", "success");
      paintNova();
      paintStats();
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

    checkout: function (form) {
      if (!accountReady()) { location.href = join("login/"); return; }
      var planId = form.elements.namedItem("plan").value;
      if (planOrder.indexOf(planId) < 1) { note(form, "پلن انتخاب‌شده معتبر نیست.", "error"); return; }
      var card = digits(form.elements.namedItem("card").value).replace(/[\s-]/g, "");
      if (!/^[0-9]{16}$/.test(card) || !luhn(card)) {
        note(form, "شماره کارت نمونه باید ۱۶ رقم و از نظر ساختار معتبر باشد.", "error"); return;
      }
      var holder = (form.elements.namedItem("holder").value || "").trim();
      if (holder.length < 2) { note(form, "نام روی کارت را وارد کنید.", "error"); return; }
      var month = digits(form.elements.namedItem("month").value);
      var year = digits(form.elements.namedItem("year").value);
      var cvv = digits(form.elements.namedItem("cvv").value);
      if (!/^(0[1-9]|1[0-2])$/.test(month)) { note(form, "ماه انقضا نامعتبر است.", "error"); return; }
      if (!/^[0-9]{2}$/.test(year)) { note(form, "سال انقضا نامعتبر است.", "error"); return; }
      if (!/^[0-9]{3,4}$/.test(cvv)) { note(form, "CVV نامعتبر است.", "error"); return; }
      if (!form.elements.namedItem("agree").checked) { note(form, "تأیید آزمایشی‌بودن فعال‌سازی لازم است.", "error"); return; }

      var now = new Date();
      var expires = new Date(now.getTime() + 30 * 864e5);
      state.user.plan = planId;
      state.user.plan_expires = expires.toISOString();
      state.subscriptions.unshift({
        phone: state.user.phone, plan: planId, status: "فعال‌شده (آزمایشی)",
        starts_at: now.toISOString(), expires_at: expires.toISOString(),
        holder: holder, last4: card.slice(-4)
      });
      state.payments.unshift({
        plan: planId, amount: planById(planId).price, status: "آزمایشی",
        reference: "NVX-" + String(Date.now()).slice(-8),
        last4: card.slice(-4), created_at: now.toISOString()
      });
      store();
      form.reset();
      var box = el("#checkout");
      var receipt = box ? el("[data-receipt]", box) : null;
      if (receipt) { receipt.innerHTML = receiptHtml(planId); receipt.hidden = false; }
      note(form, "پلن " + planById(planId).name + " به‌صورت آزمایشی فعال شد.", "success");
      paintAll();
    },

    setting: function (form) {
      state.settings[form.elements.namedItem("key").value] = (form.elements.namedItem("value").value || "").trim();
      store();
      note(form, "تنظیم ذخیره شد.", "success");
    },

    activate: function (form) {
      var phone = (form.elements.namedItem("phone").value || "").trim().replace(/[۰-۹]/g, function (d) { return "۰۱۲۳۴۵۶۷۸۹".indexOf(d); });
      if (!/^09[0-9]{9}$/.test(phone)) { note(form, "شماره و پلن معتبر وارد کنید.", "error"); return; }
      var plan = form.elements.namedItem("plan").value;
      state.subscriptions.unshift({ phone: phone, plan: plan, status: "فعال", starts_at: new Date().toISOString(), expires_at: new Date(Date.now() + 30 * 864e5).toISOString() });
      var match = state.users.filter(function (u) { return u.phone === phone; })[0];
      if (match) match.plan = plan;
      store();
      form.reset();
      note(form, "اشتراک ۳۰ روزه فعال شد.", "success");
      paintSubscriptions();
      paintStats();
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
    var out = '<article class="post-card"><div class="meta-row"><span class="pill">' + esc(p.author) + '</span><span>' + jalali(p.created_at) + '</span></div><h2>' + esc(p.title) + '</h2><p>' + esc(p.body).replace(/\n/g, "<br>") + '</p>';
    if (withActions) {
      out += '<div class="react-row"><button type="button" class="text-button" data-like="' + p.id + '">Like ' + fa(p.likes) + '</button>' +
        '<button type="button" class="text-button" data-save="' + p.id + '">Save ' + fa(p.saves) + '</button>' +
        '<span class="text-button">Comment ' + fa(p.replies.length) + '</span></div>';
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
      return '<div class="list-item"><div><div class="meta-row"><span class="pill">' + esc(t.priority) + '</span><span>' +
        (t.status === "open" ? "باز" : "بسته") + '</span><span>' + jalali(t.created_at) + '</span></div><h3>' + esc(t.subject) + '</h3><p>' + esc(t.body) + '</p></div></div>';
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
    var form = event.target.closest("[data-form]");
    if (!form) return;
    event.preventDefault();
    var handler = actions[form.getAttribute("data-form")];
    if (handler) handler(form);
  });

  document.addEventListener("click", function (event) {
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
      });
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

    var upgrade = event.target.closest("[data-demo-plan]");
    if (upgrade) {
      if (!accountReady()) { location.href = join("login/"); return; }
      var selected = upgrade.getAttribute("data-demo-plan");
      if (planOrder.indexOf(selected) < 1) return;
      openCheckout(selected);
      return;
    }

    if (event.target.closest("[data-checkout-cancel]")) {
      var box = el("#checkout");
      if (box) box.hidden = true;
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

  window.NOVENTIX = { state: state, save: store, fa: fa, join: join, repaint: paintAll };
  guardPage();
  paintAll();
})();
