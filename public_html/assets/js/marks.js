/* SPDX-License-Identifier: GPL-3.0-only */
(() => {
    'use strict';
    const specialStatus = status => ['absent', 'exempt', 'not_submitted', 'no_show'].includes(status);
    const toggle = document.querySelector('[data-marks-menu]');
    toggle?.addEventListener('click', () => {
        const sidebar = document.getElementById('marks-navigation');
        toggle.setAttribute('aria-expanded', String(sidebar.classList.toggle('is-open')));
    });
    let dirty = false, saving = false;
    const allowFilterChange = () => {
        if (dirty && !window.confirm('Υπάρχουν μη αποθηκευμένες αλλαγές. Να απορριφθούν;')) return false;
        dirty = false;
        return true;
    };
    const grade = document.querySelector('[data-marks-grade]');
    const section = document.querySelector('[data-marks-section]');
    const course = document.querySelector('[data-marks-course]');
    if (grade && section && course) {
        const sections = [...section.options].slice(1);
        const courses = [...course.options].slice(1);
        const fill = (select, options, enabled, value = '') => {
            select.replaceChildren(select.options[0], ...options);
            select.value = value;
            select.disabled = !enabled;
        };
        const refreshCourses = (value = '') => fill(course, courses.filter(option => option.dataset.section === section.value), !!section.value, value);
        const refreshSections = (value = '') => fill(section, sections.filter(option => option.dataset.grade === grade.value), !!grade.value, value);
        refreshSections(section.value);
        refreshCourses(course.value);
        [grade, section, course].forEach(select => { select.dataset.previous = select.value; });
        [grade, section].forEach(select => select.addEventListener('change', () => {
            if (!allowFilterChange()) { select.value = select.dataset.previous; return; }
            if (select === grade) refreshSections();
            refreshCourses();
            [grade, section, course].forEach(field => { field.dataset.previous = field.value; });
        }));
    }
    const overview = document.querySelector('[data-marks-overview]');
    if (overview) {
        const catalog = JSON.parse(document.getElementById('marks-overview-catalog').textContent);
        const fields = Object.fromEntries(['grade_id', 'section_id', 'subject_id', 'teacher_id'].map(key => [key, overview.elements.namedItem(key)]));
        const options = Object.fromEntries(Object.entries(fields).map(([key, field]) => [key, [...field.options]]));
        const restrict = (key, allowed) => {
            const field = fields[key], value = field.value;
            field.replaceChildren(...options[key].filter(option => !option.value || allowed.has(option.value)));
            field.value = allowed.has(value) ? value : '';
        };
        const refresh = () => {
            let scope = catalog.filter(item => !fields.grade_id.value || item.grade_id === fields.grade_id.value);
            restrict('section_id', new Set(scope.map(item => item.section_id)));
            scope = scope.filter(item => !fields.section_id.value || item.section_id === fields.section_id.value);
            restrict('subject_id', new Set(scope.map(item => item.subject_id)));
            restrict('teacher_id', new Set(scope.flatMap(item => item.teachers)));
        };
        fields.grade_id.addEventListener('change', refresh);
        fields.section_id.addEventListener('change', refresh);
        // Keep submitted combinations intact on load, including empty results.
    }
    const form = document.getElementById('marks-entry');
    if (!form) return;
    const rows = [...form.querySelectorAll('[data-mark-row]')].filter(row => row.dataset.active === '1');
    const output = document.getElementById('marks-message');
    const buttons = [...form.querySelectorAll('[data-save-action]')];
    const toUnits = text => {
        const s = text.trim().replace(',', '.');
        if (!/^[0-9]{1,4}(?:\.[0-9]{1,2})?$/.test(s)) return null;
        const [whole, fraction = ''] = s.split('.');
        return Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
    };
    const fromUnits = n => (n / 100).toFixed(2).replace(/0+$/, '').replace(/\.$/, '').replace('.', ',');
    const displayGrade = units => fromUnits(units).replace(/^(\d)(?=,|$)/, '0$1');
    const min = Number(form.dataset.min), max = Number(form.dataset.max), step = Number(form.dataset.step);
    const valid = value => value !== null && value >= min && value <= max && (value - min) % step === 0;
    const updateProgress = () => {
        const done = rows.filter(row => {
            const status = row.querySelector('[data-status]').value;
            return specialStatus(status) || (status === 'value' && valid(toUnits(row.querySelector('[data-number]').value)));
        }).length;
        document.getElementById('marks-progress').textContent = `${done}/${rows.length} ολοκληρωμένες καταχωρίσεις${dirty ? ' · Μη αποθηκευμένες αλλαγές' : ''}`;
    };
    const changed = () => { dirty = true; output.hidden = true; updateProgress(); };
    const reflect = row => {
        const input = row.querySelector('[data-number]'), range = row.querySelector('[data-range]'), status = row.querySelector('[data-status]');
        const units = toUnits(input.value), numeric = status.value === 'value';
        input.disabled = specialStatus(status.value);
        range.disabled = specialStatus(status.value);
        range.classList.toggle('marks-empty-range', !numeric || !valid(units));
        range.value = String(valid(units) ? units : min);
        range.setAttribute('aria-valuetext', numeric && valid(units) ? input.value : 'Χωρίς αριθμητικό βαθμό');
        input.setCustomValidity(numeric && input.value !== '' && !valid(units) ? 'Ελέγξτε τα όρια και το βήμα του βαθμού.' : '');
    };
    rows.forEach(row => {
        const input = row.querySelector('[data-number]'), range = row.querySelector('[data-range]'), status = row.querySelector('[data-status]');
        input.addEventListener('input', () => { status.value = input.value === '' ? 'ungraded' : 'value'; reflect(row); changed(); });
        range.addEventListener('input', () => { input.value = displayGrade(Number(range.value)); status.value = 'value'; reflect(row); changed(); });
        status.addEventListener('change', () => { if (status.value !== 'value') input.value = ''; reflect(row); changed(); if (status.value === 'value') input.focus(); });
        row.querySelector('[data-clear]').addEventListener('click', () => { input.value = ''; status.value = 'ungraded'; reflect(row); changed(); });
        reflect(row);
        input.addEventListener('blur', () => {
            const units = toUnits(input.value);
            if (status.value === 'value' && valid(units)) {
                input.value = displayGrade(units);
                reflect(row);
            }
        });
        input.addEventListener('keydown', event => {
            if (event.key === 'Enter') { event.preventDefault(); rows[rows.indexOf(row) + 1]?.querySelector('[data-number]').focus(); }
        });
    });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (saving || !form.reportValidity()) return;
        const action = event.submitter?.dataset.saveAction || 'save';
        if (action === 'submit' && !window.confirm('Να γίνει οριστική υποβολή; Μετά απαιτείται επανάνοιγμα από διαχειριστή για διορθώσεις.')) return;
        const marks = rows.map(row => ({ id: Number(row.dataset.id), status: row.querySelector('[data-status]').value, value: row.querySelector('[data-number]').value.trim().replace(/^0+(?=\d)/, '') }));
        const payload = Object.fromEntries(new FormData(form));
        Object.assign(payload, { action, marks });
        saving = true; buttons.forEach(b => b.disabled = true);
        output.hidden = false; output.className = 'marks-notice'; output.textContent = 'Αποθήκευση…';
        try {
            const response = await fetch(form.action, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload), credentials: 'same-origin' });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.error || 'Δεν ολοκληρώθηκε η αποθήκευση.');
            dirty = false;
            const nextUrl = new URL(window.location.href);
            nextUrl.searchParams.set('saved', '1');
            window.location.assign(nextUrl.toString());
        } catch (error) {
            output.className = 'marks-notice marks-error'; output.textContent = error.message || 'Πρόβλημα σύνδεσης. Οι αλλαγές παραμένουν στη σελίδα.';
            output.focus(); saving = false; buttons.forEach(b => b.disabled = false);
        }
    });
    window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
    document.querySelectorAll('[data-marks-filter]').forEach(filter => filter.addEventListener('change', () => {
        if (dirty && !window.confirm('Υπάρχουν μη αποθηκευμένες αλλαγές. Να απορριφθούν;')) { filter.value = filter.dataset.original; return; }
        dirty = false; filter.form.submit();
    }));
    updateProgress();
})();
