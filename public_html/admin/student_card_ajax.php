<?php
// admin/student_card_ajax.php - Detailed Absence History for a Single Student
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/request_validation.php';
$calendarRequest=($_GET['format']??'')==='calendar';
if ($calendarRequest) {
    header('Content-Type: application/json; charset=utf-8');
    $calendarUser=requireApiLogin();
    if ($calendarUser['role']!=='admin') schoolJsonResponse(['error'=>'Δεν επιτρέπεται η δικαιολόγηση απουσιών.'],403);
} else {
    requireAttendanceManager();
}
header('Cache-Control: no-store, private');

$studentId = schoolRequestId($_GET['student_id'] ?? null);

if ($studentId===null) {
    if ($calendarRequest) schoolJsonResponse(['error'=>'Μη έγκυρος μαθητής.'],400);
    http_response_code(400);
    echo '<div class="alert alert-danger">Μη έγκυρος μαθητής.</div>';
    exit;
}

try {
$db = getDB();
$activeAcademicYear = getActiveAcademicYear();
$activeYearId = $activeAcademicYear ? (int)$activeAcademicYear['id'] : 1;
$stmt = $db->prepare("
    SELECT s.id, s.am, s.last_name, s.first_name, 
           GROUP_CONCAT(DISTINCT sec.name ORDER BY sec.name SEPARATOR ', ') as section_names
    FROM students s
    LEFT JOIN student_sections ss ON s.id = ss.student_id
    LEFT JOIN sections sec ON ss.section_id = sec.id
    WHERE s.id = ? AND s.academic_year_id = ?
    GROUP BY s.id
");
$stmt->execute([$studentId, $activeYearId]);
$student = $stmt->fetch();

if (!$student) {
    if ($calendarRequest) schoolJsonResponse(['error'=>'Ο μαθητής δεν βρέθηκε στο τρέχον σχολικό έτος.'],404);
    http_response_code(404);
    echo '<div class="alert alert-danger">Ο μαθητής δεν βρέθηκε στο τρέχον σχολικό έτος.</div>';
    exit;
}

// Fetch all absence records for this student in the active academic year
$stmtAtt = $db->prepare("
    SELECT a.*, sec.name as section_name, u.name as teacher_name
    FROM attendance a
    LEFT JOIN sections sec ON a.section_id = sec.id
    LEFT JOIN users u ON a.teacher_id = u.id
    WHERE a.student_id = ? AND a.academic_year_id = ? AND a.is_absent = 1
    ORDER BY a.date DESC, a.hour ASC
");
$stmtAtt->execute([$studentId, $activeYearId]);
$absences = $stmtAtt->fetchAll();
} catch (Throwable $e) {
    if ($calendarRequest) schoolJsonResponse(['error'=>safeError($e)],500);
    throw $e;
}

// Read-only calendar projection, scoped to the same student and active year.
if ($calendarRequest) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!canJustifyAbsences()) {
        http_response_code(403);
        echo json_encode(['error' => 'Δεν επιτρέπεται η δικαιολόγηση απουσιών.']);
        exit;
    }
    $days = [];
    foreach ($absences as $record) {
        $day = $record['date'];
        if (!isset($days[$day])) $days[$day] = ['unjustified' => 0, 'justified' => 0];
        $key = $record['absence_type'] === 'justified' ? 'justified' : 'unjustified';
        $days[$day][$key]++;
    }
    schoolJsonResponse(['days' => $days]);
}

// Group absences by date
$byDate = [];
$totalHours = count($absences);
$unjustifiedCount = 0;
$justifiedCount = 0;
$expulsionCount = 0;

foreach ($absences as $att) {
    $d = $att['date'];
    if (!isset($byDate[$d])) {
        $byDate[$d] = [];
    }
    $byDate[$d][] = $att;

    if ($att['absence_type'] === 'justified') {
        $justifiedCount++;
    } elseif ($att['absence_type'] === 'expulsion') {
        $expulsionCount++;
    } else {
        $unjustifiedCount++;
    }
}
?>

<div style="background:var(--border); padding:14px; border-radius:10px; margin-bottom:16px;">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:10px;">
        <div>
            <div style="font-size:17px; font-weight:800; color:var(--text-main);">
                <?= htmlspecialchars($student['last_name'] . ' ' . $student['first_name']) ?>
            </div>
            <div style="font-size:12.5px; color:var(--text-muted); margin-top:2px;">
                Α.Μ.: <strong><?= htmlspecialchars($student['am']) ?></strong> &bull; 
                Τμήμα: <strong><?= htmlspecialchars($student['section_names'] ?: '-') ?></strong> &bull; 
                Σχολικό Έτος: <strong><?= htmlspecialchars($activeAcademicYear['name'] ?? '2026-2027') ?></strong>
            </div>
        </div>
        <div style="text-align:right;">
            <div style="font-size:22px; font-weight:800; color:<?= $totalHours >= 114 ? '#ef4444' : ($totalHours >= 60 ? '#f59e0b' : 'var(--primary)') ?>;">
                <?= $totalHours ?> <span style="font-size:13px; font-weight:600;">ώρες</span>
            </div>
            <div style="font-size:11px; color:var(--text-muted);">
                <?= $unjustifiedCount ?> αδ. &bull; <?= $justifiedCount ?> δικ. <?= $expulsionCount > 0 ? "&bull; {$expulsionCount} αποβ." : "" ?>
            </div>
        </div>
    </div>
</div>

<?php if (empty($byDate)): ?>
    <div style="text-align:center; padding:30px; color:var(--text-muted);">
        🎉 Ο/Η μαθητής/τρια δεν έχει καταχωρημένες απουσίες για το τρέχον έτος!
    </div>
<?php else: ?>
    <div style="font-size:13px; font-weight:700; margin-bottom:8px; color:var(--text-main);">
        📅 Ιστορικό ανά Ημερομηνία (<?= count($byDate) ?> ημέρες με απουσία):
    </div>
    <div class="dossier-table-scroll" tabindex="0" role="region" aria-label="Ιστορικό απουσιών ανά ημερομηνία" style="max-height:360px; overflow-y:auto; border:1px solid var(--border); border-radius:8px;">
        <table class="table-custom" style="width:100%; border-collapse:collapse; font-size:12.5px;">
            <thead>
                <tr>
                    <th style="width:110px;">Ημερομηνία</th>
                    <th>Ώρες & Τύπος</th>
                    <th style="width:70px; text-align:center;">Σύνολο</th>
                    <th style="width:140px;">Καταχωρήθηκε από</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($byDate as $date => $records): ?>
                    <tr>
                        <td style="font-weight:700; color:var(--text-main);">
                            <?= date('d/m/Y', strtotime($date)) ?>
                        </td>
                        <td>
                            <div style="display:flex; flex-wrap:wrap; gap:5px;">
                                <?php foreach ($records as $r): ?>
                                    <?php
                                        $cls = 'badge-gray';
                                        $lbl = $r['hour'] . 'η';
                                        if ($r['absence_type'] === 'justified') {
                                            $cls = 'badge-success';
                                            $lbl .= ' (δικ.)';
                                        } elseif ($r['absence_type'] === 'expulsion') {
                                            $cls = 'badge-warning';
                                            $lbl .= ' (αποβ.)';
                                        }
                                    ?>
                                    <span class="badge <?= $cls ?>" style="font-size:11px;">
                                        <?= $lbl ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td style="text-align:center; font-weight:700;">
                            <?= count($records) ?>
                        </td>
                        <td style="color:var(--text-muted); font-size:11.5px;">
                            <?= htmlspecialchars($records[0]['teacher_name'] ?? 'Διαχειριστής') ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
