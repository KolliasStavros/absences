// Reports only controlled labels and same-installation source locations.
(() => {
    const script = document.currentScript;
    if (!script) return;
    const root = new URL('../../', script.src);
    const endpoint = new URL('api/technical_error.php', root);
    let sent = 0;
    const seen = new Set();
    function report(kind, source, line) {
        try {
            const url = new URL(source || location.href, location.href);
            if (url.origin !== root.origin || !url.pathname.startsWith(root.pathname)) return;
            const file = url.pathname.slice(root.pathname.length);
            if (!/^(?:assets\/js\/[a-zA-Z0-9_-]+\.js|(?:admin|teacher)\/[a-zA-Z0-9_-]+\.php)$/.test(file)) return;
            line = Number.isInteger(line) ? Math.max(0, Math.min(1000000, line)) : 0;
            const key = `${kind}:${file}:${line}`;
            if (sent >= 10 || seen.has(key)) return;
            seen.add(key); sent++;
            fetch(endpoint, {
                method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': script.dataset.csrf},
                body: JSON.stringify({kind, file, line}), keepalive: true
            }).catch(() => {});
        } catch (_) { /* Diagnostics must not affect the application. */ }
    }
    window.addEventListener('error', event => {
        if (event instanceof ErrorEvent) report('RuntimeError', event.filename, event.lineno);
    });
    window.addEventListener('unhandledrejection', () => report('UnhandledRejection', location.href, 0));
})();
