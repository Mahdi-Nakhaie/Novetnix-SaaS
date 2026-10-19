const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const test = require('node:test');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../public/assets/darycheh.js'), 'utf8');

function fixture(kind = 'multi', values = ['a'], min = 2, max = 2) {
  const events = {}, windowEvents = {}, attributes = {};
  const status = { hidden: true }, error = { hidden: true }, edits = {};
  const button = { disabled: false };
  const control = { focus() { this.focused = true; } };
  const group = {
    dataset: { drKind: kind, drMin: String(min), drMax: String(max) },
    querySelectorAll() { return values.map(value => ({ value })); },
    querySelector() { return control; },
    setAttribute(name, value) { attributes[name] = value; },
    removeAttribute(name) { delete attributes[name]; }
  };
  const form = {
    matches: () => true, querySelectorAll: () => [group],
    setAttribute(name, value) { attributes['form:' + name] = value; },
    removeAttribute(name) { delete attributes['form:' + name]; }
  };
  const page = {
    addEventListener(name, listener) { events[name] = listener; },
    querySelectorAll: selector => selector === 'form.dr-form' ? [form] : [button]
  };
  vm.runInNewContext(source, {
    document: {
      querySelector: () => page,
      getElementById: id => ({ 'dr-save-status': status, 'dr-form-error': error, 'dr-edit-status': edits }[id])
    },
    window: { addEventListener(name, listener) { windowEvents[name] = listener; } }
  });
  return {
    status, error, edits, button, control, attributes, windowEvents,
    submit() {
      const event = { target: form, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
      events.submit(event);
      return event;
    },
    change() { events.change({ target: { closest: selector => selector === 'form.dr-form' ? form : group } }); }
  };
}

test('selection count is enforced, announced and focused without posting', () => {
  const f = fixture();
  assert.equal(f.submit().defaultPrevented, true);
  assert.equal(f.error.hidden, false);
  assert.equal(f.control.focused, true);
  assert.equal(f.attributes['aria-invalid'], 'true');
  assert.equal(f.status.hidden, true);
  assert.equal(f.button.disabled, false);
  f.change();
  assert.equal(f.error.hidden, true);
  assert.equal(f.attributes['aria-invalid'], undefined);
  assert.match(f.edits.textContent, /ذخیره/);
});

test('order disallows duplicates and respects minimum and maximum', () => {
  for (const values of [['a', 'a'], [], ['a', 'b', 'c']]) {
    const f = fixture('order', values, 1, 2);
    assert.equal(f.submit().defaultPrevented, true);
    assert.equal(f.button.disabled, false);
  }
});

test('valid submission uses native POST, prevents duplicates and resets on restored page', () => {
  const f = fixture('order', ['a', '', 'b'], 1, 5);
  assert.equal(f.submit().defaultPrevented, false);
  assert.equal(f.status.hidden, false);
  assert.equal(f.button.disabled, true);
  assert.equal(f.attributes['form:aria-busy'], 'true');
  assert.equal(f.submit().defaultPrevented, true);
  f.windowEvents.pageshow();
  assert.equal(f.button.disabled, false);
  assert.equal(f.status.hidden, true);
  assert.equal(f.attributes['form:aria-busy'], undefined);
  assert.equal(f.submit().defaultPrevented, false);
  f.windowEvents.pagehide();
  assert.equal(f.button.disabled, false);
});

test('single-choice controls retain native required validation', () => {
  const f = fixture('single', [], 1, 1);
  assert.equal(f.submit().defaultPrevented, false);
});

test('responsive stylesheet preserves motion preferences, focus, RTL and lightweight portal', () => {
  const css = fs.readFileSync(path.join(__dirname, '../public/assets/darycheh.css'), 'utf8');
  for (const width of [600, 1200, 1380]) assert.ok(css.includes(`@media(min-width:${width}px)`));
  for (const rule of ['prefers-reduced-motion:reduce', 'forced-colors:active', ':focus-visible', 'minmax(0,1fr)', 'margin-inline', 'inset-inline', 'min-height:48px']) assert.ok(css.includes(rule));
  assert.doesNotMatch(css, /gradient\(|url\(/);
  assert.equal((css.match(/\{/g) || []).length, (css.match(/\}/g) || []).length);
  const app = fs.readFileSync(path.join(__dirname, '../public/assets/app.js'), 'utf8');
  assert.ok(app.includes('event.target.matches("form.dr-form")'));
});
