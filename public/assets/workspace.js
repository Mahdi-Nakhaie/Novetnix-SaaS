(() => {
  'use strict';
  const messageNode = document.querySelector('[data-ws-message]');
  const message = text => { if (messageNode) messageNode.textContent = text; };
  async function request(url, data) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 15000);
    try {
      const response = await fetch(url, {method: data ? 'POST' : 'GET', body: data, credentials: 'same-origin', signal: controller.signal, headers: {'Accept': 'application/json'}});
      const type = response.headers.get('Content-Type') || '';
      if (!type.includes('application/json')) throw new Error('نشست یا درخواست معتبر نیست؛ صفحه را پس از ورود دوباره باز کنید.');
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || 'درخواست انجام نشد.');
      return result;
    } finally { clearTimeout(timer); }
  }
  document.querySelectorAll('[data-ws-form]').forEach(form => {
    form.addEventListener('submit', async event => {
      event.preventDefault();
      const button = form.querySelector('button');
      if (button.disabled) return;
      button.disabled = true;
      try {
        const result = await request(form.action, new FormData(form));
        if (form.action.endsWith('/create') || form.action.endsWith('/legacy')) location.assign('/dashboard/workspace/' + result.id);
        else if (form.action.endsWith('/admin-template-save')) location.assign('/dashboard/workspace-templates?edit=' + result.id);
        else location.reload();
      } catch (error) { message(error.message); button.disabled = false; }
    });
  });
  const root = document.querySelector('[data-ws-project]');
  if (!root) return;
  const project = Number(root.dataset.wsProject);
  const csrf = root.dataset.csrf;
  const code = root.querySelector('[data-ws-code]');
  const tree = root.querySelector('[data-ws-tree]');
  const filename = root.querySelector('[data-ws-filename]');
  const newPath = root.querySelector('[data-ws-new-path]');
  const entry = root.querySelector('[data-ws-entry]');
  const runState = root.querySelector('[data-ws-run-state]');
  const output = root.querySelector('[data-ws-output]');
  const download = root.querySelector('[data-ws-download]');
  const lines = root.querySelector('[data-ws-lines]');
  const buttons = Object.fromEntries(Array.from(root.querySelectorAll('[data-ws-action]'), node => [node.dataset.wsAction, node]));
  let files = {}, selected = '', revision = 0, dirty = false, busy = false, ready = false, runner = false;
  let runId = null, requestKey = null, pendingEntry = '', result = null, timer = null;
  const binary = name => /\.(png|joblib|pkl|npy|npz)$/i.test(name);
  const pathValid = path => typeof path === 'string' && path.length <= 160 && /^[A-Za-z0-9_][A-Za-z0-9_. /-]*$/.test(path) && path.split('/').every(p => p && !p.startsWith('.') && p.trim() === p);
  const encode = text => { const bytes = new TextEncoder().encode(text); let raw = ''; for (const byte of bytes) raw += String.fromCharCode(byte); return btoa(raw); };
  const decode = data => new TextDecoder('utf-8', {fatal: true}).decode(Uint8Array.from(atob(data), c => c.charCodeAt(0)));
  const active = () => runId !== null;
  function refreshButtons() {
    Object.values(buttons).forEach(button => { button.disabled = !ready || busy; });
    buttons.run.disabled = !ready || busy || !runner;
    buttons.run.textContent = active() ? 'بررسی وضعیت اجرا' : requestKey ? 'تلاش مجدد همان درخواست' : 'اجرای Python';
    buttons.cancel.disabled = !ready || busy || !active();
    buttons.apply.disabled = !ready || busy || active() || dirty || !result || !['succeeded', 'program_error'].includes(result.state) || result.revision !== revision;
    buttons['remove-file'].disabled = !ready || busy || !selected;
  }
  function updateLines() { lines.textContent = Array.from({length: code.value.split('\n').length}, (_, i) => i + 1).join('\n'); }
  function commitEditor() {
    if (selected && files[selected] && files[selected].kind === 'file' && !code.disabled) files[selected].data = encode(code.value);
  }
  function select(name) {
    commitEditor(); selected = name;
    filename.textContent = name || 'فایل را انتخاب کنید';
    const item = files[name];
    code.disabled = !item || item.kind === 'directory' || binary(name);
    code.value = '';
    if (item && item.kind === 'file' && !binary(name)) {
      try { code.value = decode(item.data); code.disabled = false; }
      catch (_) { code.disabled = true; message('فایل UTF-8 نیست؛ بدون تبدیل دانلود کنید.'); }
    }
    download.hidden = !item || item.kind !== 'file';
    download.href = '/workspace/download?id=' + project + '&file=' + encodeURIComponent(name);
    updateLines(); renderTree(); refreshButtons();
  }
  function renderTree() {
    tree.replaceChildren();
    Object.keys(files).sort().forEach(name => {
      const button = document.createElement('button');
      button.type = 'button'; button.dir = 'ltr'; button.textContent = (files[name].kind === 'directory' ? '[folder] ' : '') + name;
      button.setAttribute('aria-pressed', String(selected === name));
      button.addEventListener('click', () => select(name)); tree.append(button);
    });
  }
  function form(values = {}) {
    const data = new FormData();
    Object.entries({csrf, id: project, revision, ...values}).forEach(([key, value]) => data.append(key, String(value)));
    return data;
  }
  async function load() {
    const state = await request('/workspace/data?id=' + project);
    selected = ''; files = {...state.files}; revision = state.revision; runner = state.runner_ready; dirty = false; ready = true;
    if (state.pending_run) {
      if (state.pending_run.remote_id) runId = state.pending_run.id;
      else { requestKey = state.pending_run.request_key; pendingEntry = state.pending_run.entrypoint; }
    }
    select(files['main.py'] ? 'main.py' : Object.keys(files)[0] || '');
    message('نسخه ' + revision.toLocaleString('fa-IR') + ' از سرور باز شد.' + (runner ? '' : ' موتور اجرا راه‌اندازی نشده است؛ ذخیره فایل‌ها فعال است.'));
    refreshButtons();
    if (runner) {
      request('/workspace/runtime').then(runtime => {
        root.querySelector('[data-ws-runtime]').textContent = 'Python ' + runtime.python + ' | ' + Object.entries(runtime.packages).map(([name, version]) => name + '==' + version).join(' | ');
      }).catch(error => { runner = false; refreshButtons(); message(error.message + ' ذخیره فایل‌ها همچنان در دسترس است.'); });
    }
    if (runId && !timer) poll();
  }
  async function save() {
    commitEditor();
    const state = await request('/workspace/save', form({files: JSON.stringify(files)}));
    revision = state.revision; dirty = false;
    message('فایل‌ها در حساب شما ذخیره شدند؛ نسخه ' + revision.toLocaleString('fa-IR') + '.');
    refreshButtons();
  }
  const labels = {queued: 'در صف', running: 'در حال اجرا', cancelling: 'در حال توقف', cancelled: 'متوقف‌شده', succeeded: 'موفق', program_error: 'خطای برنامه', dependency_error: 'وابستگی غیرمجاز یا نسخه ناسازگار', memory_limit: 'پایان سهمیه حافظه', cpu_limit: 'پایان سهمیه پردازنده', artifact_limit: 'محدودیت فایل خروجی', timeout: 'پایان مهلت اجرا', output_limit: 'خروجی بیش از حد مجاز', infrastructure_error: 'خطای زیرساخت اجرا'};
  async function poll() {
    clearTimeout(timer); timer = null;
    if (!runId) return;
    try {
      const state = await request('/workspace/run-state?id=' + runId);
      runState.textContent = labels[state.state] || state.state;
      output.textContent = 'stdout\n' + (state.stdout || '') + '\n\nstderr / Traceback\n' + (state.stderr || '');
      if (['queued', 'running', 'cancelling'].includes(state.state)) timer = setTimeout(poll, 2000);
      else { result = state; runId = null; requestKey = null; message('اجرا پایان یافت؛ برای نگهداری فایل‌های تولیدشده دکمه ذخیره خروجی را بزنید.'); }
    } catch (error) { message(error.message + ' برای ادامه، بررسی وضعیت اجرا را بزنید؛ اجرای جدیدی ارسال نمی‌شود.'); }
    refreshButtons();
  }
  async function act(action) {
    if (busy || !ready) return;
    busy = true; refreshButtons();
    try {
      if (action === 'save') await save();
      if (action === 'reload' && (!dirty || confirm('تغییرات ذخیره‌نشده کنار گذاشته شوند؟'))) await load();
      if (action === 'new-file' || action === 'new-folder') {
        const name = newPath.value.trim();
        if (!pathValid(name) || files[name]) throw new Error('مسیر جدید معتبر و غیرتکراری وارد کنید.');
        commitEditor(); files[name] = {kind: action === 'new-folder' ? 'directory' : 'file', data: ''}; dirty = true; select(name);
      }
      if (action === 'rename') {
        const name = newPath.value.trim();
        if (!selected || !pathValid(name) || name.startsWith(selected + '/') || files[name]) throw new Error('مسیر مقصد باید معتبر و غیرتکراری باشد.');
        commitEditor();
        const old = selected, moved = {};
        Object.keys(files).filter(path => path === old || path.startsWith(old + '/')).forEach(path => { const next = name + path.slice(old.length); if (files[next]) throw new Error('فایل مقصد از قبل موجود است.'); moved[next] = files[path]; });
        Object.keys(files).filter(path => path === old || path.startsWith(old + '/')).forEach(path => { delete files[path]; });
        Object.assign(files, moved); selected = ''; dirty = true; select(name);
      }
      if (action === 'remove-file' && selected && confirm('فایل یا پوشه انتخاب‌شده و زیرمجموعه آن حذف شود؟')) {
        const name = selected; selected = '';
        Object.keys(files).filter(path => path === name || path.startsWith(name + '/')).forEach(path => { delete files[path]; });
        dirty = true; select(Object.keys(files)[0] || '');
      }
      if (action === 'upload') {
        await save();
        const data = form();
        for (const file of root.querySelector('[data-ws-upload]').files) data.append('files[]', file);
        const zip = root.querySelector('[data-ws-zip]').files[0];
        if (zip) data.append('archive', zip);
        await request('/workspace/upload', data); await load();
        root.querySelector('[data-ws-upload]').value = ''; root.querySelector('[data-ws-zip]').value = '';
      }
      if (action === 'run') {
        if (runId) { await poll(); return; }
        if (!requestKey) {
          const target = entry.value.trim();
          if (!pathValid(target) || !target.endsWith('.py') || files[target]?.kind !== 'file') throw new Error('فایل اجرایی پایتون موجود و معتبر انتخاب کنید.');
          await save(); requestKey = crypto.randomUUID(); pendingEntry = target;
        }
        const state = await request('/workspace/run', form({request_key: requestKey, entrypoint: pendingEntry}));
        runId = state.local_id; result = null; await poll();
      }
      if (action === 'cancel' && runId) { await request('/workspace/cancel', form({run_id: runId})); await poll(); }
      if (action === 'apply' && result) {
        if (dirty) throw new Error('ابتدا تغییرات را ذخیره کنید؛ خروجی روی نسخه جدید بازنویسی نمی‌شود.');
        await request('/workspace/apply-output', form({run_id: result.local_id})); result = null; await load();
      }
      if (action === 'delete' && confirm('پروژه به سطل بازیافت منتقل شود؟')) {
        await request('/workspace/delete', form()); location.assign('/dashboard/workspace');
      }
    } catch (error) { message(error.message); }
    finally { busy = false; refreshButtons(); }
  }
  Object.entries(buttons).forEach(([action, button]) => button.addEventListener('click', () => act(action)));
  code.addEventListener('input', () => { dirty = true; updateLines(); refreshButtons(); });
  code.addEventListener('scroll', () => { lines.scrollTop = code.scrollTop; });
  code.addEventListener('keydown', event => {
    if (event.key === 'Tab') { event.preventDefault(); code.setRangeText('    ', code.selectionStart, code.selectionEnd, 'end'); dirty = true; updateLines(); }
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') { event.preventDefault(); act('save'); }
  });
  window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
  load().catch(error => message(error.message));
})();
