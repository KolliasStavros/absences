<?php
// admin/sections.php - Section & Grade Management (CRUD)
$pageTitle = 'Τμήματα & Τάξεις';
require_once __DIR__ . '/header.php';

$db = getDB();
require_once __DIR__.'/../includes/co_teaching.php';
require_once __DIR__.'/../includes/request_validation.php';
$activeYearId=(int)getActiveAcademicYear()['id'];
$coGroups=coTeachingGroups($db);
$message = '';
$error = '';

// Handle all POST actions with CSRF verification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Μη έγκυρο αίτημα ασφαλείας (CSRF Token mismatch). Παρακαλώ ανανεώστε τη σελίδα.';
    } else {
        if (isset($_POST['save_co_teaching'])) {
            try {
                $id=schoolOptionalRequestId($_POST['co_section_id']??null);
                $grade=schoolRequestId($_POST['co_grade_id']??null);
                $name=$_POST['co_name']??null;$values=$_POST['co_sources']??null;
                if($id===null||$grade===null||!is_string($name)||!is_array($values))throw new DomainException('Ελέγξτε όνομα, τάξη και τμήματα.');
                $sources=[];foreach($values as $value){$source=schoolRequestId($value);if($source===null)throw new DomainException('Μη έγκυρο τμήμα.');$sources[]=$source;}
                coTeachingSave($db,$activeYearId,$id,$grade,$name,$sources);
                $message='Η συνδιδασκαλία αποθηκεύτηκε. Οι μαθητές αντλούνται αυτόματα από τα επιλεγμένα τμήματα. Ορίστε αναθέσεις καθηγητών για το νέο τμήμα.';
                $coGroups=coTeachingGroups($db);
            }catch(Throwable $e){$error=$e instanceof DomainException?$e->getMessage():'Δεν αποθηκεύτηκε η συνδιδασκαλία. Ελέγξτε μήπως υπάρχει ήδη το ίδιο όνομα στην τάξη.';}
        }

        // 1. Handle Add Section
        if (isset($_POST['add_section'])) {
            $name = trim($_POST['name'] ?? '');
            $gradeId = (int)($_POST['grade_id'] ?? 0);

            if (empty($name) || $gradeId <= 0) {
                $error = 'Παρακαλώ συμπληρώστε όνομα τμήματος και επιλέξτε τάξη.';
            } else {
                try {
                    $stmt = $db->prepare("INSERT INTO sections (grade_id, name) VALUES (?, ?)");
                    $stmt->execute([$gradeId, $name]);
                    $message = "Το τμήμα «{$name}» δημιουργήθηκε επιτυχώς!";
                } catch (Exception $e) {
                    $error = "Υπάρχει ήδη τμήμα με αυτό το όνομα στην ίδια τάξη.";
                }
            }
        }

        // 2. Handle Edit Section
        if (isset($_POST['edit_section'])) {
            $secId = (int)$_POST['section_id'];
            $name = trim($_POST['name'] ?? '');
            $gradeId = (int)($_POST['grade_id'] ?? 0);

            if ($secId <= 0 || empty($name) || $gradeId <= 0) {
                $error = 'Παρακαλώ συμπληρώστε όλα τα πεδία.';
            } else {
                try {
                    if(isset($coGroups[$secId]))throw new DomainException('Χρησιμοποιήστε την επεξεργασία συνδιδασκαλίας.');
                    foreach($coGroups as $target=>$sources)if(in_array($secId,$sources,true)){
                        $check=$db->prepare('SELECT grade_id FROM sections WHERE id=?');$check->execute([$target]);
                        if((int)$check->fetchColumn()!==$gradeId)throw new DomainException('Δεν αλλάζει η τάξη τμήματος που συμμετέχει σε συνδιδασκαλία άλλης τάξης.');
                    }
                    $stmt = $db->prepare("UPDATE sections SET name = ?, grade_id = ? WHERE id = ?");
                    $stmt->execute([$name, $gradeId, $secId]);
                    $message = "Το τμήμα ενημερώθηκε επιτυχώς σε «{$name}»!";
                } catch (Exception $e) {
                    $error = $e instanceof DomainException ? $e->getMessage() : "Σφάλμα ενημέρωσης: πιθανόν υπάρχει ήδη τμήμα με αυτό το όνομα στην ίδια τάξη.";
                }
            }
        }

        // Preserve attendance and source compositions on deletion.
        if (isset($_POST['delete_section'])) {
            try {
                $id=schoolRequestId($_POST['section_id']??null);if($id===null)throw new DomainException('Μη έγκυρο τμήμα.');
                coTeachingDelete($db,$activeYearId,$id);$coGroups=coTeachingGroups($db);$message='Το τμήμα διαγράφηκε.';
            }catch(Throwable $e){$error=$e instanceof DomainException?$e->getMessage():'Δεν ολοκληρώθηκε η διαγραφή.';}
        }

        // 4. Handle Add Grade
        if (isset($_POST['add_grade'])) {
            $gradeName = trim($_POST['grade_name'] ?? '');
            if (empty($gradeName)) {
                $error = 'Παρακαλώ εισάγετε όνομα τάξης.';
            } else {
                try {
                    $stmt = $db->prepare("INSERT INTO grades (name) VALUES (?)");
                    $stmt->execute([$gradeName]);
                    $message = "Η τάξη «{$gradeName}» δημιουργήθηκε επιτυχώς!";
                } catch (Exception $e) {
                    $error = "Υπάρχει ήδη τάξη με αυτό το όνομα.";
                }
            }
        }

        // 5. Handle Delete Grade
        if (isset($_POST['delete_grade'])) {
            $gradeId = (int)$_POST['grade_id'];
            try {
                $secCount = $db->prepare("SELECT COUNT(*) FROM sections WHERE grade_id = ?");
                $secCount->execute([$gradeId]);
                if ($secCount->fetchColumn() > 0) {
                    $error = 'Δεν μπορείτε να διαγράψετε τάξη που περιέχει τμήματα. Διαγράψτε πρώτα τα τμήματά της.';
                } else {
                    $db->prepare("DELETE FROM grades WHERE id = ?")->execute([$gradeId]);
                    $message = 'Η τάξη διαγράφηκε επιτυχώς.';
                }
            } catch (Exception $e) {
                $error = 'Σφάλμα διαγραφής τάξης: ' . safeError($e);
            }
        }
    }
}

// Fetch all grades
$activeAcademicYear = getActiveAcademicYear();
$activeYearId = $activeAcademicYear ? (int)$activeAcademicYear['id'] : 1;
$grades = $db->query("SELECT * FROM grades ORDER BY name ASC")->fetchAll();

$sectionMembershipSql=coTeachingMembershipSql($coGroups);

// Fetch sections with student count & teachers for the active academic year
$sectionsQuery = "
    SELECT 
        s.id,
        s.name as section_name,
        g.id as grade_id,
        g.name as grade_name,
        COUNT(DISTINCT stu.id) as students_count,
        GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') as teachers_list
    FROM sections s
    JOIN grades g ON s.grade_id = g.id
    LEFT JOIN {$sectionMembershipSql} ss ON s.id = ss.section_id
    LEFT JOIN students stu ON (ss.student_id = stu.id AND stu.academic_year_id = ?)
    LEFT JOIN teacher_sections ts ON (s.id = ts.section_id AND ts.academic_year_id = ?)
    LEFT JOIN users u ON ts.teacher_id = u.id
    GROUP BY s.id
    ORDER BY g.name ASC, s.name ASC
";
$stmtSections = $db->prepare($sectionsQuery);
$stmtSections->execute([$activeYearId, $activeYearId]);
$sections = $stmtSections->fetchAll();
?>

<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="sections-layout" style="display:grid; grid-template-columns: 2.2fr 1fr; gap:24px;">
    <!-- Sections Table Card -->
    <div class="card">
        <div class="card-title">
            <span>🏫 Σχολικά Τμήματα (<?= count($sections) ?>)</span>
            <div style="display:flex;flex-wrap:wrap;gap:8px;">
            <button type="button" class="btn btn-primary btn-sm" onclick="openAddSectionModal()">
                ➕ Νέο Τμήμα
            </button>
            <button type="button" class="btn btn-primary btn-sm" onclick="openCoTeachingModal()">Συνδιδασκαλίες</button>
            </div>
        </div>

        <p style="font-size:13px; color:var(--text-muted); margin-bottom:16px;">
            Διαχειριστείτε όλα τα τμήματα του σχολείου (γενικής παιδείας, ξένων γλωσσών, προσανατολισμού, ενισχυτικής κ.λπ.).
        </p>

        <?php if (empty($sections)): ?>
            <div style="text-align:center; padding:30px; color:var(--text-muted);">
                Δεν υπάρχουν ακόμη τμήματα. Πατήστε «➕ Νέο Τμήμα» για να δημιουργήσετε.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table sections-table">
                    <thead>
                        <tr>
                            <th>Τμήμα</th>
                            <th>Τάξη</th>
                            <th>Εγγεγραμμένοι Μαθητές</th>
                            <th>Καθηγητές</th>
                            <th width="120">Ενέργειες</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $previousGradeId = null; ?>
                        <?php foreach ($sections as $s): ?>
                            <?php if ($previousGradeId !== $s['grade_id']): ?>
                                <tr class="section-grade-heading"><th colspan="5"><h3>Τάξη <?= htmlspecialchars($s['grade_name']) ?></h3></th></tr>
                                <?php $previousGradeId = $s['grade_id']; ?>
                            <?php endif; ?>
                            <tr class="section-item">
                                <td>
                                    <strong><?= htmlspecialchars($s['section_name']) ?></strong>
                                    <?php if(isset($coGroups[(int)$s['id']])): ?>
                                    <small style="display:block;color:var(--text-muted);">Συνδιδασκαλία: <?= htmlspecialchars(implode(' + ',array_map(static function($id)use($sections){foreach($sections as $source)if((int)$source['id']===$id)return $source['section_name'];return '—';},$coGroups[(int)$s['id']]))) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-info">Τάξη <?= htmlspecialchars($s['grade_name']) ?></span>
                                </td>
                                <td>
                                    <?php if ($s['students_count'] > 0): ?>
                                        <a href="students.php?section_id=<?= $s['id'] ?>" style="color:var(--primary); font-weight:600; text-decoration:none;" title="Προβολή μαθητών αυτού του τμήματος">
                                            <?= $s['students_count'] ?> μαθητές &rarr;
                                        </a>
                                    <?php else: ?>
                                        <span style="font-size:12px; color:var(--text-muted);">0 μαθητές</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($s['teachers_list']): ?>
                                        <span style="font-size:13px;"><?= htmlspecialchars($s['teachers_list']) ?></span>
                                    <?php else: ?>
                                        <span style="font-size:12px; color:var(--text-muted);">Χωρίς ανάθεση</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display:flex; gap:6px;">
                                        <button type="button" class="btn btn-secondary btn-sm" onclick="openEditSectionModal(<?= htmlspecialchars(json_encode($s)) ?>)" aria-label="Επεξεργασία τμήματος" title="Επεξεργασία">
                                            ✏️
                                        </button>
                                        <form method="POST" action="sections.php" style="display:inline;" onsubmit="return confirm('Διαγραφή του τμήματος <?= htmlspecialchars(addslashes($s['section_name'])) ?>;');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="section_id" value="<?= $s['id'] ?>">
                                            <button type="submit" name="delete_section" class="btn btn-danger btn-sm" aria-label="Διαγραφή" title="Διαγραφή">
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

    <!-- Grades Side Card -->
    <div>
        <div class="card">
            <h3 class="card-title">🏷️ Τάξεις Σχολείου</h3>
            <p style="font-size:13px; color:var(--text-muted); margin-bottom:14px;">
                Βασικές βαθμίδες / τάξεις του σχολείου (π.χ. Α, Β, Γ).
            </p>

            <form method="POST" action="sections.php" style="display:flex; gap:8px; margin-bottom:18px;">
                <?= csrfField() ?>
                <input type="text" name="grade_name" class="form-control" placeholder="π.χ. Δ ή Εσπερινό" required style="font-size:14px;">
                <button type="submit" name="add_grade" class="btn btn-secondary btn-sm" style="white-space:nowrap;">➕ Προσθήκη</button>
            </form>

            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Όνομα Τάξης</th>
                            <th width="60">Ενέργεια</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($grades as $g): ?>
                            <tr>
                                <td><strong>Τάξη <?= htmlspecialchars($g['name']) ?></strong></td>
                                <td>
                                    <form method="POST" action="sections.php" style="display:inline;" onsubmit="return confirm('Διαγραφή τάξης <?= htmlspecialchars(addslashes($g['name'])) ?>;');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="grade_id" value="<?= $g['id'] ?>">
                                        <button type="submit" name="delete_grade" class="btn btn-danger btn-sm" aria-label="Διαγραφή" title="Διαγραφή">🗑️</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Add / Edit Section -->
<div id="sectionModal" role="dialog" aria-modal="true" aria-labelledby="sectionModalTitle" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center; padding:20px;">
    <div style="background:#fff; border-radius:12px; max-width:440px; width:100%; padding:24px; box-shadow:0 10px 30px rgba(0,0,0,0.2);">
        <h3 id="sectionModalTitle" style="margin-bottom:16px;">Τμήμα</h3>

        <form method="POST" action="sections.php" id="sectionForm">
            <?= csrfField() ?>
            <input type="hidden" name="section_id" id="formSectionId" value="">

            <div class="form-group">
                <label>Τάξη *</label>
                <select name="grade_id" id="formSectionGradeId" class="form-control" required>
                    <?php foreach ($grades as $g): ?>
                        <option value="<?= $g['id'] ?>">Τάξη <?= htmlspecialchars($g['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Όνομα Τμήματος *</label>
                <input type="text" name="name" id="formSectionName" class="form-control" placeholder="π.χ. Α3 ή Β-Θετική 2 ή Α-Ιταλικά" required>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                <button type="button" class="btn btn-secondary btn-sm" onclick="closeSectionModal()">Ακύρωση</button>
                <button type="submit" id="btnSubmitSection" name="add_section" class="btn btn-primary btn-sm">Αποθήκευση</button>
            </div>
        </form>
    </div>
</div>

<dialog id="coTeachingModal" aria-labelledby="coTeachingTitle" style="width:min(580px,94vw);max-height:85vh;overflow:auto;border:0;border-radius:14px;padding:24px;background:var(--bg-card,#fff);color:var(--text-main);">
<h3 id="coTeachingTitle">Συνδιδασκαλίες</h3>
<p style="font-size:13px;line-height:1.6;">Ορίστε όνομα και τουλάχιστον δύο τμήματα της ίδιας τάξης. Οι μαθητές εμφανίζονται αυτόματα μία φορά, αλφαβητικά. Οι αλλαγές στις εγγραφές των αρχικών τμημάτων εφαρμόζονται και εδώ.</p>
<form method="post" id="coTeachingForm" action="sections.php">
<?= csrfField() ?>
<input type="hidden" name="co_section_id" id="coSectionId" value="">
<div class="form-group"><label for="coGrade">Τάξη *</label><select id="coGrade" name="co_grade_id" class="form-control" required><option value="">Επιλέξτε τάξη</option><?php foreach($grades as $grade): ?><option value="<?= (int)$grade['id'] ?>"><?= htmlspecialchars($grade['name']) ?></option><?php endforeach; ?></select></div>
<div class="form-group"><label for="coName">Όνομα συνδιδασκαλίας *</label><input id="coName" name="co_name" class="form-control" maxlength="100" placeholder="π.χ. Α1 + Α2 — Συνδιδασκαλία" required></div>
<fieldset style="border:1px solid var(--border);border-radius:8px;padding:14px;"><legend>Τμήματα που θα ενωθούν *</legend><div id="coSources"></div></fieldset>
<p id="coStatus" role="status" style="font-size:13px;"></p>
<p style="font-size:12px;color:var(--text-muted);">Οι εκπαιδευτικοί χρειάζονται ανάθεση στο νέο τμήμα από «Καθηγητές &amp; Αναθέσεις». Αν υπάρχουν καταχωρίσεις παρουσιολογίου στη συνδιδασκαλία, αλλάζει μόνο το όνομά της.</p>
<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;"><button type="button" class="btn btn-secondary" onclick="document.getElementById('coTeachingModal').close()">Ακύρωση</button><button type="submit" class="btn btn-primary" name="save_co_teaching">Αποθήκευση</button></div>
</form></dialog>
<style>#coTeachingModal::backdrop{background:rgba(15,23,42,.55)}#coSources label{display:flex;gap:10px;align-items:center;padding:8px}#coSources input{width:auto}</style>
<script type="application/json" id="coTeachingConfig"><?= json_encode(['groups'=>(object)$coGroups,'sections'=>$sections],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
<script src="../assets/js/co-teaching.js?v=20261101-1"></script>

<script>
    function openAddSectionModal() {
        document.getElementById('sectionModalTitle').textContent = '➕ Προσθήκη Νέου Τμήματος';
        document.getElementById('formSectionId').value = '';
        document.getElementById('formSectionName').value = '';
        const btn = document.getElementById('btnSubmitSection');
        btn.name = 'add_section';
        btn.textContent = 'Δημιουργία Τμήματος';
        document.getElementById('sectionModal').style.display = 'flex';
    }

    function openEditSectionModal(sec) {
        if(coTeachingConfig.groups[sec.id]){openCoTeachingModal(sec);return;}
        document.getElementById('sectionModalTitle').textContent = '✏️ Επεξεργασία Τμήματος: ' + sec.section_name;
        document.getElementById('formSectionId').value = sec.id;
        document.getElementById('formSectionName').value = sec.section_name;
        document.getElementById('formSectionGradeId').value = sec.grade_id;
        const btn = document.getElementById('btnSubmitSection');
        btn.name = 'edit_section';
        btn.textContent = 'Αποθήκευση Αλλαγών';
        document.getElementById('sectionModal').style.display = 'flex';
    }

    function closeSectionModal() {
        document.getElementById('sectionModal').style.display = 'none';
    }
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
