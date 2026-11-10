(() => {
  const editor = document.querySelector('[data-profile-image-editor]');
  if (!editor) return;

  const form = editor.querySelector('form');
  const input = editor.querySelector('[data-profile-image-input]');
  const image = editor.querySelector('[data-profile-image-source]');
  const viewport = editor.querySelector('[data-profile-image-viewport]');
  const zoom = editor.querySelector('[data-profile-image-zoom]');
  const output = editor.querySelector('[data-profile-image-output]');
  const submit = editor.querySelector('[data-profile-image-submit]');
  const message = editor.querySelector('[data-profile-image-message]');
  const size = 240;
  let sourceUrl = '';
  let baseScale = 1;
  let offsetX = 0;
  let offsetY = 0;
  let dragging = false;
  let pointerX = 0;
  let pointerY = 0;

  const clamp = (value, min, max) => Math.max(min, Math.min(max, value));
  const bounds = () => {
    const scale = baseScale * Number(zoom.value);
    const maxX = Math.max(0, (image.naturalWidth * scale - size) / 2);
    const maxY = Math.max(0, (image.naturalHeight * scale - size) / 2);
    offsetX = clamp(offsetX, -maxX, maxX);
    offsetY = clamp(offsetY, -maxY, maxY);
  };
  const render = () => {
    bounds();
    const scale = baseScale * Number(zoom.value);
    image.style.transform = `translate(calc(-50% + ${offsetX}px), calc(-50% + ${offsetY}px)) scale(${scale})`;
  };
  const showMessage = (text) => {
    message.textContent = text;
    message.hidden = !text;
  };
  const loadFile = (file) => {
    if (!file) return;
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024) {
      showMessage('فایل باید JPG، PNG یا WebP و حداکثر ۲ مگابایت باشد.');
      input.value = '';
      return;
    }
    showMessage('تصویر را داخل دایره جابه‌جا کن و بزرگ‌نمایی را تنظیم کن.');
    output.value = '';
    submit.disabled = true;
    if (sourceUrl) URL.revokeObjectURL(sourceUrl);
    sourceUrl = URL.createObjectURL(file);
    image.onload = () => {
      baseScale = Math.max(size / image.naturalWidth, size / image.naturalHeight);
      offsetX = 0;
      offsetY = 0;
      zoom.value = '1';
      viewport.hidden = false;
      submit.disabled = false;
      render();
    };
    image.src = sourceUrl;
  };
  const exportCrop = () => {
    const canvas = document.createElement('canvas');
    canvas.width = 512;
    canvas.height = 512;
    const context = canvas.getContext('2d');
    const scale = baseScale * Number(zoom.value);
    const ratio = canvas.width / size;
    context.drawImage(image, (canvas.width - image.naturalWidth * scale * ratio) / 2 + offsetX * ratio, (canvas.height - image.naturalHeight * scale * ratio) / 2 + offsetY * ratio, image.naturalWidth * scale * ratio, image.naturalHeight * scale * ratio);
    output.value = canvas.toDataURL('image/jpeg', .9);
  };

  input.addEventListener('change', () => loadFile(input.files[0]));
  zoom.addEventListener('input', render);
  viewport.addEventListener('pointerdown', (event) => {
    if (!image.src) return;
    dragging = true;
    pointerX = event.clientX;
    pointerY = event.clientY;
    viewport.setPointerCapture(event.pointerId);
  });
  viewport.addEventListener('pointermove', (event) => {
    if (!dragging) return;
    offsetX += event.clientX - pointerX;
    offsetY += event.clientY - pointerY;
    pointerX = event.clientX;
    pointerY = event.clientY;
    render();
  });
  viewport.addEventListener('pointerup', () => { dragging = false; });
  viewport.addEventListener('pointercancel', () => { dragging = false; });
  form.addEventListener('submit', (event) => {
    if (!input.files.length || !image.complete) {
      event.preventDefault();
      showMessage('ابتدا یک تصویر انتخاب کن.');
      return;
    }
    exportCrop();
  });

  window.addEventListener('pagehide', () => {
    if (sourceUrl) URL.revokeObjectURL(sourceUrl);
  });
})();
