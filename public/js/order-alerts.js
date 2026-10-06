(() => {
    const panel = document.querySelector('[data-order-alerts]');
    if (!panel) return;
    const message = panel.querySelector('[data-alert-message]');
    const button = panel.querySelector('[data-alert-sound]');
    let version = null, audio = null, enabled = false, running = false;
    button.addEventListener('click', async () => {
        try {
            if (!audio) audio = new (window.AudioContext || window.webkitAudioContext)();
            await audio.resume();
            enabled = !enabled;
            button.textContent = enabled ? panel.dataset.on : panel.dataset.off;
            button.setAttribute('aria-pressed', String(enabled));
        } catch { button.disabled = true; }
    });
    function beep() {
        if (!enabled || !audio) return;
        const tone = audio.createOscillator(), gain = audio.createGain();
        tone.frequency.value = 740;
        gain.gain.setValueAtTime(0.12, audio.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, audio.currentTime + 0.45);
        tone.connect(gain); gain.connect(audio.destination);
        tone.start(); tone.stop(audio.currentTime + 0.45);
    }
    async function poll() {
        if (running) return;
        running = true;
        try {
            const response = await fetch(panel.dataset.url, {headers: {'Accept': 'application/json'}, cache: 'no-store', signal: AbortSignal.timeout(10000)});
            if (!response.ok) throw new Error('poll');
            const data = await response.json();
            const changed = version !== null && version !== data.version;
            if (changed) {
                beep(); message.textContent = data.message;
                document.querySelectorAll('[data-order-status]').forEach(el => el.textContent = data.status || el.textContent);
                if (panel.dataset.refresh) {
                    const page = await fetch(window.location.href, {cache: 'no-store', signal: AbortSignal.timeout(10000)});
                    if (!page.ok) throw new Error('refresh');
                    const html = new DOMParser().parseFromString(await page.text(), 'text/html');
                    const board = html.getElementById(panel.dataset.refresh);
                    if (board) document.getElementById(panel.dataset.refresh)?.replaceWith(board);
                }
            } else if (version === null || message.textContent === panel.dataset.failed) {
                message.textContent = data.status ? data.message : panel.dataset.waiting;
            }
            version = data.version;
        } catch { message.textContent = panel.dataset.failed; }
        finally { running = false; }
    }
    poll(); setInterval(poll, 15000);
})();
