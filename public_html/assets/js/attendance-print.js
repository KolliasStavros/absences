/* SPDX-License-Identifier: GPL-3.0-only */
(() => {
    'use strict';
    const configNode = document.getElementById('attendance-print-config');
    if (!configNode) return;
    const config = JSON.parse(configNode.textContent);
    const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
    const types = { absent: 'Α', leave: 'Αδ', expulsion: 'ΩΑ', justified: 'Δ' };
    const report = document.createElement('section');
    report.id = 'attendance-print-report';
    document.body.append(report);

    const build = () => {
        let total = 0;
        const rows = [...document.querySelectorAll('#attendanceTableBody .student-attendance-row')]
            .filter(row => !row.hidden && row.style.display !== 'none');
        const body = rows.map((row, index) => {
            const state = window.allStudentsState?.[row.dataset.studentId];
            const hours = new Map();
            if (Array.isArray(state?.attendances)) {
                state.attendances.forEach(att => {
                    const hour = Number(att.hour);
                    if (Number.isInteger(hour) && hour >= 1 && hour <= 8 && Number(att.is_absent ?? 1) !== 0) {
                        hours.set(hour, types[att.absence_type] || types.absent);
                    }
                });
            } else {
                row.querySelectorAll('[data-hour][data-current-type]').forEach(button => {
                    if (types[button.dataset.currentType]) hours.set(Number(button.dataset.hour), types[button.dataset.currentType]);
                });
            }
            total += hours.size;
            const sections = [...row.querySelectorAll('.badge-info')].map(badge => badge.textContent.trim()).join(', ');
            return `<tr><td>${index + 1}</td><td>${escape(row.dataset.am)}</td><th scope="row">${escape(row.dataset.name)}</th><td>${escape(sections)}</td>${Array.from({ length: 8 }, (_, i) => `<td>${hours.get(i + 1) || '—'}</td>`).join('')}<td>${hours.size}</td></tr>`;
        }).join('');
        const printedAt = new Date().toLocaleString('el-GR', { timeZone: 'Europe/Athens', dateStyle: 'short', timeStyle: 'short' });
        report.innerHTML = `<header><h1>${escape(config.school)}</h1><h2>ΗΜΕΡΗΣΙΟ ΔΕΛΤΙΟ ΑΠΟΥΣΙΩΝ</h2><p>Ημερομηνία: <strong>${escape(config.date)}</strong> · Σχολικό έτος: <strong>${escape(config.year)}</strong></p><p>${escape(config.filters)}</p></header>
            <p class="print-report-summary">Μαθητές: <strong>${rows.length}</strong> · Ώρες απουσίας: <strong>${total}</strong></p>
            <table><colgroup><col style="width:4%"><col style="width:8%"><col style="width:29%"><col style="width:12%">${'<col style="width:5%">'.repeat(8)}<col style="width:7%"></colgroup><thead><tr><th scope="col">Α/Α</th><th scope="col">Α.Μ.</th><th scope="col">Ονοματεπώνυμο μαθητή</th><th scope="col">Τμήμα</th>${Array.from({ length: 8 }, (_, i) => `<th scope="col">${i + 1}η</th>`).join('')}<th scope="col">Σύν.</th></tr></thead><tbody>${body || '<tr><td colspan="13">Δεν υπάρχουν μαθητές για τα επιλεγμένα φίλτρα.</td></tr>'}</tbody></table>
            <p class="print-report-legend"><strong>Ενδείξεις:</strong> Α: Απουσία · Αδ: Με άδεια · ΩΑ: Ωριαία αποβολή · Δ: Δικαιολογημένη.<br>—: Χωρίς καταχωρισμένη απουσία. Τα σύνολα περιλαμβάνουν όλους τους τύπους απουσιών.</p>
            <div class="print-report-footer"><span>Εκτυπώθηκε: ${escape(printedAt)}</span><div>Ο/Η Διευθυντής/ντρια<div class="print-report-signature">Υπογραφή</div></div></div>`;
    };
    // Also supports Ctrl+P. Capture the current filtered, live-updated rows at print time.
    window.addEventListener('beforeprint', build);
})();
