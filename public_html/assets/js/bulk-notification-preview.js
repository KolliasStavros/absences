(() => {
    const form = document.getElementById('bulkEmailForm');
    if (!form) return;
    const make = (tag, text, className = '') => {
        const el = document.createElement(tag);
        el.textContent = text;
        el.className = className;
        return el;
    };
    const dialog = make('div', '', 'app-dialog bulk-preview-dialog');
    dialog.setAttribute('role', 'dialog');
    dialog.setAttribute('aria-modal', 'true');
    dialog.setAttribute('aria-labelledby', 'bulkPreviewTitle');
    const panel = make('div', '', 'app-dialog-panel app-dialog-panel-wide');
    const heading = make('div', '', 'app-dialog-heading');
    const title = make('h2', '', 'app-dialog-title'); title.id = 'bulkPreviewTitle';
    const closeButton = make('button', '×', 'app-dialog-close'); closeButton.type = 'button'; closeButton.setAttribute('aria-label', 'Κλείσιμο προεπισκόπησης');
    heading.append(title, closeButton);
    const note = make('p', 'Ελέγξτε τους παραλήπτες και τα μηνύματα. Η τελική αποστολή χρησιμοποιεί τα τότε ισχύοντα στοιχεία και τις ρυθμίσεις. Δεν έχει σταλεί κανένα μήνυμα.', 'bulk-preview-note');
    const status = make('p', '', 'app-dialog-status'); status.setAttribute('role', 'status');
    const list = make('div', '', 'bulk-preview-list');
    const actions = make('div', '', 'app-dialog-actions');
    const cancel = make('button', 'Ακύρωση', 'btn btn-secondary'); cancel.type = 'button';
    const send = make('button', 'Επιβεβαίωση αποστολής', 'btn btn-primary'); send.type = 'button';
    const top = make('div', '', 'bulk-preview-header');
    const content = make('div', '', 'bulk-preview-scroll');
    content.tabIndex = 0;
    content.setAttribute('aria-label', 'Παραλήπτες και περιεχόμενο μηνυμάτων');
    actions.append(cancel, send); top.append(heading, actions); content.append(note, status, list);
    panel.append(top, content); dialog.append(panel); document.body.append(dialog);
    let controller, generation = 0, snapshot, returnFocus, sending = false;
    function close() {
        if (sending) return;
        generation++; controller?.abort(); dialog.style.display = 'none'; snapshot = null;
        returnFocus?.focus();
    }
    closeButton.addEventListener('click', close); cancel.addEventListener('click', close);
    dialog.addEventListener('keydown', e => {
        if (e.key === 'Escape') { e.preventDefault(); close(); }
        if (e.key === 'Tab') {
            const buttons = [...panel.querySelectorAll('button,summary')].filter(e => !e.disabled);
            const first = buttons[0], last = buttons[buttons.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
    });
    window.openBulkNotificationPreview = async channel => {
        if (sending) return;
        const selected = [...form.querySelectorAll('.student-checkbox:checked')];
        const boxes = selected;
        const ids = [...new Set(boxes.map(e => e.value))];
        if (!ids.length) { alert('Επιλέξτε πρώτα μαθητές για αποστολή ειδοποίησης.'); return; }
        returnFocus = document.activeElement;
        const action = new URL(form.action, location.href);
        snapshot = {channel, ids, action: action.href, date: action.searchParams.get('date'), csrf: form.querySelector('[name="csrf_token"]').value};
        const current = snapshot, request = ++generation;
        controller?.abort(); controller = new AbortController();
        const requestController = controller;
        const timeout = setTimeout(() => requestController.abort(), 15000);
        title.textContent = 'Προεπισκόπηση μαζικής αποστολής ' + (channel === 'sms' ? 'SMS' : 'email');
        list.replaceChildren(); status.textContent = 'Φόρτωση παραληπτών και μηνυμάτων…'; send.disabled = true;
        dialog.style.display = 'flex'; closeButton.focus();
        try {
            const response = await fetch('../api/bulk_notification_preview.php', {
                method: 'POST', cache: 'no-store', signal: requestController.signal,
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': current.csrf},
                body: JSON.stringify({channel, date: current.date, student_ids: ids})
            });
            const data = await response.json();
            if (request !== generation) return;
            if (!response.ok || !Array.isArray(data.items)) throw new Error(data.error || 'Δεν φορτώθηκε η προεπισκόπηση.');
            let eligible = 0;
            for (const item of data.items) {
                const card = make('section', '', 'bulk-preview-item');
                card.append(make('h3', item.name), make('p', 'Α.Μ. ' + item.am));
                card.append(make('p', 'Παραλήπτες: ' + (item.recipients.join(', ') || 'Κανένας')));
                for (const warning of item.warnings) card.append(make('p', warning, 'bulk-preview-warning'));
                if (item.skipped) card.append(make('p', item.skipped, 'bulk-preview-warning'));
                else eligible++;
                const details = make('details', '');
                details.append(make('summary', 'Περιεχόμενο μηνύματος'), make('strong', item.subject), make('pre', item.body));
                card.append(details); list.append(card);
            }
            status.textContent = `${data.items.length} μαθητές στην επιλογή · ${eligible} προς αποστολή. Ημερομηνία: ${current.date}`;
            send.disabled = eligible === 0;
        } catch (error) {
            if (request !== generation) return;
            status.textContent = error.name === 'AbortError' ? 'Η φόρτωση καθυστέρησε. Κλείστε και δοκιμάστε ξανά.' : error.message;
        } finally { clearTimeout(timeout); }
    };
    send.addEventListener('click', () => {
        if (sending || send.disabled || !snapshot) return;
        sending = true; send.disabled = true; cancel.disabled = true; closeButton.disabled = true;
        // Submit only the reviewed IDs; live row changes must not alter this selection.
        const submission = document.createElement('form'); submission.method = 'POST'; submission.action = snapshot.action; submission.hidden = true;
        const add = (name, value) => { const field = document.createElement('input'); field.type = 'hidden'; field.name = name; field.value = value; submission.append(field); };
        add('csrf_token', snapshot.csrf); add(snapshot.channel === 'sms' ? 'send_consolidated_sms' : 'send_consolidated_emails', '1');
        snapshot.ids.forEach(id => add('student_ids[]', id)); document.body.append(submission); submission.requestSubmit();
    });
    form.addEventListener('submit', e => {
        const name = e.submitter?.name;
        if (name === 'send_consolidated_emails' || name === 'send_consolidated_sms') {
            e.preventDefault(); window.openBulkNotificationPreview(name === 'send_consolidated_sms' ? 'sms' : 'email');
        }
    });
})();
