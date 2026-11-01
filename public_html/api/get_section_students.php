<?php
// api/get_section_students.php - Fetch students and attendance status for teacher
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/request_validation.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$user = requireApiLogin();

$user = getCurrentUser();
$sectionValue=$_GET['section_id'] ?? '';
$emptySection=in_array($sectionValue,['',0,'0'],true);
$sectionId=schoolRequestId($sectionValue);
$date=schoolRequestDate($_GET['date'] ?? date('Y-m-d'));
if ($date===null) schoolJsonResponse(['error'=>'Μη έγκυρη μορφή ημερομηνίας'],400);

$allHours = ($_GET['hour'] ?? '') === 'all';
if ($allHours && $user['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error'=>'Δεν επιτρέπεται επεξεργασία όλων των ωρών.']);
    exit;
}
require_once __DIR__ . '/../includes/attendance_day.php';
$hour = $allHours ? 1 : schoolRequestHour($_GET['hour'] ?? 1);
if ($hour===null) schoolJsonResponse(['error'=>'Μη έγκυρη διδακτική ώρα (1-8)'],400);
if ($emptySection) schoolJsonResponse(['students'=>[]]);
if ($sectionId===null) schoolJsonResponse(['error'=>'Μη έγκυρο τμήμα.'],400);

$db = getDB();
require_once __DIR__.'/../includes/co_teaching.php';
$sectionMembershipSql=coTeachingMembershipSql(coTeachingGroups($db));
$activeYear = getActiveAcademicYear();
$activeYearId = $activeYear ? (int)$activeYear['id'] : 1;

// If user is teacher, ensure this section is assigned to them for active year
if ($user['role'] === 'teacher') {
    $stmt = $db->prepare("SELECT 1 FROM teacher_sections WHERE teacher_id = ? AND section_id = ? AND academic_year_id = ?");
    $stmt->execute([$user['id'], $sectionId, $activeYearId]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Δεν έχετε ανάθεση σε αυτό το τμήμα για το τρέχον διδακτικό έτος']);
        exit;
    }
}

// Fetch students in this section for the active academic year
$stmt = $db->prepare("
    SELECT s.id, s.am, s.last_name, s.first_name, s.father_name, s.mother_name, s.gender
    FROM students s
    JOIN {$sectionMembershipSql} ss ON s.id = ss.student_id
    WHERE ss.section_id = ? AND s.academic_year_id = ?
    ORDER BY s.last_name ASC, s.first_name ASC, s.am ASC, s.id ASC
");
$stmt->execute([$sectionId, $activeYearId]);
$students = $stmt->fetchAll();

// Fetch existing absences for this date, hour and active academic year with teacher info
$stmt = $db->prepare("
    SELECT a.student_id, a.is_absent, a.absence_type, a.teacher_id, u.name as teacher_name
    FROM attendance a
    LEFT JOIN users u ON a.teacher_id = u.id
    WHERE a.date = ? AND a.hour = ? AND a.student_id IN (SELECT student_id FROM {$sectionMembershipSql} AS memberships WHERE section_id = ?) AND a.academic_year_id = ?
");
$stmt->execute([$date, $hour, $sectionId, $activeYearId]);
$rows = $stmt->fetchAll();

$absences = [];
$recordedById = null;
$recordedByName = null;

foreach ($rows as $r) {
    if ($recordedById === null && !empty($r['teacher_id'])) {
        $recordedById = (int)$r['teacher_id'];
        $recordedByName = $r['teacher_name'] ?: 'Εκπαιδευτικός';
    }
    $absences[$r['student_id']] = [
        'is_absent' => (int)$r['is_absent'],
        'absence_type' => $r['absence_type'] ?: 'absent',
        'teacher_id' => $r['teacher_id']
    ];
}

$hasSavedData = !empty($absences);
$isOwnRecord = ($recordedById !== null && $recordedById == $user['id']);
$canManageAll = canManageAnyAttendance(); // admin or teacher_admin

if (!$hasSavedData) {
    $isLocked = false;
    $canEdit = true;
    $canDelete = false;
    $lockReason = '';
} else {
    if ($canManageAll || $isOwnRecord) {
        $isLocked = false;
        $canEdit = true;
        $canDelete = true;
        $lockReason = '';
    } else {
        $isLocked = true;
        $canEdit = false;
        $canDelete = false;
        $lockReason = "Η ώρα έχει καταχωρηθεί από τον/την " . ($recordedByName ?: 'άλλον εκπαιδευτικό') . ". Μόνο ο ίδιος ή Καθηγητής-Διαχειριστής μπορεί να την τροποποιήσει ή να τη διαγράψει.";
    }
}

foreach ($students as &$s) {
    $s['gender'] = $s['gender'] ?: 'male';
    $att = $absences[$s['id']] ?? null;
    if ($att && $att['is_absent'] == 1) {
        if ($att['absence_type'] === 'expulsion') {
            $s['status'] = 'expulsion';
        } elseif ($att['absence_type'] === 'justified') {
            $s['status'] = 'justified';
            $s['is_justified_locked'] = !canJustifyAbsences();
        } else {
            $s['status'] = 'absent';
        }
        $s['is_absent'] = true;
        $s['absence_type'] = $att['absence_type'] ?: 'absent';
    } else {
        $s['status'] = 'present';
        $s['is_absent'] = false;
        $s['absence_type'] = 'absent';
    }
}
unset($s);

// Fetch all day absences for this date across all hours for section students
$stmtDay = $db->prepare("
    SELECT a.id, a.student_id, a.hour, a.is_absent, a.absence_type, a.teacher_id, a.section_id, a.updated_at, a.note, u.name as teacher_name
    FROM attendance a
    LEFT JOIN users u ON a.teacher_id = u.id
    WHERE a.date = ? 
      AND a.student_id IN (SELECT student_id FROM {$sectionMembershipSql} AS memberships WHERE section_id = ?) 
      AND a.academic_year_id = ?
");
$stmtDay->execute([$date, $sectionId, $activeYearId]);
$dayRows = $stmtDay->fetchAll();

$dayAbsences = [];
foreach ($dayRows as $dr) {
    $sId = (int)$dr['student_id'];
    $h = (int)$dr['hour'];
    $dayAbsences[$sId][$h] = [
        'version' => attendanceSlotVersion($dr),
        'is_absent' => (int)$dr['is_absent'],
        'absence_type' => $dr['absence_type'] ?: 'absent',
        'teacher_id' => (int)$dr['teacher_id'],
        'teacher_name' => $dr['teacher_name'] ?: 'Εκπαιδευτικός'
    ];
}

echo json_encode([
    'academic_year_id' => $activeYearId,
    'empty_slot_version' => attendanceSlotVersion(null),
    'students' => $students, 
    'has_saved_data' => $hasSavedData,
    'is_locked' => $isLocked,
    'can_edit' => $canEdit,
    'can_delete' => $canDelete,
    'is_own_record' => $isOwnRecord,
    'recorded_by_id' => $recordedById,
    'recorded_by_name' => $recordedByName,
    'lock_reason' => $lockReason,
    'user_role' => $user['role'],
    'day_absences' => $dayAbsences
]);
