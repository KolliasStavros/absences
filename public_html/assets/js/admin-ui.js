// Presentation-only controls; attendance handlers and API contracts stay in the page.
document.addEventListener('DOMContentLoaded', function () {
    const importToggle = document.getElementById('importInstructionsToggle');
    const importInstructions = document.getElementById('importInstructions');
    if (importToggle && importInstructions) {
        importToggle.addEventListener('click', function () {
            const expanded = importToggle.getAttribute('aria-expanded') !== 'true';
            importToggle.setAttribute('aria-expanded', String(expanded));
            importInstructions.classList.toggle('instructions-open', expanded);
        });
    }
    const teacherPanel = document.getElementById('addTeacherPanel');
    const teacherOpen = document.getElementById('openAddTeacher');
    if (teacherPanel && teacherOpen) {
        const mobile = window.matchMedia('(max-width: 768px)');
        function closeTeacherPanel(restoreFocus) {
            teacherPanel.classList.remove('teacher-add-open');
            teacherPanel.removeAttribute('role');
            teacherPanel.removeAttribute('aria-modal');
            teacherPanel.removeAttribute('aria-labelledby');
            teacherOpen.setAttribute('aria-expanded', 'false');
            if (restoreFocus && mobile.matches) teacherOpen.focus();
        }
        teacherOpen.addEventListener('click', function () {
            if (!mobile.matches) return;
            teacherPanel.classList.add('teacher-add-open');
            teacherPanel.setAttribute('role', 'dialog');
            teacherPanel.setAttribute('aria-modal', 'true');
            teacherPanel.setAttribute('aria-labelledby', 'addTeacherTitle');
            teacherOpen.setAttribute('aria-expanded', 'true');
            teacherPanel.querySelector('.teacher-add-card').scrollTop = 0;
            teacherPanel.querySelector('input[name="name"]').focus();
        });
        document.getElementById('closeAddTeacher').addEventListener('click', function () { closeTeacherPanel(true); });
        teacherPanel.addEventListener('click', function (event) {
            if (event.target === teacherPanel) closeTeacherPanel(true);
        });
        document.addEventListener('keydown', function (event) {
            if (!teacherPanel.classList.contains('teacher-add-open')) return;
            if (event.key === 'Escape') closeTeacherPanel(true);
            if (event.key === 'Tab') {
                const controls = teacherPanel.querySelectorAll('button, input:not([type="hidden"]), select, textarea, a[href]');
                const first = controls[0], last = controls[controls.length - 1];
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
            }
        });
        mobile.addEventListener('change', function () { closeTeacherPanel(false); });
    }
    document.querySelectorAll('.student-expand').forEach(function (button, index) {
        const row = button.closest('tr');
        row.id = row.id || 'student-card-' + index;
        button.setAttribute('aria-controls', row.id);
        button.addEventListener('click', function () {
            const expanded = button.getAttribute('aria-expanded') !== 'true';
            button.setAttribute('aria-expanded', String(expanded));
            button.setAttribute('title', expanded ? 'Λιγότερα στοιχεία μαθητή' : 'Περισσότερα στοιχεία μαθητή');
            button.setAttribute('aria-label', button.title);
            row.classList.toggle('student-expanded', expanded);
        });
    });
    const toggle = document.getElementById('adminNavToggle');
    const nav = document.getElementById('adminNavigation');
    toggle.addEventListener('click', function () {
        const expanded = toggle.getAttribute('aria-expanded') !== 'true';
        toggle.setAttribute('aria-expanded', String(expanded));
        nav.classList.toggle('mobile-open', expanded);
    });
    nav.querySelectorAll('.nav-dropdown-btn').forEach(function (button, index) {
        const menu = button.nextElementSibling;
        menu.id = 'adminDropdown-' + index;
        button.setAttribute('aria-controls', menu.id);
        button.setAttribute('aria-expanded', 'false');
        button.addEventListener('click', function () {
            const expanded = button.getAttribute('aria-expanded') !== 'true';
            button.setAttribute('aria-expanded', String(expanded));
            button.parentElement.classList.toggle('dropdown-open', expanded);
        });
    });
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        nav.classList.remove('mobile-open');
        toggle.setAttribute('aria-expanded', 'false');
        nav.querySelectorAll('.dropdown-open').forEach(function (dropdown) {
            dropdown.classList.remove('dropdown-open');
            dropdown.querySelector('button').setAttribute('aria-expanded', 'false');
        });
        const account = document.querySelector('.mobile-account');
        if (account) account.open = false;
    });

    // Live updates replace cell contents and insert rows. Apply the same accessible
    // labels to initial and refreshed controls without changing attendance actions.
    const rows = document.getElementById('attendanceTableBody');
    function labelAttendanceControls() {
        if (!rows) return;
        rows.querySelectorAll('button').forEach(function (button) {
            const label = button.getAttribute('title') || button.textContent.trim();
            if (!button.hasAttribute('aria-label')) button.setAttribute('aria-label', label);
            if (!button.hasAttribute('title')) button.setAttribute('title', label);
        });
        rows.querySelectorAll('.student-attendance-row').forEach(function (row) {
            const nameCell = row.children[2];
            if (!nameCell || nameCell.querySelector('.mobile-student-am')) return;
            const am = document.createElement('small');
            am.className = 'mobile-student-am';
            am.textContent = 'Α.Μ. ' + (row.getAttribute('data-am') || '');
            nameCell.appendChild(am);
        });
    }
    if (rows) {
        labelAttendanceControls();
        new MutationObserver(labelAttendanceControls).observe(rows, { childList: true, subtree: true });
    }
});
