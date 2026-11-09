'use strict';
(() => {
    const configNode = document.getElementById('attendance-undo-config');
    if (!configNode) return;
    const config = JSON.parse(configNode.textContent);
    const host = document.getElementById('attendanceUndoTools');
    const text = (tag, value, className = '') => {
        const node = document.createElement(tag); node.textContent = value; node.className = className; return node;
    };
    if (!config.ready) { host.append(text('p', 'Το ιστορικό αναίρεσης δεν έχει ενεργοποιηθεί ακόμη.', 'au-warning')); return; }
    const random = () => [...crypto.getRandomValues(new Uint8Array(16))].map(n => n.toString(16).padStart(2, '0')).join('');
    // sessionStorage is scoped to the tab; owner window prevents copied tabs sharing an identity.
    let tab, run;
    try {
        tab = sessionStorage.getItem('attendanceUndoTab') || random();
        run = sessionStorage.getItem('attendanceUndoRun') || random();
        if (sessionStorage.getItem('attendanceUndoDate') !== config.date) run = random();
        sessionStorage.setItem('attendanceUndoTab', tab);
        sessionStorage.setItem('attendanceUndoRun', run);
        sessionStorage.setItem('attendanceUndoDate', config.date);
    } catch (error) { tab = random(); run = random(); }
    window.addEventListener('pageshow', () => {
        try {
            if (sessionStorage.getItem('attendanceUndoDate') !== config.date || sessionStorage.getItem('attendanceUndoRun') !== run) {
                run = random();
                sessionStorage.setItem('attendanceUndoRun', run);
                sessionStorage.setItem('attendanceUndoDate', config.date);
            }
        } catch (error) {}
    });
    // A duplicated tab may copy sessionStorage. Detect the live original and rotate the clone.
    const documentKey = random();
    let channel;
    try {
        channel = new BroadcastChannel('attendance-undo-tabs');
        channel.onmessage = event => {
            const data = event.data;
            if (data?.tab !== tab || data.document === documentKey) return;
            if (data.type === 'probe') channel.postMessage({ type: 'occupied', tab, document: documentKey, target: data.document });
            if (data.type === 'occupied' && data.target === documentKey) {
                tab = random(); run = random();
                try { sessionStorage.setItem('attendanceUndoTab', tab); sessionStorage.setItem('attendanceUndoRun', run); } catch (error) {}
            }
        };
        channel.postMessage({ type: 'probe', tab, document: documentKey });
    } catch (error) {}
    const originalFetch = window.fetch.bind(window);
    let pending = 0;
    window.fetch = async (url, options = {}) => {
        const target = new URL(typeof url === 'string' ? url : url.url, location.href);
        if (target.origin !== location.origin || !target.pathname.endsWith('/api/toggle_student_absence.php')) return originalFetch(url, options);
        const headers = new Headers(options.headers || {});
        headers.set('X-Attendance-Tab', tab); headers.set('X-Attendance-Run', run);
        headers.set('X-Attendance-Year', String(config.year));
        pending++;
        try { return await originalFetch(url, { ...options, headers }); }
        finally { pending--; }
    };
    document.addEventListener('submit', event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || form.method.toLowerCase() !== 'post') return;
        for (const [name, value] of [['undo_tab', tab], ['undo_run', run]]) {
            let input = form.querySelector(`[name="${name}"]`);
            if (!input) { input = document.createElement('input'); input.type = 'hidden'; input.name = name; form.append(input); }
            input.value = value;
        }
    }, true);
    document.getElementById('dateSelect')?.addEventListener('change', () => {
        run = random(); try { sessionStorage.setItem('attendanceUndoRun', run); } catch (error) {}
    });
    if (config.date !== config.today) host.append(text('strong', `Προσοχή: καταχωρίζετε απουσίες για ${config.date.split('-').reverse().join('/')}, όχι για σήμερα.`, 'au-warning'));
    const open = text('button', '↶ Ιστορικό & αναίρεση αλλαγών', 'btn btn-secondary btn-sm'); open.type = 'button';
    open.title = 'Αυτόματη ομαδοποίηση · νέα ομάδα μετά από αλλαγή ημέρας ή 15 λεπτά αδράνειας.';
    (document.getElementById('attendanceUndoAction') || host).append(open);
    const dialog = document.createElement('dialog'); dialog.className = 'au-dialog'; dialog.setAttribute('aria-labelledby', 'au-title');
    const header = document.createElement('div'); header.className = 'au-header';
    const heading = text('h2', 'Ιστορικό αλλαγών απουσιών'); heading.id = 'au-title';
    const close = text('button', '✕ Κλείσιμο', 'btn btn-secondary btn-sm'); close.type = 'button';
    header.append(heading, close);
    const body = document.createElement('div'); body.className = 'au-body'; body.setAttribute('aria-live', 'polite');
    dialog.append(header, body); document.body.append(dialog);
    let busy = false, generation = 0, needsReload = false;
    close.onclick = () => { if (!busy) dialog.close(); };
    dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
    dialog.addEventListener('close', () => { generation++; body.replaceChildren(); if (needsReload) location.reload(); });
    async function api(data) {
        const response = await originalFetch('../api/attendance_undo.php', { method: 'POST', cache: 'no-store', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': config.csrf }, body: JSON.stringify({ ...data, year_id: config.year }) });
        const result = await response.json(); if (!response.ok) throw new Error(result.error || 'Το ιστορικό δεν είναι διαθέσιμο.'); return result;
    }
    function fail(error) { body.append(text('p', error.message || 'Σφάλμα επικοινωνίας. Ανανεώστε πριν συνεχίσετε.', 'au-error')); }
    async function preview(id) {
        const current = ++generation; body.textContent = 'Φόρτωση προεπισκόπησης…';
        try {
            const result = await api({ action: 'preview', batch_id: id });
            if (generation !== current || !dialog.open) return;
            body.replaceChildren();
            const back = text('button', '← Ομάδες αλλαγών', 'btn btn-secondary btn-sm'); back.onclick = () => list(); body.append(back);
            body.append(text('h3', `${result.batch.target_date} · ${result.batch.actor_name}`));
            body.append(text('p', `Αλλαγές από ${result.batch.started_at.slice(0, 19)} έως ${result.batch.last_at.slice(0, 19)}. Ασφαλής επαναφορά: ${result.eligible} εγγραφές. Εξαιρούνται: ${result.rows.length - result.eligible}.`));
            body.append(text('p', 'Η αναίρεση επαναφέρει τις παρακάτω εγγραφές στην κατάσταση πριν από το πρώτο κλικ της ομάδας. Δεν ανακαλούνται email ή άλλα μηνύματα που έχουν ήδη σταλεί.', 'au-warning'));
            if (result.rows.some(row => row.sent)) body.append(text('p', 'Εντοπίστηκαν επιτυχείς αποστολές για μαθητές αυτής της ομάδας μετά την έναρξή της. Ελέγξτε αν χρειάζεται διορθωτική ενημέρωση.', 'au-warning'));
            const table = document.createElement('table'); table.className = 'au-table';
            const thead = document.createElement('thead'); const tr = document.createElement('tr');
            ['Μαθητής / ώρα', 'Από', 'Επαναφορά σε', 'Έλεγχος'].forEach(label => tr.append(text('th', label))); thead.append(tr); table.append(thead);
            const tbody = document.createElement('tbody');
            result.rows.forEach(row => { const line = document.createElement('tr'); [row.name + ' · ' + row.hour + 'η ώρα', row.from, row.to, row.reason || 'Μπορεί να επανέλθει'].forEach(value => line.append(text('td', value))); if (!row.eligible) line.className = 'au-conflict'; tbody.append(line); });
            table.append(tbody); const scroll = document.createElement('div'); scroll.className = 'au-scroll'; scroll.append(table); body.append(scroll);
            if (result.eligible) {
                const label = document.createElement('label'); const check = document.createElement('input'); check.type = 'checkbox'; label.append(check, text('span', ' Έλεγξα την προεπισκόπηση και επιβεβαιώνω την επαναφορά των ασφαλών εγγραφών.')); body.append(label);
                const apply = text('button', 'Αναίρεση ομάδας αλλαγών', 'btn btn-danger'); apply.disabled = true; check.onchange = () => { apply.disabled = !check.checked; }; body.append(apply);
                apply.onclick = async () => {
                    if (!check.checked || busy) return;
                    if (pending) { fail(new Error('Περιμένετε να ολοκληρωθούν οι αποθηκεύσεις και ανοίξτε ξανά την προεπισκόπηση.')); return; }
                    busy = true; close.disabled = true; apply.disabled = true; back.disabled = true;
                    try {
                        const undone = await api({ action: 'apply', batch_id: id, hash: result.hash });
                        needsReload = true;
                        body.replaceChildren(text('p', `Επαναφέρθηκαν ${undone.restored} εγγραφές. Διατηρήθηκαν ${undone.skipped} εξαιρέσεις.`));
                        const reload = text('button', 'Επιστροφή στο ενημερωμένο ημερολόγιο απουσιών', 'btn btn-primary'); reload.onclick = () => location.reload(); body.append(reload);
                        close.onclick = () => location.reload();
                        run = random(); try { sessionStorage.setItem('attendanceUndoRun', run); } catch (error) {}
                    } catch (error) { fail(error); back.disabled = false; }
                    finally { busy = false; close.disabled = false; }
                };
            }
        } catch (error) { if (generation === current) { body.replaceChildren(); fail(error); } }
    }
    let filter = config.date;
    async function list(before = '') {
        const current = ++generation; body.textContent = 'Φόρτωση ιστορικού…';
        try {
            const response = await originalFetch(`../api/attendance_undo.php?date=${encodeURIComponent(filter)}&before=${encodeURIComponent(before)}`, { cache: 'no-store' });
            const result = await response.json(); if (!response.ok) throw new Error(result.error);
            if (generation !== current || !dialog.open) return;
            body.replaceChildren(); const form = document.createElement('form'); form.className = 'au-filter';
            const date = document.createElement('input'); date.type = 'date'; date.value = filter; date.className = 'form-control'; date.setAttribute('aria-label', 'Ημερομηνία καταχωρίσεων');
            const show = text('button', 'Προβολή', 'btn btn-secondary');
            form.append(date, show); form.onsubmit = event => { event.preventDefault(); filter = date.value; list(); }; body.append(form);
            body.append(text('p', 'Αφήστε κενή την ημερομηνία για όλες τις ημέρες. Το ιστορικό περιλαμβάνει μόνο αλλαγές μετά την ενεργοποίησή του.'));
            if (!result.batches.length) body.append(text('p', 'Δεν υπάρχουν ομάδες αλλαγών για αυτή την επιλογή.'));
            result.batches.forEach(batch => {
                const button = text('button', `${batch.target_date} · ${batch.actor_name} · ${batch.started_at.slice(0, 16)} – ${batch.last_at.slice(11, 16)} · ${batch.students} μαθητές / ${batch.changes} αλλαγές${['undone', 'partial'].includes(batch.status) ? ' · Έχει αναιρεθεί' : ''}`, 'au-batch');
                button.onclick = () => preview(batch.id); body.append(button);
            });
            if (result.next) { const older = text('button', 'Παλαιότερες ομάδες →', 'btn btn-secondary'); older.onclick = () => list(result.next); body.append(older); }
            if (before) { const newest = text('button', 'Νεότερες ομάδες', 'btn btn-secondary'); newest.onclick = () => list(); body.append(newest); }
        } catch (error) { if (generation === current) { body.replaceChildren(); fail(error); } }
    }
    open.onclick = () => {
        if (pending) { alert('Περιμένετε να ολοκληρωθούν οι αποθηκεύσεις.'); return; }
        if (typeof dialog.showModal !== 'function') { alert('Χρειάζεται σύγχρονος browser για το ιστορικό αναίρεσης.'); return; }
        dialog.showModal(); list();
    };
})();
