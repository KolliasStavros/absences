/**
 * Theme & PWA Helper for School Absences Application
 */
(function() {
    // 1. Theme Initialization
    let savedTheme = null;
    try { savedTheme = localStorage.getItem('school_absences_theme'); } catch (_) { /* Storage may be unavailable on shared/private devices. */ }
    const initialTheme = savedTheme === 'dark' ? 'dark' : 'light';
    document.documentElement.setAttribute('data-theme', initialTheme);

    window.toggleSchoolTheme = function() {
        const current = document.documentElement.getAttribute('data-theme') || 'light';
        const next = current === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        try { localStorage.setItem('school_absences_theme', next); } catch (_) { /* The current page still changes theme. */ }
        updateThemeToggleIcons(next);
    };

    function updateThemeToggleIcons(theme) {
        document.querySelectorAll('.theme-toggle-btn').forEach(btn => {
            btn.innerHTML = (theme === 'dark') ? '☀️ <span class="theme-toggle-text" style="font-size:12px; font-weight:600;">Φωτεινό</span>' : '🌙 <span class="theme-toggle-text" style="font-size:12px; font-weight:600;">Σκοτεινό</span>';
            btn.setAttribute('title', theme === 'dark' ? 'Αλλαγή σε φωτεινό θέμα' : 'Αλλαγή σε σκοτεινό θέμα');
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        const current = document.documentElement.getAttribute('data-theme') || 'light';
        updateThemeToggleIcons(current);
    });

    // 2. Service Worker Registration for PWA (if supported and HTTPS or localhost)
    if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
        window.addEventListener('load', function() {
            // Register relative to root
            const swPath = (location.pathname.indexOf('/admin/') !== -1 || location.pathname.indexOf('/teacher/') !== -1) ? '../sw.js' : 'sw.js';
            navigator.serviceWorker.register(swPath).catch(function(err) {
                // Fail silently in development
            });
        });
    }
})();
