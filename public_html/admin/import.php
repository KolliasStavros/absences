<?php
// admin/import.php - Import Students or Teachers & Section Assignments from MySchool Excel (.xls, .xlsx) or CSV
$pageTitle = 'Εισαγωγή Excel / MySchool';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/excel_reader.php';

$db = getDB();
$activeAcademicYear = getActiveAcademicYear();
$activeYearId = $activeAcademicYear ? (int)$activeAcademicYear['id'] : 1;

$message = '';
$error = '';
$importResults = null;
$importKind = 'students'; // 'students' or 'teachers'

// S10-03 & Step 11: Local quick-import probe is strictly restricted to development environments.
$isDevelopment = defined('APP_ENV') && APP_ENV === 'development';
$userProfile = $isDevelopment ? (getenv('USERPROFILE') ?: (getenv('HOMEDRIVE') . getenv('HOMEPATH'))) : null;

// Check for local student file in Downloads (dev-only convenience)
$defaultStudentFile = ($isDevelopment && $userProfile) ? ($userProfile . '/Downloads/rptList.xls') : '';
$hasStudentLocalDefault = !empty($defaultStudentFile) && file_exists($defaultStudentFile);

// Check for local teacher file in Downloads (dev-only convenience)
$defaultTeacherFile = ($isDevelopment && $userProfile) ? ($userProfile . '/Downloads/rptList (3).xls') : '';
if ($isDevelopment && !empty($userProfile)) {
    if (!file_exists($defaultTeacherFile) && file_exists($userProfile . '/Downloads/rptList_teachers.xls')) {
        $defaultTeacherFile = $userProfile . '/Downloads/rptList_teachers.xls';
    }
}
$hasTeacherLocalDefault = !empty($defaultTeacherFile) && file_exists($defaultTeacherFile);

$selectedTab = $_GET['type'] ?? ($_POST['import_type'] ?? 'students');
if (!in_array($selectedTab, ['students', 'teachers'])) {
    $selectedTab = 'students';
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Μη έγκυρο αίτημα (CSRF token mismatch). Παρακαλώ ανανεώστε τη σελίδα και δοκιμάστε ξανά.';
    } else {
        try {
            $sheet = null;
            $importType = $_POST['import_type'] ?? 'students';

            // 1. Quick import from local Downloads file
            if (isset($_POST['import_local_student']) && $hasStudentLocalDefault) {
                $sheet = ExcelReader::readFile($defaultStudentFile);
                $importType = 'students';
            } elseif (isset($_POST['import_local_teacher']) && $hasTeacherLocalDefault) {
                $sheet = ExcelReader::readFile($defaultTeacherFile);
                $importType = 'teachers';
            }
            // 2. Uploaded Excel or CSV File (SEC-10: File validation and extension allowlist)
            elseif (!empty($_FILES['excel_file']['tmp_name']) && is_uploaded_file($_FILES['excel_file']['tmp_name'])) {
                if ($_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception("Σφάλμα κατά τη μεταφόρτωση του αρχείου (κωδικός " . (int)$_FILES['excel_file']['error'] . ").");
                }
                if ($_FILES['excel_file']['size'] > 10 * 1024 * 1024) {
                    throw new Exception("Το μέγεθος του αρχείου υπερβαίνει το ανώτατο επιτρεπτό όριο (10MB).");
                }
                $ext = strtolower(pathinfo($_FILES['excel_file']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['xls', 'xlsx', 'csv', 'tsv', 'txt'], true)) {
                    throw new Exception("Μη επιτρεπτός τύπος αρχείου. Επιτρέπονται μόνο αρχεία Excel (.xls, .xlsx) και CSV.");
                }
                // Check for embedded PHP executable code or script tags in uploaded content
                $sampleContent = @file_get_contents($_FILES['excel_file']['tmp_name'], false, null, 0, 4096);
                if ($sampleContent !== false && (stripos($sampleContent, '<?php') !== false || stripos($sampleContent, '<?=') !== false || stripos($sampleContent, '<script') !== false)) {
                    throw new Exception("Το αρχείο περιέχει μη επιτρεπόμενο εκτελέσιμο περιεχόμενο ή κώδικα script.");
                }
                $sheet = ExcelReader::readFile($_FILES['excel_file']['tmp_name']);
            }
            // 3. Pasted Text from Clipboard
            elseif (!empty($_POST['pasted_data'])) {
                $sheet = ExcelReader::readText(trim($_POST['pasted_data']));
            }

            if (empty($sheet)) {
                throw new Exception("Δεν βρέθηκαν δεδομένα προς εισαγωγή. Παρακαλώ επιλέξτε ένα αρχείο ή επικολλήστε δεδομένα.");
            }

            ExcelReader::validateSheet($sheet);
            // Auto-detect file content type
            $isTeacherFile = false;
            foreach (array_slice($sheet, 0, 20) as $row) {
                $rowStr = implode(' ', $row);
                if (mb_stripos($rowStr, 'Αναθέσεις εκπαιδευτικών', 0, 'UTF-8') !== false || 
                    (mb_stripos($rowStr, 'Ειδικότητα εργαζομένου', 0, 'UTF-8') !== false && mb_stripos($rowStr, 'Τμήμα', 0, 'UTF-8') !== false)) {
                    $isTeacherFile = true;
                    break;
                }
            }

            if ($isTeacherFile) {
                $importType = 'teachers';
            }

            $importKind = $importType;

            // Execute Import
            $db->beginTransaction();
            if ($importType === 'teachers') {
                $importResults = ExcelReader::importMySchoolTeachers($sheet, $db, $activeYearId);
                $db->commit();
                $message = "Η εισαγωγή εκπαιδευτικών ολοκληρώθηκε επιτυχώς! Επεξεργάστηκαν {$importResults['total']} καθηγητές ({$importResults['imported']} νέοι, {$importResults['updated']} ενημερώθηκαν) με συνολικά {$importResults['total_assignments']} αναθέσεις τμημάτων.";
            } else {
                $importResults = ExcelReader::importMySchoolRows($sheet, $db);
                $db->commit();
                $totalAffected = $importResults['imported'] + $importResults['updated'];
                $message = "Η εισαγωγή μαθητών ολοκληρώθηκε επιτυχώς! Επεξεργάστηκαν {$totalAffected} μαθητές ({$importResults['imported']} νέοι, {$importResults['updated']} ενημερώθηκαν).";
            }

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = "Σφάλμα κατά την εισαγωγή: " . ($e instanceof InvalidArgumentException ? $e->getMessage() : safeError($e));
        }
    }
}
?>

<div class="card" style="margin-bottom:20px; padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
        <div>
            <h1 style="font-size:22px; font-weight:700; margin:0 0 4px 0; display:flex; align-items:center; gap:8px;">
                <span>📥</span> Εισαγωγή Δεδομένων MySchool (Excel / CSV)
            </h1>
            <p style="color:var(--text-muted); font-size:13.5px; margin:0;">
                Αυτόματη ανάγνωση και εισαγωγή αρχείων MySchool για Μαθητές ή Καθηγητές & Αναθέσεις Τμημάτων.
            </p>
        </div>
        <div>
            <span class="badge badge-success" style="font-size:12px; padding:6px 12px;">
                📅 Έτος: <strong><?= htmlspecialchars($activeAcademicYear['name'] ?? '2026-2027') ?></strong>
            </span>
        </div>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success" style="padding:16px; border-radius:10px; background:#d1fae5; color:#065f46; border:1px solid #a7f3d0; margin-bottom:20px;">
        <div style="font-weight:700; font-size:15px; margin-bottom:6px;">✓ <?= htmlspecialchars($message) ?></div>
        
        <?php if ($importKind === 'teachers'): ?>
            <div style="margin-top:10px; font-size:13px;">
                <strong>👥 Εκπαιδευτικοί που επεξεργάστηκαν (<?= count($importResults['teachers'] ?? []) ?>):</strong>
                <div style="max-height:180px; overflow-y:auto; background:#fff; border:1px solid #a7f3d0; border-radius:8px; padding:10px; margin-top:8px;">
                    <?php foreach ($importResults['teachers'] as $t): ?>
                        <div style="margin-bottom:6px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                            <strong><?= htmlspecialchars($t['name']) ?></strong>: 
                            <span style="color:var(--text-muted); font-size:12px;">(<?= $t['sections_count'] ?> τμήματα)</span>
                            <span style="font-size:11.5px; color:#059669;"><?= htmlspecialchars(implode(', ', $t['sections'])) ?></span>
                            <?php if ($t['is_new']): ?>
                                <span class="badge badge-success" style="font-size:10px;">Νέος</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="margin-top:14px;">
                <a href="teachers.php" class="btn btn-primary" style="display:inline-block; padding:8px 16px; text-decoration:none;">
                    Προβολή Καθηγητών & Αναθέσεων &rarr;
                </a>
            </div>
        <?php else: ?>
            <?php if (!empty($importResults['new_grades'])): ?>
                <div style="margin-top:8px; font-size:13px;">
                    🏷️ <strong>Νέες Τάξεις που δημιουργήθηκαν:</strong> <?= htmlspecialchars(implode(', ', $importResults['new_grades'])) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($importResults['new_sections'])): ?>
                <div style="margin-top:8px; font-size:13px;">
                    👥 <strong>Νέα Τμήματα που δημιουργήθηκαν αυτόματα:</strong>
                    <ul style="margin:6px 0 0 20px; padding:0;">
                        <?php foreach ($importResults['new_sections'] as $gName => $secs): ?>
                            <li><strong>Τάξη <?= htmlspecialchars($gName) ?>:</strong> <?= htmlspecialchars(implode(', ', $secs)) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div style="margin-top:14px;">
                <a href="students.php" class="btn btn-primary" style="display:inline-block; padding:8px 16px; text-decoration:none;">
                    Προβολή Μαθητών &rarr;
                </a>
                <a href="sections.php" class="btn btn-secondary" style="display:inline-block; padding:8px 16px; text-decoration:none; margin-left:8px;">
                    Προβολή Τμημάτων &rarr;
                </a>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger" style="padding:16px; border-radius:10px; background:#fee2e2; color:#991b1b; border:1px solid #fecaca; margin-bottom:20px;">
        ⚠️ <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<!-- Navigation Tabs for Import Types -->
<div class="import-type-tabs" style="display:flex; gap:10px; margin-bottom:20px; border-bottom:1px solid var(--border); padding-bottom:12px;">
    <a href="import.php?type=students" class="btn <?= $selectedTab === 'students' ? 'btn-primary' : 'btn-secondary' ?>" style="font-size:13.5px; padding:8px 18px; text-decoration:none;">
        🎓 Εισαγωγή Μαθητών & Τμημάτων
    </a>
    <a href="import.php?type=teachers" class="btn <?= $selectedTab === 'teachers' ? 'btn-primary' : 'btn-secondary' ?>" style="font-size:13.5px; padding:8px 18px; text-decoration:none;">
        👥 Εισαγωγή Καθηγητών & Αναθέσεων
    </a>
</div>

<button type="button" id="importInstructionsToggle" class="btn btn-secondary import-instructions-toggle" aria-expanded="false" aria-controls="importInstructions">ℹ️ Οδηγίες</button>

<?php if ($selectedTab === 'teachers'): ?>
    <!-- ==========================================
         TEACHERS IMPORT SECTION
         ========================================== -->
    <div class="import-layout" style="display:grid; grid-template-columns: 2fr 1fr; gap:24px;">
        <div>
            <!-- Quick Local File Import -->
            <?php if ($hasTeacherLocalDefault): ?>
                <div class="card" style="margin-bottom:24px; border:2px solid #10b981; background:var(--bg-card);">
                    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
                        <div>
                            <span class="badge badge-success" style="font-size:11px; margin-bottom:6px;">Έτοιμο Αρχείο MySchool</span>
                            <h3 style="font-size:16px; font-weight:700; margin:0 0 4px 0;">Εντοπίστηκε το αρχείο Αναθέσεων Καθηγητών!</h3>
                            <p style="font-size:12.5px; color:var(--text-muted); margin:0;">
                                Βρέθηκε το αρχείο: <code style="background:var(--border); padding:2px 6px; border-radius:4px; font-size:12px;"><?= htmlspecialchars(basename($defaultTeacherFile)) ?></code>
                            </p>
                        </div>
                        <form method="POST" action="import.php" style="margin:0;">
                            <?= csrfField() ?>
                            <input type="hidden" name="import_type" value="teachers">
                            <button type="submit" name="import_local_teacher" class="btn btn-primary" style="padding:10px 18px; font-weight:700;">
                                📥 Άμεση Εισαγωγή Καθηγητών
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Standard Upload / Paste Card -->
            <div class="card">
                <h3 style="font-size:16px; font-weight:700; margin-bottom:6px;">👥 Επιλογή Αρχείου ή Επικόλληση Καθηγητών</h3>
                <p style="font-size:13px; color:var(--text-muted); margin-bottom:20px;">
                    Εισάγετε το αρχείο <strong>«Αναθέσεις εκπαιδευτικών μονάδας»</strong> (.xls, .xlsx ή CSV) που εξάγεται από το MySchool.
                </p>

                <form method="POST" action="import.php" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <input type="hidden" name="import_type" value="teachers">

                    <div class="form-group" style="margin-bottom:20px;">
                        <label style="font-weight:600; display:block; margin-bottom:8px;">
                            Επιλογή Αρχείου Excel / CSV
                        </label>
                        <input type="file" name="excel_file" class="form-control" accept=".xls,.xlsx,.csv,.txt" style="padding:10px;">
                    </div>

                    <div style="text-align:center; margin:16px 0; color:var(--text-muted); font-size:13px; font-weight:600;">
                        &mdash; Ή ΕΠΙΚΟΛΛΗΣΗ ΑΠΟ ΤΟ CLIPBOARD &mdash;
                    </div>

                    <div class="form-group" style="margin-bottom:20px;">
                        <label style="font-weight:600; display:block; margin-bottom:8px;">
                            Επικόλληση Δεδομένων (Αντιγραφή από Excel)
                        </label>
                        <textarea name="pasted_data" class="form-control" rows="5" placeholder="Κάντε αντιγραφή των στηλών από το Excel και επικόλληση εδώ..."></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width:100%; padding:12px; font-weight:700;">
                        🚀 Έναρξη Εισαγωγής Καθηγητών & Αναθέσεων
                    </button>
                </form>
            </div>
        </div>

        <!-- Sidebar Instructions for Teachers -->
        <div id="importInstructions" class="import-instructions">
            <div class="card">
                <h3 style="font-size:15px; font-weight:700; margin-bottom:12px;">ℹ️ Οδηγίες Εξαγωγής MySchool</h3>
                <ol style="font-size:13px; color:var(--text-muted); line-height:1.7; padding-left:18px; margin:0;">
                    <li>Στο <strong>MySchool</strong>, μεταβείτε στο μενού:
                        <div style="font-weight:600; color:var(--text-main); margin:4px 0;">Προσωπικό ➔ Αναθέσεις Εκπαιδευτικών</div>
                    </li>
                    <li>Πατήστε το κουμπί <strong>«Εκτύπωση / Εξαγωγή»</strong> σε αρχείο Excel (.xls).</li>
                    <li>Αποθηκεύστε το αρχείο (συνήθως ονομάζεται <code>rptList.xls</code>).</li>
                    <li>Επιλέξτε το παραπάνω για αυτόματη εισαγωγή.</li>
                </ol>

                <div style="margin-top:16px; padding:12px; background:var(--border); border-radius:8px; font-size:12.5px;">
                    💡 <strong>Τι εισάγεται αυτόματα:</strong>
                    <ul style="margin:6px 0 0 16px; padding:0; color:var(--text-main);">
                        <li><strong>Επώνυμο &amp; Όνομα</strong> εκπαιδευτικού</li>
                        <li><strong>Όλα τα Τμήματα</strong> που έχει ανάθεση</li>
                        <li>Δημιουργία λογαριασμού καθηγητή στο σύστημα</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- ==========================================
         STUDENTS IMPORT SECTION (Existing)
         ========================================== -->
    <div class="import-layout" style="display:grid; grid-template-columns: 2fr 1fr; gap:24px;">
        <div>
            <!-- Quick Local File Import -->
            <?php if ($hasStudentLocalDefault): ?>
                <div class="card" style="margin-bottom:24px; border:2px solid #10b981; background:var(--bg-card);">
                    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
                        <div>
                            <span class="badge badge-success" style="font-size:11px; margin-bottom:6px;">Έτοιμο Αρχείο MySchool</span>
                            <h3 style="font-size:16px; font-weight:700; margin:0 0 4px 0;">Εντοπίστηκε το αρχείο Μαθητών!</h3>
                            <p style="font-size:12.5px; color:var(--text-muted); margin:0;">
                                Βρέθηκε το αρχείο: <code style="background:var(--border); padding:2px 6px; border-radius:4px; font-size:12px;"><?= htmlspecialchars(basename($defaultStudentFile)) ?></code>
                            </p>
                        </div>
                        <form method="POST" action="import.php" style="margin:0;">
                            <?= csrfField() ?>
                            <input type="hidden" name="import_type" value="students">
                            <button type="submit" name="import_local_student" class="btn btn-primary" style="padding:10px 18px; font-weight:700;">
                                📥 Άμεση Εισαγωγή Μαθητών
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Standard Upload / Paste Card -->
            <div class="card">
                <h3 style="font-size:16px; font-weight:700; margin-bottom:6px;">🎓 Επιλογή Αρχείου ή Επικόλληση Μαθητών</h3>
                <p style="font-size:13px; color:var(--text-muted); margin-bottom:20px;">
                    Εισάγετε το αρχείο κατάστασης μαθητών (.xls, .xlsx ή CSV) που εξάγεται από το MySchool.
                </p>

                <form method="POST" action="import.php" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <input type="hidden" name="import_type" value="students">

                    <div class="form-group" style="margin-bottom:20px;">
                        <label style="font-weight:600; display:block; margin-bottom:8px;">
                            Επιλογή Αρχείου Excel / CSV
                        </label>
                        <input type="file" name="excel_file" class="form-control" accept=".xls,.xlsx,.csv,.txt" style="padding:10px;">
                    </div>

                    <div style="text-align:center; margin:16px 0; color:var(--text-muted); font-size:13px; font-weight:600;">
                        &mdash; Ή ΕΠΙΚΟΛΛΗΣΗ ΑΠΟ ΤΟ CLIPBOARD &mdash;
                    </div>

                    <div class="form-group" style="margin-bottom:20px;">
                        <label style="font-weight:600; display:block; margin-bottom:8px;">
                            Επικόλληση Δεδομένων (Αντιγραφή από Excel)
                        </label>
                        <textarea name="pasted_data" class="form-control" rows="5" placeholder="Κάντε αντιγραφή των στηλών από το Excel και επικόλληση εδώ..."></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width:100%; padding:12px; font-weight:700;">
                        🚀 Έναρξη Εισαγωγής Μαθητών
                    </button>
                </form>
            </div>
        </div>

        <!-- Sidebar Instructions for Students -->
        <div id="importInstructions" class="import-instructions">
            <div class="card">
                <h3 style="font-size:15px; font-weight:700; margin-bottom:12px;">ℹ️ Οδηγίες Εξαγωγής MySchool</h3>
                <ol style="font-size:13px; color:var(--text-muted); line-height:1.7; padding-left:18px; margin:0;">
                    <li>Στο <strong>MySchool</strong>, μεταβείτε στο μενού:
                        <div style="font-weight:600; color:var(--text-main); margin:4px 0;">Μαθητές ➔ Κατάλογος Μαθητών</div>
                    </li>
                    <li>Πατήστε <strong>«Εξαγωγή σε Excel»</strong>.</li>
                    <li>Αποθηκεύστε το αρχείο και επιλέξτε το παραπάνω.</li>
                </ol>

                <div style="margin-top:16px; padding:12px; background:var(--border); border-radius:8px; font-size:12.5px;">
                    💡 <strong>Αυτόματη Επεξεργασία:</strong>
                    <ul style="margin:6px 0 0 16px; padding:0; color:var(--text-main);">
                        <li>Αυτόματη δημιουργία <strong>Τάξεων &amp; Τμημάτων</strong></li>
                        <li>Αυτόματη κλίση ονομάτων σε <strong>Γενική &amp; Κλητική</strong></li>
                        <li>Ανίχνευση <strong>φύλου</strong> (Μαθητής/Μαθήτρια)</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>