<?php
// admin/students.php - Full Student Management (CRUD with Gender support)
$pageTitle = 'Διαχείριση Μαθητών';
require_once __DIR__ . '/header.php';

$db = getDB();
$activeAcademicYear = getActiveAcademicYear();
$activeYearId = $activeAcademicYear ? (int)$activeAcademicYear['id'] : 1;
$message = '';
$error = '';

// Handle all POST actions with CSRF verification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Μη έγκυρο αίτημα ασφαλείας (CSRF Token mismatch). Παρακαλώ ανανεώστε τη σελίδα.';
    } else {
        // Handle Add Student
        if (isset($_POST['add_student'])) {
            $am = trim($_POST['am'] ?? '');
            $lastName = trim($_POST['last_name'] ?? '');
            $firstName = trim($_POST['first_name'] ?? '');
            $gender = trim($_POST['gender'] ?? 'male');
            $fatherName = trim($_POST['father_name'] ?? '');
            $motherName = trim($_POST['mother_name'] ?? '');
            $lastNameGen = trim($_POST['last_name_gen'] ?? '');
            $firstNameGen = trim($_POST['first_name_gen'] ?? '');
            $studentEmail = trim($_POST['student_email'] ?? '');
            $guardianName1 = trim($_POST['guardian_name'] ?? '');
            $guardianEmail1 = trim($_POST['guardian_email'] ?? '');
            $guardianMobile1 = trim($_POST['guardian_mobile'] ?? '');
            $guardianName2 = trim($_POST['guardian_name_2'] ?? '');
            $guardianEmail2 = trim($_POST['guardian_email_2'] ?? '');
            $guardianMobile2 = trim($_POST['guardian_mobile_2'] ?? '');
            $selectedSections = $_POST['sections'] ?? [];

            if (empty($am) || empty($lastName) || empty($firstName)) {
                $error = 'Παρακαλώ συμπληρώστε τα υποχρεωτικά πεδία (Α.Μ., Επώνυμο, Όνομα).';
            } else {
                try {
                    $chk = $db->prepare("SELECT id FROM students WHERE am = ? AND academic_year_id = ?");
                    $chk->execute([$am, $activeYearId]);
                    if ($chk->fetch()) {
                        $error = "Υπάρχει ήδη μαθητής με τον Αριθμό Μητρώου: {$am} στο τρέχον σχολικό έτος.";
                    } else {
                        if (empty($lastNameGen)) $lastNameGen = greek_to_genitive($lastName);
                        if (empty($firstNameGen)) $firstNameGen = greek_to_genitive($firstName);
                        $guardianVoc = 'κ./κα ' . inflect_word_vocative($lastName);

                        $db->beginTransaction();
                        $stmt = $db->prepare("
                            INSERT INTO students (
                                academic_year_id,
                                am, last_name, first_name, father_name, mother_name, gender,
                                last_name_gen, first_name_gen, student_email,
                                guardian_name, guardian_name_voc, guardian_email, guardian_mobile,
                                guardian_name_2, guardian_email_2, guardian_mobile_2
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $activeYearId,
                            $am, $lastName, $firstName, $fatherName, $motherName, $gender,
                            $lastNameGen, $firstNameGen, $studentEmail,
                            $guardianName1, $guardianVoc, $guardianEmail1, $guardianMobile1,
                            $guardianName2, $guardianEmail2, $guardianMobile2
                        ]);
                        $studentId = $db->lastInsertId();

                        if (!empty($selectedSections)) {
                            $secStmt = $db->prepare("INSERT INTO student_sections (student_id, section_id) VALUES (?, ?)");
                            foreach ($selectedSections as $secId) {
                                $secStmt->execute([$studentId, (int)$secId]);
                            }
                        }
                        $db->commit();
                        $message = "Ο/Η μαθητής/τρια {$lastName} {$firstName} (Α.Μ. {$am}) καταχωρήθηκε επιτυχώς!";
                    }
                } catch (Exception $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    $error = 'Σφάλμα καταχώρησης: ' . safeError($e);
                }
            }
        }

        // Handle Edit Student
        if (isset($_POST['edit_student'])) {
            $studentId = (int)$_POST['student_id'];
            $scope = $db->prepare('SELECT id FROM students WHERE id=? AND academic_year_id=?');
            $scope->execute([$studentId, $activeYearId]);
            if (!$scope->fetchColumn()) { http_response_code(400); exit('Μη έγκυρος μαθητής για το ενεργό έτος.'); }

            $am = trim($_POST['am'] ?? '');
            $lastName = trim($_POST['last_name'] ?? '');
            $firstName = trim($_POST['first_name'] ?? '');
            $gender = trim($_POST['gender'] ?? 'male');
            $fatherName = trim($_POST['father_name'] ?? '');
            $motherName = trim($_POST['mother_name'] ?? '');
            $lastNameGen = trim($_POST['last_name_gen'] ?? '');
            $firstNameGen = trim($_POST['first_name_gen'] ?? '');
            $studentEmail = trim($_POST['student_email'] ?? '');
            $guardianName1 = trim($_POST['guardian_name'] ?? '');
            $guardianEmail1 = trim($_POST['guardian_email'] ?? '');
            $guardianMobile1 = trim($_POST['guardian_mobile'] ?? '');
            $guardianName2 = trim($_POST['guardian_name_2'] ?? '');
            $guardianEmail2 = trim($_POST['guardian_email_2'] ?? '');
            $guardianMobile2 = trim($_POST['guardian_mobile_2'] ?? '');
            $selectedSections = $_POST['sections'] ?? [];

            if ($studentId <= 0 || empty($am) || empty($lastName) || empty($firstName)) {
                $error = 'Παρακαλώ συμπληρώστε τα υποχρεωτικά πεδία (Α.Μ., Επώνυμο, Όνομα).';
            } else {
                try {
                    $chk = $db->prepare("SELECT id FROM students WHERE am = ? AND id != ? AND academic_year_id = ?");
                    $chk->execute([$am, $studentId, $activeYearId]);
                    if ($chk->fetch()) {
                        $error = "Υπάρχει ήδη άλλος μαθητής με τον Αριθμό Μητρώου: {$am}.";
                    } else {
                        if (empty($lastNameGen)) $lastNameGen = greek_to_genitive($lastName);
                        if (empty($firstNameGen)) $firstNameGen = greek_to_genitive($firstName);
                        $guardianVoc = 'κ./κα ' . inflect_word_vocative($lastName);

                        $db->beginTransaction();
                        $stmt = $db->prepare("
                            UPDATE students SET
                                am = ?, last_name = ?, first_name = ?, father_name = ?, mother_name = ?, gender = ?,
                                last_name_gen = ?, first_name_gen = ?, student_email = ?,
                                guardian_name = ?, guardian_name_voc = ?, guardian_email = ?, guardian_mobile = ?,
                                guardian_name_2 = ?, guardian_email_2 = ?, guardian_mobile_2 = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $am, $lastName, $firstName, $fatherName, $motherName, $gender,
                            $lastNameGen, $firstNameGen, $studentEmail,
                            $guardianName1, $guardianVoc, $guardianEmail1, $guardianMobile1,
                            $guardianName2, $guardianEmail2, $guardianMobile2,
                            $studentId
                        ]);

                        $db->prepare("DELETE FROM student_sections WHERE student_id = ?")->execute([$studentId]);
                        if (!empty($selectedSections)) {
                            $secStmt = $db->prepare("INSERT INTO student_sections (student_id, section_id) VALUES (?, ?)");
                            foreach ($selectedSections as $secId) {
                                $secStmt->execute([$studentId, (int)$secId]);
                            }
                        }
                        $db->commit();
                        $message = "Τα στοιχεία του/της {$lastName} {$firstName} ενημερώθηκαν επιτυχώς!";
                    }
                } catch (Exception $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    $error = 'Σφάλμα ενημέρωσης: ' . safeError($e);
                }
            }
        }

        // Handle Delete Student - DATA-01: Protect historical attendance records from CASCADE deletion
        if (isset($_POST['delete_student'])) {
            $studentId = (int)$_POST['student_id'];
            $scope = $db->prepare('SELECT id FROM students WHERE id=? AND academic_year_id=?');
            $scope->execute([$studentId, $activeYearId]);
            if (!$scope->fetchColumn()) { http_response_code(400); exit('Μη έγκυρος μαθητής για το ενεργό έτος.'); }

            try {
                $stmt = $db->prepare("SELECT last_name, first_name, am FROM students WHERE id = ?");
                $stmt->execute([$studentId]);
                $st = $stmt->fetch();
                if ($st) {
                    // Check if student has recorded attendance
                    $stmtAtt = $db->prepare("SELECT COUNT(*) FROM attendance WHERE student_id = ?");
                    $stmtAtt->execute([$studentId]);
                    $attCount = (int)$stmtAtt->fetchColumn();

                    if ($attCount > 0) {
                        $error = "Δεν είναι δυνατή η διαγραφή του/της μαθητή/τριας {$st['last_name']} {$st['first_name']} (Α.Μ. {$st['am']}) επειδή υπάρχουν {$attCount} καταχωρημένες απουσίες στο ιστορικό του σχολείου. Για τη διασφάλιση της νομιμότητας και της ακεραιότητας των αρχείων, η διαγραφή έχει αποκλειστεί.";
                    } else {
                        $db->prepare("DELETE FROM students WHERE id = ?")->execute([$studentId]);
                        $message = "Ο/Η μαθητής/τρια {$st['last_name']} {$st['first_name']} (Α.Μ. {$st['am']}) διαγράφηκε επιτυχώς.";
                    }
                }
            } catch (Exception $e) {
                $error = 'Σφάλμα κατά τη διαγραφή: ' . safeError($e);
            }
        }
    }
}

// Fetch all sections
$allSections = $db->query("
    SELECT s.id, s.name, g.name as grade_name 
    FROM sections s 
    JOIN grades g ON s.grade_id = g.id 
    ORDER BY g.name ASC, s.name ASC
")->fetchAll();

$selectedSection = (int)($_GET['section_id'] ?? 0);
$search = trim($_GET['search'] ?? '');
require_once __DIR__.'/../includes/contact_quality.php';
$contactFilters=['all'=>'Όλοι οι μαθητές','missing_email'=>'Χωρίς email κηδεμόνα','missing_mobile'=>'Χωρίς κινητό κηδεμόνα','invalid'=>'Πιθανώς λανθασμένο email / κινητό','missing_all'=>'Χωρίς κανένα στοιχείο επικοινωνίας'];
$contactFilter=$_GET['contact_filter'] ?? 'all';
if (!is_string($contactFilter) || !isset($contactFilters[$contactFilter])) {
    http_response_code(400);
    echo '<div class="alert alert-danger">Μη έγκυρο φίλτρο στοιχείων επικοινωνίας. <a href="students.php">Καθαρισμός φίλτρων</a></div>';
    require __DIR__.'/footer.php'; exit;
}

$sql = "
    SELECT s.*, 
           GROUP_CONCAT(sec.name ORDER BY sec.name SEPARATOR ', ') as section_names,
           GROUP_CONCAT(sec.id) as section_ids
    FROM students s
    LEFT JOIN student_sections ss ON s.id = ss.student_id
    LEFT JOIN sections sec ON ss.section_id = sec.id
    WHERE s.academic_year_id = ?
";
$params = [$activeYearId];

if ($selectedSection > 0) {
    $sql .= " AND s.id IN (SELECT student_id FROM student_sections WHERE section_id = ?)";
    $params[] = $selectedSection;
}

if (!empty($search)) {
    $sql .= " AND (s.last_name LIKE ? OR s.first_name LIKE ? OR s.am LIKE ? OR s.guardian_email LIKE ? OR s.guardian_email_2 LIKE ?)";
    $searchTerm = "%{$search}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$sql .= " GROUP BY s.id ORDER BY s.last_name ASC, s.first_name ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();
$contactQuality=[];
foreach($students as $student) $contactQuality[$student['id']]=guardianContactQuality($student);
if ($contactFilter!=='all') $students=array_values(array_filter($students,static fn($student)=>$contactQuality[$student['id']][$contactFilter]));
?>
<link rel="stylesheet" href="../assets/css/contact-quality.css?v=20261029-1">

<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card" style="padding: 16px 20px; margin-bottom: 20px;">
    <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:16px;">
        <form method="GET" action="students.php" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; flex:1; min-width:320px;">
            <div class="form-group" style="flex:1; min-width:220px; margin-bottom:0;">
                <label style="display:block; font-size:12px; font-weight:600; color:var(--text-muted); margin-bottom:6px;">Αναζήτηση Μαθητή</label>
                <input type="text" name="search" class="form-control" placeholder="Επώνυμο, Όνομα, Α.Μ. ή Email..." value="<?= htmlspecialchars($search) ?>" style="height:38px; font-size:13px;">
            </div>

            <div class="form-group" style="width:220px; min-width:180px; margin-bottom:0;">
                <label style="display:block; font-size:12px; font-weight:600; color:var(--text-muted); margin-bottom:6px;">Φίλτρο Τμήματος</label>
                <select name="section_id" class="form-control" onchange="this.form.submit()" style="height:38px; font-size:13px;">
                    <option value="0">Όλα τα τμήματα</option>
                    <?php foreach ($allSections as $sec): ?>
                        <option value="<?= $sec['id'] ?>" <?= $selectedSection === $sec['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sec['name']) ?> (Τάξη <?= htmlspecialchars($sec['grade_name']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="flex:1; min-width:220px; margin-bottom:0;">
                <label for="contactFilter" style="display:block; font-size:12px; font-weight:600; color:var(--text-muted); margin-bottom:6px;">Στοιχεία επικοινωνίας κηδεμόνων</label>
                <select id="contactFilter" name="contact_filter" class="form-control" style="height:38px; font-size:13px;">
                    <?php foreach($contactFilters as $value=>$label): ?><option value="<?= htmlspecialchars($value) ?>" <?= $contactFilter===$value?'selected':'' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex; gap:6px; margin-bottom:0;">
                <button type="submit" class="btn btn-secondary" style="height:38px; padding:0 14px; font-size:13px; font-weight:600;" title="Αναζήτηση">
                    🔍 Αναζήτηση
                </button>
                <?php if (!empty($search) || $selectedSection > 0 || $contactFilter!=='all'): ?>
                    <a href="students.php" class="btn btn-secondary" style="height:38px; padding:0 12px; font-size:13px; display:inline-flex; align-items:center;" title="Καθαρισμός φίλτρων">
                        ✕
                    </a>
                <?php endif; ?>
            </div>
        </form>

        <div style="display:flex; gap:10px; align-items:flex-end; margin-bottom:0;">
            <button type="button" class="btn btn-primary" onclick="openAddStudentModal()" style="height:38px; padding:0 16px; font-size:13px; font-weight:700; white-space:nowrap;">
                ➕ Προσθήκη Μαθητή
            </button>
            <a href="import.php" class="btn btn-secondary" style="height:38px; padding:0 14px; font-size:13px; font-weight:600; white-space:nowrap; text-decoration:none;">
                📥 Εισαγωγή Excel
            </a>
        </div>
    </div>
</div>

<?php if ($contactFilter!=='all'): ?>
<div class="contact-quality-summary" role="status">
    <strong><?= htmlspecialchars($contactFilters[$contactFilter]) ?> · <?= count($students) ?> μαθητές</strong>
    <p>Λαμβάνονται υπόψη και οι δύο κηδεμόνες. Ο έλεγχος αφορά τη μορφή email και ελληνικών κινητών, όχι την ύπαρξη ή παράδοση. Πατήστε «Επεξεργασία» για διόρθωση.</p>
</div>
<?php endif; ?>
<div class="card">
    <div class="card-title">
        <span>Μαθητολόγιο (<?= count($students) ?>)</span> <span class="badge badge-success" style="font-size:12px; margin-left:8px;" title="Τρέχον Σχολικό Έτος">Έτος <?= htmlspecialchars($activeAcademicYear['name'] ?? '') ?></span>
    </div>

    <?php if (empty($students)): ?>
        <div style="text-align:center; padding:30px; color:var(--text-muted);">
            Δεν βρέθηκαν μαθητές με τα επιλεγμένα κριτήρια.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table students-table">
                <thead>
                    <tr>
                        <th>Α.Μ.</th>
                        <th>Ονοματεπώνυμο Μαθητή</th>
                        <th>Φύλο</th>
                        <th>Τμήματα</th>
                        <th>1ος Κηδεμόνας</th>
                        <th>2ος Κηδεμόνας</th>
                        <th width="140">Ενέργειες</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $s): ?>
                        <tr>
                            <td><span class="badge badge-gray"><?= htmlspecialchars($s['am']) ?></span></td>
                            <td>
                                <strong><?= htmlspecialchars($s['last_name'] . ' ' . $s['first_name']) ?></strong>
                                <?php if ($contactFilter!=='all'): ?><div class="contact-quality-warning"><?php foreach($contactQuality[$s['id']]['messages'] as $notice): ?><p><?= htmlspecialchars($notice) ?></p><?php endforeach; ?></div><?php endif; ?>
                                <?php if ($s['father_name']): ?>
                                    <span style="font-size:12px; color:var(--text-muted);">του <?= htmlspecialchars($s['father_name']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($s['mother_name'])): ?>
                                    <span style="font-size:12px; color:var(--text-muted);">& της <?= htmlspecialchars($s['mother_name']) ?></span>
                                <?php endif; ?>
                                <div style="font-size:11px; color:#64748b; margin-top:2px;">
                                    Γεν: <?= htmlspecialchars(($s['last_name_gen'] ?: $s['last_name']) . ' ' . ($s['first_name_gen'] ?: $s['first_name'])) ?>
                                    <?php if ($s['student_email']): ?>
                                        | ✉️ <?= htmlspecialchars($s['student_email']) ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?php if (($s['gender'] ?? 'male') === 'female'): ?>
                                    <span class="badge" style="background:#fce7f3; color:#9d174d;">👧 Μαθήτρια</span>
                                <?php else: ?>
                                    <span class="badge" style="background:#e0e7ff; color:#3730a3;">👦 Μαθητής</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($s['section_names']): ?>
                                    <span style="font-size:13px;"><?= htmlspecialchars($s['section_names']) ?></span>
                                <?php else: ?>
                                    <span style="font-size:12px; color:var(--danger);">Χωρίς τμήμα</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($s['guardian_email']): ?>
                                    <a href="mailto:<?= htmlspecialchars($s['guardian_email']) ?>" style="color:var(--primary); text-decoration:none; font-size:13px; font-weight:500;">
                                        <?= htmlspecialchars($s['guardian_email']) ?>
                                    </a>
                                <?php endif; ?>
                                <?php if ($s['guardian_mobile']): ?>
                                    <div style="font-size:12px; color:var(--text-muted);">📞 <?= htmlspecialchars($s['guardian_mobile']) ?></div>
                                <?php endif; ?>
                                <?php if (empty($s['guardian_email']) && empty($s['guardian_mobile'])): ?>
                                    <span style="color:var(--text-muted); font-size:12px;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($s['guardian_email_2']): ?>
                                    <a href="mailto:<?= htmlspecialchars($s['guardian_email_2']) ?>" style="color:var(--primary); text-decoration:none; font-size:13px; font-weight:500;">
                                        <?= htmlspecialchars($s['guardian_email_2']) ?>
                                    </a>
                                <?php endif; ?>
                                <?php if ($s['guardian_mobile_2']): ?>
                                    <div style="font-size:12px; color:var(--text-muted);">📞 <?= htmlspecialchars($s['guardian_mobile_2']) ?></div>
                                <?php endif; ?>
                                <?php if (empty($s['guardian_email_2']) && empty($s['guardian_mobile_2'])): ?>
                                    <span style="color:var(--text-muted); font-size:12px;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex; gap:6px;">
                                    <button type="button" class="btn btn-secondary btn-sm student-expand" aria-label="Περισσότερα στοιχεία μαθητή" title="Περισσότερα στοιχεία μαθητή" aria-expanded="false">⌄</button>
                                    <a href="student_cards.php?q=<?= urlencode($s['am']) ?>" class="btn btn-secondary btn-sm" aria-label="Προβολή Καρτέλας Απουσιών" title="Προβολή Καρτέλας Απουσιών">
                                        📋
                                    </a>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="openEditStudentModal(<?= htmlspecialchars(json_encode($s)) ?>)" aria-label="Επεξεργασία" title="Επεξεργασία">
                                        ✏️
                                    </button>
                                    <form method="POST" action="students.php" style="display:inline;" onsubmit="return confirm('Είστε σίγουροι ότι θέλετε να διαγράψετε τον/την <?= htmlspecialchars(addslashes($s['last_name'] . ' ' . $s['first_name'])) ?>;');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="student_id" value="<?= $s['id'] ?>">
                                        <button type="submit" name="delete_student" class="btn btn-danger btn-sm" aria-label="Διαγραφή" title="Διαγραφή">
                                            🗑️
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Modal: Add / Edit Student -->
<div id="studentModal" role="dialog" aria-modal="true" aria-labelledby="modalTitle" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center; overflow-y:auto; padding:20px;">
    <div style="background:#fff; border-radius:12px; max-width:680px; width:100%; padding:24px; box-shadow:0 10px 30px rgba(0,0,0,0.2); max-height:90vh; overflow-y:auto;">
        <h3 id="modalTitle" style="margin-bottom:16px; font-size:18px;">Στοιχεία Μαθητή</h3>

        <form method="POST" action="students.php" id="studentForm">
            <?= csrfField() ?>
            <input type="hidden" name="student_id" id="formStudentId" value="">

            <div class="form-grid form-grid-4">
                <div class="form-group">
                    <label>Α.Μ. *</label>
                    <input type="text" name="am" id="formAm" class="form-control" required placeholder="π.χ. 109">
                </div>
                <div class="form-group">
                    <label>Φύλο *</label>
                    <select name="gender" id="formGender" class="form-control" required>
                        <option value="male">👦 Άρρεν</option>
                        <option value="female">👧 Θήλυ</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Πατρώνυμο</label>
                    <input type="text" name="father_name" id="formFatherName" class="form-control" placeholder="π.χ. Γεώργιος">
                </div>
                <div class="form-group">
                    <label>Μητρώνυμο</label>
                    <input type="text" name="mother_name" id="formMotherName" class="form-control" placeholder="π.χ. Μαρία">
                </div>
            </div>

            <div class="form-grid form-grid-2">
                <div class="form-group">
                    <label>Επώνυμο *</label>
                    <input type="text" name="last_name" id="formLastName" class="form-control" required placeholder="π.χ. Παπαδόπουλος">
                </div>
                <div class="form-group">
                    <label>Όνομα *</label>
                    <input type="text" name="first_name" id="formFirstName" class="form-control" required placeholder="π.χ. Νικόλαος">
                </div>
            </div>

            <div class="form-grid form-grid-2">
                <div class="form-group">
                    <label>Γενική Επωνύμου <span style="font-size:11px; font-weight:normal; color:var(--text-muted);">(κενό = αυτόματο)</span></label>
                    <input type="text" name="last_name_gen" id="formLastNameGen" class="form-control" placeholder="π.χ. Παπαδόπουλου">
                </div>
                <div class="form-group">
                    <label>Γενική Ονόματος <span style="font-size:11px; font-weight:normal; color:var(--text-muted);">(κενό = αυτόματο)</span></label>
                    <input type="text" name="first_name_gen" id="formFirstNameGen" class="form-control" placeholder="π.χ. Νικολάου">
                </div>
            </div>

            <div class="form-group">
                <label>Email Μαθητή <span style="font-size:11px; font-weight:normal; color:var(--text-muted);">(Προαιρετικό)</span></label>
                <input type="email" name="student_email" id="formStudentEmail" class="form-control" placeholder="π.χ. student@sch.gr">
            </div>

            <hr style="border:0; border-top:1px solid var(--border); margin:18px 0;">

            <!-- 1st Guardian -->
            <div style="background:#f8fafc; border:1px solid var(--border); border-radius:8px; padding:12px; margin-bottom:14px;">
                <div style="font-weight:700; font-size:13px; margin-bottom:8px; color:var(--text-main);">👨‍👩‍👧 1ος Κηδεμόνας</div>
                <div class="form-grid form-grid-2 guardian-grid" style="margin-bottom:0;">
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Email 1ου Κηδεμόνα</label>
                        <input type="email" name="guardian_email" id="formGuardianEmail" class="form-control" placeholder="parent1@example.com">
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Τηλέφωνο 1ου Κηδεμόνα</label>
                        <input type="text" name="guardian_mobile" id="formGuardianMobile" class="form-control" placeholder="6970000000">
                    </div>
                </div>
            </div>

            <!-- 2nd Guardian -->
            <div style="background:#f8fafc; border:1px solid var(--border); border-radius:8px; padding:12px; margin-bottom:14px;">
                <div style="font-weight:700; font-size:13px; margin-bottom:8px; color:var(--text-main);">👨‍👩‍👦 2ος Κηδεμόνας</div>
                <div class="form-grid form-grid-2 guardian-grid" style="margin-bottom:0;">
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Email 2ου Κηδεμόνα</label>
                        <input type="email" name="guardian_email_2" id="formGuardianEmail2" class="form-control" placeholder="parent2@example.com">
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Τηλέφωνο 2ου Κηδεμόνα</label>
                        <input type="text" name="guardian_mobile_2" id="formGuardianMobile2" class="form-control" placeholder="6980000000">
                    </div>
                </div>
            </div>

            <!-- Sections Checkboxes -->
            <div class="form-group">
                <label>Τμήματα στα οποία ανήκει ο μαθητής</label>
                <div style="max-height:160px; overflow-y:auto; border:1px solid var(--border); border-radius:6px; padding:10px; background:#fff;">
                    <?php if (empty($allSections)): ?>
                        <div style="font-size:12px; color:var(--text-muted);">Δεν υπάρχουν ακόμη τμήματα.</div>
                    <?php else: ?>
                        <?php foreach ($allSections as $sec): ?>
                            <label style="display:flex; align-items:center; gap:8px; font-weight:normal; margin-bottom:6px; font-size:13px; cursor:pointer;">
                                <input type="checkbox" name="sections[]" value="<?= $sec['id'] ?>" class="modal-student-sec-check" id="sec_check_<?= $sec['id'] ?>">
                                <span><?= htmlspecialchars($sec['name']) ?> (Τάξη <?= htmlspecialchars($sec['grade_name']) ?>)</span>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                <button type="button" class="btn btn-secondary btn-sm" onclick="closeStudentModal()">Ακύρωση</button>
                <button type="submit" id="btnSubmitStudent" name="add_student" class="btn btn-primary btn-sm">Αποθήκευση Μαθητή</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openAddStudentModal() {
        document.getElementById('modalTitle').textContent = '➕ Προσθήκη Νέου Μαθητή';
        document.getElementById('formStudentId').value = '';
        document.getElementById('formAm').value = '';
        document.getElementById('formGender').value = 'male';
        document.getElementById('formLastName').value = '';
        document.getElementById('formFirstName').value = '';
        document.getElementById('formFatherName').value = '';
        document.getElementById('formMotherName').value = '';
        document.getElementById('formLastNameGen').value = '';
        document.getElementById('formFirstNameGen').value = '';
        document.getElementById('formStudentEmail').value = '';
        document.getElementById('formGuardianEmail').value = '';
        document.getElementById('formGuardianMobile').value = '';
        document.getElementById('formGuardianEmail2').value = '';
        document.getElementById('formGuardianMobile2').value = '';

        document.querySelectorAll('.modal-student-sec-check').forEach(cb => cb.checked = false);

        const btnSubmit = document.getElementById('btnSubmitStudent');
        btnSubmit.name = 'add_student';
        btnSubmit.textContent = 'Προσθήκη Μαθητή';

        document.getElementById('studentModal').style.display = 'flex';
        document.getElementById('studentModal').firstElementChild.scrollTop = 0;
    }

    function openEditStudentModal(student) {
        document.getElementById('modalTitle').textContent = '✏️ Επεξεργασία Μαθητή: ' + student.last_name + ' ' + student.first_name;
        document.getElementById('formStudentId').value = student.id;
        document.getElementById('formAm').value = student.am || '';
        document.getElementById('formGender').value = student.gender || 'male';
        document.getElementById('formLastName').value = student.last_name || '';
        document.getElementById('formFirstName').value = student.first_name || '';
        document.getElementById('formFatherName').value = student.father_name || '';
        document.getElementById('formMotherName').value = student.mother_name || '';
        document.getElementById('formLastNameGen').value = student.last_name_gen || '';
        document.getElementById('formFirstNameGen').value = student.first_name_gen || '';
        document.getElementById('formStudentEmail').value = student.student_email || '';
        document.getElementById('formGuardianEmail').value = student.guardian_email || '';
        document.getElementById('formGuardianMobile').value = student.guardian_mobile || '';
        document.getElementById('formGuardianEmail2').value = student.guardian_email_2 || '';
        document.getElementById('formGuardianMobile2').value = student.guardian_mobile_2 || '';

        const currentSecIds = (student.section_ids || '').split(',').map(s => s.trim());
        document.querySelectorAll('.modal-student-sec-check').forEach(cb => {
            cb.checked = currentSecIds.includes(cb.value);
        });

        const btnSubmit = document.getElementById('btnSubmitStudent');
        btnSubmit.name = 'edit_student';
        btnSubmit.textContent = 'Αποθήκευση Αλλαγών';

        document.getElementById('studentModal').style.display = 'flex';
        document.getElementById('studentModal').firstElementChild.scrollTop = 0;
    }

    function closeStudentModal() {
        document.getElementById('studentModal').style.display = 'none';
    }
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
