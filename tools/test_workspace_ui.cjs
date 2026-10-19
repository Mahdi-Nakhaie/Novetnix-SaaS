const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const {webcrypto} = require('node:crypto');
const source = fs.readFileSync(require('node:path').join(__dirname, '../public/assets/workspace.js'), 'utf8');

class Element {
  constructor() { this.children = []; this.listeners = {}; this.dataset = {}; this.attributes = {}; this.value = ''; this.textContent = ''; this.disabled = false; this.hidden = false; }
  append(...items) { this.children.push(...items); }
  replaceChildren(...items) { this.children = items; }
  setAttribute(name, value) { this.attributes[name] = value; }
  addEventListener(name, handler) { this.listeners[name] = handler; }
  querySelectorAll(selector) { return this.children.flatMap(child => [...(selector === 'input:checked' && child.type === 'checkbox' && child.checked ? [child] : []), ...child.querySelectorAll(selector)]); }
  focus() {}
  set innerHTML(_) { throw new Error('Unsafe HTML insertion'); }
  async emit(name, extra = {}) { return this.listeners[name]?.({preventDefault() {}, currentTarget: this, ...extra}); }
}

async function fixture({assessment = false, searchError = false, empty = false} = {}) {
  const nodes = new Map();
  const get = selector => { if (!nodes.has(selector)) nodes.set(selector, new Element()); return nodes.get(selector); };
  const actions = ['save', 'reload', 'run', 'cancel', 'apply', 'new-file', 'new-folder', 'rename', 'remove-file', 'upload', 'delete', ...(assessment ? ['assess'] : [])];
  const buttons = Object.fromEntries(actions.map(action => { const button = new Element(); button.dataset.wsAction = action; return [action, button]; }));
  const root = new Element(); root.dataset = {wsProject: '7', csrf: 'test-csrf'};
  root.querySelector = get; root.querySelectorAll = () => Object.values(buttons);
  const searchButton = new Element(); get('[data-hf-search]').querySelector = () => searchButton;
  get('[data-ws-entry]').value = 'main.py';
  const calls = [];
  const dataset = {id: 'public/iris', description: '<img src=x onerror=alert(1)>', formats: ['csv'], approximate_bytes: 50, license: 'cc0', source: 'https://huggingface.co/datasets/public/iris'};
  const fetch = async (url, options) => {
    const data = options.body ? Object.fromEntries(options.body.entries()) : null;
    calls.push({url, data});
    let response;
    let ok = true;
    if (url.startsWith('/workspace/data?')) response = {files: {'main.py': {kind: 'file', data: btoa('print(42)')}}, revision: 1, runner_ready: true};
    else if (url === '/workspace/runtime') response = {python: '3.12.0', packages: {numpy: '2.2.3'}};
    else if (url.startsWith('/workspace/datasets-state')) response = {imports: []};
    else if (url.startsWith('/workspace/datasets-search')) { ok = !searchError; response = searchError ? {error: 'سرویس جست‌وجو موقتاً در دسترس نیست.'} : {datasets: empty ? [] : [dataset]}; }
    else if (url.startsWith('/workspace/datasets-detail')) response = {...dataset, revision: 'abc', notice: 'public', files: [{path: 'iris.csv', size: 50}]};
    else if (url === '/workspace/save') response = {revision: 2};
    else if (url === '/workspace/run') response = {local_id: 9};
    else if (url.startsWith('/workspace/run-state')) response = {local_id: 9, revision: 2, state: 'succeeded', stdout: '42', ...(assessment ? {assessment: {passed: true, checks: [{name: 'quality', passed: true, execution_state: 'succeeded'}]}, xp_awarded: true} : {})};
    else if (url === '/workspace/datasets-begin') response = {id: 1, state: 'succeeded', directory: 'datasets/example'};
    else throw new Error('Unexpected endpoint: ' + url);
    return {ok, headers: {get: () => 'application/json'}, json: async () => response};
  };
  vm.runInNewContext(source, {document: {querySelector: selector => selector === '[data-ws-project]' ? root : get(selector), querySelectorAll: () => [], createElement: () => new Element()}, window: {addEventListener() {}}, location: {}, fetch, FormData, AbortController, TextEncoder, TextDecoder, Uint8Array, btoa, atob, crypto: webcrypto, setTimeout, clearTimeout, confirm: () => true});
  for (let i = 0; i < 5; i++) await new Promise(setImmediate);
  return {get, buttons, calls, searchButton};
}

(async () => {
  const editor = await fixture();
  assert.equal(editor.buttons.run.disabled, false);
  await editor.buttons.run.emit('click');
  assert.equal(editor.calls.find(call => call.url === '/workspace/run').data.assess, '0');
  assert.match(editor.get('[data-ws-output]').textContent, /42/);
  assert.equal(editor.get('[data-ws-assessment]').hidden, true);
  editor.get('[data-ws-command]').value = 'help';
  await editor.get('[data-ws-terminal-form]').emit('submit');
  assert.equal(editor.calls.filter(call => call.url === '/workspace/run').length, 1);
  assert.match(editor.get('[data-ws-terminal-log]').textContent, /python main.py/);

  const challenge = await fixture({assessment: true});
  challenge.get('[data-ws-entry]').value = 'other.py';
  await challenge.buttons.assess.emit('click');
  const request = challenge.calls.find(call => call.url === '/workspace/run');
  assert.equal(request.data.assess, '1');
  assert.equal(request.data.entrypoint, 'main.py');
  assert.equal(challenge.get('[data-ws-assessment]').hidden, false);
  assert.match(challenge.get('[data-ws-assessment-title]').textContent, /امتیاز ثبت شد/);
  assert.equal(challenge.get('[data-ws-checks]').children.length, 1);

  const library = await fixture();
  assert.match(library.get('[data-hf-imports]').children[0].textContent, /هنوز دیتاستی/);
  library.get('[data-hf-query]').value = 'iris';
  await library.get('[data-hf-search]').emit('submit');
  const card = library.get('[data-hf-results]').children[0];
  assert.equal(card.children[1].textContent, '<img src=x onerror=alert(1)>');
  await card.children.at(-1).emit('click');
  const detail = library.get('[data-hf-detail]');
  assert.equal(detail.hidden, false);
  await detail.children.at(-1).emit('click');
  assert.equal(library.calls.some(call => call.url === '/workspace/datasets-begin'), false);
  const options = detail.children.find(child => child.className === 'ws-dataset-files');
  options.children[0].children[0].checked = true;
  await options.emit('change');
  await detail.children.at(-1).emit('click');
  const download = library.calls.find(call => call.url === '/workspace/datasets-begin');
  assert.deepEqual(JSON.parse(download.data.selected_files), ['iris.csv']);
  assert.equal(library.searchButton.disabled, false);

  const noResults = await fixture({empty: true});
  await noResults.get('[data-hf-search]').emit('submit');
  assert.equal(noResults.get('[data-hf-results]').children[0].className, 'ws-dataset-empty');
  const failed = await fixture({searchError: true});
  await failed.get('[data-hf-search]').emit('submit');
  assert.equal(failed.searchButton.disabled, false);
  assert.match(failed.get('[data-hf-message]').textContent, /دسترس نیست/);
  assert.equal(failed.get('[data-hf-results]').attributes['aria-busy'], 'false');
  console.log('Workspace UI: run, assessment, terminal help, dataset selection, empty and error states passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
