(function () {
  "use strict";

  function start() {
    var canvas = document.querySelector("[data-three-scene]");
    if (!canvas || !window.THREE) return;

    var reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    var scene = new THREE.Scene();
    var camera = new THREE.PerspectiveCamera(32, 1, 0.1, 100);
    camera.position.set(0, 0.1, 7.2);

    var renderer = new THREE.WebGLRenderer({ canvas: canvas, alpha: true, antialias: true, powerPreference: "high-performance" });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.5));
    renderer.setClearColor(0x000000, 0);

    var group = new THREE.Group();
    scene.add(group);

    var core = new THREE.Mesh(
      new THREE.IcosahedronGeometry(1.25, 2),
      new THREE.MeshBasicMaterial({ color: 0x9b4ddd, wireframe: true, transparent: true, opacity: 0.72 })
    );
    group.add(core);

    var inner = new THREE.Mesh(
      new THREE.IcosahedronGeometry(0.86, 1),
      new THREE.MeshBasicMaterial({ color: 0xf0b429, wireframe: true, transparent: true, opacity: 0.42 })
    );
    inner.rotation.set(0.4, 0.2, 0.25);
    group.add(inner);

    var ring = new THREE.Mesh(
      new THREE.TorusGeometry(1.68, 0.018, 8, 96),
      new THREE.MeshBasicMaterial({ color: 0xc08bff, transparent: true, opacity: 0.9 })
    );
    ring.rotation.set(0.85, 0.2, -0.25);
    group.add(ring);

    var ringTwo = ring.clone();
    ringTwo.material = ring.material.clone();
    ringTwo.material.color.setHex(0xf0b429);
    ringTwo.material.opacity = 0.56;
    ringTwo.rotation.set(-0.35, 0.75, 0.35);
    ringTwo.scale.setScalar(0.78);
    group.add(ringTwo);

    var particleCount = 180;
    var positions = new Float32Array(particleCount * 3);
    for (var i = 0; i < particleCount; i += 1) {
      var radius = 2.05 + Math.random() * 1.8;
      var theta = Math.random() * Math.PI * 2;
      var phi = Math.acos(2 * Math.random() - 1);
      positions[i * 3] = radius * Math.sin(phi) * Math.cos(theta);
      positions[i * 3 + 1] = radius * Math.cos(phi) * 0.75;
      positions[i * 3 + 2] = radius * Math.sin(phi) * Math.sin(theta);
    }
    var particleGeometry = new THREE.BufferGeometry();
    particleGeometry.setAttribute("position", new THREE.BufferAttribute(positions, 3));
    var particles = new THREE.Points(particleGeometry, new THREE.PointsMaterial({ color: 0xc08bff, size: 0.025, transparent: true, opacity: 0.7 }));
    group.add(particles);

    var pointerX = 0;
    var pointerY = 0;
    var targetX = 0;
    var targetY = 0;
    var visible = true;
    var observer = new IntersectionObserver(function (entries) { visible = entries[0].isIntersecting; }, { threshold: 0.05 });
    observer.observe(canvas);

    function resize() {
      var width = canvas.clientWidth || 1;
      var height = canvas.clientHeight || 1;
      renderer.setSize(width, height, false);
      camera.aspect = width / height;
      camera.updateProjectionMatrix();
    }

    function move(event) {
      var rect = canvas.getBoundingClientRect();
      pointerX = ((event.clientX - rect.left) / rect.width - 0.5) * 2;
      pointerY = ((event.clientY - rect.top) / rect.height - 0.5) * 2;
    }

    resize();
    window.addEventListener("resize", resize, { passive: true });
    canvas.addEventListener("pointermove", move, { passive: true });

    function render(time) {
      if (!visible) {
        window.requestAnimationFrame(render);
        return;
      }
      var seconds = time * 0.001;
      targetX += (pointerX - targetX) * 0.035;
      targetY += (pointerY - targetY) * 0.035;
      if (!reduced) {
        group.rotation.y = seconds * 0.16 + targetX * 0.18;
        group.rotation.x = Math.sin(seconds * 0.35) * 0.08 + targetY * 0.1;
        ring.rotation.z = seconds * 0.12 - 0.25;
        ringTwo.rotation.z = -seconds * 0.16 + 0.35;
        particles.rotation.y = -seconds * 0.035;
      } else {
        group.rotation.y = targetX * 0.12;
        group.rotation.x = targetY * 0.08;
      }
      renderer.render(scene, camera);
      if (!reduced) window.requestAnimationFrame(render);
    }

    render(0);
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", start, { once: true });
  else start();
})();
