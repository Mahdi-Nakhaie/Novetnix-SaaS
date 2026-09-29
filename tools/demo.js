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
      settings: {}, subscriptions: [], messages: [], users: []
    };
  }

  var state = read() || blank();

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

  /* ------------------------------------------------------------ actions */

  var actions = {
    login: function (form) {
      var phone = (form.elements.namedItem("phone").value || "").replace(/[۰-۹]/g, function (d) { return "۰۱۲۳۴۵۶۷۸۹".indexOf(d); })
        .replace(/[\s-]/g, "").replace(/^(\+98|98)/, "0");
      if (!/^09[0-9]{9}$/.test(phone)) { note(form, "شماره موبایل معتبر وارد کنید.", "error"); return; }
      lastCode = String(Math.floor(100000 + Math.random() * 900000));
      state.pending_phone = phone;
      sessionStorage.setItem("noventix.demo.code", lastCode);
      store();
      location.href = join("verify/");
    },

    verify: function (form) {
      var code = (form.elements.namedItem("code").value || "").replace(/[۰-۹]/g, function (d) { return "۰۱۲۳۴۵۶۷۸۹".indexOf(d); });
      if (!lastCode || code !== lastCode || !state.pending_phone) { note(form, "کد واردشده صحیح نیست.", "error"); return; }
      var user = signIn(state.pending_phone);
      sessionStorage.removeItem("noventix.demo.code");
      lastCode = null;
      delete state.pending_phone;
      store();
      note(form, user.role === "admin" ? "خوش آمدید؛ ورود به پنل مدیریت…" : "خوش آمدید!", "success");
      var target = user.role === "admin" ? "panel/admin/" : "panel/student/";
      setTimeout(function () { location.href = join(target); }, 700);
    },

    profile: function (form) {
      if (!state.user) return;
      var name = (form.elements.namedItem("name").value || "").trim();
      if (name.length < 2 || name.length > 100) { note(form, "نام باید بین ۲ تا ۱۰۰ نویسه باشد.", "error"); return; }
      state.user.name = name;
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
      state.content.unshift({ title: title, kind: form.elements.namedItem("kind").value, status: "منتشرشده", created_at: new Date().toISOString() });
      store();
      form.reset();
      note(form, "محتوا ذخیره و منتشر شد.", "success");
      paintContent();
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
      streak: 7 + state.projects.length
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
  }

  function paintChallenges() {
    els("[data-challenge]").forEach(function (btn) {
      var index = Number(btn.getAttribute("data-challenge"));
      if (state.challenges.indexOf(index) < 0) return;
      btn.outerHTML = '<span class="btn btn-outline full is-done">در فهرست شما ✓</span>';
    });
  }

  function paintAll() {
    paintProfile(); paintStats(); paintEnrollments(); paintMyProjects();
    paintPosts(); paintTickets(); paintAnnouncements(); paintContent();
    paintUsers(); paintSubscriptions(); paintChallenges();
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
    var enroll = event.target.closest("[data-enroll]");
    if (enroll) {
      var slug = enroll.getAttribute("data-enroll");
      if (state.enrollments.indexOf(slug) < 0) state.enrollments.push(slug);
      store(); paintStats();
      enroll.outerHTML = '<span class="btn btn-primary is-done">در مسیر یادگیری شما ✓</span>';
      return;
    }

    var project = event.target.closest("[data-project]");
    if (project) {
      var index = Number(project.getAttribute("data-project"));
      if (state.projects.indexOf(index) < 0) state.projects.push(index);
      store(); paintStats();
      project.outerHTML = '<span class="btn btn-primary is-done">به پروژه‌های من اضافه شد ✓</span>';
      return;
    }

    var challenge = event.target.closest("[data-challenge]");
    if (challenge) {
      var ci = Number(challenge.getAttribute("data-challenge"));
      if (state.challenges.indexOf(ci) < 0) state.challenges.push(ci);
      store(); paintStats();
      challenge.outerHTML = '<span class="btn btn-outline full is-done">در فهرست شما ✓</span>';
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

  var phoneSlot = el("[data-demo-phone]");
  if (phoneSlot && state.pending_phone) {
    phoneSlot.textContent = state.pending_phone;
    if (lastCode) {
      var codeHint = document.createElement("p");
      codeHint.className = "notice success";
      codeHint.textContent = "کد تأیید نمایشی: " + fa(lastCode);
      phoneSlot.insertAdjacentElement("afterend", codeHint);
    }
  }

  window.NOVENTIX = { state: state, save: store, fa: fa, join: join, repaint: paintAll };
  paintAll();
})();
