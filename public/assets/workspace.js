(() => {
  'use strict';
  const messageNode = document.querySelector('[data-ws-message]');
  const message = text => { if (messageNode) messageNode.textContent = text; };
  async function request(url, data) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 30000);
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
  let datasetPaths = new Set(), downloading = null;
  const binary = name => /\.(png|joblib|pkl|npy|npz)$/i.test(name);
  const pathValid = path => typeof path === 'string' && path.length <= 160 && /^[A-Za-z0-9_][A-Za-z0-9_. /-]*$/.test(path) && path.split('/').every(p => p && !p.startsWith('.') && p.trim() === p);
  const encode = text => { const bytes = new TextEncoder().encode(text); let raw = ''; for (const byte of bytes) raw += String.fromCharCode(byte); return btoa(raw); };
  const decode = data => new TextDecoder('utf-8', {fatal: true}).decode(Uint8Array.from(atob(data), c => c.charCodeAt(0)));
  const active = () => runId !== null;
  function refreshButtons() {
    const locked = busy || downloading !== null;
    code.readOnly = datasetPaths.has(selected) || locked;
    Object.values(buttons).forEach(button => { button.disabled = !ready || locked; });
    buttons.run.disabled = !ready || locked || !runner;
    buttons.run.textContent = active() ? 'بررسی وضعیت اجرا' : requestKey ? 'تلاش مجدد همان درخواست' : 'اجرای Python';
    buttons.cancel.disabled = !ready || locked || !active();
    buttons.apply.disabled = !ready || locked || active() || dirty || !result || !['succeeded', 'program_error'].includes(result.state) || result.revision !== revision;
    buttons['remove-file'].disabled = !ready || locked || !selected || datasetPaths.has(selected);
    buttons.rename.disabled = !ready || locked || !selected || datasetPaths.has(selected);
  }
  function updateLines() { lines.textContent = Array.from({length: code.value.split('\n').length}, (_, i) => i + 1).join('\n'); }
  function commitEditor() {
    if (selected && files[selected] && files[selected].kind === 'file' && !code.disabled && !datasetPaths.has(selected)) files[selected].data = encode(code.value);
  }
  function select(name) {
    commitEditor(); selected = name;
    filename.textContent = name || 'فایل را انتخاب کنید';
    const item = files[name];
    code.readOnly = datasetPaths.has(name) || downloading !== null;
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
    selected = ''; files = {...state.files}; datasetPaths = new Set(state.dataset_paths || []); revision = state.revision; runner = state.runner_ready; dirty = false; ready = true;
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
    if (busy || !ready || downloading !== null) return;
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
  const hfMessage = root.querySelector('[data-hf-message]');
  const hfResults = root.querySelector('[data-hf-results]');
  const hfDetail = root.querySelector('[data-hf-detail]');
  const hfImports = root.querySelector('[data-hf-imports]');
  const hfText = text => { hfMessage.textContent = text; };
  const hfNode = (tag, text) => { const node = document.createElement(tag); node.textContent = text; return node; };
  const bytesLabel = bytes => bytes === null ? 'نامشخص' : Number(bytes).toLocaleString('fa-IR') + ' بایت';
  function hfMetadata(dataset, container) {
    const title = hfNode('h3', dataset.id); title.dir = 'ltr'; container.append(title);
    container.append(hfNode('p', dataset.description || 'توضیحات مشخص نشده است.'));
    container.append(hfNode('p', 'قالب‌ها: ' + (dataset.formats.join('، ') || 'نامشخص') + ' · اندازه تقریبی مخزن: ' + bytesLabel(dataset.approximate_bytes)));
    container.append(hfNode('p', 'مجوز: ' + (Array.isArray(dataset.license) ? dataset.license.join('، ') : dataset.license || 'نامشخص') + ' · دسترسی: عمومی بدون احراز هویت'));
    const source = hfNode('a', 'منبع و شرایط استفاده در Hugging Face'); source.href = dataset.source; source.target = '_blank'; source.rel = 'noopener noreferrer'; container.append(source);
  }
  async function hfShow(id) {
    hfText('در حال بررسی دسترسی، نسخه و فایل‌ها…');
    const data = await request('/workspace/datasets-detail?id=' + project + '&dataset=' + encodeURIComponent(id));
    hfDetail.replaceChildren(); hfDetail.hidden = false; hfMetadata(data, hfDetail);
    hfDetail.append(hfNode('p', data.notice));
    const options = hfNode('div', ''); options.className = 'ws-dataset-files';
    data.files.forEach(file => {
      const label = hfNode('label', ''); const input = document.createElement('input'); input.type = 'checkbox'; input.value = file.path;
      const text = hfNode('span', file.path + ' · ' + bytesLabel(file.size)); text.dir = 'ltr'; label.append(input, text); options.append(label);
    });
    hfDetail.append(options);
    const add = hfNode('button', 'ذخیره پروژه و دریافت فایل‌های انتخابی'); add.type = 'button'; add.disabled = !data.files.length;
    add.addEventListener('click', async () => {
      if (busy || !ready || downloading) { hfText('ابتدا عملیات جاری را پایان دهید.'); return; }
      const chosen = Array.from(options.querySelectorAll('input:checked'), input => input.value);
      if (!chosen.length || chosen.length > 10) { hfText('بین ۱ تا ۱۰ فایل انتخاب کنید.'); return; }
      busy = true; add.disabled = true; refreshButtons();
      try {
        await save();
        const job = await request('/workspace/datasets-begin', form({dataset: data.id, dataset_revision: data.revision, selected_files: JSON.stringify(chosen)}));
        await hfContinue(job);
      } catch (error) { hfText(error.message + ' وضعیت دریافت‌ها را بررسی کنید؛ ارسال تکراری فایل جدید نمی‌سازد.'); }
      finally { busy = false; add.disabled = false; refreshButtons(); await hfRefresh().catch(error => hfText(error.message)); }
    });
    hfDetail.append(add); hfText(data.files.length ? 'فایل‌ها را انتخاب کنید؛ دریافت فقط برای قالب و حجم مجاز فعال است.' : 'این دیتاست فایل کوچک و سازگار با MVP ندارد.');
  }
  async function hfContinue(job) {
    if (downloading) return;
    if (dirty) throw new Error('پیش از ادامه دریافت، تغییرات ویرایشگر را ذخیره کنید.');
    downloading = job.id; code.readOnly = true; refreshButtons();
    try {
      while (job.state === 'downloading') {
        hfText('دریافت ' + job.dataset_id + ': ' + Number(job.completed_files).toLocaleString('fa-IR') + ' از ' + Number(job.file_count).toLocaleString('fa-IR') + ' فایل تأیید شده است.');
        await hfRefresh();
        job = await request('/workspace/datasets-step', form({import_id: job.id}));
      }
      if (job.state === 'succeeded') {
        hfText('دیتاست در ' + job.directory + ' ذخیره شد. فایل‌های اصلی فقط‌خواندنی‌اند؛ در Python با مسیر نسبی بخوانید.');
        if (!dirty) await load();
        else message('دریافت کامل شد؛ پس از نگهداری تغییرات، نسخه سرور را باز کنید.');
      } else hfText(job.error || (job.state === 'cancelled' ? 'دریافت لغو شد.' : 'دریافت ناموفق بود.'));
    } finally { downloading = null; code.readOnly = datasetPaths.has(selected); refreshButtons(); }
  }
  async function hfRefresh() {
    const state = await request('/workspace/datasets-state?id=' + project);
    hfImports.replaceChildren();
    state.imports.forEach(job => {
      const card = hfNode('article', ''); card.className = 'ws-dataset-import';
      const label = {downloading: 'در حال دریافت', succeeded: 'ذخیره و checksum تأیید شد', failed: 'ناموفق', cancelled: 'لغوشده'}[job.state] || job.state;
      card.append(hfNode('p', job.dataset_id + ' · ' + label + ' · ' + job.completed_files.toLocaleString('fa-IR') + '/' + job.file_count.toLocaleString('fa-IR')));
      card.append(hfNode('p', 'مجوز: ' + (Array.isArray(job.metadata.license) ? job.metadata.license.join('، ') : job.metadata.license || 'نامشخص')));
      const source = hfNode('a', 'منبع دیتاست'); source.href = job.metadata.source; source.target = '_blank'; source.rel = 'noopener noreferrer'; card.append(source);
      if (job.error) card.append(hfNode('p', job.error));
      if (job.state === 'succeeded') { const path = hfNode('code', job.directory); path.dir = 'ltr'; card.append(path); }
      if (job.state === 'downloading') {
        const resume = hfNode('button', downloading === job.id ? 'در حال دریافت…' : 'ادامه دریافت / بررسی وضعیت'); resume.type = 'button'; resume.disabled = downloading !== null;
        resume.addEventListener('click', () => { if (busy || !ready) { hfText('ابتدا عملیات میزکار را پایان دهید.'); return; } hfContinue(job).then(hfRefresh).catch(error => hfText(error.message)); });
        const cancel = hfNode('button', 'لغو دریافت'); cancel.type = 'button';
        cancel.addEventListener('click', async () => { cancel.disabled = true; try { await request('/workspace/datasets-cancel', form({import_id: job.id})); await hfRefresh(); hfText('درخواست لغو ثبت شد.'); } catch (error) { hfText(error.message); cancel.disabled = false; } });
        card.append(resume, cancel);
      }
      hfImports.append(card);
    });
  }
  root.querySelector('[data-hf-search]').addEventListener('submit', async event => {
    event.preventDefault(); const button = event.currentTarget.querySelector('button'); button.disabled = true; hfText('در حال جست‌وجوی منابع عمومی…');
    try {
      const query = root.querySelector('[data-hf-query]').value.trim();
      const data = await request('/workspace/datasets-search?id=' + project + '&q=' + encodeURIComponent(query));
      hfResults.replaceChildren(); hfDetail.hidden = true;
      data.datasets.forEach(dataset => {
        const card = hfNode('article', ''); hfMetadata(dataset, card);
        const inspect = hfNode('button', 'بررسی فایل‌ها و امکان دریافت'); inspect.type = 'button';
        inspect.addEventListener('click', async () => { inspect.disabled = true; try { await hfShow(dataset.id); } catch (error) { hfText(error.message); } finally { inspect.disabled = false; } });
        card.append(inspect); hfResults.append(card);
      });
      hfText(data.datasets.length ? 'نتیجه جست‌وجو؛ امکان دریافت پس از بررسی فایل و سهمیه مشخص می‌شود.' : 'دیتاست عمومی سازگار با جست‌وجو پیدا نشد.');
    } catch (error) { hfText(error.message); }
    finally { button.disabled = false; }
  });
  root.querySelector('[data-hf-refresh]').addEventListener('click', () => hfRefresh().catch(error => hfText(error.message)));
  hfRefresh().catch(error => hfText(error.message));
  Object.entries(buttons).forEach(([action, button]) => button.addEventListener('click', () => act(action)));
  code.addEventListener('input', () => { dirty = true; updateLines(); refreshButtons(); });
  code.addEventListener('scroll', () => { lines.scrollTop = code.scrollTop; });
  code.addEventListener('keydown', event => {
    if (event.key === 'Tab' && !code.readOnly && !code.disabled) { event.preventDefault(); code.setRangeText('    ', code.selectionStart, code.selectionEnd, 'end'); dirty = true; updateLines(); }
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') { event.preventDefault(); act('save'); }
  });
  window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
  load().catch(error => message(error.message));
})();
