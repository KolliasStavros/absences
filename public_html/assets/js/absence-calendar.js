// Date selection only. Existing date fields and justification submission stay intact.
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('justifyModal');
    if (!modal) return;
    const fields = ['justifyDateFrom', 'justifyDateTo'].map(id => document.getElementById(id));
    const panel = document.createElement('section');
    panel.id = 'absenceCalendar';
    panel.className = 'absence-calendar';
    panel.hidden = true;
    panel.setAttribute('aria-label', 'Ημερολόγιο απουσιών');
    fields[0].parentElement.after(panel);
    let target = fields[0], month = new Date(), days = {}, state = 'loading', generation = 0;
    const monthFormat = new Intl.DateTimeFormat('el-GR', { month: 'long', year: 'numeric' });
    const dateFormat = new Intl.DateTimeFormat('el-GR', { dateStyle: 'full' });
    const iso = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const parse = value => /^\d{4}-\d{2}-\d{2}$/.test(value) ? new Date(value + 'T12:00:00') : new Date();
    function button(text, label, action) {
        const el = document.createElement('button');
        el.type = 'button'; el.textContent = text; el.title = label;
        el.setAttribute('aria-label', label); el.addEventListener('click', action);
        return el;
    }
    function render() {
        panel.replaceChildren();
        const heading = document.createElement('div'); heading.className = 'absence-calendar-heading';
        const title = document.createElement('strong'); title.textContent = monthFormat.format(month);
        title.setAttribute('aria-live', 'polite');
        heading.append(button('‹', 'Προηγούμενος μήνας', () => { month = new Date(month.getFullYear(), month.getMonth() - 1, 1); render(); }), title,
            button('›', 'Επόμενος μήνας', () => { month = new Date(month.getFullYear(), month.getMonth() + 1, 1); render(); }));
        const caption = document.createElement('p');
        caption.textContent = 'Επιλέξτε την ημέρα που θέλετε να δικαιολογήσετε.';
        panel.append(heading, caption);
        const status = document.createElement('p'); status.setAttribute('role', 'status');
        status.textContent = state === 'loading' ? 'Φόρτωση απουσιών…' : state === 'error' ? 'Δεν φορτώθηκαν οι απουσίες. Οι ημερομηνίες παραμένουν επιλέξιμες, χωρίς ενδείξεις.' : 'Κόκκινο: αδικαιολόγητες (και αποβολές) · Μπλε: δικαιολογημένες · Διπλή ένδειξη: και οι δύο.';
        panel.append(status);
        const grid = document.createElement('div'); grid.className = 'absence-calendar-grid';
        ['Δε', 'Τρ', 'Τε', 'Πε', 'Πα', 'Σα', 'Κυ'].forEach(text => { const label = document.createElement('span'); label.textContent = text; grid.append(label); });
        const offset = (new Date(month.getFullYear(), month.getMonth(), 1).getDay() + 6) % 7;
        for (let i = 0; i < offset; i++) grid.append(document.createElement('span'));
        const length = new Date(month.getFullYear(), month.getMonth() + 1, 0).getDate();
        for (let number = 1; number <= length; number++) {
            const date = new Date(month.getFullYear(), month.getMonth(), number, 12);
            const key = iso(date), counts = days[key] || {};
            const summary = `${dateFormat.format(date)}: ${counts.unjustified || 0} αδικαιολόγητες, ${counts.justified || 0} δικαιολογημένες ώρες`;
            const day = button(String(number), state === 'ready' ? summary : dateFormat.format(date), () => {
                target.value = key; fields[1].value = key; target.dispatchEvent(new Event('change', { bubbles: true }));
                panel.hidden = true; target.focus();
            });
            day.className = 'absence-calendar-day';
            if (counts.unjustified) day.classList.add('has-unjustified');
            if (counts.justified) day.classList.add('has-justified');
            if (key === target.value) { day.classList.add('calendar-selected'); day.setAttribute('aria-pressed', 'true'); }
            if (key === iso(new Date())) day.setAttribute('aria-current', 'date');
            grid.append(day);
        }
        panel.append(grid, button('Κλείσιμο ημερολογίου', 'Κλείσιμο ημερολογίου', () => { panel.hidden = true; target.focus(); }));
    }
    fields.slice(0, 1).forEach(field => {
        const open = () => { target = field; month = parse(field.value); month.setDate(1); panel.hidden = false; render(); };
        const trigger = button('📅 Ημερολόγιο απουσιών', 'Ημερολόγιο ημέρας δικαιολόγησης', open);
        trigger.className = 'btn btn-secondary btn-sm absence-calendar-trigger';
        trigger.setAttribute('aria-controls', panel.id); field.after(trigger);
        field.classList.add('colored-date-input');
        field.addEventListener('click', open);
    });
    fields[0].addEventListener('change', () => { fields[1].value = fields[0].value; });
    fields[0].form.addEventListener('submit', () => { fields[1].value = fields[0].value; });
    panel.addEventListener('keydown', event => { if (event.key === 'Escape') { event.stopPropagation(); panel.hidden = true; target.focus(); } });
    window.loadJustificationCalendar = async function (studentId) {
        const request = ++generation; days = {}; state = 'loading'; panel.hidden = true;
        try {
            const response = await fetch('student_card_ajax.php?format=calendar&student_id=' + encodeURIComponent(studentId), { cache: 'no-store' });
            if (!response.ok) throw new Error('HTTP');
            const data = await response.json();
            if (!data.days || data.error) throw new Error('Invalid calendar');
            if (request !== generation) return;
            days = data.days; state = 'ready';
        } catch (error) { if (request !== generation) return; days = {}; state = 'error'; }
        if (!panel.hidden) render();
    };
});
