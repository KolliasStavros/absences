<?php
ob_start();
// admin/header.php - Shared Admin Layout Header (with Sections Tab)
require_once __DIR__ . '/../config/config.php';
requireLogin();
$user = getCurrentUser();
if (!isAdmin() && !isTeacherAdmin()) {
    header("Location: " . BASE_URL . "/teacher/index.php");
    exit;
}

$schoolName = getSchoolSetting('school_name', '1ο Γενικό Λύκειο');
$currentPage = basename($_SERVER['PHP_SELF']);
$activeAcademicYear = getActiveAcademicYear();

// Restrict system management pages from teacher_admin
$adminOnlyPages = ['settings.php', 'import.php', 'teachers.php', 'sections.php', 'students.php', 'logs.php', 'maintenance.php', 'technical_errors.php'];
if (isTeacherAdmin() && in_array($currentPage, $adminOnlyPages)) {
    header("Location: " . BASE_URL . "/admin/index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'Διαχείριση' ?> - <?= htmlspecialchars($schoolName) ?></title>
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
    <script src="../assets/js/admin-ui.js?v=import-1" defer></script>
    <style>
        .badge {
            display: inline-block;
            padding: 3px 8px;
            font-size: 11px;
            font-weight: 700;
            border-radius: 6px;
        }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-info { background: #e0f2fe; color: #075985; }
        .badge-gray { background: #f1f5f9; color: #475569; }
    </style>
</head>
<body class="admin-layout<?= $currentPage === 'index.php' ? ' attendance-page' : ($currentPage === 'student_cards.php' ? ' absence-cards-page' : ($currentPage === 'students.php' ? ' students-page' : ($currentPage === 'sections.php' ? ' sections-page' : ($currentPage === 'teachers.php' ? ' teachers-page' : ($currentPage === 'import.php' ? ' import-page' : ($currentPage === 'settings.php' ? ' settings-page' : '')))))) ?>">
    <nav class="app-navbar">
        <a href="index.php" class="nav-brand">
            <span>🏫</span>
            <div>
                <div style="display:flex; align-items:center; gap:6px;"><span>Γραμματεία Σχολείου</span><span class="badge badge-gray" style="font-size:10px; font-weight:700;">v<?= htmlspecialchars(APP_VERSION, ENT_QUOTES, 'UTF-8') ?></span></div>
                <div style="font-size:11px; font-weight:normal; color:var(--text-muted);"><?= htmlspecialchars($schoolName) ?></div>
            </div>
        </a>
        <div class="nav-user">
            <button type="button" id="adminNavToggle" class="mobile-nav-toggle" aria-label="Μενού πλοήγησης" title="Μενού πλοήγησης" aria-controls="adminNavigation" aria-expanded="false">☰</button>
            <details class="mobile-account">
                <summary aria-label="Λογαριασμός" title="Λογαριασμός">👤</summary>
                <div class="mobile-account-panel">
                    <strong><?= htmlspecialchars($user['name']) ?></strong>
                    <span><?= isTeacherAdmin() ? 'Καθηγητής - Διαχειριστής' : 'Διαχειριστής' ?></span>
                    <span>Absences · v<?= htmlspecialchars(APP_VERSION, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if (isAdmin()): ?>
                        <a href="settings.php#admin-profile">⚙️ Ρυθμίσεις λογαριασμού</a>
                    <?php endif; ?>
                    <?= logoutForm('btn btn-secondary btn-sm') ?>
                </div>
            </details>
            <button type="button" class="theme-toggle-btn" onclick="toggleSchoolTheme()" title="Εναλλαγή Θέματος">
                🌙 <span style="font-size:12px; font-weight:600;">Σκοτεινό</span>
            </button>
            <?php if (isAdmin()): ?>
                <a href="settings.php" class="badge badge-success" style="font-size:12px; padding:6px 11px; text-decoration:none; display:inline-flex; align-items:center; gap:5px; border:1px solid #86efac; transition:all 0.2s;" title="Ενεργό Σχολικό Έτος (Κλικ για διαχείριση/αλλαγή στις Ρυθμίσεις)">
                    📅 Έτος: <strong><?= htmlspecialchars($activeAcademicYear['name'] ?? '2026-2027') ?></strong>
                </a>
            <?php else: ?>
                <span class="badge badge-success" style="font-size:12px; padding:6px 11px; display:inline-flex; align-items:center; gap:5px; border:1px solid #86efac;" title="Ενεργό Σχολικό Έτος">
                    📅 Έτος: <strong><?= htmlspecialchars($activeAcademicYear['name'] ?? '2026-2027') ?></strong>
                </span>
            <?php endif; ?>
            <div class="user-badge">
                <?php if (isAdmin()): ?>
                    <a href="settings.php#admin-profile" style="color:inherit; text-decoration:none; display:inline-flex; align-items:center; gap:5px; font-weight:600;" title="Επεξεργασία στοιχείων λογαριασμού &amp; αλλαγή κωδικού">
                        <span>👤 <?= htmlspecialchars($user['name']) ?></span>
                        <span style="font-size:11px; opacity:0.6;" title="Ρυθμίσεις Λογαριασμού">⚙️</span>
                    </a>
                <?php else: ?>
                    <span>👤 <?= htmlspecialchars($user['name']) ?></span>
                <?php endif; ?>
                <?php if (isTeacherAdmin()): ?>
                    <span class="role-tag" style="background:#7c3aed; color:#fff;">👑 Καθηγητής - Διαχειριστής</span>
                <?php else: ?>
                    <span class="role-tag">Διαχειριστής</span>
                <?php endif; ?>
            </div>
            <?= logoutForm('btn btn-secondary btn-sm') ?>
        </div>
    </nav>

    <div class="main-container">
        <div class="nav-tabs" id="adminNavigation" aria-label="Κεντρική πλοήγηση">
            <a href="index.php" class="nav-tab <?= $currentPage === 'index.php' ? 'active' : '' ?>">📊 Σημερινές Απουσίες</a>
            <a href="student_cards.php" class="nav-tab <?= $currentPage === 'student_cards.php' ? 'active' : '' ?>">📋 Καρτέλες Απουσιών &amp; Όρια</a>

            <?php if (isAdmin()): ?>
                <?php 
                    $isSchoolDataActive = in_array($currentPage, ['students.php', 'sections.php', 'teachers.php', 'import.php']);
                    $isToolsActive = in_array($currentPage, ['logs.php', 'settings.php', 'help.php', 'maintenance.php', 'technical_errors.php', 'prints.php']);
                ?>
                <!-- Dropdown: Δεδομένα Σχολείου -->
                <div class="nav-dropdown <?= $isSchoolDataActive ? 'active' : '' ?>">
                    <button type="button" class="nav-tab nav-dropdown-btn <?= $isSchoolDataActive ? 'active' : '' ?>">
                        <span>🏫 Δεδομένα Σχολείου</span>
                        <span class="dropdown-arrow">▾</span>
                    </button>
                    <div class="nav-dropdown-menu">
                        <a href="students.php" class="nav-dropdown-item <?= $currentPage === 'students.php' ? 'active' : '' ?>">
                            <span class="item-icon">🎓</span>
                            <span class="item-text">
                                <strong>Μαθητές</strong>
                                <small>Μητρώο, στοιχεία &amp; κηδεμόνες</small>
                            </span>
                        </a>
                        <a href="sections.php" class="nav-dropdown-item <?= $currentPage === 'sections.php' ? 'active' : '' ?>">
                            <span class="item-icon">🏫</span>
                            <span class="item-text">
                                <strong>Τμήματα &amp; Τάξεις</strong>
                                <small>Διαχείριση τμημάτων ανά τάξη</small>
                            </span>
                        </a>
                        <a href="teachers.php" class="nav-dropdown-item <?= $currentPage === 'teachers.php' ? 'active' : '' ?>">
                            <span class="item-icon">👥</span>
                            <span class="item-text">
                                <strong>Καθηγητές &amp; Αναθέσεις</strong>
                                <small>Λογαριασμοί &amp; διδασκαλίες</small>
                            </span>
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="import.php" class="nav-dropdown-item <?= $currentPage === 'import.php' ? 'active' : '' ?>">
                            <span class="item-icon">📥</span>
                            <span class="item-text">
                                <strong>Εισαγωγή Excel/CSV</strong>
                                <small>Μαθητές &amp; καθηγητές myschool</small>
                            </span>
                        </a>
                    </div>
                </div>

                <!-- Dropdown: Εργαλεία & Σύστημα -->
                <div class="nav-dropdown <?= $isToolsActive ? 'active' : '' ?>">
                    <button type="button" class="nav-tab nav-dropdown-btn <?= $isToolsActive ? 'active' : '' ?>">
                        <span>⚙️ Εργαλεία &amp; Σύστημα</span>
                        <span class="dropdown-arrow">▾</span>
                    </button>
                    <div class="nav-dropdown-menu">
                        <a href="prints.php" class="nav-dropdown-item <?= $currentPage === 'prints.php' ? 'active' : '' ?>"><span class="item-icon">🖨️</span><span class="item-text"><strong>Εκτυπώσεις</strong><small>Έντυπα και αιτήσεις</small></span></a>
                        <a href="logs.php" class="nav-dropdown-item <?= $currentPage === 'logs.php' ? 'active' : '' ?>">
                            <span class="item-icon">📜</span>
                            <span class="item-text">
                                <strong>Ιστορικό Ειδοποιήσεων</strong>
                                <small>Καταγραφή αποστολής email</small>
                            </span>
                        </a>
                        <a href="settings.php" class="nav-dropdown-item <?= $currentPage === 'settings.php' ? 'active' : '' ?>">
                            <span class="item-icon">⚙️</span>
                            <span class="item-text">
                                <strong>Ρυθμίσεις &amp; Email</strong>
                                <small>Σχολείο, SMTP, έτη &amp; όρια</small>
                            </span>
                        </a>
                        <a href="maintenance.php" class="nav-dropdown-item <?= $currentPage === 'maintenance.php' ? 'active' : '' ?>"><span class="item-icon">🔧</span><span class="item-text"><strong>Ενημερώσεις &amp; Απεγκατάσταση</strong><small>Έλεγχοι, αντίγραφα &amp; συντήρηση</small></span></a>
                        <a href="technical_errors.php" class="nav-dropdown-item <?= $currentPage === 'technical_errors.php' ? 'active' : '' ?>"><span class="item-icon">🛠️</span><span class="item-text"><strong>Τεχνικά σφάλματα</strong><small>Καταγραφή &amp; διάγνωση</small></span></a>
                        <div class="dropdown-divider"></div>
                        <a href="help.php" class="nav-dropdown-item <?= $currentPage === 'help.php' ? 'active' : '' ?>">
                            <span class="item-icon">❓</span>
                            <span class="item-text">
                                <strong>Βοήθεια &amp; Οδηγός</strong>
                                <small>Εγχειρίδιο χρήσης συστήματος</small>
                            </span>
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <a href="prints.php" class="nav-tab <?= $currentPage === 'prints.php' ? 'active' : '' ?>">🖨️ Εκτυπώσεις</a>
                <a href="help.php" class="nav-tab <?= $currentPage === 'help.php' ? 'active' : '' ?>">❓ Βοήθεια &amp; Οδηγός</a>
            <?php endif; ?>

            <!-- Προβολή Τμήματος (Teacher View) -->
            <?php if ($currentPage !== 'index.php'): ?>
            <a href="../teacher/index.php" class="nav-tab nav-tab-teacher" style="margin-left:auto;" title="Μετάβαση στο Παρουσιολόγιο Τμήματος των Εκπαιδευτικών">
                <span>👨‍🏫 Προβολή Τμήματος</span>
                <span style="font-size:11px; opacity:0.85;">&nearr;</span>
            </a>
            <?php endif; ?>
        </div>
