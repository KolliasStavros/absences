// Explicit multi-date selection for a single student's justification form.
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
    const inputs = document.createElement('div'); inputs.hidden = true; fields[0].form.append(inputs);
    const selectionSummary = document.createElement('p'); selectionSummary.setAttribute('role','status');
    fields[0].parentElement.append(selectionSummary);
    let selected = new Set(), syncing = false;
    window.getJustificationDates = () => [...selected].sort();
    function syncSelection(notify = true) {
        const values = window.getJustificationDates();
        inputs.replaceChildren();
        values.forEach(value => { const input = document.createElement('input'); input.type='hidden'; input.name='selected_dates[]'; input.value=value; inputs.append(input); });
        fields[0].value = values[0] || ''; fields[1].value = fields[0].value;
        selectionSummary.textContent = values.length ? `Επιλεγμένες ημέρες (${values.length}): ` + values.map(value => value.split('-').reverse().join('/')).join(', ') : 'Δεν έχει επιλεγεί ημέρα.';
        if(notify) { syncing=true; fields[0].dispatchEvent(new Event('change',{bubbles:true})); syncing=false; }
    }
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
        caption.textContent = 'Επιλέξτε μία ή περισσότερες ημέρες, ακόμη και σε διαφορετικούς μήνες. Πατήστε ξανά μια ημέρα για να την αφαιρέσετε.';
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
            const total = Number(counts.unjustified || 0) + Number(counts.justified || 0);
            const summary = `${dateFormat.format(date)}: σύνολο ${total} απουσίες, ${counts.unjustified || 0} αδικαιολόγητες, ${counts.justified || 0} δικαιολογημένες ώρες`;
            const day = button(String(number), state === 'ready' ? summary : dateFormat.format(date), () => {
                if(selected.has(key)) selected.delete(key); else selected.add(key);
                syncSelection(); render();
                panel.querySelector(`[data-date="${key}"]`)?.focus();
            });
            day.className = 'absence-calendar-day';
            const dayNumber=document.createElement('span'); dayNumber.textContent=String(number);
            const dayCount=document.createElement('small'); dayCount.className='calendar-absence-count';
            dayCount.textContent=state==='ready'?`${total} απ.`:'—';
            dayCount.setAttribute('aria-hidden','true');
            day.replaceChildren(dayNumber,dayCount);
            if (counts.unjustified) day.classList.add('has-unjustified');
            if (counts.justified) day.classList.add('has-justified');
            day.dataset.date=key; day.setAttribute('aria-pressed',String(selected.has(key)));
            if (selected.has(key)) day.classList.add('calendar-selected');
            if (key === iso(new Date())) day.setAttribute('aria-current', 'date');
            grid.append(day);
        }
        panel.append(grid, button('Καθαρισμός επιλογής', 'Αφαίρεση όλων των επιλεγμένων ημερών', () => { selected.clear(); syncSelection(); render(); }), button('Κλείσιμο ημερολογίου', 'Κλείσιμο ημερολογίου', () => { panel.hidden = true; target.focus(); }));
    }
    fields.slice(0, 1).forEach(field => {
        const open = () => { target = field; month = parse(field.value); month.setDate(1); panel.hidden = false; render(); };
        const trigger = button('📅 Ημερολόγιο απουσιών', 'Ημερολόγιο επιλογής ημερών δικαιολόγησης', open);
        trigger.className = 'btn btn-secondary btn-sm absence-calendar-trigger';
        trigger.setAttribute('aria-controls', panel.id); field.after(trigger);
        field.classList.add('colored-date-input');
        field.addEventListener('click', open);
    });
    fields[0].addEventListener('change', () => { if(syncing) return; selected=new Set(fields[0].value?[fields[0].value]:[]); syncSelection(false); if(!panel.hidden) render(); });
    fields[0].form.addEventListener('submit', event => { if(!selected.size) { event.preventDefault(); selectionSummary.textContent='Επιλέξτε τουλάχιστον μία ημέρα.'; } });
    panel.addEventListener('keydown', event => { if (event.key === 'Escape') { event.stopPropagation(); panel.hidden = true; target.focus(); } });
    window.loadJustificationCalendar = async function (studentId) {
        const request = ++generation; days = {}; state = 'loading'; panel.hidden = true;
        selected=new Set(); syncSelection(false);
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
