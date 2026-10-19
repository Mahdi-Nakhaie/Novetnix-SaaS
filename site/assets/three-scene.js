(function () {
  "use strict";

  function createScene(THREE) {
    var scene = new THREE.Scene();
    var group = new THREE.Group();
    scene.add(group);
    var core = new THREE.Mesh(new THREE.IcosahedronGeometry(1.25, 2),
      new THREE.MeshBasicMaterial({ color: 0x9b4ddd, wireframe: true, transparent: true, opacity: 0.72 }));
    group.add(core);
    var inner = new THREE.Mesh(new THREE.IcosahedronGeometry(0.86, 1),
      new THREE.MeshBasicMaterial({ color: 0xf0b429, wireframe: true, transparent: true, opacity: 0.6 }));
    inner.rotation.set(0.4, 0.2, 0.25);
    group.add(inner);
    var ring = new THREE.Mesh(new THREE.TorusGeometry(1.68, 0.018, 8, 96),
      new THREE.MeshBasicMaterial({ color: 0xc08bff, transparent: true, opacity: 0.9 }));
    ring.rotation.set(0.85, 0.2, -0.25);
    group.add(ring);
    var ringTwo = ring.clone();
    ringTwo.material = ring.material.clone();
    ringTwo.material.color.setHex(0xf0b429);
    ringTwo.rotation.set(-0.35, 0.75, 0.35);
    ringTwo.scale.setScalar(0.78);
    group.add(ringTwo);
    var positions = new Float32Array(180 * 3);
    for (var i = 0; i < 180; i += 1) {
      var radius = 2.05 + Math.random() * 1.8;
      var theta = Math.random() * Math.PI * 2;
      var phi = Math.acos(2 * Math.random() - 1);
      positions[i * 3] = radius * Math.sin(phi) * Math.cos(theta);
      positions[i * 3 + 1] = radius * Math.cos(phi) * 0.75;
      positions[i * 3 + 2] = radius * Math.sin(phi) * Math.sin(theta);
    }
    var geometry = new THREE.BufferGeometry();
    geometry.setAttribute("position", new THREE.BufferAttribute(positions, 3));
    var particles = new THREE.Points(geometry,
      new THREE.PointsMaterial({ color: 0xc08bff, size: 0.025, transparent: true, opacity: 0.7 }));
    group.add(particles);
    return { scene: scene, group: group, ring: ring, ringTwo: ringTwo, particles: particles };
  }

  function start() {
    var canvas = document.querySelector("[data-three-scene]");
    if (!canvas) return;
    var host = canvas.parentElement;
    var status = host.querySelector("[data-scene-status]");
    var button = host.querySelector("[data-scene-toggle]");
    function fallback() {
      host.classList.remove("scene-ready");
      if (status) status.textContent = "نمای ساده فعال است؛ همهٔ امکانات سایت در دسترس‌اند.";
      if (button) button.hidden = true;
    }
    if (!window.THREE) { fallback(); return; }
    var renderer;
    try {
      renderer = new THREE.WebGLRenderer({ canvas: canvas, alpha: true, antialias: true, powerPreference: "low-power" });
    } catch (error) { fallback(); return; }
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.5));
    renderer.setClearColor(0x000000, 0);
    var world = createScene(window.THREE);
    var camera = new THREE.PerspectiveCamera(40, 1, 0.1, 100);
    camera.position.set(0, 0.1, 7.2);
    var motion = window.matchMedia("(prefers-reduced-motion: reduce)");
    var paused = motion.matches;
    var visible = true;
    var lost = false;
    var frame = null;
    var elapsed = 0;
    var last = 0;
    var pointer = { x: 0, y: 0 };
    var rotation = { x: 0, y: 0 };

    function draw(time) {
      frame = null;
      if (lost || !visible || document.hidden) return;
      if (!paused && last) elapsed += Math.min((time - last) / 1000, 0.05);
      last = time;
      rotation.x += (pointer.x - rotation.x) * 0.08;
      rotation.y += (pointer.y - rotation.y) * 0.08;
      world.group.rotation.y = elapsed * 0.16 + rotation.x * 0.3;
      world.group.rotation.x = Math.sin(elapsed * 0.35) * 0.08 + rotation.y * 0.15;
      world.ring.rotation.z = elapsed * 0.12 - 0.25;
      world.ringTwo.rotation.z = -elapsed * 0.16 + 0.35;
      world.particles.rotation.y = -elapsed * 0.035;
      renderer.render(world.scene, camera);
      if (!paused) schedule();
    }
    function schedule() {
      if (frame === null && visible && !document.hidden && !lost) frame = window.requestAnimationFrame(draw);
    }
    function suspend() {
      if (frame !== null) window.cancelAnimationFrame(frame);
      frame = null;
      last = 0;
    }
    function resize() {
      var width = Math.max(canvas.clientWidth, 1);
      var height = Math.max(canvas.clientHeight, 1);
      renderer.setSize(width, height, false);
      camera.aspect = width / height;
      camera.updateProjectionMatrix();
      schedule();
    }
    function move(event) {
      if (paused || motion.matches) return;
      var rect = canvas.getBoundingClientRect();
      pointer.x = (event.clientX - rect.left) / rect.width - 0.5;
      pointer.y = (event.clientY - rect.top) / rect.height - 0.5;
    }
    function updateControl() {
      if (!button) return;
      button.hidden = false;
      button.textContent = paused ? "پخش حرکت" : "توقف حرکت";
      button.setAttribute("aria-pressed", String(paused));
    }
    function toggle() { paused = !paused; suspend(); updateControl(); schedule(); }
    function preference() { paused = motion.matches; suspend(); updateControl(); schedule(); }
    function visibility() { suspend(); schedule(); }
    function contextLost(event) { event.preventDefault(); lost = true; suspend(); fallback(); }
    function contextRestored() { lost = false; host.classList.add("scene-ready"); ready(); resize(); }
    function ready() {
      if (status) status.textContent = "هستهٔ سه‌بعدی یادگیری؛ با اشاره‌گر کاوش کنید.";
      updateControl();
    }
    var observer = new IntersectionObserver(function (entries) {
      visible = entries[0].isIntersecting;
      suspend(); schedule();
    }, { threshold: 0.05 });
    observer.observe(canvas);
    window.addEventListener("resize", resize, { passive: true });
    canvas.addEventListener("pointermove", move, { passive: true });
    canvas.addEventListener("webglcontextlost", contextLost);
    canvas.addEventListener("webglcontextrestored", contextRestored);
    document.addEventListener("visibilitychange", visibility);
    motion.addEventListener("change", preference);
    if (button) button.addEventListener("click", toggle);
    function dispose(event) {
      suspend();
      if (event.persisted) return;
      observer.disconnect();
      window.removeEventListener("resize", resize);
      canvas.removeEventListener("pointermove", move);
      canvas.removeEventListener("webglcontextlost", contextLost);
      canvas.removeEventListener("webglcontextrestored", contextRestored);
      document.removeEventListener("visibilitychange", visibility);
      motion.removeEventListener("change", preference);
      if (button) button.removeEventListener("click", toggle);
      var resources = new Set();
      world.scene.traverse(function (object) {
        if (object.geometry) resources.add(object.geometry);
        if (object.material) resources.add(object.material);
      });
      resources.forEach(function (resource) { resource.dispose(); });
      renderer.dispose();
      window.removeEventListener("pagehide", dispose);
      window.removeEventListener("pageshow", resize);
    }
    window.addEventListener("pagehide", dispose);
    window.addEventListener("pageshow", resize);
    host.classList.add("scene-ready");
    ready(); resize();
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", start, { once: true });
  else start();
})();
