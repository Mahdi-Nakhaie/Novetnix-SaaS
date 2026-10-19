(() => {
    const player = document.getElementById('fc-video');
    const button = document.getElementById('fc-switch-player');
    const status = document.getElementById('fc-player-status');
    if (!player || !button || !status) return;
    const iframe = player.querySelector('iframe');
    let alternative = false;
    button.hidden = false;
    button.addEventListener('click', () => {
        alternative = !alternative;
        if (alternative) {
            const container = document.createElement('div');
            container.id = '82531869439';
            const script = document.createElement('script');
            script.src = 'https://www.aparat.com/embed/qbk51d2?data[rnddiv]=82531869439&data[responsive]=yes&titleShow=true';
            script.onerror = () => {
                status.textContent = 'روش جایگزین بارگذاری نشد؛ از لینک «تماشا در آپارات» استفاده کنید یا به روش اصلی برگردید.';
            };
            player.replaceChildren(container);
            container.append(script);
            status.textContent = 'روش جایگزین آپارات انتخاب شد. در صورت مشکل، می‌توانید به پخش‌کنندهٔ اصلی برگردید.';
            button.textContent = 'بازگشت به پخش‌کنندهٔ اصلی';
        } else {
            player.replaceChildren(iframe);
            status.textContent = 'پخش‌کنندهٔ اصلی آپارات انتخاب شد.';
            button.textContent = 'استفاده از پخش‌کنندهٔ جایگزین';
        }
    });
})();
