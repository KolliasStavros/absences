<?php
// admin/teachers.php - Teacher Management & Section Assignments
$pageTitle = 'Καθηγητές & Αναθέσεις';
require_once __DIR__ . '/header.php';
requireAdmin();
require_once __DIR__ . '/../includes/admin_accounts.php';

$db = getDB();
$activeAcademicYear = getActiveAcademicYear();
$activeYearId = $activeAcademicYear ? (int)$activeAcademicYear['id'] : 1;
$message = '';
$error = '';

// Handle all POST actions with CSRF verification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Μη έγκυρο αίτημα ασφαλείας (CSRF Token mismatch). Παρακαλώ ανανεώστε τη σελίδα.';
    } elseif (count(array_intersect(['add_teacher','update_assignments','set_teacher_password','resend_invitation','delete_teacher'], array_keys($_POST))) !== 1) {
        $error = 'Επιλέξτε μία ενέργεια κάθε φορά.';
    } else {
        // Add New Teacher
        if (isset($_POST['add_teacher'])) {
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $plainPassword = trim($_POST['password'] ?? '');
            $assignedSections = $_POST['sections'] ?? [];

            if (empty($name) || empty($email)) {
                $error = 'Παρακαλώ συμπληρώστε όνομα και email καθηγητή.';
            } else {
                try {
                    // Check if email already exists
                    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
                    $stmt->execute([$email]);
                    if ($stmt->fetch()) {
                        $error = 'Υπάρχει ήδη χρήστης με αυτό το email.';
                    } else {
                        $role = $_POST['role'] ?? 'teacher';
                        if (!is_string($role) || !in_array($role,['teacher','teacher_admin','admin'],true)) throw new Exception('Μη έγκυρος ρόλος.');
                        
                        $status = 'pending_activation';
                        $passwordHash = null;
                        if (!empty($plainPassword)) {
                            if (strlen($plainPassword) < 6) {
                                throw new Exception('Ο κωδικός πρόσβασης πρέπει να περιέχει τουλάχιστον 6 χαρακτήρες.');
                            }
                            $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);
                            $status = 'active';
                        }

                        $db->beginTransaction();
                        lockAdminAccounts($db);
                        $assignedSections = resolveRoleSections($db, $role, $assignedSections);
                        // Create user with selected role and password if provided
                        $stmt = $db->prepare("INSERT INTO users (name, email, role, status, password_hash) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$name, $email, $role, $status, $passwordHash]);
                        $teacherId = $db->lastInsertId();

                        // Assign sections for active academic year
                        $stmtAssign = $db->prepare("INSERT INTO teacher_sections (academic_year_id, teacher_id, section_id) VALUES (?, ?, ?)");
                        foreach ($assignedSections as $secId) {
                            $stmtAssign->execute([$activeYearId, $teacherId, (int)$secId]);
                        }

                        $db->commit();

                        if (!empty($plainPassword)) {
                            $message = "Ο εκπαιδευτικός καταχωρήθηκε επιτυχώς με τον κωδικό που ορίσατε!";
                        } else {
                            // Create invitation token and send email
                            $token = createPasswordResetToken($teacherId, 'invitation');
                            $sent = sendPasswordResetEmail(['name' => $name, 'email' => $email], $token, 'invitation');
                            $message = "Ο καθηγητής καταχωρήθηκε επιτυχώς! " . ($sent['success'] ? "Εστάλη email πρόσκλησης για ορισμό κωδικού." : "Σημείωση: Το email πρόσκλησης δεν στάλθηκε (ελέγξτε τις ρυθμίσεις SMTP).");
                        }
                    }
                } catch (Exception $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    $error = 'Σφάλμα κατά την καταχώρηση: ' . safeError($e);
                }
            }
        }

        // Update Existing Teacher Info & Sections
        if (isset($_POST['update_assignments'])) {
            $teacherId = (int)$_POST['teacher_id'];
            $teacherName = trim($_POST['teacher_name'] ?? '');
            $teacherEmail = trim($_POST['teacher_email'] ?? '');
            $assignedSections = $_POST['sections'] ?? [];

            try {
                $db->beginTransaction();
                $lockedAccounts = lockAdminAccounts($db);
                if (empty($teacherName) || !filter_var($teacherEmail, FILTER_VALIDATE_EMAIL)) throw new Exception('Συμπληρώστε όνομα και έγκυρο email.');
                if (!empty($teacherName) && !empty($teacherEmail)) {
                    // Check if email taken by someone else
                    $chk = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                    $chk->execute([$teacherEmail, $teacherId]);
                    if ($chk->fetch()) {
                        throw new Exception("Το email {$teacherEmail} χρησιμοποιείται ήδη από άλλον χρήστη.");
                    }
                    $teacherRole = $_POST['teacher_role'] ?? 'teacher';
                    if (!is_string($teacherRole)) throw new Exception('Μη έγκυρος ρόλος.');
                    assertAdminRoleChange($lockedAccounts,$teacherId,$teacherRole);
                    $previousRole = null;
                    foreach ($lockedAccounts as $account) if ((int)$account['id'] === $teacherId) $previousRole = $account['role'];
                    $db->prepare("UPDATE users SET name = ?, email = ?, role = ?, auth_version = auth_version + ? WHERE id = ?")->execute([$teacherName,$teacherEmail,$teacherRole,$previousRole !== $teacherRole ? 1 : 0,$teacherId]);
                }
                $assignedSections = resolveRoleSections($db, $teacherRole, $assignedSections);
                $db->prepare("DELETE FROM teacher_sections WHERE teacher_id = ? AND academic_year_id = ?")->execute([$teacherId, $activeYearId]);
                $stmtAssign = $db->prepare("INSERT INTO teacher_sections (academic_year_id, teacher_id, section_id) VALUES (?, ?, ?)");
                foreach ($assignedSections as $secId) {
                    $stmtAssign->execute([$activeYearId, $teacherId, (int)$secId]);
                }
                $db->commit();
                $message = 'Τα στοιχεία και οι αναθέσεις τμημάτων του καθηγητή ενημερώθηκαν επιτυχώς.';
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = 'Σφάλμα: ' . ($e instanceof DomainException ? $e->getMessage() : safeError($e));
            }
        }

        // Direct Set Teacher Password by Admin
        if (isset($_POST['set_teacher_password'])) {
            $teacherId = (int)$_POST['teacher_id'];
            $newPassword = trim($_POST['new_password'] ?? '');
            if (strlen($newPassword) < 6) {
                $error = 'Ο νέος κωδικός πρόσβασης πρέπει να περιέχει τουλάχιστον 6 χαρακτήρες.';
            } else {
                try {
                    $stmt = $db->prepare("SELECT name, email FROM users WHERE id = ? AND role IN ('teacher', 'teacher_admin', 'admin')");
                    $stmt->execute([$teacherId]);
                    $t = $stmt->fetch();
                    if (!$t) {
                        $error = 'Ο εκπαιδευτικός δεν βρέθηκε.';
                    } else {
                        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                        $db->beginTransaction();
                        $db->prepare("UPDATE users SET password_hash = ?, status = 'active', auth_version = auth_version + 1 WHERE id = ?")->execute([$hash, $teacherId]);
                        $db->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$teacherId]);
                        $db->commit();
                        $message = "Ο κωδικός πρόσβασης για τον/την εκπαιδευτικό {$t['name']} ορίστηκε επιτυχώς!";
                    }
                } catch (Exception $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    $error = 'Σφάλμα κατά τον ορισμό κωδικού: ' . safeError($e);
                }
            }
        }

        // Resend Invitation / Reset Link
        if (isset($_POST['resend_invitation'])) {
            $teacherId = (int)$_POST['teacher_id'];
            $stmt = $db->prepare("SELECT name, email, status FROM users WHERE id = ?");
            $stmt->execute([$teacherId]);
            $t = $stmt->fetch();
            if ($t) {
                $token = createPasswordResetToken($teacherId, 'invitation');
                $sent = sendPasswordResetEmail($t, $token, 'invitation');
                if ($sent['success']) {
                    $message = "Εστάλη νέο email πρόσκλησης στον/στην {$t['name']} ({$t['email']}).";
                } else {
                    $error = "Αποτυχία αποστολής email: " . ($sent['error'] ?? 'Άγνωστο σφάλμα');
                }
            }
        }

        // Delete Teacher - DATA-01: Protect historical attendance records from CASCADE deletion
        if (isset($_POST['delete_teacher'])) {
            $teacherId = (int)$_POST['teacher_id'];
            try {
                $db->beginTransaction();
                lockAdminAccounts($db);
                $stmtChk = $db->prepare("SELECT name FROM users WHERE id = ? AND role IN ('teacher', 'teacher_admin')");
                $stmtChk->execute([$teacherId]);
                $teacher = $stmtChk->fetch();

                if ($teacher) {
                    // Check if teacher has recorded attendance in the past
                    $stmtAtt = $db->prepare("SELECT COUNT(*) FROM attendance WHERE teacher_id = ?");
                    $stmtAtt->execute([$teacherId]);
                    $attCount = (int)$stmtAtt->fetchColumn();

                    if ($attCount > 0) {
                        // Deactivate user instead of deleting physically, so foreign key attendance.teacher_id is preserved!
                        $db->prepare("UPDATE users SET status = 'inactive', auth_version=auth_version+1 WHERE id = ?")->execute([$teacherId]);
                        $db->prepare("DELETE FROM teacher_sections WHERE teacher_id = ? AND academic_year_id = ?")->execute([$teacherId, $activeYearId]);
                        $message = "Ο/Η εκπαιδευτικός {$teacher['name']} διαθέτει ιστορικό {$attCount} καταχωρημένων απουσιών. Για τη διασφάλιση της νομιμότητας και της ακεραιότητας των ιστορικών δεδομένων, ο λογαριασμός απενεργοποιήθηκε αντί για οριστική διαγραφή.";
                    } else {
                        // No attendance records exist: safe to delete physically
                        $db->prepare("DELETE FROM users WHERE id = ? AND role IN ('teacher', 'teacher_admin')")->execute([$teacherId]);
                        $message = "Ο/Η εκπαιδευτικός {$teacher['name']} διαγράφηκε επιτυχώς.";
                    }
                } else {
                    throw new DomainException('Για διαγραφή διαχειριστή αφαιρέστε πρώτα τον ρόλο admin από την επεξεργασία λογαριασμού.');
                }
                $db->commit();
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = 'Σφάλμα κατά τη διαγραφή: ' . ($e instanceof DomainException ? $e->getMessage() : safeError($e));
            }
        }
    }
}

// Fetch all sections
$sectionsStmt = $db->query("
    SELECT s.id, s.name, g.name as grade_name 
    FROM sections s 
    JOIN grades g ON s.grade_id = g.id 
    ORDER BY g.name ASC, s.name ASC
");
$allSections = $sectionsStmt->fetchAll();
$allSectionsByGrade = [];
foreach ($allSections as $sec) {
    $allSectionsByGrade[$sec['grade_name']][] = $sec;
}

// Fetch all teachers with their assigned sections for the active academic year
$teachersStmt = $db->prepare("
    SELECT u.id, u.name, u.email, u.role, u.status, u.created_at, u.last_active_at,
           TIMESTAMPDIFF(SECOND, u.last_active_at, NOW()) as seconds_since_active,
           GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR ', ') as section_names,
           GROUP_CONCAT(s.id) as section_ids
    FROM users u
    LEFT JOIN teacher_sections ts ON (u.id = ts.teacher_id AND ts.academic_year_id = ?)
    LEFT JOIN sections s ON ts.section_id = s.id
    WHERE u.role IN ('teacher', 'teacher_admin', 'admin')
    GROUP BY u.id
    ORDER BY u.name ASC
");
$teachersStmt->execute([$activeYearId]);
$teachers = $teachersStmt->fetchAll();

// Helper to format human-readable online / last active status
function formatTeacherActivity($seconds, $timestamp) {
    if ($seconds === null || !$timestamp) {
        return ['online' => false, 'text' => 'Χωρίς δραστηριότητα'];
    }
    if ($seconds <= 300) {
        return ['online' => true, 'text' => 'Συνδεδεμένος τώρα'];
    }
    if ($seconds < 3600) {
        $m = max(1, (int)floor($seconds / 60));
        return ['online' => false, 'text' => "Πριν {$m} " . ($m === 1 ? 'λεπτό' : 'λεπτά')];
    }
    if ($seconds < 86400) {
        $h = (int)floor($seconds / 3600);
        return ['online' => false, 'text' => "Πριν {$h} " . ($h === 1 ? 'ώρα' : 'ώρες')];
    }
    $d = (int)floor($seconds / 86400);
    if ($d === 1) {
        return ['online' => false, 'text' => 'Εχθές ' . date('H:i', strtotime($timestamp))];
    }
    if ($d < 7) {
        return ['online' => false, 'text' => "Πριν {$d} μέρες"];
    }
    return ['online' => false, 'text' => date('d/m/Y H:i', strtotime($timestamp))];
}
?>

<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Header Banner with Import Button -->
<div class="card" style="margin-bottom:20px; padding:18px 20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;">
        <div>
            <h1 style="font-size:20px; font-weight:700; margin:0 0 4px 0; display:flex; align-items:center; gap:8px;">
                <span>👥</span> Διαχείριση Καθηγητών &amp; Αναθέσεων Τμημάτων
            </h1>
            <p style="color:var(--text-muted); font-size:13px; margin:0;">
                Καταχώρηση καθηγητών, ορισμός δικαιωμάτων σύνδεσης και ανάθεση τμημάτων για το σχολικό έτος <strong><?= htmlspecialchars($activeAcademicYear['name'] ?? '2026-2027') ?></strong>.
            </p>
        </div>
        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <button type="button" onclick="openAddTeacherModal()" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:6px; padding:9px 16px; font-weight:700;">
                <span>➕</span> Προσθήκη Καθηγητή
            </button>
            <a href="import.php?type=teachers" class="btn btn-secondary" style="display:inline-flex; align-items:center; gap:6px; padding:9px 16px; font-weight:700; text-decoration:none;">
                <span>📥</span> Εισαγωγή από MySchool (Excel)
            </a>
        </div>
    </div>
</div>

<style>
.status-indicator {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    font-size: 12px;
    font-weight: 600;
}
.pulse-dot {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    display: inline-block;
}
.pulse-dot.online {
    background: #10b981;
    box-shadow: 0 0 0 rgba(16, 185, 129, 0.4);
    animation: teacherPulse 2s infinite;
}
.pulse-dot.offline {
    background: #94a3b8;
}
.pulse-dot.pending {
    background: #f59e0b;
}
@keyframes teacherPulse {
    0% {
        box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
    }
    70% {
        box-shadow: 0 0 0 7px rgba(16, 185, 129, 0);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(16, 185, 129, 0);
    }
}
</style>

<!-- Full-Width Teachers List Card -->
<div class="card" style="width:100%;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:12px;">
        <h3 class="card-title" style="margin:0; font-size:18px;">
            Λίστα Καθηγητών (<?= count($teachers) ?>)
        </h3>
        <div style="display:flex; gap:8px; align-items:center;">
            <button type="button" onclick="openAddTeacherModal()" class="btn btn-primary btn-sm" style="display:inline-flex; align-items:center; gap:5px; font-weight:600;">
                <span>➕</span> Νέος Καθηγητής
            </button>
            <span class="badge badge-success" style="font-size:12px;" title="Τρέχον Σχολικό Έτος">
                Αναθέσεις Έτους <?= htmlspecialchars($activeAcademicYear['name'] ?? '') ?>
            </span>
        </div>
    </div>

        <?php if (empty($teachers)): ?>
            <div style="text-align:center; padding:30px; color:var(--text-muted);">
                Δεν έχουν καταχωρηθεί ακόμη καθηγητές.
            </div>
        <?php else: ?>
            <?php $totalSectionsCount = count($allSections); ?>
            <div class="table-responsive">
                <table class="table teachers-table">
                    <thead>
                        <tr>
                            <th>Ονοματεπώνυμο</th>
                            <th>Ρόλος</th>
                            <th>Email</th>
                            <th>Κατάσταση</th>
                            <th>Ανατεθειμένα Τμήματα</th>
                            <th>Ενέργειες</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($teachers as $t): ?>
                            <?php
                            $act = formatTeacherActivity($t['seconds_since_active'], $t['last_active_at']);
                            $assignedIds = array_filter(explode(',', $t['section_ids'] ?? ''));
                            $assignedCount = count($assignedIds);
                            $allAssigned = ($totalSectionsCount > 0 && $assignedCount >= $totalSectionsCount);
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($t['name']) ?></strong></td>
                                <td>
                                    <?php if ($t['role'] === 'admin'): ?>
                                        <span class="badge badge-gray">👑 Διαχειριστής Συστήματος</span>
                                    <?php elseif ($t['role'] === 'teacher_admin'): ?>
                                        <span class="badge" style="background:#ede9fe; color:#6d28d9; border:1px solid #ddd6fe; font-size:11px;">👑 Καθηγητής - Διαχειριστής</span>
                                    <?php else: ?>
                                        <span class="badge badge-gray" style="font-size:11px;">👨‍🏫 Καθηγητής</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-size:13px; color:var(--text-muted);"><?= htmlspecialchars($t['email']) ?></span>
                                </td>
                                <td>
                                    <div style="display:flex; flex-direction:column; gap:3px;">
                                        <?php if ($act['online']): ?>
                                            <span class="status-indicator" style="color:#047857;" title="Ενεργός: <?= htmlspecialchars($t['last_active_at'] ?? '') ?>">
                                                <span class="pulse-dot online"></span>
                                                <span>Συνδεδεμένος</span>
                                            </span>
                                        <?php elseif ($t['status'] === 'pending_activation'): ?>
                                            <span class="status-indicator" style="color:#b45309;" title="Αναμονή αρχικού ορισμού κωδικού">
                                                <span class="pulse-dot pending"></span>
                                                <span>Αναμονή Κωδικού</span>
                                            </span>
                                        <?php elseif ($t['status'] === 'inactive'): ?>
                                            <span class="status-indicator" style="color:#b91c1c;">
                                                <span class="pulse-dot offline" style="background:#ef4444;"></span>
                                                <span>Ανενεργός</span>
                                            </span>
                                        <?php else: ?>
                                            <span class="status-indicator" style="color:#64748b;" title="Τελευταία ενέργεια: <?= $t['last_active_at'] ? htmlspecialchars($t['last_active_at']) : 'Καμία' ?>">
                                                <span class="pulse-dot offline"></span>
                                                <span>Εκτός σύνδεσης</span>
                                            </span>
                                            <?php if ($t['last_active_at']): ?>
                                                <span style="font-size:11px; color:var(--text-muted); padding-left:16px;">
                                                    <?= htmlspecialchars($act['text']) ?>
                                                </span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if (in_array($t['role'], ['admin','teacher_admin'], true)): ?>
                                        <span class="badge badge-info" title="Αυτόματη πρόσβαση βάσει ρόλου, μαζί με τα νέα τμήματα">Όλα τα τμήματα (αυτόματα)</span>
                                    <?php elseif ($allAssigned): ?>
                                        <span class="badge" style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; font-weight:700; font-size:12px; display:inline-flex; align-items:center; gap:5px; padding:4px 9px;" title="<?= htmlspecialchars($t['section_names']) ?>">
                                            🌐 Όλα τα τμήματα (<?= $assignedCount ?>)
                                        </span>
                                    <?php elseif ($assignedCount > 6): ?>
                                        <?php
                                        $namesArr = explode(', ', $t['section_names']);
                                        $preview = array_slice($namesArr, 0, 4);
                                        ?>
                                        <span style="font-size:13px;"><?= htmlspecialchars(implode(', ', $preview)) ?></span>
                                        <span class="badge badge-gray" style="font-size:11px; cursor:help; margin-left:3px;" title="<?= htmlspecialchars($t['section_names']) ?>">
                                            +<?= ($assignedCount - 4) ?> ακόμη
                                        </span>
                                    <?php elseif ($t['section_names']): ?>
                                        <span style="font-size:13px;"><?= htmlspecialchars($t['section_names']) ?></span>
                                    <?php else: ?>
                                        <span style="font-size:12px; color:var(--text-muted);">Κανένα τμήμα</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display:flex; gap:6px;">
                                        <button type="button" class="btn btn-secondary btn-sm" onclick="editTeacherSections(<?= htmlspecialchars(json_encode($t)) ?>)" aria-label="Επεξεργασία λογαριασμού και αναθέσεων" title="Επεξεργασία στοιχείων, ρόλου και αναθέσεων">
                                            ✏️ Επεξεργασία
                                        </button>
                                        <button type="button" class="btn btn-secondary btn-sm" onclick="openPasswordModal(<?= htmlspecialchars(json_encode($t)) ?>)" aria-label="Ορισμός κωδικού" title="Ορισμός νέου κωδικού πρόσβασης">
                                            🔑 Κωδικός
                                        </button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Διαγραφή αυτού του καθηγητή;');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="teacher_id" value="<?= $t['id'] ?>">
                                            <button <?= $t['role'] === 'admin' ? 'disabled' : '' ?> type="submit" name="delete_teacher" class="btn btn-danger btn-sm" aria-label="Διαγραφή" title="Διαγραφή">
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

<!-- Modal for Adding New Teacher -->
<div id="addTeacherModal" class="modal" style="display:none;">
    <div class="modal-content" style="background:var(--bg-card); border-radius:12px; max-width:520px; width:92%; padding:24px; box-shadow:var(--shadow-lg); border:1px solid var(--border);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
            <h3 style="margin:0; font-size:18px; font-weight:700;">➕ Προσθήκη Καθηγητή</h3>
            <button type="button" onclick="closeAddTeacherModal()" aria-label="Κλείσιμο" title="Κλείσιμο" style="background:none; border:none; font-size:22px; cursor:pointer; color:var(--text-muted);">&times;</button>
        </div>
        <p style="font-size:13px; color:var(--text-muted); margin-bottom:16px;">
            Μπορείτε να ορίσετε απευθείας κωδικό ή να αφήσετε το πεδίο κενό ώστε να σταλεί email πρόσκλησης.
        </p>

        <form method="POST" action="teachers.php">
            <?= csrfField() ?>
            <div class="form-group" style="margin-bottom:12px;">
                <label style="font-size:12px; font-weight:600; display:block; margin-bottom:4px;">Ονοματεπώνυμο</label>
                <input type="text" name="name" class="form-control" placeholder="π.χ. Παπαδόπουλος Νικόλαος" required style="font-size:13px;">
            </div>

            <div class="form-group" style="margin-bottom:12px;">
                <label style="font-size:12px; font-weight:600; display:block; margin-bottom:4px;">Email (Σύνδεση &amp; Ειδοποιήσεις)</label>
                <input type="email" name="email" class="form-control" placeholder="π.χ. npapad@sch.gr" required style="font-size:13px;">
            </div>

            <div class="form-group" style="margin-bottom:12px;">
                <label style="font-size:12px; font-weight:600; display:block; margin-bottom:4px;">Κωδικός Πρόσβασης (Προαιρετικό)</label>
                <div style="display:flex; gap:6px;">
                    <input type="text" name="password" id="addTeacherInputPass" class="form-control" placeholder="Κενό = αυτόματο email πρόσκλησης" autocomplete="new-password" style="font-size:13px; font-family:monospace;">
                    <button type="button" class="btn btn-secondary" onclick="generateRandomAddTeacherPassword()" title="Δημιουργία τυχαίου κωδικού" style="white-space:nowrap; padding:0 12px; font-size:12px;">🎲 Τυχαίος</button>
                </div>
                <small style="color:var(--text-muted); font-size:11px; display:block; margin-top:3px;">
                    Αν συμπληρώσετε κωδικό (≥6 χαρακτήρες), ο λογαριασμός ενεργοποιείται αμέσως.
                </small>
            </div>

            <div class="form-group" style="margin-bottom:12px;">
                <label style="font-size:12px; font-weight:600; display:block; margin-bottom:4px;">Ρόλος &amp; Δικαιώματα Λογαριασμού</label>
                <select name="role" class="form-control" style="font-size:13px; font-weight:600;" onchange="syncRoleSections('#addTeacherModal')">
                    <option value="teacher">👨‍🏫 Καθηγητής (Μόνο στις δικές του απουσίες)</option>
                    <option value="teacher_admin">👑 Καθηγητής - Διαχειριστής (Όλες οι απουσίες)</option>
                    <option value="admin">👑 Διαχειριστής Συστήματος (Πλήρης πρόσβαση)</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:16px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                    <label style="margin:0; font-weight:600; font-size:12px;">Ανάθεση Τμημάτων</label>
                    <div style="display:flex; gap:6px;">
                        <button type="button" class="btn btn-secondary btn-sm" style="padding:2px 8px; font-size:11px;" onclick="toggleAllSections('#addTeacherModal', true)">✅ Όλα</button>
                        <button type="button" class="btn btn-secondary btn-sm" style="padding:2px 8px; font-size:11px;" onclick="toggleAllSections('#addTeacherModal', false)">❌ Καθαρισμός</button>
                    </div>
                </div>
                <div style="max-height:180px; overflow-y:auto; border:1px solid var(--border); border-radius:6px; padding:10px; background:var(--bg-main);">
                    <?php if (empty($allSections)): ?>
                        <div style="font-size:12px; color:var(--text-muted);">Δεν υπάρχουν ακόμη τμήματα. Εισάγετέ τα από την «Εισαγωγή Excel».</div>
                    <?php else: ?>
                        <?php foreach ($allSectionsByGrade as $gName => $gSecs): ?>
                            <div style="font-weight:700; color:var(--primary); font-size:12px; margin-top:8px; margin-bottom:4px; padding-bottom:2px; border-bottom:1px solid var(--border);">
                                Τάξη <?= htmlspecialchars($gName) ?>
                            </div>
                            <?php foreach ($gSecs as $sec): ?>
                                <label style="display:flex; align-items:center; gap:8px; font-weight:normal; margin-bottom:5px; font-size:13px; cursor:pointer;">
                                    <input type="checkbox" name="sections[]" value="<?= $sec['id'] ?>">
                                    <span><?= htmlspecialchars($sec['name']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-secondary btn-sm" onclick="closeAddTeacherModal()">Ακύρωση</button>
                <button type="submit" name="add_teacher" class="btn btn-primary btn-sm" style="font-weight:700;">
                    Αποθήκευση Καθηγητή
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal for Editing Teacher Info & Sections -->
<div id="assignModal" class="modal" style="display:none;">
    <div class="modal-content" style="background:var(--bg-card); border-radius:12px; max-width:500px; width:92%; padding:24px; box-shadow:var(--shadow-lg); border:1px solid var(--border);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
            <h3 id="modalTeacherName" style="margin:0; font-size:17px; font-weight:700;">Επεξεργασία Καθηγητή</h3>
            <button type="button" onclick="closeAssignModal()" aria-label="Κλείσιμο" title="Κλείσιμο" style="background:none; border:none; font-size:22px; cursor:pointer; color:var(--text-muted);">&times;</button>
        </div>
        <form method="POST" action="teachers.php">
            <?= csrfField() ?>
            <input type="hidden" name="teacher_id" id="modalTeacherId">
            <div class="form-group" style="margin-bottom:12px;">
                <label style="font-size:12px; font-weight:600; display:block; margin-bottom:4px;">Ονοματεπώνυμο</label>
                <input type="text" name="teacher_name" id="modalTeacherInputName" class="form-control" required style="font-size:13px;">
            </div>
            <div class="form-group" style="margin-bottom:14px;">
                <label style="font-size:12px; font-weight:600; display:block; margin-bottom:4px;">Email Καθηγητή (Σύνδεση &amp; Ειδοποιήσεις)</label>
                <input type="email" name="teacher_email" id="modalTeacherInputEmail" class="form-control" required style="font-size:13px;">
            </div>
            <div class="form-group" style="margin-bottom:14px;">
                <label style="font-size:12px; font-weight:600; display:block; margin-bottom:4px;">Ρόλος &amp; Δικαιώματα</label><small>Ο Διαχειριστής Συστήματος έχει πλήρη πρόσβαση. Η αλλαγή ρόλου απαιτεί νέα σύνδεση. Ο τελευταίος ενεργός διαχειριστής προστατεύεται.</small>
                <select name="teacher_role" id="modalTeacherInputRole" class="form-control" style="font-size:13px; font-weight:600;" onchange="syncRoleSections('#assignModal')">
                    <option value="teacher">👨‍🏫 Καθηγητής (Μόνο στις δικές του απουσίες)</option>
                    <option value="teacher_admin">👑 Καθηγητής - Διαχειριστής (Όλες οι απουσίες)</option>
                    <option value="admin">👑 Διαχειριστής Συστήματος (Πλήρης πρόσβαση)</option>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:14px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                    <label style="font-size:12px; font-weight:600; margin:0;">Ανάθεση Τμημάτων</label>
                    <div style="display:flex; gap:6px;">
                        <button type="button" class="btn btn-secondary btn-sm" style="padding:2px 8px; font-size:11px;" onclick="toggleAllSections('#assignModal', true)">✅ Όλα</button>
                        <button type="button" class="btn btn-secondary btn-sm" style="padding:2px 8px; font-size:11px;" onclick="toggleAllSections('#assignModal', false)">❌ Καθαρισμός</button>
                    </div>
                </div>
                <div style="max-height:220px; overflow-y:auto; border:1px solid var(--border); border-radius:6px; padding:12px; background:var(--bg-main);">
                    <?php foreach ($allSectionsByGrade as $gName => $gSecs): ?>
                        <div style="font-weight:700; color:var(--primary); font-size:12px; margin-top:8px; margin-bottom:4px; padding-bottom:2px; border-bottom:1px solid var(--border);">
                            Τάξη <?= htmlspecialchars($gName) ?>
                        </div>
                        <?php foreach ($gSecs as $sec): ?>
                            <label style="display:flex; align-items:center; gap:8px; font-weight:normal; margin-bottom:6px; font-size:13px; cursor:pointer;">
                                <input type="checkbox" name="sections[]" value="<?= $sec['id'] ?>" class="modal-section-check" id="modal_sec_<?= $sec['id'] ?>">
                                <span><?= htmlspecialchars($sec['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:16px;">
                <button type="button" class="btn btn-secondary btn-sm" onclick="closeAssignModal()">Ακύρωση</button>
                <button type="submit" name="update_assignments" class="btn btn-primary btn-sm">Αποθήκευση Αλλαγών</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal for Setting Teacher Password Directly -->
<div id="passwordModal" class="modal" style="display:none;">
    <div class="modal-content" style="background:var(--bg-card); border-radius:12px; max-width:440px; width:92%; padding:24px; box-shadow:var(--shadow-lg); border:1px solid var(--border);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <h3 id="pwdModalTitle" style="margin:0; font-size:17px; font-weight:700;">🔑 Ορισμός Κωδικού</h3>
            <button type="button" onclick="closePasswordModal()" aria-label="Κλείσιμο" title="Κλείσιμο" style="background:none; border:none; font-size:22px; cursor:pointer; color:var(--text-muted);">&times;</button>
        </div>
        <p id="pwdModalTeacherDesc" style="font-size:13px; color:var(--text-muted); margin-bottom:14px;"></p>
        <form method="POST" action="teachers.php">
            <?= csrfField() ?>
            <input type="hidden" name="teacher_id" id="pwdModalTeacherId">
            <div class="form-group" style="margin-bottom:12px;">
                <label style="font-size:12px; font-weight:600; display:block; margin-bottom:4px;">Νέος Κωδικός Πρόσβασης</label>
                <div style="display:flex; gap:6px;">
                    <input type="text" name="new_password" id="pwdModalInputPass" class="form-control" placeholder="Τουλάχιστον 6 χαρακτήρες" required minlength="6" style="font-size:14px; font-family:monospace; letter-spacing:1px;">
                    <button type="button" class="btn btn-secondary" onclick="generateRandomPassword()" title="Δημιουργία τυχαίου κωδικού" style="white-space:nowrap; padding:0 12px; font-size:12px;">🎲 Τυχαίος</button>
                </div>
                <small style="color:var(--text-muted); font-size:11px; display:block; margin-top:4px;">
                    Ο κωδικός θα αποθηκευτεί άμεσα και ο λογαριασμός ενεργοποιείται αυτόματα.
                </small>
            </div>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:20px; flex-wrap:wrap; gap:10px;">
                <button type="button" class="btn btn-secondary btn-sm" style="font-size:12px;" onclick="resendInviteFromModal()">📧 Αποστολή Link Email</button>
                <div style="display:flex; gap:8px;">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="closePasswordModal()">Ακύρωση</button>
                    <button type="submit" name="set_teacher_password" class="btn btn-primary btn-sm" style="font-weight:700;">💾 Αποθήκευση</button>
                </div>
            </div>
        </form>
        <form id="resendInviteForm" method="POST" action="teachers.php" style="display:none;">
            <?= csrfField() ?>
            <input type="hidden" name="teacher_id" id="resendInviteTeacherId">
            <input type="hidden" name="resend_invitation" value="1">
        </form>
    </div>
</div>

<script>
    const roleSectionSelections = new WeakMap();
    function syncRoleSections(containerSelector, reset = false) {
        const container = document.querySelector(containerSelector);
        if (!container) return;
        const role = container.querySelector('select[name="role"], select[name="teacher_role"]').value;
        const allAccess = role === 'admin' || role === 'teacher_admin';
        const boxes = [...container.querySelectorAll('input[name="sections[]"]')];
        let state = roleSectionSelections.get(container);
        if (!state || reset) state = { all: false, selected: new Set() };
        if (allAccess) {
            if (!state.all) state.selected = new Set(reset ? [] : boxes.filter(cb => cb.checked).map(cb => cb.value));
            boxes.forEach(cb => { cb.checked = true; cb.disabled = true; });
        } else {
            boxes.forEach(cb => {
                if (state.all) cb.checked = state.selected.has(cb.value);
                cb.disabled = false;
            });
        }
        state.all = allAccess;
        roleSectionSelections.set(container, state);
        container.querySelectorAll('button[onclick^="toggleAllSections"]').forEach(btn => { btn.disabled = allAccess; });
        let note = container.querySelector('[data-section-access-note]');
        if (!note) {
            note = document.createElement('p');
            note.setAttribute('data-section-access-note', '');
            note.style.fontSize = '12px';
            note.style.color = 'var(--text-muted)';
            boxes[0]?.closest('.form-group').appendChild(note);
        }
        note.textContent = allAccess ? 'Αυτόματη πρόσβαση σε όλα τα τμήματα, ακόμη και σε όσα προστεθούν αργότερα. Δεν απαιτείται χειροκίνητη ανάθεση.' : 'Επιλέξτε τα τμήματα που ανατίθενται στον καθηγητή.';
    }

    function toggleAllSections(containerSelector, checked) {
        const container = document.querySelector(containerSelector);
        if (!container) return;
        if (roleSectionSelections.get(container)?.all) return;
        container.querySelectorAll('input[type="checkbox"][name="sections[]"]').forEach(cb => {
            cb.checked = checked;
        });
    }

    function openAddTeacherModal() {
        document.getElementById('addTeacherInputPass').value = '';
        syncRoleSections('#addTeacherModal');
        document.getElementById('addTeacherModal').style.display = 'flex';
    }

    function closeAddTeacherModal() {
        document.getElementById('addTeacherModal').style.display = 'none';
    }

    function generateRandomAddTeacherPassword() {
        const chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        let pass = '';
        for (let i = 0; i < 8; i++) {
            pass += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        const input = document.getElementById('addTeacherInputPass');
        input.value = pass;
        input.select();
    }

    function editTeacherSections(teacher) {
        document.getElementById('modalTeacherId').value = teacher.id;
        document.getElementById('modalTeacherName').textContent = 'Επεξεργασία: ' + teacher.name;
        document.getElementById('modalTeacherInputName').value = teacher.name;
        document.getElementById('modalTeacherInputEmail').value = teacher.email;
        if (document.getElementById('modalTeacherInputRole')) {
            document.getElementById('modalTeacherInputRole').value = teacher.role || 'teacher';
        }

        const currentIds = (teacher.section_ids || '').split(',').map(id => id.trim());
        document.querySelectorAll('.modal-section-check').forEach(cb => {
            cb.checked = currentIds.includes(cb.value);
        });

        syncRoleSections('#assignModal', true);
        document.getElementById('assignModal').style.display = 'flex';
    }

    function closeAssignModal() {
        document.getElementById('assignModal').style.display = 'none';
    }

    function openPasswordModal(teacher) {
        document.getElementById('pwdModalTeacherId').value = teacher.id;
        document.getElementById('resendInviteTeacherId').value = teacher.id;
        document.getElementById('pwdModalTitle').textContent = '🔑 Ορισμός Κωδικού: ' + teacher.name;
        document.getElementById('pwdModalTeacherDesc').textContent = 'Ορίστε νέο κωδικό πρόσβασης για τον/την ' + teacher.name + ' (' + teacher.email + ').';
        document.getElementById('pwdModalInputPass').value = '';
        document.getElementById('passwordModal').style.display = 'flex';
    }

    function closePasswordModal() {
        document.getElementById('passwordModal').style.display = 'none';
    }

    function generateRandomPassword() {
        const chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        let pass = '';
        for (let i = 0; i < 8; i++) {
            pass += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        const input = document.getElementById('pwdModalInputPass');
        input.value = pass;
        input.select();
    }

    function resendInviteFromModal() {
        if (confirm('Θέλετε να σταλεί email στον καθηγητή με σύνδεσμο για να ορίσει μόνος του τον κωδικό;')) {
            document.getElementById('resendInviteForm').submit();
        }
    }

    window.onclick = function(e) {
        const addModal = document.getElementById('addTeacherModal');
        if (e.target === addModal) closeAddTeacherModal();
        const assignModal = document.getElementById('assignModal');
        if (e.target === assignModal) closeAssignModal();
        const pwdModal = document.getElementById('passwordModal');
        if (e.target === pwdModal) closePasswordModal();
    };
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
