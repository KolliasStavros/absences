<?php require_once __DIR__.'/../includes/school_calendar_reminders.php'; ?>
    <nav class="app-navbar">
        <a href="index.php" class="nav-brand">
            <span>📋</span>
            <div>
                <div style="display:flex; align-items:center; gap:6px;">
                    <span class="nav-brand-title">Παρουσιολόγιο</span>
                    <span class="badge badge-gray nav-version-badge" style="font-size:10px; font-weight:700;">v<?= htmlspecialchars(APP_VERSION, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="nav-school-subtitle" style="font-size:11px; font-weight:normal; color:var(--text-muted);"><?= htmlspecialchars($schoolName) ?></div>
            </div>
        </a>
        <div class="nav-user">
            <a href="calendar.php" class="btn <?= !empty($calendarNavigationActive) ? 'btn-primary' : 'btn-secondary' ?> btn-sm" <?= !empty($calendarNavigationActive) ? 'aria-current="page"' : '' ?>>📅 Ημερολόγιο<?= calendarReminderBadge(getDB(),$user,(int)$activeAcademicYear['id']) ?></a>
            <a href="marks.php" class="btn <?= !empty($marksNavigationActive) ? 'btn-primary' : 'btn-secondary' ?> btn-sm" <?= !empty($marksNavigationActive) ? 'aria-current="page"' : '' ?>>📚 Βαθμολογία</a>
            <button type="button" class="theme-toggle-btn" onclick="toggleSchoolTheme()" title="Εναλλαγή Θέματος">
                🌙 <span class="theme-toggle-text" style="font-size:12px; font-weight:600;">Σκοτεινό</span>
            </button>
            <span class="badge badge-success nav-academic-year" style="font-size:12px; padding:5px 9px; display:inline-flex; align-items:center; gap:4px; border:1px solid #86efac;" title="Τρέχον Σχολικό Έτος">
                📅 Έτος <strong><?= htmlspecialchars($activeAcademicYear['name'] ?? '2026-2027') ?></strong>
            </span>
            <?php if ($user['role'] === 'admin' || $user['role'] === 'teacher_admin'): ?>
                <a href="../admin/index.php" class="btn btn-primary btn-sm nav-admin-btn" title="Πίνακας Διαχείρισης" aria-label="Πίνακας Διαχείρισης">
                    <span class="btn-full-text">⚙️ Πίνακας Διαχείρισης</span>
                    <span class="btn-short-text">⚙️ Διαχείριση</span>
                </a>
            <?php endif; ?>
            <a href="help.php" class="btn btn-secondary btn-sm nav-help-btn" title="Οδηγίες Χρήσης">
                <span class="btn-full-text">❓ Βοήθεια</span>
                <span class="btn-short-text">❓</span>
            </a>
            <div class="user-badge nav-user-info">
                👤 <?= htmlspecialchars($user['name']) ?>
                <?php if ($user['role'] === 'admin'): ?>
                    <span class="role-tag">Διαχειριστής</span>
                <?php elseif ($user['role'] === 'teacher_admin'): ?>
                    <span class="role-tag" style="background:#7c3aed; color:#fff;">👑 Καθηγητής - Διαχειριστής</span>
                <?php else: ?>
                    <span class="role-tag">Καθηγητής</span>
                <?php endif; ?>
            </div>
            <?= logoutForm('btn btn-secondary btn-sm nav-logout-btn') ?>
        </div>
    </nav>

