<?php
// teacher/index.php - Teacher Attendance Screen (Mobile First with Unsaved Changes Protection, 3 States & Gender Awareness)
require_once __DIR__ . '/../config/config.php';
requireTeacher();

$user = getCurrentUser();
$db = getDB();
$activeAcademicYear = getActiveAcademicYear();
$activeYearId = $activeAcademicYear ? (int)$activeAcademicYear['id'] : 1;

// Fetch assigned sections for this teacher (or all sections if admin or teacher_admin) for the active academic year
if ($user['role'] === 'admin' || $user['role'] === 'teacher_admin') {
    $stmt = $db->query("
        SELECT s.id, s.name, g.name as grade_name 
        FROM sections s 
        JOIN grades g ON s.grade_id = g.id 
        ORDER BY g.name ASC, s.name ASC
    ");
    $sections = $stmt->fetchAll();
} else {
    $stmt = $db->prepare("
        SELECT s.id, s.name, g.name as grade_name 
        FROM sections s 
        JOIN grades g ON s.grade_id = g.id 
        JOIN teacher_sections ts ON s.id = ts.section_id 
        WHERE ts.teacher_id = ? AND ts.academic_year_id = ?
        ORDER BY g.name ASC, s.name ASC
    ");
    $stmt->execute([$user['id'], $activeYearId]);
    $sections = $stmt->fetchAll();
}

$schoolName = getSchoolSetting('school_name', '1ο Γενικό Λύκειο');
$today = date('Y-m-d');
$bellSchedule = getBellSchedule();
$suggestedHour = getCurrentSuggestedTeachingHour();
?>
<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Παρουσιολόγιο Τμήματος - <?= htmlspecialchars($schoolName) ?></title>
    <meta name="theme-color" content="#4f46e5">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Παρουσιολόγιο">
    <link rel="apple-touch-icon" href="../assets/icons/icon-192.png">
    <link rel="manifest" href="../manifest.json">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css?v=daylight-20261029-1">
    <?php require __DIR__.'/../includes/diagnostics_client.php'; ?>
    <script src="../assets/js/theme.js?v=20261029-1"></script>
    <style>
        .filter-bar {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 12px;
            margin-bottom: 20px;
        }
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #10b981;
            color: #fff;
            padding: 14px 22px;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            font-weight: 600;
            display: none;
            z-index: 9999;
            animation: slideDown 0.3s ease;
        }
        @keyframes slideDown {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .btn-save-unsaved {
            background-color: #dc2626 !important;
            animation: pulseSave 2s infinite;
        }
        @keyframes pulseSave {
            0% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.6); }
            70% { box-shadow: 0 0 0 9px rgba(220, 38, 38, 0); }
            100% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0); }
        }
        /* Expulsion Card Styles */
        .student-card.expulsion {
            border-color: #f59e0b;
            background: #fffbeb;
        }
        .student-card.expulsion .status-pill {
            background: #d97706;
            color: #fff;
        }
        .student-card {
            transition: transform 0.1s ease, box-shadow 0.15s ease;
            touch-action: pan-y;
            -webkit-tap-highlight-color: transparent;
        }
        .student-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
        }
        .student-card.locked {
            opacity: 0.85;
            cursor: not-allowed !important;
        }
        .student-card.locked:hover {
            transform: none !important;
            box-shadow: none !important;
        }
        .student-card.justified {
            border-color: #93c5fd;
            background: #eff6ff;
        }
        .student-card.justified .status-pill {
            background: #2563eb;
            color: #fff;
        }
        .student-card.justified.justified-locked {
            cursor: not-allowed !important;
        }
        .legend-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
            font-weight: 600;
            padding: 2px 7px;
            border-radius: 4px;
        }

        /* View Mode Toggle Styles */
        .teacher-attendance-page .nav-admin-btn {
            background: #4f46e5;
            border-color: #4f46e5;
            color: #fff;
            font-weight: 700;
            box-shadow: 0 3px 8px rgba(79, 70, 229, 0.25);
        }
        .teacher-attendance-page .nav-admin-btn:hover {
            background: #4338ca;
            border-color: #4338ca;
            color: #fff;
        }
        .teacher-attendance-page .nav-admin-btn:focus-visible {
            outline: 3px solid #a5b4fc;
            outline-offset: 3px;
        }
        .student-card.day-summary {
            --day-present-bg: #f0fdf4;
            --day-absent-bg: #fef2f2;
            --day-expulsion-bg: #fffbeb;
            --day-justified-bg: #eff6ff;
            background: var(--day-card-background);
            cursor: default;
        }
        .student-card.day-summary.day-mixed {
            border-color: #818cf8;
        }
        [data-theme="dark"] .student-card.day-summary {
            --day-present-bg: #102c25;
            --day-absent-bg: #38202a;
            --day-expulsion-bg: #352b1b;
            --day-justified-bg: #172c46;
            background: var(--day-card-background);
        }
        .view-mode-toggle {
            display: inline-flex;
            background: #e2e8f0;
            padding: 2px;
            border-radius: 8px;
            gap: 2px;
            border: 1px solid var(--border);
        }
        .btn-view-toggle {
            background: transparent;
            border: none;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }
        .btn-view-toggle:hover {
            color: var(--text-main);
        }
        .btn-view-toggle.active {
            background: var(--surface, #ffffff);
            color: var(--primary, #4f46e5);
            box-shadow: 0 1px 3px rgba(0,0,0,0.12);
        }

        /* Student Grid base styling */
        .student-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
            gap: 14px;
            margin-top: 18px;
        }
        .student-card {
            min-width: 0;
            overflow: hidden;
            border: 2px solid #e2e8f0;
            border-radius: var(--radius-md);
            padding: 12px 16px;
            cursor: pointer;
            user-select: none;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all 0.15s ease;
            background: #fff;
        }
        .student-card .student-info {
            flex: 1 1 0;
            min-width: 0;
            margin-right: 10px;
        }
        .student-card .student-am {
            font-size: 11.5px;
            color: var(--text-muted);
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.4;
            margin-bottom: 2px;
        }
        .student-card .student-name {
            font-size: 14.5px;
            font-weight: 700;
            color: var(--text-main);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.3;
        }

        /* Full Day Bar Mode: Stacked layout with full-width bar */
        .student-grid.view-bar {
            grid-template-columns: repeat(auto-fill, minmax(360px, 1fr)) !important;
            gap: 16px !important;
        }
        .student-grid.view-bar .student-card {
            display: flex !important;
            flex-direction: column !important;
            align-items: stretch !important;
            justify-content: space-between !important;
            padding: 14px 16px !important;
            gap: 12px !important;
            min-height: 108px !important;
        }
        .student-grid.view-bar .student-card .student-info {
            width: 100% !important;
            flex: none !important;
            margin-right: 0 !important;
        }
        .student-grid.view-bar .student-am {
            font-size: 11.5px !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            margin-bottom: 3px !important;
        }
        .student-grid.view-bar .student-name {
            font-size: 15px !important;
            font-weight: 700 !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
        }
        .student-grid.view-bar .hours-bar-wrapper {
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            width: 100% !important;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 4px 6px !important;
            gap: 6px !important;
            box-sizing: border-box !important;
        }
        .student-grid.view-bar .hour-pill-btn {
            flex: 1 1 0 !important;
            height: 32px !important;
            min-width: 0 !important;
            font-size: 13px !important;
            font-weight: 700 !important;
            border-radius: 6px !important;
        }

        @media (max-width: 600px) {
            .student-grid.view-bar {
                grid-template-columns: 1fr !important;
            }
            .student-grid {
                grid-template-columns: 1fr !important;
            }
        }

        /* Hourly Bar Buttons */
        .hours-bar-wrapper {
            display: inline-flex;
            align-items: center;
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 3px 5px;
            gap: 4px;
            flex-shrink: 0;
            max-width: 100%;
        }
        .hour-pill-btn {
            min-width: 28px;
            height: 28px;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #334155;
            font-weight: 700;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.15s ease;
            padding: 0 2px;
            box-sizing: border-box;
            user-select: none;
        }
        .hour-pill-btn:hover {
            transform: translateY(-1px);
        }
        .hour-pill-btn.current-hour {
            outline: 2.5px solid #6366f1;
            outline-offset: 1px;
            font-weight: 800;
        }
        .hour-pill-btn.present {
            background: #ffffff;
            border-color: #cbd5e1;
            color: #475569;
        }
        .hour-pill-btn.absent {
            background: #ef4444 !important;
            border-color: #dc2626 !important;
            color: #ffffff !important;
        }
        .hour-pill-btn.expulsion {
            background: #f59e0b !important;
            border-color: #d97706 !important;
            color: #ffffff !important;
        }
        .hour-pill-btn.justified {
            background: #3b82f6 !important;
            border-color: #2563eb !important;
            color: #ffffff !important;
        }

        [data-theme="dark"] .view-mode-toggle {
            background: #0f172a;
            border-color: #334155;
        }
        [data-theme="dark"] .btn-view-toggle.active {
            background: #1e293b;
            color: #818cf8;
        }
        [data-theme="dark"] .hours-bar-wrapper,
        [data-theme="dark"] .student-grid.view-bar .hours-bar-wrapper {
            background: #0f172a !important;
            border-color: #334155 !important;
        }
        [data-theme="dark"] .hour-pill-btn.present,
        [data-theme="dark"] .student-grid.view-bar .hour-pill-btn.present {
            background: #1e293b !important;
            border-color: #475569 !important;
            color: #cbd5e1 !important;
        }
        [data-theme="dark"] .hour-pill-btn.current-hour {
            outline-color: #818cf8;
        }

        /* Desktop specific layout */
        @media (min-width: 769px) {
            .btn-short-text { display: none !important; }
            .lbl-short { display: none !important; }
            .legend-mobile { display: none !important; }
            .filter-actions-bar {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-top: 16px;
                border-top: 1px solid var(--border);
                padding-top: 14px;
                flex-wrap: wrap;
                gap: 12px;
            }
            .quick-tools-area {
                display: flex !important;
                align-items: center !important;
                gap: 10px !important;
                flex-wrap: nowrap !important;
                flex-shrink: 0 !important;
            }
            .quick-tools-area input#studentSearchInput {
                width: 220px !important;
                max-width: 220px !important;
                height: 36px !important;
                padding: 6px 12px !important;
                font-size: 13px !important;
                margin-bottom: 0 !important;
            }
            .quick-tools-area #btnAllPresent {
                white-space: nowrap !important;
                flex-shrink: 0 !important;
                height: 36px !important;
                padding: 0 14px !important;
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                font-size: 13px !important;
            }
        }

        /* Mobile specific ultra-compact layout */
        @media (max-width: 768px) {
            .app-navbar {
                padding: 6px 10px !important;
                min-height: 44px;
            }
            .nav-brand span:first-child {
                font-size: 18px !important;
            }
            .nav-brand-title {
                font-size: 14.5px !important;
            }
            .nav-school-subtitle,
            .nav-version-badge,
            .nav-academic-year,
            .nav-user-info {
                display: none !important;
            }
            .nav-user {
                gap: 6px !important;
            }
            .theme-toggle-btn {
                padding: 4px 7px !important;
                font-size: 13px !important;
            }
            .theme-toggle-btn span,
            .theme-toggle-text {
                display: none !important;
            }
            .btn-full-text {
                display: none !important;
            }
            .btn-short-text {
                display: inline !important;
            }
            .nav-admin-btn,
            .nav-help-btn,
            .nav-logout-btn {
                padding: 4px 8px !important;
                font-size: 12px !important;
            }
            .lbl-full {
                display: none !important;
            }
            .lbl-short {
                display: inline !important;
            }

            .main-container {
                margin: 6px auto !important;
                padding: 0 6px !important;
                width: 100% !important;
            }
            .teacher-filter-card {
                padding: 8px 10px !important;
                margin-bottom: 8px !important;
                border-radius: 10px !important;
            }
            .filter-bar {
                display: grid !important;
                grid-template-columns: 1.25fr 0.95fr 1.15fr !important;
                gap: 6px !important;
                margin-bottom: 6px !important;
            }
            .filter-bar .form-group label {
                font-size: 10.5px !important;
                font-weight: 600 !important;
                margin-bottom: 2px !important;
                color: var(--text-muted);
                text-transform: uppercase;
                letter-spacing: 0.2px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .filter-bar .form-control {
                height: 33px !important;
                padding: 3px 6px !important;
                font-size: 12px !important;
                border-radius: 6px !important;
            }

            .filter-actions-bar {
                margin-top: 6px !important;
                padding-top: 6px !important;
                border-top: 1px solid var(--border);
                display: flex !important;
                flex-direction: column !important;
                gap: 6px !important;
            }
            .quick-tools-area {
                display: flex !important;
                align-items: center !important;
                gap: 6px !important;
                width: 100% !important;
            }
            .quick-tools-area input#studentSearchInput {
                flex: 1 !important;
                min-width: 0 !important;
                max-width: 100% !important;
                height: 32px !important;
                padding: 4px 8px !important;
                font-size: 12px !important;
                border-radius: 6px !important;
            }
            .quick-tools-area #btnAllPresent {
                flex-shrink: 0 !important;
                height: 32px !important;
                padding: 4px 9px !important;
                font-size: 11.5px !important;
                white-space: nowrap !important;
                border-radius: 6px !important;
            }

            .legend-desktop {
                display: none !important;
            }
            .legend-mobile {
                display: flex !important;
                align-items: center !important;
                justify-content: space-between !important;
                gap: 4px !important;
                font-size: 10px !important;
                font-weight: 600 !important;
                color: var(--text-muted);
                padding: 1px 0 !important;
                overflow-x: auto;
                white-space: nowrap;
            }
            .legend-mobile .leg-item {
                display: inline-flex !important;
                align-items: center !important;
                gap: 3px !important;
                padding: 2px 5px !important;
                border-radius: 4px !important;
            }

            #ownershipBanner {
                margin-top: 6px !important;
                padding: 6px 10px !important;
                font-size: 11.5px !important;
                line-height: 1.35 !important;
                border-radius: 6px !important;
            }
            #statusIndicator {
                margin-top: 4px !important;
                font-size: 11px !important;
            }
            #statusIndicator:empty {
                display: none !important;
            }

            .student-grid {
                margin-top: 8px !important;
                gap: 8px !important;
            }
            .student-card {
                padding: 9px 12px !important;
                border-radius: 8px !important;
            }
            .student-info .student-am {
                font-size: 10px !important;
            }
            .student-info .student-name {
                font-size: 13.5px !important;
            }
            .status-pill {
                font-size: 10.5px !important;
                padding: 2px 7px !important;
            }

            .floating-bar {
                padding: 8px 12px !important;
                margin-top: 14px !important;
                bottom: 8px !important;
                border-radius: 10px !important;
                gap: 6px !important;
            }
            .floating-bar .btn {
                padding: 8px 12px !important;
                font-size: 13px !important;
            }
        }

        .attendance-save-feedback {
            flex-basis: 100%;
            font-size: 13px;
            line-height: 1.5;
            color: var(--text-muted);
            overflow-wrap: anywhere;
        }
        .attendance-save-feedback[data-state="success"] { color: var(--success); }
        .attendance-save-feedback[data-state="error"] { color: var(--danger); }
        .attendance-save-actions { display: flex; flex-wrap: wrap; gap: 10px; }
        .floating-bar { flex-wrap: wrap; }
        @media (max-width: 768px) {
            .attendance-save-actions { width: 100%; }
            .attendance-save-actions .btn { flex: 1 1 180px; min-height: 44px; }
            .floating-bar { bottom: max(8px, env(safe-area-inset-bottom)) !important; }
        }
        @media (prefers-reduced-motion: reduce) {
            .btn-save-unsaved { animation: none; }
        }
        @media (max-width: 360px) {
            .filter-bar {
                grid-template-columns: 1.2fr 1fr !important;
            }
            .filter-bar .fg-date {
                grid-column: 1 / -1 !important;
            }
        }
    </style>
    <link rel="stylesheet" href="../assets/css/attendance-responsive.css?v=20261029-1">
</head>
<body class="teacher-attendance-page">
    <div id="toast" class="toast" role="status" aria-live="polite"></div>

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

    <div class="main-container">
        <?php if (empty($sections)): ?>
            <div class="card" style="text-align:center; padding:48px 20px;">
                <div style="font-size:48px; margin-bottom:12px;">📋</div>
                <h2>Δεν έχουν ανατεθεί τμήματα</h2>
                <p style="color:var(--text-muted); margin-top:8px;">
                    Δεν βρέθηκαν ανατεθειμένα τμήματα στον λογαριασμό σας. Παρακαλώ επικοινωνήστε με τη γραμματεία.
                </p>
            </div>
        <?php else: ?>
            <div class="card teacher-filter-card">
                <div class="filter-bar">
                    <div class="form-group fg-section" style="margin-bottom:0;">
                        <label for="selectSection"><span class="lbl-full">Επιλογή Τμήματος</span><span class="lbl-short">Τμήμα</span></label>
                        <?php
                        $sectionsByGrade = [];
                        foreach ($sections as $s) {
                            $sectionsByGrade[$s['grade_name']][] = $s;
                        }
                        ?>
                        <select id="selectSection" class="form-control" style="font-weight:600;">
                            <?php foreach ($sectionsByGrade as $gName => $gSecs): ?>
                                <optgroup label="Τάξη <?= htmlspecialchars($gName) ?>">
                                    <?php foreach ($gSecs as $s): ?>
                                        <option value="<?= $s['id'] ?>">
                                            <?= htmlspecialchars($s['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group fg-hour" style="margin-bottom:0;">
                        <label for="selectHour"><span class="lbl-full">Διδακτική Ώρα</span><span class="lbl-short">Ώρα</span></label>
                        <select id="selectHour" class="form-control" style="font-weight:600;">
                            <?php if (isAdmin()): ?><option value="all" hidden>Όλες οι ώρες (1η–7η)</option><?php endif; ?>
                            <?php for ($h = 1; $h <= 7; $h++): ?>
                                <?php 
                                    $timeLabel = '';
                                    if (isset($bellSchedule[$h])) {
                                        $timeLabel = " ({$bellSchedule[$h]['start']}-{$bellSchedule[$h]['end']})";
                                    }
                                    $selected = ($h === $suggestedHour) ? 'selected' : '';
                                ?>
                                <option value="<?= $h ?>" <?= $selected ?>><?= $h ?>η ώρα<?= $timeLabel ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="form-group fg-date" style="margin-bottom:0;">
                        <label for="selectDate"><span class="lbl-full">Ημερομηνία</span><span class="lbl-short">Ημ/νία</span></label>
                        <input type="date" id="selectDate" class="form-control" value="<?= $today ?>">
                    </div>
                </div>

                <!-- Legend & Actions Bar -->
                <div class="filter-actions-bar">
                    <div class="legend-desktop">
                        <span style="font-size:12px; color:var(--text-muted); font-weight:600;">Κατάσταση (πάτημα κάρτας):</span>
                        <span class="legend-tag" style="background:#d1fae5; color:#065f46;">🟢 Παρών / Παρούσα</span>
                        <span class="legend-tag" style="background:#fee2e2; color:#991b1b;">🔴 Απών / Απούσα</span>
                        <span class="legend-tag" style="background:#fef3c7; color:#92400e;">🟠 Ωριαία Αποβολή</span>
                        <span class="legend-tag" style="background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe;" title="Μόνο οι Διαχειριστές μπορούν να δικαιολογούν απουσίες">🔵 Δικαιολογημένη (Μόνο Admin)</span>
                    </div>

                    <div class="legend-mobile">
                        <span class="leg-item" style="background:#d1fae5; color:#065f46;">🟢 Παρών</span>
                        <span class="leg-item" style="background:#fee2e2; color:#991b1b;">🔴 Απών</span>
                        <span class="leg-item" style="background:#fef3c7; color:#92400e;">🟠 Αποβολή</span>
                        <span class="leg-item" style="background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe;">🔵 Δικ/νη</span>
                    </div>

                    <div class="quick-tools-area">
                        <div class="view-mode-toggle" title="Εναλλαγή προβολής">
                            <button type="button" class="btn-view-toggle active" id="btnViewCards" title="Προβολή επιλεγμένης ώρας (Κάρτες)">
                                📇 Ώρα
                            </button>
                            <button type="button" class="btn-view-toggle" id="btnViewBar" title="Ολοήμερη προβολή (Μπάρα ωρών 1-7)">
                                📊 Μπάρα 1-7
                            </button>
                        </div>
                        <input type="text" id="studentSearchInput" class="form-control" placeholder="🔍 Αναζήτηση μαθητή...">
                        <button type="button" class="btn btn-secondary btn-sm" id="btnAllPresent" title="Ορισμός όλων των μαθητών ως Παρόντες/Παρούσες">
                            ✓ Όλοι Παρόντες
                        </button>
                    </div>
                </div>

                <div id="ownershipBanner" style="display:none; margin-top:14px; padding:12px 16px; border-radius:8px; font-size:13px; line-height:1.5;"></div>

                <div style="font-size:13px; color:var(--text-muted); margin-top:10px;" id="statusIndicator">
                    Φόρτωση μαθητών...
                </div>
            </div>

            <!-- Student Grid -->
            <div id="studentGrid" class="student-grid"></div>

            <!-- Floating Save Bar -->
            <div class="floating-bar">
                <div style="display:flex; align-items:center; flex-wrap:wrap; gap:8px;">
                    <span id="summaryTotal" style="font-weight:600;">Μαθητές: 0</span>
                    <span style="color:var(--border);">|</span>
                    <span id="summaryAbsent" style="font-weight:700; color:var(--danger);">Απόντες: 0</span>
                    <span id="summaryExpulsion" style="display:none; font-weight:700; color:#d97706;">
                        | Αποβολές: 0
                    </span>
                    <span id="summaryJustified" style="display:none; font-weight:700; color:#2563eb;">
                        | Δικαιολογημένες: 0
                    </span>
                    <span id="unsavedWarningBadge" style="display:none; background:#fee2e2; color:#991b1b; padding:3px 8px; border-radius:6px; font-size:12px; font-weight:700; border:1px solid #fca5a5;">
                        ⚠️ Μη αποθηκευμένες αλλαγές
                    </span>
                </div>
                <div class="attendance-save-actions">
                    <button type="button" id="btnDeleteAttendance" class="btn btn-danger" style="display:none; background:#dc2626; border-color:#b91c1c;">
                        🗑️ Διαγραφή Ώρας
                    </button>
                    <button type="button" id="btnSaveAttendance" class="btn btn-primary">
                        💾 Αποθήκευση Παρουσιολογίου
                    </button>
                </div>
                <div id="saveFeedback" class="attendance-save-feedback" role="status" aria-live="polite" aria-atomic="true">Οι αλλαγές καταχωρούνται με το κουμπί Αποθήκευση.</div>
            </div>
        <?php endif; ?>

        <footer style="margin-top:35px; margin-bottom:85px; padding:18px 0; border-top:1px solid var(--border); font-size:12.5px; color:var(--text-muted); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <div>
                🏫 <strong><?= htmlspecialchars($schoolName) ?></strong> &bull; Παρουσιολόγιο Τμήματος
            </div>
            <div style="display:flex; align-items:center; gap:8px;">
                <span class="badge badge-info" style="font-size:11px;">v<?= htmlspecialchars(APP_VERSION, ENT_QUOTES, 'UTF-8') ?></span>
                <span>Δημιουργός: <strong style="color:var(--text-main);">Κόλλιας Σταύρος</strong> &copy; <?= date('Y') ?></span>
                <a href="../license.php" style="color:var(--primary);">Άδεια GPLv3</a>
            </div>
        </footer>
    </div>

    <script>
        const canUserJustify = <?= canJustifyAbsences() ? 'true' : 'false' ?>;
        const canEditAllHours = <?= isAdmin() ? 'true' : 'false' ?>;
        let dayDraft = {}, dayBaseline = {};
        let lastSingleHour = '';
        let savingDay = false;
        let savingHour = false;
        let deletingHour = false;
        let lastSaveReceipt = null;
        let dataReady = false;
        let loadSequence = 0;
        function isDayMode() { return canEditAllHours && selectHour.value === 'all'; }
        function dayChanges() {
            const changes = [];
            for (const [id, hours] of Object.entries(dayDraft)) {
                for (const [hour, status] of Object.entries(hours)) {
                    if (status !== dayBaseline[id][hour].status) changes.push({student_id:Number(id),hour:Number(hour),status,version:dayBaseline[id][hour].version});
                }
            }
            return changes;
        }
        let studentsList = [];
        let savedAbsenceState = {}; // maps studentId => status ('present'|'absent'|'expulsion'|'justified')
        let hasUnsavedChanges = false;
        let lastLoadedSection = '';
        let lastLoadedHour = '';
        let lastLoadedDate = '';
        let currentHourData = null;
        const originalPageTitle = document.title;

        const selectSection = document.getElementById('selectSection');
        const selectHour = document.getElementById('selectHour');
        const selectDate = document.getElementById('selectDate');
        const studentGrid = document.getElementById('studentGrid');
        const statusIndicator = document.getElementById('statusIndicator');
        const ownershipBanner = document.getElementById('ownershipBanner');
        const summaryTotal = document.getElementById('summaryTotal');
        const summaryAbsent = document.getElementById('summaryAbsent');
        const summaryExpulsion = document.getElementById('summaryExpulsion');
        const summaryJustified = document.getElementById('summaryJustified');
        const btnSaveAttendance = document.getElementById('btnSaveAttendance');
        const btnDeleteAttendance = document.getElementById('btnDeleteAttendance');
        const btnAllPresent = document.getElementById('btnAllPresent');
        const unsavedWarningBadge = document.getElementById('unsavedWarningBadge');
        const saveFeedback = document.getElementById('saveFeedback');

        function setSaveFeedback(message, state = '') {
            if (!saveFeedback) return;
            saveFeedback.textContent = message;
            saveFeedback.dataset.state = state;
        }
        function recordSuccessfulSave(section, hour, date) {
            const sectionName = selectSection.options[selectSection.selectedIndex]?.textContent.trim() || section;
            const displayDate = date.split('-').reverse().join('/');
            const time = new Date().toLocaleTimeString('el-GR', {hour:'2-digit', minute:'2-digit'});
            lastSaveReceipt = {section, hour, date, message:`✓ Αποθηκεύτηκε στις ${time} · ${sectionName} · ${displayDate} · ${hour === 'all' ? 'Ώρες 1–7' : hour + 'η ώρα'}`};
            setSaveFeedback(lastSaveReceipt.message, 'success');
        }

        const bellSchedule = <?= json_encode($bellSchedule, JSON_UNESCAPED_UNICODE) ?>;
        const initialSuggestedHour = <?= (int)$suggestedHour ?>;

        // Determine current school hour based on current local time and configured bell schedule
        function autoSelectHour() {
            if (!selectHour) return;

            let selectedH = initialSuggestedHour || 1;

            if (bellSchedule && typeof bellSchedule === 'object' && Object.keys(bellSchedule).length > 0) {
                const now = new Date();
                const nowMinutes = now.getHours() * 60 + now.getMinutes();
                const hours = Object.keys(bellSchedule).map(Number).sort((a, b) => a - b);

                const toMins = (tStr) => {
                    if (!tStr) return 0;
                    const p = tStr.split(':');
                    return (parseInt(p[0], 10) || 0) * 60 + (parseInt(p[1], 10) || 0);
                };

                // 1. Direct match within an hour
                let matched = null;
                for (const h of hours) {
                    const startMin = toMins(bellSchedule[h].start);
                    const endMin = toMins(bellSchedule[h].end);
                    if (nowMinutes >= startMin && nowMinutes <= endMin) {
                        matched = h;
                        break;
                    }
                }

                // 2. Break or outside hours matching
                if (matched === null) {
                    const firstH = hours[0];
                    const lastH = hours[hours.length - 1];
                    const firstStart = toMins(bellSchedule[firstH].start);
                    const lastEnd = toMins(bellSchedule[lastH].end);

                    if (nowMinutes < firstStart) {
                        matched = firstH; // Morning before school starts -> 1st hour
                    } else if (nowMinutes > lastEnd) {
                        matched = 1; // After school day -> fallback to 1st hour
                    } else {
                        // Between hours (break)
                        for (let i = 0; i < hours.length - 1; i++) {
                            const hCurr = hours[i];
                            const hNext = hours[i + 1];
                            const currEnd = toMins(bellSchedule[hCurr].end);
                            const nextStart = toMins(bellSchedule[hNext].start);

                            if (nowMinutes > currEnd && nowMinutes < nextStart) {
                                // Break midpoint: first half belongs to finished hour, second half to upcoming hour
                                const midPoint = currEnd + ((nextStart - currEnd) / 2);
                                matched = (nowMinutes <= midPoint) ? hCurr : hNext;
                                break;
                            }
                        }
                    }
                }

                if (matched !== null) {
                    selectedH = matched;
                }
            }

            selectHour.value = String(selectedH);
        }

        // Return label based on gender and status
        function getStatusLabel(gender, status) {
            const isFemale = (gender === 'female');
            if (status === 'expulsion') {
                return 'ΩΡΙΑΙΑ ΑΠΟΒΟΛΗ';
            }
            if (status === 'justified') {
                return isFemale ? 'ΔΙΚΑΙΟΛΟΓΗΜΕΝΗ' : 'ΔΙΚΑΙΟΛΟΓΗΜΕΝΟΣ';
            }
            if (status === 'absent') {
                return isFemale ? 'ΑΠΟΥΣΑ' : 'ΑΠΩΝ';
            }
            return isFemale ? 'ΠΑΡΟΥΣΑ' : 'ΠΑΡΩΝ';
        }

        // Check whether current studentsList matches initial savedAbsenceState
        function checkIfDirty() {
            if (isDayMode()) return dayChanges().length > 0;
            if (!studentsList || studentsList.length === 0) return false;
            for (let i = 0; i < studentsList.length; i++) {
                const s = studentsList[i];
                const savedStatus = savedAbsenceState[s.id] || 'present';
                const currentStatus = s.status || 'present';
                if (savedStatus !== currentStatus) {
                    return true;
                }
            }
            return false;
        }

        function updateUnsavedChangesUI() {
            hasUnsavedChanges = checkIfDirty();
            if (savingHour || savingDay || deletingHour) {
                if (btnSaveAttendance) btnSaveAttendance.textContent = deletingHour ? '⏳ Διαγραφή...' : '⏳ Αποθήκευση...';
                return;
            }

            if (hasUnsavedChanges) {
                if (saveFeedback?.dataset.state !== 'error') setSaveFeedback('⚠ Μη αποθηκευμένες αλλαγές. Πατήστε Αποθήκευση για καταχώρηση.', 'pending');
                document.title = '⚠️ [Μη αποθηκευμένο] ' + originalPageTitle;
                if (btnSaveAttendance) {
                    btnSaveAttendance.classList.add('btn-save-unsaved');
                    btnSaveAttendance.innerHTML = '💾 Αποθήκευση Αλλαγών *';
                }
                if (unsavedWarningBadge) {
                    unsavedWarningBadge.style.display = 'inline-block';
                }
                if (statusIndicator) {
                    statusIndicator.innerHTML = '<span style="color:#dc2626; font-weight:700;">⚠️ Έχετε κάνει αλλαγές που δεν έχουν αποθηκευτεί! Πατήστε «Αποθήκευση».</span>';
                }
            } else {
                if (lastSaveReceipt && saveFeedback?.dataset.state !== 'error') setSaveFeedback(lastSaveReceipt.message, 'success');
                else if (saveFeedback?.dataset.state === 'pending') setSaveFeedback('Δεν υπάρχουν μη αποθηκευμένες αλλαγές.');
                document.title = originalPageTitle;
                if (btnSaveAttendance) {
                    btnSaveAttendance.classList.remove('btn-save-unsaved');
                    btnSaveAttendance.innerHTML = '💾 Αποθήκευση Παρουσιολογίου';
                }
                if (unsavedWarningBadge) {
                    unsavedWarningBadge.style.display = 'none';
                }
            }
        }

        // Prompt helper when navigating away with unsaved changes
        function confirmLeaveIfUnsaved(reason) {
            if (savingHour || savingDay || deletingHour) {
                showToast('Περιμένετε να ολοκληρωθεί η καταχώρηση πριν αλλάξετε προβολή.', 'warning');
                return false;
            }
            if (!hasUnsavedChanges) return true;
            return confirm(
                "⚠️ ΠΡΟΣΟΧΗ: Υπάρχουν μη αποθηκευμένες αλλαγές!\n\n" +
                "Έχετε τροποποιήσει τις απουσίες αλλά ΔΕΝ έχετε πατήσει «Αποθήκευση Παρουσιολογίου».\n" +
                "Αν αλλάξετε " + reason + ", οι αλλαγές σας θα ΧΑΘΟΥΝ!\n\n" +
                "Πατήστε «Ακύρωση» για να μείνετε και να αποθηκεύσετε, ή «OK» για να απορρίψετε τις αλλαγές."
            );
        }

        async function loadStudents() {
            if (!selectSection) return;
            dataReady = false;
            if (btnSaveAttendance) btnSaveAttendance.disabled = true;
            const requestId = ++loadSequence;
            const sectionId = selectSection.value;
            const hour = selectHour.value;
            const date = selectDate.value;
            if (!lastSaveReceipt && !savingHour && !savingDay) setSaveFeedback('Οι αλλαγές καταχωρούνται με το κουμπί Αποθήκευση.');
            if (lastSaveReceipt && (lastSaveReceipt.section !== sectionId || lastSaveReceipt.hour !== hour || lastSaveReceipt.date !== date)) {
                lastSaveReceipt = null;
                setSaveFeedback('Οι αλλαγές καταχωρούνται με το κουμπί Αποθήκευση.');
            }

            statusIndicator.innerHTML = '🔄 Φόρτωση...';
            studentGrid.innerHTML = '<div style="grid-column: 1/-1; text-align:center; padding:30px; color:var(--text-muted);">Φόρτωση μαθητών τμήματος...</div>';
            if (ownershipBanner) ownershipBanner.style.display = 'none';

            try {
                const res = await fetch(`../api/get_section_students.php?section_id=${sectionId}&hour=${hour}&date=${date}`);
                const data = await res.json();
                if (requestId !== loadSequence) return;

                if (data.error) {
                    studentGrid.textContent = data.error;
                    return;
                }

                currentHourData = data;
                dataReady = true;
                if (btnSaveAttendance && !savingDay && !savingHour && !deletingHour) btnSaveAttendance.disabled = false;
                studentsList = data.students;
                if (isDayMode()) {
                    dayDraft = {}; dayBaseline = {};
                    studentsList.forEach(s => {
                        dayDraft[s.id] = {}; dayBaseline[s.id] = {};
                        for (let h=1; h<=7; h++) {
                            const row = data.day_absences?.[s.id]?.[h];
                            const status = row?.is_absent == 1 ? row.absence_type : 'present';
                            dayDraft[s.id][h] = status;
                            dayBaseline[s.id][h] = {status,version:row?.version || data.empty_slot_version};
                        }
                    });
                }

                // Record baseline saved snapshot
                savedAbsenceState = {};
                studentsList.forEach(s => {
                    s.status = s.status || (s.is_absent ? (s.absence_type === 'expulsion' ? 'expulsion' : (s.absence_type === 'justified' ? 'justified' : 'absent')) : 'present');
                    savedAbsenceState[s.id] = s.status;
                });

                hasUnsavedChanges = false;
                lastLoadedSection = sectionId;
                lastLoadedHour = hour;
                lastLoadedDate = date;
                updateUnsavedChangesUI();

                // Ownership & Locking Banner Handling
                if (data.is_locked) {
                    if (ownershipBanner) {
                        ownershipBanner.style.display = 'block';
                        ownershipBanner.style.background = '#fef2f2';
                        ownershipBanner.style.border = '1px solid #f87171';
                        ownershipBanner.style.color = '#991b1b';
                        ownershipBanner.textContent = `🔒 Κλειδωμένη Ώρα: ${data.lock_reason}`;
                    }
                    if (btnSaveAttendance) btnSaveAttendance.style.display = 'none';
                    if (btnDeleteAttendance) btnDeleteAttendance.style.display = 'none';
                    if (btnAllPresent) btnAllPresent.disabled = true;
                    statusIndicator.innerHTML = '';
                } else if (data.has_saved_data) {
                    if (ownershipBanner) {
                        ownershipBanner.style.display = 'block';
                        if (data.is_own_record) {
                            ownershipBanner.style.background = '#ecfdf5';
                            ownershipBanner.style.border = '1px solid #a7f3d0';
                            ownershipBanner.style.color = '#065f46';
                            ownershipBanner.innerHTML = '✏️ <strong>Καταχωρήθηκε από εσάς:</strong> Δυνατότητα τροποποίησης ή διαγραφής.';
                        } else {
                            ownershipBanner.style.background = '#f5f3ff';
                            ownershipBanner.style.border = '1px solid #c4b5fd';
                            ownershipBanner.style.color = '#5b21b6';
                            ownershipBanner.textContent = `👑 Διαχειριστική Πρόσβαση: Καταχωρήθηκε από: ${data.recorded_by_name || 'Εκπαιδευτικό'}.`;
                        }
                    }
                    if (btnSaveAttendance) btnSaveAttendance.style.display = 'inline-block';
                    if (btnDeleteAttendance) {
                        btnDeleteAttendance.style.display = data.can_delete ? 'inline-flex' : 'none';
                    }
                    if (btnAllPresent) btnAllPresent.disabled = false;
                    statusIndicator.innerHTML = '';
                } else {
                    if (ownershipBanner) ownershipBanner.style.display = 'none';
                    if (btnSaveAttendance) btnSaveAttendance.style.display = 'inline-block';
                    if (btnDeleteAttendance) btnDeleteAttendance.style.display = 'none';
                    if (btnAllPresent) btnAllPresent.disabled = false;
                    statusIndicator.innerHTML = '<span style="color:var(--success); font-weight:500;">🟢 Νέα καταχώρηση.</span>';
                }

                if (isDayMode()) {
                    if (ownershipBanner) ownershipBanner.style.display = 'none';
                    if (btnDeleteAttendance) btnDeleteAttendance.style.display = 'none';
                    if (btnAllPresent) btnAllPresent.disabled = true;
                    statusIndicator.textContent = 'Επεξεργασία ανά ώρα. Οι αλλαγές καταχωρούνται με Αποθήκευση.';
                }
                renderStudents();
            } catch (err) {
                statusIndicator.innerHTML = '<span style="color:var(--danger);">Σφάλμα επικοινωνίας με το σύστημα.</span>';
            }
        }

        function renderStudents() {
            if (studentsList.length === 0) {
                studentGrid.innerHTML = '<div style="grid-column: 1/-1; text-align:center; padding:30px; color:var(--text-muted);">Δεν υπάρχουν μαθητές σε αυτό το τμήμα.</div>';
                updateCounters();
                return;
            }

            const filterTerm = (document.getElementById('studentSearchInput')?.value || '').trim().toLowerCase();
            studentGrid.innerHTML = '';
            studentGrid.classList.toggle('view-bar', currentViewMode === 'bar');
            let visibleCount = 0;
            const isHourLocked = (currentHourData && currentHourData.is_locked);

            studentsList.forEach(s => {
                const fullName = `${s.last_name} ${s.first_name}`.toLowerCase();
                const am = String(s.am).toLowerCase();
                if (filterTerm && !fullName.includes(filterTerm) && !am.includes(filterTerm)) {
                    return;
                }
                visibleCount++;

                const status = s.status || 'present';
                const label = getStatusLabel(s.gender, status);

                const card = document.createElement('div');
                card.id = `student-${s.id}`;

                let cardTouchStartX = 0;
                let cardTouchStartY = 0;
                let isCardScrollGesture = false;

                card.addEventListener('touchstart', (e) => {
                    if (e.touches && e.touches[0]) {
                        cardTouchStartX = e.touches[0].clientX;
                        cardTouchStartY = e.touches[0].clientY;
                    }
                    isCardScrollGesture = false;
                }, { passive: true });

                card.addEventListener('touchmove', (e) => {
                    if (e.touches && e.touches[0]) {
                        const dx = Math.abs(e.touches[0].clientX - cardTouchStartX);
                        const dy = Math.abs(e.touches[0].clientY - cardTouchStartY);
                        if (dx > 8 || dy > 8) {
                            isCardScrollGesture = true;
                        }
                    }
                }, { passive: true });

                if (isHourLocked) {
                    card.className = `student-card ${status} locked`;
                    if (currentViewMode !== 'bar') {
                        card.title = `🔒 Η ώρα είναι κλειδωμένη (καταχωρήθηκε από ${currentHourData.recorded_by_name || 'άλλον καθηγητή'})`;
                    }
                    card.addEventListener('click', () => {
                        if (isCardScrollGesture) return;
                        showToast(`🔒 Η ώρα είναι κλειδωμένη (καταχωρήθηκε από ${currentHourData.recorded_by_name || 'άλλον εκπαιδευτικό'}).`, 'warning');
                    });
                } else if (s.is_justified_locked && !canUserJustify) {
                    card.className = `student-card ${status} justified-locked`;
                    if (currentViewMode !== 'bar') {
                        card.title = '🔵 Η απουσία είναι δικαιολογημένη από τη διεύθυνση (κλειδωμένη)';
                    }
                    card.addEventListener('click', () => {
                        if (isCardScrollGesture) return;
                        showToast('🔵 Η απουσία είναι δικαιολογημένη από τη διεύθυνση και δεν μπορεί να τροποποιηθεί.', 'warning');
                    });
                } else {
                    card.className = `student-card ${status}`;
                    if (currentViewMode !== 'bar') {
                        card.title = canUserJustify 
                            ? 'Πατήστε για εναλλαγή (Παρών ➔ Απών ➔ Αποβολή ➔ Δικαιολογημένη)' 
                            : 'Πατήστε για εναλλαγή (Παρών ➔ Απών ➔ Αποβολή)';
                    }
                    card.addEventListener('click', () => {
                        if (isCardScrollGesture || isDayMode()) return;
                        cycleStudentStatus(s.id);
                    });
                }

                if (isDayMode()) {
                    const dayStatuses = [...new Set(Object.values(dayDraft[s.id]))];
                    card.className = `student-card day-summary ${dayStatuses.length === 1 ? dayStatuses[0] : 'day-mixed'}`;
                    const colors = dayStatuses.map(st => `var(--day-${st}-bg)`);
                    card.style.setProperty('--day-card-background', colors.length === 1 ? colors[0] : `linear-gradient(135deg, ${colors.join(', ')})`);
                    card.title = Object.entries(dayDraft[s.id]).map(([h, st]) => `${h}η ώρα: ${getStatusLabel(s.gender, st)}`).join(' · ');
                }
                const info = document.createElement('div');
                info.className = 'student-info';
                const amElement = document.createElement('div');
                amElement.className = 'student-am';
                const genderText = s.gender === 'female' ? 'Θήλυ' : 'Άρρεν';
                const fatherText = s.father_name ? ` • πατρ. ${s.father_name}` : '';
                const motherText = s.mother_name ? ` • μητρ. ${s.mother_name}` : '';
                amElement.textContent = `Α.Μ. ${s.am} • (${genderText})${fatherText}${motherText}`;
                const name = document.createElement('div');
                name.className = 'student-name';
                name.textContent = `${s.last_name} ${s.first_name}`;
                info.append(amElement, name);

                if (currentViewMode === 'bar') {
                    const barWrapper = document.createElement('div');
                    barWrapper.className = 'hours-bar-wrapper';
                    const selectedH = parseInt(selectHour ? selectHour.value : 1, 10);

                    for (let h = 1; h <= 7; h++) {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.textContent = h;

                        if (isDayMode()) {
                            const hourStatus = dayDraft[s.id][h];
                            const dirty = hourStatus !== dayBaseline[s.id][h].status;
                            btn.className = `hour-pill-btn ${hourStatus}`;
                            btn.textContent = String(h) + (dirty ? ' *' : '');
                            btn.title = `${h}η ώρα: ${getStatusLabel(s.gender,hourStatus)}${dirty ? ' — Μη αποθηκευμένη αλλαγή' : ''}`;
                            btn.setAttribute('aria-label', btn.title);
                            btn.disabled = savingDay;
                            btn.addEventListener('click', e => {
                                e.stopPropagation();
                                if (isCardScrollGesture || savingDay) return;
                                const states = ['present','absent','expulsion','justified'];
                                dayDraft[s.id][h] = states[(states.indexOf(dayDraft[s.id][h])+1)%states.length];
                                renderStudents(); updateUnsavedChangesUI();
                            });
                        } else if (h === selectedH) {
                            btn.className = `hour-pill-btn current-hour ${status}`;
                            btn.title = `Τρέχουσα ${h}η ώρα: ${label} (Πάτημα για εναλλαγή)`;
                            btn.addEventListener('click', (e) => {
                                e.stopPropagation();
                                if (isCardScrollGesture) return;
                                if (isHourLocked) {
                                    showToast(`🔒 Η ώρα είναι κλειδωμένη (καταχωρήθηκε από ${currentHourData.recorded_by_name || 'άλλον εκπαιδευτικό'}).`, 'warning');
                                } else if (s.is_justified_locked && !canUserJustify) {
                                    showToast('🔵 Η απουσία είναι δικαιολογημένη από τη διεύθυνση και δεν μπορεί να τροποποιηθεί.', 'warning');
                                } else {
                                    cycleStudentStatus(s.id);
                                }
                            });
                        } else {
                            const dayAtt = (currentHourData && currentHourData.day_absences && currentHourData.day_absences[s.id] && currentHourData.day_absences[s.id][h]);
                            if (dayAtt && dayAtt.is_absent == 1) {
                                const attType = dayAtt.absence_type || 'absent';
                                const attLabel = getStatusLabel(s.gender, attType);
                                btn.className = `hour-pill-btn ${attType}`;
                                btn.title = `${h}η ώρα: ${attLabel} (από: ${dayAtt.teacher_name})`;
                                btn.addEventListener('click', (e) => {
                                    e.stopPropagation();
                                    showToast(`ℹ️ ${h}η ώρα: ${attLabel} (Καταχωρήθηκε από: ${dayAtt.teacher_name})`, 'info');
                                });
                            } else {
                                btn.className = 'hour-pill-btn present';
                                btn.title = `${h}η ώρα: Παρών / Παρούσα`;
                                btn.addEventListener('click', (e) => {
                                    e.stopPropagation();
                                    showToast(`💡 Για καταχώρηση στη ${h}η ώρα, επιλέξτε την από το μενού «Ώρα» επάνω.`, 'info');
                                });
                            }
                        }
                        barWrapper.appendChild(btn);
                    }
                    card.append(info, barWrapper);
                } else {
                    const pill = document.createElement('div');
                    pill.className = 'status-pill';
                    pill.textContent = label;
                    card.append(info, pill);
                }

                studentGrid.appendChild(card);
            });

            if (visibleCount === 0 && filterTerm) {
                studentGrid.innerHTML = '<div style="grid-column: 1/-1; text-align:center; padding:30px; color:var(--text-muted);">🔍 Δεν βρέθηκε μαθητής που να ταιριάζει με την αναζήτηση.</div>';
            }

            updateCounters();
        }

        // View Mode Switcher Handlers
        let currentViewMode = localStorage.getItem('absences_teacher_view_mode') || 'cards';
        const btnViewCards = document.getElementById('btnViewCards');
        const btnViewBar = document.getElementById('btnViewBar');

        function setViewMode(mode) {
            if (savingHour || savingDay || deletingHour) return;
            if (mode === currentViewMode) return;
            if (canEditAllHours) {
                if (hasUnsavedChanges && !confirmLeaveIfUnsaved('προβολή')) return;
                const allOption = selectHour.querySelector('option[value="all"]');
                if (mode === 'bar') {
                    lastSingleHour = selectHour.value === 'all' ? lastSingleHour : selectHour.value;
                    allOption.hidden = false;
                    selectHour.value = 'all';
                } else {
                    if (selectHour.value !== 'all') lastSingleHour = selectHour.value;
                    selectHour.value = lastSingleHour || String(initialSuggestedHour || 1);
                    allOption.hidden = true;
                }
            }
            currentViewMode = mode;
            localStorage.setItem('absences_teacher_view_mode', mode);
            if (btnViewCards) btnViewCards.classList.toggle('active', mode === 'cards');
            if (btnViewBar) btnViewBar.classList.toggle('active', mode === 'bar');
            if (canEditAllHours) loadStudents(); else renderStudents();
        }

        btnViewCards?.addEventListener('click', () => setViewMode('cards'));
        btnViewBar?.addEventListener('click', () => setViewMode('bar'));
        if (btnViewCards) btnViewCards.classList.toggle('active', currentViewMode === 'cards');
        if (btnViewBar) btnViewBar.classList.toggle('active', currentViewMode === 'bar');

        document.getElementById('studentSearchInput')?.addEventListener('input', renderStudents);

        // Cycle through: present -> absent -> expulsion -> (justified for admin) -> present
        function cycleStudentStatus(studentId) {
            if (!dataReady || savingHour || savingDay || deletingHour) return;
            if (currentHourData && currentHourData.is_locked) {
                showToast('🔒 Η ώρα είναι κλειδωμένη.', 'warning');
                return;
            }
            const student = studentsList.find(s => s.id == studentId);
            if (!student) return;

            if (student.is_justified_locked && !canUserJustify) {
                showToast('🔵 Η απουσία είναι δικαιολογημένη από τη διεύθυνση και δεν μπορεί να τροποποιηθεί.', 'warning');
                return;
            }

            const prev = student.status || 'present';
            let next = 'present';
            if (prev === 'present') {
                next = 'absent';
            } else if (prev === 'absent') {
                next = 'expulsion';
            } else if (prev === 'expulsion') {
                next = canUserJustify ? 'justified' : 'present';
            } else if (prev === 'justified') {
                next = 'present';
            } else {
                next = 'present';
            }

            student.status = next;
            student.is_absent = (next !== 'present');
            student.absence_type = (next === 'expulsion') ? 'expulsion' : (next === 'justified' ? 'justified' : 'absent');

            const card = document.getElementById(`student-${studentId}`);
            if (card) {
                card.className = `student-card ${next}`;
                const pill = card.querySelector('.status-pill');
                if (pill) {
                    pill.textContent = getStatusLabel(student.gender, next);
                }
                const curBtn = card.querySelector('.hour-pill-btn.current-hour');
                if (curBtn) {
                    curBtn.className = `hour-pill-btn current-hour ${next}`;
                    curBtn.title = `Τρέχουσα ${selectHour ? selectHour.value : ''}η ώρα: ${getStatusLabel(student.gender, next)} (Πάτημα για εναλλαγή)`;
                }
            }

            updateCounters();
            updateUnsavedChangesUI();
        }

        function updateCounters() {
            if (isDayMode()) {
                const statuses = Object.values(dayDraft).flatMap(hours => Object.values(hours));
                summaryTotal.textContent = `Μαθητές: ${studentsList.length}`;
                summaryAbsent.textContent = `Ώρες απουσίας: ${statuses.filter(s=>s==='absent').length}`;
                summaryExpulsion.style.display = 'inline';
                summaryExpulsion.textContent = ` | Ώρες αποβολής: ${statuses.filter(s=>s==='expulsion').length}`;
                summaryJustified.style.display = 'inline';
                summaryJustified.textContent = ` | Δικαιολογημένες ώρες: ${statuses.filter(s=>s==='justified').length} | Αλλαγές: ${dayChanges().length}`;
                return;
            }
            const total = studentsList.length;
            const absent = studentsList.filter(s => s.status === 'absent').length;
            const expulsion = studentsList.filter(s => s.status === 'expulsion').length;
            const justified = studentsList.filter(s => s.status === 'justified').length;

            summaryTotal.textContent = `Μαθητές: ${total}`;
            summaryAbsent.textContent = `Απόντες: ${absent}`;

            if (expulsion > 0) {
                summaryExpulsion.style.display = 'inline';
                summaryExpulsion.textContent = `| Αποβολές: ${expulsion}`;
            } else {
                summaryExpulsion.style.display = 'none';
            }

            if (justified > 0) {
                summaryJustified.style.display = 'inline';
                summaryJustified.textContent = `| Δικαιολογημένες: ${justified}`;
            } else {
                summaryJustified.style.display = 'none';
            }
        }

        if (btnAllPresent) {
            btnAllPresent.onclick = () => {
                if (!dataReady || savingHour || savingDay || deletingHour) return;
                if (isDayMode()) return;
                if (currentHourData && currentHourData.is_locked) return;
                studentsList.forEach(s => {
                    // Do not reset justified students
                    if (!s.is_justified_locked) {
                        s.status = 'present';
                        s.is_absent = false;
                        s.absence_type = 'absent';
                    }
                });
                renderStudents();
                updateUnsavedChangesUI();
            };
        }

        if (btnDeleteAttendance) {
            btnDeleteAttendance.onclick = async () => {
                if (!dataReady || savingHour || savingDay || deletingHour) return;
                if (isDayMode()) return;
                const hour = selectHour.value;
                if (!confirm(`⚠️ Είστε βέβαιοι ότι θέλετε να διαγράψετε όλες τις καταχωρημένες απουσίες για την ${hour}η ώρα;\n\nΗ ενέργεια θα μηδενίσει το παρουσιολόγιο της ώρας για το επιλεγμένο τμήμα.`)) {
                    return;
                }

                btnDeleteAttendance.disabled = true;
                btnDeleteAttendance.textContent = '⏳ Διαγραφή...';
                deletingHour = true;
                const controls = [selectSection, selectHour, selectDate, btnViewCards, btnViewBar, btnAllPresent, btnSaveAttendance].filter(Boolean);
                const disabledBefore = controls.map(c => c.disabled);
                controls.forEach(c => { c.disabled = true; });
                setSaveFeedback('Διαγραφή σε εξέλιξη. Περιμένετε την επιβεβαίωση.');

                try {
                    const res = await fetch('../api/delete_attendance_hour.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': '<?= getCsrfToken() ?>'
                        },
                        body: JSON.stringify({
                            section_id: selectSection.value,
                            hour: selectHour.value,
                            date: selectDate.value,
                            csrf_token: '<?= getCsrfToken() ?>'
                        })
                    });
                    const data = await res.json();
                    if (res.ok && data.success) {
                        lastSaveReceipt = null;
                        setSaveFeedback('Η διαγραφή επιβεβαιώθηκε.', 'success');
                        showToast(data.message || 'Οι απουσίες διαγράφηκαν επιτυχώς.', 'success');
                        await loadStudents();
                    } else {
                        showToast(data.error || 'Σφάλμα κατά τη διαγραφή.', 'danger');
                        setSaveFeedback('Η διαγραφή δεν επιβεβαιώθηκε. Οι επιλογές παραμένουν στην οθόνη.', 'error');
                    }
                } catch (e) {
                    showToast('Σφάλμα επικοινωνίας με το σύστημα.', 'danger');
                    setSaveFeedback('Δεν λάβαμε επιβεβαίωση διαγραφής. Οι επιλογές παραμένουν στην οθόνη.', 'error');
                } finally {
                    deletingHour = false;
                    controls.forEach((c, i) => { c.disabled = disabledBefore[i]; });
                    if (btnAllPresent && currentHourData?.is_locked) btnAllPresent.disabled = true;
                    if (btnSaveAttendance) btnSaveAttendance.disabled = !dataReady;
                    btnDeleteAttendance.disabled = false;
                    btnDeleteAttendance.innerHTML = '🗑️ Διαγραφή Ώρας';
                    updateUnsavedChangesUI();
                }
            };
        }

        if (btnSaveAttendance) {
            btnSaveAttendance.onclick = async () => {
                if (!dataReady || savingHour || savingDay || deletingHour) return;
                if (isDayMode()) { await saveDayChanges(); return; }
                const sectionId = selectSection.value;
                const hour = selectHour.value;
                const date = selectDate.value;

                // Create statuses map { studentId: status }
                const statusesMap = {};
                const absentIds = [];
                studentsList.forEach(s => {
                    const st = s.status || 'present';
                    statusesMap[s.id] = st;
                    if (st !== 'present') {
                        absentIds.push(s.id);
                    }
                });

                btnSaveAttendance.disabled = true;
                btnSaveAttendance.textContent = '⏳ Αποθήκευση...';
                savingHour = true;
                setSaveFeedback('Αποθήκευση σε εξέλιξη. Περιμένετε την επιβεβαίωση.');
                const controls = [selectSection, selectHour, selectDate, btnViewCards, btnViewBar, btnAllPresent, btnDeleteAttendance].filter(Boolean);
                const disabledBefore = controls.map(c => c.disabled);
                controls.forEach(c => { c.disabled = true; });

                try {
                    const res = await fetch('../api/save_attendance.php', {
                        method: 'POST',
                        headers: { 
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': '<?= getCsrfToken() ?>'
                        },
                        body: JSON.stringify({
                            section_id: sectionId,
                            hour: hour,
                            date: date,
                            statuses: statusesMap,
                            absent_student_ids: absentIds
                        })
                    });
                    const data = await res.json();
                    if (res.ok && data.success) {
                        // Update baseline snapshot to current state
                        savedAbsenceState = {...statusesMap};
                        hasUnsavedChanges = false;
                        updateUnsavedChangesUI();

                        const totalMissing = (data.absent_count || 0) + (data.expulsion_count || 0) + (data.justified_count || 0);
                        let msg = `✓ Αποθηκεύτηκαν επιτυχώς: ${data.absent_count || 0} απόντες`;
                        if (data.expulsion_count > 0) {
                            msg += `, ${data.expulsion_count} αποβολή/ές`;
                        }
                        if (data.justified_count > 0) {
                            msg += `, ${data.justified_count} δικαιολογημένη/ες`;
                        }
                        showToast(msg, 'success');
                        recordSuccessfulSave(sectionId, hour, date);
                        statusIndicator.innerHTML = `<span style="color:var(--success); font-weight:600;">✓ Οι αλλαγές αποθηκεύτηκαν επιτυχώς (${totalMissing} μη παρόντες)!</span>`;
                        // Reload to ensure ownership state is refreshed
                        await loadStudents();
                    } else {
                        showToast(data.error || 'Σφάλμα κατά την αποθήκευση', 'danger');
                        setSaveFeedback('Η αποθήκευση δεν επιβεβαιώθηκε. Οι αλλαγές παραμένουν στην οθόνη. Δοκιμάστε ξανά.', 'error');
                    }
                } catch (e) {
                    showToast('Σφάλμα δικτύου. Παρακαλώ δοκιμάστε ξανά.', 'danger');
                    setSaveFeedback('Δεν λάβαμε επιβεβαίωση αποθήκευσης. Οι αλλαγές παραμένουν στην οθόνη για επανάληψη.', 'error');
                } finally {
                    savingHour = false;
                    controls.forEach((c, i) => { c.disabled = disabledBefore[i]; });
                    if (btnAllPresent && currentHourData?.is_locked) btnAllPresent.disabled = true;
                    btnSaveAttendance.disabled = !dataReady;
                    updateUnsavedChangesUI();
                }
            };
        }

        async function saveDayChanges() {
            const changes = dayChanges();
            if (!dataReady || !changes.length || savingDay) return;
            savingDay = true;
            setSaveFeedback('Αποθήκευση σε εξέλιξη. Περιμένετε την επιβεβαίωση.');
            btnSaveAttendance.textContent = '⏳ Αποθήκευση...';
            const controls = [selectSection,selectHour,selectDate,btnViewCards,btnViewBar,btnSaveAttendance];
            controls.forEach(c => { if(c) c.disabled = true; });
            renderStudents();
            try {
                const res = await fetch('../api/save_attendance_changes.php', {
                    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':<?= json_encode(getCsrfToken()) ?>},
                    body:JSON.stringify({section_id:lastLoadedSection,date:lastLoadedDate,academic_year_id:currentHourData.academic_year_id,changes})
                });
                const data = await res.json();
                if (!res.ok || !data.success) throw new Error(data.error || 'Αποτυχία αποθήκευσης.');
                // Acknowledged writes are no longer pending, even if the following refresh fails.
                changes.forEach(c => { dayBaseline[c.student_id][c.hour].status = c.status; });
                updateUnsavedChangesUI();
                showToast(`Αποθηκεύτηκαν ${data.saved_count} αλλαγές.`, 'success');
                recordSuccessfulSave(lastLoadedSection, 'all', lastLoadedDate);
                await loadStudents();
            } catch (e) {
                showToast(e.message || 'Σφάλμα επικοινωνίας. Οι αλλαγές παραμένουν στην οθόνη.', 'danger');
                setSaveFeedback('Η αποθήκευση δεν επιβεβαιώθηκε. Οι αλλαγές παραμένουν στην οθόνη. Ελέγξτε το μήνυμα και δοκιμάστε ξανά.', 'error');
            } finally {
                savingDay = false;
                controls.forEach(c => { if(c) c.disabled = false; });
                btnSaveAttendance.disabled = !dataReady;
                renderStudents(); updateUnsavedChangesUI();
            }
        }

        function showToast(msg, type = 'success') {
            const t = document.getElementById('toast');
            if (!t) return;
            t.textContent = msg;
            if (type === 'warning') {
                t.style.background = '#d97706';
            } else if (type === 'danger') {
                t.style.background = '#dc2626';
            } else {
                t.style.background = '#10b981';
            }
            t.style.display = 'block';
            setTimeout(() => { t.style.display = 'none'; }, 4000);
        }

        // Section change listener with unsaved guard
        if (selectSection) {
            autoSelectHour();
            lastSingleHour = selectHour.value;
            if (canEditAllHours && currentViewMode === 'bar') {
                selectHour.querySelector('option[value="all"]').hidden = false;
                selectHour.value = 'all';
            }

            lastLoadedSection = selectSection.value;
            lastLoadedHour = selectHour ? selectHour.value : '1';
            lastLoadedDate = selectDate ? selectDate.value : '';

            selectSection.addEventListener('change', function () {
                if (!confirmLeaveIfUnsaved('τμήμα')) {
                    this.value = lastLoadedSection;
                    return;
                }
                lastLoadedSection = this.value;
                loadStudents();
            });

            if (selectHour) {
                selectHour.addEventListener('change', function () {
                    if (!confirmLeaveIfUnsaved('διδακτική ώρα')) {
                        this.value = lastLoadedHour;
                        return;
                    }
                    lastLoadedHour = this.value;
                    if (this.value !== 'all') lastSingleHour = this.value;
                    loadStudents();
                });
            }

            if (selectDate) {
                selectDate.addEventListener('change', function () {
                    if (!confirmLeaveIfUnsaved('ημερομηνία')) {
                        this.value = lastLoadedDate;
                        return;
                    }
                    lastLoadedDate = this.value;
                    loadStudents();
                });
            }

            // Initial load
            loadStudents();
        }

        // Browser beforeunload event (tab close, page refresh, back/forward)
        window.addEventListener('beforeunload', function (e) {
            if (hasUnsavedChanges || savingHour || savingDay || deletingHour) {
                e.preventDefault();
                e.returnValue = 'Υπάρχουν μη αποθηκευμένες αλλαγές στις απουσίες. Θέλετε σίγουρα να αποχωρήσετε;';
                return e.returnValue;
            }
        });

        // Use beforeunload for a single warning on navigation; do not clear the draft.
        // Block navigation while a write is pending, including POST logout forms.
        function preventNavigationDuringSave(e) {
            if (!savingHour && !savingDay && !deletingHour) return;
            e.preventDefault();
            e.stopPropagation();
            showToast('Περιμένετε να ολοκληρωθεί η καταχώρηση πριν φύγετε από τη σελίδα.', 'warning');
        }
        document.addEventListener('submit', preventNavigationDuringSave, true);
        document.addEventListener('click', function (e) {
            const link = e.target.closest('a');
            if (!link || !link.href) return;
            if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button !== 0) return;
            if (link.hasAttribute('download')) return;
            const target = link.getAttribute('target');
            if (target && target !== '_self') return;
            if (link.href.startsWith('javascript:') || link.getAttribute('href') === '#') return;
            preventNavigationDuringSave(e);
        }, true);
    </script>
</body>
</html>
