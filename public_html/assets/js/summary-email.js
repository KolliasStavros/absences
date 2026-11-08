/* SPDX-License-Identifier: GPL-3.0-only */
(() => {
    'use strict';
    const open = document.getElementById('summary-email-open');
    if (!open) return;
    const choices = [...document.querySelectorAll('.summary-student-choice')];
    const allStudents = document.getElementById('summary-select-all');
    const count = document.getElementById('summary-selection-count');
    const selectionStatus = document.getElementById('summary-selection-status') || count;
    const update = () => {
        const n = choices.filter(c => c.checked).length;
        count.textContent = String(n);
        open.disabled = n === 0;
        allStudents.checked = choices.length > 0 && n === choices.length;
        allStudents.indeterminate = n > 0 && n < choices.length;
    };
    choices.forEach(c => c.addEventListener('change', update));
    allStudents.addEventListener('change', () => { choices.forEach(c => { c.checked = allStudents.checked; }); update(); });
    const dialog = document.createElement('div');
    dialog.className = 'app-dialog bulk-preview-dialog summary-email-dialog'; dialog.hidden = true;
    dialog.setAttribute('role', 'dialog'); dialog.setAttribute('aria-modal', 'true'); dialog.setAttribute('aria-labelledby', 'summary-email-title');
    dialog.innerHTML = `<section class="app-dialog-panel app-dialog-panel-wide"><div class="bulk-preview-header"><div class="app-dialog-heading"><h2 id="summary-email-title" class="app-dialog-title">Προεπισκόπηση μαζικής αποστολής email</h2><button type="button" class="app-dialog-close summary-x" aria-label="Κλείσιμο προεπισκόπησης">×</button></div><div class="app-dialog-actions"><button type="button" class="btn btn-secondary summary-close">Ακύρωση</button><button type="button" class="btn btn-primary summary-send" disabled>Επιβεβαίωση αποστολής</button></div></div><div class="bulk-preview-scroll" tabindex="0" aria-label="Παραλήπτες και περιεχόμενο μηνυμάτων"><p class="bulk-preview-note">Ελέγξτε τους παραλήπτες και τα μηνύματα. Κάθε κηδεμόνας λαμβάνει μόνο τα στοιχεία του δικού του παιδιού. Δεν έχει σταλεί κανένα μήνυμα.</p><form class="summary-options"><label><input type="checkbox" class="summary-year" checked> Ολόκληρο το ενεργό σχολικό έτος</label><label>Από<input type="date" class="form-control summary-from" disabled></label><label>Έως<input type="date" class="form-control summary-to" disabled></label><label><input type="checkbox" class="summary-daily"> Ανάλυση ανά ημέρα</label><button class="btn btn-secondary">Ενημέρωση προεπισκόπησης</button></form><p class="summary-status" role="status"></p><div class="summary-previews bulk-preview-list"></div></div></section>`;
    document.body.append(dialog);
    const form = dialog.querySelector('form'), whole = dialog.querySelector('.summary-year');
    const from = dialog.querySelector('.summary-from'), to = dialog.querySelector('.summary-to');
    const status = dialog.querySelector('.summary-status'), previews = dialog.querySelector('.summary-previews');
    const send = dialog.querySelector('.summary-send'), close = dialog.querySelector('.summary-close');
    const closeX = dialog.querySelector('.summary-x'), daily = dialog.querySelector('.summary-daily');
    let ids = [], messages = [], busy = false, prepared = false;
    const request = async payload => {
        const response = await fetch('../api/student_email.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('input[name="csrf_token"]').value }, body: JSON.stringify(payload), cache: 'no-store' });
        const data = await response.json();
        if (!response.ok) throw Error(data.error || 'Η ενέργεια δεν ολοκληρώθηκε.');
        return data;
    };
    const lock = value => {
        busy = value;
        form.querySelectorAll('input,button').forEach(c => { c.disabled = value; });
        from.disabled = to.disabled = value || whole.checked;
        close.disabled = value;
        closeX.disabled = value;
        previews.querySelectorAll('input').forEach(c => { c.disabled = value; });
    };
    const invalidate = () => { prepared = false; send.disabled = true; send.textContent = 'Επιβεβαίωση αποστολής'; previews.replaceChildren(); messages = []; status.textContent = 'Πατήστε «Ενημέρωση προεπισκόπησης» για τις νέες επιλογές.'; };
    whole.addEventListener('change', () => { from.disabled = to.disabled = whole.checked; from.required = to.required = !whole.checked; invalidate(); });
    [from, to].forEach(c => c.addEventListener('change', invalidate));
    daily.addEventListener('change', invalidate);
    const hide = () => { if (!busy) { dialog.hidden = true; dialog.style.display = 'none'; open.focus(); } };
    close.addEventListener('click', hide);
    closeX.addEventListener('click', hide);
    dialog.addEventListener('keydown', e => {
        if (e.key === 'Escape') hide();
        if (e.key === 'Tab') {
            const focusable = [...dialog.querySelectorAll('input,button,summary')].filter(c => !c.disabled && !c.hidden);
            const first = focusable[0], last = focusable[focusable.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
    });
    open.addEventListener('click', () => {
        if (busy) return;
        ids = choices.filter(c => c.checked).map(c => Number(c.value));
        if (!ids.length) { selectionStatus.textContent = 'Επιλέξτε τουλάχιστον έναν μαθητή.'; return; }
        if (ids.length > 200) { selectionStatus.textContent = 'Επιλέξτε έως 200 μαθητές ανά αποστολή, περιορίζοντας τα φίλτρα.'; return; }
        invalidate(); status.textContent = `${ids.length} μαθητές. Επιλέξτε περίοδο και δημιουργήστε προεπισκόπηση.`;
        dialog.querySelector('.bulk-preview-note').textContent = 'Ελέγξτε τους παραλήπτες και τα μηνύματα. Κάθε κηδεμόνας λαμβάνει μόνο τα στοιχεία του δικού του παιδιού. Δεν έχει σταλεί κανένα μήνυμα.';
        dialog.hidden = false; dialog.style.display = 'flex'; closeX.focus();
        form.requestSubmit();
    });
    form.addEventListener('submit', async e => {
        e.preventDefault(); if (busy) return;
        if (!whole.checked && (!from.value || !to.value || from.value > to.value)) { status.textContent = 'Επιλέξτε έγκυρο διάστημα Από και Έως.'; return; }
        invalidate(); lock(true); let skipped = 0, errors = 0;
        for (const id of ids) {
            status.textContent = `Προετοιμασία ${messages.length + skipped + errors + 1}/${ids.length}…`;
            try {
                const message = await request({ action: 'prepare', mode: 'summary', student_id: id, from: whole.checked ? null : from.value, to: whole.checked ? null : to.value, include_daily: daily.checked });
                const slots = Object.keys(message.contacts);
                if (!slots.length) {
                    skipped++;
                    const warning = document.createElement('p'); warning.textContent = `${message.name}: Δεν έχει οριστεί έγκυρο email κηδεμόνα.`; previews.append(warning);
                    continue;
                }
                const card = document.createElement('section'); card.className = 'bulk-preview-item';
                const name = document.createElement('h3'), am = document.createElement('p');
                name.textContent = message.name; am.textContent = 'Α.Μ. ' + (message.am || '—'); card.append(name, am);
                const recipients = document.createElement('div'), caption = document.createElement('p');
                caption.textContent = 'Παραλήπτες:'; recipients.append(caption); recipients.className = 'summary-recipients';
                const details = document.createElement('details'), title = document.createElement('summary');
                title.textContent = 'Περιεχόμενο μηνύματος'; details.append(title); message.checkboxes = [];
                for (const slot of slots) {
                    const label = document.createElement('label'), cb = document.createElement('input');
                    cb.type = 'checkbox'; cb.checked = true; cb.value = slot;
                    label.append(cb, document.createTextNode(' ' + message.contacts[slot])); recipients.append(label); message.checkboxes.push(cb);
                }
                const subject = document.createElement('strong'), text = document.createElement('pre');
                subject.textContent = message.subject; text.textContent = message.body;
                text.style.cssText = 'white-space:pre-wrap;overflow-wrap:anywhere;font:inherit';
                details.append(subject, text); card.append(recipients, details); previews.append(card); messages.push(message);
            } catch (error) {
                errors++;
                const warning = document.createElement('p'); warning.textContent = `Μαθητής ${id}: ${error.message}`; previews.append(warning);
            }
        }
        lock(false); prepared = true; send.disabled = !messages.length;
        status.textContent = `Έτοιμα: ${messages.length} μηνύματα · Χωρίς έγκυρο email: ${skipped} · Σφάλματα: ${errors}. Ελέγξτε παραλήπτες και μηνύματα πριν την επιβεβαίωση.`;
    });
    send.addEventListener('click', async () => {
        if (busy || !prepared) return;
        const pending = messages.map(m => ({ message: m, slots: m.checkboxes.filter(c => c.checked && !c.dataset.sent).map(c => c.value) })).filter(m => m.slots.length);
        if (!pending.length) { status.textContent = 'Επιλέξτε τουλάχιστον έναν παραλήπτη.'; return; }
        lock(true); send.disabled = true; let success = 0, failed = 0, uncertain = false;
        dialog.querySelector('.bulk-preview-note').textContent = 'Η αποστολή ξεκίνησε. Τα αποτελέσματα εμφανίζονται παρακάτω και καταγράφονται στο ιστορικό ειδοποιήσεων.';
        for (let i = 0; i < pending.length; i++) {
            const { message, slots } = pending[i]; status.textContent = `Αποστολή ${i + 1}/${pending.length}…`;
            try {
                const result = await request({ action: 'send', nonce: message.nonce, subject: message.subject, body: message.body, slots });
                result.results.forEach(r => {
                    const cb = message.checkboxes.find(c => c.value === r.slot);
                    if (r.success) { success++; if (cb) { cb.checked = false; cb.dataset.sent = '1'; } } else failed++;
                });
                message.nonce = result.retry_nonce;
            } catch (error) {
                uncertain = true;
                const warning = document.createElement('p'); warning.textContent = `${message.name}: ${error.message}. Ελέγξτε το ιστορικό ειδοποιήσεων πριν από νέα αποστολή.`; previews.append(warning); break;
            }
        }
        lock(false);
        messages.forEach(m => m.checkboxes.forEach(c => { if (c.dataset.sent) c.disabled = true; }));
        status.textContent = `Επιτυχείς παραλήπτες: ${success} · Αποτυχίες: ${failed}. ${uncertain ? 'Η διαδικασία σταμάτησε. Ελέγξτε το ιστορικό πριν επαναλάβετε.' : 'Οι αποστολές καταγράφηκαν στο ιστορικό.'}`;
        prepared = !uncertain && messages.some(m => m.nonce && m.checkboxes.some(c => c.checked));
        send.disabled = !prepared; send.textContent = prepared ? 'Επανάληψη μόνο αποτυχημένων παραληπτών' : 'Η διαδικασία ολοκληρώθηκε';
    });
    update();
})();
