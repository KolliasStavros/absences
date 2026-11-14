'use strict';
// A poll that overlaps any local mutation must never repaint newer UI state.
(() => {
    if (window.attendanceSync) return;
    let revision = 0;
    let pending = 0;
    const fetchOriginal = window.fetch.bind(window);
    const mutations = new Set(['toggle_student_absence.php', 'save_attendance.php', 'save_attendance_changes.php', 'delete_attendance_hour.php', 'student_email.php']);
    window.attendanceSync = Object.freeze({
        snapshot: () => pending === 0 ? revision : null,
        accepts: snapshot => snapshot !== null && pending === 0 && snapshot === revision
    });
    window.fetch = async (input, options = {}) => {
        const url = new URL(typeof input === 'string' ? input : (input.url || String(input)), window.location.href);
        const method = String(options.method || input.method || 'GET').toUpperCase();
        if (url.origin !== window.location.origin || method === 'GET' || method === 'HEAD' || !mutations.has(url.pathname.split('/').pop())) return fetchOriginal(input, options);
        revision++;
        pending++;
        try { return await fetchOriginal(input, options); }
        finally { pending--; revision++; }
    };
})();
