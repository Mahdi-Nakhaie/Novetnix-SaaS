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
  const commandInput = root.querySelector('[data-ws-command]');
  const commandSubmit = root.querySelector('[data-ws-terminal-submit]');
  const terminalLog = root.querySelector('[data-ws-terminal-log]');
  const history = [];
  let historyIndex = 0, historyDraft = '', pendingCommand = '', terminalRun = false;
  const terminalPrint = text => {
    terminalLog.textContent = (terminalLog.textContent + '\n' + text).slice(-60000);
    terminalLog.scrollTop = terminalLog.scrollHeight;
  };
  const buttons = Object.fromEntries(Array.from(root.querySelectorAll('[data-ws-action]'), node => [node.dataset.wsAction, node]));
  let files = {}, selected = '', revision = 0, dirty = false, busy = false, ready = false, runner = false;
  let runId = null, requestKey = null, pendingEntry = '', pendingAssess = false, result = null, timer = null;
  let datasetPaths = new Set(), downloading = null, previewUrl = null, artifactUrls = [], uploadsValid = false;
  const tabs = Array.from(root.querySelectorAll('[data-ws-tab]'));
  function showPane(name) {
    tabs.forEach(tab => { const selected = tab.dataset.wsTab === name; tab.setAttribute('aria-selected', String(selected)); tab.tabIndex = selected ? 0 : -1; });
    root.querySelectorAll('[data-ws-pane]').forEach(pane => { pane.hidden = pane.dataset.wsPane !== name; });
  }
  tabs.forEach((tab, index) => {
    tab.addEventListener('click', () => showPane(tab.dataset.wsTab));
    tab.addEventListener('keydown', event => {
      if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
      event.preventDefault();
      const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowLeft' ? 1 : -1) + tabs.length) % tabs.length;
      showPane(tabs[next].dataset.wsTab); tabs[next].focus();
    });
  });
  function pngUrl(data) {
    try {
      const bytes = Uint8Array.from(atob(data), c => c.charCodeAt(0));
      if (![137, 80, 78, 71, 13, 10, 26, 10].every((value, i) => bytes[i] === value)) return null;
      return URL.createObjectURL(new Blob([bytes], {type: 'image/png'}));
    } catch (_) { return null; }
  }
  function renderPlots(items) {
    artifactUrls.forEach(url => URL.revokeObjectURL(url)); artifactUrls = [];
    const gallery = root.querySelector('[data-ws-plots]'); gallery.replaceChildren();
    for (const [name, file] of Object.entries(items || {})) {
      if (file.kind !== 'file' || !/\.png$/i.test(name)) continue;
      const url = pngUrl(file.data); if (!url) continue;
      artifactUrls.push(url);
      const card = document.createElement('figure');
      const image = document.createElement('img'); image.src = url; image.alt = 'نمودار یا تصویر خروجی: ' + name; image.loading = 'lazy';
      const caption = document.createElement('figcaption');
      const title = document.createElement('span'); title.textContent = name; title.dir = 'ltr';
      const download = document.createElement('a'); download.href = url; download.download = name.split('/').pop(); download.textContent = 'دانلود PNG';
      caption.append(title, download); card.append(image, caption); gallery.append(card);
    }
    root.querySelector('[data-ws-plot-count]').textContent = artifactUrls.length.toLocaleString('fa-IR');
    if (!artifactUrls.length) { const empty = document.createElement('p'); empty.className = 'ws-plots-empty'; empty.textContent = 'نموداری در خروجی این اجرا وجود ندارد. نمودار را باز نگه دار یا با plt.savefig("chart.png") ذخیره کن.'; gallery.append(empty); }
    return artifactUrls.length;
  }
  function refreshUploads() {
    const items = [...root.querySelector('[data-ws-upload]').files];
    const zip = root.querySelector('[data-ws-zip]').files[0];
    const list = root.querySelector('[data-ws-upload-list]'); list.replaceChildren();
    let error = '';
    for (const file of [...items, ...(zip ? [zip] : [])]) {
      const item = document.createElement('li');
      const name = document.createElement('span'); name.textContent = file.name; name.dir = 'ltr';
      const size = document.createElement('small'); size.textContent = (file.size / 1024).toLocaleString('fa-IR', {maximumFractionDigits: 1}) + ' کیلوبایت';
      item.append(name, size); list.append(item);
    }
    if (items.some(file => file.size > 1048576 || !pathValid(file.name) || !/\.(py|csv|json|txt|md|png|joblib|pkl|npy|npz)$/i.test(file.name))) error = 'نام، قالب یا حجم فایل معتبر نیست؛ هر فایل حداکثر ۱ مگابایت.';
    if (zip && (!/\.zip$/i.test(zip.name) || zip.size > 5242880)) error = 'مجموعه باید ZIP و حداکثر ۵ مگابایت باشد.';
    if (items.length > 100 || items.reduce((total, file) => total + file.size, 0) > 5242880) error = 'مجموع فایل‌ها بیشتر از سهمیهٔ ۵ مگابایتی یا ۱۰۰ فایل است.';
    uploadsValid = Boolean(items.length || zip) && !error;
    const note = root.querySelector('[data-ws-upload-note]'); note.textContent = error || (uploadsValid ? 'آمادهٔ بارگذاری؛ فایل هم‌نام جایگزین می‌شود. سهمیه و محتوای ZIP روی سرور بررسی می‌شوند.' : 'هر فایل حداکثر ۱ مگابایت؛ کل پروژه ۵ مگابایت.'); note.dataset.error = String(Boolean(error));
    refreshButtons();
  }
  root.querySelector('[data-ws-upload]').addEventListener('change', refreshUploads);
  root.querySelector('[data-ws-zip]').addEventListener('change', refreshUploads);
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
    if (buttons.assess) buttons.assess.disabled = !ready || locked || !runner || active() || requestKey !== null;
    buttons.apply.disabled = !ready || locked || active() || dirty || !result || !['succeeded', 'program_error'].includes(result.state) || result.revision !== revision;
    buttons['remove-file'].disabled = !ready || locked || !selected || datasetPaths.has(selected);
    commandInput.disabled = commandSubmit.disabled = !ready || locked || !runner || active() || requestKey !== null;
    buttons.rename.disabled = !ready || locked || !selected || datasetPaths.has(selected);
    buttons.upload.disabled = !ready || locked || !uploadsValid;
    buttons.upload.textContent = busy ? 'در حال پردازش…' : 'بارگذاری فایل‌های انتخابی';
  }
  function updateLines() {
    const count = code.value.split('\n').length;
    lines.textContent = Array.from({length: count}, (_, i) => i + 1).join('\n');
    root.querySelector('[data-ws-editor-status]').textContent = (selected ? selected.split('.').pop().toUpperCase() : 'Python') + (binary(selected) ? ' · فایل باینری' : ' · UTF-8 · ' + count.toLocaleString('fa-IR') + ' خط') + (datasetPaths.has(selected) ? ' · فقط‌خواندنی' : dirty ? ' · ذخیره‌نشده' : '');
  }
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
    if (previewUrl) URL.revokeObjectURL(previewUrl); previewUrl = null;
    const preview = root.querySelector('[data-ws-image-preview]'); preview.hidden = true;
    root.querySelector('[data-ws-code-wrap]').hidden = false;
    if (item?.kind === 'file' && /\.png$/i.test(name)) {
      previewUrl = pngUrl(item.data);
      if (previewUrl) { root.querySelector('[data-ws-image]').src = previewUrl; preview.hidden = false; root.querySelector('[data-ws-code-wrap]').hidden = true; }
      else message('این فایل محتوای PNG معتبر ندارد؛ نسخهٔ اصلی را دانلود کنید.');
    }
    updateLines(); renderTree(); refreshButtons();
  }
  function renderTree() {
    tree.replaceChildren();
    root.querySelector('[data-ws-file-count]').textContent = Object.values(files).filter(file => file.kind === 'file').length.toLocaleString('fa-IR');
    if (!Object.keys(files).length) { const empty = document.createElement('p'); empty.textContent = 'اولین فایل پروژه را بساز یا بارگذاری کن.'; empty.className = 'ws-tree-empty'; tree.append(empty); }
    Object.keys(files).sort().forEach(name => {
      const button = document.createElement('button');
      button.type = 'button'; button.dir = 'ltr'; button.title = name;
      const extension = document.createElement('span'); extension.className = 'ws-file-type'; extension.textContent = files[name].kind === 'directory' ? 'DIR' : name.split('.').pop().slice(0, 5).toUpperCase();
      const path = document.createElement('span'); path.className = 'ws-file-path'; path.textContent = name;
      button.append(extension, path);
      if (datasetPaths.has(name)) { const lock = document.createElement('small'); lock.textContent = 'فقط‌خواندنی'; lock.dir = 'rtl'; button.append(lock); }
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
      terminalRun = state.pending_run.entrypoint === 'noventix_terminal.py';
      if (state.pending_run.remote_id) runId = Number(state.pending_run.id);
      else { requestKey = state.pending_run.request_key; pendingEntry = state.pending_run.entrypoint; }
    }
    select(files['main.py'] ? 'main.py' : Object.keys(files)[0] || '');
    message('نسخه ' + revision.toLocaleString('fa-IR') + ' از سرور باز شد.' + (runner ? '' : ' موتور اجرا راه‌اندازی نشده است؛ ذخیره فایل‌ها فعال است.'));
    refreshButtons();
    const runtimeNode = root.querySelector('[data-ws-runtime]');
    if (!runner) {
      runtimeNode.textContent = 'موتور اجرای Python در دسترس نیست؛ ذخیره و ویرایش فایل‌ها فعال است.';
      runtimeNode.dataset.state = 'offline';
    } else {
      runtimeNode.textContent = 'در حال بررسی اتصال به موتور Python…';
      runtimeNode.dataset.state = 'checking';
      request('/workspace/runtime').then(runtime => {
        runtimeNode.textContent = 'Python ' + runtime.python + ' | ' + Object.entries(runtime.packages).map(([name, version]) => name + '==' + version).join(' | ');
        runtimeNode.dataset.state = 'ready';
      }).catch(error => {
        runner = false; refreshButtons();
        runtimeNode.textContent = 'اتصال به موتور Python برقرار نشد؛ ذخیره و ویرایش فایل‌ها فعال است.';
        runtimeNode.dataset.state = 'offline';
        message(error.message + ' ذخیره فایل‌ها همچنان در دسترس است.');
      });
    }
    if (runId && !timer) poll();
  }
  async function save() {
    commitEditor();
    const state = await request('/workspace/save', form({files: JSON.stringify(files)}));
    revision = state.revision; dirty = false; updateLines();
    message('فایل‌ها در حساب شما ذخیره شدند؛ نسخه ' + revision.toLocaleString('fa-IR') + '.');
    refreshButtons();
  }
  const labels = {queued: 'در صف', running: 'در حال اجرا', cancelling: 'در حال توقف', cancelled: 'متوقف‌شده', succeeded: 'موفق', program_error: 'خطای برنامه', dependency_error: 'وابستگی غیرمجاز یا نسخه ناسازگار', memory_limit: 'پایان سهمیه حافظه', cpu_limit: 'پایان سهمیه پردازنده', artifact_limit: 'محدودیت فایل خروجی', timeout: 'پایان مهلت اجرا', output_limit: 'خروجی بیش از حد مجاز', infrastructure_error: 'خطای زیرساخت اجرا'};
  async function poll() {
    clearTimeout(timer); timer = null;
    if (!runId) return;
    try {
      const state = await request('/workspace/run-state?id=' + runId);
      runState.textContent = labels[state.state] || state.state; runState.dataset.state = state.state;
      output.textContent = state.stdout || (['queued', 'running', 'cancelling'].includes(state.state) ? 'در انتظار نتیجهٔ اجرا…' : 'خروجی متنی برای این اجرا ثبت نشده است.');
      root.querySelector('[data-ws-errors]').textContent = state.stderr || '';
      root.querySelector('[data-ws-error-panel]').hidden = !state.stderr;
      if (['queued', 'running', 'cancelling'].includes(state.state)) timer = setTimeout(poll, 2000);
      else {
        if (terminalRun) terminalPrint((state.stdout || '') + (state.stderr ? '\n' + state.stderr : '') + '\n[' + (labels[state.state] || state.state) + ']');
        const assessment = root.querySelector('[data-ws-assessment]');
        assessment.hidden = !state.assessment;
        const checks = root.querySelector('[data-ws-checks]'); checks.replaceChildren();
        if (state.assessment) {
          root.querySelector('[data-ws-assessment-title]').textContent = state.assessment.passed ? (state.xp_awarded ? 'ارزیابی موفق؛ امتیاز ثبت شد.' : 'ارزیابی موفق؛ امتیاز قبلاً ثبت شده یا نسخه تغییر کرده است.') : 'ارزیابی ناموفق؛ معیارها را بررسی و دوباره تلاش کن.';
          for (const check of state.assessment.checks || []) {
            const item = document.createElement('li');
            item.textContent = (check.passed ? 'موفق' : 'ناموفق') + ' · ' + check.name + ' · ' + (labels[check.execution_state] || check.execution_state);
            checks.append(item);
          }
        }
        const plots = renderPlots(state.files);
        if (plots) showPane('plots');
        else if (state.stderr && !terminalRun) showPane('output');
        result = state; runId = null; requestKey = null; pendingCommand = ''; pendingAssess = false; terminalRun = false;
        message('اجرا پایان یافت؛ برای نگهداری فایل‌های تولیدشده دکمه ذخیره خروجی را بزنید.');
      }
    } catch (error) { message(error.message + ' برای ادامه، بررسی وضعیت اجرا را بزنید؛ اجرای جدیدی ارسال نمی‌شود.'); }
    refreshButtons();
  }
  async function startRun(command = '', assess = false) {
    if (runId) { await poll(); return; }
    if (!requestKey) {
      const target = assess ? 'main.py' : entry.value.trim();
      if (!command && (!pathValid(target) || !target.endsWith('.py') || files[target]?.kind !== 'file')) throw new Error('فایل اجرایی پایتون موجود و معتبر انتخاب کنید.');
      await save(); requestKey = crypto.randomUUID(); pendingEntry = target; pendingCommand = command; pendingAssess = assess; terminalRun = Boolean(command);
    }
    const state = await request('/workspace/run', form({request_key: requestKey, entrypoint: pendingEntry, terminal_command: pendingCommand, assess: pendingAssess ? '1' : '0'}));
    root.querySelector('[data-ws-assessment]').hidden = true;
    renderPlots({}); showPane(command ? 'terminal' : 'output');
    root.querySelector('[data-ws-error-panel]').hidden = true;
    output.textContent = 'در انتظار شروع اجرا…';
    runId = state.local_id; result = null; runState.textContent = 'در صف اجرا'; runState.dataset.state = 'queued'; await poll();
  }
  root.querySelector('[data-ws-terminal-form]').addEventListener('submit', async event => {
    event.preventDefault();
    const command = commandInput.value.trim();
    if (!command || busy || !ready || !runner || runId || requestKey || downloading !== null) return;
    history.push(command); historyIndex = history.length; historyDraft = ''; commandInput.value = '';
    terminalPrint('/workspace $ ' + command);
    if (command === 'clear' || command === 'cls') { terminalLog.textContent = ''; return; }
    if (command === 'help') {
      terminalPrint('python main.py [args]\npython -c "print(2 + 2)"\npython --version\npip list\nls / dir\ncat filename / type filename\npwd\nclear / cls\n\nهر فرمان اجرای مستقل است؛ متغیرها بین اجراها باقی نمی‌مانند. فایل خروجی را با دکمهٔ ذخیره خروجی نگه دارید. نصب اینترنتی و shell غیرفعال است.');
      return;
    }
    busy = true; refreshButtons();
    try { await startRun(command); }
    catch (error) { terminalPrint(error.message); message(error.message); }
    finally { busy = false; refreshButtons(); if (!commandInput.disabled) commandInput.focus(); }
  });
  commandInput.addEventListener('keydown', event => {
    if (!['ArrowUp', 'ArrowDown'].includes(event.key)) return;
    event.preventDefault();
    if (historyIndex === history.length) historyDraft = commandInput.value;
    historyIndex = Math.max(0, Math.min(history.length, historyIndex + (event.key === 'ArrowUp' ? -1 : 1)));
    commandInput.value = history[historyIndex] ?? historyDraft;
  });
  root.querySelector('[data-ws-terminal-clear]').addEventListener('click', () => { terminalLog.textContent = ''; });
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
        if (!uploadsValid) throw new Error('ابتدا فایل معتبر انتخاب کن.');
        await save();
        const data = form();
        for (const file of root.querySelector('[data-ws-upload]').files) data.append('files[]', file);
        const zip = root.querySelector('[data-ws-zip]').files[0];
        if (zip) data.append('archive', zip);
        await request('/workspace/upload', data); await load();
        root.querySelector('[data-ws-upload]').value = ''; root.querySelector('[data-ws-zip]').value = ''; refreshUploads();
      }
      if (action === 'run') await startRun();
      if (action === 'assess') await startRun('', true);
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
    const description = hfNode('p', dataset.description || 'توضیحی برای این دیتاست ثبت نشده است.'); description.className = 'ws-dataset-description'; container.append(description);
    const metadata = hfNode('div', ''); metadata.className = 'ws-dataset-tags';
    for (const text of [dataset.formats.join(' · ') || 'قالب نامشخص', bytesLabel(dataset.approximate_bytes), 'مجوز: ' + (Array.isArray(dataset.license) ? dataset.license.join('، ') : dataset.license || 'نامشخص')]) metadata.append(hfNode('span', text));
    container.append(metadata);
    const source = hfNode('a', 'منبع و شرایط استفاده'); source.className = 'ws-text-link'; source.href = dataset.source; source.target = '_blank'; source.rel = 'noopener noreferrer'; container.append(source);
  }
  async function hfShow(id) {
    hfText('در حال بررسی دسترسی، نسخه و فایل‌ها…');
    const data = await request('/workspace/datasets-detail?id=' + project + '&dataset=' + encodeURIComponent(id));
    hfDetail.replaceChildren(); hfDetail.hidden = false; hfMetadata(data, hfDetail);
    hfDetail.append(hfNode('p', data.notice));
    const options = hfNode('div', ''); options.className = 'ws-dataset-files';
    data.files.forEach(file => {
      const label = hfNode('label', ''); const input = document.createElement('input'); input.type = 'checkbox'; input.value = file.path;
      const text = hfNode('span', file.path); text.className = 'ws-dataset-file-name'; text.dir = 'ltr';
      const size = hfNode('small', bytesLabel(file.size)); size.className = 'ws-dataset-file-size';
      const kind = hfNode('span', file.path.split('.').pop().toUpperCase()); kind.className = 'ws-dataset-file-type';
      label.append(input, kind, text, size); options.append(label);
    });
    const heading = hfNode('h4', data.files.length.toLocaleString('fa-IR') + ' فایل قابل دریافت'); heading.className = 'ws-dataset-file-heading'; hfDetail.append(heading, options);
    const add = hfNode('button', 'افزودن فایل‌های انتخابی به پروژه'); add.className = 'btn btn-primary'; add.type = 'button'; add.disabled = !data.files.length;
    const selection = hfNode('p', 'هیچ فایلی انتخاب نشده است.'); selection.setAttribute('role', 'status');
    options.addEventListener('change', () => {
      const count = options.querySelectorAll('input:checked').length;
      selection.textContent = count.toLocaleString('fa-IR') + ' فایل از حداکثر ۱۰ فایل انتخاب شده است.';
    });
    hfDetail.append(selection);
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
    if (!state.imports.length) {
      const empty = hfNode('p', 'هنوز دیتاستی اضافه نکرده‌ای. با جست‌وجوی بالا اولین دادهٔ پروژه را پیدا کن.'); empty.className = 'ws-dataset-empty'; hfImports.append(empty);
    }
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
        resume.className = 'btn btn-outline'; cancel.className = 'btn btn-outline'; card.append(resume, cancel);
      }
      hfImports.append(card);
    });
  }
  root.querySelector('[data-hf-search]').addEventListener('submit', async event => {
    event.preventDefault(); const button = event.currentTarget.querySelector('button'); button.disabled = true; button.textContent = 'در حال جست‌وجو…'; hfResults.setAttribute('aria-busy', 'true'); hfText('در حال جست‌وجوی منابع عمومی…');
    try {
      const query = root.querySelector('[data-hf-query]').value.trim();
      const data = await request('/workspace/datasets-search?id=' + project + '&q=' + encodeURIComponent(query));
      hfResults.replaceChildren(); hfDetail.hidden = true;
      data.datasets.forEach(dataset => {
        const card = hfNode('article', ''); hfMetadata(dataset, card);
        const inspect = hfNode('button', 'بررسی فایل‌ها'); inspect.className = 'btn btn-outline'; inspect.type = 'button';
        inspect.addEventListener('click', async () => { inspect.disabled = true; try { await hfShow(dataset.id); } catch (error) { hfText(error.message); } finally { inspect.disabled = false; } });
        card.append(inspect); hfResults.append(card);
      });
      if (!data.datasets.length) { const empty = hfNode('p', 'نتیجه‌ای پیدا نشد؛ یک نام کوتاه‌تر یا موضوع دیگری را امتحان کن.'); empty.className = 'ws-dataset-empty'; hfResults.append(empty); }
      hfText(data.datasets.length ? data.datasets.length.toLocaleString('fa-IR') + ' دیتاست پیدا شد؛ برای انتخاب فایل‌ها، جزئیات را بررسی کن.' : 'دیتاست عمومی سازگار با جست‌وجو پیدا نشد.');
    } catch (error) { hfText(error.message); }
    finally { button.disabled = false; button.textContent = 'جست‌وجوی دیتاست'; hfResults.setAttribute('aria-busy', 'false'); }
  });
  root.querySelector('[data-hf-refresh]').addEventListener('click', () => hfRefresh().catch(error => hfText(error.message)));
  hfRefresh().catch(error => hfText(error.message));
  Object.entries(buttons).forEach(([action, button]) => button.addEventListener('click', () => act(action)));
  const hintsPanel = root.querySelector('[data-ws-hints]');
  if (hintsPanel) {
    const hintBalance = hintsPanel.querySelector('[data-ws-hint-balance]');
    hintsPanel.addEventListener('click', async event => {
      const button = event.target.closest('[data-ws-hint-unlock]');
      if (!button || button.disabled) return;
      button.disabled = true;
      try {
        const result = await request('/workspace/hint-unlock', form({id: project, hint: button.dataset.wsHintUnlock}));
        const item = button.closest('[data-ws-hint]');
        item.classList.add('is-unlocked');
        item.querySelector('.ws-hint-text').textContent = result.hint;
        item.querySelector('.ws-hint-head').insertAdjacentHTML('beforeend', '<span class="ws-hint-state">بازشده</span>');
        button.remove();
        if (hintBalance) hintBalance.textContent = Number(result.balance).toLocaleString('fa-IR');
        message('راهنمایی باز شد؛ ' + Number(result.balance).toLocaleString('fa-IR') + ' سکه باقی مانده است.');
      } catch (error) { message(error.message); button.disabled = false; }
    });
  }
  code.addEventListener('input', () => { dirty = true; updateLines(); refreshButtons(); });
  code.addEventListener('scroll', () => { lines.scrollTop = code.scrollTop; });
  code.addEventListener('keydown', event => {
    if (event.key === 'Tab' && !code.readOnly && !code.disabled) { event.preventDefault(); code.setRangeText('    ', code.selectionStart, code.selectionEnd, 'end'); dirty = true; updateLines(); }
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') { event.preventDefault(); act('save'); }
  });
  window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
  window.addEventListener('pagehide', () => { artifactUrls.forEach(url => URL.revokeObjectURL(url)); if (previewUrl) URL.revokeObjectURL(previewUrl); });
  load().catch(error => message(error.message));
})();
