'use strict';
function initializeCalendarForms(root) {
    root.querySelectorAll('[data-calendar-form]').forEach(form => {
        if (form.dataset.calendarInitialized) return;
        form.dataset.calendarInitialized = '1';
        const visibility = form.querySelector('[data-calendar-visibility]');
        const readers = form.querySelector('[data-calendar-readers]');
        const update = () => { readers.hidden = visibility.value !== 'selected'; };
        visibility.addEventListener('change', update);
        update();
        const start = form.elements.start_date;
        const end = form.elements.end_date;
        let previous = start.value;
        start.addEventListener('change', () => {
            if (!end.value || end.value === previous || end.value < start.value) end.value = start.value;
            end.min = start.value;
            previous = start.value;
        });
        end.min = start.value;
        for (const name of ['start_time', 'end_time']) {
            const input = form.elements[name];
            if (!input) continue;
            let firstClick = false;
            input.addEventListener('focus', () => {
                firstClick = true;
                input.select();
            });
            input.addEventListener('click', () => {
                if (firstClick) { input.select(); firstClick = false; }
            });
            input.addEventListener('blur', () => {
                firstClick = false;
                let value = input.value.trim();
                if (/^\d{3,4}$/.test(value)) value = value.slice(0, -2) + ':' + value.slice(-2);
                const match = /^(\d{1,2})(?:[:.](\d{1,2}))?$/.exec(value);
                if (match && Number(match[1]) < 24 && Number(match[2] || 0) < 60) {
                    input.value = match[1].padStart(2, '0') + ':' + (match[2] || '0').padStart(2, '0');
                }
            });
        }
    });
}
initializeCalendarForms(document);
// Always fetch freshly authorized content, including forms, from the existing page.
if (typeof HTMLDialogElement !== 'undefined' && HTMLDialogElement.prototype.showModal) {
    const dialog = document.createElement('dialog');
    dialog.className = 'cal-event-dialog';
    dialog.setAttribute('aria-labelledby', 'cal-dialog-title');
    const header = document.createElement('div');
    header.className = 'cal-dialog-header';
    const title = document.createElement('h2');
    title.id = 'cal-dialog-title';
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'btn btn-secondary btn-sm';
    close.textContent = '✕ Κλείσιμο';
    close.setAttribute('aria-label', 'Κλείσιμο παραθύρου');
    const content = document.createElement('div');
    content.className = 'cal-dialog-content';
    content.setAttribute('aria-live', 'polite');
    header.append(title, close);
    dialog.append(header, content);
    document.body.append(dialog);
    let request = null;
    let previousOverflow = '';
    let submitting = false;
    let currentUrl = '';
    function notice(text, error = false) {
        const message = document.createElement('p');
        message.className = error ? 'cal-notice cal-error' : 'cal-notice';
        message.setAttribute('role', error ? 'alert' : 'status');
        message.textContent = text;
        content.prepend(message);
    }
    async function load(url) {
        if (request) request.abort();
        const controller = new AbortController();
        request = controller;
        content.textContent = 'Φόρτωση καταχώρισης…';
        content.setAttribute('aria-busy', 'true');
        if (!dialog.open) {
            previousOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            dialog.showModal();
        }
        title.textContent = 'Καταχώριση ημερολογίου';
        close.focus();
        try {
            const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
            if (!response.ok) throw new Error('Unavailable event');
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const detail = page.querySelector('.cal-detail, .cal-editor');
            if (!detail) throw new Error('Unavailable event');
            if (request !== controller || !dialog.open) return;
            currentUrl = url;
            content.replaceChildren(document.importNode(detail, true));
            initializeCalendarForms(content);
            title.textContent = detail.querySelector('h2')?.textContent || 'Καταχώριση ημερολογίου';
            content.querySelector('[name="title"]')?.focus();
            dialog.scrollTop = 0;
        } catch (error) {
            if (controller.signal.aborted || request !== controller || !dialog.open) return;
            content.replaceChildren();
            notice('Η καταχώριση δεν είναι διαθέσιμη ή η σύνδεση διακόπηκε.', true);
            const fallback = document.createElement('a');
            fallback.className = 'btn btn-secondary';
            fallback.href = url;
            fallback.textContent = 'Άνοιγμα στη σελίδα καταχώρισης';
            content.append(fallback);
        } finally {
            if (request === controller) content.removeAttribute('aria-busy');
        }
    }
    async function refreshCalendar() {
        const response = await fetch(window.location.href, { credentials: 'same-origin', cache: 'no-store' });
        if (!response.ok) throw new Error('Calendar refresh failed');
        const page = new DOMParser().parseFromString(await response.text(), 'text/html');
        const fresh = page.querySelector('.school-calendar');
        const old = document.querySelector('.school-calendar');
        if (!fresh || !old) throw new Error('Calendar refresh failed');
        const scroll = window.scrollY;
        old.replaceWith(document.importNode(fresh, true));
        initializeCalendarForms(document);
        window.scrollTo({ top: scroll });
    }
    close.addEventListener('click', () => { if (!submitting) dialog.close(); });
    dialog.addEventListener('cancel', event => { if (submitting) event.preventDefault(); });
    dialog.addEventListener('click', event => {
        if (submitting || event.target !== dialog) return;
        const bounds = dialog.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right ||
            event.clientY < bounds.top || event.clientY > bounds.bottom) dialog.close();
    });
    dialog.addEventListener('close', () => {
        if (request) request.abort();
        request = null;
        content.replaceChildren();
        document.body.style.overflow = previousOverflow;
    });
    document.addEventListener('click', async event => {
        const dismiss = event.target.closest?.('[data-calendar-dismiss]');
        if (dismiss && dialog.contains(dismiss)) {
            event.preventDefault();
            if (!submitting) dialog.close();
            return;
        }
        const link = event.target.closest?.('.school-calendar .cal-event, .school-calendar .cal-agenda-item, [data-calendar-modal]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey ||
            event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        if (!submitting) await load(link.href);
    });
    dialog.addEventListener('submit', async event => {
        const form = event.target;
        if (!form.matches('[data-calendar-form], [data-calendar-delete]')) return;
        event.preventDefault();
        if (submitting || !form.reportValidity()) return;
        const data = new FormData(form);
        const deleting = form.matches('[data-calendar-delete]');
        const buttons = [...form.querySelectorAll('button')];
        submitting = true;
        close.disabled = true;
        buttons.forEach(button => { button.disabled = true; });
        content.querySelectorAll('.cal-notice').forEach(node => node.remove());
        try {
            // A named input "action" shadows HTMLFormElement.action in the browser.
            const actionUrl = new URL(form.getAttribute('action') || currentUrl, window.location.href).href;
            const response = await fetch(actionUrl, {
                method: 'POST', body: data, credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }
            });
            // Do not retry POST on connection failure: its commit status could be unknown.
            const result = await response.json();
            if (!response.ok || result.error || !result.url) {
                notice(result.error || 'Η αποθήκευση δεν ολοκληρώθηκε. Ελέγξτε τα πεδία.', true);
                dialog.scrollTop = 0;
                return;
            }
            if (deleting) {
                content.replaceChildren();
                notice(result.message);
            } else {
                await load(new URL(result.url, window.location.href).href);
                notice(result.message);
            }
            try { await refreshCalendar(); }
            catch (error) { notice('Η αλλαγή αποθηκεύτηκε. Ανανεώστε το ημερολόγιο για να εμφανιστεί στην προβολή.', true); }
        } catch (error) {
            notice('Δεν επιβεβαιώθηκε η αποθήκευση. Ελέγξτε τη σύνδεση και το ημερολόγιο πριν δοκιμάσετε ξανά.', true);
            dialog.scrollTop = 0;
        } finally {
            submitting = false;
            close.disabled = false;
            buttons.forEach(button => { button.disabled = false; });
        }
    });
}
