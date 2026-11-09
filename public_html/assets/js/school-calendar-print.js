'use strict';
(() => {
    document.addEventListener('click', event => {
        const dialog = document.getElementById('calendar-print-options');
        if (!dialog) return;
        if (event.target.closest('[data-calendar-print-close]')) { dialog.close(); return; }
        if (!event.target.closest('[data-calendar-print]')) return;
        const form = dialog.querySelector('[data-calendar-print-form]');
        const range = form.querySelector('[data-calendar-print-range]');
        const update = () => {
            const selected = form.elements.scope.value === 'range';
            range.hidden = !selected;
            for (const name of ['from', 'to']) {
                form.elements[name].disabled = !selected;
                form.elements[name].required = selected;
            }
            form.elements.to.min = form.elements.from.value;
        };
        form.elements.scope.onchange = update;
        form.elements.from.onchange = update;
        update();
        dialog.showModal();
    });
    const clear = () => {
        document.getElementById('calendar-print-report')?.remove();
        document.body.classList.remove('calendar-printing');
    };
    window.addEventListener('beforeprint', () => {
        clear();
        const template = document.getElementById('calendar-print-template');
        if (!template) return;
        document.body.append(template.content.cloneNode(true));
        document.body.classList.add('calendar-printing');
    });
    window.addEventListener('afterprint', clear);
})();
