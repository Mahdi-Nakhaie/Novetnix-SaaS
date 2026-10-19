const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../public/assets/three-scene.js'), 'utf8');

function fixture(mode) {
  const events = {};
  const frames = new Map();
  let next = 0;
  let renders = 0;
  let disposals = 0;
  let intersection;
  const classes = new Set();
  const status = {};
  const button = { addEventListener: (key, fn) => { events['button:' + key] = fn; }, removeEventListener() {}, setAttribute() {} };
  const host = { classList: { add: name => classes.add(name), remove: name => classes.delete(name) }, querySelector: s => s.includes('status') ? status : button };
  const canvas = { parentElement: host, clientWidth: 500, clientHeight: 360,
    addEventListener: (key, fn) => { events[key] = fn; }, removeEventListener() {},
    getBoundingClientRect: () => ({ left: 0, top: 0, width: 500, height: 360 }) };
  class Object3D {
    constructor(geometry, material) {
      this.geometry = geometry; this.material = material; this.children = [];
      this.rotation = { set() {} }; this.position = { set() {} }; this.scale = { setScalar() {} };
    }
    add(child) { this.children.push(child); }
    clone() { return new Object3D(this.geometry, this.material); }
    traverse(fn) { fn(this); this.children.forEach(child => child.traverse(fn)); }
    updateProjectionMatrix() {}
  }
  class Resource {
    constructor() { this.color = { setHex() {} }; }
    dispose() { disposals++; }
    setAttribute() {}
    clone() { return new Resource(); }
  }
  const THREE = { Scene: Object3D, Group: Object3D, Mesh: Object3D, Points: Object3D, PerspectiveCamera: Object3D,
    IcosahedronGeometry: Resource, MeshBasicMaterial: Resource, TorusGeometry: Resource,
    BufferGeometry: Resource, BufferAttribute: Resource, PointsMaterial: Resource,
    WebGLRenderer: class {
      constructor() { if (mode === 'no-webgl') throw new Error('unsupported'); }
      setPixelRatio() {} setClearColor() {} setSize() {}
      render() { renders++; } dispose() { disposals++; }
    } };
  const document = { readyState: 'complete', hidden: false, querySelector: () => canvas,
    addEventListener: (key, fn) => { events[key] = fn; }, removeEventListener() {} };
  const window = { THREE: mode === 'missing-runtime' ? null : THREE, devicePixelRatio: 2,
    matchMedia: () => ({ matches: mode === 'reduced', addEventListener() {}, removeEventListener() {} }),
    requestAnimationFrame: fn => { frames.set(++next, fn); return next; },
    cancelAnimationFrame: id => frames.delete(id),
    addEventListener: (key, fn) => { events[key] = fn; }, removeEventListener() {} };
  vm.runInNewContext(source, { window, document, THREE, Float32Array, Set, Math,
    IntersectionObserver: class { constructor(fn) { intersection = fn; } observe() {} disconnect() {} } });
  function tick() { const pending = [...frames.values()]; frames.clear(); pending.forEach(fn => fn(100)); }
  return { events, frames, classes, status, button, tick, intersection: value => intersection([{ isIntersecting: value }]),
    rendered: () => renders, disposed: () => disposals };
}
for (const mode of ['missing-runtime', 'no-webgl']) {
  const f = fixture(mode);
  assert.equal(f.button.hidden, true);
  assert.match(f.status.textContent, /نمای ساده/);
  assert.equal(f.frames.size, 0);
}
const reduced = fixture('reduced');
reduced.tick();
assert.equal(reduced.rendered(), 1);
assert.equal(reduced.frames.size, 0);
const active = fixture('active');
active.tick();
assert.equal(active.frames.size, 1);
active.intersection(false);
assert.equal(active.frames.size, 0);
active.intersection(true);
active.events['button:click']();
active.tick();
assert.equal(active.frames.size, 0);
active.events.webglcontextlost({ preventDefault() {} });
assert.equal(active.classes.has('scene-ready'), false);
active.events.webglcontextrestored();
assert.equal(active.classes.has('scene-ready'), true);
active.events.pagehide({ persisted: false });
assert.equal(active.frames.size, 0);
assert.ok(active.disposed() > 0);
console.log('PASS: missing runtime, no WebGL, reduced motion, pause, visibility, context recovery and disposal (mocked; not GPU rendering)');
