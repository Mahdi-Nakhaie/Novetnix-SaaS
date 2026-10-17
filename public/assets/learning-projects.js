(() => {
  'use strict';
  const root = document.querySelector('[data-lp-id]');
  if (!root) return;
  const $ = s => root.querySelector(`[data-lp-${s}]`);
  const id = root.dataset.lpId, csrf = root.dataset.csrf;
  let files = {}, revision = 0, selected = '', busy = false, ready = false, dirty = false, pending = null;
  const encode = s => btoa(Array.from(new TextEncoder().encode(s), b => String.fromCharCode(b)).join(''));
  const decode = s => new TextDecoder().decode(Uint8Array.from(atob(s), c => c.charCodeAt(0)));
  async function request(path, data) {
    const response = await fetch(`/workspace/${path}`, data ? {method: 'POST', body: new URLSearchParams({...data, csrf, id})} : {});
    const body = await response.json();
    if (!response.ok) throw new Error(body.error || 'درخواست انجام نشد.');
    return body;
  }
  function controls() {
    root.querySelectorAll('[data-lp-action]').forEach(button => {
      button.disabled = busy || !revision || (['run', 'assess'].includes(button.dataset.lpAction) && !ready);
    });
    $('code').disabled = busy || !selected;
    $('file').disabled = busy;
  }
  function stash() { if (selected) files[selected] = {kind: 'file', data: encode($('code').value)}; }
  function select(name) { selected = name; $('file').value = name; $('code').value = decode(files[name].data); }
  async function load() {
    const data = await request(`data?id=${id}`);
    files = data.files; revision = data.revision; ready = data.runner_ready; pending = data.pending_run;
    $('file').replaceChildren();
    for (const name of Object.keys(files).filter(n => /\.(py|json|csv|txt)$/.test(n))) {
      const option = document.createElement('option'); option.value = name; option.textContent = name; $('file').append(option);
    }
    select('main.py'); dirty = false;
    $('status').textContent = ready ? 'کد هنوز اجرا نشده است؛ ذخیره، اجرا و ارزیابی مستقل‌اند.' : 'سرویس اجرای ایزوله راه‌اندازی نشده است؛ ذخیره فعال است، اجرا و XP در دسترس نیست.';
    controls();
  }
  async function save() {
    stash();
    if (!dirty) return;
    const result = await request('save', {files: JSON.stringify(files), revision});
    revision = result.revision; dirty = false;
  }
  async function poll(localId) {
    for (let count = 0; count < 160; count++) {
      const result = await request(`run-state?id=${localId}`);
      $('status').textContent = `وضعیت اجرای واقعی: ${result.state} · نسخه ${result.revision}`;
      if (!['queued', 'running', 'cancelling', 'submitting'].includes(result.state)) {
        $('output').textContent = (result.stdout || '') + '\n' + (result.stderr || '');
        $('checks').replaceChildren(); $('artifacts').replaceChildren();
        for (const check of result.assessment?.checks || []) {
          const line = document.createElement('p');
          line.textContent = `${check.passed ? 'PASS' : 'FAIL'} · ${check.name} · ${check.execution_state}`;
          $('checks').append(line);
        }
        for (const [name, file] of Object.entries(result.files || {})) {
          if (!['result.json', 'chart.png', 'cleaned.csv', 'model.json'].includes(name) || file.kind !== 'file') continue;
          const bytes = Uint8Array.from(atob(file.data), c => c.charCodeAt(0));
          const url = URL.createObjectURL(new Blob([bytes], {type: name.endsWith('.png') ? 'image/png' : 'application/octet-stream'}));
          const link = document.createElement('a'); link.href = url; link.download = name; link.textContent = `دانلود ${name}`; $('artifacts').append(link);
          if (name === 'chart.png') {
            const image = document.createElement('img');
            image.src = `data:image/png;base64,${file.data}`; image.alt = 'نمودار تولیدشده توسط کد شما';
            $('artifacts').append(image);
          } else if (name === 'result.json') {
            const output = document.createElement('pre'); output.className = 'lp-output'; output.dir = 'ltr'; output.textContent = decode(file.data); $('artifacts').append(output);
          }
          setTimeout(() => URL.revokeObjectURL(url), 300000);
        }
        if (result.assessment) {
          $('status').textContent += result.assessment.passed ? ' · ارزیابی موفق' : ' · ارزیابی ناموفق؛ XP جدیدی تعلق نمی‌گیرد.';
          if (result.xp_awarded) $('completion').textContent = 'پروژه تکمیل شد؛ XP یک‌بار در حساب ثبت شد.';
          else if (result.assessment.passed) $('status').textContent += ' · جایزه قبلاً ثبت شده یا نسخه کد تغییر کرده است.';
        }
        pending = null;
        return;
      }
      await new Promise(resolve => setTimeout(resolve, 1500));
    }
    throw new Error('اجرا هنوز پایان نیافته؛ صفحه را دوباره باز کنید تا وضعیت همان اجرا بازیابی شود.');
  }
  $('code').addEventListener('input', () => { dirty = true; $('status').textContent = 'تغییرات ذخیره نشده‌اند؛ ارزیابی قبلی برای این کد معتبر نیست.'; });
  $('file').addEventListener('change', () => { const name = $('file').value; stash(); select(name); });
  root.addEventListener('click', async event => {
    const button = event.target.closest('[data-lp-action]');
    if (!button || busy) return;
    busy = true; controls();
    try {
      const action = button.dataset.lpAction;
      if (action === 'solution') {
        const result = await request(`project-solution?id=${id}`);
        $('solution').textContent = result.solution; $('reference').hidden = false; $('reference').open = true;
      } else {
        await save();
        if (action === 'save') $('status').textContent = `نسخه ${revision} ذخیره شد؛ هنوز ارزیابی نشده است.`;
        else {
          $('output').textContent = ''; $('checks').replaceChildren();
          const result = await request('run', {revision, entrypoint: 'main.py', request_key: crypto.randomUUID(), assess: action === 'assess' ? '1' : '0'});
          await poll(result.local_id);
        }
      }
    } catch (error) { $('status').textContent = error.message; }
    finally { busy = false; controls(); }
  });
  window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
  load().then(async () => { if (pending) { busy = true; controls(); try { await poll(pending.id); } finally { busy = false; controls(); } } }).catch(error => { $('status').textContent = error.message; });
})();
